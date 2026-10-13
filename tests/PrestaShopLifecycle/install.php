<?php

declare(strict_types=1);

require __DIR__ . '/Guard.php';
try {
    $pdo = LifecycleGuard::connect(true);
    $pdo->exec('CREATE TABLE p002d_test_authorization (run_id VARCHAR(64) PRIMARY KEY, token CHAR(48) NOT NULL) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO p002d_test_authorization VALUES (?,?)')->execute([
        getenv('HLUTIRIS_LIFECYCLE_RUN'), getenv('HLUTIRIS_LIFECYCLE_TOKEN'),
    ]);
    $argv = ['/var/www/html/install/index_cli.php'];
    foreach (['domain' => 'lifecycle.invalid', 'db_server' => getenv('HLUTIRIS_LIFECYCLE_HOST'),
        'db_name' => getenv('HLUTIRIS_LIFECYCLE_DATABASE'), 'db_user' => 'lifecycle_test',
        'db_password' => getenv('HLUTIRIS_LIFECYCLE_PASSWORD'), 'prefix' => 'life_',
        'db_clear' => '0', 'db_create' => '0', 'firstname' => 'Lifecycle', 'lastname' => 'Test',
        'password' => getenv('HLUTIRIS_LIFECYCLE_ADMIN_PASSWORD'), 'email' => 'lifecycle@example.invalid',
        'language' => 'en', 'country' => 'is', 'timezone' => 'Atlantic/Reykjavik',
        'fixtures' => '1', 'modules' => 'ps_emailalerts', 'ssl' => '0'] as $key => $value) {
        $argv[] = '--' . $key . '=' . $value;
    }
    $argc = count($argv);
    require '/var/www/html/install/index_cli.php';
} catch (Throwable $error) {
    fwrite(STDERR, 'Disposable native installer preflight failed: ' . get_class($error) . '. ' . ($error instanceof PDOException ? 'Database operation rejected.' : $error->getMessage()) . PHP_EOL);
    exit(1);
}
