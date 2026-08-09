<?php

require_once(_PS_ROOT_DIR_ . '/modules/eindsuppliersearch/controllers/front/addtocartfunctions.php');
require_once(_PS_ROOT_DIR_ . '/modules/eindsuppliersearch/controllers/front/callsupplierapi.php');
require_once(_PS_ROOT_DIR_ . '/modules/eindsuppliersearch/controllers/front/apidatabase.php');
require_once(_PS_ROOT_DIR_ . '/modules/eindsuppliersearch/controllers/front/supplierproductpresenter.php');

use EindSupplierSearch\Domain\SearchQuery;
use EindSupplierSearch\Factory\SupplierProviderResolver;
use EindSupplierSearch\Service\SearchService;

class EindsuppliersearchSearchResultsModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $apidatabase = new EindApiDatabase();

        $cookie = $this->context->cookie;
        $this->module->ensureSearchCookie($cookie, true);

        // Get current parameters from cookie and set default parameters
        $cookie->eind_searchTerm = "any";
        $backTrack = false;

        // Get the action type and supplier from the request. The initial search-bar
        // form submits `api_submit`; every pagination/filter form on the results page
        // (previous/next/changePage/iop/searchFilter/back) submits `submit_search`.
        $searchAction = Tools::getValue('api_submit');
        if ($searchAction === false || $searchAction === '') {
            $searchAction = Tools::getValue('submit_search');
        }
        $supplierId = Tools::getValue('supplierId');
        $searchHistory = [];
        $history = [];
        $useCachedResults = false;

        // PrestaShop's currency switcher does a full-page GET reload of whatever
        // URL the shopper was on (core's Tools::setCurrency() reads
        // SubmitCurrency/id_currency but never redirects), which replays that
        // URL's leftover action params (submit_search=next, api_submit=search,
        // ...) as if they were a brand-new user action -- silently advancing
        // pagination again, or wiping the query on a plain revisit with no
        // api_search_query. A currency change isn't a search action: redisplay
        // the results already stored for this session instead, so prices get
        // re-presented (and correctly converted, see EindSupplierProductPresenter)
        // in the new currency without otherwise touching search state.
        if (Tools::isSubmit('SubmitCurrency')) {
            $useCachedResults = true;
            $searchAction = 'currencyChange';
        }

        switch ($searchAction) {
            case "currencyChange":
                // No-op: cookie/search state is left exactly as it was: only
                // the currency changed, so nothing about the search itself
                // (query, page, filters) should change.
                break;

            case "search":
                $cookie->eind_query = Tools::getValue('api_search_query');
                $cookie->eind_pageOffset = (int) 1;
                $cookie->eind_pnuTrack = false;
                $history["query"] = $cookie->eind_query;
                $history["pageOffset"] = (int) 1;
                $history["searchTerm"] = "any";
                $history["itemsOnPage"] = $cookie->eind_itemsOnPage;
                $history["inStock"] = (bool) $cookie->eind_inStock;
                $history["rohsCompliant"] = (bool) $cookie->eind_rohsCompliant;
                $searchHistory = [];
                $searchHistory[] = $history;
                $cookie->eind_searchHistory = json_encode($searchHistory);
                break;

            case "previous":
                // $pageOffset is an item offset into the supplier's result set (passed
                // straight through to the API as resultsSettings.offset), not a page
                // number -- step back by a full batch, not by 1.
                if ($cookie->eind_pageOffset > 0) {
                    $itemsOnPage = max(1, (int) $cookie->eind_itemsOnPage);
                    $cookie->eind_pageOffset = max(1, (int) $cookie->eind_pageOffset - $itemsOnPage);
                }
                $searchHistory = $this->readSearchHistory($cookie);
                if (!empty($searchHistory)) {
                    $history = (array) $searchHistory[0];
                    $history["pageOffset"] = $cookie->eind_pageOffset;
                    $searchHistory[0] = $history;
                    $this->writeSearchHistory($cookie, $searchHistory);
                }
                break;

            case "next":
                // Same item-offset reasoning as "previous": step forward by a full
                // batch, bounded by the total number of results (not number of pages).
                $totalResults = (int) Tools::getValue('totalResults');
                if ($cookie->eind_pageOffset < $totalResults) {
                    $itemsOnPage = max(1, (int) $cookie->eind_itemsOnPage);
                    $cookie->eind_pageOffset = (int) $cookie->eind_pageOffset + $itemsOnPage;
                }
                $searchHistory = $this->readSearchHistory($cookie);
                if (!empty($searchHistory)) {
                    $history = (array) $searchHistory[0];
                    $history["pageOffset"] = (int) $cookie->eind_pageOffset;
                    $searchHistory[0] = $history;
                    $this->writeSearchHistory($cookie, $searchHistory);
                }
                break;

            case "changePage":
                // pageNum is a human-facing page NUMBER (1, 2, 3...); convert it to
                // the item offset the API actually expects.
                $page = (int) Tools::getValue('pageNum');
                if ($page > 0) {
                    $itemsOnPage = max(1, (int) $cookie->eind_itemsOnPage);
                    $cookie->eind_pageOffset = ($page - 1) * $itemsOnPage + 1;
                }
                $searchHistory = $this->readSearchHistory($cookie);
                if (!empty($searchHistory)) {
                    $history = (array) $searchHistory[0];
                    $history["pageOffset"] = (int) $cookie->eind_pageOffset;
                    $searchHistory[0] = $history;
                    $this->writeSearchHistory($cookie, $searchHistory);
                }
                break;

            case "iop":
                $cookie->eind_itemsOnPage = Tools::getValue('itemsonpage');
                $cookie->eind_pageOffset = 1;
                $searchHistory = $this->readSearchHistory($cookie);
                if (!empty($searchHistory)) {
                    $history = (array) $searchHistory[0];
                    $history["itemsOnPage"] = $cookie->eind_itemsOnPage;
                    $history["pageOffset"] = (int) $cookie->eind_pageOffset;
                    $searchHistory[0] = $history;
                    $this->writeSearchHistory($cookie, $searchHistory);
                }
                break;

            case "searchFilter":
                $cookie->eind_searchFilter = "";
                $filter = Tools::getValue('filterStock');
                if ($filter == "inStock") {
                    $cookie->eind_inStock = (bool) true;
                    $cookie->eind_searchFilter = "inStock,";
                } else {
                    $cookie->eind_inStock = (bool) false;
                }
                $filter = Tools::getValue('filterRohs');
                if ($filter == "rohsCompliant") {
                    $cookie->eind_rohsCompliant = (bool) true;
                    $cookie->eind_searchFilter = $cookie->eind_searchFilter . "rohsCompliant";
                } else {
                    $cookie->eind_rohsCompliant = (bool) false;
                    if (strlen($cookie->eind_searchFilter) > 0) {
                        $cookie->eind_searchFilter = substr($cookie->eind_searchFilter, 0, -1);
                    }
                }
                $searchHistory = $this->readSearchHistory($cookie);
                if (!empty($searchHistory)) {
                    $history = (array) $searchHistory[0];
                    $history["inStock"] = (bool) $cookie->eind_inStock;
                    $history["rohsCompliant"] = (bool) $cookie->eind_rohsCompliant;
                    $searchHistory[0] = $history;
                    $this->writeSearchHistory($cookie, $searchHistory);
                }
                break;

            case "sku":
                $useCachedResults = true;
                $cookie->eind_preSkuQuery = $cookie->eind_query;
                $cookie->eind_preSkuPageOffset = (int) $cookie->eind_pageOffset;
                $cookie->eind_preSkuTerm = $cookie->eind_searchTerm;
                $cookie->eind_query = Tools::getValue('sku');
                $cookie->eind_pageOffset = 1;
                $cookie->eind_searchTerm = "id";
                $cookie->eind_productAnchor = $cookie->eind_query;
                $searchHistory = $this->readSearchHistory($cookie);
                if (count($searchHistory) === 5) {
                    array_shift($searchHistory);
                }
                $history = [];
                $history["query"] = $cookie->eind_query;
                $history["pageOffset"] = 1;
                $history["searchTerm"] = "id";
                $history["itemsOnPage"] = $cookie->eind_itemsOnPage;
                $history["inStock"] = (bool) $cookie->eind_inStock;
                $history["rohsCompliant"] = (bool) $cookie->eind_rohsCompliant;
                $searchHistory[] = $history;
                $this->writeSearchHistory($cookie, $searchHistory);
                $backTrack = true;
                break;

            case "manuPartNum":
                $cookie->eind_prePnuQuery = $cookie->eind_query;
                $cookie->eind_prePnuPageOffset = (int) $cookie->eind_pageOffset;
                $cookie->eind_prePnuTerm = $cookie->eind_searchTerm;
                $cookie->eind_query = Tools::getValue('manuPartNum');
                $cookie->eind_pageOffset = 1;
                $cookie->eind_searchTerm = "manuPartNum";
                $cookie->eind_pnuTrack = true;
                $cookie->eind_productAnchor = $cookie->eind_query;

                $searchHistory = $this->readSearchHistory($cookie);
                if (count($searchHistory) === 5) {
                    array_shift($searchHistory);
                }
                $history = [];
                $history["query"] = $cookie->eind_query;
                $history["pageOffset"] = 1;
                $history["searchTerm"] = "manuPartNum";
                $history["itemsOnPage"] = $cookie->eind_itemsOnPage;
                $history["inStock"] = (bool) $cookie->eind_inStock;
                $history["rohsCompliant"] = (bool) $cookie->eind_rohsCompliant;
                $searchHistory[] = $history;
                $this->writeSearchHistory($cookie, $searchHistory);
                $backTrack = true;
                break;

            case "back":
                $searchHistory = $this->readSearchHistory($cookie);
                if (!empty($searchHistory)) {
                    array_pop($searchHistory);
                    if (!empty($searchHistory)) {
                        $index = count($searchHistory) - 1;
                        $history = (array) $searchHistory[$index];
                        $cookie->eind_query = $history["query"];
                        $cookie->eind_pageOffset = (int) $history["pageOffset"];
                        $cookie->eind_searchTerm = $history["searchTerm"];
                        $cookie->eind_itemsOnPage = $history["itemsOnPage"];
                        $cookie->eind_inStock = (bool) $history["inStock"];
                        $cookie->eind_rohsCompliant = (bool) $history["rohsCompliant"];
                        $this->writeSearchHistory($cookie, $searchHistory);
                        if (count($searchHistory) > 1) {
                            $backTrack = true;
                        }
                    } else {
                        $this->writeSearchHistory($cookie, $searchHistory);
                    }
                }
                break;

            default:
                $cookie->eind_pageOffset = 1;
                $cookie->eind_query = Tools::getValue('api_search_query');
                $cookie->eind_searchTerm = "any";
                $cookie->eind_pnuTrack = false;
                $backTrack = false;
        }

        $searchData = [];
        $searchData["query"] = $cookie->eind_query;
        $searchData["inStock"] = (bool) $cookie->eind_inStock;
        $searchData["rohsCompliant"] = (bool) $cookie->eind_rohsCompliant;

        if ($useCachedResults) {
            $searchResults = $apidatabase->getProductListBySessionId($cookie->eind_session_id);
            if (!is_array($searchResults)) {
                $searchResults = [];
            }
        } else {
            $searchResults = $this->runSearch(
                $cookie->eind_query,
                (int) $cookie->eind_itemsOnPage,
                (int) $cookie->eind_pageOffset,
                $cookie->eind_searchTerm,
                $cookie->eind_searchFilter
            );
        }

        if ($searchResults === false || empty($searchResults)) {
            $searchError = [];
            $searchError['Error'] = (bool) true;
            $searchError['Query'] = (string) $cookie->eind_query;
            $this->context->smarty->assign([
                'search_results' => $searchError,
                'currency' => $this->context->currency,
            ]);
            $this->setTemplate('module:eindsuppliersearch/views/templates/front/searchresults.tpl');

            return;
        }

        foreach ($searchResults as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $searchResults[$key]["RohsFilter"] = (bool) $cookie->eind_rohsCompliant;
            $searchResults[$key]["InStockFilter"] = (bool) $cookie->eind_inStock;
            $searchResults[$key]["Query"] = $cookie->eind_query;
            $searchResults[$key]["BackTrack"] = $backTrack;

            // PageOffset is an item offset (see the "next"/"previous" cases above),
            // not a page number -- derive a page number purely for display, and
            // pre-compute the pagination button states from the same bounds the
            // "next"/"previous" actions use, so they can never disagree.
            $itemsOnPage = max(1, (int) $cookie->eind_itemsOnPage);
            $numberOfResults = (int) ($value["NumberOfResults"] ?? 0);
            $pageOffset = (int) $cookie->eind_pageOffset;
            $searchResults[$key]["CurrentPage"] = (int) floor(max(0, $pageOffset - 1) / $itemsOnPage) + 1;
            $searchResults[$key]["HasPreviousPage"] = $pageOffset > 1;
            $searchResults[$key]["HasNextPage"] = $pageOffset < $numberOfResults;
        }

        if (!$useCachedResults) {
            $apidatabase->storeProductListInDb($cookie->eind_session_id, $searchResults);
        }

        $this->assignPresentedProducts($searchResults);

        Media::addJsDef([
            'eindsuppliersearch_addToCartUrl' =>
                $this->context->link->getModuleLink('eindsuppliersearch', 'addtocart', [], null, null, null, false, false),
            'eindsuppliersearch_addToCartToken' => Tools::getToken(false),
        ]);

        $this->context->smarty->assign([
            'search_results' => $searchResults,
            'searchData' => $searchData,
            'currency' => $this->context->currency,
        ]);

        $this->setTemplate('module:eindsuppliersearch/views/templates/front/searchresults.tpl');
    }

    /**
     * Resolves the configured supplier provider (live API or JSON fixtures,
     * see SupplierProviderResolver) and runs the search through it via
     * SearchService, returning the same supplier-id-keyed array shape
     * EindCallSupplierApi::querySuppliers() used to return directly.
     */
    private function runSearch(string $criteria, int $itemsOnPage, int $pageOffset, string $searchTerm, string $searchFilter): array
    {
        $searchQuery = SearchQuery::fromLegacyParameters($criteria, $itemsOnPage, $pageOffset, $searchTerm, $searchFilter);

        $searchService = new SearchService(
            SupplierProviderResolver::forModule()->resolve(),
            static function (string $message, int $severity): void {
                PrestaShopLogger::addLog('[EindSupplierSearch] ' . $message, $severity);
            }
        );

        return $searchService->search($searchQuery)->toLegacyArray();
    }

    /**
     * Runs each supplier's product list through EindSupplierProductPresenter and
     * assigns the result as $supplierData['PresentedProducts'] (same order/keys
     * as $supplierData['Products']), so the template can render native-looking
     * cards without re-deriving presentation logic itself. Items that fail to
     * present (unexpected supplier data shape) are skipped rather than
     * breaking the whole grid.
     */
    private function assignPresentedProducts(array &$searchResults): void
    {
        $presenter = new EindSupplierProductPresenter();

        foreach ($searchResults as $supplierId => $supplierData) {
            if (!is_array($supplierData) || empty($supplierData['Products']) || !is_array($supplierData['Products'])) {
                continue;
            }

            $presented = [];
            foreach ($supplierData['Products'] as $productKey => $product) {
                if (!is_array($product)) {
                    continue;
                }

                try {
                    $presented[$productKey] = $presenter->present($product, (int) $supplierId, (int) $productKey);
                } catch (\Throwable $exception) {
                    PrestaShopLogger::addLog(
                        sprintf('[EindSupplierSearch] Failed to present product %s: %s', (string) ($product['OrderCode'] ?? '?'), $exception->getMessage()),
                        3
                    );
                }
            }

            $searchResults[$supplierId]['PresentedProducts'] = $presented;
        }
    }

    private function readSearchHistory($cookie): array
    {
        $history = json_decode((string) ($cookie->eind_searchHistory ?? ''), true);

        return is_array($history) ? $history : [];
    }

    private function writeSearchHistory($cookie, array $history): void
    {
        $cookie->eind_searchHistory = json_encode($history);
    }
}
