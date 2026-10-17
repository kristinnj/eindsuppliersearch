<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Complete closed observation body validation; external dependencies are a separate boundary. */
final class ObservationProfileValidator
{
    public function validate(string $bytes): \stdClass
    {
        $o = CanonicalJson::decode($bytes);
        EvidenceProfileSchema::object($o, EvidenceProfileSchema::fields('observation'));
        EvidenceProfileSchema::check(count(array_unique([$o->installation_id, $o->observation_id,
            $o->cache_generation, $o->auth_scope])) === 4, 'PROFILE_IDS_NOT_DISTINCT');
        $p = $o->provenance;
        $n = $o->normalized_payload;
        EvidenceProfileSchema::check($o->normalized_payload_sha256 === EvidenceDigest::hex(CanonicalJson::encode($n)),
            'NORMALIZED_PAYLOAD_DIGEST_MISMATCH');
        EvidenceProfileSchema::check($o->currency === $n->supplier_cost_currency
            && $o->unit_price_tax_excl === $n->supplier_cost_tax_excl, 'SOURCE_PRICE_MISMATCH');
        EvidenceProfileSchema::check($p->request_started_at <= $p->received_at && $p->received_at === $o->observed_at
            && $p->price_observed_at <= $p->received_at && $o->availability_observed_at <= $p->received_at
            && $o->observed_at < $o->expires_at, 'TIME_ORDER_INVALID');
        if ($o->origin === 'FIXTURE') {
            EvidenceProfileSchema::check($p->source_authentication === 'LOCAL_FIXTURE'
                && $p->endpoint_id === 'fixture-local' && $p->credential_version_id === 'fixture-none', 'ORIGIN_MISMATCH');
        } else {
            EvidenceProfileSchema::check($p->source_authentication === 'TLS_API_CREDENTIAL'
                && $p->endpoint_id !== 'fixture-local' && $p->credential_version_id !== 'fixture-none', 'ORIGIN_MISMATCH');
        }
        return $o;
    }
}
