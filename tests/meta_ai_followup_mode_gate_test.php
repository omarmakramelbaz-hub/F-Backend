<?php
// The real inherited followup coordinator with isolated policy, sender and AI boundaries.
// No framework, network, customer data, persistent writes or ERP calls are used.
namespace Illuminate\Support\Facades {
    class Schema { public static bool $installed=true; public static function hasTable($table): bool { return self::$installed; } }
    class Crypt { public static function getKey(): string { return str_repeat('fixture-key-',4); } }
}
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess { public const WABA_ID='fixture-waba'; public const PHONE_ID='fixture-phone'; }
    class MetaOrderNativeEvidence { public function thread(int $id): object { return (object)['id'=>$id,'waba_id'=>$GLOBALS['modeControl']->channel,'phone_number_id'=>'fixture-account']; } }
    class WhatsAppOrderAiProvider {
        public static int $calls=0;
        public function followupVariant(array $fields): array { self::$calls++; return ['ok'=>true,'variant'=>'SHORT']; }
    }
    class WhatsAppReplyService {
        public static int $lookups=0; public static int $reservations=0; public static int $sends=0;
        public static ?string $switchAt=null; public static array $transactionGuardChecks=[];
        public function orderFollowupState($actor,$cid,$uuid,$inbound) { self::$lookups++; return null; }
        public function state($actor,$cid): array { return ['can_reply'=>true,'latest_inbound_id'=>1]; }
        public function sendOrderFollowup($actor,$cid,array $input,callable $guard): array {
            if (self::$switchAt==='before_reservation') $GLOBALS['modeControl']->analysisOnly=true;
            // The actual sender invokes this guard under its transaction, before CLAIMED.
            self::$transactionGuardChecks[]=$guard();
            if (!end(self::$transactionGuardChecks)) return ['state'=>'FAILED'];
            self::$reservations++;
            if (self::$switchAt==='before_composition') $GLOBALS['modeControl']->analysisOnly=true;
            try { ($input['_compose'])(); } catch (\Throwable $error) { return ['state'=>'FAILED']; }
            if (self::$switchAt==='before_send') $GLOBALS['modeControl']->analysisOnly=true;
            if (!$guard()) return ['state'=>'FAILED'];
            self::$sends++;
            return ['state'=>'ACCEPTED','reply_id'=>1];
        }
    }
    class MetaReplyService extends WhatsAppReplyService {}
}
namespace {
    require __DIR__.'/../app/Support/WhatsAppInboxProtocol.php';
    require __DIR__.'/../app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
    require __DIR__.'/../app/Support/WhatsAppOrderExtraction.php';
    require __DIR__.'/../app/Services/Dashboard/WhatsAppOrderFollowupService.php';
    require __DIR__.'/../app/Services/Dashboard/MetaOrderFollowupService.php';
    use App\Services\Dashboard\WhatsAppOrderFollowupService;
    use App\Services\Dashboard\MetaOrderFollowupService;
    use App\Services\Dashboard\WhatsAppReplyService;
    use App\Services\Dashboard\MetaReplyService;
    use App\Services\Dashboard\WhatsAppOrderAiProvider;
    use App\Support\WhatsAppOrderExtraction;
    class ModePolicyFixture {
        public bool $analysisOnly=false; public string $channel='whatsapp'; public int $revision=7;
        public function gate($channel): array {
            return ['effective'=>!$this->analysisOnly&&$channel===$this->channel,
                'revision'=>$this->revision,'actor_id'=>9,
                'floor'=>['message_floor'=>0,'event_floor'=>0,'started_at_utc'=>'2026-10-15 09:00:00']];
        }
    }
    // Deliberately permissive caller handoff/refresh proves the local send-mode guard.
    class ModeFollowupFixture extends WhatsAppOrderFollowupService { public function handoffAllowed(array $plan): bool { return true; } }
    class ModeMetaFollowupFixture extends MetaOrderFollowupService { public function handoffAllowed(array $plan): bool { return true; } }
    function app($class) { return $GLOBALS['modeControl']; }
    $checks=0;
    function modeCheck(bool $ok,string $label): void { $GLOBALS['checks']++; if (!$ok) throw new \RuntimeException('FOLLOWUP_MODE_GATE: '.$label); }
    function modePlan(string $channel): array {
        $data=WhatsAppOrderExtraction::none('MISSING_ITEMS'); $data['decision']='DRAFT'; $data['evidence']['request_ids']=['m1'];
        return ['draft_id'=>1,'conversation_id'=>1,'evidence_floor'=>0,'evidence_ceiling'=>1,
            'evidence_hash'=>str_repeat('a',64),'latest_customer_id'=>1,'latest_customer_key'=>str_repeat('b',64),
            'episode_customer_key'=>str_repeat('b',64),'fields'=>['NAME'],'data'=>$data,
            'transcript'=>[['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-10-15 09:01:00','text'=>'عايز فسيخ','location'=>null]],
            'primary_ai'=>['channel'=>$channel,'revision'=>7,'actor_id'=>9,'message_floor'=>0,'event_floor'=>0,'started_at_utc'=>'2026-10-15 09:00:00']];
    }
    function modeReset(string $channel): void {
        $GLOBALS['modeControl']=new ModePolicyFixture; $GLOBALS['modeControl']->channel=$channel;
        WhatsAppReplyService::$lookups=0; WhatsAppReplyService::$reservations=0; WhatsAppReplyService::$sends=0;
        WhatsAppReplyService::$switchAt=null; WhatsAppReplyService::$transactionGuardChecks=[]; WhatsAppOrderAiProvider::$calls=0;
        \Illuminate\Support\Facades\Schema::$installed=true;
    }
    foreach (['whatsapp','messenger','instagram'] as $channel) {
        $make=static fn()=>$channel==='whatsapp'
            ?new ModeFollowupFixture(new WhatsAppReplyService,new WhatsAppOrderAiProvider)
            :new ModeMetaFollowupFixture(new MetaReplyService,new WhatsAppOrderAiProvider);
        modeReset($channel); $plan=modePlan($channel);
        $result=$make()->request((object)['id'=>9],$plan,static fn()=>$plan);
        modeCheck($result['status']==='ACCEPTED'&&WhatsAppReplyService::$reservations===1
            &&WhatsAppOrderAiProvider::$calls===1&&WhatsAppReplyService::$sends===1,'full mode keeps existing automatic path '.$channel);
        modeReset($channel); $GLOBALS['modeControl']->analysisOnly=true;
        $refreshCalls=0;
        $result=$make()->request((object)['id'=>9],$plan,static function()use($plan,&$refreshCalls){$refreshCalls++;return $plan;});
        modeCheck($result['status']==='BLOCKED'&&$refreshCalls===0&&WhatsAppReplyService::$lookups===0
            &&WhatsAppReplyService::$reservations===0&&WhatsAppOrderAiProvider::$calls===0&&WhatsAppReplyService::$sends===0,
            'analysis-only old valid plan stops before lookup reservation composition and send '.$channel);
        modeReset($channel); $GLOBALS['modeControl']->revision=8;
        $result=$make()->request((object)['id'=>9],$plan,static fn()=>$plan);
        modeCheck($result['status']==='BLOCKED'&&WhatsAppReplyService::$reservations===0&&WhatsAppOrderAiProvider::$calls===0,
            'stale revision cannot reserve with permissive external guard '.$channel);
        modeReset($channel);
        $result=$make()->request((object)['id'=>9],$plan,static function()use($plan){$GLOBALS['modeControl']->analysisOnly=true;return $plan;});
        modeCheck($result['status']==='BLOCKED'&&WhatsAppReplyService::$lookups===0&&WhatsAppReplyService::$reservations===0,
            'mode change while refreshing stops before reservation '.$channel);
        foreach (['before_reservation','before_composition','before_send'] as $phase) {
            modeReset($channel); WhatsAppReplyService::$switchAt=$phase;
            $result=$make()->request((object)['id'=>9],$plan,static fn()=>$plan);
            modeCheck($result['status']==='BLOCKED'&&WhatsAppReplyService::$sends===0,'fresh guard stops mode change at '.$phase.' '.$channel);
            modeCheck(WhatsAppReplyService::$reservations===($phase==='before_reservation'?0:1),'transaction guard reserves only under reply authority '.$phase.' '.$channel);
            modeCheck(WhatsAppOrderAiProvider::$calls===($phase==='before_send'?1:0),'composition guard avoids AI after mode closed '.$phase.' '.$channel);
        }
    }
    echo 'PASS meta AI followup mode gate ('.$checks.' checks)'.PHP_EOL;
}
