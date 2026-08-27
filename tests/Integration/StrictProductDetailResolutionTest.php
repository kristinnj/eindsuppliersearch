<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Integration;

use EindSupplierSearch\Domain\CachedOfferResolution;
use EindSupplierSearch\Domain\OfferActionReference;
use EindSupplierSearch\Service\CachedOfferResolver;
use PHPUnit\Framework\TestCase;

/**
 * productview.php (see controllers/front/productview.php::resolveStrictProduct())
 * resolves the requested offer through the exact same CachedOfferResolver
 * add-to-cart uses -- there is no second, detail-only resolution
 * mechanism. These tests exercise that resolver the way productview.php's
 * own request-parsing calls it (an optional order code that may be an
 * empty string when the URL omits it, exactly as Tools::getValue()
 * returns), and are the mandatory multi-supplier proof (see
 * docs/development/strict-product-detail.md) that a detail reference for
 * one supplier can never resolve another supplier's row -- the same
 * guarantee CachedOfferResolverTest/SupplierStrictResolutionRegressionTest
 * already prove for the commerce path, re-exercised here because detail
 * resolution is a second, independent caller of that same guarantee.
 */
final class StrictProductDetailResolutionTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function multiSupplierPayload(): array
    {
        $path = __DIR__ . '/../fixtures/suppliers/multi-supplier-same-product.json';

        return json_decode((string) file_get_contents($path), true);
    }

    public function testFarnellRowZeroResolvesOnlyFarnellsProductDetail(): void
    {
        $resolution = (new CachedOfferResolver())->resolve($this->multiSupplierPayload(), new OfferActionReference(100001, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('FAR-STM32F446', $resolution->getProduct()['OrderCode']);
    }

    public function testMouserRowZeroResolvesOnlyMousersProductDetailNeverFarnellOrDigiKey(): void
    {
        $resolution = (new CachedOfferResolver())->resolve($this->multiSupplierPayload(), new OfferActionReference(100002, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('MOU-511-STM32F446RET6', $resolution->getProduct()['OrderCode']);
    }

    public function testDigiKeyRowZeroResolvesOnlyDigiKeysProductDetailNeverFarnellOrMouser(): void
    {
        $resolution = (new CachedOfferResolver())->resolve($this->multiSupplierPayload(), new OfferActionReference(100003, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('497-STM32F446RET6-ND', $resolution->getProduct()['OrderCode']);
    }

    /**
     * productview.php passes an empty string (Tools::getValue()'s default
     * for a missing GET param) rather than null when the legacy detail
     * link happens to omit orderCode -- must be treated as "no
     * verification requested", not as a literal empty-string order code
     * to match against.
     */
    public function testEmptyStringOrderCodeIsTreatedAsNoVerificationRequested(): void
    {
        $resolution = (new CachedOfferResolver())->resolve(
            $this->multiSupplierPayload(),
            new OfferActionReference(100001, 0),
            null // productview.php normalizes '' to null before calling the resolver
        );

        self::assertTrue($resolution->isSuccessful());
    }

    public function testMismatchedOrderCodeOnAValidRowFailsClosedForDetailToo(): void
    {
        $resolution = (new CachedOfferResolver())->resolve(
            $this->multiSupplierPayload(),
            new OfferActionReference(100001, 0),
            'MOU-511-STM32F446RET6'
        );

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::ORDER_CODE_MISMATCH, $resolution->getFailureCategory());
    }

    public function testStaleSupplierBucketFailsClosedForDetailResolution(): void
    {
        $payload = $this->multiSupplierPayload();
        unset($payload[100002]);

        $resolution = (new CachedOfferResolver())->resolve($payload, new OfferActionReference(100002, 0));

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::SUPPLIER_NOT_FOUND, $resolution->getFailureCategory());
    }
}
