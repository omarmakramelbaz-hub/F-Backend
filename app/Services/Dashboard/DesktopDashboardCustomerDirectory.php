<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema,Validator};

/** Original CRM writes, bounded by branch links and immutable, typed before facts. */
class DesktopDashboardCustomerDirectory
{
    public const ROUTES=['customers.directory-save','customers.consent'];
    private const IDENTITY=['id','phone','phone_key'];
    private const PROFILE=['name','address','area','delivery_notes','latitude','longitude'];
    private const CONSENT=['service_consent','marketing_consent','service_consented_at','marketing_consented_at',
        'service_consent_evidence','marketing_consent_evidence','service_consented_by','marketing_consented_by','opted_out_at'];
    private const SAVED=['id','branch','phone_key','name','address','area','delivery_notes','latitude','longitude','revision'];
    public static function handles(?string $route): bool {return in_array($route,self::ROUTES,true);}
    private function values(string $route,array $v): array
    {
        abort_unless(self::handles($route),422);
        $allowed=['branch','idempotency_key','expected_revision','directory_id'];
        $allowed=array_merge($allowed,$route==='customers.directory-save'
            ?['expected_updated_at','channel','phone',...self::PROFILE]
            :['service_consent','marketing_consent','evidence','consent_method','consent_scope']);
        abort_if(array_diff(array_keys($v),$allowed),422,'حقول عملية العميل غير معتمدة.');
        $rules=['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'idempotency_key'=>'required|uuid',
            'directory_id'=>($route==='customers.consent'?'required':'nullable').'|integer|min:1'];
        if($route==='customers.directory-save')$rules+=['phone'=>'required|string|max:30','channel'=>'required|in:takeaway,dine,app,delivery'.(!empty($v['directory_id'])?',whatsapp':'')];
        else $rules+=['service_consent'=>'required|boolean','marketing_consent'=>'required|boolean',
            'consent_scope'=>'nullable|in:all,service,marketing','consent_method'=>'nullable|in:verbal,withdrawal'];
        Validator::make($v,$rules)->validate();return $v;
    }
    private function original(string $route): void
    {
        $original=app('router')->getRoutes()->getByName($route);
        $action=$route==='customers.directory-save'?'directorySave':'customerConsent';
        abort_unless($original&&$original->methods()===['POST']&&in_array('IsAdmin',$original->gatherMiddleware(),true)
            &&$original->getActionName()==='App\\Http\\Controllers\\Dashboard\\BranchOperationsController@'.$action,
            409,'مسار العملاء الأصلي تغير؛ العملية محفوظة للمراجعة.');
    }
    private function state(string $route,array $v): array
    {
        foreach(['crm_customers','crm_customer_sources','branch_customers'] as $table)
            abort_unless(Schema::hasTable($table),503,'بيانات العملاء المحلية غير مكتملة؛ نزّل نسخة الفرع المحدثة.');
        $q=DB::table('crm_customers');
        if(!empty($v['directory_id']))$q->where('id',$v['directory_id']);
        else $q->where('phone',CustomerDirectory::requirePhone($v['phone'],'phone'));
        $row=$q->lockForUpdate()->first();
        abort_if(!$row&&!empty($v['directory_id']),409,'العميل المحلي لم يعد موجودًا في السيرفر.');
        $sources=$row?DB::table('crm_customer_sources')->where('customer_id',$row->id)->where('branch',$v['branch'])
            ->orderBy('channel')->lockForUpdate()->get(['branch','channel'])->map(fn($r)=>(array)$r)->all():[];
        abort_if($row&&!$sources,409,'العميل غير مرتبط بفرع الجهاز؛ التعديل المحلي محفوظ للمراجعة.');
        $columns=array_merge(self::IDENTITY,$route==='customers.directory-save'?self::PROFILE:self::CONSENT);
        $customer=$row?array_intersect_key((array)$row,array_flip($columns)):null;
        $saved=$row&&$route==='customers.directory-save'&&!empty($v['directory_id'])
            ?DB::table('branch_customers')->where('branch',$v['branch'])->where('phone_key',$row->phone_key)->orderBy('id')
                ->lockForUpdate()->get(self::SAVED)->map(fn($r)=>(array)$r)->all():[];
        return ['customer'=>$customer,'sources'=>$sources,'saved_profiles'=>$saved];
    }
    private function facts(string $route,array $payload): array
    {
        abort_unless(empty($payload['parameters'])&&empty($payload['files'])
            &&array_diff(array_keys($payload),['parameters','values','files','facts'])===[],422);
        $v=$this->values($route,$payload['values']??[]);
        abort_unless(array_keys($payload['facts']??[])===['customer_before'],409);
        $before=$payload['facts']['customer_before'];
        abort_unless(is_array($before)&&count($before)===3&&array_diff(array_keys($before),['customer','sources','saved_profiles'])===[]
            &&is_array($before['sources'])&&is_array($before['saved_profiles']),409,'مصادر العميل المحلي غير مكتملة.');
        $customer=$before['customer'];
        if($customer===null){abort_unless($route==='customers.directory-save'&&empty($v['directory_id'])&&$before['sources']===[]&&$before['saved_profiles']===[],409);}
        else{
            $columns=array_merge(self::IDENTITY,$route==='customers.directory-save'?self::PROFILE:self::CONSENT);
            abort_unless(is_array($customer)&&count($customer)===count($columns)&&array_diff(array_keys($customer),$columns)===[]
                &&is_numeric($customer['id'])&&(int)$customer['id']>0&&CustomerDirectory::normalizePhone((string)$customer['phone'])===$customer['phone']
                &&CustomerDirectory::phoneKey($customer['phone'])===$customer['phone_key'],409);
            if(!empty($v['directory_id']))abort_unless((int)$v['directory_id']===(int)$customer['id'],409);
            if($route==='customers.directory-save')abort_unless(CustomerDirectory::normalizePhone($v['phone'])===$customer['phone'],422);
            abort_unless(count($before['sources'])>0,409);
        }
        foreach($before['sources'] as $source)abort_unless(is_array($source)&&count($source)===2
            &&($source['branch']??null)===$v['branch']&&in_array($source['channel']??null,['takeaway','dine','app','delivery','whatsapp'],true),409);
        foreach($before['saved_profiles'] as $row)abort_unless($route==='customers.directory-save'&&!empty($v['directory_id'])
            &&is_array($row)&&count($row)===count(self::SAVED)&&array_diff(array_keys($row),self::SAVED)===[]
            &&($row['branch']??null)===$v['branch']&&($row['phone_key']??null)===($customer['phone_key']??null),409);
        return $before;
    }
    private function scope(string $route,array $values,User $actor,object $scope): User
    {
        $this->original($route);
        $access=app(TakeawayAccess::class);$original=$access->actor($actor);
        $actor=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($actor&&(int)$scope->actor_id===(int)$actor->id&&!in_array($actor->status??null,['disabled','declined'],true)
            &&$actor->account_type===$original->account_type&&(int)$actor->owner_resturant_id===(int)$original->owner_resturant_id,403);
        // The original helper reloads with ordinary reads. An older RR snapshot
        // must not restore a removed central order-list/Super Admin grant.
        if($actor->account_type==='admin'&&empty($actor->owner_resturant_id)&&(int)$actor->id!==1){
            foreach(['roles','permissions','model_has_roles','model_has_permissions','role_has_permissions'] as $table)
                abort_unless(Schema::hasTable(config('permission.table_names.'.$table,$table)),403);
            $roles=$actor->roles()->lockForUpdate()->get();
            $super=$roles->contains(fn($role)=>$role->guard_name==='admin'&&$role->name==='Super Admin');
            if(!$super){
                $permission=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->where('name','order-list')->lockForUpdate()->first();
                abort_unless($permission,403);
                $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$actor->getMorphClass())->where('model_id',$actor->id)->where('permission_id',$permission->id)->lockForUpdate()->first();
                $grant=DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$roles->pluck('id'))->where('permission_id',$permission->id)->lockForUpdate()->first();
                abort_unless($direct||$grant,403);
            }
        }
        abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        app(DesktopDashboardDevices::class)->branch($scope,$values['branch'],$actor);
        abort_unless($access->canAccess($actor)&&$access->permissions($actor)['can_checkout'],403);
        $access->branch($values['branch'],$actor,true);return $actor;
    }
    public function authorize(string $route,array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($route,$payload);
        $actor=$this->scope($route,$payload['values'],$actor,$scope);
        if($before['customer']!==null){
            abort_unless(DB::table('crm_customers')->where('id',$before['customer']['id'])->where('phone',$before['customer']['phone'])->lockForUpdate()->first(['id'])
                &&DB::table('crm_customer_sources')->where('customer_id',$before['customer']['id'])->where('branch',$payload['values']['branch'])->lockForUpdate()->first(['id']),409,
                'هوية العميل أو ارتباط الفرع تغير؛ العملية المحلية محفوظة للمراجعة.');
        }
        return $actor;
    }
    public function payload(string $route,Request $request,User $actor,object $scope): array
    {
        abort_if($request->allFiles(),501);
        $v=$this->values($route,$request->except('_token'));
        $actor=$this->scope($route,$v,$actor,$scope);
        $facts=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$v['idempotency_key'],(int)$actor->id,$route)
            ??['customer_before'=>$this->state($route,$v)];
        $payload=['parameters'=>[],'values'=>$v,'files'=>[],'facts'=>$facts];
        $this->authorize($route,$payload,$actor,$scope);return $payload;
    }
    public function capture(string $route,array $payload,callable $work): array
    {
        $before=$this->facts($route,$payload);$current=$this->state($route,$payload['values']);
        abort_unless(app(DesktopDashboardJournal::class)->fingerprint($before)===app(DesktopDashboardJournal::class)->fingerprint($current),409,
            'بيانات العميل أو موافقته تغيرت على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
        if($route==='customers.consent'){
            $v=$payload['values'];$scope=$v['consent_scope']??'all';
            foreach(['service','marketing'] as $purpose)if($scope!==($purpose==='service'?'marketing':'service')&&!empty($v[$purpose.'_consent'])){
                $customer=$before['customer'];$recorded=false;
                foreach(['_consented_at','_consent_evidence','_consented_by'] as $suffix)
                    if($customer[$purpose.$suffix]!==null&&$customer[$purpose.$suffix]!=='')$recorded=true;
                abort_if(!empty($customer['opted_out_at'])||(!in_array($customer[$purpose.'_consent'],[true,1,'1'],true)&&$recorded),409,
                    'إعادة تفعيل موافقة رسائل أُوقفت تتطلب مراجعة من الداشبورد المتصل قبل تنفيذ هذه العملية.');
            }
        }
        $phone=$before['customer']['phone']??CustomerDirectory::requirePhone($payload['values']['phone'],'phone');
        $id=$before['customer']['id']??null;
        return app(DesktopDashboardCustomerFacts::class)->withIdentities([$phone=>$id===null?null:(int)$id],$work);
    }
    public function execute(string $route,array $payload,User $actor): array
    {
        return $this->capture($route,$payload,function()use($route,$payload,$actor){
            $v=$payload['values'];
            // Strong profile facts permit harmless last-seen changes; keep the original
            // controller's optimistic timestamp aligned with the locked current row.
            if($route==='customers.directory-save'&&!empty($v['directory_id'])&&array_key_exists('expected_updated_at',$v))
                $v['expected_updated_at']=(string)DB::table('crm_customers')->where('id',$v['directory_id'])->value('updated_at');
            return app(BranchCustomers::class)->{$route==='customers.directory-save'?'directorySave':'consent'}($v,$actor);
        });
    }
    public function outputs(string $route,array $result): array
    {
        $id=$route==='customers.directory-save'?data_get($result,'customer.id'):($result['customer_id']??null);
        return is_numeric($id)&&(int)$id>0?['crm_customer'=>(int)$id]:[];
    }
    /** A CREATE has null before-facts; its saved output still needs current identity/source authority. */
    public function authorizeSaved(string $route,array $result,array $payload,string $device,int $actorId): void
    {
        abort_unless(DB::transactionLevel()>0,503);
        if(isset($payload['envelope'])){
            abort_unless(($result['committed']??null)===true&&is_array($result['result']??null),503);
            $result=$result['result'];$payload=$payload['envelope']['payload'];
            $scope=DB::table('desktop_dashboard_devices')->where('id',$device)->lockForUpdate()->first();
        }else $scope=app(DesktopDashboardRefresh::class)->lock($device);
        $id=$this->outputs($route,$result)['crm_customer']??null;
        $phone=$route==='customers.directory-save'?data_get($result,'customer.phone'):data_get($payload,'facts.customer_before.customer.phone');
        abort_unless($id&&is_string($phone)&&CustomerDirectory::normalizePhone($phone)===$phone,503,'هوية نتيجة العميل المحفوظة غير مكتملة.');
        $actor=User::withoutGlobalScopes()->find($actorId);abort_unless($actor&&$scope&&(int)$scope->actor_id===$actorId,403);
        $this->authorizeRemoteBinding($route,['version'=>1,'route'=>$route,'branch'=>$payload['values']['branch']??null,
            'operation'=>$payload['values']['idempotency_key']??null,'customer'=>['id'=>$id,'phone'=>$phone]],$actor,$scope);
    }
    /** A connected native receipt keeps identity/scope, never a frozen profile or consent tuple. */
    public function remoteBinding(string $route,array $values,string $operation,array $result,User $actor,object $device): array
    {
        $id=$this->outputs($route,$result)['crm_customer']??null;
        $phone=$id?DB::table('crm_customers')->where('id',$id)->value('phone'):null;
        $binding=['version'=>1,'route'=>$route,'branch'=>$values['branch']??null,'operation'=>$operation,
            'customer'=>['id'=>$id,'phone'=>$phone]];
        $this->authorizeRemoteBinding($route,$binding,$actor,$device);return $binding;
    }
    public function authorizeRemoteBinding(string $route,array $binding,User $actor,object $device): void
    {
        abort_unless(self::handles($route)&&count($binding)===5&&array_diff(array_keys($binding),['version','route','branch','operation','customer'])===[]
            &&($binding['version']??null)===1&&($binding['route']??null)===$route&&is_array($binding['customer']??null)
            &&count($binding['customer'])===2&&array_diff(array_keys($binding['customer']),['id','phone'])===[],503);
        Validator::make($binding,['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],
            'operation'=>'required|uuid','customer.id'=>'required|integer|min:1','customer.phone'=>'required|string|max:30'])->validate();
        $customer=$binding['customer'];
        abort_unless(CustomerDirectory::normalizePhone($customer['phone'])===$customer['phone'],503);
        $this->scope($route,['branch'=>$binding['branch']],$actor,$device);
        foreach(['crm_customers','crm_customer_sources'] as $table)abort_unless(Schema::hasTable($table),503);
        abort_unless(DB::table('crm_customers')->where('id',$customer['id'])->where('phone',$customer['phone'])->lockForUpdate()->first(['id'])
            &&DB::table('crm_customer_sources')->where('customer_id',$customer['id'])->where('branch',$binding['branch'])->lockForUpdate()->first(['id']),409,
            'هوية العميل أو ارتباط الفرع تغير؛ نتيجة الطلب المحفوظة تحتاج مراجعة.');
    }
    public function verifyAfter(string $route,array $result,array $local): void
    {
        $fields=$route==='customers.directory-save'?array_merge(['phone','phone_key'],self::PROFILE)
            :['consent','consent_evidence','consent_method','consent_scope','recorded_by','recorded_at'];
        $actual=$route==='customers.directory-save'?($result['customer']??[]):$result;
        $expected=$route==='customers.directory-save'?($local['customer']??[]):$local;
        abort_unless(count(array_intersect_key($expected,array_flip($fields)))===count($fields)
            &&app(DesktopDashboardJournal::class)->fingerprint(array_intersect_key($actual,array_flip($fields)))
                ===app(DesktopDashboardJournal::class)->fingerprint(array_intersect_key($expected,array_flip($fields))),409,'أثر تعديل العميل مختلف؛ العملية المحلية محفوظة للمراجعة.');
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach(['values.directory_id','facts.customer_before.customer.id'] as $path){$id=data_get($payload,$path);if($id!==null&&!is_array($id))data_set($payload,$path,$reference('crm_customer',$id));}
        foreach($payload['facts']['customer_before']['saved_profiles']??[] as $i=>$row)if(!is_array($row['id']))
            $payload['facts']['customer_before']['saved_profiles'][$i]['id']=$reference('customer',$row['id']);
        foreach(['service_consent_evidence','marketing_consent_evidence'] as $field){
            $path='facts.customer_before.customer.'.$field;$evidence=data_get($payload,$path);
            if(is_string($evidence)&&preg_match('/^(pos-checkout:|pos-ticket:(?:phone|dine):)([1-9][0-9]*)$/D',$evidence,$match)){
                $entity=$match[1]==='pos-checkout:'?'receipt':'ticket';$mapped=$reference($entity,(int)$match[2]);
                if(is_array($mapped))data_set($payload,$path,['$desktop_crm_evidence'=>['prefix'=>$match[1],'reference'=>$mapped]]);
            }
        }
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach(['values.directory_id','facts.customer_before.customer.id'] as $path){$value=data_get($payload,$path);if(is_array($value))
            abort_unless(count($value)===1&&preg_match('/^crm_customer(?:\.[0-9]{1,3})?$/D',$value['$desktop_ref']['entity']??''),422,'نوع مرجع العميل الموحد غير صحيح.');}
        foreach($payload['facts']['customer_before']['saved_profiles']??[] as $row)if(is_array($row['id']??null))
            abort_unless(count($row['id'])===1&&($row['id']['$desktop_ref']['entity']??null)==='customer',422);
    }
}
