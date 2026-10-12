<?php
namespace {
    $vendor = getenv('WA_INBOX_TEST_VENDOR') ?: dirname(__DIR__) . '/vendor';
    require $vendor . '/autoload.php';
    if (!extension_loaded('pdo_sqlite')) throw new \RuntimeException('Isolated SQLite required.');
}
namespace App\Models {
    class User extends \Illuminate\Database\Eloquent\Model {
        protected $table='users'; protected $guarded=[]; public $timestamps=false;
        public function can($ability,$arguments=[]): bool {return false;}
    }
}
namespace App\Services\Dashboard {
    class PosServiceTable {public function ready(): bool{return true;}public function requireReady(): void{}public function policy(string $branch,bool $lock=false):array{return ['service_bps'=>0,'service_rate'=>'0.00','settings_revision'=>1];}}
    class BranchOperations {public function ready():void{}}
    class BranchCustomers {public static function phoneKey(string $phone):string{return preg_replace('/[^0-9+]/','',$phone);}}
    class DeliveryCompanies {public function snapshot($id,string $branch):?array{return null;}}
    class OperatingDay {public static function date($when=null):string{return '2026-10-12';}}
    class PhoneMapProvider {
        public static bool $on=false;public static array $roads=[];
        public function enabled():bool{return self::$on;}
        public function road(array $policy,float $lat,float $lng):array{self::$roads[]=[$policy['latitude'],$policy['longitude'],$lat,$lng];return ['meters'=>1000,'method'=>'road_osrm','path'=>[[$policy['latitude'],$policy['longitude']],[$lat,$lng]]];}
    }
    // The delivery/ticket code is real; this isolated canonical POS adapter rejects origin-store product IDs.
    class TakeawayService {
        public static array $calls=[];
        public function canonicalCart(array $v):array {return ['branch'=>$v['branch'],'items'=>$v['items'],'discount'=>$v['discount']??'0.00','discount_reason'=>$v['discount_reason']??''];}
        public function quote(array $v,$actor,array $context=[],bool $lock=false):array {
            self::$calls[]=isset($context['saved_quote'])?'saved':'fresh';
            if(isset($context['saved_quote']))return $context['saved_quote'];
            $b=app(TakeawayAccess::class)->branch($v['branch'],$actor);$lines=[];$subtotal=0;
            foreach($v['items'] as $item){
                $p=\Illuminate\Support\Facades\DB::table('resturant_products')->where('id',$item['product_id'])->where('resturant_id',$b['id'])->first();abort_unless($p,422);
                $quantity=(int)round((float)$item['quantity']*1000);$lineTotal=intdiv($p->price_cents*$quantity+500,1000);$subtotal+=$lineTotal;
                $lines[]=['product_id'=>(int)$p->id,'name'=>$p->name,'quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode'],'option_id'=>$item['option_id']??'',
                    'quantity_millis'=>$quantity,'unit_price_cents'=>(int)$p->price_cents,'total_cents'=>$lineTotal,'unit_price'=>\App\Services\GoServices\Money::decimal($p->price_cents),'total'=>\App\Services\GoServices\Money::decimal($lineTotal)];
            }
            $delivery=$context['delivery_cents']??0;$q=['success'=>true,'branch'=>$b,'items'=>$lines,'subtotal_cents'=>$subtotal,'delivery_cents'=>$delivery,'discount_cents'=>0,'tax_cents'=>0,'service_cents'=>0,'tax_rate'=>'0.00','service_bps'=>0];
            foreach(['subtotal'=>$subtotal,'discount'=>0,'tax'=>0,'service'=>0,'delivery'=>$delivery,'total'=>$subtotal+$delivery] as $key=>$cents)$q[$key]=\App\Services\GoServices\Money::decimal($cents);
            $q['quote_hash']=PosServiceTicket::fingerprint($q);return $q;
        }
    }
}
namespace {
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use App\Services\Dashboard\PhoneDelivery;
    use App\Services\Dashboard\FasakhanstaDeliveryOrigin;
    use App\Services\Dashboard\PosServiceTicket;
    use App\Services\Dashboard\TakeawayAccess;
    use App\Services\Dashboard\TakeawayService;
    use App\Services\Dashboard\PhoneMapProvider;
    $core = getenv('WA_POS_SOURCE_ROOT') ?: dirname(__DIR__);
    require $core . '/app/Services/GoServices/Money.php';
    require $core . '/app/Services/Dashboard/TakeawayAccess.php';
    require getenv('WA_POS_PRINTING_SOURCE') ?: $core . '/app/Services/Dashboard/PosBranchPrinting.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/FasakhanstaDeliveryOrigin.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/PhoneDelivery.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/PosServiceTicket.php';
    function abort_unless($condition,int $status=403,string $message=''):void{if(!$condition)throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);}
    function abort_if($condition,int $status=403,string $message=''):void{abort_unless(!$condition,$status,$message);}
    function app(?string $class=null){global $container;return $class===null?$container:$container->make($class);}
    function config(string $key,$default=null){return $key==='app.timezone'?'Africa/Cairo':$default;}
    function now(string $timezone='UTC'){return \Carbon\Carbon::now($timezone);}
    function route(string $name,array $args=[]):string{return '/fixture/'.$name.'/'.($args['id']??'');}
    $capsule=new Capsule();$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$capsule->setAsGlobal();$capsule->bootEloquent();
    $container=$capsule->getContainer();$container->instance('db',$capsule->getDatabaseManager());$container->instance('db.schema',$capsule->getConnection()->getSchemaBuilder());
    $loader=new \Illuminate\Translation\ArrayLoader();$translator=new \Illuminate\Translation\Translator($loader,'en');
    $container->instance('validator',new \Illuminate\Validation\Factory($translator,$container));\Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    Schema::create('users',function($t){$t->integer('id')->primary();$t->string('account_type');$t->integer('owner_resturant_id')->nullable();$t->string('name');$t->string('app_scope')->nullable();$t->string('status')->nullable();});
    Schema::create('resturants',function($t){$t->integer('id')->primary();$t->integer('user_id');$t->integer('parent_id')->nullable();foreach(['name','address','phone','control','lat','lng','km_price'] as $c)$t->string($c)->nullable();});
    Schema::create('go_stores',function($t){$t->integer('user_id')->primary();foreach(['name','address','phone','lat','lng','km_price'] as $c)$t->string($c)->nullable();});
    Schema::create('resturant_products',function($t){$t->integer('id')->primary();$t->integer('resturant_id');$t->string('name');$t->integer('price_cents');});
    Schema::create('pos_service_tickets',function($t){$t->increments('id');foreach(['branch','channel','customer_name','customer_phone','phone_key','address','area','delivery_notes','notes','cart_snapshot','quote_snapshot','delivery_snapshot','delivery_company_snapshot','table_snapshot','waiter_name','cancel_reason','business_date','created_at','updated_at','bill_issued_at'] as $c)$t->text($c)->nullable();foreach(['table_id','guest_count','customer_id','delivery_company_id','last_kitchen_id','paid_order_id','bill_issued_by','bill_issued_revision'] as $c)$t->integer($c)->nullable();$t->integer('actor_id');$t->integer('revision');$t->integer('delivery_cents');$t->string('status')->default('new');$t->string('payment_status')->default('unpaid');});
    Schema::create('pos_service_commands',function($t){$t->increments('id');$t->string('branch');$t->integer('actor_id');$t->string('request_key');$t->string('request_hash');$t->string('kind');$t->integer('ticket_id');$t->integer('revision');$t->text('metadata');$t->string('created_at');$t->string('updated_at');$t->unique(['branch','actor_id','request_key']);});
    Schema::create('pos_service_kitchen_tickets',function($t){$t->increments('id');$t->string('branch');$t->integer('ticket_id');$t->integer('revision');$t->integer('actor_id');$t->text('snapshot');$t->string('created_at');$t->string('updated_at');});
    Schema::create('pos_branch_print_jobs',function($t){$t->increments('id');$t->string('branch');$t->integer('ticket_id');$t->integer('kitchen_id');$t->string('status');$t->string('claimed_at')->nullable();$t->string('created_at');$t->string('updated_at');$t->unique(['branch','ticket_id','kitchen_id']);});
    DB::table('users')->insert([['id'=>1,'account_type'=>'admin','owner_resturant_id'=>null,'name'=>'Central','app_scope'=>null,'status'=>null],['id'=>2,'account_type'=>'admin','owner_resturant_id'=>301,'name'=>'Shubra','app_scope'=>null,'status'=>null],['id'=>3,'account_type'=>'admin','owner_resturant_id'=>401,'name'=>'Other','app_scope'=>null,'status'=>null],['id'=>501,'account_type'=>'vendor','owner_resturant_id'=>null,'name'=>'GO','app_scope'=>'go_partner','status'=>'accepted']]);
    // Public ID/name inventory captured from the server before this feature; no ownership parents were configured.
    $retailInventory=[309=>'المقطم ستور',310=>'زايد ستور',311=>'التجمع ستور',312=>'مدينتي ستور',313=>'مصر الجديده',
        336=>'المهندسين ستور',337=>'مدينة  نصر ستور',338=>'زهراء مدينة نصر ستور',339=>'عين شمس ستور',340=>'التجمع الثالث ستور',
        341=>'15مايو ستور',342=>'المعادي ستور',343=>'أكتوبر ستور',344=>'حدائق أكتوبر ستور',345=>'الجيزه ستور',346=>'حلوان ستور',
        347=>'الهرم ستور',348=>'زمالك ستور',349=>'الشروق ستور',350=>'الرحاب ستور',354=>'منصورية ستور',355=>'قليوب ستور',
        356=>'العاصمه ستور',357=>'العاشر ستور',358=>'عبور ستور',359=>'شبرا ستور',360=>'كرداسة ستور'];
    foreach([94=>[301,'فرع المحله',31.0,32.0,'30.00'],301=>[null,'فرع شبرا',30.1,31.1,'10.00'],307=>[301,'فرع نبروه',31.0,32.0,'30.00'],363=>[301,'فرع المنصورة',31.0,32.0,'30.00'],308=>[301,'جُملة ستور',31.0,32.0,'30.00'],321=>[301,'متجر خارج القاهرة',31.0,32.0,'30.00'],401=>[null,'Other branch',31.0,32.0,'30.00']] as $id=>$v)DB::table('resturants')->insert(['id'=>$id,'user_id'=>$id,'parent_id'=>$v[0],'name'=>$v[1],'address'=>'Public address','phone'=>'Public phone','control'=>'show','lat'=>(string)$v[2],'lng'=>(string)$v[3],'km_price'=>$v[4]]);
    foreach($retailInventory as $id=>$name)DB::table('resturants')->insert(['id'=>$id,'user_id'=>$id,'parent_id'=>null,'name'=>$name,'address'=>'Public address','phone'=>'Public phone','control'=>'show','lat'=>'30','lng'=>'31','km_price'=>'20.00']);
    DB::table('go_stores')->insert(['user_id'=>501,'name'=>'GO shop','address'=>'Public address','phone'=>'Public phone','lat'=>'29','lng'=>'30','km_price'=>'5.00']);
    DB::table('resturant_products')->insert([['id'=>1001,'resturant_id'=>301,'name'=>'Physical product','price_cents'=>10000],['id'=>2001,'resturant_id'=>309,'name'=>'Origin product','price_cents'=>20000]]);
    $access=new TakeawayAccess();$container->instance(TakeawayAccess::class,$access);$container->instance(FasakhanstaDeliveryOrigin::class,new FasakhanstaDeliveryOrigin($access));
    $delivery=new PhoneDelivery($access);$container->instance(PhoneDelivery::class,$delivery);$sales=new TakeawayService();$container->instance(TakeawayService::class,$sales);$tables=new \App\Services\Dashboard\PosServiceTable();$tickets=new PosServiceTicket($access,$sales,$tables);
    $actor=\App\Models\User::find(1);$checks=0;
    function expect($actual,$expected,string $label):void{global $checks;$checks++;if($actual!==$expected)throw new \RuntimeException($label);}
    function refuses(callable $call,int $status,string $label):void{try{$call();throw new \RuntimeException($label.' accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){expect($e->getStatusCode(),$status,$label);}catch(\Illuminate\Validation\ValidationException $e){expect($status,422,$label);}}
    function saveValues(array $delivery,array $quote,string $key):array{return ['branch'=>'f:301','delivery_origin_branch'=>'f:309','customer_name'=>'Fixture','customer_phone'=>'201000000001','address'=>'Fixture street','area'=>'Cairo','items'=>[['product_id'=>1001,'quantity'=>'1.000','quantity_mode'=>'weight','option_id'=>'']], 'discount'=>'0.00','discount_reason'=>'','latitude'=>'30.01','longitude'=>'31.01','location_confirmed'=>true,'delivery_quote_hash'=>$delivery['delivery_quote_hash'],'quote_hash'=>$quote['quote_hash'],'idempotency_key'=>$key,'send_to_kitchen'=>true];}
    try {
        $normal=['branch'=>'f:301','latitude'=>'30.01','longitude'=>'31.01','location_confirmed'=>true];$mapped=$normal+['delivery_origin_branch'=>'f:309'];
        expect(FasakhanstaDeliveryOrigin::CAIRO_ORIGINS,$retailInventory,'registry exactly matches the reviewed 27 retail identities');
        foreach($retailInventory as $originId=>$originName){
            expect(FasakhanstaDeliveryOrigin::isConfiguredOrigin('f:'.$originId),true,'each approved retail origin is configured');
            $approved=$delivery->quote($normal+['delivery_origin_branch'=>'f:'.$originId],$actor)['delivery'];
            expect($approved['delivery_origin']['name'],$originName,'approved live ID/name is preserved');
            expect($approved['delivery_origin']['parent_id'],null,'NULL ownership parent is described without mutation');
        }
        expect(DB::table('resturants')->whereIn('id',array_keys($retailInventory))->whereNotNull('parent_id')->count(),0,'delivery registry never changes database ownership');
        foreach(['f:308','f:321','f:94','f:301','f:307','f:363','gs:309','f:0309','f:9999999999999999999'] as $unknown){
            expect(FasakhanstaDeliveryOrigin::isConfiguredOrigin($unknown),false,'only exact approved retail identifiers are configured');
        }
        refuses(fn()=>$delivery->quote($normal+['delivery_origin_branch'=>'f:308'],$actor),422,'wholesale308 is never a Cairo retail origin');
        refuses(fn()=>$delivery->quote($normal+['delivery_origin_branch'=>'f:321'],$actor),422,'unknown child301 is never automatically configured');
        DB::table('resturants')->where('id',309)->update(['name'=>'متجر مختلف']);refuses(fn()=>$delivery->quote($mapped,$actor,true),422,'registered ID with a changed name fails closed');DB::table('resturants')->where('id',309)->update(['name'=>'المقطم ستور']);
        expect(FasakhanstaDeliveryOrigin::matchesRegisteredName('f:337',"  مدينة\tنصر ستور  "),true,'identity normalization only permits whitespace differences');
        expect(FasakhanstaDeliveryOrigin::matchesRegisteredName('f:309','مقطم ستور'),false,'identity normalization does not permit fuzzy name replacement');
        foreach([0,301] as $parent){DB::table('resturants')->where('id',309)->update(['parent_id'=>$parent]);expect($delivery->quote($mapped,$actor)['delivery']['delivery_origin']['parent_id'],$parent,'explicit harmless parent is preserved');}
        DB::table('resturants')->where('id',309)->update(['parent_id'=>94]);refuses(fn()=>$delivery->quote($mapped,$actor,true),422,'conflicting physical parent rejects a registered store');DB::table('resturants')->where('id',309)->update(['parent_id'=>null]);
        $old=$delivery->quote($normal,$actor)['delivery'];expect(isset($old['delivery_origin_branch']),false,'normal hash payload has no origin additions');
        expect($delivery->quote($normal+['delivery_origin_branch'=>'f:301'],$actor)['delivery'],$old,'explicit same origin leaves normal quote unchanged');
        foreach([94,307,363] as $physicalId){
            refuses(fn()=>$delivery->quote($normal+['delivery_origin_branch'=>'f:'.$physicalId],$actor),422,'physical branch parent301 is never a virtual origin');
            $physical=$normal;$physical['branch']='f:'.$physicalId;$physicalQuote=$delivery->quote($physical,$actor)['delivery'];
            expect($physicalQuote['branch'],'f:'.$physicalId,'physical fulfillment is never automatically rerouted');
            expect(isset($physicalQuote['delivery_origin_branch']),false,'ordinary physical branch quote retains its original shape');
        }
        $new=$delivery->quote($mapped,$actor)['delivery'];expect($new['branch'],'f:301','fulfillment remains physical');expect($new['branch_latitude'],30.0,'distance starts at store');expect($new['km_price'],'20.00','rate belongs to selected store');expect($new['delivery_origin']['id'],309,'source identity is explicit');expect($new['fulfillment']['id'],301,'physical pickup identity is explicit');expect($new['fulfillment']['latitude'],30.1,'pickup pin is preserved separately');expect($new['fulfillment']['name'],'فرع شبرا','physical name retained');
        expect($new['delivery_quote_hash']!==$old['delivery_quote_hash'],true,'mapped source affects delivery hash');
        $sameGeometry=$delivery->quote($normal+['delivery_origin_branch'=>'f:310'],$actor)['delivery'];expect($sameGeometry['distance_meters'],$new['distance_meters'],'fixture stores have equal route lengths');expect($sameGeometry['delivery_quote_hash']!==$new['delivery_quote_hash'],true,'different source IDs cannot share a quote hash');
        refuses(fn()=>$delivery->quote($mapped,\App\Models\User::find(3)),404,'unauthorized fulfillment actor cannot read origin');
        refuses(fn()=>$delivery->quote($normal+['delivery_origin_branch'=>'gs:501'],$actor),422,'GO origin value is not a Cairo alias');
        refuses(fn()=>$delivery->quote(['branch'=>'gs:501','delivery_origin_branch'=>'f:309','latitude'=>'30','longitude'=>'31','location_confirmed'=>true],\App\Models\User::find(501)),422,'GO fulfillment never reroutes');
        $go=$delivery->quote(['branch'=>'gs:501','latitude'=>'29.01','longitude'=>'30.01','location_confirmed'=>true],\App\Models\User::find(501))['delivery'];expect(isset($go['delivery_origin']),false,'ordinary GO quote remains unchanged');expect($go['km_price'],'5.00','ordinary GO rate retained');
        DB::table('resturants')->where('id',309)->update(['parent_id'=>401]);refuses(fn()=>$delivery->quote($mapped,$actor,true),422,'reparented source refuses locked dispatch');DB::table('resturants')->where('id',309)->update(['parent_id'=>null,'control'=>'hide']);refuses(fn()=>$delivery->quote($mapped,$actor),422,'hidden source refuses');DB::table('resturants')->where('id',309)->update(['control'=>'show']);
        DB::table('resturants')->where('id',309)->update(['lat'=>'0','lng'=>'0']);refuses(fn()=>$delivery->quote($mapped,$actor),422,'missing store pin cannot invent delivery geometry');DB::table('resturants')->where('id',309)->update(['lat'=>'30','lng'=>'31','km_price'=>'-1']);refuses(fn()=>$delivery->quote($mapped,$actor),422,'invalid store rate refuses');DB::table('resturants')->where('id',309)->update(['km_price'=>'20.00']);
        DB::table('resturants')->where('id',301)->update(['lat'=>'0','lng'=>'0']);$pickupMissing=$delivery->quote($mapped,$actor)['delivery'];expect($pickupMissing['fulfillment']['latitude'],null,'missing physical pickup pin is never replaced by the virtual store pin');expect($pickupMissing['branch_latitude'],30.0,'billing origin is still the approved store');DB::table('resturants')->where('id',301)->update(['lat'=>'30.1','lng'=>'31.1']);
        PhoneMapProvider::$on=true;PhoneMapProvider::$roads=[];$road=$delivery->quote($mapped,$actor)['delivery'];expect(count(PhoneMapProvider::$roads),1,'one commercial route request only');expect(PhoneMapProvider::$roads[0],[30.0,31.0,30.01,31.01],'road input is store to customer');PhoneMapProvider::$on=false;
        $mapped['delivery_quote_hash']=$new['delivery_quote_hash'];
        $cart=['branch'=>'f:301','items'=>[['product_id'=>1001,'quantity'=>'1.000','quantity_mode'=>'weight','option_id'=>'']]];
        $priced=$tickets->quote('phone',$mapped+$cart,$actor);$values=saveValues($new,$priced,'00000000-0000-4000-8000-000000000001');
        $saved=$tickets->save('phone',$values,$actor);$id=$saved['ticket']['id'];expect($saved['ticket']['branch']['value'],'f:301','ticket belongs to Shubra');expect($saved['ticket']['delivery_location']['delivery_origin_branch'],'f:309','ticket retains store origin');expect($saved['ticket']['items'][0]['unit_price'],'100.00','physical canonical product price stays unchanged');expect(DB::table('pos_branch_print_jobs')->value('branch'),'f:301','only Shubra receives kitchen print');expect(json_decode(DB::table('pos_service_kitchen_tickets')->value('snapshot'),true)['delivery_location']['delivery_origin']['id'],309,'kitchen snapshot preserves commercial source');
        $printQueue=app(\App\Services\Dashboard\PosBranchPrinting::class)->listing(['branch'=>'f:301'],\App\Models\User::find(2));expect($printQueue['jobs'][0]['ticket_id'],$id,'physical branch printer receives the mapped-order ticket');
        if(isset($printQueue['incoming_ticket_ids']))expect($printQueue['incoming_ticket_ids'],[$id],'current live branch alert includes the mapped call-center order');
        $count=DB::table('pos_service_tickets')->count();$replay=$tickets->save('phone',$values,$actor);expect($replay['replayed'],true,'same complete command safely replays');expect(DB::table('pos_service_tickets')->count(),$count,'replay creates no duplicate order');expect(DB::table('pos_branch_print_jobs')->count(),1,'replay creates no duplicate print');
        $tamper=$values;$tamper['delivery_origin_branch']='f:310';refuses(fn()=>$tickets->save('phone',$tamper,$actor),409,'reused command cannot change source identity');
        DB::table('resturants')->where('id',309)->update(['km_price'=>'25.00']);$stale=$values;$stale['idempotency_key']='00000000-0000-4000-8000-000000000002';refuses(fn()=>$tickets->save('phone',$stale,$actor),409,'changed store rate rejects stale hash before publishing');expect(DB::table('pos_service_tickets')->count(),$count,'stale quote has no order side effects');DB::table('resturants')->where('id',309)->update(['km_price'=>'20.00']);
        DB::table('resturants')->where('id',309)->update(['parent_id'=>401]);refuses(fn()=>$tickets->save('phone',$stale,$actor),422,'relationship is rechecked before ticket publication');expect(DB::table('pos_service_tickets')->count(),$count,'reparented store has no order side effects');DB::table('resturants')->where('id',309)->update(['parent_id'=>null]);
        $edit=$values;unset($edit['delivery_origin_branch']);$edit['ticket_id']=$id;$edit['expected_revision']=$saved['ticket']['revision'];$edit['idempotency_key']='00000000-0000-4000-8000-000000000003';$edit['send_to_kitchen']=false;
        $requote=$tickets->quote('phone',$edit,$actor);expect($requote['delivery_cents'],$priced['delivery_cents'],'requote inherits existing store origin');$edited=$tickets->save('phone',$edit,$actor);expect($edited['ticket']['delivery_location']['delivery_origin_branch'],'f:309','editing omission cannot erase origin');
        $changed=$values;$changed['ticket_id']=$id;$changed['expected_revision']=$edited['ticket']['revision'];$changed['delivery_origin_branch']='f:310';$changed['delivery_quote_hash']=$sameGeometry['delivery_quote_hash'];$changed['idempotency_key']='00000000-0000-4000-8000-000000000004';$changed['send_to_kitchen']=false;$freshBefore=count(array_filter(TakeawayService::$calls,fn($call)=>$call==='fresh'));
        $changedTicket=$tickets->save('phone',$changed,$actor);expect($changedTicket['ticket']['delivery_location']['delivery_origin_branch'],'f:310','validated source change is persisted');expect(count(array_filter(TakeawayService::$calls,fn($call)=>$call==='fresh')),$freshBefore+1,'source change forces canonical reprice even when fee is numerically identical');
        $wrongCart=$mapped+$cart;$wrongCart['items'][0]['product_id']=2001;refuses(fn()=>$tickets->quote('phone',$wrongCart,$actor),422,'origin product IDs never enter the physical branch catalog');
        DB::table('users')->where('id',1)->update(['owner_resturant_id'=>401]);refuses(fn()=>$delivery->quote($mapped,$actor),404,'a revoked persisted actor is not authorized by its stale object');
        DB::table('users')->where('id',1)->update(['owner_resturant_id'=>null]);DB::statement('ALTER TABLE resturants DROP COLUMN parent_id');$withoutParent=new TakeawayAccess();$container->instance(FasakhanstaDeliveryOrigin::class,new FasakhanstaDeliveryOrigin($withoutParent));refuses(fn()=>$delivery->quote($mapped,$actor),503,'absent parent linkage schema fails closed');
        echo 'WHATSAPP_CAIRO_DELIVERY_ORIGIN_TESTS='.$checks." PASS\n";
    } catch(\Throwable $error) {echo 'WHATSAPP_CAIRO_DELIVERY_ORIGIN_FAILED='.get_class($error).' '.$error->getMessage()."\n".$error->getTraceAsString()."\n";exit(1);}
}
