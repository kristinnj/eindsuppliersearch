<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/**
 * Pure synthetic-key MAC primitives, NOT an attestor or evidence eligibility gate.
 * Full profiles/body-to-context bindings belong to B.2B.3. No operational signing.
 * Every operation validates exact canonical bytes before resolving a scoped key.
 */
final class EvidenceAuthenticator
{
    public function __construct(private readonly EvidenceKeyRing $keys) {}

    public function mac(mixed $purpose, mixed $installation, mixed $keyId, mixed $body): string
    {
        $frame = EvidenceMacFrame::build($purpose, $installation, $keyId, $body);
        if (!function_exists('hash_hmac') || !function_exists('hash_equals')
            || !in_array('sha256', hash_hmac_algos(), true)) {
            throw new EvidenceCryptoException('CRYPTO_UNAVAILABLE');
        }
        $key = $this->keys->resolve($installation, $purpose, $keyId);
        // Defend against incorrectly implemented injected rings as well.
        EvidenceKeyMaterial::validate($key);
        return hash_hmac('sha256', $frame, $key, true);
    }

    public function macHex(mixed $purpose, mixed $installation, mixed $keyId, mixed $body): string
    {
        return bin2hex($this->mac($purpose, $installation, $keyId, $body));
    }

    /** False means a validly represented MAC mismatch; malformed input throws. */
    public function verify(mixed $purpose, mixed $installation, mixed $keyId, mixed $body, mixed $mac): bool
    {
        if (!is_string($mac) || strlen($mac) !== 32) {
            throw new CanonicalValidationException('MAC_RAW_INVALID');
        }
        $expected = $this->mac($purpose, $installation, $keyId, $body);
        return hash_equals($expected, $mac);
    }

    public function verifyHex(mixed $purpose, mixed $installation, mixed $keyId, mixed $body, mixed $macHex): bool
    {
        if (!is_string($macHex) || preg_match('/\A[0-9a-f]{64}\z/D', $macHex) !== 1) {
            throw new CanonicalValidationException('MAC_HEX_INVALID');
        }
        return $this->verify($purpose, $installation, $keyId, $body, hex2bin($macHex));
    }
}
