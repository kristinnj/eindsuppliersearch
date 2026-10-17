<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\{CanonicalJson, CanonicalValidationException, EvidenceDependencyValidator,
    EvidenceProfileTime, EvidenceArtifactValidator};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceDependencyValidatorTest extends TestCase
{
    public function testCompleteObservationAndQuoteGraphs(): void
    {
        $v = new EvidenceDependencyValidator();
        $v->observation(ProfileFixtures::bytes('observation'), ProfileFixtures::observationArtifacts());
        $v->quote(ProfileFixtures::bytes('quote'), ProfileFixtures::bytes('observation'), ProfileFixtures::artifacts(), ProfileFixtures::CURRENCIES);
        $this->addToAssertionCount(2);
    }

    #[DataProvider('missingArtifacts')]
    public function testEveryMissingDependencyFailsClosed(string $kind): void
    {
        $a = ProfileFixtures::artifacts();
        unset($a[$kind]);
        $this->expectExceptionMessage('DEPENDENCY_UNAVAILABLE');
        $this->quote($a);
    }

    public static function missingArtifacts(): iterable
    {
        foreach (array_keys(ProfileFixtures::artifacts()) as $kind) { yield [$kind]; }
    }

    #[DataProvider('missingArtifacts')]
    public function testEveryTypeSubstitutionRejects(string $kind): void
    {
        $a = ProfileFixtures::artifacts();
        $a[$kind] = ProfileFixtures::bytes($kind === 'markup' ? 'rounding' : 'markup');
        $this->expectException(CanonicalValidationException::class);
        $this->expectExceptionMessage('SCHEMA_UNKNOWN_FIELD');
        $this->quote($a);
    }

    #[DataProvider('artifactMismatches')]
    public function testRehashedArtifactCannotBypassCrossChecks(string $kind, string $field, mixed $value,
        bool $rehash, string $code): void
    {
        $o = CanonicalJson::decode(ProfileFixtures::bytes('observation'));
        $a = ProfileFixtures::observationArtifacts();
        $a[$kind] = ProfileFixtures::mutate($a[$kind], $field, $value);
        if ($rehash) {
            if ($kind === 'integration') { $o->config_sha256 = hash('sha256', $a[$kind]); }
            if ($kind === 'response_projection') { $o->provenance->response_projection_sha256 = hash('sha256', $a[$kind]); }
            if ($kind === 'supplier_validity') {
                $policy = CanonicalJson::decode($a['observation_policy']);
                $policy->supplier_validity_policy_sha256 = hash('sha256', $a[$kind]);
                $a['observation_policy'] = CanonicalJson::encode($policy);
                $o->policy_sha256 = hash('sha256', $a['observation_policy']);
            }
        }
        $this->expectExceptionMessage($code);
        (new EvidenceDependencyValidator())->observation(CanonicalJson::encode($o), $a);
    }

    public static function artifactMismatches(): iterable
    {
        yield ['integration', 'market_id', 'changed', false, 'POLICY_DIGEST_MISMATCH'];
        yield ['integration', 'id_supplier', '900002', true, 'CONFIG_CONTEXT_MISMATCH'];
        yield ['integration', 'adapter_version', '2', true, 'CONFIG_CONTEXT_MISMATCH'];
        yield ['integration', 'credential_version_id', 'changed', true, 'PROVENANCE_MISMATCH'];
        yield ['integration', 'source_currency', 'USD', true, 'SOURCE_PRICE_MISMATCH'];
        yield ['response_projection', 'unit_price_tax_excl', '1.260000', true, 'RESPONSE_PROJECTION_MISMATCH'];
        yield ['response_projection', 'available_quantity', '11', true, 'RESPONSE_PROJECTION_MISMATCH'];
        yield ['response_projection', 'order_reference_base64', base64_encode('other'), true, 'RESPONSE_PROJECTION_MISMATCH'];
        yield ['response_projection', 'minimum_quantity', '2', true, 'RESPONSE_PROJECTION_MISMATCH'];
        yield ['supplier_validity', 'market_id', 'changed', true, 'SUPPLIER_VALIDITY_CONTEXT_MISMATCH'];
        yield ['supplier_validity', 'source_cache_mode', 'SUPPLIER_DATA_AT', true, 'SUPPLIER_ANCHOR_UNAVAILABLE'];
    }

    #[DataProvider('quoteMismatches')]
    public function testQuoteToObservationBoundary(string $field, mixed $value, string $code): void
    {
        $q = ProfileFixtures::mutate(ProfileFixtures::bytes('quote'), $field, $value);
        $this->expectExceptionMessage($code);
        (new EvidenceDependencyValidator())->quote($q, ProfileFixtures::bytes('observation'), ProfileFixtures::artifacts(), ProfileFixtures::CURRENCIES);
    }

    public static function quoteMismatches(): iterable
    {
        yield ['observation_body_sha256', str_repeat('a', 64), 'OBSERVATION_DIGEST_MISMATCH'];
        yield ['observation_id', str_repeat('a', 32), 'OBSERVATION_ID_MISMATCH'];
        yield ['auth_scope', str_repeat('a', 32), 'SCOPE_MISMATCH'];
        yield ['offer_identity.id_supplier', '900002', 'OFFER_IDENTITY_MISMATCH'];
        yield ['offer_identity.id_shop', '2', 'OFFER_IDENTITY_MISMATCH'];
        yield ['offer_identity.config_sha256', str_repeat('a', 64), 'OFFER_IDENTITY_MISMATCH'];
        yield ['offer_identity.order_reference_base64', base64_encode('other'), 'OFFER_IDENTITY_MISMATCH'];
        yield ['id_currency', '2', 'CURRENCY_CONTEXT_UNAVAILABLE'];
        yield ['issued_at', '2026-10-16T11:59:59.000000Z', 'TIME_ORDER_INVALID'];
        yield ['expires_at', '2026-10-16T12:00:32.000000Z', 'EXPIRY_MISMATCH'];
    }

    public function testUnexpectedDependencyRejects(): void
    {
        $a = ProfileFixtures::artifacts();
        $a['freshness'] = '{}';
        $this->expectExceptionMessage('DEPENDENCY_UNEXPECTED');
        $this->quote($a);
    }

    public function testCurrencyContextMismatchRejects(): void
    {
        $this->expectExceptionMessage('CURRENCY_CONTEXT_MISMATCH');
        (new EvidenceDependencyValidator())->quote(ProfileFixtures::bytes('quote'), ProfileFixtures::bytes('observation'),
            ProfileFixtures::artifacts(), ['1' => ['1' => 'USD']]);
    }

    public function testMatchingRehashedRecipeStillMustEqualQuote(): void
    {
        $a = ProfileFixtures::artifacts();
        $a['markup'] = ProfileFixtures::mutate($a['markup'], 'markup_rate_bps', '3000');
        $q = CanonicalJson::decode(ProfileFixtures::bytes('quote'));
        $q->commercial_policy->markup_policy_sha256 = hash('sha256', $a['markup']);
        $bundle = CanonicalJson::decode($a['quote_bundle']);
        $bundle->commercial_policy = $q->commercial_policy;
        $a['quote_bundle'] = CanonicalJson::encode($bundle);
        $q->policy_sha256 = hash('sha256', $a['quote_bundle']);
        $this->expectExceptionMessage('RECIPE_MISMATCH');
        (new EvidenceDependencyValidator())->quote(CanonicalJson::encode($q), ProfileFixtures::bytes('observation'), $a, ProfileFixtures::CURRENCIES);
    }

    public function testCrossKindBackReferenceCannotCreateCycle(): void
    {
        $a = ProfileFixtures::observationArtifacts();
        // Back-edge to the observation policy in a slot requiring a transport artifact.
        $policy = CanonicalJson::decode($a['observation_policy']);
        $policy->transport_profile_sha256 = hash('sha256', $a['observation_policy']);
        $a['observation_policy'] = CanonicalJson::encode($policy);
        $o = ProfileFixtures::mutate(ProfileFixtures::bytes('observation'), 'policy_sha256', hash('sha256', $a['observation_policy']));
        $this->expectExceptionMessage('PROVENANCE_MISMATCH');
        (new EvidenceDependencyValidator())->observation($o, $a);
    }

    public function testCacheTimeCannotRefreshSourceFacts(): void
    {
        $o = CanonicalJson::decode(ProfileFixtures::bytes('observation'));
        $o->provenance->price_observed_at = '2026-10-16T11:59:59.000000Z';
        $this->expectExceptionMessage('SOURCE_CACHE_MISMATCH');
        (new EvidenceDependencyValidator())->observation(CanonicalJson::encode($o), ProfileFixtures::observationArtifacts());
    }

    public function testSupplierTimeSkewRejects(): void
    {
        $o = ProfileFixtures::mutate(ProfileFixtures::bytes('observation'), 'provenance.supplier_data_at', '2026-10-16T12:00:02.000001Z');
        $this->expectExceptionMessage('TIME_ORDER_INVALID');
        (new EvidenceDependencyValidator())->observation($o, ProfileFixtures::observationArtifacts());
    }

    public function testTimestampAnchoredValidityAndSourceCacheAreSupported(): void
    {
        $o = CanonicalJson::decode(ProfileFixtures::bytes('observation'));
        $a = ProfileFixtures::observationArtifacts();
        $o->provenance->supplier_data_at = '2026-10-16T11:59:59.123456Z';
        $o->provenance->price_observed_at = $o->availability_observed_at = $o->provenance->supplier_data_at;
        $v = CanonicalJson::decode($a['supplier_validity']);
        $v->source_cache_mode = 'SUPPLIER_DATA_AT';
        $v->validity_mode = 'TIMESTAMP_PLUS_SECONDS';
        $v->validity_anchor = 'supplier_data_at';
        $v->validity_max_age_s = '20';
        $a['supplier_validity'] = CanonicalJson::encode($v);
        $policy = CanonicalJson::decode($a['observation_policy']);
        $policy->supplier_validity_policy_sha256 = hash('sha256', $a['supplier_validity']);
        $a['observation_policy'] = CanonicalJson::encode($policy);
        $o->policy_sha256 = hash('sha256', $a['observation_policy']);
        $o->expires_at = '2026-10-16T12:00:19.123456Z';
        (new EvidenceArtifactValidator())->validate('supplier_validity', $a['supplier_validity']);
        (new EvidenceDependencyValidator())->observation(CanonicalJson::encode($o), $a);
        self::assertSame($o->expires_at, EvidenceProfileTime::supplierDeadline($o, $v));
    }

    public function testMissingSupplierDeadlineAnchorCannotMeanInfinity(): void
    {
        $o = CanonicalJson::decode(ProfileFixtures::bytes('observation'));
        $v = CanonicalJson::decode(ProfileFixtures::bytes('supplier_validity'));
        $v->validity_mode = 'TIMESTAMP_PLUS_SECONDS';
        $v->validity_anchor = 'supplier_data_at';
        $v->validity_max_age_s = '20';
        $this->expectExceptionMessage('SUPPLIER_ANCHOR_UNAVAILABLE');
        EvidenceProfileTime::supplierDeadline($o, $v);
    }

    private function quote(array $artifacts): void
    {
        (new EvidenceDependencyValidator())->quote(ProfileFixtures::bytes('quote'), ProfileFixtures::bytes('observation'), $artifacts, ProfileFixtures::CURRENCIES);
    }
}
