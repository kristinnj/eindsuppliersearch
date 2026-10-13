<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Database;

use EindSupplierSearch\Domain\LocalProductBindingEvidence;
use EindSupplierSearch\Domain\LocalProductCandidate;
use EindSupplierSearch\Domain\LocalProductLookupContext;
use EindSupplierSearch\Domain\LocalProductLookupReason as Reason;
use EindSupplierSearch\Domain\LocalProductLookupOutcome as Outcome;
use EindSupplierSearch\Domain\LocalProductNativeEvidence;
use EindSupplierSearch\Domain\LocalProductTarget;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Exception\LocalProductLookupException;
use EindSupplierSearch\Infrastructure\Database\NativeProductEvidenceReaderInterface;
use EindSupplierSearch\Infrastructure\Database\OfferBindingReaderInterface;
use EindSupplierSearch\Infrastructure\Database\PersistentLocalProductLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersistentLocalProductLookupTest extends TestCase
{
    private function row(string $reference = '001-Ab '): array
    {
        return ['id_binding' => '10', 'id_shop' => '1', 'id_supplier' => '2',
            'order_reference' => $reference, 'reference_sha256' => hash('sha256', $reference, true),
            'id_product' => '50', 'id_product_attribute' => '0', 'state' => 'ACTIVE',
            'provenance' => 'MODULE_CREATED_LIVE', 'creation_operation_id' => str_repeat("\0", 16),
            'integrity_reason' => null, 'created_at' => '2026-10-12 12:00:00.000001',
            'updated_at' => '2026-10-12 12:00:00.000001'];
    }

    private function adapter(array $rows, ?callable $nativeRows = null, ?array $claims = null): PersistentLocalProductLookup
    {
        $registry = $this->createMock(OfferBindingReaderInterface::class);
        $registry->method('find')->willReturn($rows);
        $registry->method('forTarget')->willReturn($claims ?? $rows);
        $native = $this->createMock(NativeProductEvidenceReaderInterface::class);
        $native->method('context')->willReturn(new LocalProductLookupContext(2, 1, true, true, true, true));
        $native->method('candidates')->willReturnCallback($nativeRows ?? static fn ($binding) => [
            new LocalProductCandidate($binding, new LocalProductNativeEvidence($binding->target,
                $binding->identity, true, true, 2)),
        ]);
        return new PersistentLocalProductLookup($registry, $native);
    }

    public static function exactReferences(): array
    {
        return array_map(static fn ($reference) => [$reference], [
            '001-Ab ', 'Ab', 'ab', ' Ab ', '0001', 'A/B-.:', "é", "e\u{0301}", "A\0B",
        ]);
    }

    #[DataProvider('exactReferences')]
    public function testExactBinaryIdentityIsPassedWithoutNormalization(string $reference): void
    {
        $identity = new SupplierOfferIdentity(2, $reference);
        $row = $this->row($reference);
        $registry = $this->createMock(OfferBindingReaderInterface::class);
        $registry->expects(self::once())->method('find')->with(1, 2, hash('sha256', $reference, true))->willReturn([$row]);
        $registry->expects(self::once())->method('forTarget')->willReturn([$row]);
        $native = $this->createMock(NativeProductEvidenceReaderInterface::class);
        $native->method('context')->willReturn(new LocalProductLookupContext(2, 1, true, true, true, true));
        $native->method('candidates')->willReturnCallback(static function ($binding) use ($identity) {
            self::assertSame($identity, $binding->identity);
            return [new LocalProductCandidate($binding, new LocalProductNativeEvidence($binding->target, $identity, true, true, 2))];
        });
        $result = (new PersistentLocalProductLookup($registry, $native))->lookup($identity, 1);
        self::assertSame(Outcome::UNIQUE, $result->getOutcome());
        self::assertSame(50, $result->getTarget()->getProductId());
    }

    public static function corruptRows(): array
    {
        return [
            'different shop' => [['id_shop' => '3'], 'IDENTITY_SCOPE_MISMATCH'],
            'different supplier' => [['id_supplier' => '3'], 'IDENTITY_SCOPE_MISMATCH'],
            'case mismatch/collision' => [['order_reference' => '001-ab '], 'HASH_COLLISION'],
            'whitespace mismatch' => [['order_reference' => '001-Ab'], 'HASH_COLLISION'],
            'leading zeros mismatch' => [['order_reference' => '01-Ab '], 'HASH_COLLISION'],
            'unicode mismatch' => [['order_reference' => "e\u{0301}"], 'HASH_COLLISION'],
            'digest mismatch' => [['reference_sha256' => str_repeat('x', 32)], 'DIGEST_MISMATCH'],
            'short digest' => [['reference_sha256' => 'x'], 'MALFORMED_REGISTRY_ROW'],
            'null digest' => [['reference_sha256' => null], 'MALFORMED_REGISTRY_ROW'],
            'active without target' => [['id_product' => null, 'id_product_attribute' => null], 'ACTIVE_WITHOUT_TARGET'],
            'partial target' => [['id_product' => null], 'MALFORMED_REGISTRY_ROW'],
            'zero target' => [['id_product' => '0'], 'MALFORMED_REGISTRY_ROW'],
            'invalid integer' => [['id_shop' => '1junk'], 'MALFORMED_REGISTRY_ROW'],
            'overflow integer' => [['id_binding' => '18446744073709551615'], 'MALFORMED_REGISTRY_ROW'],
            'unknown state' => [['state' => 'active'], 'MALFORMED_REGISTRY_ROW'],
            'unknown provenance' => [['provenance' => 'FIXTURE'], 'MALFORMED_REGISTRY_ROW'],
            'invalid operation' => [['creation_operation_id' => null], 'MALFORMED_REGISTRY_ROW'],
            'NUL date' => [['updated_at' => "2026\0"], 'MALFORMED_REGISTRY_ROW'],
            'invalid date' => [['updated_at' => '2026-02-31 12:00:00.000001'], 'MALFORMED_REGISTRY_ROW'],
            'time reversal' => [['updated_at' => '2025-10-12 12:00:00.000001'], 'MALFORMED_REGISTRY_ROW'],
            'invalid reason' => [['integrity_reason' => 'unexpected'], 'MALFORMED_REGISTRY_ROW'],
        ];
    }

    #[DataProvider('corruptRows')]
    public function testCorruptRegistryNeverReachesNativeReader(array $changes, string $diagnostic): void
    {
        $row = array_replace($this->row(), $changes);
        $registry = $this->createMock(OfferBindingReaderInterface::class);
        $registry->method('find')->willReturn([$row]);
        $registry->expects(self::never())->method('forTarget');
        $native = $this->createMock(NativeProductEvidenceReaderInterface::class);
        $native->expects(self::never())->method('context');
        $native->expects(self::never())->method('candidates');
        $adapter = new PersistentLocalProductLookup($registry, $native);
        $result = $adapter->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1);
        self::assertSame(Reason::INCONSISTENT_BINDING, $result->getReason());
        self::assertNull($result->getTarget());
        self::assertSame($diagnostic, $adapter->getDiagnostic());
    }

    public static function states(): array
    {
        return [['RESERVED', null], ['RESERVED', '50'], ['QUARANTINED', null],
            ['QUARANTINED', '50'], ['RETIRED', null], ['RETIRED', '50']];
    }

    #[DataProvider('states')]
    public function testNonActiveStatesRemainVisibleWithoutNativeReads(string $state, ?string $product): void
    {
        $row = array_replace($this->row(), ['state' => $state, 'id_product' => $product,
            'id_product_attribute' => $product === null ? null : '0',
            'integrity_reason' => $state === 'QUARANTINED' ? 'INSPECT' : null]);
        $adapter = $this->adapter([$row], static function () { self::fail('Non-active binding read native target.'); });
        $result = $adapter->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1);
        self::assertSame(Reason::OWNERSHIP_UNVERIFIED, $result->getReason());
        self::assertNull($result->getTarget());
        self::assertSame($state, $adapter->getDiagnostic());
    }

    public static function nativeFailures(): array
    {
        return [
            'missing product' => [false, true, 2, 2, '001-Ab ', 0, Reason::DANGLING_BINDING],
            'wrong shop' => [true, false, 2, 2, '001-Ab ', 0, Reason::RELATIONSHIP_CONFLICT],
            'missing association' => [true, true, 2, null, null, 0, Reason::RELATIONSHIP_CONFLICT],
            'wrong supplier' => [true, true, 2, 3, '001-Ab ', 0, Reason::RELATIONSHIP_CONFLICT],
            'wrong reference' => [true, true, 2, 2, '001-ab ', 0, Reason::RELATIONSHIP_CONFLICT],
            'default mismatch' => [true, true, 3, 2, '001-Ab ', 0, Reason::RELATIONSHIP_CONFLICT],
            'combination' => [true, true, 2, 2, '001-Ab ', 4, Reason::UNSUPPORTED_COMBINATION],
        ];
    }

    #[DataProvider('nativeFailures')]
    public function testEvaluatorRejectsCollectedNativeEvidence(bool $exists, bool $shop, int $default,
        ?int $supplier, ?string $reference, int $attribute, Reason $reason): void
    {
        $row = array_replace($this->row(), ['id_product_attribute' => (string) $attribute]);
        $adapter = $this->adapter([$row], static fn ($binding) => [new LocalProductCandidate($binding,
            new LocalProductNativeEvidence($binding->target,
                $supplier === null ? null : new SupplierOfferIdentity($supplier, $reference), $exists, $shop, $default))]);
        $result = $adapter->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1);
        self::assertSame($reason, $result->getReason());
        self::assertNull($result->getTarget());
    }

    public function testContradictoryNativeRowsCannotBeHiddenByMatchingRow(): void
    {
        $adapter = $this->adapter([$this->row()], static fn ($b) => [
            new LocalProductCandidate($b, new LocalProductNativeEvidence($b->target, $b->identity, true, true, 2)),
            new LocalProductCandidate($b, new LocalProductNativeEvidence($b->target, new SupplierOfferIdentity(3, 'other'), true, true, 2)),
        ]);
        self::assertSame(Reason::RELATIONSHIP_CONFLICT, $adapter->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1)->getReason());
    }

    public function testConflictingOrChangedTargetClaimsFailClosed(): void
    {
        foreach ([[], [$this->row(), array_replace($this->row(), ['id_supplier' => '3'])],
            [array_replace($this->row(), ['state' => 'RETIRED'])]] as $claims) {
            $adapter = $this->adapter([$this->row()], null, $claims);
            self::assertSame(Reason::INCONSISTENT_BINDING, $adapter->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1)->getReason());
        }
    }

    public function testMissingIdentityUsesContextAndDoesNotScanNativeProducts(): void
    {
        $adapter = $this->adapter([], static function () { self::fail('Missing identity scanned native products.'); });
        self::assertSame(Outcome::MISSING, $adapter->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1)->getOutcome());
    }

    public function testEmptyNativeEvidenceIsDanglingRatherThanMissing(): void
    {
        $adapter = $this->adapter([$this->row()], static fn () => []);
        self::assertSame(Reason::DANGLING_BINDING, $adapter->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1)->getReason());
    }

    public static function databaseFailures(): array
    {
        return [['registry', new \RuntimeException('query failure')], ['registry', new \PDOException('missing table')],
            ['registry', new \Error('unexpected failure')], ['native', new \PDOException('connection failure')],
            ['target', new \PDOException('target query failure')], ['candidates', new \PDOException('native query failure')]];
    }

    #[DataProvider('databaseFailures')]
    public function testOperationalFailuresPreserveExceptionContractAndSanitizeMessage(string $stage, \Throwable $error): void
    {
        $registry = $this->createMock(OfferBindingReaderInterface::class);
        $registry->method('find')->willReturn([$this->row()]);
        $registry->method('forTarget')->willReturn([$this->row()]);
        $native = $this->createMock(NativeProductEvidenceReaderInterface::class);
        if ($stage === 'registry') {
            $registry->method('find')->willThrowException($error);
        } elseif ($stage === 'target') {
            $registry->method('forTarget')->willThrowException($error);
        } elseif ($stage === 'candidates') {
            $native->method('context')->willReturn(new LocalProductLookupContext(2, 1, true, true, true, true));
            $native->method('candidates')->willThrowException($error);
        } else {
            $native->method('context')->willThrowException($error);
        }
        $adapter = new PersistentLocalProductLookup($registry, $native);
        try {
            $adapter->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1);
            self::fail('Operational failure was swallowed.');
        } catch (LocalProductLookupException $caught) {
            self::assertSame($error, $caught->getPrevious());
            self::assertSame('Read-only ownership lookup failed.', $caught->getMessage());
            self::assertSame('PERSISTENCE_FAILURE', $adapter->getDiagnostic());
        }
    }

    public function testLengthLimitAndInvalidShopAreCheckedBeforeIo(): void
    {
        $registry = $this->createMock(OfferBindingReaderInterface::class);
        $registry->expects(self::never())->method('find');
        $adapter = new PersistentLocalProductLookup($registry, $this->createMock(NativeProductEvidenceReaderInterface::class));
        self::assertSame(Reason::INCONSISTENT_BINDING, $adapter->lookup(new SupplierOfferIdentity(2, str_repeat('x', 4097)), 1)->getReason());
        self::assertSame('REFERENCE_TOO_LONG', $adapter->getDiagnostic());
        $this->expectException(\InvalidArgumentException::class);
        $adapter->lookup(new SupplierOfferIdentity(2, 'A'), 0);
    }

    public function testMalformedRowAndDuplicateBucketFailClosed(): void
    {
        foreach ([[null], [$this->row(), $this->row()]] as $rows) {
            self::assertSame(Reason::INCONSISTENT_BINDING,
                $this->adapter($rows)->lookup(new SupplierOfferIdentity(2, '001-Ab '), 1)->getReason());
        }
    }
}
