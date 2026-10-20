<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Schema,Validator};

/** Source-specific original board transitions; no courier discovery or payment checkout. */
class DesktopDashboardOrderBoard
{
    public const ROUTE='order-board.action';
    private const TABLES=['legacy'=>'orders','store'=>'go_store_orders','service'=>'go_service_jobs','partner_service'=>'partner_service_requests'];
    private const ENTITIES=['legacy'=>'legacy_order','store'=>'store_order','service'=>'service_job','partner_service'=>'partner_service_request'];
    private const USER=['id','name','mobile','email','account_type','app_scope','status','balance','min_wallet','pending_vendor_id','owner_resturant_id','delegate_fees','expiration_date','connected','created_at'];
    public static function handles(?string $route): bool {return $route===self::ROUTE;}
    private function original()
    {
        $route=app('router')->getRoutes()->getByName(self::ROUTE);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\OrderBoardController@action'
            &&$route->uri()==='admin/order-board/{source}/{id}/action'&&$route->methods()===['POST']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409);
        return $route;
    }
    private function parameters(array $parameters): array
    {
        abort_if(array_diff(array_keys($parameters),['source','id']),422);
        $p=Validator::make($parameters,['source'=>'required|in:legacy,store,service,partner_service','id'=>'required|integer|min:1'])->validate();
        return ['source'=>$p['source'],'id'=>(int)$p['id']];
    }
    private function values(array $values): array
    {
        abort_if(array_diff(array_keys($values),['action','expected_status','expected_accepted_notify','expected_revision','reason','idempotency_key']),422);
        Validator::make($values,['action'=>'required|in:accept,reject,cancel,prepare,ready,dispatch,complete','expected_status'=>'required|string|max:32',
            'expected_accepted_notify'=>'nullable|string|max:10','expected_revision'=>'nullable|integer|min:0','reason'=>'nullable|string|max:500','idempotency_key'=>'required|uuid'])->validate();
        return $values;
    }
    public function command(Request $request): string {return app(DesktopDashboardWalletCash::class)->command($request);}
    private function rows(string $table,callable $scope,array $drop=[]): array
    {
        if(!Schema::hasTable($table))return [];$q=DB::table($table);$scope($q);
        $rows=$q->lockForUpdate()->limit(10001)->get()->map(function($row)use($drop){$row=(array)$row;
            foreach(array_keys($row) as $key)if(in_array($key,$drop,true)||preg_match('/password|remember_token|(?:^|_)(?:token|secret|api_key|private_key|credential|partner_auth_email|fcm_id|mobile_code|email_code|otp_first_no)(?:$|_)/i',$key))unset($row[$key]);
            return $row;
        })->all();abort_if(count($rows)>10000,413,'تفاصيل الطلب أكبر من الحد المجهز للمزامنة.');return $this->sorted($rows);
    }
    private function sorted(array $rows): array {usort($rows,fn($a,$b)=>strcmp(DesktopDashboardBootstrap::json($a),DesktopDashboardBootstrap::json($b)));return $rows;}
    private function graph(array $parameters,User $actor): array
    {
        $p=$this->parameters($parameters);$source=$p['source'];$id=$p['id'];
        $row=(array)app(OrderBoardService::class)->scopedRow($source,$id,$actor,true);
        foreach(array_keys($row) as $key)if(preg_match('/(?:token|secret|credential)/i',$key))unset($row[$key]);
        $tables=[];$branches=[];$partyIds=[];
        $tables['order_board_clocks']=$this->rows('order_board_clocks',fn($q)=>$q->where('source',$source)->where('order_id',$id),['id']);
        if($source==='legacy'){
            $branches=['f:'.(int)$row['resturant_id']];$fulfillment=FasakhanstaStoreFulfillment::branch($branches[0]);$branches=array_values(array_unique([...$branches,$fulfillment]));
            $restaurantIds=array_map(fn($branch)=>(int)substr($branch,2),$branches);
            $tables['resturants']=$this->rows('resturants',fn($q)=>$q->whereIn('id',$restaurantIds));
            $partyIds=[(int)$row['user_id'],(int)($row['delegate_id']??0),...array_column($tables['resturants'],'user_id')];
            $tables['carts']=$this->rows('carts',fn($q)=>$q->where('order_id',$id));
            $tables['user_address']=$this->rows('user_address',fn($q)=>$q->where('id',$row['user_address_id']??0));
            $tables['payments']=$this->rows('payments',fn($q)=>$q->where('order_id',$id));
            $tables['delegate_notifications']=$this->rows('delegate_notifications',fn($q)=>$q->where('order_id',$id));
            $tables['resturant_products']=$this->rows('resturant_products',fn($q)=>$q->whereIn('resturant_id',$restaurantIds));
            $productIds=array_column($tables['resturant_products'],'product_id');
            $tables['product_features']=$this->rows('product_features',fn($q)=>$q->whereIn('product_id',$productIds));
            $tables['stock_ingredients']=$this->rows('stock_ingredients',fn($q)=>$q);
            foreach(['branch_inventory','branch_stock','branch_stock_recipes','branch_basic_recipes'] as $table)
                $tables[$table]=$this->rows($table,fn($q)=>Schema::hasColumn($table,'branch')?$q->whereIn('branch',$branches):$q,['id']);
            foreach(['branch_recipe_sales','branch_inventory_movements','branch_stock_movements'] as $table)
                $tables[$table]=$this->rows($table,fn($q)=>$q->where('source_type','app')->where('source_id',(string)$id),['id','stock_id']);
            $tables['coupon_wheels']=$this->rows('coupon_wheels',fn($q)=>$q);
            $tables['coupon_wheel_resturants']=$this->rows('coupon_wheel_resturants',fn($q)=>$q->whereIn('resturant_id',$restaurantIds),['id']);
            $tables['coupon_subscripes']=$this->rows('coupon_subscripes',fn($q)=>$q->where('user_id',$row['user_id']));
            $tables['competition_orders']=DB::table('orders')->where('user_id',$row['user_id'])->orderBy('id')->lockForUpdate()
                ->limit(10001)->get(['id','user_id','type','status','coupon_wheel_id'])->map(fn($r)=>(array)$r)->all();
            abort_if(count($tables['competition_orders'])>10000,413);
        }elseif($source==='store'){
            $branches=['gs:'.(int)$row['store_id']];$partyIds=[(int)$row['customer_id'],(int)$row['store_id']];
            $tables['go_stores']=$this->rows('go_stores',fn($q)=>$q->where('user_id',$row['store_id']));
        }elseif($source==='service'){
            $partyIds=[(int)$row['customer_id'],(int)($row['partner_id']??0)];
            foreach(['go_service_offers','go_service_recipients','go_service_assignments','go_service_ledger','go_service_payments'] as $table)
                $tables[$table]=$this->rows($table,fn($q)=>$q->where('job_id',$id));
            foreach($tables['go_service_recipients'] as $recipient)$partyIds[]=(int)$recipient['partner_id'];
            $noticeUsers=array_filter([(int)$row['customer_id'],(int)($row['partner_id']??0)]);
            $keys=array_map(fn($userId)=>'job:'.$id.':cancelled:user:'.$userId,$noticeUsers);
            $tables['go_service_outbox']=$this->rows('go_service_outbox',fn($q)=>$q->whereIn('event_key',$keys),['id']);
        }else $partyIds=[(int)$row['user_id'],(int)$row['partner_id']];
        $partyIds=array_values(array_unique(array_filter($partyIds)));sort($partyIds,SORT_NUMERIC);
        $tables['users']=DB::table('users')->whereIn('id',$partyIds)->orderBy('id')->lockForUpdate()->get(self::USER)->map(fn($r)=>(array)$r)->all();
        abort_unless(count($tables['users'])===count($partyIds),409,'أحد أطراف الطلب لم يعد موجودًا.');
        $pendingIds=array_values(array_filter(array_column($tables['users'],'pending_vendor_id')));
        $tables['pending_vendors']=$this->rows('pending_vendors',fn($q)=>$q->whereIn('id',$pendingIds));
        $settingNames=array_map(fn($property)=>$property->getName(),(new \ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC));
        $tables['settings']=$this->rows('settings',fn($q)=>$q->where('group','general')->whereIn('name',$settingNames),['id']);
        $tables['wallets']=$this->rows('wallets',fn($q)=>$q->where(function($q)use($source,$id,$partyIds){
            $q->whereIn('from_user',$partyIds)->orWhereIn('to_user',$partyIds);
            if($source==='legacy')$q->orWhere('order_id',$id);if($source==='store')$q->orWhere('transfer_reference','like','gs:'.$id.':%');
        }));
        $phones=[];foreach($tables['users'] as $user)if($user['account_type']==='user')$phones[]=$user['mobile'];
        foreach($tables['user_address']??[] as $address)$phones[]=$address['mobile']??null;
        if($source==='store'){$snapshot=json_decode($row['snapshot']??'{}',true,512,JSON_THROW_ON_ERROR);$phones[]=$snapshot['customer_mobile']??null;$phones[]=$snapshot['address']['mobile']??$snapshot['address']['phone']??$snapshot['address']['customer_phone']??null;}
        $phones=array_values(array_unique(array_filter(array_map(fn($phone)=>CustomerDirectory::normalizePhone((string)$phone),$phones))));sort($phones,SORT_STRING);
        $crm=[];foreach($phones as $phone){
            $customer=Schema::hasTable('crm_customers')?DB::table('crm_customers')->where('phone',$phone)->lockForUpdate()->first():null;
            $crm[]=['phone'=>$phone,'customer'=>$customer?(array)$customer:null,
                'sources'=>$customer?$this->rows('crm_customer_sources',fn($q)=>$q->where('customer_id',$customer->id)->whereIn('branch',$branches),['id']):[]];
        }
        $purchaseType=$source==='legacy'?'app_order':'go_store_order';
        $tables['crm_purchases']=in_array($source,['legacy','store'],true)?$this->rows('crm_purchases',fn($q)=>$q->where('source_type',$purchaseType)->where('source_id',(string)$id),['id']):[];
        $graph=['source'=>$source,'row'=>$row,'branches'=>$branches,'tables'=>$tables,'crm'=>$crm];
        abort_if(strlen(DesktopDashboardBootstrap::json($graph))>16*1024*1024,413);return $graph;
    }
    private function facts(array $payload): array
    {
        abort_if(array_diff(array_keys($payload),['parameters','values','files','facts']),422);$p=$this->parameters($payload['parameters']??[]);$this->values($payload['values']??[]);
        abort_unless(empty($payload['files'])&&array_keys($payload['facts']??[])===['order_board_before'],422);$before=$payload['facts']['order_board_before'];
        abort_unless(is_array($before)&&array_keys($before)===['source','row','branches','tables','crm']&&$before['source']===$p['source']
            &&is_array($before['row'])&&(int)($before['row']['id']??0)===$p['id']&&is_array($before['tables'])&&is_array($before['crm']),409);
        return $before;
    }
    private function admitted(array $p,array $values,array $before): void
    {
        if($p['source']==='legacy'){
            abort_unless(($before['row']['type']??null)==='current',501,'الطلب المحلي يقتصر على طلبات المطعم الحالية.');
            abort_if($values['action']==='dispatch',501,'اختيار مندوب خارجي يتم من الداشبورد المتصل.');
            abort_if($values['action']==='reject'&&($before['row']['payment_type']??null)!=='cash',501,'رفض الطلب المدفوع يحتاج مراجعة التسوية من الداشبورد المتصل.');
            abort_if($values['action']==='complete'&&($before['row']['payment_type']??null)!=='cash',501,'تحصيل الطلب المدفوع إلكترونيًا يحتاج مراجعة سجل الدفع من الداشبورد المتصل.');
        }
        if($p['source']==='store'){
            $row=$before['row'];
            abort_if(($row['payment_status']??'')==='held'&&in_array($values['action'],['reject','cancel','complete'],true),501,
                'تسوية حجز محفظة المتجر تحتاج سجل الحجز الأصلي من الداشبورد المتصل.');
            if(($row['payment_status']??'')==='paid'){
                $gross=array_values(array_filter($before['tables']['wallets']??[],fn($movement)=>($movement['transfer_reference']??null)==='gs:'.$p['id'].':gross'));
                abort_unless(count($gross)===1&&$gross[0]['status']==='completed'&&$gross[0]['payment']==='wallet'&&$gross[0]['type']==='transfer'
                    &&$gross[0]['from_user']===null&&(int)$gross[0]['to_user']===(int)$row['store_id'],409,'قيد الدفع الإلكتروني للمتجر يحتاج مراجعة.');
                try{$amount=\App\Services\GoServices\Money::minor($gross[0]['amount']);}catch(\InvalidArgumentException $error){abort(409,'قيد الدفع الإلكتروني للمتجر يحتاج مراجعة.');}
                abort_unless($amount===(int)$row['total_cents'],409,'قيد الدفع الإلكتروني للمتجر يحتاج مراجعة.');
            }
        }
    }
    private function actor(User $actor,object $scope): User
    {
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        // The owner snapshot is the only captured cohort with the actual app balance,
        // all service sources, coupon state and complete financial party ledgers.
        abort_unless($fresh&&(int)$fresh->id===1&&$fresh->account_type==='admin'&&empty($fresh->owner_resturant_id)
            &&!in_array($fresh->status,['disabled','declined'],true)&&(int)$scope->actor_id===(int)$fresh->id,403);
        abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        $enrolled=json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR);abort_unless(is_array($enrolled)&&count($enrolled)>0,403);
        return $fresh;
    }
    public function authorize(array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($payload);$p=$this->parameters($payload['parameters']);$this->original();$fresh=$this->actor($actor,$scope);
        $this->admitted($p,$payload['values'],$before);
        foreach($before['branches'] as $branch)app(DesktopDashboardDevices::class)->branch($scope,$branch,$fresh);
        $current=(array)app(OrderBoardService::class)->scopedRow($p['source'],$p['id'],$fresh,true);
        foreach(['id','user_id','customer_id','store_id','resturant_id','partner_id','type','created_at'] as $field)
            if(array_key_exists($field,$before['row']))abort_unless(($current[$field]??null)===$before['row'][$field],409,'هوية الطلب أو أحد أطرافه تغيّرت.');
        return $fresh;
    }
    public function payload(Request $request,string $command,User $actor,object $scope,?array $saved=null): array
    {
        $actor=$this->actor($actor,$scope);
        abort_if($request->allFiles(),501);$p=$this->parameters($request->route()->parameters());
        $values=$request->except('_token','_desktop_command');abort_if(isset($values['idempotency_key'])&&$values['idempotency_key']!==$command,409);$values['idempotency_key']=$command;$this->values($values);
        $facts=$saved??app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,self::ROUTE)
            ??['order_board_before'=>$this->graph($p,$actor)];
        $payload=['parameters'=>$p,'values'=>$values,'files'=>[],'facts'=>$facts];$this->authorize($payload,$actor,$scope);return $payload;
    }
    private function paths(array $payload): array
    {
        $source=$payload['parameters']['source']??null;abort_unless(isset(self::ENTITIES[$source]),422);$entity=self::ENTITIES[$source];
        $paths=['parameters.id'=>$entity,'facts.order_board_before.row.id'=>$entity];
        $columns=['user_id'=>'wallet_account','customer_id'=>'wallet_account','store_id'=>'wallet_account','delegate_id'=>'wallet_account','partner_id'=>'wallet_account','resturant_id'=>'menu_restaurant','user_address_id'=>'user_address','wallet_id'=>'wallet_cash_movement','coupon_wheel_id'=>'coupon_wheel'];
        foreach($columns as $column=>$kind)$paths['facts.order_board_before.row.'.$column]=$kind;
        $ids=['users'=>'wallet_account','wallets'=>'wallet_cash_movement','resturants'=>'menu_restaurant','carts'=>'legacy_cart','resturant_products'=>'menu_restaurant_product',
            'product_features'=>'catalog_product_feature','stock_ingredients'=>'stock_ingredient','pending_vendors'=>'pending_vendor','user_address'=>'user_address','coupon_wheels'=>'coupon_wheel',
            'go_service_offers'=>'service_offer','go_service_recipients'=>'service_recipient','go_service_ledger'=>'service_ledger','go_service_payments'=>'service_payment','payments'=>'legacy_payment','delegate_notifications'=>'delegate_notification',
            'coupon_subscripes'=>'coupon_subscription','competition_orders'=>'legacy_order'];
        foreach($payload['facts']['order_board_before']['tables']??[] as $table=>$rows)foreach($rows as $i=>$row){
            $base='facts.order_board_before.tables.'.$table.'.'.$i.'.';if(isset($ids[$table]))$paths[$base.'id']=$ids[$table];
            foreach(['from_user'=>'wallet_account','to_user'=>'wallet_account','user_id'=>'wallet_account','partner_id'=>'wallet_account','customer_id'=>'wallet_account',
                'added_by'=>'wallet_account','updated_by'=>'wallet_account','actor_id'=>'wallet_account','area_id'=>'catalog_area','category_id'=>'catalog_category',
                'pending_vendor_id'=>'pending_vendor','owner_resturant_id'=>'menu_restaurant','resturant_id'=>'menu_restaurant','parent_id'=>'menu_restaurant','resturant_product_id'=>'menu_restaurant_product',
                'product_feature'=>'catalog_product_feature','ingredient_id'=>'stock_ingredient','coupon_wheel_id'=>'coupon_wheel','wallet_id'=>'wallet_cash_movement'] as $field=>$kind)
                if(array_key_exists($field,$row))$paths[$base.$field]=$kind;
            if($table==='crm_purchases'&&array_key_exists('customer_id',$row))$paths[$base.'customer_id']='crm_customer';
            if(array_key_exists('product_id',$row))$paths[$base.'product_id']=in_array($table,['branch_stock','branch_stock_recipes'],true)?'menu_restaurant_product':'catalog_product';
            if(array_key_exists('order_id',$row))$paths[$base.'order_id']=$table==='order_board_clocks'?$entity:'legacy_order';
        }
        foreach($payload['facts']['order_board_before']['crm']??[] as $i=>$target){
            $paths['facts.order_board_before.crm.'.$i.'.customer.id']='crm_customer';
            foreach($target['sources']??[] as $j=>$row)$paths['facts.order_board_before.crm.'.$i.'.sources.'.$j.'.customer_id']='crm_customer';
        }
        return $paths;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach($this->paths($payload) as $path=>$entity){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($entity,$value));}
        $carrier=['facts'=>['customer_directory'=>['targets'=>$payload['facts']['order_board_before']['crm']??[]]]];
        $carrier=app(DesktopDashboardCustomerFacts::class)->inputs($carrier,$reference);
        if(isset($payload['facts']['order_board_before']))$payload['facts']['order_board_before']['crm']=$carrier['facts']['customer_directory']['targets'];
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach($this->paths($payload) as $path=>$entity){$value=data_get($payload,$path);if(is_array($value))abort_unless(count($value)===1
            &&preg_match('/^'.preg_quote($entity,'/').'(?:\.[0-9]{1,3})?$/D',$value['$desktop_ref']['entity']??''),422);}
    }
    private function fingerprint(array $graph): string
    {
        foreach($graph['tables'] as &$rows)$rows=$this->sorted($rows);unset($rows);return app(DesktopDashboardJournal::class)->fingerprint($graph);
    }
    private function withIdentities(array $before,callable $work): array
    {
        $expected=[];foreach($before['crm'] as $target)$expected[$target['phone']]=$target['customer']['id']??null;
        return app(DesktopDashboardCustomerFacts::class)->withIdentities($expected,$work);
    }
    public function capture(array $payload,User $actor,callable $work): array
    {
        $before=$this->facts($payload);$p=$this->parameters($payload['parameters']);
        return $this->withIdentities($before,function()use($work,$p,$actor,$before){
            $response=$work();abort_unless($response->getStatusCode()===200,409);$json=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);abort_unless(($json['success']??false)===true,409);
            $after=$this->graph($p,$actor);$references=[self::ENTITIES[$p['source']]=>$p['id']];
            $oldWalletIds=array_column($before['tables']['wallets'],'id');$newWallets=array_values(array_filter($after['tables']['wallets'],fn($row)=>!in_array($row['id'],$oldWalletIds,true)));
            usort($newWallets,fn($a,$b)=>strcmp((string)$a['transfer_reference'],(string)$b['transfer_reference']));
            foreach($newWallets as $i=>$wallet)$references['wallet_cash_movement.'.$i]=(int)$wallet['id'];
            foreach($this->newDraws($after,$before) as $i=>$draw)$references['coupon_subscription.'.$i]=(int)$draw['id'];
            foreach($after['crm'] as $i=>$target)if($target['customer'])$references['crm_customer.'.$i]=(int)$target['customer']['id'];
            return ['http'=>['status'=>200,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type')],'after'=>$after,'references'=>$references];
        });
    }
    public function outputs(array $result): array
    {
        $references=$result['references']??null;abort_unless(is_array($references)&&count($references)>0,409);
        foreach($references as $entity=>$id)abort_unless(preg_match('/^(?:legacy_order|store_order|service_job|partner_service_request|wallet_cash_movement\.[0-9]+|crm_customer\.[0-9]+|coupon_subscription\.[0-9]+)$/D',$entity)&&is_int($id)&&$id>0,409);
        return $references;
    }
    private function newDraws(array $after,array $before): array
    {
        $old=array_map(fn($r)=>(int)(is_array($r['id'])?($r['id']['$desktop_ref']['local_id']??0):$r['id']),$before['tables']['coupon_subscripes']??[]);
        $new=array_values(array_filter($after['tables']['coupon_subscripes']??[],fn($r)=>!in_array((int)$r['id'],$old,true)));
        usort($new,fn($a,$b)=>strcmp($a['user_coupon_code'],$b['user_coupon_code']));return $new;
    }
    private static ?array $drawCodes=null;
    public static function competitionCode(int $length,callable $ordinary): string
    {
        if(self::$drawCodes===null)return $ordinary();
        $code=array_shift(self::$drawCodes);abort_unless(is_string($code)&&strlen($code)===$length&&preg_match('/^[A-Z0-9]+$/D',$code),409);
        abort_if(DB::table('coupon_subscripes')->where('user_coupon_code',$code)->lockForUpdate()->exists(),409,'كود دخول المسابقة المحلية مستخدم بالفعل.');return $code;
    }
    public function replayContext(array $local,array $localPayload,callable $work)
    {
        abort_unless(self::$drawCodes===null,409);$this->outputs($local);$draws=$this->newDraws($local['after'],$localPayload['facts']['order_board_before']);
        abort_if(count($draws)>1,409);$codes=[];foreach($draws as $i=>$draw){
            abort_unless(($local['references']['coupon_subscription.'.$i]??null)===(int)$draw['id']&&is_string($draw['user_coupon_code'])&&preg_match('/^[A-Z0-9]{10}$/D',$draw['user_coupon_code']),409);
            $codes[]=$draw['user_coupon_code'];
        }
        self::$drawCodes=$codes;
        try{$result=$work();abort_unless(self::$drawCodes===[],409,'نتيجة دخول المسابقة لا تطابق العملية المحلية.');return $result;}
        finally{self::$drawCodes=null;}
    }
    public function verifyAfter(array $result,array $local,array $payload=[],array $localPayload=[]): void
    {
        abort_unless(isset($result['after'],$local['after']),409);$mapping=[];
        if($localPayload&&$payload)foreach($this->paths($localPayload) as $path=>$entity){
            $from=data_get($localPayload,$path);$to=data_get($payload,$path);
            if(is_array($from))$from=$from['$desktop_ref']['local_id']??null;
            if(is_numeric($from)&&is_numeric($to)&&(int)$from>0&&(int)$to>0)$mapping[$entity][(int)$from]=(int)$to;
        }
        foreach($this->outputs($local) as $entity=>$from){$to=$this->outputs($result)[$entity]??null;abort_unless(is_int($to)&&$to>0,409);
            $kind=preg_replace('/\.[0-9]+$/D','',$entity);$mapping[$kind][$from]=$to;
        }
        $expected=['parameters'=>['source'=>$local['after']['source'],'id'=>$local['after']['row']['id']],
            'facts'=>['order_board_before'=>$local['after']]];
        foreach($this->paths($expected) as $path=>$entity){$value=data_get($expected,$path);
            if(is_numeric($value)&&isset($mapping[$entity][(int)$value]))data_set($expected,$path,$mapping[$entity][(int)$value]);
        }
        // Board transitions never renew messaging consent. Evidence may refer to
        // an earlier mapped POS receipt/ticket, so preserve its proved server
        // representation rather than comparing unrelated integer namespaces.
        $localBefore=collect($localPayload['facts']['order_board_before']['crm']??[])->keyBy('phone');
        $serverBefore=collect($payload['facts']['order_board_before']['crm']??[])->keyBy('phone');
        foreach($expected['facts']['order_board_before']['crm'] as &$target){
            $old=$localBefore->get($target['phone']);$serverOld=$serverBefore->get($target['phone']);
            if(!$old||!$serverOld||!$old['customer']||!$serverOld['customer']||!$target['customer'])continue;
            foreach(['service_consent','marketing_consent','service_consented_at','marketing_consented_at','service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at'] as $field){
                $localValue=$old['customer'][$field];
                if(is_array($localValue)&&isset($localValue['$desktop_crm_evidence'])){$e=$localValue['$desktop_crm_evidence'];$localValue=$e['prefix'].$e['reference']['$desktop_ref']['local_id'];}
                abort_unless($target['customer'][$field]===$localValue,409,'تحديث الطلب لا يسمح بتغيير موافقة رسائل العميل.');
                $target['customer'][$field]=$serverOld['customer'][$field];
            }
        }unset($target);
        abort_unless(hash_equals($this->fingerprint($result['after']),$this->fingerprint($expected['facts']['order_board_before'])),409,
            'نتيجة الطلب المحلية مختلفة عن التسوية الحالية؛ العملية محفوظة للمراجعة.');
    }
    public function response(array $result) {return response($result['http']['content'],$result['http']['status'])->header('Content-Type',$result['http']['type'])->header('Cache-Control','private, no-store');}
    public function execute(array $payload,User $actor): array
    {
        $before=$this->facts($payload);$p=$this->parameters($payload['parameters']);$this->admitted($p,$payload['values'],$before);
        abort_unless(hash_equals($this->fingerprint($before),$this->fingerprint($this->graph($p,$actor))),409,'بيانات الطلب أو الرصيد أو المخزون تغيّرت؛ العملية المحلية محفوظة للمراجعة.');
        $values=$payload['values'];unset($values['idempotency_key']);$original=$this->original();
        $request=Request::create(url('admin/order-board/'.$p['source'].'/'.$p['id'].'/action'),'POST',$values);$request->headers->set('Accept','application/json');
        $session=new Store('desktop-order-board-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($name=null)=>auth($name??'admin')->user());
            $router=app('router');$middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($payload,$actor,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
