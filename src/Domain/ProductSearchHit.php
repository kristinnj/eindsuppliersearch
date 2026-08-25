<?php

namespace EindSupplierSearch\Domain;

/**
 * One normalized product together with every supplier offer that was
 * matched to it during aggregation (see SearchResult::getProductHits()).
 *
 * This is purely a grouping structure: it holds no pricing/ranking logic
 * and no formatting -- callers decide how to present the offers.
 */
final class ProductSearchHit
{
    private NormalizedProduct $product;

    /** @var SupplierOffer[] */
    private array $offers;

    /** @param SupplierOffer[] $offers */
    public function __construct(NormalizedProduct $product, array $offers)
    {
        $this->product = $product;
        $this->offers = $offers;
    }

    public function getProduct(): NormalizedProduct
    {
        return $this->product;
    }

    /** @return SupplierOffer[] */
    public function getOffers(): array
    {
        return $this->offers;
    }
}
