<?php

namespace App\Http\Resources\Api\Vendor;

use Illuminate\Http\Resources\Json\JsonResource;

class ResturantProductResource extends JsonResource
{
    public function toArray($request)
    {  
        $cart=auth('api')->user()?->carts()->where('resturant_product_id',$this->id)->latest()->first();
        return [
            'id'                        => $this->id,
            'vendor_id'                 => (int) $this->added_by,
            'vendor_name'               => $this->vendor?->name,
            'resturant_id'              => (int) $this->resturant_id,
            'resturant_name'            => $this->resturant?->name,
            'product_name'              => $this->product_name,
            'product_description'       => $this->product_description,
            'features'                  =>FeatureResource::collection($this->product?->product_features),
            'product_price'             => (double) $this->product_price,
            'extra_combo'               => (double) json_decode($this->price)->extra_combo,
            'extra_large'               => (double) json_decode($this->price)->extra_large,
            'extra_medium'              => (double) json_decode($this->price)->extra_medium,
            'extra_clean'               => (double) json_decode($this->price)->extra_clean,
            'extra_clear'               => (double) json_decode($this->price)->extra_clear,
            'extra_vacuim'               =>(double) json_decode($this->price)->extra_vacuim,
            'category_id'               =>(int)$this->category_id,
            'category_name'             =>$this->category?->name,
            'sub_category_id'               =>(int)$this->product?->subcategory_id,
            'sub_category_name'             =>$this->product?->subcategory?->name,
            'product_id'               =>(int)$this->product?->id,
            'product_title'             =>$this->product?->name,

            'status'                    =>$this->status,
            'highest_rated'             =>$this->highest_rated,
            'has_clean'                    =>$this->product?->has_clean,

            'product_image'             => $this->getFirstMediaUrl('product_image','thumb'),
            'created_at'                => $this->created_at,
            
            'latest_order_id'=>$cart?->order_id === null ? null : (int) $cart->order_id,
            // Installed customer apps parse this optional hint as int?, while MySQL
            // now returns DECIMAL quantities as strings (including whole "1.000").
            // Fractional weights belong in the additive numeric field, never rounded.
            'latest_order_qty'=>$this->legacyLatestQuantity($cart?->qty),
            'latest_order_quantity'=>$cart?->qty === null ? null : (float) $cart->qty,
            'latest_order_product_clean'=>$cart?->product_clean,
            'latest_order_product_feature'=>$cart?->product_feature === null ? null : (int) $cart->product_feature,
        ];
    }

    private function legacyLatestQuantity($quantity): ?int
    {
        if ($quantity === null || !preg_match('/^\+?(\d+)(?:\.0+)?$/D', (string) $quantity, $parts)) {
            return null;
        }
        $whole = ltrim($parts[1], '0');
        $value = filter_var($whole === '' ? '0' : $whole, FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0]]);
        return $value === false ? null : $value;
    }
}
