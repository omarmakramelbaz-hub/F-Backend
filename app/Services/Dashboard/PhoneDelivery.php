<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PhoneDelivery
{
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access){$this->access=$access;}
    public function settings(string $branch,$actor,?string $deliveryOriginBranch=null,bool $lock=false): array
    {
        $b=$this->access->branch($branch,$actor,$lock);$origin=null;
        if($deliveryOriginBranch!==null&&$deliveryOriginBranch!==''&&$deliveryOriginBranch!==$b['value']){
            $origin=app(FasakhanstaDeliveryOrigin::class)->check($deliveryOriginBranch,$b['value'],$actor,$lock);
            $lat=$origin['latitude'];$lng=$origin['longitude'];$rates=BranchDeliveryTariff::rates($origin);
        }else{
            $row=$b['kind']==='f'?DB::table('resturants')->where('id',$b['id'])->first():DB::table('go_stores')->where('user_id',$b['id'])->first();
            $lat=$row->lat??($row->latitude??null);$lng=$row->lng??($row->longitude??null);$rates=BranchDeliveryTariff::rates($row??[]);
        }
        $valid=is_numeric($lat)&&is_numeric($lng)&&abs((float)$lat)<=90&&abs((float)$lng)<=180&&!((float)$lat===0.0&&(float)$lng===0.0);
        $settings=['branch'=>$b,'latitude'=>$valid?(float)$lat:null,'longitude'=>$valid?(float)$lng:null,'km_price'=>$rates['km_price'],'pricing_rates'=>$rates,'ready'=>$valid&&$rates['km_price']!==null];
        if($origin!==null){
            $settings['delivery_origin_branch']=$origin['value'];$settings['fulfillment_branch']=$b['value'];
            $settings['delivery_origin']=array_intersect_key($origin,array_flip(['value','id','kind','name','label','address','phone','parent_id']))
                +['latitude'=>$settings['latitude'],'longitude'=>$settings['longitude'],'km_price'=>$settings['km_price']];
            $physical=DB::table('resturants')->where('id',$b['id'])->first();$pLat=$physical->lat??($physical->latitude??null);$pLng=$physical->lng??($physical->longitude??null);
            $pValid=is_numeric($pLat)&&is_numeric($pLng)&&abs((float)$pLat)<=90&&abs((float)$pLng)<=180&&!((float)$pLat===0.0&&(float)$pLng===0.0);
            $settings['fulfillment']=$b+['latitude'=>$pValid?(float)$pLat:null,'longitude'=>$pValid?(float)$pLng:null];
        }
        return $settings;
    }
    public function quote(array $values,$actor,bool $lock=false): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:30','delivery_origin_branch'=>['nullable','string','regex:/^f:[1-9][0-9]{0,18}$/D'],'latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','location_confirmed'=>'required|accepted'])->validate();
        $policy=$this->settings($v['branch'],$actor,$v['delivery_origin_branch']??null,$lock);abort_unless($policy['ready'],422,'حدد دبوس الفرع وسعر الكيلومتر في إعدادات المطعم أولًا.');
        $lat=round((float)$v['latitude'],7);$lng=round((float)$v['longitude'],7);abort_if($lat===0.0&&$lng===0.0,422,'أكد موقع العميل الصحيح.');
        $distance=BranchDeliveryTariff::distance($policy['latitude'],$policy['longitude'],$lat,$lng);
        $priced=BranchDeliveryTariff::price($distance,$policy['pricing_rates']);
        $road=app(PhoneMapProvider::class)->enabled()?app(PhoneMapProvider::class)->road($policy,$lat,$lng):null;
        $data=['branch'=>$v['branch'],'branch_latitude'=>$policy['latitude'],'branch_longitude'=>$policy['longitude'],'latitude'=>$lat,'longitude'=>$lng,'distance_meters'=>(int)round($distance),'distance_km'=>number_format($distance/1000,3,'.',''),'km_price'=>$policy['km_price'],'method'=>'app_direct']+$priced;
        if($road)$data+=['route_path'=>$road['path'],'route_method'=>$road['method'],'road_distance_meters'=>$road['meters'],'road_distance_km'=>number_format($road['meters']/1000,3,'.','')];
        if(isset($policy['delivery_origin_branch']))$data+=array_intersect_key($policy,array_flip(['delivery_origin_branch','fulfillment_branch','delivery_origin','fulfillment']));
        // The visual route can change without changing the app's payable tariff.
        $data['delivery_quote_hash']=PosServiceTicket::fingerprint(array_diff_key($data,array_flip(['route_path','route_method','road_distance_meters','road_distance_km'])));return ['success'=>true,'delivery'=>$data];
    }
}
