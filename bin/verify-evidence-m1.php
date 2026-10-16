#!/usr/bin/env php
<?php

declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new RuntimeException('M1 runtime failure.'); });
$root = dirname(__DIR__);
try {
    foreach (['Database/OfferBindingReference', 'Database/OfferBindingSchema', 'Database/M1IntegrityException',
        'Database/M1Schema', 'Verification/M1Configuration', 'Verification/PdoM1Reader', 'Verification/M1Verification'] as $file) {
        require_once $root . '/src/Infrastructure/' . $file . '.php';
    }
    $args = array_slice($argv, 1); $json = in_array('--json', $args, true);
    sort($args);
    $expected = ['--mode=structure', '--phase=M1']; if ($json) { $expected[] = '--json'; } sort($expected);
    if (PHP_SAPI !== 'cli' || $args !== $expected) { throw new RuntimeException('Explicit M1 structure scope required.'); }
    $path = getenv('EIND_M1_VERIFY_CONFIG'); if (!$path) { throw new RuntimeException('Configuration required.'); }
    $config = \EindSupplierSearch\Infrastructure\Verification\M1Configuration::configuration($path, $root);
    $pdo = new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    $reader = new \EindSupplierSearch\Infrastructure\Verification\PdoM1Reader($pdo, $config['prefix']);
    $schema = new \EindSupplierSearch\Infrastructure\Database\M1Schema($config['prefix'],
        static function (): never { throw new LogicException('M1 verifier mutation prohibited.'); }, $reader->schemaSelect(...));
    $result = \EindSupplierSearch\Infrastructure\Verification\M1Verification::run(
        static fn () => $reader->validateTarget($config), $schema->prerequisite(...), $schema->enforcement(...),
        $schema->unexpected(...), $schema->verifyTable(...));
} catch (Throwable) {
    $result = class_exists(\EindSupplierSearch\Infrastructure\Verification\M1Verification::class)
        ? \EindSupplierSearch\Infrastructure\Verification\M1Verification::failure('CONFIGURATION_OR_CONNECTION_FAILURE')
        : ['contract_version' => 1, 'mode' => 'structure', 'phase' => 'M1', 'exit_code' => 2, 'commerce_eligible' => false,
            'manifest_version' => 'M1-v1', 'manifest_sha256' => [
                'eind_observation'=>'68b374dcf67e4f7c54d01de1ce73be58de6dfba591815bd4880a9d734874230f',
                'eind_quote'=>'a4d69f1574d96e86d9896d16d06adc30be804906f4720a30743ac13a8a2573ea'],
            'inspected_object_count'=>0, 'status' => 'FAIL', 'checks' => [['name'=>'environment','status'=>'ERROR','code' => 'BOOTSTRAP_FAILURE']],
            'unassessed' => ['authentication','observation_content','quotes','creation_history','native_products','cart','checkout','order_attribution','history_validity','deployment_authorization']];
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (!($json ?? false)) { fwrite(STDERR, 'M1 structure: ' . $result['status'] . '; commerce_eligible=false; exit ' . $result['exit_code'] . PHP_EOL); }
exit($result['exit_code']);
