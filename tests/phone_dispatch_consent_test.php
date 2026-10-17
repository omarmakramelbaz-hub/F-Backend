<?php
// Reuse the isolated real CRM/POS integration fixture, then exercise the actual HTTP controller boundary.
require __DIR__.'/customer_directory_checkout_test.php';

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use App\Services\Dashboard\{CustomerDirectory,PhoneDelivery,PosServiceTicket};

class ConsentControllerBase {public function middleware($callback){}}
class_alias(ConsentControllerBase::class,'App\\Http\\Controllers\\Controller');
require_once dirname(__DIR__).'/app/Http/Controllers/Dashboard/PosServiceController.php';
class ConsentPhoneController extends \App\Http\Controllers\Dashboard\PosServiceController {protected string $channel='phone';public function __construct(){}}
class ConsentDiningController extends \App\Http\Controllers\Dashboard\PosServiceController {protected string $channel='dine';public function __construct(){}}
function auth($guard=null){return new class {public function user(){return $GLOBALS['actor'];}};}
function response(){return new class {public function json($value){return (object)['data'=>$value];}};}
require_once dirname(__DIR__).'/database/migrations/2026_10_04_000001_create_pos_branch_print_jobs.php';
(new \CreatePosBranchPrintJobs)->up();
$count=0;$phoneController=new ConsentPhoneController;$dineController=new ConsentDiningController;$writes=[];
DB::listen(function($query)use(&$writes){if(preg_match('/\A\s*(insert|update|delete|replace|create|alter)\b/i',$query->sql))$writes[]=$query->sql;});
function dispatchState():array{
    $tables=['pos_service_tickets','pos_service_commands','pos_service_kitchen_tickets','pos_branch_print_jobs','pos_service_settings',
        'crm_customers','crm_customer_sources','crm_purchases','branch_customers','takeaway_orders','takeaway_till_entries','takeaway_tills','branch_inventory','branch_inventory_movements'];
    $state=[];foreach($tables as $table)if(Schema::hasTable($table))$state[$table]=hash('sha256',json_encode(DB::table($table)->orderBy('id')->get()->all()));return $state;
}
function phonePayload(string $phone,bool $send=true):array{
    global $actor,$tickets;
    $v=['branch'=>'f:100','items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']],
        'customer_name'=>'عميل اختبار موافقة الفرع','customer_phone'=>$phone,'address'=>'عنوان اختبار الفرع','latitude'=>30.001,'longitude'=>31.001,
        'location_confirmed'=>true,'send_to_kitchen'=>$send,'idempotency_key'=>(string)Str::uuid()];
    $v['delivery_quote_hash']=app(PhoneDelivery::class)->quote($v,$actor)['delivery']['delivery_quote_hash'];
    $v['quote_hash']=$tickets->quote('phone',$v,$actor)['quote_hash'];return $v;
}
function httpSave(array $values):array {global $phoneController,$tickets;return $phoneController->save(Request::create('/admin/phone-orders/save','POST',$values),$tickets)->data;}
function httpAction(int $id,array $values):array {global $phoneController,$tickets;return $phoneController->action(Request::create('/admin/phone-orders/tickets/'.$id.'/action','POST',$values),$id,$tickets)->data;}
function blockedConsent(callable $call,string $message):void{
    global $writes;$before=dispatchState();$writes=[];
    try{$call();}catch(\Illuminate\Validation\ValidationException $error){check(isset($error->errors()['service_consent']),$message);check($writes===[]&&dispatchState()===$before,'rejected consent performs no database/customer/print/fiscal write');return;}
    throw new RuntimeException('Expected service-consent validation: '.$message);
}

$unchecked=phonePayload('01234000001');
foreach(['missing'=>null,'false'=>false,'zero'=>0,'string_zero'=>'0','null'=>null] as $label=>$consent){$v=$unchecked;$v['idempotency_key']=(string)Str::uuid();if($label!=='missing')$v['service_consent']=$consent;blockedConsent(fn()=>httpSave($v),'HTTP phone create rejects '.$label.' service consent');}
blockedConsent(fn()=>httpSave($unchecked+['marketing_consent'=>true,'require_service_consent'=>false]),'marketing approval or a user-supplied bypass flag cannot replace service consent');
blockedConsent(fn()=>$tickets->save('phone',$unchecked,$actor,true),'the explicit service boundary flag enforces consent before delivery/customer work');
foreach(['yes','true','1.0',[],new \stdClass] as $invalid){$v=$unchecked;$v['service_consent']=$invalid;blockedConsent(fn()=>httpSave($v),'invalid consent representation is rejected by the actual controller');}
$approvedValues=$unchecked+['service_consent'=>true,'marketing_consent'=>false];$approved=httpSave($approvedValues);$ticket=$approved['ticket'];
check(!$approved['replayed']&&$ticket['payment_status']==='unpaid','explicit service consent creates an unpaid phone order');
$customer=DB::table('crm_customers')->where('phone','+201234000001')->first();
check($customer&&(bool)$customer->service_consent&&!(bool)$customer->marketing_consent,'required follow-up consent never forces marketing consent');
check($customer->service_consent_evidence==='pos-ticket:phone:'.$ticket['id']&&(int)$customer->service_consented_by===(int)$actor->id,'phone dispatch consent records the actual ticket and staff evidence');
check(DB::table('pos_service_kitchen_tickets')->where('ticket_id',$ticket['id'])->count()===1&&DB::table('pos_branch_print_jobs')->where('ticket_id',$ticket['id'])->count()===1,'an approved dispatch creates exactly one kitchen snapshot and branch print job');
$approvedState=dispatchState();$approvedReplay=httpSave($approvedValues);
check($approvedReplay['replayed']&&$approvedReplay['ticket']['id']===$ticket['id']&&dispatchState()===$approvedState,'exact approved HTTP save replay creates no duplicate ticket/customer/print side effects');
$edit=array_replace($approvedValues,['ticket_id'=>$ticket['id'],'expected_revision'=>$ticket['revision'],'idempotency_key'=>(string)Str::uuid(),'send_to_kitchen'=>false]);
unset($edit['service_consent']);blockedConsent(fn()=>httpSave($edit),'HTTP phone update still requires explicit consent even if the customer previously approved');
$edit['service_consent']=false;blockedConsent(fn()=>httpSave($edit),'unchecked phone update cannot bypass the required dispatch checkbox');
$edit['service_consent']=true;$edited=httpSave($edit);
check($edited['ticket']['revision']===$ticket['revision']+1&&DB::table('pos_branch_print_jobs')->where('ticket_id',$ticket['id'])->count()===1,'approved phone update retains its existing unpaid order without an unwanted print');
foreach([1,'1'] as $truth){$v=phonePayload('0123400000'.($truth===1?'2':'3'),false)+['service_consent'=>$truth];check(httpSave($v)['ticket']['payment_status']==='unpaid','validated true boolean representation is accepted');}

// This pre-existing trusted integration command had no service approval. Its exact HTTP retry stays replay-only.
$legacyState=dispatchState();$legacy=httpSave($phoneDraft);
check($legacy['replayed']&&$legacy['ticket']['id']===$phoneDraftResult['ticket']['id']&&dispatchState()===$legacyState,'legacy unchecked exact save replay remains safe without renewing any consent');
$legacyNew=array_replace($phoneDraft,['idempotency_key'=>(string)Str::uuid()]);blockedConsent(fn()=>httpSave($legacyNew),'a new unchecked request cannot reuse legacy dispatch behavior');
$internal=phonePayload('01234000004',false);$trusted=$tickets->save('phone',$internal,$actor);
check(!$trusted['replayed']&&$trusted['ticket']['payment_status']==='unpaid','trusted internal phone integrations retain their pre-existing service signature and behavior');
$send=['branch'=>'f:100','expected_revision'=>$trusted['ticket']['revision'],'idempotency_key'=>(string)Str::uuid(),'action'=>'send_kitchen'];
blockedConsent(fn()=>httpAction($trusted['ticket']['id'],$send),'the separate HTTP send_kitchen action cannot bypass missing consent');
blockedConsent(fn()=>httpAction($trusted['ticket']['id'],$send+['service_consent'=>false,'marketing_consent'=>true]),'marketing consent cannot bypass the separate branch-send action');
$sent=httpAction($trusted['ticket']['id'],$send+['service_consent'=>true,'marketing_consent'=>false]);
$sentCustomer=DB::table('crm_customers')->where('phone','+201234000004')->first();
check($sent['ticket']['kitchen_sent']&&(bool)$sentCustomer->service_consent&&!(bool)$sentCustomer->marketing_consent,'approved alternate send action records service-only consent and queues the branch');
$sentState=dispatchState();check(httpAction($trusted['ticket']['id'],$send+['service_consent'=>true,'marketing_consent'=>false])['replayed']&&dispatchState()===$sentState,'alternate send action exact replay cannot duplicate prints or grant writes');
$directory->consent((int)$sentCustomer->id,false,false,'whatsapp-inbound:opt-out',(int)$actor->id);$stoppedState=dispatchState();httpAction($trusted['ticket']['id'],$send+['service_consent'=>true,'marketing_consent'=>false]);
check(dispatchState()===$stoppedState&&!(bool)DB::table('crm_customers')->where('id',$sentCustomer->id)->value('service_consent'),'old exact dispatch replay cannot revive later customer opt-out');
$legacyActionValues=phonePayload('01234000005',false);$legacyActionTicket=$tickets->save('phone',$legacyActionValues,$actor)['ticket'];
$legacyAction=['branch'=>'f:100','expected_revision'=>$legacyActionTicket['revision'],'idempotency_key'=>(string)Str::uuid(),'action'=>'send_kitchen'];
$tickets->action('phone',$legacyActionTicket['id'],$legacyAction,$actor);$legacyActionState=dispatchState();
check(httpAction($legacyActionTicket['id'],$legacyAction)['replayed']&&dispatchState()===$legacyActionState,'legacy exact send_kitchen replay remains a read-only result after the new HTTP requirement');

DB::table('pos_service_tables')->insert(['id'=>3,'branch'=>'f:100','name'=>'Consent regression table','capacity'=>4,'active'=>true,'revision'=>1]);
$dine=['branch'=>'f:100','table_id'=>3,'waiter_name'=>'كاشير اختبار','guest_count'=>1,'items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']],'idempotency_key'=>(string)Str::uuid()];
$dine['quote_hash']=$tickets->quote('dine',$dine,$actor)['quote_hash'];$dineSaved=$dineController->save(Request::create('/admin/dining/save','POST',$dine),$tickets)->data;
check($dineSaved['ticket']['payment_status']==='unpaid','HTTP dining drafts remain valid without phone dispatch consent');
$dineSent=$dineController->action(Request::create('/admin/dining/action','POST',['branch'=>'f:100','expected_revision'=>$dineSaved['ticket']['revision'],'idempotency_key'=>(string)Str::uuid(),'action'=>'send_kitchen']),$dineSaved['ticket']['id'],$tickets)->data;
check($dineSent['ticket']['kitchen_sent'],'dining kitchen actions retain their existing optional consent semantics');
echo $count.' dashboard phone dispatch consent checks passed'.PHP_EOL;
