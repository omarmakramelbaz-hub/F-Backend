<?php

namespace App\Http\Resources\Api\Home;

use Illuminate\Http\Resources\Json\JsonResource;

class CouponWheelResturant extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                        => $this->resturant->id,
            'vendor_id'                 => (int) $this->resturant->user_id,
            'vendor_name'               => $this->resturant->user?->name,
            'vendor_email'               => $this->resturant->user?->email,
            'name'                      => $this->resturant->name,
            'status'                    =>  ($this->resturant->resturant_products_count ?? $this->resturant->resturant_products()->count())==0?'closed':$this->resturant->status,
            'avg_rate'                  => (double) $this->resturant->avg_rate,
            'address'                   => $this->resturant->address,
            'logo'                      => $this->resturant->getFirstMediaUrl('logo','thumb'),
            'bg_image'                  => $this->resturant->getFirstMediaUrl('bg_image','thumb'),
            'delivery_time'             => $this->resturant->delivery_time,

            'resturant_phone'             => $this->resturant->resturant_phone,
            'lat'                       => $this->resturant->lat,
            'lng'                       => $this->resturant->lng,
            'country_name'              => $this->resturant->country_name,
            'city_name'                 => $this->resturant->city_name,
            'created_at'                => $this->resturant->created_at,
            'city_id'                   => $this->resturant->area_id,
            'cityname'                  => $this->resturant->area?->title,
            'under_contract'            => $this->resturant->under_contract,
            'service_fees'             =>(double)$this->resturant->service_fees,
            'close_at'                  =>$this->resturant->close_at,
            'open_at'                   =>$this->resturant->open_at,
            'min_order_price'           =>(double)$this->resturant->min_order_price,
            'km_price'           =>(double)$this->resturant->km_price,
        ];
    }
}
