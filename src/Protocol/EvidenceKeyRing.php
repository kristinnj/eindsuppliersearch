<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/**
 * Synthetic-only injection boundary. No production key-loading contract is defined.
 * Resolve an exact ACTIVE (installation raw16, purpose, key ID) tuple or throw
 * EvidenceCryptoException. No default, wildcard or alternate-key lookup is allowed.
 */
interface EvidenceKeyRing
{
    public function resolve(mixed $installation, mixed $purpose, mixed $keyId): string;
}
