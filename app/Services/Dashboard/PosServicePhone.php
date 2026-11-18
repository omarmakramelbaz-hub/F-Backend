<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PosServicePhone
{
    private TakeawayAccess $access;
    private PosServiceTable $tables;
    public function __construct(TakeawayAccess $access, PosServiceTable $tables) { $this->access=$access; $this->tables=$tables; }
    public static function key(string $phone): string
    {
        $phone=strtr($phone,array_combine(preg_split('//u','٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),range(0,9)));
        $phone=strtr($phone,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),range(0,9)));return preg_replace('/[^0-9+]/','',$phone);
    }
    public function customers(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>['required','regex:/^(all|(f|gs):[1-9][0-9]{0,18})$/D'],'phone'=>'required|string|max:30','prefix'=>'nullable|boolean'])->validate();
        $this->tables->requireReady();
        $actor=$this->access->actor($actor);$central=$actor->account_type==='admin'&&empty($actor->owner_resturant_id);$all=$v['branch']==='all';
        // Match the delivery page's can_view_all_branches permission, including
        // branch-bound admins who must never gain a global customer search.
        if($all)abort_unless($central,403);
        $branch=$all?['value'=>'all','id'=>null,'kind'=>'all','name'=>'جميع الفروع','label'=>'جميع الفروع']:$this->access->branch($v['branch'],$actor);
        $branches=($all||($central&&$branch['kind']==='f'))?$this->access->branches($actor):[$branch];
        $branchesByValue=array_column($branches,null,'value');
        $scope=$all?array_column($branches,'value'):($central&&$branch['kind']==='f'?array_column(array_filter($branches,fn($b)=>$b['kind']==='f'),'value'):[$v['branch']]);
        $key=self::key($v['phone']);
        abort_unless(preg_match('/^\+?[0-9]{3,20}$/D',$key),422,'اكتب ٣ أرقام على الأقل.');
        $prefix=(bool)($v['prefix']??false);
        $keys=[$key];
        if(str_starts_with($key,'0')&&!str_starts_with($key,'00'))$keys=array_merge($keys,['+20'.substr($key,1),'20'.substr($key,1),'0020'.substr($key,1)]);
        elseif(preg_match('/^(?:\+20|0020|20)([1-9][0-9]*)$/',$key,$m))$keys=array_merge($keys,['0'.$m[1],'+20'.$m[1],'20'.$m[1],'0020'.$m[1]]);
        foreach($keys as $variant)if(preg_match('/^0(1[0125][0-9]*)$/',$variant,$m))$keys[]=$m[1];
        if(preg_match('/^1[0125][0-9]*$/',$key))$keys=array_merge($keys,['0'.$key,'+20'.$key,'20'.$key,'0020'.$key]);
        $keys=array_unique($keys);$items=[];$seen=[];
        $add=function(array $item)use(&$items,&$seen,$all,$branch,$branchesByValue){
            // In an explicit branch lookup foreign addresses remain usable in
            // the selected branch. In all-branch lookup each origin is a choice.
            $target=$all?($item['branch']??null):$branch['value'];
            if($all&&$target!==null&&!isset($branchesByValue[$target]))return;
            $item['branch']=$target;$item['branch_name']=$target===null?null:($branchesByValue[$target]['name']??$branch['name']);
            $phone=BranchCustomers::phoneKey($item['phone']);$item['phone']=$phone;
            $lat=$item['latitude']??null;$lng=$item['longitude']??null;
            $located=is_numeric($lat)&&is_numeric($lng)&&abs((float)$lat)<=90&&abs((float)$lng)<=180&&!((float)$lat===0.0&&(float)$lng===0.0);
            $item['latitude']=$located?(float)$lat:null;$item['longitude']=$located?(float)$lng:null;
            $hash=PosServiceTicket::fingerprint([$phone,trim($item['address']),trim($item['area']),$all?$target:null]);
            if(isset($seen[$hash])) {
                $index=$seen[$hash];
                if(!isset($items[$index]['latitude'],$items[$index]['longitude'])&&isset($item['latitude'],$item['longitude'])) {
                    $items[$index]['latitude']=$item['latitude'];$items[$index]['longitude']=$item['longitude'];
                }
                return;
            }
            if(count($items)>=8)return;$seen[$hash]=count($items);$items[]=$item;
        };
        // Shared directory lookup precedes branch-local historical suggestions.
        if(($branch['kind']==='f'||($branch['kind']==='all'&&$central))&&in_array($actor->account_type,['admin','vendor','resturant_owner'],true)&&$this->access->has('crm_customers','phone_key')){
            $directory=DB::table('crm_customers');$this->numberFilter($directory,'phone_key',$keys,$prefix);
            foreach($directory->orderByRaw('CASE WHEN phone_key = ? THEN 0 ELSE 1 END',[BranchCustomers::phoneKey($v['phone'])])->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->get() as $row){
                $add(['customer_id'=>null,'customer_revision'=>null,'directory_id'=>(int)$row->id,
                    'name'=>$row->name??'','phone'=>$row->phone,'address'=>$row->address??'','area'=>$row->area??'',
                    'delivery_notes'=>$row->delivery_notes??'','latitude'=>$row->latitude,'longitude'=>$row->longitude]);
            }
        }
        if($this->access->has('branch_customers','phone_key')){
            $saved=DB::table('branch_customers')->whereIn('branch',$scope);$this->numberFilter($saved,'phone_key',$keys,$prefix);
            foreach($saved->orderByRaw('CASE WHEN branch = ? THEN 0 ELSE 1 END',[$v['branch']])->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->get() as $row)$add(['branch'=>$row->branch,'customer_id'=>$all||$row->branch===$v['branch']?(int)$row->id:null,'customer_revision'=>$all||$row->branch===$v['branch']?(int)$row->revision:null,'name'=>$row->name,'phone'=>$row->phone,'address'=>$row->address,'area'=>$row->area??'','delivery_notes'=>$row->delivery_notes??'','latitude'=>$row->latitude,'longitude'=>$row->longitude]);
        }
        // UNIFIED_CUSTOMER_DIRECTORY_LOOKUP_V3_BEGIN
        // An authorized F delivery lookup can find matching directory contacts
        // across F branches. Bulk lists and order permissions are unchanged.
        $directoryFBranches=array_values(array_filter($scope,fn($value)=>preg_match('/^f:[1-9][0-9]{0,18}$/D',$value)));
        if(count($items)<8&&$directoryFBranches) {
            $directoryKeys=array_values(array_unique(array_merge($keys,array_map(fn($value)=>str_starts_with($value,'00')?substr($value,2):ltrim($value,'+'),$keys))));
            $legacyRows=[];$completeLegacyPhones=[];
            if(\Illuminate\Support\Facades\Schema::hasColumns('legacy_customer_import_profiles',[
                'id','dataset_id','branch','phone','phone_key','name','address','area','delivery_notes','latitude','longitude','lookup_eligible'
            ])&&\Illuminate\Support\Facades\Schema::hasColumns('legacy_customer_import_datasets',['id','schema_version'])) {
                $legacy=DB::table('legacy_customer_import_profiles as lp')
                    ->join('legacy_customer_import_datasets as ld','ld.id','=','lp.dataset_id')
                    ->where('ld.schema_version',1)->where('lp.lookup_eligible',true)->whereNotNull('lp.phone')
                    ->where(function($q){$q->where('lp.branch','')->orWhere('lp.branch','like','f:%');});
                $this->numberFilter($legacy,'lp.phone_key',$directoryKeys,$prefix);
                $legacyRows=$legacy->select(['lp.id','lp.branch','lp.phone','lp.name','lp.address','lp.area','lp.delivery_notes','lp.latitude','lp.longitude'])
                    ->orderByDesc('lp.id')->limit(40)->get();
                foreach($legacyRows as $row)if(trim((string)$row->name)!==''&&trim((string)$row->address)!=='')$completeLegacyPhones[$row->phone]=true;
            }
            if(\Illuminate\Support\Facades\Schema::hasColumns('crm_customers',[
                'id','phone','phone_key','name','address','area','delivery_notes','latitude','longitude'
            ])&&\Illuminate\Support\Facades\Schema::hasColumns('crm_customer_sources',['customer_id','branch','channel'])) {
                $directory=DB::table('crm_customers as dc')->where(function($q){
                    $q->whereExists(function($s){$s->selectRaw('1')->from('crm_customer_sources as dsf')->whereColumn('dsf.customer_id','dc.id')->where('dsf.branch','like','f:%');})
                        ->orWhereExists(function($s){$s->selectRaw('1')->from('crm_customer_sources as dsg')->whereColumn('dsg.customer_id','dc.id')->where('dsg.branch','')->whereIn('dsg.channel',['delivery','whatsapp']);})
                        ->orWhereNotExists(function($s){$s->selectRaw('1')->from('crm_customer_sources as dss')->whereColumn('dss.customer_id','dc.id')->where('dss.branch','like','gs:%');});
                });
                $this->numberFilter($directory,'dc.phone_key',$directoryKeys,$prefix);
                foreach($directory->select(['dc.id','dc.phone','dc.name','dc.address','dc.area','dc.delivery_notes','dc.latitude','dc.longitude'])
                    ->orderByDesc('dc.id')->limit(20)->get() as $row) {
                    if(trim((string)$row->name)===''&&trim((string)$row->address)===''&&isset($completeLegacyPhones[$row->phone]))continue;
                    $add(['branch'=>null,'name'=>$row->name??'','phone'=>$row->phone,'address'=>$row->address??'',
                        'area'=>$row->area??'','delivery_notes'=>$row->delivery_notes??'','latitude'=>$row->latitude,'longitude'=>$row->longitude]);
                }
            }
            foreach($legacyRows as $row) {
                $add(['branch'=>$row->branch===''?null:$row->branch,'name'=>$row->name,'phone'=>$row->phone,
                    'address'=>$row->address,'area'=>$row->area,'delivery_notes'=>$row->delivery_notes,
                    'latitude'=>$row->latitude,'longitude'=>$row->longitude]);
            }
        }
        // UNIFIED_CUSTOMER_DIRECTORY_LOOKUP_V3_END
        $query=DB::table('pos_service_tickets')->where('channel','phone')->whereIn('branch',$scope);
        $this->numberFilter($query,'phone_key',$keys,$prefix);
        foreach($query->orderByDesc('id')->limit(40)->get() as $row) {
            $location=json_decode($row->delivery_snapshot??'null',true);
            $add(['branch'=>$row->branch,'name'=>$row->customer_name,'phone'=>$row->customer_phone,'address'=>$row->address,'area'=>$row->area??'','last_ticket_id'=>(int)$row->id,'delivery_notes'=>$row->delivery_notes??'','latitude'=>$location['latitude']??null,'longitude'=>$location['longitude']??null]);
        }
        // Existing app customers are available to the call center. Branch staff
        // can only look up app customers who have ordered from their own branch.
        if(count($items)<8&&($branch['kind']==='f'||($all&&count(array_filter($branches,fn($b)=>$b['kind']==='f'))))&&$this->access->has('users','mobile')) {
            $customers=DB::table('users')->where('account_type','user');
            if($this->access->has('users','app_scope'))$customers->where(function($q){$q->whereNull('app_scope')->orWhere('app_scope','')->orWhere('app_scope','fasakhansta');});
            if(!$central) {
                if(!$this->access->has('orders','resturant_id')||!$this->access->has('orders','user_id'))$customers->whereRaw('1=0');
                else $customers->whereIn('id',DB::table('orders')->select('user_id')->where('resturant_id',$branch['id']));
            }
            $this->numberFilter($customers,$this->normalizedColumn('mobile'),$keys,$prefix);
            foreach($customers->orderByDesc('id')->limit(8)->get() as $customer) {
                $addresses=$this->access->has('user_address','user_id')?DB::table('user_address')->where('user_id',$customer->id)->orderByDesc('id')->limit(3)->get():collect();
                if($addresses->isEmpty())$add(['name'=>$customer->name??'','phone'=>$customer->mobile,'address'=>'','area'=>'','delivery_notes'=>'']);
                foreach($addresses as $address) {
                    $parts=array_filter([$address->address??($address->address_name??null),$address->street_name??null,!empty($address->floor_no)?'الدور '.$address->floor_no:null,!empty($address->apartment_no)?'شقة '.$address->apartment_no:null]);
                    $add(['name'=>$customer->name??'','phone'=>$customer->mobile,'address'=>implode('، ',array_unique($parts)),'area'=>$address->area_name??'','delivery_notes'=>$address->badge??'','latitude'=>$address->lat??($address->latitude??null),'longitude'=>$address->lng??($address->longitude??null)]);
                }
            }
        }
        // Resolve identity against each choice's effective branch. A global app
        // profile has no reliable branch assignment, so it carries no local id.
        $local=[];
        if($items&&$this->access->has('branch_customers','phone_key')) {
            foreach(DB::table('branch_customers')->whereIn('branch',array_values(array_filter(array_unique(array_column($items,'branch')))))
                ->whereIn('phone_key',array_column($items,'phone'))->get() as $row)$local[$row->branch][$row->phone_key]=$row;
        }
        foreach($items as &$item) {
            $row=$item['branch']===null?null:($local[$item['branch']][$item['phone']]??null);
            $item['customer_id']=$row?(int)$row->id:null;$item['customer_revision']=$row?(int)$row->revision:null;
        }
        unset($item);
        return ['success'=>true,'branch'=>$branch,'items'=>$items,'customers'=>$items,'matches'=>$items];
    }
    public static function normalizedColumn(string $column)
    {
        $sql="REPLACE(REPLACE(REPLACE(REPLACE($column, ' ', ''), '-', ''), '(', ''), ')', '')";foreach(['٠١٢٣٤٥٦٧٨٩','۰۱۲۳۴۵۶۷۸۹'] as $digits)foreach(preg_split('//u',$digits,-1,PREG_SPLIT_NO_EMPTY) as $n=>$d)$sql="REPLACE($sql, '$d', '$n')";return DB::raw($sql);
    }
    private function numberFilter($query,$column,array $keys,bool $prefix): void
    {
        $query->where(function($q)use($column,$keys,$prefix){foreach($keys as $key)$q->orWhere($column,$prefix?'like':'=',$prefix?$key.'%':$key);});
    }
}
