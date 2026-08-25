<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Integration;

use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Domain\SearchResult;
use EindSupplierSearch\Exception\SupplierTimeoutException;
use EindSupplierSearch\Presenter\ProductSearchHitPresenter;
use EindSupplierSearch\Service\SearchService;
use EindSupplierSearch\Tests\Doubles\FakeSupplierProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the same pattern controllers/front/searchresults.php follows:
 * one SearchResult produces both the legacy array (search_results) and the
 * grouped view-model (product_hits), and the cached-result path
 * reconstructs a SearchResult from previously-stored legacy buckets
 * instead of re-querying a provider. A full PrestaShop controller
 * bootstrap is out of scope for this unit-test suite, so this proves the
 * underlying transformation logic instead of the framework glue.
 */
final class SearchResultToProductHitsFlowTest extends TestCase
{
    private function query(): SearchQuery
    {
        return SearchQuery::fromLegacyParameters('stm32', 25, 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function twoSupplierBuckets(): array
    {
        return [
            1 => [
                'SupplierName' => 'Farnell',
                'NumberOfResults' => 1,
                'Products' => [[
                    'OrderCode' => 'FAR-1',
                    'ManufacturerName' => 'STMicroelectronics',
                    'ManufacturerPartNumber' => 'STM32F446RET6',
                    'DisplayText' => 'STM32F446RET6',
                    'Prices' => [['SupplierPrice' => 6.1, 'From' => 1]],
                    'Stock' => ['Level' => 100, 'Status' => 'In Stock'],
                    'MinimumOrderQty' => 1,
                ]],
            ],
            2 => [
                'SupplierName' => 'Mouser',
                'NumberOfResults' => 1,
                'Products' => [[
                    'OrderCode' => 'MOU-1',
                    'ManufacturerName' => 'STMicroelectronics',
                    'ManufacturerPartNumber' => 'STM32F446RET6',
                    'DisplayText' => 'STM32F446RET6',
                    'Prices' => [['SupplierPrice' => 5.9, 'From' => 1]],
                    'Stock' => ['Level' => 500, 'Status' => 'In Stock'],
                    'MinimumOrderQty' => 1,
                ]],
            ],
        ];
    }

    public function testLiveSearchDerivesBothLegacyArrayAndProductHitsFromOneProviderCall(): void
    {
        $callCount = 0;
        $buckets = $this->twoSupplierBuckets();
        $provider = new FakeSupplierProvider(function (SearchQuery $q) use (&$callCount, $buckets): SearchResult {
            $callCount++;

            return SearchResult::fromLegacyBuckets($buckets, 'fake', 1.0);
        });

        $result = (new SearchService($provider))->search($this->query());

        // Mirrors the controller: both derived from the same $result instance.
        $legacyArray = $result->toLegacyArray();
        $productHits = $result->getProductHits();
        $presented = (new ProductSearchHitPresenter())->presentMany($productHits);

        self::assertSame(1, $callCount, 'the provider must be called exactly once');
        self::assertSame($buckets, $legacyArray);
        self::assertCount(1, $productHits);
        self::assertCount(2, $productHits[0]->getOffers());
        self::assertCount(1, $presented);
        self::assertSame(2, $presented[0]['supplierCount']);
    }

    public function testCachedLegacyBucketsCanProduceProductHitsWithoutAProvider(): void
    {
        // Simulates round-tripping through EindApiDatabase (json_encode/decode).
        $cachedBuckets = json_decode(json_encode($this->twoSupplierBuckets()), true);

        $reconstructed = SearchResult::fromLegacyBuckets($cachedBuckets, 'cache', 0.0);
        $presented = (new ProductSearchHitPresenter())->presentMany($reconstructed->getProductHits());

        self::assertCount(1, $presented);
        self::assertSame('STMicroelectronics', $presented[0]['manufacturer']);
        self::assertSame(2, $presented[0]['supplierCount']);
    }

    public function testFailedSearchProducesEmptyProductHits(): void
    {
        $provider = new FakeSupplierProvider(static function (SearchQuery $q): SearchResult {
            throw new SupplierTimeoutException('simulated timeout');
        });

        $result = (new SearchService($provider))->search($this->query());
        $presented = (new ProductSearchHitPresenter())->presentMany($result->getProductHits());

        self::assertSame([], $result->toLegacyArray());
        self::assertSame([], $presented);
    }

    public function testEmptyCachedBucketsProduceEmptyProductHits(): void
    {
        $reconstructed = SearchResult::fromLegacyBuckets([], 'cache', 0.0);
        $presented = (new ProductSearchHitPresenter())->presentMany($reconstructed->getProductHits());

        self::assertSame([], $presented);
    }
}
