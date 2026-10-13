<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Database;

use EindSupplierSearch\Infrastructure\Database\OfferBindingSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfferBindingSchemaTest extends TestCase
{
    public function testIncompatibleExistingTableIsNotChangedAndLockIsReleased(): void
    {
        $writes = [];
        $reads = [];
        $schema = new OfferBindingSchema('test_', static function (string $sql) use (&$writes): bool {
            $writes[] = $sql;
            return true;
        }, static function (string $sql) use (&$reads): array {
            $reads[] = $sql;
            if (str_contains($sql, '@@sql_mode')) {
                return [['db' => 'test', 'mode' => 'STRICT_TRANS_TABLES', 'checks' => 1, 'uniques' => 1]];
            }
            if (str_contains($sql, 'GET_LOCK')) {
                return [['acquired' => 1]];
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                return [['released' => 1]];
            }
            return [['ENGINE' => 'MyISAM', 'TABLE_COLLATION' => 'utf8mb4_bin', 'TABLE_TYPE' => 'BASE TABLE']];
        });
        try {
            $schema->install();
            self::fail('Incompatible existing table accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Incompatible', $error->getMessage());
        }
        self::assertSame([], $writes);
        self::assertStringContainsString('RELEASE_LOCK', end($reads));
    }

    public function testMetadataFailureDoesNotBecomeAbsenceOrCreateATable(): void
    {
        $schema = new OfferBindingSchema('test_', static function (): bool {
            self::fail('Database write after failed read.');
        }, static fn (): array|false => false);
        $this->expectException(\RuntimeException::class);
        $schema->install();
    }

    #[DataProvider('unsafeSettings')]
    public function testDisabledConstraintEnforcementFailsBeforeWriting(array $settings): void
    {
        $schema = new OfferBindingSchema('test_', static function (): bool {
            self::fail('Database write with disabled constraints.');
        }, static fn (): array => [$settings]);
        $this->expectException(\RuntimeException::class);
        $schema->install();
    }

    public static function unsafeSettings(): array
    {
        return [
            [['db' => 'test', 'mode' => 'STRICT_TRANS_TABLES', 'checks' => 0, 'uniques' => 1]],
            [['db' => 'test', 'mode' => 'STRICT_TRANS_TABLES', 'checks' => 1, 'uniques' => 0]],
            [['db' => 'test', 'mode' => '', 'checks' => 1, 'uniques' => 1]],
            [['db' => null, 'mode' => 'STRICT_TRANS_TABLES', 'checks' => 1, 'uniques' => 1]],
        ];
    }

    public function testDdlFailurePropagatesAndReleasesTheLock(): void
    {
        $released = false;
        $schema = new OfferBindingSchema('test_', static fn (): bool => false,
            static function (string $sql) use (&$released): array {
                if (str_contains($sql, '@@sql_mode')) {
                    return [['db' => 'test', 'mode' => 'STRICT_TRANS_TABLES', 'checks' => 1, 'uniques' => 1]];
                }
                if (str_contains($sql, 'GET_LOCK')) {
                    return [['acquired' => 1]];
                }
                if (str_contains($sql, 'RELEASE_LOCK')) {
                    $released = true;
                    return [['released' => 1]];
                }
                return [];
            });
        try {
            $schema->install();
            self::fail('Failed DDL was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Offer binding schema creation failed.', $error->getMessage());
        }
        self::assertTrue($released);
    }

    public function testLockFailureFailsBeforeWriting(): void
    {
        $schema = new OfferBindingSchema('test_', static function (): bool {
            self::fail('Database write without a migration lock.');
        }, static fn (string $sql): array => str_contains($sql, '@@sql_mode')
            ? [['db' => 'test', 'mode' => 'STRICT_TRANS_TABLES', 'checks' => 1, 'uniques' => 1]] : [['acquired' => 0]]);
        $this->expectException(\RuntimeException::class);
        $schema->install();
    }

    public function testUnsafePrefixIsRejectedBeforeIO(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OfferBindingSchema('bad`prefix', static fn (): bool => true, static fn (): array => []);
    }

    public function testScopedStrictModeRestoresOriginalOnVerificationFailure(): void
    {
        $writes = [];
        $schema = new OfferBindingSchema('test_', static function (string $sql) use (&$writes): bool {
            $writes[] = $sql;
            return true;
        }, static function (string $sql): array {
            if ($sql === 'SELECT @@SESSION.sql_mode AS mode') {
                return [['mode' => 'ANSI_QUOTES']];
            }
            if (str_contains($sql, '@@sql_mode')) {
                return [['db' => 'test', 'mode' => 'ANSI_QUOTES,STRICT_ALL_TABLES', 'checks' => 1, 'uniques' => 1]];
            }
            if (str_contains($sql, 'GET_LOCK')) {
                return [['acquired' => 1]];
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                return [['released' => 1]];
            }
            return [['ENGINE' => 'MyISAM', 'TABLE_COLLATION' => 'utf8mb4_bin', 'TABLE_TYPE' => 'BASE TABLE']];
        });
        try {
            $schema->installWithScopedStrictMode();
            self::fail('Incompatible table accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Incompatible', $error->getMessage());
        }
        self::assertSame(["SET SESSION sql_mode = X'" . bin2hex('ANSI_QUOTES,STRICT_ALL_TABLES') . "'",
            "SET SESSION sql_mode = X'" . bin2hex('ANSI_QUOTES') . "'"], $writes);
    }

    public function testScopedStrictModeFailureStillAttemptsRestoration(): void
    {
        $writes = [];
        $schema = new OfferBindingSchema('test_', static function (string $sql) use (&$writes): bool {
            $writes[] = $sql;
            return count($writes) > 1;
        }, static fn (): array => [['mode' => '']]);
        try {
            $schema->installWithScopedStrictMode();
            self::fail('Failed mode change accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('could not be enabled', $error->getMessage());
        }
        self::assertCount(2, $writes);
        self::assertSame("SET SESSION sql_mode = X''", $writes[1]);
    }

    public function testFailedModeRestorationDoesNotReportSuccess(): void
    {
        $writes = [];
        $schema = new OfferBindingSchema('test_', static function (string $sql) use (&$writes): bool {
            $writes[] = $sql;
            return count($writes) === 1;
        }, static fn (): array => [['mode' => '']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('original SQL mode could not be restored');
        $schema->installWithScopedStrictMode();
    }

}
