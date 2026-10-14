<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Verification;

use EindSupplierSearch\Infrastructure\Verification\UpgradeVerification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpgradeVerificationTest extends TestCase
{
    private static function state(): array
    {
        return ['modules' => [['id_module' => '8', 'name' => 'eindsuppliersearch', 'version' => '1.1.0', 'active' => '1']],
            'shops' => [['id_shop' => '1', 'active' => '1', 'deleted' => '0'], ['id_shop' => '2', 'active' => '1', 'deleted' => '0']],
            'links' => [['id_module' => '8', 'id_shop' => '1', 'enable_device' => '7'], ['id_module' => '8', 'id_shop' => '2', 'enable_device' => '7']]];
    }

    public static function scenarios(): iterable
    {
        $state = self::state();
        yield 'valid multishop' => [$state, true, true, true, 0];
        $old = $state; $old['modules'][0]['version'] = '1.0.0';
        yield 'old version' => [$old, true, true, true, 1];
        $unexpected = $state; $unexpected['modules'][0]['version'] = '9.9.9';
        yield 'unexpected version' => [$unexpected, true, true, true, 1];
        $missing = $state; $missing['modules'] = [];
        yield 'missing registration' => [$missing, true, true, true, 1];
        $duplicate = $state; $duplicate['modules'][] = $state['modules'][0];
        yield 'duplicate registration' => [$duplicate, true, true, true, 1];
        $disabled = $state; $disabled['modules'][0]['active'] = '0'; $disabled['links'] = [];
        yield 'false success disabled incompatible' => [$disabled, true, false, true, 1];
        yield 'disabled correct schema' => [$disabled, true, true, true, 1];
        yield 'missing registry' => [$state, false, false, true, 1];
        yield 'incompatible registry' => [$state, true, false, true, 1];
        yield 'source mismatch' => [$state, true, true, false, 1];
        $partial = $state; array_pop($partial['links']);
        yield 'missing required shop' => [$partial, true, true, true, 1];
        $device = $state; $device['links'][1]['enable_device'] = '3';
        yield 'restricted devices' => [$device, true, true, true, 1];
        $orphan = $state; $orphan['links'][1]['id_shop'] = '3';
        yield 'orphan shop' => [$orphan, true, true, true, 1];
        $inactive = $partial; $inactive['shops'][1]['active'] = '0';
        yield 'inactive shop excluded' => [$inactive, true, true, true, 0];
        $deleted = $partial; $deleted['shops'][1]['deleted'] = '1';
        yield 'deleted shop excluded' => [$deleted, true, true, true, 0];
        $none = $state; $none['shops'] = []; $none['links'] = [];
        yield 'no required shops' => [$none, true, true, true, 1];
    }

    #[DataProvider('scenarios')]
    public function testDecisions(array $state, bool $exists, bool $valid, bool $agrees, int $exit): void
    {
        $gate = new UpgradeVerification(static fn () => $state, static fn () => ['exists' => $exists, 'schema_valid' => $valid]);
        $result = $gate->verify('1.1.0', $agrees);
        self::assertSame($exit, $result['exit_code']);
        self::assertSame($exit === 0 ? 'PASS' : 'FAIL', $result['status']);
        self::assertSame('eindsuppliersearch', $result['module']);
        self::assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR));
    }

    public function testUnknownChecksNeverPassAndDoNotSuppressIndependentRegistry(): void
    {
        $called = false;
        $gate = new UpgradeVerification(static function (): array { throw new \RuntimeException('SECRET'); },
            static function () use (&$called): array { $called = true; return ['exists' => false, 'schema_valid' => false]; });
        $result = $gate->verify('1.1.0', true);
        self::assertSame(2, $result['exit_code']);
        self::assertTrue($called);
        self::assertStringNotContainsString('SECRET', json_encode($result));
    }

    public function testUnexpectedRegistryExceptionIsOperational(): void
    {
        $gate = new UpgradeVerification(self::state(...), static function (): array { throw new \LogicException('SECRET'); });
        $result = $gate->verify('1.1.0', true);
        self::assertSame(2, $result['exit_code']);
        self::assertSame('ERROR', $result['checks'][count($result['checks']) - 1]['status']);
    }

    public function testMissingConfigProcessReturnsValidSanitizedJson(): void
    {
        $command = [PHP_BINARY, dirname(__DIR__, 3) . '/bin/verify-module-upgrade.php', '--json'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['EIND_VERIFY_CONFIG' => '/nonexistent/SECRET']);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(2, proc_close($process));
        self::assertSame('FAIL', json_decode($output, true, 32, JSON_THROW_ON_ERROR)['status']);
        self::assertStringNotContainsString('SECRET', $output . $error);
        self::assertSame('', $error);
    }
}
