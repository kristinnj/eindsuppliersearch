<?php

declare(strict_types=1);
namespace EindSupplierSearch\Infrastructure\Verification;

use EindSupplierSearch\Infrastructure\Database\M1IntegrityException;
use EindSupplierSearch\Infrastructure\Database\M1Schema;

/** Structural report only: never attests records, signing or commerce readiness. */
final class M1Verification
{
    public static function report(): array
    {
        return ['contract_version' => 1, 'mode' => 'structure', 'phase' => 'M1',
            'manifest_version' => 'M1-v1', 'manifest_sha256' => M1Schema::HASHES,
            'status' => 'PASS', 'exit_code' => 0, 'commerce_eligible' => false,
            'inspected_object_count' => 0, 'checks' => [],
            'unassessed' => ['authentication', 'observation_content', 'quotes', 'creation_history',
                'native_products', 'cart', 'checkout', 'order_attribution', 'history_validity',
                'deployment_authorization']];
    }

    public static function run(callable $target, callable $registry, callable $enforcement, callable $unexpected, callable $table): array
    {
        $r = self::report();
        $check = static function (string $name, callable $f) use (&$r): void {
            if (in_array($name, ['registry', 'eind_observation', 'eind_quote'], true)) { ++$r['inspected_object_count']; }
            try {
                $f(); $r['checks'][] = ['name' => $name, 'status' => 'PASS', 'code' => 'OK'];
            } catch (\Throwable $e) {
                $integrity = $e instanceof M1IntegrityException
                    || (str_starts_with($e->getMessage(), 'Incompatible offer binding')
                        || str_starts_with($e->getMessage(), 'Unexpected offer binding'));
                $r['checks'][] = ['name' => $name, 'status' => $integrity ? 'FAIL' : 'ERROR',
                    'code' => $integrity ? ($e instanceof M1IntegrityException ? $e->getMessage() : 'REGISTRY_INCOMPATIBLE') : 'READ_FAILURE'];
                $r['exit_code'] = max($r['exit_code'], $integrity ? 1 : 2);
            }
        };
        $check('target_and_visibility', $target);
        if ($r['exit_code'] === 0) {
            $check('registry', $registry); $check('enforcement', $enforcement); $check('phase_objects', $unexpected);
            foreach (array_keys(M1Schema::HASHES) as $name) { $check($name, static fn () => $table($name)); }
        }
        $r['status'] = $r['exit_code'] === 0 ? 'PASS' : 'FAIL';
        return $r;
    }

    public static function failure(string $code): array
    {
        $r = self::report(); $r['status'] = 'FAIL'; $r['exit_code'] = 2;
        $r['checks'][] = ['name' => 'environment', 'status' => 'ERROR', 'code' => $code];
        return $r;
    }
}
