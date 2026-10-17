<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\{CanonicalJson, CanonicalValidationException, EvidenceArtifactValidator,
    ObservationProfileValidator, QuoteProfileValidator, EvidenceDigest, EvidenceMacFrame};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceArtifactValidatorTest extends TestCase
{
    #[DataProvider('golden')]
    public function testAllTwelveFrozenCanonicalFixtures(string $kind, string $digest): void
    {
        $bytes = ProfileFixtures::bytes($kind);
        self::assertSame($digest, EvidenceDigest::hex($bytes));
        self::assertSame($bytes, CanonicalJson::encode(CanonicalJson::decode($bytes)));
        if ($kind === 'observation' || $kind === 'quote') {
            $validator = $kind === 'observation' ? new ObservationProfileValidator() : new QuoteProfileValidator();
            self::assertSame($bytes, CanonicalJson::encode($validator->validate($bytes)));
        } else {
            self::assertSame($bytes, CanonicalJson::encode((new EvidenceArtifactValidator())->validate($kind, $bytes, $digest)));
        }
    }

    public static function golden(): iterable
    {
        foreach (ProfileFixtures::DIGESTS as $kind => $digest) { yield $kind => [$kind, $digest]; }
    }

    /** Exhaust every declared required key/closed nested object in the frozen examples. */
    #[DataProvider('closedObjects')]
    public function testEveryClosedObjectRejectsMissingOrUnknownField(string $kind, string $path, bool $missing): void
    {
        $bytes = ProfileFixtures::bytes($kind);
        $body = CanonicalJson::decode($bytes);
        $node = $body;
        if ($path !== '') { foreach (explode('.', $path) as $part) { $node = $node->$part; } }
        $field = array_key_first(get_object_vars($node));
        if ($missing) { unset($node->$field); } else { $node->unknown = 'x'; }
        $this->expectException(CanonicalValidationException::class);
        $this->expectExceptionMessage($missing ? 'SCHEMA_MISSING_FIELD' : 'SCHEMA_UNKNOWN_FIELD');
        $this->validate($kind, CanonicalJson::encode($body));
    }

    public static function closedObjects(): iterable
    {
        foreach (array_keys(ProfileFixtures::DIGESTS) as $kind) {
            $walk = function ($node, string $path) use (&$walk, $kind): iterable {
                if (!$node instanceof \stdClass || str_ends_with($path, '_by_language')) { return; }
                foreach ([true, false] as $missing) { yield "$kind:$path:" . ($missing ? 'missing' : 'unknown') => [$kind, $path, $missing]; }
                foreach (get_object_vars($node) as $key => $value) {
                    yield from $walk($value, $path === '' ? $key : "$path.$key");
                }
            };
            yield from $walk(CanonicalJson::decode(ProfileFixtures::bytes($kind)), '');
        }
    }

    #[DataProvider('requiredFields')]
    public function testEveryRequiredFieldRejectsOmission(string $kind, string $path): void
    {
        $this->expectExceptionMessage('SCHEMA_MISSING_FIELD');
        $this->validate($kind, ProfileFixtures::mutate(ProfileFixtures::bytes($kind), $path, null, true));
    }

    public static function requiredFields(): iterable
    {
        foreach (array_keys(ProfileFixtures::DIGESTS) as $kind) {
            $walk = function ($node, string $path) use (&$walk, $kind): iterable {
                if (!$node instanceof \stdClass || str_ends_with($path, '_by_language')) { return; }
                foreach (get_object_vars($node) as $key => $value) {
                    $child = $path === '' ? $key : "$path.$key";
                    yield "$kind:$child" => [$kind, $child];
                    yield from $walk($value, $child);
                }
            };
            yield from $walk(CanonicalJson::decode(ProfileFixtures::bytes($kind)), '');
        }
    }

    #[DataProvider('artifactMutations')]
    public function testArtifactDomainChecksThrow(string $kind, string $path, mixed $value, string $code): void
    {
        $this->expectException(CanonicalValidationException::class);
        $this->expectExceptionMessage($code);
        (new EvidenceArtifactValidator())->validate($kind, ProfileFixtures::mutate(ProfileFixtures::bytes($kind), $path, $value));
    }

    public static function artifactMutations(): iterable
    {
        yield ['transport', 'connect_timeout_s', '11', 'TRANSPORT_TIMEOUT_INVALID'];
        yield ['transport', 'endpoint_origin', '', 'TRANSPORT_ENDPOINT_INVALID'];
        yield ['transport', 'endpoint_origin', 'https://user@host', 'ORIGIN_MISMATCH'];
        yield ['transport', 'endpoint_path', 'none#fragment', 'TRANSPORT_ENDPOINT_INVALID'];
        yield ['transport', 'method', 'GET', 'ORIGIN_MISMATCH'];
        yield ['integration', 'mapping_version', '0', 'INTEGER_RANGE_INVALID'];
        yield ['observation_policy', 'freshness.price_max_age_s', '0', 'INTEGER_RANGE_INVALID'];
        yield ['observation_policy', 'freshness.clock_skew_s', '-1', 'INTEGER_LEXICAL_INVALID'];
        yield ['supplier_validity', 'validity_mode', 'UNKNOWN', 'PROFILE_LITERAL_INVALID'];
        yield ['supplier_validity', 'source_cache_mode', 'CACHE_ACCESS', 'PROFILE_LITERAL_INVALID'];
        yield ['supplier_validity', 'validity_anchor', 'observed_at', 'SUPPLIER_VALIDITY_INVALID'];
        yield ['supplier_validity', 'validity_max_age_s', '1', 'SUPPLIER_VALIDITY_INVALID'];
        yield ['approval_manifest', 'environment', 'PRODUCTION', 'ORIGIN_MISMATCH'];
        yield ['approval_manifest', 'effective_until', '2026-10-16T11:00:00.000000Z', 'APPROVAL_INTERVAL_INVALID'];
        yield ['approval_manifest', 'approved', true, 'SCHEMA_UNKNOWN_FIELD'];
        yield ['tax', 'calculation_method', 'MULTIPLE_RATES', 'PROFILE_LITERAL_INVALID'];
        yield ['quote_bundle', 'policy_version', '2', 'POLICY_CONTEXT_MISMATCH'];
    }

    public function testIndependentDigestRejectsWrongBytes(): void
    {
        $this->expectExceptionMessage('POLICY_DIGEST_MISMATCH');
        (new EvidenceArtifactValidator())->validate('markup', ProfileFixtures::bytes('markup'), str_repeat('a', 64));
    }

    public function testEachArtifactMustBeCanonical(): void
    {
        $this->expectExceptionMessage('NONCANONICAL_JSON');
        (new EvidenceArtifactValidator())->validate('markup', ProfileFixtures::bytes('markup') . "\n");
    }

    public function testFrozenFramesAndPublicShortKeyOnlyInReferenceCalculation(): void
    {
        foreach ([['observation', 'OBSERVATION', 1931, 2001,
            '1ce82c5684adeb36b5c5fb1c4a0ea6c28ab638a19baba86521ffde229a0f50d8',
            '101539f9e8dd1f9b35c293da1b5668d3f461b4b2f7e86b8e42a020ff91a33fac'],
            ['quote', 'QUOTE', 1743, 1807, '6e8948bd54288b92554ee8f0f7efcdfc296069287dcdbcaecda61dcdf4637b52',
            '420c5985eb55a1f4a1a09403d01e55a750f014088c690d3c60f074b449cfd203']] as [$kind, $purpose, $bodyLength, $frameLength, $frameHash, $mac]) {
            $bytes = ProfileFixtures::bytes($kind);
            $frame = EvidenceMacFrame::build($purpose, EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $bytes);
            self::assertSame($bodyLength, strlen($bytes));
            self::assertSame($frameLength, strlen($frame));
            self::assertSame($frameHash, hash('sha256', $frame));
            // Isolated public reference oracle. Never passed to the production authenticator.
            self::assertSame($mac, hash_hmac('sha256', $frame, str_repeat("\x0b", 20)));
        }
    }

    private function validate(string $kind, string $bytes): void
    {
        match ($kind) {
            'observation' => (new ObservationProfileValidator())->validate($bytes),
            'quote' => (new QuoteProfileValidator())->validate($bytes),
            default => (new EvidenceArtifactValidator())->validate($kind, $bytes),
        };
    }
}
