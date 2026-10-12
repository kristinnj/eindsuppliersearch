<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Doubles;

use EindSupplierSearch\Contract\LocalProductLookupInterface;
use EindSupplierSearch\Domain\LocalProductLookupResult;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Exception\LocalProductLookupException;

/** In-memory contract example; no native relationship or ownership verification. */
final class FakeLocalProductLookup implements LocalProductLookupInterface
{
    public ?SupplierOfferIdentity $receivedIdentity = null;
    public ?int $receivedShopId = null;

    public function __construct(
        private readonly LocalProductLookupResult|LocalProductLookupException $response
    ) {
    }

    public function lookup(SupplierOfferIdentity $identity, int $shopId): LocalProductLookupResult
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Lookup shop id must be positive.');
        }
        $this->receivedIdentity = $identity;
        $this->receivedShopId = $shopId;
        if ($this->response instanceof LocalProductLookupException) {
            throw $this->response;
        }

        return $this->response;
    }
}
