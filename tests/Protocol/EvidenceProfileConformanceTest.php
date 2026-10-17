<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\{CanonicalJson, CanonicalValidationException, EvidenceArtifactValidator,
    EvidenceDependencyValidator, EvidenceAuthenticator, EvidenceProfileAuthenticator, ObservationProfileValidator,
    QuoteProfileValidator};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceProfileConformanceTest extends TestCase
{
    public function testLiveCanonicalGraphIsSupportedWithoutClaimingAdapterOrApprovalTrust(): void
    {
        $o = CanonicalJson::decode(ProfileFixtures::bytes('observation'));
        $o->origin = 'LIVE';
        $o->adapter_id = 'synthetic-live';
        $o->provenance->source_authentication = 'TLS_API_CREDENTIAL';
        $o->provenance->endpoint_id = 'synthetic-endpoint';
        $o->provenance->credential_version_id = 'synthetic-credential';
        $a = ProfileFixtures::artifacts();
        $a = array_map(static fn ($bytes) => str_replace(
            ['documentation-fixture', 'fixture-local"', 'fixture-none"'],
            ['synthetic-live', 'synthetic-endpoint"', 'synthetic-credential"'], $bytes), $a);
        $t = CanonicalJson::decode($a['transport']);
        $t->authentication = 'TLS_API_CREDENTIAL';
        $t->endpoint_origin = 'https://supplier.invalid';
        $t->endpoint_path = '/v1/offers';
        $t->method = 'GET';
        $a['transport'] = CanonicalJson::encode($t);
        $a['approval_manifest'] = ProfileFixtures::mutate($a['approval_manifest'], 'origin', 'LIVE');
        [$ob, $qb, $a] = ProfileFixtures::rehash(CanonicalJson::encode($o), ProfileFixtures::bytes('quote'), $a);
        (new EvidenceDependencyValidator())->quote($qb, $ob, $a, ProfileFixtures::CURRENCIES);
        $auth = new EvidenceAuthenticator(EvidenceTestVectors::ring());
        $r = (new EvidenceProfileAuthenticator($auth))->quote($qb, $ob, EvidenceTestVectors::INSTALLATION,
            EvidenceTestVectors::KEY_ID, $auth->macHex('QUOTE', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $qb),
            EvidenceTestVectors::KEY_ID, $auth->macHex('OBSERVATION', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $ob),
            $a, ProfileFixtures::CURRENCIES);
        self::assertTrue($r->cryptographicallyAuthentic);
        self::assertContains('adapter_capture_capability', $r->unassessedDomains);
        self::assertSame('UNASSESSED_INDEPENDENT_APPROVAL_REQUIRED', $r->historicalAuthorization);
        self::assertFalse($r->commerceEligible);
    }

    #[DataProvider('quantities')]
    public function testQuantityConstraintsAtDependencyBoundary(string $minimum, string $step, string $stock): void
    {
        $o = CanonicalJson::decode(ProfileFixtures::bytes('observation'));
        $o->normalized_payload->minimum_quantity = $minimum;
        $o->normalized_payload->quantity_step = $step;
        $o->available_quantity = $stock;
        $a = ProfileFixtures::artifacts();
        $r = CanonicalJson::decode($a['response_projection']);
        $r->minimum_quantity = $minimum;
        $r->quantity_step = $step;
        $r->available_quantity = $stock;
        $a['response_projection'] = CanonicalJson::encode($r);
        [$ob, $qb, $a] = ProfileFixtures::rehash(CanonicalJson::encode($o), ProfileFixtures::bytes('quote'), $a);
        $this->expectExceptionMessage('QUANTITY_INVALID');
        (new EvidenceDependencyValidator())->quote($qb, $ob, $a, ProfileFixtures::CURRENCIES);
    }

    public static function quantities(): iterable
    {
        yield 'below MOQ' => ['3', '1', '10'];
        yield 'not step multiple' => ['1', '3', '10'];
        yield 'exceeds stock' => ['1', '1', '1'];
        yield 'zero stock' => ['1', '1', '0'];
    }

    #[DataProvider('purposes')]
    public function testOtherClosedPurposesRemainValidHistoricalProfiles(string $purpose): void
    {
        $q = ProfileFixtures::mutate(ProfileFixtures::bytes('quote'), 'purpose', $purpose);
        $a = ProfileFixtures::artifacts();
        foreach (['quote_bundle', 'approval_manifest'] as $kind) {
            $a[$kind] = ProfileFixtures::mutate($a[$kind], 'purpose', $purpose);
        }
        [$o, $q, $a] = ProfileFixtures::rehash(ProfileFixtures::bytes('observation'), $q, $a);
        (new EvidenceDependencyValidator())->quote($q, $o, $a, ProfileFixtures::CURRENCIES);
        self::assertSame($purpose, CanonicalJson::decode($q)->purpose);
    }

    public static function purposes(): iterable
    {
        yield ['CART'];
        yield ['CHECKOUT'];
    }

    public function testNullableManufacturerAndMultipleLanguagesAreStructurallySupported(): void
    {
        $o = CanonicalJson::decode(ProfileFixtures::bytes('observation'));
        $o->normalized_payload->id_manufacturer = null;
        foreach (['name_by_language', 'description_by_language', 'slug_by_language'] as $f) {
            $o->normalized_payload->$f = (object) ['10' => 'é', '2' => 'é'];
        }
        [$bytes] = ProfileFixtures::rehash(CanonicalJson::encode($o), ProfileFixtures::bytes('quote'), ProfileFixtures::artifacts());
        self::assertNull((new ObservationProfileValidator())->validate($bytes)->normalized_payload->id_manufacturer);
        self::assertStringContainsString('"10":"é","2":"é"', $bytes);
        // Native approved language coverage/manufacturer policy is explicitly outside structural validity.
    }

    #[DataProvider('manifestContradictions')]
    public function testStructurallyValidManifestContextContradictionsReject(string $field, string $value, string $code): void
    {
        $a = ProfileFixtures::artifacts();
        $a['approval_manifest'] = ProfileFixtures::mutate($a['approval_manifest'], $field, $value);
        $this->expectExceptionMessage($code);
        (new EvidenceDependencyValidator())->quote(ProfileFixtures::bytes('quote'), ProfileFixtures::bytes('observation'), $a, ProfileFixtures::CURRENCIES);
    }

    public static function manifestContradictions(): iterable
    {
        yield ['installation_id', str_repeat('a', 32), 'APPROVAL_CONTEXT_MISMATCH'];
        yield ['id_shop', '2', 'APPROVAL_CONTEXT_MISMATCH'];
        yield ['id_supplier', '900002', 'APPROVAL_CONTEXT_MISMATCH'];
        yield ['currency', 'USD', 'APPROVAL_CONTEXT_MISMATCH'];
        yield ['purpose', 'CHECKOUT', 'APPROVAL_CONTEXT_MISMATCH'];
        yield ['market_id', 'other', 'APPROVAL_CONTEXT_MISMATCH'];
        yield ['transport_profile_sha256', str_repeat('a', 64), 'POLICY_DIGEST_MISMATCH'];
        yield ['effective_from', '2026-10-16T12:00:00.000001Z', 'APPROVAL_INTERVAL_MISMATCH'];
        yield ['effective_until', '2026-10-16T12:00:01.000000Z', 'APPROVAL_INTERVAL_MISMATCH'];
    }

    #[DataProvider('liveTransportAttacks')]
    public function testLiveTransportEndpointRestrictions(string $origin, string $path): void
    {
        $t = CanonicalJson::decode(ProfileFixtures::bytes('transport'));
        $t->authentication = 'TLS_API_CREDENTIAL';
        $t->method = 'GET';
        $t->endpoint_origin = $origin;
        $t->endpoint_path = $path;
        $this->expectExceptionMessage('TRANSPORT_ENDPOINT_INVALID');
        (new EvidenceArtifactValidator())->validate('transport', CanonicalJson::encode($t));
    }

    public static function liveTransportAttacks(): iterable
    {
        yield ['http://supplier.invalid', '/api'];
        yield ['https://user:pass@supplier.invalid', '/api'];
        yield ['https://supplier.invalid/path', '/api'];
        yield ['https://supplier.invalid?key=x', '/api'];
        yield ['https://supplier.invalid', 'relative'];
        yield ['https://supplier.invalid#fragment', '/api'];
    }

    public function testForbiddenNullInAllNonnullableFieldsRejects(): void
    {
        // Independent record-field enumeration rather than callbacks derived from implementation.
        foreach (['observation' => ['adapter_id', 'available_quantity', 'auth_scope', 'normalized_payload',
            'unit_price_tax_excl', 'currency', 'provenance', 'provenance.received_at', 'provenance.unit',
            'normalized_payload.minimum_quantity', 'normalized_payload.name_by_language'],
            'quote' => ['quantity', 'offer_identity', 'source_price', 'commercial_policy', 'tax_basis',
            'tax_basis.rates', 'accepted_amounts', 'accepted_amounts.tax_line', 'issued_at', 'id_currency']] as $kind => $fields) {
            foreach ($fields as $field) {
                try {
                    $bytes = ProfileFixtures::mutate(ProfileFixtures::bytes($kind), $field, null);
                    $kind === 'observation' ? (new ObservationProfileValidator())->validate($bytes) : (new QuoteProfileValidator())->validate($bytes);
                    self::fail("Forbidden null accepted at $kind:$field");
                } catch (CanonicalValidationException $e) {
                    self::assertNotSame('', $e->getMessage());
                }
            }
        }
    }
}
