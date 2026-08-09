<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Doubles;

use EindSupplierSearch\Contract\SupplierProviderInterface;
use EindSupplierSearch\Domain\NormalizedProduct;
use EindSupplierSearch\Domain\ProductIdentifier;
use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Domain\SearchResult;
use EindSupplierSearch\Domain\SupplierOffer;
use EindSupplierSearch\Exception\UnsupportedOperationException;

/**
 * Test double letting SearchServiceTest control exactly what "the
 * provider" does on search() (return a result, or throw), without needing
 * a real HTTP/JSON-fixture provider.
 */
final class FakeSupplierProvider implements SupplierProviderInterface
{
    /** @var callable(SearchQuery): SearchResult */
    private $searchCallback;

    /** @param callable(SearchQuery): SearchResult $searchCallback */
    public function __construct(callable $searchCallback)
    {
        $this->searchCallback = $searchCallback;
    }

    public function search(SearchQuery $query): SearchResult
    {
        return ($this->searchCallback)($query);
    }

    public function getProduct(ProductIdentifier $identifier): ?NormalizedProduct
    {
        throw new UnsupportedOperationException('Not used by SearchServiceTest.');
    }

    public function getOffer(ProductIdentifier $identifier, int $quantity): ?SupplierOffer
    {
        throw new UnsupportedOperationException('Not used by SearchServiceTest.');
    }

    public function testConnection(): bool
    {
        return true;
    }
}
