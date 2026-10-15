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

            $supplierValue = Tools::getValue('supplierId');
            $productReference = Tools::getValue('product_reference');
            $orderCode = Tools::getValue('order_code');
            $supplierId = (is_string($supplierValue) || is_int($supplierValue))
                ? filter_var($supplierValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

            if ($supplierId === false || !is_string($productReference) || !ctype_digit($productReference)
                || ($orderCode !== false && $orderCode !== null && !is_string($orderCode))) {
                return $this->renderJson(false, $this->orderingUnavailableMessage());
            }

            $cookie = $this->context->cookie;
            if (!$this->module->ensureSearchCookie($cookie, false)) {
                return $this->renderJson(false, $this->module->l('Search session expired. Please run the search again.'));
            }
            if (empty($cookie->eind_session_id)) {
                return $this->renderJson(false, $this->module->l('Search session expired. Please run the search again.'));
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

            // Cached selection is not ownership evidence. Runtime provenance,
            // verified native reuse and a write-time safety boundary are not wired.
            // Contain ALL supplier ordering, including apparently ACTIVE bindings,
            // before native lookup, creation, updates or cart mutation.
            return $this->renderJson(false, $this->orderingUnavailableMessage());
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                sprintf('[EindSupplierSearch] Supplier ordering rejected: %s', get_class($exception)),
                3
            );

            return $this->renderJson(false, $this->orderingUnavailableMessage());
        }
    }

    /**
     * Logs only the failure category, without raw client references or
     * session details -- see
     * docs/development/offer-action-reference.md, "Logging".
     */
    private function logResolutionFailure(string $category, int $supplierId, $productReference, $orderCode): void
    {
        \PrestaShopLogger::addLog('[EindSupplierSearch] Offer resolution rejected: ' . $category, 2);
    }

    private function denyInvalidRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->renderJson(false, $this->module->l('Invalid request method.'));
        }

        $token = Tools::getValue('token');
        $expected = Tools::getToken(false);
        if (!is_string($token) || !$token || !$expected || !hash_equals($expected, $token)) {
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

    private function orderingUnavailableMessage(): string
    {
        return $this->module->l('This supplier offer is not currently available for ordering. Please try another offer or contact us.');
    }
}
