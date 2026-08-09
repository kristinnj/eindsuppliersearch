<?php

namespace EindSupplierSearch\Domain;

/**
 * Supplier-agnostic view of a product, derived from the "standard product"
 * array EindConvertJsonToProductArray already produces. This is a
 * best-effort projection for callers that want the new domain shape
 * (logging, future UI); the legacy array itself remains the source of
 * truth for the existing template contract (see SearchResult::toLegacyArray()).
 */
final class NormalizedProduct
{
    private string $manufacturer;
    private string $manufacturerPartNumber;
    private string $name;
    private string $description;
    /** @var array<int, array<string, mixed>> */
    private array $attributes;
    /** @var array<int, array<string, mixed>> */
    private array $images;
    /** @var array<int, array<string, mixed>> */
    private array $datasheets;
    /** @var array<string, mixed> */
    private array $compliance;

    public function __construct(
        string $manufacturer,
        string $manufacturerPartNumber,
        string $name,
        string $description,
        array $attributes,
        array $images,
        array $datasheets,
        array $compliance
    ) {
        $this->manufacturer = $manufacturer;
        $this->manufacturerPartNumber = $manufacturerPartNumber;
        $this->name = $name;
        $this->description = $description;
        $this->attributes = $attributes;
        $this->images = $images;
        $this->datasheets = $datasheets;
        $this->compliance = $compliance;
    }

    /**
     * @param array<string, mixed> $standardProduct one entry from the legacy
     *   "standard product" Products[] array
     */
    public static function fromLegacyProduct(array $standardProduct): self
    {
        return new self(
            (string) ($standardProduct['ManufacturerName'] ?? ''),
            (string) ($standardProduct['ManufacturerPartNumber'] ?? ''),
            (string) ($standardProduct['DisplayText'] ?? $standardProduct['ProductName'] ?? ''),
            (string) ($standardProduct['ProductInformation']['Description'][0] ?? ''),
            is_array($standardProduct['Attributes'] ?? null) ? $standardProduct['Attributes'] : [],
            is_array($standardProduct['Images'] ?? null) ? $standardProduct['Images'] : [],
            is_array($standardProduct['Datasheets'] ?? null) ? $standardProduct['Datasheets'] : [],
            ['rohsCompliant' => (bool) ($standardProduct['RohsCompliant'] ?? false)]
        );
    }

    public function getManufacturer(): string
    {
        return $this->manufacturer;
    }

    public function getManufacturerPartNumber(): string
    {
        return $this->manufacturerPartNumber;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /** @return array<int, array<string, mixed>> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @return array<int, array<string, mixed>> */
    public function getImages(): array
    {
        return $this->images;
    }

    /** @return array<int, array<string, mixed>> */
    public function getDatasheets(): array
    {
        return $this->datasheets;
    }

    /** @return array<string, mixed> */
    public function getCompliance(): array
    {
        return $this->compliance;
    }
}
