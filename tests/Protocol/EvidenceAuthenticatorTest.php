<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalValidationException;
use EindSupplierSearch\Protocol\EvidenceAuthenticator as Auth;
use EindSupplierSearch\Protocol\EvidenceCryptoException;
use EindSupplierSearch\Protocol\EvidenceDigest;
use EindSupplierSearch\Protocol\EvidenceKeyRing;
use EindSupplierSearch\Protocol\EvidenceMacFrame;
use EindSupplierSearch\Protocol\SyntheticEvidenceKeyRing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceAuthenticatorTest extends TestCase
{
    #[DataProvider('goldenVectors')]
    public function testFrozenVectors(string $purpose, string $body, int $bodyLength, string $bodyHash, int $frameLength, string $frameHash, string $mac): void
    {
        $i = EvidenceTestVectors::INSTALLATION;
        $k = EvidenceTestVectors::KEY_ID;
        self::assertSame($bodyLength, strlen($body));
        self::assertSame($bodyHash, EvidenceDigest::hex($body));
        $frame = EvidenceMacFrame::build($purpose, $i, $k, $body);
        self::assertSame($frameLength, strlen($frame));
        self::assertSame($frameHash, EvidenceDigest::hex($frame));
        // Historical public vectors are reference calculations only, not an
        // exception in the ordinary authenticator or an alternate runtime API.
        self::assertSame($mac, hash_hmac('sha256', $frame, str_repeat("\x0b", 20)));
        self::assertSame(hex2bin($mac), hash_hmac('sha256', $frame, str_repeat("\x0b", 20), true));
    }

    public static function goldenVectors(): iterable
    {
        yield ['OBSERVATION', EvidenceTestVectors::OBSERVATION, 1931,
            '6054f682855f9488e60695608f684a1b8092255d24bdf1ec24c4ecbd08fc839a', 2001,
            '1ce82c5684adeb36b5c5fb1c4a0ea6c28ab638a19baba86521ffde229a0f50d8',
            '101539f9e8dd1f9b35c293da1b5668d3f461b4b2f7e86b8e42a020ff91a33fac'];
        yield ['QUOTE', EvidenceTestVectors::QUOTE, 1743,
            'c004e87e2909ef0dfb3a96c27b7c0ca74b49818df8883b01a2ba560be8956bea', 1807,
            '6e8948bd54288b92554ee8f0f7efcdfc296069287dcdbcaecda61dcdf4637b52',
            '420c5985eb55a1f4a1a09403d01e55a750f014088c690d3c60f074b449cfd203'];
        yield ['OBSERVATION', '{}', 2,
            '44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a', 72,
            'b569c22141116ab54e96b5bdde1547aabea3101eced5efb87df988d506c1d10e',
            '11fcb551cbd6f7252acbadc7e59ce0757517c7ab95e8c98a49b736e2aab151c2'];
    }

    public function testRfc4231PlatformKnownAnswer(): void
    {
        // RFC 4231 case 1 has no evidence frame. This independently checks the
        // native primitive; the frozen tests above check evidence framing with the public key.
        self::assertSame('b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7',
            hash_hmac('sha256', 'Hi There', str_repeat("\x0b", 20)));
    }

    public function testContextSubstitutionsFailEvenWhenKeysAreIdentical(): void
    {
        $i = EvidenceTestVectors::INSTALLATION;
        $other = str_repeat("\x02", 16);
        $entries = [];
        foreach ([$i, $other] as $installation) {
            foreach (['OBSERVATION', 'QUOTE'] as $purpose) {
                foreach (['doc-public-1', 'other-key'] as $keyId) {
                    $entries[] = ['installation' => $installation, 'purpose' => $purpose,
                        'key_id' => $keyId, 'key' => str_repeat('PUBLIC-TEST-KEY!!', 2), 'state' => 'ACTIVE'];
                }
            }
        }
        $auth = new Auth(new SyntheticEvidenceKeyRing($entries));
        $mac = $auth->mac('OBSERVATION', $i, 'doc-public-1', '{"v":"a"}');
        self::assertFalse($auth->verify('QUOTE', $i, 'doc-public-1', '{"v":"a"}', $mac));
        self::assertFalse($auth->verify('OBSERVATION', $other, 'doc-public-1', '{"v":"a"}', $mac));
        self::assertFalse($auth->verify('OBSERVATION', $i, 'other-key', '{"v":"a"}', $mac));
        self::assertFalse($auth->verify('OBSERVATION', $i, 'doc-public-1', '{"v":"b"}', $mac));
        self::assertFalse($auth->verifyHex('OBSERVATION', $i, 'doc-public-1', '{"v":"b"}', bin2hex($mac)));
        $mac[0] = chr(ord($mac[0]) ^ 1);
        self::assertFalse($auth->verify('OBSERVATION', $i, 'doc-public-1', '{"v":"a"}', $mac));
        self::assertNotSame(EvidenceMacFrame::build('QUOTE', $i, 'other-key', '{}'),
            EvidenceMacFrame::build('QUOTE', $other, 'other-key', '{}'));
    }

    #[DataProvider('noncanonicalBodies')]
    public function testAllAuthenticationPathsRejectRatherThanRepair(mixed $body, string $method): void
    {
        $auth = new Auth(EvidenceTestVectors::ring());
        $this->expectException(CanonicalValidationException::class);
        $args = ['OBSERVATION', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $body];
        if ($method === 'verify') { $args[] = str_repeat("\0", 32); }
        if ($method === 'verifyHex') { $args[] = str_repeat('0', 64); }
        $auth->{$method}(...$args);
    }

    public static function noncanonicalBodies(): iterable
    {
        foreach (EvidenceMacFrameTest::invalidBodies() as [$body]) {
            foreach (['mac', 'macHex', 'verify', 'verifyHex'] as $method) { yield [$body, $method]; }
        }
    }

    #[DataProvider('malformedMacs')]
    public function testMalformedMacRejects(mixed $mac, bool $hex): void
    {
        $this->expectException(CanonicalValidationException::class);
        $auth = new Auth(EvidenceTestVectors::ring());
        $method = $hex ? 'verifyHex' : 'verify';
        $auth->{$method}('QUOTE', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, '{}', $mac);
    }

    public static function malformedMacs(): iterable
    {
        foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('a', 64), null, 1, false, []] as $v) { yield [$v, false]; }
        foreach (['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64),
            str_repeat('0', 64) . "\n", str_repeat('0', 63) . "\0", str_repeat("\xff", 32), null, 1, []] as $v) { yield [$v, true]; }
    }

    public function testMaximumBodyAndOversize(): void
    {
        $auth = new Auth(EvidenceTestVectors::ring());
        $body = '{"s":"' . str_repeat('x', 1048568) . '"}';
        $mac = $auth->mac('QUOTE', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $body);
        self::assertSame(32, strlen($mac));
        self::assertTrue($auth->verify('QUOTE', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $body, $mac));
        $this->expectExceptionMessage('JSON_SIZE_EXCEEDED');
        $auth->mac('QUOTE', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, $body . ' ');
    }

    public function testWrongFrameLayoutsCannotAuthenticate(): void
    {
        $auth = new Auth(EvidenceTestVectors::ring());
        $frame = hex2bin(EvidenceTestVectors::PRIMITIVE_FRAME_HEX);
        $wrongLength = $frame;
        $wrongLength[19] = "\x0b"; $wrongLength[22] = "\0"; // little endian LP(purpose)
        $wrongVersion = $frame; $wrongVersion[18] = "\x02";
        $missingInstallation = substr($frame, 0, 34) . substr($frame, 50);
        foreach ([$wrongLength, $wrongVersion, $missingInstallation, $frame . "\0", $frame . "\n", substr($frame, 0, -1)] as $bad) {
            $mac = hash_hmac('sha256', $bad, EvidenceTestVectors::AUTH_KEY, true);
            self::assertFalse($auth->verify('OBSERVATION', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, '{}', $mac));
        }
    }

    public function testExactTupleSelectsDifferentPublicKeys(): void
    {
        $entries = []; $contexts = [];
        foreach ([EvidenceTestVectors::INSTALLATION, str_repeat("\x02", 16)] as $installation) {
            foreach (['OBSERVATION', 'QUOTE'] as $purpose) {
                $index = count($entries);
                $entries[] = ['installation' => $installation, 'purpose' => $purpose,
                    'key_id' => 'public-test', 'key' => str_repeat(chr(65 + $index), 32), 'state' => 'ACTIVE'];
                $contexts[] = [$purpose, $installation, 'public-test', '{}'];
            }
        }
        $auth = new Auth(new SyntheticEvidenceKeyRing($entries));
        // Independent Python hmac/struct known answers; no implementation-derived oracle.
        $expected = [
            'c305edd622f5a689573cc351b3fcc1b83bdc3c3db57d7493e82bc79348f85d84',
            '1f4fffc36f423a462527ed2ac558698326d3d3c3adf78f27b2824b56a41ebea5',
            'a0b2cd6f3da0c47dc58fc4d1e3f1d42ae20bd0d3a4aa56812af0a419b77cb65a',
            '774eb1976a204f8d70affe4f83d2fcd887314f8a701a30962d6b65e33f529e1c',
        ];
        foreach ($contexts as $index => $context) {
            self::assertSame($expected[$index], $auth->macHex(...$context));
        }
        $frame = EvidenceMacFrame::build(...$contexts[0]);
        $otherKeyMac = hash_hmac('sha256', $frame, $entries[1]['key'], true);
        self::assertFalse($auth->verify(...[...$contexts[0], $otherKeyMac]));
    }

    public function testCanonicalValidationPrecedesKeyResolution(): void
    {
        $ring = new class implements EvidenceKeyRing {
            public int $calls = 0;
            public function resolve(mixed $installation, mixed $purpose, mixed $keyId): string
            {
                ++$this->calls;
                throw new EvidenceCryptoException('KEY_UNAVAILABLE');
            }
        };
        $auth = new Auth($ring);
        try { $auth->mac('QUOTE', EvidenceTestVectors::INSTALLATION, 'missing', "{}\n"); self::fail('Accepted'); }
        catch (CanonicalValidationException $e) { self::assertSame('NONCANONICAL_JSON', $e->category); }
        self::assertSame(0, $ring->calls);
    }

    #[DataProvider('keyBoundaries')]
    public function testEveryAuthenticationPathEnforcesKeyMaterial(string $key, bool $accepted, string $installation, string $purpose, string $method): void
    {
        $ring = new class($key) implements EvidenceKeyRing {
            public function __construct(private readonly string $key) {}
            public function resolve(mixed $installation, mixed $purpose, mixed $keyId): string { return $this->key; }
        };
        $body = '{"origin":"LIVE"}';
        $args = [$purpose, $installation, 'doc-public-1', $body];
        // Matching MAC, independently framed without calling EvidenceMacFrame.
        $lp = static fn (string $bytes): string => pack('N', strlen($bytes)) . $bytes;
        $frame = "EIND-EVIDENCE-MAC\x00\x01" . $lp($purpose) . $installation
            . $lp('doc-public-1') . $lp($body);
        $expected = hash_hmac('sha256', $frame, $key, true);
        if ($method === 'verify') { $args[] = $expected; }
        if ($method === 'verifyHex') { $args[] = bin2hex($expected); }
        if (!$accepted) {
            $this->expectException(EvidenceCryptoException::class);
            $this->expectExceptionMessage('KEY_MATERIAL_INVALID');
        }
        $result = (new Auth($ring))->{$method}(...$args);
        if ($accepted) {
            self::assertSame(match ($method) {
                'mac' => $expected,
                'macHex' => bin2hex($expected),
                default => true,
            }, $result);
        }
    }

    public static function keyBoundaries(): iterable
    {
        foreach ([[str_repeat("\x0b", 20), false], [str_repeat('x', 31), false],
            [str_repeat('x', 32), true], [str_repeat('x', 33), true]] as [$key, $accepted]) {
            foreach ([EvidenceTestVectors::INSTALLATION, str_repeat("\x22", 16)] as $installation) {
                foreach (['OBSERVATION', 'QUOTE'] as $purpose) {
                    foreach (['mac', 'macHex', 'verify', 'verifyHex'] as $method) {
                        yield [$key, $accepted, $installation, $purpose, $method];
                    }
                }
            }
        }
    }

    public function testUnknownKeyRejectsEveryAuthenticationPath(): void
    {
        $auth = new Auth(new SyntheticEvidenceKeyRing([]));
        foreach (['mac', 'macHex', 'verify', 'verifyHex'] as $method) {
            $args = ['QUOTE', EvidenceTestVectors::INSTALLATION, 'missing', '{}'];
            if ($method === 'verify') { $args[] = str_repeat("\0", 32); }
            if ($method === 'verifyHex') { $args[] = str_repeat('0', 64); }
            try { $auth->{$method}(...$args); self::fail('Unknown key accepted'); }
            catch (EvidenceCryptoException $e) { self::assertSame('KEY_UNAVAILABLE', $e->category); }
        }
    }

    public function testMisimplementedRingCannotSupplyShortKey(): void
    {
        $ring = new class implements EvidenceKeyRing {
            public function resolve(mixed $installation, mixed $purpose, mixed $keyId): string { return 'short-public-test-key'; }
        };
        $this->expectExceptionMessage('KEY_MATERIAL_INVALID');
        (new Auth($ring))->mac('QUOTE', EvidenceTestVectors::INSTALLATION, 'a', '{}');
    }
}
