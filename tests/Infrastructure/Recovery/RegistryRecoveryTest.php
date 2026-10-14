<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Recovery;

use EindSupplierSearch\Infrastructure\Recovery\RegistryRecovery;
use EindSupplierSearch\Infrastructure\Recovery\RecoveryAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryRecoveryTest extends TestCase
{
    private static function state(): array
    {
        return ['identity_verified' => true, 'identity' => ['database' => 'isolated', 'server_hostname' => 'isolated-db'],
            'source_version' => '1.1.0', 'source_agrees' => true, 'registered' => true, 'registered_version' => '1.1.0',
            'disabled' => true, 'registration_consistent' => true, 'protected_data_hash' => 'unchanged',
            'alternate_tables' => [], 'registry' => ['exists' => false, 'schema_valid' => false]];
    }

    private static function gate(): array
    {
        return ['exit_code' => 1, 'registry' => ['exists' => true, 'schema_valid' => true], 'checks' => [
            ['name' => 'module_enabled', 'status' => 'FAIL', 'code' => 'REQUIRED_SHOP_DISABLED']]];
    }

    public static function blocked(): iterable
    {
        foreach (['identity_verified' => false, 'source_version' => '1.0.0', 'source_agrees' => false,
            'registered' => false, 'registered_version' => '1.0.0', 'disabled' => false,
            'registration_consistent' => false, 'alternate_tables' => ['offer_binding_backup']] as $key => $value) {
            yield $key => [$key, $value];
        }
        yield 'missing schema metadata' => ['registry', []];
        yield 'incompatible registry' => ['registry', ['exists' => true, 'schema_valid' => false]];
    }

    #[DataProvider('blocked')]
    public function testUnsafeStatesNeverMutate(string $key, mixed $value): void
    {
        $state = self::state(); $state[$key] = $value;
        $never = static function (): never { throw new \LogicException('Mutation must not be reached'); };
        $result = (new RegistryRecovery(static fn () => $state, $never, $never, $never, $never))->run(true, $never);
        self::assertSame(1, $result['exit_code']); self::assertFalse($result['mutation_performed']);
    }

    public function testDefaultDryRunIsReadOnlyAndEligible(): void
    {
        $never = static function (): never { throw new \LogicException('Must not be reached'); };
        $result = (new RegistryRecovery(self::state(...), $never, $never, $never, $never))->run();
        self::assertSame('ELIGIBLE', $result['status']); self::assertSame(0, $result['exit_code']);
        self::assertSame('CREATE_EMPTY_CANONICAL_REGISTRY', $result['planned_mutation']); self::assertFalse($result['mutation_performed']);
    }

    public function testCompatiblePopulatedRegistryIsNoOpWithoutAuthorization(): void
    {
        $state = self::state(); $state['registry'] = ['exists' => true, 'schema_valid' => true, 'rows' => 4];
        $never = static function (): never { throw new \LogicException('Must not be reached'); };
        $result = (new RegistryRecovery(static fn () => $state, $never, $never, $never, self::gate(...)))->run(true);
        self::assertSame('UNNECESSARY', $result['status']); self::assertSame(0, $result['exit_code']); self::assertFalse($result['mutation_performed']);
        self::assertSame(1, $result['verification']['exit_code']); self::assertFalse($result['deployment_ready']);
    }

    public function testMissingAuthorizationDoesNotAcquireLock(): void
    {
        $never = static function (): never { throw new \LogicException('Must not be reached'); };
        $result = (new RegistryRecovery(self::state(...), $never, $never, $never, $never))->run(true);
        self::assertSame('AUTHORIZATION_REJECTED', $result['code']); self::assertSame(1, $result['exit_code']);
    }

    public static function executionCases(): iterable
    {
        yield 'create' => ['create', 0, true];
        yield 'lock conflict' => ['lock', 1, false];
        yield 'changed state' => ['changed', 1, false];
        yield 'concurrent compatible creation' => ['concurrent', 0, false];
        yield 'concurrent incompatible creation' => ['concurrent-incompatible', 1, false];
        yield 'enabled while waiting' => ['pre-enabled', 1, false];
        yield 'version changed while waiting' => ['pre-version', 1, false];
        yield 'interrupted CREATE' => ['interrupted', 2, true];
        yield 'failed verification' => ['verify', 2, true];
        yield 'changed enablement after creation' => ['enabled', 2, true];
        yield 'changed version after creation' => ['version', 2, true];
        yield 'binding records unexpectedly present' => ['bindings', 2, true];
        yield 'unknown independent gate' => ['gate', 2, true];
    }

    #[DataProvider('executionCases')]
    public function testExecutionPostconditions(string $case, int $exit, bool $mutated): void
    {
        $state = self::state(); $reads = 0; $unlocks = 0; $creates = 0;
        $inspect = static function () use (&$state, &$reads, $case): array {
            ++$reads;
            if ($reads === 2 && $case === 'changed') { $state['protected_data_hash'] = 'changed'; }
            if ($reads === 2 && $case === 'concurrent-incompatible') { $state['registry'] = ['exists' => true, 'schema_valid' => false, 'rows' => 4]; }
            if ($reads === 2 && $case === 'pre-enabled') { $state['disabled'] = false; }
            if ($reads === 2 && $case === 'pre-version') { $state['registered_version'] = '1.2.0'; }
            if ($reads === 2 && $case === 'concurrent') { $state['registry'] = ['exists' => true, 'schema_valid' => true, 'rows' => 0]; }
            return $state;
        };
        $create = static function () use (&$state, &$creates, $case): void {
            ++$creates; $state['registry'] = ['exists' => true, 'schema_valid' => $case !== 'verify', 'rows' => $case === 'bindings' ? 1 : 0];
            if ($case === 'enabled') { $state['disabled'] = false; }
            if ($case === 'version') { $state['registered_version'] = '1.2.0'; }
            if ($case === 'interrupted') { throw new \RuntimeException('Interrupted after real boundary'); }
        };
        $gate = static fn () => $case === 'gate' ? ['exit_code' => 2] : self::gate();
        $result = (new RegistryRecovery($inspect, static fn () => $case !== 'lock',
            static function () use (&$unlocks): void { ++$unlocks; }, $create, $gate))->run(true, static fn () => true);
        self::assertSame($exit, $result['exit_code']); self::assertSame($mutated, $result['mutation_performed']);
        self::assertSame($mutated ? 1 : 0, $creates); self::assertSame($case === 'lock' ? 0 : 1, $unlocks);
        self::assertFalse($result['deployment_ready']);
    }

    public function testAuthorizationExpiryIsCheckedAgainUnderLock(): void
    {
        $created = false; $released = false;
        $result = (new RegistryRecovery(self::state(...), static fn () => true,
            static function () use (&$released): void { $released = true; },
            static function () use (&$created): void { $created = true; }, self::gate(...)))
            ->run(true, static fn () => true, static fn () => false);
        self::assertSame('AUTHORIZATION_EXPIRED_OR_CHANGED', $result['code']);
        self::assertFalse($created); self::assertTrue($released);
    }

    public function testUnknownOutcomeAfterDdlIsNotReportedAsRolledBack(): void
    {
        $reads = 0;
        $inspect = static function () use (&$reads): array {
            if (++$reads > 2) { throw new \PDOException('SECRET metadata unavailable'); }
            return self::state();
        };
        $result = (new RegistryRecovery($inspect, static fn () => true, static function (): void {},
            static function (): void {}, self::gate(...)))->run(true, static fn () => true);
        self::assertSame(2, $result['exit_code']); self::assertNull($result['mutation_performed']);
        self::assertTrue($result['ddl_attempted']); self::assertStringNotContainsString('SECRET', json_encode($result));
    }

    public function testAcceptedAuthorizationStillRequiresSamePlanAndUnexpiredLease(): void
    {
        $time = 1000; $plan = ['database' => 'isolated', 'operation' => 'CREATE'];
        $auth = new RecoveryAuthorization($plan, static function () use (&$time): int { return $time; });
        $challenge = $auth->challenge();
        self::assertTrue($auth->consume($plan, ['token' => $challenge['token'], 'confirmation' => $challenge['confirmation']]));
        self::assertTrue($auth->validFor($plan));
        self::assertFalse($auth->validFor(['database' => 'other', 'operation' => 'CREATE']));
        $time = 1120; self::assertFalse($auth->validFor($plan));
    }

    public static function authorizationCases(): iterable
    {
        foreach (['valid', 'missing', 'invalid', 'expired', 'database', 'plan', 'state', 'confirmation', 'replay'] as $case) { yield $case => [$case]; }
    }

    #[DataProvider('authorizationCases')]
    public function testAuthorizationIsProcessLocalAndBoundToExactPlan(string $case): void
    {
        $time = 1000; $plan = ['operation' => 'CREATE_EMPTY_CANONICAL_REGISTRY', 'state' => self::state()];
        $auth = new RecoveryAuthorization($plan, static function () use (&$time): int { return $time; });
        $challenge = $auth->challenge(); $response = ['token' => $challenge['token'], 'confirmation' => $challenge['confirmation']];
        if ($case === 'missing') { $response = []; }
        if ($case === 'invalid') { $response['token'] = str_repeat('0', 64); }
        if ($case === 'expired') { $time = 1120; }
        if ($case === 'database') { $plan['state']['identity']['database'] = 'other'; }
        if ($case === 'plan') { $plan['operation'] = 'REPAIR'; }
        if ($case === 'state') { $plan['state']['disabled'] = false; }
        if ($case === 'confirmation') { $response['confirmation'] = '--yes'; }
        if ($case === 'replay') { self::assertTrue($auth->consume($plan, $response)); }
        self::assertSame($case === 'valid', $auth->consume($plan, $response));
        self::assertFalse($auth->consume($plan, $response));
    }
}
