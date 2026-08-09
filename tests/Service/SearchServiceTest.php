<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Service;

use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Domain\SearchResult;
use EindSupplierSearch\Exception\SupplierTimeoutException;
use EindSupplierSearch\Service\SearchService;
use EindSupplierSearch\Tests\Doubles\FakeSupplierProvider;
use PHPUnit\Framework\TestCase;

final class SearchServiceTest extends TestCase
{
    private function query(): SearchQuery
    {
        return SearchQuery::fromLegacyParameters('10k resistor', 25, 1);
    }

    public function testReturnsTheProviderResultUnchangedOnSuccess(): void
    {
        $expected = SearchResult::fromLegacyBuckets([1 => ['SupplierName' => 'Acme', 'NumberOfResults' => 3, 'Products' => []]], 'fake', 5.0);
        $provider = new FakeSupplierProvider(static fn (SearchQuery $q) => $expected);

        $result = (new SearchService($provider))->search($this->query());

        self::assertSame($expected, $result);
        self::assertFalse($result->isFailed());
    }

    public function testCatchesProviderExceptionsAndReturnsASafeFailedResult(): void
    {
        $provider = new FakeSupplierProvider(static function (SearchQuery $q): SearchResult {
            throw new SupplierTimeoutException('simulated timeout');
        });

        $result = (new SearchService($provider))->search($this->query());

        self::assertInstanceOf(SearchResult::class, $result);
        self::assertTrue($result->isFailed());
        self::assertSame([], $result->toLegacyArray());
        self::assertSame('simulated timeout', $result->getFailureReason());
    }

    public function testCatchesUnexpectedThrowablesAndReturnsASafeFailedResult(): void
    {
        $provider = new FakeSupplierProvider(static function (SearchQuery $q): SearchResult {
            throw new \RuntimeException('something the provider was never supposed to throw');
        });

        $result = (new SearchService($provider))->search($this->query());

        self::assertTrue($result->isFailed());
        self::assertSame([], $result->toLegacyArray());
    }

    public function testMeasuresDurationOnFailure(): void
    {
        $provider = new FakeSupplierProvider(static function (SearchQuery $q): SearchResult {
            throw new SupplierTimeoutException('simulated timeout');
        });

        $result = (new SearchService($provider))->search($this->query());

        self::assertGreaterThanOrEqual(0.0, $result->getDurationMs());
    }

    public function testLoggerReceivesAMessageOnSuccessAndOnFailure(): void
    {
        $messages = [];
        $logger = function (string $message, int $severity) use (&$messages): void {
            $messages[] = [$message, $severity];
        };

        $okProvider = new FakeSupplierProvider(
            static fn (SearchQuery $q) => SearchResult::fromLegacyBuckets([], 'fake', 1.0)
        );
        (new SearchService($okProvider, $logger))->search($this->query());

        $failProvider = new FakeSupplierProvider(static function (SearchQuery $q): SearchResult {
            throw new SupplierTimeoutException('simulated timeout');
        });
        (new SearchService($failProvider, $logger))->search($this->query());

        self::assertCount(2, $messages);
        self::assertSame(3, $messages[1][1]);
    }
}
