<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalDecimal as Decimal;
use EindSupplierSearch\Protocol\CanonicalValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalDecimalTest extends TestCase
{
    public function testExactBoundsAndFieldPositivity(): void
    {
        foreach (['0.000000','0.000001','0.010000','1.000000',Decimal::MONEY_MAX] as $s) { self::assertSame($s, Decimal::decimal($s)); }
        self::assertSame('0.010000', Decimal::decimal('0.010000', true));
        self::assertSame('100.000000', Decimal::decimal('100.000000', false, '100.000000'));
        self::assertSame('4294967295', Decimal::unsignedInteger('4294967295', true));
        self::assertSame('18446744073709551616', Decimal::unsignedInteger('18446744073709551616', true, '18446744073709551616'));
        self::assertSame('0', Decimal::unsignedInteger('0'));
    }

    #[DataProvider('badDecimals')]
    public function testRejectsDecimal(mixed $s, bool $positive = false, string $max = Decimal::MONEY_MAX): void
    {
        $this->expectException(CanonicalValidationException::class);
        Decimal::decimal($s, $positive, $max);
    }

    public static function badDecimals(): iterable
    {
        foreach (['100000000000000.000000','1','1.0','1.0000000','-0.000000','-1.000000','+1.000000','01.000000','00.010000','1e0',' 1.000000',"1.000000\n",'1.000000 ',1.0,1,null,true] as $s) { yield [$s]; }
        yield ['0.000000', true]; yield ['100.000001', false, '100.000000'];
        yield ['0.010000', false, '0.009999']; yield ['1.000000', false, 'bad'];
    }

    #[DataProvider('badIntegers')]
    public function testRejectsInteger(mixed $s, bool $positive = false): void
    {
        $this->expectException(CanonicalValidationException::class);
        Decimal::unsignedInteger($s, $positive);
    }

    public static function badIntegers(): iterable
    {
        foreach (['4294967296',str_repeat('9',100),'01','+1','-1','1.0','1e1',' 1',"1\n",'',1,1.0,null] as $s) { yield [$s]; }
        yield ['0', true];
    }
}
