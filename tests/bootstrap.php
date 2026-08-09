<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
// modules/eindsuppliersearch/tests -> modules/eindsuppliersearch -> modules -> repo root.
$repoRoot = dirname($moduleRoot, 2);

if (!defined('_PS_ROOT_DIR_')) {
    // Only used by the legacy files below to resolve a require_once path;
    // matches /var/www/html in the real container, where `modules/` also
    // sits directly under the PrestaShop root.
    define('_PS_ROOT_DIR_', $repoRoot);
}

/*
 * Deliberately vendor-dev/, not vendor/: PrestaShop core's
 * ContainerBuilder::loadModulesAutoloader() unconditionally include_once's
 * <every installed module>/vendor/autoload.php on every Symfony container
 * build (i.e. every back-office request), regardless of what this module's
 * own PHP does. Since this composer.json's require-dev (PHPUnit) pulls in
 * nikic/php-parser ^5 while PrestaShop core bundles v4.x, a vendor/
 * directory here would shadow core's copy admin-wide. Renaming the
 * Composer vendor-dir (see composer.json's config.vendor-dir) keeps that
 * path out of core's literal "modules/<name>/vendor/autoload.php" check.
 */
require $moduleRoot . '/vendor-dev/autoload.php';

/*
 * EindCallSupplierApi/EindApiDatabase only touch PrestaShop core classes
 * (Db, Context, Supplier, ...) inside method bodies, not at parse time, so
 * they can be required directly here without a running PrestaShop. This
 * lets LiveSupplierProvider be constructed and interface-checked in
 * PHPUnit; its search() method (which does need PrestaShop + network) is
 * intentionally not exercised by the automated suite.
 */
require_once $moduleRoot . '/controllers/front/callsupplierapi.php';
require_once $moduleRoot . '/controllers/front/apidatabase.php';
