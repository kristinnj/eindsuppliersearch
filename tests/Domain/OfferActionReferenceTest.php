<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Domain;

use EindSupplierSearch\Domain\OfferActionReference;
use PHPUnit\Framework\TestCase;

final class OfferActionReferenceTest extends TestCase
{
    public function testStoresTheSuppliedBucketIdAndRowIndex(): void
    {
        $reference = new OfferActionReference(100001, 3);

        self::assertSame(100001, $reference->getSupplierBucketId());
        self::assertSame(3, $reference->getSourceRowIndex());
    }

    public function testAcceptsAStringBucketId(): void
    {
        $reference = new OfferActionReference('farnell-uk', 0);

        self::assertSame('farnell-uk', $reference->getSupplierBucketId());
    }

    public function testTwoReferencesForDifferentOffersAreIndependent(): void
    {
        $farnell = new OfferActionReference(100001, 0);
        $mouser = new OfferActionReference(100002, 0);

        self::assertNotSame($farnell->getSupplierBucketId(), $mouser->getSupplierBucketId());
    }

    public function testDistinctRowsWithinTheSameBucketAreIndependent(): void
    {
        $first = new OfferActionReference(100001, 0);
        $second = new OfferActionReference(100001, 1);

        self::assertSame($first->getSupplierBucketId(), $second->getSupplierBucketId());
        self::assertNotSame($first->getSourceRowIndex(), $second->getSourceRowIndex());
    }

    public function testRejectsAnEmptyStringBucketId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OfferActionReference('', 0);
    }

    public function testRejectsANegativeSourceRowIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OfferActionReference(100001, -1);
    }
}
