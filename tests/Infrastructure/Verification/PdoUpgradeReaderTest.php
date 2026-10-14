<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Infrastructure\Verification;

use EindSupplierSearch\Infrastructure\Verification\PdoUpgradeReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdoUpgradeReaderTest extends TestCase
{
    public static function targetCases(): iterable
    {
        yield 'valid metadata bridge' => ['valid', true];
        yield 'missing trigger view' => ['missing', false];
        yield 'tampered trigger view' => ['tampered', false];
        yield 'wrong definer' => ['definer', false];
        yield 'lost definer visibility' => ['visibility', false];
        yield 'write-capable account' => ['writes', false];
        yield 'constraint enforcement disabled' => ['constraints', false];
    }

    #[DataProvider('targetCases')]
    public function testTrustworthyMetadataAndReadOnlyQueries(string $case, bool $valid): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn ($attribute) => $attribute === \PDO::ATTR_DRIVER_NAME ? 'mysql' : false);
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('beginTransaction');
        $grants = $this->createMock(\PDOStatement::class);
        $grants->method('fetchAll')->willReturn([
            'GRANT USAGE ON *.* TO `reader`@`%`',
            $case === 'writes' ? 'GRANT ALL PRIVILEGES ON `test`.* TO `reader`@`%`' : 'GRANT SELECT, SHOW VIEW ON `test`.* TO `reader`@`%`',
        ]);
        $pdo->expects(self::once())->method('query')->with('SHOW GRANTS FOR CURRENT_USER')->willReturn($grants);
        $view = ['VIEW_DEFINITION' => "select `information_schema`.`TRIGGERS`.`TRIGGER_SCHEMA` AS `TRIGGER_SCHEMA`, `information_schema`.`TRIGGERS`.`EVENT_OBJECT_TABLE` AS `EVENT_OBJECT_TABLE`, `information_schema`.`TRIGGERS`.`TRIGGER_NAME` AS `TRIGGER_NAME` from `information_schema`.`TRIGGERS` where (`information_schema`.`TRIGGERS`.`TRIGGER_SCHEMA` = 'test')",
            'SECURITY_TYPE' => 'DEFINER', 'DEFINER' => $case === 'definer' ? 'other@localhost' : 'metadata@localhost', 'IS_UPDATABLE' => 'NO'];
        if ($case === 'tampered') { $view['VIEW_DEFINITION'] .= ' AND 1=0'; }
        $responses = [[['db' => 'test', 'hostname' => 'isolated', 'account' => 'reader@%']],
            $case === 'missing' ? [] : [$view],
            [['VIEW_DEFINITION' => 'SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES', 'SECURITY_TYPE' => 'DEFINER', 'DEFINER' => 'metadata@localhost']],
            $case === 'visibility' ? [] : [['PRIVILEGE_TYPE' => 'TRIGGER']], [],
            [['checks' => $case === 'constraints' ? '0' : '1', 'uniques' => '1']]];
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$responses) {
            self::assertStringStartsWith('SELECT ', $sql);
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn(array_shift($responses));
            return $statement;
        });
        $reader = new PdoUpgradeReader($pdo, 'test_');
        if (!$valid) { $this->expectException(\RuntimeException::class); }
        $identity = $reader->validateTarget(['database' => 'test', 'server_hostname' => 'isolated', 'metadata_definer' => 'metadata@localhost']);
        self::assertSame(['database' => 'test', 'table_prefix' => 'test_', 'server_hostname' => 'isolated'], $identity);
    }
}
