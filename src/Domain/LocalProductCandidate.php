<?php

namespace EindSupplierSearch\Domain;

final class LocalProductCandidate
{
    public function __construct(
        public readonly ?LocalProductBindingEvidence $binding,
        public readonly ?LocalProductNativeEvidence $native
    ) {
        if ($binding === null && $native === null) {
            throw new \InvalidArgumentException('A candidate needs mapping or native evidence.');
        }
    }
}
