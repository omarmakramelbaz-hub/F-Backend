<?php

namespace App\Repositories\Api;

use App\Interfaces\Api\UserAuthRepositoryInterface;
use App\Models\User;
use App\Models\Area;
use App\Models\Admin;
use App\Http\Traits\UploadImageTrait;
use App\Http\Traits\UserTrait;
use Arr;
use Notification;
use Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class UserAuthRepository implements UserAuthRepositoryInterface 
{
    use UploadImageTrait;use UserTrait;

    private function appScope(): string
    {
        return request()->header('X-App-Scope') === 'go' ? 'go' : 'fasakhansta';
    }

    public function loginUser(array $userDetails) 
    {
        $register=0;
        if(!$user = User::where('mobile', $userDetails['mobile'])->where('account_type','user')->when(Schema::hasColumn('users', 'app_scope'), function ($query) { $query->where('app_scope', $this->appScope()); })->first()){
              $createDetails = ['added_by' => 1,'fcm_id'=>$userDetails['fcm_id'],'mobile'=>$userDetails['mobile'],'mobile_code' => '1234','account_type'=>'user','status'=>'accepted','password'=> $userDetails['password']];
              if (Schema::hasColumn('users', 'app_scope')) { $createDetails['app_scope'] = $this->appScope(); }
              $user=User::create($createDetails);
              $register= 1;
            }

       if (!Hash::check($userDetails['password'], $user->password)) {
          return 2;
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
            $user->mobile_code=$this->send_otp();
            $user->save();
        }elseif($user->status == 'declined'){
            return 4;
        }
        if($userDetails['fcm_id']){
        $user->fcm_id=$userDetails['fcm_id'];
        $user->newOrExistingToken($userDetails['fcm_id']);
        }
        $user->save();
    return ['user'=>$user,'register'=>$register];
    }

    public function profileUser($userId) 
    {
        $user = User::findOrFail($userId);
        if(!$user){
            return 1;            
        }
        return $user;
    }

    public function deleteUser($userId) 
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
    public function createUser(array $userDetails) 
    {
        if (Schema::hasColumn('users', 'app_scope')) {
            $userDetails['app_scope'] = $this->appScope();
        }
        // $userDetails['status'] = 'accepted';
        // $userDetails['mobile_verified_at'] = now();
        // $userDetails['mobile_code'] = mt_rand(1111,9999);
        $user = User::create($userDetails);
        // $user->update(['mobile' => request('country_code'). $user->mobile]);
            // $bearer = '3f33b7b5c6a2f0f46b20fd3de61cd85a';
            // $taqnyt = new TaqnyatSms($bearer);
            // $body = 'من فضلك ادخل رمز التحقق المرسل لك من موقع dezin  ' . $user->mobile_code . '  ( مؤسسة الذكاء المعماري )';
            // $recipients = [$user->mobile];
            // $sender = 'SmartArchit';
            // $smsId = '45568';

            // $message =$taqnyt->sendMsg($body, $recipients, $sender, $smsId);

        // $user->sendVerificationCode();
          $admins = User::get();
             foreach ($admins as $key => $value) {   
                 if($value->hasPermissionTo('user-list')){
                     Notification::send($value,new \App\Notifications\NotifyUserCreatedNotification($user));
                 }
             }
        return $user;        
    }

    public function updateUser($userId, array $newDetails) 
    {
        unset($newDetails['photo_profile']);
        $get_user = User::whereId($userId)->first();
        if(request()->hasFile('photo_profile') && request()->file('photo_profile')->isValid())
        {
            $get_user->clearMediaCollection('photo_profile');
            $get_user->addMediaFromRequest('photo_profile')->toMediaCollection('photo_profile','users');
        }
        return User::whereId($userId)->update($newDetails);
    }

    public function deleteAllUsers($ids) 
    {
        return User::whereIn('id',explode(",",$ids))->delete();
    }

}