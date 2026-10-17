<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\{CanonicalJson, CanonicalValidationException, ObservationProfileValidator};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObservationProfileValidatorTest extends TestCase
{
    public function testFrozenCompleteBody(): void
    {
        $bytes = ProfileFixtures::bytes('observation');
        self::assertSame(EvidenceTestVectors::OBSERVATION, $bytes);
        self::assertSame('FIXTURE', (new ObservationProfileValidator())->validate($bytes)->origin);
    }

    #[DataProvider('invalidFields')]
    public function testRejectsAtTheSpecifiedBoundary(string $path, mixed $value, string $code): void
    {
        $this->expectException(CanonicalValidationException::class);
        $this->expectExceptionMessage($code);
        $bytes = ProfileFixtures::mutate(ProfileFixtures::bytes('observation'), $path, $value);
        if ($path === 'normalized_payload.supplier_cost_currency') {
            $o = CanonicalJson::decode($bytes);
            $o->normalized_payload_sha256 = hash('sha256', CanonicalJson::encode($o->normalized_payload));
            $bytes = CanonicalJson::encode($o);
        }
        (new ObservationProfileValidator())->validate($bytes);
    }

    public static function invalidFields(): iterable
    {
        yield ['unknown', 'x', 'SCHEMA_UNKNOWN_FIELD'];
        yield ['schema_version', '2', 'PROFILE_LITERAL_INVALID'];
        yield ['body_profile', 'old', 'PROFILE_LITERAL_INVALID'];
        yield ['origin', 'CACHE', 'PROFILE_LITERAL_INVALID'];
        yield ['origin', 'LIVE', 'ORIGIN_MISMATCH'];
        yield ['provenance.source_authentication', 'TLS_API_CREDENTIAL', 'ORIGIN_MISMATCH'];
        yield ['provenance.endpoint_id', 'live', 'ORIGIN_MISMATCH'];
        yield ['provenance.credential_version_id', 'live', 'ORIGIN_MISMATCH'];
        yield ['provenance', [], 'SCHEMA_TYPE_INVALID'];
        yield ['normalized_payload', [], 'SCHEMA_TYPE_INVALID'];
        yield ['normalized_payload.name_by_language', [], 'SCHEMA_TYPE_INVALID'];
        yield ['normalized_payload.name_by_language', (object) [], 'PROFILE_LANGUAGE_COUNT_INVALID'];
        yield ['normalized_payload.name_by_language', (object) ['01' => 'x'], 'INTEGER_LEXICAL_INVALID'];
        yield ['normalized_payload.name_by_language', (object) ['1' => str_repeat('x', 4097)], 'PROFILE_TEXT_INVALID'];
        yield ['normalized_payload.description_by_language', (object) ['1' => str_repeat('x', 65537)], 'PROFILE_TEXT_INVALID'];
        yield ['normalized_payload.name_by_language', (object) array_fill(1, 17, 'x'), 'PROFILE_LANGUAGE_COUNT_INVALID'];
        yield ['normalized_payload.id_manufacturer', '0', 'INTEGER_RANGE_INVALID'];
        yield ['normalized_payload.minimum_quantity', '0', 'INTEGER_RANGE_INVALID'];
        yield ['normalized_payload.quantity_step', '4294967296', 'INTEGER_RANGE_INVALID'];
        yield ['normalized_payload.weight', '-0.000000', 'DECIMAL_LEXICAL_INVALID'];
        yield ['normalized_payload.supplier_cost_tax_excl', '0.000000', 'DECIMAL_RANGE_INVALID'];
        yield ['normalized_payload.supplier_cost_currency', 'USD', 'SOURCE_PRICE_MISMATCH'];
        yield ['normalized_payload_sha256', str_repeat('a', 64), 'NORMALIZED_PAYLOAD_DIGEST_MISMATCH'];
        yield ['unit_price_tax_excl', '1.250001', 'SOURCE_PRICE_MISMATCH'];
        yield ['unit_price_tax_excl', '1.25', 'DECIMAL_LEXICAL_INVALID'];
        yield ['unit_price_tax_excl', '0.000000', 'DECIMAL_RANGE_INVALID'];
        yield ['unit_price_tax_excl', '100000000000000.000000', 'DECIMAL_RANGE_INVALID'];
        yield ['available_quantity', null, 'INTEGER_LEXICAL_INVALID'];
        yield ['id_shop', '01', 'INTEGER_LEXICAL_INVALID'];
        yield ['id_supplier', '0', 'INTEGER_RANGE_INVALID'];
        yield ['auth_scope', str_repeat('0', 32), 'PROFILE_ID_INVALID'];
        yield ['auth_scope', '00000000000000000000000000000002', 'PROFILE_IDS_NOT_DISTINCT'];
        yield ['installation_id', str_repeat('A', 32), 'PROFILE_ID_INVALID'];
        yield ['adapter_id', '../unsafe', 'PROFILE_LABEL_INVALID'];
        yield ['adapter_version', str_repeat('x', 33), 'PROFILE_LABEL_INVALID'];
        yield ['currency', 'eur', 'PROFILE_CURRENCY_INVALID'];
        yield ['order_reference_base64', 'QR==', 'BASE64_NONCANONICAL'];
        yield ['order_reference_base64', base64_encode(" \t"), 'PROFILE_REFERENCE_BLANK'];
        yield ['observed_at', '2026-02-30T12:00:00.000000Z', 'TIMESTAMP_INVALID'];
        yield ['expires_at', '2026-10-16T12:00:00.000000Z', 'TIME_ORDER_INVALID'];
        yield ['availability_observed_at', '2026-10-16T12:00:01.000000Z', 'TIME_ORDER_INVALID'];
        yield ['provenance.price_observed_at', '2026-10-16T12:00:01.000000Z', 'TIME_ORDER_INVALID'];
        yield ['provenance.received_at', '2026-10-16T12:00:01.000000Z', 'TIME_ORDER_INVALID'];
        yield ['provenance.request_started_at', '2026-10-16T12:00:01.000000Z', 'TIME_ORDER_INVALID'];
        yield ['provenance.unit', 'PACK', 'PROFILE_LITERAL_INVALID'];
        yield ['provenance.stock_basis', 'BACKORDER', 'PROFILE_LITERAL_INVALID'];
    }

    #[DataProvider('badEncoding')]
    public function testOriginalNoncanonicalBytesReject(string $bytes, string $code): void
    {
        $this->expectException(CanonicalValidationException::class);
        $this->expectExceptionMessage($code);
        (new ObservationProfileValidator())->validate($bytes);
    }

    public static function badEncoding(): iterable
    {
        $b = ProfileFixtures::bytes('observation');
        yield [$b . "\n", 'NONCANONICAL_JSON'];
        yield [str_replace('"available_quantity":"10"', '"available_quantity":10', $b), 'JSON_NUMBER_FORBIDDEN'];
        yield [str_replace('"adapter_id":', '"adapter_id":"x","adapter_id":', $b), 'JSON_DUPLICATE_KEY'];
        yield [str_replace('"adapter_id":', '"adapter_id":"x","\\u0061dapter_id":', $b), 'JSON_DUPLICATE_KEY'];
        yield [str_replace('documentation-fixture', '\\u0064ocumentation-fixture', $b), 'NONCANONICAL_JSON'];
        $o = CanonicalJson::decode($b);
        yield [json_encode((object) array_reverse(get_object_vars($o), true), JSON_UNESCAPED_SLASHES), 'NONCANONICAL_JSON'];
    }
}
