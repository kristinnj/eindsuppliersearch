<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Database;

use EindSupplierSearch\Infrastructure\Database\OfferBindingSchema;
use EindSupplierSearch\Infrastructure\Verification\PdoUpgradeReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpgradeVerificationDatabaseTest extends TestCase
{
    private \PDO $pdo;
    private array $config;

    protected function setUp(): void
    {
        $this->pdo = $GLOBALS['offer_binding_test_pdo'];
        $this->config = $GLOBALS['offer_binding_test_config'];
        $this->mutate('DROP TABLE IF EXISTS test_eind_offer_binding');
        $this->mutate('DROP VIEW IF EXISTS test_eind_upgrade_trigger_metadata');
    }

    private function mutate(string $sql): void
    {
        DisposableDatabaseGuard::assertSafe($this->pdo, $this->config);
        $this->pdo->exec($sql);
    }

    protected function tearDown(): void
    {
        $this->mutate('DROP TABLE IF EXISTS test_eind_offer_binding');
        $this->mutate('DROP VIEW IF EXISTS test_eind_upgrade_trigger_metadata');
    }

    public static function defects(): iterable
    {
        yield 'canonical' => ['', true];
        yield 'index' => ['DROP INDEX uq_offer_identity', false];
        yield 'column' => ['MODIFY reference_sha256 VARBINARY(32) NOT NULL', false];
        yield 'hash CHECK' => ['DROP CONSTRAINT ck_eob_hash', false];
        yield 'limit CHECK' => ['DROP CONSTRAINT ck_eob_reference', false];
        yield 'engine' => ['ENGINE=MyISAM', false];
    }

    #[DataProvider('defects')]
    public function testRealMetadataIsReadOnlyAndCanonical(string $alter, bool $valid): void
    {
        $schema = new OfferBindingSchema('test_', static function (string $sql): bool { throw new \LogicException('No schema mutation callback'); },
            fn (string $sql): array => $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC));
        $this->mutate($schema->ddl());
        $this->mutate('CREATE SQL SECURITY DEFINER VIEW test_eind_upgrade_trigger_metadata AS SELECT TRIGGER_SCHEMA, EVENT_OBJECT_TABLE, TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
        if ($alter !== '') { $this->mutate('ALTER TABLE test_eind_offer_binding ' . $alter); }
        $before = $this->pdo->query('SHOW CREATE TABLE test_eind_offer_binding')->fetchAll();
        $reader = new PdoUpgradeReader($this->pdo, 'test_');
        self::assertSame(['exists' => true, 'schema_valid' => $valid], $reader->registry());
        self::assertSame($before, $this->pdo->query('SHOW CREATE TABLE test_eind_offer_binding')->fetchAll());
        self::assertGreaterThan(0, count($reader->queries()));
        foreach ($reader->queries() as $sql) { self::assertStringStartsWith('SELECT ', $sql); }
    }

    public function testMissingRegistryIsNotSuccess(): void
    {
        self::assertSame(['exists' => false, 'schema_valid' => false], (new PdoUpgradeReader($this->pdo, 'test_'))->registry());
    }

    public function testAccessFailureIsNotAnIncompatibleSchemaResult(): void
    {
        $reader = new PdoUpgradeReader($this->pdo, 'test_');
        $this->expectException(\PDOException::class);
        $reader->registration();
    }

    public function testMutationQueryIsRejectedBeforeDatabaseExecution(): void
    {
        $reader = new PdoUpgradeReader($this->pdo, 'test_');
        $this->expectException(\LogicException::class);
        $reader->select('DELETE FROM test_eind_offer_binding');
    }
}
