<?php

namespace EindSupplierSearch\Contract;

use EindSupplierSearch\Domain\NormalizedProduct;
use EindSupplierSearch\Domain\ProductIdentifier;
use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Domain\SearchResult;
use EindSupplierSearch\Domain\SupplierOffer;
use EindSupplierSearch\Exception\SupplierProviderException;

/**
 * A source of supplier data: either a live supplier API or a local JSON
 * fixture set. SearchService depends only on this interface and never
 * knows which one it is talking to.
 */
interface SupplierProviderInterface
{
    /**
     * @throws SupplierProviderException on timeout, malformed data, or any
     *   other provider-side failure. SearchService is responsible for
     *   catching this and returning a safe SearchResult.
     */
    public function search(SearchQuery $query): SearchResult;

    /**
     * @throws SupplierProviderException if the provider cannot look up a
     *   single product (including "not implemented yet").
     */
    public function getProduct(ProductIdentifier $identifier): ?NormalizedProduct;

    /**
     * @throws SupplierProviderException if the provider cannot look up a
     *   quantity-specific offer (including "not implemented yet").
     */
    public function getOffer(ProductIdentifier $identifier, int $quantity): ?SupplierOffer;

    /**
     * Lightweight connectivity/availability check. Must not throw; return
     * false on any failure.
     */
    public function testConnection(): bool;
}
