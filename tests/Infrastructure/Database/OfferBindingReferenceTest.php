<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Database;

use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Infrastructure\Database\OfferBindingReference;
use PHPUnit\Framework\TestCase;

final class OfferBindingReferenceTest extends TestCase
{
    public function testLimitCountsBytesAndPreservesTheUnboundedDomainIdentity(): void
    {
        $reference = str_repeat("é", 2048);
        $identity = new SupplierOfferIdentity(1, $reference);
        self::assertSame(hash('sha256', $reference, true), OfferBindingReference::sha256($identity));
        self::assertSame(32, strlen(OfferBindingReference::sha256($identity)));
        $tooLong = new SupplierOfferIdentity(1, $reference . 'x');
        self::assertSame(4097, strlen($tooLong->getSupplierOrderReference()));
        $this->expectException(\LengthException::class);
        OfferBindingReference::sha256($tooLong);
    }

    public function testExactBytesAreHashedWithoutNormalization(): void
    {
        $reference = " 001Aa\0\xff<>\r\n ";
        $identity = new SupplierOfferIdentity(1, $reference);
        self::assertSame(hash('sha256', $reference, true), OfferBindingReference::sha256($identity));
        self::assertSame($reference, $identity->getSupplierOrderReference());
        self::assertNotSame(OfferBindingReference::sha256($identity), OfferBindingReference::sha256(new SupplierOfferIdentity(1, trim($reference))));
    }
}
