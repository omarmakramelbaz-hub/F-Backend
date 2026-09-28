<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\Auth\UserAddressResource;
use App\Models\User;
use App\Models\GeneralSettings;
class ShippingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
     

   
    
    public function toArray($request)
    {
        $admin=User::where('account_type','admin')->first();
        $setting=app(GeneralSettings::class);
        $goPayment=\App\Services\GoPayments\OrderPayments::record((int)$this->id);
        return [
            'id'                        =>$this->id,
            'order_no'                  => $this->order_no,
           
            'delegate_id'               => $this->delegate_id,
            'delegate_name'             => $this->delegate?->name,
            'delegate_mobile'           => $this->delegate?->mobile,
            'delegate_fcm_id'           => $this->delegate?->fcm_id,
            'delegate_logo'             => $this->delegate?->getFirstMediaUrl('photo_profile','thumb'),
            'user_id'                   => $this->user_id,
            'user_name'                 => $this->user?->name,
            'user_fcm_id'                 => $this->user?->fcm_id,
            'user_balance'                 => (double)$this->user?->balance,
            'user_mobile'               => $this->user?->mobile,
            'user_location'             => $this->user?->address,
            'user_logo'                 => $this->user?->getFirstMediaUrl('photo_profile','thumb'),

            'status'                    => $this->status,
            'type'                      => $this->type,
            'order_type'                      => $this->order_type,
            'payment_type'              => $this->payment_type,
            'payment_deferred'          => $goPayment !== null,
            'payment_status'            => $goPayment?->status,
            'payment_required'          => $goPayment && $this->status==='accepted' && in_array($goPayment->status,['ready','pending','creating'],true),

            'created_at'                => $this->created_at,
            'updated_at'                => $this->updated_at,

                       // shipping
            // 'shipping_id'=>$this->shipping?->id,
            'description'=>$this->shipping?->description,
            'from_lat'=>$this->shipping?->from_lat,
            'from_lng'=>$this->shipping?->from_lng,
            'to_lat'=>$this->shipping?->to_lat,
            'to_lng'=>$this->shipping?->to_lng,
            'from_address'=>$this->shipping?->from_address,
            'to_address'=>$this->shipping?->to_address,
            'actual_price'=>(double)$this->shipping?->actual_price,
            'expected_price'=>(double)$this->shipping?->expected_price,
            'admin'=>$admin->id,
            'admin_device_token'=>$admin->device_token,
            'admin_fcm_id'=>$admin->fcm_id,
            'setting_mobile'=>$setting->phone,

    
        ];
    }
}
