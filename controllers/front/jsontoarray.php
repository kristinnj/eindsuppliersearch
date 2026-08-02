<?php

/* jsontoarray.php */

class EindConvertJsonToProductArray
{
    public function __construct()
    {

    }

    private function fixJsonData (string $faultyJson, array $formatSection): string
    // Makes corrections to json data according to format information
    // Active corrections are for: DulicateKeys
    // INPUT:
    //          $faultyJson     >   Corrupt json data in a string
    //          &fromatSection  >   Section of the supplier format containing informarion on how to mend the data
    // OUTPUT:
    //          $fixedJson      >   Corrected json formatted data in a string
    // -------------------------------------------------------------
    {
        error_log("EindConvertJsonToProductArray->fixJsonData called -----------------------------------------------");
        foreach ($formatSection as $faultArray) {
            switch ($faultArray["ErrorType"]) {
                case "DuplicateKeys":
                    // The name of the section that contains duplicate keys?
                    $sectionKey = $faultArray["ErrorKey"];
                    $keyEnd = -1;
                    do {
                        // Where does next section in the main data start?
                        $keyStart = strpos($faultyJson, $sectionKey, $keyEnd + 1);
                        if ($keyStart) {        //
                            // ..and where does it end?
                            $keyEnd = strpos ($faultyJson, "}", $keyStart + 1);
                            // Extract this portion and fix
                            $toBeFixed = substr($faultyJson, $keyStart-1, $keyEnd - $keyStart + 2);
                            // At the start change '{' into '[{'
                            $toBeFixed = str_replace("{", "[{", $toBeFixed);
                            // At the end change '}' into '}]'
                            $toBeFixed = str_replace("}", "}]", $toBeFixed);
                            // Find every '",' and change into '"}, {'
                            $toBeFixed = str_replace('",', '"}, {', $toBeFixed);

                            // Replace the faulty data in the json string
                            $faultyJson = substr($faultyJson, 0, $keyStart -1) . $toBeFixed . substr($faultyJson, $keyEnd + 1, strlen($faultyJson));
                        }
                    } while ($keyStart);
                    break;
                default:
            }
        }
        error_log("EindConvertJsonToProductArray->fixJsonData ending -----------------------------------------------");
        return $faultyJson;
    }

    public function supplierToStandard (string $jsonData, array $supplierFormat, $supplier)
    // Reads data from supplier according to the supplier format and stores
    // it in an array
    // INPUT:
    //          $jsonData =         string data returned by API call
    //          $responseFormat =   array containing the format of the supplier data
    //          $supplier =         array containing supplierinformation
    // OUTPUT:
    //          $productList        An array formatted to the standard product list
    // --------------------------------------------------------------------
    {
        error_log("EindConvertJsonToProductArray->supplierToStandard called ----------------------------------------");
        if (isset($supplierFormat["JsonErrors"])) {
            // Fix the faults in the json data
            $jsonData = $this->fixJsonData($jsonData, $supplierFormat["JsonErrors"] );
        }
        $productList = [];

        // Decode the json data
        $data = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $productList["ErrorsArray"] = "❌ JSON Error: " . json_last_error_msg();
            return $productList;
        } else {
            $productList["ErrorsArray"] = false;
        }


        // Get number of results
        $searchReturnKey = "";
        foreach ($supplierFormat["ResponseArray"] as $value) {
            if ($value["ResponseName"] == array_key_first($data)) {
                $searchReturnKey = $value["ResponseName"];
                break;
            }
        }

        // ProductList contains the following additonal items for paging and filtering:
        $productList["NumberOfResults"] = $data[$searchReturnKey][$supplierFormat["NumberOfResults"]];
        $productList["ItemsOnPage"] = 25; //Default (integer)
        $productList["PageOffset"] = 1; //Default (integer)
        $productList["NumberOfPages"] = 1; //Default (integer)
        $productList["RohsFilter"] = false; //Default (boolean)
        $productList["InStockFilter"] = false; //Default (boolean)
        $productList["Query"] = ""; (string)

        // Get the VAT rate for product list prices
        $vat = $this->getVatRate();
        $productList["TaxRate"] = $vat;

        // Use the product format to read the supplier data
        $productFormat = $supplierFormat["Products"];

        // Get the products array from the data
        $supplierProducts = $data[$searchReturnKey][$productFormat["ColumnName"]];

        // Get the currency rate to convert prices from supplier's to customer's selected currency
        $defaultCurrencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $selectedCurrency = Context::getContext()->currency;
        $currencyRate = $this->getCurrencyConversionRate($supplier['supplier_currency'] ,$selectedCurrency->id);

        // Write the data from the supplier to a standard product array
        $standardProduct = [];
        $counter = 0;
        foreach ($supplierProducts as $product) {
            $counter++;
            $standardProduct["OrderCode"] = $product[$productFormat["OrderCode"]] ?? "Unknown";
            $standardProduct["ProductName"] = $product[$productFormat["ProductName"]] ?? "Unknown";
            $standardProduct["StockStatus"] = $product[$productFormat["StockStatus"]] ?? 0;
            if (isset($product[$productFormat["RohsCompliant"]])) {
                $standardProduct["RohsCompliant"] = ($product[$productFormat["RohsCompliant"]] == $productFormat["RohsCode"]) ? true : false;
            } else {
                $standardProduct["RohsCompliant"] = false;
            }
            $standardProduct["PacketSize"] = $product[$productFormat["PacketSize"]] ?? "Unknown";
            $standardProduct["Unit"] =  $product[$productFormat["Unit"]] ?? "Unknown";

            // Get image or images if many
            $imageFormat = $productFormat["Images"];
            $standardImages = [];
            switch ($imageFormat["SingleImage"]) {
                case "true":
                // There is only one image
                    $imageSection = $imageFormat["ColumnName"];
                    if (isset($product[$imageSection])) {
                        $productImage = $product[$imageFormat["ColumnName"]];
                        $standardImages = [];
                        if ($imageFormat["PrimaryURL"] != "") {
                        // Images are made of PrimaryURL and ImageName
                            $image["PrimaryURL"] = $imageFormat["PrimaryURL"] . $productImage[$imageFormat["ImageName"]];
                            $image["SecondaryURL"] = $imageFormat["SecondaryURL"] . $productImage[$imageFormat["ImageName"]];
                        } else {
                        // Images are stored in FullImageURL
                            $image["PrimaryURL"] = $productImage[$imageFormat["FullImageURL"]];
                            $image["SecondaryURL"] = "";
                        }
                        $image["ThumbNailURL"] = $productImage[$imageFormat["ThumbNailURL"]] ?? "";
                        $standardImages[] = $image;
                    }
                    break;
                default:
                // There are multiple images
                    foreach ($product[$imageFormat["ColumnName"]] as $productImage) {
                        if ($imageFormat["PrimaryURL"] != "") {
                        // Images are made of PrimaryURL and Image
                            $image["PrimaryURL"] = $imageFormat["PrimaryURL"] . $productImage[$imageFormat["ImageName"]];
                            $image["SecondaryURL"] = $imageFormat["SecondaryURL"] . $productImage[$imageFormat["ImageName"]];
                        } else {
                        // Images are stored in FullImageURL
                            $image["PrimaryURL"] = $productImage[$imageFormat["FullImageURL"]];
                            $image["SecondaryURL"] = "";
                        }
                        $image["ThumbNailURL"] = $productImage[$imageFormat["ThumbNailURL"]] ?? "";
                        $standardImages[] = $image;
                    }
            }
            // Store the images
            $standardProduct["Images"] = $standardImages;

            // Get datasheets
            $datasheetFormat =  $productFormat["Datasheets"];
            $standardSheets = [];
            if (isset($product[$datasheetFormat["ColumnName"]])) {
                switch ($datasheetFormat["SingleSheet"]) {
                    case "true":
                    // Only one sheet
                        $dataSheet = $product[$datasheetFormat["ColumnName"]];
                        if ($datasheetFormat["Sheet"] != "") {
                        // Sheet url is based on two factors
                            $sheet["SheetUrl"] = $dataSheet[$datasheetFormat["FixedURL"]] . $dataSheet[$datasheetFormat["Sheet"]];
                        } else {
                        // Sheet url is based on one factor
                            $sheet["SheetUrl"] = $dataSheet[$datasheetFormat["SheetURL"]];
                        }
                        $sheet["SheetName"] = $dataSheet[$datasheetFormat["SheetName"]] ?? "";
                        $standardSheets[] = $sheet;
                        break;
                    default:
                    // Multiple sheets
                        foreach ($product[$datasheetFormat["ColumnName"]] as $dataSheet) {
                            if ($datasheetFormat["Sheet"] != "") {
                            // Sheet url is based on two factors
                                $sheet["SheetUrl"] = $dataSheet[$datasheetFormat["FixedURL"]] . $dataSheet[$datasheetFormat["Sheet"]];
                            } else {
                            // Sheet url is based on one factor
                                $sheet["SheetUrl"] = $dataSheet[$datasheetFormat["SheetURL"]];
                            }
                            $sheet["SheetName"] = $dataSheet[$datasheetFormat["SheetName"]];
                            $standardSheets[] = $sheet;
                        }
                }
                // Store the datasheets
                $standardProduct["Datasheets"] = $standardSheets;
            }

            // Get product information
            $informationFormat = $productFormat["ProductInformation"];
            if (isset($product[$informationFormat["ColumnName"]])) {
                $standardInformation = [];
                // Itterate throug the ProductInformation
                foreach ($product[$informationFormat["ColumnName"]] as $infoValue) {
                    $currentKey = array_search(array_key_first($infoValue), $informationFormat, true);
                    if ($currentKey != "") {
                        $standardInformation[$currentKey][] = $infoValue[$informationFormat[$currentKey]];
                    }
                }
                $standardProduct["ProductInformation"] = $standardInformation;
            }

            // Get prices
            $priceFormat = $productFormat["Prices"];
            $standardPrice = [];
            switch ($priceFormat["SinglePrice"]) {
                case "true":
                    $productPrice = $product[$priceFormat["ColumnName"]];
                    $price["SupplierPrice"] = $productPrice[$priceFormat["Price"]];
                    $price["ProductPrice"] = $price["SupplierPrice"] / (1 - $supplier["supplier_markup"] / 100) * (1 + $supplier["supplier_transport"] / 100);
                    $price["From"] = $productPrice[$priceFormat["Quantity"]];
                    $price["LocalPrice"] = round($price["ProductPrice"] * $currencyRate, 2);
                    $price["LocalVATPrice"] = round($price["CurrencyPrice"] * (1 + $vat / 100), 2);
                    $standardPrice[] = $price;
                    break;
                default:
                foreach ($product[$priceFormat["ColumnName"]] as $productPrice) {
                    $price["SupplierPrice"] = $productPrice[$priceFormat["Price"]];
                    $price["ProductPrice"] = $price["SupplierPrice"] / (1 - $supplier["supplier_markup"] / 100) * (1 + $supplier["supplier_transport"] / 100);
                    $price["From"] = $productPrice[$priceFormat["Quantity"]];
                    $price["LocalPrice"] = round($price["ProductPrice"] * $currencyRate, 2);
                    $price["LocalVATPrice"] = round($price["LocalPrice"] * (1 + $vat / 100), 2);
                    $standardPrice[] = $price;
                }
                $standardProduct["Prices"] = $standardPrice;
            }

            // Various parameters
            $standardProduct["NumberInStock"] = $product[$productFormat["NumberInStock"]] ?? 0;
            $standardProduct["ManufacturerName"] = $product[$productFormat["ManufacturerName"]] ?? "Unknown";
            $standardProduct["ManufacturerLogo"] = $product[$productFormat["ManufacturerLogo"]] ?? "Unknown";
            $standardProduct["BrandName"] = $product[$productFormat["BrandName"]] ?? "Unknown";
            $standardProduct["ManufacturerPartNumber"] = $product[$productFormat["ManufacturerPartNumber"]] ?? "Unknown";
            $standardProduct["MinimumOrderQty"] = $product[$productFormat["MinimumOrderQty"]] ?? "Unknown";
            $namePreface = $standardProduct["ManufacturerName"] . " - " . $standardProduct["ManufacturerPartNumber"];
            if (strpos($standardProduct["ProductName"], $namePreface ) >= 0) {
                $standardProduct["DisplayText"] = substr($standardProduct["ProductName"], strlen($namePreface) + 3);
            } else {
                $standardProduct["DisplayText"] = $standardProduct["ProductName"];
            }


            //    "ProductAttributes": {
            if (isset($productFormat["ProductAttributes"])) {
                $attributeFormat = $productFormat["ProductAttributes"];
                $standardAttribute = [];
                foreach ($product[$attributeFormat["ColumnName"]] as $productAttribute) {
                    $attribute["Label"] = $productAttribute[$attributeFormat["Label"]];
                    $attribute["Value"] = $productAttribute[$attributeFormat["Value"]];
                    $attribute["Unit"] = $productAttribute[$attributeFormat["Unit"]] ?? "";
                    $standardAttribute[] = $attribute;
                }
                $standardProduct["Attributes"] = $standardAttribute;
            }

            // Stock status

            if (isset($productFormat["Stock"])) {
                $stockFormat = $productFormat["Stock"];
                $productStock = $product[$stockFormat["ColumnName"]];
                $stock["Level"] = $productStock[$stockFormat["Level"]];
                $stock["Status"] = $productStock[$stockFormat["Status"]];
                $standardProduct["Stock"] = $stock;
            }

            // Various information
            $standardProduct["CountryOfOrigin"] = $product[$productFormat["CountryOfOrigin"]] ?? "Unknown";
            $standardProduct["ImportTaxCode"] = $product[$productFormat["ImportTaxCode"]] ?? "Unknown";
            $standardProduct["OrderMultiples"] = $product[$productFormat["OrderMultiples"]] ?? "Unknown";
            $standardProduct["Package"] = $product[$productFormat["Package"]] ?? "Unknown";

            // Restrictions on product delivery
            if (isset($productFormat["Restriction"])) {
                $restrictionFormat = $productFormat["Restriction"];
                $standardProduct["Restriction"] = (bool) false;
                if ($restrictionFormat["Array"] === "true") {
                    foreach ($standardProduct[$restrictionFormat["ColumnName"]] as $restriction) {
                        if ($restriction[$restrictionFormat["Key"]] === $restrictionFormat["KeyValue"] and
                            $restriction[$restrictionFormat["ValueKey"]] === $restrictionFormat["Value"]) {
                            $standardProduct["Restriction"] = (bool) true;
                            break;
                        }
                    }
                }
                else {
                    $standardProduct["Restriction"] = $standardProduct[$productFormat["Restriction"]] ?? false;
                }
            }

            // Store product to product list
            $productList["Products"][] = $standardProduct;
        }
        error_log("EindConvertJsonToProductArray->supplierToStandard ending ----------------------------------------");
        return $productList;
    }


    private function getCurrencyConversionRate($supplierCurrency, $customerCurrency) {
        error_log("EindConvertJsonToProductArray->getCurrencyConversionRate called ---------------------------------");
        $currencies = Currency::getCurrencies(false); // false = exclude deleted ones
        foreach ($currencies as $currency) {
            switch ($currency['id_currency']) {
                case $supplierCurrency:
                    $sourceRate = $currency['conversion_rate'];
                case $customerCurrency:
                    $targetRate = $currency['conversion_rate'];
            }
        }
        $converted = $targetRate / $sourceRate;
        error_log("EindConvertJsonToProductArray->getCurrencyConversionRate ending ---------------------------------");
        return $converted;
    }

    // Function to format numbers #.###
    private function numFormat($value)
    {
        $v = trim(strval($value));
        $res = "";
        while (strlen($v) > 3) {
            $res = "." . substr($v, -3) . $res;
            $v = substr($v, 0, -3);
        }
        $res = $v . $res;
        return $res;
    }

    private function getVatRate() {
        error_log("EindConvertJsonToProductArray->getVatRate called ------------------------------------------------");
        // 1. Get default country
        $defaultCountryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');

        // 2. Create a virtual address for tax evaluation
        $address = new Address();
        $address->id_country = $defaultCountryId;
        $address->id_state = 0;
        $address->postcode = ''; // Can be omitted unless needed

        // 3. Get a default tax rules group
        $defaultTaxRulesGroupId = 1;

        // 4. Get the tax rate
        $taxCalculator = TaxManagerFactory::getManager($address, $defaultTaxRulesGroupId)->getTaxCalculator();
        $taxRate = $taxCalculator->getTotalRate();
        error_log("EindConvertJsonToProductArray->getVatRate ending ------------------------------------------------");
        return $taxRate;
    }

}
