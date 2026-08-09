<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Provider;

use EindCallSupplierApi;
use EindSupplierSearch\Contract\SupplierProviderInterface;
use EindSupplierSearch\Provider\JsonFixtureSupplierProvider;
use EindSupplierSearch\Provider\LiveSupplierProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both providers must be constructible and satisfy the same interface
 * without any PrestaShop bootstrap or network/DB access -- this is what
 * makes the search flow swappable purely by configuration.
 */
final class ProviderConformanceTest extends TestCase
{
    public function testLiveSupplierProviderImplementsTheInterface(): void
    {
        $provider = new LiveSupplierProvider(new EindCallSupplierApi());

        self::assertInstanceOf(SupplierProviderInterface::class, $provider);
    }

    public function testJsonFixtureSupplierProviderImplementsTheInterface(): void
    {
        $provider = new JsonFixtureSupplierProvider(__DIR__ . '/../fixtures/suppliers');

        self::assertInstanceOf(SupplierProviderInterface::class, $provider);
    }
}
