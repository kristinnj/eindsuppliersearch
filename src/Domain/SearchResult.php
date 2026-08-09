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
