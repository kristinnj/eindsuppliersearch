<?php

/* supplierproductpresenter.php */

use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use PrestaShop\PrestaShop\Core\Product\ProductPresentationSettings;

require_once _PS_ROOT_DIR_ . '/modules/eindsuppliersearch/controllers/front/addtocartfunctions.php';

/**
 * Feeds supplier search results through PrestaShop's own ProductListingPresenter
 * so grid cards render with the theme's real product-miniature markup instead of
 * bespoke module CSS.
 *
 * Products already present locally (matched by supplier_reference) are presented
 * from the real Product row (eind_virtual = false: native PrestaShop card,
 * native cart form, real product page link).
 *
 * Everything else is presented from the raw supplier data with a synthetic
 * negative placeholder id (eind_virtual = true), because core's image lookup
 * (ProductLazyArray::fillImages()) unconditionally re-queries the `image` table
 * by id_product and would otherwise wipe out any supplier image URL passed in
 * the raw array -- the virtual-product-miniature.tpl override reads
 * eind_image_url / eind_detail_url directly instead of relying on
 * $product.cover / $product.url for these placeholder items.
 */
class EindSupplierProductPresenter
{
    /** @var EindAddToCartFunctions */
    private $cartHelper;

    /** @var ProductListingPresenter */
    private $presenter;

    /** @var ProductPresentationSettings */
    private $settings;

    public function __construct()
    {
        $this->cartHelper = new EindAddToCartFunctions();

        $context = Context::getContext();
        $this->presenter = new ProductListingPresenter(
            new ImageRetriever($context->link),
            $context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $context->getTranslator()
        );

        $this->settings = new ProductPresentationSettings();
        $this->settings->catalog_mode = false;
        $this->settings->showPrices = true;
        $this->settings->include_taxes = true;
    }

    /**
     * @param array $standardProduct one "standard product" array as produced by EindConvertJsonToProductArray
     * @param int $supplierId
     * @param int $productKey index of this product inside its supplier's Products[] array
     *
     * @return mixed presented product (ArrayAccess-able) ready for the miniature template, or null on failure
     */
    public function present(array $standardProduct, int $supplierId, int $productKey)
    {
        $orderCode = (string) ($standardProduct['OrderCode'] ?? '');
        $existingProductId = $orderCode !== '' ? (int) $this->cartHelper->getIdBySupplierReference($orderCode) : 0;

        if ($existingProductId > 0) {
            $raw = $this->buildRawArrayFromLocalProduct($existingProductId);
            if ($raw !== null) {
                $presented = $this->presenter->present($this->settings, $raw, Context::getContext()->language);
                $presented['eind_virtual'] = false;

                return $presented;
            }
            // Fall through to the virtual presentation if the matched local product
            // turned out to be invalid/deleted between the reference lookup and here.
        }

        $placeholderId = 0 - (($supplierId * 1000000) + $productKey + 1);
        $raw = $this->buildRawArrayFromStandardProduct($standardProduct, $supplierId, $placeholderId);
        $presented = $this->presenter->present($this->settings, $raw, Context::getContext()->language);

        $presented['eind_virtual'] = true;
        $presented['eind_supplier_id'] = $supplierId;
        $presented['eind_product_key'] = $productKey;
        $presented['eind_order_code'] = $orderCode;
        $presented['eind_image_url'] = $standardProduct['Images'][0]['PrimaryURL']
            ?? $standardProduct['Images'][0]['SecondaryURL']
            ?? '';
        $presented['eind_detail_url'] = Context::getContext()->link->getModuleLink(
            'eindsuppliersearch',
            'productview',
            [
                'supplierId' => $supplierId,
                'key' => $productKey,
                'orderCode' => $orderCode,
            ]
        );
        $presented['eind_minimum_order_qty'] = $this->toPositiveInt($standardProduct['MinimumOrderQty'] ?? null, 1);
        $presented['eind_order_multiples'] = $this->toPositiveInt($standardProduct['OrderMultiples'] ?? null, 1);

        return $presented;
    }

    private function buildRawArrayFromLocalProduct(int $idProduct): ?array
    {
        $context = Context::getContext();
        $idLang = (int) $context->language->id;
        $product = new Product($idProduct, false, $idLang);

        if (!Validate::isLoadedObject($product)) {
            return null;
        }

        $price = $this->convertToCurrentCurrency((float) $product->price);

        return [
            'id_product' => (int) $product->id,
            'id_product_attribute' => 0,
            'name' => $product->name,
            'price' => $price,
            'id_image' => null,
            'link_rewrite' => $product->link_rewrite,
            'id_supplier' => (int) $product->id_supplier,
            'id_manufacturer' => (int) $product->id_manufacturer,
            'id_category_default' => (int) $product->id_category_default,
            'id_shop_default' => (int) $product->id_shop_default,
            'id_tax_rules_group' => (int) $product->id_tax_rules_group,
            'reference' => $product->reference,
            'supplier_reference' => $product->supplier_reference,
            'description_short' => $product->description_short,
            'description' => $product->description,
            'low_stock_threshold' => $product->low_stock_threshold,
            'product_type' => $product->product_type ?: 'standard',
            'active' => (int) $product->active,
            'visibility' => $product->visibility,
            'quantity' => (int) $product->quantity,
            'out_of_stock' => (int) $product->out_of_stock,
            'customizable' => (int) $product->customizable,
            'pack' => 0,
            'virtual' => (int) $product->is_virtual,
            'available_for_order' => (int) $product->available_for_order,
            'show_price' => true,
            'minimal_quantity' => 1,
        ] + $this->pricingDefaults($price);
    }

    /**
     * @param array $standardProduct
     * @param int $supplierId
     * @param int $placeholderId negative synthetic id, unique per session/page
     */
    private function buildRawArrayFromStandardProduct(array $standardProduct, int $supplierId, int $placeholderId): array
    {
        $context = Context::getContext();
        $languages = Language::getLanguages(false);

        $name = (string) ($standardProduct['DisplayText'] ?? $standardProduct['ProductName'] ?? $standardProduct['OrderCode'] ?? 'Unknown');
        $price = isset($standardProduct['Prices'][0]['LocalPrice'])
            ? $this->convertToCurrentCurrency((float) $standardProduct['Prices'][0]['LocalPrice'])
            : 0.0;
        $quantity = isset($standardProduct['Stock']['Level']) ? (int) $standardProduct['Stock']['Level'] : 0;
        $reference = (string) ($standardProduct['ManufacturerPartNumber'] ?? '');
        $rewriteBase = Tools::str2url($name !== '' ? $name : 'product');
        $linkRewrite = $this->buildMultilangValue($languages, $rewriteBase);

        return [
            'id_product' => $placeholderId,
            'id_product_attribute' => 0,
            'name' => $name,
            'price' => $price,
            'id_image' => null,
            'link_rewrite' => $linkRewrite,
            'id_supplier' => $supplierId,
            'id_manufacturer' => 0,
            'id_category_default' => (int) Configuration::get('PS_HOME_CATEGORY'),
            'id_shop_default' => (int) $context->shop->id,
            'id_tax_rules_group' => 0,
            'reference' => $reference,
            'supplier_reference' => (string) ($standardProduct['OrderCode'] ?? ''),
            'description_short' => '',
            'description' => '',
            'low_stock_threshold' => 0,
            'product_type' => 'standard',
            'active' => 1,
            'visibility' => 'both',
            'quantity' => $quantity,
            'out_of_stock' => 1, // allow ordering; supplier stock is informational, not authoritative
            'customizable' => 0,
            'pack' => 0,
            'virtual' => 0,
            'available_for_order' => 1,
            'show_price' => true,
            'minimal_quantity' => $this->toPositiveInt($standardProduct['MinimumOrderQty'] ?? null, 1),
        ] + $this->pricingDefaults($price);
    }

    /**
     * Supplier/local product prices are stored in the shop's default currency;
     * ProductListingLazyArray only formats whatever 'price' it's given, it
     * does not itself apply the currency exchange rate (that's baked into the
     * SQL for real catalog listings, which this raw-array path bypasses).
     * Without this conversion, switching currencies would relabel the price
     * with a new symbol without actually changing the number.
     */
    private function convertToCurrentCurrency(float $price): float
    {
        return (float) Tools::convertPrice($price, Context::getContext()->currency);
    }

    /**
     * Fields ProductListingLazyArray's price/discount computation reads from
     * the raw product array but that core's own SQL-backed listings would
     * normally already carry (specific price / reduction bookkeeping). None
     * of our supplier or locally-matched products have an active discount,
     * so these are fixed "no discount applies" defaults.
     */
    private function pricingDefaults(float $price): array
    {
        return [
            'specific_prices' => false,
            'reduction' => 0,
            'price_without_reduction' => $price,
            'price_without_reduction_without_tax' => $price,
            'price_tax_exc' => $price,
            'online_only' => false,
            'on_sale' => false,
        ];
    }

    private function toPositiveInt($value, int $default): int
    {
        if (is_numeric($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : $default;
        }

        return $default;
    }

    private function buildMultilangValue(array $languages, string $value): array
    {
        $values = [];

        foreach ($languages as $language) {
            $idLang = isset($language['id_lang']) ? (int) $language['id_lang'] : 0;
            if ($idLang > 0) {
                $values[$idLang] = $value;
            }
        }

        if (empty($values)) {
            $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
            if ($defaultLangId > 0) {
                $values[$defaultLangId] = $value;
            }
        }

        return $values;
    }
}
