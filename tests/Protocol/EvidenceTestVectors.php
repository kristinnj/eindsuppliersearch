<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\SyntheticEvidenceKeyRing;

/** Public frozen section 15 fixtures, copied verbatim without final LF. */
final class EvidenceTestVectors
{
    public const INSTALLATION = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\x01";
    public const KEY_ID = 'doc-public-1';
    public const PRIMITIVE_FRAME_HEX = '45494e442d45564944454e43452d4d414300010000000b4f42534552564154494f4e000000000000000000000000000000010000000c646f632d7075626c69632d31000000027b7d';
    public const OBSERVATION = <<<'JSON'
{"adapter_id":"documentation-fixture","adapter_version":"1","auth_scope":"00000000000000000000000000000003","availability_observed_at":"2026-10-16T12:00:00.000000Z","available_quantity":"10","body_profile":"EIND-OQ-1","cache_generation":"00000000000000000000000000000004","config_sha256":"0021934aa1aeb580f092f793aa747098305d2556cb8a046344f5bd50ded39c92","currency":"EUR","expires_at":"2026-10-16T12:01:00.000000Z","id_shop":"1","id_supplier":"900001","installation_id":"00000000000000000000000000000001","normalized_payload":{"description_by_language":{"1":"Documentation only"},"id_category_default":"2","id_manufacturer":"1","id_tax_rules_group":"1","manufacturer_part_number":"DOC-01","minimum_quantity":"1","name_by_language":{"1":"Synthetic component"},"quantity_step":"1","slug_by_language":{"1":"synthetic-component"},"supplier_cost_currency":"EUR","supplier_cost_tax_excl":"1.250000","weight":"0.010000"},"normalized_payload_sha256":"3e2855eb7d83c1b5b8f6b637c1658b32a29b6ba3a6ea0389a9b4a3b4a8e262e5","observation_id":"00000000000000000000000000000002","observed_at":"2026-10-16T12:00:00.000000Z","order_reference_base64":"MDAwMS1B","origin":"FIXTURE","policy_sha256":"05b05186bc5c5fb349b79ea9f9e3fed302b6277a690be0beabbed8fede74d64f","product_type":"standard","provenance":{"credential_version_id":"fixture-none","endpoint_id":"fixture-local","price_observed_at":"2026-10-16T12:00:00.000000Z","received_at":"2026-10-16T12:00:00.000000Z","request_id":"00000000000000000000000000000006","request_started_at":"2026-10-16T11:59:59.000000Z","response_projection_sha256":"39e7c4c7c8d77e65021a4f487291bf23060c2bb10e2559303825f01dcae204e2","source_authentication":"LOCAL_FIXTURE","stock_basis":"PHYSICAL_AVAILABLE","supplier_data_at":null,"transport_profile_sha256":"de8788ff26db56602ad806e9d737a8597b83153df464d939ae58134f963f6ad4","unit":"EACH"},"record_kind":"OBSERVATION","schema_version":"1","unit_price_tax_excl":"1.250000"}
JSON;
    public const QUOTE = <<<'JSON'
{"accepted_amounts":{"gross_line":"3.720000","net_line":"3.000000","tax_line":"0.720000"},"auth_scope":"00000000000000000000000000000003","body_profile":"EIND-OQ-1","commercial_policy":{"currency_precision":"2","line_rounding":"HALF_UP","markup_method":"MARKUP_ON_COST","markup_policy_sha256":"6526888072ea00a040d79301c5c6c7a610658b568bea9af2d89ee4894e138c47","markup_rate_bps":"2000","minimum_net_line":"0.000000","policy_id":"doc-quote","policy_version":"1","price_basis":"TAX_EXCLUSIVE","rounding_policy_sha256":"c1ad389a4fa78ae43535a6c02df0a360025935926b9cb2bcaaa1822f187d8f43","tax_policy_sha256":"77e572dbda8625427d7925cd928a8f55075b5fdc0fdd37ee0d09cb94cbf62bdb","tax_rounding":"HALF_UP","unit_rounding":"HALF_UP"},"currency":"EUR","expires_at":"2026-10-16T12:00:31.000000Z","fx":null,"id_currency":"1","id_tax_rules_group":"1","installation_id":"00000000000000000000000000000001","issued_at":"2026-10-16T12:00:01.000000Z","observation_body_sha256":"6054f682855f9488e60695608f684a1b8092255d24bdf1ec24c4ecbd08fc839a","observation_id":"00000000000000000000000000000002","offer_identity":{"config_sha256":"0021934aa1aeb580f092f793aa747098305d2556cb8a046344f5bd50ded39c92","id_shop":"1","id_supplier":"900001","order_reference_base64":"MDAwMS1B"},"policy_sha256":"662afd35ca690f68f66e2cb8b2f8892390f6e775fa0522552b404810c4f55a5a","purpose":"CREATE","quantity":"2","quote_id":"00000000000000000000000000000005","quote_version":"1","record_kind":"QUOTE","source_price":{"currency":"EUR","unit_price_tax_excl":"1.250000"},"tax_basis":{"basis_version":"1","calculation_policy_sha256":"77e572dbda8625427d7925cd928a8f55075b5fdc0fdd37ee0d09cb94cbf62bdb","country_id":"1","rates":["24.000000"],"rules_group_id":"1"},"unit_price_tax_excl":"1.500000"}
JSON;

    // Ordinary authentication uses >=32-byte synthetic material, never the frozen short key.
    public const AUTH_KEY = 'PUBLIC-TEST-KEY!!PUBLIC-TEST-KEY!!';

    public static function ring(): SyntheticEvidenceKeyRing
    {
        return new SyntheticEvidenceKeyRing(array_map(static fn ($purpose) => [
            'installation' => self::INSTALLATION, 'purpose' => $purpose,
            'key_id' => self::KEY_ID, 'key' => self::AUTH_KEY, 'state' => 'ACTIVE',
        ], ['OBSERVATION', 'QUOTE']));
    }
}
