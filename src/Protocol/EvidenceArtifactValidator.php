<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Independent canonical artifact validation; hashes confer no administrative trust. */
final class EvidenceArtifactValidator
{
    public function validate(string $kind, string $bytes, ?string $expectedDigest = null): \stdClass
    {
        $artifact = CanonicalJson::decode($bytes);
        EvidenceProfileSchema::object($artifact, EvidenceProfileSchema::fields($kind));
        if ($expectedDigest !== null) {
            EvidenceDigest::fromHex($expectedDigest);
            EvidenceProfileSchema::check(EvidenceDigest::hex($bytes) === $expectedDigest, 'POLICY_DIGEST_MISMATCH');
        }
        if ($kind === 'transport') {
            EvidenceProfileSchema::check((int) $artifact->connect_timeout_s <= (int) $artifact->total_timeout_s,
                'TRANSPORT_TIMEOUT_INVALID');
            foreach (['endpoint_origin', 'endpoint_path'] as $field) {
                EvidenceProfileSchema::check(preg_match('/\A[\x21-\x7e]{1,2048}\z/D', $artifact->$field) === 1
                    && !str_contains($artifact->$field, '#'), 'TRANSPORT_ENDPOINT_INVALID');
            }
            if ($artifact->authentication === 'LOCAL_FIXTURE') {
                EvidenceProfileSchema::check($artifact->endpoint_origin === 'fixture-local'
                    && $artifact->endpoint_path === 'none' && $artifact->method === 'LOCAL_READ', 'ORIGIN_MISMATCH');
            } else {
                $origin = parse_url($artifact->endpoint_origin);
                EvidenceProfileSchema::check(is_array($origin) && ($origin['scheme'] ?? '') === 'https'
                    && isset($origin['host']) && $origin['host'] !== ''
                    && array_diff(array_keys($origin), ['scheme', 'host', 'port']) === []
                    && str_starts_with($artifact->endpoint_path, '/') && $artifact->method === 'GET', 'TRANSPORT_ENDPOINT_INVALID');
            }
        }
        if ($kind === 'supplier_validity') {
            if ($artifact->validity_mode === 'NO_ADDITIONAL_LIMIT') {
                EvidenceProfileSchema::check($artifact->validity_anchor === null && $artifact->validity_max_age_s === null,
                    'SUPPLIER_VALIDITY_INVALID');
            } else {
                EvidenceProfileSchema::literal($artifact->validity_anchor, 'observed_at', 'price_observed_at',
                    'availability_observed_at', 'supplier_data_at');
                CanonicalDecimal::unsignedInteger($artifact->validity_max_age_s, true);
            }
        }
        if ($kind === 'approval_manifest') {
            EvidenceProfileSchema::check($artifact->effective_from < $artifact->effective_until, 'APPROVAL_INTERVAL_INVALID');
            EvidenceProfileSchema::check($artifact->origin !== 'FIXTURE' || $artifact->environment === 'TEST', 'ORIGIN_MISMATCH');
        }
        if ($kind === 'quote_bundle') {
            EvidenceProfileSchema::check($artifact->policy_id === $artifact->commercial_policy->policy_id
                && $artifact->policy_version === $artifact->commercial_policy->policy_version, 'POLICY_CONTEXT_MISMATCH');
        }
        return $artifact;
    }
}
