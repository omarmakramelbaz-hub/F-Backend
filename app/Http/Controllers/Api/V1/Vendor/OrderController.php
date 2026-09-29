<?php
namespace App\Http\Controllers\Api\V1\Vendor;
use App\Services\OrderBroadcastService;
use App\Services\OrderAction;
use App\Events\OrderStatusUpdated;
use App\Events\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Resturant;
use App\Models\Order;
use App\Models\DelegateNotification;

use Illuminate\Http\Request;
use App\Http\Requests\Api\Auth\StoreUserResturantRequest;
use App\Http\Resources\Api\Auth\UserResource;
use App\Http\Resources\Api\User\CartResource;
use App\Http\Resources\Api\User\OrderResource;
use App\Http\Traits\ApiResponses;
use Notification;
use App\Jobs\ProcessCart;
use JWTAuth;
use Auth;
use \Carbon\Carbon;
use App\Models\Wallet;
use App\Models\GeneralSettings;
use App\Interfaces\ResturantRepositoryInterface;
use Mail;
use DB;
use App\Http\Requests\Api\Vendor\UpdateOrderTotalRequest;
use App\Events\DelegateUpdated;
use App\Events\VendorUpdated;
use App\Events\UserUpdated;
use App\Events\OrderFinishedUpdated;

class OrderController extends Controller
{

    use ApiResponses;

    public function getOrders(Request $request)
    {
        $resturant_id = auth('api')->user()->base_resturant->id;
        $order = Order::query();
        if (!empty($request->status)) {
            if ($request->status == 'completed') {
                $order = $order->where(function ($q) {
                    $q->whereIn('status', ['cancelled', 'completed', 'declined'])
                        ->orWhereNull('status');
                });
            } elseif ($request->status == 'current') {
                if (!empty($request->type) && $request->type == 'accepted') {
                    $order = $order->where('status', 'accepted');
                }
                if (!empty($request->type) && $request->type == 'shipped') {
                    $order = $order->where('status', 'shipped');
                }
                $order = $order->whereIn('status', ['accepted', 'shipped']);
            } elseif ($request->status == 'pending') {
                $order = $order->whereIn('status', ['pending', 'another_delegate', 'new_order']);
            }
        }
        if (!empty($request->order_no)) {
            $order = $order->where('order_no', 'like', '%' . $request->order_no . '%');
        }
        if (!empty($request->delegate_from_out)) {
            $order = $order->where('delegate_from_out', $request->delegate_from_out);
        }

        if (!empty($request->date)) {
            $order = $order->whereDate('created_at', $request->date);
        }

        //today orders
        if (!empty($request->home) && $request->home == 'yes') {
            $today = Carbon::today();
            $order = $order->has('carts')->where('resturant_id', $resturant_id)->whereDate('created_at', $today)->orderBy('id', 'DESC')->paginate(5);
        } else {
            //all orders
            $order = $order->has('carts')->where('resturant_id', $resturant_id)->orderBy('id', 'DESC')->paginate(5);
        }
        $carts = resource_collection(OrderResource::collection($order));
        return $this->successResponse($carts, __('api.success data'));
    }

    public function getSingleOrder(Request $request, Order $order)
    {

        if ($request->wantsJson() || $request->is('api/*')) {
            $carts = OrderResource::make($order);
            return $this->successResponse($carts, __('api.success data'));
        } else {
            return view('admin.orders.single_apply', compact('order'));
        }
    }

    public function searchDelegates()
    {
        // dd(request()->all());
        $resturant = Resturant::where('id', request('resturant_id'))->first();
        $latitude = $resturant->lat;
        $longitude = $resturant->lng;
        $order = Order::where('id', request('order_id'))->first();
        $delegates = User::where('connected', 'active')->where('status', 'accepted')->where('account_type', 'delegate')->select(\DB::raw('*, ( 6367 * acos( cos( radians(' . $latitude . ') ) * cos( radians( lat ) ) * 
          cos( radians( lng ) - radians(' . $longitude . ') ) + sin( radians(' . $latitude . ') ) * sin( radians( lat ) ) ) ) AS distance'))
            ->having('distance', '<', 10)
            ->orderBy('distance')->get();
        $order->update(['delegate_id' => null]);
        // dd($delegates);
        //send notification for all delegates
        if ($delegates) {
            foreach ($delegates as $key => $value) {
                $order->update(['delegate_from_out' => 'out_resturant']);

                broadcast(new DelegateUpdated($order, 1, $value->id));
                DelegateNotification::create([
                    'delegate_id' => $value->id,
                    'order_id' => $order->id,
                ]);
                Notification::send($value, new \App\Notifications\NotifyDelegatesNewOrderNotification($order));
            }
        }
        if (request()->wantsJson() || request()->is('api/*')) {
            $usersData = UserResource::collection($delegates);
            return $this->successResponse($usersData, __('api.success data'));
        } else {

            return redirect()->back()->with('success_code', 5);
        }
    }

    public function updateOrder(Request $request, Order $order)
    {
        // dd($request);
        if ($request->type == 'in_resturant') {
            $up = $order->update(['status' => 'accepted', 'delegate_from_out' => 'in_resturant']);
            //send notification for user has order    
            $user_order_owner = User::where('id', $order->user_id)->first();
            if ($user_order_owner) {
                Notification::send($user_order_owner, new \App\Notifications\NotifyUserOrderStatusUpdatedNotification($order));
            }

            $email = $user_order_owner->email ?? null;
            if ($email) {
                Mail::send('emails.send_order_email', ['email' => $email, 'cart' => $order], function ($message) use ($email) {
                    $message->to($email);
                    $message->subject('Your order has been received!');

                });
            }
            OrderBroadcastService::accept($order);

broadcast(new OrderFinishedUpdated($order, 1, $order->user_id));

event(new OrderStatusUpdated($order));
            if ($request->wantsJson() || $request->is('api/*')) {
                $orderData = OrderResource::make($order->fresh());
                return $this->successResponse($orderData, __('api.order has prepared from resturant'));
            } else {
                return redirect()->back()->with('success', trans('api.order has prepared from resturant'));
            }

        }
        if ($request->type == 'out_resturant') {

    $order->update(['delegate_from_out' => 'out_resturant']);

    // إشعار العميل
    broadcast(new OrderFinishedUpdated($order, 1, $order->user_id));

    // إشعار الفرع
    $vendor = User::find(optional($order->resturant)->user_id);
    if ($vendor) {
        OrderBroadcastService::outForDelivery($order);
    }

    // إشعار الإدارة الرئيسية (الحساب 635)
    $mainAdmin = User::where('account_type', 'resturant_owner')->first();
    if ($mainAdmin) {
    }

    $this->searchDelegates();

            if ($request->wantsJson() || $request->is('api/*')) {
                $orderData = OrderResource::make($order->fresh());
                return $this->successResponse($orderData, __('api.wait until search for a delegate'));
            } else {

                // if(!session()->has('details')){
                //  session()->put('details', [ 0 =>[
                //         'order_id' => $order->id ,
                //         'time' => now()->timestamp * 1000,
                // ]
                //     ]);
                // }else{
                // session()->push('details', [
                //         'order_id' => $order->id ,
                //         'time' => now()->timestamp * 1000,
                //     ]);
                // }
                $orderId = $order->id; // Assuming $order is your order object

                // Retrieve the existing details from the session
                $details = session()->get('details', []);

                $orderExists = false;

                // Check if order_id exists in the details array
                foreach ($details as &$detail) {
                    if ($detail['order_id'] === $orderId) {
                        // Update the time if the order_id exists
                        $detail['time'] = now()->timestamp * 1000;
                        $orderExists = true;
                        break; // No need to check further
                    }
                }

                if (!$orderExists) {
                    // If it doesn't exist, add a new entry
                    $details[] = [
                        'order_id' => $orderId,
                        'time' => now()->timestamp * 1000,
                    ];
                }

                // Update the session with the modified details array
                session()->put('details', $details);

                return redirect()->back()->with(['success_code' => 5, 'success' => __('api.wait until search for a delegate')]);
            }

            // ProcessCart::dispatch($order)->delay(now()->addSeconds(5));

        }

    }


    public function updateOrderStatus(Request $request, Order $order)
    {
        if (\App\Services\GoPayments\OrderPayments::record((int)$order->id)) {
            abort_unless(auth('admin')->check() || (int)$order->resturant?->user_id===(int)auth('api')->id(),403);
            if ($request->status==='completed') {
                app(\App\Services\GoPayments\OrderPayments::class)->finish((int)$order->id);
                return $this->successResponse('success',__('api.order updated successfully'));
            }
            if ($request->status==='declined') {
                app(\App\Services\GoPayments\OrderPayments::class)->cancel((int)$order->id,(int)$order->user_id);
                return $this->successResponse('success',__('api.order updated successfully'));
            }
            app(\App\Services\GoPayments\OrderPayments::class)->assertPayableWork($order);
        }

    
\Log::info('UPDATE_ORDER_STATUS', [
    'order_id' => $order->id,
    'status' => $request->status,
    'customer_id' => $order->user_id,
    'restaurant_owner_id' => optional($order->resturant)->user_id,
]);    if ($order->status != $request->status) {
            $data = $order->update(['status' => $request->status]);
            OrderBroadcastService::decline($order);

$user_order_owner = User::where('id', $order->user_id)->first();
$resturant_owner = User::where('id', $order->resturant->user_id)->first();

// تحديث جميع شاشات الأدمن
if ($resturant_owner) {
}
            if ($order->status == 'completed') {
                if ($order->payment_type != 'cash') {
                    $user_price = $order->total - $order->updated_total;

                    if ($user_price > 0) {
                        if ($user_order_owner) {
                            if ($user_price > 0) {
                                //  return $resturant_owner->id;
                                // if($user_order_owner && $resturant_owner && $resturant_owner->balance>=$user_price){
                                $resturant_owner->update(['balance' => $resturant_owner->balance - $user_price]);
                                $user_order_owner->update(['balance' => $user_order_owner->balance + $user_price]);
                                Wallet::create([
                                    'from_user' => $resturant_owner->id,
                                    'to_user' => $user_order_owner->id,
                                    'amount' => $user_price,
                                    'payment' => 'wallet',
                                    'type' => 'transfer',
                                    'order_id' => $order->id,
                                    'status' => 'completed',
                                ]);
                                Notification::send($user_order_owner, new \App\Notifications\NotifyOrderPriceTransferToWalletNotification($order, $user_price));
                                // }
                            }
                        }
                    }
                } elseif ($order->payment_type == 'cash') {
                    if ($order->delegate_from_out == 'in_resturant') {
                        (new \App\Http\Controllers\Api\V1\Vendor\OrderController)->transfer_order_price($order->id);
                    } elseif ($order->delegate_from_out == 'out_resturant') {
                        (new \App\Http\Controllers\Api\V1\Delegate\DelegateOrderController)->transfer_order_price($order->id);
                    }
                } elseif ($order->payment_type == 'wallet') {
                    (new \App\Http\Controllers\Dashboard\OrderController)->transferPrice($order->id);

                }
            }

            if ($order->status == 'declined') {
                $order->declined_by = auth('admin')->check() ? auth('admin')->user()->account_type : auth('api')->user()->account_type;
                $order->save();
                if ($order->payment_type != 'cash') {

                    (new \App\Http\Controllers\Dashboard\OrderController)->transferCancelledOrderPrice($order->id);


                }
                $notis = DelegateNotification::where('order_id', $order->id)->get();
                foreach ($notis as $noti) {
                    $n = DB::table('notifications')
                        ->where('type', 'App\Notifications\NotifyDelegatesNewOrderNotification')
                        ->where('notifiable_id', $noti->delegate_i)
                        ->first();
                    if ($n) {
                        $n->delete();
                    }
                    $noti->delete();
                }

            }


            // send notification to user order updated
            if ($user_order_owner) {
                Notification::send($user_order_owner, new \App\Notifications\NotifyUserOrderStatusUpdatedNotification($order));

                $email = $user_order_owner->email;
                if ($email) {
                    Mail::send('emails.send_order_email', ['email' => $email, 'cart' => $order], function ($message) use ($email) {
                        $message->to($email);
                        $message->subject('Your order has been received!');

                    });
                }
            }

            event(new OrderStatusUpdated($order));
        }
        if ($request->wantsJson() || $request->is('api/*')) {
            return $this->successResponse("success", __('api.order updated successfully'));
        } else {
            if ($order->status == 'completed' || $order->status == 'declined') {
                return redirect()->route('orders.applies')->with('success_code', $order->status);
            }
            return redirect()->back()->with('success_code', $order->status);
        }

    }

    public function acceptOrder(Request $request, Order $order)
    {
        if ($order->accepted_notify != 'yes') {

    $data = $order->update(['accepted_notify' => 'yes']);

    // إشعار العميل
    OrderBroadcastService::accept($order);

    // إشعار الفرع
    $vendor = User::find(optional($order->resturant)->user_id);
    if ($vendor) {
    }

    Notification::send($order->user, new \App\Notifications\NotifyAcceptOrderNotification($order));
}
        if ($request->wantsJson() || $request->is('api/*')) {
            return $this->successResponse("success", __('api.accepted order successfully'));
        } else {

            return redirect()->back()->with('success_code', $order->status);
        }
    }
    public function reports()
    {
        if (request()->wantsJson() || request()->is('api/*')) {
            $resturant_id = auth('api')->user()->base_resturant->id;
        } else {
            $resturant_id = auth('admin')->user()->base_resturant->id;
        }
        $orders = Order::query()->where('resturant_id', $resturant_id)->where('status', 'completed');
        if (request()->report_type == 'day') {
            // Get the specific day from the request or default to today
            $day = request('day') ?? Carbon::today()->format('Y-m-d');

            // Query to get the count of orders for the specific day
            $chart_orders = DB::table('orders')
                ->whereDate('created_at', $day)
                ->where('resturant_id', $resturant_id)
                ->where('type', 'current')
                ->where('status', 'completed')
                ->selectRaw('HOUR(created_at) as hour') // Group by hour for hourly counts
                ->selectRaw('COUNT(*) as count')
                ->groupBy('hour')
                ->orderBy('hour')
                ->pluck('count', 'hour')
                ->toArray();

            // Create an array for 24 hours with default count of 0
            $order_day_count = array_fill(0, 24, 0);

            // Map the counts to the appropriate hour
            foreach ($chart_orders as $hour => $count) {
                $order_day_count[$hour] = $count;
            }

            // The $order_day_count array now holds the order counts for each hour of the day
            $chartOrders = $order_day_count;

            // Filter the orders for the specific day
            $orders = $orders->whereDate('created_at', $day);
        } elseif (request()->report_type == 'week') {
            Carbon::setWeekStartsAt(Carbon::SUNDAY);
            $week = request('week') ?? date('d');
            // Get the start and end of the current week
            $startOfWeek_format = Carbon::now()->startOfWeek()->format('Y-m-d');
            $endOfWeek_format = Carbon::now()->endOfWeek()->format('Y-m-d');

            // Query to get the count of orders per day for the current week
            $chart_orders = DB::table('orders')
                ->whereBetween('created_at', [$startOfWeek_format, $endOfWeek_format])
                ->where('resturant_id', $resturant_id)
                ->where('type', 'current')
                ->where('status', 'completed')
                ->selectRaw('DATE(created_at) as day')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('count', 'day')
                ->toArray();
            $order_week_count = array_fill(0, 7, 0); // Array for 7 days of the week
            // Map the counts to the appropriate day of the week (0=Monday, 6=Sunday)
            foreach ($chart_orders as $day => $count) {
                $dayOfWeek = Carbon::parse($day)->dayOfWeek; // Gets the day of the week as a number (0=Sunday, 6=Saturday)
                $order_week_count[$dayOfWeek] = $count;
            }

            // The $order_week_count array now holds the order counts for each day of the week
            $chartOrders = $order_week_count;
            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();
            $orders = $orders->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
        } elseif (request()->report_type == 'month') {
            $month = request('day') ?? date('m');
            // dd($month);
            $chart_orders = DB::table('orders')
                ->whereMonth('created_at', $month)
                ->where('resturant_id', $resturant_id)
                ->where('type', 'current')
                ->where('status', 'completed')
                ->selectRaw('day(created_at) as day')
                ->selectRaw('count(*) as count')
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('count', 'day')->toArray();
            // dd($chart_orders);
            $order_month_count = [];
            for ($i = 0; $i < 31; $i++) {
                if (array_key_exists($i + 1, $chart_orders)) {
                    array_push($order_month_count, $chart_orders[$i + 1]);
                } else {
                    array_push($order_month_count, 0);
                }
            }
            $chartOrders = $order_month_count;

            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();
            $orders = $orders->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
        } elseif (request()->report_type == 'year') {
            $year = request('year') ?? date('Y');
            $chart_orders = DB::table('orders')
                ->whereYear('created_at', $year)
                ->selectRaw('month(created_at) as month')
                ->selectRaw('count(*) as count')
                ->where('resturant_id', $resturant_id)
                ->where('type', 'current')
                ->where('status', 'completed')
                ->groupBy('month')
                ->orderBy('month')
                ->pluck('count', 'month')->toArray();
            $order_month_count = [];
            for ($i = 0; $i < 12; $i++) {
                if (array_key_exists($i + 1, $chart_orders)) {
                    array_push($order_month_count, $chart_orders[$i + 1]);
                } else {
                    array_push($order_month_count, 0);
                }
            }
            $chartOrders = $order_month_count;
            $startOfYear = Carbon::now()->startOfYear();
            $endOfYear = Carbon::now()->endOfYear();
            $orders = $orders->whereBetween('created_at', [$startOfYear, $endOfYear]);
        }

        $orders = $orders->get();



        $delivery_price = 0;

        // calculate delegate not payed orders
        $not_payed = $orders->whereNull('transfer_price_by')->whereNull('delegate_id')->where('payment_type', 'cash');
        $not_payed_total = 0;
        $total = 0;
        foreach ($not_payed as $not) {
            $total = $total + $not->total;
            $not_payed_total = $not_payed_total + $not->app_percentage;
            $delivery_price = $delivery_price + $not->delivery_price;
        }
        // calculate delegate  payed orders
        $payed = $orders->where('transfer_price_by', 'vendor')->where('payment_type', 'cash');
        $payed_total = 0;
        // return $payed->count();
        foreach ($payed as $pay) {
            $total = $total + $pay->total;
            $payed_total = $payed_total + $pay->app_percentage;
            $delivery_price = $delivery_price + $pay->delivery_price;
        }
        if (request()->wantsJson() || request()->is('api/*')) {
            $vendor_orders = auth('api')->user()->base_resturant->orders;
        } else {

            $vendor_orders = auth('admin')->user()->base_resturant->orders;
        }
        // المكاسب من الطلبات الكاش المدفوع رسومها +لم يتم دفع رسومها
        $gain_from_cash_delivery = $vendor_orders->where('payment_type', 'cash')->where('status', 'completed');
        $gain_cash = 0;
        foreach ($gain_from_cash_delivery as $cash) {
            if ($cash->delegate_id == null) {
                $gain_cash = $gain_cash + $cash->delivery_price + $cash->vendor_percentage;
            } else {
                $gain_cash = $gain_cash + $cash->vendor_percentage;
            }
        }
        // المكاسب من الطلبات الاونلاين المدفوع رسومها   
        $gain_from_online_delivery = $vendor_orders->where('status', 'completed')->where('payment_type', '!=', 'cash')->whereNotNull('transfer_price_by');
        $gain_online = 0;
        foreach ($gain_from_online_delivery as $online) {
            if ($online->delegate_id == null) {
                $gain_online = $gain_online + $online->delivery_price + $online->vendor_percentage;
            } else {
                $gain_online = $gain_online + $online->vendor_percentage;
            }
        }



        $data = [
            'chart_orders' => $chartOrders,
            'orders' => OrderResource::collection($orders),
            'orders_count' => $orders->count(),
            // رسوم لم يتم تحويلها
            'not_transfer_cash_orders' => $not_payed_total,
            // رسوم تم تحويلها
            'transfer_cash_orders' => $payed_total,
            // المبالغ المستلمه تم دفع رسومها +لم يتم دفع رسومها
            'total_cash_order' => $total + $delivery_price,
            //المكسب من الابلكيشن
            'total_gain_from_app' => $gain_online + $gain_cash,
            'gain_online' => $gain_online,
            'gain_cash' => $gain_cash,

        ];

        if (request()->wantsJson() || request()->is('api/*')) {
            return $this->successResponse($data, __('api.success data'));
        } else {
            return view('admin.resturants.report', compact('data'));
        }
    }

    public function transfer_order_price($id)
    {
        if (app(\App\Services\GoPayments\OrderPayments::class)->complete((int)$id)) return;

        try {
            $order = Order::find($id);
            $vendor = $order->resturant?->user;
            if ($order && $order->grand_total > 0 && $order->transfer_price_by == null && $order->delegate_id == null) {
                $app_price = $order->app_percentage;

                // if($vendor->balance>=$app_price){

                // transfer tax for app
                $setting = app(GeneralSettings::class);
                $setting->app_balance = $setting->app_balance + $app_price;
                $setting->save();
                Wallet::create([
                    'from_user' => $vendor->id,
                    'amount' => $app_price,
                    'payment' => 'wallet',
                    'type' => 'transfer',
                    'order_id' => $order->id,
                    'status' => 'completed',
                ]);
                $vendor->update(['balance' => $vendor->balance - $app_price]);
                $admin = User::findOrFail(1);
                $order->update(['transfer_price_by' => 'vendor']);
                //notify admin with order percentage
                Notification::send($admin, new \App\Notifications\NotifyOrderPercentageNotification($order));

                if ($vendor->balance < 0) {
                    $vendor->update(['expiration_date' => now()->addHours(2), 'connected' => 'inactive']);
                    Notification::send($vendor, new \App\Notifications\NotifyMinWalletBalanceNotification($vendor));
                }
                return $this->successResponse(OrderResource::make($order), __('api.successfully transfer'));
                // }else{
                //       return $this->errorResponse(__('api.charge your wallet first')); 
                //     }

            } else {
                return $this->errorResponse(__('api.error'));
            }
        } catch (\Exception $e) {
            return $e;
            return $this->errorResponse($e->getMessage());
        }
    }

    public function updateOrderTotalPrice(UpdateOrderTotalRequest $request, Order $order)
    {
        app(\App\Services\GoPayments\OrderPayments::class)->assertCanReprice($order);

        $setting = app(GeneralSettings::class);
        //   return $order;
        if ($order->status == 'pending' || $order->status == 'accepted' || $order->status == 'another_delegate') {
            $item = $order->carts->where('id', $request->item_id)->first();
            if ($item) {
                $item->update([
                    'updated_total' => $request->total,
                    'reason_update_total' => $request->reason,
                ]);

                $resturant = $order->resturant;
                $vendor_tax = $order->updated_total * ($resturant->service_fees / 100);
                $tax = $vendor_tax * ($setting->tax / 100);
                $user_tax = $order->updated_total * ($setting->tax / 100);
                $updated = $order->update(
                    [
                        'tax' => $tax,
                        'vendor_tax' => $vendor_tax,
                        'user_tax' => $user_tax,
                    ]
                );
                //  return ['tax'=>$tax,'vendor_tax'=>$vendor_tax,'user_tax'=>$user_tax,'order_tax'=>$order->vendor_tax
                //  ];


                //  return $updated;

                $user_order = $order->user;
                if ($user_order) {
                    Notification::send($user_order, new \App\Notifications\NotifyUserOrderTotalUpdatedNotification($order));
                }
            }
        } else {
            return $this->errorResponse(__('api.can not update order price now'));
        }
        if (request()->wantsJson() || request()->is('api/*')) {
            return $this->successResponse("success", __('api.order updated successfully'));
        } else {
            return redirect()->back()->with("success", __('api.order updated successfully'));
        }

    }
}
