<?php

namespace EindSupplierSearch\Domain;

/**
 * Result of CachedOfferResolver::resolve(): either the resolved raw
 * legacy product row, or a failure with a stable category a caller can
 * log without the resolver itself needing any logging/PrestaShop
 * dependency. See docs/development/offer-action-reference.md,
 * "Supplier-strict commerce resolution".
 */
final class CachedOfferResolution
{
    public const CACHE_MISSING = 'CACHE_MISSING';
    public const MALFORMED_CACHE = 'MALFORMED_CACHE';
    public const SUPPLIER_NOT_FOUND = 'SUPPLIER_NOT_FOUND';
    public const ROW_NOT_FOUND = 'ROW_NOT_FOUND';
    public const ORDER_CODE_MISMATCH = 'ORDER_CODE_MISMATCH';

    private bool $successful;
    /** @var array<string, mixed>|null */
    private ?array $product;
    private ?string $failureCategory;

    /** @param array<string, mixed>|null $product */
    private function __construct(bool $successful, ?array $product, ?string $failureCategory)
    {
        $this->successful = $successful;
        $this->product = $product;
        $this->failureCategory = $failureCategory;
    }

    /** @param array<string, mixed> $product */
    public static function success(array $product): self
    {
        return new self(true, $product, null);
    }

    public static function failure(string $failureCategory): self
    {
        return new self(false, null, $failureCategory);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /** @return array<string, mixed>|null */
    public function getProduct(): ?array
    {
        return $this->product;
    }

    public function getFailureCategory(): ?string
    {
        return $this->failureCategory;
    }
}
