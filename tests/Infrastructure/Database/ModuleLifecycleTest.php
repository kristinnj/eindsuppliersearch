<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Database;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ModuleLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__ . '/ModuleLifecycleHarness.php';
    }

    public function testSchemaFailureStopsFreshInstallationBeforeOtherWrites(): void
    {
        $module = new \Eindsuppliersearch();
        self::assertFalse($module->install());
        self::assertSame([], \Db::$statements);
        self::assertCount(1, $module->_errors);
    }

    public function testFreshInstallationInvokesSharedSchemaEntryPoint(): void
    {
        $module = new class extends \Eindsuppliersearch {
            public int $schemaCalls = 0;
            public function installOfferBindingSchema(): bool { ++$this->schemaCalls; return true; }
        };
        self::assertTrue($module->install());
        self::assertSame(1, $module->schemaCalls);
        self::assertCount(2, \Db::$statements);
    }

    public function testUpgradeEntryPointReturnsSharedSchemaFailure(): void
    {
        require dirname(__DIR__, 3) . '/upgrade/upgrade-1.1.0.php';
        self::assertFalse(upgrade_module_1_1_0(new \Eindsuppliersearch()));
        self::assertSame([], \Db::$statements);
    }

    public function testUpgradeEntryPointReturnsSharedSchemaSuccess(): void
    {
        require dirname(__DIR__, 3) . '/upgrade/upgrade-1.1.0.php';
        $module = new class extends \Eindsuppliersearch {
            public int $schemaCalls = 0;
            public function installOfferBindingSchema(): bool { ++$this->schemaCalls; return true; }
        };
        self::assertTrue(upgrade_module_1_1_0($module));
        self::assertSame(1, $module->schemaCalls);
    }

    public function testUninstallDoesNotDropTheRegistry(): void
    {
        self::assertTrue((new \Eindsuppliersearch())->uninstall());
        self::assertCount(2, \Db::$statements);
        foreach (\Db::$statements as $sql) {
            self::assertStringNotContainsString('offer_binding', $sql);
        }
    }

    public function testVersionMetadataAndDiscoverableUpgradeAgree(): void
    {
        $module = new \Eindsuppliersearch();
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/config.xml');
        self::assertSame('1.1.0', $module->version);
        self::assertSame($module->version, (string) $xml->version);
        self::assertFileExists(dirname(__DIR__, 3) . '/upgrade/upgrade-' . $module->version . '.php');
        require dirname(__DIR__, 3) . '/upgrade/upgrade-1.1.0.php';
        self::assertTrue(function_exists('upgrade_module_' . str_replace('.', '_', $module->version)));
    }
}
