<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Closed quote body and frozen arithmetic. Does not authenticate its observation. */
final class QuoteProfileValidator
{
    public function validate(string $bytes): \stdClass
    {
        $q = CanonicalJson::decode($bytes);
        EvidenceProfileSchema::object($q, EvidenceProfileSchema::fields('quote'));
        EvidenceProfileSchema::check($q->issued_at < $q->expires_at, 'TIME_ORDER_INVALID');
        EvidenceProfileSchema::check($q->source_price->currency === $q->currency, 'SOURCE_PRICE_MISMATCH');
        EvidenceProfileSchema::check($q->tax_basis->rules_group_id === $q->id_tax_rules_group
            && $q->tax_basis->calculation_policy_sha256 === $q->commercial_policy->tax_policy_sha256, 'TAX_CONTEXT_MISMATCH');
        EvidenceExactArithmetic::quote($q);
        return $q;
    }
}
