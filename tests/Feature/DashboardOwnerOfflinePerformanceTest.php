<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\{BranchShiftClosing,FasakhanstaStoreFulfillment,HomeOverview,TakeawayAccess};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

require_once __DIR__.'/DashboardHomeOverviewTest.php';

/** Disposable offline Owner measurement. No production bootstrap, data or network. */
class DashboardOwnerOfflinePerformanceTest extends DashboardHomeOverviewTest
{
    private array $measurements=[];
    private ?string $measuring=null;
    private array $shapes=[];
    private const DEVICE='00000000-0000-4000-8000-000000000058';
    private const BRANCHES=32;
    private const CUSTOMERS=11145;

    private function fixtureKey(int $id): string
    {
        return sprintf('00000000-0000-4000-8000-%012d',$id);
    }

    private function retainedOwner(): array
    {
        $this->assertSame('sqlite',DB::connection()->getDriverName());
        $this->assertSame(':memory:',config('database.connections.sqlite.database'));
        config(['desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>self::DEVICE]);
        for($id=103;$id<132;$id++)DB::table('resturants')->insert([
            'id'=>$id,'user_id'=>11,'name'=>'Synthetic branch '.$id,'parent_id'=>null]);
        $branches=array_map(fn($id)=>'f:'.$id,range(100,131));
        Schema::create('desktop_dashboard_local_state',function(Blueprint $t){
            $t->uuid('device_id')->primary();$t->unsignedBigInteger('actor_id');$t->text('coverage');
        });
        Schema::create('desktop_dashboard_devices',function(Blueprint $t){
            $t->uuid('id')->primary();$t->boolean('enabled');$t->longText('branches');
        });
        DB::table('desktop_dashboard_local_state')->insert(['device_id'=>self::DEVICE,'actor_id'=>1,
            'coverage'=>json_encode(['catalog_scopes'=>1,'print_protocol'=>1])]);
        DB::table('desktop_dashboard_devices')->insert(['id'=>self::DEVICE,'enabled'=>true,'branches'=>json_encode($branches)]);
        Schema::create('payments',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('order_id');$t->string('status');$t->string('transaction_id')->nullable();
        });
        DB::table('users')->where('account_type','user')->delete();
        foreach(array_chunk(range(1000,1000+self::CUSTOMERS-1),100) as $ids){
            DB::table('users')->insert(array_map(fn($id)=>['id'=>$id,'name'=>'Synthetic customer '.$id,
                'account_type'=>'user','app_scope'=>'fasakhansta','status'=>'accepted','created_at'=>'2026-10-01 08:00:00'], $ids));
        }
        $posId=0;$appId=0;
        foreach($branches as $index=>$value){
            $branchId=100+$index;
            $tillId=DB::table('takeaway_tills')->insertGetId(['branch'=>$value,'balance_cents'=>50000+$index*100,'tax_bps'=>0,'revision'=>1]);
            $closing=null;
            if($index%4!==0){
                $snapshot=$index%4===1?['till_reset'=>['after_cents'=>7000]]:[];
                $closing=DB::table('branch_shift_closings')->insertGetId(['branch'=>$value,'sequence'=>1,'actor_id'=>1,
                    'request_key'=>$this->fixtureKey(700000+$index),'request_hash'=>str_repeat('c',64),
                    'activated_at'=>'2026-10-01 03:00:00','started_at'=>'2026-10-01 03:00:00','closed_at'=>'2026-10-04 03:00:00',
                    'counted_cents'=>10000,'expected_cents'=>10000,'variance_cents'=>0,'till_balance_cents'=>20000,
                    'snapshot'=>json_encode($snapshot),'notes'=>'Synthetic retained closing']);
            }
            for($n=0;$n<128;$n++){
                $id=++$posId;$day=$n<96?'2026-10-02':'2026-10-05';$method=['cash','card','mixed'][$n%3];
                DB::table('takeaway_orders')->insert(['id'=>$id,'branch'=>$value,'till_id'=>$tillId,'actor_id'=>10,
                    'request_key'=>$this->fixtureKey($id),'request_hash'=>str_repeat('a',64),'quote_hash'=>str_repeat('b',64),
                    'business_date'=>$day,'payment_method'=>$method,'subtotal_cents'=>10000,'discount_cents'=>0,'tax_cents'=>0,
                    'total_cents'=>12000,'cash_received_cents'=>12000,'change_cents'=>0,'tax_bps'=>0,'branch_snapshot'=>'{}',
                    'cashier_snapshot'=>'{}','channel'=>$n%2?'phone':'takeaway','delivery_cents'=>2000,
                    'tenders_snapshot'=>$method==='mixed'?json_encode([['method'=>'cash','amount_cents'=>6000],['method'=>'card','amount_cents'=>6000]]):null,
                    'created_at'=>$day.' 08:00:00','updated_at'=>$day.' 08:00:00']);
                if($closing&&$n<8)DB::table('branch_shift_sources')->insert(['closing_id'=>$closing,'branch'=>$value,'source'=>'pos','source_id'=>$id]);
            }
            for($n=0;$n<64;$n++){
                $id=++$appId;$day=$n<48?'2026-10-02':'2026-10-05';$vendor=$n%2===0;
                DB::table('orders')->insert(['id'=>$id,'resturant_id'=>$branchId,'user_id'=>1000,'order_no'=>'SYNTHETIC-'.$id,
                    'status'=>$n>=56?'pending':'completed','type'=>'current','payment_type'=>$vendor?'cash':'card',
                    'transfer_price_by'=>$vendor?'vendor':null,'total_price'=>'100.00','delivery_price'=>'20.00','user_tax'=>'5.00',
                    'created_at'=>$day.' 08:00:00','updated_at'=>$day.' 08:00:00']);
                DB::table('carts')->insert(['order_id'=>$id,'price'=>'100.00','qty'=>'1.000']);
                if($closing&&$n<8)DB::table('branch_shift_sources')->insert(['closing_id'=>$closing,'branch'=>$value,
                    'source'=>$n%4===0?'app_cash':'app','source_id'=>$id]);
                DB::table('pos_service_tickets')->insert(['branch'=>$value,'channel'=>$n%2?'phone':'dine','actor_id'=>10,
                    'status'=>$n<32?'open':'paid','payment_status'=>$n<32?'unpaid':'paid','table_snapshot'=>'{"name":"Synthetic"}',
                    'cart_snapshot'=>'[]','quote_snapshot'=>'{}','business_date'=>$day,'created_at'=>$day.' 08:00:00','updated_at'=>$day.' 08:00:00']);
                DB::table('branch_expenses')->insert(['branch'=>$value,'actor_id'=>10,'occurred_on'=>$day,'category'=>'purchases',
                    'description'=>'Synthetic expense','amount_cents'=>3000,'payment_method'=>'cash','status'=>$n<32?'pending':'approved']);
            }
        }
        return $branches;
    }

    private function beginMeasurements(): void
    {
        DB::listen(function(QueryExecuted $event){
            if($this->measuring===null)return;
            $name=$this->measuring;$sql=strtolower(preg_replace('/\s+/',' ',trim($event->sql)));
            $measurement=&$this->measurements[$name];$measurement['queries']++;$measurement['database_ms']+=(float)$event->time;
            if(preg_match('/sqlite_master|pragma\s+(?:table_info|table_xinfo|index_info|index_list)|information_schema|show\s+(?:full\s+)?columns/',$sql))$measurement['schema_queries']++;
            foreach(['users'=>'users','payments'=>'payments','takeaway_orders'=>'pos','orders'=>'app','branch_expenses'=>'expenses','pos_service_tickets'=>'tickets'] as $table=>$category)
                if(preg_match('/\b(?:from|join)\s+["`]?'.preg_quote($table,'/').'["`]?\b/',$sql))$measurement[$category.'_queries']++;
            if(preg_match('/^(?:insert|update|delete|replace|create|alter|drop|truncate)\b/',$sql))$measurement['writes']++;
            $shape=substr(hash('sha256',$sql),0,16);$this->shapes[$name][$shape]=($this->shapes[$name][$shape]??0)+1;
        });
    }

    private function measure(string $name,callable $work)
    {
        $this->measurements[$name]=['queries'=>0,'schema_queries'=>0,'users_queries'=>0,'payments_queries'=>0,
            'pos_queries'=>0,'app_queries'=>0,'expenses_queries'=>0,'tickets_queries'=>0,'writes'=>0,'database_ms'=>0];
        $this->measuring=$name;$at=hrtime(true);
        try{return $work();}
        finally{
            $this->measurements[$name]['wall_ms']=round((hrtime(true)-$at)/1000000,3);
            $this->measurements[$name]['database_ms']=round($this->measurements[$name]['database_ms'],3);
            arsort($this->shapes[$name]);$this->measurements[$name]['top_query_shapes']=array_slice($this->shapes[$name],0,8,true);
            $this->measuring=null;
        }
    }

    /** Diagnostic comparison only. Reuses original scope, app-money and claim rules. */
    private function cashFactors(array $branches,User $actor): array
    {
        $access=app(TakeawayAccess::class);$actor=$access->actor($actor);
        $this->assertSame(1,(int)$actor->id);$this->assertSame('admin',$actor->account_type);
        $service=app(BranchShiftClosing::class);
        $unclaimed=new \ReflectionMethod($service,'unclaimed');$unclaimed->setAccessible(true);
        $appAmount=new \ReflectionMethod($service,'appAmount');$appAmount->setAccessible(true);
        $out=[];
        foreach($branches as $value){
            $branch=$access->branch($value,$actor);
            $last=DB::table('branch_shift_closings')->where('branch',$value)->orderByDesc('sequence')->first();
            $activated=$last?$last->activated_at:\App\Services\Dashboard\OperatingDay::start()->utc()->toDateTimeString();
            $till=DB::table('takeaway_tills')->where('branch',$value)->first();$balance=(int)($till->balance_cents??0);
            $snapshot=$last?json_decode($last->snapshot,true):[];
            $anchor=$last?(int)($snapshot['till_reset']['after_cents']??$last->till_balance_cents):0;
            $opening=$last?(isset($snapshot['till_reset'])?(int)$snapshot['till_reset']['after_cents']:(int)$last->expected_cents)
                :$balance-(int)DB::table('takeaway_till_entries')->where('branch',$value)->where('created_at','>=',$activated)->sum('amount_cents');
            $movement=$last?$balance-$anchor:$balance-$opening;
            $pos=DB::table('takeaway_orders')->where('branch',$value)->where('created_at','>=',$activated)->where('payment_method','cash');
            $cashDelivery=(int)$unclaimed->invoke($service,$pos,$value,'pos','takeaway_orders.id')->sum('delivery_cents');
            $app=DB::table('orders')->whereIn('resturant_id',FasakhanstaStoreFulfillment::sourceRestaurantIdsForBranch((int)$branch['id']))
                ->where('type','current')->where('status','completed')->where('updated_at','>=',$activated)
                ->where('payment_type','cash')->where('transfer_price_by','vendor');
            $rows=$unclaimed->invoke($service,$app,$value,'app_cash','orders.id')->orderBy('id')->get();$appCash=0;
            foreach($rows as $row){[$total,$delivery]=$appAmount->invoke($service,$row);$appCash+=$total-$delivery;}
            $out[$value]=['expected_cents'=>$opening+$movement+$appCash-$cashDelivery];
        }
        return $out;
    }

    public function test_measure_actual_retained_offline_owner_without_changing_business_data(): void
    {
        $this->assertSame('sqlite',env('TAKEAWAY_TEST_CONNECTION','sqlite'));
        $this->assertSame('8.83.29',$this->app->version());
        $branches=$this->retainedOwner();$owner=User::withoutGlobalScopes()->findOrFail(1);
        $counts=[];foreach(['users','resturants','takeaway_orders','orders','branch_expenses','pos_service_tickets','branch_shift_sources','branch_shift_closings'] as $table)$counts[$table]=DB::table($table)->count();
        $this->assertCount(self::BRANCHES,$branches);$this->assertSame(self::CUSTOMERS,DB::table('users')->where('account_type','user')->count());
        $this->beginMeasurements();
        $first=$this->measure('home_overview_first',fn()=>app(HomeOverview::class)->data([],$owner));
        $second=$this->measure('home_overview_repeat',fn()=>app(HomeOverview::class)->data([],$owner));
        $balances=$this->measure('owner_balances_full_report',fn()=>app(BranchShiftClosing::class)->ownerBalances($branches,$owner));
        $factors=$this->measure('owner_balances_cash_factors_only',fn()=>$this->cashFactors($branches,$owner));
        $branchRows=$this->measure('branch_authorization_facts',function()use($branches,$owner){
            $access=app(TakeawayAccess::class);return array_map(fn($value)=>$access->branch($value,$owner),$branches);
        });
        $service=app(BranchShiftClosing::class);
        foreach(['pendingExpenses','pendingInvoices'] as $method){
            $originalMethod=new \ReflectionMethod($service,$method);$originalMethod->setAccessible(true);
            $this->measure('unused_report_'.$method,function()use($branchRows,$service,$originalMethod){
                $total=0;foreach($branchRows as $branch)$total+=$originalMethod->invoke($service,$branch)['total'];return $total;
            });
        }
        $original=array_map(fn($row)=>['expected_cents'=>$row['expected_cents']],$balances);
        $this->assertSame($original,$factors);
        $this->assertSame($first['owner_drawer'],$second['owner_drawer']);
        $this->assertSame(self::BRANCHES,$first['selected_branches']);$this->assertCount(self::BRANCHES,$balances);
        $this->assertSame(array_sum(array_column($balances,'expected_cents')),$first['owner_drawer']['total_cents']);
        foreach($this->measurements as $measurement){$this->assertGreaterThan(0,$measurement['queries']);$this->assertSame(0,$measurement['writes']);}
        foreach($counts as $table=>$count)$this->assertSame($count,DB::table($table)->count(),$table.' remains unchanged');
        $sources=[];foreach([HomeOverview::class,BranchShiftClosing::class,TakeawayAccess::class] as $class)$sources[$class]=hash_file('sha256',(new \ReflectionClass($class))->getFileName());
        $report=['format'=>1,'kind'=>'go-erp-offline-owner-performance','framework'=>$this->app->version(),'driver'=>'sqlite',
            'private_database'=>true,'offline'=>true,'synthetic_data'=>true,'production_data_read'=>false,'business_writes_during_measurement'=>false,
            'native_startup_measured'=>false,'http_queue_measured'=>false,'hardware'=>false,'peak_process_mib'=>round(memory_get_peak_usage(true)/1048576,3),
            'branches'=>self::BRANCHES,'customers'=>self::CUSTOMERS,'fixture_counts'=>$counts,'original_balances_match_cash_factors'=>true,
            'source_hashes'=>$sources,'measurements'=>$this->measurements];
        $json=json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $this->assertLessThan(16384,strlen($json));
        $target=getenv('GO_ERP_OWNER_PERFORMANCE_REPORT');
        if(is_string($target)&&$target!==''){
            $this->assertFileDoesNotExist($target);$this->assertSame(strlen($json."\n"),file_put_contents($target,$json."\n",LOCK_EX));
        }
        fwrite(STDOUT,'GO_ERP_OWNER_PERFORMANCE '. $json.PHP_EOL);
    }
}
