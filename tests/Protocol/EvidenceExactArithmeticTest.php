<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\{CanonicalJson, EvidenceExactArithmetic, QuoteProfileValidator, EvidenceProfileTime};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceExactArithmeticTest extends TestCase
{
    #[DataProvider('prices')]
    public function testIndependentExactRecipeCases(string $cost, string $bps, string $quantity, string $precision,
        string $rate, string $unit, string $net, string $tax, string $gross): void
    {
        $q = CanonicalJson::decode(ProfileFixtures::bytes('quote'));
        $q->source_price->unit_price_tax_excl = $cost;
        $q->commercial_policy->markup_rate_bps = $bps;
        $q->commercial_policy->currency_precision = $precision;
        $q->quantity = $quantity;
        $q->tax_basis->rates = [$rate];
        $q->unit_price_tax_excl = $unit;
        $q->accepted_amounts = (object) ['net_line' => $net, 'tax_line' => $tax, 'gross_line' => $gross];
        self::assertSame($unit, (new QuoteProfileValidator())->validate(CanonicalJson::encode($q))->unit_price_tax_excl);
    }

    public static function prices(): iterable
    {
        yield 'unit rounding tie' => ['0.000001', '5000', '1', '6', '0.000000', '0.000002', '0.000002', '0.000000', '0.000002'];
        yield 'line rounding tie' => ['0.005000', '0', '1', '2', '0.000000', '0.005000', '0.010000', '0.000000', '0.010000'];
        yield 'tax rounding tie' => ['0.010000', '0', '1', '2', '50.000000', '0.010000', '0.010000', '0.010000', '0.020000'];
        yield 'precision zero' => ['1.500000', '0', '1', '0', '0.000000', '1.500000', '2.000000', '0.000000', '2.000000'];
        yield 'maximum above int64 scaled' => ['99999999999999.999999', '0', '1', '6', '0.000000', '99999999999999.999999', '99999999999999.999999', '0.000000', '99999999999999.999999'];
        yield 'max bps exact' => ['0.000001', '4294967295', '1', '6', '0.000000', '0.429498', '0.429498', '0.000000', '0.429498'];
    }

    public function testMonetaryOverflowRejects(): void
    {
        $q = CanonicalJson::decode(ProfileFixtures::bytes('quote'));
        $q->source_price->unit_price_tax_excl = '99999999999999.999999';
        $this->expectExceptionMessage('DECIMAL_RANGE_INVALID');
        (new QuoteProfileValidator())->validate(CanonicalJson::encode($q));
    }

    public function testZeroRoundedNetRejectsWithDisabledFloor(): void
    {
        $q = CanonicalJson::decode(ProfileFixtures::bytes('quote'));
        $q->source_price->unit_price_tax_excl = $q->unit_price_tax_excl = '0.000001';
        $q->commercial_policy->markup_rate_bps = '0';
        $q->quantity = '1';
        $this->expectExceptionMessage('DECIMAL_RANGE_INVALID');
        (new QuoteProfileValidator())->validate(CanonicalJson::encode($q));
    }

    public function testMaximumIntegerProductDoesNotUseInt64Money(): void
    {
        self::assertSame('18446744065119617025', EvidenceExactArithmetic::multiply('4294967295', '4294967295'));
        self::assertSame('100000000000000000000', EvidenceExactArithmetic::add('99999999999999999999', '1'));
    }

    public function testTimestampOverflowRejectsWithoutClamping(): void
    {
        $this->expectExceptionMessage('TIMESTAMP_INVALID');
        EvidenceProfileTime::add('9999-12-31T23:59:59.999999Z', '1');
    }

    public function testExpiryPredicatePreservesMicroseconds(): void
    {
        self::assertSame('2026-10-16T12:00:20.123456Z', EvidenceProfileTime::add('2026-10-16T12:00:00.123456Z', '20'));
        self::assertTrue(EvidenceProfileTime::beforeExpiry('2026-10-16T12:00:30.999999Z', '2026-10-16T12:00:31.000000Z'));
        self::assertFalse(EvidenceProfileTime::beforeExpiry('2026-10-16T12:00:31.000000Z', '2026-10-16T12:00:31.000000Z'));
    }
}
