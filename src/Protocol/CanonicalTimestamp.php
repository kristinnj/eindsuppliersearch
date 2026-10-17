<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

final class CanonicalTimestamp
{
    public static function parse(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $value) !== 1) {
            throw new CanonicalValidationException('TIMESTAMP_INVALID');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new CanonicalValidationException('TIMESTAMP_INVALID');
        }
        return $date;
    }

    public static function compare(mixed $left, mixed $right): int
    {
        self::parse($left);
        self::parse($right);
        return strcmp($left, $right) <=> 0;
    }

    public static function sqlUtc(mixed $value): string
    {
        return self::parse($value)->format('Y-m-d H:i:s.u');
    }
}
