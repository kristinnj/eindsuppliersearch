<?php

declare(strict_types=1);

use EindSupplierSearch\Infrastructure\Database\M1Schema;
use EindSupplierSearch\Infrastructure\Database\M1IntegrityException;
use EindSupplierSearch\Infrastructure\Database\OfferBindingSchema;
use EindSupplierSearch\Infrastructure\Database\M1Migration;
use EindSupplierSearch\Infrastructure\Verification\PdoM1Reader;
use EindSupplierSearch\Infrastructure\Verification\M1Verification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class M1DatabaseTest extends TestCase
{
    private PDO $pdo;
    private array $config;
    protected function setUp(): void
    {
        $this->pdo=$GLOBALS['offer_binding_test_pdo'];
        $this->config=['host'=>getenv('EIND_TEST_DB_HOST'),'port'=>3306,'database'=>getenv('EIND_TEST_DB_NAME'),
            'prefix'=>'test_','user'=>'m1reader','password'=>getenv('EIND_M1_READER_PASSWORD'),
            'server_hostname'=>getenv('EIND_TEST_DB_HOST'),'metadata_definer'=>'m1meta@%'];
        foreach (['test_eind_quote','test_eind_observation','test_eind_creation_operation','test_eind_offer_binding','unrelated_m1'] as $table) {
            $this->mutate('DROP TABLE IF EXISTS `'.$table.'`');
        }
        (new OfferBindingSchema('test_',$this->mutate(...),$this->select(...)))->install();
        $this->mutate('CREATE TABLE unrelated_m1 (id INT PRIMARY KEY,value VARCHAR(20))');
        $this->mutate("INSERT INTO unrelated_m1 VALUES (1,'preserved')");
    }
    protected function tearDown(): void
    {
        foreach (['test_eind_quote','test_eind_observation','test_eind_creation_operation','test_eind_offer_binding','unrelated_m1'] as $table) {
            $this->mutate('DROP TABLE IF EXISTS `'.$table.'`');
        }
    }
    private function mutate(string $sql): bool { M1DisposableGuard::assertSafe($this->pdo);return $this->pdo->exec($sql)!==false; }
    private function select(string $sql): array { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    private function schema(?callable $execute=null): M1Schema { return new M1Schema('test_',$execute ?? $this->mutate(...),$this->select(...)); }
    private function account(string $kind): PDO
    {
        return new PDO('mysql:host='.$this->config['host'].';dbname='.$this->config['database'].';charset=utf8mb4',
            'm1'.$kind,getenv('EIND_M1_'.strtoupper($kind).'_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    private function report(?PDO $pdo=null): array
    {
        $reader=new PdoM1Reader($pdo ?? $this->account('reader'),'test_');
        $s=new M1Schema('test_',static function (): never { self::fail('Read-only gate attempted mutation'); },$reader->schemaSelect(...));
        return M1Verification::run(fn()=>$reader->validateTarget($this->config),$s->prerequisite(...),$s->enforcement(...),$s->unexpected(...),$s->verifyTable(...));
    }
    private function snapshot(): array
    {
        $result=[];
        foreach ($this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name,$type]) {
            if ($type==='VIEW') { continue; }
            $result[$name]=[$this->pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1],
                $this->pdo->query('SELECT * FROM `'.$name.'`')->fetchAll()];
        }
        ksort($result);return $result;
    }
    private function insertObservation(PDO $pdo, string $id='000102030405060708090a0b0c0d0e0f'): void
    {
        M1DisposableGuard::assertSafe($this->pdo);
        $pdo->exec("INSERT INTO test_eind_observation VALUES (X'$id',1,2,X'303031',UNHEX(SHA2(X'303031',256)),
            'LIVE','fixture','1',X'010102030405060708090a0b0c0d0e0f','2026-10-15 00:00:00','2026-10-15 00:01:00',
            REPEAT('a',32),REPEAT('b',32),'EUR',1.000000,2,1,X'7b7d',UNHEX(SHA2(X'7b7d',256)),'fixture',REPEAT('c',32))");
    }

    #[DataProvider('partialStates')]
    public function testAllPartialStatesAndIdempotency(array $retained): void
    {
        $s=$this->schema();foreach ($retained as $name) {$this->mutate($s->ddl($name));}
        $beforeRegistry=$this->pdo->query('SHOW CREATE TABLE test_eind_offer_binding')->fetchAll();
        $s->install();$s->verify();self::assertSame(0,$this->report()['exit_code']);
        $before=$this->snapshot();$s->install();self::assertSame($before,$this->snapshot());
        self::assertSame($beforeRegistry,$this->pdo->query('SHOW CREATE TABLE test_eind_offer_binding')->fetchAll());
        self::assertSame('preserved',$this->pdo->query('SELECT value FROM unrelated_m1')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM test_eind_offer_binding')->fetchColumn());
        self::assertArrayNotHasKey('test_eind_creation_operation',$before);
    }
    public static function partialStates(): array {return [[[]],[['eind_observation']],[['eind_quote']],[['eind_observation','eind_quote']]];}

    #[DataProvider('incompatibilities')]
    public function testIncompatibleObjectsBlockBeforeAnyCreate(string $name,string $alter): void
    {
        $s=$this->schema();$this->mutate($s->ddl($name));$this->mutate('ALTER TABLE test_'.$name.' '.$alter);
        $before=$this->snapshot();
        try {$s->install();self::fail('Incompatible accepted');}catch(M1IntegrityException){}
        self::assertSame($before,$this->snapshot());self::assertSame(1,$this->report()['exit_code']);
    }
    public static function incompatibilities(): array
    {
        return [['eind_observation','MODIFY observation_id VARBINARY(16) NOT NULL'],
            ['eind_quote','MODIFY unit_price_tax_excl DECIMAL(19,5) NOT NULL'],
            ['eind_quote','DROP INDEX ix_quote_observation'],['eind_observation','DROP CONSTRAINT ck_obs_ref'],
            ['eind_quote',"MODIFY currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL"],
            ['eind_observation','ADD unexpected INT NULL'],['eind_quote','MODIFY key_id VARCHAR(40) NOT NULL DEFAULT \'x\'']];
    }
    public function testInterruptedCreateRetainedAndRetryCompletes(): void
    {
        $calls=0;$s=$this->schema(function($sql)use(&$calls):bool{
            if (++$calls===2){throw new RuntimeException('Simulated lost DDL acknowledgment');}return $this->mutate($sql);
        });
        try{$s->install();self::fail('Interruption missing');}catch(RuntimeException){}
        self::assertSame(1,$this->report()['exit_code']);$this->schema()->install();self::assertSame(0,$this->report()['exit_code']);
    }
    public function testRegistryMissingAndUnexpectedLaterPhaseRefuse(): void
    {
        $this->mutate('DROP TABLE test_eind_offer_binding');$before=$this->snapshot();
        try{$this->schema()->install();self::fail('Missing prerequisite accepted');}catch(RuntimeException){}
        self::assertSame($before,$this->snapshot());self::assertSame(1,$this->report()['exit_code']);
        (new OfferBindingSchema('test_',$this->mutate(...),$this->select(...)))->install();
        $this->mutate('CREATE TABLE test_eind_creation_operation (id INT)');$before=$this->snapshot();
        try{$this->schema()->install();self::fail('Later phase accepted');}catch(M1IntegrityException){}
        self::assertSame($before,$this->snapshot());
    }
    public function testRealTriggerAndForeignKeyRejectedWithoutRepair(): void
    {
        $this->schema()->install();
        $this->mutate('CREATE TRIGGER m1_extra BEFORE INSERT ON test_eind_observation FOR EACH ROW SET NEW.id_shop=1');
        self::assertSame(1,$this->report()['exit_code']);$this->mutate('DROP TRIGGER m1_extra');
        $this->mutate('ALTER TABLE test_eind_quote ADD CONSTRAINT m1_fk FOREIGN KEY (observation_id) REFERENCES test_eind_observation(observation_id)');
        self::assertSame(1,$this->report()['exit_code']);
    }
    public function testInsufficientMetadataAndWriterCannotPoseAsReader(): void
    {
        $this->schema()->install();self::assertSame(2,$this->report($this->account('insert'))['exit_code']);
        $this->mutate('RENAME TABLE test_eind_upgrade_metadata_capability TO m1_hidden_capability');
        try{self::assertSame(2,$this->report()['exit_code']);}finally{$this->mutate('RENAME TABLE m1_hidden_capability TO test_eind_upgrade_metadata_capability');}
        self::assertSame(2,$this->report($this->account('migration'))['exit_code']);
    }
    public function testConstraintSettingsAndActualRepresentations(): void
    {
        $this->schema()->install();$reader=$this->account('reader');$reader->exec('SET SESSION check_constraint_checks=0');
        self::assertSame(2,$this->report($reader)['exit_code']);$reader->exec('SET SESSION check_constraint_checks=1');
        $reader->exec('SET SESSION unique_checks=0');self::assertSame(2,$this->report($reader)['exit_code']);
        $this->insertObservation($this->account('insert'));
        // Engine permits short BINARY padding and rounds fractional precision: not authenticity guarantees.
        $this->mutate("UPDATE test_eind_observation SET observation_id=X'01',unit_price_tax_excl=1.0000004");
        $row=$this->pdo->query('SELECT HEX(observation_id) AS id,unit_price_tax_excl, origin=\'LIVE \' AS padded FROM test_eind_observation')->fetch();
        self::assertSame('01'.str_repeat('00',15),$row['id']);self::assertSame('1.000000',$row['unit_price_tax_excl']);self::assertSame(1,(int)$row['padded']);
        foreach (["id_shop=0","reference_sha256=REPEAT('z',32)","origin='live'","schema_version=NULL","unit_price_tax_excl=0"] as $bad) {
            try{$this->mutate('UPDATE test_eind_observation SET '.$bad);self::fail('CHECK/NULL accepted');}catch(PDOException $e){self::assertNotSame('00000',$e->getCode());}
        }
        // Structural verification deliberately does not read or authenticate synthetic content.
        self::assertSame(0,$this->report()['exit_code']);self::assertFalse($this->report()['commerce_eligible']);
    }
    public function testReaderAndInsertOnlyPrivilegesAndPreservedData(): void
    {
        $this->schema()->install();$before=$this->snapshot();$ro=$this->account('reader');self::assertSame(0,$this->report($ro)['exit_code']);
        self::assertSame($before,$this->snapshot());$insert=$this->account('insert');$this->insertObservation($insert);
        foreach ([$ro,$insert] as $pdo) {
            foreach (["UPDATE test_eind_observation SET id_shop=2","DELETE FROM test_eind_observation",
                'REPLACE INTO test_eind_observation SELECT * FROM test_eind_observation','TRUNCATE TABLE test_eind_observation',
                'ALTER TABLE test_eind_observation ADD bad INT','DROP TABLE test_eind_quote','CREATE TABLE forbidden_probe(id INT)',
                'CREATE TRIGGER denied_trigger BEFORE INSERT ON test_eind_observation FOR EACH ROW SET NEW.id_shop=1',
                "GRANT SELECT ON test_eind_observation TO 'm1reader'@'%'",'DELETE FROM test_eind_offer_binding'] as $sql) {
                M1DisposableGuard::assertSafe($this->pdo);
                $this->assertPermissionDenied($pdo,$sql);
            }
        }
    }
    public function testDedicatedMigrationAndExecutableCli(): void
    {
        $directory=sys_get_temp_dir().'/m1-cli-'.bin2hex(random_bytes(6));mkdir($directory.'/modules/eindsuppliersearch/bin',0700,true);mkdir($directory.'/app/config',0700,true);
        $module=dirname(__DIR__,2);$fake=$directory.'/modules/eindsuppliersearch';
        symlink($module.'/src',$fake.'/src');copy($module.'/bin/verify-evidence-m1.php',$fake.'/bin/verify-evidence-m1.php');
        $config=$this->config;
        file_put_contents($directory.'/app/config/parameters.php',"<?php return ['parameters'=>['database_host'=>'".$config['host']."','database_name'=>'".$config['database']."','database_prefix'=>'test_','database_port'=>3306]];");
        $path=$directory.'/reader.json';file_put_contents($path,json_encode($config));chmod($path,0600);
        $writer=$config;$writer['user']='m1migration';$writer['password']=getenv('EIND_M1_MIGRATION_PASSWORD');
        $wpath=$directory.'/writer.json';file_put_contents($wpath,json_encode($writer));chmod($wpath,0600);
        try {
            putenv('EIND_M1_MIGRATION_CONFIG='.$wpath);M1Migration::run($fake);
            $before=$this->snapshot();M1Migration::run($fake);self::assertSame($before,$this->snapshot());
            putenv('EIND_M1_VERIFY_CONFIG='.$path);
            $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($fake.'/bin/verify-evidence-m1.php').' --mode=structure --phase=M1 --json';
            exec($cmd,$output,$code);$r=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
            self::assertSame(0,$code);self::assertSame('M1',$r['phase']);self::assertSame('structure',$r['mode']);self::assertFalse($r['commerce_eligible']);
            self::assertSame($before,$this->snapshot());
            $this->mutate('ALTER TABLE test_eind_quote DROP INDEX ix_quote_observation');
            $output=[];exec($cmd,$output,$code);
            $r=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
            self::assertSame(1,$code);self::assertSame(1,$r['exit_code']);self::assertFalse($r['commerce_eligible']);
            $this->mutate('RENAME TABLE test_eind_upgrade_metadata_capability TO m1_hidden_capability');
            try {
                $output=[];exec($cmd,$output,$code);
                $r=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
                self::assertSame(2,$code);self::assertSame(2,$r['exit_code']);self::assertFalse($r['commerce_eligible']);
            } finally {$this->mutate('RENAME TABLE m1_hidden_capability TO test_eind_upgrade_metadata_capability');}
        } finally {
            putenv('EIND_M1_MIGRATION_CONFIG');putenv('EIND_M1_VERIFY_CONFIG');
            unlink($fake.'/src');unlink($fake.'/bin/verify-evidence-m1.php');rmdir($fake.'/bin');rmdir($fake);rmdir($directory.'/modules');
            unlink($directory.'/app/config/parameters.php');rmdir($directory.'/app/config');rmdir($directory.'/app');unlink($path);unlink($wpath);rmdir($directory);
        }
    }

    public function testPopulatedRegistryAndAuxiliaryRowsRemainByteIdentical(): void
    {
        $this->mutate("INSERT INTO test_eind_offer_binding (id_shop,id_supplier,order_reference,reference_sha256,state,provenance,creation_operation_id,created_at,updated_at) VALUES (1,2,X'303031',UNHEX(SHA2(X'303031',256)),'RETIRED','MODULE_CREATED_LIVE',REPEAT('x',16),'2026-10-15','2026-10-15')");
        $this->schema()->install();$this->insertObservation($this->pdo);$before=$this->snapshot();
        $this->schema()->install();self::assertSame($before,$this->snapshot());
        self::assertSame(0,$this->report()['exit_code']);self::assertSame($before,$this->snapshot());
    }

    public function testUniquePrimaryKeysAndNullableCheckSemantics(): void
    {
        $this->schema()->install();$this->insertObservation($this->pdo);
        try{$this->insertObservation($this->pdo);self::fail('Duplicate ID accepted');}catch(PDOException $e){self::assertSame('23000',$e->getCode());}
        $this->mutate("INSERT INTO test_eind_quote VALUES (REPEAT('q',16),REPEAT('o',16),REPEAT('s',16),'CREATE',1,1,1,'EUR',1,0,'2026-10-15','2026-10-16',REPEAT('p',32),X'7b7d',UNHEX(SHA2(X'7b7d',256)),'fixture',REPEAT('m',32))");
        try{$this->mutate('INSERT INTO test_eind_quote SELECT * FROM test_eind_quote');self::fail('Duplicate quote accepted');}catch(PDOException $e){self::assertSame('23000',$e->getCode());}
        $this->mutate('CREATE TABLE m1_nullable_check (value INT NULL CHECK(value>0))');
        try {
            $this->mutate('INSERT INTO m1_nullable_check VALUES(NULL)');
            self::assertNull($this->pdo->query('SELECT value FROM m1_nullable_check')->fetchColumn());
            $this->mutate('SET SESSION check_constraint_checks=0');
            $this->mutate('UPDATE test_eind_observation SET id_shop=0');
            self::assertSame(0,(int)$this->pdo->query('SELECT id_shop FROM test_eind_observation')->fetchColumn());
        } finally {
            $this->mutate('SET SESSION check_constraint_checks=1');$this->mutate('DROP TABLE m1_nullable_check');
        }
        // An orphan quote and historically unchecked contents are outside structural scope.
        self::assertSame(0,$this->report()['exit_code']);self::assertFalse($this->report()['commerce_eligible']);
    }

    public function testHeldSchemaLockRefusesWithoutAnyDdl(): void
    {
        $other=new PDO('mysql:host='.getenv('EIND_TEST_DB_HOST').';dbname='.getenv('EIND_TEST_DB_NAME'),
            'guarded_test',getenv('EIND_TEST_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $lock='m1:'.substr(hash('sha256',$this->config['database'].':test_'),0,56);
        $q=$other->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);self::assertSame(1,(int)$q->fetchColumn());
        $before=$this->snapshot();
        try{$this->schema()->install();self::fail('Competing migration lock ignored');}catch(RuntimeException){}
        finally{$q=$other->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
        self::assertSame($before,$this->snapshot());$this->schema()->install();self::assertSame(0,$this->report()['exit_code']);
    }

    public function testM1ViewInsteadOfTableAndUnknownNamespaceAreRejected(): void
    {
        $this->mutate('CREATE VIEW test_eind_observation AS SELECT 1 AS observation_id');
        try {
            $before=$this->pdo->query('SHOW CREATE VIEW test_eind_observation')->fetchAll();
            try{$this->schema()->install();self::fail('View accepted as table');}catch(M1IntegrityException){}
            self::assertSame($before,$this->pdo->query('SHOW CREATE VIEW test_eind_observation')->fetchAll());
        }finally{$this->mutate('DROP VIEW test_eind_observation');}
        $this->mutate('CREATE TABLE test_eind_unknown_future(id INT)');
        try{$this->schema()->install();self::fail('Unknown module object accepted');}catch(M1IntegrityException $e){self::assertSame('UNEXPECTED_M1_OBJECT',$e->getMessage());}
        finally{$this->mutate('DROP TABLE test_eind_unknown_future');}
    }

    public function testAmbiguousTargetAndInvalidPrefixFail(): void
    {
        $bad=$this->config;$bad['database'].='_wrong';
        $r=new PdoM1Reader($this->account('reader'),'test_');
        try{$r->validateTarget($bad);self::fail('Wrong target accepted');}catch(RuntimeException){self::assertTrue(true);}
        $this->expectException(InvalidArgumentException::class);
        new M1Schema('bad-prefix',$this->mutate(...),$this->select(...));
    }
    private function assertPermissionDenied(PDO $pdo,string $sql): void
    {
        M1DisposableGuard::assertSafe($this->pdo);
        try {$pdo->exec($sql);self::fail('Unauthorized privilege succeeded');}
        catch (PDOException $e) {
            self::assertSame('42000',$e->getCode());
            self::assertContains((int)$e->errorInfo[1],[1044,1142,1143,1227]);
        }
    }

    public function testMigrationAccountCanCreateOnlyM1AndCannotMutateRows(): void
    {
        $writer=$this->account('migration');
        $reader=new PdoM1Reader($writer,'test_',true);
        $reader->validateTarget($this->config);
        foreach (array_keys(M1Schema::HASHES) as $name) {
            M1DisposableGuard::assertSafe($this->pdo);
            self::assertSame(0,$writer->exec($this->schema()->ddl($name)));
        }
        self::assertSame(0,$this->report()['exit_code']);
        foreach (['CREATE TABLE forbidden_probe(id INT)',
            'ALTER TABLE test_eind_observation ADD forbidden INT','DROP TABLE test_eind_quote',
            'TRUNCATE TABLE test_eind_quote',
            'INSERT INTO test_eind_quote SELECT * FROM test_eind_quote',
            'UPDATE test_eind_quote SET quantity=2','DELETE FROM test_eind_quote',
            'REPLACE INTO test_eind_quote SELECT * FROM test_eind_quote',
            'CREATE TRIGGER forbidden_trigger BEFORE INSERT ON test_eind_quote FOR EACH ROW SET NEW.quantity=1',
            "GRANT SELECT ON test_eind_quote TO 'm1reader'@'%'",
            'DELETE FROM test_eind_offer_binding'] as $sql) {
            $this->assertPermissionDenied($writer,$sql);
        }
        self::assertSame(2,$this->report($writer)['exit_code']);
        foreach (['broad','grant','role'] as $kind) {
            $pdo=$this->account($kind);
            $r=new PdoM1Reader($pdo,'test_',true);
            try {$r->validateTarget($this->config);self::fail('Unexpected privilege accepted');}
            catch (RuntimeException $e) {self::assertSame('Unexpected database permissions.',$e->getMessage());}
        }
    }

    public function testDescendingIndexIsRejectedByMigrationAndGate(): void
    {
        $this->schema()->install();self::assertSame(0,$this->report()['exit_code']);
        $this->mutate('ALTER TABLE test_eind_quote DROP INDEX ix_quote_scope_expiry, ADD KEY ix_quote_scope_expiry(auth_scope,expires_at DESC)');
        $metadata=$this->pdo->query("SELECT COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='test_eind_quote' AND INDEX_NAME='ix_quote_scope_expiry' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['A','D'],$metadata);
        $before=$this->snapshot();$writes=[];
        $schema=$this->schema(function($sql)use(&$writes):bool{$writes[]=$sql;return $this->mutate($sql);});
        try {$schema->install();self::fail('Descending index accepted');}
        catch (M1IntegrityException $e) {self::assertSame('M1_INDEX_SEMANTICS',$e->getMessage());}
        self::assertSame([],$writes);self::assertSame($before,$this->snapshot());
        $r=$this->report();self::assertSame(1,$r['exit_code']);self::assertFalse($r['commerce_eligible']);
        self::assertContains('M1_INDEX_SEMANTICS',array_column($r['checks'],'code'));
    }

    #[DataProvider('unknownDirections')]
    public function testUnprovedIndexDirectionFailsClosed(mixed $direction): void
    {
        $this->schema()->install();
        $select=function($sql)use($direction):array {
            $rows=$this->select($sql);
            if (str_contains($sql,'information_schema.STATISTICS')) {
                if ($direction==='MISSING') {unset($rows[0]['COLLATION']);}
                else {$rows[0]['COLLATION']=$direction;}
            }
            return $rows;
        };
        $s=new M1Schema('test_',static function():never{self::fail('Unexpected DDL');},$select);
        $this->expectException(M1IntegrityException::class);
        $this->expectExceptionMessage('M1_INDEX_SEMANTICS');$s->install();
    }
    public static function unknownDirections(): array {return [[null],[''],['UNKNOWN'],['MISSING']];}

    #[DataProvider('retainedCompatibility')]
    public function testCommittedCreateWithoutAcknowledgmentIsIndependentlyReconciled(bool $incompatible): void
    {
        $calls=[];
        $s=$this->schema(function($sql)use(&$calls):bool {
            $calls[]=$sql;$this->mutate($sql);
            throw new RuntimeException('Injected untrustworthy CREATE acknowledgment');
        });
        try {$s->install();self::fail('Lost acknowledgment ignored');}
        catch (RuntimeException $e) {self::assertSame('Injected untrustworthy CREATE acknowledgment',$e->getMessage());}
        self::assertCount(1,$calls);
        $observation=$this->pdo->query('SHOW CREATE TABLE test_eind_observation')->fetchAll();
        self::assertSame(1,$this->report()['exit_code']); // quote remains absent
        if ($incompatible) {$this->mutate('ALTER TABLE test_eind_observation DROP CONSTRAINT ck_obs_ref');}
        $before=$this->snapshot();$retry=[];
        $s=$this->schema(function($sql)use(&$retry):bool{$retry[]=$sql;return $this->mutate($sql);});
        if ($incompatible) {
            try {$s->install();self::fail('Incompatible retained object accepted');}
            catch (M1IntegrityException $e) {self::assertSame('M1_CHECKS',$e->getMessage());}
            self::assertSame([],$retry);self::assertSame($before,$this->snapshot());
        } else {
            $s->install();self::assertSame([$s->ddl('eind_quote')],$retry);
            self::assertSame($observation,$this->pdo->query('SHOW CREATE TABLE test_eind_observation')->fetchAll());
            self::assertSame(0,$this->report()['exit_code']);
        }
    }
    public static function retainedCompatibility(): array {return [[false],[true]];}

    public function testEscapedCheckMetadataCannotPassFrozenManifest(): void
    {
        $this->schema()->install();
        // Unknown extra CHECK with an escaped literal: native rendering must reject,
        // regardless of whether MariaDB chooses backslash or doubled-quote spelling.
        $this->mutate("ALTER TABLE test_eind_observation ADD CONSTRAINT extra_escaped CHECK (adapter_id <> 'a\\' X Y')");
        $clause=$this->pdo->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='test_eind_observation' AND CONSTRAINT_NAME='extra_escaped'")->fetchColumn();
        self::assertIsString($clause);self::assertNotSame('',$clause);
        $before=$this->snapshot();self::assertSame(1,$this->report()['exit_code']);
        try {$this->schema()->install();self::fail('Extra escaped CHECK accepted');}
        catch (M1IntegrityException) {}
        self::assertSame($before,$this->snapshot());
    }

}
