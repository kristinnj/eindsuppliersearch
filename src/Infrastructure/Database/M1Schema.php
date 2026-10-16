<?php

declare(strict_types=1);
namespace EindSupplierSearch\Infrastructure\Database;

/** M1 DDL only. No evidence records, native data or registry writes. */
final class M1Schema
{
    public const HASHES = [
        'eind_observation' => '68b374dcf67e4f7c54d01de1ce73be58de6dfba591815bd4880a9d734874230f',
        'eind_quote' => 'a4d69f1574d96e86d9896d16d06adc30be804906f4720a30743ac13a8a2573ea',
    ];
    private readonly \Closure $execute;
    private readonly \Closure $select;

    public function __construct(private readonly string $prefix, callable $execute, callable $select)
    {
        if (!preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) || strlen($prefix) > 41) {
            throw new \InvalidArgumentException('Unsupported M1 prefix.');
        }
        $this->execute = \Closure::fromCallable($execute);
        $this->select = \Closure::fromCallable($select);
    }

    public static function template(string $name): string
    {
        if (!isset(self::HASHES[$name])) { throw new \InvalidArgumentException('Unknown M1 object.'); }
        $sql = file_get_contents(dirname(__DIR__, 3) . '/resources/schema/m1/' . substr($name, 5) . '.sql');
        if ($sql === false || hash('sha256', $sql) !== self::HASHES[$name]) {
            throw new \RuntimeException('M1 source manifest mismatch.');
        }
        return $sql;
    }

    public function ddl(string $name): string
    {
        return str_replace('CREATE TABLE P_' . $name, 'CREATE TABLE `' . $this->prefix . $name . '`', self::template($name));
    }

    private function read(string $sql): array
    {
        $rows = ($this->select)($sql);
        if (!is_array($rows)) { throw new \RuntimeException('M1 metadata unavailable.'); }
        return $rows;
    }

    private static function literal(string $text): string { return "X'" . bin2hex($text) . "'"; }

    public function prerequisite(): void
    {
        (new OfferBindingSchema($this->prefix,
            static function (): never { throw new \LogicException('Registry writes prohibited.'); },
            $this->select))->verify();
    }

    public function enforcement(): void
    {
        $r = $this->read('SELECT DATABASE() AS db, VERSION() AS version, @@SESSION.sql_mode AS mode, '
            . '@@check_constraint_checks AS checks, @@unique_checks AS uniques');
        if (count($r) !== 1 || empty($r[0]['db']) || !preg_match('/\A11\.4\..*MariaDB/i', $r[0]['version'] ?? '')
            || !preg_match('/(?:^|,)(STRICT_ALL_TABLES|STRICT_TRANS_TABLES)(?:,|$)/', $r[0]['mode'] ?? '')
            || (string) ($r[0]['checks'] ?? '') !== '1' || (string) ($r[0]['uniques'] ?? '') !== '1') {
            throw new \RuntimeException('M1 enforcement prerequisites unavailable.');
        }
    }

    /** Verify every retained M1 object before the first CREATE; DDL is not rollbackable. */
    public function install(): void
    {
        $this->enforcement();
        $db = $this->read('SELECT DATABASE() AS db')[0]['db'] ?? '';
        $lock = self::literal('m1:' . substr(hash('sha256', $db . ':' . $this->prefix), 0, 56));
        if ((string) ($this->read('SELECT GET_LOCK(' . $lock . ', 0) AS acquired')[0]['acquired'] ?? '') !== '1') {
            throw new \RuntimeException('M1 schema lock unavailable.');
        }
        try {
            $this->prerequisite();
            $this->unexpected();
            $missing = [];
            foreach (array_keys(self::HASHES) as $name) {
                if ($this->metadata($name) === []) { $missing[] = $name; }
                else { $this->verifyTable($name); }
            }
            foreach ($missing as $name) {
                if (!(($this->execute)($this->ddl($name)))) { throw new \RuntimeException('M1 CREATE outcome uncertain.'); }
                $this->verifyTable($name);
            }
            $this->verify();
        } finally {
            if ((string) ($this->read('SELECT RELEASE_LOCK(' . $lock . ') AS released')[0]['released'] ?? '') !== '1') {
                throw new \RuntimeException('M1 lock release uncertain.');
            }
        }
    }

    public function verify(): void
    {
        $this->enforcement(); $this->prerequisite(); $this->unexpected();
        foreach (array_keys(self::HASHES) as $name) { $this->verifyTable($name); }
    }

    /** Known later evidence, shadows and unrecognized evidence namespace objects fail M1. */
    public function unexpected(): void
    {
        $rows = $this->read('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
        foreach ($rows as $row) {
            $name = $row['TABLE_NAME'];
            if (!str_starts_with($name, $this->prefix)) { continue; }
            $suffix = substr($name, strlen($this->prefix));
            if (str_starts_with(strtolower($suffix), 'eind_') && !in_array($suffix,
                ['eind_search_results', 'eind_search_suppliers', 'eind_offer_binding',
                    'eind_upgrade_trigger_metadata', 'eind_upgrade_metadata_capability'], true)
                && !isset(self::HASHES[$suffix])) {
                throw new M1IntegrityException('UNEXPECTED_M1_OBJECT');
            }
        }
    }

    private function metadata(string $name): array
    {
        return $this->read('SELECT ENGINE,TABLE_COLLATION,TABLE_TYPE FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . self::literal($this->prefix . $name));
    }

    /** Manifest parser accepts only the frozen, hash-checked SQL grammar. */
    public static function definition(string $name): array
    {
        $sql = self::template($name);
        $body = substr($sql, strpos($sql, '(') + 1, strrpos($sql, ') ENGINE=') - strpos($sql, '(') - 1);
        $parts = []; $start = 0; $depth = 0; $quote = false;
        for ($i = 0; $i < strlen($body); ++$i) {
            $c = $body[$i];
            if ($c === "'") { $quote = !$quote; }
            if ($quote) { continue; }
            if ($c === '(') { ++$depth; } elseif ($c === ')') { --$depth; }
            elseif ($c === ',' && $depth === 0) { $parts[] = trim(substr($body, $start, $i - $start)); $start = $i + 1; }
        }
        $parts[] = trim(substr($body, $start));
        $columns = []; $indexes = []; $checks = [];
        foreach ($parts as $part) {
            if (preg_match('/\A(\w+) (BINARY\(\d+\)|INT UNSIGNED|SMALLINT UNSIGNED|VARCHAR\(\d+\)|CHAR\(\d+\)|DATETIME\(6\)|DECIMAL\(20,6\)|MEDIUMBLOB) (.+)\z/s', $part, $m)) {
                $columns[] = [$m[1], strtolower($m[2]), str_contains($m[3], 'NOT NULL') ? 'NO' : 'YES',
                    str_contains($m[3], 'COLLATE ascii_bin') ? 'ascii_bin' : null];
            } elseif (preg_match('/\A(PRIMARY KEY|UNIQUE KEY (\w+)|KEY (\w+)) \(([^)]+)\)\z/', $part, $m)) {
                $key = $m[1] === 'PRIMARY KEY' ? 'PRIMARY' : (($m[2] ?? '') ?: $m[3]);
                $indexes[$key] = ['unique' => !str_starts_with($m[1], 'KEY '), 'columns' => explode(',', $m[4]),
                    'directions' => array_fill(0, count(explode(',', $m[4])), 'A')];
            } elseif (preg_match('/\ACONSTRAINT (\w+) CHECK \((.*)\)\z/s', $part, $m)) {
                $checks[$m[1]] = self::normalize($m[2]);
            } else { throw new \LogicException('Unsupported frozen M1 grammar.'); }
        }
        return compact('columns', 'indexes', 'checks');
    }

    /**
     * Tokenize the frozen CHECK subset. Reject backslashes, doubled quotes,
     * comments and unsupported tokens rather than guessing SQL escape modes.
     * Token boundaries, literal bytes and parentheses are retained.
     */
    public static function normalize(string $sql): string
    {
        $tokens = [];
        $depth = 0;
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            if (str_contains(" \t\r\n", $sql[$i])) { ++$i; continue; }
            if ($sql[$i] === "'") {
                $start = $i++;
                while ($i < $length && $sql[$i] !== "'") {
                    $byte = ord($sql[$i]);
                    if ($sql[$i] === '\\' || $byte < 32 || $byte > 126) {
                        throw new M1IntegrityException('M1_CHECK_UNSUPPORTED');
                    }
                    ++$i;
                }
                if ($i === $length || ($i + 1 < $length && $sql[$i + 1] === "'")) {
                    throw new M1IntegrityException('M1_CHECK_UNSUPPORTED');
                }
                $tokens[] = substr($sql, $start, ++$i - $start);
                continue;
            }
            if ($sql[$i] === '`') {
                if (!preg_match('/\G`([A-Za-z_][A-Za-z0-9_]*)`/', $sql, $m, 0, $i)) {
                    throw new M1IntegrityException('M1_CHECK_UNSUPPORTED');
                }
                $tokens[] = strtolower($m[1]); $i += strlen($m[0]); continue;
            }
            if (preg_match('/\G(?:[A-Za-z_][A-Za-z0-9_]*|[0-9]+)/', $sql, $m, 0, $i)) {
                $tokens[] = strtolower($m[0]); $i += strlen($m[0]); continue;
            }
            $c = $sql[$i++];
            if (!str_contains('(),=>', $c)) { throw new M1IntegrityException('M1_CHECK_UNSUPPORTED'); }
            if ($c === '(') { ++$depth; }
            if ($c === ')' && --$depth < 0) { throw new M1IntegrityException('M1_CHECK_UNSUPPORTED'); }
            $tokens[] = $c;
        }
        if ($depth !== 0 || $tokens === []) { throw new M1IntegrityException('M1_CHECK_UNSUPPORTED'); }
        // Rewrite token sequences only, never text contained in a literal token.
        $canonical = [];
        for ($i = 0; $i < count($tokens); ++$i) {
            $cast = array_slice($tokens, $i, 6);
            if (count($cast) === 6 && $cast[0] === 'cast' && $cast[1] === '('
                && preg_match('/\\A[a-z_][a-z0-9_]*\\z/D', $cast[2])
                && array_slice($cast, 3) === ['as', 'binary', ')']) {
                array_push($canonical, 'cast', '(', $cast[2], 'as', 'char', 'charset', 'binary', ')');
                $i += 5;
            } elseif ($tokens[$i] === 'regexp' && ($tokens[$i + 1] ?? null) === 'binary'
                && str_starts_with($tokens[$i + 2] ?? '', "'")) {
                array_push($canonical, 'regexp', 'cast', '(', $tokens[$i + 2], 'as', 'char', 'charset', 'binary', ')');
                $i += 2;
            } else { $canonical[] = $tokens[$i]; }
        }
        return implode(' ', $canonical);
    }

    public function verifyTable(string $name): void
    {
        $expected = self::definition($name); $meta = $this->metadata($name);
        if (count($meta) !== 1 || $meta[0]['ENGINE'] !== 'InnoDB' || $meta[0]['TABLE_TYPE'] !== 'BASE TABLE'
            || $meta[0]['TABLE_COLLATION'] !== 'utf8mb4_bin') { throw new M1IntegrityException('M1_TABLE_MISSING_OR_INCOMPATIBLE'); }
        $where = 'TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . self::literal($this->prefix . $name);
        $rows = $this->read('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,CHARACTER_SET_NAME,IS_GENERATED '
            . 'FROM information_schema.COLUMNS WHERE ' . $where . ' ORDER BY ORDINAL_POSITION');
        if (count($rows) !== count($expected['columns'])) { throw new M1IntegrityException('M1_COLUMNS'); }
        foreach ($rows as $i => $row) {
            [$column, $type, $nullable, $collation] = $expected['columns'][$i];
            $actual = preg_replace('/\b(int|smallint)\([0-9]+\)/', '$1', strtolower($row['COLUMN_TYPE']));
            if ($row['COLUMN_NAME'] !== $column || $actual !== $type || $row['IS_NULLABLE'] !== $nullable
                || $row['COLUMN_DEFAULT'] !== null || $row['EXTRA'] !== '' || $row['IS_GENERATED'] !== 'NEVER'
                || $row['COLLATION_NAME'] !== $collation || $row['CHARACTER_SET_NAME'] !== ($collation === null ? null : 'ascii')) {
                throw new M1IntegrityException('M1_COLUMN_DEFINITION');
            }
        }
        $indexes = [];
        foreach ($this->read('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SEQ_IN_INDEX,SUB_PART,INDEX_TYPE,IGNORED,COLLATION '
            . 'FROM information_schema.STATISTICS WHERE ' . $where . ' ORDER BY INDEX_NAME,SEQ_IN_INDEX') as $row) {
            if ($row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IGNORED'] !== 'NO'
                || !isset($row['COLLATION']) || $row['COLLATION'] !== 'A') { throw new M1IntegrityException('M1_INDEX_SEMANTICS'); }
            $indexes[$row['INDEX_NAME']]['unique'] = (string) $row['NON_UNIQUE'] === '0';
            $indexes[$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
            $indexes[$row['INDEX_NAME']]['directions'][] = $row['COLLATION'];
        }
        ksort($indexes); ksort($expected['indexes']);
        if ($indexes !== $expected['indexes']) { throw new M1IntegrityException('M1_INDEXES'); }
        $checks = [];
        foreach ($this->read('SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE '
            . 'CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=' . self::literal($this->prefix . $name)) as $row) {
            $checks[$row['CONSTRAINT_NAME']] = self::normalize($row['CHECK_CLAUSE']);
        }
        ksort($checks); ksort($expected['checks']);
        if ($checks !== $expected['checks']) { throw new M1IntegrityException('M1_CHECKS'); }
        if ($this->read('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE ' . $where . " AND CONSTRAINT_TYPE='FOREIGN KEY'") !== []
            || $this->read('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='
                . self::literal($this->prefix . $name)) !== []) { throw new M1IntegrityException('M1_FOREIGN_KEYS_OR_TRIGGERS'); }
    }
}
