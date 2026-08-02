<?php

/* apidatabase.php */

class EindApiDatabase
{

    public function __construct()
    {

    }

    // Function to store or update the latest product list in dB with reference to the session id
    public function storeProductListInDb($api_session_id, $product_list)
    {
        $json_data = json_encode($product_list);
        if ($json_data === false) {
            // Handle JSON encode error
            return false;
        }
        $db = Db::getInstance();
        $tableName = _DB_PREFIX_ . pSQL(Eind_SupplierSearch::SEARCH_RESULTS_TABLE);

        $safeJson = $db->escape($json_data, true);
        $sql = 'INSERT INTO ' . $tableName . ' (session_id, created_at, product_list)
                VALUES (\'' . $db->escape($api_session_id) . '\', NOW(), \'' . $safeJson . '\')
                ON DUPLICATE KEY UPDATE
                created_at = NOW(),
                product_list = \'' . $safeJson . '\'';

        return $db->execute($sql);
    }

    public function getProductListBySessionId($api_session_id)
    {
        $db = Db::getInstance();
        $tableName = _DB_PREFIX_ . pSQL(Eind_SupplierSearch::SEARCH_RESULTS_TABLE);

        $row = $db->getRow(
            'SELECT product_list FROM ' . $tableName . ' WHERE session_id = \'' . $db->escape($api_session_id) . '\''
        );

        if (!$row || !isset($row['product_list'])) {
            return [];
        }

        $decoded = json_decode($row['product_list'], true);

        return is_array($decoded) ? $decoded : [];
    }

}
