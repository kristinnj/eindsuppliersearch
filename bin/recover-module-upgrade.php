#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new RuntimeException('Recovery runtime failure.'); });
use EindSupplierSearch\Infrastructure\Verification\VerificationSource;
use EindSupplierSearch\Infrastructure\Verification\PdoUpgradeReader;
use EindSupplierSearch\Infrastructure\Recovery\PdoRecoverySession;
use EindSupplierSearch\Infrastructure\Recovery\RegistryRecovery;
use EindSupplierSearch\Infrastructure\Recovery\RecoveryAuthorization;

try {
    $root = dirname(__DIR__);
    foreach (['Infrastructure/Database/OfferBindingReference', 'Infrastructure/Database/OfferBindingSchema',
        'Infrastructure/Verification/UpgradeVerification', 'Infrastructure/Verification/VerificationSource',
        'Infrastructure/Verification/PdoUpgradeReader', 'Infrastructure/Recovery/RecoveryAuthorization',
        'Infrastructure/Recovery/RegistryRecovery', 'Infrastructure/Recovery/PdoRecoverySession'] as $class) {
        require_once $root . '/src/' . $class . '.php';
    }
    $arguments = array_slice($argv, 1);
    if (PHP_SAPI !== 'cli' || count($arguments) !== count(array_unique($arguments))
        || array_diff($arguments, ['--json', '--dry-run', '--recover']) !== []
        || (in_array('--dry-run', $arguments, true) && in_array('--recover', $arguments, true))) {
        throw new RuntimeException('Invalid recovery arguments.');
    }
    $readPath = getenv('EIND_VERIFY_CONFIG');
    if (!$readPath) { throw new RuntimeException('Reader configuration required.'); }
    $config = VerificationSource::configuration($readPath, $root);
    $connect = static function (array $settings): PDO {
        return new PDO('mysql:host=' . $settings['host'] . ';port=' . $settings['port'] . ';dbname=' . $settings['database'] . ';charset=utf8mb4',
            $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    };
    $reader = new PdoUpgradeReader($connect($config), $config['prefix']);
    $writer = static function () use ($config, $connect, $root): PDO {
        $path = getenv('EIND_RECOVERY_CONFIG');
        if (!$path) { throw new RuntimeException('Recovery credentials required.'); }
        $settings = VerificationSource::configuration($path, $root);
        foreach (['host', 'port', 'database', 'prefix', 'server_hostname', 'metadata_definer'] as $key) {
            if ($settings[$key] !== $config[$key]) { throw new RuntimeException('Recovery target changed.'); }
        }
        return $connect($settings);
    };
    $session = new PdoRecoverySession($reader, $config, $root, $writer);
    $independentGate = static function () use ($root): array {
        $process = proc_open([PHP_BINARY, $root . '/bin/verify-module-upgrade.php', '--json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Independent verifier unavailable.'); }
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        $gate = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($gate) || $error !== '' || ($gate['exit_code'] ?? null) !== $exit) {
            throw new RuntimeException('Independent verifier incomplete.');
        }
        return $gate;
    };
    $coordinator = new RegistryRecovery($session->inspect(...), $session->lock(...), $session->unlock(...), $session->create(...), $independentGate);
    $authorization = null;
    $result = $coordinator->run(in_array('--recover', $arguments, true), static function (array $plan) use (&$authorization): bool {
        $authorization = new RecoveryAuthorization($plan, time(...));
        // Challenge only on stderr; stdout stays a single final JSON object.
        fwrite(STDERR, json_encode(['authorization_challenge' => $authorization->challenge()], JSON_THROW_ON_ERROR) . PHP_EOL);
        $deadline = time() + 120;
        $line = '';
        stream_set_blocking(STDIN, false);
        while (time() < $deadline && strlen($line) <= 8192) {
            $read = [STDIN]; $write = []; $except = [];
            if (stream_select($read, $write, $except, 1) > 0) {
                $chunk = fread(STDIN, 8193);
                if ($chunk === false || ($chunk === '' && feof(STDIN))) { return false; }
                $line .= $chunk;
                if (str_ends_with($line, "\n")) {
                    try { $response = json_decode($line, true, 16, JSON_THROW_ON_ERROR); }
                    catch (Throwable) { return false; }
                    return is_array($response) && $authorization->consume($plan, $response);
                }
            }
        }
        return false;
    }, static function (array $plan) use (&$authorization): bool { return $authorization?->validFor($plan) ?? false; });
} catch (Throwable) {
    $result = ['contract_version' => 1, 'status' => 'FAILED', 'exit_code' => 2,
        'module' => 'eindsuppliersearch', 'code' => 'RECOVERY_ENVIRONMENT_FAILURE', 'mutation_performed' => null, 'deployment_ready' => false];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['exit_code']);
