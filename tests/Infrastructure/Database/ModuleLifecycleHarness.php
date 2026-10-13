<?php

// Test-only core substitutes. Never load this file in web execution.
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '9.1.0');
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'test_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

class Module
{
    public $name, $tab, $version, $author, $need_instance, $bootstrap, $ps_versions_compliancy;
    public $displayName, $description, $confirmUninstall;
    public $_errors = [];
    public function __construct() {}
    public function l($message) { return $message; }
    public function install() { return true; }
    public function uninstall() { return true; }
    public function registerHook($name) { return true; }
    public function unregisterHook($name) { return true; }
}

class Db
{
    public static array $statements = [];
    public static ?Closure $executor = null;
    public static ?Closure $reader = null;
    public static function getInstance() { return new self(); }
    public function execute($sql) {
        self::$statements[] = $sql;
        return self::$executor === null ? true : (self::$executor)($sql);
    }
    public function executeS($sql, $array = true, $cache = true) {
        if ($cache !== false) {
            throw new RuntimeException('Schema metadata caching must be disabled.');
        }
        return self::$reader === null ? false : (self::$reader)($sql);
    }
}

class Configuration
{
    public static function updateValue($key, $value) { return true; }
    public static function deleteByName($key) { return true; }
}

class Hook
{
    public static function getIdByName($name) { return false; }
}

function pSQL($value) { return $value; }
function bqSQL($value) { return $value; }

require_once dirname(__DIR__, 3) . '/eindsuppliersearch.php';
