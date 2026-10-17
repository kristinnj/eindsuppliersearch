<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** @internal Synthetic-key validation shared by the ring and authenticator. */
final class EvidenceKeyMaterial
{
    public static function validate(#[\SensitiveParameter] mixed $key): void
    {
        if (!is_string($key) || strlen($key) < 32) {
            throw new EvidenceCryptoException('KEY_MATERIAL_INVALID');
        }
    }
}
