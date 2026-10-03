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
    public function index(GeneralSettings $settings, Request $request)
    {
        if(auth('admin')->id() === 635 || auth()->user()->roles->pluck("id")->first() == 11){
        $month =request('day')?? date('m');
        $users = DB::table('users')
                    ->whereMonth('created_at',$month)
                    ->selectRaw('day(created_at) as day')
                    ->selectRaw('count(*) as count')
                    ->groupBy('day')
                    ->orderBy('day')
                    ->pluck('count', 'day')->toArray();
                    // dd($users);
        $user_day_count=[];
        for ($i=0; $i < 31; $i++) { 
            if(array_key_exists($i+1, $users)) {
                array_push( $user_day_count, $users[$i+1]);
            }else{
                array_push( $user_day_count, 0);
            }
        }
        $users_monthlyChart = new UserChart;
        $users_monthlyChart->labels(['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31']);
        $users_monthlyChart->dataset(trans('main.users_monthlyChart'), 'line', $user_day_count)->options([
            'fill' => 'true',
            'borderColor' => '#364576'
        ]);

        $year =request('year')?? date('Y');
        $users2 = DB::table('users')
                    ->whereYear('created_at',$year)
                    ->selectRaw('month(created_at) as month')
                    ->selectRaw('count(*) as count')
                    ->groupBy('month')
                    ->orderBy('month')
                    ->pluck('count', 'month')->toArray();
                    // dd($users2);
        $user_month_count=[];
        for ($i=0; $i < 12; $i++) { 
            if(array_key_exists($i+1, $users2)) {
                array_push( $user_month_count, $users2[$i+1]);
            }else{
                array_push( $user_month_count, 0);
            }
        }
        $users_yearlyChart = new UserChart;
        $users_yearlyChart->labels(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
        $users_yearlyChart->dataset(trans('main.users_yearlyChart'), 'line', $user_month_count)->options([
            'fill' => 'true',
            'borderColor' => '#364576'
        ]);
        $adminsCount = User::where('account_type','admin')->count();
        $vendorsCount = User::where('account_type','vendor')->count();
        $usersDelegateTypeCatCount=User::where('account_type','delegate')->count();
        $usersUserTypeCatCount= User::where('account_type','user')->count();
        $ticketsCount= User::count();
        $subcategorysCount= Category::whereNotNull('parent_id')->count();
        $categorysCount= Category::whereNull('parent_id')->count();
        $ordersCount = Order::where('type','!=','wallet')->whereNotNull('status')->count();
        $pending_vendorsCount = PendingVendor::count();
        $rolesCount = Role::count();
        $faqsCount = QuestionAnswer::count();
        $contactsCount= Contact::count();
        $productsCount = Product::count();
        $areasCount = Area::count();
        $resturantsCount= Resturant::count();
        $bannersCount= Banner::count();

        $delegates_most_ordered = Order::where('status','completed')->groupBy('delegate_id')->take(10)->get();
        $latest_orders = Order::where('status','!=','pending')->orderBy('id','DESC')->groupBy('id')->take(10)->get();
        $resturants_most_ordered = Order::where('status','completed')->groupBy('resturant_id')->take(10)->get();
         $CouponWheel=CouponWheel::first();
            $now=Carbon::now();
            if($CouponWheel){
            $startDate = Carbon::parse($CouponWheel->start_date);
            $endDate = Carbon::parse($CouponWheel->end_date);
            }
            $winner=CouponSubscripe::orderBy('created_at','desc')->where('status','winner')->first();
            $winnerShow=0;
           if($CouponWheel && $CouponWheel->status=='show'&& $now->greaterThanOrEqualTo($startDate) && $now->greaterThanOrEqualTo($endDate) && $winner){
                 $winnerShow=1;
            }
        return view('admin.home' , compact('winnerShow','winner','resturants_most_ordered','latest_orders','delegates_most_ordered','bannersCount','faqsCount','adminsCount','vendorsCount','usersDelegateTypeCatCount','usersUserTypeCatCount','ticketsCount','subcategorysCount','categorysCount','ordersCount','pending_vendorsCount','contactsCount','productsCount','areasCount','resturantsCount','rolesCount','users_monthlyChart','users_yearlyChart'));
        }else{
            $all_orders = Order::where('type','!=','wallet')->whereNotNull('status')->get();
            
            $resturant_id = auth('admin')->user()->base_resturant?->id;
        $orders = Order::query()->where('resturant_id',$resturant_id)->where('status','completed');
        $orders=$orders->get();
        $delivery_price=0;
    
        // calculate delegate not payed orders
        $not_payed=$orders->whereNull('transfer_price_by')->whereNull('delegate_id')->where('payment_type','cash');
        $not_payed_total=0;$total=0;
        if($not_payed){
        foreach($not_payed as $not){
            $total=$total+$not->total;
            $not_payed_total=$not_payed_total+$not->app_percentage;
             $delivery_price=$delivery_price+$not->delivery_price;
        }
        }
        // calculate delegate  payed orders
        $payed=$orders->where('transfer_price_by','vendor')->where('payment_type','cash');
        $payed_total=0;
        // return $payed->count();
        if($payed){
        foreach($payed as $pay){
            $total=$total+$pay->total;
            $payed_total=$payed_total+$pay->app_percentage;
            $delivery_price=$delivery_price+$pay->delivery_price;
        }
        }
        
        $vendor_orders=auth('admin')->user()->base_resturant?->orders;
        
        // المكاسب من الطلبات الكاش المدفوع رسومها +لم يتم دفع رسومها
        $gain_from_cash_delivery=$vendor_orders?->where('payment_type','cash')->where('status','completed');
        $gain_cash=0;
        if($gain_from_cash_delivery){
            foreach($gain_from_cash_delivery as $cash){
                if($cash->delegate_id==null){
                    $gain_cash=$gain_cash+$cash->delivery_price+$cash->vendor_percentage;
                }else{
                    $gain_cash=$gain_cash+$cash->vendor_percentage;
                }
            }
        }
                // المكاسب من الطلبات الاونلاين المدفوع رسومها   
         $gain_from_online_delivery=$vendor_orders?->where('status','completed')->where('payment_type','!=','cash')->whereNotNull('transfer_price_by');
         $gain_online=0;
         if($gain_from_online_delivery){
         foreach($gain_from_online_delivery as $online){
            if($online->delegate_id==null){
                $gain_online=$gain_online+$online->delivery_price+$online->vendor_percentage;
            }else{
                $gain_online=$gain_online+$online->vendor_percentage;
            }
        }
         }
        // رسوم لم يتم تحويلها
        $not_transfer_cash_orders=$not_payed_total;
        // رسوم تم تحويلها
        $transfer_cash_orders=$payed_total;
        // المبالغ المستلمه تم دفع رسومها +لم يتم دفع رسومها
        $total_cash_order=$total+$delivery_price;
        //المكسب من الابلكيشن
        $total_gain_from_app=$gain_online+$gain_cash;
        $today_orders =Order::where('type','!=','wallet')->where('status','completed')->whereDay('created_at', now()->day)->get();

    
            return view('admin.home' , compact('today_orders','all_orders','not_transfer_cash_orders','transfer_cash_orders','total_cash_order','total_gain_from_app','gain_cash','gain_online'));
        }
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
            return redirect('admin/dashboard')
                    ->with('success',trans('main.signed in'));
           }elseif($user->account_type=='vendor'){
                           session()->put('id_user', auth('admin')->user()->id);

               return redirect('admin/applies-orders')
                    ->with('success',trans('main.signed in'));
           }
           elseif($user->account_type=='resturant_owner'){
                           session()->put('id_user', auth('admin')->user()->id);

               return redirect('admin/resturants')
                    ->with('success',trans('main.signed in'));
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
        return redirect("admin/login")->with('error',trans('main.logout success'));
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
