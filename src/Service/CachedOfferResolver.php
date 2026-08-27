<?php

namespace EindSupplierSearch\Service;

use EindSupplierSearch\Domain\CachedOfferResolution;
use EindSupplierSearch\Domain\OfferActionReference;

/**
 * Supplier-strict resolution of one cached supplier product row from an
 * OfferActionReference. This is the single supported production
 * cached-offer resolution path -- both EindAddToCartFunctions::getStrictCachedOffer()
 * callers (addtocart.php's commerce action and productview.php's
 * read-only detail action) resolve through this class. It never falls
 * back to searching another supplier bucket: a reference that cannot be
 * resolved exactly enough within its own requested supplier fails,
 * period. See docs/development/offer-action-reference.md.
 *
 * Pure and PrestaShop-free by design (no Db/session access -- the caller
 * supplies the already-fetched/decoded cached payload), so it is directly
 * unit-testable and auditable: every code path here is strict, with no
 * "mode" to reason about.
 */
final class CachedOfferResolver
{
    /**
     * @param array<int|string, mixed> $cachedBuckets the same supplier-bucket-keyed
     *   shape SearchResult::toLegacyArray() returns ({SupplierName, Products: [...]} per bucket)
     * @param string|null $expectedOrderCode when given, the resolved row's OrderCode
     *   must match it exactly, or resolution fails -- this is a verification signal
     *   only, never a way to locate a row in a different supplier's bucket
     */
    public function resolve(array $cachedBuckets, OfferActionReference $reference, ?string $expectedOrderCode = null): CachedOfferResolution
    {
        $bucket = $this->findBucket($cachedBuckets, $reference->getSupplierBucketId());
        if ($bucket === null) {
            return CachedOfferResolution::failure(CachedOfferResolution::SUPPLIER_NOT_FOUND);
        }

        $products = isset($bucket['Products']) && is_array($bucket['Products']) ? $bucket['Products'] : null;
        if ($products === null) {
            return CachedOfferResolution::failure(CachedOfferResolution::SUPPLIER_NOT_FOUND);
        }

        $product = $this->findRow($products, $reference->getSourceRowIndex(), $expectedOrderCode);
        if ($product === null) {
            return CachedOfferResolution::failure(CachedOfferResolution::ROW_NOT_FOUND);
        }

        if ($expectedOrderCode !== null && $expectedOrderCode !== '') {
            $actualOrderCode = isset($product['OrderCode']) ? (string) $product['OrderCode'] : '';
            if ($actualOrderCode !== $expectedOrderCode) {
                return CachedOfferResolution::failure(CachedOfferResolution::ORDER_CODE_MISMATCH);
            }
        }

        return CachedOfferResolution::success($product);
    }

    /**
     * Accepts compatible representations of the *same requested* supplier
     * -- json_decode() already normalizes a numeric bucket key (whether it
     * arrived as a JSON number or a numeric JSON-object-key string) to a
     * plain PHP int, so a single isset() covers both "raw key" and "int
     * form of a numeric key"; a bucket carrying an explicit SupplierId
     * field is also accepted. Never matches a different supplier.
     *
     * @param array<int|string, mixed> $cachedBuckets
     * @param int|string $requestedBucketId
     * @return array<string, mixed>|null
     */
    private function findBucket(array $cachedBuckets, $requestedBucketId): ?array
    {
        if (isset($cachedBuckets[$requestedBucketId]) && is_array($cachedBuckets[$requestedBucketId])) {
            return $cachedBuckets[$requestedBucketId];
        }

        $raw = (string) $requestedBucketId;
        foreach ($cachedBuckets as $bucket) {
            if (is_array($bucket) && isset($bucket['SupplierId']) && (string) $bucket['SupplierId'] === $raw) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * Resolves a row strictly within the already-resolved supplier's own
     * Products[] array. Prefers the exact index from the reference; when
     * that index is missing (e.g. cache drift) an in-bucket OrderCode
     * fallback is allowed -- but only within this same bucket, never by
     * scanning any other supplier's rows.
     *
     * @param array<int|string, mixed> $products
     * @return array<string, mixed>|null
     */
    private function findRow(array $products, int $requestedRowIndex, ?string $expectedOrderCode): ?array
    {
        if (isset($products[$requestedRowIndex]) && is_array($products[$requestedRowIndex])) {
            return $products[$requestedRowIndex];
        }

        if ($expectedOrderCode !== null && $expectedOrderCode !== '') {
            foreach ($products as $product) {
                if (is_array($product) && isset($product['OrderCode']) && (string) $product['OrderCode'] === $expectedOrderCode) {
                    return $product;
                }
            }
        }

        return null;
    }
}
