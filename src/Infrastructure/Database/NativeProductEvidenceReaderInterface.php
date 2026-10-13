<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

use EindSupplierSearch\Domain\LocalProductBindingEvidence;
use EindSupplierSearch\Domain\LocalProductLookupContext;

interface NativeProductEvidenceReaderInterface
{
    public function context(int $supplierId, int $shopId): LocalProductLookupContext;

    /** @return \EindSupplierSearch\Domain\LocalProductCandidate[] */
    public function candidates(LocalProductBindingEvidence $binding): array;
}
