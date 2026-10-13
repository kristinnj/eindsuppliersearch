<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Database;

use EindSupplierSearch\Domain\LocalProductBindingEvidence;
use EindSupplierSearch\Domain\LocalProductTarget;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Infrastructure\Database\LookupSelectConnection;
use EindSupplierSearch\Infrastructure\Database\PdoNativeProductEvidenceReader;
use EindSupplierSearch\Infrastructure\Database\PdoOfferBindingReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdoLookupReadersTest extends TestCase
{
    private function connection(array $responses, array &$queries): LookupSelectConnection
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn ($attribute) => $attribute === \PDO::ATTR_DRIVER_NAME ? 'mysql' : false);
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('query');
        $pdo->method('prepare')->willReturnCallback(function ($sql) use (&$queries, &$responses) {
            self::assertStringStartsWith('SELECT ', $sql);
            self::assertStringNotContainsString('supplier_reference =', $sql);
            $index = count($queries);
            $queries[$index] = ['sql' => $sql, 'parameters' => []];
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('bindValue')->willReturnCallback(static function ($position, $value, $type) use (&$queries, $index) {
                $queries[$index]['parameters'][$position] = [$value, $type];
                return true;
            });
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn(array_shift($responses));
            return $statement;
        });
        return new LookupSelectConnection($pdo, 'test_');
    }

    public function testRegistryUsesParameterizedScopeAndBinaryDigestAndReadsEveryTargetClaim(): void
    {
        $queries = [];
        $reader = new PdoOfferBindingReader($this->connection([[['raw' => "\0"]], []], $queries));
        $digest = hash('sha256', "A\0B", true);
        self::assertSame([['raw' => "\0"]], $reader->find(1, 2, $digest));
        $reader->forTarget(new LocalProductTarget(50, 0, 1));
        self::assertSame([[1, \PDO::PARAM_INT], [2, \PDO::PARAM_INT], [$digest, \PDO::PARAM_LOB]], array_values($queries[0]['parameters']));
        self::assertSame('SELECT * FROM `test_eind_offer_binding` WHERE id_shop = ? AND id_supplier = ? AND reference_sha256 = ?', $queries[0]['sql']);
        self::assertSame('SELECT * FROM `test_eind_offer_binding` WHERE id_product = ? AND id_product_attribute = ?', $queries[1]['sql']);
    }

    public static function compatibility(): array
    {
        return [['001-Ab ', true], [' Ab ', true], ['A/B-.:', true], ['é', true], ["e\u{0301}", true],
            [str_repeat('é', 64), true], [str_repeat('A', 65), false], [str_repeat('é', 65), false],
            ["A\0B", false], ["A\xff", false], ['A<B', false], ['', false], ['  ', false]];
    }

    #[DataProvider('compatibility')]
    public function testNativeReferenceCompatibilityDoesNotNormalize(string $reference, bool $compatible): void
    {
        $queries = [];
        $reader = new PdoNativeProductEvidenceReader($this->connection([
            [['id_product' => '50', 'id_supplier' => '2']], [['id_product' => '50']],
            [['id_product' => '50', 'id_product_attribute' => '0', 'id_supplier' => '2', 'order_reference' => $reference]],
        ], $queries), static fn () => true);
        $binding = new LocalProductBindingEvidence('10', new SupplierOfferIdentity(2, 'A'), new LocalProductTarget(50, 0, 1), true);
        $candidates = $reader->candidates($binding);
        self::assertCount(1, $candidates);
        self::assertSame($compatible ? $reference : null, $candidates[0]->native->supplierIdentity?->getSupplierOrderReference());
        self::assertStringContainsString('CAST(product_supplier_reference AS BINARY)', $queries[2]['sql']);
        self::assertStringNotContainsString('id_shop', $queries[2]['sql']);
        self::assertSame([[50, \PDO::PARAM_INT]], array_values($queries[2]['parameters']));
    }

    public function testNativeReaderIncludesContradictionsAndAbsentAssociation(): void
    {
        foreach ([[], [
            ['id_product' => '50', 'id_product_attribute' => '0', 'id_supplier' => '2', 'order_reference' => 'A'],
            ['id_product' => '50', 'id_product_attribute' => '1', 'id_supplier' => '3', 'order_reference' => 'other'],
        ]] as $rows) {
            $queries = [];
            $reader = new PdoNativeProductEvidenceReader($this->connection([[], [], $rows], $queries), static fn () => true);
            $binding = new LocalProductBindingEvidence('10', new SupplierOfferIdentity(2, 'A'), new LocalProductTarget(50, 0, 1), true);
            $candidates = $reader->candidates($binding);
            self::assertCount(max(1, count($rows)), $candidates);
            self::assertFalse($candidates[0]->native->productExists);
            self::assertFalse($candidates[0]->native->productInShop);
            if ($rows !== []) {
                self::assertSame(3, $candidates[1]->native->supplierIdentity->getSupplierId());
                self::assertSame(1, $candidates[1]->native->target->getProductAttributeId());
            } else {
                self::assertNull($candidates[0]->native->supplierIdentity);
            }
        }
    }

    public static function contexts(): array
    {
        return [[true, '1', '1', '0', true, true, true, true],
            [false, '1', '1', '0', true, true, false, true],
            [true, '0', '1', '0', true, true, false, true],
            [true, '1', '0', '0', true, true, true, false],
            [true, '1', '1', '1', true, true, true, false],
            [true, '1', '1', '0', false, false, false, false]];
    }

    #[DataProvider('contexts')]
    public function testNativeContextRequiresTrustedEligibilityAndNativeScope(bool $eligible, string $active,
        string $shopActive, string $deleted, bool $exists, bool $supplierExists, bool $expectedEligible, bool $shopExists): void
    {
        $queries = [];
        $reader = new PdoNativeProductEvidenceReader($this->connection([
            $exists ? [['id_supplier' => '2', 'active' => $active]] : [],
            $exists ? [['id_shop' => '1', 'active' => $shopActive, 'deleted' => $deleted]] : [],
            $exists ? [['id_supplier' => '2']] : [],
        ], $queries), static function ($supplier, $shop) use ($eligible) {
            self::assertSame(2, $supplier);
            self::assertSame(1, $shop);
            return $eligible;
        });
        $context = $reader->context(2, 1);
        self::assertSame($supplierExists, $context->supplierExists);
        self::assertSame($expectedEligible, $context->supplierEligible);
        self::assertSame($shopExists, $context->shopExists);
        self::assertSame($exists, $context->supplierInShop);
    }

    public static function badPrefixes(): array
    {
        return [["x`"], ['x;DROP'], ['a-b'], [str_repeat('a', 64)]];
    }

    #[DataProvider('badPrefixes')]
    public function testInvalidPrefixIsRejectedBeforeConnectionUse(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LookupSelectConnection($this->createMock(\PDO::class), $prefix);
    }

    public function testSilentQueryFailureIsNotEmptyEvidence(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn ($attribute) => $attribute === \PDO::ATTR_DRIVER_NAME ? 'mysql' : false);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(false);
        $pdo->method('prepare')->willReturn($statement);
        $this->expectException(\RuntimeException::class);
        (new LookupSelectConnection($pdo, ''))->select('SELECT 1', []);
    }

    public function testWritesAreRejectedBeforePdoUse(): void
    {
        $queries = [];
        $connection = $this->connection([], $queries);
        $this->expectException(\InvalidArgumentException::class);
        $connection->select('UPDATE product SET id_supplier = 2', []);
    }
}
