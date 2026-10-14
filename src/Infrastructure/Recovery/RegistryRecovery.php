<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Recovery;

/** Bounded orchestration. Dependencies never update module/native/ownership rows. */
final class RegistryRecovery
{
    public function __construct(private readonly \Closure $inspect, private readonly \Closure $lock,
        private readonly \Closure $unlock, private readonly \Closure $create, private readonly \Closure $verifyGate)
    {
    }

    public static function eligibility(array $state): array
    {
        $codes = [];
        if (!($state['identity_verified'] ?? false)) { $codes[] = 'IDENTITY_UNVERIFIED'; }
        if (($state['source_version'] ?? null) !== '1.1.0' || !($state['source_agrees'] ?? false)) { $codes[] = 'SOURCE_MISMATCH'; }
        if (!($state['registered'] ?? false)) { $codes[] = 'REGISTRATION_MISSING'; }
        if (($state['registered_version'] ?? null) !== '1.1.0') { $codes[] = 'VERSION_MISMATCH'; }
        if (!($state['disabled'] ?? false)) { $codes[] = 'MODULE_NOT_DISABLED'; }
        if (!($state['registration_consistent'] ?? false)) { $codes[] = 'REGISTRATION_INCONSISTENT'; }
        if (!empty($state['alternate_tables'])) { $codes[] = 'AMBIGUOUS_REGISTRY'; }
        if (!isset($state['registry']['exists'], $state['registry']['schema_valid'])) { $codes[] = 'METADATA_UNAVAILABLE'; }
        elseif ($state['registry']['exists'] && !$state['registry']['schema_valid']) { $codes[] = 'INCOMPATIBLE_REGISTRY'; }
        return $codes;
    }

    public function run(bool $recover = false, ?\Closure $authorize = null, ?\Closure $authorizationValid = null): array
    {
        $result = ['contract_version' => 1, 'status' => 'BLOCKED', 'exit_code' => 1,
            'action' => $recover ? 'RECOVER' : 'DRY_RUN', 'module' => 'eindsuppliersearch',
            'recovery_eligible' => false, 'mutation_performed' => false, 'deployment_ready' => false];
        $held = false;
        $attempted = false;
        try {
            $before = ($this->inspect)();
            $result['source_version'] = $before['source_version'];
            $result['registered_version'] = $before['registered_version'];
            $result['registry_before'] = $before['registry'];
            $result['database_fingerprint'] = RecoveryAuthorization::fingerprint($before['identity']);
            if (isset($before['identity']['table_prefix'])) {
                $result['canonical_table'] = $before['identity']['table_prefix'] . \EindSupplierSearch\Infrastructure\Database\OfferBindingSchema::TABLE;
            }
            $codes = self::eligibility($before);
            if ($codes !== []) { $result['codes'] = $codes; return $result; }
            $result['recovery_eligible'] = !$before['registry']['exists'];
            $result['planned_mutation'] = $before['registry']['exists'] ? null : 'CREATE_EMPTY_CANONICAL_REGISTRY';
            if (!$recover) {
                $result['status'] = $before['registry']['exists'] ? 'UNNECESSARY' : 'ELIGIBLE';
                $result['exit_code'] = 0;
                $result['code'] = 'DRY_RUN';
                return $result;
            }
            if ($before['registry']['exists']) {
                $result['verification'] = ($this->verifyGate)();
                if (!self::expectedDisabledGate($result['verification'])) { throw new \RuntimeException('Incomplete independent verification.'); }
                if (($this->inspect)() !== $before) { throw new \RuntimeException('No-op invariants changed.'); }
                $result['registry_after'] = $before['registry'];
                $result['status'] = 'UNNECESSARY'; $result['exit_code'] = 0; $result['code'] = 'CANONICAL_REGISTRY_RETAINED';
                return $result;
            }
            $plan = ['operation' => 'CREATE_EMPTY_CANONICAL_REGISTRY', 'state' => $before];
            if ($authorize === null || !$authorize($plan)) { $result['code'] = 'AUTHORIZATION_REJECTED'; return $result; }
            if (!(($this->lock)())) { $result['code'] = 'RECOVERY_LOCK_BUSY'; return $result; }
            $held = true;
            $current = ($this->inspect)();
            $codes = self::eligibility($current);
            if ($codes !== []) { $result['codes'] = $codes; return $result; }
            if ($current['registry']['exists']) {
                // Only canonical creation may have changed while waiting; no registration/data changes.
                $comparable = $current; $comparable['registry'] = $before['registry'];
                if ($comparable !== $before) { $result['code'] = 'STATE_CHANGED'; return $result; }
                $result['registry_after'] = $current['registry'];
                $result['verification'] = ($this->verifyGate)();
                if (!self::expectedDisabledGate($result['verification'])) { throw new \RuntimeException('Incomplete independent verification.'); }
                $result['status'] = 'UNNECESSARY'; $result['exit_code'] = 0; $result['code'] = 'CONCURRENT_CANONICAL_CREATION';
                return $result;
            }
            if ($current !== $before) { $result['code'] = 'STATE_CHANGED'; return $result; }
            if ($authorizationValid !== null && !$authorizationValid(['operation' => 'CREATE_EMPTY_CANONICAL_REGISTRY', 'state' => $current])) {
                $result['code'] = 'AUTHORIZATION_EXPIRED_OR_CHANGED'; return $result;
            }
            // No SQL other than the shared canonical CREATE is authorized by this callback.
            $attempted = true;
            $result['ddl_attempted'] = true;
            ($this->create)();
            $result['mutation_performed'] = true;
            $after = ($this->inspect)();
            $result['registry_after'] = $after['registry'];
            $comparable = $after; $comparable['registry'] = $before['registry'];
            if (self::eligibility($after) !== [] || !$after['registry']['exists']
                || !$after['registry']['schema_valid'] || ($after['registry']['rows'] ?? null) !== 0
                || $comparable !== $before) { throw new \RuntimeException('Postconditions failed.'); }
            $result['verification'] = ($this->verifyGate)();
            if (!self::expectedDisabledGate($result['verification'])) { throw new \RuntimeException('Incomplete independent verification.'); }
            $result['status'] = 'RECOVERED'; $result['exit_code'] = 0; $result['code'] = 'EMPTY_CANONICAL_REGISTRY_CREATED';
            return $result;
        } catch (\Throwable) {
            $result['status'] = 'FAILED'; $result['exit_code'] = 2; $result['code'] = 'RECOVERY_INCOMPLETE';
            // DDL may have completed even if the callback threw; preserve evidence and the table.
            try { $result['registry_after'] = ($this->inspect)()['registry']; } catch (\Throwable) { }
            $result['mutation_performed'] = isset($result['registry_after']['exists'])
                ? ($attempted && ($result['registry_before']['exists'] ?? true) === false && $result['registry_after']['exists'])
                : ($attempted ? null : false);
            return $result;
        } finally {
            if ($held) { ($this->unlock)(); }
        }
    }

    private static function expectedDisabledGate(array $gate): bool
    {
        if (($gate['exit_code'] ?? null) !== 1 || ($gate['registry']['schema_valid'] ?? false) !== true) { return false; }
        $disabled = false;
        foreach ($gate['checks'] ?? [] as $check) {
            if ($check['status'] !== 'PASS') {
                if ($check['name'] !== 'module_enabled' || $check['code'] !== 'REQUIRED_SHOP_DISABLED') { return false; }
                $disabled = true;
            }
        }
        return $disabled;
    }
}
