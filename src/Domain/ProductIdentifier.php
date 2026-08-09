<?php

namespace EindSupplierSearch\Domain;

/**
 * Identifies a single product for the not-yet-implemented single-product
 * provider lookups (SupplierProviderInterface::getProduct()/getOffer()).
 */
final class ProductIdentifier
{
    private string $manufacturerName;
    private string $manufacturerPartNumber;
    private ?string $supplierCode;

    public function __construct(string $manufacturerName, string $manufacturerPartNumber, ?string $supplierCode = null)
    {
        $this->manufacturerName = $manufacturerName;
        $this->manufacturerPartNumber = $manufacturerPartNumber;
        $this->supplierCode = $supplierCode;
    }

    public function getManufacturerName(): string
    {
        return $this->manufacturerName;
    }

    public function getManufacturerPartNumber(): string
    {
        return $this->manufacturerPartNumber;
    }

    public function getSupplierCode(): ?string
    {
        return $this->supplierCode;
    }
}
