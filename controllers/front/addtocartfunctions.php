<?php

/* addtocartfunctions.php */

class EindAddToCartFunctions
{
    const CURLOPT_URL                       = 10002;
    const CURLOPT_RETURNTRANSFER            = 19913;

    /**
     * Allow remote media downloads only from trusted hosts.
     *
     * Extend this list if additional suppliers are added.
     *
     * @var string[]
     */
    private $allowedImageHosts = [
        'farnell.com',
    ];

    // ------------------------- IN USE ----------------------------------------
    // Function to download image from url and add to product -----------------+
    // Returns true if successfull or false if not
    function addProductImage($id_product, $imageUrl, $extension = 'jpg') {
        if (empty($imageUrl) || !$this->isAllowedImageUrl($imageUrl)) {
            return false;
        }

        $image = new Image();
        $image->id_product = (int)$id_product;
        $image->position = (int)Image::getHighestPosition($id_product) + 1;
        $image->cover = Image::getCover($id_product) ? 0 : 1;

        if (!$image->add()) {
            return false;
        }

        $tmpFile = $this->downloadImageToTmp($imageUrl);
        if (!$tmpFile) {
            $image->delete();
            return false;
        }

        $destination = $image->getPathForCreation() . '.' . $extension;
        if (!@rename($tmpFile, $destination)) {
            @unlink($tmpFile);
            $image->delete();
            return false;
        }

        $imagesTypes = ImageType::getImagesTypes('products');
        foreach ($imagesTypes as $imageType) {
            $thumbDestination = $image->getPathForCreation() . '-' . stripslashes($imageType['name']) . '.' . $extension;
            if (!ImageManager::resize($destination, $thumbDestination, (int)$imageType['width'], (int)$imageType['height']) && _PS_MODE_DEV_) {
                $this->debugLog('addProductImage: Failed to generate thumbnail for type: ' . $imageType['name']);
            }
        }

        return true;
    }


    // ------------------------------IN USE-------------------------------------
    // Function to add speciffic prices ---------------------------------------+
    // Returns: true or false
    function addSpecifficPrice($productId, $qtyFrom, $price)
    {
        $this->debugLog('1 Function addSpecifficPrice -----------------------');
        // Create a new specific price
        $specificPrice = new SpecificPrice();

        $specificPrice->id_product = $productId;        // Product id to use
        $specificPrice->id_shop = 1;                    // Shop ID, usually 1 for default
        $specificPrice->id_currency = 0;                // Currency ID, 0 for default, 4 for GBP
        $specificPrice->id_country = 0;                 // Country ID, 0 for default
        $specificPrice->id_group = 0;                   // Customer group ID, 0 for default
        $specificPrice->id_customer = 0;                // Customer ID, 0 for default
        $specificPrice->price = $price;                 // Specific price
        $specificPrice->from_quantity = $qtyFrom;       // Minimum quantity to apply this price
        $specificPrice->reduction = 0;                  // Reduction amount
        $specificPrice->reduction_type = 'amount';      // Reduction type ('amount' or 'percentage')
        $specificPrice->from = '0000-00-00 00:00:00';   // Start date
        $specificPrice->to = '0000-00-00 00:00:00';     // End date

        // Save the specific price
        return $specificPrice->add();
    }

    /**
     * Update core product data (price/reference/tax rule) when the product already exists.
     */
    function updateExistingProduct($productId, array $productData, int $taxRule): bool
    {
        $product = new Product((int) $productId);
        if (!Validate::isLoadedObject($product)) {
            $this->debugLog('updateExistingProduct: product #' . $productId . ' not found.');
            return false;
        }

        if (!empty($productData['Prices'][0]['LocalPrice'])) {
            $product->price = (float) $productData['Prices'][0]['LocalPrice'];
        }

        if ($taxRule > 0) {
            $product->id_tax_rules_group = (int) $taxRule;
        }

        if (!empty($productData['ManufacturerPartNumber'])) {
            $product->reference = (string) $productData['ManufacturerPartNumber'];
        }

        if (!empty($productData['OrderCode'])) {
            $product->supplier_reference = (string) $productData['OrderCode'];
        }

        if (!empty($productData['ManufacturerName'])) {
            $idManufacturer = $this->getOrCreateManufacturerId((string) $productData['ManufacturerName']);
            if ($idManufacturer > 0) {
                $product->id_manufacturer = $idManufacturer;
            }
        }

        $result = (bool) $product->update();
        if (!$result) {
            $this->debugLog('updateExistingProduct: failed to update product #' . $productId);
        }

        return $result;
    }

    /**
     * Find the local Manufacturer matching the supplier's manufacturer name,
     * creating it if it doesn't exist yet. Supplier data uses "Unknown" as a
     * placeholder when the field is missing (see EindConvertJsonToProductArray),
     * which is deliberately not treated as a real manufacturer name.
     *
     * @return int id_manufacturer, or 0 if the name is empty/unusable
     */
    function getOrCreateManufacturerId(string $manufacturerName): int
    {
        $manufacturerName = trim($manufacturerName);
        if ($manufacturerName === '' || $manufacturerName === 'Unknown') {
            return 0;
        }

        $existingId = Manufacturer::getIdByName($manufacturerName);
        if ($existingId) {
            return (int) $existingId;
        }

        $manufacturer = new Manufacturer();
        $manufacturer->name = $manufacturerName;
        $manufacturer->active = 1;

        if (!$manufacturer->add()) {
            $this->debugLog('getOrCreateManufacturerId: failed to create manufacturer "' . $manufacturerName . '"');

            return 0;
        }

        return (int) $manufacturer->id;
    }

    /**
     * Replace all specific prices for a product with the latest supplier tiers.
     */
    function syncSpecificPrices($productId, array $prices): void
    {
        Db::getInstance()->delete('specific_price', 'id_product = ' . (int) $productId);

        if (count($prices) <= 1) {
            return;
        }

        foreach ($prices as $priceRow) {
            if (!isset($priceRow['From'], $priceRow['LocalPrice'])) {
                continue;
            }

            $this->addSpecifficPrice(
                (int) $productId,
                (int) $priceRow['From'],
                (float) $priceRow['LocalPrice']
            );
        }
    }


    // ----------------------------IN USE---------------------------------------
    // Function to add product to cart ----------------------------------------+
    // Returns:
    function addToCart($productID, $quantity, $productAttributeID)
    {
        $this->debugLog('1 Function addToCart -------------------------------------');
        $context = Context::getContext();
        $cart = $context->cart;
        // Check if the cart exists, if not create a new one
        if (!$cart->id) {
            $cart->id_shop = (int)$context->shop->id;
            $cart->id_lang = (int)$context->language->id;
            if ($context->customer->id) {
                $cart->id_customer = (int)$context->customer->id;
            }
            $cart->add();
            $context->cookie->id_cart = $cart->id;
            $context->cookie->write();
            $this->debugLog('2 addToCart; New Cart ID: ' . $cart->id);
            $cart = new Cart((int)$cart->id); // Reload the cart
        } else {
            $this->debugLog('3 addToCart; Existing Cart ID: ' . $cart->id);
        }

        // Log product stock
        $product = new Product($productID);
        $this->debugLog("4 addToCart; Product stock quantity: " . $product->quantity);

        // Add the product to the cart
        $this->debugLog("5 addToCart; quantity: " . $quantity . 'ProductID: ' . $productID . 'productAttributeID: ' . $productAttributeID);
        $result = $cart->updateQty($quantity, $productID, $productAttributeID, false, 'up', 0, null, false);
        $this->debugLog("6 addToCart; Product cart update: " . $result);
        if ($result) {
            $this->debugLog("7 addToCart; Product added to cart successfully.");
        } else {
            $this->debugLog("8 addToCart; Failed to add product to cart.");
        }

        // Save cart
        $cart->update();
        $context->cookie->id_cart = (int)$cart->id;
        $context->cookie->write();

        return $result;
    }


    // ---------------------------IN USE----------------------------------------
    // Function to get product_id by supplier_reference -----------------------+
    // Returns:   product id or false if not found
    function getIdBySupplierReference($supplierReference)
    {
        $sql = 'SELECT id_product FROM ' . _DB_PREFIX_ . pSQL('product') . ' WHERE supplier_reference = \'' . pSQL($supplierReference) . '\'';
        return Db::getInstance()->getValue($sql);
    }


    // ---------------------------IN USE----------------------------------------
    // Function to get the product list for the session -----------------------+
    // Returns: Product list or false if none existing
    function getProductBySessionId($sessionId, $supplierId, $productKey, $orderCode = null)
    {
        $this->debugLog('1 getProductBySessionID; SessionID: ' . $sessionId . ', SupplierID: ' . $supplierId . ', Key - ' . $productKey);

        $sql = 'SELECT product_list FROM ' . _DB_PREFIX_ . pSQL(Eindsuppliersearch::SEARCH_RESULTS_TABLE) . ' WHERE session_id = \'' . pSQL($sessionId) . '\'';
        $result = Db::getInstance()->getValue($sql);
        $this->debugLog('2 getProductBySessionId; $result: ' . print_r($result, true));
        if (!$result) {                                                                 // If there is no recort return false
            $this->debugLog('3 getProductBySessionID: FALSE');
            return false;
        }
        $data = json_decode($result, true);                                             // Json Decode the data
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->debugLog('getProductBySessionId; json_decode failed: ' . json_last_error_msg());
            return false;
        }

        $rawSupplierId = (string)$supplierId;
        $rawProductKey = (string)$productKey;
        $intSupplierId = ctype_digit($rawSupplierId) ? (int)$rawSupplierId : null;
        $intProductKey = ctype_digit($rawProductKey) ? (int)$rawProductKey : null;

        // Resolve supplier bucket using multiple key forms
        $supplierBuckets = [];
        if (isset($data[$rawSupplierId])) {
            $supplierBuckets[] = $data[$rawSupplierId];
        }
        if ($intSupplierId !== null && isset($data[$intSupplierId])) {
            $supplierBuckets[] = $data[$intSupplierId];
        }
        if (!$supplierBuckets) {
            foreach ($data as $key => $supplier) {
                if (!is_array($supplier)) {
                    continue;
                }
                if (isset($supplier['SupplierId']) && (string)$supplier['SupplierId'] === $rawSupplierId) {
                    $supplierBuckets[] = $supplier;
                    break;
                }
            }
        }

        $supplierProducts = null;
        if ($supplierBuckets) {
            foreach ($supplierBuckets as $bucket) {
                if (isset($bucket['Products']) && is_array($bucket['Products'])) {
                    $supplierProducts = $bucket['Products'];
                    break;
                }
            }
        } else {
            $this->debugLog('getProductBySessionId; supplier ' . $rawSupplierId . ' missing from cached results, will search globally.');
        }

        if (isset($supplierProducts[$rawProductKey])) {
            $product = $supplierProducts[$rawProductKey];
            $this->debugLog('4 getProductBySessionId; matched raw key. product.OrderCode: ' . $product["OrderCode"]);
            return $product;
        }

        if ($intProductKey !== null && isset($supplierProducts[$intProductKey])) {
            $product = $supplierProducts[$intProductKey];
            $this->debugLog('4 getProductBySessionId; matched int key. product.OrderCode: ' . $product["OrderCode"]);
            return $product;
        }

        $targetOrderCode = $orderCode ? (string)$orderCode : null;

        foreach ($supplierProducts as $idx => $product) {
            if (!is_array($product)) {
                continue;
            }
            if (isset($product['OrderCode']) && (string)$product['OrderCode'] === $rawProductKey) {
                $this->debugLog('getProductBySessionId; fallback matched OrderCode ' . $product['OrderCode'] . ' at index ' . $idx);
                return $product;
            }
            if ($targetOrderCode !== null && isset($product['OrderCode']) && (string)$product['OrderCode'] === $targetOrderCode) {
                $this->debugLog('getProductBySessionId; fallback matched explicit orderCode ' . $product['OrderCode'] . ' at index ' . $idx);
                return $product;
            }
        }

        // Last resort: search all suppliers in the cached payload
        foreach ($data as $sid => $supplier) {
            if (!is_array($supplier) || !isset($supplier['Products']) || !is_array($supplier['Products'])) {
                continue;
            }

            if (isset($supplier['Products'][$rawProductKey])) {
                $product = $supplier['Products'][$rawProductKey];
                $this->debugLog('getProductBySessionId; global fallback matched raw key under supplier ' . $sid);
                return $product;
            }

            if ($intProductKey !== null && isset($supplier['Products'][$intProductKey])) {
                $product = $supplier['Products'][$intProductKey];
                $this->debugLog('getProductBySessionId; global fallback matched int key under supplier ' . $sid);
                return $product;
            }

            foreach ($supplier['Products'] as $idx => $product) {
                if (!is_array($product)) {
                    continue;
                }
                if (isset($product['OrderCode']) && (string)$product['OrderCode'] === $rawProductKey) {
                    $this->debugLog('getProductBySessionId; global fallback matched OrderCode ' . $product['OrderCode'] . ' under supplier ' . $sid . ' at index ' . $idx);
                    return $product;
                }
                if ($targetOrderCode !== null && isset($product['OrderCode']) && (string)$product['OrderCode'] === $targetOrderCode) {
                    $this->debugLog('getProductBySessionId; global fallback matched explicit orderCode ' . $product['OrderCode'] . ' under supplier ' . $sid . ' at index ' . $idx);
                    return $product;
                }
            }
        }

        $this->debugLog('getProductBySessionId; product key raw ' . $rawProductKey . ' missing for supplier ' . $rawSupplierId);
        return false;
    }

    private function isAllowedImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);
        foreach ($this->allowedImageHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function downloadImageToTmp(string $url): ?string
    {
        $tmpFile = tempnam(_PS_TMP_IMG_DIR_, 'esm');
        if (!$tmpFile) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'EindSupplierSearch/1.0');

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch) || $httpCode !== 200) {
            curl_close($ch);
            @unlink($tmpFile);
            return null;
        }
        curl_close($ch);

        if (file_put_contents($tmpFile, $data) === false) {
            @unlink($tmpFile);
            return null;
        }

        $mime = mime_content_type($tmpFile);
        if ($mime === false || strpos($mime, 'image/') !== 0) {
            @unlink($tmpFile);
            return null;
        }

        return $tmpFile;
    }

    private function debugLog(string $message): void
    {
        if (_PS_MODE_DEV_) {
            error_log('[EindSupplierSearch] ' . $message);
        }
    }


    // ------------------------- IN USE ----------------------------------------
    // Function to insert new product using -----------------------------------+
    // Returns: Product ID or false if error
    function insertNewProduct($productData, $supplierId, $taxRule, $category)
    {
        $this->debugLog('1 Insert New Product ------------------------------------');

        // Get all active languages (optionally pass the current shop ID in multishop)
        $languages = \Language::getLanguages(false /* active only */);

        $names            = [];
        $linkRewrites     = [];
        $metaTitles       = [];
        $metaDescriptions = [];
        $metaKeywords     = [];
        $descriptions     = [];
        $shortDescriptions= [];

        $tmpName = substr($productData['DisplayText'], 0, strpos($productData['DisplayText'], ',')) . ' - ' . $productData['ManufacturerPartNumber'];
        if (isset($productData['ProductInformation']['Description'])) {
            $tmpShortDescription = $productData['ProductInformation']['Description'][0];
        } else {
            $tmpShortDescription = $productData['ProductName'];
        }

        foreach ($languages as $lang) {
            $id = (int)$lang['id_lang'];

            $names[$id]            = $tmpName;
            $linkRewrites[$id]     = \Tools::str2url($tmpName);
            $metaTitles[$id]       = \Tools::substr($tmpName, 0, 128);
            $metaDescriptions[$id] = \Tools::substr($tmpShortDescription, 0, 512);
            $metaKeywords[$id]     = \Tools::substr($productData['DisplayText'] ?? '', 0, 255);

            // HTML allowed in descriptions; keep them arrays per language
            $descriptions[$id]      = $this->writeDescription($productData);
            $shortDescriptions[$id] = \Tools::substr($tmpShortDescription, 0, 800); // PS default limit
        }

        // Now build the product
        $product = new \Product();
        $product->id_supplier          = $supplierId;
        $product->supplier_name        = 'Farnell';
        $product->id_manufacturer      = $this->getOrCreateManufacturerId((string) ($productData['ManufacturerName'] ?? ''));
        $product->id_category_default  = (int)$category;
        $product->id_shop_default      = (int)Context::getContext()->shop->id;
        $product->id_tax_rules_group   = (int)$taxRule;
        $product->quantity             = (int)$productData['Stock']['Level'];
        $product->price                = (float)$productData['Prices'][0]['LocalPrice'];
        $product->reference            = (string)$productData['ManufacturerPartNumber'];
        $product->supplier_reference   = (string)$productData['OrderCode'];
        $product->product_type         = 'standard';
        $product->active               = 1;                         // Mark product as active/published
        $product->visibility           = 'both';                // Ensure it's visible in catalog/search
        $product->low_stock_threshold = 0;

        // Multilingual fields (arrays keyed by id_lang)
        $product->name                 = $names;
        $product->link_rewrite         = $linkRewrites;
        $product->meta_title           = $metaTitles;
        $product->meta_description     = $metaDescriptions;
        $product->meta_keywords        = $metaKeywords;
        $product->description          = $descriptions;
        $product->description_short    = $shortDescriptions;

        if (!$product->validateFields(false) || !$product->validateFieldsLang(false)) {
            $this->debugLog("Product errors: "  . $product->getValidationErrors());
        }

        if (!$product->add()) {
            if (_PS_MODE_DEV_) {
                $this->debugLog('insertNewProduct: failed to add product.');
            }
            return false;
        }

        $product->updateCategories(array($category));
        $shops = [(int)Context::getContext()->shop->id];
        $product->associateTo($shops);

        return (int)$product->id;
    }


    // ---------------------------IN USE ---------------------------------------
    // Function to update the stock quantity ----------------------------------+
    // Returns:
    function updateStock($productId, $productAttributeId, $quantity)
    {
        StockAvailable::setQuantity((int)$productId, (int)$productAttributeId, (int)$quantity);
        $this->debugLog('1 updateStock OK');
    }


    // ---------------------------IN USE----------------------------------------
    // Function to write the HTML code for the product description ------------+
    // Returns HTML code for product description
    function writeDescription($productData)
    {
        $this->debugLog('EindAddToCartFunctions->Write description ------------------------------------');

        $productHTML = '<div class="mb-3"><h5>Product Description</h5> ';
        if (isset($productData['ProductInformation'])) {
            $productInformation = $productData['ProductInformation'];
            if (isset($productInformation['Description'])) {
                foreach ($productInformation['Description'] as $description) {
                    $productHTML .= '<p>'. $description . '</p> ';
                }
            }
            if (isset($productInformation['Bullets'])) {
                $productHTML .= '<ul class="list-group mt-05 mb-2">';
                foreach ($productInformation['Bullets'] as $value) {
                    $productHTML .= '<li class="list-group-item">' . $value . '</li>';
                }
                $productHTML .= '</ul>';
            }
            if (isset($productInformation['Applications'])) {
                $productHTML .= '<h6>Applications</h6> ';
                $productHTML .= '<ul class="list-group mt-05 mb-2">';
                foreach ($productInformation['Applications'] as $value) {
                    $productHTML .= '<li class="list-group-item">' . $value . '</li>';
                }
                $productHTML .= '</ul>';
            }
            if (isset($productInformation['AlternativeReference'])) {
                $productHTML .= '<h6>Alternative Reference</h6> ';
                $productHTML .= '<ul class="list-group mt-05 mb-2">';
                foreach ($productInformation['AlternativeReference'] as $value) {
                    $productHTML .= '<li class="list-group-item">' . $value . '</li>';
                }
                $productHTML .= '</ul>';
            }
        }
        $productHTML .= '</div>';

        if (isset($productData["ProductAttributes"])) {
            $productHTML .= '<div class="mb-3"><h5>Product AttributesInformation</h5>';
            foreach ($productData["ProductAttributes"] as $attribute) {
                if (preg_match('~^\p{Lu}~u', $attribute['Label'])) {
                    $productHTML .= '<div>
                        <div class="row">
                            <div class="col-xs-6">
                                <span>' . $attribute['Label'] . ':</span>
                            </div>
                            <div class="col-xs-6">
                                <span>' . $attribute['Value'];
                                    if (isset($attribute['Unit'])){
                                        $productHTML .= '&nbsp' . $attribute['Unit'];
                                    }
                                $productHTML .= '</span>
                            </div>
                        </div>
                    </div>';
                }
            }
            foreach ($productData["ProductAttributes"] as $attribute) {
                if (!preg_match('~^\p{Lu}~u', $attribute['Label'])) {
                    $productHTML .= '<div>
                        <div class="row">
                            <div class="col-xs-6">
                                <span>' . $attribute['Label'] . ':</span>
                            </div>
                            <div class="col-xs-6">
                                <span>' . $attribute['Value'];
                                    if (isset($attribute['Unit'])){
                                        $productHTML .= '&nbsp' . $attribute['Unit'];
                                    }
                                $productHTML .= '</span>
                            </div>
                        </div>
                    </div>';
                }
            }
            $productHTML .= '</div>';
        }
        if (isset($productData['CountryOfOrigin'])) {
            $productHTML .= '<div>
                <div class="row">
                    <div class="col-xs-6">
                        <span>Country Of Origin:</span>
                    </div>
                    <div class="col-xs-6">
                        <span>' . $productData['CountryOfOrigin'] . '</span>
                    </div>
                </div>
            </div>';
        }
        if (isset($productData['ProductInformation'])) {
            $productInformation = $productData['ProductInformation'];
            $productHTML .= '<div><div class="mb-3">';
            if (isset($productInformation['PackageContent'])){
                $productHTML .= '<div class="mb-3">
                    <h6>Package Content</h6>';
                    foreach ($productInformation['PackageContent'] as $value) {
                        $productHTML .= '<span>' . $value . '</span><br />';
                    }
                $productHTML .= '</div>';
            }
            if (isset($productInformation['Warnings'])){
                $productHTML .= '<div class="alert alert-warning">
                    <h5>Warning</h5>';
                    foreach ($productInformation['Warnings'] as $value) {
                        $productHTML .= '<span>' . $value . '</span><br />';
                    }
                $productHTML .= '</div>';
            }
            if (isset($productInformation['FootNotes'])){
                $productHTML .= '<div class="alert alert-warning">
                    <h5>Foot Notes</h5>';
                    foreach ($productInformation['FootNotes'] as $value) {
                        $productHTML .= '<span>' . $value . '</span><br />';
                    }
                $productHTML .= '</div>';
            }
            $productHTML .= '</div></div>';
        }
        $this->debugLog('EindAddToCartFunctions->writeDescription; Description: ' . $productHTML);
        return $productHTML;
    }


}
