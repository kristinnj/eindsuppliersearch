<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Exact lexical/range checks only; no arithmetic or native-number conversion. */
final class CanonicalDecimal
{
    public const MONEY_MAX = '99999999999999.999999';
    public const UINT32_MAX = '4294967295';

    public static function decimal(mixed $value, bool $positive = false, string $maximum = self::MONEY_MAX): string
    {
        self::decimalLexical($value);
        self::decimalLexical($maximum);
        if (($positive && $value === '0.000000')
            || self::compareUnsigned(str_replace('.', '', $value), str_replace('.', '', $maximum)) > 0) {
            throw new CanonicalValidationException('DECIMAL_RANGE_INVALID');
        }
        return $value;
    }

    public static function unsignedInteger(mixed $value, bool $positive = false, string $maximum = self::UINT32_MAX): string
    {
        self::integerLexical($value);
        self::integerLexical($maximum);
        if (($positive && $value === '0') || self::compareUnsigned($value, $maximum) > 0) {
            throw new CanonicalValidationException('INTEGER_RANGE_INVALID');
        }
        return $value;
    }

    public static function decimalLexical(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A(0|[1-9][0-9]*)\.[0-9]{6}\z/D', $value) !== 1) {
            throw new CanonicalValidationException('DECIMAL_LEXICAL_INVALID');
        }
    }

    public static function integerLexical(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A(0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new CanonicalValidationException('INTEGER_LEXICAL_INVALID');
        }
    }

    private static function compareUnsigned(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');
        return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
    }
}
