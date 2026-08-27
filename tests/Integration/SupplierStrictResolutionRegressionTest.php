<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Integration;

use EindSupplierSearch\Domain\CachedOfferResolution;
use EindSupplierSearch\Domain\OfferActionReference;
use EindSupplierSearch\Service\CachedOfferResolver;
use PHPUnit\Framework\TestCase;

/**
 * The key regression test for the bug class CachedOfferResolver fixes:
 * three real suppliers (multi-supplier-same-product.json), each with the
 * SAME manufacturer/MPN product at row 0 but a different order code --
 * exactly the "Supplier A row 0 = Product X, Supplier B row 0 = Product X"
 * scenario a cross-supplier fallback could confuse. Proves
 * CachedOfferResolver -- the single supported production cached-offer
 * resolution path (see docs/development/offer-action-reference.md,
 * "Supplier-strict commerce resolution") -- never resolves one supplier's
 * reference to another supplier's row, in either direction.
 */
final class SupplierStrictResolutionRegressionTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function multiSupplierPayload(): array
    {
        $path = __DIR__ . '/../fixtures/suppliers/multi-supplier-same-product.json';

        return json_decode((string) file_get_contents($path), true);
    }

    public function testFarnellReferenceCanNeverResolveMouserOrDigiKeysRowZero(): void
    {
        $resolution = (new CachedOfferResolver())->resolve($this->multiSupplierPayload(), new OfferActionReference(100001, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('FAR-STM32F446', $resolution->getProduct()['OrderCode']);
    }

    public function testMouserReferenceCanNeverResolveFarnellOrDigiKeysRowZero(): void
    {
        $resolution = (new CachedOfferResolver())->resolve($this->multiSupplierPayload(), new OfferActionReference(100002, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('MOU-511-STM32F446RET6', $resolution->getProduct()['OrderCode']);
    }

    public function testDigiKeyReferenceCanNeverResolveFarnellOrMousersRowZero(): void
    {
        $resolution = (new CachedOfferResolver())->resolve($this->multiSupplierPayload(), new OfferActionReference(100003, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('497-STM32F446RET6-ND', $resolution->getProduct()['OrderCode']);
    }

    public function testAStaleOrRemovedSupplierBucketFailsClosedRatherThanSubstitutingAnotherSupplier(): void
    {
        // Simulates a supplier that dropped out of the cached result
        // between page render and add-to-cart click.
        $payload = $this->multiSupplierPayload();
        unset($payload[100001]);

        $resolution = (new CachedOfferResolver())->resolve($payload, new OfferActionReference(100001, 0));

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::SUPPLIER_NOT_FOUND, $resolution->getFailureCategory());
    }

    public function testOrderCodeVerificationCannotBeUsedToJumpToAnotherSupplier(): void
    {
        // Ask for Mouser's bucket but supply Farnell's order code as the
        // "expected" verification value -- must fail, not silently accept
        // Mouser's row-0 product under a mismatched order code, and must
        // never go looking for Farnell's row instead.
        $resolution = (new CachedOfferResolver())->resolve(
            $this->multiSupplierPayload(),
            new OfferActionReference(100002, 0),
            'FAR-STM32F446'
        );

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::ORDER_CODE_MISMATCH, $resolution->getFailureCategory());
    }
}
