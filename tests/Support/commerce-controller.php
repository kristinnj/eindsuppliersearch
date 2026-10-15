<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Isolated framework-free process exercising the real controller's dispatch.
$scenario = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
define('_PS_VERSION_', '9.1.5');
define('_PS_MODE_DEV_', $scenario['dev'] ?? false);
require dirname(__DIR__) . '/bootstrap.php';

class ModuleFrontController { public $context; public $module; public $ajax; }
class Tools {
    public static function getValue($key) { return $GLOBALS['scenario']['request'][$key] ?? false; }
    public static function getToken($unused) { return 'synthetic-csrf'; }
}
class PrestaShopLogger {
    public static array $logs = [];
    public static function addLog($message, $severity) { self::$logs[] = $message; }
}
class Configuration { public static function get($key) { return 1; } }
class Db {
    public static int $calls = 0;
    public static function getInstance() { ++self::$calls; return new self(); }
    public function getValue($sql) { return 111; }
}
if (!defined('_DB_PREFIX_')) { define('_DB_PREFIX_', 'test_'); }
function pSQL($value) { return $value; }

require dirname(__DIR__, 2) . '/controllers/front/addtocart.php';
$helper = new class {
    public array $calls = [];
    public function getStrictCachedOffer($session, $reference, $code) {
        $this->calls[] = 'getStrictCachedOffer';
        if ($GLOBALS['scenario']['cache_exception'] ?? false) {
            throw new RuntimeException('PRIVATE-CACHE-DATABASE-SENTINEL');
        }
        $buckets = $GLOBALS['scenario']['buckets'];
        return (new \EindSupplierSearch\Service\CachedOfferResolver())->resolve($buckets, $reference, $code);
    }
    public function __call($method, $arguments) {
        $this->calls[] = $method;
        return $method === 'getIdBySupplierReference' || $method === 'insertNewProduct' ? 111 : true;
    }
};
register_shutdown_function(static function () use ($helper): void {
    echo "\nAUDIT:" . json_encode(['calls'=>$helper->calls,'native_queries'=>Db::$calls,'logs'=>PrestaShopLogger::$logs]);
});
if (($scenario['mode'] ?? '') === 'legacy_lookup') {
    try {
        $id = (new EindAddToCartFunctions())->getIdBySupplierReference('SHARED');
        echo json_encode(['selected'=>$id]);
    } catch (Throwable $error) {
        echo json_encode(['exception'=>get_class($error)]);
    }
    exit;
}
$_SERVER['REQUEST_METHOD'] = $scenario['method'] ?? 'POST';
$controller = new EindsuppliersearchAddToCartModuleFrontController();
$controller->module = new class {
    public function l($message) { return $message; }
    public function ensureSearchCookie($cookie, $reset) { return $GLOBALS['scenario']['session_valid'] ?? true; }
};
$controller->context = (object)['cookie'=>(object)['eind_session_id'=>'synthetic-session'],
    'cart'=>new class { public int $id = 1; public function getNbProducts($id) { return 1; } }];
(new ReflectionProperty($controller, 'cartHelper'))->setValue($controller, $helper);
$controller->postProcess();
