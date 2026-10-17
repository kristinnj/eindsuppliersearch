<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalTimestamp as Time;
use EindSupplierSearch\Protocol\CanonicalValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalTimestampTest extends TestCase
{
    public function testExactUtcCalendarAndComparison(): void
    {
        foreach (['1000-01-01T00:00:00.000000Z','9999-12-31T23:59:59.999999Z','2000-02-29T12:34:56.000001Z','2024-02-29T00:00:00.123456Z'] as $s) {
            $date=Time::parse($s);
            self::assertSame($s,$date->format('Y-m-d\TH:i:s.u\Z'));
            self::assertSame('UTC',$date->getTimezone()->getName());
        }
        self::assertSame('2026-10-15 12:34:56.000001',Time::sqlUtc('2026-10-15T12:34:56.000001Z'));
        self::assertSame(-1,Time::compare('2026-01-01T00:00:00.000001Z','2026-01-01T00:00:00.000002Z'));
        self::assertSame(0,Time::compare('2026-01-01T00:00:00.000001Z','2026-01-01T00:00:00.000001Z'));
        self::assertSame(1,Time::compare('2027-01-01T00:00:00.000000Z','2026-12-31T23:59:59.999999Z'));
    }

    #[DataProvider('badValues')]
    public function testRejectsInvalidTime(mixed $value): void
    {
        $this->expectException(CanonicalValidationException::class);
        Time::parse($value);
    }

    public static function badValues(): iterable
    {
        foreach (['0999-12-31T23:59:59.999999Z','10000-01-01T00:00:00.000000Z','1900-02-29T00:00:00.000000Z','2023-02-29T00:00:00.000000Z','2026-04-31T00:00:00.000000Z','2026-00-01T00:00:00.000000Z','2026-13-01T00:00:00.000000Z','2026-01-00T00:00:00.000000Z','2026-01-01T24:00:00.000000Z','2026-01-01T00:60:00.000000Z','2026-01-01T00:00:60.000000Z','2026-01-01T00:00:00.000000+00:00','2026-01-01T00:00:00.000000','2026-01-01T00:00:00.00000Z','2026-01-01T00:00:00.0000000Z','2026-01-01 00:00:00.000000Z',"2026-01-01T00:00:00.000000Z\n",null,1] as $s) { yield [$s]; }
    }
}
