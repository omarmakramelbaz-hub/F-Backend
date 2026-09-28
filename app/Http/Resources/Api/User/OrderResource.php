<?php

namespace App\Http\Resources\Api\User;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\Auth\UserAddressResource;
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
     
      protected $orders_count;

   
    public function getOrdersCount($orders_count)
    {
        $this->orders_count = $orders_count;
        return $this;
    }
    
    public function toArray($request)
    {
        $goPayment=\App\Services\GoPayments\OrderPayments::record((int)$this->id);

        return [
            'id'                        =>$this->id,
            'order_no'                  => $this->order_no,
            'resturant_id'              => (int) $this->resturant_id,
            'resturant_name'            => $this->resturant?->name,
            'resturant_areas'           => $this->resturant?->resturant_areas,
            'resturant_phone'           =>$this->resturant?->resturant_phone,
            'resturant_logo'            => $this->resturant?->getFirstMediaUrl('logo','thumb'),
            'resturant_location'        => $this->resturant?->address,
            'resturant_lat'             => $this->resturant?->lat,
            'resturant_lng'             => $this->resturant?->lng,
            'resturant_status'             => $this->resturant?->status,
            'resturant_km_price'        => (int) preg_replace('#[^\w()/.%\-&]#',"",$this->resturant?->km_price),
            'resturant_vendor_fcm_id'             => $this->resturant?->user?->fcm_id,
            'resturant_vendor_id'             => $this->resturant?->user_id,
            'resturant_vendor_device_token'             => $this->resturant?->user?->device_token,
            'delegate_id'               => $this->delegate_id,
            'delegate_name'             => $this->delegate?->name,
            'delegate_mobile'           => $this->delegate?->mobile,
            'delegate_fcm_id'           => $this->delegate?->fcm_id,
            'delegate_logo'             => $this->delegate?->getFirstMediaUrl('photo_profile','thumb'),
            'user_id'                   => $this->user_id,
            'user_name'                 => $this->user?->name,
            'user_mobile'               => $this->user?->mobile,
            'user_location'             => $this->user?->address,
            'user_fcm_id'             => $this->user?->fcm_id,
            'user_logo'                 => $this->user?->getFirstMediaUrl('photo_profile','thumb'),

            'status'                    => $this->status,
            'type'                      => $this->type,
            'order_type'                      => $this->order_type,
            'schedule_date'             => $this->schedule_date,
            'accepted_notify'           =>$this->accepted_notify,
            'payment_type'              => $this->payment_type,
            'payment_status'            => $goPayment?->status,
            'payment_required'          => $goPayment && !in_array($goPayment->status,['cash_due','held','paid'],true),
            'delivery_price'            => (double)$this->delivery_price,
            'tax'                       => $this->tax,
            'total_item_price'          =>(double) $this->total,
            'updated_total_item_price'  =>(double) $this->updated_total,
            'user_address_id'           => (int)$this->user_address_id,
            'user_address'              => new UserAddressResource($this->user_address),
            'items'                     => CartResource::collection($this->carts),
            'created_at'                => $this->created_at,
            'updated_at'                => $this->updated_at,
            'orders_count'              => $this->orders_count,
            'delegate_from_out'         => $this->delegate_from_out,
            'has_rated_before'          => ($this->type == 'shipping')?null :$this->rated_before(),
            'has_commissioned_before'   => $this->commissioned_before(),
            'has_transfered_before'     => $this->transfered_before(),
            'total_vendor_orders'       => \App\Models\Order::where('type','!=','wallet')->whereNotNull('status')->where('status','!=','pending')->where('resturant_id',$this->resturant_id)->count(),
            
            'total_delegate_orders'      => \App\Models\Order::where('type','!=','wallet')->where('delegate_id',$this->delegate_id)->whereNotNull('status')->where('status','!=','pending')->where('resturant_id',$this->resturant_id)->count(),
    
            'notes'=>$this->notes,
            'service_fees'  => $this->service_fees,
            'tax'  => $this->tax,
            'vendor_percentage'  => $this->vendor_percentage,
            'app_percentage'  => $this->app_percentage,
            'app_vendor_percentage' => $this->vendor_tax,
            'app_delegate_percentage' =>$this->delegate_to_app_percentage,
            'app_to_vendor_percentage'  => $this->app_to_vendor_percentage,
            'grand_total'  => $this->grand_total,
            
            // shipping
            'shipping_id'=>$this->shipping?->id,
            'description'=>$this->shipping?->description,
            'from_lat'=>$this->shipping?->from_lat,
            'from_lng'=>$this->shipping?->from_lng,
            'to_lat'=>$this->shipping?->to_lat,
            'to_lng'=>$this->shipping?->to_lng,
            'from_address'=>$this->shipping?->from_address,
            'to_address'=>$this->shipping?->to_address,
            'actual_price'=>$this->shipping?->actual_price,
            'expected_price'=>$this->shipping?->expected_price,
            'delegate_has_status'=>(auth()->check())?($this->delegate_notifications()->where('delegate_id',auth('api')->check()?auth('api')->user()->id:auth('admin')->user()->id)->first()?->status) : null,
    
        ];
    }
}
