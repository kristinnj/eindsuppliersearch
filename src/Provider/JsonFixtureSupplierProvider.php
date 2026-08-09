<?php

namespace EindSupplierSearch\Provider;

use EindSupplierSearch\Contract\SupplierProviderInterface;
use EindSupplierSearch\Domain\FixtureScenario;
use EindSupplierSearch\Domain\NormalizedProduct;
use EindSupplierSearch\Domain\ProductIdentifier;
use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Domain\SearchResult;
use EindSupplierSearch\Domain\SupplierOffer;
use EindSupplierSearch\Exception\FixtureNotFoundException;
use EindSupplierSearch\Exception\SupplierMalformedResponseException;
use EindSupplierSearch\Exception\SupplierTimeoutException;
use EindSupplierSearch\Exception\UnsupportedOperationException;

/**
 * Serves supplier search results from local JSON fixtures instead of an
 * HTTP call. Returns data through the exact same legacy-array shape the
 * live provider produces, so the controller/template never know the
 * difference. Never performs network or database access.
 */
final class JsonFixtureSupplierProvider implements SupplierProviderInterface
{
    public const NAME = 'fixture';

    /** Synthetic supplier id used for the single fixture "supplier" bucket. */
    private const FIXTURE_SUPPLIER_ID = 900001;

    private string $fixturesPath;

    /** @var callable(string, int): void */
    private $logger;

    /**
     * @param string $fixturesPath absolute path to tests/fixtures/suppliers
     * @param callable(string, int): void|null $logger Receives (message, severity).
     */
    public function __construct(string $fixturesPath, ?callable $logger = null)
    {
        $this->fixturesPath = rtrim($fixturesPath, '/\\');
        $this->logger = $logger ?? static function (string $message, int $severity): void {
        };
    }

    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);
        $scenario = $this->resolveScenario($query);

        ($this->logger)(sprintf('fixture search scenario=%s query=%s', $scenario, $query->getOriginalText()), 1);

        if ($scenario === FixtureScenario::TIMEOUT) {
            ($this->logger)('simulated supplier timeout (fixture scenario TIMEOUT)', 3);
            throw new SupplierTimeoutException('Simulated supplier timeout for fixture scenario TIMEOUT.');
        }

        $bucket = $this->loadScenarioBucket($scenario);
        $durationMs = (microtime(true) - $start) * 1000;

        return SearchResult::fromLegacyBuckets([self::FIXTURE_SUPPLIER_ID => $bucket], self::NAME, $durationMs);
    }

    public function getProduct(ProductIdentifier $identifier): ?NormalizedProduct
    {
        throw new UnsupportedOperationException('JsonFixtureSupplierProvider::getProduct() is not implemented yet.');
    }

    public function getOffer(ProductIdentifier $identifier, int $quantity): ?SupplierOffer
    {
        throw new UnsupportedOperationException('JsonFixtureSupplierProvider::getOffer() is not implemented yet.');
    }

    public function testConnection(): bool
    {
        return is_dir($this->fixturesPath) && is_readable($this->fixturesPath);
    }

    /**
     * Resolution order: an explicit SearchQuery::fixtureScenario (the path
     * PHPUnit tests use) wins; otherwise, as a manual-QA convenience, a
     * search text that exactly matches a scenario name (case-insensitive)
     * selects that scenario; otherwise a sensible default.
     */
    private function resolveScenario(SearchQuery $query): string
    {
        $explicit = $query->getFixtureScenario();
        if ($explicit !== null && FixtureScenario::isValid($explicit)) {
            return $explicit;
        }

        $byText = strtoupper(trim($query->getOriginalText()));
        if (FixtureScenario::isValid($byText)) {
            return $byText;
        }

        return FixtureScenario::DEFAULT;
    }

    /** @return array<string, mixed> */
    private function loadScenarioBucket(string $scenario): array
    {
        $path = $this->fixturesPath . '/' . FixtureScenario::filename($scenario);

        if (!is_file($path)) {
            ($this->logger)(sprintf('fixture file not found for scenario=%s path=%s', $scenario, $path), 2);
            throw new FixtureNotFoundException(sprintf('No fixture file found for scenario "%s".', $scenario));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            ($this->logger)(sprintf('fixture file unreadable for scenario=%s path=%s', $scenario, $path), 3);
            throw new SupplierMalformedResponseException(sprintf('Fixture file for scenario "%s" could not be read.', $scenario));
        }

        $decoded = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            ($this->logger)(sprintf(
                'malformed fixture JSON for scenario=%s error=%s',
                $scenario,
                json_last_error_msg()
            ), 3);
            throw new SupplierMalformedResponseException(sprintf(
                'Fixture data for scenario "%s" is not valid: %s',
                $scenario,
                json_last_error_msg()
            ));
        }

        return $decoded;
    }
}
