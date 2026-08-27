<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Domain;

use EindSupplierSearch\Domain\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    /**
     * Builds one legacy supplier bucket the way EindCallSupplierApi does:
     * {SupplierName, NumberOfResults, Products: [...]}.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<string, mixed>
     */
    private function bucket(string $supplierName, array $products): array
    {
        return [
            'SupplierName' => $supplierName,
            'NumberOfResults' => count($products),
            'Products' => $products,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'OrderCode' => 'DEFAULT-CODE',
            'ManufacturerName' => 'STMicroelectronics',
            'ManufacturerPartNumber' => 'STM32F446RET6',
            'ProductName' => 'STMicroelectronics - STM32F446RET6',
            'DisplayText' => 'ARM Cortex-M4 microcontroller',
            'Prices' => [['SupplierPrice' => 1.0, 'ProductPrice' => 1.5, 'From' => 1]],
            'Stock' => ['Level' => 10, 'Status' => 'In Stock'],
            'MinimumOrderQty' => 1,
        ], $overrides);
    }

    // --- Task 4/10.1-10.4: grouping by identity -----------------------

    public function testSameManufacturerAndMpnFromTwoSuppliersBecomesOneHitWithTwoOffers(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'FAR-1'])]),
            2 => $this->bucket('Mouser', [$this->product(['OrderCode' => 'MOU-1'])]),
        ], 'fake', 1.0);

        $hits = $result->getProductHits();

        self::assertCount(1, $hits);
        self::assertCount(2, $hits[0]->getOffers());
    }

    public function testSameManufacturerAndMpnFromThreeSuppliersBecomesOneHitWithThreeOffers(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'FAR-1'])]),
            2 => $this->bucket('Mouser', [$this->product(['OrderCode' => 'MOU-1'])]),
            3 => $this->bucket('DigiKey', [$this->product(['OrderCode' => 'DK-1'])]),
        ], 'fake', 1.0);

        $hits = $result->getProductHits();

        self::assertCount(1, $hits);
        self::assertCount(3, $hits[0]->getOffers());
        self::assertSame(
            ['Farnell', 'Mouser', 'DigiKey'],
            array_map(static fn ($offer) => $offer->getSupplierName(), $hits[0]->getOffers())
        );
    }

    public function testSameManufacturerDifferentMpnBecomesTwoHits(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['ManufacturerPartNumber' => 'STM32F446RET6'])]),
            2 => $this->bucket('Mouser', [$this->product(['ManufacturerPartNumber' => 'STM32F103C8T6'])]),
        ], 'fake', 1.0);

        self::assertCount(2, $result->getProductHits());
    }

    public function testDifferentManufacturerSameMpnBecomesTwoHits(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['ManufacturerName' => 'STMicroelectronics'])]),
            2 => $this->bucket('Mouser', [$this->product(['ManufacturerName' => 'NXP'])]),
        ], 'fake', 1.0);

        self::assertCount(2, $result->getProductHits());
    }

    // --- Task 3/10.5-10.8: conservative normalization ------------------

    public function testManufacturerComparisonIsCaseInsensitive(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['ManufacturerName' => 'STMicroelectronics'])]),
            2 => $this->bucket('Mouser', [$this->product(['ManufacturerName' => 'STMICROELECTRONICS'])]),
        ], 'fake', 1.0);

        self::assertCount(1, $result->getProductHits());
    }

    public function testMpnComparisonIsCaseInsensitive(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['ManufacturerPartNumber' => 'stm32f446ret6'])]),
            2 => $this->bucket('Mouser', [$this->product(['ManufacturerPartNumber' => 'STM32F446RET6'])]),
        ], 'fake', 1.0);

        self::assertCount(1, $result->getProductHits());
    }

    public function testLeadingAndTrailingWhitespaceDoesNotPreventMatching(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['ManufacturerPartNumber' => '  STM32F446RET6  '])]),
            2 => $this->bucket('Mouser', [$this->product(['ManufacturerPartNumber' => 'STM32F446RET6'])]),
        ], 'fake', 1.0);

        self::assertCount(1, $result->getProductHits());
    }

    public function testRepeatedInternalWhitespaceDoesNotPreventMatching(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['ManufacturerName' => 'STMicroelectronics   Inc'])]),
            2 => $this->bucket('Mouser', [$this->product(['ManufacturerName' => 'STMicroelectronics Inc'])]),
        ], 'fake', 1.0);

        self::assertCount(1, $result->getProductHits());
    }

    // --- Task 5/10.9-10.10: safe fallback on missing identity ----------

    public function testMissingManufacturerDoesNotMergeUnrelatedProducts(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['ManufacturerName' => '', 'OrderCode' => 'FAR-1'])]),
            2 => $this->bucket('Mouser', [$this->product(['ManufacturerName' => '', 'OrderCode' => 'MOU-1'])]),
        ], 'fake', 1.0);

        $hits = $result->getProductHits();

        self::assertCount(2, $hits);
        self::assertCount(1, $hits[0]->getOffers());
        self::assertCount(1, $hits[1]->getOffers());
    }

    public function testMissingMpnDoesNotMergeUnrelatedProducts(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['ManufacturerPartNumber' => '', 'OrderCode' => 'FAR-1'])]),
            2 => $this->bucket('Mouser', [$this->product(['ManufacturerPartNumber' => '', 'OrderCode' => 'MOU-1'])]),
        ], 'fake', 1.0);

        $hits = $result->getProductHits();

        self::assertCount(2, $hits);
        self::assertCount(1, $hits[0]->getOffers());
        self::assertCount(1, $hits[1]->getOffers());
    }

    public function testMissingIdentityAndOrderCodeStillKeepsRowsSeparate(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('', [$this->product(['ManufacturerName' => '', 'ManufacturerPartNumber' => '', 'OrderCode' => ''])]),
            2 => $this->bucket('', [$this->product(['ManufacturerName' => '', 'ManufacturerPartNumber' => '', 'OrderCode' => ''])]),
        ], 'fake', 1.0);

        self::assertCount(2, $result->getProductHits());
    }

    // --- Task 8/10.11-10.14: offers stay independent -------------------

    public function testSupplierOrderCodesRemainDistinct(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'ABC123'])]),
            2 => $this->bucket('Mouser', [$this->product(['OrderCode' => 'XYZ789'])]),
        ], 'fake', 1.0);

        $offers = $result->getProductHits()[0]->getOffers();

        self::assertSame('ABC123', $offers[0]->getSupplierOrderCode());
        self::assertSame('XYZ789', $offers[1]->getSupplierOrderCode());
    }

    public function testStockRemainsSupplierSpecific(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['Stock' => ['Level' => 100, 'Status' => 'In Stock']])]),
            2 => $this->bucket('Mouser', [$this->product(['Stock' => ['Level' => 500, 'Status' => 'In Stock']])]),
        ], 'fake', 1.0);

        $offers = $result->getProductHits()[0]->getOffers();

        self::assertSame(100, $offers[0]->getStockLevel());
        self::assertSame(500, $offers[1]->getStockLevel());
    }

    public function testPriceTiersRemainSupplierSpecific(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['Prices' => [['SupplierPrice' => 1.0, 'From' => 1]]])]),
            2 => $this->bucket('Mouser', [$this->product(['Prices' => [
                ['SupplierPrice' => 0.9, 'From' => 1],
                ['SupplierPrice' => 0.8, 'From' => 10],
            ]])]),
        ], 'fake', 1.0);

        $offers = $result->getProductHits()[0]->getOffers();

        self::assertCount(1, $offers[0]->getPriceTiers());
        self::assertCount(2, $offers[1]->getPriceTiers());
    }

    public function testMoqRemainsSupplierSpecific(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['MinimumOrderQty' => 1])]),
            2 => $this->bucket('Mouser', [$this->product(['MinimumOrderQty' => 250])]),
        ], 'fake', 1.0);

        $offers = $result->getProductHits()[0]->getOffers();

        self::assertSame(1, $offers[0]->getMinimumOrderQty());
        self::assertSame(250, $offers[1]->getMinimumOrderQty());
    }

    // --- Task 9: no accidental duplication -----------------------------

    public function testSingleProductRowProducesExactlyOneOffer(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product()]),
        ], 'fake', 1.0);

        $hits = $result->getProductHits();

        self::assertCount(1, $hits);
        self::assertCount(1, $hits[0]->getOffers());
    }

    // --- Task 6/10.15-10.17: legacy methods unchanged -------------------

    public function testToLegacyArrayRemainsUnchangedWithMultipleSuppliers(): void
    {
        $buckets = [
            1 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'FAR-1'])]),
            2 => $this->bucket('Mouser', [$this->product(['OrderCode' => 'MOU-1'])]),
        ];
        $result = SearchResult::fromLegacyBuckets($buckets, 'fake', 1.0);

        self::assertSame($buckets, $result->toLegacyArray());
    }

    public function testGetProductsBehaviorRemainsUnchangedAndFlat(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'FAR-1'])]),
            2 => $this->bucket('Mouser', [$this->product(['OrderCode' => 'MOU-1'])]),
        ], 'fake', 1.0);

        // Same product from two suppliers: getProducts() stays a flat
        // two-entry list (no grouping), unlike getProductHits().
        self::assertCount(2, $result->getProducts());
        self::assertSame('STM32F446RET6', $result->getProducts()[0]->getManufacturerPartNumber());
        self::assertSame('STM32F446RET6', $result->getProducts()[1]->getManufacturerPartNumber());
    }

    public function testGetOffersBehaviorRemainsUnchangedAndFlat(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'FAR-1'])]),
            2 => $this->bucket('Mouser', [$this->product(['OrderCode' => 'MOU-1'])]),
        ], 'fake', 1.0);

        $offers = $result->getOffers();

        self::assertCount(2, $offers);
        self::assertSame('FAR-1', $offers[0]->getSupplierOrderCode());
        self::assertSame('MOU-1', $offers[1]->getSupplierOrderCode());
    }

    public function testGetNumberOfResultsRemainsUnchanged(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product()]),
            2 => $this->bucket('Mouser', [$this->product()]),
        ], 'fake', 1.0);

        self::assertSame(2, $result->getNumberOfResults());
    }

    // --- edge cases -------------------------------------------------

    public function testFailedResultProducesNoProductHits(): void
    {
        $result = SearchResult::failedResult('fake', 1.0, 'simulated timeout');

        self::assertSame([], $result->getProductHits());
    }

    public function testGetProductHitsIsCachedAcrossCalls(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product()]),
        ], 'fake', 1.0);

        self::assertSame($result->getProductHits(), $result->getProductHits());
    }

    // --- Task 11: reuse of the multi-supplier fixture -------------------

    public function testMultiSupplierFixtureFileGroupsIntoOneHitWithThreeDistinctOffers(): void
    {
        $path = __DIR__ . '/../fixtures/suppliers/multi-supplier-same-product.json';
        $buckets = json_decode((string) file_get_contents($path), true);

        $result = SearchResult::fromLegacyBuckets($buckets, 'fake', 1.0);
        $hits = $result->getProductHits();

        self::assertCount(1, $hits);
        self::assertSame('STMicroelectronics', $hits[0]->getProduct()->getManufacturer());
        self::assertSame('STM32F446RET6', $hits[0]->getProduct()->getManufacturerPartNumber());

        $offers = $hits[0]->getOffers();
        self::assertCount(3, $offers);
        self::assertSame(['Farnell', 'Mouser', 'DigiKey'], array_map(static fn ($o) => $o->getSupplierName(), $offers));
        self::assertSame([100, 500, 2300], array_map(static fn ($o) => $o->getStockLevel(), $offers));
        self::assertSame(
            ['FAR-STM32F446', 'MOU-511-STM32F446RET6', '497-STM32F446RET6-ND'],
            array_map(static fn ($o) => $o->getSupplierOrderCode(), $offers)
        );
    }

    // --- Offer action references (OfferActionReference) -----------------

    public function testThreeSuppliersGroupedUnderOneProductRetainThreeDistinctActionReferences(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            100001 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'FAR-1'])]),
            100002 => $this->bucket('Mouser', [$this->product(['OrderCode' => 'MOU-1'])]),
            100003 => $this->bucket('DigiKey', [$this->product(['OrderCode' => 'DK-1'])]),
        ], 'fake', 1.0);

        $offers = $result->getProductHits()[0]->getOffers();

        self::assertCount(3, $offers);
        $bucketIds = array_map(static fn ($o) => $o->getActionReference()->getSupplierBucketId(), $offers);
        self::assertSame([100001, 100002, 100003], $bucketIds);
        self::assertSame(3, count(array_unique($bucketIds)));
    }

    public function testActionReferenceSourceRowMatchesThatOffersPositionWithinItsOwnBucket(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            100001 => $this->bucket('Farnell', [
                $this->product(['OrderCode' => 'FAR-A', 'ManufacturerPartNumber' => 'PART-A']),
                $this->product(['OrderCode' => 'FAR-B', 'ManufacturerPartNumber' => 'PART-B']),
            ]),
        ], 'fake', 1.0);

        $hits = $result->getProductHits();
        $hitByMpn = [];
        foreach ($hits as $hit) {
            $hitByMpn[$hit->getProduct()->getManufacturerPartNumber()] = $hit;
        }

        self::assertSame(0, $hitByMpn['PART-A']->getOffers()[0]->getActionReference()->getSourceRowIndex());
        self::assertSame(1, $hitByMpn['PART-B']->getOffers()[0]->getActionReference()->getSourceRowIndex());
    }

    public function testActionReferenceNeverLeaksBetweenOffersInGroupedResult(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            100001 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'FAR-1'])]),
            100002 => $this->bucket('Mouser', [$this->product(['OrderCode' => 'MOU-1'])]),
        ], 'fake', 1.0);

        $offers = $result->getProductHits()[0]->getOffers();
        $farnellOffer = $offers[0];
        $mouserOffer = $offers[1];

        self::assertSame('Farnell', $farnellOffer->getSupplierName());
        self::assertSame(100001, $farnellOffer->getActionReference()->getSupplierBucketId());
        self::assertSame('Mouser', $mouserOffer->getSupplierName());
        self::assertSame(100002, $mouserOffer->getActionReference()->getSupplierBucketId());
    }

    public function testActionReferencesDoNotAffectProductAggregationIdentity(): void
    {
        // Same manufacturer+MPN from two different bucket keys must still
        // merge into one hit -- the action reference is metadata, not part
        // of computeProductIdentityKey().
        $result = SearchResult::fromLegacyBuckets([
            100001 => $this->bucket('Farnell', [$this->product(['OrderCode' => 'FAR-1'])]),
            999999 => $this->bucket('SomeOtherSupplier', [$this->product(['OrderCode' => 'OTH-1'])]),
        ], 'fake', 1.0);

        self::assertCount(1, $result->getProductHits());
        self::assertCount(2, $result->getProductHits()[0]->getOffers());
    }

    public function testLegacyGetOffersNeverCarriesAnActionReference(): void
    {
        $result = SearchResult::fromLegacyBuckets([
            1 => $this->bucket('Farnell', [$this->product()]),
        ], 'fake', 1.0);

        foreach ($result->getOffers() as $offer) {
            self::assertNull($offer->getActionReference());
        }
    }

    public function testMultiSupplierFixtureOffersEachHaveAnIndependentActionReference(): void
    {
        $path = __DIR__ . '/../fixtures/suppliers/multi-supplier-same-product.json';
        $buckets = json_decode((string) file_get_contents($path), true);

        $offers = SearchResult::fromLegacyBuckets($buckets, 'fake', 1.0)->getProductHits()[0]->getOffers();

        $bucketIds = array_map(static fn ($o) => $o->getActionReference()->getSupplierBucketId(), $offers);
        self::assertSame([100001, 100002, 100003], $bucketIds);
        self::assertSame([0, 0, 0], array_map(static fn ($o) => $o->getActionReference()->getSourceRowIndex(), $offers));
    }
}
