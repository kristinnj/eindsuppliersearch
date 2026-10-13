<?php

declare(strict_types=1);

/** Separate guard for a full native installation; ordinary DB guard stays unchanged. */
final class LifecycleGuard
{
    public static function validateConfiguration(array $settings): void
    {
        $run = $settings['run'] ?? '';
        $database = $settings['database'] ?? '';
        if (($settings['disposable'] ?? '') !== 'YES'
            || !is_string($run) || !preg_match('/\Ahlutiris-p002d-[a-f0-9]{12}\z/D', $run)
            || ($settings['host'] ?? '') !== $run . '-db'
            || $database !== 'hlutiris_lifecycle_test_' . substr($run, -12)
            || ($settings['user'] ?? '') !== 'lifecycle_test'
            || !is_string($settings['token'] ?? null) || strlen($settings['token']) !== 48
            || !is_string($settings['password'] ?? null) || strlen($settings['password']) !== 48
            || $database === 'eind_dev') {
            throw new RuntimeException('Disposable lifecycle configuration refused.');
        }
    }

    public static function connect(bool $initial = false): PDO
    {
        $settings = [];
        foreach (['disposable', 'run', 'database', 'host', 'user', 'token', 'password'] as $key) {
            $settings[$key] = getenv('HLUTIRIS_LIFECYCLE_' . strtoupper($key)) ?: '';
        }
        self::validateConfiguration($settings);
        $run = $settings['run'];
        $database = $settings['database'];
        $host = $settings['host'];
        $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4',
            'lifecycle_test', getenv('HLUTIRIS_LIFECYCLE_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        $identity = $pdo->query('SELECT DATABASE() AS db, @@hostname AS host, CURRENT_USER() AS user')->fetch();
        if ($identity['db'] !== $database || $identity['host'] !== $host || $identity['user'] !== 'lifecycle_test@%') {
            throw new RuntimeException('Connected lifecycle server identity refused.');
        }
        foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (!str_starts_with($grant, 'GRANT USAGE ON *.* TO ')
                && !(preg_match('/\AGRANT ALL PRIVILEGES ON `([^`]+)`\.\* TO /', $grant, $match)
                    && str_replace('\\_', '_', $match[1]) === $database)) {
                throw new RuntimeException('Unexpected disposable credential privileges.');
            }
        }
        if ($initial) {
            if ($pdo->query('SHOW TABLES')->fetchAll() !== []) {
                throw new RuntimeException('Installer refuses a nonempty database.');
            }
            return $pdo;
        }
        $marker = $pdo->query('SELECT run_id, token FROM p002d_test_authorization')->fetchAll();
        if (count($marker) !== 1 || $marker[0]['run_id'] !== $run
            || $marker[0]['token'] !== getenv('HLUTIRIS_LIFECYCLE_TOKEN')) {
            throw new RuntimeException('Disposable database authorization marker refused.');
        }
        $parameters = require '/var/www/html/app/config/parameters.php';
        foreach (['database_host' => $host, 'database_name' => $database, 'database_user' => 'lifecycle_test',
            'database_password' => getenv('HLUTIRIS_LIFECYCLE_PASSWORD'), 'database_prefix' => 'life_'] as $key => $expected) {
            if (($parameters['parameters'][$key] ?? null) !== $expected) {
                throw new RuntimeException('Installed connection differs from disposable authorization.');
            }
        }
        $baseline = '/var/www/html/.lifecycle-protected-baseline.json';
        if (is_file($baseline) && json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR) !== self::protectedData($pdo)) {
            throw new RuntimeException('Protected native fixture data differs from the pristine installer baseline.');
        }
        return $pdo;
    }

    public static function protectedData(PDO $pdo): array
    {
        $result = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (preg_match('/\Alife_(?:product(?:_.*)?|supplier(?:_.*)?|stock(?:_.*)?|cart(?:_.*)?|orders?|order_.*|customer(?:_.*)?|specific_price(?:_.*)?|address(?:_.*)?|guest)\z/D', $table)) {
                $rows = array_map('serialize', $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll());
                sort($rows, SORT_STRING);
                $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', serialize($rows))];
            }
        }
        ksort($result);
        return $result;
    }
}
