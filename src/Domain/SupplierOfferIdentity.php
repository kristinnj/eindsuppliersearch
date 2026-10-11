<?php

namespace EindSupplierSearch\Domain;

/**
 * Durable supplier-scoped offer identity, independent of search coordinates
 * and temporary commercial observations. Supplier existence is validated at
 * the integration boundary, never through database access in this value object.
 */
final class SupplierOfferIdentity
{
    private readonly int $supplierId;
    private readonly string $supplierOrderReference;

    public function __construct(int $supplierId, string $supplierOrderReference)
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('SupplierOfferIdentity supplier id must be positive.');
        }
        if ($supplierOrderReference === '' || preg_match('/\A[\s\p{Z}]*\z/u', $supplierOrderReference) === 1) {
            throw new \InvalidArgumentException('SupplierOfferIdentity order reference must not be empty or whitespace-only.');
        }

        $this->supplierId = $supplierId;
        $this->supplierOrderReference = $supplierOrderReference;
    }

    public function getSupplierId(): int
    {
        return $this->supplierId;
    }

    public function getSupplierOrderReference(): string
    {
        return $this->supplierOrderReference;
    }

    public function equals(self $other): bool
    {
        return $this->supplierId === $other->supplierId
            && $this->supplierOrderReference === $other->supplierOrderReference;
    }
}
