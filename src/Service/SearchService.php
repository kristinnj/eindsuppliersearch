<?php

namespace EindSupplierSearch\Service;

use EindSupplierSearch\Contract\SupplierProviderInterface;
use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Domain\SearchResult;
use EindSupplierSearch\Exception\SupplierProviderException;

/**
 * Thin orchestration layer between the controller and a
 * SupplierProviderInterface. Knows nothing about HTTP, JSON fixtures, or
 * Smarty -- only how to call a provider safely and time it.
 */
final class SearchService
{
    private SupplierProviderInterface $provider;

    /** @var callable(string, int): void */
    private $logger;

    /**
     * @param callable(string, int): void|null $logger Receives (message, severity).
     *   Severity follows PrestaShopLogger conventions (1=info .. 4=error).
     */
    public function __construct(SupplierProviderInterface $provider, ?callable $logger = null)
    {
        $this->provider = $provider;
        $this->logger = $logger ?? static function (string $message, int $severity): void {
        };
    }

    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);
        $providerName = get_class($this->provider);

        try {
            $result = $this->provider->search($query);

            ($this->logger)(sprintf(
                'search provider=%s searchType=%s fixtureScenario=%s durationMs=%.1f results=%d',
                $providerName,
                $query->getSearchType(),
                $query->getFixtureScenario() ?? '-',
                $result->getDurationMs(),
                $result->getNumberOfResults()
            ), 1);

            return $result;
        } catch (SupplierProviderException $exception) {
            $durationMs = (microtime(true) - $start) * 1000;

            ($this->logger)(sprintf(
                'search provider=%s searchType=%s failed (%s): %s',
                $providerName,
                $query->getSearchType(),
                (new \ReflectionClass($exception))->getShortName(),
                $exception->getMessage()
            ), 3);

            return SearchResult::failedResult($providerName, $durationMs, $exception->getMessage());
        } catch (\Throwable $exception) {
            // Defense in depth: a provider must never be able to crash the
            // storefront, even via a bug we didn't anticipate.
            $durationMs = (microtime(true) - $start) * 1000;

            ($this->logger)(sprintf(
                'search provider=%s searchType=%s raised an unexpected error: %s',
                $providerName,
                $query->getSearchType(),
                $exception->getMessage()
            ), 4);

            return SearchResult::failedResult($providerName, $durationMs, 'unexpected_error');
        }
    }
}
