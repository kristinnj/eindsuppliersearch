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
        $product = null;

        if ($this->module->ensureSearchCookie($cookie, false) && !empty($cookie->eind_session_id)) {
            $cartHelper = new EindAddToCartFunctions();
            $found = $cartHelper->getProductBySessionId($cookie->eind_session_id, $supplierId, $productKey, $orderCode);
            $product = $found ?: null;
        }

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
