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

    public static function assertSafe(\PDO $pdo, array $config): void
    {
        $actual = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(\PDO::FETCH_COLUMN);
        self::validateMetadata($config, (string) $actual, $tables);
    }
}
