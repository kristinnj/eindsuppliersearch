<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Factory;

use EindSupplierSearch\Factory\SupplierProviderResolver;
use EindSupplierSearch\Provider\JsonFixtureSupplierProvider;
use EindSupplierSearch\Provider\LiveSupplierProvider;
use PHPUnit\Framework\TestCase;

final class SupplierProviderResolverTest extends TestCase
{
    private const FIXTURES_PATH = __DIR__ . '/../fixtures/suppliers';

    public function testLiveModeResolvesToLiveSupplierProvider(): void
    {
        $resolver = new SupplierProviderResolver(SupplierProviderResolver::MODE_LIVE, self::FIXTURES_PATH);

        self::assertInstanceOf(LiveSupplierProvider::class, $resolver->resolve());
    }

    public function testFixtureModeResolvesToJsonFixtureSupplierProvider(): void
    {
        $resolver = new SupplierProviderResolver(SupplierProviderResolver::MODE_FIXTURE, self::FIXTURES_PATH);

        self::assertInstanceOf(JsonFixtureSupplierProvider::class, $resolver->resolve());
    }

    public function testUnrecognisedModeFallsBackToLive(): void
    {
        $resolver = new SupplierProviderResolver('not-a-real-mode', self::FIXTURES_PATH);

        self::assertSame(SupplierProviderResolver::MODE_LIVE, $resolver->getMode());
        self::assertInstanceOf(LiveSupplierProvider::class, $resolver->resolve());
    }

    public function testModeIsCaseAndWhitespaceInsensitive(): void
    {
        $resolver = new SupplierProviderResolver('  FIXTURE  ', self::FIXTURES_PATH);

        self::assertSame(SupplierProviderResolver::MODE_FIXTURE, $resolver->getMode());
    }
}
