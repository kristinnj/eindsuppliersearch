<?php

namespace EindSupplierSearch\Domain;

/** Facts supplied by a future adapter, not queried or inferred here. */
final class LocalProductLookupContext
{
    public function __construct(
        public readonly int $supplierId,
        public readonly int $shopId,
        public readonly bool $supplierExists,
        public readonly bool $supplierEligible,
        public readonly bool $shopExists,
        public readonly bool $supplierInShop
    ) {
    }
}
