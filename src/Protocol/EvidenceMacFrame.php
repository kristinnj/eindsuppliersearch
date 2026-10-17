<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** EIND-OQ-1 only. No frame import/parser or alternate layouts are supported. */
final class EvidenceMacFrame
{
    public const VERSION = 1;

    public static function build(mixed $purpose, mixed $installation, mixed $keyId, mixed $body, mixed $version = self::VERSION): string
    {
        if ($version !== self::VERSION) { throw new CanonicalValidationException('FRAME_VERSION_INVALID'); }
        self::validatePurpose($purpose);
        self::validateInstallation($installation);
        self::validateKeyId($keyId);
        if (!is_string($body)) { throw new CanonicalValidationException('BODY_TYPE_INVALID'); }
        // Mandatory on every public framing/authentication path; never repair bytes.
        CanonicalJson::decode($body);
        return "EIND-EVIDENCE-MAC\x00\x01" . self::lp($purpose) . $installation . self::lp($keyId) . self::lp($body);
    }

    public static function validatePurpose(mixed $purpose): void
    {
        // B.0a RECEIPT is outside the frozen EIND-OQ-1 observation/quote scope.
        if ($purpose !== 'OBSERVATION' && $purpose !== 'QUOTE') {
            throw new CanonicalValidationException('PURPOSE_INVALID');
        }
    }

    public static function validateInstallation(mixed $installation): void
    {
        if (!is_string($installation) || strlen($installation) !== 16 || $installation === str_repeat("\0", 16)) {
            throw new CanonicalValidationException('INSTALLATION_INVALID');
        }
    }

    public static function validateKeyId(mixed $keyId): void
    {
        if (!is_string($keyId) || preg_match('/\A[a-z0-9][a-z0-9._-]{0,39}\z/D', $keyId) !== 1) {
            throw new CanonicalValidationException('KEY_ID_INVALID');
        }
    }

    private static function lp(string $bytes): string
    {
        $length = strlen($bytes);
        // Each field is already contract-bounded; guard packing independently too.
        if ($length > 4294967295) { throw new CanonicalValidationException('FRAME_LENGTH_INVALID'); }
        return pack('N', $length) . $bytes;
    }
}
