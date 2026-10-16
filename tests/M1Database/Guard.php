<?php

declare(strict_types=1);

/** Adds owned-instance manifest/server/account/marker proof to the unchanged PDO guard. */
final class M1DisposableGuard
{
    public static function assertSafe(PDO $pdo): void
    {
        \EindSupplierSearch\Tests\Database\DisposableDatabaseGuard::assertSafe($pdo, $GLOBALS['offer_binding_test_config']);
        $path=getenv('EIND_M1_OWNERSHIP');
        if (!$path || !is_file($path)) { throw new RuntimeException('M1 ownership manifest required.'); }
        $m=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        $row=$pdo->query('SELECT DATABASE() AS db,@@hostname AS host,CURRENT_USER() AS account,VERSION() AS version')->fetch();
        if (!preg_match('/\Ahlutiris-m1-[a-f0-9]{12}\z/D',$m['run'] ?? '')
            || $m['server']!==$m['run'].'-server' || $m['volume']!==$m['run'].'-data' || $m['network']!==$m['run'].'-net'
            || $row['db']!==$m['database'] || $row['host']!==$m['server'] || $row['account']!=='guarded_test@%'
            || getenv('EIND_TEST_DB_HOST')!==$m['server'] || !str_starts_with($row['version'],'11.4.')
            || ($m['topology_verified'] ?? false)!==true) { throw new RuntimeException('M1 owned server identity refused.'); }
        $marker=$pdo->query('SELECT run_id,token_sha256 FROM m1_test_authorization')->fetchAll();
        if (count($marker)!==1 || $marker[0]['run_id']!==$m['run']
            || !hash_equals($marker[0]['token_sha256'],hash('sha256',getenv('EIND_M1_RUN_TOKEN') ?: ''))) {
            throw new RuntimeException('M1 ownership marker refused.');
        }
        foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (str_starts_with($grant,'GRANT USAGE ON *.* TO ')) { continue; }
            if (preg_match('/\AGRANT ALL PRIVILEGES ON `([^`]+)`\.\* TO /',$grant,$g)
                && str_replace('\\_','_',$g[1])===$m['database'] && !str_contains($grant,'WITH GRANT OPTION')) { continue; }
            throw new RuntimeException('M1 test account grants refused.');
        }
    }
}
