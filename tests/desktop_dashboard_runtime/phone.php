<?php
// Original company/ticket/handoff/batch services on two real disposable databases.
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardDevices,DesktopDashboardReconciliation,BranchShiftClosing,PhoneDelivery,PosServiceTicket,PhoneDeliveryBoard,DeliveryCompanies};

$phoneServer=$remoteDatabase;
config(['database.connections.mysql.database'=>$phoneServer,'desktop_dashboard.local'=>false]);DB::purge();
require $application.'/database/migrations/2026_10_06_130000_create_phone_delivery_batches.php';(new CreatePhoneDeliveryBatches)->up();
Schema::table('resturants',function($table){$table->decimal('lat',10,7)->nullable();$table->decimal('lng',10,7)->nullable();$table->decimal('km_price',14,2)->nullable();});
DB::table('resturants')->where('id',100)->update(['lat'=>30,'lng'=>31,'km_price'=>'0.00']);
$phoneActor=User::withoutGlobalScopes()->findOrFail(1);$phoneDevice=(string)Str::uuid();
$phoneLink=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$phoneDevice,'name'=>'phone reconciliation fixture','nonce'=>bin2hex(random_bytes(32))],$phoneActor);
$phoneRemote=app(DesktopDashboardDevices::class)->device($phoneLink['token']);
$phoneSnapshot=app(\App\Services\Dashboard\DesktopDashboardBootstrap::class)->export($phoneRemote);
$phoneLocal='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$pdo->exec('CREATE DATABASE `'.$phoneLocal.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$phoneLocal.'`'));
config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$phoneDevice]);DB::purge();
app(\App\Services\Dashboard\DesktopDashboardImport::class)->import($phoneSnapshot);
$phoneActor=User::withoutGlobalScopes()->findOrFail(1);
$writePhone=function($route,$values,$work)use($phoneDevice,$phoneActor){return app(DesktopDashboardJournal::class)->execute($phoneDevice,$values['idempotency_key'],1,$route,['values'=>$values],[],$work);};
$companyValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'name'=>'شركة اختبار محلية','phone'=>'01098765432','active'=>true];
$companyResult=$writePhone('delivery-companies.save',$companyValues,fn()=>app(DeliveryCompanies::class)->save($companyValues,$phoneActor));
$tickets=[];$savedPhone=[];
foreach(['0.250','0.500'] as $quantity){
    $values=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>$quantity]],
        'discount'=>'0.00','customer_name'=>'عميل الهاتف','customer_phone'=>'01012345678','address'=>'المنصورة','latitude'=>30.001,'longitude'=>31.001,'location_confirmed'=>true,
        'delivery_company_id'=>$companyResult['company']['id']];
    $values['delivery_quote_hash']=app(PhoneDelivery::class)->quote($values,$phoneActor)['delivery']['delivery_quote_hash'];
    $values['quote_hash']=app(PosServiceTicket::class)->quote('phone',$values,$phoneActor)['quote_hash'];
    $result=$writePhone('phone-orders.save',$values,fn()=>app(PosServiceTicket::class)->save('phone',$values,$phoneActor));
    $ticket=$result['ticket'];$savedPhone[]=(array)DB::table('pos_service_tickets')->where('id',$ticket['id'])->first();
    $handoff=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'ticket_id'=>$ticket['id'],'company_id'=>$companyResult['company']['id'],'expected_revision'=>$ticket['revision']];
    $sent=$writePhone('phone-orders.dispatch-company',$handoff,fn()=>app(PhoneDeliveryBoard::class)->dispatch($handoff,$phoneActor));
    $tickets[]=['id'=>$ticket['id'],'revision'=>$sent['revision'],'quote_hash'=>$ticket['quote_hash']];
}
$batchValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'items'=>array_reverse($tickets),'total'=>'85.50','cash_received'=>'100.00','payment_method'=>'cash','payment_confirmed'=>true,'courier_name'=>'مندوب الاختبار'];
$batch=$writePhone('phone-orders.finish-batch',$batchValues,fn()=>app(PhoneDeliveryBoard::class)->finish($batchValues,$phoneActor));
check(count($batch['batch']['items'])===2&&DB::table('phone_delivery_batch_items')->count()===2,'original local phone batch settles each handoff once');
$localOrders=array_column($batch['batch']['items'],'order_id','ticket_id');
$batchRow=DB::table('desktop_dashboard_commands')->where('command_id',$batchValues['idempotency_key'])->first();
$batchPayload=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($batchRow->command_cipher),true);
check(isset($batchPayload['values']['items'][0]['id']['$desktop_ref'])&&count(json_decode($batchRow->dependencies,true))===2,'batch collection retains typed dependencies for every locally created ticket');
$review=app(BranchShiftClosing::class)->data(['branch'=>'f:100'],$phoneActor);
$closeValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'previous_closing_id'=>$review['previous_closing_id'],'review_token'=>$review['review_token'],'counted_cash'=>'85.50','notes'=>'تقفيل دفعة هاتف'];
$closeFacts=app(BranchShiftClosing::class)->desktopReview($closeValues,$phoneActor);
app(DesktopDashboardJournal::class)->execute($phoneDevice,$closeValues['idempotency_key'],1,'branch-shifts.close',['values'=>$closeValues,'facts'=>['shift'=>$closeFacts]],[],fn()=>app(BranchShiftClosing::class)->close($closeValues,$phoneActor));
config(['database.connections.mysql.database'=>$phoneServer,'desktop_dashboard.local'=>false]);DB::purge();
// Reserve both ticket IDs in reverse creation order and the local company ID on the server.
$collision=DB::table('branch_delivery_companies')->insertGetId(['branch'=>'f:100','actor_id'=>1,'name'=>'شركة سيرفر مستقلة','phone'=>'01000000000','active'=>true,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
foreach(array_reverse($savedPhone) as $row){$row['customer_name']='طلب سيرفر مستقل';DB::table('pos_service_tickets')->insert($row);}
foreach($localOrders as $id){
    $row=(array)DB::table('takeaway_orders')->first();$row['id']=$id;$row['branch']='f:101';$row['request_key']=(string)Str::uuid();
    DB::table('takeaway_orders')->insert($row);
}
DB::table('phone_delivery_batches')->insert(['id'=>$batch['batch']['id'],'branch'=>'f:101','actor_id'=>1,'request_key'=>(string)Str::uuid(),'total_cents'=>0,'snapshot'=>'{}','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$receipts=[];
for($iteration=0;$iteration<7;$iteration++){
    config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.local'=>true]);DB::purge();
    $envelope=app(DesktopDashboardJournal::class)->pending($phoneDevice)[0];
    config(['database.connections.mysql.database'=>$phoneServer,'desktop_dashboard.local'=>false]);DB::purge();
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$envelope);
    check($receipt===app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$envelope),'original phone reconciliation retains exact-once receipt: '.$envelope['route_name']);
    $receipts[$envelope['route_name']]=$receipt;
    config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($phoneDevice,$envelope['command_id'],$receipt);
}
config(['database.connections.mysql.database'=>$phoneServer,'desktop_dashboard.local'=>false]);DB::purge();
$serverBatch=$receipts['phone-orders.finish-batch'];
check(count($serverBatch['references'])===3&&isset($serverBatch['references'][1]['entity']),'a collected batch maps its own ID and every created payment receipt');
$mapping=collect($serverBatch['references'])->keyBy('local_id');
foreach($localOrders as $localOrder){check(isset($mapping[$localOrder])&&$mapping[$localOrder]['server_id']!==$localOrder,'collected phone receipt uses the mapped server payment ID');}
$serverClose=$receipts['branch-shifts.close']['result']['closing']['id'];
$claimed=DB::table('branch_shift_sources')->where('closing_id',$serverClose)->where('source','pos')->pluck('source_id')->map(fn($id)=>(int)$id)->sort()->values()->all();
$paidIds=collect($serverBatch['result']['batch']['items'])->pluck('order_id')->sort()->values()->all();
check($claimed===$paidIds&&DB::table('phone_delivery_batch_items')->count()===2,'the server shift claims the actual mapped phone receipts once, despite ticket/company/payment ID collisions');
config(['database.connections.mysql.database'=>$remoteDatabase,'desktop_dashboard.device_id'=>$device,'desktop_dashboard.local'=>false]);DB::purge();
