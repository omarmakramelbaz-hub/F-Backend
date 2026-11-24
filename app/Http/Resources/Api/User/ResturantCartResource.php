<?php

namespace App\Http\Resources\Api\User;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\Auth\UserAddressResource;
use DB;
class ResturantCartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {      $tax = DB::table('settings')->where('name','tax')->first()->payload;
            $zone=$this->resturant?->resturant_areas()->where('area_id',auth('api')->user()->area_id)->first();
               $service_fees = DB::table('settings')->where('name','service_fees')->first()->payload;

       
        return [
            'id'=>$this->id,
            'resturant_id'      => (int) $this->resturant_id,
            'resturant_name'    => $this->resturant?->name,
            'resturant_status'    => $this->resturant?->effective_status,
            'resturant_logo'    => \App\Support\MediaThumb::first($this->resturant,'logo'),
            'resturant_zone'              =>$zone && $zone->type=='kilo'?true:false,
            'zone_day'          =>$zone?->expected_delivery,
            'zone_type'          =>$zone?->type,
                        'resturant_areas'    => $this->resturant?->resturant_areas,
            'resturant_min_order_price'           =>(double) $this->resturant?->min_order_price,
            'default_0_1'=>(double)$this->resturant?->default_0_1,
            'default_1_2'=>(double)$this->resturant?->default_1_2,
            'default_2_3'=>(double)$this->resturant->default_2_3,

            'resturant_km_price'           =>(double) $this->resturant?->km_price,
            'resturant_service_fees'           =>(double) $this->resturant?->service_fees,
            'tax'        => (double) preg_replace('#[^\w()/.%\-&]#',"",$tax),
            'service_fees'        => (double) preg_replace('#[^\w()/.%\-&]#',"",$service_fees),

        ];
    }
}
