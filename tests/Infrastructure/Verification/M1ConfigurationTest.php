<?php

declare(strict_types=1);
namespace EindSupplierSearch\Tests\Infrastructure\Verification;

use EindSupplierSearch\Infrastructure\Verification\M1Configuration;
use EindSupplierSearch\Infrastructure\Database\M1Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class M1ConfigurationTest extends TestCase
{
    private const BODY = "'database_host'=>'fixture-host','database_name'=>'fixture_db','database_prefix'=>'test_','database_port'=>3306";
    private const TARGET = ['host'=>'fixture-host','database'=>'fixture_db','prefix'=>'test_','port'=>3306];

    public function testSupportedLiteralStructuresAndCommentTrivia(): void
    {
        foreach (["<?php return ['parameters'=>[".self::BODY.",]];",
            "<?php /* 'database_port'=>9999 */ return array('parameters'=>array(".self::BODY.",)); // trailing comment"] as $source) {
            $p = M1Configuration::installedParameters($source);
            M1Configuration::matchTarget($p, self::TARGET);
            self::assertSame(3306, $p['database_port']);
            self::assertSame('fixture-host', $p['database_host']);
        }
        $values=['parameters'=>['database_host'=>'fixture-host','database_name'=>'fixture_db',
            'database_prefix'=>'test_','database_port'=>null,'extra'=>['flag'=>true,'disabled'=>false,
            'text'=>"quote' backslash\\ combination\\'",'empty'=>[]]]];
        self::assertSame($values['parameters'], M1Configuration::installedParameters('<?php return '.var_export($values,true).';'));
    }

    #[DataProvider('unsupported')]
    public function testAmbiguousOrUnsupportedSourceIsRejected(string $source): void
    {
        $this->expectException(\RuntimeException::class);
        M1Configuration::installedParameters($source);
    }

    public static function unsupported(): array
    {
        $b=self::BODY;
        $cases=[];
        foreach (["'database_port'=>3307", "'database_port'=>3306", "'database_host'=>'fixture-host'",
            "'database_name'=>'fixture_db'", "'database_prefix'=>'test_'", "'database_host'=>'different'",
            "'database_name'=>'different'", "'database_prefix'=>'different_'", "'database_user'=>'b'"] as $duplicate) {
            $body=$b.($duplicate==="'database_user'=>'b'" ? ",'database_user'=>'a'" : '');
            $cases['duplicate '.$duplicate]=["<?php return ['parameters'=>[$body,$duplicate]];"];
        }
        $cases += [
            'comment is not assignment'=>["<?php return ['parameters'=>[/* $b */]];"],
            'commented port'=>["<?php return ['parameters'=>[".str_replace("'database_port'=>3306", "/* 'database_port'=>3306 */",$b)."]];"],
            'dynamic port'=>["<?php return ['parameters'=>[".str_replace('3306',"getenv('DB_PORT')",$b)."]];"],
            'dynamic duplicate'=>["<?php return ['parameters'=>[$b,'database_port'=>getenv('DB_PORT')]];"],
            'comment masks dynamic host'=>["<?php /* $b */ return ['parameters'=>['database_host'=>getenv('DB_HOST')]];"],
            'missing key'=>["<?php return ['parameters'=>['database_port'=>3306]];"],
            'extra statement'=>["<?php touch('must-never-execute'); return ['parameters'=>[$b]];"],
            'expression'=>["<?php return ['parameters'=>[".str_replace('3306','3300+6',$b)."]];"],
            'unexpected root'=>["<?php return ['parameters'=>[$b],'other'=>[]];"],
            'unpacking'=>["<?php return ['parameters'=>[...['database_port'=>3306]]];"],
            'missing semicolon'=>["<?php return ['parameters'=>[$b]]"],
            'trailing code'=>["<?php return ['parameters'=>[$b]]; echo 'secret';"],
            'unterminated'=>["<?php return ['parameters'=>['database_host'=>'unterminated"],
            'no php tag'=>["return ['parameters'=>[$b]];"],
            'double quoted unsupported'=>['<?php return ["parameters"=>[]];'],
            'closing tag'=>["<?php return ['parameters'=>[$b]]; ?>"],
        ];
        return $cases;
    }

    #[DataProvider('targetChanges')]
    public function testWrongEndpointAndInvalidPortReject(array $changes): void
    {
        $p=M1Configuration::installedParameters("<?php return ['parameters'=>[".self::BODY."]];");
        $this->expectException(\RuntimeException::class);
        M1Configuration::matchTarget(array_replace($p,$changes), self::TARGET);
    }
    public static function targetChanges(): array
    {
        return [[['database_host'=>'other']], [['database_name'=>'other']], [['database_prefix'=>'other_']],
            [['database_port'=>3307]], [['database_port'=>false]], [['database_port'=>'03306']],
            [['database_port'=>0]], [['database_port'=>65536]], [['database_port'=>[]]]];
    }
    public function testExplicitDefaultAndStringPortsMatch(): void
    {
        $p=M1Configuration::installedParameters("<?php return ['parameters'=>[".self::BODY."]];");
        foreach ([3306,'3306',null,''] as $port) {
            M1Configuration::matchTarget(array_replace($p,['database_port'=>$port]),self::TARGET);
            self::assertTrue(true);
        }
    }
    public function testRealConfigurationLoaderRejectsAmbiguityBeforeAnyConnection(): void
    {
        $dir=sys_get_temp_dir().'/m1-config-'.bin2hex(random_bytes(8));
        mkdir($dir.'/app/config',0700,true);mkdir($dir.'/modules/eindsuppliersearch',0700,true);
        $path=$dir.'/reader.json';$installed=$dir.'/app/config/parameters.php';
        $config=self::TARGET+['user'=>'synthetic-test-only','password'=>'synthetic-test-only',
            'server_hostname'=>'synthetic-server','metadata_definer'=>'synthetic@%'];
        file_put_contents($path,json_encode($config,JSON_THROW_ON_ERROR));chmod($path,0600);
        $previousMigrationConfig = getenv('EIND_M1_MIGRATION_CONFIG');
        try {
            putenv('EIND_M1_MIGRATION_CONFIG='.$path);
            file_put_contents($installed,"<?php return ['parameters'=>[".self::BODY."]];");
            self::assertSame($config,M1Configuration::configuration($path,$dir.'/modules/eindsuppliersearch'));
            file_put_contents($installed,"<?php return ['parameters'=>[".self::BODY.",'database_port'=>3307]];");
            try {M1Configuration::configuration($path,$dir.'/modules/eindsuppliersearch');self::fail('Duplicate port accepted');}
            catch (\RuntimeException $e) {self::assertSame('Ambiguous installed configuration.',$e->getMessage());}
            // Exercise the production migration entry point: a PDO attempt would
            // fail differently against this deliberately nonexistent endpoint.
            try {M1Migration::run($dir.'/modules/eindsuppliersearch');self::fail('Migration accepted duplicate port');}
            catch (\RuntimeException $e) {self::assertSame('Ambiguous installed configuration.',$e->getMessage());}
            file_put_contents($installed,"<?php return ['parameters'=>[".str_replace('3306','3307',self::BODY)."]];");
            try {M1Configuration::configuration($path,$dir.'/modules/eindsuppliersearch');self::fail('Wrong port accepted');}
            catch (\RuntimeException $e) {self::assertSame('Target port mismatch.',$e->getMessage());}
            try {M1Migration::run($dir.'/modules/eindsuppliersearch');self::fail('Migration accepted wrong endpoint');}
            catch (\RuntimeException $e) {self::assertSame('Target port mismatch.',$e->getMessage());}
        } finally {
            putenv($previousMigrationConfig === false ? 'EIND_M1_MIGRATION_CONFIG' : 'EIND_M1_MIGRATION_CONFIG='.$previousMigrationConfig);
            unlink($installed);unlink($path);rmdir($dir.'/app/config');rmdir($dir.'/app');
            rmdir($dir.'/modules/eindsuppliersearch');rmdir($dir.'/modules');rmdir($dir);
        }
    }

}
