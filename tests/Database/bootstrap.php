<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/DisposableDatabaseGuard.php';

use EindSupplierSearch\Tests\Database\DisposableDatabaseGuard;

try {
    $applications = array_filter(array_map('trim', explode(',', getenv('EIND_TEST_APPLICATION_DATABASES') ?: '')));
    // Also deny application databases known locally, without booting PrestaShop.
    $parametersPath = dirname(__DIR__, 4) . '/app/config/parameters.php';
    if (is_file($parametersPath)) {
        $parameters = require $parametersPath;
        $applications[] = $parameters['parameters']['database_name'];
    }
    $config = [
        'disposable' => getenv('EIND_TEST_DISPOSABLE') ?: '',
        'database' => getenv('EIND_TEST_DB_NAME') ?: '',
        'confirmed_database' => getenv('EIND_TEST_DISPOSABLE_DATABASE') ?: '',
        'application_databases' => array_merge(['eind_dev'], $applications),
    ];
    if (!getenv('EIND_TEST_APPLICATION_DATABASES')) {
        throw new RuntimeException('Known application database denylist must be explicitly configured.');
    }
    DisposableDatabaseGuard::validateConfiguration($config);
    $host = getenv('EIND_TEST_DB_HOST') ?: '';
    $port = getenv('EIND_TEST_DB_PORT') ?: '3306';
    $user = getenv('EIND_TEST_DB_USER') ?: '';
    if (!preg_match('/\A[A-Za-z0-9_.-]+\z/D', $host) || !ctype_digit($port)
        || (int) $port < 1 || (int) $port > 65535 || $user === '') {
        throw new RuntimeException('Explicit disposable database connection settings are required.');
    }
    $pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $user, getenv('EIND_TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    DisposableDatabaseGuard::assertSafe($pdo, $config);
    $GLOBALS['offer_binding_test_pdo'] = $pdo;
    $GLOBALS['offer_binding_test_config'] = $config;
} catch (Throwable $error) {
    // Never render PDO exceptions or credentials through PHPUnit bootstrap errors.
    throw new RuntimeException('Isolated database preflight failed; no database mutation was authorized.');
}
