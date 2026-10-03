<?php

namespace App\Http\Resources\Api\User;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\Auth\UserAddressResource;
use DB;
class ResturantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $tax = DB::table('settings')->where('name', 'tax')->first()->payload;
        $zone = $this->resturant_areas()->where('area_id', auth('api')->user()->area_id)->first();
        $service_fees = DB::table('settings')->where('name', 'service_fees')->first()->payload;


        return [
            // 'id'=>$this->id,
            'resturant_id' => (int) $this->id,
            'resturant_name' => $this->name,
            'resturant_status' => $this->effective_status,
            'resturant_open_at' => $this->open_at,
            'resturant_close_at' => $this->close_at,
            'resturant_logo' => $this->getFirstMediaUrl('logo', 'thumb'),
            'resturant_zone' => $zone && $zone->type == 'kilo' ? true : false,
            'zone_day' => $zone?->expected_delivery,
            'zone_type' => $zone?->type,
            'resturant_areas' => $this->resturant_areas,
            'resturant_min_order_price' => (double) $this->min_order_price,
            'default_0_1' => (double) $this->default_0_1,
            'default_1_2' => (double) $this->default_1_2,
            'default_2_3' => (double) $this->default_2_3,

            'resturant_km_price' => (double) $this->km_price,
            'resturant_service_fees' => (double) $this->service_fees,
            'tax' => (double) preg_replace('#[^\w()/.%\-&]#', "", $tax),
            'service_fees' => (double) preg_replace('#[^\w()/.%\-&]#', "", $service_fees),

        ];
    }
}
