<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Database;

require_once dirname(__DIR__, 2) . '/Database/DisposableDatabaseGuard.php';

use EindSupplierSearch\Tests\Database\DisposableDatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DisposableDatabaseGuardTest extends TestCase
{
    private static function config(): array
    {
        return ['disposable' => 'YES', 'database' => 'hlutiris_offer_binding_test_unit',
            'confirmed_database' => 'hlutiris_offer_binding_test_unit', 'application_databases' => ['eind_dev', 'production']];
    }

    public function testAllowsExplicitIsolatedDatabaseWithOnlyUnrelatedTables(): void
    {
        DisposableDatabaseGuard::validateMetadata(self::config(), 'hlutiris_offer_binding_test_unit', ['test_eind_offer_binding', 'unrelated_records']);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConfigurations')]
    public function testRefusesUnsafeConfiguration(array $changes): void
    {
        $this->expectException(\RuntimeException::class);
        DisposableDatabaseGuard::validateConfiguration(array_replace(self::config(), $changes));
    }

    public static function unsafeConfigurations(): array
    {
        return [
            [['disposable' => '']],
            [['database' => 'eind_dev', 'confirmed_database' => 'eind_dev']],
            [['database' => 'production', 'confirmed_database' => 'production']],
            [['confirmed_database' => 'another_database']],
            [['application_databases' => []]],
            [['application_databases' => ['HLUTIRIS_OFFER_BINDING_TEST_UNIT']]],
        ];
    }

    #[DataProvider('applicationTables')]
    public function testRefusesCatalogCustomerCartOrderAndOtherApplicationTables(string $table): void
    {
        $this->expectException(\RuntimeException::class);
        DisposableDatabaseGuard::validateMetadata(self::config(), self::config()['database'], [$table]);
    }

    public static function applicationTables(): array
    {
        return array_map(static fn (string $table): array => [$table], [
            'product', 'ps_product', 'eind_product_shop', 'abc_customer', 'customer_group',
            'ps_cart', 'other_cart_product', 'orders', 'x_order_detail', 'ps_supplier_shop',
            'ps_shop', 'ps_configuration', 'ps_module', 'ps_address', 'ps_guest', 'ps_stock_available',
        ]);
    }

    public function testRefusesDifferentConnectedDatabase(): void
    {
        $this->expectException(\RuntimeException::class);
        DisposableDatabaseGuard::validateMetadata(self::config(), 'eind_dev', []);
    }
}
