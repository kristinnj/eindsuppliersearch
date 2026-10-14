<?php

declare(strict_types=1);

// Isolated setup/evidence only. Never used by the production verifier.
require __DIR__ . '/Guard.php';
try {
    $pdo = LifecycleGuard::connect();
    $action = $argv[1] ?? '';
    if ($action === 'config') {
        $config = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        $path = '/var/www/html/' . ($argv[2] ?? 'gate.json');
        if (!in_array(basename($path), ['gate.json', 'gate-bad.json', 'gate-limited.json', 'gate-unavailable.json', 'gate-invalid.json', 'recovery.json'], true)) {
            throw new RuntimeException('Unknown test config path.');
        }
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        echo "{}\n";
    } elseif (in_array($action, ['snapshot', 'protected-snapshot'], true)) {
        $tables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($action === 'protected-snapshot' && $table === 'life_eind_offer_binding') { continue; }
            $rows = array_map('serialize', $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`')->fetchAll());
            sort($rows, SORT_STRING);
            $tables[$table] = [hash('sha256', serialize($rows)),
                hash('sha256', $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM)[1])];
        }
        echo json_encode(['database_snapshot' => hash('sha256', serialize($tables))]) . PHP_EOL;
    } elseif ($action === 'cli-missing-failure') {
        $settings = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        $path = '/var/www/html/app/config/parameters.php';
        $original = file_get_contents($path);
        $parameters = require $path;
        $parameters['parameters']['database_user'] = 'upgrade_no_create';
        $parameters['parameters']['database_password'] = $settings['password'];
        try {
            file_put_contents($path, '<?php return ' . var_export($parameters, true) . ';');
            $process = proc_open(['php', '/var/www/html/bin/console', 'prestashop:module', 'upgrade', 'eindsuppliersearch', '--env=prod', '--no-debug'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $output .= stream_get_contents($pipes[2]); fclose($pipes[2]);
            $exit = proc_close($process);
            $output = str_replace([$settings['password'], getenv('HLUTIRIS_LIFECYCLE_PASSWORD')], '[REDACTED]', $output);
            echo json_encode(['exit_code' => $exit, 'output' => $output]) . PHP_EOL;
        } finally {
            file_put_contents($path, $original);
        }
    } elseif ($action === 'probe') {
        $config = json_decode(file_get_contents('/var/www/html/gate.json'), true, 32, JSON_THROW_ON_ERROR);
        $reader = new PDO('mysql:host=' . $config['host'] . ';dbname=' . $config['database'], $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $denied = [];
        foreach (['UPDATE life_module SET active=active WHERE 1=0',
            'CREATE TABLE forbidden_gate_probe (id INT)',
            'INSERT INTO life_module (name,version,active) VALUES (\'forbidden_gate_probe\',\'0\',0)'] as $sql) {
            try { $reader->exec($sql); throw new RuntimeException('Read-only account accepted mutation.'); }
            catch (PDOException) { $denied[] = true; }
        }
        echo json_encode(['write_ddl_denials' => count($denied)]) . PHP_EOL;
    } elseif ($action === 'fixture') {
        switch ($argv[2] ?? '') {
            case 'alternate': $pdo->exec('CREATE TABLE life_eind_offer_binding_backup (id INT) ENGINE=InnoDB'); break;
            case 'alternate-remove': $pdo->exec('DROP TABLE life_eind_offer_binding_backup'); break;
            case 'metadata-view-missing': $pdo->exec('DROP VIEW life_eind_upgrade_trigger_metadata'); break;
            case 'missing': $pdo->exec('DROP TABLE life_eind_offer_binding'); break;
            case 'limit': $pdo->exec('ALTER TABLE life_eind_offer_binding DROP CONSTRAINT ck_eob_reference'); break;
            case 'trigger':
                $pdo->exec('CREATE TRIGGER gate_fixture BEFORE INSERT ON life_eind_offer_binding FOR EACH ROW SET NEW.id_shop=NEW.id_shop'); break;
            case 'multishop':
                $pdo->exec("INSERT INTO life_shop (id_shop_group,name,color,id_category,theme_name,active,deleted) SELECT id_shop_group,'Gate fixture',color,id_category,theme_name,1,0 FROM life_shop WHERE id_shop=1");
                break;
            case 'multishop-enable':
                $pdo->exec("INSERT INTO life_module_shop (id_module,id_shop,enable_device) SELECT id_module,(SELECT MAX(id_shop) FROM life_shop),7 FROM life_module WHERE name='eindsuppliersearch'"); break;
            case 'disable':
                $pdo->exec("DELETE FROM life_module_shop WHERE id_module=(SELECT id_module FROM life_module WHERE name='eindsuppliersearch')");
                $pdo->exec("UPDATE life_module SET active=0 WHERE name='eindsuppliersearch'"); break;
            case 'metadata':
                $path = '/var/www/html/modules/eindsuppliersearch/config.xml';
                $text = file_get_contents($path);
                file_put_contents($path, str_replace('1.1.0', '9.9.9', $text)); break;
            default: throw new RuntimeException('Unknown gate fixture.');
        }
        echo "{}\n";
    } else {
        throw new RuntimeException('Unknown gate support action.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Isolated gate support failed.\n");
    exit(1);
}
