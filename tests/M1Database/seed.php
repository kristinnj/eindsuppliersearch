<?php
require __DIR__.'/bootstrap.php';
$pdo=$GLOBALS['offer_binding_test_pdo'];
$select=static fn($sql)=>$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$execute=static function($sql) use($pdo): bool { M1DisposableGuard::assertSafe($pdo);return $pdo->exec($sql)!==false; };
(new \EindSupplierSearch\Infrastructure\Database\OfferBindingSchema('test_',$execute,$select))->install();
(new \EindSupplierSearch\Infrastructure\Database\M1Schema('test_',$execute,$select))->install();
echo "Owned M1 seed verified\n";
