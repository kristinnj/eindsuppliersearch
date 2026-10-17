<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalValidationException;
use EindSupplierSearch\Protocol\EvidenceAuthenticator;
use EindSupplierSearch\Protocol\EvidenceCryptoException;
use EindSupplierSearch\Protocol\SyntheticEvidenceKeyRing as Ring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyntheticEvidenceKeyRingTest extends TestCase
{
    private static function entry(): array
    {
        return ['installation' => EvidenceTestVectors::INSTALLATION, 'purpose' => 'QUOTE',
            'key_id' => 'public-test', 'key' => str_repeat('PUBLIC-TEST-KEY!!', 2), 'state' => 'ACTIVE'];
    }

    public function testExactScopeAndNoFallback(): void
    {
        $ring = new Ring([self::entry()]);
        self::assertSame(self::entry()['key'], $ring->resolve(EvidenceTestVectors::INSTALLATION, 'QUOTE', 'public-test'));
        foreach ([[str_repeat("\x02", 16), 'QUOTE', 'public-test'],
            [EvidenceTestVectors::INSTALLATION, 'OBSERVATION', 'public-test'],
            [EvidenceTestVectors::INSTALLATION, 'QUOTE', 'missing']] as $scope) {
            try { $ring->resolve(...$scope); self::fail('Fallback'); }
            catch (EvidenceCryptoException $e) { self::assertSame('KEY_UNAVAILABLE', $e->getMessage()); }
            $this->assertAuthenticationRejectsScope($ring, $scope);
        }
    }

    private function assertAuthenticationRejectsScope(Ring $ring, array $scope): void
    {
        $auth = new EvidenceAuthenticator($ring);
        try { $auth->verify(...[ $scope[1], $scope[0], $scope[2], '{}', str_repeat("\0", 32)]); self::fail('Accepted'); }
        catch (EvidenceCryptoException $e) { self::assertSame('KEY_UNAVAILABLE', $e->category); }
    }

    #[DataProvider('inactiveStates')]
    public function testNonactiveKeyFailsSigningAndVerification(string $state): void
    {
        $entry = self::entry(); $entry['state'] = $state;
        $auth = new EvidenceAuthenticator(new Ring([$entry]));
        foreach (['mac', 'verify'] as $method) {
            $args = ['QUOTE', $entry['installation'], $entry['key_id'], '{}'];
            if ($method === 'verify') { $args[] = str_repeat("\0", 32); }
            try { $auth->{$method}(...$args); self::fail('Inactive key accepted'); }
            catch (EvidenceCryptoException $e) { self::assertSame('KEY_UNAVAILABLE', $e->category); }
        }
    }

    public static function inactiveStates(): iterable
    {
        foreach (['VERIFY_ONLY', 'REVOKED', 'UNAVAILABLE', 'UNKNOWN'] as $state) { yield [$state]; }
    }

    #[DataProvider('invalidMaterial')]
    public function testRejectsWrongKeyMaterial(mixed $key): void
    {
        $entry = self::entry(); $entry['key'] = $key;
        $this->expectExceptionMessage('KEY_MATERIAL_INVALID');
        new Ring([$entry]);
    }

    public static function invalidMaterial(): iterable
    {
        foreach (['', str_repeat('x', 19), str_repeat('x', 20), str_repeat('x', 31), null, 32, false, []] as $v) { yield [$v]; }
    }

    public function testFrozenPublicKeyCannotEnterSyntheticRing(): void
    {
        $entry = self::entry();
        $entry['key_id'] = 'doc-public-1';
        $entry['key'] = str_repeat("\x0b", 20);
        $this->expectException(EvidenceCryptoException::class);
        $this->expectExceptionMessage('KEY_MATERIAL_INVALID');
        new Ring([$entry]);
    }

    public function testPublicVectorIdDoesNotPermitOtherShortKeys(): void
    {
        $entry = self::entry(); $entry['key_id'] = 'doc-public-1'; $entry['key'] = str_repeat('x', 20);
        $this->expectExceptionMessage('KEY_MATERIAL_INVALID');
        new Ring([$entry]);
    }

    public function testDuplicateTupleAndUnknownDefinitionReject(): void
    {
        $entry = self::entry();
        foreach ([[$entry, $entry], [array_diff_key($entry, ['key' => true])], [$entry + ['extra' => 'x']],
            [array_replace($entry, ['state' => 'active'])], ['not-an-entry']] as $entries) {
            try { new Ring($entries); self::fail('Invalid ring accepted'); }
            catch (CanonicalValidationException $e) { self::assertSame('KEY_RING_DEFINITION_INVALID', $e->category); }
        }
    }

    public function testPublicVectorKeyRequiresItsExplicitFixtureId(): void
    {
        $entry = self::entry(); $entry['key'] = str_repeat("\x0b", 20);
        $this->expectExceptionMessage('KEY_MATERIAL_INVALID');
        new Ring([$entry]);
    }

    #[DataProvider('invalidScopes')]
    public function testDirectLookupRejectsMalformedScope(mixed $installation, mixed $purpose, mixed $keyId): void
    {
        $this->expectException(CanonicalValidationException::class);
        (new Ring([]))->resolve($installation, $purpose, $keyId);
    }

    public static function invalidScopes(): iterable
    {
        foreach (EvidenceMacFrameTest::invalidFields() as [$purpose, $installation, $keyId, $version]) {
            if ($version === 1) { yield [$installation, $purpose, $keyId]; }
        }
    }

    public function testDebugOutputAndSerializationDoNotExportKeys(): void
    {
        $ring = new Ring([self::entry()]);
        ob_start(); var_dump($ring); $dump = ob_get_clean();
        self::assertStringNotContainsString(self::entry()['key'], $dump);
        self::assertSame('{}', json_encode($ring, JSON_THROW_ON_ERROR));
        $this->expectExceptionMessage('KEY_RING_SERIALIZATION_FORBIDDEN');
        serialize($ring);
    }
}
