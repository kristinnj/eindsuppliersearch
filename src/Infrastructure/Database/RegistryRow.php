<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

/** Strict decoding of untrusted persistence values, independent of SQL coercion. */
final class RegistryRow
{
    public static function integer(mixed $value, bool $allowZero = false): int
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $value)
            || strlen((string) $value) > strlen((string) PHP_INT_MAX)
            || (strlen((string) $value) === strlen((string) PHP_INT_MAX) && strcmp((string) $value, (string) PHP_INT_MAX) > 0)
            || (int) $value < ($allowZero ? 0 : 1)) {
            throw new \UnexpectedValueException('Invalid registry integer.');
        }
        return (int) $value;
    }

    public static function validate(array $row): void
    {
        foreach (['id_binding', 'id_shop', 'id_supplier'] as $field) {
            self::integer($row[$field] ?? null);
        }
        foreach (['order_reference', 'reference_sha256', 'state', 'provenance', 'creation_operation_id', 'created_at', 'updated_at'] as $field) {
            if (!isset($row[$field]) || !is_string($row[$field])) {
                throw new \UnexpectedValueException('Missing registry value.');
            }
        }
        if (!array_key_exists('id_product', $row) || !array_key_exists('id_product_attribute', $row)
            || !array_key_exists('integrity_reason', $row)
            || $row['order_reference'] === '' || strlen($row['order_reference']) > OfferBindingReference::MAX_BYTES
            || strlen($row['reference_sha256']) !== 32 || strlen($row['creation_operation_id']) !== 16
            || !in_array($row['state'], ['RESERVED', 'ACTIVE', 'QUARANTINED', 'RETIRED'], true)
            || $row['provenance'] !== 'MODULE_CREATED_LIVE'
            || ($row['id_product'] === null) !== ($row['id_product_attribute'] === null)) {
            throw new \UnexpectedValueException('Malformed registry evidence.');
        }
        new \EindSupplierSearch\Domain\SupplierOfferIdentity(self::integer($row['id_supplier']), $row['order_reference']);
        if ($row['id_product'] !== null) {
            self::integer($row['id_product']);
            self::integer($row['id_product_attribute'], true);
        }
        $reason = $row['integrity_reason'];
        if (($reason !== null && (!is_string($reason) || $reason === '' || strlen($reason) > 64))
            || (in_array($row['state'], ['RESERVED', 'ACTIVE'], true) && $reason !== null)
            || ($row['state'] === 'QUARANTINED' && $reason === null)) {
            throw new \UnexpectedValueException('Invalid registry lifecycle evidence.');
        }
        foreach (['created_at', 'updated_at'] as $field) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $row[$field], new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d H:i:s.u') !== $row[$field]) {
                throw new \UnexpectedValueException('Invalid registry timestamp.');
            }
        }
        if ($row['updated_at'] < $row['created_at']) {
            throw new \UnexpectedValueException('Invalid registry timestamp order.');
        }
    }
}
