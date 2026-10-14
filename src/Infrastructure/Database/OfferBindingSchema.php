<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

/** Additive schema installation only: never writes bindings or native records. */
final class OfferBindingSchema
{
    public const TABLE = 'eind_offer_binding';

    private readonly string $table;
    private readonly \Closure $execute;
    private readonly \Closure $select;

    /** @param callable(string): bool $execute @param callable(string): array|false $select */
    public function __construct(string $prefix, callable $execute, callable $select)
    {
        if (!preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) || strlen($prefix . self::TABLE) > 64) {
            throw new \InvalidArgumentException('Invalid offer binding table prefix.');
        }
        $this->table = $prefix . self::TABLE;
        $this->execute = \Closure::fromCallable($execute);
        $this->select = \Closure::fromCallable($select);
    }

    public static function forPrestaShop(): self
    {
        $db = \Db::getInstance();

        return new self(
            _DB_PREFIX_,
            static fn (string $sql): bool => $db->execute($sql),
            // Metadata must never be served from PrestaShop's query cache.
            static fn (string $sql): array|false => $db->executeS($sql, true, false)
        );
    }

    /** PrestaShop clears session SQL mode; scope strictness to this migration only. */
    public function installWithScopedStrictMode(): void
    {
        $settings = $this->read('SELECT @@SESSION.sql_mode AS mode');
        if (count($settings) !== 1 || !isset($settings[0]['mode']) || !is_string($settings[0]['mode'])) {
            throw new \RuntimeException('Offer binding schema SQL mode could not be read.');
        }
        $original = $settings[0]['mode'];
        if (preg_match('/(?:^|,)(STRICT_TRANS_TABLES|STRICT_ALL_TABLES)(?:,|$)/', $original)) {
            $this->install();
            return;
        }
        $strict = $original === '' ? 'STRICT_ALL_TABLES' : $original . ',STRICT_ALL_TABLES';
        try {
            if (!(($this->execute)('SET SESSION sql_mode = ' . self::literal($strict)))) {
                throw new \RuntimeException('Offer binding schema strict SQL mode could not be enabled.');
            }
            $this->install();
        } finally {
            if (!(($this->execute)('SET SESSION sql_mode = ' . self::literal($original)))) {
                throw new \RuntimeException('Offer binding schema original SQL mode could not be restored.');
            }
        }
    }

    public function install(): void
    {
        $settings = $this->read('SELECT DATABASE() AS db, @@sql_mode AS mode,
            @@check_constraint_checks AS checks, @@unique_checks AS uniques')[0] ?? [];
        if (empty($settings['db']) || (int) ($settings['checks'] ?? 0) !== 1
            || (int) ($settings['uniques'] ?? 0) !== 1
            || !preg_match('/(?:^|,)(STRICT_TRANS_TABLES|STRICT_ALL_TABLES)(?:,|$)/', $settings['mode'] ?? '')) {
            throw new \RuntimeException('Offer binding schema requires a database, strict SQL mode and enforced constraints.');
        }
        $lock = self::literal($this->migrationLockName($settings['db']));
        if ((int) ($this->read('SELECT GET_LOCK(' . $lock . ', 10) AS acquired')[0]['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Offer binding schema lock could not be acquired.');
        }
        try {
            if ($this->tableMetadata() === []) {
                if (!(($this->execute)($this->ddl()))) {
                    throw new \RuntimeException('Offer binding schema creation failed.');
                }
            }
            $this->verify();
        } finally {
            $this->read('SELECT RELEASE_LOCK(' . $lock . ') AS released');
        }
    }

    /** Shared with explicit recovery; exact existing lock identity is unchanged. */
    public function migrationLockName(string $database): string
    {
        return 'eob:' . substr(hash('sha256', $database . ':' . $this->table), 0, 56);
    }

    /** Existing incompatible tables are rejected without ALTER, DROP or data repair. */
    public function verify(): void
    {
        $table = $this->tableMetadata();
        if (count($table) !== 1 || $table[0]['ENGINE'] !== 'InnoDB'
            || $table[0]['TABLE_COLLATION'] !== 'utf8mb4_bin' || $table[0]['TABLE_TYPE'] !== 'BASE TABLE') {
            throw new \RuntimeException('Incompatible offer binding table engine or character semantics.');
        }
        $where = 'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . self::literal($this->table);
        $columns = $this->read('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
            EXTRA, COLLATION_NAME, IS_GENERATED FROM information_schema.COLUMNS WHERE ' . $where . ' ORDER BY ORDINAL_POSITION');
        $expected = self::columns();
        if (count($columns) !== count($expected)) {
            throw new \RuntimeException('Incompatible offer binding columns.');
        }
        foreach ($columns as $index => $column) {
            [$name, $type, $nullable, $collation, $extra] = $expected[$index];
            $actualType = preg_replace('/\b(int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            $default = $column['COLUMN_DEFAULT'];
            if ($column['COLUMN_NAME'] !== $name || $actualType !== $type
                || $column['IS_NULLABLE'] !== $nullable || $column['COLLATION_NAME'] !== $collation
                || $column['EXTRA'] !== $extra || $column['IS_GENERATED'] !== 'NEVER'
                || ($default !== null && !($nullable === 'YES' && $default === 'NULL'))) {
                throw new \RuntimeException('Incompatible offer binding column: ' . $name . '.');
            }
        }
        $indexes = [];
        foreach ($this->read('SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX,
            SUB_PART, INDEX_TYPE, IGNORED FROM information_schema.STATISTICS WHERE ' . $where . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX') as $index) {
            if ($index['SUB_PART'] !== null || $index['INDEX_TYPE'] !== 'BTREE' || $index['IGNORED'] !== 'NO') {
                throw new \RuntimeException('Incompatible offer binding index semantics.');
            }
            $indexes[$index['INDEX_NAME']]['unique'] = (int) $index['NON_UNIQUE'] === 0;
            $indexes[$index['INDEX_NAME']]['columns'][] = $index['COLUMN_NAME'];
        }
        $expectedIndexes = self::indexes();
        ksort($indexes);
        ksort($expectedIndexes);
        if ($indexes !== $expectedIndexes) {
            throw new \RuntimeException('Incompatible offer binding primary or secondary indexes.');
        }
        $checks = [];
        foreach ($this->read('SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ' . self::literal($this->table)) as $check) {
            $checks[$check['CONSTRAINT_NAME']] = self::normalizeCheck($check['CHECK_CLAUSE']);
        }
        $expectedChecks = array_map([self::class, 'normalizeCheck'], self::checks());
        ksort($checks);
        ksort($expectedChecks);
        if ($checks !== $expectedChecks) {
            throw new \RuntimeException('Incompatible offer binding CHECK constraints.');
        }
        $foreignKeys = $this->read('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE ' . $where . " AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $triggers = $this->read('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ' . self::literal($this->table));
        if ($foreignKeys !== [] || $triggers !== []) {
            throw new \RuntimeException('Unexpected offer binding foreign keys or triggers.');
        }
    }

    public function ddl(): string
    {
        $definitions = [
            '`id_binding` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            '`id_shop` INT UNSIGNED NOT NULL',
            '`id_supplier` INT UNSIGNED NOT NULL',
            '`order_reference` MEDIUMBLOB NOT NULL',
            '`reference_sha256` BINARY(32) NOT NULL',
            '`id_product` INT UNSIGNED DEFAULT NULL',
            '`id_product_attribute` INT UNSIGNED DEFAULT NULL',
            '`state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            '`provenance` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            '`creation_operation_id` BINARY(16) NOT NULL',
            '`integrity_reason` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL',
            '`created_at` DATETIME(6) NOT NULL',
            '`updated_at` DATETIME(6) NOT NULL',
        ];
        foreach (self::indexes() as $name => $index) {
            $key = $name === 'PRIMARY' ? 'PRIMARY KEY' : ($index['unique'] ? 'UNIQUE KEY' : 'KEY') . ' `' . $name . '`';
            $definitions[] = $key . ' (`' . implode('`, `', $index['columns']) . '`)';
        }
        foreach (self::checks() as $name => $expression) {
            $definitions[] = 'CONSTRAINT `' . $name . '` CHECK (' . $expression . ')';
        }

        return 'CREATE TABLE IF NOT EXISTS `' . $this->table . "` (\n"
            . implode(",\n", $definitions)
            . "\n) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin";
    }

    private function tableMetadata(): array
    {
        return $this->read('SELECT ENGINE, TABLE_COLLATION, TABLE_TYPE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . self::literal($this->table));
    }

    private function read(string $sql): array
    {
        $rows = ($this->select)($sql);
        if (!is_array($rows)) {
            throw new \RuntimeException('Offer binding schema metadata query failed.');
        }

        return $rows;
    }

    private static function literal(string $value): string
    {
        return "X'" . bin2hex($value) . "'";
    }

    private static function columns(): array
    {
        return [
            ['id_binding', 'bigint unsigned', 'NO', null, 'auto_increment'],
            ['id_shop', 'int unsigned', 'NO', null, ''],
            ['id_supplier', 'int unsigned', 'NO', null, ''],
            ['order_reference', 'mediumblob', 'NO', null, ''],
            ['reference_sha256', 'binary(32)', 'NO', null, ''],
            ['id_product', 'int unsigned', 'YES', null, ''],
            ['id_product_attribute', 'int unsigned', 'YES', null, ''],
            ['state', 'varchar(16)', 'NO', 'ascii_bin', ''],
            ['provenance', 'varchar(32)', 'NO', 'ascii_bin', ''],
            ['creation_operation_id', 'binary(16)', 'NO', null, ''],
            ['integrity_reason', 'varchar(64)', 'YES', 'ascii_bin', ''],
            ['created_at', 'datetime(6)', 'NO', null, ''],
            ['updated_at', 'datetime(6)', 'NO', null, ''],
        ];
    }

    private static function indexes(): array
    {
        return [
            'PRIMARY' => ['unique' => true, 'columns' => ['id_binding']],
            'uq_offer_identity' => ['unique' => true, 'columns' => ['id_shop', 'id_supplier', 'reference_sha256']],
            'uq_native_target' => ['unique' => true, 'columns' => ['id_product', 'id_product_attribute']],
            'uq_creation_operation' => ['unique' => true, 'columns' => ['creation_operation_id']],
            'ix_reconciliation' => ['unique' => false, 'columns' => ['state', 'updated_at', 'id_binding']],
        ];
    }

    private static function checks(): array
    {
        return [
            'ck_eob_scope' => '`id_shop` > 0 AND `id_supplier` > 0',
            'ck_eob_reference' => 'OCTET_LENGTH(`order_reference`) BETWEEN 1 AND ' . OfferBindingReference::MAX_BYTES,
            'ck_eob_hash' => 'UNHEX(SHA2(`order_reference`, 256)) IS NOT NULL AND `reference_sha256` = UNHEX(SHA2(`order_reference`, 256))',
            'ck_eob_state' => "CAST(`state` AS CHAR CHARSET binary) IN ('RESERVED', 'ACTIVE', 'QUARANTINED', 'RETIRED')",
            'ck_eob_provenance' => "CAST(`provenance` AS CHAR CHARSET binary) = 'MODULE_CREATED_LIVE'",
            'ck_eob_target' => '`id_product` IS NULL AND `id_product_attribute` IS NULL OR
                `id_product` IS NOT NULL AND `id_product` > 0 AND `id_product_attribute` IS NOT NULL AND `id_product_attribute` = 0',
            'ck_eob_active_target' => "CAST(`state` AS CHAR CHARSET binary) <> 'ACTIVE' OR `id_product` IS NOT NULL",
            'ck_eob_reason' => "CAST(`state` AS CHAR CHARSET binary) IN ('RESERVED', 'ACTIVE') AND `integrity_reason` IS NULL OR
                CAST(`state` AS CHAR CHARSET binary) = 'QUARANTINED' AND `integrity_reason` IS NOT NULL AND OCTET_LENGTH(`integrity_reason`) > 0 OR
                CAST(`state` AS CHAR CHARSET binary) = 'RETIRED' AND (`integrity_reason` IS NULL OR OCTET_LENGTH(`integrity_reason`) > 0)",
            'ck_eob_timestamps' => '`updated_at` >= `created_at`',
        ];
    }

    /** Normalize formatting only; retain grouping and case-sensitive string literals. */
    private static function normalizeCheck(string $expression): string
    {
        $parts = preg_split("/('[^']*')/", $expression, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            if ($index % 2 === 0) {
                $part = preg_replace('/\s+/', '', strtolower(str_replace('`', '', $part)));
                $part = str_replace('!=', '<>', $part);
            }
        }
        unset($part);

        return implode('', $parts);
    }
}
