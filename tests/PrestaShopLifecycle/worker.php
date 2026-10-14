<?php

declare(strict_types=1);

require __DIR__ . '/Guard.php';
$workerChecks = 0;

function expect(bool $condition, string $message): void
{
    ++$GLOBALS['workerChecks'];
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tableExists(PDO $pdo, string $name): bool
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $query->execute([$name]);
    return (int) $query->fetchColumn() === 1;
}

function registryState(PDO $pdo): array
{
    if (!tableExists($pdo, 'life_eind_offer_binding')) {
        return ['exists' => false];
    }
    $rows = $pdo->query('SELECT * FROM life_eind_offer_binding ORDER BY id_binding')->fetchAll();
    return ['exists' => true, 'rows' => count($rows), 'data' => hash('sha256', serialize($rows)),
        'ddl' => hash('sha256', $pdo->query('SHOW CREATE TABLE life_eind_offer_binding')->fetch(PDO::FETCH_NUM)[1])];
}

function moduleVersion(PDO $pdo): ?string
{
    $value = $pdo->query("SELECT version FROM life_module WHERE name='eindsuppliersearch'")->fetchColumn();
    return $value === false ? null : $value;
}

function configurationState(PDO $pdo): string
{
    return hash('sha256', serialize($pdo->query("SELECT * FROM life_configuration WHERE name LIKE 'EIND\\_%' ORDER BY id_configuration")->fetchAll()));
}

function moduleRecords(PDO $pdo): string
{
    $rows = [];
    foreach (['life_eind_search_results', 'life_eind_search_suppliers'] as $table) {
        $rows[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
    }
    return hash('sha256', serialize($rows));
}

function schemaValid(): bool
{
    \EindSupplierSearch\Infrastructure\Database\OfferBindingSchema::forPrestaShop()->verify();
    return true;
}

function installSchema(): bool
{
    LifecycleGuard::connect();
    $module = Module::getInstanceByName('eindsuppliersearch');
    return $module->installOfferBindingSchema();
}

function fixture(PDO $pdo): void
{
    LifecycleGuard::connect();
    $statement = $pdo->prepare('INSERT INTO life_eind_offer_binding '
        . '(id_shop,id_supplier,order_reference,reference_sha256,id_product,id_product_attribute,state,provenance,'
        . 'creation_operation_id,integrity_reason,created_at,updated_at) VALUES (1,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([[901, "001-Ab \0é", null, 'RESERVED'], [902, 'ACTIVE-fixture', 900001, 'ACTIVE'],
        [903, 'QUARANTINED-fixture', 900002, 'QUARANTINED'], [904, 'RETIRED-fixture', 900003, 'RETIRED']] as [$supplier, $reference, $product, $state]) {
        LifecycleGuard::connect();
        $statement->execute([$supplier, $reference, hash('sha256', $reference, true), $product,
            $product === null ? null : 0, $state, 'MODULE_CREATED_LIVE', random_bytes(16),
            $state === 'QUARANTINED' ? 'TEST_ONLY' : null, '2026-10-13 00:00:00.000001', '2026-10-13 00:00:00.000001']);
    }
    $reference = $pdo->query('SELECT order_reference FROM life_eind_offer_binding WHERE id_supplier=901')->fetchColumn();
    expect($reference === "001-Ab \0é", 'Exact byte fixture round trip failed.');
}

function copyModule(string $source): void
{
    $destination = '/var/www/html/modules/eindsuppliersearch';
    // Paths are fixed to disposable storage; no host source path is writable.
    if (is_dir($destination)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($destination);
    }
    mkdir($destination);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        if (str_starts_with($relative, '.git') || str_starts_with($relative, 'vendor-dev/') || $relative === 'vendor-dev') {
            continue;
        }
        $path = $destination . '/' . $relative;
        $file->isDir() ? mkdir($path) : copy($file->getPathname(), $path);
    }
}

try {
    $action = $argv[1] ?? '';
    $pdo = LifecycleGuard::connect();
    if ($action === 'stage') {
        expect(in_array($argv[2] ?? '', ['current', 'historical'], true), 'Unknown source stage.');
        copyModule($argv[2] === 'current' ? '/test-source' : '/test-historical');
        echo json_encode(['staged' => $argv[2]]) . PHP_EOL;
        exit;
    }
    $_SERVER['HTTP_HOST'] = 'lifecycle.invalid';
    $_SERVER['SERVER_NAME'] = 'lifecycle.invalid';
    $_SERVER['REQUEST_URI'] = '/';
    require '/var/www/html/config/config.inc.php';
    expect(_PS_VERSION_ === '9.1.0', 'Unexpected native core version.');
    expect(_DB_NAME_ === getenv('HLUTIRIS_LIFECYCLE_DATABASE') && _DB_SERVER_ === getenv('HLUTIRIS_LIFECYCLE_HOST'), 'Core connection refused.');
    Context::getContext()->employee = new Employee(1);
    $kernel = new AdminKernel('prod', false);
    $kernel->boot();
    $kernel->getContainer()->get(\PrestaShop\PrestaShop\Core\Context\ContextBuilderPreparer::class)
        ->prepareFromLegacyContext(Context::getContext());
    if (is_file(_PS_MODULE_DIR_ . 'eindsuppliersearch/eindsuppliersearch.php')) {
        expect(Module::getInstanceByName('eindsuppliersearch') instanceof Module, 'Native module class loading failed.');
    }
    $before = LifecycleGuard::protectedData($pdo);
    $nativeModeBefore = Db::getInstance()->executeS('SELECT @@SESSION.sql_mode AS mode', true, false)[0]['mode'];
    $result = [];
    switch ($action) {
        case 'baseline':
            expect(moduleVersion($pdo) === null, 'Module unexpectedly registered in pristine image.');
            expect(!tableExists($pdo, 'life_eind_offer_binding'), 'Registry unexpectedly exists.');
            expect(count($before) > 10, 'Missing genuine native schema.');
            file_put_contents('/var/www/html/.lifecycle-protected-baseline.json', json_encode($before, JSON_THROW_ON_ERROR));
            $result = ['prestashop' => _PS_VERSION_, 'php' => PHP_VERSION, 'mariadb' => $pdo->query('SELECT VERSION()')->fetchColumn(),
                'protected_tables' => count($before), 'protected_rows' => array_sum(array_column($before, 'rows'))];
            break;
        case 'install':
            expect(moduleVersion($pdo) === null, 'Install requires unregistered module.');
            expect(!tableExists($pdo, 'life_eind_offer_binding'), 'Fresh install requires absent registry.');
            $module = Module::getInstanceByName('eindsuppliersearch');
            expect($module instanceof Module && $module->version === $argv[2], 'Source version mismatch.');
            $xml = simplexml_load_file(_PS_MODULE_DIR_ . 'eindsuppliersearch/config.xml');
            expect((string) $xml->version === $argv[2], 'XML version mismatch.');
            LifecycleGuard::connect();
            $success = $module->install();
            $registry = tableExists($pdo, 'life_eind_offer_binding');
            $result = ['success' => $success, 'version' => moduleVersion($pdo), 'source_version' => $module->version,
                'xml_version' => (string) $xml->version, 'registry_exists' => $registry,
                'registry_count' => $registry ? (int) $pdo->query('SELECT COUNT(*) FROM life_eind_offer_binding')->fetchColumn() : 0,
                'schema_valid' => $registry ? schemaValid() : false];
            expect($success, 'Native module install failed: ' . implode(' ', $module->getErrors()));
            if ($argv[2] === '1.0.0') {
                LifecycleGuard::connect();
                $pdo->prepare('INSERT INTO life_eind_search_results (session_id,created_at,product_list) VALUES (?,?,?)')
                    ->execute(['p002d-test-only', '2026-10-13 00:00:00', '{"isolated_fixture":true}']);
            }
            break;
        case 'reset':
            if (moduleVersion($pdo) !== null) {
                LifecycleGuard::connect();
                expect(Module::getInstanceByName('eindsuppliersearch')->uninstall(), 'Native reset uninstall failed.');
            }
            LifecycleGuard::connect();
            $pdo->exec('DROP TABLE IF EXISTS life_eind_offer_binding');
            $result = ['reset' => true];
            break;
        case 'repeat':
            fixture($pdo);
            $snapshot = registryState($pdo);
            expect(installSchema() && installSchema() && installSchema(), 'Repeated migration failed.');
            $result = ['unchanged' => $snapshot === registryState($pdo), 'schema_valid' => schemaValid(), 'records' => $snapshot['rows']];
            break;
        case 'uninstall':
            $snapshot = registryState($pdo);
            $moduleId = (int) $pdo->query("SELECT id_module FROM life_module WHERE name='eindsuppliersearch'")->fetchColumn();
            file_put_contents('/var/www/html/.lifecycle-retained.json', json_encode($snapshot));
            LifecycleGuard::connect();
            $success = Module::getInstanceByName('eindsuppliersearch')->uninstall();
            $result = ['success' => $success, 'retained' => $snapshot === registryState($pdo),
                'cleanup_valid' => moduleVersion($pdo) === null
                    && (int) $pdo->query('SELECT COUNT(*) FROM life_hook_module WHERE id_module=' . $moduleId)->fetchColumn() === 0
                    && !tableExists($pdo, 'life_eind_search_results')
                    && !tableExists($pdo, 'life_eind_search_suppliers')
                    && (int) $pdo->query("SELECT COUNT(*) FROM life_configuration WHERE name IN ('EIND_SUPPLIERSEARCH_API_MODE','EIND_GROUPED_SEARCH_UI')")->fetchColumn() === 0];
            break;
        case 'reinstall':
            $snapshot = json_decode(file_get_contents('/var/www/html/.lifecycle-retained.json'), true, 512, JSON_THROW_ON_ERROR);
            LifecycleGuard::connect();
            $success = Module::getInstanceByName('eindsuppliersearch')->install();
            $result = ['success' => $success, 'retained' => $snapshot === registryState($pdo),
                'version' => moduleVersion($pdo), 'schema_valid' => schemaValid()];
            break;
        case 'upgrade':
            expect(moduleVersion($pdo) === '1.0.0', 'Upgrade requires genuine historical registered version.');
            $module = Module::getInstanceByName('eindsuppliersearch');
            expect($module->version === '1.1.0', 'Upgrade source mismatch.');
            $configuration = configurationState($pdo);
            $moduleData = moduleRecords($pdo);
            $registry = registryState($pdo);
            LifecycleGuard::connect();
            // Follow native ModuleManager preparation: disk metadata supplies installed version.
            $metadata = null;
            foreach (Module::getModulesOnDisk() as $onDisk) {
                if ($onDisk->name === 'eindsuppliersearch') {
                    $metadata = $onDisk;
                    break;
                }
            }
            expect($metadata !== null && $metadata->installed && $metadata->database_version === '1.0.0', 'Native disk metadata lost historical version.');
            expect(Module::initUpgradeModule($metadata) === true, 'Native runner did not discover script.');
            expect(Module::getUpgradeStatus('eindsuppliersearch') === false, 'Runner reported success before execution.');
            // Read-only inspection of core's discovered list; no cache/state is fabricated.
            $cache = (new ReflectionProperty(Module::class, 'modules_cache'))->getValue();
            $initial = $cache['eindsuppliersearch']['upgrade'];
            expect(count($initial['upgrade_file_left']) === 1, 'Unexpected native upgrade file count.');
            $file = reset($initial['upgrade_file_left']);
            expect(basename($file['file']) === 'upgrade-1.1.0.php' && $file['version'] === '1.1.0', 'Native runner discovered wrong script.');
            expect(!function_exists('upgrade_module_1_1_0'), 'Upgrade function prematurely loaded.');
            LifecycleGuard::connect();
            $status = $module->runUpgradeModule();
            expect(function_exists('upgrade_module_1_1_0'), 'Native runner never included upgrade function.');
            $result = ['success' => (bool) $status['success'], 'available' => $status['available_upgrade'],
                'executed' => $status['number_upgraded'], 'upgraded_to' => $status['upgraded_to'],
                'version_fail' => $status['version_fail'], 'version' => moduleVersion($pdo),
                'enabled' => (bool) $pdo->query("SELECT active FROM life_module WHERE name='eindsuppliersearch'")->fetchColumn(),
                'shop_enabled_rows' => (int) $pdo->query("SELECT COUNT(*) FROM life_module_shop WHERE id_module=(SELECT id_module FROM life_module WHERE name='eindsuppliersearch')")->fetchColumn(),
                'configuration_unchanged' => $configuration === configurationState($pdo),
                'module_records_unchanged' => $moduleData === moduleRecords($pdo),
                'registry_unchanged' => $registry === registryState($pdo), 'errors' => $module->getErrors()];
            if ($status['success']) {
                $result['schema_valid'] = schemaValid();
                expect($pdo->query('SELECT COUNT(*) FROM life_eind_offer_binding')->fetchColumn() == 0, 'Upgrade populated bindings.');
            }
            break;
        case 'direct':
            $snapshot = registryState($pdo);
            require _PS_MODULE_DIR_ . 'eindsuppliersearch/upgrade/upgrade-1.1.0.php';
            LifecycleGuard::connect();
            $result = ['success' => upgrade_module_1_1_0(Module::getInstanceByName('eindsuppliersearch')),
                'unchanged' => $snapshot === registryState($pdo)];
            break;
        case 'interrupted':
            LifecycleGuard::connect();
            $pdo->exec('DROP TABLE life_eind_offer_binding');
            // Simulate interruption precisely after canonical DDL, before verification.
            $schema = \EindSupplierSearch\Infrastructure\Database\OfferBindingSchema::forPrestaShop();
            LifecycleGuard::connect();
            $pdo->exec($schema->ddl());
            fixture($pdo);
            $snapshot = registryState($pdo);
            $result = ['success' => installSchema(), 'unchanged' => $snapshot === registryState($pdo)];
            break;
        case 'retry-discovery':
            expect(moduleVersion($pdo) === '1.1.0', 'Retry requires advanced registered version.');
            foreach (Module::getModulesOnDisk() as $onDisk) {
                if ($onDisk->name === 'eindsuppliersearch') {
                    $discovered = Module::initUpgradeModule($onDisk);
                    break;
                }
            }
            $cache = (new ReflectionProperty(Module::class, 'modules_cache'))->getValue();
            $result = ['discovered' => $discovered,
                'available' => $cache['eindsuppliersearch']['upgrade']['available_upgrade'],
                'function_loaded' => function_exists('upgrade_module_1_1_0')];
            expect(!$discovered && $result['available'] === 0 && !$result['function_loaded'], 'Retry unexpectedly discovered migration.');
            break;
        case 'observe':
            $valid = false;
            try {
                $valid = schemaValid();
            } catch (RuntimeException $error) {
                // Incompatible/missing schema remains failed diagnostic evidence.
            }
            $result = ['version' => moduleVersion($pdo),
                'enabled' => (bool) $pdo->query("SELECT active FROM life_module WHERE name='eindsuppliersearch'")->fetchColumn(),
                'schema_valid' => $valid,
                'shop_enabled_rows' => (int) $pdo->query("SELECT COUNT(*) FROM life_module_shop WHERE id_module=(SELECT id_module FROM life_module WHERE name='eindsuppliersearch')")->fetchColumn(),
                'registry' => registryState($pdo), 'configuration' => configurationState($pdo), 'module_records' => moduleRecords($pdo)];
            break;
        case 'state':
            $result = registryState($pdo);
            break;
        case 'migrate':
            $result = ['success' => installSchema()];
            break;
        case 'incompatible':
            expect(!tableExists($pdo, 'life_eind_offer_binding'), 'Failure fixture requires absent registry.');
            LifecycleGuard::connect();
            $pdo->exec(\EindSupplierSearch\Infrastructure\Database\OfferBindingSchema::forPrestaShop()->ddl());
            fixture($pdo);
            $changes = ['index' => 'DROP INDEX uq_offer_identity',
                'column' => 'MODIFY reference_sha256 VARBINARY(32) NOT NULL',
                'check' => 'DROP CONSTRAINT ck_eob_hash', 'engine' => 'ENGINE=MyISAM'];
            expect(isset($changes[$argv[2]]), 'Unknown failure fixture.');
            LifecycleGuard::connect();
            $pdo->exec('ALTER TABLE life_eind_offer_binding ' . $changes[$argv[2]]);
            $result = registryState($pdo);
            break;
        default:
            throw new RuntimeException('Unknown lifecycle action.');
    }
    expect($before === LifecycleGuard::protectedData($pdo), 'Lifecycle changed protected catalog or commerce records.');
    LifecycleGuard::connect();
    $nativeModeAfter = Db::getInstance()->executeS('SELECT @@SESSION.sql_mode AS mode', true, false)[0]['mode'];
    expect($nativeModeBefore === $nativeModeAfter, 'Native session SQL mode was not restored.');
    $result['native_sql_mode_before'] = $nativeModeBefore;
    $result['native_sql_mode_after'] = $nativeModeAfter;
    $result['catalog_commerce_unchanged'] = true;
    $result['worker_checks'] = $GLOBALS['workerChecks'];
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
} catch (Throwable $error) {
    // Never render core stack traces, database credentials or connection strings.
    fwrite(STDERR, 'Disposable lifecycle worker failed: ' . get_class($error) . ' in ' . basename($error->getFile()) . ':' . $error->getLine() . '. ' .
        ($error instanceof PDOException ? 'Database operation rejected.' : $error->getMessage()) . PHP_EOL);
    exit(1);
}
