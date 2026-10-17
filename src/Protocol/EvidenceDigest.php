<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** SHA-256 of exact bytes, including empty or binary input; no JSON conversion. */
final class EvidenceDigest
{
    public static function raw(mixed $bytes): string
    {
        if (!is_string($bytes)) { throw new CanonicalValidationException('DIGEST_INPUT_INVALID'); }
        if (!function_exists('hash') || !in_array('sha256', hash_algos(), true)) {
            throw new EvidenceCryptoException('CRYPTO_UNAVAILABLE');
        }
        return hash('sha256', $bytes, true);
    }

    public static function hex(mixed $bytes): string
    {
        return bin2hex(self::raw($bytes));
    }

    /** Also used for the identically sized raw HMAC-SHA256 representation. */
    public static function validateRaw(mixed $value): string
    {
        if (!is_string($value) || strlen($value) !== 32) {
            throw new CanonicalValidationException('DIGEST_RAW_INVALID');
        }
        return $value;
    }

    public static function fromHex(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new CanonicalValidationException('DIGEST_HEX_INVALID');
        }
        return hex2bin($value);
    }

    public static function toHex(mixed $value): string
    {
        return bin2hex(self::validateRaw($value));
    }
}
