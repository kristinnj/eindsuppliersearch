<?php

namespace EindSupplierSearch\Domain;

/** An authoritative record token is opaque; no database schema is implied. */
final class LocalProductBindingEvidence
{
    public function __construct(
        public readonly string $bindingToken,
        public readonly SupplierOfferIdentity $identity,
        public readonly LocalProductTarget $target,
        public readonly bool $moduleOwnershipVerified
    ) {
        if ($bindingToken === '') {
            throw new \InvalidArgumentException('Binding evidence needs a record token.');
        }
    }
}
