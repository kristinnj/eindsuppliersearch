<?php

declare(strict_types=1);
namespace EindSupplierSearch\Tests\Infrastructure\Database;

use EindSupplierSearch\Infrastructure\Database\M1Schema;
use EindSupplierSearch\Infrastructure\Database\M1IntegrityException;
use EindSupplierSearch\Infrastructure\Verification\M1Verification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class M1SchemaTest extends TestCase
{
    public function testFrozenManifestAndExactPrefixBoundary(): void
    {
        foreach (M1Schema::HASHES as $name => $hash) {
            self::assertSame($hash, hash('sha256', M1Schema::template($name)));
            self::assertNotEmpty(M1Schema::definition($name)['checks']);
            self::assertStringStartsWith('CREATE TABLE `' . str_repeat('a',41) . $name . '`',
                (new M1Schema(str_repeat('a',41), fn()=>false, fn()=>[]))->ddl($name));
        }
        $this->expectException(\InvalidArgumentException::class);
        new M1Schema(str_repeat('a',42), fn()=>false, fn()=>[]);
    }

    #[DataProvider('prefixes')]
    public function testInvalidPrefixesRejectBeforeCallbacks(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new M1Schema($prefix, static function (): never { self::fail('DDL reached'); }, static function (): never { self::fail('Read reached'); });
    }
    public static function prefixes(): array { return [["x`"],['x-y'],["x\0"],[str_repeat('x',65)]]; }

    public function testOnlyFormattingAndKnownBinaryCastsNormalize(): void
    {
        self::assertSame(M1Schema::normalize("CAST(origin AS BINARY) IN ('LIVE','FIXTURE')"),
            M1Schema::normalize("cast(`origin` as char charset binary) in ('LIVE','FIXTURE')"));
        self::assertNotSame(M1Schema::normalize("origin='LIVE'"), M1Schema::normalize("origin='live'"));
        self::assertNotSame(M1Schema::normalize('(a AND b) OR c'), M1Schema::normalize('a AND (b OR c)'));
    }

    #[DataProvider('unsupportedChecks')]
    public function testUnsupportedCheckFormsRejectWithoutLosingLiteralBytes(string $sql): void
    {
        $this->expectException(M1IntegrityException::class);
        $this->expectExceptionMessage('M1_CHECK_UNSUPPORTED');
        M1Schema::normalize($sql);
    }
    public static function unsupportedChecks(): array
    {
        return [["origin='a\\' X Y'"], ["origin='a\\' xy'"], ["origin='a''b'"],
            ["origin='a\\\\b'"], ["origin='unterminated"], ['(id_shop>0'], ['id_shop>0)'],
            ['id_shop>0 /* ignored */'], ['id_shop>0 -- comment'], ['`id_ shop`>0'], ['origin="LIVE"']];
    }
    public function testLiteralTextIsNeverRewrittenAndTokensNeverMerge(): void
    {
        $sql="origin='cast ( origin as binary ) regexp binary'";
        self::assertSame($sql, str_replace(' = ', '=', M1Schema::normalize($sql)));
        self::assertNotSame(M1Schema::normalize("origin='X Y'"),M1Schema::normalize("origin='xy'"));
        self::assertNotSame(M1Schema::normalize('a b'),M1Schema::normalize('ab'));
        self::assertSame(M1Schema::normalize("currency REGEXP BINARY '^[A-Z]{3}$'"),
            M1Schema::normalize("`currency` regexp cast('^[A-Z]{3}$' as char charset binary)"));
        foreach (M1Schema::HASHES as $name=>$hash) {
            foreach (M1Schema::definition($name)['indexes'] as $index) {
                self::assertSame(array_fill(0,count($index['columns']),'A'),$index['directions']);
            }
        }
    }

    public function testOperationalPrecedenceRetainsKnownIntegrityAndNeverAttestsContent(): void
    {
        $r = M1Verification::run(fn()=>null, fn()=>null, fn()=>null, fn()=>null,
            static function ($name): never {
                if ($name === 'eind_observation') { throw new M1IntegrityException('M1_COLUMNS'); }
                throw new \RuntimeException('private database detail');
            });
        self::assertSame(2,$r['exit_code']); self::assertFalse($r['commerce_eligible']);
        self::assertSame('M1_COLUMNS',$r['checks'][4]['code']);
        self::assertStringNotContainsString('private',json_encode($r));
        foreach (['authentication','quotes','creation_history','native_products','cart','checkout','order_attribution'] as $domain) {
            self::assertContains($domain,$r['unassessed']);
        }
    }

    public function testStructuralPassIsExplicitScopeOnly(): void
    {
        $r=M1Verification::run(fn()=>null,fn()=>null,fn()=>null,fn()=>null,fn()=>null);
        self::assertSame(0,$r['exit_code']); self::assertSame(3,$r['inspected_object_count']);
        self::assertSame('structure',$r['mode']); self::assertSame('M1',$r['phase']); self::assertFalse($r['commerce_eligible']);
    }

    public function testUnprovedTargetVisibilityStopsFurtherInspection(): void
    {
        $no=static function (): never { self::fail('Untrusted target inspected'); };
        $r=M1Verification::run(static function (): never { throw new \RuntimeException('hidden metadata'); },$no,$no,$no,$no);
        self::assertSame(2,$r['exit_code']); self::assertCount(1,$r['checks']);
    }

    public function testCliRequiresExplicitM1ScopeAndSafeJsonWithoutConfiguration(): void
    {
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__,3).'/bin/verify-evidence-m1.php').' --json';
        exec($command,$out,$exit); $r=json_decode(implode("\n",$out),true,512,JSON_THROW_ON_ERROR);
        self::assertSame(2,$exit); self::assertSame('M1',$r['phase']); self::assertFalse($r['commerce_eligible']);
    }
}
