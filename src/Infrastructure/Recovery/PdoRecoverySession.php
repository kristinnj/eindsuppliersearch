<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Recovery;

use EindSupplierSearch\Infrastructure\Database\OfferBindingSchema;
use EindSupplierSearch\Infrastructure\Verification\PdoUpgradeReader;
use EindSupplierSearch\Infrastructure\Verification\UpgradeVerification;
use EindSupplierSearch\Infrastructure\Verification\VerificationSource;

/** Separate credentials: read-only inspection and canonical-table CREATE only. */
final class PdoRecoverySession
{
    private ?\PDO $writer = null;
    private ?OfferBindingSchema $schema = null;

    public function __construct(private readonly PdoUpgradeReader $reader, private readonly array $config,
        private readonly string $moduleRoot, private readonly \Closure $writerConnection)
    {
    }

    public function inspect(): array
    {
        $identity = $this->reader->validateTarget($this->config);
        $source = VerificationSource::read($this->moduleRoot);
        $registration = $this->reader->registration();
        $modules = $registration['modules'];
        $registered = count($modules) === 1 && $modules[0]['name'] === UpgradeVerification::MODULE;
        $consistent = $registered && (int) $modules[0]['id_module'] > 0
            && in_array((string) $modules[0]['active'], ['0', '1'], true)
            && !((string) $modules[0]['active'] === '0' && $registration['links'] !== []);
        $registry = $this->reader->registry();
        if ($registry['exists']) {
            $registry['rows'] = (int) $this->reader->select('SELECT COUNT(*) AS row_count FROM `' . $this->config['prefix'] . OfferBindingSchema::TABLE . '`')[0]['row_count'];
        }
        $tables = $this->reader->select('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');
        $alternates = [];
        $snapshot = [];
        foreach ($tables as $table) {
            $name = $table['TABLE_NAME'];
            if ($name === $this->config['prefix'] . OfferBindingSchema::TABLE) { continue; }
            if (preg_match('/offer.*(?:bind|registry)|bind.*offer|ownership|eob_registry/i', $name)) { $alternates[] = $name; }
            // Hash complete persistent data, never emit it. Includes native module/shop/commerce.
            $sql = 'SELECT * FROM `' . str_replace('`', '``', $name) . '`';
            $rows = array_map('serialize', $this->reader->select($sql));
            sort($rows, SORT_STRING);
            $snapshot[$name] = ['type' => $table['TABLE_TYPE'], 'data' => hash('sha256', serialize($rows))];
        }
        return ['identity_verified' => true, 'identity' => $identity, 'source_version' => $source['version'],
            'source_agrees' => $source['agrees'], 'registered' => $registered,
            'registered_version' => $registered ? $modules[0]['version'] : null,
            'disabled' => $registered && (string) $modules[0]['active'] === '0' && $registration['links'] === [],
            'registration_consistent' => $consistent, 'registration_hash' => hash('sha256', serialize($registration)),
            'protected_data_hash' => hash('sha256', serialize($snapshot)), 'alternate_tables' => $alternates, 'registry' => $registry];
    }

    public function lock(): bool
    {
        $this->writer = ($this->writerConnection)();
        if ($this->writer->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql' || $this->writer->getAttribute(\PDO::ATTR_EMULATE_PREPARES)) {
            throw new \RuntimeException('Invalid recovery connection.');
        }
        $identity = $this->select('SELECT DATABASE() AS db, @@hostname AS hostname')[0];
        if ($identity['db'] !== $this->config['database'] || $identity['hostname'] !== $this->config['server_hostname']) {
            throw new \RuntimeException('Recovery target mismatch.');
        }
        $create = false;
        $read = false;
        foreach ($this->writer->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(\PDO::FETCH_COLUMN) as $grant) {
            if (str_starts_with($grant, 'GRANT USAGE ON *.* TO ') && !str_contains($grant, 'WITH GRANT OPTION')) { continue; }
            if (str_contains($grant, 'WITH GRANT OPTION')) { throw new \RuntimeException('Unexpected recovery grant.'); }
            if (preg_match('/\AGRANT SELECT, SHOW VIEW ON `([^`]+)`\.\* TO /', $grant, $match)
                && str_replace('\\_', '_', $match[1]) === $this->config['database']) { $read = true; continue; }
            if (preg_match('/\AGRANT CREATE ON `([^`]+)`\.`([^`]+)` TO /', $grant, $match)
                && str_replace('\\_', '_', $match[1]) === $this->config['database']
                && $match[2] === $this->config['prefix'] . OfferBindingSchema::TABLE) { $create = true; continue; }
            throw new \RuntimeException('Unexpected recovery privileges.');
        }
        if (!$read || !$create) { throw new \RuntimeException('Recovery privileges unavailable.'); }
        $this->schema = new OfferBindingSchema($this->config['prefix'],
            function (string $sql): bool {
                if ($this->schema === null || $sql !== $this->schema->ddl()) { throw new \LogicException('Unapproved recovery SQL.'); }
                return $this->writer->exec($sql) !== false;
            }, fn (string $sql): array => $this->select($sql));
        return (int) $this->select('SELECT GET_LOCK(' . self::literal($this->schema->migrationLockName($this->config['database'])) . ',0) AS acquired')[0]['acquired'] === 1;
    }

    public function create(): void
    {
        if ($this->schema === null) { throw new \LogicException('No recovery lock.'); }
        // Shared installer reacquires the same connection-owned advisory lock, then balances it.
        // It requires existing strict SQL mode; recovery does not change session SQL mode.
        $this->schema->install();
    }

    public function unlock(): void
    {
        if ($this->schema === null) { throw new \LogicException('No recovery lock.'); }
        if ((int) $this->select('SELECT RELEASE_LOCK(' . self::literal($this->schema->migrationLockName($this->config['database'])) . ') AS released')[0]['released'] !== 1) {
            throw new \RuntimeException('Recovery lock release failed.');
        }
    }

    private function select(string $sql): array
    {
        if (!str_starts_with($sql, 'SELECT ') || str_contains($sql, ';')) { throw new \LogicException('Non-read recovery SQL.'); }
        $sql = str_replace('information_schema.TRIGGERS', '`' . $this->config['prefix'] . 'eind_upgrade_trigger_metadata`', $sql);
        $statement = $this->writer->query($sql);
        if (!$statement) { throw new \RuntimeException('Recovery metadata read failed.'); }
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function literal(string $value): string
    {
        return "X'" . bin2hex($value) . "'";
    }

}
