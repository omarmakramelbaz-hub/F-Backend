<?php

namespace App\Http\Resources\Api\Vendor;

use Illuminate\Http\Resources\Json\JsonResource;

class ResturantProductResource extends JsonResource
{
    /**
     * Loads everything a list of menu items shows in a few queries instead of
     * several per item: their relations, images and the signed-in customer's
     * latest cart line for each (what toArray would otherwise ask per item).
     */
    public static function preload($products): void
    {
        $products = new \Illuminate\Database\Eloquent\Collection(collect($products)->values()->all());
        if ($products->isEmpty()) return;
        $products->loadMissing(['vendor', 'resturant', 'category', 'media',
            'product.product_features', 'product.subcategory', 'product.category']);
        $user = auth('api')->user();
        $latest = $user === null ? collect() : $user->carts()
            ->whereIn('resturant_product_id', $products->pluck('id')->unique()->all())
            ->latest()->orderByDesc('id')->get()->unique('resturant_product_id')->keyBy('resturant_product_id');
        foreach ($products as $product) {
            $product->setRelation('latestUserCart', $latest->get($product->id));
        }
    }

    public function toArray($request)
    {
        $cart = $this->resource->relationLoaded('latestUserCart')
            ? $this->resource->getRelation('latestUserCart')
            : auth('api')->user()?->carts()->where('resturant_product_id',$this->id)->latest()->first();
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
            // «مسابقة السحب» by this product: the badge and its prize (null when none runs in this branch).
            'draw'                      =>\App\Services\Draws\ProductDraw::badge($this->product_name),

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
