<?php
// Real in-memory SQLite services. No production bootstrap, network, or shared cache.
ob_start();
require __DIR__.'/customer_directory_checkout_test.php';
ob_end_clean();

use App\Services\Dashboard\PosServiceTicket;
use Illuminate\Support\Facades\DB;

$count=0;
$queries=[];
DB::connection()->setEventDispatcher(new \Illuminate\Events\Dispatcher(app()));
DB::listen(function($query)use(&$queries){$queries[]=$query->sql;});
DB::table('users')->insert(['id'=>1,'name'=>'Batch administrator','account_type'=>'admin']);
$viewer=TestUser::find(1);
$template=DB::table('pos_service_tickets')->first();
$customers=[];
for($i=0;$i<6;$i++){
    $customers[]=DB::table('branch_customers')->insertGetId(['branch'=>$i===5?'f:101':'f:100',
        'phone_key'=>'0129000000'.$i,'phone'=>'0129000000'.$i,'name'=>'Batch customer '.$i,
        'address'=>'Fixture address','revision'=>$i+2,'actor_id'=>10]);
}
$rows=[];
for($i=0;$i<20;$i++){
    $row=clone $template;
    $row->id=10000+$i;
    $row->actor_id=$i===19?999999:($i%2?10:11);
    $row->channel=$i%3?'phone':'dine';
    $row->branch='f:100';
    $row->payment_status=$i%2?'unpaid':'paid';
    $row->paid_order_id=$row->payment_status==='paid'?50000+$i:null;
    $row->customer_id=$i===0?null:($i===19?999999:$customers[$i%6]);
    $rows[]=$row;
}

$service=app(PosServiceTicket::class);
$queries=[];
$baseline=array_map(fn($row)=>$service->present($row,false,$viewer),$rows);
$before=count($queries);
$queries=[];
$batched=$service->presentMany($rows,false,$viewer);
$after=count($queries);
check($baseline===$batched,'batched cards preserve the exact existing payload, order and value types');
check($before===49&&$after===3,'twenty-card metadata reads fall from 49 queries to 3');
check($batched[0]['customer_revision']===null&&$batched[5]['customer_revision']===0,
    'missing links and foreign-branch customer revisions retain existing semantics');
check($batched[19]['cashier']['name']===''&&$batched[19]['customer_revision']===0,
    'deleted cashiers and customers retain the existing empty-name and zero-revision behavior');
check(count(array_filter($queries,fn($sql)=>preg_match('/\A\s*(insert|update|delete|replace|create|alter)\b/i',$sql)))===0,
    'presentation performs no financial, customer, stock or order writes');
check($service->presentMany($rows,true,$actor)===array_map(fn($row)=>$service->present($row,true,$actor),$rows),
    'full item/cart payloads and branch-cashier reprint restrictions stay identical');
$queries=[];
check($service->presentMany([],false,$viewer)===[]&&$queries===[],'an empty page performs no metadata queries');

DB::table('users')->where('id',10)->update(['name'=>'Updated cashier']);
DB::table('branch_customers')->where('id',$customers[1])->update(['revision'=>77]);
$fresh=$service->presentMany($rows,false,$viewer);
check($fresh[1]['cashier']['name']==='Updated cashier'&&$fresh[1]['customer_revision']===77,
    'the next poll reads current names and customer revisions through the same service instance');
DB::table('users')->where('id',1)->update(['account_type'=>'vendor']);
$restricted=$service->presentMany($rows,false,$viewer);
check(!$restricted[0]['can_reprint']&&$restricted[0]['receipt_url']===null,
    'the next poll rechecks persisted viewer permissions rather than caching a prior grant');

echo json_encode(['cards'=>count($rows),'baseline_queries'=>$before,'batched_queries'=>$after,
    'reduction_percent'=>round(100*(1-$after/$before),2),'checks'=>$count],JSON_UNESCAPED_SLASHES).PHP_EOL;
