<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Resturant;
use App\Models\UserAddress;
use App\Models\Area;
use App\Models\Order;
use App\Http\Resources\Api\Vendor\ResturantResource;
use Illuminate\Http\Request;
use App\Http\Traits\UploadImageTrait;
use App\Http\Resources\Api\Auth\UserResource;
use App\Http\Requests\Api\Auth\StoreUserRequest;
use App\Http\Requests\Api\Auth\StoreVendorRequest;
use App\Http\Requests\Api\Auth\UserLoginRequest;
use App\Http\Requests\Api\Auth\UpdateUserRequest;
use App\Http\Requests\Api\Auth\UpdateUserPhotoRequest;
use App\Http\Traits\ApiResponses;
use Notification;
use JWTAuth;
use Hash;
use Validator;
use Auth;
use TaqnyatSms;
use App\Interfaces\Api\UserAuthRepositoryInterface;
use Mail;
class UserController extends Controller {

  use ApiResponses;
  use UploadImageTrait;

    private UserAuthRepositoryInterface $userRepository;
    public function __construct(UserAuthRepositoryInterface $userRepository) 
    {      
        $this->userRepository = $userRepository;
    }

    private function appScope(): string
    {
        return request()->header('X-App-Scope') === 'go' ? 'go' : 'fasakhansta';
    }
    
     public function updateUserLocation(Request $request, User $user) {
        $up_Resturant = UserAddress::create([
                'user_id' => $user->id,
                'country_name'  => $request->country_name,
                'city_name'     => $request->city_name,
                'address'       => $request->address,
                'lat'           => $request->lat,
                'lng'           => $request->lng,
            ]);
        $city_name = getCityName($up_Resturant->lat,$up_Resturant->lng);         

        $area = Area::where('title_ar', 'like', '%' . $city_name . '%')->orWhere('title_en', 'like', '%' . $city_name . '%')->first();
        if($area){
            $up_Resturant->area_id = $area->id;
            $up_Resturant->save();
        }    
        $userData = UserResource::make($user->fresh());
        return $this->successResponse($userData,__('api.update status'));
    }
    
  public function register(StoreUserRequest $request) {

    $userDetails = $request->validated();    
    $user = $this->userRepository->createUser($userDetails);
    $userData = UserResource::make($user)->getToken(JWTAuth::fromUser($user));
    return $this->successResponse($userData,__('api.created new user'));
  }

public function resend_code(Request $request){
        $user_code=$request['mobile_code'];
        $user_country_code=$request['country_code'];
        $user_mobile=$request['mobile'];
        $user=User::where('country_code',$user_country_code)->where('mobile_code', $user_code)->where('status','pending')->where('account_type','user')->where('app_scope', $this->appScope())->where('mobile', $user_mobile)->first();
        if($user){
            $user->update([
                'mobile_verified_at' => now(),
                'mobile_code' => null,
                'status' => 'accepted',
                ]);
          return $this->successResponse(null,trans('api.code successed'));

        }
        return $this->errorResponse(trans('api.retry code'));
    }
   public function check_code_activate(Request $request){
        $user_code=$request['mobile_code'];
        $user_country_code=$request['country_code'];
        $user_mobile=$request['mobile'];
        // $user=User::where('country_code',$user_country_code)->where('mobile_code', $user_code)->where('status','pending')->where('mobile', $user_mobile)->first();
        $user=auth('api')->user();
        if($user->mobile_code==$request['mobile_code']){
            $user->update([
                'mobile_verified_at' => now(),
                'mobile_code' => null,
                'status' => 'accepted',
                ]);
          return $this->successResponse(null,trans('api.code successed'));

        }
        return $this->errorResponse(trans('api.retry code'));
    }

 public function login(UserLoginRequest $request){
    $userDetails = $request->validated();   
    // return $userDetails;
    $user = $this->userRepository->loginUser($userDetails);
  
    if(is_array($user) && is_object($user['user'])){
        $userData = UserResource::make($user['user'])->getToken(JWTAuth::fromUser($user['user']));
        
        return $this->successResponse(['user_data'=>$userData,'register'=>$user['register']] , __('api.signed'));
    }elseif($user == 1){
        return $this->errorResponse(__('api.failed'));
    }elseif($user == 2){
        return $this->errorResponse(__('api.failed_in_data'));
    }else if($user == 4){
        return $this->errorResponse(__('api.your account has declined'));
    }
  }
    
    public function userProfile(){
        $userDetails = auth()->user()->id;   
        $user = $this->userRepository->profileUser($userDetails);
        if(is_object($user)){
            $getData = UserResource::make($user)->getToken(JWTAuth::fromUser($user));
            return $this->successResponse($getData,trans('api.user profile'));
        }else if($user == 1){
            return $this->errorResponse(trans('api.error in user type'));
        }
    }

  public function userWishlist(){
        $resturants = Resturant::whereHas('wishlists',function($q){
            $q->where('user_id',auth('api')->user()->id);
        })->get();         
        $resturantData = ResturantResource::collection($resturants);
        return $this->successResponse($resturantData,__('api.get all user wishlists'));
    }
    
    public function userUpdateProfile(UpdateUserRequest $request){
        $userDetails = $request->validated();   
        $id = auth('api')->user()->id;
        $user = $this->userRepository->updateUser($id,$userDetails);
        $userData=User::find(auth('api')->user()->id);
        $getData = new UserResource($userData);
        return $this->successResponse($getData,trans('api.updated profile successfully'));
    }
    
    public function userUpdateProfilePhoto(UpdateUserPhotoRequest $request){
        $userDetails = $request->validated();   
        
        $id = auth('api')->user()->id;
        $user = $this->userRepository->updateUser($id,$userDetails);
        $userData=User::find(auth('api')->user()->id);
        $getData = new UserResource($userData);
        return $this->successResponse($getData,trans('api.updated profile successfully'));
    }
   
    
   public function userLogout() {
      $id= Auth::guard('api')->user()->id;
    // dd($id);
        $user=User::where('id', $id)->first();
        $user->tokens()->where('token',auth('api')->user()->fcm_id)->delete();
      $up_key=User::where('id', $id)->update(['fcm_id'=> null]);

      $data = Auth::guard('api')->logout();
      
      return $this->successResponse($data,trans('api.logout successfully'));
   }
  public function access_sms(Request $request)
    {
        $user_country_code=$request['country_code'];
        $user_mobile=$request['mobile'];
        $code= random_int(1000, 9999);
        $user=User::where('country_code',$user_country_code)->where('account_type','user')->where('app_scope', $this->appScope())->where('mobile', $user_mobile)->first();
        if($user)
        {
            // $user->update([
            //     'mobile_code' => $code,
            // ]);
        // $bearer = '3f33b7b5c6a2f0f46b20fd3de61cd85a';
        // $taqnyt = new TaqnyatSms($bearer);
        // $body = 'من فضلك ادخل رمز التحقق المرسل لك من موقع dezin  ' . $user->mobile_code . '  ( مؤسسة الذكاء المعماري )';
        // $recipients = [$user->mobile];
        // $sender = 'SmartArchit';
        // $smsId = '45568';

        // $message =$taqnyt->sendMsg($body, $recipients, $sender, $smsId);

            //  $mail=Mail::send('emails.forget_password', ['email' => $request->email,'code' => $code], function($message) use ($request) {
            //     $message->to($request->email);
            //     $message->subject('Forget Password Verfiy Code');
            // });
          //  dd($mail);
           return $this->errorResponse('هذا الحساب موجود من قبل');

        }else{
            return $this->successResponse($code,'success');
        }
        // return $this->errorResponse('لا يوجد صاحب لهذا الرقم ');

    }
   public function forget_pass(Request $request)
    {
        $user_country_code=$request['country_code'];
        $user_email=$request['email'];
        $user_mobile=$request['mobile'];
        $user=User::where('mobile',$user_mobile)->where('email', $user_email)->where('account_type','user')->where('app_scope', $this->appScope())->first();
        if($user)
        {
            $code= random_int(1000, 9999);
            $user->update([
                'email_code' => $code,
            ]);
        // $bearer = '3f33b7b5c6a2f0f46b20fd3de61cd85a';
        // $taqnyt = new TaqnyatSms($bearer);
        // $body = 'من فضلك ادخل رمز التحقق المرسل لك من موقع dezin  ' . $user->mobile_code . '  ( مؤسسة الذكاء المعماري )';
        // $recipients = [$user->mobile];
        // $sender = 'SmartArchit';
        // $smsId = '45568';

        // $message =$taqnyt->sendMsg($body, $recipients, $sender, $smsId);

             $mail=Mail::send('emails.forget_password', ['email' => $request->email,'code' => $code,'name'=>$user->name], function($message) use ($request) {
                $message->to($request->email);
                $message->subject('Forget Password Verfiy Code');
            });
          //  dd($mail);
            return $this->successResponse($user->email_code,'راجع الكود المرسل على بريدك الإلكتروني');

        }
        return $this->errorResponse('لا يوجد صاحب لهذا البريد الإلكتروني ');

    }
    
     public function checkOtpFirstOrder(Request $request){
        $otp_first_no=$request['otp_first_no'];
        $user=User::where('id',auth('api')->user()->id)->where('otp_first_no', $otp_first_no)->first();
        if($user){
            $user->update([
                'otp_first_order' => 1,
            ]);
            
         return $this->successResponse($user->otp_first_order,trans('api.code successed'));

        }
        return $this->errorResponse(trans('api.retry code'));
    }
    
    public function check_mobile_has_account(Request $request){
        $user_country_code=$request->country_code;
        $mobile=$request->mobile;
        $user=User::where('country_code',$user_country_code)->where('mobile', $mobile)->where('account_type','user')->where('app_scope', $this->appScope())->first();
        if($user){
            if($user->email){    
             return $this->successResponse($user->email,trans('api.done'));
            }else{
            return $this->successResponse(null,'هذا الحساب غير مسجل له بريد إلكتروني');
            }
        }
        return $this->errorResponse('لا يوجد صاحب لهذا الرقم ');
    }
    public function check_code_forget_pass(Request $request){
        $user_code=$request['code'];
        $user_country_code=$request['country_code'];
        $user_email=$request['email'];
        $user=User::where('email_code', $user_code)->where('email', $user_email)->where('account_type','user')->where('app_scope', $this->appScope())->first();
        if($user){
            $user->update([
                'forget_password' => 1,
                'email_code' => null,
            ]);
            
         return $this->successResponse($user->email,trans('api.code successed'));

        }
        return $this->errorResponse(trans('api.retry code'));
    }
    
    public function reset_password(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'new_password' => 'required|min:6|required_with:confirm_password|same:confirm_password',
        'confirm_password' => 'required|min:6',
    ]);
    
      if($validator->fails()){
        return $this->errorResponse($validator->errors()->first());
    }
            $user_mobile=$request['mobile'];
        $user_country_code=$request['country_code'];
        $user_email=$request['email'];
        $user=User::where('forget_password',1)->where('mobile', $user_mobile)->where('email', $user_email)->where('account_type','user')->where('app_scope', $this->appScope())->first();
        if($user)
        {
            $user->update(['password' => $request->get('new_password'),
            ]);
        return $this->successResponse($user->email,trans('api.reset password done'));

        }
        return $this->errorResponse(trans('api.error in password'));

    }

    public function changePassword(Request $request){
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|min:6',
            'password' => 'required|min:6|confirmed',
        ]);
    
        if($validator->fails()){
            return $this->errorResponse($validator->errors()->first());
        }
        $user=User::where('id', auth()->guard('api')->user()->id)->first();
        
        if($user)
        {
            if(Hash::check($request->current_password, $user->password) ){
                $user->update(['password' => $request->get('password')]);
                $data = UserResource::make($user)->getToken(JWTAuth::fromUser($user));
                return $this->successResponse($data,trans('api.password changed'));
                                Auth::guard('api')->logout();

            }else{
                return $this->errorResponse(trans('api.error in current password'));
            }
        }
        return $this->errorResponse(trans('api.error in password'));
    }
    public function get_notifications()
    {   
        $get_notifications = Auth::guard('api')->user()->notifications()->select('type','id','data','created_at')->orderBy('id','DESC')->get();
        if(! empty($get_notifications))
        {
            return $this->successResponse($get_notifications,trans('api.show all notifications'));
        }
        else
            return $this->successResponse(null);
    }
    
    public function deleteNotifications($id){
        $del = DB::table('notifications')->where('id', $id)->delete();
        return $this->successResponse($del,trans('api.delete notification'));
    }

    public function delete_account(Request $request){
         $validator = Validator::make($request->all(), [
            'password' => 'required',
        ]);
    
        if($validator->fails()){
            return $this->errorResponse($validator->errors()->first());
        }
        $userDetails = auth('api')->user()->id;   
        $user = $this->userRepository->deleteUser($userDetails);
        if($user == 3){
            return $this->successResponse(null,trans('api.deleted account'));
        }else if($user == 1){
            return $this->errorResponse('لا يوجد بيانات');  
        }else if($user == 2){
            return $this->errorResponse(trans('api.check your current password'));
        }
    }
    
    public function updateConnected(){
        if(auth('api')->user()->status != 'accepted'){
            return $this->errorResponse(__('api.contact admin for account activation'));
        }
        
        $userDetails = auth('api')->user();   
        $userDetails->connected = request('connected');
        $userDetails->save();
        return $this->successResponse(null,trans('api.updated connected'));
    }
    
}