<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PhoneDelivery
{
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access){$this->access=$access;}
    public function settings(string $branch,$actor): array
    {
        $b=$this->access->branch($branch,$actor);$row=$b['kind']==='f'?DB::table('resturants')->where('id',$b['id'])->first():DB::table('go_stores')->where('user_id',$b['id'])->first();
        $lat=$row->lat??($row->latitude??null);$lng=$row->lng??($row->longitude??null);$rate=$row->km_price??null;
        $valid=is_numeric($lat)&&is_numeric($lng)&&abs((float)$lat)<=90&&abs((float)$lng)<=180&&!((float)$lat===0.0&&(float)$lng===0.0);
        $cents=null;try{if($rate!==null)$cents=Money::minor((string)$rate);}catch(\InvalidArgumentException $e){}
        return ['branch'=>$b,'latitude'=>$valid?(float)$lat:null,'longitude'=>$valid?(float)$lng:null,'km_price'=>$cents!==null&&$cents>=0?Money::decimal($cents):null,'ready'=>$valid&&$cents!==null&&$cents>=0&&$cents<=100000000];
    }
    public function quote(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:30','latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','location_confirmed'=>'required|accepted'])->validate();
        $policy=$this->settings($v['branch'],$actor);abort_unless($policy['ready'],422,'حدد دبوس الفرع وسعر الكيلومتر في إعدادات المطعم أولًا.');
        $lat=round((float)$v['latitude'],7);$lng=round((float)$v['longitude'],7);abort_if($lat===0.0&&$lng===0.0,422,'أكد موقع العميل الصحيح.');
        $a=sin(deg2rad($lat-$policy['latitude'])/2)**2+cos(deg2rad($policy['latitude']))*cos(deg2rad($lat))*sin(deg2rad($lng-$policy['longitude'])/2)**2;
        $meters=(int)round(6371000*2*atan2(sqrt(min(1,$a)),sqrt(max(0,1-$a))));
        $road=app(PhoneMapProvider::class)->enabled()?app(PhoneMapProvider::class)->road($policy,$lat,$lng):null;if($road)$meters=$road['meters'];
        $fee=intdiv($meters*Money::minor($policy['km_price'])+500,1000);abort_unless($fee<=100000000,422,'قيمة التوصيل أكبر من الحد المسموح.');
        $data=['branch'=>$v['branch'],'branch_latitude'=>$policy['latitude'],'branch_longitude'=>$policy['longitude'],'latitude'=>$lat,'longitude'=>$lng,'distance_meters'=>$meters,'distance_km'=>number_format($meters/1000,3,'.',''),'km_price'=>$policy['km_price'],'delivery_fee'=>Money::decimal($fee),'method'=>$road?$road['method']:'pin_distance'];
        if($road)$data['route_path']=$road['path'];
        $data['delivery_quote_hash']=PosServiceTicket::fingerprint($data);return ['success'=>true,'delivery'=>$data];
    }
}
