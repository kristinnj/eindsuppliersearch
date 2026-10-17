<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\{CanonicalValidationException, QuoteProfileValidator};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteProfileValidatorTest extends TestCase
{
    public function testFrozenCompleteQuote(): void
    {
        $bytes = ProfileFixtures::bytes('quote');
        self::assertSame(EvidenceTestVectors::QUOTE, $bytes);
        self::assertSame('3.720000', (new QuoteProfileValidator())->validate($bytes)->accepted_amounts->gross_line);
    }

    #[DataProvider('mutations')]
    public function testRejectsAtSpecifiedBoundary(string $field, mixed $value, string $code): void
    {
        $this->expectException(CanonicalValidationException::class);
        $this->expectExceptionMessage($code);
        (new QuoteProfileValidator())->validate(ProfileFixtures::mutate(ProfileFixtures::bytes('quote'), $field, $value));
    }

    public static function mutations(): iterable
    {
        yield ['quote_version', '2', 'PROFILE_LITERAL_INVALID'];
        yield ['purpose', 'DISPLAY', 'PROFILE_LITERAL_INVALID'];
        yield ['record_kind', 'OBSERVATION', 'PROFILE_LITERAL_INVALID'];
        yield ['fx', (object) [], 'PROFILE_NULL_REQUIRED'];
        yield ['quantity', '0', 'INTEGER_RANGE_INVALID'];
        yield ['quantity', '4294967296', 'INTEGER_RANGE_INVALID'];
        yield ['quantity', '1e1', 'INTEGER_LEXICAL_INVALID'];
        yield ['quantity', true, 'INTEGER_LEXICAL_INVALID'];
        yield ['currency', 'USD', 'SOURCE_PRICE_MISMATCH'];
        yield ['expires_at', '2026-10-16T12:00:01.000000Z', 'TIME_ORDER_INVALID'];
        yield ['issued_at', '2026-10-16T12:00:01Z', 'TIMESTAMP_INVALID'];
        yield ['tax_basis.rates', (object) [], 'SCHEMA_TYPE_INVALID'];
        yield ['tax_basis.rates', [], 'SCHEMA_LIST_SIZE_INVALID'];
        yield ['tax_basis.rates', ['24.000000', '0.000000'], 'SCHEMA_LIST_SIZE_INVALID'];
        yield ['tax_basis.rates', ['100.000001'], 'DECIMAL_RANGE_INVALID'];
        yield ['tax_basis.rates', ['2.4e1'], 'DECIMAL_LEXICAL_INVALID'];
        yield ['id_tax_rules_group', '2', 'TAX_CONTEXT_MISMATCH'];
        yield ['tax_basis.calculation_policy_sha256', str_repeat('a', 64), 'TAX_CONTEXT_MISMATCH'];
        yield ['commercial_policy.currency_precision', '7', 'INTEGER_RANGE_INVALID'];
        yield ['commercial_policy.markup_method', 'MARGIN', 'PROFILE_LITERAL_INVALID'];
        yield ['commercial_policy.unit_rounding', 'HALF_EVEN', 'PROFILE_LITERAL_INVALID'];
        yield ['commercial_policy.price_basis', 'TAX_INCLUSIVE', 'PROFILE_LITERAL_INVALID'];
        yield ['commercial_policy.minimum_net_line', '3.000001', 'COMMERCIAL_TERMS_INVALID'];
        yield ['unit_price_tax_excl', '1.500001', 'COMMERCIAL_TERMS_INVALID'];
        yield ['accepted_amounts.net_line', '2.999999', 'COMMERCIAL_TERMS_INVALID'];
        yield ['accepted_amounts.tax_line', '0.720001', 'COMMERCIAL_TERMS_INVALID'];
        yield ['accepted_amounts.gross_line', '3.720001', 'COMMERCIAL_TERMS_INVALID'];
        yield ['accepted_amounts.net_line', '0.000000', 'DECIMAL_RANGE_INVALID'];
        yield ['accepted_amounts.tax_line', '-0.000000', 'DECIMAL_LEXICAL_INVALID'];
        yield ['accepted_amounts', [], 'SCHEMA_TYPE_INVALID'];
        yield ['offer_identity', [], 'SCHEMA_TYPE_INVALID'];
        yield ['offer_identity.order_reference_base64', 'MDAwMS1B\n', 'BASE64_NONCANONICAL'];
    }

    public function testFloorEqualityAccepts(): void
    {
        $bytes = ProfileFixtures::mutate(ProfileFixtures::bytes('quote'), 'commercial_policy.minimum_net_line', '3.000000');
        self::assertSame('3.000000', (new QuoteProfileValidator())->validate($bytes)->accepted_amounts->net_line);
    }

    public function testNativeJsonQuantityRejectsBeforeArithmetic(): void
    {
        $this->expectExceptionMessage('JSON_NUMBER_FORBIDDEN');
        (new QuoteProfileValidator())->validate(str_replace('"quantity":"2"', '"quantity":2', ProfileFixtures::bytes('quote')));
    }
}
