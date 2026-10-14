<?php
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess { const WABA_ID='468336579702269'; const PHONE_ID='515388018324075'; }
    class MetaInboxAccess {
        public static array $accounts = [['channel'=>'messenger','account_id'=>'123'],['channel'=>'instagram','account_id'=>'456']];
        public static bool $owner = true;
        public static function accounts():array { return self::$accounts; }
        public function owner($actor) { if (!self::$owner) throw new \RuntimeException('DENIED'); return $actor; }
    }
}
namespace {
    use App\Services\Dashboard\MetaBusinessAgent;
    use App\Services\Dashboard\MetaInboxAccess;
    function app($name) { return new $name; }
    function config($name,$default=null) { return $default; }
    require __DIR__.'/../app/Services/Dashboard/MetaBusinessAgent.php';
    $checks=0;
    function check($value,string $label):void { global $checks; $checks++; if (!$value) throw new RuntimeException($label); }
    function rejects(callable $fn,string $label):void { try { $fn(); } catch (Throwable $e) { check(true,$label); return; } check(false,$label); }
    $owner=new class { public function getKey(){ return 1; } };
    $config=['access_token'=>'fixture-only-access-token'];
    $calls=[]; $responses=[];
    $transport=function($method,$url,$payload,$token,$version) use (&$calls,&$responses) {
        $calls[]=[$method,$url,$payload,$token,$version];
        return array_shift($responses)??['status'=>0,'body'=>''];
    };
    $service=new MetaBusinessAgent($transport,$config);
    $receipt=$service->prepare($owner,['instagram','whatsapp','messenger'],true);
    check($calls===[],'attestation sends no API request');
    check($receipt['channels']===['whatsapp','messenger','instagram'],'canonical channels');
    check($receipt['method']==='OWNER_ATTESTED','attestation remains explicit');
    check(MetaBusinessAgent::validReceipt($receipt,['whatsapp','messenger','instagram']),'fresh receipt valid');
    check(!MetaBusinessAgent::validReceipt(null,['whatsapp']),'null receipt denied');
    check(!MetaBusinessAgent::validReceipt($receipt,['whatsapp']),'scope reduction needs own receipt');
    foreach (['version'=>2,'account_fingerprint'=>str_repeat('0',64),'channels'=>['whatsapp'],'method'=>'VERIFIED_WHATSAPP_API',
        'actor_id'=>0,'prepared_at_utc'=>'2099-01-01 00:00:00','extra'=>true] as $key=>$value) {
        $bad=$receipt; $bad[$key]=$value;
        check(!MetaBusinessAgent::validReceipt($bad,['whatsapp','messenger','instagram']),'bad receipt '.$key);
    }
    $accounts=MetaInboxAccess::$accounts; MetaInboxAccess::$accounts[0]['account_id']='987';
    check(!MetaBusinessAgent::validReceipt($receipt,['whatsapp','messenger','instagram']),'account switch invalidates handoff');
    MetaInboxAccess::$accounts=$accounts;
    foreach ([[],['whatsapp','whatsapp'],['telegram'],[1],['x'=>'whatsapp']] as $bad)
        rejects(fn()=>MetaBusinessAgent::fingerprint($bad),'invalid channels');
    $responses=[['status'=>200,'body'=>'{"rollout":{"enabled":false}}']];
    $receipt=$service->prepare($owner,['whatsapp'],false);
    check(count($calls)===1&&$calls[0][0]==='GET','already paused read-only');
    check($receipt['method']==='VERIFIED_WHATSAPP_API'&&MetaBusinessAgent::validReceipt($receipt,['whatsapp']),'paused verified');
    $calls=[];
    $responses=[['status'=>200,'body'=>'{"data":{"rollout":{"enabled":true}}}'],
        ['status'=>200,'body'=>'{"success":true}'],['status'=>200,'body'=>'{"rollout":{"enabled":false}}']];
    $service->prepare($owner,['whatsapp'],false);
    check(array_column($calls,0)===['GET','PUT','GET'],'disable is bounded verified sequence');
    check($calls[1][2]===['rollout'=>['enabled'=>false]],'partial update only disables rollout');
    foreach ($calls as $call) {
        check($call[1]==='https://api.facebook.com/515388018324075/agent_config/settings','fixed approved phone endpoint');
        check($call[3]===$config['access_token']&&$call[4]==='2.0.0','token and API version');
    }
    foreach ([['status'=>401,'body'=>'{"error":{"code":190}}'],['status'=>200,'body'=>'{}'],
        ['status'=>200,'body'=>'{"rollout":{"enabled":"false"}}'],['status'=>200,'body'=>'{"rollout":{"enabled":false},"data":{}}'],
        ['status'=>200,'body'=>'{"data":[{"rollout":{"enabled":false}}]}'],['status'=>0,'body'=>''],
        ['status'=>200,'body'=>str_repeat('x',131073)]] as $response) {
        $calls=[]; $responses=[$response];
        rejects(fn()=>$service->prepare($owner,['whatsapp'],false),'unreadable never treated paused');
        check(count($calls)===1&&$calls[0][0]==='GET','unreadable read cannot write');
    }
    $calls=[]; $responses=[['status'=>200,'body'=>'{"rollout":{"enabled":true}}'],['status'=>0,'body'=>''],
        ['status'=>200,'body'=>'{"rollout":{"enabled":false}}']];
    check($service->prepare($owner,['whatsapp'],false)['method']==='VERIFIED_WHATSAPP_API','uncertain PUT requires actual paused read');
    check(count($calls)===3,'no retry after uncertain PUT');
    $calls=[]; $responses=[['status'=>200,'body'=>'{"rollout":{"enabled":true}}'],['status'=>403,'body'=>'{}'],
        ['status'=>200,'body'=>'{"rollout":{"enabled":true}}']];
    rejects(fn()=>$service->prepare($owner,['whatsapp'],false),'active after update denied');
    check(count($calls)===3,'failed pause is bounded');
    $calls=[];
    rejects(fn()=>$service->prepare($owner,['whatsapp','messenger'],false),'non-WA must be paused explicitly');
    check($calls===[],'unsupported channels do not modify unrelated WA');
    MetaInboxAccess::$owner=false;
    rejects(fn()=>$service->prepare($owner,['whatsapp'],true),'non-owner denied');
    MetaInboxAccess::$owner=true;
    $calls=[]; $responses=[['status'=>200,'body'=>'{"rollout":{"enabled":true}}']];
    check($service->inspectWhatsApp()===['readable'=>true,'enabled'=>true,'status'=>'ACTIVE','http_status'=>200],'sanitized read-only status');
    check(array_column($calls,0)===['GET'],'status never sends PUT');
    $service=new MetaBusinessAgent(fn()=>throw new RuntimeException('secret transport text'),$config);
    check($service->inspectWhatsApp()===['readable'=>false,'enabled'=>null,'status'=>'UNVERIFIED','http_status'=>0],'transport errors disclose no details');
    echo 'PASS '.$checks."\n";
}
