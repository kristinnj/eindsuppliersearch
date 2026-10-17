<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Injected PUBLIC TEST material only. No IO, environment or production provider. */
final class SyntheticEvidenceKeyRing implements EvidenceKeyRing
{
    private array $entries = [];

    /**
     * List of closed entries: installation raw16, purpose, key_id, key bytes, state.
     * Only ACTIVE entries resolve. Historical VERIFY_ONLY policy is deferred.
     * Callers must supply public synthetic keys, never production secrets.
     */
    public function __construct(#[\SensitiveParameter] array $entries)
    {
        if (!array_is_list($entries)) { throw new CanonicalValidationException('KEY_RING_DEFINITION_INVALID'); }
        foreach ($entries as $entry) {
            $fields = ['installation', 'purpose', 'key_id', 'key', 'state'];
            if (!is_array($entry) || count($entry) !== count($fields) || array_diff($fields, array_keys($entry)) !== []) {
                throw new CanonicalValidationException('KEY_RING_DEFINITION_INVALID');
            }
            EvidenceMacFrame::validateInstallation($entry['installation']);
            EvidenceMacFrame::validatePurpose($entry['purpose']);
            EvidenceMacFrame::validateKeyId($entry['key_id']);
            EvidenceKeyMaterial::validate($entry['key']);
            if (!in_array($entry['state'], ['ACTIVE', 'VERIFY_ONLY', 'REVOKED', 'UNAVAILABLE', 'UNKNOWN'], true)) {
                throw new CanonicalValidationException('KEY_RING_DEFINITION_INVALID');
            }
            $index = self::index($entry['installation'], $entry['purpose'], $entry['key_id']);
            if (array_key_exists($index, $this->entries)) {
                throw new CanonicalValidationException('KEY_RING_DEFINITION_INVALID');
            }
            $this->entries[$index] = ['key' => $entry['key'], 'state' => $entry['state']];
        }
    }

    public function resolve(mixed $installation, mixed $purpose, mixed $keyId): string
    {
        EvidenceMacFrame::validateInstallation($installation);
        EvidenceMacFrame::validatePurpose($purpose);
        EvidenceMacFrame::validateKeyId($keyId);
        $entry = $this->entries[self::index($installation, $purpose, $keyId)] ?? null;
        // Same safe operational category for absent and nonactive entries.
        if ($entry === null || $entry['state'] !== 'ACTIVE') {
            throw new EvidenceCryptoException('KEY_UNAVAILABLE');
        }
        return $entry['key'];
    }

    public function __debugInfo(): array
    {
        return ['synthetic_only' => true];
    }

    public function __serialize(): array
    {
        throw new EvidenceCryptoException('KEY_RING_SERIALIZATION_FORBIDDEN');
    }

    public function __unserialize(array $data): void
    {
        throw new EvidenceCryptoException('KEY_RING_SERIALIZATION_FORBIDDEN');
    }

    private static function index(string $installation, string $purpose, string $keyId): string
    {
        return bin2hex($installation) . ':' . $purpose . ':' . $keyId;
    }
}
