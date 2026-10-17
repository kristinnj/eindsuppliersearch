<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\{CanonicalJson, EvidenceAuthenticator, EvidenceProfileAuthenticator,
    EvidenceProfileTime, SyntheticEvidenceKeyRing};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceProfileAuthenticatorTest extends TestCase
{
    private function primitive(): EvidenceAuthenticator
    {
        return new EvidenceAuthenticator(EvidenceTestVectors::ring());
    }

    private function mac(string $purpose, string $body): string
    {
        return $this->primitive()->macHex($purpose, EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $body);
    }

    private function observation(string $body, ?string $mac = null, ?array $artifacts = null): mixed
    {
        return (new EvidenceProfileAuthenticator($this->primitive()))->observation($body, EvidenceTestVectors::INSTALLATION,
            EvidenceTestVectors::KEY_ID, $mac ?? $this->mac('OBSERVATION', $body), $artifacts ?? ProfileFixtures::observationArtifacts());
    }

    private function quote(string $body, ?array $artifacts = null, ?string $observationMac = null): mixed
    {
        $o = ProfileFixtures::bytes('observation');
        return (new EvidenceProfileAuthenticator($this->primitive()))->quote($body, $o, EvidenceTestVectors::INSTALLATION,
            EvidenceTestVectors::KEY_ID, $this->mac('QUOTE', $body), EvidenceTestVectors::KEY_ID,
            $observationMac ?? $this->mac('OBSERVATION', $o), $artifacts ?? ProfileFixtures::artifacts(), ProfileFixtures::CURRENCIES);
    }

    public function testCompleteSyntheticAuthenticationPreservesOriginalBytesAndSeparateFindings(): void
    {
        foreach (['observation', 'quote'] as $kind) {
            $bytes = ProfileFixtures::bytes($kind);
            $result = $kind === 'observation' ? $this->observation($bytes) : $this->quote($bytes);
            self::assertSame($bytes, $result->bodyBytes);
            self::assertTrue($result->structurallyValid);
            self::assertTrue($result->dependenciesValid);
            self::assertTrue($result->cryptographicallyAuthentic);
            self::assertSame('UNASSESSED_INDEPENDENT_APPROVAL_REQUIRED', $result->historicalAuthorization);
            self::assertSame('UNASSESSED', $result->currentEligibility);
            self::assertFalse($result->commerceEligible);
        }
    }

    public function testExpiredHistoricalQuoteRemainsAuthenticWithoutCurrentAuthorization(): void
    {
        $q = ProfileFixtures::bytes('quote');
        $r = $this->quote($q);
        self::assertTrue($r->cryptographicallyAuthentic);
        self::assertFalse(EvidenceProfileTime::beforeExpiry('2026-10-16T12:00:31.000000Z', CanonicalJson::decode($q)->expires_at));
        self::assertSame('UNASSESSED', $r->currentEligibility);
        self::assertFalse($r->commerceEligible);
    }

    public function testSelfSignedChangedManifestCannotAuthorizeItself(): void
    {
        $a = ProfileFixtures::artifacts();
        $a['approval_manifest'] = ProfileFixtures::mutate($a['approval_manifest'], 'authority_id', 'self-appointed-signer');
        // Even an exact HMAC over a context-matching candidate manifest proves no independent approval.
        $this->primitive()->macHex('QUOTE', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $a['approval_manifest']);
        $r = $this->quote(ProfileFixtures::bytes('quote'), $a);
        self::assertTrue($r->cryptographicallyAuthentic);
        self::assertSame('UNASSESSED_INDEPENDENT_APPROVAL_REQUIRED', $r->historicalAuthorization);
        self::assertFalse($r->commerceEligible);
    }

    public function testMatchingMacOverInvalidProfileRejectsAtSchemaBoundary(): void
    {
        $body = ProfileFixtures::mutate(ProfileFixtures::bytes('observation'), 'approved', true);
        $mac = $this->mac('OBSERVATION', $body);
        self::assertTrue($this->primitive()->verifyHex('OBSERVATION', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $body, $mac));
        $this->expectExceptionMessage('SCHEMA_UNKNOWN_FIELD');
        $this->observation($body, $mac);
    }

    public function testCorrectMacCannotPromoteFixtureToLive(): void
    {
        $body = ProfileFixtures::mutate(ProfileFixtures::bytes('observation'), 'origin', 'LIVE');
        $this->expectExceptionMessage('ORIGIN_MISMATCH');
        $this->observation($body);
    }

    public function testCorrectMacCannotReplaceObservationDigest(): void
    {
        $q = ProfileFixtures::mutate(ProfileFixtures::bytes('quote'), 'observation_body_sha256', str_repeat('a', 64));
        $this->expectExceptionMessage('OBSERVATION_DIGEST_MISMATCH');
        $this->quote($q);
    }

    public function testCorrectMacCannotBypassMissingDependencies(): void
    {
        $this->expectExceptionMessage('DEPENDENCY_UNAVAILABLE');
        $this->quote(ProfileFixtures::bytes('quote'), []);
    }

    public function testQuoteRequiresAuthenticObservationToo(): void
    {
        $this->expectExceptionMessage('OBSERVATION_MAC_INVALID');
        $this->quote(ProfileFixtures::bytes('quote'), null, str_repeat('0', 64));
    }

    public function testBodyMutationInvalidatesOriginalMac(): void
    {
        $o = ProfileFixtures::bytes('observation');
        $changed = ProfileFixtures::mutate($o, 'cache_generation', str_repeat('a', 32));
        $this->expectExceptionMessage('MAC_INVALID');
        $this->observation($changed, $this->mac('OBSERVATION', $o));
    }

    public function testMacMutationRejects(): void
    {
        $this->expectExceptionMessage('MAC_INVALID');
        $this->observation(ProfileFixtures::bytes('observation'), str_repeat('0', 64));
    }

    public function testObservationMacCannotServeQuotePurpose(): void
    {
        $body = ProfileFixtures::bytes('quote');
        $o = ProfileFixtures::bytes('observation');
        $this->expectExceptionMessage('MAC_INVALID');
        (new EvidenceProfileAuthenticator($this->primitive()))->quote($body, $o, EvidenceTestVectors::INSTALLATION,
            EvidenceTestVectors::KEY_ID, $this->mac('OBSERVATION', $body), EvidenceTestVectors::KEY_ID,
            $this->mac('OBSERVATION', $o), ProfileFixtures::artifacts(), ProfileFixtures::CURRENCIES);
    }

    public function testQuoteMacCannotServeObservationPurpose(): void
    {
        $o = ProfileFixtures::bytes('observation');
        $this->expectExceptionMessage('MAC_INVALID');
        $this->observation($o, $this->mac('QUOTE', $o));
    }

    #[DataProvider('purposeSubstitution')]
    public function testBodyCannotUseOtherProfileEntryPoint(bool $quote): void
    {
        $this->expectExceptionMessage('SCHEMA_UNKNOWN_FIELD');
        if ($quote) { $this->quote(ProfileFixtures::bytes('observation')); }
        else { $this->observation(ProfileFixtures::bytes('quote')); }
    }

    public static function purposeSubstitution(): iterable
    {
        yield [true];
        yield [false];
    }

    public function testInstallationContextSubstitutionRejectsBeforeKeyLookup(): void
    {
        $this->expectExceptionMessage('INSTALLATION_MISMATCH');
        (new EvidenceProfileAuthenticator($this->primitive()))->observation(ProfileFixtures::bytes('observation'),
            str_repeat('x', 16), EvidenceTestVectors::KEY_ID, str_repeat('0', 64), ProfileFixtures::observationArtifacts());
    }

    public function testKeyIdSubstitutionEvenWithSameSecretNeedsNewFramedMac(): void
    {
        $body = ProfileFixtures::bytes('observation');
        $ring = new SyntheticEvidenceKeyRing([[
            'installation' => EvidenceTestVectors::INSTALLATION, 'purpose' => 'OBSERVATION',
            'key_id' => 'alternate', 'key' => EvidenceTestVectors::AUTH_KEY, 'state' => 'ACTIVE',
        ]]);
        $facade = new EvidenceProfileAuthenticator(new EvidenceAuthenticator($ring));
        $this->expectExceptionMessage('MAC_INVALID');
        $facade->observation($body, EvidenceTestVectors::INSTALLATION, 'alternate', $this->mac('OBSERVATION', $body), ProfileFixtures::observationArtifacts());
    }

    public function testAlternateKeyIdAcceptsOnlyItsOwnValidScopedMac(): void
    {
        $body = ProfileFixtures::bytes('observation');
        $ring = new SyntheticEvidenceKeyRing([[
            'installation' => EvidenceTestVectors::INSTALLATION, 'purpose' => 'OBSERVATION',
            'key_id' => 'alternate', 'key' => EvidenceTestVectors::AUTH_KEY, 'state' => 'ACTIVE',
        ]]);
        $primitive = new EvidenceAuthenticator($ring);
        $mac = $primitive->macHex('OBSERVATION', EvidenceTestVectors::INSTALLATION, 'alternate', $body);
        self::assertTrue((new EvidenceProfileAuthenticator($primitive))->observation($body, EvidenceTestVectors::INSTALLATION,
            'alternate', $mac, ProfileFixtures::observationArtifacts())->cryptographicallyAuthentic);
    }
}
