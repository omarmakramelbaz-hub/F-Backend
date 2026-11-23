<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Auth;
use App\Models\User;
use App\Models\Feature;
use App\Models\Contact;
use Illuminate\Http\Request;
use Response;
use Validator;
use DB;
use Notification;
class HomeController extends Controller {
    public function paySuccess(){
        $urlPrevious = url()->current();
        session()->put('url.intended', $urlPrevious);
 
		return view('site.paySuccess');
	}
	
	public function payFailed(){
        $urlPrevious = url()->current();
        session()->put('url.intended', $urlPrevious);
 
		return view('site.payFailed');
	}
    public function home(){
// dd(session()->get('details'));
        $urlPrevious = url()->current();
        session()->put('url.intended', $urlPrevious);
        $features = Feature::where('status','show')->get();
        return view('site.home', compact('features'));
    }

	public function terms(){
        $urlPrevious = url()->current();
        session()->put('url.intended', $urlPrevious);
 
		return view('site.terms');
	}
    public function features(){
        $urlPrevious = url()->current();
        session()->put('url.intended', $urlPrevious);
        $features = Feature::where('status','show')->get();
        return view('site.features', compact('features'));
    }

    public function screens(){
        $urlPrevious = url()->current();
        session()->put('url.intended', $urlPrevious);
        return view('site.screens');
    }
    public function aboutUs(){
        $urlPrevious = url()->current();
        session()->put('url.intended', $urlPrevious);
        return view('site.about-us');
    }

    public function contactus(){
        $urlPrevious = url()->current();
        session()->put('url.intended', $urlPrevious);
        return view('site.contact-us');
    }
    
    public function storeContact(Request $request){
        $rules = [
            'email' => 'required|email',
            'name' => 'required|string|min:3|max:200',
            'message' => 'required|string|min:3|max:1000',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(array(
                'errors' => $validator->errors()->all(),
            ));
        } else {
            $data = $request->except("_token", "_method");
            $user_store = Contact::create($data);
            if($user_store){
                $admins = User::where("account_type","!=","user")->get(); // staff only: customers never hold dashboard permissions

                foreach ($admins as $key => $value) {   
                    if($value->hasPermissionTo('contact-list')){
                        Notification::send($value,new \App\Notifications\NotifyContactUsNotification($user_store));
                    }
                }
                return 1;
            }
            return 2;
        }
    }

    public function storeSubscriber(Request $request){
        $rules = [
            'email' => 'required|email:rfc,dns|unique:subscribers,email',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(array(
                'errors' => $validator->errors()->all(),
            ));
        } else {
            $data = $request->except("_token", "_method");
            $user_store = Subscriber::create($data);
            if($user_store){
                $admins = User::where("account_type","!=","user")->get(); // staff only: customers never hold dashboard permissions

                foreach ($admins as $key => $value) {   
                    if($value->hasPermissionTo('contact-list')){
                        Notification::send($value,new \App\Notifications\NotifySubscriberNotification($user_store));
                    }
                }
                return 1;
            }
            return 2;
        }
    }
}