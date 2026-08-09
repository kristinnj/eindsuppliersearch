<?php

namespace EindSupplierSearch\Domain;

/**
 * Immutable representation of a single supplier search request.
 *
 * Mirrors the parameters `EindCallSupplierApi::querySuppliers()` already
 * accepts (criteria/itemsOnPage/pageOffset/searchTerm/searchFilter) so the
 * live provider can pass them straight through unchanged.
 */
final class SearchQuery
{
    public const SEARCH_TYPE_ANY = 'any';
    public const SEARCH_TYPE_ID = 'id';
    public const SEARCH_TYPE_MANUFACTURER_PART_NUMBER = 'manuPartNum';

    private string $originalText;
    private string $normalizedText;
    private string $searchType;
    private int $itemsOnPage;
    private int $pageOffset;
    private string $searchFilter;
    private ?string $fixtureScenario;

    public function __construct(
        string $originalText,
        string $normalizedText,
        string $searchType,
        int $itemsOnPage,
        int $pageOffset,
        string $searchFilter,
        ?string $fixtureScenario = null
    ) {
        $this->originalText = $originalText;
        $this->normalizedText = $normalizedText;
        $this->searchType = $searchType;
        $this->itemsOnPage = $itemsOnPage;
        $this->pageOffset = $pageOffset;
        $this->searchFilter = $searchFilter;
        $this->fixtureScenario = $fixtureScenario;
    }

    /**
     * Builds a SearchQuery from the same loose parameters the legacy
     * controller/EindCallSupplierApi already work with.
     */
    public static function fromLegacyParameters(
        string $criteria,
        int $itemsOnPage,
        int $pageOffset,
        string $searchType = self::SEARCH_TYPE_ANY,
        string $searchFilter = '',
        ?string $fixtureScenario = null
    ): self {
        return new self($criteria, trim($criteria), $searchType, $itemsOnPage, $pageOffset, $searchFilter, $fixtureScenario);
    }

    public function withFixtureScenario(?string $fixtureScenario): self
    {
        return new self(
            $this->originalText,
            $this->normalizedText,
            $this->searchType,
            $this->itemsOnPage,
            $this->pageOffset,
            $this->searchFilter,
            $fixtureScenario
        );
    }

    public function getOriginalText(): string
    {
        return $this->originalText;
    }

    public function getNormalizedText(): string
    {
        return $this->normalizedText;
    }

    public function getSearchType(): string
    {
        return $this->searchType;
    }

    public function getItemsOnPage(): int
    {
        return $this->itemsOnPage;
    }

    public function getPageOffset(): int
    {
        return $this->pageOffset;
    }

    public function getSearchFilter(): string
    {
        return $this->searchFilter;
    }

    public function getFixtureScenario(): ?string
    {
        return $this->fixtureScenario;
    }
}
