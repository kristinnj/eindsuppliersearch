<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Eind_SupplierSearch extends Module
{
    public const SEARCH_RESULTS_TABLE = 'eind_search_results';
    public const SUPPLIER_TABLE = 'eind_search_suppliers';
    private const SEARCH_COOKIE_TTL = 86400; // 24 hours

    public $list_table;
    public $supplier_table;

    public function __construct()
    {
        $this->name = 'eind_suppliersearch';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Kristinn Johannesson';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('EIND Supplier Search');
        $this->description = $this->l('Search products from external supplier APIs and display them like native catalog products.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->list_table = self::SEARCH_RESULTS_TABLE;
        $this->supplier_table = self::SUPPLIER_TABLE;
    }

    public function install()
    {
        return $this->installListDb() &&
            $this->installSupplierDb() &&
            parent::install() &&
            $this->registerHook('displayTop') &&
            $this->registerHook('displaySearch') &&
            $this->registerOptionalHook('displayNavSearchBlock') &&
            $this->registerOptionalHook('displayMobileSearchBlock') &&
            $this->registerHook('displayLeftColumn') &&
            $this->registerHook('actionFrontControllerSetMedia');
    }

    private function installListDb()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.pSQL($this->list_table).'` (
                `id` INT(10) NOT NULL AUTO_INCREMENT,
                `session_id` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `product_list` MEDIUMTEXT NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `SessionIndex` (`session_id`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ');
    }

    private function installSupplierDb()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.pSQL($this->supplier_table).'` (
                `id` INT(10) NOT NULL AUTO_INCREMENT,
                `supplier_id` INT(10) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `api_method` VARCHAR(10) NOT NULL,
                `api_key` VARCHAR(255) NOT NULL,
                `api_url` VARCHAR(255) NOT NULL,
                `api_format` TEXT NOT NULL,
                `api_response` TEXT NOT NULL,
                `supplier_currency` TEXT NOT NULL,
                `supplier_markup` DECIMAL(10,2) NOT NULL,
                `supplier_transport` DECIMAL(10,2) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `SupplierIndex` (`supplier_id`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ');
    }

    private function uninstallDb($table)
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.bqSQL($table).'`');
    }

    private function registerOptionalHook($hookName)
    {
        if (!Hook::getIdByName($hookName)) {
            return true;
        }

        return $this->registerHook($hookName);
    }

    private function unregisterOptionalHook($hookName)
    {
        if (!Hook::getIdByName($hookName)) {
            return true;
        }

        return $this->unregisterHook($hookName);
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            $this->uninstallDb($this->list_table) &&
            $this->uninstallDb($this->supplier_table) &&
            $this->unregisterOptionalHook('displayNavSearchBlock') &&
            $this->unregisterHook('displaySearch') &&
            $this->unregisterOptionalHook('displayMobileSearchBlock') &&
            $this->unregisterHook('displayLeftColumn') &&
            $this->unregisterHook('actionFrontControllerSetMedia');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit_add_supplier_api')) {
            $supplierId = (int) Tools::getValue('supplier_id');
            $apiMethod = Tools::strtoupper(Tools::getValue('api_method', 'GET'));
            $apiKey = trim((string) Tools::getValue('api_key', ''));
            $apiUrl = trim((string) Tools::getValue('api_url', ''));
            $apiFormat = Tools::getValue('api_format', '');
            $apiResponse = Tools::getValue('api_response', '');
            $supplierCurrency = (int) Tools::getValue('supplier_currency');
            $supplierMarkup = Tools::getValue('supplier_markup');
            $supplierTransport = Tools::getValue('supplier_transport');

            $validationErrors = [];
            if ($supplierId <= 0) {
                $validationErrors[] = $this->l('Please select a supplier.');
            }
            if (!in_array($apiMethod, ['GET', 'POST'], true)) {
                $validationErrors[] = $this->l('Unsupported API method selected.');
            }
            if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
                $validationErrors[] = $this->l('Please provide a valid API URL (including protocol).');
            }

            $exists = (int) Db::getInstance()->getValue(
                'SELECT id FROM ' . _DB_PREFIX_ . pSQL($this->supplier_table) . ' WHERE supplier_id = ' . (int) $supplierId
            );
            if (!$exists && $apiKey === '') {
                $validationErrors[] = $this->l('An API key is required for new suppliers.');
            }

            if ($validationErrors) {
                $output .= $this->displayError(implode('<br>', $validationErrors));
            } else {
                $dbData = [
                    'api_method' => pSQL($apiMethod),
                    'api_url' => pSQL($apiUrl),
                    'api_format' => pSQL($apiFormat, true),
                    'api_response' => pSQL($apiResponse, true),
                    'supplier_currency' => pSQL((string) $supplierCurrency),
                    'supplier_markup' => number_format((float) $supplierMarkup, 2, '.', ''),
                    'supplier_transport' => number_format((float) $supplierTransport, 2, '.', ''),
                ];

                if ($apiKey !== '') {
                    $dbData['api_key'] = pSQL($apiKey);
                }

                if ($exists) {
                    Db::getInstance()->update(
                        $this->supplier_table,
                        $dbData,
                        'supplier_id = ' . (int) $supplierId
                    );
                } else {
                    $dbData['supplier_id'] = (int) $supplierId;
                    Db::getInstance()->insert($this->supplier_table, $dbData);
                }

                $output .= $this->displayConfirmation($this->l('Supplier API saved successfully.'));
            }
        }

        if ($supplierIdToDelete = Tools::getValue('delete_supplier_id')) {
            $supplierIdToDelete = (int) $supplierIdToDelete;

            $deleted = Db::getInstance()->delete($this->supplier_table, 'supplier_id = '.$supplierIdToDelete);

            $output .= $deleted
                ? $this->displayConfirmation($this->l('Supplier API deleted successfully.'))
                : $this->displayError($this->l('Could not delete the supplier API.'));
        }

        $editSupplierId = (int) Tools::getValue('edit_supplier_id');

        return $output
            . $this->renderSupplierApiForm($editSupplierId)
            . $this->renderSupplierApiList();
    }

    protected function renderSupplierApiForm($editSupplierId = 0)
    {
        $suppliers = Supplier::getSuppliers(false, $this->context->language->id);
        $supplierOptions = [];

        foreach ($suppliers as $supplier) {
            $supplierOptions[] = [
                'id_option' => $supplier['id_supplier'],
                'name' => $supplier['name'],
            ];
        }

        $currencies = Currency::getCurrencies(false);
        $currencyOptions = [];

        foreach ($currencies as $currency) {
            $currencyOptions[] = [
                'id_option' => $currency['id_currency'],
                'name' => $currency['name'] . ' (' . $currency['iso_code'] . ')',
            ];
        }

        $existing = $editSupplierId
            ? Db::getInstance()->getRow(
                'SELECT * FROM ' . _DB_PREFIX_ . pSQL($this->supplier_table) . ' WHERE supplier_id = ' . (int) $editSupplierId
            )
            : [];

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Supplier API Configuration'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    ['type' => 'select', 'label' => $this->l('Supplier'), 'name' => 'supplier_id',
                        'options' => ['query' => $supplierOptions, 'id' => 'id_option', 'name' => 'name'],
                        'hint' => $this->l('The Supplier needs to be registered in the system.'),
                    ],
                    ['type' => 'select', 'label' => $this->l('API Method'), 'name' => 'api_method',
                        'options' => ['query' => [['id_option' => 'GET', 'name' => 'GET'], ['id_option' => 'POST', 'name' => 'POST']], 'id' => 'id_option', 'name' => 'name'],
                        'hint' => $this->l('The API call is either submitted as GET or POST method.'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('API Key'),
                        'name' => 'api_key',
                        'hint' => $this->l('This is your private API key for the supplier. Keep it safe.'),
                        'required' => !$editSupplierId,
                        'desc' => $editSupplierId ? $this->l('Leave empty to keep the existing API key.') : '',
                    ],
                    ['type' => 'text', 'label' => $this->l('API URL'), 'name' => 'api_url', 'hint' => $this->l('Enter the URL for the suppliers API call.')],
                    ['type' => 'textarea', 'label' => $this->l('API Format Description'), 'name' => 'api_format', 'hint' => $this->l('Enter the API call format as described below.')],
                    ['type' => 'textarea', 'label' => $this->l('API Response Description'), 'name' => 'api_response', 'hint' => $this->l('Enter the response format as described below.')],
                    ['type' => 'select', 'label' => $this->l('Currency'), 'name' => 'supplier_currency',
                        'options' => ['query' => $currencyOptions, 'id' => 'id_option', 'name' => 'name'],
                        'hint' => $this->l('Select the suppliers currency.'),
                    ],
                    ['type' => 'text', 'label' => $this->l('Sales Markup (%)'), 'name' => 'supplier_markup', 'class' => 'fixed-width-sm',
                        'hint' => $this->l('Enter a number like 10.5 for 10.5% markup.'),
                    ],
                    ['type' => 'text', 'label' => $this->l('Supplier Transport rate (%)'), 'name' => 'supplier_transport', 'class' => 'fixed-width-sm',
                        'hint' => $this->l('Enter a number like 5.3 for average 5.3% transport cost on product price.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save'), 'name' => 'submit_add_supplier_api'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->fields_value = [
            'supplier_id' => $existing['supplier_id'] ?? '',
            'api_method' => $existing['api_method'] ?? '',
            'api_key' => '',
            'api_url' => $existing['api_url'] ?? '',
            'api_format' => $existing['api_format'] ?? '',
            'api_response' => $existing['api_response'] ?? '',
            'supplier_currency' => $existing['supplier_currency'] ?? '',
            'supplier_markup' => $existing['supplier_markup'] ?? '',
            'supplier_transport' => $existing['supplier_transport'] ?? '',
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    protected function renderSupplierApiList()
    {
        $rows = Db::getInstance()->executeS('
            SELECT a.*, s.name AS supplier_name
            FROM ' . _DB_PREFIX_ . pSQL($this->supplier_table) . ' a
            JOIN ' . _DB_PREFIX_ . 'supplier s ON s.id_supplier = a.supplier_id
            ORDER BY s.name
        ');

        if (empty($rows)) {
            return '<h3>'.$this->l('Configured Supplier APIs').'</h3><p>' . $this->l('No APIs configured yet.') . '</p>';
        }

        $html = '<h3>'.$this->l('Configured Supplier APIs').'</h3><table class="table"><thead><tr>
            <th>'.$this->l('Supplier').'</th>
            <th>'.$this->l('API Method').'</th>
            <th>'.$this->l('API URL').'</th>
            <th>'.$this->l('API Key').'</th>
            <th>'.$this->l('Actions').'</th>
        </tr></thead><tbody>';

        foreach ($rows as $row) {
            $editUrl = AdminController::$currentIndex.'&configure='.$this->name.'&edit_supplier_id='.$row['supplier_id'].'&token='.Tools::getAdminTokenLite('AdminModules');
            $deleteUrl = AdminController::$currentIndex.'&configure='.$this->name.'&delete_supplier_id='.$row['supplier_id'].'&token='.Tools::getAdminTokenLite('AdminModules');

            $confirmText = json_encode($this->l('Are you sure you want to delete this supplier API configuration?'));

            $html .= '<tr>
                <td>' . htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8') . '</td>
                <td>' . htmlspecialchars($row['api_method'], ENT_QUOTES, 'UTF-8') . '</td>
                <td>' . htmlspecialchars($row['api_url'], ENT_QUOTES, 'UTF-8') . '</td>
                <td>' . htmlspecialchars($this->maskApiKey($row['api_key']), ENT_QUOTES, 'UTF-8') . '</td>
                <td>
                    <a class="btn btn-sm btn-primary" href="' . $editUrl . '">
                        <i class="icon-pencil"></i> ' . $this->l('Edit') . '
                    </a>
                    <a class="btn btn-sm btn-danger" href="' . $deleteUrl . '" onclick="return confirm(' . $confirmText . ');">
                        <i class="icon-trash"></i> ' . $this->l('Delete') . '
                    </a>
                </td>
            </tr>';
        }

        return $html.'</tbody></table>';
    }

    public function hookDisplayNavSearchBlock($params)
    {
        return $this->displaySearchBar();
    }

    public function hookDisplaySearch($params)
    {
        return $this->displaySearchBar();
    }

    public function hookDisplayTop($params)
    {
        return $this->displaySearchBar();
    }

    public function hookDisplayMobileSearchBlock($params)
    {
        return $this->displaySearchBar();
    }

    private function maskApiKey($apiKey)
    {
        $apiKey = (string) $apiKey;
        $length = Tools::strlen($apiKey);

        if ($length === 0) {
            return '';
        }

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return Tools::substr($apiKey, 0, 4) . str_repeat('*', $length - 8) . Tools::substr($apiKey, -4);
    }

    private function displaySearchBar()
    {
        $this->context->smarty->assign(array(
            'search_action_url' => $this->context->link->getModuleLink($this->name, 'searchresults')
        ));

        $cookie = $this->context->cookie;
        $this->ensureSearchCookie($cookie, true);
        $searchData["query"] = $cookie->eind_query;
        $searchData["inStock"] = (bool) $cookie->eind_inStock;
        $searchData["rohsCompliant"] = (bool) $cookie->eind_rohsCompliant;

        $this->context->smarty->assign(array(
            'searchData' => $searchData
        ));
        Media::addJsDef([
            'eind_suppliersearch_search_url' => $this->context->link->getModuleLink($this->name, 'searchresults')
        ]);
        return $this->display(__FILE__, 'views/templates/hook/searchbar.tpl');
    }

    public function hookActionFrontControllerSetMedia()
    {
        $this->context->controller->registerStylesheet(
            'eind-suppliersearch-style',
            'modules/' . $this->name . '/views/css/eind_suppliersearch.css',
            [
                'media' => 'all',
                'priority' => 1000,
            ]
        );

        $this->context->controller->registerJavascript(
            'eind-suppliersearch-javascript',
            'modules/' . $this->name . '/views/js/eind_suppliersearch.js',
            [
                'position' => 'bottom',
                'priority' => 1000,
            ]
        );
    }

    public function hookDisplayLeftColumn($params)
    {
        return '';
    }

    /**
     * Search-session state lives in cookie fields prefixed `eind_` so this
     * module never collides with apisearchmodule's own cookie fields while
     * both are installed side by side.
     */
    public function ensureSearchCookie($cookie, bool $resetIfInvalid): bool
    {
        $now = time();
        $expiresAt = isset($cookie->eind_session_expires_at) ? (int) $cookie->eind_session_expires_at : 0;

        $historyRaw = $cookie->eind_searchHistory ?? '';
        $historyDecoded = $historyRaw === '' ? [] : json_decode((string) $historyRaw, true);
        $historyValid = $historyDecoded === null ? false : is_array($historyDecoded);

        $isSessionValid = !empty($cookie->eind_session_id) && ctype_xdigit((string) $cookie->eind_session_id);
        $isExpired = $expiresAt < $now;

        if (!$isSessionValid || $isExpired || !$historyValid) {
            if (!$resetIfInvalid) {
                return false;
            }
            $this->resetSearchCookie($cookie);
            return true;
        }

        $cookie->eind_itemsOnPage = (int) ($cookie->eind_itemsOnPage ?? 10);
        if ($cookie->eind_itemsOnPage <= 0) {
            $cookie->eind_itemsOnPage = 10;
        }

        $cookie->eind_pageOffset = max(1, (int) ($cookie->eind_pageOffset ?? 1));
        $cookie->eind_pages = max(0, (int) ($cookie->eind_pages ?? 0));
        $cookie->eind_inStock = (bool) ($cookie->eind_inStock ?? false);
        $cookie->eind_rohsCompliant = (bool) ($cookie->eind_rohsCompliant ?? false);
        $cookie->eind_searchFilter = (string) ($cookie->eind_searchFilter ?? '');
        $cookie->eind_query = (string) ($cookie->eind_query ?? '');
        $cookie->eind_preSkuQuery = (string) ($cookie->eind_preSkuQuery ?? '');
        $cookie->eind_preSkuPageOffset = max(1, (int) ($cookie->eind_preSkuPageOffset ?? 1));
        $cookie->eind_preSkuTerm = (string) ($cookie->eind_preSkuTerm ?? 'any');
        $cookie->eind_prePnuQuery = (string) ($cookie->eind_prePnuQuery ?? '');
        $cookie->eind_prePnuPageOffset = max(1, (int) ($cookie->eind_prePnuPageOffset ?? 1));
        $cookie->eind_prePnuTerm = (string) ($cookie->eind_prePnuTerm ?? 'any');
        $cookie->eind_pnuTrack = (bool) ($cookie->eind_pnuTrack ?? false);
        $cookie->eind_searchHistory = json_encode($historyDecoded);

        $cookie->eind_session_expires_at = $now + self::SEARCH_COOKIE_TTL;

        return true;
    }

    private function resetSearchCookie($cookie): void
    {
        $cookie->eind_session_id = bin2hex(random_bytes(16));
        $cookie->eind_itemsOnPage = (int) 10;
        $cookie->eind_pageOffset = (int) 1;
        $cookie->eind_pages = (int) 0;
        $cookie->eind_query = '';
        $cookie->eind_inStock = (bool) false;
        $cookie->eind_rohsCompliant = (bool) false;
        $cookie->eind_searchFilter = '';
        $cookie->eind_preSkuQuery = '';
        $cookie->eind_preSkuPageOffset = (int) 1;
        $cookie->eind_preSkuTerm = 'any';
        $cookie->eind_prePnuQuery = '';
        $cookie->eind_prePnuPageOffset = (int) 1;
        $cookie->eind_prePnuTerm = 'any';
        $cookie->eind_pnuTrack = false;
        $cookie->eind_productAnchor = '';
        $cookie->eind_searchHistory = json_encode([]);
        $cookie->eind_session_expires_at = time() + self::SEARCH_COOKIE_TTL;
    }
}
