<?php

namespace App\Http\Resources\Api\User;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\Auth\UserAddressResource;
class WalletResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id'=>$this->id,
            'amount'=>(double)$this->amount,
            'type'=>$this->type,

            'from_user'=>$this->from_user,
            'from_user_name'=>$this->from?->name,
            'to_user'=>$this->to_user,
            'to_user_name'=>$this->to?->name,
            'order_id'=>$this->order_id,
            'order_no'=>$this->cart_order?->order_no,

            'payment' => $this->type === 'charging'
                && $this->transfer_reference === \App\Services\GoAccountCreator::openingReference((int) $this->to_user)
                    ? 'opening_balance' : $this->payment,
            'created_at'=>$this->created_at,
            
        ];
    }
}
