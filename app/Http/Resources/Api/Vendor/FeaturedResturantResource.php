<?php

namespace App\Http\Resources\Api\Vendor;

use Illuminate\Http\Resources\Json\JsonResource;

class FeaturedResturantResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                        => $this->id,
            'vendor_id'                 => (int) $this->user_id,
            'vendor_name'               => $this->user?->name,
            'name'                      => $this->name,
            'is_featured'               => $this->is_featured,
            'status'                    => $this->resturant_products->count()==0?'closed':$this->effective_status,
            'logo'                      => \App\Support\MediaThumb::first($this->resource,'logo'),
            
        ];
    }
}
