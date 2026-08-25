<?php

namespace EindSupplierSearch\Domain;

/**
 * Result of a SupplierProviderInterface::search() call.
 *
 * `legacyBuckets` is the supplier-id-keyed array shape the existing
 * template/controller already understand (same shape
 * EindCallSupplierApi::querySuppliers() returns) and is preserved
 * losslessly so toLegacyArray() is a drop-in replacement for the old
 * direct call. getProducts()/getOffers() are a best-effort domain-object
 * projection built lazily from the same data for callers that want it.
 */
final class SearchResult
{
    /** @var array<int|string, array<string, mixed>> */
    private array $legacyBuckets;
    private string $providerName;
    private float $durationMs;
    private bool $failed;
    private ?string $failureReason;

    /** @var NormalizedProduct[]|null */
    private ?array $productsCache = null;
    /** @var SupplierOffer[]|null */
    private ?array $offersCache = null;
    /** @var ProductSearchHit[]|null */
    private ?array $productHitsCache = null;

    private function __construct(array $legacyBuckets, string $providerName, float $durationMs, bool $failed, ?string $failureReason)
    {
        $this->legacyBuckets = $legacyBuckets;
        $this->providerName = $providerName;
        $this->durationMs = $durationMs;
        $this->failed = $failed;
        $this->failureReason = $failureReason;
    }

    /** @param array<int|string, array<string, mixed>> $legacyBuckets */
    public static function fromLegacyBuckets(array $legacyBuckets, string $providerName, float $durationMs): self
    {
        return new self($legacyBuckets, $providerName, $durationMs, false, null);
    }

    /**
     * A safe, empty result for provider failures (timeout, malformed data,
     * missing fixture, unexpected exception). Its legacy array is
     * deliberately empty so it triggers the same "no results" branch the
     * controller/template already handle.
     */
    public static function failedResult(string $providerName, float $durationMs, string $reason): self
    {
        return new self([], $providerName, $durationMs, true, $reason);
    }

    /** @return array<int|string, array<string, mixed>> */
    public function toLegacyArray(): array
    {
        return $this->legacyBuckets;
    }

    public function isFailed(): bool
    {
        return $this->failed;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getNumberOfResults(): int
    {
        $total = 0;
        foreach ($this->legacyBuckets as $bucket) {
            if (is_array($bucket)) {
                $total += (int) ($bucket['NumberOfResults'] ?? 0);
            }
        }

        return $total;
    }

    /** @return NormalizedProduct[] */
    public function getProducts(): array
    {
        if ($this->productsCache === null) {
            $this->productsCache = [];
            foreach ($this->legacyBuckets as $bucket) {
                foreach ($this->bucketProducts($bucket) as $product) {
                    $this->productsCache[] = NormalizedProduct::fromLegacyProduct($product);
                }
            }
        }

        return $this->productsCache;
    }

    /** @return SupplierOffer[] */
    public function getOffers(): array
    {
        if ($this->offersCache === null) {
            $this->offersCache = [];
            foreach ($this->legacyBuckets as $bucket) {
                $supplierName = is_array($bucket) ? (string) ($bucket['SupplierName'] ?? '') : '';
                foreach ($this->bucketProducts($bucket) as $product) {
                    $this->offersCache[] = SupplierOffer::fromLegacyProduct($product, $supplierName);
                }
            }
        }

        return $this->offersCache;
    }

    /**
     * Groups every supplier product row into one ProductSearchHit per
     * distinct product identity (see computeProductIdentityKey()), each
     * carrying every SupplierOffer matched to that identity.
     *
     * The canonical NormalizedProduct for a hit is the first one built for
     * that identity key; richer cross-supplier product-data merging
     * (best image, longest description, union of attributes, ...) is not
     * attempted yet and may be added later.
     *
     * Additive: does not change toLegacyArray()/getProducts()/getOffers().
     *
     * @return ProductSearchHit[]
     */
    public function getProductHits(): array
    {
        if ($this->productHitsCache === null) {
            /** @var array<string, NormalizedProduct> $productByKey */
            $productByKey = [];
            /** @var array<string, SupplierOffer[]> $offersByKey */
            $offersByKey = [];
            /** @var string[] $keyOrder first-seen order, so hit order matches input order */
            $keyOrder = [];
            $rowIndex = 0;

            foreach ($this->legacyBuckets as $bucket) {
                $supplierName = is_array($bucket) ? (string) ($bucket['SupplierName'] ?? '') : '';
                foreach ($this->bucketProducts($bucket) as $product) {
                    $identityKey = $this->computeProductIdentityKey($product, $supplierName, $rowIndex);
                    ++$rowIndex;

                    if (!isset($productByKey[$identityKey])) {
                        $productByKey[$identityKey] = NormalizedProduct::fromLegacyProduct($product);
                        $offersByKey[$identityKey] = [];
                        $keyOrder[] = $identityKey;
                    }

                    $offersByKey[$identityKey][] = SupplierOffer::fromLegacyProduct($product, $supplierName);
                }
            }

            $this->productHitsCache = [];
            foreach ($keyOrder as $identityKey) {
                $this->productHitsCache[] = new ProductSearchHit($productByKey[$identityKey], $offersByKey[$identityKey]);
            }
        }

        return $this->productHitsCache;
    }

    /**
     * Computes a stable identity key used to group the same manufacturer
     * product across supplier buckets.
     *
     * Primary identity = normalized manufacturer + normalized manufacturer
     * part number (see normalizeIdentityPart()). Normalization is
     * deliberately conservative -- only whitespace/case differences are
     * folded together. Suffixes, packaging codes, hyphens, slashes,
     * temperature grades, reel indicators, and other punctuation are left
     * untouched, since stripping them could merge parts that are not
     * actually interchangeable.
     *
     * Fallback: when manufacturer or MPN is missing/blank after
     * normalization, product identity cannot be safely established, so we
     * must not merge the row with anything else. We fall back to
     * "supplier name + supplier order code" (unique within a supplier's
     * catalog); if even that is blank, we fall back to a per-row unique
     * key (its position in the overall result) so the row is always kept
     * separate rather than risk an unsafe merge.
     *
     * @param array<string, mixed> $standardProduct one entry from the legacy
     *   "standard product" Products[] array
     */
    private function computeProductIdentityKey(array $standardProduct, string $supplierName, int $rowIndex): string
    {
        $manufacturer = $this->normalizeIdentityPart((string) ($standardProduct['ManufacturerName'] ?? ''));
        $partNumber = $this->normalizeIdentityPart((string) ($standardProduct['ManufacturerPartNumber'] ?? ''));

        if ($manufacturer !== '' && $partNumber !== '') {
            return 'identity:' . $manufacturer . '|' . $partNumber;
        }

        $orderCode = $this->normalizeIdentityPart((string) ($standardProduct['OrderCode'] ?? ''));
        $normalizedSupplier = $this->normalizeIdentityPart($supplierName);

        if ($normalizedSupplier !== '' && $orderCode !== '') {
            return 'fallback-order:' . $normalizedSupplier . '|' . $orderCode;
        }

        return 'fallback-row:' . $rowIndex;
    }

    /**
     * Conservative identity normalization: trims leading/trailing
     * whitespace, collapses repeated internal whitespace to a single
     * space, and case-folds. Nothing else is altered.
     */
    private function normalizeIdentityPart(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * @param mixed $bucket
     * @return array<int, array<string, mixed>>
     */
    private function bucketProducts($bucket): array
    {
        if (!is_array($bucket) || empty($bucket['Products']) || !is_array($bucket['Products'])) {
            return [];
        }

        return array_filter($bucket['Products'], 'is_array');
    }
}
