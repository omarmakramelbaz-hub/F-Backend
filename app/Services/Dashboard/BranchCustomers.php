<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
            return ['customer'=>(array)DB::table('branch_customers')->where('id',$id)->first()];
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
    public function listing(array $values,$actor): array
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
