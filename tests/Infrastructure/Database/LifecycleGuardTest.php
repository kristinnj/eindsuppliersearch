<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Database;

require_once dirname(__DIR__, 2) . '/PrestaShopLifecycle/Guard.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LifecycleGuardTest extends TestCase
{
    private function configuration(): array
    {
        return ['disposable' => 'YES', 'run' => 'hlutiris-p002d-012345abcdef',
            'host' => 'hlutiris-p002d-012345abcdef-db', 'database' => 'hlutiris_lifecycle_test_012345abcdef',
            'user' => 'lifecycle_test', 'token' => str_repeat('t', 48), 'password' => str_repeat('p', 48)];
    }

    public function testExplicitDisposableConfigurationPassesWithoutIo(): void
    {
        \LifecycleGuard::validateConfiguration($this->configuration());
        $this->addToAssertionCount(1);
    }

    public static function unsafeConfigurations(): array
    {
        return [[['disposable' => '']], [['database' => 'eind_dev']], [['database' => 'production']],
            [['host' => 'prestashop_database']], [['user' => 'root']], [['token' => '']],
            [['password' => '']], [['run' => 'hlutiris-p002d-invalid']], [['run' => null]],
            [['database' => 'hlutiris_lifecycle_test_abcdef012345']]];
    }

    #[DataProvider('unsafeConfigurations')]
    public function testUnsafeIdentityIsRejectedBeforeConnection(array $changes): void
    {
        $this->expectException(\RuntimeException::class);
        \LifecycleGuard::validateConfiguration(array_replace($this->configuration(), $changes));
    }
}
