<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalBase64 as Base64;
use EindSupplierSearch\Protocol\CanonicalValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalBase64Test extends TestCase
{
    public function testCanonicalBytesAndBounds(): void
    {
        self::assertSame('A', Base64::decode('QQ=='));
        self::assertSame('303030312d41', bin2hex(Base64::decode('MDAwMS1B')));
        self::assertSame('0020412f2bc3a920', bin2hex(Base64::decode('ACBBLyvDqSA=')));
        self::assertSame('MDAwMS1B', Base64::encode('0001-A'));
        self::assertSame('', Base64::decode('', 0));
        self::assertSame('', Base64::encode('', 0));
        self::assertSame(str_repeat("\xff",4096), Base64::decode(base64_encode(str_repeat("\xff",4096))));
        self::assertSame(base64_encode(str_repeat('a',4096)), Base64::encode(str_repeat('a',4096)));
        self::assertSame('abc', Base64::decode('YWJj', 3, 3));
    }

    #[DataProvider('badValues')]
    public function testRejectsMalformedOrOversize(mixed $value, int $min = 1, int $max = 4096): void
    {
        $this->expectException(CanonicalValidationException::class);
        Base64::decode($value, $min, $max);
    }

    public static function badValues(): iterable
    {
        foreach (['QR==','QQ','Q Q==',"QQ==\n",'QQ===','QQ=','QQ====','QQ==junk','_w==','-w==','*Q==','',null,42,base64_encode(str_repeat('a',4097))] as $s) { yield [$s]; }
        yield ['YWJj',4,4]; yield ['QQ==',-1,2]; yield ['QQ==',2,1]; yield ['QQ==',0,1048577];
    }

    public function testEncodeRejectsOversizeAndEmptyReference(): void
    {
        foreach (['',str_repeat('a',4097)] as $s) {
            try { Base64::encode($s); self::fail('Accepted size'); }
            catch (CanonicalValidationException $e) { self::assertSame('BASE64_SIZE_INVALID',$e->category); }
        }
    }
}
