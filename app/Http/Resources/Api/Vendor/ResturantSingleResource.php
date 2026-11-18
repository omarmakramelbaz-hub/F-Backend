<?php

namespace App\Http\Resources\Api\Vendor;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\Home\CategoryResource;

class ResturantSingleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                        => $this->id,
            'vendor_id'                 => (int) $this->user_id,
            'vendor_name'               => $this->user?->name,

            'vendor_email'               => $this->user?->email,
            'name'                      => $this->name,
            'status'                    => $this->effective_status,
            'avg_rate'                  => (double) $this->avg_rate,
            'address'                   => $this->address,
            'logo'                      => $this->getFirstMediaUrl('logo','thumb'),
            'bg_image'                  => $this->getFirstMediaUrl('bg_image','thumb'),
            'delivery_time'             => $this->delivery_time,
            'lat'                       => $this->lat,
            'lng'                       => $this->lng,
            'is_fav'                    => $this->is_fav(),
            'service_fees'              =>(double)$this->service_fees,
            'close_at'                  =>$this->close_at,
            'open_at'                   =>$this->open_at,
            'min_order_price'           =>(int)$this->min_order_price,
            'km_price'                  =>(double)$this->km_price,
             'default_0_1'=>(double)$this->default_0_1,
            'default_1_2'=>(double)$this->default_1_2,
            'default_2_3'=>(double)$this->default_2_3,
            'resturant_areas'           => $this->resturant_areas,
            'resturant_categorys'       => CategoryResource::collection($this->resturant_categorys()),
            'resturant_items'           => ResturantProductResource::collection(\App\Support\PosOnlyProducts::without($this->resturant_products)),
            'created_at'                => $this->created_at,
            'under_contract'            => $this->under_contract,
            'highest_rated'             =>ResturantProductResource::collection(\App\Support\PosOnlyProducts::without($this->resturant_highest_rated_products)->take(4))
        ];
    }
}
