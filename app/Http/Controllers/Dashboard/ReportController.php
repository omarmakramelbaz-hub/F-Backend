<?php

namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Auth;
use App\Models\GeneralSettings;
use App\Models\User;
use App\Models\Commission;
use App\Models\Wishlist;
use App\Models\Order;
use App\Models\Resturant;
use App\Models\ValetTracker;
use App;
use Carbon\Carbon;
use App\Exports\ExportPackages;
use App\Exports\ExportTickets;
use App\Exports\ExportValetTrackers;
use Maatwebsite\Excel\Facades\Excel;
use App\Charts\UserChart;
use DB;
class ReportController extends Controller
{
    public function __construct() 
    {
        $this->middleware('permission:report-list', ['only' => ['index','getOrders','getCustomers','getVendors','getDelegates','getResturants']]);
    }

    public function index(GeneralSettings $settings, Request $request)
    {
        return view('admin.reports.index');
    }

    public function getOrders()
    {
        $n_orders = Order::query();
        $period=request('q');
        if(in_array($period,['daily','weekly','monthly','yearly'],true)){
            $day=\Carbon\Carbon::parse(\App\Services\Dashboard\OperatingDay::date(),'Africa/Cairo');
            $from=$day->copy();$to=$day->copy();
            if($period==='weekly'){$from->startOfWeek(Carbon::SUNDAY);$to->endOfWeek(Carbon::SATURDAY);}
            elseif($period==='monthly'){$from->startOfMonth();$to->endOfMonth();}
            elseif($period==='yearly'){$from->startOfYear();$to->endOfYear();}
            $n_orders->where('updated_at','>=',\App\Services\Dashboard\OperatingDay::start($from->toDateString())->toDateTimeString())
                ->where('updated_at','<',\App\Services\Dashboard\OperatingDay::end($to->toDateString())->toDateTimeString());
        }

        if(! empty(request('status'))){
            $n_orders = $n_orders->where('status',request('status'));
        }
        $orders = $n_orders->whereNotNull('status')->where('type','current')->latest()->get();
        $registrations = $n_orders->selectRaw('status as status')
                    ->selectRaw('count(*) as count')
                    ->groupBy('status')
                    ->orderBy('status')
                    ->pluck('count', 'status')->toArray();
        $arr=['pending', 'accepted', 'shipped', 'cancelled', 'completed'];
        $registration_month_count=[];
        for ($i=0; $i < count($arr); $i++) { 
            if(array_key_exists($arr[$i], $registrations)) {
                array_push( $registration_month_count, array_values($registrations)[0]);
            }else{
                array_push( $registration_month_count, 0);
            }
        }
        $registrationsChart = new UserChart;
        $registrationsChart->labels([__('main.order-pending'), __('main.order-accepted'),__('main.order-shipped'),__('main.order-cancelled'), __('main.order-completed')]);
        $registrationsChart->dataset(trans('main.registrationsChart'), 'pie', $registration_month_count)->options([
            'fill' => 'true',
            'borderColor' => '#364576'
        ]);
        return view('admin.reports.orders_report', compact('orders','registrationsChart'));
    }

    public function getCustomers()
    {
        $pending_users=User::where('account_type','user')->where('status','pending')->count();
        $accepted_users=User::where('account_type','user')->where('status','accepted')->count();
        $no_wishlits_users=Wishlist::count();
        $no_commissions_users=Commission::count();
        $users = DB::table('users')->where('account_type','user')
                    ->whereYear('created_at',date('Y'))
                    ->selectRaw('month(created_at) as month')
                    ->selectRaw('count(*) as count')
                    ->groupBy('month')
                    ->orderBy('month')
                    ->pluck('count', 'month')->toArray();
        $user_month_count=[];
        for ($i=0; $i < 12; $i++) { 
            if(array_key_exists($i+1, $users)) {
                array_push( $user_month_count, $users[$i+1]);
            }else{
                array_push( $user_month_count, 0);
            }
        }
        $usersChart = new UserChart;
        $usersChart->labels(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
        $usersChart->dataset(trans('main.usersChart'), 'line', $user_month_count)->options([
            'fill' => 'true',
            'borderColor' => '#364576'
        ]);
        return view('admin.reports.customers_report', compact('usersChart','pending_users','accepted_users','no_wishlits_users','no_commissions_users'));
    }
    
    public function getDelegates()
    {
        $pending_delegates=User::where('account_type','delegate')->where('status','pending')->count();
        $accepted_delegates=User::where('account_type','delegate')->where('status','accepted')->count();
        $no_commissions_delegates=Commission::count();

        $delegates = DB::table('users')->where('account_type','delegate')
                    ->whereYear('created_at',date('Y'))
                    ->selectRaw('month(created_at) as month')
                    ->selectRaw('count(*) as count')
                    ->groupBy('month')
                    ->orderBy('month')
                    ->pluck('count', 'month')->toArray();
        $delegate_month_count=[];
        for ($i=0; $i < 12; $i++) { 
            if(array_key_exists($i+1, $delegates)) {
                array_push( $delegate_month_count, $delegates[$i+1]);
            }else{
                array_push( $delegate_month_count, 0);
            }
        }
        $delegatesChart = new UserChart;
        $delegatesChart->labels(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
        $delegatesChart->dataset(trans('main.delegatesChart'), 'line', $delegate_month_count)->options([
            'fill' => 'true',
            'borderColor' => '#364576'
        ]);
        return view('admin.reports.delegates_report', compact('delegatesChart','pending_delegates','accepted_delegates','no_commissions_delegates'));
    }
    
    public function getResturants()
    {
        $orders = Order::query();
        if(! empty(request('resturant_id'))){
            $orders = $orders->where('resturant_id',request('resturant_id'));
        }
        $orders=$orders->get();
        $delivery_price=0;
    
        // calculate delegate not payed orders
        $not_payed=$orders->whereNull('transfer_price_by')->whereNull('delegate_id')->where('payment_type','cash');
        $not_payed_total=0;$total=0;
        foreach($not_payed as $not){
            $total=$total+$not->total;
            $not_payed_total=$not_payed_total+$not->app_percentage;
             $delivery_price=$delivery_price+$not->delivery_price;
        }
        // calculate delegate  payed orders
        $payed=$orders->where('transfer_price_by','vendor')->where('payment_type','cash');
        $payed_total=0;
        // return $payed->count();
        foreach($payed as $pay){
            $total=$total+$pay->total;
            $payed_total=$payed_total+$pay->app_percentage;
            $delivery_price=$delivery_price+$pay->delivery_price;
        }
        
        $vendor_orders=$orders;
        // المكاسب من الطلبات الكاش المدفوع رسومها +لم يتم دفع رسومها
        $gain_from_cash_delivery=$vendor_orders->where('payment_type','cash')->where('status','completed');
        $gain_cash=0;
        foreach($gain_from_cash_delivery as $cash){
            if($cash->delegate_id==null){
                $gain_cash=$gain_cash+$cash->delivery_price+$cash->vendor_percentage;
            }else{
                $gain_cash=$gain_cash+$cash->vendor_percentage;
            }
        }
                // المكاسب من الطلبات الاونلاين المدفوع رسومها   
         $gain_from_online_delivery=$vendor_orders->where('status','completed')->where('payment_type','!=','cash')->whereNotNull('transfer_price_by');
         $gain_online=0;
         foreach($gain_from_online_delivery as $online){
            if($online->delegate_id==null){
                $gain_online=$gain_online+$online->delivery_price+$online->vendor_percentage;
            }else{
                $gain_online=$gain_online+$online->vendor_percentage;
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
        $gain_online=$gain_online;
        $gain_cash=$gain_cash;
        
        

        return view('admin.reports.resturants_report',compact('orders',
        'not_transfer_cash_orders','transfer_cash_orders',
            'total_cash_order',
            'total_gain_from_app',
            'gain_online',
            'gain_cash'));
    }
    
    public function getVendors()
    {
        $pending_vendors=User::where('account_type','vendor')->where('status','pending')->count();
        $accepted_vendors=User::where('account_type','vendor')->where('status','accepted')->count();
        $no_wishlits_vendors=Wishlist::distinct('resturant_id')->count();

        $vendors = DB::table('users')->where('account_type','vendor')
                    ->whereYear('created_at',date('Y'))
                    ->selectRaw('month(created_at) as month')
                    ->selectRaw('count(*) as count')
                    ->groupBy('month')
                    ->orderBy('month')
                    ->pluck('count', 'month')->toArray();
        $vendor_month_count=[];
        for ($i=0; $i < 12; $i++) { 
            if(array_key_exists($i+1, $vendors)) {
                array_push( $vendor_month_count, $vendors[$i+1]);
            }else{
                array_push( $vendor_month_count, 0);
            }
        }
        $vendorsChart = new UserChart;
        $vendorsChart->labels(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
        $vendorsChart->dataset(trans('main.vendorsChart'), 'line', $vendor_month_count)->options([
            'fill' => 'true',
            'borderColor' => '#364576'
        ]);
        return view('admin.reports.vendors_report', compact('vendorsChart','pending_vendors',
            'accepted_vendors',
            'no_wishlits_vendors'));
    }
    
    
    public function exportTicketExcelFile() 
    {
        return Excel::download(new ExportTickets, 'tickets-'.now().'.xlsx');
    } 
    
    public function exportPackageExcelFile() 
    {
        return Excel::download(new ExportPackages, 'user_packages-'.now().'.xlsx');
    } 

    public function exportValetTrackerExcelFile() 
    {
        return Excel::download(new ExportValetTrackers, 'valet-trackers-'.now().'.xlsx');
    } 
}
