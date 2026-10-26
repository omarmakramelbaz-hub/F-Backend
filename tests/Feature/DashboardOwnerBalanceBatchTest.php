<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\BranchShiftClosing;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/DashboardHomeOverviewTest.php';

class DashboardOwnerBalanceBatchTest extends DashboardHomeOverviewTest
{
    private function batchActor(): User {return User::withoutGlobalScopes()->findOrFail(1);}
    private function batchKey(int $id): string {return sprintf('00000000-0000-4000-8000-%012d',$id);}
    private function batchTill(string $branch='f:100',int $balance=50000): int
    {
        return DB::table('takeaway_tills')->insertGetId(['branch'=>$branch,'balance_cents'=>$balance,'tax_bps'=>0,'revision'=>1]);
    }
    private function batchOrder(int $id,array $extra=[],array $lines=[]): void
    {
        DB::table('orders')->insert($extra+['id'=>$id,'resturant_id'=>100,'user_id'=>20,'status'=>'completed','type'=>'current',
            'payment_type'=>'cash','transfer_price_by'=>'vendor','total_price'=>'100.01','delivery_price'=>'20.00','user_tax'=>'1.13',
            'created_at'=>'2026-10-05 06:00:00','updated_at'=>'2026-10-05 06:00:00']);
        foreach($lines as $line)DB::table('carts')->insert($line+['order_id'=>$id,'price'=>'1.00','qty'=>'1.000','updated_total'=>null]);
    }
    private function batchPos(int $id,int $till,string $method,int $delivery): void
    {
        DB::table('takeaway_orders')->insert(['id'=>$id,'branch'=>'f:100','till_id'=>$till,'actor_id'=>10,
            'request_key'=>$this->batchKey($id),'request_hash'=>str_repeat('a',64),'quote_hash'=>str_repeat('b',64),
            'business_date'=>'2026-10-05','payment_method'=>$method,'subtotal_cents'=>10000,'discount_cents'=>0,'tax_cents'=>0,
            'total_cents'=>10000+$delivery,'cash_received_cents'=>10000+$delivery,'change_cents'=>0,'tax_bps'=>0,
            'branch_snapshot'=>'{}','cashier_snapshot'=>'{}','channel'=>'phone','delivery_cents'=>$delivery,
            'tenders_snapshot'=>$method==='mixed'?'[{"method":"cash","amount_cents":5000},{"method":"card","amount_cents":8500}]':null,
            'created_at'=>'2026-10-05 06:00:00','updated_at'=>'2026-10-05 06:00:00']);
    }
    private function batchClosing(string $branch): int
    {
        return DB::table('branch_shift_closings')->insertGetId(['branch'=>$branch,'sequence'=>1,'actor_id'=>1,
            'request_key'=>$this->batchKey(90000),'request_hash'=>str_repeat('c',64),'activated_at'=>'2026-10-05 03:00:00',
            'started_at'=>'2026-10-05 03:00:00','closed_at'=>'2026-10-05 03:00:00','counted_cents'=>0,'expected_cents'=>0,
            'variance_cents'=>0,'till_balance_cents'=>0,'snapshot'=>'{}']);
    }
    private function measuredBalances(array $branches=['f:100'],?BranchShiftClosing $service=null): array
    {
        DB::flushQueryLog();DB::enableQueryLog();
        try{$value=($service??app(BranchShiftClosing::class))->ownerBalances($branches,$this->batchActor());return [$value,DB::getQueryLog()];}
        finally{DB::disableQueryLog();DB::flushQueryLog();}
    }
    private function batchQueries(array $queries,string $table): array
    {
        return array_values(array_filter($queries,fn($query)=>preg_match('/\bfrom\s+["`]?'.preg_quote($table,'/').'["`]?\b/i',$query['query'])));
    }

    public static function batchSizes(): array {return ['one'=>[1],'boundary'=>[500],'next_batch'=>[501],'three_batches'=>[1001]];}
    /** @dataProvider batchSizes */
    public function test_batch_reads_are_bounded_and_fee_is_not_queried_per_order(int $count): void
    {
        $this->batchTill();
        for($id=1;$id<=$count;$id++)$this->batchOrder($id,[],[['price'=>'100.01','qty'=>'1.000']]);
        [$balances,$queries]=$this->measuredBalances();
        $this->assertSame(50000+11114*$count,$balances['f:100']['expected_cents']);
        $carts=$this->batchQueries($queries,'carts');$fees=$this->batchQueries($queries,'settings');
        $this->assertCount((int)ceil($count/500),$carts);$this->assertCount(1,$fees);
        $ids=[];foreach($carts as $query){$this->assertLessThanOrEqual(500,count($query['bindings']));$this->assertStringContainsString(' in (',strtolower($query['query']));$ids=array_merge($ids,$query['bindings']);}
        $this->assertEquals(range(1,$count),$ids);
    }

    public function test_batch_preserves_cash_delivery_mixed_card_quantity_rounding_updated_totals_and_empty_carts(): void
    {
        $till=$this->batchTill();$this->batchPos(1,$till,'cash',2000);$this->batchPos(2,$till,'mixed',3500);$this->batchPos(3,$till,'card',7000);
        $this->batchOrder(1); // No carts: frozen order subtotal fallback, 100.01 + 10.00 fee + 1.13 tax.
        $this->batchOrder(2,[],[['price'=>'19.99','qty'=>'0.333'],['updated_total'=>'4.25','price'=>'999.99','qty'=>'7.000']]);
        $this->batchOrder(3,[],[['updated_total'=>'41.23','price'=>'999.99','qty'=>'7.000']]);
        $this->batchOrder(4,[],[['price'=>'0.05','qty'=>'0.100']]); // Half-cent quantity rounds up before fee.
        $this->batchOrder(5,['payment_type'=>'card','transfer_price_by'=>'admin']);
        $this->batchOrder(6,['payment_type'=>'cash','transfer_price_by'=>'delegate']);
        [$balances,$queries]=$this->measuredBalances();
        $this->assertSame(65189,$balances['f:100']['expected_cents']);
        $this->assertSame(50000,(int)DB::table('takeaway_tills')->value('balance_cents'));
        $this->assertCount(1,$this->batchQueries($queries,'carts'));$this->assertCount(1,$this->batchQueries($queries,'settings'));
        $review=app(BranchShiftClosing::class)->desktopReview(['branch'=>'f:100'],$this->batchActor());
        $this->assertSame('651.89',$review['snapshot']['expected_cash']);
        $this->assertSame(6,$review['snapshot']['channels']['app']['count']);
        $this->assertCount(13,$review['sources']); // Three POS claims, six app claims and four vendor-cash claims.
        $this->assertSame([],DB::table('branch_shift_sources')->get()->all());
    }

    public function test_batch_reloads_the_fee_for_each_new_build_and_keeps_missing_rate_zero(): void
    {
        $this->batchTill();$this->batchOrder(1);$service=app(BranchShiftClosing::class);
        [$first]=$this->measuredBalances(['f:100'],$service);$this->assertSame(61114,$first['f:100']['expected_cents']);
        DB::table('settings')->where('name','service_fees')->update(['payload'=>'20']);
        [$second,$queries]=$this->measuredBalances(['f:100'],$service);$this->assertSame(62114,$second['f:100']['expected_cents']);$this->assertCount(1,$this->batchQueries($queries,'settings'));
        DB::table('settings')->where('name','service_fees')->delete();
        [$third]=$this->measuredBalances(['f:100'],$service);$this->assertSame(60114,$third['f:100']['expected_cents']);
    }

    public function test_batch_keeps_invalid_quantity_before_invalid_rate_and_does_not_skip_card_validation(): void
    {
        $this->batchTill();DB::table('settings')->where('name','service_fees')->update(['payload'=>'101']);
        [$empty,$queries]=$this->measuredBalances();$this->assertSame(50000,$empty['f:100']['expected_cents']);
        $this->assertCount(0,$this->batchQueries($queries,'settings'));$this->assertCount(0,$this->batchQueries($queries,'carts'));
        $this->batchOrder(1,['payment_type'=>'card','transfer_price_by'=>'admin'],[['price'=>'1.00','qty'=>'invalid']]);
        try{$this->measuredBalances();$this->fail('Invalid noncash quantity must still be checked');}
        catch(HttpException $error){$this->assertSame(409,$error->getStatusCode());$this->assertSame('كمية طلب التطبيق غير صالحة.',$error->getMessage());}
        DB::table('carts')->update(['qty'=>'1.000']);
        $this->expectException(\InvalidArgumentException::class);$this->measuredBalances();
    }

    public function test_batch_keeps_claimed_store_cash_in_its_original_financial_branch_and_skips_fully_claimed_amounts(): void
    {
        DB::table('resturants')->insert([['id'=>301,'user_id'=>10,'name'=>'Shubra','parent_id'=>null],['id'=>302,'user_id'=>11,'name'=>'Synthetic store','parent_id'=>301]]);
        $this->batchTill('f:301');$this->batchTill('f:302',99900);$closing=$this->batchClosing('f:302');
        $this->batchOrder(1,['resturant_id'=>302]);$this->batchOrder(2,['resturant_id'=>301]);$this->batchOrder(3,['resturant_id'=>302]);
        foreach([['app',1],['app_cash',1],['app',3]] as [$source,$id])DB::table('branch_shift_sources')->insert(['closing_id'=>$closing,'branch'=>'f:302','source'=>$source,'source_id'=>$id]);
        [$balances,$queries]=$this->measuredBalances(['f:301','f:302']);
        $this->assertSame(72228,$balances['f:301']['expected_cents']);$this->assertSame(99900,$balances['f:302']['expected_cents']);
        $carts=$this->batchQueries($queries,'carts');$this->assertCount(1,$carts);$this->assertEquals([2,3],$carts[0]['bindings']);
        $this->assertCount(1,$this->batchQueries($queries,'settings'));
        $review=app(BranchShiftClosing::class)->desktopReview(['branch'=>'f:301'],$this->batchActor());
        $this->assertSame([['source'=>'app','source_id'=>2],['source'=>'app_cash','source_id'=>2],['source'=>'app_cash','source_id'=>3]],$review['sources']);
        $this->assertSame(3,DB::table('branch_shift_sources')->count());
    }
}
