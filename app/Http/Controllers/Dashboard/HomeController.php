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
        app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class)->central(['wallet-list']);
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
        if(isset($boot['initial']['owner_drawer']))$boot['drawer_url']=route('dashboard.overview',['section'=>'drawers']);
        // The GO control room plays its start-up sequence once per sign-in (sign-out clears the session).
        $boot['intro']=!$request->session()->has('go_hud_intro');$request->session()->put('go_hud_intro',true);
        $boot['hud_links']=\App\Services\Dashboard\HomeHudLinks::for(auth('admin')->user());
        $boot['initial']['hologram']=app(\App\Services\Dashboard\HomeHologramMetrics::class)->data(auth('admin')->user(),$boot['hud_links']);
        // A refresh, section change or idle return must never replay the voice.
        $boot['greeting']=$request->session()->pull('go_hologram_login');
        if(is_array($boot['greeting']))$boot['greeting']['audio']=app(\App\Services\Dashboard\HomeHologramVoice::class)->forActor(auth('admin')->user());
        return response()->view($boot['initial']['branch_home']?'admin.home_branch':'admin.home',compact('boot'))->header('Cache-Control','private, no-store');
    }

    public function overview(Request $request, \App\Services\Dashboard\HomeOverview $overview)
    {
        if($request->query('section')==='hologram')return response()->json([
            'success'=>true,'hologram'=>app(\App\Services\Dashboard\HomeHologramMetrics::class)->data(auth('admin')->user())
        ])->header('Cache-Control','private, no-store');
        $data=$request->query('section')==='drawers'
            ?$overview->drawers($request->query(),auth('admin')->user())
            :$overview->data($request->query(),auth('admin')->user());
        if($request->query('section')!=='drawers')$data['hologram']=app(\App\Services\Dashboard\HomeHologramMetrics::class)->data(auth('admin')->user());
        return response()->json($data)->header('Cache-Control','private, no-store');
    }

    public function loginPage(GeneralSettings $settings){
        return response()->view('admin.login', compact('settings'))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function signin(Request $request){
        $request->validate([
            'email' => 'required|string|max:255',
            'password' => 'required|string',
            'remember' => 'nullable|boolean',
        ]);
        $identity = trim($request->input('email'));
        $mobiles = \App\Services\Dashboard\DashboardLoginIdentity::mobileVariants($identity);
        $candidates = User::where(function ($query) use ($identity, $mobiles) {
            $query->where('email', $identity)->orWhere('mobile', $identity);
            if ($mobiles !== []) { $query->orWhereIn('mobile', $mobiles); }
        })->whereIn('account_type', \App\Services\Dashboard\DashboardLoginIdentity::ACCOUNT_TYPES)->orderBy('id')->limit(10)->get()
            ->sortBy(fn ($candidate) => strcasecmp((string) $candidate->email, $identity) === 0 ? 0 : 1)->values();

        // Several dashboard accounts can share an email or a mobile. Taking whichever row came first signed a correct
        // password in against the wrong account now and then; sign in the one whose password matches (email first).
        $user = null;
        foreach ($candidates as $candidate) {
            if (Auth::guard('admin')->attempt([
                'id' => $candidate->getAuthIdentifier(),
                'account_type' => $candidate->account_type,
                'password' => $request->input('password'),
            ], $request->boolean('remember'))) { $user = $candidate; break; }
        }
        if ($user) {
            $request->session()->regenerate();
            session()->forget('id_user');
            session()->put('id_user', auth('admin')->id());
            $request->session()->forget('go_hud_intro');
            $request->session()->put('go_hologram_login',[
                'nonce'=>(string)\Illuminate\Support\Str::uuid(),'name'=>(string)$user->name,
            ]);
            return redirect('admin/dashboard');
        }
        return redirect()->back()->withInput($request->only('email', 'remember'))
            ->with('error', trans('main.invalid data'));
    }

    public function adminLogout()
    {
        $guard=Auth::guard('admin');
        if(config('desktop_dashboard.local')===true&&getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true'){
            // Revoke this device's remembered login even if the cached member was retired.
            $provider=$guard->getProvider();
            if(method_exists($provider,'forgetRemembered'))$provider->forgetRemembered($guard->user());
            $guard->logoutCurrentDevice();
        }else $guard->logout();
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
        $desktopNotificationGeneration = app(\App\Services\Dashboard\DesktopDashboardNotificationReads::class)->generation(Auth::guard('admin')->user());
        return view('admin.notifications', compact('data','desktopNotificationGeneration'));
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
        if(!request()->attributes->has('_desktop_notification_ids')||$data->read_at===null)$data->update(['read_at'=>now()]);
        
    //   if(isset($data->data['data']['order_id'])){
    //       return redirect()->route('orders.show',$data->data['data']['order_id']);
    //   }
        return redirect()->back();
    }
    
    public function mark_all_as_read(){
       $query=auth('admin')->user()->unreadNotifications();
       if(request()->attributes->has('_desktop_notification_ids'))$query->whereIn('id',request()->attributes->get('_desktop_notification_ids'));
       $query->update(['read_at' => now(), 'updated_at' => now()]);
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

