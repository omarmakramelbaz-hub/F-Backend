<?php

namespace App\Http\Resources\Api\User;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\Vendor\ResturantProductResource;
use DB;
class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    /** Read once per request, not once per cart line. */
    private static ?string $minOrderPrice = null;
    private static array $featureNames = [];

    public function toArray($request)
    {
        $min_order_price = self::$minOrderPrice ??= DB::table('settings')->where('name','min_order_price')->first()->payload;
        $featureId = $this->product_feature;
        if ($featureId !== null && !array_key_exists($featureId, self::$featureNames)) {
            self::$featureNames[$featureId] = \App\Models\ProductFeature::where('id',$featureId)->first()?->name;
        }

        return [
            'id'                    => $this->id,
            'resturant_product'     => new ResturantProductResource($this->resturant_product),
            'resturant_id'          => $this->resturant_id,
            'resturant_name'        => $this->resturant?->name,
            'resturant_lat'        => $this->resturant?->lat,
            'resturant_lng'        => $this->resturant?->lng,
            'resturant_status'        => $this->resturant?->status,
            'resturant_city_name'        => $this->resturant?->city_name,
            'resturant_delivery_time'        => $this->resturant?->delivery_time,

            'order_id'              => $this->order_id,
            'price'                 => (double) $this->price,
            'qty'                   => (float)$this->qty,
            'product_feature'       => $this->product_feature,
            'product_feature_name'  => $featureId === null ? null : self::$featureNames[$featureId],
            'product_clean'         => $this->product_clean,
            'created_at'            => $this->created_at,
            'min_order_price'       => (int) preg_replace('#[^\w()/.%\-&]#',"",$min_order_price),
            'total'                 =>$this->qty*$this->price,
            'updated_total'         =>(double)$this->updated_total,
            'reason_update_total'   =>$this->reason_update_total,
  
        ];
    }
}
