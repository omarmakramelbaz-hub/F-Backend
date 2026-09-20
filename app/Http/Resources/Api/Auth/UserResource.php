<?php

namespace App\Http\Resources\Api\Auth;

use Illuminate\Http\Resources\Json\JsonResource;
use DB;
use App\Models\GeneralSettings;
use App\Models\Resturant;
class UserResource extends JsonResource
{
    private $token = '';

    public function getToken($token)
    {
        $this->token = $token;
        return $this;
    }

    public function getPhotoProfile($photo_profile)
    {
        $this->photo_profile = $photo_profile;
        return $this;
    }

    public function toArray($request)
    {
        $resturant=Resturant::where('user_id', $this->id)->first();
        $km_price = $resturant?->km_price;
        $tax = DB::table('settings')->where('name','tax')->first()->payload;
        $app_banner_background_color = app(GeneralSettings::class)->app_banner_background_color;
        $service_fees =$resturant?->service_fees;
        $partnerApplication = $this->pending_vendor;
        $professionLabels = $partnerApplication?->profession_key
            ? (\App\Http\Controllers\Api\V1\PartnerApplicationController::professions()[$partnerApplication->profession_key] ?? null)
            : null;
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'email'               => $this->email,
            'account_type'        => $this->account_type,
            'app_scope'           => $this->app_scope,
            'is_go_partner'       => $this->app_scope === 'go_partner',
            'partner_profession_key' => $partnerApplication?->profession_key,
            'partner_profession_name_ar' => $professionLabels['ar'] ?? null,
            'partner_profession_name_en' => $professionLabels['en'] ?? null,
            'partner_work_radius_km' => $partnerApplication?->work_radius_km,
            'partner_application_status' => $partnerApplication?->status,
            'country_code'        => $this->country_code,
            'mobile'              => $this->mobile,
            'fcm_id'                 => $this->fcm_id,
            'gender'              => $this->gender,
            'lat'                 => $this->lat,
            'lng'                 => $this->lng,
            'country_name'                 => $this->country_name,
            'city_name'                 => $this->city_name,
            'address'                 => $this->address,
            'status'             => $this->status,
            'mobile_code'         => '1234',
            'area_id'             => $this->area_id,
            'area_title'          => $this->area?->title,
            'cart'                => $this->pending_order()?->carts->count(),
            'photo_profile'       => $this->getFirstMediaUrl('photo_profile','thumb'),
            'mobile_verified_at'  => $this->mobile_verified_at,
            'balance'             => $this->balance,
            'min_wallet'          =>$this->min_wallet,
            'min_wallet_disabled'          =>$this->min_wallet?$this->min_wallet/2:null,
            
            'resturant_id'        => ($resturant)? (int) $resturant->id:null,
            'resturant_lat'        => ($resturant)? $resturant->lat:null,
            'resturant_lng'        => ($resturant)? $resturant->lng:null,
            'resturant_city'      => ($resturant)?  $resturant->city_name:null,
            
            'resturant_open_at'        => ($resturant)? $resturant->open_at:null,
            'resturant_close_at'      => ($resturant)?  $resturant->close_at:null,

            'resturant_city_id'      => ($resturant)?  $resturant->area_id:null,
            'resturant_cityname'      => ($resturant)?  $resturant->area?->title:null,

            'resturant_phone'      => ($resturant)?  $resturant->resturant_phone:null,


            'resturant_name'        => ($resturant)? $resturant->name:null,
            'resturant_logo'        => ($resturant)? $resturant->getFirstMediaUrl('logo','resturants'):null,
            
            'resturant_bg_image'    => ($resturant)? $resturant->getFirstMediaUrl('bg_image','thumb'): null,

            'resturant_area_id'        => ($resturant)? $resturant->resturant_areas()->where('type','kilo')->first()?->area_id:null,
            'resturant_area_name'        => ($resturant)? $resturant->resturant_areas()->where('type','kilo')->first()?->area?->title:null,
            'myresturant_has_menu'=> ($resturant)? ((count($resturant->resturant_products) > 0)?'yes':'no') :null,
            'resturant_parent_id' => ($resturant)? (int) $resturant->parent_id:null,
            'parent_has_menu'     => ($resturant)? ((($resturant->parent?->resturant_products->count()) > 0)?'yes':'no') :null,
            'delegate_status'     => $this->connected,
            'vendor_status'       => ($resturant)?$resturant->status:null,
            'created_at'          => $this->created_at,
            'token'               => $this->token,
            'expiration_date'               => $this->expiration_date,
            'km_price'        => (int) preg_replace('#[^\w()/.%\-&]#',"",$km_price),
            'tax'        => (double) preg_replace('#[^\w()/.%\-&]#',"",$tax),
            'service_fees'        => (double) preg_replace('#[^\w()/.%\-&]#',"",$service_fees),
             'min_order_price'=>($resturant)? (int) $resturant->min_order_price:null,
            'delegate_fees'      =>(double)$this->delegate_fees,
            'user_addresses'  => UserAddressResource::collection($this->addresses),
        
            'notificaions_count'  =>  $this->notifications()->orderBy('id','DESC')->count() ,
            'go_drive_block'=>$this->go_drive_block,
            'wallet_block'=>($this->status == 'disabled')?1:0,

            'app_banner_background_color'=>$app_banner_background_color,
            'otp_first_order' => $this->otp_first_order,
            'otp_first_no' => $this->otp_first_no,
            'app_multi_vendor'=>Resturant::where('control','show')->count()>1?null:Resturant::where('control','show')->pluck('id')->first(),
            ];
    }
}
