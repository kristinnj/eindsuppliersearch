<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Presenter;

use EindSupplierSearch\Domain\NormalizedProduct;
use EindSupplierSearch\Domain\ProductSearchHit;
use EindSupplierSearch\Domain\SupplierOffer;
use EindSupplierSearch\Presenter\ProductSearchHitPresenter;
use PHPUnit\Framework\TestCase;

final class ProductSearchHitPresenterTest extends TestCase
{
    private function product(array $overrides = []): NormalizedProduct
    {
        $defaults = [
            'manufacturer' => 'STMicroelectronics',
            'manufacturerPartNumber' => 'STM32F446RET6',
            'name' => 'STM32F446RET6',
            'description' => 'ARM Cortex-M4 microcontroller',
            'attributes' => [['Label' => 'Core', 'Value' => 'ARM Cortex-M4']],
            'images' => [['PrimaryURL' => 'https://example.invalid/img.jpg']],
            'datasheets' => [['SheetUrl' => 'https://example.invalid/ds.pdf']],
            'compliance' => ['rohsCompliant' => true],
        ];
        $values = array_merge($defaults, $overrides);

        return new NormalizedProduct(
            $values['manufacturer'],
            $values['manufacturerPartNumber'],
            $values['name'],
            $values['description'],
            $values['attributes'],
            $values['images'],
            $values['datasheets'],
            $values['compliance']
        );
    }

    private function offer(array $overrides = []): SupplierOffer
    {
        $defaults = [
            'supplierName' => 'Farnell',
            'supplierOrderCode' => 'FAR-1',
            'priceTiers' => [['SupplierPrice' => 1.0, 'From' => 1]],
            'stockLevel' => 100,
            'stockStatus' => 'In Stock',
            'minimumOrderQty' => 1,
            'packaging' => 'Cut Tape',
            'deliveryInfo' => null,
            'timestamp' => new \DateTimeImmutable('2026-08-25T12:34:56+00:00'),
        ];
        $values = array_merge($defaults, $overrides);

        return new SupplierOffer(
            $values['supplierName'],
            $values['supplierOrderCode'],
            $values['priceTiers'],
            $values['stockLevel'],
            $values['stockStatus'],
            $values['minimumOrderQty'],
            $values['packaging'],
            $values['deliveryInfo'],
            $values['timestamp']
        );
    }

    public function testProductFieldsArePresentedCorrectly(): void
    {
        $hit = new ProductSearchHit($this->product(), [$this->offer()]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame('STMicroelectronics', $view['manufacturer']);
        self::assertSame('STM32F446RET6', $view['manufacturerPartNumber']);
        self::assertSame('STM32F446RET6', $view['name']);
        self::assertSame('ARM Cortex-M4 microcontroller', $view['description']);
        self::assertSame([['Label' => 'Core', 'Value' => 'ARM Cortex-M4']], $view['attributes']);
        self::assertSame([['PrimaryURL' => 'https://example.invalid/img.jpg']], $view['images']);
        self::assertSame([['SheetUrl' => 'https://example.invalid/ds.pdf']], $view['datasheets']);
        self::assertSame(['rohsCompliant' => true], $view['compliance']);
    }

    public function testSupplierCountMatchesOffersCount(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['supplierName' => 'Farnell']),
            $this->offer(['supplierName' => 'Mouser']),
            $this->offer(['supplierName' => 'DigiKey']),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame(3, $view['supplierCount']);
        self::assertCount(3, $view['offers']);
    }

    public function testTwoOffersAreBothPreserved(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['supplierName' => 'Farnell']),
            $this->offer(['supplierName' => 'Mouser']),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertCount(2, $view['offers']);
    }

    public function testSupplierNamesRemainDistinct(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['supplierName' => 'Farnell']),
            $this->offer(['supplierName' => 'Mouser']),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame('Farnell', $view['offers'][0]['supplierName']);
        self::assertSame('Mouser', $view['offers'][1]['supplierName']);
    }

    public function testSupplierOrderCodesRemainDistinct(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['supplierOrderCode' => 'FAR-1']),
            $this->offer(['supplierOrderCode' => 'MOU-2']),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame('FAR-1', $view['offers'][0]['supplierOrderCode']);
        self::assertSame('MOU-2', $view['offers'][1]['supplierOrderCode']);
    }

    public function testPriceTiersRemainUnchanged(): void
    {
        $tiers = [
            ['SupplierPrice' => 1.0, 'From' => 1],
            ['SupplierPrice' => 0.8, 'From' => 10],
        ];
        $hit = new ProductSearchHit($this->product(), [$this->offer(['priceTiers' => $tiers])]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame($tiers, $view['offers'][0]['priceTiers']);
    }

    public function testStockRemainsSupplierSpecific(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['stockLevel' => 100]),
            $this->offer(['stockLevel' => 500]),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame(100, $view['offers'][0]['stockLevel']);
        self::assertSame(500, $view['offers'][1]['stockLevel']);
    }

    public function testMoqRemainsSupplierSpecific(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['minimumOrderQty' => 1]),
            $this->offer(['minimumOrderQty' => 250]),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame(1, $view['offers'][0]['minimumOrderQty']);
        self::assertSame(250, $view['offers'][1]['minimumOrderQty']);
    }

    public function testNullPackagingIsSafe(): void
    {
        $hit = new ProductSearchHit($this->product(), [$this->offer(['packaging' => null])]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertNull($view['offers'][0]['packaging']);
    }

    public function testNullDeliveryInfoIsSafe(): void
    {
        $hit = new ProductSearchHit($this->product(), [$this->offer(['deliveryInfo' => null])]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertNull($view['offers'][0]['deliveryInfo']);
    }

    public function testEmptyImagesAreSafe(): void
    {
        $hit = new ProductSearchHit($this->product(['images' => []]), [$this->offer()]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame([], $view['images']);
    }

    public function testEmptyDatasheetsAreSafe(): void
    {
        $hit = new ProductSearchHit($this->product(['datasheets' => []]), [$this->offer()]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame([], $view['datasheets']);
    }

    public function testTimestampIsEmittedAsIso8601(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['timestamp' => new \DateTimeImmutable('2026-08-25T12:34:56+00:00')]),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame('2026-08-25T12:34:56+00:00', $view['offers'][0]['timestamp']);
    }

    public function testEmptyPriceTiersAreSafe(): void
    {
        $hit = new ProductSearchHit($this->product(), [$this->offer(['priceTiers' => []])]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame([], $view['offers'][0]['priceTiers']);
    }

    public function testPresentManyPreservesNumberOfHits(): void
    {
        $hits = [
            new ProductSearchHit($this->product(['manufacturerPartNumber' => 'A']), [$this->offer()]),
            new ProductSearchHit($this->product(['manufacturerPartNumber' => 'B']), [$this->offer()]),
            new ProductSearchHit($this->product(['manufacturerPartNumber' => 'C']), [$this->offer()]),
        ];

        $views = (new ProductSearchHitPresenter())->presentMany($hits);

        self::assertCount(3, $views);
    }

    public function testPresentManyOnEmptyArrayReturnsEmptyArray(): void
    {
        self::assertSame([], (new ProductSearchHitPresenter())->presentMany([]));
    }

    /**
     * Locks down the exact key set the grouped-UI template
     * (_partials/product-search-hit.tpl) relies on, so an accidental
     * rename/removal here is caught by the test suite instead of by a
     * silently-blank field in the rendered page.
     */
    public function testPresentedShapeContainsExactlyTheKeysTheTemplateExpects(): void
    {
        $hit = new ProductSearchHit($this->product(), [$this->offer()]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame(
            ['manufacturer', 'manufacturerPartNumber', 'name', 'description', 'attributes', 'images', 'datasheets', 'compliance', 'supplierCount', 'offers'],
            array_keys($view)
        );
        self::assertSame(
            ['supplierName', 'supplierOrderCode', 'stockLevel', 'stockStatus', 'minimumOrderQty', 'packaging', 'deliveryInfo', 'priceTiers', 'timestamp'],
            array_keys($view['offers'][0])
        );
    }

    public function testOffersPreserveOrderThroughPresentation(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['supplierName' => 'Farnell']),
            $this->offer(['supplierName' => 'Mouser']),
            $this->offer(['supplierName' => 'DigiKey']),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame(
            ['Farnell', 'Mouser', 'DigiKey'],
            array_column($view['offers'], 'supplierName')
        );
    }

    /**
     * The grouped partial does not (yet) wire add-to-cart/product-detail
     * actions per offer (see docs/development/grouped-search-ui.md, "Known
     * limitations") -- but the per-offer supplier order code must still
     * come through distinctly, since it is the identifier any future
     * action would need to key off.
     */
    public function testEachOfferExposesADistinctSupplierIdentifierForFutureActions(): void
    {
        $hit = new ProductSearchHit($this->product(), [
            $this->offer(['supplierName' => 'Farnell', 'supplierOrderCode' => 'FAR-1']),
            $this->offer(['supplierName' => 'Mouser', 'supplierOrderCode' => 'MOU-2']),
        ]);

        $view = (new ProductSearchHitPresenter())->present($hit);

        self::assertSame(['FAR-1', 'MOU-2'], array_column($view['offers'], 'supplierOrderCode'));
        self::assertNotSame($view['offers'][0]['supplierOrderCode'], $view['offers'][1]['supplierOrderCode']);
    }
}
