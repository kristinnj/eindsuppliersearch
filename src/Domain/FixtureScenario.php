<?php

namespace EindSupplierSearch\Domain;

/**
 * Names of the supported fixture scenarios, and their file mapping under
 * tests/fixtures/suppliers/. Used instead of scattered string literals.
 */
final class FixtureScenario
{
    public const EXACT_PART_NUMBER = 'EXACT_PART_NUMBER';
    public const KEYWORD_MANY_RESULTS = 'KEYWORD_MANY_RESULTS';
    public const ONE_RESULT = 'ONE_RESULT';
    public const NO_RESULTS = 'NO_RESULTS';
    public const OUT_OF_STOCK = 'OUT_OF_STOCK';
    public const QUANTITY_PRICES = 'QUANTITY_PRICES';
    public const MALFORMED_RESPONSE = 'MALFORMED_RESPONSE';
    public const TIMEOUT = 'TIMEOUT';

    /** Default scenario used when a query doesn't request one explicitly. */
    public const DEFAULT = self::KEYWORD_MANY_RESULTS;

    private const FILENAMES = [
        self::EXACT_PART_NUMBER => 'exact-part-number.json',
        self::KEYWORD_MANY_RESULTS => 'keyword-many-results.json',
        self::ONE_RESULT => 'one-result.json',
        self::NO_RESULTS => 'no-results.json',
        self::OUT_OF_STOCK => 'out-of-stock.json',
        self::QUANTITY_PRICES => 'quantity-prices.json',
        self::MALFORMED_RESPONSE => 'malformed-response.json',
        self::TIMEOUT => 'timeout.json',
    ];

    private function __construct()
    {
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::FILENAMES);
    }

    public static function isValid(string $scenario): bool
    {
        return isset(self::FILENAMES[$scenario]);
    }

    public static function filename(string $scenario): string
    {
        if (!self::isValid($scenario)) {
            throw new \InvalidArgumentException('Unknown fixture scenario: ' . $scenario);
        }

        return self::FILENAMES[$scenario];
    }
}
