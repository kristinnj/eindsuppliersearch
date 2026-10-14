<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Database;

use EindSupplierSearch\Infrastructure\Database\OfferBindingSchema;
use EindSupplierSearch\Infrastructure\Recovery\RegistryRecovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryRecoveryDatabaseTest extends TestCase
{
    private \PDO $pdo;
    private array $config;

    protected function setUp(): void
    {
        $this->pdo = $GLOBALS['offer_binding_test_pdo']; $this->config = $GLOBALS['offer_binding_test_config'];
        $this->mutate('DROP TABLE IF EXISTS test_eind_offer_binding');
        $this->mutate('DROP TABLE IF EXISTS recovery_unrelated');
        $this->mutate('CREATE TABLE recovery_unrelated (id INT PRIMARY KEY, payload VARBINARY(8)) ENGINE=InnoDB');
        $this->mutate("INSERT INTO recovery_unrelated VALUES(1,X'0041ff')");
    }

    private function mutate(string $sql): void
    {
        DisposableDatabaseGuard::assertSafe($this->pdo, $this->config); $this->pdo->exec($sql);
    }

    private function schema(): OfferBindingSchema
    {
        return new OfferBindingSchema('test_', function (string $sql): bool { $this->mutate($sql); return true; },
            fn (string $sql): array => $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function inspect(): array
    {
        DisposableDatabaseGuard::assertSafe($this->pdo, $this->config);
        $exists = (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='test_eind_offer_binding'")->fetchColumn() === 1;
        $registry = ['exists' => $exists, 'schema_valid' => false];
        if ($exists) {
            try { $this->schema()->verify(); $registry['schema_valid'] = true; } catch (\RuntimeException) { }
            $registry['rows'] = (int) $this->pdo->query('SELECT COUNT(*) FROM test_eind_offer_binding')->fetchColumn();
        }
        return ['identity_verified' => true, 'identity' => ['database' => $this->config['database']], 'source_version' => '1.1.0',
            'source_agrees' => true, 'registered' => true, 'registered_version' => '1.1.0', 'disabled' => true,
            'registration_consistent' => true, 'alternate_tables' => [], 'registry' => $registry,
            'unrelated' => hash('sha256', serialize($this->pdo->query('SELECT * FROM recovery_unrelated')->fetchAll()))];
    }

    private function coordinator(bool $interrupted = false): RegistryRecovery
    {
        $schema = $this->schema(); $lock = $schema->migrationLockName($this->config['database']);
        return new RegistryRecovery($this->inspect(...),
            function () use ($lock): bool { $query = $this->pdo->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lock]); return (int) $query->fetchColumn() === 1; },
            function () use ($lock): void { $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lock]); },
            function () use ($schema, $interrupted): void {
                if ($interrupted) { $this->mutate($schema->ddl()); throw new \RuntimeException('Interrupted after CREATE'); }
                $schema->install();
            }, static fn () => ['exit_code' => 1, 'registry' => ['schema_valid' => true],
                'checks' => [['name' => 'module_enabled', 'status' => 'FAIL', 'code' => 'REQUIRED_SHOP_DISABLED']]]);
    }

    protected function tearDown(): void
    {
        $this->mutate('DROP TABLE IF EXISTS test_eind_offer_binding'); $this->mutate('DROP TABLE IF EXISTS recovery_unrelated');
    }

    public function testCreationRetryAndReadOnlyDryRun(): void
    {
        $before = $this->inspect();
        self::assertSame('ELIGIBLE', $this->coordinator()->run()['status']);
        self::assertSame($before, $this->inspect());
        $result = $this->coordinator()->run(true, static fn () => true);
        self::assertSame(0, $result['exit_code']); self::assertSame('RECOVERED', $result['status']);
        self::assertSame(0, $result['registry_after']['rows']); $this->schema()->verify();
        self::assertSame($before['unrelated'], $this->inspect()['unrelated']);
        self::assertSame('UNNECESSARY', $this->coordinator()->run(true)['status']);
        $lock = $this->schema()->migrationLockName($this->config['database']);
        $query = $this->pdo->prepare('SELECT IS_USED_LOCK(?)'); $query->execute([$lock]);
        self::assertNull($query->fetchColumn(), 'Reentrant installer/recovery lock must be fully released');
    }

    public function testInterruptedCreationIsRetainedAndNextRecoveryIsNoOp(): void
    {
        $result = $this->coordinator(true)->run(true, static fn () => true);
        self::assertSame(2, $result['exit_code']); self::assertTrue($result['mutation_performed']);
        self::assertTrue($this->inspect()['registry']['schema_valid']);
        self::assertSame('UNNECESSARY', $this->coordinator()->run(true)['status']);
    }

    public function testIncompatibleRegistryIsUnchanged(): void
    {
        $this->mutate($this->schema()->ddl()); $this->mutate('ALTER TABLE test_eind_offer_binding DROP INDEX uq_offer_identity');
        $before = $this->pdo->query('SHOW CREATE TABLE test_eind_offer_binding')->fetchAll();
        self::assertSame(1, $this->coordinator()->run(true, static fn () => true)['exit_code']);
        self::assertSame($before, $this->pdo->query('SHOW CREATE TABLE test_eind_offer_binding')->fetchAll());
    }

    public function testCompatiblePopulatedRegistryIsPreservedExactly(): void
    {
        $this->mutate($this->schema()->ddl());
        $reference = "Exact Bytes \0";
        DisposableDatabaseGuard::assertSafe($this->pdo, $this->config);
        $query = $this->pdo->prepare("INSERT INTO test_eind_offer_binding (id_shop,id_supplier,order_reference,reference_sha256,state,provenance,creation_operation_id,created_at,updated_at) VALUES (1,901,?,?, 'RESERVED','MODULE_CREATED_LIVE',?,'2026-10-14 00:00:00.000001','2026-10-14 00:00:00.000001')");
        $query->execute([$reference, hash('sha256', $reference, true), random_bytes(16)]);
        $before = $this->pdo->query('SELECT * FROM test_eind_offer_binding')->fetchAll();
        $result = $this->coordinator()->run(true);
        self::assertSame('UNNECESSARY', $result['status']); self::assertFalse($result['mutation_performed']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM test_eind_offer_binding')->fetchAll());
    }

    public function testVerificationExceptionAfterCanonicalCreationPreservesTable(): void
    {
        $created = false;
        $schema = new OfferBindingSchema('test_', function (string $sql) use (&$created): bool {
            $this->mutate($sql); $created = true; return true;
        }, function (string $sql) use (&$created): array {
            if ($created && str_contains($sql, 'information_schema.')) { throw new \PDOException('Metadata unavailable after DDL'); }
            return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        });
        $recovery = new RegistryRecovery($this->inspect(...), static fn () => true, static function (): void {},
            $schema->install(...), static fn () => []);
        self::assertSame(2, $recovery->run(true, static fn () => true)['exit_code']);
        self::assertTrue($this->inspect()['registry']['schema_valid']);
        self::assertSame(0, $this->inspect()['registry']['rows']);
    }

    public function testOtherConnectionHoldingMigrationLockBlocksRecovery(): void
    {
        $other = new \PDO('mysql:host=' . getenv('EIND_TEST_DB_HOST') . ';port=' . (getenv('EIND_TEST_DB_PORT') ?: '3306') . ';dbname=' . $this->config['database'],
            getenv('EIND_TEST_DB_USER'), getenv('EIND_TEST_DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $lock = $this->schema()->migrationLockName($this->config['database']);
        $query = $other->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lock]); self::assertSame(1, (int) $query->fetchColumn());
        try { self::assertSame('RECOVERY_LOCK_BUSY', $this->coordinator()->run(true, static fn () => true)['code']); }
        finally { $query = $other->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lock]); }
        self::assertFalse($this->inspect()['registry']['exists']);
    }
}
