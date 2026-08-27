<?php

namespace EindSupplierSearch\Presenter;

use EindSupplierSearch\Domain\OfferActionReference;
use EindSupplierSearch\Domain\ProductSearchHit;
use EindSupplierSearch\Domain\SupplierOffer;

/**
 * Converts ProductSearchHit domain objects into simple, Smarty-ready
 * associative arrays (no domain objects, no formatting decisions beyond
 * a stable timestamp string).
 *
 * Presentation mapping only. Must never: call supplier APIs, read
 * cookies, perform searches, create PrestaShop products, calculate
 * margins, pick a "preferred" supplier, change stock, or write to the
 * database.
 */
final class ProductSearchHitPresenter
{
    /** @return array<string, mixed> */
    public function present(ProductSearchHit $hit): array
    {
        $product = $hit->getProduct();
        $offers = $hit->getOffers();

        return [
            'manufacturer' => $product->getManufacturer(),
            'manufacturerPartNumber' => $product->getManufacturerPartNumber(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'attributes' => $product->getAttributes(),
            'images' => $product->getImages(),
            'datasheets' => $product->getDatasheets(),
            'compliance' => $product->getCompliance(),
            'supplierCount' => count($offers),
            'offers' => array_map([$this, 'presentOffer'], $offers),
        ];
    }

    /**
     * @param ProductSearchHit[] $hits
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $hits): array
    {
        return array_map([$this, 'present'], $hits);
    }

    /** @return array<string, mixed> */
    private function presentOffer(SupplierOffer $offer): array
    {
        return [
            'supplierName' => $offer->getSupplierName(),
            'supplierOrderCode' => $offer->getSupplierOrderCode(),
            'stockLevel' => $offer->getStockLevel(),
            'stockStatus' => $offer->getStockStatus(),
            'minimumOrderQty' => $offer->getMinimumOrderQty(),
            'packaging' => $offer->getPackaging(),
            'deliveryInfo' => $offer->getDeliveryInfo(),
            'priceTiers' => $offer->getPriceTiers(),
            'timestamp' => $offer->getTimestamp()->format(DATE_ATOM),
            'action' => $this->presentAction($offer->getActionReference()),
        ];
    }

    /**
     * Opaque-to-the-template action information: whether this offer can
     * safely perform a commerce action, and if so, the reference fields
     * the existing add-to-cart bridge (EindAddToCartFunctions) needs to
     * re-locate the exact source row server-side. Never carries price,
     * stock, or any other commercial data -- that stays on the offer
     * itself and is never trusted from the browser on submit.
     *
     * @return array{available: bool, reference: array{supplierBucketId: int|string, sourceRowIndex: int}|null}
     */
    private function presentAction(?OfferActionReference $actionReference): array
    {
        if ($actionReference === null) {
            return [
                'available' => false,
                'reference' => null,
            ];
        }

        return [
            'available' => true,
            'reference' => [
                'supplierBucketId' => $actionReference->getSupplierBucketId(),
                'sourceRowIndex' => $actionReference->getSourceRowIndex(),
            ],
        ];
    }
}
