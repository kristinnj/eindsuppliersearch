<?php

namespace EindSupplierSearch\Domain;

/**
 * Commercial terms for a product from a specific supplier, derived from the
 * legacy "standard product" array. See NormalizedProduct for the rationale
 * on why this is a best-effort projection rather than the source of truth.
 */
final class SupplierOffer
{
    private string $supplierName;
    private string $supplierOrderCode;
    /** @var array<int, array<string, mixed>> */
    private array $priceTiers;
    private int $stockLevel;
    private string $stockStatus;
    private int $minimumOrderQty;
    private ?string $packaging;
    private ?string $deliveryInfo;
    private \DateTimeImmutable $timestamp;

    public function __construct(
        string $supplierName,
        string $supplierOrderCode,
        array $priceTiers,
        int $stockLevel,
        string $stockStatus,
        int $minimumOrderQty,
        ?string $packaging,
        ?string $deliveryInfo,
        \DateTimeImmutable $timestamp
    ) {
        $this->supplierName = $supplierName;
        $this->supplierOrderCode = $supplierOrderCode;
        $this->priceTiers = $priceTiers;
        $this->stockLevel = $stockLevel;
        $this->stockStatus = $stockStatus;
        $this->minimumOrderQty = $minimumOrderQty;
        $this->packaging = $packaging;
        $this->deliveryInfo = $deliveryInfo;
        $this->timestamp = $timestamp;
    }

    /**
     * @param array<string, mixed> $standardProduct one entry from the legacy
     *   "standard product" Products[] array
     */
    public static function fromLegacyProduct(array $standardProduct, string $supplierName): self
    {
        $minimumOrderQty = $standardProduct['MinimumOrderQty'] ?? null;

        return new self(
            $supplierName,
            (string) ($standardProduct['OrderCode'] ?? ''),
            is_array($standardProduct['Prices'] ?? null) ? $standardProduct['Prices'] : [],
            (int) ($standardProduct['Stock']['Level'] ?? $standardProduct['NumberInStock'] ?? 0),
            (string) ($standardProduct['Stock']['Status'] ?? $standardProduct['StockStatus'] ?? ''),
            is_numeric($minimumOrderQty) ? (int) $minimumOrderQty : 1,
            isset($standardProduct['Package']) ? (string) $standardProduct['Package'] : null,
            null, // legacy data has no delivery-lead-time field to source this from
            new \DateTimeImmutable()
        );
    }

    public function getSupplierName(): string
    {
        return $this->supplierName;
    }

    public function getSupplierOrderCode(): string
    {
        return $this->supplierOrderCode;
    }

    /** @return array<int, array<string, mixed>> */
    public function getPriceTiers(): array
    {
        return $this->priceTiers;
    }

    public function getStockLevel(): int
    {
        return $this->stockLevel;
    }

    public function getStockStatus(): string
    {
        return $this->stockStatus;
    }

    public function getMinimumOrderQty(): int
    {
        return $this->minimumOrderQty;
    }

    public function getPackaging(): ?string
    {
        return $this->packaging;
    }

    public function getDeliveryInfo(): ?string
    {
        return $this->deliveryInfo;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }
}
