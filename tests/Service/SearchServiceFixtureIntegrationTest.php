<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Service;

use EindSupplierSearch\Domain\FixtureScenario;
use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Provider\JsonFixtureSupplierProvider;
use EindSupplierSearch\Service\SearchService;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end (SearchService + the real JsonFixtureSupplierProvider, no
 * mocking) proof that timeout / malformed / missing-fixture scenarios
 * never let an exception reach the controller: SearchService must always
 * hand back a safe, empty-but-valid SearchResult instead.
 */
final class SearchServiceFixtureIntegrationTest extends TestCase
{
    private const FIXTURES_PATH = __DIR__ . '/../fixtures/suppliers';

    private function serviceWithProviderPath(string $fixturesPath): SearchService
    {
        return new SearchService(new JsonFixtureSupplierProvider($fixturesPath));
    }

    public function testTimeoutScenarioDoesNotThrowAndReturnsFailedResult(): void
    {
        $query = SearchQuery::fromLegacyParameters('anything', 25, 1, SearchQuery::SEARCH_TYPE_ANY, '', FixtureScenario::TIMEOUT);

        $result = $this->serviceWithProviderPath(self::FIXTURES_PATH)->search($query);

        self::assertTrue($result->isFailed());
        self::assertSame([], $result->toLegacyArray());
    }

    public function testMalformedResponseScenarioDoesNotThrowAndReturnsFailedResult(): void
    {
        $query = SearchQuery::fromLegacyParameters('anything', 25, 1, SearchQuery::SEARCH_TYPE_ANY, '', FixtureScenario::MALFORMED_RESPONSE);

        $result = $this->serviceWithProviderPath(self::FIXTURES_PATH)->search($query);

        self::assertTrue($result->isFailed());
        self::assertSame([], $result->toLegacyArray());
    }

    public function testMissingFixtureDoesNotThrowAndReturnsFailedResult(): void
    {
        $query = SearchQuery::fromLegacyParameters('anything', 25, 1, SearchQuery::SEARCH_TYPE_ANY, '', FixtureScenario::ONE_RESULT);

        $result = $this->serviceWithProviderPath(__DIR__ . '/../fixtures/does-not-exist')->search($query);

        self::assertTrue($result->isFailed());
        self::assertSame([], $result->toLegacyArray());
    }

    public function testHappyPathStillReturnsUsableLegacyArrayThroughSearchService(): void
    {
        $query = SearchQuery::fromLegacyParameters('anything', 25, 1, SearchQuery::SEARCH_TYPE_ANY, '', FixtureScenario::ONE_RESULT);

        $result = $this->serviceWithProviderPath(self::FIXTURES_PATH)->search($query);

        self::assertFalse($result->isFailed());
        self::assertNotSame([], $result->toLegacyArray());
        self::assertSame(1, $result->getNumberOfResults());
    }
}
