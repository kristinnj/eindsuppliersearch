<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Database;

use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Domain\LocalProductLookupReason as Reason;
use EindSupplierSearch\Exception\LocalProductLookupException;
use EindSupplierSearch\Infrastructure\Database\LookupSelectConnection;
use EindSupplierSearch\Infrastructure\Database\OfferBindingSchema;
use EindSupplierSearch\Infrastructure\Database\PdoNativeProductEvidenceReader;
use EindSupplierSearch\Infrastructure\Database\PdoOfferBindingReader;
use EindSupplierSearch\Infrastructure\Database\PersistentLocalProductLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfferBindingLookupTest extends TestCase
{
    private \PDO $pdo;
    private const TABLES = ['product', 'product_shop', 'product_supplier', 'supplier', 'supplier_shop', 'shop'];

    protected function setUp(): void
    {
        $this->pdo = $GLOBALS['offer_binding_test_pdo'];
        $this->mutate('DROP TABLE IF EXISTS `test_eind_offer_binding`');
        (new OfferBindingSchema('test_', fn ($sql) => $this->mutate($sql), fn ($sql) => $this->pdo->query($sql)->fetchAll()))->install();
        // Connection-local minimal native fixtures; never relax the guard's permanent-table denial.
        $definitions = [
            'product' => 'id_product INT UNSIGNED PRIMARY KEY, id_supplier INT UNSIGNED NULL',
            'product_shop' => 'id_product INT UNSIGNED, id_shop INT UNSIGNED, PRIMARY KEY(id_product,id_shop)',
            'product_supplier' => 'id_product_supplier INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_product INT UNSIGNED NOT NULL, '
                . 'id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0, id_supplier INT UNSIGNED NOT NULL, '
                . 'product_supplier_reference VARCHAR(64) NULL, UNIQUE KEY(id_product,id_product_attribute,id_supplier)',
            'supplier' => 'id_supplier INT UNSIGNED PRIMARY KEY, active TINYINT NOT NULL',
            'supplier_shop' => 'id_supplier INT UNSIGNED, id_shop INT UNSIGNED, PRIMARY KEY(id_supplier,id_shop)',
            'shop' => 'id_shop INT PRIMARY KEY, active TINYINT NOT NULL, deleted TINYINT NOT NULL',
        ];
        foreach ($definitions as $table => $definition) {
            $this->mutate('CREATE TEMPORARY TABLE `test_' . $table . '` (' . $definition . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci');
        }
        $this->mutate('INSERT INTO test_supplier VALUES (2,1),(3,1)');
        $this->mutate('INSERT INTO test_shop VALUES (1,1,0),(2,1,0)');
        $this->mutate('INSERT INTO test_supplier_shop VALUES (2,1),(2,2),(3,1),(3,2)');
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            $this->mutate('DROP TEMPORARY TABLE IF EXISTS `test_' . $table . '`');
        }
        $this->mutate('DROP TABLE IF EXISTS `test_eind_offer_binding`');
    }

    private function mutate(string $sql): bool
    {
        DisposableDatabaseGuard::assertSafe($this->pdo, $GLOBALS['offer_binding_test_config'], true);
        return $this->pdo->exec($sql) !== false;
    }

    private function insertBinding(string $reference, int $supplier = 2, int $shop = 1,
        ?int $product = 50, string $state = 'ACTIVE'): void
    {
        DisposableDatabaseGuard::assertSafe($this->pdo, $GLOBALS['offer_binding_test_config'], true);
        $statement = $this->pdo->prepare('INSERT INTO test_eind_offer_binding '
            . '(id_shop,id_supplier,order_reference,reference_sha256,id_product,id_product_attribute,state,provenance,'
            . 'creation_operation_id,integrity_reason,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$shop, $supplier, $reference, hash('sha256', $reference, true), $product,
            $product === null ? null : 0, $state, 'MODULE_CREATED_LIVE', random_bytes(16),
            $state === 'QUARANTINED' ? 'INSPECT' : null, '2026-10-12 12:00:00.000001', '2026-10-12 12:00:00.000001']);
    }

    private function insertNative(string $reference, int $supplier = 2, int $shop = 1, int $product = 50): void
    {
        DisposableDatabaseGuard::assertSafe($this->pdo, $GLOBALS['offer_binding_test_config'], true);
        $this->pdo->prepare('INSERT INTO test_product VALUES (?,?)')->execute([$product, $supplier]);
        $this->pdo->prepare('INSERT INTO test_product_shop VALUES (?,?)')->execute([$product, $shop]);
        $this->pdo->prepare('INSERT INTO test_product_supplier (id_product,id_product_attribute,id_supplier,product_supplier_reference) VALUES (?,0,?,?)')
            ->execute([$product, $supplier, $reference]);
    }

    private function lookup(): PersistentLocalProductLookup
    {
        $connection = new LookupSelectConnection($this->pdo, 'test_');
        return new PersistentLocalProductLookup(new PdoOfferBindingReader($connection),
            new PdoNativeProductEvidenceReader($connection, static fn ($supplier, $shop) => in_array($supplier, [2,3], true)));
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (array_merge(['eind_offer_binding'], self::TABLES) as $table) {
            $snapshot[$table] = $this->pdo->query('SELECT * FROM `test_' . $table . '`')->fetchAll();
        }
        return $snapshot;
    }

    public static function references(): array
    {
        return [['001-Ab '], [' Ab '], ['0001'], ['A/B-.:'], ['é'], ["e\u{0301}"], [str_repeat('é',64)],
            ["A\0B"], ["A\xffB"], [str_repeat('A',65)], [str_repeat('A',4096)]];
    }

    #[DataProvider('references')]
    public function testBinaryLookupAndExactRoundTripWithoutWrites(string $reference): void
    {
        $this->insertBinding($reference);
        $compatible = PdoNativeProductEvidenceReader::referenceCompatible($reference);
        $this->insertNative($compatible ? $reference : 'incompatible');
        $before = $this->snapshot();
        $reader = new PdoOfferBindingReader(new LookupSelectConnection($this->pdo, 'test_'));
        $rows = $reader->find(1, 2, hash('sha256', $reference, true));
        self::assertCount(1, $rows);
        self::assertSame($reference, $rows[0]['order_reference']);
        self::assertSame(hash('sha256', $reference, true), $rows[0]['reference_sha256']);
        $result = $this->lookup()->lookup(new SupplierOfferIdentity(2, $reference), 1);
        self::assertSame($compatible ? Reason::VERIFIED_OWNED_TARGET : Reason::RELATIONSHIP_CONFLICT, $result->getReason());
        self::assertSame($before, $this->snapshot());
    }

    public function testSameReferenceUnderDifferentSuppliersAndShops(): void
    {
        foreach ([[2,1,50],[3,1,51],[2,2,52]] as [$supplier,$shop,$product]) {
            $this->insertBinding('Same', $supplier, $shop, $product);
            $this->insertNative('Same', $supplier, $shop, $product);
        }
        $before = $this->snapshot();
        foreach ([[2,1,50],[3,1,51],[2,2,52]] as [$supplier,$shop,$product]) {
            self::assertSame($product, $this->lookup()->lookup(new SupplierOfferIdentity($supplier, 'Same'), $shop)->getTarget()->getProductId());
        }
        self::assertSame(Reason::NO_CANDIDATES, $this->lookup()->lookup(new SupplierOfferIdentity(3, 'Same'), 2)->getReason());
        self::assertSame($before, $this->snapshot());
    }

    public static function states(): array
    {
        return [['RESERVED',null], ['RESERVED',50], ['QUARANTINED',null], ['QUARANTINED',50], ['RETIRED',null], ['RETIRED',50]];
    }

    #[DataProvider('states')]
    public function testNonActiveAndNullableTargetsAreNotMissing(string $state, ?int $product): void
    {
        $this->insertBinding('A',2,1,$product,$state);
        $before = $this->snapshot();
        $lookup = $this->lookup();
        $result = $lookup->lookup(new SupplierOfferIdentity(2,'A'),1);
        self::assertSame(Reason::OWNERSHIP_UNVERIFIED,$result->getReason());
        self::assertNull($result->getTarget());
        self::assertSame($state,$lookup->getDiagnostic());
        self::assertSame($before,$this->snapshot());
    }

    public static function nativeChanges(): array
    {
        return [
            ['DELETE FROM test_product', Reason::DANGLING_BINDING],
            ['DELETE FROM test_product_shop', Reason::RELATIONSHIP_CONFLICT],
            ['DELETE FROM test_product_supplier', Reason::RELATIONSHIP_CONFLICT],
            ['UPDATE test_product_supplier SET id_supplier=3', Reason::RELATIONSHIP_CONFLICT],
            ["UPDATE test_product_supplier SET product_supplier_reference='a'", Reason::RELATIONSHIP_CONFLICT],
            ["UPDATE test_product_supplier SET product_supplier_reference='á'", Reason::RELATIONSHIP_CONFLICT],
            ['UPDATE test_product_supplier SET product_supplier_reference=NULL', Reason::RELATIONSHIP_CONFLICT],
            ["INSERT INTO test_product_supplier (id_product,id_product_attribute,id_supplier,product_supplier_reference) VALUES (50,0,3,'A')", Reason::RELATIONSHIP_CONFLICT],
            ['UPDATE test_product SET id_supplier=3', Reason::RELATIONSHIP_CONFLICT],
            ['UPDATE test_supplier SET active=0 WHERE id_supplier=2', Reason::SUPPLIER_NOT_ELIGIBLE],
            ['DELETE FROM test_supplier WHERE id_supplier=2', Reason::SUPPLIER_NOT_FOUND],
            ['DELETE FROM test_supplier_shop WHERE id_supplier=2', Reason::SUPPLIER_NOT_IN_SHOP],
            ['UPDATE test_shop SET deleted=1', Reason::SHOP_NOT_FOUND],
        ];
    }

    #[DataProvider('nativeChanges')]
    public function testNativeRelationshipsFailClosedAndLookupDoesNotRepair(string $change, Reason $reason): void
    {
        $this->insertBinding('A');
        $this->insertNative('A');
        $this->mutate($change);
        $before = $this->snapshot();
        $result = $this->lookup()->lookup(new SupplierOfferIdentity(2,'A'),1);
        self::assertSame($reason,$result->getReason());
        self::assertNull($result->getTarget());
        self::assertSame($before,$this->snapshot());
    }

    public static function unavailableTables(): array
    {
        return [['eind_offer_binding',false], ['product_supplier',true], ['product',true], ['supplier_shop',true]];
    }

    #[DataProvider('unavailableTables')]
    public function testUnavailableSchemaIsAnOperationalFailure(string $table, bool $temporary): void
    {
        $this->insertBinding('A');
        $this->insertNative('A');
        $this->mutate('DROP ' . ($temporary ? 'TEMPORARY ' : '') . 'TABLE `test_' . $table . '`');
        $this->expectException(LocalProductLookupException::class);
        $this->lookup()->lookup(new SupplierOfferIdentity(2,'A'),1);
    }

    public function testReadOnlyTransactionAllowsSuccessfulLookup(): void
    {
        $this->insertBinding('A');
        $this->insertNative('A');
        $before = $this->snapshot();
        DisposableDatabaseGuard::assertSafe($this->pdo, $GLOBALS['offer_binding_test_config'], true);
        $this->pdo->exec('START TRANSACTION READ ONLY');
        try {
            self::assertSame(Reason::VERIFIED_OWNED_TARGET,$this->lookup()->lookup(new SupplierOfferIdentity(2,'A'),1)->getReason());
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame($before,$this->snapshot());
    }
}
