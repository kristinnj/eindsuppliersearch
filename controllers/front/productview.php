<?php

use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;

require_once(_PS_ROOT_DIR_ . '/modules/eindsuppliersearch/controllers/front/addtocartfunctions.php');

/**
 * Virtual detail page for a supplier search result that isn't a real
 * PrestaShop product yet. Rendered with the theme's layout/chrome (not
 * core's catalog/product.tpl, which assumes a fully catalogued product with
 * combinations/reviews/etc. that a not-yet-imported item can't provide).
 */
class EindsuppliersearchProductViewModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $supplierId = (int) Tools::getValue('supplierId');
        $productKey = Tools::getValue('key');
        $orderCode = Tools::getValue('orderCode');

        $cookie = $this->context->cookie;
        $product = $this->resolveStrictProduct($cookie, $supplierId, $productKey, $orderCode);

        if ($product) {
            $product = $this->addFormattedPrices($product);
        }

        $this->context->smarty->assign([
            'eind_product' => $product,
            'eind_supplier_id' => $supplierId,
            'eind_product_key' => $productKey,
            'eind_back_url' => $this->context->link->getModuleLink('eindsuppliersearch', 'searchresults'),
        ]);

        if ($product) {
            Media::addJsDef([
                'eindsuppliersearch_addToCartUrl' =>
                    $this->context->link->getModuleLink('eindsuppliersearch', 'addtocart', [], null, null, null, false, false),
                'eindsuppliersearch_addToCartToken' => Tools::getToken(false),
            ]);
        }

        $this->setTemplate('module:eindsuppliersearch/views/templates/front/productview.tpl');
    }

    /**
     * Resolves the requested supplier offer through the same
     * supplier-strict path add-to-cart uses (see
     * docs/development/strict-product-detail.md): the query-string
     * supplierId/key/orderCode are untrusted input used only to build an
     * OfferActionReference, which EindSupplierSearch\Service\CachedOfferResolver
     * then resolves ONLY within the requested supplier's own cached
     * bucket -- never falling back to another supplier, another row, or
     * trusting these values as product data themselves. Returns null on
     * any failure (missing session, malformed cache, unknown supplier,
     * missing row, order-code mismatch, or a non-numeric row reference),
     * which productview.tpl already renders as its existing "Product not
     * found" state.
     *
     * @param mixed $productKey
     * @param mixed $orderCode
     * @return array<string, mixed>|null
     */
    private function resolveStrictProduct($cookie, int $supplierId, $productKey, $orderCode): ?array
    {
        if ($supplierId <= 0 || !ctype_digit((string) $productKey)) {
            return null;
        }

        if (!$this->module->ensureSearchCookie($cookie, false) || empty($cookie->eind_session_id)) {
            return null;
        }

        try {
            $reference = new \EindSupplierSearch\Domain\OfferActionReference($supplierId, (int) $productKey);
        } catch (\InvalidArgumentException $exception) {
            $this->logResolutionFailure('INVALID_REFERENCE', $supplierId, $productKey, $orderCode);

            return null;
        }

        $expectedOrderCode = ($orderCode !== null && $orderCode !== '') ? (string) $orderCode : null;

        $cartHelper = new EindAddToCartFunctions();
        $resolution = $cartHelper->getStrictCachedOffer($cookie->eind_session_id, $reference, $expectedOrderCode);

        if (!$resolution->isSuccessful()) {
            $this->logResolutionFailure((string) $resolution->getFailureCategory(), $supplierId, $productKey, $orderCode);

            return null;
        }

        return $resolution->getProduct();
    }

    /**
     * Logs a failed strict offer resolution with enough context to
     * diagnose it, without logging anything sensitive -- mirrors
     * EindsuppliersearchAddToCartModuleFrontController::logResolutionFailure()
     * (see docs/development/offer-action-reference.md, "Logging").
     */
    private function logResolutionFailure(string $category, int $supplierId, $productKey, $orderCode): void
    {
        PrestaShopLogger::addLog(
            sprintf(
                '[EindSupplierSearch] Detail offer resolution failed: category=%s supplierId=%d row=%s orderCode=%s',
                $category,
                $supplierId,
                (string) $productKey,
                ($orderCode !== null && $orderCode !== '') ? (string) $orderCode : '-'
            ),
            2
        );
    }

    /**
     * Pre-formats prices in PHP rather than calling a formatter from the
     * template -- PrestaShop's Smarty security policy does not allow
     * arbitrary static-class calls inside .tpl files.
     *
     * PriceFormatter::format() only applies locale/symbol formatting -- it
     * does not convert currency. Supplier prices are in the shop's default
     * currency, so convertAndFormat() (which converts via the current
     * context currency's exchange rate before formatting) has to be used
     * instead, otherwise the price stays the same number when the shopper
     * switches currency and only the symbol changes.
     */
    private function addFormattedPrices(array $product): array
    {
        if (empty($product['Prices']) || !is_array($product['Prices'])) {
            return $product;
        }

        $formatter = new PriceFormatter();

        foreach ($product['Prices'] as $index => $price) {
            if (isset($price['LocalVATPrice'])) {
                $price['FormattedLocalVATPrice'] = $formatter->convertAndFormat((float) $price['LocalVATPrice']);
            }
            if (isset($price['LocalPrice'])) {
                $price['FormattedLocalPrice'] = $formatter->convertAndFormat((float) $price['LocalPrice']);
            }
            $product['Prices'][$index] = $price;
        }

        return $product;
    }
}
