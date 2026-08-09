<?php

namespace EindSupplierSearch\Provider;

use EindCallSupplierApi;
use EindSupplierSearch\Contract\SupplierProviderInterface;
use EindSupplierSearch\Domain\NormalizedProduct;
use EindSupplierSearch\Domain\ProductIdentifier;
use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Domain\SearchResult;
use EindSupplierSearch\Domain\SupplierOffer;
use EindSupplierSearch\Exception\UnsupportedOperationException;

/**
 * Wraps the existing, working EindCallSupplierApi so it can be used behind
 * SupplierProviderInterface without touching its request/auth/field-mapping
 * behaviour. The collaborator is injected (never `new`'d here) so this
 * class stays trivial to construct in tests.
 */
final class LiveSupplierProvider implements SupplierProviderInterface
{
    public const NAME = 'live';

    private EindCallSupplierApi $callSupplierApi;

    public function __construct(EindCallSupplierApi $callSupplierApi)
    {
        $this->callSupplierApi = $callSupplierApi;
    }

    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);

        // Deliberately the raw, non-normalized text: the existing controller
        // always passed the cookie value straight through without trimming.
        $raw = $this->callSupplierApi->querySuppliers(
            $query->getOriginalText(),
            $query->getItemsOnPage(),
            $query->getPageOffset(),
            $query->getSearchType(),
            $query->getSearchFilter()
        );

        $buckets = is_array($raw) ? $raw : [];
        $durationMs = (microtime(true) - $start) * 1000;

        return SearchResult::fromLegacyBuckets($buckets, self::NAME, $durationMs);
    }

    public function getProduct(ProductIdentifier $identifier): ?NormalizedProduct
    {
        throw new UnsupportedOperationException('LiveSupplierProvider::getProduct() is not implemented yet.');
    }

    public function getOffer(ProductIdentifier $identifier, int $quantity): ?SupplierOffer
    {
        throw new UnsupportedOperationException('LiveSupplierProvider::getOffer() is not implemented yet.');
    }

    public function testConnection(): bool
    {
        try {
            return count($this->callSupplierApi->getAllApiSuppliers()) > 0;
        } catch (\Throwable $exception) {
            return false;
        }
    }
}
