<?php

namespace App\Repositories;

use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use App\Models\Area;
use App\Models\PendingVendor;
use App\Support\PartnerWorkArea;
use App\Http\Traits\UploadImageTrait;
use Arr;
use DB;
use Mail;
class UserRepository implements UserRepositoryInterface 
{
    use UploadImageTrait;

    public function getAllUsers($request) 
    {
        
        $searchQuery = trim($request->query('search'));
        $users = User::query();
        if(!empty($searchQuery)){
            $users= $users->where('account_type',request('account_type'))->where('name', 'like', '%' . $searchQuery . '%')->orWhere('email', 'like', '%' . $searchQuery . '%')->orWhere('mobile', 'like', '%' . $searchQuery . '%')  ;
        }
        if($request->has('from_date') && request('from_date')!=null){
            
            $users= $users->where('created_at', '>=', request('from_date'));
        }
        if($request->has('to_date') && request('to_date')!=null){
            $users= $users->where('created_at', '<=', request('to_date'));
        }
        
        $users= $users->where('account_type',request('account_type'))->orderBy('id', 'desc')->paginate(30);
        
        return $users;
    }

    public function getUserById($userId) 
    {
        return User::findOrFail($userId);
    }

    public function deleteUser($userId) 
    {
        $get_user = User::whereId($userId)->delete();
        
    }
    public function createUser(array $userDetails) 
    {  
        $workArea = PartnerWorkArea::take($userDetails);
        if(User::where('account_type',$userDetails['account_type'])->where('mobile',$userDetails['mobile'])->first()){
            return 2;
        }
        unset($userDetails['photo_profile']);
         unset($userDetails["national_id"],$userDetails["commercial_registration_no"],$userDetails["driving_license_no"],$userDetails["tax_no"]);
        unset($userDetails['owner_name'],$userDetails['branches_no'],$userDetails['vodafone_cash_mobile'],$userDetails['location'],$userDetails['national_id_image'],$userDetails['driving_license_image']);

        // $userDetails['password'] = bcrypt($userDetails['password']);
        $userDetails['roles_name'] = json_encode($userDetails['roles_name']);

        $user = User::create($userDetails);
        $user->status = 'accepted';
        if(request('pending_vendor_id')){
            $user->pending_vendor_id = request('pending_vendor_id');
            $pending_vendor= PendingVendor::where('id',request('pending_vendor_id'))->first();
            if($pending_vendor){
                $pending_vendor->update(['status' => 'accepted']);
            }
        }
        $user->save();
        if(request()->hasFile('photo_profile') && request()->file('photo_profile')->isValid())
        {
            // $this->convertImageToWebp(request()->photo_profile,$user,'photo_profile','users');//
            $user->addMediaFromRequest('photo_profile')->toMediaCollection('photo_profile','users');
        }
        if($userDetails['account_type'] == 'admin'||$userDetails['account_type'] == 'vendor' ||$userDetails['account_type'] == 'delegate' ||$userDetails['account_type'] == 'resturant_owner'){
            $user->assignRole(json_decode($userDetails['roles_name']));
        }
        $request = request();
        $mobile[]=$user->mobile;
        $password[]=$request->password;

        //send emails by number of branches
        $emailFailed = false;
        $to_email = $user->email;
        if($to_email){
            try{
            $mail=Mail::send('emails.send_pending_vendor_acceptance_email', ['user' => $user->name, 'email' => $to_email, 'mobile' => $mobile, 'password' => $password,'account_type'=>$user->account_type], function($message) use ($request, $to_email) {
                $message->to($to_email);
                $message->subject('Send Notification');
            });
            } catch (\Exception $e) {
                $emailFailed = true; // Finish saving profile/coverage even when mail is unavailable.
            }
        }
        
        
          // ===========pending_vendor=============
        $pending_vendor=$user->pending_vendor;
        if(!$pending_vendor){
            $pending_vendor=new PendingVendor;
        }
        $pending_vendor->added_by=auth('admin')->user()->id;
        $pending_vendor->full_name=request()->name;
        $pending_vendor->type=request()->account_type;
        $pending_vendor->status='accepted';
        $pending_vendor->mobile=request()->mobile;
        $pending_vendor->email=request()->email;
        $pending_vendor->national_id=request()->national_id;
        $pending_vendor->commercial_registration_no=request()->commercial_registration_no;
        $pending_vendor->driving_license_no=request()->driving_license_no;
        $pending_vendor->tax_no=request()->tax_no;
        $pending_vendor->owner_name=request()->owner_name;
        $pending_vendor->branches_no=request()->branches_no;
        $pending_vendor->location=request()->location;
        $pending_vendor-> vodafone_cash_mobile=request()->vodafone_cash_mobile;
        PartnerWorkArea::apply($pending_vendor, $workArea);
        $pending_vendor->save();
                   $user->update(['pending_vendor_id'=>$pending_vendor->id]);


         
        if(request()->hasFile('national_id_image') && request()->file('national_id_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('national_id_image');
            $pending_vendor->addMediaFromRequest('national_id_image')->toMediaCollection('national_id_image','pending_vendor');
        }
        if(request()->hasFile('commercial_registration_no_image') && request()->file('commercial_registration_no_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('commercial_registration_no_image');
            $pending_vendor->addMediaFromRequest('commercial_registration_no_image')->toMediaCollection('commercial_registration_no_image','pending_vendor');
        }
        
         if(request()->hasFile('driving_license_image') && request()->file('driving_license_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('driving_license_image');
            $pending_vendor->addMediaFromRequest('driving_license_image')->toMediaCollection('driving_license_image','pending_vendor');
        }
        if(request()->hasFile('tax_no_image') && request()->file('tax_no_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('tax_no_image');
            $pending_vendor->addMediaFromRequest('tax_no_image')->toMediaCollection('tax_no_image','pending_vendor');
        }
        return $emailFailed ? false : $user;
    }

    public function updateUser($userId, array $newDetails) 
    {
        $workArea = PartnerWorkArea::take($newDetails);

        unset($newDetails['photo_profile']);
        unset($newDetails["national_id_image"],$newDetails["commercial_registration_no_image"],$newDetails["driving_license_image"],$newDetails["tax_no_image"]);
        unset($newDetails["national_id"],$newDetails["commercial_registration_no"],$newDetails["driving_license_no"],$newDetails["tax_no"]);
        unset($newDetails['owner_name'],$newDetails['branches_no'],$newDetails['vodafone_cash_mobile'],$newDetails['location']);

        $get_user = User::whereId($userId)->first();
        if(request()->hasFile('photo_profile') && request()->file('photo_profile')->isValid())
        {
            $get_user->clearMediaCollection('photo_profile');
            // $this->convertImageToWebp(request('photo_profile'),$get_user,'photo_profile','users');
            $get_user->addMediaFromRequest('photo_profile')->toMediaCollection('photo_profile','users');
        }
        // if(!empty($newDetails['password'])){
        //     $newDetails['password'] = bcrypt($newDetails['password']);
        // }else{
        //     $newDetails = Arr::except($newDetails,array('password'));
        // }
        if($get_user->id != 1 && $newDetails['account_type'] == 'admin'||$newDetails['account_type'] == 'vendor' ||$newDetails['account_type'] == 'delegate'||$newDetails['account_type'] == 'resturant_owner'){
            DB::table('model_has_roles')->where('model_id',$get_user->id)->delete();
            $get_user->assignRole($newDetails['roles_name']);
        }
        
        
        // ===========pending_vendor=============
        $pending_vendor=$get_user->pending_vendor;
        if(!$pending_vendor){
            $pending_vendor=new PendingVendor;
        }
        $pending_vendor->added_by=auth('admin')->user()->id;
        $pending_vendor->full_name=request()->name;
        $pending_vendor->type=request()->account_type;
        $pending_vendor->status='accepted';
        $pending_vendor->mobile=request()->mobile;
        $pending_vendor->email=request()->email;
        if (request()->has('national_id')) { $pending_vendor->national_id=request()->national_id; }
        if (request()->has('commercial_registration_no')) { $pending_vendor->commercial_registration_no=request()->commercial_registration_no; }
        if (request()->has('driving_license_no')) { $pending_vendor->driving_license_no=request()->driving_license_no; }
        if (request()->has('tax_no')) { $pending_vendor->tax_no=request()->tax_no; }
        if (request()->has('owner_name')) { $pending_vendor->owner_name=request()->owner_name; }
        if (request()->has('branches_no')) { $pending_vendor->branches_no=request()->branches_no; }
        if (request()->has('location')) { $pending_vendor->location=request()->location; }
        if (request()->has('vodafone_cash_mobile')) { $pending_vendor->vodafone_cash_mobile=request()->vodafone_cash_mobile; }
        PartnerWorkArea::apply($pending_vendor, $workArea);
        $pending_vendor->save();

        // $get_user->update(['pending_vendor_id'=>$pending_vendor->id]);

                // dd($get_user->pending_vendor_id,$pending_vendor->id);

         
        if(request()->hasFile('national_id_image') && request()->file('national_id_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('national_id_image');
            $pending_vendor->addMediaFromRequest('national_id_image')->toMediaCollection('national_id_image','pending_vendor');
        }
        if(request()->hasFile('commercial_registration_no_image') && request()->file('commercial_registration_no_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('commercial_registration_no_image');
            $pending_vendor->addMediaFromRequest('commercial_registration_no_image')->toMediaCollection('commercial_registration_no_image','pending_vendor');
        }
        
         if(request()->hasFile('driving_license_image') && request()->file('driving_license_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('driving_license_image');
            $pending_vendor->addMediaFromRequest('driving_license_image')->toMediaCollection('driving_license_image','pending_vendor');
        }
        if(request()->hasFile('tax_no_image') && request()->file('tax_no_image')->isValid())
        {
            $pending_vendor->clearMediaCollection('tax_no_image');
            $pending_vendor->addMediaFromRequest('tax_no_image')->toMediaCollection('tax_no_image','pending_vendor');
        }
        $newDetails['pending_vendor_id']=$pending_vendor->id;
        $get_user->update($newDetails);
        // dd($get_user->pending_vendor_id);
        return $get_user;
    }

    public function deleteAllUsers($ids) 
    {
        return User::whereIn('id',explode(",",$ids))->delete();
    }

}