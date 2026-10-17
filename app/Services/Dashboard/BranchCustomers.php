<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;

class BranchCustomers
{
    private BranchOperations $ops;
    public function __construct(BranchOperations $ops){$this->ops=$ops;}
    public static function phoneKey(string $phone): string
    {
        $key=PosServicePhone::key($phone);
        $key=preg_replace('/^(?:\+20|0020|20)(1[0125][0-9]*)$/','0$1',$key);
        if(preg_match('/^1[0125][0-9]{8}$/D',$key))$key='0'.$key;
        return $key;
    }
    public function save(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['customer_id'=>'nullable|integer|min:1','name'=>'required|string|max:100','phone'=>'required|string|max:30','address'=>'required|string|max:500','area'=>'nullable|string|max:150','delivery_notes'=>'nullable|string|max:500','latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180'])->validate();
        foreach(['name','phone','address','area','delivery_notes'] as $key)$v[$key]=trim($v[$key]??'');
        abort_if($v['name']===''||$v['address']==='',422,'أدخل اسم العميل وعنوانه.');$v['phone_key']=self::phoneKey($v['phone']);
        abort_unless(preg_match('/^\+?[0-9]{6,20}$/D',$v['phone_key']),422,'رقم الهاتف غير صالح.');
        abort_if(isset($v['latitude'])!==isset($v['longitude']),422,'أدخل إحداثيات الموقع كاملة.');
        return $this->ops->write('customer.save',$v,$actor,function($branch,$actor)use($v){
            $row=!empty($v['customer_id'])?DB::table('branch_customers')->where('branch',$v['branch'])->where('id',$v['customer_id'])->lockForUpdate()->first():null;
            if(!empty($v['customer_id'])){abort_unless($row,404);$this->ops->revision($row,$v);}
            $duplicate=DB::table('branch_customers')->where('branch',$v['branch'])->where('phone_key',$v['phone_key'])->first();
            abort_if($duplicate&&(!$row||(int)$row->id!==(int)$duplicate->id),409,'هذا العميل مسجل للفرع. اختره من البحث ثم عدّل بياناته.');
            $data=array_intersect_key($v,array_flip(['branch','name','phone','phone_key','address','area','delivery_notes','latitude','longitude']));
            $data+=['latitude'=>null,'longitude'=>null,'revision'=>$row?(int)$row->revision+1:1,'actor_id'=>$actor->id,'updated_at'=>now('UTC')];
            if($row){$id=$row->id;DB::table('branch_customers')->where('id',$id)->update($data);}else $id=DB::table('branch_customers')->insertGetId($data+['created_at'=>now('UTC')]);
            $saved=DB::table('branch_customers')->where('id',$id)->first();
            app(CustomerDirectory::class)->capture((array)$saved,'delivery',$v['branch']);
            return ['customer'=>(array)$saved];
        });
    }
    public function appQuery($actor,array $branches)
    {
        $a=$this->ops->access;$actor=$a->actor($actor);$q=DB::table('users')->where('account_type','user');
        if(!$a->has('users','mobile'))return null;
        if($a->has('users','app_scope'))$q->where(function($q){$q->whereNull('app_scope')->orWhere('app_scope','')->orWhere('app_scope','fasakhansta');});
        if(!($actor->account_type==='admin'&&empty($actor->owner_resturant_id))){
            $ids=array_column(array_filter($branches,fn($b)=>$b['kind']==='f'),'id');
            if(!$a->has('orders','resturant_id')||!$a->has('orders','user_id'))$q->whereRaw('1=0');
            else $q->whereIn('id',DB::table('orders')->select('user_id')->whereIn('resturant_id',$ids));
        }
        return $q;
    }
    public function addresses(int $user): array
    {
        if(!$this->ops->access->has('user_address','user_id'))return [];
        return DB::table('user_address')->where('user_id',$user)->orderByDesc('id')->limit(10)->get()->map(function($r){
            $parts=array_filter([$r->address??($r->address_name??null),$r->street_name??null,!empty($r->building_no)?'عقار '.$r->building_no:null,!empty($r->floor_no)?'الدور '.$r->floor_no:null,!empty($r->apartment_no)?'شقة '.$r->apartment_no:null]);
            return ['address'=>implode('، ',array_unique($parts)),'area'=>$r->area_name??'','delivery_notes'=>$r->badge??'','latitude'=>isset($r->lat)?(float)$r->lat:(isset($r->latitude)?(float)$r->latitude:null),'longitude'=>isset($r->lng)?(float)$r->lng:(isset($r->longitude)?(float)$r->longitude:null)];
        })->all();
    }
    /** One row per canonical phone; source badges remain limited to the permitted branches. */
    public function listing(array $values,$actor): array
    {
        if(!Schema::hasTable('crm_customers')||!Schema::hasTable('crm_customer_sources')){if(!in_array($values['source']??'all',['all','saved','app','history'],true))$values['source']='all';return $this->legacyListing($values,$actor);}
        $v=Validator::make($values,['branch'=>'required|string|max:30','search'=>'nullable|string|max:100','source'=>'nullable|in:all,takeaway,dine,app,delivery,saved,history','page'=>'nullable|integer|min:1'])->validate();
        $branches=$this->ops->branches($v['branch'],$actor);$scope=array_column($branches,'value');if($v['branch']==='all')$scope[]='';$source=$v['source']??'all';
        if(in_array($source,['saved','history'],true))$source='delivery';
        $sources=DB::table('crm_customer_sources')->whereIn('branch',$scope);
        if($source!=='all')$sources->where('channel',$source);
        $q=DB::table('crm_customers')->where(function($q)use($sources,$v,$source){$q->whereIn('id',(clone $sources)->select('customer_id'));if($v['branch']==='all'&&$source==='all')$q->orWhereNotExists(function($unassigned){$unassigned->selectRaw('1')->from('crm_customer_sources')->whereColumn('crm_customer_sources.customer_id','crm_customers.id');});});
        $search=trim($v['search']??'');
        if($search!=='')$q->where(function($q)use($search){$q->where('name','like','%'.$search.'%');$phone=self::phoneKey($search);if($phone!=='')$q->orWhere('phone_key','like','%'.$phone.'%');});
        $count=(clone $q)->count();$last=max(1,(int)ceil($count/30));$page=min((int)($v['page']??1),$last);
        $items=$q->orderByRaw("CASE WHEN name IS NULL OR name = '' THEN 1 ELSE 0 END")->orderBy('name')->orderBy('id')->offset(($page-1)*30)->limit(30)->get();
        $associations=DB::table('crm_customer_sources')->whereIn('branch',$scope)->whereIn('customer_id',$items->pluck('id')->all())->orderBy('branch')->orderBy('channel')->get()->groupBy('customer_id');
        $result=$items->map(function($row)use($associations){$item=(array)$row;$links=$associations->get($row->id,collect());$item['id']=(int)$row->id;$item['source']='crm';$item['branch']=$links->first(fn($link)=>$link->branch!=='')->branch??'';$item['channels']=$links->pluck('channel')->unique()->values()->all();$item['branches']=$links->pluck('branch')->unique()->values()->all();$item['service_consent']=(bool)$row->service_consent;$item['marketing_consent']=(bool)$row->marketing_consent;return $item;})->all();
        return ['success'=>true,'items'=>$result,'branches'=>$branches,'registry'=>true,'summary'=>['customers'=>$count,'service_opted_in'=>(clone $q)->where('service_consent',true)->whereNull('opted_out_at')->count(),'marketing_opted_in'=>(clone $q)->where('marketing_consent',true)->whereNull('opted_out_at')->count()],'pagination'=>['page'=>$page,'last_page'=>$last,'total'=>$count]];
    }
    public function directorySave(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['directory_id'=>'nullable|integer|min:1','expected_updated_at'=>'nullable|string|max:40','channel'=>'required|in:takeaway,dine,app,delivery','name'=>'nullable|string|max:100','phone'=>'required|string|max:30','address'=>'nullable|string|max:500','area'=>'nullable|string|max:150','delivery_notes'=>'nullable|string|max:500','latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180'])->validate();
        abort_unless(CustomerDirectory::normalizePhone($v['phone']),422,'رقم الهاتف غير صالح.');
        abort_if(isset($v['latitude'])!==isset($v['longitude']),422,'أدخل إحداثيات الموقع كاملة.');
        return $this->ops->write('customer.directory.save',$v,$actor,function($branch,$actor)use($v){
            $this->directoryReady();
            $row=null;
            if(!empty($v['directory_id'])){
                $row=$this->scopedCustomer((int)$v['directory_id'],$v['branch'],$actor);
                abort_unless(CustomerDirectory::normalizePhone($v['phone'])===$row->phone,422,'رقم العميل مرتبط بسجل مشترياته. لإضافة رقم آخر أضف عميلًا جديدًا.');
                if(isset($v['expected_updated_at']))abort_unless((string)$row->updated_at===$v['expected_updated_at'],409,'بيانات العميل تغيرت. حدّث القائمة قبل الحفظ.');
            }
            $customer=$row??app(CustomerDirectory::class)->capture($v,$v['channel'],$v['branch']);
            abort_unless($customer,503,'تعذر تسجيل بيانات العميل.');
            if($row){
                $profile=[];foreach(['name','address','area','delivery_notes','latitude','longitude'] as $key)if(array_key_exists($key,$v))$profile[$key]=is_string($v[$key])?(trim($v[$key])?:null):$v[$key];
                if($profile)DB::table('crm_customers')->where('id',$row->id)->update($profile+['updated_at'=>now('UTC')]);
                // Saved delivery profiles keep their IDs and optimistic-lock revisions.
                $saved=array_intersect_key($profile,array_flip(['name','address','area','delivery_notes','latitude','longitude']));
                foreach(['name','address','area','delivery_notes'] as $key)if(array_key_exists($key,$saved))$saved[$key]=$saved[$key]??'';
                if($saved)DB::table('branch_customers')->where('branch',$v['branch'])->where('phone_key',$row->phone_key)->update($saved+['revision'=>DB::raw('revision + 1'),'updated_at'=>now('UTC')]);
            }
            return ['customer'=>(array)DB::table('crm_customers')->where('id',$customer->id)->first()];
        });
    }
    public function consent(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['directory_id'=>'required|integer|min:1','service_consent'=>'required|boolean','marketing_consent'=>'required|boolean','evidence'=>'nullable|string|max:500','consent_method'=>'nullable|in:verbal,withdrawal','consent_scope'=>'nullable|in:all,service,marketing'])->validate();
        $v['service_consent']=(bool)$v['service_consent'];$v['marketing_consent']=(bool)$v['marketing_consent'];$v['evidence']=trim($v['evidence']??'');
        return $this->ops->write('customer.consent',$v,$actor,function($branch,$actor)use($v){
            $this->directoryReady();$row=$this->scopedCustomer((int)$v['directory_id'],$v['branch'],$actor);
            $scope=$v['consent_scope']??'all';$targets=['service'=>$scope!=='marketing','marketing'=>$scope!=='service'];
            $newService=$targets['service']&&$v['service_consent']&&(!$row->service_consent||$row->opted_out_at);$newMarketing=$targets['marketing']&&$v['marketing_consent']&&(!$row->marketing_consent||$row->opted_out_at);
            $method=$v['consent_method']??null;
            $approved=[];$revoked=[];
            foreach(['service'=>'متابعة الطلب والخدمة','marketing'=>'العروض الترويجية'] as $purpose=>$label)if($targets[$purpose]){
                if($v[$purpose.'_consent'])$approved[]=$label;else $revoked[]=$label;
            }
            abort_if($method==='withdrawal'&&$approved,422,'إلغاء الرسائل لا يسجل موافقة جديدة.');
            // This method is an explicit staff attestation after asking the customer; profiles never imply consent.
            if($method==='verbal'||$method==='withdrawal'){
                $evidence=[];
                if($approved)$evidence[]='أقر الموظف بأن العميل وافق شفهيًا على رسائل واتساب: '.implode('، ',$approved).'.';
                if($revoked)$evidence[]='سجل الموظف إيقاف رسائل واتساب: '.implode('، ',$revoked).'.';
                $evidence=implode(' ',$evidence);
            }else{
                abort_if(($newService||$newMarketing)&&$v['evidence']==='',422,'سجل موافقة العميل على رسائل واتساب.');
                $evidence=$v['evidence']!==''?$v['evidence']:'تحديث موافقات واتساب من قائمة العملاء دون تسجيل موافقة جديدة.';
            }
            $recordedAt=now('UTC')->toIso8601String();
            // Never resubmit the other purpose's stale UI value. In particular a scoped
            // withdrawal cannot turn an older opt-in back on after an incoming STOP.
            $service=!$targets['service']||($v['service_consent']===(bool)$row->service_consent&&!$row->opted_out_at)?null:$v['service_consent'];
            $marketing=!$targets['marketing']||($v['marketing_consent']===(bool)$row->marketing_consent&&!$row->opted_out_at)?null:$v['marketing_consent'];
            $customer=app(CustomerDirectory::class)->consent((int)$row->id,$service,$marketing,$evidence,(int)$actor->id);
            abort_unless($customer,503,'تعذر تسجيل موافقة العميل.');
            // The immutable branch operation receipt also retains who changed consent and the evidence supplied.
            return ['customer_id'=>(int)$row->id,'consent'=>['service_consent'=>(bool)$customer->service_consent,'marketing_consent'=>(bool)$customer->marketing_consent,'opted_out_at'=>$customer->opted_out_at],'consent_evidence'=>$evidence,'consent_method'=>$method??'legacy','consent_scope'=>$scope,'recorded_by'=>(int)$actor->id,'recorded_at'=>$recordedAt];
        });
    }
    private function directoryReady(): void {abort_unless(Schema::hasTable('crm_customers')&&Schema::hasTable('crm_customer_sources'),503,'قائمة العملاء الموحدة تحتاج تحديث قاعدة البيانات.');}
    private function scopedCustomer(int $id,string $branch,$actor): object
    {
        $row=DB::table('crm_customers')->where('id',$id)->lockForUpdate()->first();
        $central=$actor->account_type==='admin'&&empty($actor->owner_resturant_id);
        $visible=DB::table('crm_customer_sources')->where('customer_id',$id)->whereIn('branch',$central?[$branch,'']:[$branch])->exists();
        if($central&&!$visible)$visible=!DB::table('crm_customer_sources')->where('customer_id',$id)->exists();
        abort_unless($row&&$visible,404,'العميل غير موجود في الفرع المحدد.');
        return $row;
    }
    private function legacyListing(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:30','search'=>'nullable|string|max:100','source'=>'nullable|in:all,saved,app,history','page'=>'nullable|integer|min:1'])->validate();
        $branches=$this->ops->branches($v['branch'],$actor);$scope=array_column($branches,'value');$source=$v['source']??'all';$search=trim($v['search']??'');$queries=[];
        $saved=DB::table('branch_customers')->whereIn('branch',$scope)->select('id','branch','name','phone','address','area','delivery_notes','latitude','longitude','revision')->selectRaw("'saved' AS source");
        if($search!=='')$saved->where(function($q)use($search){$q->where('name','like','%'.$search.'%');if(self::phoneKey($search)!=='')$q->orWhere('phone_key','like','%'.self::phoneKey($search).'%');});
        if(in_array($source,['saved','all'],true))$queries[]=$saved;
        if(in_array($source,['history','all'],true)){
            $history=DB::table('pos_service_tickets')->whereIn('branch',$scope)->where('channel','phone');
            // Most recent complete address per branch/phone; no mutation or copying app accounts.
            $history->whereIn('id',DB::table('pos_service_tickets')->selectRaw('MAX(id)')->whereIn('branch',$scope)->where('channel','phone')->groupBy('branch','phone_key'));
            if($search!=='')$history->where(function($q)use($search){$q->where('customer_name','like','%'.$search.'%');if(PosServicePhone::key($search)!=='')$q->orWhere('phone_key','like','%'.PosServicePhone::key($search).'%');});
            $queries[]=$history->selectRaw("id,branch,customer_name AS name,customer_phone AS phone,address,area,delivery_notes,NULL AS latitude,NULL AS longitude,revision,'history' AS source");
        }
        if(in_array($source,['app','all'],true)&&count(array_filter($branches,fn($b)=>$b['kind']==='f'))&&($app=$this->appQuery($actor,$branches))){
            if($search!=='')$app->where(function($q)use($search){$q->where('name','like','%'.$search.'%');if(PosServicePhone::key($search)!=='')$q->orWhere(PosServicePhone::normalizedColumn('mobile'),'like','%'.ltrim(self::phoneKey($search),'0').'%');});
            $queries[]=$app->selectRaw("id,'' AS branch,name,mobile AS phone,'' AS address,'' AS area,'' AS delivery_notes,NULL AS latitude,NULL AS longitude,1 AS revision,'app' AS source");
        }
        if(!$queries)return ['success'=>true,'items'=>[],'pagination'=>['page'=>1,'last_page'=>1,'total'=>0]];
        $query=array_shift($queries);foreach($queries as $q)$query->unionAll($q);$all=DB::query()->fromSub($query,'customers');
        $count=(clone $all)->count();$last=max(1,(int)ceil($count/30));$page=min((int)($v['page']??1),$last);
        $items=$all->orderBy('name')->orderBy('source')->orderBy('id')->offset(($page-1)*30)->limit(30)->get()->map(function($r){$item=(array)$r;$item['id']=(int)$r->id;$item['revision']=(int)$r->revision;
            if($r->source==='app'){$item['addresses']=$this->addresses($r->id);if($item['addresses'])$item=array_merge($item,$item['addresses'][0]);$item['phone']=self::phoneKey($item['phone']);}return $item;})->all();
        return ['success'=>true,'items'=>$items,'branches'=>$branches,'pagination'=>['page'=>$page,'last_page'=>$last,'total'=>$count]];
    }
}
