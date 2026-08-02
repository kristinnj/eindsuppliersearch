<?php

/* callsupplierapi.php */

require_once(_PS_ROOT_DIR_ . '/modules/eind_suppliersearch/controllers/front/jsontoarray.php');

class EindCallSupplierApi
{

    public function __construct()
    {

    }

    // Function to search for products at Farnell using API
    function querySuppliers($criteria, $itemsOnPage, $pageOffset, $searchTerm = "any", $searchFilter = "")
    {
        $this->logDebug(sprintf('EindCallSupplierApi->querySuppliers (page %d, criteria %s)', $pageOffset, $criteria));
        $jsonFunctions = new EindConvertJsonToProductArray;

        // Get all the suppliers with API service
        $apiSuppliers = $this->getAllApiSuppliers();

        // Itterate throug all the suppliers
        foreach ($apiSuppliers as $apiSupplier)
        {
            // Store the supplier parameters
            $supplierResponseFormat = json_decode($apiSupplier['api_response'], true);

            // Get the supplier name
            $currentSupplier = new Supplier($apiSupplier['supplier_id']);
            if (Validate::isLoadedObject($currentSupplier)) {
                $supplierName = $currentSupplier->name;
            } else {
                $supplierName = Context::getContext()->getTranslator()->trans(
                    'Unknown supplier',
                    [],
                    'Modules.Eindsuppliersearch.Supplier'
                );
            }

            // Prepare the API call according to the submit method and the API format
            switch ($apiSupplier['api_method']) {
                case "GET":
                    // Add the relevant parameters to the API Script
                    $apiSubmitString = str_replace("{term}", urlencode($searchTerm), $apiSupplier['api_format']);
                    $apiSubmitString = str_replace("{criteria}", urlencode($criteria), $apiSubmitString);
                    $apiSubmitString = str_replace("{pageOffset}", $pageOffset, $apiSubmitString);
                    $apiSubmitString = str_replace("{itemsOnPage}", $itemsOnPage, $apiSubmitString);
                    $apiSubmitString = str_replace("{searchFilter}", $searchFilter, $apiSubmitString);
                    $apiSubmitString = str_replace("{apiKey}", urlencode($apiSupplier['api_key']), $apiSubmitString);
                    $apiSubmitString = $apiSupplier['api_url'] . $apiSubmitString;

                    $this->logDebug(sprintf(
                        'Requesting supplier %s (%d) page %d',
                        $supplierName,
                        (int) $apiSupplier['supplier_id'],
                        (int) $pageOffset
                    ));

                    try {
                        // Initialize cURL
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $apiSubmitString);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);

                        // Log the raw API response
                        $this->logDebug(sprintf(
                            'querySuppliers - %s returned %d bytes',
                            $supplierName,
                            Tools::strlen($response)
                        ));

                        if ($response === false) {
                            // Log cURL error
                            throw new Exception("Error fetching data from API: " . curl_error($ch));
                        }

                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        if ($httpCode != 200) {
                            throw new Exception("Received HTTP code $httpCode from API");
                        }

                        curl_close($ch);

                        // Convert json responce to standard product array
                        $data[$apiSupplier['supplier_id']] = $jsonFunctions->supplierToStandard($response, $supplierResponseFormat, $apiSupplier);
                        $data[$apiSupplier['supplier_id']]["SupplierName"] = $supplierName;
                        $data[$apiSupplier['supplier_id']]["PageOffset"] = $pageOffset;
                        $data[$apiSupplier['supplier_id']]["ItemsOnPage"] = $itemsOnPage;
                        $data[$apiSupplier['supplier_id']]["NumberOfPages"] = round(($data[$apiSupplier['supplier_id']]["NumberOfResults"] / $itemsOnPage) + 0.5);

                    } catch(Exception $e) {
                        $data[$apiSupplier['supplier_id']] = false;
                        $this->logDebug(sprintf(
                            'Caught exception calling API for %s: %s',
                            $supplierName,
                            $e->getMessage()
                        ));
                    }
                    break;
                case "PUSH":
                    $this->logDebug('PUSH method not implemented for supplier API calls.');
                    break;
            }
        }

        $this->logDebug('EindCallSupplierApi->querySuppliers ending');
        return $data;
    }

    public function getAllApiSuppliers() {
        // Get all the available suppliers that offer search API
        $this->logDebug('EindCallSupplierApi->getAllApiSuppliers called');
        $module = Module::getInstanceByName('eind_suppliersearch');                      // Get the name of the table that stores the list of API services
        $tableName = _DB_PREFIX_.pSQL($module->supplier_table);

        $sql = 'SELECT * FROM `' . $tableName . '`';                                // Get all the records from the Db table
        $results = Db::getInstance()->executeS($sql);

        $this->logDebug('EindCallSupplierApi->getAllApiSuppliers ending');
        return $results;
    }

    private function logDebug(string $message): void
    {
        if (_PS_MODE_DEV_) {
            error_log('[EindSupplierSearch] ' . $message);
        }
    }

}
