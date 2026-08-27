<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Service;

use EindSupplierSearch\Domain\CachedOfferResolution;
use EindSupplierSearch\Domain\OfferActionReference;
use EindSupplierSearch\Service\CachedOfferResolver;
use PHPUnit\Framework\TestCase;

final class CachedOfferResolverTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function twoSupplierPayload(): array
    {
        return [
            100001 => [
                'SupplierName' => 'Farnell',
                'Products' => [
                    0 => ['OrderCode' => 'FAR-STM32F446', 'ManufacturerPartNumber' => 'STM32F446RET6'],
                ],
            ],
            100002 => [
                'SupplierName' => 'Mouser',
                'Products' => [
                    0 => ['OrderCode' => 'MOU-511-STM32F446RET6', 'ManufacturerPartNumber' => 'STM32F446RET6'],
                ],
            ],
        ];
    }

    private function resolver(): CachedOfferResolver
    {
        return new CachedOfferResolver();
    }

    public function testCorrectSupplierAndCorrectRowResolvesSuccessfully(): void
    {
        $resolution = $this->resolver()->resolve($this->twoSupplierPayload(), new OfferActionReference(100002, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('MOU-511-STM32F446RET6', $resolution->getProduct()['OrderCode']);
    }

    public function testNumericSupplierIdInStringFormResolvesTheSameSupplier(): void
    {
        // json_decode() already normalizes numeric object keys to int, so
        // this also exercises that a string reference for a numeric id
        // still finds the bucket.
        $resolution = $this->resolver()->resolve($this->twoSupplierPayload(), new OfferActionReference('100002', 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('MOU-511-STM32F446RET6', $resolution->getProduct()['OrderCode']);
    }

    public function testExplicitSupplierIdFieldResolvesTheSameSupplier(): void
    {
        $data = [
            'bucket-a' => [
                'SupplierId' => 555,
                'SupplierName' => 'Acme',
                'Products' => [0 => ['OrderCode' => 'ACME-1']],
            ],
        ];

        $resolution = $this->resolver()->resolve($data, new OfferActionReference(555, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('ACME-1', $resolution->getProduct()['OrderCode']);
    }

    public function testWrongSupplierIdFails(): void
    {
        $resolution = $this->resolver()->resolve($this->twoSupplierPayload(), new OfferActionReference(999999, 0));

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::SUPPLIER_NOT_FOUND, $resolution->getFailureCategory());
    }

    public function testMissingSupplierFails(): void
    {
        $resolution = $this->resolver()->resolve([], new OfferActionReference(100001, 0));

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::SUPPLIER_NOT_FOUND, $resolution->getFailureCategory());
    }

    public function testCorrectSupplierWithWrongRowFails(): void
    {
        $resolution = $this->resolver()->resolve($this->twoSupplierPayload(), new OfferActionReference(100001, 5));

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::ROW_NOT_FOUND, $resolution->getFailureCategory());
    }

    public function testCorrectSupplierAndRowWithMatchingOrderCodeSucceeds(): void
    {
        $resolution = $this->resolver()->resolve(
            $this->twoSupplierPayload(),
            new OfferActionReference(100001, 0),
            'FAR-STM32F446'
        );

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('FAR-STM32F446', $resolution->getProduct()['OrderCode']);
    }

    public function testCorrectSupplierAndRowWithMismatchingOrderCodeFails(): void
    {
        $resolution = $this->resolver()->resolve(
            $this->twoSupplierPayload(),
            new OfferActionReference(100001, 0),
            'SOME-OTHER-CODE'
        );

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::ORDER_CODE_MISMATCH, $resolution->getFailureCategory());
    }

    public function testSameRowIndexUnderAnotherSupplierDoesNotResolve(): void
    {
        // Both buckets have a row 0; asking for supplier 100001's row 0
        // must never return supplier 100002's row 0's data.
        $resolution = $this->resolver()->resolve($this->twoSupplierPayload(), new OfferActionReference(100001, 0));

        self::assertTrue($resolution->isSuccessful());
        self::assertSame('FAR-STM32F446', $resolution->getProduct()['OrderCode']);
        self::assertNotSame('MOU-511-STM32F446RET6', $resolution->getProduct()['OrderCode']);
    }

    public function testSameOrderCodeUnderAnotherSupplierDoesNotResolve(): void
    {
        $data = [
            100001 => [
                'SupplierName' => 'Farnell',
                'Products' => [0 => ['OrderCode' => 'SHARED-CODE']],
            ],
            100002 => [
                'SupplierName' => 'Mouser',
                'Products' => [0 => ['OrderCode' => 'SHARED-CODE']],
            ],
        ];

        // Wrong supplier id, row 0 doesn't exist there, but the order
        // code happens to match a row under a DIFFERENT supplier.
        $resolution = $this->resolver()->resolve($data, new OfferActionReference(999999, 0), 'SHARED-CODE');

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::SUPPLIER_NOT_FOUND, $resolution->getFailureCategory());
    }

    public function testGlobalFallbackIsImpossibleInStrictMode(): void
    {
        // Row 0 exists under supplier 100002 but NOT 100001; a lenient
        // resolver might scan globally and find it anyway. Strict must not.
        $data = [
            100001 => ['SupplierName' => 'Farnell', 'Products' => []],
            100002 => ['SupplierName' => 'Mouser', 'Products' => [0 => ['OrderCode' => 'MOU-1']]],
        ];

        $resolution = $this->resolver()->resolve($data, new OfferActionReference(100001, 0));

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::ROW_NOT_FOUND, $resolution->getFailureCategory());
    }

    public function testNonArrayProductRowFailsSafely(): void
    {
        $data = [100001 => ['SupplierName' => 'Farnell', 'Products' => [0 => 'not-an-array']]];

        $resolution = $this->resolver()->resolve($data, new OfferActionReference(100001, 0));

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::ROW_NOT_FOUND, $resolution->getFailureCategory());
    }

    public function testBucketWithoutAProductsArrayFailsSafely(): void
    {
        $data = [100001 => ['SupplierName' => 'Farnell']];

        $resolution = $this->resolver()->resolve($data, new OfferActionReference(100001, 0));

        self::assertFalse($resolution->isSuccessful());
        self::assertSame(CachedOfferResolution::SUPPLIER_NOT_FOUND, $resolution->getFailureCategory());
    }

    public function testEmptyCachedPayloadFailsSafely(): void
    {
        $resolution = $this->resolver()->resolve([], new OfferActionReference(100001, 0));

        self::assertFalse($resolution->isSuccessful());
        self::assertNull($resolution->getProduct());
    }
}
