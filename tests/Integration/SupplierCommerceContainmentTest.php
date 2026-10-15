<?php
declare(strict_types=1);

namespace EindSupplierSearch\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupplierCommerceContainmentTest extends TestCase
{
    private function dispatch(array $changes = []): array
    {
        $scenario = array_replace_recursive([
            'request'=>['supplierId'=>'100002','product_reference'=>'0','order_code'=>'SHARED','quantity'=>'1','token'=>'synthetic-csrf'],
            'buckets'=>[
                100001=>['Products'=>[['OrderCode'=>'SHARED','ManufacturerPartNumber'=>'PART-A','Stock'=>['Level'=>5],'Prices'=>[]]]],
                100002=>['Products'=>[['OrderCode'=>'SHARED','ManufacturerPartNumber'=>'PART-B','Stock'=>['Level'=>50],'Prices'=>[]]]],
            ],
        ], $changes);
        $process = proc_open([PHP_BINARY, __DIR__ . '/../Support/commerce-controller.php'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($scenario, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);
        self::assertSame('', $errors);
        [$body,$audit] = explode("\nAUDIT:", $output);
        return [json_decode($body,true,512,JSON_THROW_ON_ERROR),json_decode($audit,true,512,JSON_THROW_ON_ERROR)];
    }

    public static function deniedRequests(): iterable
    {
        yield 'shared order code different suppliers'=>[[]];
        yield 'same supplier another reference'=>[['request'=>['supplierId'=>'100001','order_code'=>'DIFFERENT']]];
        yield 'different supplier different code'=>[['request'=>['order_code'=>'OTHER']]];
        yield 'missing supplier'=>[['request'=>['supplierId'=>'']]];
        yield 'malformed supplier'=>[['request'=>['supplierId'=>['100002']]]];
        yield 'missing row'=>[['request'=>['product_reference'=>'']]];
        yield 'malformed row'=>[['request'=>['product_reference'=>'0junk']]];
        yield 'stale row same code'=>[['request'=>['product_reference'=>'999']]];
        yield 'stale wrong code'=>[['request'=>['product_reference'=>'999','order_code'=>'STALE']]];
        yield 'cross supplier wrong code'=>[['request'=>['supplierId'=>'100003']]];
        yield 'order code array'=>[['request'=>['order_code'=>['SHARED']]]];
        yield 'missing session'=>[['session_valid'=>false]];
        yield 'unsupported combination'=>[['request'=>['id_product_attribute'=>'12']]];
        yield 'cache database failure'=>[['cache_exception'=>true]];
        yield 'cache failure in development mode'=>[['cache_exception'=>true,'dev'=>true]];
        yield 'invalid security token'=>[['request'=>['token'=>'wrong']]];
        yield 'malformed security token'=>[['request'=>['token'=>['synthetic-csrf']]]];
        yield 'invalid method'=>[['method'=>'GET']];
    }

    #[DataProvider('deniedRequests')]
    public function testNoSupplierRequestCanReachNativeResolutionOrMutation(array $changes): void
    {
        [$response,$audit] = $this->dispatch($changes);
        self::assertFalse($response['success']);
        self::assertNotEmpty($response['message']);
        self::assertArrayNotHasKey('product_id',$response);
        self::assertArrayNotHasKey('cart_quantity',$response);
        self::assertSame([],array_values(array_diff($audit['calls'],['getStrictCachedOffer'])));
        self::assertSame(0,$audit['native_queries']);
        self::assertStringNotContainsString('PRIVATE-CACHE-DATABASE-SENTINEL',json_encode([$response,$audit]));
    }

    public function testValidReferenceHasControlledUnavailableMessageAndIsRepeatable(): void
    {
        $first = $this->dispatch();
        self::assertSame('This supplier offer is not currently available for ordering. Please try another offer or contact us.', $first[0]['message']);
        self::assertSame($first,$this->dispatch());
    }

    public function testReferenceOnlyNativeLookupIsDisabledBeforeDatabaseAccess(): void
    {
        [$response,$audit] = $this->dispatch(['mode'=>'legacy_lookup']);
        self::assertSame(\LogicException::class,$response['exception'] ?? null);
        self::assertSame(0,$audit['native_queries']);
    }
}
