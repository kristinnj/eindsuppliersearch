<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

use EindSupplierSearch\Domain\LocalProductTarget;

final class PdoOfferBindingReader implements OfferBindingReaderInterface
{
    public function __construct(private readonly LookupSelectConnection $connection)
    {
    }

    public function find(int $shopId, int $supplierId, string $digest): array
    {
        return $this->connection->select('SELECT * FROM ' . $this->connection->table(OfferBindingSchema::TABLE)
            . ' WHERE id_shop = ? AND id_supplier = ? AND reference_sha256 = ?', [$shopId, $supplierId, $digest]);
    }

    public function forTarget(LocalProductTarget $target): array
    {
        return $this->connection->select('SELECT * FROM ' . $this->connection->table(OfferBindingSchema::TABLE)
            . ' WHERE id_product = ? AND id_product_attribute = ?',
            [$target->getProductId(), $target->getProductAttributeId()]);
    }
}
