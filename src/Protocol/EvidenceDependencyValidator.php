<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/**
 * Finite acyclic EIND-OQ-1 graph, supplied entirely in memory as kind => exact bytes.
 * A digest is always resolved under its required kind; unknown/redundant inputs reject.
 * No generic reference traversal or administrative trust is inferred.
 */
final class EvidenceDependencyValidator
{
    private const OBSERVATION = ['integration', 'transport', 'observation_policy', 'supplier_validity', 'response_projection'];
    private const QUOTE = ['markup', 'rounding', 'tax', 'quote_bundle', 'approval_manifest'];

    public function observation(string $bytes, array $artifacts): void
    {
        $o = (new ObservationProfileValidator())->validate($bytes);
        $a = $this->load($artifacts, self::OBSERVATION);
        $this->bindObservation($o, $a, $artifacts);
    }

    /**
     * Validates Q->O and all artifacts; authentication of BOTH bodies remains separate.
     * currencyBindings is explicit trusted server context: shop ID => currency ID => code.
     * Passing browser data here cannot establish that administrative trust boundary.
     */
    public function quote(string $bytes, string $observationBytes, array $artifacts, array $currencyBindings): void
    {
        $q = (new QuoteProfileValidator())->validate($bytes);
        $o = (new ObservationProfileValidator())->validate($observationBytes);
        $a = $this->load($artifacts, array_merge(self::OBSERVATION, self::QUOTE));
        $this->bindObservation($o, $a, $artifacts);
        EvidenceProfileSchema::check($q->observation_body_sha256 === EvidenceDigest::hex($observationBytes),
            'OBSERVATION_DIGEST_MISMATCH');
        EvidenceProfileSchema::check($q->observation_id === $o->observation_id, 'OBSERVATION_ID_MISMATCH');
        EvidenceProfileSchema::check($q->installation_id === $o->installation_id && $q->auth_scope === $o->auth_scope,
            'SCOPE_MISMATCH');
        foreach (['config_sha256', 'id_shop', 'id_supplier', 'order_reference_base64'] as $field) {
            $this->equal($q->offer_identity->$field, $o->$field, 'OFFER_IDENTITY_MISMATCH');
        }
        EvidenceProfileSchema::check(isset($currencyBindings[$o->id_shop][$q->id_currency]), 'CURRENCY_CONTEXT_UNAVAILABLE');
        $this->equal($currencyBindings[$o->id_shop][$q->id_currency], $q->currency, 'CURRENCY_CONTEXT_MISMATCH');
        $this->equal($q->source_price->currency, $o->currency, 'SOURCE_PRICE_MISMATCH');
        $this->equal($q->source_price->unit_price_tax_excl, $o->unit_price_tax_excl, 'SOURCE_PRICE_MISMATCH');
        $this->equal($q->id_tax_rules_group, $o->normalized_payload->id_tax_rules_group, 'TAX_CONTEXT_MISMATCH');
        $quantity = (int) $q->quantity;
        EvidenceProfileSchema::check($quantity >= (int) $o->normalized_payload->minimum_quantity
            && $quantity % (int) $o->normalized_payload->quantity_step === 0
            && $quantity <= (int) $o->available_quantity, 'QUANTITY_INVALID');
        EvidenceProfileSchema::check($q->issued_at >= $o->observed_at
            && $q->issued_at >= $o->provenance->price_observed_at
            && $q->issued_at >= $o->availability_observed_at, 'TIME_ORDER_INVALID');
        $this->digest($artifacts['quote_bundle'], $q->policy_sha256);
        $cp = $q->commercial_policy;
        foreach (['markup', 'rounding', 'tax'] as $kind) {
            $field = $kind . '_policy_sha256';
            $this->digest($artifacts[$kind], $cp->$field);
        }
        $this->equal($q->commercial_policy, $a['quote_bundle']->commercial_policy, 'POLICY_CONTEXT_MISMATCH');
        $this->equal($q->tax_basis, $a['quote_bundle']->tax_basis, 'TAX_CONTEXT_MISMATCH');
        $this->equal($q->purpose, $a['quote_bundle']->purpose, 'POLICY_CONTEXT_MISMATCH');
        foreach (['markup_method', 'markup_rate_bps', 'minimum_net_line'] as $f) {
            $this->equal($cp->$f, $a['markup']->$f, 'RECIPE_MISMATCH');
        }
        foreach (['currency_precision', 'unit_rounding', 'line_rounding', 'tax_rounding'] as $f) {
            $this->equal($cp->$f, $a['rounding']->$f, 'RECIPE_MISMATCH');
        }
        foreach (['basis_version', 'country_id', 'rules_group_id', 'rates'] as $f) {
            $this->equal($q->tax_basis->$f, $a['tax']->$f, 'TAX_CONTEXT_MISMATCH');
        }
        $this->equal($q->expires_at, EvidenceProfileTime::quoteExpiry($q, $o,
            $a['quote_bundle']->freshness, $a['supplier_validity']), 'EXPIRY_MISMATCH');
        $this->manifest($q, $o, $a, $artifacts);
    }

    private function load(array $bytes, array $required): array
    {
        EvidenceProfileSchema::check(array_diff(array_keys($bytes), $required) === [], 'DEPENDENCY_UNEXPECTED');
        EvidenceProfileSchema::check(array_diff($required, array_keys($bytes)) === [], 'DEPENDENCY_UNAVAILABLE');
        $a = [];
        $identities = [];
        foreach ($required as $kind) {
            EvidenceProfileSchema::check(is_string($bytes[$kind]), 'DEPENDENCY_TYPE_INVALID');
            $a[$kind] = (new EvidenceArtifactValidator())->validate($kind, $bytes[$kind]);
            // One artifact per required kind; identities cannot be overwritten or aliased.
            $identity = match ($kind) {
                'transport' => [$a[$kind]->profile_id, $a[$kind]->profile_version],
                'integration' => null,
                'response_projection' => null,
                'approval_manifest' => [$a[$kind]->manifest_id, $a[$kind]->manifest_version],
                default => [$a[$kind]->policy_id, $a[$kind]->policy_version],
            };
            if ($identity !== null) {
                $key = $kind . ':' . implode(':', $identity);
                EvidenceProfileSchema::check(!isset($identities[$key]) || $identities[$key] === $bytes[$kind], 'ARTIFACT_IDENTITY_CONFLICT');
                $identities[$key] = $bytes[$kind];
            }
        }
        return $a;
    }

    private function bindObservation(\stdClass $o, array $a, array $bytes): void
    {
        $p = $o->provenance;
        $i = $a['integration'];
        $policy = $a['observation_policy'];
        $v = $a['supplier_validity'];
        $t = $a['transport'];
        $this->digest($bytes['integration'], $o->config_sha256);
        $this->digest($bytes['observation_policy'], $o->policy_sha256);
        $this->digest($bytes['supplier_validity'], $policy->supplier_validity_policy_sha256);
        $this->digest($bytes['transport'], $p->transport_profile_sha256);
        $this->digest($bytes['response_projection'], $p->response_projection_sha256);
        foreach (['adapter_id', 'adapter_version', 'id_shop', 'id_supplier'] as $f) {
            $this->equal($o->$f, $i->$f, 'CONFIG_CONTEXT_MISMATCH');
        }
        foreach (['credential_version_id', 'endpoint_id', 'transport_profile_sha256'] as $f) {
            $this->equal($p->$f, $i->$f, 'PROVENANCE_MISMATCH');
        }
        $this->equal($o->currency, $i->source_currency, 'SOURCE_PRICE_MISMATCH');
        $this->equal($policy->transport_profile_sha256, $p->transport_profile_sha256, 'PROVENANCE_MISMATCH');
        foreach (['credential_version_id', 'endpoint_id', 'id_shop', 'id_supplier', 'market_id'] as $f) {
            $this->equal($v->$f, $i->$f, 'SUPPLIER_VALIDITY_CONTEXT_MISMATCH');
        }
        $this->equal($t->authentication, $p->source_authentication, 'ORIGIN_MISMATCH');
        $projection = $a['response_projection'];
        foreach (['available_quantity', 'currency', 'order_reference_base64', 'unit_price_tax_excl'] as $f) {
            $this->equal($projection->$f, $o->$f, 'RESPONSE_PROJECTION_MISMATCH');
        }
        foreach (['minimum_quantity', 'quantity_step'] as $f) {
            $this->equal($projection->$f, $o->normalized_payload->$f, 'RESPONSE_PROJECTION_MISMATCH');
        }
        foreach (['stock_basis', 'unit'] as $f) {
            $this->equal($projection->$f, $p->$f, 'RESPONSE_PROJECTION_MISMATCH');
        }
        if ($v->source_cache_mode === 'RECEIPT_UNCACHED') {
            $effective = $o->observed_at;
        } else {
            EvidenceProfileSchema::check($p->supplier_data_at !== null, 'SUPPLIER_ANCHOR_UNAVAILABLE');
            EvidenceProfileSchema::check($p->supplier_data_at <= $o->observed_at, 'TIME_ORDER_INVALID');
            $effective = $p->supplier_data_at;
        }
        $this->equal($p->price_observed_at, $effective, 'SOURCE_CACHE_MISMATCH');
        $this->equal($o->availability_observed_at, $effective, 'SOURCE_CACHE_MISMATCH');
        if ($p->supplier_data_at !== null) {
            EvidenceProfileSchema::check($p->supplier_data_at <= EvidenceProfileTime::add($o->observed_at,
                $policy->freshness->clock_skew_s), 'TIME_ORDER_INVALID');
        }
        $this->equal($o->expires_at, EvidenceProfileTime::observationExpiry($o, $policy->freshness, $v), 'EXPIRY_MISMATCH');
    }

    private function manifest(\stdClass $q, \stdClass $o, array $a, array $bytes): void
    {
        $m = $a['approval_manifest'];
        foreach (['installation_id', 'id_shop', 'id_supplier', 'currency', 'origin'] as $f) {
            $this->equal($m->$f, $o->$f, 'APPROVAL_CONTEXT_MISMATCH');
        }
        $this->equal($m->purpose, $q->purpose, 'APPROVAL_CONTEXT_MISMATCH');
        foreach (['credential_version_id', 'endpoint_id', 'market_id'] as $f) {
            $this->equal($m->$f, $a['integration']->$f, 'APPROVAL_CONTEXT_MISMATCH');
        }
        foreach (['config_sha256' => 'integration', 'transport_profile_sha256' => 'transport',
            'observation_policy_sha256' => 'observation_policy', 'supplier_validity_policy_sha256' => 'supplier_validity',
            'markup_policy_sha256' => 'markup', 'rounding_policy_sha256' => 'rounding',
            'tax_policy_sha256' => 'tax', 'quote_policy_sha256' => 'quote_bundle'] as $f => $kind) {
            $this->digest($bytes[$kind], $m->$f);
        }
        foreach ([$o->observed_at, $q->issued_at] as $time) {
            EvidenceProfileSchema::check($time >= $m->effective_from && $time < $m->effective_until, 'APPROVAL_INTERVAL_MISMATCH');
        }
        // Matching manifest structure/context is NOT independent historical authorization.
    }

    private function digest(string $bytes, string $digest): void
    {
        EvidenceProfileSchema::check(EvidenceDigest::hex($bytes) === $digest, 'POLICY_DIGEST_MISMATCH');
    }

    private function equal(mixed $left, mixed $right, string $code): void
    {
        if ($left instanceof \stdClass && $right instanceof \stdClass) {
            $left = CanonicalJson::encode($left);
            $right = CanonicalJson::encode($right);
        }
        EvidenceProfileSchema::check($left === $right, $code);
    }
}
