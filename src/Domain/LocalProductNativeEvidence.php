<?php

namespace EindSupplierSearch\Domain;

/** Native supplier association identity remains separate from mapping identity. */
final class LocalProductNativeEvidence
{
    public function __construct(
        public readonly LocalProductTarget $target,
        public readonly ?SupplierOfferIdentity $supplierIdentity,
        public readonly bool $productExists,
        public readonly bool $productInShop,
        public readonly int $defaultSupplierId
    ) {
    }
}
