<?php

namespace App\Http\Resources\Api\Vendor;

use Illuminate\Http\Resources\Json\JsonResource;

class ResturantResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                        => $this->id,
            'vendor_id'                 => (int) $this->user_id,
            'vendor_name'               => $this->user?->name,
            'vendor_email'               => $this->user?->email,
            'name'                      => $this->name,
                // 'status'                    =>  $this->resturant_products->count()==0?'closed':$this->status,
            'status'                    => $this->effective_status,
            'avg_rate'                  => (double) $this->avg_rate,
            'address'                   => $this->address,
            'logo'                      => \App\Support\MediaThumb::first($this->resource,'logo'),
            'bg_image'                  => \App\Support\MediaThumb::first($this->resource,'bg_image'),
            'delivery_time'             => $this->delivery_time,

            'resturant_phone'             => $this->resturant_phone,
            'lat'                       => $this->lat,
            'lng'                       => $this->lng,
            'country_name'              => $this->country_name,
            'city_name'                 => $this->city_name,
            'created_at'                => $this->created_at,
            'city_id'                   => $this->area_id,
            'cityname'                  => $this->area?->title,
            'under_contract'            => $this->under_contract,
            'service_fees'             =>(double)$this->service_fees,
            'close_at'                  =>$this->close_at,
            'open_at'                   =>$this->open_at,
            'min_order_price'           =>(int)$this->min_order_price,
            'km_price'           =>(double)$this->km_price,
            'default_0_1'=>(double)$this->default_0_1,
            'default_1_2'=>(double)$this->default_1_2,
            'default_2_3'=>(double)$this->default_2_3,
        ];
    }
}
