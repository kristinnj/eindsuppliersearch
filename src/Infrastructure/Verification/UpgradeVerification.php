<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Verification;

/** Collects independent evidence; never invokes any lifecycle operation. */
final class UpgradeVerification
{
    public const MODULE = 'eindsuppliersearch';

    public function __construct(private readonly \Closure $registration, private readonly \Closure $registry)
    {
    }

    public static function failure(string $code): array
    {
        return ['contract_version' => 1, 'status' => 'FAIL', 'exit_code' => 2,
            'module' => self::MODULE, 'checks' => [['name' => 'environment', 'status' => 'ERROR', 'code' => $code]]];
    }

    public function verify(string $sourceVersion, bool $metadataAgrees): array
    {
        $result = ['contract_version' => 1, 'status' => 'PASS', 'exit_code' => 0,
            'module' => self::MODULE, 'expected_version' => $sourceVersion,
            'registered_version' => null, 'module_enabled' => null,
            'enabled_policy' => 'ALL_ACTIVE_NON_DELETED_SHOPS', 'required_shops' => [],
            'registry' => ['exists' => null, 'schema_valid' => null], 'checks' => []];
        $check = static function (string $name, bool $valid, string $code) use (&$result): void {
            $result['checks'][] = ['name' => $name, 'status' => $valid ? 'PASS' : 'FAIL', 'code' => $valid ? 'OK' : $code];
            if (!$valid) {
                $result['exit_code'] = max(1, $result['exit_code']);
            }
        };
        $error = static function (string $name) use (&$result): void {
            $result['checks'][] = ['name' => $name, 'status' => 'ERROR', 'code' => 'READ_FAILURE'];
            $result['exit_code'] = 2;
        };
        $check('source_metadata', $metadataAgrees, 'SOURCE_METADATA_MISMATCH');
        try {
            $state = ($this->registration)();
            $modules = $state['modules'];
            $shops = $state['shops'];
            $links = $state['links'];
            $check('registration', count($modules) === 1, count($modules) === 0 ? 'MODULE_NOT_REGISTERED' : 'CONTRADICTORY_REGISTRATION');
            if (count($modules) === 1) {
                $module = $modules[0];
                $result['registered_version'] = $module['version'];
                $check('registered_version', $module['version'] === $sourceVersion, 'VERSION_MISMATCH');
                $valid = $module['name'] === self::MODULE && (int) $module['id_module'] > 0
                    && in_array((string) $module['active'], ['0', '1'], true);
                $required = [];
                $all = [];
                foreach ($shops as $shop) {
                    $id = (int) $shop['id_shop'];
                    $valid = $valid && $id > 0 && !isset($all[$id])
                        && in_array((string) $shop['active'], ['0', '1'], true)
                        && in_array((string) $shop['deleted'], ['0', '1'], true);
                    $all[$id] = true;
                    if ((string) $shop['active'] === '1' && (string) $shop['deleted'] === '0') {
                        $required[] = $id;
                    }
                }
                sort($required);
                $linked = [];
                foreach ($links as $link) {
                    $id = (int) $link['id_shop'];
                    $valid = $valid && !isset($linked[$id]) && isset($all[$id])
                        && (int) $link['id_module'] === (int) $module['id_module']
                        && (int) $link['enable_device'] >= 0 && (int) $link['enable_device'] <= 7;
                    $linked[$id] = (int) $link['enable_device'];
                }
                $valid = $valid && !((string) $module['active'] === '0' && $links !== []);
                $result['required_shops'] = $required;
                $check('registration_consistency', $valid, 'CONTRADICTORY_REGISTRATION');
                $enabled = $valid && (string) $module['active'] === '1' && $required !== [];
                foreach ($required as $id) {
                    $enabled = $enabled && ($linked[$id] ?? null) === 7;
                }
                $result['module_enabled'] = $enabled;
                $check('module_enabled', $enabled, 'REQUIRED_SHOP_DISABLED');
            }
        } catch (\Throwable) {
            $error('registration');
        }
        try {
            $result['registry'] = ($this->registry)();
            $check('registry_exists', $result['registry']['exists'], 'REGISTRY_MISSING');
            $check('registry_schema', $result['registry']['schema_valid'], 'INCOMPATIBLE_SCHEMA');
        } catch (\Throwable) {
            $error('registry_schema');
        }
        $result['status'] = $result['exit_code'] === 0 ? 'PASS' : 'FAIL';
        return $result;
    }
}
