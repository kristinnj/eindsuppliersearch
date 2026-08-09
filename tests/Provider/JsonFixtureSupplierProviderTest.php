<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Provider;

use EindSupplierSearch\Domain\FixtureScenario;
use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Exception\FixtureNotFoundException;
use EindSupplierSearch\Exception\SupplierMalformedResponseException;
use EindSupplierSearch\Exception\SupplierTimeoutException;
use EindSupplierSearch\Provider\JsonFixtureSupplierProvider;
use PHPUnit\Framework\TestCase;

final class JsonFixtureSupplierProviderTest extends TestCase
{
    private const FIXTURES_PATH = __DIR__ . '/../fixtures/suppliers';

    private function provider(): JsonFixtureSupplierProvider
    {
        return new JsonFixtureSupplierProvider(self::FIXTURES_PATH);
    }

    private function queryFor(string $scenario): SearchQuery
    {
        return SearchQuery::fromLegacyParameters('anything', 25, 1, SearchQuery::SEARCH_TYPE_ANY, '', $scenario);
    }

    public function testExactPartNumberFixtureLoads(): void
    {
        $result = $this->provider()->search($this->queryFor(FixtureScenario::EXACT_PART_NUMBER));

        self::assertFalse($result->isFailed());
        self::assertSame(1, $result->getNumberOfResults());
        self::assertCount(1, $result->getProducts());
        self::assertSame('RC0603FR-0710KL', $result->getProducts()[0]->getManufacturerPartNumber());
    }

    public function testKeywordManyResultsFixtureLoads(): void
    {
        $result = $this->provider()->search($this->queryFor(FixtureScenario::KEYWORD_MANY_RESULTS));

        self::assertFalse($result->isFailed());
        self::assertSame(214, $result->getNumberOfResults());
        self::assertGreaterThan(1, count($result->getProducts()));
    }

    public function testOneResultFixtureLoads(): void
    {
        $result = $this->provider()->search($this->queryFor(FixtureScenario::ONE_RESULT));

        self::assertSame(1, $result->getNumberOfResults());
        self::assertCount(1, $result->getProducts());
    }

    public function testNoResultsFixtureReturnsEmptyProductList(): void
    {
        $result = $this->provider()->search($this->queryFor(FixtureScenario::NO_RESULTS));

        self::assertFalse($result->isFailed());
        self::assertSame(0, $result->getNumberOfResults());
        self::assertSame([], $result->getProducts());
        // Still a legacy bucket present (not a hard failure) so the template's
        // per-supplier "no results" branch renders normally.
        self::assertNotSame([], $result->toLegacyArray());
    }

    public function testOutOfStockFixtureLoads(): void
    {
        $result = $this->provider()->search($this->queryFor(FixtureScenario::OUT_OF_STOCK));

        self::assertSame(2, $result->getNumberOfResults());
        foreach ($result->getOffers() as $offer) {
            self::assertSame(0, $offer->getStockLevel());
        }
    }

    public function testQuantityPricesFixtureLoads(): void
    {
        $result = $this->provider()->search($this->queryFor(FixtureScenario::QUANTITY_PRICES));

        self::assertCount(1, $result->getOffers());
        self::assertGreaterThan(1, count($result->getOffers()[0]->getPriceTiers()));
    }

    public function testMalformedResponseThrows(): void
    {
        $this->expectException(SupplierMalformedResponseException::class);

        $this->provider()->search($this->queryFor(FixtureScenario::MALFORMED_RESPONSE));
    }

    public function testTimeoutThrowsWithoutReadingAnyFile(): void
    {
        $this->expectException(SupplierTimeoutException::class);

        $this->provider()->search($this->queryFor(FixtureScenario::TIMEOUT));
    }

    public function testMissingFixtureThrowsFixtureNotFoundException(): void
    {
        $provider = new JsonFixtureSupplierProvider(__DIR__ . '/../fixtures/does-not-exist');

        $this->expectException(FixtureNotFoundException::class);

        $provider->search($this->queryFor(FixtureScenario::ONE_RESULT));
    }

    public function testSearchTextCanSelectScenarioWithoutAnExplicitFixtureScenario(): void
    {
        $query = SearchQuery::fromLegacyParameters(FixtureScenario::NO_RESULTS, 25, 1);

        $result = $this->provider()->search($query);

        self::assertSame(0, $result->getNumberOfResults());
    }

    public function testUnrecognisedQueryFallsBackToDefaultScenario(): void
    {
        $query = SearchQuery::fromLegacyParameters('some ad-hoc keyword', 25, 1);

        $result = $this->provider()->search($query);

        self::assertFalse($result->isFailed());
        self::assertSame(FixtureScenario::DEFAULT, FixtureScenario::KEYWORD_MANY_RESULTS);
    }

    public function testTestConnectionReflectsFixturesDirectoryPresence(): void
    {
        self::assertTrue($this->provider()->testConnection());
        self::assertFalse((new JsonFixtureSupplierProvider(__DIR__ . '/../fixtures/does-not-exist'))->testConnection());
    }
}
