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
        public function extract(array $transcript): array {
            self::$calls++; self::$lastTranscript=$transcript;
            \abort_unless(DB::connection()->transactionLevel()===0,500,'NETWORK_LOCK');
            if (self::$callback) (self::$callback)();
            return self::$failure?['ok'=>false,'reason'=>'TRANSPORT_ERROR','data'=>null]:['ok'=>true,'reason'=>null,'data'=>self::$data];
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
        public static $drift=0;
        public function quote(array $v,$actor): array {
            \app(TakeawayAccess::class)->branch($v['branch'],$actor);
            \abort_unless($v['location_confirmed']&&isset($v['latitude'],$v['longitude']),422,'PIN_CONTRACT');
            return ['delivery'=>['delivery_quote_hash'=>PosServiceTicket::fingerprint([$v['branch'],$v['latitude'],$v['longitude'],self::$drift]),
                'delivery_fee'=>'50.00','distance_km'=>'1.000','method'=>'fixture']];
        }
    }
    class PosServiceTicket {
        public static $drift=0; public static $failAfterWrite=false; public static $saveCalls=0;
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
            self::$saveCalls++;
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
    require dirname(__DIR__).'/app/Support/WhatsAppOrderExtraction.php';
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
    $workflow=new WhatsAppOrderWorkflow; $actor=(object)['id'=>7]; $checks=0;
    function expectFlow($condition,$label) { global $checks; $checks++; if(!$condition)throw new \RuntimeException('FAILED:'.$label); }
    function blocked(callable $call,int $status,string $label) { try{$call();}catch(WorkflowBlocked $e){expectFlow($e->getCode()===$status,$label);return;} throw new \RuntimeException('NOT_BLOCKED:'.$label); }
    function resetFlow() {
        foreach(['whatsapp_order_drafts','whatsapp_order_scans','whatsapp_inbox_messages','whatsapp_inbox_conversations','branch_customers','test_print','test_kitchen','test_tickets','pos_service_tickets','whatsapp_webhook_events']as$t)DB::table($t)->delete();
        Ai::$calls=0; Ai::$callback=null; Ai::$failure=false; Pos::$drift=0; Pos::$failAfterWrite=false; Pos::$saveCalls=0; PhoneDelivery::$drift=0; BranchCustomers::$calls=0;
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
            'approximate_total'=>'100.00','evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m3'],'customer_acceptance_ids'=>[], 'location_id'=>'m2'],'issues'=>[]];
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
    function fixtureThread(string $suffix=''): int {
        $c=addConversation('US.fixture'.$suffix);
        insertMessage($c,'req'.$suffix,'inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1,null,'US.fixture'.$suffix);
        insertMessage($c,'pin'.$suffix,'inbound','',2,['lat'=>31.04,'long'=>31.37],'US.fixture'.$suffix);
        insertMessage($c,'confirm'.$suffix,'outbound','تأكيد طلب عمر: 1 كيلو فسيخ من المنصورة إلى شارع البحر، الإجمالي 100.00 جنيه.',3,null,'US.fixture'.$suffix);
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
    function cartThread(): int {
        $c=addConversation();
        insertMessage($c,'cart-customer','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة.',1);
        insertCartMessage($c,'cart-order',2,[['product_retailer_id'=>'half-fish','quantity'=>2,'item_price'=>'45.00','currency'=>'EGP']]);
        insertMessage($c,'cart-pin','inbound','',3,['lat'=>31.04,'long'=>31.37]);
        insertMessage($c,'cart-confirm','outbound','تأكيد طلب عمر: 1 كيلو فسيخ من المنصورة إلى شارع البحر، الإجمالي 100.00 جنيه.',4);
        Ai::$data=extractionData(); Ai::$data['items'][0]['cart_reference']=['message_id'=>'m2','product_retailer_id'=>'half-fish'];
        Ai::$data['evidence']=['request_ids'=>['m1','m2'],'confirmation_ids'=>['m4'],'customer_acceptance_ids'=>[],'location_id'=>'m3'];
        return $c;
    }
    function mappedCart(): void {
        app('config')->set('whatsapp_cart.product_mappings',['999'=>['half-fish'=>['f:1'=>[
            'product_id'=>11,'quantity_mode'=>'weight','quantity_per_unit'=>'0.5','option_id'=>'']]]]);
    }
    function textAddressThread(string $address='شارع البحر',string $total='99.00'): int {
        $c=addConversation();
        insertMessage($c,'address-request','inbound','اسمي عمر ورقمي 201000000001 وعنواني '.$address.' في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1);
        insertMessage($c,'address-confirm','outbound','تأكيد طلب عمر: 1 كيلو فسيخ من المنصورة إلى '.$address.'، الإجمالي التقريبي '.$total.' جنيه.',3);
        Ai::$data=extractionData(); Ai::$data['customer']['address']=$address; Ai::$data['approximate_total']=$total;
        Ai::$data['evidence']['confirmation_ids']=['m2']; Ai::$data['evidence']['location_id']=null;
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

    resetFlow(); $c=fixtureThread(); [$id,$v]=prepared($workflow,$actor,$c);
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

    resetFlow();$c=fixtureThread();app('config')->set('whatsapp_orders.mode','auto');
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
    insertMessage($c,'fresh-cutover-confirmation','outbound','تأكيد طلب عمر: 1 كيلو فسيخ من المنصورة إلى شارع البحر، الإجمالي 100.00 جنيه.',6);
    app('config')->set('whatsapp_orders.activation_message_id',$cutover);app('config')->set('whatsapp_orders.mode','auto');
    $p=$workflow->process();$fresh=DB::table('whatsapp_order_drafts')->where('status','DISPATCHED')->first();
    expectFlow($p['dispatched']===1&&DB::table('test_tickets')->count()===1&&(int)$fresh->evidence_floor===$cutover,'complete new confirmation dispatches after cutover in existing thread');
    expectFlow(count(Ai::$lastTranscript)===3&&$fresh->id!==$old['draft']['id']&&(array)DB::table('whatsapp_order_drafts')->where('id',$old['draft']['id'])->first()===$historical,'fresh confirmation excludes and retains earlier cached order');

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
    expectFlow($p['dispatched']===1&&DB::table('test_tickets')->first()->branch==='f:1','explicit area alias resolves omitted branch hint');
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
    Ai::$data=extractionData(); Ai::$data['evidence']['request_ids']=['m1','m2']; Ai::$data['evidence']['confirmation_ids']=['m4']; Ai::$data['evidence']['location_id']='m3'; $p=$workflow->process();
    expectFlow($p['review_required']===1&&DB::table('test_tickets')->count()===0,'new name-only followup cannot launder historical committing request');

    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto'); $p=$workflow->process();
    expectFlow($p['dispatched']===1&&WhatsAppOrderWorkflow::AUTOMATIC_ACTIVATION_TIME_GUARD==='whatsapp-auto-activation-time-v1','fresh commitment and confirmation pass explicit automatic time guard');
    resetFlow(); $c=addConversation(); app('config')->set('whatsapp_orders.mode','auto'); app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:05');
    insertMessage($c,'old-request-fresh-accept','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1);
    insertMessage($c,'fresh-accept-pin','inbound','',7,['lat'=>31.04,'long'=>31.37]);
    insertMessage($c,'fresh-accept-final','outbound','تأكيد طلب عمر: 1 كيلو فسيخ من المنصورة إلى شارع البحر، الإجمالي 100.00 جنيه.',8);
    insertMessage($c,'fresh-explicit-accept','inbound','تمام موافق',9);
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
    Ai::$data['customer']['area']=null; Ai::$data['issues'][]='MISSING_AREA'; app('config')->set('whatsapp_orders.mode','auto'); BranchCustomers::$calls=0; $p=$workflow->process();
    expectFlow($p['dispatched']===1&&BranchCustomers::$calls===0&&DB::table('branch_customers')->first()->delivery_notes==='تعليمات محفوظة','omitted area and notes preserve authoritative unchanged saved source');

    // A literal current catalog choice cannot vanish because the model omitted option_hint.
    resetFlow(); $c=fixtureThread(); app('config')->set('whatsapp_orders.mode','auto');
    \App\Services\Dashboard\TakeawayCatalog::$options=[['id'=>'clean-extra','label'=>'تنظيف إضافي']];
    $summary=DB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->where('direction','outbound')->first();
    $dto=json_decode(Crypt::decryptString($summary->content),true); $dto['text']=str_replace('1 كيلو فسيخ','1 كيلو فسيخ تنظيف إضافي',$dto['text']);
    $dto['content']['message']['text']['body']=$dto['text']; DB::table('whatsapp_inbox_messages')->where('id',$summary->id)->update(['content'=>Crypt::encryptString(json_encode($dto))]); $p=$workflow->process();
    $sealed=json_decode(Crypt::decryptString(DB::table('whatsapp_order_drafts')->first()->sealed_payload),true);
    expectFlow($p['dispatched']===1&&$sealed['review']['items'][0]['option_id']==='clean-extra','omitted AI hint resolves explicit unique live option instead of base order');

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
    expectFlow($p['dispatched']===1,'exact cart mapping preserves proven canonical option when model hint omitted');

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

    fwrite(STDOUT,'whatsapp-order-workflow: '.$checks." checks passed\n");
}
