<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Database;

/** Test-only fail-closed guard. No database mutation is performed here. */
final class DisposableDatabaseGuard
{
    public static function validateConfiguration(array $config): void
    {
        if (($config['disposable'] ?? '') !== 'YES'
            || !preg_match('/\Ahlutiris_offer_binding_test_[a-z0-9_]+\z/D', $config['database'] ?? '')
            || ($config['confirmed_database'] ?? '') !== ($config['database'] ?? '')
            || empty($config['application_databases'])
            || in_array(strtolower($config['database'] ?? ''), array_map('strtolower', $config['application_databases'] ?? []), true)
            || strtolower($config['database'] ?? '') === 'eind_dev') {
            throw new \RuntimeException('Disposable database identity could not be established.');
        }
    }

    public static function validateMetadata(array $config, string $actualDatabase, array $tables): void
    {
        self::validateConfiguration($config);
        if ($actualDatabase !== $config['database']) {
            throw new \RuntimeException('Connected database does not match the disposable database.');
        }
        foreach ($tables as $table) {
            if (preg_match('/(?:^|_)(?:product(?:_.*)?|customer(?:_.*)?|cart(?:_.*)?|orders?|order_.*|supplier(?:_.*)?|shop(?:_.*)?|configuration(?:_.*)?|module(?:_.*)?|address(?:_.*)?|guest|stock(?:_.*)?)\z/i', $table)) {
                throw new \RuntimeException('Application tables detected; database mutation refused.');
            }
        }
    }

    /** Explicit opt-in only for this suite's six connection-local native fixtures. */
    public static function validateTableMetadata(array $config, string $actualDatabase, array $tables, bool $temporaryNativeFixtures = false): void
    {
        $names = [];
        foreach ($tables as $table) {
            if ($temporaryNativeFixtures && ($table['TABLE_TYPE'] ?? null) === 'TEMPORARY'
                && in_array($table['TABLE_NAME'] ?? null, ['test_product', 'test_product_shop',
                    'test_product_supplier', 'test_supplier', 'test_supplier_shop', 'test_shop'], true)) {
                continue;
            }
            if (!isset($table['TABLE_NAME']) || !is_string($table['TABLE_NAME'])) {
                throw new \RuntimeException('Invalid database metadata; mutation refused.');
            }
            $names[] = $table['TABLE_NAME'];
        }
        self::validateMetadata($config, $actualDatabase, $names);
    }

    public static function assertSafe(\PDO $pdo, array $config, bool $temporaryNativeFixtures = false): void
    {
        $actual = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $tables = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(\PDO::FETCH_ASSOC);
        self::validateTableMetadata($config, (string) $actual, $tables, $temporaryNativeFixtures);
    }
}
