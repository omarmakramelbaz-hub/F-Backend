<?php
namespace App\Services\Dashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
class PosServicePhone
{
    private TakeawayAccess $access;private PosServiceTable $tables;
    public function __construct(TakeawayAccess $access,PosServiceTable $tables){$this->access=$access;$this->tables=$tables;}
    public static function key(string $phone): string {return preg_replace('/[^0-9+]/','',$phone);}
    public function customers(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'phone'=>'required|string|max:30'])->validate();
        $this->tables->requireReady();$branch=$this->access->branch($v['branch'],$actor);$key=self::key($v['phone']);
        abort_unless(preg_match('/^\+?[0-9]{6,20}$/D',$key),422,'رقم الهاتف غير صالح.');
        $rows=DB::table('pos_service_tickets')->where('branch',$v['branch'])->where('channel','phone')->where('phone_key',$key)->orderByDesc('id')->limit(5)->get();$items=[];$seen=[];
        foreach($rows as $row){$hash=PosServiceTicket::fingerprint([$row->customer_name,$row->address,$row->area]);if(isset($seen[$hash]))continue;$seen[$hash]=true;
            $items[]=['name'=>$row->customer_name,'phone'=>$row->customer_phone,'address'=>$row->address,'area'=>$row->area,'last_ticket_id'=>(int)$row->id,'delivery_notes'=>$row->delivery_notes??''];}
        return ['success'=>true,'branch'=>$branch,'items'=>$items,'customers'=>$items,'matches'=>$items];
    }
}
