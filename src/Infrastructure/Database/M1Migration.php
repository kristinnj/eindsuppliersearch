<?php

declare(strict_types=1);
namespace EindSupplierSearch\Infrastructure\Database;

use EindSupplierSearch\Infrastructure\Verification\M1Configuration;
use EindSupplierSearch\Infrastructure\Verification\PdoM1Reader;

/** Native upgrade delegate: explicit restricted writer config, no native Db credentials. */
final class M1Migration
{
    public static function run(string $moduleRoot): void
    {
        $path = getenv('EIND_M1_MIGRATION_CONFIG');
        if (!$path) { throw new \RuntimeException('Explicit M1 migration configuration required.'); }
        $config = M1Configuration::configuration($path, $moduleRoot);
        $pdo = new \PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
            $config['user'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_TIMEOUT => 5]);
        $reader = new PdoM1Reader($pdo, $config['prefix'], true);
        $reader->validateTarget($config);
        $mode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        if (!is_string($mode)) { throw new \RuntimeException('M1 SQL mode unavailable.'); }
        try {
            if (!preg_match('/(?:^|,)(STRICT_ALL_TABLES|STRICT_TRANS_TABLES)(?:,|$)/', $mode)) {
                $pdo->exec('SET SESSION sql_mode=' . $pdo->quote($mode === '' ? 'STRICT_ALL_TABLES' : $mode . ',STRICT_ALL_TABLES'));
            }
            $schema = new M1Schema($config['prefix'], static fn ($sql) => $pdo->exec($sql) !== false,
                static function ($sql) use ($pdo, $reader): array {
                    if (preg_match('/\ASELECT (GET_LOCK|RELEASE_LOCK)\(/', $sql)) {
                        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
                    }
                    return $reader->schemaSelect($sql);
                });
            $schema->install();
        } finally {
            $pdo->exec('SET SESSION sql_mode=' . $pdo->quote($mode));
        }
    }
}
