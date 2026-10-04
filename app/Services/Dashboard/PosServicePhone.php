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
        $v=Validator::make($values,['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'phone'=>'required|string|max:30','prefix'=>'nullable|boolean'])->validate();
        $this->tables->requireReady();
        $branch=$this->access->branch($v['branch'],$actor);$actor=$this->access->actor($actor);$key=self::key($v['phone']);
        abort_unless(preg_match('/^\+?[0-9]{3,20}$/D',$key),422,'اكتب ٣ أرقام على الأقل.');
        $prefix=(bool)($v['prefix']??false);$central=$actor->account_type==='admin'&&empty($actor->owner_resturant_id);
        $keys=[$key];
        if(str_starts_with($key,'0')&&!str_starts_with($key,'00'))$keys=array_merge($keys,['+20'.substr($key,1),'20'.substr($key,1),'0020'.substr($key,1)]);
        elseif(preg_match('/^(?:\+20|0020|20)([1-9][0-9]*)$/',$key,$m))$keys=array_merge($keys,['0'.$m[1],'+20'.$m[1],'20'.$m[1],'0020'.$m[1]]);
        foreach($keys as $variant)if(preg_match('/^0(1[0125][0-9]*)$/',$variant,$m))$keys[]=$m[1];
        if(preg_match('/^1[0125][0-9]*$/',$key))$keys=array_merge($keys,['0'.$key,'+20'.$key,'20'.$key,'0020'.$key]);
        $keys=array_unique($keys);$items=[];$seen=[];
        $add=function(array $item)use(&$items,&$seen){
            $phone=BranchCustomers::phoneKey($item['phone']);$item['phone']=$phone;
            $hash=PosServiceTicket::fingerprint([$phone,trim($item['address']),trim($item['area'])]);
            if(isset($seen[$hash])||count($items)>=8)return;$seen[$hash]=true;$items[]=$item;
        };
        $scope=$central&&$branch['kind']==='f'?array_column(array_filter($this->access->branches($actor),fn($b)=>$b['kind']==='f'),'value'):[$v['branch']];
        if($this->access->has('branch_customers','phone_key')){
            $saved=DB::table('branch_customers')->whereIn('branch',$scope);$this->numberFilter($saved,'phone_key',$keys,$prefix);
            foreach($saved->orderByDesc('updated_at')->limit(20)->get() as $row)$add(['customer_id'=>$row->branch===$v['branch']?(int)$row->id:null,'customer_revision'=>$row->branch===$v['branch']?(int)$row->revision:null,'name'=>$row->name,'phone'=>$row->phone,'address'=>$row->address,'area'=>$row->area??'','delivery_notes'=>$row->delivery_notes??'','latitude'=>$row->latitude,'longitude'=>$row->longitude]);
        }
        $query=DB::table('pos_service_tickets')->where('channel','phone');
        if($central&&$branch['kind']==='f')$query->whereIn('branch',array_column(array_filter($this->access->branches($actor),fn($b)=>$b['kind']==='f'),'value'));
        else $query->where('branch',$v['branch']);
        $this->numberFilter($query,'phone_key',$keys,$prefix);
        foreach($query->orderByDesc('id')->limit(40)->get() as $row)$add(['name'=>$row->customer_name,'phone'=>$row->customer_phone,'address'=>$row->address,'area'=>$row->area??'','last_ticket_id'=>(int)$row->id,'delivery_notes'=>$row->delivery_notes??'']);
        // Existing app customers are available to the call center. Branch staff
        // can only look up app customers who have ordered from their own branch.
        if(count($items)<8&&$branch['kind']==='f'&&$this->access->has('users','mobile')) {
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
                    $add(['name'=>$customer->name??'','phone'=>$customer->mobile,'address'=>implode('، ',array_unique($parts)),'area'=>$address->area_name??'','delivery_notes'=>$address->badge??'']);
                }
            }
        }
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
