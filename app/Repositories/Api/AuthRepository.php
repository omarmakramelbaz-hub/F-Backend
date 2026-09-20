<?php

namespace App\Repositories\Api;

use App\Interfaces\Api\AuthRepositoryInterface;
use App\Models\User;
use App\Models\Area;
use App\Models\PendingVendor;
use App\Http\Traits\UploadImageTrait;
use App\Http\Traits\UserTrait;
use Arr;
use Notification;
use Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use App\Models\Resturant;
use App\Models\ResturantArea;
class AuthRepository implements AuthRepositoryInterface 
{
    use UploadImageTrait;use UserTrait;

    private function appScope(): string
    {
        return request()->header('X-App-Scope') === 'go_partner' ? 'go_partner' : 'fasakhansta';
    }

    public function login(array $Details) 
    {
          if (substr($Details['mobile'], 0, 1) === '0') {
        $Details['mobile'] = substr($Details['mobile'], 1);
    }
    
        $scope = $this->appScope();
        $baseQuery = function ($scopeValue) use ($Details) {
            return User::where(function ($query) use ($Details) {
                $query->where('mobile', $Details['mobile'])
                    ->orWhere('email', $Details['mobile']);
            })
            ->where('account_type', $Details['account_type'])
            ->when(Schema::hasColumn('users', 'app_scope'), function ($query) use ($scopeValue) {
                $query->where('app_scope', $scopeValue);
            });
        };

        $user = $baseQuery($scope)->first();

        // Existing delivery couriers pre-date GO Partners. Keep them able to
        // sign in during the transition, while new professional partners use
        // the isolated go_partner scope.
        if (!$user && $scope === 'go_partner' && $Details['account_type'] === 'delegate') {
            $user = $baseQuery('fasakhansta')->first();
        }
        if(!$user){
            if (($Details['account_type'] ?? null) === 'delegate') {
                $applicationMobile = preg_replace('/\\D+/', '', (string) $Details['mobile']);
                if (substr($applicationMobile, 0, 2) === '20' && strlen($applicationMobile) > 10) {
                    $applicationMobile = substr($applicationMobile, 2);
                }
                $applicationMobile = ltrim($applicationMobile, '0');

                $application = PendingVendor::where('application_kind', 'partner')
                    ->where('mobile', $applicationMobile)
                    ->latest('id')
                    ->first();

                if ($application) {
                    if ($application->status === 'declined') {
                        return 4;
                    }

                    // Pending, or accepted but the admin has not created the
                    // partner login yet: account creation remains admin-only.
                    return 3;
                }
            }

            return 1;
        }
         if (!Hash::check($Details['password'], $user->password)) {
          return 2;
        }
    
        if($user->account_type!='delegate'&&$user->account_type!='vendor'){
            return 5;
        }
       
        if ($user->status == 'pending') {
          // return $this->phoneActivationReturn($user);
            // $bearer = '3f33b7b5c6a2f0f46b20fd3de61cd85a';
            // $taqnyt = new TaqnyatSms($bearer);
            // $body = 'من فضلك ادخل رمز التحقق المرسل لك من موقع dezin  ' . $user->mobile_code. '  ( مؤسسة الذكاء المعماري )';
            // $recipients = [$user->mobile];
            // $sender = 'SmartArchit';
            // $smsId = '45568';
    
            // $message =$taqnyt->sendMsg($body, $recipients, $sender, $smsId);
            return 3;
        }elseif ($user->status == 'declined') {
                     return 4;

        }elseif ($user->status == 'disabled') {
                     return $user;

        }elseif ($user->status == 'accepted') {
            if(request('fcm_id')){
                $user->update(['fcm_id' => (string)request('fcm_id')]);
                $user->newOrExistingToken(request('fcm_id'));
                
            }

                    return $user;

        }
    }

    public function profile($userId) 
    {
        $user = User::findOrFail($userId);
        if(!$user){
            return 1;            
        }
        return $user;
    }

    public function delete($userId) 
    {
        $user= User::where('id',$userId)->first();
        if(!$user){
            return 1;    
        }
        if(Hash::check(request()->password ,$user->password))
        {
            // if(count($user->products) > 0){
            //     foreach ($user->products as $key => $value) {
            //         $value->delete();
            //     }
            // }
            // if(count($user->orders) > 0){
            //     foreach ($user->orders as $key => $value) {
            //         $value->delete();
            //     }
            // }
            // if(count($user->carts) > 0){
            //     foreach ($user->carts as $key => $value) {
            //         $value->delete();
            //     }
            // }
            $user->delete();
            return 3;
        }else{
            return 2;
        }        
    }
    public function create(array $Details) 
    {
    
          unset($Details["national_id_image"],$Details["commercial_registration_no_image"],$Details["driving_license_image"],$Details["tax_no_image"]);
        if(User::where('account_type',$Details['type'])->where('mobile',$Details['mobile'])->first()){
            $user = 3;
        }else{
        $user = PendingVendor::create($Details);
        if(request()->hasFile('national_id_image') && request()->file('national_id_image')->isValid())
        {
            $user->clearMediaCollection('national_id_image');
            $user->addMediaFromRequest('national_id_image')->toMediaCollection('national_id_image','pending_vendor');
        }
        if(request()->hasFile('commercial_registration_no_image') && request()->file('commercial_registration_no_image')->isValid())
        {
            $user->clearMediaCollection('commercial_registration_no_image');
            $user->addMediaFromRequest('commercial_registration_no_image')->toMediaCollection('commercial_registration_no_image','pending_vendor');
        }
        
         if(request()->hasFile('driving_license_image') && request()->file('driving_license_image')->isValid())
        {
            $user->clearMediaCollection('driving_license_image');
            $user->addMediaFromRequest('driving_license_image')->toMediaCollection('driving_license_image','pending_vendor');
        }
        if(request()->hasFile('tax_no_image') && request()->file('tax_no_image')->isValid())
        {
            $user->clearMediaCollection('tax_no_image');
            $user->addMediaFromRequest('tax_no_image')->toMediaCollection('tax_no_image','pending_vendor');
        }
          
          
          //send notification for admins
        $admins = User::where('account_type','admin')->get();
             foreach ($admins as $key => $value) {   
                 if($value->hasPermissionTo('pending_vendor-list')){
                     Notification::send($value,new \App\Notifications\NotifyAdminNewDelegateAndVendorNotification($user));
                 }
             }
        }
        return $user;        
    }

    public function update($userId, array $newDetails) 
    {
        unset($newDetails['photo_profile']);
        unset($newDetails['resturant_logo']);
        unset($newDetails['resturant_name']);
        unset($newDetails['resturant_area_id']);
        
        unset($newDetails['open_at']);
        unset($newDetails['close_at']);
        unset($newDetails['min_order_price']);
        $get_user = User::whereId($userId)->first();
        if(request()->hasFile('photo_profile') && request()->file('photo_profile')->isValid())
        {
            $get_user->clearMediaCollection('photo_profile');
            $get_user->addMediaFromRequest('photo_profile')->toMediaCollection('photo_profile','users');
        }
        $resturant = Resturant::where('user_id', auth('api')->user()->id)->first();
        if($resturant){
            if(request()->hasFile('resturant_logo') && request()->file('resturant_logo')->isValid())
            {
                $resturant->clearMediaCollection('logo');
                $resturant->addMediaFromRequest('resturant_logo')->toMediaCollection('logo','resturants');
            }
          $resturant->update(['name'=>request()->resturant_name??$resturant->name,'open_at'=>request('open_at'),'close_at'=>request('close_at'),'min_order_price'=>request('min_order_price')]);
          $main_branch=$resturant->resturant_areas()->where('type','kilo')->first();
          if($main_branch){
              $main_branch->update(['area_id'=>request()->resturant_area_id??$main_branch->area_id]);
          }else{
               ResturantArea::Create([
                    'added_by' => auth('api')->user()->id,
                    'resturant_id' => $resturant->id,
                    'area_id' => request('resturant_area_id'),
                    // 'expected_delivery' => request('expected_delivery')[$i],
                    'type' => 'kilo',
                ]);
          }
        }
        return User::whereId($userId)->update($newDetails);
    }

    public function deleteAlls($ids) 
    {
        return User::whereIn('id',explode(",",$ids))->delete();
    }

}