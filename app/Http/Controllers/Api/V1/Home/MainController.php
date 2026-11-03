<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Home\ContactResource;
use App\Models\User;
use App\Models\Contact;
use App\Models\Advertising;
use App\Models\Area;
use App\Models\Order;
use App\Models\About;
use App\Http\Resources\Api\Home\AboutResource;
use App\Http\Resources\Api\Home\AdvertisingResource;
use Illuminate\Http\Request;
use App\Http\Traits\ApiResponses;
use App\Http\Traits\HomeTraits;
use App\Http\Requests\Api\Home\ContactRequest;
use Notification;
use App\Models\GeneralSettings;
use App\Http\Resources\Api\Home\SplasheResource;
use App\Http\Resources\Api\Home\AreaResource;
use App\Http\Resources\Api\Home\HelpResource;
use App\Http\Resources\Api\Home\SlidearResource;
use App\Models\Resturant;
class MainController extends Controller {
  use ApiResponses;use HomeTraits;
    
    public function getCitiesInCountry(){
        // dd(getCityName(30.12548785816271, 31.166614510515387));
        $user_order_owner = User::where('id',226)->first();
                $order = Order::where('id',375)->first();

            if($user_order_owner){
                Notification::send($user_order_owner,new \App\Notifications\NotifyUserOrderStatusUpdatedNotification($order));
            }
//         $text = getCityName(26.05330252308602, 32.166512248267104);
// $ignoreWords = ['ثان','مركز' ,'مدينة','قسم', 'اول'];

// // Remove words
// $filteredText = str_replace($ignoreWords, '', $text);

// // Optionally, you may want to clean up extra spaces
// $filteredText = preg_replace('/\s+/', ' ', trim($filteredText));
// dd($filteredText);
    }

  public function storeContact(ContactRequest $request) {
        
    $contact = Contact::create($request->validated());
        $admins = User::get();
                foreach ($admins as $key => $value) {   
                    if($value->hasPermissionTo('contact-list')){
                        Notification::send($value,new \App\Notifications\NotifyContactUsNotification($contact));
                    }
                }
    $contactData = ContactResource::make($contact);
    return $this->successResponse($contactData,__('api.created new contact'));
  }

 public function getSetting(GeneralSettings $settings) {
     $admin=User::where('account_type','admin')->first();
         $resturant = Resturant::query();
    if(! empty($request->lat)  && ! empty($request->lng) ){
      
      $latitude = $request->lat;
      $longitude = $request->lng; 
      $city_name = getCityName($latitude,$longitude);         
    $area = Area::where('title_ar', 'LIKE', '%' . $city_name . '%')->orWhere('title_en', 'LIKE', '%' . $city_name . '%')->first();
    // dd($area);
  
      $resturant =$resturant->select(\DB::raw('*, ( 6367 * acos( cos( radians('.$latitude.') ) * cos( radians( lat ) ) * 
                      cos( radians( lng ) - radians('.$longitude.') ) + sin( radians('.$latitude.') ) * sin( radians( lat ) ) ) ) AS distance'))
                    ->having('distance', '<', 10000000)
                    ->orderBy('distance','asc')->orWhereHas('resturant_areas',function($q) use ($area){
                        $q->where('area_id' ,$area->id);
                    });
    }
    
    $resturant = $resturant->whereNotNull(['lat','lng'])->where('status','!=','hide')->get();
    $data= [
      'email'           =>  $settings->email,
      'mobile'          =>  $settings->phone,
      'address'         =>  $settings->address,
    //   'km_price'        =>  (int)$settings->km_price,
    //   'service_fees'    =>  (int)$settings->service_fees,
      'admin_id'        =>$admin->id,
      'admin_device_token'=>$admin->device_token,
    //   'admin_fcm_id'=>$admin->fcm_id,
      'logo'            =>config('app.url').'/storage/'.$settings->logo,
      'favicon'         =>config('app.url').'/storage/'.$settings->favicon,
      'twitter_link'    =>$settings->twitter_link,
      'facebook_link'   =>$settings->facebook_link,
      'instagram_link'  =>$settings->instagram_link,
      'google_link'     =>$settings->google_link,
      'privacy'         =>$settings->policy(),
      'terms'           =>$settings->terms(),
      'contact_text'    => $settings->contact_text(),
      'wallet_card_activate' => $settings->wallet_card_activate,
      'payment_card_activate' =>$settings->payment_card_activate,
    //   'min_order_price' =>$settings->min_order_price,
      'count_resturant_not_hide'=>$resturant->count(),
      'app_banner_background_color'=>$settings->app_banner_background_color,
    //   if delegate_vendor_small_info==1 show only required small data
      'delegate_vendor_small_info'=>$settings->delegate_vendor_small_info,
      'default_0_1'=>$settings->default_0_1,
      'default_1_2'=>$settings->default_1_2,
      'default_2_3'=>$settings->default_2_3,
      
    ];
    // if(auth('api')->check()){
    //     $cart_count =auth()->guard('api')->user()->cartCount();
    //     $data['cart_count'] = $cart_count;
    //  }else{
    //     $data['cart_count'] =  0;
    //  }
     
    return $this->successResponse($data,trans('api.success data') );
  }
 public function advertising(GeneralSettings $settings) {
    $data= [
      'advertise_resturant_id'           => (int) $settings->advertise_resturant_id,
      'advertise_image'            =>config('app.url').'/storage/'.$settings->advertise_image,
    ];

    return $this->successResponse($data,trans('api.success data') );
  }

  public function getAboutMain() {
    $first_onboarding= About::find(1);
    $second_onboarding= About::find(2);
    $third_onboarding= About::find(3);
    $data = [
        'first_onboarding_title' => $first_onboarding->title,
        'first_onboarding_special_text' => $first_onboarding->special_text,
        'first_onboarding_description' => $first_onboarding->description,
        
        'second_onboarding_title' => $second_onboarding->title,
        'second_onboarding_special_text' => $second_onboarding->special_text,
        'second_onboarding_description' => $second_onboarding->description,
        
        'third_onboarding_title' => $third_onboarding->title,
        'third_onboarding_special_text' => $third_onboarding->special_text,
        'third_onboarding_description' => $third_onboarding->description,
        ];
    return $this->successResponse($data,__('api.get all about_mains'));
  }
 
 public function splashes(){
     $data=SplasheResource::collection($this->splashes_data());
     return $this->successResponse($data,__('api.get all about_mains')); 
 }
 public function areas(){
     $data=AreaResource::collection($this->areas_data());
     return $this->successResponse($data,__('api.get all about_mains')); 
 }
  public function help(){
     $data=HelpResource::collection($this->help_data());
     return $this->successResponse($data,__('api.get all about_mains')); 
 }
 public function slidears(){
     if($this->slidear_data()){
        $data=SlidearResource::collection($this->slidear_data());
     }else{
         $data = null;
     }
     return $this->successResponse($data,__('api.get all about_mains')); 
 }
 
 
  public function dailyAdvertising(){
      if(request('lat') != null && request('lng') != null){
        $city_name =getCityName(request('lat'), request('lng'));
        $area = Area::where('title_ar', 'LIKE', '%' . $city_name . '%')->orWhere('title_en', 'LIKE', '%' . $city_name . '%')->first();
        if(!$area){
            $area = null;
        }
        $advertisings= Advertising::whereHas('resturant',function($q) use($area){
            $q->where('area_id', $area->id)->orWhereHas('resturant_areas',function($q) use ($area){
                        $q->where('area_id' ,$area->id);
                    });
        })->where('from_date','<=',\Carbon\Carbon::now())->where('to_date','>=',\Carbon\Carbon::now())->get();
        $data = AdvertisingResource::collection($advertisings);
        return $this->successResponse($data,trans('api.success data') );
      }
      else{
          return $this->successResponse(null,trans('api.empty data') );
      }
  }
  
  
}
