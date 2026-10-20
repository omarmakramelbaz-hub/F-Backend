<?php

namespace {
    class WorkflowBlocked extends \RuntimeException {}
    function abort_unless($condition,$status,$message='BLOCKED') { if (!$condition) throw new WorkflowBlocked($message,$status); }
    function abort_if($condition,$status,$message='BLOCKED') { if ($condition) throw new WorkflowBlocked($message,$status); }
    function app($abstract=null) { $c=\Illuminate\Container\Container::getInstance(); return $abstract===null?$c:$c->make($abstract); }
    function config($key=null,$default=null) { return $key===null?app('config'):app('config')->get($key,$default); }
    function now($timezone=null) { return \Carbon\Carbon::now($timezone); }
    $autoload=getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($autoload)&&is_dir($autoload)) $autoload.='/autoload.php';
    if (!is_string($autoload)||!is_file($autoload)) throw new \RuntimeException('ISOLATED_TEST_VENDOR_REQUIRED');
    require $autoload;
    set_error_handler(function($severity,$message,$file,$line){if($severity&E_ALL)throw new \ErrorException($message,0,$severity,$file,$line);return false;});
}

namespace App\Models {
    class User {
        public static function withoutGlobalScopes() { return new class {
            public function find($id) { return \Illuminate\Support\Facades\DB::table('test_users')->where('id',$id)->first(); }
        }; }
    }
    class Resturant {
        public $effective_status;
        public static function withoutGlobalScopes() { return new class {
            public function lockForUpdate() { return $this; }
            public function find($id) {
                $row=\Illuminate\Support\Facades\DB::table('test_branches')->where('id',$id)->first();
                if (!$row) return null; $model=new Resturant; $model->effective_status=$row->status; return $model;
            }
        }; }
        public function isWithinBusinessHours() { return $this->effective_status==='opened'; }
        public function getEffectiveStatusAttribute() { return $this->effective_status; }
    }
}

namespace App\Services\Dashboard {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    class TakeawayAccess {
        public function actor($actor) {
            $row=$actor?\App\Models\User::withoutGlobalScopes()->find($actor->id):null;
            \abort_unless($row&&$row->active&&$row->account_type==='admin',403,'ACTOR_DENIED'); return $row;
        }
        public function permissions($actor) { return ['can_checkout'=>(bool)$this->actor($actor)->can_checkout]; }
        public function branch($branch,$actor,$lock=false) {
            $actor=$this->actor($actor); \abort_unless(in_array($branch,json_decode($actor->branches,true),true),403,'BRANCH_DENIED');
            $q=DB::table('test_branches')->where('id',(int)substr($branch,2)); if ($lock) $q->lockForUpdate();
            $r=$q->first(); \abort_unless($r,404,'BRANCH_MISSING'); return ['value'=>$branch,'id'=>$r->id,'kind'=>'f','name'=>$r->name];
        }
        public function branches($actor) { return array_map(fn($b)=>$this->branch($b,$actor),json_decode($this->actor($actor)->branches,true)); }
    }
    class WhatsAppInboxAccess {
        public const WABA_ID='468336579702269'; public const PHONE_ID='515388018324075';
        public function actor($actor) { $fresh=\app(TakeawayAccess::class)->actor($actor); \abort_unless(empty($fresh->owner_resturant_id),403,'CENTRAL_ONLY'); return $fresh; }
        public function available() { return Schema::hasTable('whatsapp_inbox_messages'); }
    }
    class WhatsAppOrderAiProvider {
        public static $data; public static $calls=0; public static $callback; public static $failure=false; public static $lastTranscript;
        public static $failureReason='TRANSPORT_ERROR'; public static $failureDiagnostic=[];
        public function followupVariant(array $fields): array { return ['ok'=>true,'variant'=>'SHORT']; }
        // This harness mocks extraction; production spelling/intent policy has its own pure suite.
        public static function followupFulfillment(array $data,array $transcript): string {
            $mode=$data['fulfillment']??'UNKNOWN';
            return $mode==='UNKNOWN'&&is_string($data['customer']['phone']??null)&&is_string($data['customer']['address']??null)
                ?'DELIVERY':$mode;
        }
        public function extract(array $transcript): array {
            self::$calls++; self::$lastTranscript=$transcript;
            \abort_unless(DB::connection()->transactionLevel()===0,500,'NETWORK_LOCK');
            if (self::$callback) (self::$callback)();
            return self::$failure?['ok'=>false,'reason'=>self::$failureReason,'data'=>null,'diagnostic'=>self::$failureDiagnostic]:['ok'=>true,'reason'=>null,'data'=>self::$data];
        }
    }
    class BranchCustomers {
        public static $calls=0;
        public static function phoneKey(string $phone): string {
            $phone=strtr($phone,array_combine(preg_split('//u','٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),range(0,9)));
            $phone=strtr($phone,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),range(0,9)));
            $phone=preg_replace('/[^0-9+]/','',$phone); return preg_replace('/^(?:\+20|0020|20)(1[0125][0-9]*)$/','0$1',$phone);
        }
        public function save(array $v,$actor): array {
            self::$calls++; \app(TakeawayAccess::class)->branch($v['branch'],$actor,true);
            \abort_unless(isset($v['idempotency_key'],$v['latitude'],$v['longitude']),422,'CUSTOMER_CONTRACT');
            $key=self::phoneKey($v['phone']); $row=DB::table('branch_customers')->where('branch',$v['branch'])->where('phone_key',$key)->first();
            if ($row) \abort_unless(($v['customer_id']??null)===$row->id&&($v['expected_revision']??null)===$row->revision,409,'CUSTOMER_RACE');
            $data=['branch'=>$v['branch'],'phone_key'=>$key,'phone'=>$v['phone'],'name'=>$v['name'],'address'=>$v['address'],'area'=>$v['area'],
                'delivery_notes'=>$v['delivery_notes'],'latitude'=>$v['latitude'],'longitude'=>$v['longitude'],'revision'=>$row?$row->revision+1:1];
            if ($row) { DB::table('branch_customers')->where('id',$row->id)->update($data); $id=$row->id; }
            else $id=DB::table('branch_customers')->insertGetId($data);
            return ['customer'=>(array)DB::table('branch_customers')->where('id',$id)->first()];
        }
    }
    class PosServicePhone {
        public static $items=[]; public static $calls=0; public static $callback;
        public function customers(array $values,$actor): array {
            self::$calls++; \abort_unless(($values['prefix']??null)===false,500,'EXACT_PHONE_REQUIRED');
            \app(TakeawayAccess::class)->branch($values['branch'],$actor);
            if (self::$callback) (self::$callback)();
            return ['items'=>self::$items];
        }
    }
    class PhoneDelivery {
        public static $drift=0; public static $callback;
        public function settings(string$branch,$actor,?string$origin=null,bool$lock=false):array {
            $b=\app(TakeawayAccess::class)->branch($branch,$actor,$lock);
            \abort_unless($origin===null,422,'PHYSICAL_SETTINGS_ONLY');
            $query=DB::table('resturants')->where('id',$b['id']);if($lock)$query->lockForUpdate();$row=$query->first();
            $lat=$row->lat??null;$long=$row->lng??null;
            $valid=is_numeric($lat)&&is_numeric($long)&&is_finite((float)$lat)&&is_finite((float)$long)
                &&abs((float)$lat)<=90&&abs((float)$long)<=180&&!((float)$lat===0.0&&(float)$long===0.0);
            return['branch'=>$b,'latitude'=>$valid?(float)$lat:null,'longitude'=>$valid?(float)$long:null,
                'km_price'=>$row->km_price??null,'ready'=>$valid&&is_numeric($row->km_price??null)];
        }
        public function quote(array $v,$actor): array {
            \app(TakeawayAccess::class)->branch($v['branch'],$actor);
            \abort_unless($v['location_confirmed']&&isset($v['latitude'],$v['longitude']),422,'PIN_CONTRACT');
            if (isset($v['delivery_origin_branch'])) {
                $origin=\app(FasakhanstaDeliveryOrigin::class)->check($v['delivery_origin_branch'],$v['branch'],$actor);
                if (self::$callback) (self::$callback)();
                return ['delivery'=>['delivery_quote_hash'=>PosServiceTicket::fingerprint([$v['branch'],$origin,$v['latitude'],$v['longitude'],self::$drift]),
                    'delivery_origin_branch'=>$origin['value'],'delivery_origin'=>$origin,'fulfillment_branch'=>$v['branch'],
                    'delivery_fee'=>'50.00','distance_km'=>'1.000','method'=>'fixture']];
            }
            return ['delivery'=>['delivery_quote_hash'=>PosServiceTicket::fingerprint([$v['branch'],$v['latitude'],$v['longitude'],self::$drift]),
                'delivery_fee'=>'50.00','distance_km'=>'1.000','method'=>'fixture']];
        }
    }
    class FasakhanstaDeliveryOrigin {
        public const CAIRO_ORIGINS = [309=>'المقطم ستور',311=>'التجمع ستور'];
        public static function isConfiguredOrigin(string $value): bool {
            return preg_match('/\Af:([1-9][0-9]*)\z/',$value,$m)&&array_key_exists((int)$m[1],self::CAIRO_ORIGINS);
        }
        public static function matchesRegisteredName(string $value,string $name): bool {
            if (!self::isConfiguredOrigin($value)) return false;
            return trim(preg_replace('/\s+/u',' ',$name))===self::CAIRO_ORIGINS[(int)substr($value,2)];
        }
        public function check(string $origin,string $fulfillment,$actor,bool $lock=false): array {
            \app(TakeawayAccess::class)->branch($fulfillment,$actor,$lock);
            \abort_unless($fulfillment==='f:301'&&self::isConfiguredOrigin($origin),422,'INVALID_DELIVERY_ORIGIN');
            $m=[null,substr($origin,2)];
            $q=DB::table('resturants')->where('id',(int)$m[1]);if($lock)$q->lockForUpdate();$row=$q->first();
            \abort_unless($row&&in_array((int)($row->parent_id??0),[0,301],true)&&self::matchesRegisteredName($origin,$row->name),422,'INVALID_DELIVERY_ORIGIN');
            return ['value'=>$origin,'id'=>(int)$row->id,'kind'=>'f','name'=>$row->name,'parent_id'=>$row->parent_id===null?null:(int)$row->parent_id,
                'latitude'=>(float)$row->lat,'longitude'=>(float)$row->lng,'km_price'=>$row->km_price];
        }
    }
    class PosServiceTicket {
        public static $drift=0; public static $failAfterWrite=false; public static $saveCalls=0; public static $lastPayload;
        public static function fingerprint(array $v): string {
            $sort=function($a)use(&$sort){if(!is_array($a))return $a;if(!array_is_list($a))ksort($a);foreach($a as $k=>$x)$a[$k]=$sort($x);return $a;};
            return hash('sha256',json_encode($sort($v),JSON_UNESCAPED_UNICODE));
        }
        public function quote($channel,array $v,$actor): array {
            \abort_unless($channel==='phone',422,'CHANNEL_CONTRACT'); \app(TakeawayAccess::class)->branch($v['branch'],$actor);
            \abort_unless(isset($v['delivery_quote_hash']),422,'DELIVERY_HASH_REQUIRED');
            foreach($v['items'] as $i) \abort_unless($i['product_id']===11&&$i['quantity_mode']==='weight'&&(float)$i['quantity']>0&&in_array($i['option_id'],array_merge([''],array_column(TakeawayCatalog::$options,'id')),true),422,'CATALOG_CONTRACT');
            $total=10000+self::$drift;
            return ['quote_hash'=>self::fingerprint([$v['branch'],$v['items'],$total]),'total'=>number_format($total/100,2,'.',''),'total_cents'=>$total];
        }
        public function save($channel,array $v,$actor): array {
            self::$saveCalls++; self::$lastPayload=$v;
            \abort_unless($channel==='phone'&&$v['send_to_kitchen']===true&&$v['discount']==='0.00',422,'UNPAID_CONTRACT');
            \abort_unless(!isset($v['ticket_id'])&&!isset($v['actor_id'])&&!isset($v['payment_status']),422,'INJECTED_COMMAND');
            $q=$this->quote($channel,$v,$actor); \abort_unless($q['quote_hash']===$v['quote_hash'],409,'QUOTE_DRIFT');
            $id=DB::table('test_tickets')->insertGetId(['branch'=>$v['branch'],'customer_id'=>$v['customer_id'],'actor_id'=>$actor->id,
                'command_key'=>$v['idempotency_key'],'payment_status'=>'unpaid','status'=>'new']);
            DB::table('test_kitchen')->insert(['ticket_id'=>$id]);
            if(self::$failAfterWrite) throw new \RuntimeException('FIXED_ERP_FAILURE');
            DB::table('test_print')->insert(['ticket_id'=>$id,'branch'=>$v['branch'],'status'=>'pending']);
            return ['ticket'=>['id'=>$id],'operation'=>['ticket_id'=>$id],'print_queued'=>true];
        }
    }
    class TakeawayCatalog {
        public static $ambiguous=false; public static $mode='weight'; public static $options=[];
        public function listing($v,$actor): array {
            \app(TakeawayAccess::class)->branch($v['branch'],$actor);
            $p=['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>self::$mode,'options'=>self::$options];
            return ['items'=>self::$ambiguous?[$p,$p]:[$p],'pagination'=>['last_page'=>1]];
        }
    }
}

namespace {
    use App\Services\Dashboard\WhatsAppOrderWorkflow;
    use App\Services\Dashboard\WhatsAppOrderAiProvider as Ai;
    use App\Services\Dashboard\PosServiceTicket as Pos;
    use App\Services\Dashboard\PhoneDelivery;
    use App\Services\Dashboard\BranchCustomers;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\Facade;

    $container=new Container; Container::setInstance($container); $db=new Capsule($container);
    $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
    $db->setEventDispatcher(new Dispatcher($container)); $db->setAsGlobal(); $db->bootEloquent();
    $container->instance('db',$db->getDatabaseManager()); $container->instance('db.schema',$db->getConnection()->getSchemaBuilder());
    $key=random_bytes(32); $container->instance('encrypter',new \Illuminate\Encryption\Encrypter($key,'AES-256-CBC'));
    $container->instance('config',new \Illuminate\Config\Repository(['app'=>['key'=>'base64:'.base64_encode($key)],
        'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]],
        'whatsapp_orders'=>['enabled'=>true,'mode'=>'review','model'=>'fixture','api_key'=>'fixture-secret','automation_actor_id'=>7,
            'allowed_branch_ids'=>['1'],'activation_message_id'=>0,'activation_event_id'=>0],
        'whatsapp_cart'=>['debounce_seconds'=>0,'product_mappings'=>[],'activated_at'=>'2026-10-09 22:00:00']]));
    $translator=new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en');
    $container->instance('validator',new \Illuminate\Validation\Factory($translator,$container)); Facade::setFacadeApplication($container);
    require dirname(__DIR__).'/app/Support/WhatsAppInboxProtocol.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
    require dirname(__DIR__).'/app/Support/WhatsAppOrderExtraction.php';
    // Exercise the deployed final-form consent gate, rather than treating its
    // production dependency as absent in this isolated database fixture.
    require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderFollowupService.php';
    require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderWorkflow.php';
    require dirname(__DIR__).'/database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php';
    require dirname(__DIR__).'/database/migrations/2026_10_10_000002_create_whatsapp_order_drafts.php';
    Schema::create('whatsapp_webhook_events',function(Blueprint $t){$t->bigIncrements('id');$t->timestamp('processed_at')->nullable();});
    (new \CreateWhatsAppInboxTables)->up(); (new \CreateWhatsAppOrderDrafts)->up();
    Schema::create('test_users',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->string('account_type');$t->integer('owner_resturant_id')->nullable();$t->boolean('active');$t->boolean('can_checkout');$t->text('branches');});
    Schema::create('test_branches',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->string('name');$t->string('status');});
    Schema::create('branch_customers',function(Blueprint $t){$t->bigIncrements('id');$t->string('branch');$t->string('phone_key');$t->string('phone');$t->string('name');$t->string('address');$t->string('area');$t->string('delivery_notes');$t->float('latitude');$t->float('longitude');$t->integer('revision');$t->unique(['branch','phone_key']);});
    Schema::create('pos_service_tickets',function(Blueprint $t){$t->bigIncrements('id');$t->string('branch');$t->string('channel');$t->string('customer_phone');$t->string('address');$t->string('area')->nullable();$t->string('delivery_notes')->nullable();$t->string('status');$t->string('payment_status');$t->timestamp('bill_issued_at')->nullable();$t->text('delivery_snapshot');});
    Schema::create('test_tickets',function(Blueprint $t){$t->bigIncrements('id');$t->string('branch');$t->integer('customer_id');$t->integer('actor_id');$t->uuid('command_key')->unique();$t->string('payment_status');$t->string('status');});
    Schema::create('test_kitchen',function(Blueprint $t){$t->integer('ticket_id')->unique();});
    Schema::create('test_print',function(Blueprint $t){$t->integer('ticket_id')->unique();$t->string('branch');$t->string('status');});
    Schema::create('test_billing',function(Blueprint $t){$t->integer('forbidden_calls');});
    DB::table('test_users')->insert(['id'=>7,'account_type'=>'admin','owner_resturant_id'=>null,'active'=>1,'can_checkout'=>1,'branches'=>'["f:1","f:2"]']);
    DB::table('test_users')->insert(['id'=>8,'account_type'=>'admin','owner_resturant_id'=>null,'active'=>1,'can_checkout'=>1,'branches'=>'["f:1"]']);
    DB::table('test_branches')->insert([['id'=>1,'name'=>'المنصورة','status'=>'opened'],['id'=>2,'name'=>'المحلة','status'=>'opened']]);
    // This suite isolates native extraction from the optional owned followup sender.
    // Its real static form gate still runs; receipt-backed cases below restore the
    // production coordinator once the authenticated reply schema is installed.
    class NativeExtractionWorkflow extends WhatsAppOrderWorkflow { protected function followupService() { return null; } }
    $workflow=new NativeExtractionWorkflow; $actor=(object)['id'=>7]; $checks=0;
    function expectFlow($condition,$label) { global $checks; $checks++; if(!$condition)throw new \RuntimeException('FAILED:'.$label); }
    function blocked(callable $call,int $status,string $label) { try{$call();}catch(WorkflowBlocked $e){expectFlow($e->getCode()===$status,$label);return;} throw new \RuntimeException('NOT_BLOCKED:'.$label); }
    function resetFlow() {
        // Fixed native message dates must be compared with a fixed calendar,
        // never the wall clock of whichever machine happens to run this suite.
        \Carbon\Carbon::setTestNow('2026-10-09 22:05:00');
        \Carbon\CarbonImmutable::setTestNow('2026-10-09 22:05:00');
        foreach (['whatsapp_reply_projection_keys','whatsapp_reply_requests'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
        foreach(['whatsapp_order_drafts','whatsapp_order_scans','whatsapp_inbox_messages','whatsapp_inbox_conversations','branch_customers','test_print','test_kitchen','test_tickets','pos_service_tickets','whatsapp_webhook_events']as$t)DB::table($t)->delete();
        Ai::$calls=0; Ai::$callback=null; Ai::$failure=false; Ai::$failureReason='TRANSPORT_ERROR'; Ai::$failureDiagnostic=[];
        Pos::$drift=0; Pos::$failAfterWrite=false; Pos::$saveCalls=0; Pos::$lastPayload=null; PhoneDelivery::$drift=0; PhoneDelivery::$callback=null; BranchCustomers::$calls=0;
        \App\Services\Dashboard\TakeawayCatalog::$ambiguous=false; \App\Services\Dashboard\TakeawayCatalog::$mode='weight'; \App\Services\Dashboard\TakeawayCatalog::$options=[];
        \App\Services\Dashboard\PosServicePhone::$items=[]; \App\Services\Dashboard\PosServicePhone::$calls=0; \App\Services\Dashboard\PosServicePhone::$callback=null;
        DB::table('test_users')->where('id',7)->update(['active'=>1,'can_checkout'=>1,'owner_resturant_id'=>null]);
        DB::table('test_branches')->where('id',1)->update(['status'=>'opened']);
        app('config')->set('whatsapp_orders.mode','review'); app('config')->set('whatsapp_orders.activation_message_id',0); app('config')->set('whatsapp_orders.allowed_branch_ids',['1']);
        app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:00'); app('config')->set('whatsapp_cart.debounce_seconds',0); app('config')->set('whatsapp_cart.product_mappings',[]); app('config')->set('whatsapp_cart.branch_aliases',[]);
        Ai::$data=extractionData();
    }
    function extractionData(): array {
        return ['decision'=>'CONFIRMED','customer'=>['name'=>'عمر','phone'=>'201000000001','address'=>'شارع البحر','area'=>'المنصورة','notes'=>null],
            'branch_hint'=>'المنصورة','fulfillment'=>'DELIVERY','items'=>[['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null]],
            'approximate_total'=>null,'evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m3'],'customer_acceptance_ids'=>['m4'], 'location_id'=>'m2'],'issues'=>[]];
    }
    function finalOrderForm(array $data): string {
        $customer=$data['customer'];$items=[];
        foreach($data['items'] as $item)$items[]=$item['quantity'].' '.($item['quantity_mode']==='weight'?'كيلو':'قطعة').' '.$item['name']
            .(!empty($item['option_hint'])?' '.$item['option_hint']:'');
        return "تأكيد الطلب:\n".implode('؛ ',$items)."\nالاسم: ".$customer['name'].' | رقم التواصل: '.$customer['phone']
            ."\nالعنوان: ".$customer['address'].(!empty($customer['area'])?' | المنطقة: '.$customer['area']:'')
            ."\nالفرع: ".$data['branch_hint'].' | الاستلام: دليفري'
            .(!empty($customer['notes'])?"\nملاحظات: ".$customer['notes']:'')."\nكده كله تمام؟";
    }
    function addConversation(string $peer='US.fixture'): int {
        $hash=hash_hmac('sha256','whatsapp-peer-v1:468336579702269:515388018324075:user:'.$peer,Crypt::getKey());
        return DB::table('whatsapp_inbox_conversations')->insertGetId(['waba_id'=>'468336579702269','phone_number_id'=>'515388018324075','peer_hash'=>$hash,
            'customer'=>Crypt::encryptString('{}'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    function insertMessage(int $c,string $id,string $direction,string $text,int $second,?array $location=null,string $peer='US.fixture'): int {
        $m=['id'=>$id,'timestamp'=>(string)(strtotime('2026-10-09 22:00:00 UTC')+$second),'type'=>$location?'location':'text'];
        if($location)$m['location']=['latitude'=>$location['lat'],'longitude'=>$location['long']];else$m['text']=['body'=>$text];
        $source=$direction==='inbound'?'messages':'smb_message_echoes';
        if($direction==='inbound'){$m['from']='201000000001';$m['from_user_id']=$peer;}else{$m['from']='201285545554';$m['to']='201000000001';$m['to_user_id']=$peer;}
        $payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>'468336579702269','changes'=>[['field'=>$source,'value'=>['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075','display_phone_number'=>'201285545554'],$direction==='inbound'?'messages':'message_echoes'=>[$m]]]]]]];
        $report=\App\Support\WhatsAppInboxProtocol::report($payload,'468336579702269','515388018324075');
        expectFlow(count($report['messages'])===1,'actual normalizer message'); $dto=$report['messages'][0];
        return DB::table('whatsapp_inbox_messages')->insertGetId(['conversation_id'=>$c,'message_key'=>hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:'.$id),
            'direction'=>$dto['direction'],'type'=>$dto['type'],'source'=>$dto['source'],'sent_at'=>$dto['sent_at'],'content'=>Crypt::encryptString(json_encode($dto)),
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    function replaceNativeText(int $conversation,string $direction,string $text): void {
        $row=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('direction',$direction)->orderByDesc('id')->first();
        $dto=json_decode(Crypt::decryptString($row->content),true);$dto['text']=$text;$dto['content']['message']['text']['body']=$text;
        DB::table('whatsapp_inbox_messages')->where('id',$row->id)->update(['content'=>Crypt::encryptString(json_encode($dto))]);
    }
    function fixtureThread(string $suffix='',?string $option=null): int {
        $data=extractionData();$data['items'][0]['option_hint']=$option;
        if ($option!==null) Ai::$data=$data;
        $c=addConversation('US.fixture'.$suffix);
        insertMessage($c,'req'.$suffix,'inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ'.($option===null?'':' '.$option).' من المنصورة.',1,null,'US.fixture'.$suffix);
        insertMessage($c,'pin'.$suffix,'inbound','',2,['lat'=>31.04,'long'=>31.37],'US.fixture'.$suffix);
        insertMessage($c,'confirm'.$suffix,'outbound',finalOrderForm($data),3,null,'US.fixture'.$suffix);
        insertMessage($c,'approval'.$suffix,'inbound','تمام',4,null,'US.fixture'.$suffix);
        return $c;
    }
    function insertCartMessage(int $c,string $id,int $second,array $lines): int {
        $m=['id'=>$id,'timestamp'=>(string)(strtotime('2026-10-09 22:00:00 UTC')+$second),'type'=>'order',
            'from'=>'201000000001','from_user_id'=>'US.fixture','order'=>['catalog_id'=>'999','product_items'=>$lines]];
        $payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>'468336579702269','changes'=>[['field'=>'messages','value'=>[
            'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075','display_phone_number'=>'201285545554'],'messages'=>[$m]]]]]]];
        $report=\App\Support\WhatsAppInboxProtocol::report($payload,'468336579702269','515388018324075');
        expectFlow(count($report['messages'])===1,'actual cart normalizer'); $dto=$report['messages'][0];
        return DB::table('whatsapp_inbox_messages')->insertGetId(['conversation_id'=>$c,'message_key'=>hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:'.$id),
            'direction'=>$dto['direction'],'type'=>$dto['type'],'source'=>$dto['source'],'sent_at'=>$dto['sent_at'],'content'=>Crypt::encryptString(json_encode($dto)),
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    function cartThread(?string $option=null): int {
        $data=extractionData();$data['items'][0]['option_hint']=$option;
        $c=addConversation();
        insertMessage($c,'cart-customer','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة.'.($option===null?'':' اختياري فسيخ '.$option.'.'),1);
        insertCartMessage($c,'cart-order',2,[['product_retailer_id'=>'half-fish','quantity'=>2,'item_price'=>'45.00','currency'=>'EGP']]);
        insertMessage($c,'cart-pin','inbound','',3,['lat'=>31.04,'long'=>31.37]);
        insertMessage($c,'cart-confirm','outbound',finalOrderForm($data),4);
        insertMessage($c,'cart-approval','inbound','تمام',5);
        Ai::$data=$data; Ai::$data['items'][0]['cart_reference']=['message_id'=>'m2','product_retailer_id'=>'half-fish'];
        Ai::$data['evidence']=['request_ids'=>['m1','m2'],'confirmation_ids'=>['m4'],'customer_acceptance_ids'=>['m5'],'location_id'=>'m3'];
        return $c;
    }
    function mappedCart(): void {
        app('config')->set('whatsapp_cart.product_mappings',['999'=>['half-fish'=>['f:1'=>[
            'product_id'=>11,'quantity_mode'=>'weight','quantity_per_unit'=>'0.5','option_id'=>'']]]]);
    }
    function textAddressThread(string $address='شارع البحر',string $total='99.00'): int {
        $c=addConversation();
        insertMessage($c,'address-request','inbound','اسمي عمر ورقمي 201000000001 وعنواني '.$address.' في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1);
        Ai::$data=extractionData(); Ai::$data['customer']['address']=$address; Ai::$data['approximate_total']=$total;
        insertMessage($c,'address-estimate','outbound','تقدير مبدئي للطلب: 1 كيلو فسيخ من المنصورة إلى '.$address.'، الإجمالي التقريبي '.$total.' جنيه.',2);
        insertMessage($c,'address-confirm','outbound',finalOrderForm(Ai::$data),3);
        insertMessage($c,'address-approval','inbound','تمام',4);
        Ai::$data['evidence']['confirmation_ids']=['m2','m3']; Ai::$data['evidence']['location_id']=null;
        Ai::$data['issues']=['LOCATION_REQUIRED','PRICE_ESTIMATE_ONLY'];
        return $c;
    }
    function savedCustomer($actor,string $address='شارع البحر',float $lat=31.04,float $lng=31.37): void {
        app(BranchCustomers::class)->save(['branch'=>'f:1','idempotency_key'=>'00000000-0000-4000-8000-000000000007',
            'name'=>'عمر','phone'=>'201000000001','address'=>$address,'area'=>'المنصورة','delivery_notes'=>'','latitude'=>$lat,'longitude'=>$lng],$actor);
    }
    function reviewData(int $revision=1): array {
        return ['expected_revision'=>$revision,'branch'=>'f:1','customer_name'=>'عمر','customer_phone'=>'201000000001','address'=>'شارع البحر','area'=>'المنصورة','delivery_notes'=>'',
            'latitude'=>31.04,'longitude'=>31.37,'location_confirmed'=>true,'items'=>[['product_id'=>11,'quantity'=>'1','quantity_mode'=>'weight','option_id'=>'']]];
    }
    function prepared($workflow,$actor,int $c): array {
        $a=$workflow->analyze($c,$actor); $q=$workflow->quote($a['draft']['id'],reviewData($a['draft']['revision']),$actor);
        $v=$q['draft']['review'];$v['expected_revision']=$q['draft']['revision'];return [$q['draft']['id'],$v];
    }

    // The current complete form ends with a question, but its later, separate
    // customer approval must make it usable by the real extraction coordinator.
    resetFlow(); $c=fixtureThread();$actual=$workflow->analyze($c,$actor);
    expectFlow($actual['draft']['data']!==null,'actual compact final form and fresh customer approval survive extraction');
    [$id,$v]=prepared($workflow,$actor,$c);
    expectFlow(Ai::$lastTranscript[1]['location']===['lat'=>31.04,'long'=>31.37],'normalizer location bound to transcript');
    $r=$workflow->dispatch($id,$v+['actor_id'=>999,'customer_id'=>999,'ticket_id'=>999],$actor);
    expectFlow($r['ticket_id']>0&&DB::table('test_tickets')->count()===1&&DB::table('test_print')->count()===1,'one ticket and branch print');
    expectFlow(DB::table('test_tickets')->first()->payment_status==='unpaid'&&DB::table('test_billing')->count()===0,'no billing');
    expectFlow(strpos(DB::table('whatsapp_order_drafts')->first()->extraction,'شارع البحر')===false,'encrypted draft');
    expectFlow($workflow->dispatch($id,$v,(object)['id'=>8])['replayed']===true&&DB::table('test_tickets')->count()===1,'cross actor duplicate receipt');
    $changed=$v;$changed['branch']='f:2';blocked(fn()=>$workflow->dispatch($id,$changed,$actor),409,'changed branch cannot duplicate');

    resetFlow();$c=fixtureThread();[$id,$v]=prepared($workflow,$actor,$c);DB::table('test_users')->where('id',7)->update(['active'=>0]);
    blocked(fn()=>$workflow->dispatch($id,$v,$actor),403,'stale actor');expectFlow(DB::table('test_tickets')->count()===0,'denied actor no write');

    resetFlow();$c=fixtureThread();[$id,$v]=prepared($workflow,$actor,$c);insertMessage($c,'cancel','inbound','الغي الطلب',4);
    blocked(fn()=>$workflow->dispatch($id,$v,$actor),409,'late cancellation');expectFlow(DB::table('branch_customers')->count()===0,'stale evidence no customer');

    resetFlow();$c=fixtureThread();[$id,$v]=prepared($workflow,$actor,$c);PhoneDelivery::$drift=1;
    blocked(fn()=>$workflow->dispatch($id,$v,$actor),409,'stale pin quote');PhoneDelivery::$drift=0;Pos::$drift=1;
    blocked(fn()=>$workflow->dispatch($id,$v,$actor),409,'stale price quote');expectFlow(DB::table('test_tickets')->count()===0,'drift no order');

    resetFlow();$c=fixtureThread();[$id,$v]=prepared($workflow,$actor,$c);
    BranchCustomers::$calls=0;app(BranchCustomers::class)->save(['branch'=>'f:1','idempotency_key'=>'00000000-0000-4000-8000-000000000007','name'=>'حديث','phone'=>'201000000001','address'=>'عنوان حديث','area'=>'','delivery_notes'=>'','latitude'=>31,'longitude'=>31],$actor);
    blocked(fn()=>$workflow->dispatch($id,$v,$actor),409,'customer creation race');expectFlow(DB::table('branch_customers')->first()->name==='حديث','racing customer retained');

    resetFlow();$c=fixtureThread();[$id,$v]=prepared($workflow,$actor,$c);Pos::$failAfterWrite=true;
    try{$workflow->dispatch($id,$v,$actor);}catch(\RuntimeException $e){}
    expectFlow(DB::table('branch_customers')->count()===0&&DB::table('test_tickets')->count()===0&&DB::table('test_kitchen')->count()===0,'outer rollback all ERP writes');
    expectFlow(DB::table('whatsapp_order_drafts')->first()->status==='READY','rollback draft remains retryable');

    resetFlow();$c=fixtureThread();Ai::$callback=function()use($c){insertMessage($c,'new','inbound','لحظة',4);};
    blocked(fn()=>$workflow->analyze($c,$actor),409,'provider source CAS');expectFlow(DB::table('whatsapp_order_drafts')->count()===0&&DB::table('whatsapp_order_scans')->first()->claim_nonce===null,'lost CAS releases claim');

    resetFlow();$c=fixtureThread();$a=$workflow->analyze($c,$actor);$msg=DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->first();
    $dto=json_decode(Crypt::decryptString($msg->content),true);$dto['content']['sources'][]='standby';$dto['source']='standby';
    DB::table('whatsapp_inbox_messages')->where('id',$msg->id)->update(['content'=>Crypt::encryptString(json_encode($dto))]);
    expectFlow($workflow->quote($a['draft']['id'],reviewData(),$actor)['success'],'semantic replay sources do not stale evidence');

    resetFlow();$c=fixtureThread();$other=fixtureThread('other');$foreign=DB::table('whatsapp_inbox_messages')->where('conversation_id',$other)->first()->content;
    DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->orderBy('id')->limit(1)->update(['content'=>$foreign]);
    blocked(fn()=>$workflow->analyze($c,$actor),500,'wrong encrypted conversation');expectFlow(Ai::$calls===0,'foreign ciphertext never sent');

    resetFlow();$c=fixtureThread();DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->orderBy('id')->limit(1)->update(['direction'=>'outbound']);
    blocked(fn()=>$workflow->analyze($c,$actor),500,'direction column mismatch');

    resetFlow();$c=fixtureThread();$a=$workflow->analyze($c,$actor);$v=reviewData();$v['customer_phone']='٢٠١٠٠٠٠٠٠٠٠١';
    expectFlow($workflow->quote($a['draft']['id'],$v,$actor)['draft']['review']['customer_phone']==='01000000001','Arabic phone normalized');

    resetFlow();$old=fixtureThread('old');app('config')->set('whatsapp_orders.activation_message_id',(int)DB::table('whatsapp_inbox_messages')->max('id'));
    app('config')->set('whatsapp_orders.mode','auto');$new=fixtureThread('new');$p=$workflow->process();
    expectFlow($p['dispatched']===1&&DB::table('whatsapp_order_drafts')->count()===1,'first new thread order survives cutover');
    expectFlow(DB::table('whatsapp_order_drafts')->first()->conversation_id===$new,'historical thread not billed');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');DB::table('test_branches')->where('id',1)->update(['status'=>'closed']);
    $p=$workflow->process();expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'closed branch review');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');Ai::$data['decision']='NONE';$p=$workflow->process();
    expectFlow(DB::table('whatsapp_order_drafts')->first()->status==='NONE','worker preserves no-order state');
    blocked(fn()=>$workflow->quote(DB::table('whatsapp_order_drafts')->first()->id,reviewData(),$actor),409,'NONE cannot dispatch');

    resetFlow();$c=fixtureThread();Ai::$failure=true;$a=$workflow->analyze($c,$actor);Ai::$failure=false;$b=$workflow->analyze($c,$actor,true);
    expectFlow($a['draft']['id']===$b['draft']['id']&&$b['draft']['revision']===2&&$b['draft']['data']!==null,'force retry same evidence uses same draft');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');DB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);$p=$workflow->process();
    expectFlow($p['capture_pending']===1&&Ai::$calls===0&&DB::table('test_tickets')->count()===0,'unprojected capture blocks analysis and auto dispatch');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');
    $event=DB::table('whatsapp_webhook_events')->insertGetId(['processed_at'=>null]);
    DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$event,'reason'=>'CONFLICTING_MESSAGE','attempts'=>1,'last_attempted_at'=>now('UTC')]);
    $p=$workflow->process();expectFlow($p['review_required']===1&&DB::table('whatsapp_order_drafts')->first()->reason==='CAPTURE_REVIEW_REQUIRED','new quarantine blocks auto');
    expectFlow(DB::table('test_tickets')->count()===0,'quarantine never silently bills');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');
    insertMessage($c,'cancelled','inbound','الغي الطلب',4);Ai::$data['decision']='CANCELLED';$p=$workflow->process();
    expectFlow(DB::table('whatsapp_order_drafts')->first()->status==='CANCELLED','worker preserves cancelled state');

    resetFlow();$c=fixtureThread();$a=$workflow->analyze($c,$actor);
    app(BranchCustomers::class)->save(['branch'=>'f:1','idempotency_key'=>'00000000-0000-4000-8000-000000000007','name'=>'عمر','phone'=>'201000000001','address'=>'شارع البحر','area'=>'','delivery_notes'=>'original','latitude'=>31.04,'longitude'=>31.37],$actor);
    $q=$workflow->quote($a['draft']['id'],reviewData(),$actor);$v=$q['draft']['review'];$v['expected_revision']=$q['draft']['revision'];
    DB::table('branch_customers')->update(['revision'=>2,'address'=>'عنوان أحدث']);
    blocked(fn()=>$workflow->dispatch($a['draft']['id'],$v,$actor),409,'sealed customer revision');
    expectFlow(DB::table('branch_customers')->first()->address==='عنوان أحدث','operator address update preserved');

    resetFlow();$c=fixtureThread();Ai::$callback=function()use($workflow,$c,$actor){blocked(fn()=>$workflow->analyze($c,$actor),409,'exclusive network lease');};
    expectFlow($workflow->analyze($c,$actor)['success']&&Ai::$calls===1,'duplicate analysis no second provider call');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');Ai::$failure=true;
    $workflow->process();expectFlow(Ai::$calls===1,'first transient attempt');$workflow->process();expectFlow(Ai::$calls===1,'fixed backoff suppresses immediate retry');
    DB::table('whatsapp_order_scans')->update(['next_attempt_at'=>now('UTC')->subMinute()]);$workflow->process();
    DB::table('whatsapp_order_scans')->update(['next_attempt_at'=>now('UTC')->subMinute()]);$workflow->process();
    DB::table('whatsapp_order_scans')->update(['next_attempt_at'=>now('UTC')->subMinute()]);$workflow->process();
    expectFlow(Ai::$calls===3&&DB::table('whatsapp_order_drafts')->count()===1,'transient retries capped without duplicate draft');

    resetFlow();$c=fixtureThread();for($i=4;$i<=64;$i++)insertMessage($c,'context'.$i,'inbound','رسالة', $i);
    $a=$workflow->analyze($c,$actor);expectFlow($a['draft']['reason']==='CONTEXT_INCOMPLETE'&&Ai::$calls===0,'bounded context cannot auto classify');

    resetFlow();$c=fixtureThread();$a=$workflow->analyze($c,$actor);$q=$workflow->quote($a['draft']['id'],reviewData(),$actor);
    insertMessage($c,'accept','inbound','تمام',4);$new=$workflow->analyze($c,$actor);$q2=$workflow->quote($new['draft']['id'],reviewData(),$actor);
    expectFlow($q2['success']&&DB::table('whatsapp_order_drafts')->where('id',$a['draft']['id'])->first()->reason==='STALE_TRANSCRIPT','same confirmation can replace undispatched stale quote');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');
    app(BranchCustomers::class)->save(['branch'=>'f:1','idempotency_key'=>'00000000-0000-4000-8000-000000000007','name'=>'عمر','phone'=>'201000000001','address'=>'شارع البحر','area'=>'المنصورة','delivery_notes'=>'','latitude'=>31.04,'longitude'=>31.37],$actor);
    BranchCustomers::$calls=0;$before=(array)DB::table('branch_customers')->first();$p=$workflow->process();
    expectFlow($p['dispatched']===1&&BranchCustomers::$calls===0,'auto reuses saved customer without update');
    expectFlow((array)DB::table('branch_customers')->first()===$before,'all saved customer metadata retained');

    resetFlow();$c=textAddressThread('شارع البحر','100.00');savedCustomer($actor);app('config')->set('whatsapp_orders.mode','auto');
    $event=DB::table('whatsapp_webhook_events')->insertGetId(['processed_at'=>null]);
    DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$event,'reason'=>'CONFLICTING_MESSAGE','attempts'=>1,'last_attempted_at'=>now('UTC')]);
    app('config')->set('whatsapp_orders.activation_event_id',$event);Ai::$data['approximate_total']='100';
    $p=$workflow->process();expectFlow($p['dispatched']===1,'old quarantine retained and equal numeric total allowed');
    expectFlow(DB::table('whatsapp_inbox_ingestion_failures')->count()===1,'auto never clears quarantine');
    app('config')->set('whatsapp_orders.activation_event_id',0);

    resetFlow();$c=fixtureThread();Ai::$callback=function(){DB::table('test_users')->where('id',7)->update(['active'=>0]);};
    blocked(fn()=>$workflow->analyze($c,$actor),403,'permission revoked during network call');
    expectFlow(DB::table('whatsapp_order_drafts')->count()===0&&DB::table('whatsapp_order_scans')->first()->claim_nonce===null,'revoked analysis releases claim without draft');

    resetFlow();$c=fixtureThread();$a=$workflow->analyze($c,$actor);$v=reviewData();$v['items'][0]['product_id']=999;
    blocked(fn()=>$workflow->quote($a['draft']['id'],$v,$actor),422,'foreign catalog id');expectFlow(DB::table('test_tickets')->count()===0,'invalid catalog no order');
    $state=$workflow->state($c,$actor);expectFlow(Ai::$calls===1&&count($state['drafts'])===1,'state polling has no AI call');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.enabled',false);$p=$workflow->process();
    expectFlow($p['disabled']===1&&Ai::$calls===0&&DB::table('whatsapp_order_scans')->count()===0,'disabled worker no side effects');
    app('config')->set('whatsapp_orders.enabled',true);

    // A later review-to-auto cutoff applies to existing scan rows and cached drafts.
    resetFlow();$c=fixtureThread();$a=$workflow->analyze($c,$actor);
    $historical=(array)DB::table('whatsapp_order_drafts')->where('id',$a['draft']['id'])->first();
    $cutover=(int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
    DB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['auto_floor'=>0,'analyzed_ceiling'=>0]);
    app('config')->set('whatsapp_orders.activation_message_id',$cutover);app('config')->set('whatsapp_orders.mode','auto');
    $calls=Ai::$calls;$p=$workflow->process();
    expectFlow($p['threads_seen']===0&&Ai::$calls===$calls,'refreshed global cutoff excludes historical thread despite old scan');
    expectFlow((array)DB::table('whatsapp_order_drafts')->where('id',$a['draft']['id'])->first()===$historical&&DB::table('test_tickets')->count()===0,'excluded historical draft retained without ticket');

    resetFlow();$c=fixtureThread();$old=$workflow->analyze($c,$actor);
    $historical=(array)DB::table('whatsapp_order_drafts')->where('id',$old['draft']['id'])->first();
    $cutover=(int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
    DB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['auto_floor'=>0]);
    insertMessage($c,'fresh-cutover-request','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',4);
    insertMessage($c,'fresh-cutover-pin','inbound','',5,['lat'=>31.04,'long'=>31.37]);
    insertMessage($c,'fresh-cutover-confirmation','outbound',finalOrderForm(extractionData()),6);
    insertMessage($c,'fresh-cutover-approval','inbound','تمام',7);
    app('config')->set('whatsapp_orders.activation_message_id',$cutover);app('config')->set('whatsapp_orders.mode','auto');
    $p=$workflow->process();$fresh=DB::table('whatsapp_order_drafts')->where('status','DISPATCHED')->first();
    expectFlow($p['dispatched']===1&&DB::table('test_tickets')->count()===1&&(int)$fresh->evidence_floor===$cutover,'complete new confirmation dispatches after cutover in existing thread');
    expectFlow(count(Ai::$lastTranscript)===4&&$fresh->id!==$old['draft']['id']&&(array)DB::table('whatsapp_order_drafts')->where('id',$old['draft']['id'])->first()===$historical,'fresh confirmation excludes and retains earlier cached order');

    resetFlow();$c=fixtureThread();$cutover=(int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
    insertMessage($c,'post-cutover-greeting','inbound','أهلا',4);
    $a=$workflow->analyze($c,$actor);$q=$workflow->quote($a['draft']['id'],reviewData(),$actor);
    $cached=(array)DB::table('whatsapp_order_drafts')->where('id',$a['draft']['id'])->first();
    $latest=(int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
    DB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['auto_floor'=>0,'analyzed_ceiling'=>0,
        'claimed_ceiling'=>$latest,'attempts'=>3,'next_attempt_at'=>now('UTC')->addHour()]);
    app('config')->set('whatsapp_orders.activation_message_id',$cutover);app('config')->set('whatsapp_orders.mode','auto');
    Ai::$data=\App\Support\WhatsAppOrderExtraction::none();$calls=Ai::$calls;$p=$workflow->process();
    $fresh=DB::table('whatsapp_order_drafts')->where('id',$a['draft']['id'])->first();
    $scan=DB::table('whatsapp_order_scans')->where('conversation_id',$c)->first();
    expectFlow(Ai::$calls===$calls+1&&count(Ai::$lastTranscript)===1&&Ai::$lastTranscript[0]['text']==='أهلا','stale cached extraction recomputed only from post-cutover evidence');
    expectFlow($fresh->status==='NONE'&&(int)$fresh->evidence_floor===$cutover&&(int)$fresh->revision===(int)$cached['revision']+1,'cutoff replaces stale cached draft in place');
    expectFlow($fresh->confirmation_key===null&&$fresh->sealed_payload===null&&$fresh->customer_command_key===$cached['customer_command_key']&&$fresh->ticket_command_key===$cached['ticket_command_key'],'reanalysis clears stale authorization and preserves command identities');
    expectFlow((int)$scan->auto_floor===$cutover&&(int)$scan->attempts===1&&$scan->claim_nonce===null&&$scan->next_attempt_at===null,'new cutoff scope resets exhausted retry and backoff safely');
    expectFlow($p['dispatched']===0&&DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0,'old confirmation never dispatches on later greeting');

    // The dispatch gate independently refuses an old READY cache, even with current source hashes.
    resetFlow();$c=fixtureThread();$cutover=(int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
    insertMessage($c,'cached-ready-greeting','inbound','أهلا',4);[$id,$v]=prepared($workflow,$actor,$c);
    app('config')->set('whatsapp_orders.activation_message_id',$cutover);app('config')->set('whatsapp_orders.mode','auto');
    $autoReview=new \ReflectionMethod(WhatsAppOrderWorkflow::class,'automaticReview');$autoReview->setAccessible(true);
    $reason=null;$args=[DB::table('whatsapp_order_drafts')->where('id',$id)->first(),$actor,&$reason];
    expectFlow($autoReview->invokeArgs($workflow,$args)===null&&$reason==='STALE_TRANSCRIPT','automatic review refuses cached floor below refreshed activation');
    $autoDispatch=new \ReflectionMethod(WhatsAppOrderWorkflow::class,'dispatchInternal');$autoDispatch->setAccessible(true);
    blocked(fn()=>$autoDispatch->invoke($workflow,$id,$v,$actor,true),409,'automatic direct dispatch refuses historical READY cache');
    expectFlow(DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0,'stale direct dispatch has no ERP writes');

    // A floor migration must not clear another analysis holder, including a cached draft path.
    resetFlow();$c=fixtureThread();$cutover=(int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
    insertMessage($c,'leased-greeting','inbound','أهلا',4);$a=$workflow->analyze($c,$actor);
    $latest=(int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
    DB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['auto_floor'=>0,'analyzed_ceiling'=>0,
        'claimed_ceiling'=>$latest,'attempts'=>3,'next_attempt_at'=>now('UTC')->addHour(),
        'claim_nonce'=>str_repeat('a',64),'lease_until'=>now('UTC')->addMinutes(3)]);
    $before=(array)DB::table('whatsapp_order_scans')->where('conversation_id',$c)->first();
    app('config')->set('whatsapp_orders.activation_message_id',$cutover);app('config')->set('whatsapp_orders.mode','auto');
    $autoAnalyze=new \ReflectionMethod(WhatsAppOrderWorkflow::class,'analyzeInternal');$autoAnalyze->setAccessible(true);$calls=Ai::$calls;
    blocked(fn()=>$autoAnalyze->invoke($workflow,$c,$actor,false,true),409,'live holder blocks automatic cached reuse and cutoff reset');
    $workflow->process();
    expectFlow(Ai::$calls===$calls&&(array)DB::table('whatsapp_order_scans')->where('conversation_id',$c)->first()===$before,'live holder nonce lease attempts backoff and floor all preserved');
    expectFlow(DB::table('test_tickets')->count()===0,'leased historical cache never creates ticket');

    // Configuration or a stored effective floor changing during the provider call invalidates its result.
    foreach (['activation_message_id','activation_event_id','stored_auto_floor'] as $race) {
        resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.activation_event_id',0);app('config')->set('whatsapp_orders.mode','auto');
        $latest=(int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
        Ai::$callback=function()use($race,$latest,$c){
            if($race==='stored_auto_floor')DB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['auto_floor'=>$latest]);
            else app('config')->set('whatsapp_orders.'.$race,$race==='activation_message_id'?$latest:1);
        };
        $p=$workflow->process();
        expectFlow(Ai::$calls===1&&$p['errors']===1&&DB::table('whatsapp_order_drafts')->count()===0,'cutover race '.$race.' rejects provider persistence');
        expectFlow(DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0&&DB::table('whatsapp_order_scans')->first()->claim_nonce===null,'cutover race '.$race.' releases own lease without ERP writes');
    }
    app('config')->set('whatsapp_orders.activation_event_id',0);

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');$otherNonce=str_repeat('b',64);$holder=null;
    Ai::$callback=function()use($c,$otherNonce,&$holder){
        DB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['claim_nonce'=>$otherNonce,'lease_until'=>now('UTC')->addMinutes(3)]);
        $holder=(array)DB::table('whatsapp_order_scans')->where('conversation_id',$c)->first();
    };
    $p=$workflow->process();
    expectFlow($p['errors']===1&&DB::table('whatsapp_order_drafts')->count()===0&&(array)DB::table('whatsapp_order_scans')->first()===$holder,'lease-loss cleanup cannot clear replacement holder');

    // Successful historical receipts survive every cutoff change and remain replayable.
    resetFlow();$c=fixtureThread();[$id,$v]=prepared($workflow,$actor,$c);$workflow->dispatch($id,$v,$actor);
    $receipt=(array)DB::table('whatsapp_order_drafts')->where('id',$id)->first();
    insertMessage($c,'after-receipt-greeting','inbound','أهلا',4);Ai::$data=\App\Support\WhatsAppOrderExtraction::none();
    app('config')->set('whatsapp_orders.mode','auto');$p=$workflow->process();
    expectFlow(count(Ai::$lastTranscript)===1&&Ai::$lastTranscript[0]['text']==='أهلا','last dispatched receipt remains lower bound of automatic evidence');
    expectFlow((array)DB::table('whatsapp_order_drafts')->where('id',$id)->first()===$receipt&&DB::table('test_tickets')->count()===1,'cutover analysis retains dispatched receipt byte for byte');
    app('config')->set('whatsapp_orders.activation_message_id',(int)DB::table('whatsapp_inbox_messages')->max('id'));
    expectFlow($workflow->dispatch($id,$v,(object)['id'=>8])['replayed']===true&&DB::table('test_tickets')->count()===1,'historical dispatched receipt replay survives newer global cutoff');

    // Arrival bursts settle before analysis and reset the debounce when another message arrives.
    resetFlow(); \Carbon\Carbon::setTestNow('2026-10-10 02:00:00'); $c=fixtureThread();
    app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_cart.debounce_seconds',10);
    $p=$workflow->process(); expectFlow($p['analyzed']===0&&Ai::$calls===0,'new arrival deferred without API billing');
    \Carbon\Carbon::setTestNow('2026-10-10 02:00:09'); $p=$workflow->process(); expectFlow(Ai::$calls===0,'settlement window remains bounded');
    insertMessage($c,'burst-last','inbound','تمام',4);
    \Carbon\Carbon::setTestNow('2026-10-10 02:00:11'); $p=$workflow->process(); expectFlow(Ai::$calls===0,'latest arrival resets settlement');
    \Carbon\Carbon::setTestNow('2026-10-10 02:00:20'); $p=$workflow->process();
    expectFlow($p['dispatched']===1&&Ai::$calls===1,'settled burst analyzes and dispatches once');
    $workflow->process(); expectFlow(Ai::$calls===1&&DB::table('test_tickets')->count()===1,'idle poll never repeats billing or order');
    \Carbon\Carbon::setTestNow();

    // The real F catalog lets the operator choose weight/piece rather than fixing one mode.
    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\TakeawayCatalog::$mode='select'; $p=$workflow->process();
    expectFlow($p['dispatched']===1,'live select quantity mode accepts grounded weight choice');

    // An estimated total is evidence only; the unpaid ERP quote remains canonical.
    resetFlow(); $c=textAddressThread(); savedCustomer($actor); app('config')->set('whatsapp_orders.mode','auto');
    $before=(array)DB::table('branch_customers')->first(); BranchCustomers::$calls=0; $p=$workflow->process();
    expectFlow($p['dispatched']===1&&\App\Services\Dashboard\PosServicePhone::$calls>=2,'exact saved address reuses actual phone lookup automatically');
    expectFlow(BranchCustomers::$calls===0&&(array)DB::table('branch_customers')->first()===$before,'unchanged saved customer retains metadata');
    $sealed=json_decode(Crypt::decryptString(DB::table('whatsapp_order_drafts')->first()->sealed_payload),true);
    expectFlow($sealed['quote']['total']==='100.00'&&Ai::$data['approximate_total']==='99.00','ticket uses current ERP quote instead of estimate');
    resetFlow(); $c=textAddressThread('عنوان مختلف'); savedCustomer($actor); app('config')->set('whatsapp_orders.mode','auto'); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'changed text address cannot reuse old saved pin');
    resetFlow(); $c=fixtureThread(); savedCustomer($actor,'عنوان قديم',32.1,32.2); app('config')->set('whatsapp_orders.mode','auto'); $p=$workflow->process();
    $saved=DB::table('branch_customers')->first();
    expectFlow($p['dispatched']===1&&$saved->address==='شارع البحر'&&(int)$saved->revision===2&&round((float)$saved->latitude,2)===31.04,'fresh native pin updates existing customer through revisioned phone service');

    resetFlow(); $c=textAddressThread(); app('config')->set('whatsapp_orders.mode','auto');
    $history=DB::table('pos_service_tickets')->insertGetId(['branch'=>'f:1','channel'=>'phone','customer_phone'=>'01000000001','address'=>'شارع البحر','area'=>'المنصورة','delivery_notes'=>'',
        'status'=>'finished','payment_status'=>'paid','bill_issued_at'=>now('UTC')->subDay(),'delivery_snapshot'=>json_encode(['latitude'=>31.04,'longitude'=>31.37])]);
    \App\Services\Dashboard\PosServicePhone::$items=[['phone'=>'01000000001','address'=>'شارع البحر','latitude'=>31.04,'longitude'=>31.37,'last_ticket_id'=>$history]];
    $p=$workflow->process(); expectFlow($p['dispatched']===1&&DB::table('branch_customers')->count()===1,'closed prior phone ticket supplies exact historical customer pin');
    resetFlow(); $c=textAddressThread(); savedCustomer($actor); app('config')->set('whatsapp_orders.mode','auto');
    $history=DB::table('pos_service_tickets')->insertGetId(['branch'=>'f:1','channel'=>'phone','customer_phone'=>'01000000001','address'=>'شارع البحر','area'=>'المنصورة','delivery_notes'=>'',
        'status'=>'finished','payment_status'=>'paid','bill_issued_at'=>now('UTC')->subDay(),'delivery_snapshot'=>json_encode(['latitude'=>32.0,'longitude'=>32.0])]);
    \App\Services\Dashboard\PosServicePhone::$items=[['phone'=>'01000000001','address'=>'شارع البحر','latitude'=>32.0,'longitude'=>32.0,'last_ticket_id'=>$history]];
    $p=$workflow->process(); expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'conflicting saved and historical exact address coordinates require review');
    resetFlow(); $c=textAddressThread(); app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\PosServicePhone::$items=[['phone'=>'01000000001','address'=>'شارع البحر','latitude'=>31.04,'longitude'=>31.37]];
    $p=$workflow->process(); expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'unproven source lookup suggestion cannot become confirmed customer location');
    resetFlow(); $c=textAddressThread(); savedCustomer($actor); app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\PosServicePhone::$callback=function(){if(DB::connection()->transactionLevel()>0)DB::table('branch_customers')->update(['revision'=>2,'address'=>'عنوان أحدث']);};
    $p=$workflow->process();
    expectFlow($p['errors']===1&&DB::table('test_tickets')->count()===0,'pin source changing at locked dispatch gate blocks stale quote');

    // A killed/failed ERP write resumes the cached analysis after backoff, without another AI call.
    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto'); Pos::$failAfterWrite=true; $p=$workflow->process();
    expectFlow($p['errors']===1&&DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0,'pipeline rollback leaves no partial ERP writes');
    expectFlow(DB::table('whatsapp_order_drafts')->first()->status==='READY','prepared draft remains recoverable');
    Pos::$failAfterWrite=false; $workflow->process(); expectFlow(Ai::$calls===1,'dispatch backoff avoids hot retry and API billing');
    DB::table('whatsapp_order_scans')->update(['next_attempt_at'=>now('UTC')->subSecond()]); $p=$workflow->process();
    expectFlow($p['dispatched']===1&&Ai::$calls===1&&DB::table('test_tickets')->count()===1&&DB::table('test_kitchen')->count()===1,'cached confirmed draft resumes one unpaid order');

    // A grounded delivery area can select only the explicitly configured unique branch alias.
    resetFlow(); $c=fixtureThread(); Ai::$data['branch_hint']=null; Ai::$data['issues']=['MISSING_BRANCH'];
    app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_cart.branch_aliases',['1'=>['المنصورة']]); $p=$workflow->process();
    expectFlow($p['dispatched']===1&&DB::table('test_tickets')->first()->branch==='f:1','explicit area alias resolves omitted branch with an exactly approved fulfillment form');
    resetFlow(); $c=fixtureThread(); Ai::$data['branch_hint']=null; Ai::$data['issues']=['MISSING_BRANCH'];
    app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_orders.allowed_branch_ids',['1','2']);
    app('config')->set('whatsapp_cart.branch_aliases',['1'=>['المنصورة'],'2'=>['المنصورة']]); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'ambiguous area aliases never choose first branch');

    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_cart.debounce_seconds',10);
    DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->update(['created_at'=>now('UTC')->subSeconds(11)]);
    Ai::$callback=function()use($c){insertMessage($c,'arrives-during-ai','inbound','غير الطلب',4);}; $p=$workflow->process();
    expectFlow($p['errors']===1&&DB::table('test_tickets')->count()===0&&DB::table('whatsapp_order_drafts')->count()===0,'incoming change during provider call invalidates automatic result');

    // Arrival ids may be new while the actual message timestamps predate automatic activation.
    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto');
    app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:05'); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('whatsapp_order_drafts')->first()->reason==='STALE_TRANSCRIPT'&&DB::table('test_tickets')->count()===0,'late preactivation confirmation cannot dispatch through newer insertion ids');
    expectFlow((int)DB::table('whatsapp_order_scans')->first()->auto_floor===0,'late history guard never advances message floor');
    $draft=DB::table('whatsapp_order_drafts')->first(); $q=$workflow->quote((int)$draft->id,reviewData((int)$draft->revision),$actor);
    expectFlow($q['success']&&$q['draft']['status']==='READY','manual historical review remains available after automatic time cutover');

    resetFlow(); $c=addConversation(); app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:05');
    insertMessage($c,'old-commit','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1);
    insertMessage($c,'new-info-only','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة.',6);
    insertMessage($c,'later-pin','inbound','',7,['lat'=>31.04,'long'=>31.37]);
    insertMessage($c,'new-final-old-request','outbound','تأكيد طلب عمر: 1 كيلو فسيخ من المنصورة إلى شارع البحر، الإجمالي 100.00 جنيه.',8);
    Ai::$data=extractionData(); Ai::$data['evidence']['request_ids']=['m1','m2']; Ai::$data['evidence']['confirmation_ids']=['m4']; Ai::$data['evidence']['customer_acceptance_ids']=[]; Ai::$data['evidence']['location_id']='m3'; $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'new name-only followup cannot launder historical committing request');

    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto'); $p=$workflow->process();
    expectFlow($p['dispatched']===1&&WhatsAppOrderWorkflow::AUTOMATIC_ACTIVATION_TIME_GUARD==='whatsapp-auto-activation-time-v1','fresh commitment and confirmation pass explicit automatic time guard');
    resetFlow(); $c=addConversation(); app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:05');
    insertMessage($c,'old-request-fresh-accept','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1);
    insertMessage($c,'fresh-accept-pin','inbound','',7,['lat'=>31.04,'long'=>31.37]);
    insertMessage($c,'fresh-accept-final','outbound',finalOrderForm(extractionData()),8);
    insertMessage($c,'fresh-explicit-accept','inbound','تمام',9);
    Ai::$data=extractionData(); Ai::$data['evidence']['customer_acceptance_ids']=['m4']; $p=$workflow->process();
    expectFlow($p['dispatched']===1,'fresh explicit acceptance of fresh final summary counts as automatic commitment');
    foreach ([null,'2026-02-30 00:00:00','2026-10-09T22:00:00Z','0000-01-01 00:00:00',now('UTC')->addMinutes(2)->format('Y-m-d H:i:s')] as $invalid) {
        resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_cart.activated_at',$invalid); $p=$workflow->process();
        expectFlow($p['disabled']===1&&Ai::$calls===0&&DB::table('whatsapp_order_scans')->count()===0,'invalid automatic activation timestamp prevents provider call and dispatch');
    }
    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto');
    Ai::$callback=function(){app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:01');}; $p=$workflow->process();
    expectFlow($p['errors']===1&&DB::table('whatsapp_order_drafts')->count()===0&&DB::table('test_tickets')->count()===0,'activation timestamp changing during provider call invalidates claim');

    resetFlow(); $c=textAddressThread(); savedCustomer($actor); DB::table('branch_customers')->update(['area'=>'مدينة مبارك']);
    app('config')->set('whatsapp_orders.mode','auto'); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'changed explicit delivery area cannot reuse same text address pin');
    resetFlow(); $c=textAddressThread(); savedCustomer($actor); DB::table('branch_customers')->update(['delivery_notes'=>'تعليمات محفوظة']);
    Ai::$data['customer']['area']=null;replaceNativeText($c,'outbound',finalOrderForm(Ai::$data)); Ai::$data['issues'][]='MISSING_AREA'; app('config')->set('whatsapp_orders.mode','auto'); BranchCustomers::$calls=0; $p=$workflow->process();
    expectFlow($p['dispatched']===1&&BranchCustomers::$calls===0&&DB::table('branch_customers')->first()->delivery_notes==='تعليمات محفوظة','omitted area and notes preserve authoritative unchanged saved source');

    // The current consent gate requires explicit preparation in both extraction and approved form.
    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\TakeawayCatalog::$options=[['id'=>'clean-extra','label'=>'تنظيف إضافي']];
    $summary=DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->where('direction','outbound')->first();
    $dto=json_decode(Crypt::decryptString($summary->content),true); $dto['text']=str_replace('1 كيلو فسيخ','1 كيلو فسيخ تنظيف إضافي',$dto['text']);
    $dto['content']['message']['text']['body']=$dto['text']; DB::table('whatsapp_inbox_messages')->where('id',$summary->id)->update(['content'=>Crypt::encryptString(json_encode($dto))]); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'omitted preparation hint cannot authorize an incomplete final form');

    resetFlow();$c=fixtureThread('', 'تنظيف إضافي');app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\TakeawayCatalog::$options=[['id'=>'clean-extra','label'=>'تنظيف إضافي']];$p=$workflow->process();
    expectFlow($p['dispatched']===1,'explicit grounded preparation reaches automatic dispatch');
    $sealed=json_decode(Crypt::decryptString(DB::table('whatsapp_order_drafts')->first()->sealed_payload),true);
    expectFlow($p['dispatched']===1&&$sealed['review']['items'][0]['option_id']==='clean-extra','explicit customer preparation and matching final form dispatch the current catalog option');

    resetFlow(); $c=cartThread(); mappedCart(); app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\TakeawayCatalog::$options=[['id'=>'clean-extra','label'=>'تنظيف إضافي']];
    $summary=DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->where('direction','outbound')->first();
    $dto=json_decode(Crypt::decryptString($summary->content),true); $dto['text']=str_replace('1 كيلو فسيخ','1 كيلو فسيخ تنظيف إضافي',$dto['text']);
    $dto['content']['message']['text']['body']=$dto['text']; DB::table('whatsapp_inbox_messages')->where('id',$summary->id)->update(['content'=>Crypt::encryptString(json_encode($dto))]); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'base cart mapping cannot drop a positive final cleaning choice');
    resetFlow(); $c=cartThread(); mappedCart(); app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\TakeawayCatalog::$options=[['id'=>'clean-extra','label'=>'تنظيف إضافي']];
    app('config')->set('whatsapp_cart.product_mappings.999.half-fish.f:1.option_id','clean-extra');
    $summary=DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->where('direction','outbound')->first();
    $dto=json_decode(Crypt::decryptString($summary->content),true); $dto['text']=str_replace('1 كيلو فسيخ','1 كيلو فسيخ تنظيف إضافي',$dto['text']);
    $dto['content']['message']['text']['body']=$dto['text']; DB::table('whatsapp_inbox_messages')->where('id',$summary->id)->update(['content'=>Crypt::encryptString(json_encode($dto))]); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'cart mapping cannot substitute for explicit preparation in the approved form');

    resetFlow();$c=cartThread('تنظيف إضافي');mappedCart();app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\TakeawayCatalog::$options=[['id'=>'clean-extra','label'=>'تنظيف إضافي']];
    app('config')->set('whatsapp_cart.product_mappings.999.half-fish.f:1.option_id','clean-extra');$p=$workflow->process();
    expectFlow($p['dispatched']===1,'explicit customer preparation and matching catalog cart mapping dispatch together');

    // Cart identities and portions are resolved from explicit mappings, never numeric retailer ids.
    resetFlow(); $c=cartThread(); mappedCart(); app('config')->set('whatsapp_orders.mode','auto'); $p=$workflow->process();
    expectFlow($p['dispatched']===1&&Ai::$lastTranscript[1]['cart']['product_items'][0]['quantity']==='2','submitted cart reaches automatic analysis and canonical ERP product');
    $sealed=json_decode(Crypt::decryptString(DB::table('whatsapp_order_drafts')->first()->sealed_payload),true);
    expectFlow($sealed['review']['items'][0]['product_id']===11&&$sealed['review']['items'][0]['quantity']==='1','verified two half-kilo units create one grounded kilo');
    resetFlow(); $c=cartThread(); app('config')->set('whatsapp_orders.mode','auto'); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('whatsapp_order_drafts')->first()->reason==='CATALOG_UNRESOLVED'&&DB::table('test_tickets')->count()===0,'unmapped cart remains review without product guesses');
    resetFlow(); $c=cartThread(); mappedCart(); app('config')->set('whatsapp_orders.mode','auto');
    app('config')->set('whatsapp_cart.product_mappings.999.half-fish.f:1.quantity_per_unit','1'); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'cart portion mismatch blocks automatic order');
    resetFlow(); $c=cartThread(); mappedCart(); app('config')->set('whatsapp_orders.mode','auto');
    app('config')->set('whatsapp_cart.product_mappings.999.half-fish.f:1.product_id',999); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'foreign ERP product mapping rejected');
    resetFlow(); $c=cartThread(); mappedCart(); app('config')->set('whatsapp_orders.mode','auto');
    $order=DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->where('type','order')->first();
    $dto=json_decode(Crypt::decryptString($order->content),true); $dto['content']['message']['order']['product_items'][]=
        ['product_retailer_id'=>'second-item','quantity'=>1,'item_price'=>'10.00','currency'=>'EGP'];
    DB::table('whatsapp_inbox_messages')->where('id',$order->id)->update(['content'=>Crypt::encryptString(json_encode($dto))]); $p=$workflow->process();
    expectFlow($p['dispatched']===0&&DB::table('test_tickets')->count()===0,'partial cart extraction never dispatches only one submitted line');
    resetFlow(); $c=cartThread(); mappedCart(); app('config')->set('whatsapp_orders.mode','auto');
    $order=DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->where('type','order')->first();
    $dto=json_decode(Crypt::decryptString($order->content),true); $dto['content']['message']['order']['product_items'][0]['currency']='USD';
    DB::table('whatsapp_inbox_messages')->where('id',$order->id)->update(['content'=>Crypt::encryptString(json_encode($dto))]); $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'foreign currency cart cannot silently convert to ERP order');
    resetFlow(); $c=cartThread(); mappedCart(); app('config')->set('whatsapp_orders.mode','auto');
    insertCartMessage($c,'later-cart-same-second',4,[['product_retailer_id'=>'half-fish','quantity'=>3,'item_price'=>'45.00','currency'=>'EGP']]); $p=$workflow->process();
    expectFlow($p['dispatched']===0&&DB::table('test_tickets')->count()===0,'later same-second cart invalidates earlier confirmation');

    // Cairo store origins are separate from the physical branch that owns customers,
    // menu prices, unpaid tickets, kitchen receipts and branch printing.
    Schema::create('resturants',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->unsignedInteger('parent_id')->nullable();
        $t->string('name');$t->string('control');$t->string('lat');$t->string('lng');$t->string('km_price');});
    function originSetup(): void {
        resetFlow();DB::table('resturants')->delete();DB::table('test_branches')->where('id','>=',301)->delete();
        DB::table('test_users')->where('id',7)->update(['branches'=>'["f:1","f:2","f:301","f:309","f:311"]']);
        DB::table('test_branches')->insert([['id'=>301,'name'=>'فرع شبرا','status'=>'opened'],['id'=>309,'name'=>'المقطم ستور','status'=>'closed'],['id'=>311,'name'=>'التجمع ستور','status'=>'closed']]);
        DB::table('resturants')->insert([['id'=>301,'parent_id'=>null,'name'=>'فرع شبرا','control'=>'show','lat'=>'30.10','lng'=>'31.20','km_price'=>'10.00'],
            ['id'=>309,'parent_id'=>null,'name'=>'المقطم ستور','control'=>'hide','lat'=>'30.01','lng'=>'31.30','km_price'=>'11.00'],
            ['id'=>311,'parent_id'=>null,'name'=>'التجمع ستور','control'=>'hide','lat'=>'30.02','lng'=>'31.50','km_price'=>'12.00']]);
        app('config')->set('whatsapp_orders.allowed_branch_ids',['1','301']);app('config')->set('whatsapp_orders.mode','auto');
        app('config')->set('whatsapp_cart.branch_aliases',['301'=>['شبرا'],'309'=>['المقطم'],'311'=>['التجمع']]);
    }
    function originThread(string $hint='المقطم ستور',string $area='المقطم'): int {
        $c=addConversation();
        insertMessage($c,'origin-request','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في '.$area.'. عايز 1 كيلو فسيخ من '.$hint.'.',1);
        insertMessage($c,'origin-pin','inbound','',2,['lat'=>30.03,'long'=>31.32]);
        Ai::$data=extractionData();Ai::$data['branch_hint']=$hint;Ai::$data['customer']['area']=$area;
        $display=Ai::$data;$display['branch_hint']=$hint==='فرع المحلة'?'فرع المحلة':'فرع شبرا';
        insertMessage($c,'origin-summary','outbound',finalOrderForm($display),3);
        insertMessage($c,'origin-approval','inbound','تمام',4);return $c;
    }
    originSetup();$c=originThread();$p=$workflow->process();
    expectFlow($p['dispatched']===1&&$p['errors']===0,'hidden closed registered Cairo billing origin dispatches through open Shubra');
    expectFlow(DB::table('resturants')->where('id',309)->value('parent_id')===null,'explicit Cairo delivery policy does not rewrite ownership hierarchy');
    expectFlow(DB::table('resturants')->where('id',309)->value('control')==='hide'
        &&DB::table('test_branches')->where('id',309)->value('status')==='closed','routing does not change storefront visibility or status');
    $draft=DB::table('whatsapp_order_drafts')->first();$sealed=json_decode(Crypt::decryptString($draft->sealed_payload),true);
    expectFlow($sealed['review']['branch']==='f:301'&&$sealed['review']['delivery_origin_branch']==='f:309','store origin and fulfillment remain separately sealed');
    expectFlow($sealed['delivery']['delivery_origin_branch']==='f:309'&&$sealed['delivery']['fulfillment_branch']==='f:301','delivery quote records the store origin');
    expectFlow(DB::table('branch_customers')->first()->branch==='f:301'&&DB::table('test_tickets')->first()->branch==='f:301'
        &&DB::table('test_print')->first()->branch==='f:301'&&DB::table('test_kitchen')->count()===1,'customer ticket kitchen and printer all belong to Shubra');
    expectFlow(Pos::$lastPayload['delivery_origin_branch']==='f:309'&&Pos::$lastPayload['send_to_kitchen']===true,'origin survives the final ERP payload');
    $review=$sealed['review'];$review['expected_revision']=(int)$draft->revision;
    expectFlow($workflow->dispatch((int)$draft->id,$review,$actor)['replayed']===true&&DB::table('test_tickets')->count()===1,'origin receipt replay creates no duplicate order');
    $omitted=$review;unset($omitted['delivery_origin_branch']);
    expectFlow($workflow->dispatch((int)$draft->id,$omitted,$actor)['replayed']===true&&DB::table('test_tickets')->count()===1,'legacy form omission preserves the trusted dispatched origin');
    $nullOrigin=$review;$nullOrigin['delivery_origin_branch']=null;
    blocked(fn()=>$workflow->dispatch((int)$draft->id,$nullOrigin,$actor),409,'explicit origin removal cannot bypass the sealed confirmation');
    $changed=$review;$changed['delivery_origin_branch']='f:311';
    blocked(fn()=>$workflow->dispatch((int)$draft->id,$changed,$actor),409,'another origin cannot reuse the dispatched confirmation');
    $workflow->process();expectFlow(Ai::$calls===1&&DB::table('test_tickets')->count()===1,'pipeline replay preserves one confirmed order and one AI call');

    originSetup();$c=originThread();Ai::$data['branch_hint']=null;Ai::$data['issues']=['MISSING_BRANCH'];$p=$workflow->process();
    expectFlow($p['dispatched']===1&&Pos::$lastPayload['delivery_origin_branch']==='f:309','literal approved area alias resolves origin with an exactly approved fulfillment form');
    originSetup();$c=originThread();Ai::$data['branch_hint']=null;Ai::$data['issues']=['MISSING_BRANCH'];
    app('config')->set('whatsapp_cart.branch_aliases',[]);$p=$workflow->process();
    expectFlow($p['dispatched']===1&&Pos::$lastPayload['delivery_origin_branch']==='f:309','whole public store label resolves without geographic guessing and dispatches exact approved form');
    originSetup();$c=originThread();Ai::$data['branch_hint']=null;Ai::$data['issues']=['MISSING_BRANCH'];
    app('config')->set('whatsapp_cart.branch_aliases',['311'=>['المقطم']]);$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'duplicate stripped public labels never choose a store');
    originSetup();$c=originThread();app('config')->set('whatsapp_orders.allowed_branch_ids',['1','301','309']);$p=$workflow->process();
    expectFlow($p['dispatched']===1&&Pos::$lastPayload['branch']==='f:301'&&Pos::$lastPayload['delivery_origin_branch']==='f:309','a listed child store still cannot become its own fulfillment branch');
    originSetup();DB::table('test_branches')->insert(['id'=>94,'name'=>'فرع المحلة','status'=>'opened']);
    DB::table('resturants')->insert(['id'=>94,'parent_id'=>301,'name'=>'فرع المحلة','control'=>'show','lat'=>'30.97','lng'=>'31.16','km_price'=>'10.00']);
    DB::table('test_users')->where('id',7)->update(['branches'=>'["f:1","f:2","f:94","f:301","f:309","f:311"]']);
    app('config')->set('whatsapp_orders.allowed_branch_ids',['94','301']);app('config')->set('whatsapp_cart.branch_aliases',['94'=>['المحلة']]);
    $c=originThread('فرع المحلة','المحلة');$p=$workflow->process();
    expectFlow($p['dispatched']===1&&Pos::$lastPayload['branch']==='f:94'&&!isset(Pos::$lastPayload['delivery_origin_branch']),
        'approved physical branch94 keeps its own fulfillment despite parent301 ownership');
    expectFlow(DB::table('branch_customers')->first()->branch==='f:94'&&DB::table('test_print')->first()->branch==='f:94',
        'physical ownership hierarchy cannot reroute its customer or printer to Shubra');
    blocked(fn()=>app(\App\Services\Dashboard\FasakhanstaDeliveryOrigin::class)->check('f:94','f:301',$actor),422,
        'physical branch94 is never a Cairo store origin');
    originSetup();$c=originThread('المقطم ستور','التجمع');$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'conflicting exact store and area never choose one origin');
    originSetup();$c=originThread();app('config')->set('whatsapp_cart.branch_aliases.311',['المقطم ستور']);$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'duplicate exact public aliases require review');
    originSetup();$c=originThread();DB::table('resturants')->where('id',309)->update(['parent_id'=>363]);$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'store reparented away from Shubra cannot automatically route');
    originSetup();$c=originThread();DB::table('resturants')->where('id',309)->update(['parent_id'=>0]);$p=$workflow->process();
    expectFlow($p['dispatched']===1&&Pos::$lastPayload['delivery_origin_branch']==='f:309','registered zero-parent origin uses explicit policy without a hierarchy mutation');
    originSetup();$c=originThread();DB::table('resturants')->where('id',309)->update(['name'=>'متجر أعيدت تسميته']);$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'registered ID with mismatched live public name cannot automatically route');
    originSetup();DB::table('test_branches')->insert(['id'=>500,'name'=>'رأس البر','status'=>'opened']);
    DB::table('resturants')->insert(['id'=>500,'parent_id'=>301,'name'=>'رأس البر','control'=>'show','lat'=>'31.51','lng'=>'31.82','km_price'=>'10.00']);
    DB::table('test_users')->where('id',7)->update(['branches'=>'["f:1","f:301","f:309","f:311","f:500"]']);
    app('config')->set('whatsapp_orders.allowed_branch_ids',['301','500']);$c=originThread('رأس البر','رأس البر');$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'unknown future child301 is not classified as a Cairo origin or fulfillment');
    blocked(fn()=>app(\App\Services\Dashboard\FasakhanstaDeliveryOrigin::class)->check('f:500','f:301',$actor),422,
        'parent301 alone cannot authorize an unknown future origin');
    originSetup();$c=originThread();DB::table('resturants')->where('id',309)->update(['control'=>'show']);$p=$workflow->process();
    expectFlow($p['dispatched']===1&&Pos::$lastPayload['branch']==='f:301'&&Pos::$lastPayload['delivery_origin_branch']==='f:309','closed visible billing origin keeps delivery routing to open Shubra');
    originSetup();$c=originThread();DB::table('test_branches')->where('id',301)->update(['status'=>'closed']);$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('whatsapp_order_drafts')->first()->reason==='BRANCH_CLOSED'&&DB::table('test_tickets')->count()===0,'closed physical fulfillment branch cannot automatically dispatch');
    expectFlow(DB::table('branch_customers')->count()===0&&DB::table('test_print')->count()===0,'closed Shubra creates no customer or print request for a hidden closed origin');
    originSetup();$c=originThread();DB::table('test_branches')->where('id',309)->update(['status'=>'opened']);$p=$workflow->process();
    expectFlow($p['dispatched']===1&&Pos::$lastPayload['delivery_origin_branch']==='f:309','hidden open billing origin keeps delivery routing to open Shubra');
    originSetup();$c=originThread();DB::table('test_users')->where('id',7)->update(['can_checkout'=>0]);$p=$workflow->process();
    expectFlow($p['disabled']===1&&Ai::$calls===0&&DB::table('branch_customers')->count()===0&&DB::table('test_tickets')->count()===0,'hidden closed origin never bypasses physical checkout permission');
    originSetup();$c=originThread();app('config')->set('whatsapp_orders.allowed_branch_ids',['1']);$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'origin route needs authorized allowed Shubra fulfillment');
    originSetup();$c=originThread();DB::table('test_users')->where('id',7)->update(['branches'=>'["f:1","f:301","f:311"]']);$p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'unauthorized source store cannot be selected by the automatic actor');

    originSetup();$c=originThread();PhoneDelivery::$callback=function(){DB::table('resturants')->where('id',309)->update(['parent_id'=>94]);};$p=$workflow->process();
    expectFlow($p['errors']===1&&DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0,'origin parent changing from live null to94 between quote and locked sealing blocks all ERP writes');
    originSetup();$c=originThread();PhoneDelivery::$callback=function(){DB::table('test_branches')->where('id',301)->update(['status'=>'closed']);};$p=$workflow->process();
    expectFlow($p['errors']===1&&DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0
        &&DB::table('test_print')->count()===0,'Shubra closing after quote is rechecked before locked automatic dispatch');
    originSetup();$c=originThread();app('config')->set('whatsapp_orders.mode','review');$a=$workflow->analyze($c,$actor);
    $v=reviewData($a['draft']['revision']);$v['branch']='f:301';$v['delivery_origin_branch']='f:309';$v['area']='المقطم';
    $q=$workflow->quote($a['draft']['id'],$v,$actor);$v=$q['draft']['review'];$v['expected_revision']=$q['draft']['revision'];
    $omitted=$v;unset($omitted['delivery_origin_branch']);$requote=$workflow->quote($q['draft']['id'],$omitted,$actor);
    expectFlow($requote['draft']['review']['delivery_origin_branch']==='f:309','legacy form requote preserves the trusted origin for unchanged fulfillment');
    $v=$requote['draft']['review'];$v['expected_revision']=$requote['draft']['revision'];
    DB::table('resturants')->where('id',309)->update(['km_price'=>'99.00']);
    blocked(fn()=>$workflow->dispatch($q['draft']['id'],$v,$actor),409,'origin rate drift invalidates the canonical delivery quote');
    expectFlow(DB::table('branch_customers')->count()===0&&DB::table('test_tickets')->count()===0,'changed origin quote never partially saves customer or ticket');
    DB::table('resturants')->where('id',309)->update(['parent_id'=>363]);
    blocked(fn()=>$workflow->dispatch($q['draft']['id'],$v,$actor),422,'locked dispatch refuses reparented origin');
    $v['delivery_origin_branch']='gs:309';
    try { $workflow->quote($q['draft']['id'],$v,$actor);throw new \RuntimeException('GO_ORIGIN_NOT_REJECTED'); }
    catch (\Illuminate\Validation\ValidationException $error) {
        expectFlow($error->validator->errors()->has('delivery_origin_branch'),'GO store identity is not a Fasakhansta origin');
    }

    originSetup();$c=originThread();Pos::$failAfterWrite=true;$p=$workflow->process();
    expectFlow($p['errors']===1&&DB::table('test_tickets')->count()===0&&DB::table('whatsapp_order_drafts')->first()->status==='READY','failed origin dispatch retains only its prepared review');
    Pos::$failAfterWrite=false;DB::table('whatsapp_order_scans')->update(['next_attempt_at'=>now('UTC')->subSecond()]);$p=$workflow->process();
    expectFlow($p['dispatched']===1&&Ai::$calls===1&&DB::table('test_tickets')->count()===1&&Pos::$lastPayload['delivery_origin_branch']==='f:309','cached store review resumes once with its origin and no repeated AI extraction');

    originSetup();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','review');$a=$workflow->analyze($c,$actor);
    $v=reviewData($a['draft']['revision']);$v['delivery_origin_branch']='f:1';$q=$workflow->quote($a['draft']['id'],$v,$actor);
    expectFlow(!array_key_exists('delivery_origin_branch',$q['draft']['review']),'same-branch origin retains the legacy sealed review shape');
    $v=$q['draft']['review'];$v['expected_revision']=$q['draft']['revision'];$sent=$workflow->dispatch($q['draft']['id'],$v,$actor);
    expectFlow($sent['ticket_id']>0&&!array_key_exists('delivery_origin_branch',Pos::$lastPayload),'ordinary branches preserve the original ERP payload contract');

    // A manual click in an active automatic session uses the same fresh lower bound.
    // Historical probe text must not turn a fresh confirmed order into NONE/TEST_MESSAGE.
    resetFlow(); $c=addConversation();
    insertMessage($c,'old-probe','inbound','WA-0101',-86400); $cutover=(int)DB::table('whatsapp_inbox_messages')->max('id');
    insertMessage($c,'new-manual-request','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1);
    insertMessage($c,'new-manual-pin','inbound','',2,['lat'=>31.04,'long'=>31.37]);
    insertMessage($c,'new-manual-confirm','outbound',finalOrderForm(extractionData()),3);
    insertMessage($c,'new-manual-approval','inbound','تمام',4);
    Ai::$data=\App\Support\WhatsAppOrderExtraction::none('TEST_MESSAGE'); $a=$workflow->analyze($c,$actor);
    $old=DB::table('whatsapp_order_drafts')->where('id',$a['draft']['id'])->first();
    expectFlow($old->status==='NONE'&&(int)$old->evidence_floor===0&&count(Ai::$lastTranscript)===5,'historical review preserves explicit probe rejection');
    $rawBefore=DB::table('whatsapp_inbox_messages')->orderBy('id')->get()->toJson();
    app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_orders.activation_message_id',$cutover);
    DB::table('whatsapp_order_scans')->update(['auto_floor'=>$cutover,'attempts'=>3,'next_attempt_at'=>now('UTC')->addHour()]);
    Ai::$data=extractionData(); $b=$workflow->analyze($c,$actor);
    $fresh=DB::table('whatsapp_order_drafts')->where('id',$old->id)->first();
    expectFlow($b['draft']['status']==='REVIEW'&&$b['draft']['data']['decision']==='CONFIRMED'&&Ai::$calls===2,'manual active session recomputes cached old NONE without forced click');
    expectFlow((int)$fresh->evidence_floor===$cutover&&(int)$fresh->revision===2&&count(Ai::$lastTranscript)===4
        &&!\App\Support\WhatsAppOrderExtraction::hasProbe(Ai::$lastTranscript),'fresh manual slice excludes historical test marker');
    expectFlow($fresh->ticket_command_key===$old->ticket_command_key&&$fresh->customer_command_key===$old->customer_command_key
        &&DB::table('whatsapp_inbox_messages')->orderBy('id')->get()->toJson()===$rawBefore,'manual reanalysis preserves durable command keys and source messages');
    expectFlow(DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0,'manual analysis performs no ERP write');
    $p=$workflow->process(); expectFlow($p['dispatched']===1&&Ai::$calls===2&&DB::table('test_tickets')->count()===1,'same fresh manual confirmation dispatches once without repeat AI');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');$latest=(int)DB::table('whatsapp_inbox_messages')->max('id');
    app('config')->set('whatsapp_orders.activation_message_id',$latest);
    blocked(fn()=>$workflow->analyze($c,$actor,true),409,'manual force cannot bypass current activation floor');
    expectFlow(Ai::$calls===0&&DB::table('whatsapp_order_drafts')->count()===0,'historical-only forced analysis spends no AI in active auto mode');

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');
    Ai::$callback=function(){app('config')->set('whatsapp_orders.activation_event_id',1);};
    blocked(fn()=>$workflow->analyze($c,$actor),409,'manual active-session extraction still checks cutover CAS');
    expectFlow(DB::table('whatsapp_order_drafts')->count()===0&&DB::table('whatsapp_order_scans')->first()->claim_nonce===null,'manual cutover race releases only its lease');
    app('config')->set('whatsapp_orders.activation_event_id',0);

    // Provider failures are durable fixed diagnostics, never counterfeit extracted orders.
    resetFlow();$c=fixtureThread();Ai::$failure=true;Ai::$failureReason='INCOMPLETE';
    Ai::$failureDiagnostic=['stage'=>'RESPONSE','http_status'=>200,'transport_category'=>'NONE','curl_errno'=>0,
        'elapsed_ms'=>60001,'response_status'=>'incomplete','incomplete_reason'=>'max_output_tokens',
        'input_tokens'=>300,'output_tokens'=>8192,'reasoning_tokens'=>8000,'raw_response'=>'secret-provider-response'];
    $a=$workflow->analyze($c,$actor); $stored=DB::table('whatsapp_order_drafts')->first();
    $failure=json_decode(Crypt::decryptString($stored->extraction),true)['analysis_failure'];
    expectFlow($a['draft']['reason']==='AI_UNAVAILABLE'&&$a['draft']['data']===null&&$failure===$a['draft']['analysis_failure'],'encrypted provider failure is exposed separately from extraction');
    expectFlow($failure['reason']==='INCOMPLETE'&&$failure['diagnostic']['output_tokens']===8192
        &&$failure['diagnostic']['incomplete_reason']==='max_output_tokens'&&!isset($failure['diagnostic']['raw_response']),'safe diagnostics retain budget cause without response prose');
    expectFlow(strpos($stored->extraction,'max_output_tokens')===false&&strpos(json_encode($a),'secret-provider-response')===false,'provider diagnostics remain encrypted and redact unknown fields');
    $failureMethod=new \ReflectionMethod(WhatsAppOrderWorkflow::class,'safeAnalysisFailure');$failureMethod->setAccessible(true);
    $unsafe=$failureMethod->invoke($workflow,['reason'=>'secret','diagnostic'=>['stage'=>'SECRET','http_status'=>'200','curl_errno'=>-1,
        'input_tokens'=>1000000001,'response_status'=>'provider-secret','incomplete_reason'=>'provider-secret','raw_response'=>'secret']]);
    expectFlow($unsafe['reason']==='UNKNOWN'&&array_filter($unsafe['diagnostic'],fn($v)=>$v!==null)===[],'failure sanitizer rejects arbitrary strings numeric coercion and unbounded counts');
    $state=$workflow->state($c,$actor);expectFlow($state['drafts'][0]['analysis_failure']===$failure&&Ai::$calls===1,'read-only state preserves safe failure details without retry');

    // Real encrypted ACCEPTED receipts and projection schema, with no fake native echo.
    require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppInboxMessage.php';
    require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php';
    require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php';
    require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php';
    require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppInboxConsumer.php';
    require dirname(__DIR__).'/database/migrations/2026_10_10_000003_create_whatsapp_reply_requests.php';
    require dirname(__DIR__).'/database/migrations/2026_10_12_000005_create_whatsapp_inbox_hidden_messages.php';
    (new \CreateWhatsAppReplyRequests)->up(); (new \CreateWhatsAppInboxHiddenMessages)->up();
    Schema::table('whatsapp_webhook_events',function(Blueprint $t){$t->string('payload_hash',64)->nullable();$t->longText('payload')->nullable();$t->timestamp('received_at')->nullable();});
    // Receipt-backed cases use the production coordinator and its authenticated
    // native customer-tail checks, with the real projection-key schema above.
    $workflow=new WhatsAppOrderWorkflow;
    function apiRequestThread(string $suffix=''): array {
        $peer='US.fixture'.$suffix;$c=addConversation($peer);
        $request=insertMessage($c,'wamid.api-request'.$suffix,'inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1,null,$peer);
        insertMessage($c,'wamid.api-pin'.$suffix,'inbound','',2,['lat'=>31.04,'long'=>31.37],$peer);
        return [$c,$request];
    }
    function acceptedApiReply(int $c,int $request,string $id='wamid.api-confirm',int $second=3,?string $text=null): int {
        $uuid='00000000-0000-4000-8000-'.str_pad((string)(DB::table('whatsapp_reply_requests')->max('id')+1),12,'0',STR_PAD_LEFT);
        $text=$text??finalOrderForm(extractionData());
        $values=['text',$request,$text,null];
        $audit=['client_request_id'=>$uuid,'text'=>$text,'recipient'=>'201000000001','payload_values'=>$values];
        $response=Crypt::encryptString(json_encode(['message_id'=>$id]));
        $at=gmdate('Y-m-d H:i:s',strtotime('2026-10-09 22:00:00 UTC')+$second);
        $r=DB::table('whatsapp_reply_requests')->insertGetId(['conversation_id'=>$c,'actor_id'=>7,'waba_id'=>'468336579702269','phone_number_id'=>'515388018324075',
            'request_hash'=>hash_hmac('sha256','whatsapp-reply-request-v1:7:'.$c.':'.$uuid,Crypt::getKey()),
            'payload_hash'=>hash_hmac('sha256','whatsapp-reply-payload-v1:'.json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),Crypt::getKey()),
            'inbound_message_id'=>$request,'kind'=>'text','state'=>'ACCEPTED','audit'=>Crypt::encryptString(json_encode($audit)),
            'response_details'=>$response,'remote_message_key'=>hash('sha256','whatsapp-reply-message-v1:468336579702269:515388018324075:'.$id),
            'claimed_at'=>'2026-10-09 22:00:02','send_started_at'=>$at,'completed_at'=>$at,'created_at'=>$at,'updated_at'=>$at]);
        expectFlow((new \App\Services\Dashboard\WhatsAppInboxMessage)->indexReply(DB::table('whatsapp_reply_requests')->where('id',$r)->first()),'actual API native-key index');
        return $r;
    }
    resetFlow();[$c,$request]=apiRequestThread();acceptedApiReply($c,$request);
    app('config')->set('whatsapp_orders.mode','auto');Ai::$data['evidence']['customer_acceptance_ids']=[];$unapproved=$workflow->process();
    expectFlow($unapproved['dispatched']===0&&$unapproved['review_required']===1&&Ai::$calls===1
        &&DB::table('whatsapp_order_drafts')->first()->reason==='INVALID_EXTRACTION'
        &&DB::table('whatsapp_inbox_messages')->where('source','dashboard_api')->count()===1,
        'authentic complete API form without customer approval cannot authorize extracted CONFIRMED order');
    expectFlow(DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0&&DB::table('test_print')->count()===0,
        'unapproved authenticated API form creates no ERP customer ticket or print');

    resetFlow();[$c,$request]=apiRequestThread();app('config')->set('whatsapp_orders.mode','auto');
    Ai::$data=\App\Support\WhatsAppOrderExtraction::none();$before=$workflow->process();$ceiling=(int)DB::table('whatsapp_inbox_messages')->max('id');
    expectFlow($before['dispatched']===0&&DB::table('whatsapp_order_drafts')->first()->status==='NONE','preconfirmation ceiling contains no finalized order');
    $receipt=acceptedApiReply($c,$request);
    insertMessage($c,'wamid.api-approval','inbound','تمام',4);
    Ai::$data=extractionData();$p=$workflow->process();
    $projected=DB::table('whatsapp_inbox_messages')->where('source','dashboard_api')->first();
    $draft=DB::table('whatsapp_order_drafts')->where('status','DISPATCHED')->first();
    expectFlow($p['dispatched']===1&&Ai::$calls===2&&(int)$projected->id>$ceiling&&count(Ai::$lastTranscript)===4,'accepted API confirmation advances existing cursor and wakes automatic analysis');
    expectFlow(Ai::$lastTranscript[2]['speaker']==='business'&&Ai::$lastTranscript[2]['text']===finalOrderForm(extractionData())
        &&Ai::$lastTranscript[3]['speaker']==='customer'&&Ai::$lastTranscript[3]['text']==='تمام','receipt contributes validated business form followed by separate customer approval');
    $envelope=json_decode(Crypt::decryptString($projected->content),true);
    expectFlow($envelope['schema']==='whatsapp-accepted-reply-evidence-v1'&&!isset($envelope['content']['message']),'receipt evidence retains honest provenance without invented native payload');
    expectFlow(DB::table('test_tickets')->count()===1&&DB::table('test_print')->count()===1&&$draft->confirmation_key!==null,'accepted API order creates one ticket and one print');
    $originalHash=$draft->evidence_hash;$originalKey=$draft->confirmation_key;$originalRow=(array)$projected;
    $message=['id'=>'wamid.api-confirm','timestamp'=>(string)strtotime('2026-10-09 22:00:03 UTC'),'type'=>'text',
        'from'=>'201285545554','to'=>'201000000001','to_user_id'=>'US.fixture','text'=>['body'=>Ai::$lastTranscript[2]['text']]];
    $payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>'468336579702269','changes'=>[['field'=>'smb_message_echoes','value'=>[
        'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075'],'message_echoes'=>[$message]]]]]]];
    $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $event=DB::table('whatsapp_webhook_events')->insertGetId(['payload_hash'=>hash('sha256',$json),'payload'=>Crypt::encryptString($json),'received_at'=>now('UTC'),'processed_at'=>null]);
    $echo=(new \App\Services\Dashboard\WhatsAppInboxConsumer)->consume(1);$after=$workflow->process();
    $snapshotMethod=new \ReflectionMethod(WhatsAppOrderWorkflow::class,'snapshot');$snapshotMethod->setAccessible(true);
    $confirmationMethod=new \ReflectionMethod(WhatsAppOrderWorkflow::class,'confirmationKey');$confirmationMethod->setAccessible(true);
    $snap=$snapshotMethod->invoke($workflow,$c,(int)$draft->evidence_floor,(int)$draft->evidence_ceiling);
    expectFlow($echo['messages_replayed']===1&&$echo['messages_inserted']===0&&$echo['quarantined_events']===0,'actual native echo replays the accepted API canonical message');
    expectFlow((array)DB::table('whatsapp_inbox_messages')->where('id',$projected->id)->first()===$originalRow
        &&$snap['hash']===$originalHash&&$confirmationMethod->invoke($workflow,$draft)===$originalKey,'echo preserves receipt timestamp source hash and shared confirmation key');
    expectFlow(DB::table('test_tickets')->count()===1&&Ai::$calls===2&&$after['dispatched']===0,'late echo produces no duplicate ERP order or AI extraction');

    resetFlow();[$c,$request]=apiRequestThread();acceptedApiReply($c,$request);
    insertMessage($c,'wamid.api-manual-approval','inbound','تمام',4);$a=$workflow->analyze($c,$actor);
    expectFlow($a['draft']['data']['decision']==='CONFIRMED'&&count(Ai::$lastTranscript)===4,'manual analysis also includes accepted API confirmation and customer approval');
    $projection=DB::table('whatsapp_inbox_messages')->where('source','dashboard_api')->first();
    $oldAudit=DB::table('whatsapp_reply_requests')->first()->audit;$audit=json_decode(Crypt::decryptString($oldAudit),true);$audit['recipient']='201000000002';
    DB::table('whatsapp_reply_requests')->update(['audit'=>Crypt::encryptString(json_encode($audit))]);
    try{$workflow->analyze($c,$actor,true);throw new \RuntimeException('TAMPERED_RECEIPT_ACCEPTED');}catch(\RuntimeException $e){expectFlow($e->getMessage()==='INVALID_ACCEPTED_REPLY','altered accepted recipient fails fresh receipt validation');}
    expectFlow(Ai::$calls===1&&DB::table('test_tickets')->count()===0,'invalid durable receipt is never sent to AI or ERP');

    // A bad known conversation does not prevent an unrelated fresh order from being processed.
    resetFlow();[$bad,$request]=apiRequestThread('bad');$rid=acceptedApiReply($bad,$request,'wamid.api-bad');
    DB::table('whatsapp_reply_requests')->where('id',$rid)->update(['payload_hash'=>str_repeat('0',64)]);
    [$good,$goodRequest]=apiRequestThread('good');
    insertMessage($good,'wamid.api-good-form','outbound',finalOrderForm(extractionData()),3,null,'US.fixturegood');
    insertMessage($good,'wamid.api-good-approval','inbound','تمام',4,null,'US.fixturegood');
    app('config')->set('whatsapp_orders.mode','auto');$p=$workflow->process();
    expectFlow($p['dispatched']===1&&$p['capture_pending']===1&&Ai::$calls===1
        &&DB::table('whatsapp_order_drafts')->where('conversation_id',$bad)->count()===0,'invalid API evidence blocks its conversation while unrelated native order proceeds');
    expectFlow(DB::table('test_tickets')->count()===1&&DB::table('whatsapp_reply_requests')->where('id',$rid)->value('payload_hash')===str_repeat('0',64),'bad receipt remains intact and no implicit recovery or source rewrite occurs');

    resetFlow();[$c,$request]=apiRequestThread();acceptedApiReply($c,$request,'wamid.api-old',3);
    app('config')->set('whatsapp_orders.mode','auto');app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:04');
    Ai::$data=\App\Support\WhatsAppOrderExtraction::none();$p=$workflow->process();
    expectFlow(DB::table('whatsapp_inbox_messages')->where('source','dashboard_api')->count()===0
        &&count(Ai::$lastTranscript)===2&&$p['dispatched']===0,'global and scoped backfill exclude accepted confirmations predating current activation');

    // A newly inserted receipt can retain an old send time; ids alone cannot make it fresh.
    // Retain this historical receipt's older nonquestion format to exercise the
    // timestamp guard independently of the current compact-form consent gate.
    resetFlow();[$c,$request]=apiRequestThread();acceptedApiReply($c,$request,'wamid.api-late',3,
        'تأكيد طلب عمر: 1 كيلو فسيخ من المنصورة إلى شارع البحر، الإجمالي 100.00 جنيه.');
    (new \App\Services\Dashboard\WhatsAppAcceptedReplyEvidence)->projectPending(100,$c,null);
    app('config')->set('whatsapp_orders.mode','auto');app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:04');
    Ai::$data['evidence']['customer_acceptance_ids']=[];
    $p=$workflow->process();
    expectFlow($p['dispatched']===0&&DB::table('whatsapp_order_drafts')->first()->reason==='STALE_TRANSCRIPT'
        &&DB::table('test_tickets')->count()===0,'late receipt projection cannot bypass automatic evidence timestamp guard');

    resetFlow();[$c,$request]=apiRequestThread();Ai::$data=\App\Support\WhatsAppOrderExtraction::none();
    Ai::$callback=function()use($c,$request){acceptedApiReply($c,$request,'wamid.api-during-ai');};
    blocked(fn()=>$workflow->analyze($c,$actor),409,'accepted API reply during extraction invalidates message ceiling CAS');
    expectFlow(Ai::$calls===1&&DB::table('whatsapp_order_drafts')->count()===0&&DB::table('whatsapp_order_scans')->first()->claim_nonce===null
        &&DB::table('whatsapp_inbox_messages')->where('source','dashboard_api')->count()===1,'in-flight API confirmation cannot persist false NONE and releases only its lease');

    resetFlow();[$c,$request]=apiRequestThread();acceptedApiReply($c,$request);
    insertMessage($c,'wamid.api-prepared-approval','inbound','تمام',4);[$id,$v]=prepared($workflow,$actor,$c);
    acceptedApiReply($c,$request,'wamid.api-after-quote',5);
    blocked(fn()=>$workflow->dispatch($id,$v,$actor),409,'unprojected accepted API reply invalidates existing READY quote before ERP write');
    expectFlow(DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0
        &&DB::table('whatsapp_order_drafts')->where('id',$id)->value('status')==='READY','receipt arrival after quote leaves no partial order or customer');

    // Substantive validation failures are reviewable once, not transient transport retries.
    foreach (['INVALID_INPUT','INVALID_EVIDENCE','INVALID_EXTRACTION'] as $invalidReason) {
        resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');Ai::$failure=true;Ai::$failureReason=$invalidReason;
        $p=$workflow->process();$stored=DB::table('whatsapp_order_drafts')->first();$again=$workflow->process();
        expectFlow($stored->reason==='INVALID_EXTRACTION'&&$stored->status==='REVIEW'&&$p['review_required']===1
            &&Ai::$calls===1&&$again['dispatched']===0,'substantive '.$invalidReason.' preserves fixed reason without repeated AI request');
        expectFlow(DB::table('test_tickets')->count()===0&&DB::table('whatsapp_order_scans')->first()->next_attempt_at===null,'substantive validation failure neither dispatches nor enters transient backoff');
    }

    function hydrationThread(): array {
        $c=addConversation();$at=(string)strtotime('2026-10-09 22:00:01 UTC');
        $unavailable=['id'=>'wamid.hydration-request','timestamp'=>$at,'type'=>'unsupported','from'=>'201000000001','from_user_id'=>'US.fixture',
            'errors'=>[['code'=>131060,'title'=>'Message unavailable']]];
        $payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>'468336579702269','changes'=>[['field'=>'messages','value'=>[
            'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075'],'messages'=>[$unavailable]]]]]]];
        $report=\App\Support\WhatsAppInboxProtocol::report($payload,'468336579702269','515388018324075');
        expectFlow(count($report['messages'])===1,'actual native unsupported placeholder normalized');$dto=$report['messages'][0];
        $mid=DB::table('whatsapp_inbox_messages')->insertGetId(['conversation_id'=>$c,
            'message_key'=>hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:wamid.hydration-request'),
            'direction'=>'inbound','type'=>'unsupported','source'=>'messages','sent_at'=>$dto['sent_at'],'content'=>Crypt::encryptString(json_encode($dto)),
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        insertMessage($c,'wamid.hydration-pin','inbound','',2,['lat'=>31.04,'long'=>31.37]);
        insertMessage($c,'wamid.hydration-summary','outbound','تأكيد طلب عمر: 1 كيلو فسيخ من المنصورة إلى شارع البحر، الإجمالي 100.00 جنيه.',3);
        $text=$unavailable;unset($text['errors']);$text['type']='text';
        $text['text']=['body'=>'اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.'];
        $payload['entry'][0]['changes'][0]['value']['messages']=[$text];
        $raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        return [$c,$mid,$raw];
    }
    function hydrationEvent(string $raw): int {
        $id=DB::table('whatsapp_webhook_events')->insertGetId(['payload_hash'=>hash('sha256',$raw),'payload'=>Crypt::encryptString($raw),
            'received_at'=>now('UTC'),'processed_at'=>null]);
        DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$id,'reason'=>'CONFLICTING_MESSAGE','attempts'=>1,'last_attempted_at'=>now('UTC')]);
        return $id;
    }
    resetFlow();\Carbon\Carbon::setTestNow('2026-10-10 00:00:00');[$c,$mid,$raw]=hydrationThread();
    app('config')->set('whatsapp_orders.mode','auto');Ai::$data=\App\Support\WhatsAppOrderExtraction::none();$workflow->process();
    $oldDraft=DB::table('whatsapp_order_drafts')->first();$oldScan=DB::table('whatsapp_order_scans')->first();
    $oldSource=DB::table('whatsapp_inbox_messages')->where('id',$mid)->first();$oldCeiling=(int)DB::table('whatsapp_inbox_messages')->max('id');
    DB::table('whatsapp_order_scans')->update(['attempts'=>3,'next_attempt_at'=>now('UTC')->addHour()]);
    $event=hydrationEvent($raw);$hydrated=(new \App\Services\Dashboard\WhatsAppInboxConsumer)->consume(1,true);
    $newSource=DB::table('whatsapp_inbox_messages')->where('id',$mid)->first();$scan=DB::table('whatsapp_order_scans')->first();
    expectFlow($hydrated['errors']===0&&$hydrated['quarantined_events']===0&&$newSource->type==='text'
        &&$newSource->message_key===$oldSource->message_key&&(int)DB::table('whatsapp_inbox_messages')->max('id')===$oldCeiling,'proven hydration keeps original native key ID and ceiling');
    expectFlow((int)$scan->analyzed_ceiling===0&&(int)$scan->claimed_ceiling===0&&(int)$scan->attempts===0&&$scan->next_attempt_at===null
        &&$scan->auto_floor===$oldScan->auto_floor&&$scan->claim_nonce===$oldScan->claim_nonce&&$scan->lease_until===$oldScan->lease_until,'idle semantic revision wakes only affected scan while preserving cutoffs and lease fields');
    Ai::$data=extractionData();Ai::$data['evidence']['customer_acceptance_ids']=[];
    $p=$workflow->process();$recovered=DB::table('whatsapp_order_drafts')->first();
    expectFlow(Ai::$calls===2&&$p['review_required']===1&&$recovered->status==='REVIEW'&&$recovered->reason==='RECOVERED_HISTORY_REVIEW'
        &&(int)$recovered->revision===(int)$oldDraft->revision+1,'same-ID corrected NONE is re-extracted but historical recovered order remains review');
    expectFlow($recovered->ticket_command_key===$oldDraft->ticket_command_key&&$recovered->customer_command_key===$oldDraft->customer_command_key
        &&$recovered->confirmation_key===null&&$recovered->sealed_payload===null&&$recovered->evidence_hash!==$oldDraft->evidence_hash,'revision clears stale authorization retains command identity and binds corrected evidence');
    expectFlow(DB::table('test_tickets')->count()===0&&DB::table('branch_customers')->count()===0
        &&app('config')->get('whatsapp_orders.activation_message_id')===0,'recovery never dispatches old confirmation or advances activation');
    \Carbon\Carbon::setTestNow('2026-10-10 00:00:10');
    insertMessage($c,'wamid.hydration-fresh-summary','outbound',finalOrderForm(extractionData()),7201);
    insertMessage($c,'wamid.hydration-fresh-acceptance','inbound','تمام',7202);
    Ai::$data=extractionData();Ai::$data['evidence']['confirmation_ids']=['m4'];Ai::$data['evidence']['customer_acceptance_ids']=['m5'];
    DB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]);$p=$workflow->process();
    expectFlow($p['dispatched']===1&&DB::table('test_tickets')->count()===1&&DB::table('test_print')->count()===1,'fresh full summary and customer acceptance after recovery may dispatch the same reviewed order once');
    $again=$workflow->process();expectFlow($again['dispatched']===0&&DB::table('test_tickets')->count()===1,'fresh recovered-order confirmation remains idempotent');

    resetFlow();\Carbon\Carbon::setTestNow('2026-10-10 00:00:00');[$c,$mid,$raw]=hydrationThread();
    Ai::$data=\App\Support\WhatsAppOrderExtraction::none();$workflow->analyze($c,$actor);app('config')->set('whatsapp_orders.mode','auto');
    DB::table('whatsapp_order_scans')->update(['claim_nonce'=>str_repeat('e',64),'lease_until'=>now('UTC')->addMinutes(3),
        'attempts'=>3,'next_attempt_at'=>now('UTC')->addHour()]);
    $holder=(array)DB::table('whatsapp_order_scans')->first();$original=(array)DB::table('whatsapp_inbox_messages')->where('id',$mid)->first();
    $event=hydrationEvent($raw);$r=(new \App\Services\Dashboard\WhatsAppInboxConsumer)->consume(1,true);
    expectFlow($r['errors']===1&&(array)DB::table('whatsapp_order_scans')->first()===$holder
        &&(array)DB::table('whatsapp_inbox_messages')->where('id',$mid)->first()===$original,'active holder rejects and rolls back the entire hydration without changing scan or source');
    expectFlow(DB::table('whatsapp_webhook_events')->where('id',$event)->value('processed_at')===null
        &&DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$event)->exists()&&Ai::$calls===1,'busy revision retains raw quarantine and performs no AI call');
    \Carbon\Carbon::setTestNow('2026-10-10 00:04:00');$r=(new \App\Services\Dashboard\WhatsAppInboxConsumer)->consume(1,true);
    $scan=DB::table('whatsapp_order_scans')->first();
    expectFlow($r['errors']===0&&DB::table('whatsapp_inbox_messages')->where('id',$mid)->value('type')==='text'
        &&$scan->claim_nonce===$holder['claim_nonce']&&$scan->lease_until===$holder['lease_until']&&(int)$scan->attempts===0,'expired holder can be corrected while nonce and lease fields are preserved for the next real claim');

    resetFlow();\Carbon\Carbon::setTestNow('2026-10-10 00:00:00');[$c,$mid,$raw]=hydrationThread();
    Ai::$data=\App\Support\WhatsAppOrderExtraction::none();$workflow->analyze($c,$actor);
    app('config')->set('whatsapp_orders.mode','auto');app('config')->set('whatsapp_orders.activation_message_id',$mid);
    DB::table('whatsapp_order_scans')->update(['auto_floor'=>$mid,'attempts'=>3,'next_attempt_at'=>now('UTC')->addHour()]);
    $scanBefore=(array)DB::table('whatsapp_order_scans')->first();$event=hydrationEvent($raw);
    $r=(new \App\Services\Dashboard\WhatsAppInboxConsumer)->consume(1,true);
    expectFlow($r['errors']===0&&(array)DB::table('whatsapp_order_scans')->first()===$scanBefore
        &&app('config')->get('whatsapp_orders.activation_message_id')===$mid,'hydrating a message at or below the activation floor never resets its scan');

    resetFlow();\Carbon\Carbon::setTestNow('2026-10-10 00:00:00');[$c,$mid,$raw]=hydrationThread();
    Ai::$data['evidence']['customer_acceptance_ids']=[];
    [$id,$v]=prepared($workflow,$actor,$c);$workflow->dispatch($id,$v,$actor);
    $receipt=(array)DB::table('whatsapp_order_drafts')->where('id',$id)->first();$source=(array)DB::table('whatsapp_inbox_messages')->where('id',$mid)->first();
    $event=hydrationEvent($raw);$r=(new \App\Services\Dashboard\WhatsAppInboxConsumer)->consume(1,true);
    expectFlow($r['errors']===1&&DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$event)->exists()
        &&(array)DB::table('whatsapp_order_drafts')->where('id',$id)->first()===$receipt
        &&(array)DB::table('whatsapp_inbox_messages')->where('id',$mid)->first()===$source,'completed dispatch receipts and their original native evidence remain immutable');
    expectFlow(DB::table('test_tickets')->count()===1&&DB::table('test_print')->count()===1,'recovery of a sealed completed segment cannot create another order or print');

    resetFlow();\Carbon\Carbon::setTestNow('2026-10-10 00:00:00');[$c,$mid,$raw]=hydrationThread();$event=hydrationEvent($raw);
    $r=(new \App\Services\Dashboard\WhatsAppInboxConsumer)->consume(1,true);
    expectFlow($r['errors']===0&&$r['quarantined_events']===0,'tamper fixture begins with proven native correction');
    $source=DB::table('whatsapp_inbox_messages')->where('id',$mid)->first();$dto=json_decode(Crypt::decryptString($source->content),true);
    $originalCipher=$source->content;$dto['content']['hydration']['at_utc']='2026-10-09 23:59:59';
    DB::table('whatsapp_inbox_messages')->where('id',$mid)->update(['content'=>Crypt::encryptString(json_encode($dto))]);
    blocked(fn()=>$workflow->analyze($c,$actor),500,'hydration time must equal authenticated processing time');
    expectFlow(Ai::$calls===0&&DB::table('whatsapp_order_drafts')->count()===0,'invalid hydration provenance never reaches extraction');
    DB::table('whatsapp_inbox_messages')->where('id',$mid)->update(['content'=>$originalCipher]);
    DB::table('whatsapp_webhook_events')->where('id',$event)->update(['payload'=>Crypt::encryptString('{"unrelated":true}')]);
    blocked(fn()=>$workflow->analyze($c,$actor,true),500,'stored raw hash alone cannot authenticate altered hydration payload');
    expectFlow(Ai::$calls===0&&DB::table('test_tickets')->count()===0,'raw payload tamper fails closed with no provider or ERP call');

    resetFlow();\Carbon\Carbon::setTestNow('2026-10-10 00:00:00');[$c,$mid,$raw]=hydrationThread();
    Ai::$data=\App\Support\WhatsAppOrderExtraction::none();$workflow->analyze($c,$actor);
    $other=fixtureThread('revision-unrelated');$workflow->analyze($other,$actor);
    DB::table('whatsapp_order_scans')->where('conversation_id',$other)->update(['attempts'=>3,'next_attempt_at'=>now('UTC')->addHour(),
        'claim_nonce'=>str_repeat('f',64),'lease_until'=>now('UTC')->addMinutes(3)]);
    $unrelated=(array)DB::table('whatsapp_order_scans')->where('conversation_id',$other)->first();
    $unrelatedRaw=DB::table('whatsapp_webhook_events')->insertGetId(['payload_hash'=>str_repeat('0',64),'payload'=>Crypt::encryptString('{}'),
        'received_at'=>now('UTC'),'processed_at'=>null]);
    DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$unrelatedRaw,'reason'=>'INVALID_EVENT','attempts'=>9,'last_attempted_at'=>now('UTC')]);
    $unrelatedFailure=(array)DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$unrelatedRaw)->first();
    $event=hydrationEvent($raw);$r=(new \App\Services\Dashboard\WhatsAppInboxConsumer)->recoverUnavailableMessages([$event]);
    expectFlow($r['errors']===0&&$r['messages_hydrated']===1
        &&(array)DB::table('whatsapp_order_scans')->where('conversation_id',$other)->first()===$unrelated,'explicit event recovery changes no unrelated scan or live holder');
    expectFlow((array)DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$unrelatedRaw)->first()===$unrelatedFailure
        &&DB::table('whatsapp_webhook_events')->where('id',$unrelatedRaw)->value('processed_at')===null,'targeted recovery never clears or retries an unrelated quarantine');
    \Carbon\Carbon::setTestNow();

    fwrite(STDOUT,'whatsapp-order-workflow: '.$checks." checks passed\n");
}
