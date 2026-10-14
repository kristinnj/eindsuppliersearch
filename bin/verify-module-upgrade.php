#!/usr/bin/env php
<?php

declare(strict_types=1);

// No PrestaShop, Composer, module constructor, caches or executable config loaded.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new RuntimeException('Verification runtime failure.'); });
$root = dirname(__DIR__);
try {
foreach (['Infrastructure/Database/OfferBindingReference', 'Infrastructure/Database/OfferBindingSchema',
    'Infrastructure/Verification/UpgradeVerification', 'Infrastructure/Verification/VerificationSource',
    'Infrastructure/Verification/PdoUpgradeReader'] as $class) {
    require_once $root . '/src/' . $class . '.php';
}
} catch (Throwable) {
    echo json_encode(['contract_version' => 1, 'status' => 'FAIL', 'exit_code' => 2,
        'module' => 'eindsuppliersearch', 'checks' => [['name' => 'environment', 'status' => 'ERROR', 'code' => 'BOOTSTRAP_FAILURE']]]) . PHP_EOL;
    exit(2);
}
use EindSupplierSearch\Infrastructure\Verification\UpgradeVerification;
use EindSupplierSearch\Infrastructure\Verification\VerificationSource;
use EindSupplierSearch\Infrastructure\Verification\PdoUpgradeReader;

try {
    if (PHP_SAPI !== 'cli' || !in_array(array_slice($argv, 1), [[], ['--json']], true)) {
        throw new RuntimeException('Unsupported arguments.');
    }
    $source = VerificationSource::read($root);
    $path = getenv('EIND_VERIFY_CONFIG');
    if (!$path) {
        throw new RuntimeException('Configuration required.');
    }
    $config = VerificationSource::configuration($path, $root);
} catch (Throwable) {
    $result = UpgradeVerification::failure('CONFIGURATION_FAILURE');
}
if (!isset($result)) {
    try {
        $pdo = new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
            $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
        $reader = new PdoUpgradeReader($pdo, $config['prefix']);
        $identity = $reader->validateTarget($config);
        $gate = new UpgradeVerification($reader->registration(...), $reader->registry(...));
        $result = $gate->verify($source['version'], $source['agrees']);
        $result['environment'] = $identity;
    } catch (Throwable) {
        $result = UpgradeVerification::failure('DATABASE_ACCESS_FAILURE');
    }
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($result['exit_code']);
