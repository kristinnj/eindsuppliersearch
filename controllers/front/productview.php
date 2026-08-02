<?php

use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;

require_once(_PS_ROOT_DIR_ . '/modules/eind_suppliersearch/controllers/front/addtocartfunctions.php');

/**
 * Virtual detail page for a supplier search result that isn't a real
 * PrestaShop product yet. Rendered with the theme's layout/chrome (not
 * core's catalog/product.tpl, which assumes a fully catalogued product with
 * combinations/reviews/etc. that a not-yet-imported item can't provide).
 */
class Eind_SuppliersearchProductViewModuleFrontController extends ModuleFrontController
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
            'eind_back_url' => $this->context->link->getModuleLink('eind_suppliersearch', 'searchresults'),
        ]);

        if ($product) {
            Media::addJsDef([
                'eind_suppliersearch_addToCartUrl' =>
                    $this->context->link->getModuleLink('eind_suppliersearch', 'addtocart', [], null, null, null, false, false),
                'eind_suppliersearch_addToCartToken' => Tools::getToken(false),
            ]);
        }

        $this->setTemplate('module:eind_suppliersearch/views/templates/front/productview.tpl');
    }

    /**
     * Pre-formats prices in PHP rather than calling a formatter from the
     * template -- PrestaShop's Smarty security policy does not allow
     * arbitrary static-class calls inside .tpl files.
     */
    private function addFormattedPrices(array $product): array
    {
        if (empty($product['Prices']) || !is_array($product['Prices'])) {
            return $product;
        }

        $formatter = new PriceFormatter();
        $currency = $this->context->currency;

        foreach ($product['Prices'] as $index => $price) {
            if (isset($price['LocalVATPrice'])) {
                $price['FormattedLocalVATPrice'] = $formatter->format((float) $price['LocalVATPrice'], $currency);
            }
            if (isset($price['LocalPrice'])) {
                $price['FormattedLocalPrice'] = $formatter->format((float) $price['LocalPrice'], $currency);
            }
            $product['Prices'][$index] = $price;
        }

        return $product;
    }
}
