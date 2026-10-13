<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Database;

use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Infrastructure\Database\OfferBindingReference;
use EindSupplierSearch\Infrastructure\Database\OfferBindingSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfferBindingMigrationTest extends TestCase
{
    private \PDO $pdo;
    private array $config;

    protected function setUp(): void
    {
        $this->pdo = $GLOBALS['offer_binding_test_pdo'];
        $this->config = $GLOBALS['offer_binding_test_config'];
        DisposableDatabaseGuard::assertSafe($this->pdo, $this->config);
        $this->mutate('DROP TABLE IF EXISTS `test_eind_offer_binding`');
        $this->mutate('DROP TABLE IF EXISTS `unrelated_records`');
    }

    protected function tearDown(): void
    {
        $this->mutate('DROP TABLE IF EXISTS `test_eind_offer_binding`');
        $this->mutate('DROP TABLE IF EXISTS `unrelated_records`');
    }

    private function mutate(string $sql): bool
    {
        DisposableDatabaseGuard::assertSafe($this->pdo, $this->config);
        return $this->pdo->exec($sql) !== false;
    }

    private function schema(?callable $execute = null): OfferBindingSchema
    {
        return new OfferBindingSchema('test_', $execute ?? fn (string $sql): bool => $this->mutate($sql),
            fn (string $sql): array => $this->pdo->query($sql)->fetchAll());
    }

    private function insert(array $changes = []): void
    {
        DisposableDatabaseGuard::assertSafe($this->pdo, $this->config);
        $values = array_replace([
            'id_shop' => 1, 'id_supplier' => 2, 'order_reference' => '001-Ab ',
            'id_product' => null, 'id_product_attribute' => null, 'state' => 'RESERVED',
            'provenance' => 'MODULE_CREATED_LIVE', 'creation_operation_id' => random_bytes(16),
            'integrity_reason' => null, 'created_at' => '2026-10-12 12:00:00.000001',
            'updated_at' => '2026-10-12 12:00:00.000001',
        ], $changes);
        if (!array_key_exists('reference_sha256', $values)) {
            // Deliberately also allow invalid-length raw SQL fixtures to test DB enforcement.
            $values['reference_sha256'] = hash('sha256', $values['order_reference'], true);
        }
        $sql = 'INSERT INTO `test_eind_offer_binding` (`' . implode('`, `', array_keys($values))
            . '`) VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($values));
    }

    private function assertInsertRejected(array $changes, int $driverCode): void
    {
        try {
            $this->insert($changes);
            self::fail('Invalid registry fixture was accepted.');
        } catch (\PDOException $error) {
            self::assertSame($driverCode, (int) $error->errorInfo[1]);
        }
    }

    public function testFreshCreationAndRepeatedMigrationPreserveRegistryAndUnrelatedRecords(): void
    {
        $this->mutate('CREATE TABLE `unrelated_records` (`id` INT PRIMARY KEY, `payload` VARBINARY(64)) ENGINE=InnoDB');
        $this->mutate("INSERT INTO `unrelated_records` VALUES (1, X'004142ff')");
        $before = $this->pdo->query('SELECT * FROM `unrelated_records`')->fetchAll();
        $schema = $this->schema();
        $schema->install();
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM `test_eind_offer_binding`')->fetchColumn());
        $this->insert();
        $bindings = $this->pdo->query('SELECT * FROM `test_eind_offer_binding`')->fetchAll();
        $ddl = $this->pdo->query('SHOW CREATE TABLE `test_eind_offer_binding`')->fetch(\PDO::FETCH_NUM)[1];
        $schema->install();
        self::assertSame($ddl, $this->pdo->query('SHOW CREATE TABLE `test_eind_offer_binding`')->fetch(\PDO::FETCH_NUM)[1]);
        self::assertSame($bindings, $this->pdo->query('SELECT * FROM `test_eind_offer_binding`')->fetchAll());
        self::assertSame($before, $this->pdo->query('SELECT * FROM `unrelated_records`')->fetchAll());
    }

    public function testSupplierAndShopScopedIdentityAndPermanentReservation(): void
    {
        $this->schema()->install();
        $this->insert(['state' => 'RETIRED']);
        $this->assertInsertRejected([], 1062);
        $this->insert(['id_supplier' => 3]);
        $this->insert(['id_shop' => 2]);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM `test_eind_offer_binding`')->fetchColumn());
    }

    public function testGlobalTargetsCannotBeReassignedAcrossShopsOrStates(): void
    {
        $this->schema()->install();
        $this->insert(['id_product' => 42, 'id_product_attribute' => 0, 'state' => 'RETIRED']);
        $this->assertInsertRejected(['id_shop' => 2, 'order_reference' => 'another', 'id_product' => 42,
            'id_product_attribute' => 0, 'state' => 'ACTIVE'], 1062);
    }

    public function testNullableTargetsAndOperationUniqueness(): void
    {
        $this->schema()->install();
        $operation = random_bytes(16);
        $this->insert(['creation_operation_id' => $operation]);
        $this->insert(['order_reference' => 'second']);
        $this->assertInsertRejected(['order_reference' => 'third', 'creation_operation_id' => $operation], 1062);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM `test_eind_offer_binding` WHERE id_product IS NULL')->fetchColumn());
    }

    public function testExactBytesAndDigestRoundTripWithoutReferenceNormalization(): void
    {
        $this->schema()->install();
        $references = ['Aa', 'aa', 'Aa ', ' Aa', '001', '1', "A<>\0\xff\r\n", "é", "e\u{0301}", str_repeat("é", 2048)];
        foreach ($references as $reference) {
            $digest = OfferBindingReference::sha256(new SupplierOfferIdentity(2, $reference));
            $this->insert(['order_reference' => $reference, 'reference_sha256' => $digest]);
        }
        $rows = $this->pdo->query('SELECT order_reference, reference_sha256 FROM `test_eind_offer_binding` ORDER BY id_binding')->fetchAll();
        self::assertSame($references, array_column($rows, 'order_reference'));
        foreach ($rows as $row) {
            self::assertSame(hash('sha256', $row['order_reference'], true), $row['reference_sha256']);
            $query = $this->pdo->prepare('SELECT COUNT(*) FROM `test_eind_offer_binding` WHERE order_reference = ?');
            $query->execute([$row['order_reference']]);
            self::assertSame(1, (int) $query->fetchColumn());
        }
    }

    #[DataProvider('invalidRows')]
    public function testInvalidRowsAreRejectedByChecks(array $changes): void
    {
        $this->schema()->install();
        $this->assertInsertRejected($changes, 4025);
    }

    public static function invalidRows(): array
    {
        return [
            'empty reference' => [['order_reference' => '']],
            '4097 byte reference' => [['order_reference' => str_repeat('x', 4097)]],
            'wrong hash' => [['reference_sha256' => str_repeat("\0", 32)]],
            'unknown state' => [['state' => 'UNKNOWN']],
            'lowercase state' => [['state' => 'reserved']],
            'trailing state space' => [['state' => 'ACTIVE ']],
            'fixture provenance' => [['provenance' => 'FIXTURE']],
            'trailing provenance space' => [['provenance' => 'MODULE_CREATED_LIVE ']],
            'zero shop' => [['id_shop' => 0]],
            'zero supplier' => [['id_supplier' => 0]],
            'partial product' => [['id_product' => 1]],
            'partial attribute' => [['id_product_attribute' => 0]],
            'zero product' => [['id_product' => 0, 'id_product_attribute' => 0]],
            'unsupported combination' => [['id_product' => 1, 'id_product_attribute' => 1]],
            'active without target' => [['state' => 'ACTIVE']],
            'quarantine without reason' => [['state' => 'QUARANTINED']],
            'quarantine empty reason' => [['state' => 'QUARANTINED', 'integrity_reason' => '']],
            'active with reason' => [['state' => 'ACTIVE', 'id_product' => 1, 'id_product_attribute' => 0, 'integrity_reason' => 'FAILURE']],
            'retired empty reason' => [['state' => 'RETIRED', 'integrity_reason' => '']],
            'backwards timestamps' => [['updated_at' => '2026-10-11 12:00:00']],
        ];
    }

    public function testValidLifecycleRowShapesRemainStored(): void
    {
        $this->schema()->install();
        $this->insert();
        $this->insert(['order_reference' => 'active', 'state' => 'ACTIVE', 'id_product' => 1, 'id_product_attribute' => 0]);
        $this->insert(['order_reference' => 'quarantine', 'state' => 'QUARANTINED', 'integrity_reason' => 'UNCERTAIN_CREATION']);
        $this->insert(['order_reference' => 'retired', 'state' => 'RETIRED']);
        self::assertSame(['RESERVED', 'ACTIVE', 'QUARANTINED', 'RETIRED'], $this->pdo->query('SELECT state FROM `test_eind_offer_binding` ORDER BY id_binding')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testInterruptedCreationCanBeVerifiedOnRetry(): void
    {
        $schema = $this->schema(function (string $sql): bool {
            $this->mutate($sql);
            throw new \RuntimeException('Simulated interruption after DDL.');
        });
        try {
            $schema->install();
            self::fail('Expected simulated interruption.');
        } catch (\RuntimeException $error) {
            self::assertSame('Simulated interruption after DDL.', $error->getMessage());
        }
        $this->schema()->install();
        $this->schema()->verify();
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM `test_eind_offer_binding`')->fetchColumn());
    }

    #[DataProvider('incompatibleSchemaChanges')]
    public function testIncompatibleExistingSchemaIsPreservedAndRejected(string $alter): void
    {
        $this->schema()->install();
        $this->insert();
        $this->mutate('ALTER TABLE `test_eind_offer_binding` ' . $alter);
        $before = $this->pdo->query('SHOW CREATE TABLE `test_eind_offer_binding`')->fetch(\PDO::FETCH_NUM)[1];
        $records = $this->pdo->query('SELECT * FROM `test_eind_offer_binding`')->fetchAll();
        try {
            $this->schema()->install();
            self::fail('Incompatible schema accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Incompatible', $error->getMessage());
        }
        self::assertSame($before, $this->pdo->query('SHOW CREATE TABLE `test_eind_offer_binding`')->fetch(\PDO::FETCH_NUM)[1]);
        self::assertSame($records, $this->pdo->query('SELECT * FROM `test_eind_offer_binding`')->fetchAll());
    }

    public static function incompatibleSchemaChanges(): array
    {
        return array_map(static fn (string $alter): array => [$alter], [
            'DROP INDEX uq_offer_identity',
            'DROP INDEX uq_native_target',
            'DROP INDEX uq_creation_operation',
            'DROP CONSTRAINT ck_eob_hash',
            'DROP CONSTRAINT ck_eob_reference, ADD CONSTRAINT ck_eob_reference CHECK (OCTET_LENGTH(order_reference) > 0)',
            'MODIFY reference_sha256 VARBINARY(32) NOT NULL',
            'MODIFY id_supplier INT UNSIGNED NULL',
            'MODIFY state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL',
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
            'ADD COLUMN extra_column INT NULL',
        ]);
    }

    public function testUpgradeUsesTheRealSharedEntryPointOnTheIsolatedDatabase(): void
    {
        require_once dirname(__DIR__) . '/Infrastructure/Database/ModuleLifecycleHarness.php';
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.1.0.php';
        \Db::$executor = fn (string $sql): bool => $this->mutate($sql);
        \Db::$reader = fn (string $sql): array => $this->pdo->query($sql)->fetchAll();
        try {
            $module = new \Eindsuppliersearch();
            self::assertTrue(upgrade_module_1_1_0($module));
            self::assertTrue(upgrade_module_1_1_0($module));
            self::assertSame('1.1.0', $module->version);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM `test_eind_offer_binding`')->fetchColumn());
            $this->mutate('ALTER TABLE `test_eind_offer_binding` DROP CONSTRAINT ck_eob_hash');
            self::assertFalse(upgrade_module_1_1_0($module));
            self::assertCount(1, $module->_errors);
        } finally {
            \Db::$executor = null;
            \Db::$reader = null;
        }
    }

    public function testRegistrySurvivesActualModuleUninstallCodeUsingIsolatedCoreSubstitutes(): void
    {
        $this->schema()->install();
        $this->insert(['state' => 'RETIRED']);
        $before = $this->pdo->query('SELECT * FROM `test_eind_offer_binding`')->fetchAll();
        require_once dirname(__DIR__) . '/Infrastructure/Database/ModuleLifecycleHarness.php';
        \Db::$executor = fn (string $sql): bool => $this->mutate($sql);
        try {
            self::assertTrue((new \Eindsuppliersearch())->uninstall());
            $this->schema()->install();
            self::assertSame($before, $this->pdo->query('SELECT * FROM `test_eind_offer_binding`')->fetchAll());
        } finally {
            \Db::$executor = null;
        }
    }
}
