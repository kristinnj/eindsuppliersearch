<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Domain;

use EindSupplierSearch\Domain\SupplierOffer;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use PHPUnit\Framework\TestCase;

final class SupplierOfferIdentityTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $identity = new SupplierOfferIdentity(3, 'FAR-123');

        self::assertSame(3, $identity->getSupplierId());
        self::assertSame('FAR-123', $identity->getSupplierOrderReference());
    }

    public function testRejectsZeroSupplierId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SupplierOfferIdentity(0, '123');
    }

    public function testRejectsNegativeSupplierId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SupplierOfferIdentity(-1, '123');
    }

    public function testRejectsEmptyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SupplierOfferIdentity(3, '');
    }

    public function testRejectsWhitespaceOnlyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SupplierOfferIdentity(3, " \t\r\n ");
    }

    public function testRejectsUnicodeWhitespaceOnlyReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SupplierOfferIdentity(3, "\u{00A0}\u{2003}");
    }

    public function testIdenticalValuesAreEqual(): void
    {
        $first = new SupplierOfferIdentity(3, '123');
        $second = new SupplierOfferIdentity(3, '123');

        self::assertTrue($first->equals($first));
        self::assertTrue($first->equals($second));
        self::assertTrue($second->equals($first));
    }

    public function testDifferentSuppliersWithSameReferenceAreNotEqual(): void
    {
        self::assertFalse((new SupplierOfferIdentity(3, '123'))->equals(new SupplierOfferIdentity(4, '123')));
    }

    public function testDifferentReferencesForSameSupplierAreNotEqual(): void
    {
        self::assertFalse((new SupplierOfferIdentity(3, '123'))->equals(new SupplierOfferIdentity(3, '124')));
    }

    public function testComparisonIsCaseSensitive(): void
    {
        self::assertFalse((new SupplierOfferIdentity(3, 'AbC'))->equals(new SupplierOfferIdentity(3, 'abc')));
    }

    public function testLeadingZerosArePreservedAndSignificant(): void
    {
        $identity = new SupplierOfferIdentity(3, '00123');

        self::assertSame('00123', $identity->getSupplierOrderReference());
        self::assertFalse($identity->equals(new SupplierOfferIdentity(3, '123')));
    }

    public function testPunctuationAndInternalWhitespaceArePreserved(): void
    {
        $reference = "ABC-12/3.ND:  A\tB";
        $identity = new SupplierOfferIdentity(3, $reference);

        self::assertSame($reference, $identity->getSupplierOrderReference());
        self::assertFalse($identity->equals(new SupplierOfferIdentity(3, "ABC-12/3.ND: A\tB")));
    }

    public function testOuterWhitespaceIsPreservedAndSignificant(): void
    {
        $identity = new SupplierOfferIdentity(3, ' 123 ');

        self::assertSame(' 123 ', $identity->getSupplierOrderReference());
        self::assertFalse($identity->equals(new SupplierOfferIdentity(3, '123')));
    }

    public function testLongReferencesAreNotTruncated(): void
    {
        $reference = str_repeat('A', 100) . '001';
        $identity = new SupplierOfferIdentity(3, $reference);

        self::assertSame($reference, $identity->getSupplierOrderReference());
        self::assertFalse($identity->equals(new SupplierOfferIdentity(3, substr($reference, 0, 64))));
    }

    public function testZeroReferenceIsValid(): void
    {
        self::assertSame('0', (new SupplierOfferIdentity(3, '0'))->getSupplierOrderReference());
    }

    public function testIdentityIsIndependentOfTemporaryOfferAttributes(): void
    {
        $firstOffer = SupplierOffer::fromLegacyProduct([
            'OrderCode' => '00123', 'NumberInStock' => 10,
            'Prices' => [['From' => 1, 'SupplierPrice' => 2.0]],
        ], 'Original display name');
        $secondOffer = SupplierOffer::fromLegacyProduct([
            'OrderCode' => '00123', 'NumberInStock' => 0,
            'Prices' => [['From' => 5, 'SupplierPrice' => 3.0]],
            'MinimumOrderQty' => 5, 'Package' => 'Reel',
        ], 'Renamed supplier');

        self::assertTrue(
            (new SupplierOfferIdentity(3, $firstOffer->getSupplierOrderCode()))->equals(
                new SupplierOfferIdentity(3, $secondOffer->getSupplierOrderCode())
            )
        );
    }
}
