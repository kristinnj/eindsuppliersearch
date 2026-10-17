<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Operational crypto/key failure, distinct from invalid bytes or MAC mismatch. */
final class EvidenceCryptoException extends \RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct($category);
    }
}
