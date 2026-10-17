<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalValidationException;
use EindSupplierSearch\Protocol\EvidenceDigest as Digest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceDigestTest extends TestCase
{
    #[DataProvider('vectors')]
    public function testExactBytes(string $bytes, string $hex): void
    {
        self::assertSame($hex, Digest::hex($bytes));
        self::assertSame(hex2bin($hex), Digest::raw($bytes));
        self::assertSame($hex, Digest::toHex(Digest::fromHex($hex)));
    }

    public static function vectors(): iterable
    {
        yield ['', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'];
        yield ["\0\xffA\0", '2d744acc131016f456815e233575b6154853d3cdac6313ea0eee081b10862688'];
        yield ['abc', 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'];
        yield ['{"s":"é"}', '86028b41ba792eaf82aa26a45b218f6734f7f1096a86f1746c8296e088a0ccb4'];
        yield ["{\"s\":\"e\u{301}\"}", '1fc0bd7cc93fca8092a2041d7d01842876422aa7e2838acf42c14578e9f2be05'];
    }

    public function testNoTrimmingNormalizationOrBinaryLoss(): void
    {
        self::assertNotSame(Digest::raw('abc'), Digest::raw("abc\n"));
        self::assertNotSame(Digest::raw('abc'), Digest::raw(' abc'));
        self::assertNotSame(Digest::raw("\0\xffA\0"), Digest::raw("\0\xffA"));
        self::assertSame(32, strlen(Digest::raw("\0\xffA\0")));
    }

    #[DataProvider('invalidTypes')]
    public function testRejectsCoercion(mixed $value): void
    {
        $this->expectException(CanonicalValidationException::class);
        Digest::hex($value);
    }

    public static function invalidTypes(): iterable
    {
        foreach ([null, false, true, 1, 1.0, [], new \stdClass()] as $value) { yield [$value]; }
    }

    #[DataProvider('invalidHex')]
    public function testStrictHex(mixed $hex): void
    {
        $this->expectExceptionMessage('DIGEST_HEX_INVALID');
        Digest::fromHex($hex);
    }

    public static function invalidHex(): iterable
    {
        foreach (['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64),
            str_repeat('a', 64) . "\n", str_repeat("\0", 32), null, 1] as $value) { yield [$value]; }
    }

    #[DataProvider('invalidRaw')]
    public function testStrictRaw(mixed $raw): void
    {
        $this->expectExceptionMessage('DIGEST_RAW_INVALID');
        Digest::toHex($raw);
    }

    public static function invalidRaw(): iterable
    {
        foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('a', 64), null, 1] as $value) { yield [$value]; }
    }
}
