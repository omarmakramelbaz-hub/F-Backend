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
// These unrelated synthetic rows reserve colliding IDs, not an additional open
// invoice. Start them terminal so real mapped collections can close their shift.
foreach(array_reverse($savedPhone) as $row){$row['customer_name']='طلب سيرفر مستقل';$row['status']='cancelled';DB::table('pos_service_tickets')->insert($row);}
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
$batchEntities=array_column($serverBatch['references'],'entity');sort($batchEntities);
check($batchEntities===['crm_customer','delivery_batch','receipt.0','receipt.1'],'a collected batch maps its own ID, every payment receipt and its shared CRM identity');
$mapping=collect($serverBatch['references'])->keyBy('local_id');
foreach($localOrders as $localOrder){check(isset($mapping[$localOrder])&&$mapping[$localOrder]['server_id']!==$localOrder,'collected phone receipt uses the mapped server payment ID');}
$serverClose=$receipts['branch-shifts.close']['result']['closing']['id'];
$claimed=DB::table('branch_shift_sources')->where('closing_id',$serverClose)->where('source','pos')->pluck('source_id')->map(fn($id)=>(int)$id)->sort()->values()->all();
$paidIds=collect($serverBatch['result']['batch']['items'])->pluck('order_id')->sort()->values()->all();
check($claimed===$paidIds&&DB::table('phone_delivery_batch_items')->count()===2,'the server shift claims the actual mapped phone receipts once, despite ticket/company/payment ID collisions');
// A later STOP must never turn an already dispatched order into uncollectible debt.
config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.device_id'=>$phoneDevice,'desktop_dashboard.local'=>true]);DB::purge();
$stopPhoneValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),
    'items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00',
    'customer_name'=>'عميل تحصيل بعد الإيقاف','customer_phone'=>'01090000005','address'=>'المنصورة',
    'latitude'=>30.001,'longitude'=>31.001,'location_confirmed'=>true,'service_consent'=>true,
    'delivery_company_id'=>$companyResult['company']['id']];
$stopPhoneValues['delivery_quote_hash']=app(PhoneDelivery::class)->quote($stopPhoneValues,$phoneActor)['delivery']['delivery_quote_hash'];
$stopPhoneValues['quote_hash']=app(PosServiceTicket::class)->quote('phone',$stopPhoneValues,$phoneActor)['quote_hash'];
$stopPhoneSaved=$writePhone('phone-orders.save',$stopPhoneValues,fn()=>app(PosServiceTicket::class)->save('phone',$stopPhoneValues,$phoneActor));
$stopPhoneTicket=$stopPhoneSaved['ticket'];
$stopPhoneHandoff=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),'ticket_id'=>$stopPhoneTicket['id'],
    'company_id'=>$companyResult['company']['id'],'expected_revision'=>$stopPhoneTicket['revision']];
$stopPhoneSent=$writePhone('phone-orders.dispatch-company',$stopPhoneHandoff,fn()=>app(PhoneDeliveryBoard::class)->dispatch($stopPhoneHandoff,$phoneActor));
$stopPhoneBatchValues=['branch'=>'f:100','idempotency_key'=>(string)Str::uuid(),
    'items'=>[['id'=>$stopPhoneTicket['id'],'revision'=>$stopPhoneSent['revision'],'quote_hash'=>$stopPhoneTicket['quote_hash'],
        'service_consent'=>true,'marketing_consent'=>true]],
    'total'=>$stopPhoneTicket['total'],'cash_received'=>'100.00','payment_method'=>'cash','payment_confirmed'=>true];
$writePhone('phone-orders.finish-batch',$stopPhoneBatchValues,fn()=>app(PhoneDeliveryBoard::class)->finish($stopPhoneBatchValues,$phoneActor));
foreach(['phone-orders.save','phone-orders.dispatch-company'] as $expectedRoute){
    $stopPhoneEnvelope=app(DesktopDashboardJournal::class)->pending($phoneDevice)[0];
    check($stopPhoneEnvelope['route_name']===$expectedRoute,'the after-STOP collection fixture first reconciles its original '.$expectedRoute);
    config(['database.connections.mysql.database'=>$phoneServer,'desktop_dashboard.local'=>false]);DB::purge();
    $stopPhoneReceipt=app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$stopPhoneEnvelope);
    config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.local'=>true]);DB::purge();
    app(DesktopDashboardJournal::class)->acknowledge($phoneDevice,$stopPhoneEnvelope['command_id'],$stopPhoneReceipt);
}
$stopPhoneEnvelope=app(DesktopDashboardJournal::class)->pending($phoneDevice)[0];
check($stopPhoneEnvelope['route_name']==='phone-orders.finish-batch','the original batch remains pending after its ticket and dispatch are confirmed');
config(['database.connections.mysql.database'=>$phoneServer,'desktop_dashboard.local'=>false]);DB::purge();
$stopPhoneCustomer=DB::table('crm_customers')->where('phone','+201090000005')->first();
app(\App\Services\Dashboard\CustomerDirectory::class)->consent((int)$stopPhoneCustomer->id,false,false,'whatsapp-inbound:opt-out');
$stopPhoneConsentFields=['service_consent','marketing_consent','service_consented_at','marketing_consented_at',
    'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at'];
$stopPhoneConsentBefore=(array)DB::table('crm_customers')->where('id',$stopPhoneCustomer->id)->first($stopPhoneConsentFields);
$stopPhoneCash=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
$stopPhoneOrderCount=DB::table('takeaway_orders')->count();$stopPhoneBatchCount=DB::table('phone_delivery_batches')->count();
$stopPhoneReceipt=app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$stopPhoneEnvelope);
check($stopPhoneReceipt['committed']&&DB::table('takeaway_orders')->count()===$stopPhoneOrderCount+1
    &&DB::table('phone_delivery_batches')->count()===$stopPhoneBatchCount+1
    &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$stopPhoneCash+(int)round((float)$stopPhoneTicket['total']*100),
    'a dispatched phone batch still collects one original cash receipt after a newer server STOP');
check((array)DB::table('crm_customers')->where('id',$stopPhoneCustomer->id)->first($stopPhoneConsentFields)===$stopPhoneConsentBefore,
    'batch collection preserves the complete newer STOP tuple even when an old client submits positive item consent flags');
check($stopPhoneReceipt===app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$stopPhoneEnvelope)
    &&DB::table('takeaway_orders')->count()===$stopPhoneOrderCount+1
    &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$stopPhoneCash+(int)round((float)$stopPhoneTicket['total']*100),
    'a lost after-STOP collection reply returns its exact batch receipt without collecting twice');
config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.local'=>true]);DB::purge();
app(DesktopDashboardJournal::class)->acknowledge($phoneDevice,$stopPhoneEnvelope['command_id'],$stopPhoneReceipt);
config(['database.connections.mysql.database'=>$remoteDatabase,'desktop_dashboard.device_id'=>$device,'desktop_dashboard.local'=>false]);DB::purge();
