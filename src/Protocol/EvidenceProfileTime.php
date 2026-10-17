<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Pure timestamp derivations only. No clock, access or commerce eligibility. */
final class EvidenceProfileTime
{
    public static function add(string $timestamp, string $seconds): string
    {
        CanonicalDecimal::unsignedInteger($seconds);
        $time = CanonicalTimestamp::parse($timestamp)->add(new \DateInterval('PT' . $seconds . 'S'));
        $result = $time->format('Y-m-d\TH:i:s.u\Z');
        CanonicalTimestamp::parse($result); // Reject year overflow, never clamp.
        return $result;
    }

    public static function supplierDeadline(\stdClass $o, \stdClass $policy): ?string
    {
        if ($policy->validity_mode === 'NO_ADDITIONAL_LIMIT') { return null; }
        $anchor = match ($policy->validity_anchor) {
            'observed_at' => $o->observed_at,
            'availability_observed_at' => $o->availability_observed_at,
            'price_observed_at' => $o->provenance->price_observed_at,
            'supplier_data_at' => $o->provenance->supplier_data_at,
        };
        EvidenceProfileSchema::check($anchor !== null, 'SUPPLIER_ANCHOR_UNAVAILABLE');
        return self::add($anchor, $policy->validity_max_age_s);
    }

    public static function observationExpiry(\stdClass $o, \stdClass $freshness, \stdClass $validity): string
    {
        return min(array_filter([
            self::add($o->observed_at, $freshness->observation_reuse_s),
            self::add($o->provenance->price_observed_at, $freshness->price_max_age_s),
            self::add($o->availability_observed_at, $freshness->stock_max_age_s),
            self::supplierDeadline($o, $validity),
        ], static fn ($v) => $v !== null));
    }

    public static function quoteExpiry(\stdClass $q, \stdClass $o, \stdClass $freshness, \stdClass $validity): string
    {
        return min(array_filter([
            $o->expires_at,
            self::add($o->provenance->price_observed_at, $freshness->price_max_age_s),
            self::add($o->availability_observed_at, $freshness->stock_max_age_s),
            self::add($q->issued_at, $freshness->quote_ttl_s), self::supplierDeadline($o, $validity),
        ], static fn ($v) => $v !== null));
    }

    /**
     * Contract 8 time predicates ONLY, using explicit canonical inputs.
     * These findings do not assess clock trust, authentication, access, revocation,
     * supplier truth or permission for any current operation.
     */
    public static function quoteTimePredicates(string $now, string $quoteBytes, string $observationBytes,
        string $quoteBundleBytes): array
    {
        CanonicalTimestamp::parse($now);
        $q = (new QuoteProfileValidator())->validate($quoteBytes);
        $o = (new ObservationProfileValidator())->validate($observationBytes);
        $bundle = (new EvidenceArtifactValidator())->validate('quote_bundle', $quoteBundleBytes, $q->policy_sha256);
        EvidenceProfileSchema::check($q->observation_body_sha256 === EvidenceDigest::hex($observationBytes),
            'OBSERVATION_DIGEST_MISMATCH');
        $f = $bundle->freshness;
        $price = $o->provenance->price_observed_at;
        $stock = $o->availability_observed_at;
        $nonnegative = $now >= $o->observed_at && $now >= $q->issued_at && $now >= $price && $now >= $stock;
        $priceAge = $now <= self::add($price, $f->price_max_age_s);
        $stockAge = $now <= self::add($stock, $f->stock_max_age_s);
        $purposeAge = match ($q->purpose) {
            'CREATE' => $now <= self::add($o->observed_at, $f->create_max_age_s),
            'CHECKOUT' => $now <= self::add($price, $f->checkout_max_age_s)
                && $now <= self::add($stock, $f->checkout_max_age_s),
            'CART' => true,
        };
        return ['before_expiry' => self::beforeExpiry($now, $q->expires_at),
            'nonnegative_ages' => $nonnegative, 'price_age_within_limit' => $priceAge,
            'stock_age_within_limit' => $stockAge, 'purpose_age_within_limit' => $purposeAge];
    }

    /** Only tests the strict expiry predicate; success asserts no other current permission. */
    public static function beforeExpiry(string $now, string $expiresAt): bool
    {
        return CanonicalTimestamp::compare($now, $expiresAt) < 0;
    }
}
