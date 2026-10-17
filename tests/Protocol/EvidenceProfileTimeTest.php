<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\{EvidenceProfileTime, CanonicalJson};
use PHPUnit\Framework\TestCase;

final class EvidenceProfileTimeTest extends TestCase
{
    public function testPureTimeFindingsAreSeparateFromPermission(): void
    {
        $f = EvidenceProfileTime::quoteTimePredicates('2026-10-16T12:00:30.999999Z',
            ProfileFixtures::bytes('quote'), ProfileFixtures::bytes('observation'), ProfileFixtures::bytes('quote_bundle'));
        self::assertSame(['before_expiry' => true, 'nonnegative_ages' => true,
            'price_age_within_limit' => true, 'stock_age_within_limit' => true, 'purpose_age_within_limit' => true], $f);
        self::assertArrayNotHasKey('eligible', $f);
        self::assertArrayNotHasKey('authorized', $f);
    }

    public function testExactExpiryDeniesEvenIfSourceAgesRemainWithinLimits(): void
    {
        $f = EvidenceProfileTime::quoteTimePredicates('2026-10-16T12:00:31.000000Z',
            ProfileFixtures::bytes('quote'), ProfileFixtures::bytes('observation'), ProfileFixtures::bytes('quote_bundle'));
        self::assertFalse($f['before_expiry']);
        self::assertTrue($f['price_age_within_limit']);
    }

    public function testFutureEffectiveFactsFailNonnegativeAgePredicate(): void
    {
        $f = EvidenceProfileTime::quoteTimePredicates('2026-10-16T11:59:59.999999Z',
            ProfileFixtures::bytes('quote'), ProfileFixtures::bytes('observation'), ProfileFixtures::bytes('quote_bundle'));
        self::assertFalse($f['nonnegative_ages']);
    }

    public function testCheckoutAgeLimitIsIndependentOfPriceStockAndExpiry(): void
    {
        $q = ProfileFixtures::mutate(ProfileFixtures::bytes('quote'), 'purpose', 'CHECKOUT');
        $a = ProfileFixtures::artifacts();
        $a['quote_bundle'] = ProfileFixtures::mutate($a['quote_bundle'], 'purpose', 'CHECKOUT');
        [$o, $q, $a] = ProfileFixtures::rehash(ProfileFixtures::bytes('observation'), $q, $a);
        $f = EvidenceProfileTime::quoteTimePredicates('2026-10-16T12:00:15.000001Z', $q, $o, $a['quote_bundle']);
        self::assertTrue($f['before_expiry']);
        self::assertTrue($f['price_age_within_limit']);
        self::assertTrue($f['stock_age_within_limit']);
        self::assertFalse($f['purpose_age_within_limit']);
    }

    public function testCreateLimitIsPinnedAndNotInheritedFromCheckout(): void
    {
        $q = ProfileFixtures::bytes('quote');
        $a = ProfileFixtures::artifacts();
        $a['quote_bundle'] = ProfileFixtures::mutate($a['quote_bundle'], 'freshness.create_max_age_s', '5');
        [$o, $q, $a] = ProfileFixtures::rehash(ProfileFixtures::bytes('observation'), $q, $a);
        $f = EvidenceProfileTime::quoteTimePredicates('2026-10-16T12:00:05.000001Z', $q, $o, $a['quote_bundle']);
        self::assertTrue($f['before_expiry']);
        self::assertFalse($f['purpose_age_within_limit']);
    }

    public function testWrongObservationInTimeAssessmentRejects(): void
    {
        $this->expectExceptionMessage('OBSERVATION_DIGEST_MISMATCH');
        EvidenceProfileTime::quoteTimePredicates('2026-10-16T12:00:05.000000Z', ProfileFixtures::bytes('quote'),
            ProfileFixtures::mutate(ProfileFixtures::bytes('observation'), 'cache_generation', str_repeat('a', 32)),
            ProfileFixtures::bytes('quote_bundle'));
    }
}
