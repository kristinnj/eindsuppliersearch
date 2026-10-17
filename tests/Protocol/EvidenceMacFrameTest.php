<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalValidationException;
use EindSupplierSearch\Protocol\EvidenceDigest;
use EindSupplierSearch\Protocol\EvidenceMacFrame as Frame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceMacFrameTest extends TestCase
{
    public function testFrozenPrimitiveLayout(): void
    {
        $frame = Frame::build('OBSERVATION', EvidenceTestVectors::INSTALLATION, EvidenceTestVectors::KEY_ID, '{}');
        self::assertSame(EvidenceTestVectors::PRIMITIVE_FRAME_HEX, bin2hex($frame));
        self::assertSame(72, strlen($frame));
        self::assertSame('b569c22141116ab54e96b5bdde1547aabea3101eced5efb87df988d506c1d10e', EvidenceDigest::hex($frame));
        self::assertSame("\x00\x00\x00\x0b", substr($frame, 19, 4));
        self::assertSame("\x00\x00\x00\x0c", substr($frame, 50, 4));
        self::assertSame("\x00\x00\x00\x02{}", substr($frame, -6));
    }

    public function testLengthsAreUtf8BytesAndKeyIdBoundIsExact(): void
    {
        $frame = Frame::build('QUOTE', EvidenceTestVectors::INSTALLATION, 'a' . str_repeat('-', 39), '{"s":"é"}');
        self::assertSame("\x00\x00\x00\x0a", substr($frame, -14, 4));
        self::assertSame('{"s":"é"}', substr($frame, -10));
    }

    #[DataProvider('invalidFields')]
    public function testRejectsFields(mixed $purpose, mixed $installation, mixed $keyId, mixed $version): void
    {
        $this->expectException(CanonicalValidationException::class);
        Frame::build($purpose, $installation, $keyId, '{}', $version);
    }

    public static function invalidFields(): iterable
    {
        $i = EvidenceTestVectors::INSTALLATION;
        foreach (['', 'RECEIPT', 'observation', 'OBSERVATION ', "QUOTE\0", 'QUOTÉ', null, 1] as $p) { yield [$p, $i, 'a', 1]; }
        foreach (['', str_repeat('a', 15), str_repeat('a', 17), str_repeat("\0", 16), bin2hex($i),
            '00000000-0000-0000-0000-000000000001', null, 1234567890123456] as $v) { yield ['QUOTE', $v, 'a', 1]; }
        foreach (['', 'A', '_a', '.a', '-a', 'a/b', 'a:b', "a\0", "a\n", 'é', str_repeat('a', 41), null, 1] as $k) { yield ['QUOTE', $i, $k, 1]; }
        foreach ([0, 2, -1, '1', "\x01", null, true] as $v) { yield ['QUOTE', $i, 'a', $v]; }
    }

    #[DataProvider('invalidBodies')]
    public function testStrictCanonicalBodyBoundary(mixed $body): void
    {
        $this->expectException(CanonicalValidationException::class);
        Frame::build('QUOTE', EvidenceTestVectors::INSTALLATION, 'a', $body);
    }

    public static function invalidBodies(): iterable
    {
        foreach (['', "{}\n", ' {}', '{"z":"1","a":"2"}', '{"a":"\\u0061"}', '{"a":"\\/"}',
            '{"a":"1","\\u0061":"2"}', '[]', 'null', '{}{}', '{"a":1}', "{\"a\":\"\xff\"}",
            null, 1, (object) [], str_repeat(' ', 1048577), str_repeat('{"v":', 17) . '"s"' . str_repeat('}', 17)] as $b) { yield [$b]; }
    }
}
