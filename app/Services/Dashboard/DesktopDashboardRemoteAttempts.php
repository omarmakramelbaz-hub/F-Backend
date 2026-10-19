<?php
namespace App\Services\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Support\Facades\{DB,Schema,Validator,Crypt};

/** A terminal server decision prevents a delayed original request from writing after recovery. */
class DesktopDashboardRemoteAttempts
{
    // Reviewed original actions; expense attachments use durable immutable private files.
    public const CORE=[
        'read_notify'=>'can_read_own_notifications','mark_all_as_read'=>'can_read_own_notifications',
        'dashboard-inbox.notifications.read'=>'can_read_own_notifications',
        'takeaway.checkout'=>'can_checkout','takeaway.movements'=>'can_manage','takeaway.settings'=>'can_manage',
        'dining.save'=>'can_checkout','dining.action'=>'can_checkout','dining.settle'=>'can_checkout','dining.table-save'=>'can_manage_tables','dining.settings'=>'can_manage',
        'phone-orders.save'=>'can_checkout','phone-orders.action'=>'can_checkout','phone-orders.settle'=>'can_checkout',
        'phone-orders.dispatch-company'=>'can_checkout','phone-orders.finish-batch'=>'can_checkout',
        'customers.save'=>'can_checkout','customers.directory-save'=>'can_checkout','customers.consent'=>'can_checkout',
        'delivery-companies.save'=>'can_checkout','branch-shifts.close'=>'can_checkout',
        'branch-stock.minimum-save'=>'can_manage_inventory','branch-stock.basic-apply'=>'can_manage_inventory',
        'branch-stock.receive'=>'can_checkout','branch-stock.recipe-save'=>'can_manage_inventory',
        'branch-expenses.review'=>'can_approve_expense',
        'branch-expenses.save'=>'can_checkout',
        'branch-expenses.categorySave'=>'can_manage_expense_categories',
        'employees.save'=>'can_checkout','employees.attendance'=>'can_checkout','employees.entry'=>'can_checkout',
        'employees.wallet'=>'can_checkout','employees.daily-notes'=>'can_checkout','employees.close'=>'can_checkout','employees.pay'=>'can_checkout',
        'employees.attendance-rules'=>'can_payroll_owner','employees.void-entry'=>'can_payroll_owner',
    ];
    public function __construct(private DesktopDashboardDevices $devices) {}
    private function values(array $values): array
    {
        $v=Validator::make($values,['id'=>'required|uuid','method'=>'required|in:GET,POST,PUT,PATCH,DELETE','path'=>'required|string|max:200'])->validate();
        $single=preg_match('#^/admin/(?:areas|categorys|products|question_answers|features|contracts)(?:/[1-9][0-9]{0,18})?$#D',$v['path']);
        $roles=($v['method']==='POST'&&$v['path']==='/admin/roles')
            ||(in_array($v['method'],['POST','PUT','PATCH','DELETE'],true)&&preg_match('#^/admin/roles/[1-9][0-9]{0,18}$#D',$v['path']))
            ||($v['method']==='DELETE'&&$v['path']==='/admin/rolesDeleteAll');
        $bulk=preg_match('#^/admin/(?:areas|categorys|products|question_answers|features)DeleteAll$#D',$v['path'])&&$v['method']==='DELETE';
        $ordering=$v['path']==='/admin/post-sortable'&&$v['method']==='POST';
        $availability=$v['method']==='POST'&&preg_match('#^/admin/order-board/menu/(f|gs)/[1-9][0-9]{0,18}/products/[1-9][0-9]{0,18}/availability$#D',$v['path'],$menuPath);
        $store=$v['method']==='POST'&&preg_match('#^/admin/go-stores/[1-9][0-9]{0,18}$#D',$v['path']);
        $contact=(in_array($v['method'],['POST','DELETE'],true)&&preg_match('#^/admin/contacts/[1-9][0-9]{0,18}$#D',$v['path']))
            ||($v['method']==='DELETE'&&$v['path']==='/admin/contactsDeleteAll');
        $review=in_array($v['method'],['POST','DELETE'],true)&&preg_match('#^/admin/resturant_reviews/[1-9][0-9]{0,18}$#D',$v['path']);
        $wishlist=in_array($v['method'],['POST','DELETE'],true)&&preg_match('#^/admin/userWishlistsDelete/[1-9][0-9]{0,18}$#D',$v['path']);
        $restaurantControl=$v['method']==='GET'&&$v['path']==='/admin/resturantControl';
        $restaurantFlags=$v['method']==='POST'&&preg_match('#^/admin/(?:resturantchange(?:Status|UnderContract)|update_sorting_is_featured)/[1-9][0-9]{0,18}$#D',$v['path']);
        $restaurantProducts=$v['method']==='GET'&&preg_match('#^/admin/resturant_products/[1-9][0-9]{0,18}/update/(?:status|highest_rated)$#D',$v['path']);
        $productCrud=($v['method']==='POST'&&$v['path']==='/admin/resturant_products')
            ||(in_array($v['method'],['POST','PUT'],true)&&$v['path']==='/admin/resturant_products/update')
            ||(in_array($v['method'],['POST','DELETE'],true)&&preg_match('#^/admin/resturant_products/[1-9][0-9]{0,18}$#D',$v['path']));
        $menuCopy=$v['method']==='POST'&&$v['path']==='/admin/resturant/copy/items';
        $wallet=$v['method']==='POST'&&in_array($v['path'],['/admin/wallets/transfer','/admin/wallets/withdraw/store'],true);
        // Only the exact reviewed original GET mutation links may reserve a write; ordinary reads cannot.
        abort_if($v['method']==='GET'&&!$restaurantProducts&&!$restaurantControl,422);
        $history=in_array($v['method'],['POST','PUT'],true)&&preg_match('#^/admin/read/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$#iD',$v['path']);
        $core=false;
        if($v['method']==='POST'&&str_starts_with($v['path'],'/admin/')){
            try{$route=app('router')->getRoutes()->match(Request::create($v['path'],'POST'));$core=isset(self::CORE[$route->getName()??'']);}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$core=false;}
        }
        abort_unless($wallet||$single||$roles||$bulk||$ordering||$availability||$store||$contact||$review||$wishlist||$restaurantFlags||$restaurantControl||$restaurantProducts||$productCrud||$menuCopy||$core||$history,422,'تأكيد نتيجة هذا القسم لم يُجهّز بعد.');
        abort_unless(Schema::hasTable('desktop_dashboard_remote_attempts'),503,'سجل نتائج السيرفر لم يُجهّز بعد.');
        foreach(['desktop_dashboard_devices','desktop_dashboard_remote_attempts','categories','products','product_features'] as $table){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$table]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد نتيجة السيرفر يحتاج جداول تدعم المعاملات.');
        }
        $table=match(true){$roles=>'roles',$history||$v['path']==='/admin/read/all/notification'=>'notifications',str_starts_with($v['path'],'/admin/contracts')=>'contracts',str_starts_with($v['path'],'/admin/features')=>'features',str_starts_with($v['path'],'/admin/areas')=>'areas',str_starts_with($v['path'],'/admin/question_answers')=>'question_answers',$contact=>'contacts',default=>null};
        if($roles)foreach(['permissions','role_has_permissions','model_has_roles'] as $roleTable){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$roleTable]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد الدور يحتاج جداول تدعم المعاملات.');
        }
        if($review)foreach(['reviews','resturants','orders','users'] as $reviewTable){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$reviewTable]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد حذف التقييم يحتاج جداول تدعم المعاملات.');
        }
        if($wishlist)foreach(['wishlists','resturants','users','roles','model_has_roles'] as $wishlistTable){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$wishlistTable]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد حذف المفضلة يحتاج جداول تدعم المعاملات.');
        }
        if($wallet)foreach(['wallets','users','notifications','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions'] as $walletTable){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$walletTable]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد المحفظة يحتاج جداول تدعم المعاملات.');
        }
        if($restaurantFlags||$restaurantControl)foreach(['resturants','users','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions'] as $flagTable){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$flagTable]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد علامة المطعم يحتاج جداول تدعم المعاملات.');
        }
        if($restaurantProducts||$productCrud||$menuCopy)foreach(['resturant_products','resturants','users','products','categories','product_features','media','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions'] as $productTable){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$productTable]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد حالة صنف المطعم يحتاج جداول تدعم المعاملات.');
        }
        if($availability)foreach(array_merge(['users','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions'],
            $menuPath[1]==='f'?['resturants','resturant_products']:['go_stores','go_store_products','pending_vendors']) as $menuTable){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$menuTable]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد إتاحة الصنف يحتاج جداول تدعم المعاملات.');
        }
        if($store)foreach(['users','go_stores','pending_vendors','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions'] as $storeTable){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$storeTable]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد بيانات المتجر يحتاج جداول تدعم المعاملات.');
        }
        if($table){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$table]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد نتيجة هذا القسم يحتاج جدولاً يدعم المعاملات.');
        }
        if($core){
            $tables=DesktopDashboardSchema::TABLES;
            $engines=DB::select('SELECT TABLE_NAME AS name,ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).')',array_merge([DB::connection()->getDatabaseName()],$tables));
            foreach($engines as $engine)abort_unless(strcasecmp($engine->engine,'InnoDB')===0,503,'تأكيد العملية المالية يحتاج جداول تدعم المعاملات.');
        }
        return $v;
    }
    private function capability(object $row): string
    {
        return hash_hmac('sha256',json_encode([$row->id,$row->device_id,(int)$row->actor_id,$row->method,$row->path]),(string)config('app.key'));
    }
    private function device(object $device): object
    {
        $fresh=DB::table('desktop_dashboard_devices')->where('id',$device->id)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->enabled&&(int)$fresh->actor_id===(int)$device->actor_id,401);
        $this->devices->actor($fresh);return $fresh;
    }
    public function decide(object $device,array $values,bool $cancel): array
    {
        $v=$this->values($values);
        return DB::transaction(function()use($device,$v,$cancel){
            $fresh=$this->device($device);
            $row=DB::table('desktop_dashboard_remote_attempts')->where('id',$v['id'])->lockForUpdate()->first();
            if(!$row){
                DB::table('desktop_dashboard_remote_attempts')->insert(['id'=>$v['id'],'device_id'=>$fresh->id,'actor_id'=>$fresh->actor_id,
                    'method'=>$v['method'],'path'=>$v['path'],'status'=>$cancel?'cancelled':'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
                $row=DB::table('desktop_dashboard_remote_attempts')->where('id',$v['id'])->first();
            }
            abort_unless($row->device_id===$fresh->id&&(int)$row->actor_id===(int)$fresh->actor_id&&$row->method===$v['method']&&$row->path===$v['path'],409);
            if($cancel&&$row->status==='ready'){
                DB::table('desktop_dashboard_remote_attempts')->where('id',$row->id)->update(['status'=>'cancelled','updated_at'=>now('UTC')]);$row->status='cancelled';
            }
            if($row->status==='committed'){
                if($row->method==='POST'&&preg_match('#^/admin/go-stores/([1-9][0-9]{0,18})$#D',$row->path,$goPath)){
                    $adapter=app(DesktopDashboardGoStoreProfile::class);$adapter->authorizeActor($this->devices->actor($fresh));
                    // A past commit remains terminal even if its profile was
                    // removed; current grants and enrolled owner scope still bind acknowledgment.
                    $adapter->enrolledBranch($fresh,['owner'=>$goPath[1]]);
                }
                abort_unless(preg_match('/^[a-f0-9]{64}$/D',$row->request_hash??'')&&is_string($row->response_cipher),503);
                $saved=json_decode(Crypt::decryptString($row->response_cipher),true,512,JSON_THROW_ON_ERROR);
                abort_unless(is_array($saved)&&isset($saved['status'],$saved['operation'])&&$saved['status']>=200&&$saved['status']<400,503);
                if($row->method==='POST'&&in_array($row->path,['/admin/customers/directory-save','/admin/customers/consent'],true))
                    $this->customerReply($row->path==='/admin/customers/directory-save'?'customers.directory-save':'customers.consent',$saved,$this->devices->actor($fresh),$fresh);
                if($row->method==='POST'&&in_array($row->path,['/admin/wallets/transfer','/admin/wallets/withdraw/store'],true))
                    $this->walletReply($row->path==='/admin/wallets/transfer'?'wallets.transfer':'wallets.withdraw.store',$saved,$this->devices->actor($fresh),$fresh);
                if(isset($saved['restaurant_product_crud_payload'],$saved['restaurant_product_crud_result'])){
                    $adapter=app(DesktopDashboardRestaurantProductCrud::class);
                    abort_unless(DesktopDashboardRestaurantProductCrud::handles($saved['restaurant_product_crud_route']??null),503);
                    $adapter->authorize($saved['restaurant_product_crud_route'],$saved['restaurant_product_crud_payload'],$this->devices->actor($fresh),$fresh);
                    app(DesktopDashboardProductImages::class)->verifyResult($saved['restaurant_product_crud_result'],$saved['restaurant_product_crud_payload']['files']??[]);
                }
                if($row->method==='POST'&&$row->path==='/admin/resturant/copy/items'){
                    abort_unless(is_array($saved['restaurant_menu_copy_payload']??null)&&is_array($saved['restaurant_menu_copy_result']??null)
                        &&DesktopDashboardRestaurantMenuCopy::handles($saved['restaurant_menu_copy_route']??null),503);
                    $adapter=app(DesktopDashboardRestaurantMenuCopy::class);
                    // Settling a past copy still requires both CURRENT enrolled branch grants.
                    $adapter->authorize($saved['restaurant_menu_copy_route'],$saved['restaurant_menu_copy_payload'],$this->devices->actor($fresh),$fresh);
                    $adapter->verifyResult($saved['restaurant_menu_copy_result']);
                }
                if(preg_match('#^/admin/resturant_reviews/([1-9][0-9]{0,18})$#D',$row->path,$reviewPath)){
                    abort_unless(is_array($saved['review_facts']??null),503);
                    app(DesktopDashboardReviewDeletion::class)->authorize(['parameters'=>['review'=>(int)$reviewPath[1]],
                        'values'=>['idempotency_key'=>$saved['operation']],'files'=>[],'facts'=>$saved['review_facts']],$this->devices->actor($fresh),$fresh);
                }
                if(preg_match('#^/admin/userWishlistsDelete/([1-9][0-9]{0,18})$#D',$row->path,$wishlistPath)){
                    abort_unless(is_array($saved['wishlist_facts']??null),503);
                    app(DesktopDashboardWishlistDeletion::class)->authorize(['parameters'=>['id'=>(int)$wishlistPath[1]],
                        'values'=>['idempotency_key'=>$saved['operation']],'files'=>[],'facts'=>$saved['wishlist_facts']],$this->devices->actor($fresh),$fresh);
                }
                if($row->method==='GET'&&$row->path==='/admin/resturantControl'){
                    abort_unless(is_array($saved['restaurant_control_facts']??null),503);
                    app(DesktopDashboardRestaurantControl::class)->authorize(DesktopDashboardRestaurantControl::ROUTE,
                        ['parameters'=>[],'values'=>['idempotency_key'=>$saved['operation']],'files'=>[],'facts'=>$saved['restaurant_control_facts']],
                        $this->devices->actor($fresh),$fresh);
                }
                if($row->method==='POST'&&preg_match('#^/admin/(resturantchangeStatus|resturantchangeUnderContract|update_sorting_is_featured)/([1-9][0-9]{0,18})$#D',$row->path,$flagPath)){
                    abort_unless(is_array($saved['restaurant_flag_facts']??null),503);
                    $route=match($flagPath[1]){'resturantchangeStatus'=>'resturants.changeStatus','resturantchangeUnderContract'=>'resturants.changeUnderContract',default=>'update_sorting_is_featured'};
                    app(DesktopDashboardRestaurantFlags::class)->authorize($route,
                        ['parameters'=>['resturant'=>(int)$flagPath[2]],'values'=>['idempotency_key'=>$saved['operation']],
                            'files'=>[],'facts'=>$saved['restaurant_flag_facts']],$this->devices->actor($fresh),$fresh);
                }
                if($row->method==='GET'&&preg_match('#^/admin/resturant_products/([1-9][0-9]{0,18})/update/(status|highest_rated)$#D',$row->path,$productPath)){
                    abort_unless(is_array($saved['restaurant_product_facts']??null),503);
                    app(DesktopDashboardRestaurantProducts::class)->authorize('resturant_products.update.'.$productPath[2],
                        ['parameters'=>['id'=>(int)$productPath[1]],'values'=>['idempotency_key'=>$saved['operation']],
                            'files'=>[],'facts'=>$saved['restaurant_product_facts']],$this->devices->actor($fresh),$fresh);
                }
            }
            return ['format'=>1,'id'=>$row->id,'device_id'=>$row->device_id,'actor_id'=>(int)$row->actor_id,'method'=>$row->method,'path'=>$row->path,
                'status'=>$row->status,'capability'=>$cancel?null:$this->capability($row)];
        });
    }
    public function handle(Request $request,callable $next)
    {
        $this->devices->ready();
        $name=$request->route()?->getName();$catalog=DesktopDashboardLegacy::handles($name);
        $history=DesktopDashboardNotificationReads::handles($name);
        $availability=DesktopDashboardMenuAvailability::handles($name);
        $review=DesktopDashboardReviewDeletion::handles($name);
        $wishlist=DesktopDashboardWishlistDeletion::handles($name);
        $restaurantControl=DesktopDashboardRestaurantControl::handles($name);$restaurantFlags=$restaurantControl||DesktopDashboardRestaurantFlags::handles($name);$restaurantProducts=DesktopDashboardRestaurantProducts::handles($name);
        $productCrud=DesktopDashboardRestaurantProductCrud::handles($name);
        $menuCopy=DesktopDashboardRestaurantMenuCopy::handles($name);
        $wallet=DesktopDashboardWalletCash::handles($name);
        abort_unless($wallet||$catalog||$availability||$review||$wishlist||$restaurantFlags||$restaurantProducts||$productCrud||$menuCopy||isset(self::CORE[$name??'']),501);
        $files=$productCrud?app(DesktopDashboardProductImages::class)->files($request):app(DesktopDashboardExpenseAttachments::class)->files($request,$name);
        $id=(string)$request->header('X-Fasakhansta-Remote-Attempt');$capability=(string)$request->header('X-Fasakhansta-Remote-Capability');
        $v=$this->values(['id'=>$id,'method'=>strtoupper((string)$request->server('REQUEST_METHOD')),'path'=>'/'.$request->path()]);
        $candidate=DB::table('desktop_dashboard_remote_attempts')->where('id',$id)->first();abort_unless($candidate,409);
        $work=function()use($candidate,$request,$next,$v,$capability,$catalog,$history,$availability,$review,$wishlist,$restaurantFlags,$restaurantControl,$restaurantProducts,$productCrud,$menuCopy,$wallet,$name,$files){return DB::transaction(function()use($candidate,$request,$next,$v,$capability,$catalog,$history,$availability,$review,$wishlist,$restaurantFlags,$restaurantControl,$restaurantProducts,$productCrud,$menuCopy,$wallet,$name,$files){
            $device=DB::table('desktop_dashboard_devices')->where('id',$candidate->device_id)->first();abort_unless($device,401);$device=$this->device($device);
            $row=DB::table('desktop_dashboard_remote_attempts')->where('id',$candidate->id)->lockForUpdate()->first();
            abort_unless($row&&(int)$row->actor_id===(int)$device->actor_id&&$row->method===$v['method']&&$row->path===$v['path']&&preg_match('/^[a-f0-9]{64}$/D',$capability)
                &&hash_equals($this->capability($row),$capability),403);
            $actor=$this->devices->actor($device);abort_unless((int)auth('admin')->id()===(int)$actor->id,403);
            $reviewPayload=null;$wishlistPayload=null;$flagPayload=null;$walletPayload=null;
            if($wallet){
                $adapter=app(DesktopDashboardWalletCash::class);$operation=$adapter->command($request);
                $savedRow=$row->status==='committed'?$row:DB::table('desktop_dashboard_remote_attempts')->where('device_id',$device->id)->where('operation_id',$operation)->lockForUpdate()->first();
                $savedFacts=null;
                if($savedRow){
                    abort_unless($savedRow->status==='committed',409);$savedResult=json_decode(Crypt::decryptString($savedRow->response_cipher),true,512,JSON_THROW_ON_ERROR);
                    abort_unless(is_array($savedResult['wallet_cash_payload']['facts']??null),503);$savedFacts=$savedResult['wallet_cash_payload']['facts'];
                }
                $walletPayload=$adapter->payload($name,$request,$operation,$actor,$device,$savedFacts);
                $actor=$adapter->authorize($name,$walletPayload,$actor,$device);
            }
            elseif($restaurantFlags||$restaurantProducts||$productCrud||$menuCopy){
                $adapter=app(match(true){$restaurantControl=>DesktopDashboardRestaurantControl::class,$menuCopy=>DesktopDashboardRestaurantMenuCopy::class,$productCrud=>DesktopDashboardRestaurantProductCrud::class,$restaurantProducts=>DesktopDashboardRestaurantProducts::class,default=>DesktopDashboardRestaurantFlags::class});$operation=$adapter->command($request);
                $savedRow=$row->status==='committed'?$row:DB::table('desktop_dashboard_remote_attempts')->where('device_id',$device->id)->where('operation_id',$operation)->lockForUpdate()->first();
                $savedFacts=null;
                if($savedRow){
                    abort_unless($savedRow->status==='committed',409);
                    $savedResult=json_decode(Crypt::decryptString($savedRow->response_cipher),true,512,JSON_THROW_ON_ERROR);
                    $factsKey=$restaurantControl?'restaurant_control_facts':($menuCopy?'restaurant_menu_copy_facts':($productCrud?'restaurant_product_crud_facts':($restaurantProducts?'restaurant_product_facts':'restaurant_flag_facts')));
                    abort_unless(is_array($savedResult[$factsKey]??null),503);$savedFacts=$savedResult[$factsKey];
                }
                $flagPayload=$adapter->remotePayload($name,$request,$operation,$actor,$device,$savedFacts);
                $actor=$adapter->authorize($name,$flagPayload,$actor,$device);
            }
            elseif($review){
                $operation=app(DesktopDashboardReviewDeletion::class)->command($request);
                $savedRow=$row->status==='committed'?$row:DB::table('desktop_dashboard_remote_attempts')->where('device_id',$device->id)->where('operation_id',$operation)->lockForUpdate()->first();
                $savedFacts=null;
                if($savedRow){
                    abort_unless($savedRow->status==='committed',409);
                    $savedResult=json_decode(Crypt::decryptString($savedRow->response_cipher),true,512,JSON_THROW_ON_ERROR);
                    abort_unless(is_array($savedResult['review_facts']??null),503);$savedFacts=$savedResult['review_facts'];
                }
                $reviewPayload=app(DesktopDashboardReviewDeletion::class)->remotePayload($request,$operation,$actor,$device,$savedFacts);
            }
            elseif($wishlist){
                $operation=app(DesktopDashboardWishlistDeletion::class)->command($request);
                $savedRow=$row->status==='committed'?$row:DB::table('desktop_dashboard_remote_attempts')->where('device_id',$device->id)->where('operation_id',$operation)->lockForUpdate()->first();
                $savedFacts=null;
                if($savedRow){
                    abort_unless($savedRow->status==='committed',409);
                    $savedResult=json_decode(Crypt::decryptString($savedRow->response_cipher),true,512,JSON_THROW_ON_ERROR);
                    abort_unless(is_array($savedResult['wishlist_facts']??null),503);$savedFacts=$savedResult['wishlist_facts'];
                }
                $wishlistPayload=app(DesktopDashboardWishlistDeletion::class)->remotePayload($request,$operation,$actor,$device,$savedFacts);
            }
            elseif($catalog){
                app(DesktopDashboardLegacy::class)->authorize($actor,$name);
                if(DesktopDashboardGoStoreProfile::handles($name)){
                    $adapter=app(DesktopDashboardGoStoreProfile::class);$parameters=$request->route()->parameters();
                    $actor=$adapter->authorize($parameters,$actor);$adapter->enrolledBranch($device,$parameters);
                    auth('admin')->setUser($actor);
                }
            }
            elseif($availability){
                $parameters=$request->route()->parameters();$actor=app(DesktopDashboardMenuAvailability::class)->authorize($parameters,$actor)['actor'];
                app(DesktopDashboardMenuAvailability::class)->enrolledBranch($device,$parameters);
            }
            else{
                if($name==='branch-expenses.categorySave'&&$request->filled('branch'))$this->devices->branch($device,(string)$request->input('branch'),$actor);
                // Legacy branchless category receipts and own notifications retain their read-only retry scope.
                if(!in_array(self::CORE[$name],['can_manage_expense_categories','can_read_own_notifications'],true))$this->devices->branch($device,(string)$request->input('branch'),$actor);
                if(str_starts_with($name,'employees.'))abort_unless(in_array($actor->account_type,['admin','vendor','resturant_owner'],true),403);
                $permissions=app(TakeawayAccess::class)->permissions($actor);
                $allowed=match(self::CORE[$name]){
                    'can_read_own_notifications'=>true,
                    'can_manage_inventory'=>$permissions['can_checkout']&&app(BranchInventory::class)->canManage($actor),
                    'can_approve_expense'=>app(BranchExpenses::class)->permissions($actor)['can_approve'],
                    'can_manage_expense_categories'=>app(ExpenseCategories::class)->canCreate($actor),
                    'can_payroll_owner'=>$permissions['can_checkout']&&app(BranchPayroll::class)->canManageAttendance($actor),
                    default=>$permissions[self::CORE[$name]],
                };
                abort_unless($allowed,403);
                if($name==='employees.entry'&&$request->input('kind')==='bonus')
                    abort_unless(app(BranchPayroll::class)->canAddBonus($actor),403,'إضافة المكافآت متاحة للأونر فقط.');
            }
            // Even stored replies require the original CURRENT controller permissions, before model binding.
            $router=app('router');$middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),$request->route()->controllerMiddleware());
            (new Pipeline(app()))->send($request)->through($middleware)->then(fn()=>true);
            abort_if($row->status==='cancelled',409,'الطلب السابق أُلغي قبل تنفيذه؛ أعد المحاولة كعملية جديدة.');
            $operation=match(true){
                $wallet=>app(DesktopDashboardWalletCash::class)->command($request),
                $menuCopy=>app(DesktopDashboardRestaurantMenuCopy::class)->command($request),
                $productCrud=>app(DesktopDashboardRestaurantProductCrud::class)->command($request),
                $restaurantControl=>app(DesktopDashboardRestaurantControl::class)->command($request),
                $restaurantFlags=>app(DesktopDashboardRestaurantFlags::class)->command($request),
                $restaurantProducts=>app(DesktopDashboardRestaurantProducts::class)->command($request),
                $review=>app(DesktopDashboardReviewDeletion::class)->command($request),
                $wishlist=>app(DesktopDashboardWishlistDeletion::class)->command($request),
                DesktopDashboardGoStoreProfile::handles($name)=>app(DesktopDashboardGoStoreProfile::class)->command($request),
                default=>(string)(($catalog||$history)?($request->header('X-Fasakhansta-Command')?:$request->input('_desktop_command')):$request->input('idempotency_key')),
            };
            Validator::make(['operation_id'=>$operation],['operation_id'=>'required|uuid'])->validate();
            if($history)app(DesktopDashboardNotificationReads::class)->payload($request,$operation,$actor);
            $values=$request->except('_token','_method','_desktop_command','attachment',...($productCrud?['product_image']:[]));
            if($name==='categorys.reorder')$values=app(CategoryOrdering::class)->values($values);
            if(str_ends_with($v['path'],'DeleteAll')){
                abort_unless(is_string($values['ids']??null)&&preg_match('/^[1-9][0-9]{0,18}(?:,[1-9][0-9]{0,18}){0,199}$/D',$values['ids']),422);
                $values['ids']=array_map('intval',explode(',',$values['ids']));sort($values['ids']);
                Validator::make($values,['ids'=>'required|array|min:1|max:200','ids.*'=>'required|integer|min:1|distinct'])->validate();
            }
            $fingerprintValues=['route'=>$request->route()->getName(),'path'=>$v['path'],'method'=>$request->method(),'values'=>$values];
            if($files)$fingerprintValues['files']=$files;
            $fingerprint=app(DesktopDashboardJournal::class)->fingerprint($fingerprintValues);
            if($row->status==='committed'){
                abort_unless(hash_equals($row->request_hash,$fingerprint),409,'محتوى الطلب يختلف عن العملية المحفوظة.');
                $saved=json_decode(Crypt::decryptString($row->response_cipher),true,512,JSON_THROW_ON_ERROR);
                abort_unless(($saved['operation']??null)===$operation,409);
                $this->customerReply($name,$saved,$actor,$device);if($wallet)$this->walletReply($name,$saved,$actor,$device);return $this->response($saved,$files);
            }
            abort_unless($row->status==='ready',409);
            $previous=DB::table('desktop_dashboard_remote_attempts')->where('device_id',$device->id)->where('operation_id',$operation)->lockForUpdate()->first();
            if($previous){
                abort_unless($previous->status==='committed'&&hash_equals($previous->request_hash,$fingerprint),409,'محتوى العملية يختلف عن طلبها المحفوظ.');
                $saved=json_decode(Crypt::decryptString($previous->response_cipher),true,512,JSON_THROW_ON_ERROR);
                $this->customerReply($name,$saved,$actor,$device);
                if($wallet)$this->walletReply($name,$saved,$actor,$device);
                DB::table('desktop_dashboard_remote_attempts')->where('id',$row->id)->update(['status'=>'committed','request_hash'=>$fingerprint,'response_cipher'=>$previous->response_cipher,'updated_at'=>now('UTC')]);
                return $this->response($saved,$files);
            }
            $request->request->remove('_desktop_command');$request->query->remove('_desktop_command');
            if($productCrud){$request->request->remove('_method');$request->query->remove('_method');}
            $flagResult=null;
            if($wallet){
                $request->request->set('idempotency_key',$operation);$response=null;
                app(DesktopDashboardWalletCash::class)->capture($name,$request,function()use($request,$next,&$response){return $response=$next($request);});
            }
            elseif($restaurantFlags||$restaurantProducts||$productCrud||$menuCopy){
                $guard=auth('admin');$previousActor=$guard->getUser();$response=null;
                try{
                    $guard->setUser($actor);
                    $flagResult=app(match(true){$restaurantControl=>DesktopDashboardRestaurantControl::class,$menuCopy=>DesktopDashboardRestaurantMenuCopy::class,$productCrud=>DesktopDashboardRestaurantProductCrud::class,$restaurantProducts=>DesktopDashboardRestaurantProducts::class,default=>DesktopDashboardRestaurantFlags::class})->capture($name,$flagPayload,function()use($request,$next,&$response){return $response=$next($request);});
                }finally{if($previousActor)$guard->setUser($previousActor);else (function(){$this->user=null;})->call($guard);}
            }else $response=$next($request);
            if($response->getStatusCode()>=400||($request->hasSession()&&in_array('errors',$request->session()->get('_flash.new',[]),true)))
                throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            // Unhandled exceptions roll back both original rows and the result. Recovery can then cancel the reservation.
            abort_if(strlen($response->getContent())>1024*1024,413);
            if($menuCopy)app(DesktopDashboardRestaurantMenuCopy::class)->verifyResult($flagResult);
            elseif($productCrud)app(DesktopDashboardProductImages::class)->verifyResult($flagResult,$files);
            elseif($files)app(DesktopDashboardExpenseAttachments::class)->verifyResult(json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR),$files);
            $saved=['operation'=>$operation,'status'=>$response->getStatusCode(),'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$response->headers->get('Location')];
            if($wallet){$saved['wallet_cash_route']=$name;$saved['wallet_cash_payload']=$walletPayload;$saved['wallet_cash_success']=session('success');}
            if(DesktopDashboardCustomerDirectory::handles($name)){
                $result=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);abort_unless(is_array($result),503);
                $saved['customer_directory_binding']=app(DesktopDashboardCustomerDirectory::class)->remoteBinding($name,$values,$operation,$result,$actor,$device);
            }
            if($review){$saved['review_facts']=$reviewPayload['facts'];$saved['review_success']=session('success');}
            if($wishlist){$saved['wishlist_facts']=$wishlistPayload['facts'];$saved['wishlist_success']=session('success');}
            if($restaurantFlags||$restaurantProducts||$productCrud||$menuCopy){$prefix=$restaurantControl?'restaurant_control':($menuCopy?'restaurant_menu_copy':($productCrud?'restaurant_product_crud':($restaurantProducts?'restaurant_product':'restaurant_flag')));$saved[$prefix.'_facts']=$flagPayload['facts'];$saved[$prefix.'_after']=$flagResult['after'];$saved[$prefix.'_success']=$flagResult['success'];}
            if($productCrud){$saved['restaurant_product_crud_route']=$name;$saved['restaurant_product_crud_payload']=$flagPayload;$saved['restaurant_product_crud_result']=$flagResult;}
            if($menuCopy){$saved['restaurant_menu_copy_route']=$name;$saved['restaurant_menu_copy_payload']=$flagPayload;$saved['restaurant_menu_copy_result']=$flagResult;}
            DB::table('desktop_dashboard_remote_attempts')->where('id',$row->id)->update(['status'=>'committed','request_hash'=>$fingerprint,
                'operation_id'=>$operation,'response_cipher'=>Crypt::encryptString(json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR|(($restaurantFlags||$restaurantProducts||$productCrud||$menuCopy)?JSON_PRESERVE_ZERO_FRACTION:0))),'updated_at'=>now('UTC')]);
            return $response;
        });};
        return ($productCrud||$menuCopy)?app(DesktopDashboardProductImages::class)->guard(fn()=>app(DesktopDashboardProductEffects::class)->guard($work)):$work();
    }
    private function customerReply(string $route,array $saved,\App\Models\User $actor,object $device): void
    {
        if(!DesktopDashboardCustomerDirectory::handles($route))return;
        $binding=$saved['customer_directory_binding']??null;
        abort_unless(is_array($binding)&&($binding['operation']??null)===($saved['operation']??null),503,
            'هوية نتيجة العميل المحفوظة غير مكتملة؛ الطلب يحتاج مراجعة.');
        app(DesktopDashboardCustomerDirectory::class)->authorizeRemoteBinding($route,$binding,$actor,$device);
    }
    private function walletReply(string $route,array $saved,\App\Models\User $actor,object $device): void
    {
        abort_unless(DesktopDashboardWalletCash::handles($route)&&($saved['wallet_cash_route']??null)===$route
            &&is_array($saved['wallet_cash_payload']??null)&&($saved['wallet_cash_payload']['values']['idempotency_key']??null)===($saved['operation']??null),503);
        app(DesktopDashboardWalletCash::class)->authorize($route,$saved['wallet_cash_payload'],$actor,$device);
    }
    private function response(array $saved,array $files=[])
    {
        if(isset($saved['restaurant_menu_copy_result']))app(DesktopDashboardRestaurantMenuCopy::class)->verifyResult($saved['restaurant_menu_copy_result']);
        elseif(isset($saved['restaurant_product_crud_result']))app(DesktopDashboardProductImages::class)->verifyResult($saved['restaurant_product_crud_result'],$files);
        elseif($files)app(DesktopDashboardExpenseAttachments::class)->verifyResult(json_decode($saved['content'],true,512,JSON_THROW_ON_ERROR),$files);
        // A lost Review redirect must retain its original success flash for the following page.
        if(isset($saved['review_facts'],$saved['review_success']))session()->flash('success',$saved['review_success']);
        if(isset($saved['wishlist_facts'],$saved['wishlist_success']))session()->flash('success',$saved['wishlist_success']);
        if(isset($saved['wallet_cash_payload'],$saved['wallet_cash_success']))session()->flash('success',$saved['wallet_cash_success']);
        if(isset($saved['restaurant_control_facts'],$saved['restaurant_control_success']))session()->flash('success',$saved['restaurant_control_success']);
        if(isset($saved['restaurant_flag_facts'],$saved['restaurant_flag_success']))session()->flash('success',$saved['restaurant_flag_success']);
        if(isset($saved['restaurant_product_facts'],$saved['restaurant_product_success']))session()->flash('success',$saved['restaurant_product_success']);
        if(isset($saved['restaurant_product_crud_facts'],$saved['restaurant_product_crud_success']))session()->flash('success',$saved['restaurant_product_crud_success']);
        if(isset($saved['restaurant_menu_copy_facts'],$saved['restaurant_menu_copy_success']))session()->flash('success',$saved['restaurant_menu_copy_success']);
        $response=response($saved['content'],$saved['status']);
        foreach(['type'=>'Content-Type','location'=>'Location'] as $key=>$header)if($saved[$key])$response->headers->set($header,$saved[$key]);
        return $response->header('Cache-Control','private, no-store');
    }
}
