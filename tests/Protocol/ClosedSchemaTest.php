<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\ClosedSchema as Schema;
use EindSupplierSearch\Protocol\CanonicalDecimal as Decimal;
use EindSupplierSearch\Protocol\CanonicalJson;
use EindSupplierSearch\Protocol\CanonicalValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClosedSchemaTest extends TestCase
{
    private static function validate(mixed $value): void
    {
        Schema::object($value, [
            'cost' => static fn ($v) => Decimal::decimal($v, true),
            'nested' => static fn ($v) => Schema::object($v, ['label' => static fn ($v) => Schema::scalarType($v, 'string')]),
            'values' => static fn ($v) => Schema::list($v, static fn ($v) => Decimal::unsignedInteger($v), 1, 2),
        ], ['nullable' => static fn ($v) => Schema::nullable($v, static fn ($v) => Schema::scalarType($v, 'string'))]);
    }

    public function testRequiredOptionalAndExplicitNull(): void
    {
        self::validate(CanonicalJson::decode('{"cost":"0.010000","nested":{"label":"ok"},"values":["1"]}'));
        self::validate(CanonicalJson::decode('{"cost":"0.010000","nested":{"label":"ok"},"nullable":null,"values":["1","2"]}'));
        self::validate(CanonicalJson::decode('{"cost":"0.010000","nested":{"label":"ok"},"nullable":"yes","values":["1"]}'));
        Schema::object((object) ['1' => 'one'], ['1' => static fn ($v) => Schema::scalarType($v,'string')]);
        self::assertTrue(true);
    }

    #[DataProvider('badObjects')]
    public function testRejectsStructure(mixed $value, string $category): void
    {
        try { self::validate($value); self::fail('Accepted invalid structure'); }
        catch (CanonicalValidationException $e) { self::assertSame($category,$e->category); }
    }

    public static function badObjects(): iterable
    {
        $good=(object) ['cost'=>'1.000000','nested'=>(object) ['label'=>'ok'],'values'=>['1']];
        yield [[], 'SCHEMA_TYPE_INVALID'];
        yield [(object) [], 'SCHEMA_MISSING_FIELD'];
        $v=clone $good;$v->extra='x';yield [$v,'SCHEMA_UNKNOWN_FIELD'];
        foreach ([null,1,1.0,true] as $cost) {$v=clone $good;$v->cost=$cost;yield [$v,'DECIMAL_LEXICAL_INVALID'];}
        $v=clone $good;$v->nested=[];yield [$v,'SCHEMA_TYPE_INVALID'];
        $v=clone $good;$v->nested=(object) ['label'=>null];yield [$v,'SCHEMA_TYPE_INVALID'];
        $v=clone $good;$v->nested=(object) ['label'=>'x','extra'=>'y'];yield [$v,'SCHEMA_UNKNOWN_FIELD'];
        $v=clone $good;$v->values=(object) [];yield [$v,'SCHEMA_TYPE_INVALID'];
        $v=clone $good;$v->values=[1=>'1'];yield [$v,'SCHEMA_TYPE_INVALID'];
        foreach ([[],['1','2','3']] as $values) {$v=clone $good;$v->values=$values;yield [$v,'SCHEMA_LIST_SIZE_INVALID'];}
        $v=clone $good;$v->values=['01'];yield [$v,'INTEGER_LEXICAL_INVALID'];
    }

    public function testDuplicateFieldsRejectedBeforeSchemaConstruction(): void
    {
        $this->expectExceptionMessage('JSON_DUPLICATE_KEY');
        self::validate(CanonicalJson::decode('{"cost":"1.000000","cost":"2.000000"}'));
    }

    public function testDuplicateSchemaDefinitionRejected(): void
    {
        $validator=static fn ($v) => Schema::scalarType($v,'string');
        $this->expectExceptionMessage('SCHEMA_DEFINITION_INVALID');
        Schema::object((object) ['a'=>'x'],['a'=>$validator],['a'=>$validator]);
    }
}
