<?php
/**
 * Secure front controller that handles AJAX add-to-cart requests.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_ROOT_DIR_ . '/modules/eindsuppliersearch/controllers/front/addtocartfunctions.php';

class EindsuppliersearchAddToCartModuleFrontController extends ModuleFrontController
{
    /**
     * @var EindAddToCartFunctions
     */
    private $cartHelper;

    public function init()
    {
        parent::init();
        $this->ajax = true;
        $this->cartHelper = new EindAddToCartFunctions();
    }

    public function postProcess()
    {
        try {
            $this->denyInvalidRequest();

            $supplierId = (int) Tools::getValue('supplierId');
            $productReference = Tools::getValue('product_reference');
            $quantity = max(1, (int) Tools::getValue('quantity'));
            $orderCode = Tools::getValue('order_code');

            if ($supplierId <= 0 || $productReference === null || $productReference === '') {
                return $this->renderJson(false, $this->module->l('Missing supplier or product information.'));
            }

            $cookie = $this->context->cookie;
            if (!$this->module->ensureSearchCookie($cookie, false)) {
                return $this->renderJson(false, $this->module->l('Search session expired. Please run the search again.'));
            }
            if (empty($cookie->eind_session_id)) {
                return $this->renderJson(false, $this->module->l('Search session expired. Please run the search again.'));
            }

            $taxRuleId = $this->resolveTaxRuleId();
            if ($taxRuleId <= 0) {
                return $this->renderJson(false, $this->module->l('The tax rule for supplier products is not configured.'));
            }

            // Supplier-strict resolution (see
            // docs/development/offer-action-reference.md, "Supplier-strict
            // commerce resolution"): product_reference must be a genuine
            // row index, not an arbitrary string that would silently
            // coerce to 0 via (int) cast -- reject anything else rather
            // than guess.
            if (!ctype_digit((string) $productReference)) {
                $this->logResolutionFailure('MALFORMED_ROW_REFERENCE', $supplierId, $productReference, $orderCode);

                return $this->renderJson(false, $this->module->l('Unable to locate the product in the cached supplier response.'));
            }

            try {
                $actionReference = new \EindSupplierSearch\Domain\OfferActionReference($supplierId, (int) $productReference);
            } catch (\InvalidArgumentException $exception) {
                $this->logResolutionFailure('INVALID_REFERENCE', $supplierId, $productReference, $orderCode);

                return $this->renderJson(false, $this->module->l('Unable to locate the product in the cached supplier response.'));
            }

            $expectedOrderCode = ($orderCode !== null && $orderCode !== '') ? (string) $orderCode : null;

            $resolution = $this->cartHelper->getStrictCachedOffer($cookie->eind_session_id, $actionReference, $expectedOrderCode);

            if (!$resolution->isSuccessful()) {
                $this->logResolutionFailure((string) $resolution->getFailureCategory(), $supplierId, $productReference, $orderCode);

                return $this->renderJson(false, $this->module->l('Unable to locate the product in the cached supplier response.'));
            }

            $product = $resolution->getProduct();

            $category = (int) Configuration::get('EIND_SUPPLIERSEARCH_DEFAULT_CATEGORY');
            if ($category <= 0) {
                $category = (int) Configuration::get('PS_HOME_CATEGORY');
            }

            $productId = $this->cartHelper->getIdBySupplierReference($product['OrderCode']);
            if (!$productId) {
                $productId = $this->cartHelper->insertNewProduct($product, $supplierId, $taxRuleId, $category);
                if (!$productId) {
                    return $this->renderJson(false, $this->module->l('Failed to create the product locally.'));
                }

                if (!empty($product['Images'][0])) {
                    $primary = $product['Images'][0]['PrimaryURL'] ?? '';
                    $secondary = $product['Images'][0]['SecondaryURL'] ?? '';
                    if (!$this->cartHelper->addProductImage($productId, $primary, 'jpg') && $secondary) {
                        $this->cartHelper->addProductImage($productId, $secondary, 'jpg');
                    }
                }

                Search::indexation(false);
            } else {
                if (!$this->cartHelper->updateExistingProduct($productId, $product, $taxRuleId)) {
                    return $this->renderJson(false, $this->module->l('Failed to update the existing product.'));
                }
            }

            $this->cartHelper->updateStock($productId, 0, $product['Stock']['Level']);

            $priceRows = isset($product['Prices']) && is_array($product['Prices']) ? $product['Prices'] : [];
            $this->cartHelper->syncSpecificPrices($productId, $priceRows);

            $this->cartHelper->addToCart($productId, $quantity, 0);

            $cartQuantity = (int) $this->context->cart->getNbProducts((int) $this->context->cart->id);

            return $this->renderJson(
                true,
                $this->module->l('Product added to the cart.'),
                [
                    'product_id' => (int) $productId,
                    'cart_quantity' => $cartQuantity,
                ]
            );
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                sprintf('[EindSupplierSearch] Add-to-cart failed: %s', $exception->getMessage()),
                3
            );

            $message = _PS_MODE_DEV_
                ? $exception->getMessage()
                : $this->module->l('Unexpected error while adding the product to the cart.');

            return $this->renderJson(false, $message);
        }
    }

    /**
     * Logs a failed offer resolution with enough context to diagnose it
     * (requested supplier/row/order-code, failure category) without
     * logging anything sensitive -- see
     * docs/development/offer-action-reference.md, "Logging".
     */
    private function logResolutionFailure(string $category, int $supplierId, $productReference, $orderCode): void
    {
        \PrestaShopLogger::addLog(
            sprintf(
                '[EindSupplierSearch] Offer resolution failed: category=%s supplierId=%d row=%s orderCode=%s',
                $category,
                $supplierId,
                (string) $productReference,
                ($orderCode !== null && $orderCode !== '') ? (string) $orderCode : '-'
            ),
            2
        );
    }

    private function denyInvalidRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->renderJson(false, $this->module->l('Invalid request method.'));
        }

        $token = Tools::getValue('token');
        $expected = Tools::getToken(false);
        if (!$token || !$expected || !hash_equals($expected, $token)) {
            $this->renderJson(false, $this->module->l('Invalid security token.'));
        }
    }

    private function renderJson(bool $success, string $message, array $payload = [])
    {
        header('Content-Type: application/json');
        $body = array_merge([
            'success' => $success,
            'message' => $message,
        ], $payload);

        $json = json_encode($body);
        if ($json === false || $json === null) {
            $errorMessage = function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown';
            \PrestaShopLogger::addLog(
                sprintf('[EindSupplierSearch] Failed to JSON-encode response: %s', $errorMessage),
                3
            );

            $json = json_encode([
                'success' => false,
                'message' => $this->module->l('Failed to encode JSON response.'),
            ]);
        }

        die($json);
    }

    private function resolveTaxRuleId(): int
    {
        $configured = (int) Configuration::get('EIND_SUPPLIERSEARCH_API_TAX_RULE');
        if ($configured > 0) {
            return $configured;
        }

        $fallback = (int) Db::getInstance()->getValue(
            'SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE active = 1 ORDER BY id_tax_rules_group ASC'
        );

        return $fallback > 0 ? $fallback : 0;
    }
}
