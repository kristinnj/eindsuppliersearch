<?php

namespace EindSupplierSearch\Domain;

final class LocalProductTarget
{
    public function __construct(
        private readonly int $productId,
        private readonly int $productAttributeId,
        private readonly int $shopId
    ) {
        if ($productId <= 0 || $productAttributeId < 0 || $shopId <= 0) {
            throw new \InvalidArgumentException('Invalid local product target coordinates.');
        }
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getProductAttributeId(): int
    {
        return $this->productAttributeId;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function equals(self $other): bool
    {
        return $this->productId === $other->productId
            && $this->productAttributeId === $other->productAttributeId
            && $this->shopId === $other->shopId;
    }
}
