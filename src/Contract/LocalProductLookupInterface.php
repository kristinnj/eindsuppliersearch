<?php

namespace EindSupplierSearch\Contract;

use EindSupplierSearch\Domain\LocalProductLookupResult;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Exception\LocalProductLookupException;

/** Read-only lookup of authoritative ownership and consistent native evidence. */
interface LocalProductLookupInterface
{
    /**
     * Preserve the exact identity and explicit shop scope. Implementations must
     * validate a positive shop ID before IO, verify supplier eligibility and live
     * provenance, and evaluate authoritative bindings against native relationships.
     * References or native supplier associations alone never establish ownership.
     * Conflicting evidence must fail safely; infrastructure failure is not MISSING.
     * This operation must not create, adopt, repair, or mutate products or bindings.
     *
     * @throws \InvalidArgumentException if $shopId is not positive
     * @throws LocalProductLookupException on infrastructure failure
     */
    public function lookup(SupplierOfferIdentity $identity, int $shopId): LocalProductLookupResult;
}
