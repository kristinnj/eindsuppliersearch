<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Frozen EIND-OQ-1 field domains. Every callback throws on rejection. */
final class EvidenceProfileSchema
{
    public static function check(bool $condition, string $code): void
    {
        if (!$condition) { throw new CanonicalValidationException($code); }
    }

    public static function literal(mixed $value, string ...$allowed): void
    {
        self::check(in_array($value, $allowed, true), 'PROFILE_LITERAL_INVALID');
    }

    public static function label(mixed $value, int $maximum = 64): void
    {
        self::check(is_string($value) && strlen($value) <= $maximum
            && preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $value) === 1, 'PROFILE_LABEL_INVALID');
    }

    public static function id(mixed $value): void
    {
        self::check(is_string($value) && preg_match('/\A[0-9a-f]{32}\z/D', $value) === 1
            && $value !== str_repeat('0', 32), 'PROFILE_ID_INVALID');
    }

    public static function currency(mixed $value): void
    {
        self::check(is_string($value) && preg_match('/\A[A-Z]{3}\z/D', $value) === 1, 'PROFILE_CURRENCY_INVALID');
    }

    public static function text(mixed $value, int $maximum = CanonicalJson::MAX_BYTES): void
    {
        self::check(is_string($value) && strlen($value) <= $maximum && preg_match('//u', $value) === 1,
            'PROFILE_TEXT_INVALID');
    }

    public static function reference(mixed $value): void
    {
        $bytes = CanonicalBase64::decode($value);
        self::check(preg_match('/\A[\s\p{Z}]*\z/u', $bytes) !== 1, 'PROFILE_REFERENCE_BLANK');
    }

    public static function languages(mixed $value, int $maximum): void
    {
        ClosedSchema::scalarType($value, 'object');
        $fields = get_object_vars($value);
        self::check(count($fields) >= 1 && count($fields) <= 16, 'PROFILE_LANGUAGE_COUNT_INVALID');
        foreach ($fields as $language => $text) {
            CanonicalDecimal::unsignedInteger((string) $language, true);
            self::text($text, $maximum);
        }
    }

    public static function object(mixed $value, array $fields): void
    {
        $validators = [];
        foreach ($fields as $field => $type) {
            $validators[$field] = static function (mixed $v) use ($type): void { self::field($type, $v); };
        }
        ClosedSchema::object($value, $validators);
    }

    private static function field(string $type, mixed $v): void
    {
        if (str_starts_with($type, '=')) { self::literal($v, ...explode('|', substr($type, 1))); return; }
        switch ($type) {
            case 'id': self::id($v); return;
            case 'digest': EvidenceDigest::fromHex($v); return;
            case 'label': self::label($v); return;
            case 'adapter_version': self::label($v, 32); return;
            case 'version':
            case 'positive': CanonicalDecimal::unsignedInteger($v, true); return;
            case 'uint': CanonicalDecimal::unsignedInteger($v); return;
            case 'precision': CanonicalDecimal::unsignedInteger($v, false, '6'); return;
            case 'money': CanonicalDecimal::decimal($v); return;
            case 'price': CanonicalDecimal::decimal($v, true); return;
            case 'currency': self::currency($v); return;
            case 'time': CanonicalTimestamp::parse($v); return;
            case 'nullable_time': ClosedSchema::nullable($v, CanonicalTimestamp::parse(...)); return;
            case 'manufacturer': ClosedSchema::nullable($v, static function ($x): void { CanonicalDecimal::unsignedInteger($x, true); }); return;
            case 'reference': self::reference($v); return;
            case 'text': self::text($v); return;
            case 'text_or_null': ClosedSchema::nullable($v, self::text(...)); return;
            case 'positive_or_null': ClosedSchema::nullable($v, static function ($x): void { CanonicalDecimal::unsignedInteger($x, true); }); return;
            case 'null': self::check($v === null, 'PROFILE_NULL_REQUIRED'); return;
            case 'languages': self::languages($v, 4096); return;
            case 'descriptions': self::languages($v, 65536); return;
            case 'rates': ClosedSchema::list($v, static function ($x): void { CanonicalDecimal::decimal($x, false, '100.000000'); }, 1, 1); return;
            default: self::object($v, self::fields($type));
        }
    }

    /** Exact schemas from contract 4.2, 4.3, 7, 7.1, 8 and 8.1. */
    public static function fields(string $kind): array
    {
        $identity = ['policy_id' => 'label', 'policy_version' => 'version'];
        return match ($kind) {
            'normalized_payload' => [
                'description_by_language' => 'descriptions', 'id_category_default' => 'positive',
                'id_manufacturer' => 'manufacturer', 'id_tax_rules_group' => 'uint',
                'manufacturer_part_number' => 'text', 'minimum_quantity' => 'positive',
                'name_by_language' => 'languages', 'quantity_step' => 'positive',
                'slug_by_language' => 'languages', 'supplier_cost_currency' => 'currency',
                'supplier_cost_tax_excl' => 'price', 'weight' => 'money',
            ],
            'offer_identity' => ['config_sha256' => 'digest', 'id_shop' => 'positive',
                'id_supplier' => 'positive', 'order_reference_base64' => 'reference'],
            'provenance' => [
                'credential_version_id' => 'label', 'endpoint_id' => 'label',
                'price_observed_at' => 'time', 'received_at' => 'time', 'request_id' => 'id',
                'request_started_at' => 'time', 'response_projection_sha256' => 'digest',
                'source_authentication' => '=TLS_API_CREDENTIAL|LOCAL_FIXTURE',
                'stock_basis' => '=PHYSICAL_AVAILABLE', 'supplier_data_at' => 'nullable_time',
                'transport_profile_sha256' => 'digest', 'unit' => '=EACH',
            ],
            'response_projection' => [
                'available_quantity' => 'uint', 'currency' => 'currency', 'minimum_quantity' => 'positive',
                'order_reference_base64' => 'reference', 'quantity_step' => 'positive',
                'stock_basis' => '=PHYSICAL_AVAILABLE', 'unit' => '=EACH', 'unit_price_tax_excl' => 'price',
            ],
            'integration' => [
                'adapter_id' => 'label', 'adapter_version' => 'adapter_version', 'credential_version_id' => 'label',
                'endpoint_id' => 'label', 'id_shop' => 'positive', 'id_supplier' => 'positive', 'market_id' => 'label',
                'mapping_version' => 'version', 'source_currency' => 'currency', 'transport_profile_sha256' => 'digest',
            ],
            'transport' => [
                'account_validation_profile_id' => 'label', 'authentication' => '=TLS_API_CREDENTIAL|LOCAL_FIXTURE',
                'connect_timeout_s' => 'positive', 'destination_policy_id' => 'label', 'endpoint_origin' => 'text',
                'endpoint_path' => 'text', 'max_offers' => 'positive', 'max_response_bytes' => 'positive',
                'method' => '=GET|LOCAL_READ', 'profile_id' => 'label', 'profile_version' => 'version',
                'total_timeout_s' => 'positive',
            ],
            'freshness' => [
                'cache_max_age_s' => 'positive', 'checkout_max_age_s' => 'positive', 'clock_skew_s' => 'uint',
                'create_max_age_s' => 'positive', 'observation_reuse_s' => 'positive', 'price_max_age_s' => 'positive',
                'quote_ttl_s' => 'positive', 'stock_max_age_s' => 'positive',
            ],
            'observation_policy' => $identity + ['freshness' => 'freshness',
                'supplier_validity_policy_sha256' => 'digest', 'transport_profile_sha256' => 'digest'],
            'markup' => $identity + ['markup_method' => '=MARKUP_ON_COST', 'markup_rate_bps' => 'uint', 'minimum_net_line' => 'money'],
            'rounding' => $identity + ['currency_precision' => 'precision', 'line_rounding' => '=HALF_UP',
                'tax_rounding' => '=HALF_UP', 'unit_rounding' => '=HALF_UP'],
            'tax' => $identity + ['basis_version' => '=1', 'calculation_method' => '=NET_LINE_SINGLE_RATE',
                'country_id' => 'positive', 'rates' => 'rates', 'rules_group_id' => 'uint'],
            'commercial_policy' => $identity + [
                'currency_precision' => 'precision', 'line_rounding' => '=HALF_UP', 'markup_method' => '=MARKUP_ON_COST',
                'markup_policy_sha256' => 'digest', 'markup_rate_bps' => 'uint', 'minimum_net_line' => 'money',
                'price_basis' => '=TAX_EXCLUSIVE', 'rounding_policy_sha256' => 'digest',
                'tax_policy_sha256' => 'digest', 'tax_rounding' => '=HALF_UP', 'unit_rounding' => '=HALF_UP',
            ],
            'tax_basis' => ['basis_version' => '=1', 'calculation_policy_sha256' => 'digest',
                'country_id' => 'positive', 'rates' => 'rates', 'rules_group_id' => 'uint'],
            'quote_bundle' => $identity + ['commercial_policy' => 'commercial_policy', 'freshness' => 'freshness',
                'purpose' => '=CREATE|CART|CHECKOUT', 'tax_basis' => 'tax_basis'],
            'supplier_validity' => $identity + ['credential_version_id' => 'label', 'endpoint_id' => 'label',
                'id_shop' => 'positive', 'id_supplier' => 'positive', 'market_id' => 'label',
                'source_cache_mode' => '=RECEIPT_UNCACHED|SUPPLIER_DATA_AT',
                // Conditional domains are checked by EvidenceArtifactValidator.
                'validity_anchor' => 'text_or_null', 'validity_max_age_s' => 'positive_or_null',
                'validity_mode' => '=NO_ADDITIONAL_LIMIT|TIMESTAMP_PLUS_SECONDS'],
            'approval_manifest' => [
                'authority_id' => 'label', 'commercial_subset' => '=STANDARD_EACH_FLAT_NO_FX_FEES_V1',
                'config_sha256' => 'digest', 'credential_version_id' => 'label', 'currency' => 'currency',
                'effective_from' => 'time', 'effective_until' => 'time', 'endpoint_id' => 'label',
                'environment' => '=TEST|STAGING|PRODUCTION', 'id_shop' => 'positive', 'id_supplier' => 'positive',
                'installation_id' => 'id', 'manifest_id' => 'label', 'manifest_version' => 'version',
                'market_id' => 'label', 'markup_policy_sha256' => 'digest', 'observation_policy_sha256' => 'digest',
                'origin' => '=FIXTURE|LIVE', 'purpose' => '=CREATE|CART|CHECKOUT', 'quote_policy_sha256' => 'digest',
                'rounding_policy_sha256' => 'digest', 'supplier_validity_policy_sha256' => 'digest',
                'tax_policy_sha256' => 'digest', 'transport_profile_sha256' => 'digest',
            ],
            'observation' => [
                'adapter_id' => 'label', 'adapter_version' => 'adapter_version', 'auth_scope' => 'id',
                'availability_observed_at' => 'time', 'available_quantity' => 'uint', 'body_profile' => '=EIND-OQ-1',
                'cache_generation' => 'id', 'config_sha256' => 'digest', 'currency' => 'currency', 'expires_at' => 'time',
                'id_shop' => 'positive', 'id_supplier' => 'positive', 'installation_id' => 'id',
                'normalized_payload' => 'normalized_payload', 'normalized_payload_sha256' => 'digest',
                'observation_id' => 'id', 'observed_at' => 'time', 'order_reference_base64' => 'reference',
                'origin' => '=LIVE|FIXTURE', 'policy_sha256' => 'digest', 'product_type' => '=standard',
                'provenance' => 'provenance', 'record_kind' => '=OBSERVATION', 'schema_version' => '=1',
                'unit_price_tax_excl' => 'price',
            ],
            'quote' => [
                'accepted_amounts' => 'amounts', 'auth_scope' => 'id', 'body_profile' => '=EIND-OQ-1',
                'commercial_policy' => 'commercial_policy', 'currency' => 'currency', 'expires_at' => 'time',
                'fx' => 'null', 'id_currency' => 'positive', 'id_tax_rules_group' => 'uint', 'installation_id' => 'id',
                'issued_at' => 'time', 'observation_body_sha256' => 'digest', 'observation_id' => 'id',
                'offer_identity' => 'offer_identity', 'policy_sha256' => 'digest', 'purpose' => '=CREATE|CART|CHECKOUT',
                'quantity' => 'positive', 'quote_id' => 'id', 'quote_version' => '=1', 'record_kind' => '=QUOTE',
                'source_price' => 'source_price', 'tax_basis' => 'tax_basis', 'unit_price_tax_excl' => 'price',
            ],
            'amounts' => ['gross_line' => 'price', 'net_line' => 'price', 'tax_line' => 'money'],
            'source_price' => ['currency' => 'currency', 'unit_price_tax_excl' => 'price'],
            default => throw new CanonicalValidationException('ARTIFACT_KIND_INVALID'),
        };
    }
}
