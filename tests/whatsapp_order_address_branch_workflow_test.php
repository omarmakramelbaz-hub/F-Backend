<?php

// Reuse the existing isolated SQLite workflow harness and all its regression gates.
// New customer/order identifiers below are synthetic. No server, network or live ERP access.
require __DIR__.'/whatsapp_order_workflow_test.php';

use App\Services\Dashboard\WhatsAppOrderAiProvider as AddressAi;
use App\Services\Dashboard\PosServiceTicket as AddressPos;
use Illuminate\Support\Facades\DB as AddressDB;
use Illuminate\Support\Facades\Crypt as AddressCrypt;

$addressChecks=0;
function addressFlowExpect($condition,string $label): void {
    $GLOBALS['addressChecks']++;
    if (!$condition) throw new RuntimeException('ADDRESS_BRANCH_FAILED:'.$label);
}
function addressFlowSetup(): void {
    resetFlow();
    AddressDB::table('test_branches')->delete(); AddressDB::table('resturants')->delete();
    foreach ([94=>'فرع المحلة',301=>'فرع شبرا الخيمة',307=>'فرع نبروه',363=>'فرع المنصورة'] as $id=>$name) {
        $point=[94=>['30.97','31.17'],301=>['30.12','31.24'],307=>['31.13','31.30'],363=>['31.00','31.40']][$id];
        AddressDB::table('test_branches')->insert(['id'=>$id,'name'=>$name,'status'=>'opened']);
        AddressDB::table('resturants')->insert(['id'=>$id,'name'=>$name,'parent_id'=>null,'control'=>'show',
            'lat'=>$point[0],'lng'=>$point[1],'km_price'=>'10.00']);
    }
    AddressDB::table('test_users')->where('id',7)->update(['branches'=>'["f:94","f:301","f:307","f:363"]']);
    app('config')->set('whatsapp_orders.allowed_branch_ids',['94','301','307','363']);
    app('config')->set('whatsapp_orders.mode','auto');
    app('config')->set('whatsapp_cart.branch_aliases',[]);
}
function addressFlowThread(string $address,?string $area,?string $hint=null,bool $pin=true): int {
    $c=addConversation('US.address-fixture');
    $place=$area!==null?' والمنطقة '.$area:'';
    $branch=$hint!==null?' من '.$hint:'';
    insertMessage($c,'address-fixture-request','inbound',
        'اسمي عميل مثال ورقمي 201000000001 وعنواني '.$address.$place.'. عايز 1 كيلو فسيخ'.$branch.'.',1,null,'US.address-fixture');
    if ($pin) insertMessage($c,'address-fixture-pin','inbound','',2,['lat'=>31.00,'long'=>31.40],'US.address-fixture');
    else insertMessage($c,'address-fixture-ack','inbound','تمام',2,null,'US.address-fixture');
    insertMessage($c,'address-fixture-summary','outbound',
        'تأكيد طلب عميل مثال: 1 كيلو فسيخ'.$branch.' إلى '.$address.$place.'، الإجمالي 100.00 جنيه.',3,null,'US.address-fixture');
    AddressAi::$data=extractionData();
    AddressAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>$address,'area'=>$area,'notes'=>null];
    AddressAi::$data['branch_hint']=$hint;
    AddressAi::$data['issues']=$area===null?['MISSING_BRANCH','MISSING_AREA']:['MISSING_BRANCH'];
    AddressAi::$data['evidence']['location_id']=$pin?'m2':null;
    return $c;
}
function addressFlowProcess(string $expected,?string $branch=null): array {
    global $workflow;
    $facts=AddressAi::$data['customer']; $evidence=AddressAi::$data['evidence']; $config=app('config')->all();
    $contents=AddressDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();
    $result=$workflow->process(); $draft=AddressDB::table('whatsapp_order_drafts')->first();
    addressFlowExpect($result['errors']===0,'bounded routing creates no processing error');
    addressFlowExpect(app('config')->all()===$config,'configuration/activation preserved');
    addressFlowExpect(AddressDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$contents,
        'routing preserves original message evidence');
    $stored=json_decode(AddressCrypt::decryptString($draft->extraction),true,32,JSON_THROW_ON_ERROR);
    addressFlowExpect($stored['customer']===$facts&&$stored['evidence']===$evidence,'stored literal facts/citations unchanged');
    if ($expected==='DISPATCHED') {
        addressFlowExpect($result['dispatched']===1&&$draft->status==='DISPATCHED','one confirmed order dispatched');
        addressFlowExpect(AddressDB::table('test_tickets')->count()===1
            &&AddressDB::table('test_tickets')->first()->branch===$branch,'unpaid ticket belongs to matched physical branch');
        addressFlowExpect(AddressDB::table('branch_customers')->first()->branch===$branch
            &&AddressDB::table('test_print')->first()->branch===$branch,'customer and kitchen printing use same selected branch');
        addressFlowExpect(AddressDB::table('test_billing')->count()===0,'routing never bills or collects money');
    } else {
        addressFlowExpect($result['review_required']===1&&$draft->reason===$expected,'existing review reason retained '.$expected);
        addressFlowExpect(AddressDB::table('test_tickets')->count()===0&&AddressDB::table('branch_customers')->count()===0
            &&AddressDB::table('test_print')->count()===0,'review creates no partial customer/ticket/print writes');
    }
    return $result;
}

addressFlowSetup(); addressFlowThread('مدينة مبارك المنصوره شارع المثال','مدينة مبارك المنصوره');
addressFlowProcess('DISPATCHED','f:363');
$workflow->process();
addressFlowExpect(AddressAi::$calls===1&&AddressDB::table('test_tickets')->count()===1,'replay preserves one order and one AI analysis');
addressFlowSetup(); addressFlowThread('شارع الجمهورية بالمنصوره',null);
addressFlowProcess('DISPATCHED','f:363');
addressFlowSetup(); addressFlowThread('شارع المثال في نبروه',null);
addressFlowProcess('DISPATCHED','f:307');
addressFlowSetup(); addressFlowThread('شارع المنصورة',null);
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('ش. المنصورة','شارع المنصورة');
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('المنصورة الجديدة شارع المثال',null);
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('شارع المثال المنصوريه',null);
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('المنصورة، نبروه',null);
app('config')->set('whatsapp_orders.allowed_branch_ids',['307']);
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('شارع المثال المنصوره','المنصوره','فرع شبرا الخيمة');
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('شارع المثال المنصوره','نبروه');
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('شارع المثال المنصوره','المنصوره');
app('config')->set('whatsapp_cart.branch_aliases',['94'=>['المنصورة']]);
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('شارع المثال المنصوره','المنصوره');
app('config')->set('whatsapp_orders.allowed_branch_ids',['94','301','307']);
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('شارع المثال المنصوره','المنصوره');
AddressDB::table('test_users')->where('id',7)->update(['branches'=>'["f:94","f:301","f:307"]']);
addressFlowProcess('BRANCH_UNRESOLVED');
addressFlowSetup(); addressFlowThread('شارع المثال المنصوره','المنصوره');
AddressDB::table('test_branches')->where('id',363)->update(['status'=>'closed']);
addressFlowProcess('BRANCH_CLOSED');
addressFlowSetup(); addressFlowThread('شارع المثال المنصوره','المنصوره',null,false);
addressFlowProcess('LOCATION_UNCONFIRMED');
// A previously analyzed routing hold resumes through the ordinary cached pipeline.
addressFlowSetup(); $c=addressFlowThread('مدينة مبارك المنصوره شارع المثال','مدينة مبارك المنصوره');
$analyzed=$workflow->analyze($c,(object)['id'=>7]);
$draftId=(int)$analyzed['draft']['id']; $cached=AddressDB::table('whatsapp_order_drafts')->where('id',$draftId)->first();
AddressDB::table('whatsapp_order_drafts')->where('id',$draftId)->update(['status'=>'REVIEW','reason'=>'BRANCH_UNRESOLVED']);
$calls=AddressAi::$calls;
addressFlowProcess('DISPATCHED','f:363');
$sent=AddressDB::table('whatsapp_order_drafts')->where('id',$draftId)->first();
addressFlowExpect(AddressAi::$calls===$calls&&$calls===1,'cached branch retry makes no additional provider call');
addressFlowExpect(AddressDB::table('whatsapp_order_drafts')->count()===1&&$sent->status==='DISPATCHED',
    'ordinary pipeline dispatches the same held draft');
addressFlowExpect($sent->extraction===$cached->extraction&&$sent->evidence_floor===$cached->evidence_floor
    &&$sent->evidence_ceiling===$cached->evidence_ceiling&&$sent->evidence_hash===$cached->evidence_hash,
    'cached retry preserves encrypted facts and exact evidence boundaries');
echo 'WHATSAPP_ORDER_ADDRESS_BRANCH_WORKFLOW_TESTS='.$addressChecks." PASS\n";
