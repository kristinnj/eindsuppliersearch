<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalJson as Json;
use EindSupplierSearch\Protocol\CanonicalValidationException as Invalid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function testExplicitContainersAndByteOrdering(): void
    {
        $object = new \stdClass();
        $object->{'2'} = 'two';
        $object->{'10'} = 'ten';
        $value = (object) ['z' => [], 'a' => (object) [], 'languages' => $object];
        self::assertSame('{"a":{},"languages":{"10":"ten","2":"two"},"z":[]}', Json::encode($value));
        $decoded = Json::decode(Json::encode($value));
        self::assertInstanceOf(\stdClass::class, $decoded->a);
        self::assertSame([], $decoded->z);
        self::assertSame('ten', $decoded->languages->{'10'});
        self::assertSame('{"v":["1",null,true,false,{}]}', Json::encode((object) ['v' => ['1', null, true, false, new \stdClass()]]));
    }

    public function testExactEscapesAndUnicodeVectors(): void
    {
        self::assertSame('{"s":"é"}', Json::encode((object) ['s' => "\u{e9}"]));
        self::assertSame('86028b41ba792eaf82aa26a45b218f6734f7f1096a86f1746c8296e088a0ccb4', hash('sha256', Json::encode((object) ['s' => "\u{e9}"])));
        self::assertSame('1fc0bd7cc93fca8092a2041d7d01842876422aa7e2838acf42c14578e9f2be05', hash('sha256', Json::encode((object) ['s' => "e\u{301}"])));
        $text = "/\"\\\x00\x01\x08\x09\x0a\x0c\x0d\x1f\u{2028}\u{2029}";
        $expected = '{"s":"/\"\\\\\u0000\u0001\b\t\n\f\r\u001f' . "\u{2028}\u{2029}" . '"}';
        self::assertSame($expected, Json::encode((object) ['s' => $text]));
        self::assertSame($text, Json::decode($expected)->s);
        self::assertSame('{"s":"😀"}', Json::canonicalize('{"s":"\ud83d\ude00"}'));
    }

    public function testExplicitCanonicalizationIsSeparateFromValidation(): void
    {
        $input = " { \"z\" : \"\\/\\u00e9\", \"a\":{} }\n";
        self::assertSame('/é', Json::parseCanonicalizable($input)->z);
        self::assertSame('{"a":{},"z":"/é"}', Json::canonicalize($input));
        $this->expectExceptionMessage('NONCANONICAL_JSON');
        Json::decode($input);
    }

    #[DataProvider('noncanonical')]
    public function testRejectsNoncanonicalBytes(string $bytes): void
    {
        $this->expectException(Invalid::class);
        Json::decode($bytes);
    }

    public static function noncanonical(): iterable
    {
        foreach ([' {"s":"a"}', "{\"s\":\"a\"}\n", '{"z":"1","a":"2"}', '{"s":"\u0061"}', '{"s":"\/"}', '{"s":"\u000a"}', '{"s":"\u001F"}'] as $s) { yield [$s]; }
    }

    #[DataProvider('malformed')]
    public function testStrictParserRejects(string $bytes, string $category): void
    {
        try { Json::parseCanonicalizable($bytes); self::fail('Accepted invalid input'); }
        catch (Invalid $e) { self::assertSame($category, $e->category); self::assertSame($category, $e->getMessage()); }
    }

    public static function malformed(): iterable
    {
        foreach (['{"a":"1","a":"2"}', '{"a":"1","\u0061":"2"}', '{"x":{"1":"a","\u0031":"b"}}'] as $s) { yield [$s, 'JSON_DUPLICATE_KEY']; }
        foreach (['{"n":1}', '{"n":1.0}', '{"n":1e99}', '{"n":-0}', '{"n":18446744073709551616}'] as $s) { yield [$s, 'JSON_NUMBER_FORBIDDEN']; }
        foreach (["{\"s\":\"\xc0\xaf\"}", "{\"s\":\"\xed\xa0\x80\"}", '{"s":"\ud800"}', '{"s":"\udc00"}', '{"s":"\ud800\u0041"}', '{"s":"\x00"}', "{\"s\":\"\x01\"}"] as $s) { yield [$s, 'JSON_STRING_INVALID']; }
        foreach (['', '{', '{"a":', '{"a":"x}', '{"a":"x\\', '{"a" "x"}', '{"a":null,}', '{"a":[true,]}', '{}{}', '{} x', "\xef\xbb\xbf{}", '{"a":undefined}', '{"a":nul}', '{"a":truefalse}', '{"a":[]'] as $s) { yield [$s, 'JSON_SYNTAX_INVALID']; }
        foreach (['[]', 'null', 'true', '"x"'] as $s) { yield [$s, 'ROOT_OBJECT_REQUIRED']; }
        foreach (['{"é":"x"}', '{"":"x"}', '{"\u0000":"x"}'] as $s) { yield [$s, 'OBJECT_KEY_INVALID']; }
    }

    #[DataProvider('badValues')]
    public function testRejectsPhpCoercion(mixed $value): void
    {
        $this->expectException(Invalid::class);
        Json::encode($value);
    }

    public static function badValues(): iterable
    {
        yield [[]]; yield [['a' => 'x']]; yield [new \DateTimeImmutable()];
        foreach ([1, 1.0, INF, NAN, [1 => 'x'], ['a' => 'x'], "\xff", new \DateTimeImmutable()] as $v) { yield [(object) ['v' => $v]]; }
        yield [new class extends \stdClass {}];
    }

    public function testDepthCountsContainersOnlyAndEmptyContainersCount(): void
    {
        $x = 's';
        for ($i = 0; $i < 16; ++$i) { $x = (object) ['v' => $x]; }
        $bytes = str_repeat('{"v":', 16) . '"s"' . str_repeat('}', 16);
        self::assertSame($bytes, Json::encode($x));
        self::assertSame($bytes, Json::encode(Json::decode($bytes)));
        foreach (['s', [], new \stdClass()] as $leaf) {
            $v = $leaf;
            for ($i = 0; $i < 17; ++$i) { $v = (object) ['v' => $v]; }
            try { Json::encode($v); self::fail('Depth accepted'); }
            catch (Invalid $e) { self::assertSame('JSON_DEPTH_EXCEEDED', $e->category); }
        }
        foreach (['{}', '[]'] as $empty) {
            $ok = str_repeat('{"v":', 15) . $empty . str_repeat('}', 15);
            self::assertSame($ok, Json::encode(Json::decode($ok)));
            $bad = str_repeat('{"v":', 16) . $empty . str_repeat('}', 16);
            try { Json::decode($bad); self::fail('Empty depth17 accepted'); }
            catch (Invalid $e) { self::assertSame('JSON_DEPTH_EXCEEDED', $e->category); }
        }
        $bad = '{"v":' . $bytes . '}';
        $this->expectExceptionMessage('JSON_DEPTH_EXCEEDED');
        Json::decode($bad);
    }

    public function testMixedArrayDepthAndCyclesAreBounded(): void
    {
        $bytes = '{"v":' . str_repeat('[', 15) . '"s"' . str_repeat(']', 15) . '}';
        self::assertSame($bytes, Json::encode(Json::decode($bytes)));
        $x = new \stdClass(); $x->self = $x;
        $this->expectExceptionMessage('JSON_DEPTH_EXCEEDED');
        Json::encode($x);
    }

    public function testByteLimitsApplyToInputAndEncodedOutput(): void
    {
        $text = str_repeat('x', Json::MAX_BYTES - 8);
        $bytes = '{"s":"' . $text . '"}';
        self::assertSame(Json::MAX_BYTES, strlen($bytes));
        self::assertSame($bytes, Json::encode((object) ['s' => $text]));
        self::assertSame($text, Json::decode($bytes)->s);
        foreach ([$bytes . ' ', str_repeat(' ', Json::MAX_BYTES + 1)] as $bad) {
            try { Json::decode($bad); self::fail('Oversize accepted'); }
            catch (Invalid $e) { self::assertSame('JSON_SIZE_EXCEEDED', $e->category); }
        }
        foreach ([$text . 'x', str_repeat("\x01", 200000), str_repeat('x', Json::MAX_BYTES + 1)] as $bad) {
            try { Json::encode((object) ['s' => $bad]); self::fail('Oversize encoded output accepted'); }
            catch (Invalid $e) { self::assertSame('JSON_SIZE_EXCEEDED', $e->category); }
        }
    }
}
