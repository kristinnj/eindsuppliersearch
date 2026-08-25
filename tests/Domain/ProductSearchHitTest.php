<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Domain;

use EindSupplierSearch\Domain\NormalizedProduct;
use EindSupplierSearch\Domain\ProductSearchHit;
use EindSupplierSearch\Domain\SupplierOffer;
use PHPUnit\Framework\TestCase;

final class ProductSearchHitTest extends TestCase
{
    private function product(string $manufacturer = 'STMicroelectronics', string $mpn = 'STM32F446RET6'): NormalizedProduct
    {
        return new NormalizedProduct($manufacturer, $mpn, 'Name', 'Description', [], [], [], []);
    }

    private function offer(string $supplierName, string $orderCode): SupplierOffer
    {
        return new SupplierOffer($supplierName, $orderCode, [], 0, 'In Stock', 1, null, null, new \DateTimeImmutable());
    }

    public function testExposesTheProductItWasConstructedWith(): void
    {
        $product = $this->product();
        $hit = new ProductSearchHit($product, [$this->offer('Farnell', 'A1')]);

        self::assertSame($product, $hit->getProduct());
    }

    public function testExposesASingleOffer(): void
    {
        $offer = $this->offer('Farnell', 'A1');
        $hit = new ProductSearchHit($this->product(), [$offer]);

        self::assertSame([$offer], $hit->getOffers());
    }

    public function testExposesMultipleOffersInGivenOrder(): void
    {
        $farnell = $this->offer('Farnell', 'A1');
        $mouser = $this->offer('Mouser', 'B2');
        $digikey = $this->offer('DigiKey', 'C3');

        $hit = new ProductSearchHit($this->product(), [$farnell, $mouser, $digikey]);

        self::assertSame([$farnell, $mouser, $digikey], $hit->getOffers());
        self::assertCount(3, $hit->getOffers());
    }

    public function testOffersRemainIndependentObjectsWithDistinctData(): void
    {
        $farnell = $this->offer('Farnell', 'A1');
        $mouser = $this->offer('Mouser', 'B2');

        $hit = new ProductSearchHit($this->product(), [$farnell, $mouser]);

        $offers = $hit->getOffers();
        self::assertSame('Farnell', $offers[0]->getSupplierName());
        self::assertSame('A1', $offers[0]->getSupplierOrderCode());
        self::assertSame('Mouser', $offers[1]->getSupplierName());
        self::assertSame('B2', $offers[1]->getSupplierOrderCode());
    }
}
