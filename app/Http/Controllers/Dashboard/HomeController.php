<?php

namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Auth;
use App\Models\User;
use App\Charts\UserChart;
use Spatie\Permission\Models\Role;
use App\Models\Banner;
use App\Models\GeneralSettings;
use App\Models\Resturant;
use App\Models\Category;
use App\Models\CategoryType;
use App\Models\CouponWheel;
use App\Models\Product;
use App\Models\Contact;
use App\Models\QuestionAnswer;
use App\Models\Area;
use App\Models\Order;
use App\Models\PendingVendor;
use App\Models\GeneralNotify;
use App\Models\CouponSubscripe;
use App\Models\Wallet;
use DB;
use App;
use Notification;
use CyrildeWit\EloquentViewable\Support\Period;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class HomeController extends Controller
{
    public function resturantControl(Request $request){
        $Resturants  =Resturant::where('id','!=',82)->get();
        foreach ($Resturants as $restaurant) {
            if($restaurant->control == 'show')
            {
                $restaurant->update(['control' => 'hide']);
            }elseif($restaurant->control == 'hide')
            {
                $restaurant->update(['control' => 'show']);
            }
        }
        return redirect()->back()->with('success',trans('main.update success'));
    }
    
    
    public function adminWallet(){
        $wallets = Wallet::whereNull('from_user')->orWhereNull('to_user')->orderBy('id','DESC')->paginate(30);

        return view('admin.admin_wallet', compact('wallets'));
    }
    public function chooseType()
    {
        return view('admin.choose_type');
    }

    public function chooseTypeChange()
    {
        if(request('menu') == 'application')
        {
            session()->put('menu','application');
        }
        else if(request('menu') == 'resturant')
        {
            session()->put('menu','resturant');
        }
        return redirect('admin/dashboard');
    }
    public function index(Request $request, \App\Services\Dashboard\HomeOverview $overview)
    {
        $boot=['initial'=>$overview->data($request->query(),auth('admin')->user()),
            'url'=>route('dashboard.overview'),'locale'=>app()->getLocale(),'labels'=>__('home_overview')];
        return response()->view('admin.home',compact('boot'))->header('Cache-Control','private, no-store');
    }

    public function overview(Request $request, \App\Services\Dashboard\HomeOverview $overview)
    {
        return response()->json($overview->data($request->query(),auth('admin')->user()))->header('Cache-Control','private, no-store');
    }

    public function loginPage(GeneralSettings $settings){
        return view('admin.login', compact('settings'));
    }

    public function signin(Request $request){
        // dd(($request->account_type));
        $request->validate([
            'email' => 'required',
            'password' => 'required',
        ]);
   
   
        $email         = $request->email;
         // Remove the first 0 from the mobile number if it exists
    if (substr($email, 0, 1) === '0') {
        $email = substr($email, 1);
    }
    
        $password     = $request->password;
    // Find user by email
    // $user = User::where('email', $email)->whereIn('account_type',['admin', 'vendor','resturant_owner'])->first();
    // $attempt='email';
    // if(!$user){
    //   $user = User::where('mobile', $email)->whereIn('account_type',['admin', 'vendor','resturant_owner'])->first();
    // $attempt='mobile';  
    // }
     $user=User::where(function ($query) use ($email) {
        $query->where('mobile', $email)
              ->orWhere('email', $email);
    })
    ->whereIn('account_type', ['admin', 'vendor','resturant_owner'])
    ->first();
    // dd($user);
    // Check if user exists and account type is valid
    if ($user && in_array($user->account_type, ['admin', 'vendor','resturant_owner'])) {
        // dd($user->mobile,$password,Hash::check($password, $user->password));
        if (Auth::guard('admin')->attempt(['mobile' => $user->mobile ,'password'=> $password, 'account_type' => $user->account_type])) {
            session()->forget('id_user');

            // dd(auth('admin')->user()->id);
            session()->put('id_user', auth('admin')->user()->id);
           if($user->account_type=='admin'){
            return redirect('admin/dashboard');
           }elseif($user->account_type=='vendor'){
                           session()->put('id_user', auth('admin')->user()->id);

               return redirect('admin/applies-orders');
           }
           elseif($user->account_type=='resturant_owner'){
                           session()->put('id_user', auth('admin')->user()->id);

               return redirect('admin/resturants');
           }
        }
    }
        
        return redirect()->back()->with('error',trans('main.invalid data'));
    }

    public function adminLogout()
    {
        Auth::guard('admin')->logout();
             // Invalidate the session for this guard
        request()->session()->invalidate();

        // Regenerate the CSRF token for security
        request()->session()->regenerateToken();
        return redirect("admin/login");
    }


    function changeLang($langcode){
    
    App::setLocale($langcode);
      session()->put("lang_code",$langcode);      
      // dd(App::getLocale());
      return redirect()->back();
  }  
    public function notifications(){
        $data = Auth::guard('admin')->user()->notifications()->select('type','id','data','created_at','read_at')->orderBy('created_at','DESC')->get();

        return view('admin.notifications', compact('data'));
    }


    public function bulk_notifications(){
        return view('admin.bulk_notifications');
    }
    
    public function sendNotify(){
        $data = request()->except('_token','user_id','for','valet_id');
        // dd($data);
        if(! empty(request('for'))){
            if(request('for') == 'user')
            {
                $users = User::whereIn('id',request('user_id'))->get();
                // dd($users);
                foreach ($users as $key => $value) {   
                    // dd($value->id);
                    $notify = GeneralNotify::create($data + ['user_id' => $value->id]);
                    Notification::send($value,new \App\Notifications\AdminToUserNotification($notify));
                }
            }elseif(request('for') == 'valet'){
                $users = User::whereIn('id',request('valet_id'))->get();
                // dd($users);
                foreach ($users as $key => $value) {   
                    // dd($value->id);
                    $notify = GeneralNotify::create($data + ['user_id' => $value->id]);
                    Notification::send($value,new \App\Notifications\AdminToUserNotification($notify));
                }
            }
        }
        return redirect()->back()->with('success',trans('main.notification sent done'));
    }

    public function read($id){
        $data =auth('admin')->user()->notifications()->where('id',$id)->firstOrFail();
        $data->update([
            'read_at' => now(),
        ]);
        
    //   if(isset($data->data['data']['order_id'])){
    //       return redirect()->route('orders.show',$data->data['data']['order_id']);
    //   }
        return redirect()->back();
    }
    
    public function mark_all_as_read(){
       auth('admin')->user()->unreadNotifications()->update(['read_at' => now(), 'updated_at' => now()]);
        return redirect()->back();
    }

    public function generatQr(Ticket $ticket){
        return view('generateQr', compact('ticket'));
    }

    public function couponWheel(CouponWheel $coupon_wheel){
        return view('admin.coupon_wheels', compact('coupon_wheel'));
    }

    public function couponWheelUpdate(CouponWheel $coupon_wheel, Request $request){
        $data = $request->except('_token');
        $coupon_wheel->update($data);
        return redirect()->back()->with('success',trans('main.update success'));
    }

}
