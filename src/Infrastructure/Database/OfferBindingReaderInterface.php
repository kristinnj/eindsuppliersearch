<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

use EindSupplierSearch\Domain\LocalProductTarget;

/** Raw persistence evidence, without ownership decisions. */
interface OfferBindingReaderInterface
{
    /** @return array[] All rows in the exact binary digest bucket. */
    public function find(int $shopId, int $supplierId, string $digest): array;

    /** @return array[] All claims on the target, including other shops/identities/states. */
    public function forTarget(LocalProductTarget $target): array;
}
