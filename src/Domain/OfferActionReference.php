<?php

namespace EindSupplierSearch\Domain;

/**
 * The technical reference needed to reconnect one presented SupplierOffer
 * back to the exact source row EindSupplierSearch\Service\CachedOfferResolver
 * (via EindAddToCartFunctions::getStrictCachedOffer()) can resolve from
 * the cached search-result payload.
 *
 * This is deliberately NOT commercial identity (supplier name, order code,
 * manufacturer part number -- those live on SupplierOffer/NormalizedProduct
 * and drive SearchResult::getProductHits() aggregation). It is only an
 * implementation-level coordinate: which supplier bucket, and which row
 * within that bucket's Products[] array, the offer came from.
 */
final class OfferActionReference
{
    private int|string $supplierBucketId;

    private int $sourceRowIndex;

    public function __construct(int|string $supplierBucketId, int $sourceRowIndex)
    {
        if ($supplierBucketId === '') {
            throw new \InvalidArgumentException('OfferActionReference supplier bucket id must not be empty.');
        }
        if ($sourceRowIndex < 0) {
            throw new \InvalidArgumentException('OfferActionReference source row index must not be negative.');
        }

        $this->supplierBucketId = $supplierBucketId;
        $this->sourceRowIndex = $sourceRowIndex;
    }

    public function getSupplierBucketId(): int|string
    {
        return $this->supplierBucketId;
    }

    public function getSourceRowIndex(): int
    {
        return $this->sourceRowIndex;
    }
}
