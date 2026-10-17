<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

final class CanonicalBase64
{
    public const REFERENCE_MAX_BYTES = 4096;

    /** Caller may tighten bounds; the protocol's body bound is never exceeded. */
    public static function decode(mixed $value, int $minimum = 1, int $maximum = self::REFERENCE_MAX_BYTES): string
    {
        self::bounds($minimum, $maximum);
        if (!is_string($value)) { throw new CanonicalValidationException('BASE64_TYPE_INVALID'); }
        // Bound encoded allocation before decoding, then enforce the exact decoded size.
        if (strlen($value) > 4 * intdiv($maximum + 2, 3)) {
            throw new CanonicalValidationException('BASE64_SIZE_INVALID');
        }
        $raw = base64_decode($value, true);
        if ($raw === false || base64_encode($raw) !== $value) {
            throw new CanonicalValidationException('BASE64_NONCANONICAL');
        }
        if (strlen($raw) < $minimum || strlen($raw) > $maximum) {
            throw new CanonicalValidationException('BASE64_SIZE_INVALID');
        }
        return $raw;
    }

    public static function encode(string $bytes, int $minimum = 1, int $maximum = self::REFERENCE_MAX_BYTES): string
    {
        self::bounds($minimum, $maximum);
        if (strlen($bytes) < $minimum || strlen($bytes) > $maximum) {
            throw new CanonicalValidationException('BASE64_SIZE_INVALID');
        }
        return base64_encode($bytes);
    }

    private static function bounds(int $minimum, int $maximum): void
    {
        if ($minimum < 0 || $maximum < $minimum || $maximum > CanonicalJson::MAX_BYTES) {
            throw new CanonicalValidationException('BASE64_BOUNDS_INVALID');
        }
    }
}
