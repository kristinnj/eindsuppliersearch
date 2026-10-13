<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

use EindSupplierSearch\Domain\SupplierOfferIdentity;

/** Persistence limits are deliberately separate from the unbounded domain identity. */
final class OfferBindingReference
{
    public const MAX_BYTES = 4096;

    public static function sha256(SupplierOfferIdentity $identity): string
    {
        $reference = $identity->getSupplierOrderReference();
        if (strlen($reference) > self::MAX_BYTES) {
            throw new \LengthException('Offer binding reference exceeds 4096 bytes.');
        }

        return hash('sha256', $reference, true);
    }
}
