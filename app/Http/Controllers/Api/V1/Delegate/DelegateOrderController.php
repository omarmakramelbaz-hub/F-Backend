<?php
namespace App\Http\Controllers\Api\V1\Delegate;
use App\Services\Dashboard\OrderProviderDelivery;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Resturant;
use App\Models\Order;
use App\Services\Dashboard\LegacyOrderCompletion;
use App\Models\DelegateNotification;
use Illuminate\Http\Request;
use App\Http\Requests\Api\Auth\StoreUserResturantRequest;
use App\Http\Resources\Api\Auth\UserResource;
use App\Http\Resources\Api\User\CartResource;
use App\Http\Resources\Api\User\OrderResource;
use App\Http\Traits\ApiResponses;
use Notification;
use JWTAuth;
use Auth;
use Mail;
use DB;
use \Carbon\Carbon;
use App\Interfaces\ResturantRepositoryInterface;
use App\Models\Wallet;
use App\Models\GeneralSettings;
use App\Events\OrderUpdated;
use App\Events\VendorUpdated;
use App\Events\DelegateShippingUpdated;
use App\Events\ShippingUpdated;
use App\Events\UserUpdated;
use App\Events\DelegateUpdated;

class DelegateOrderController extends Controller {

  use ApiResponses;
    
    // public function getOrfffffders(Request $request){
    //     $delegate_id = auth('api')->user()->id;
    //     if(! empty($request->status) ){
    //                 $order = Order::query();

    //         if($request->status == 'completed'){
    //             $order = $order->where('status', 'completed');
    //             if(! empty($request->home) && $request->home == 'yes'){
    //                     $order_first = $order->has('carts')->where('delegate_id',$delegate_id)->first();
    //                     $orders_count = $order->has('carts')->count();
    //                     if($order_first){
    //                     $carts=OrderResource::make($order_first)->getOrdersCount($orders_count);
    //                     }else{
    //                         $carts=null;
    //                     }
        
    //             }else{
    //                 $order = $order->has('carts')->where('delegate_id',$delegate_id)->latest()->paginate(5);
    //                 $carts=resource_collection(OrderResource::collection($order));
    //             }
    //         }elseif($request->status == 'accepted')
    //         {
    //     $order = Order::query();

    //             $order = $order->whereNotNull('status')->whereNotIn('status',['pending','completed']);
                
    //             if(! empty($request->home) && $request->home == 'yes'){
    //                     $order_first = $order->has('carts')->where('delegate_id',$delegate_id)->first();
    //                     $orders_count = $order->has('carts')->count();
    //                     if($order_first){
    //                     $carts=OrderResource::make($order_first)->getOrdersCount($orders_count);
    //                     }else{
    //                         $carts=null;
    //                     }
        
    //             }else{
    //                 $order = $order->has('carts')->where('delegate_id',$delegate_id)->latest()->paginate(5);
    //                 $carts=resource_collection(OrderResource::collection($order));
    //             }
    //         }
    //         elseif($request->status == 'pending')
    //         {
    //     $order = Order::query();

    //             $order = $order->whereNotNull('status')->whereIn('status' ,['pending','another_delegate']);
    //             if(! empty($request->home) && $request->home == 'yes'){
    //                     $order_first = $order->whereHas('delegate_notifications',function($q) use($delegate_id){
    //                         $q->where('delegate_id',$delegate_id)->whereNull('status');
    //                     })->where('delegate_from_out','out_resturant')->latest()->first();
    //                     $orders_count = $order->count();
    //                     if($order_first){
    //                     $carts=OrderResource::make($order_first)->getOrdersCount($orders_count);
    //                     }else{
    //                         $carts=null;
    //                     }
        
    //             }else{
    //                 $order = $order->whereHas('delegate_notifications',function($q) use($delegate_id){
    //                         $q->where('delegate_id',$delegate_id)->where('status','!=','declined');
    //                     })->where('delegate_from_out','out_resturant')->paginate(5);
    //                 $carts=resource_collection(OrderResource::collection($order));
    //             }
    //         }
    //     }
        
    //     return $this->successResponse($carts,__('api.success data'));
    // }
    
    public function getOrders(Request $request){
        $delegate_id = auth('api')->user()->id;
        $order = Order::query();
        if(! empty($request->status) ){
            if($request->status == 'completed'){
                $order = $order->whereIn('status',['cancelled','completed','new_order']);
            }elseif($request->status == 'current')
            {
                if(! empty($request->type) && $request->type == 'accepted'){
                    $order = $order->where('status','accepted');    
                }
                if(! empty($request->type) && $request->type == 'shipped'){
                    $order = $order->where('status','shipped');    
                }
                $order = $order->whereIn('status',['accepted','shipped']);
            }
            elseif($request->status == 'pending')
            {
                $order = $order->whereIn('status' ,['pending','another_delegate']);
            }
        }
        if(! empty($request->order_no) ){
            $order = $order->where('order_no' , 'like', '%' . $request->order_no . '%');
        }
        
        if(! empty($request->date) ){
            $order = $order->whereDate('created_at' , $request->date);
        }
        
        //today orders
        if(! empty($request->home) && $request->home == 'yes'){
            $today = Carbon::today();
            if($request->status == 'pending'){
                $order = $order->whereNotNull('status')->whereHas('delegate_notifications',function($q) use($delegate_id){
                            $q->where('delegate_id',$delegate_id) ->where(function ($query) {
                                  $query->where('status', '!=', 'declined')
                                        ->orWhereNull('status');
                              });
                        })->where('delegate_from_out','out_resturant')->whereDate('created_at', $today)->orderBy('id','DESC')->paginate(5);
            }else{
                $order = $order->whereNotNull('status')->where('delegate_id',$delegate_id)->where('delegate_from_out','out_resturant')->whereDate('created_at', $today)->orderBy('id','DESC')->paginate(5);
            }
        }else{
            //all orders
            
            if($request->status == 'pending'){
                $order = $order->whereNotNull('status')->whereHas('delegate_notifications',function($q) use($delegate_id){
                            $q->where('delegate_id',$delegate_id) ->where(function ($query) {
                                  $query->where('status', '!=', 'declined')
                                        ->orWhereNull('status');
                              });
                        })->where('delegate_from_out','out_resturant')->orderBy('id','DESC')->paginate(5);
            }else{
                $order = $order->whereNotNull('status')->where('delegate_id',$delegate_id)->where('delegate_from_out','out_resturant')->orderBy('id','DESC')->paginate(5);
            }
        }
        $carts=resource_collection(OrderResource::collection($order));
        // broadcast(new OrderUpdated($order,1,$order->user_id));

        return $this->successResponse($carts,__('api.success data'));
    }
    
    public function getSingleOrder(Request $request, Order $order){
        $carts=OrderResource::make($order);
        return $this->successResponse($carts,__('api.success data'));
    }
    
    public function submitShippingOffer(Request $request, Order $order){
        if(auth('api')->user()->status != 'accepted'){
            return $this->errorResponse(__('api.contact admin for account activation'));
        }
        if($order->type != 'shipping' || !in_array($order->status, ['pending','another_delegate']) || $order->delegate_id != null){
            return $this->errorResponse(__('api.sorry another delegate accept order'));
        }
        $request->validate(['price' => 'required|numeric|min:1']);
        $notification = DelegateNotification::where('delegate_id', auth('api')->user()->id)
            ->where('order_id', $order->id)->first();
        if(!$notification){
            return $this->errorResponse(__('api.order not found'));
        }

        // Keep the order pending: this is only an offer. No commission is
        // charged until the customer explicitly accepts this delegate.
        $notification->update(['status' => 'accepted', 'offer_price' => $request->price]);

        $user = User::find($order->user_id);
        $delegate = auth('api')->user();
        if($user){
            Notification::send($user,new \App\Notifications\NotifyUserAfterOrderShippingAccepted($order));
            app(OrderProviderDelivery::class)->event(new DelegateShippingUpdated($delegate,1,$user->id,$request->price), (int) $order->id);
            app(OrderProviderDelivery::class)->event(new ShippingUpdated($order,1,$user->id), (int) $order->id);
        }
        return $this->successResponse(OrderResource::make($order->fresh()), __('api.accepted order successfully'));
    }

    public function reviseShippingOffer(Request $request, Order $order){
        if($order->type != 'shipping' || $order->status != 'accepted' ||
           $order->delegate_id != auth('api')->user()->id){
            return $this->errorResponse(__('api.order not found'));
        }
        $request->validate(['price' => 'required|numeric|min:1']);
        $offer = DelegateNotification::firstOrCreate([
            'delegate_id' => auth('api')->user()->id,
            'order_id' => $order->id,
        ]);
        $offer->update([
            'status' => 'price_revision',
            'offer_price' => $request->price,
        ]);
        $user = User::find($order->user_id);
        if($user){
            app(OrderProviderDelivery::class)->event(new ShippingUpdated($order,1,$user->id), (int) $order->id);
        }
        return $this->successResponse([
            'order' => OrderResource::make($order),
            'offer_price' => (float) $request->price,
        ], __('api.success data'));
    }

    public function respondShippingRevision(Request $request, Order $order){
        if($order->type != 'shipping' || $order->status != 'accepted' ||
           $order->user_id != auth('api')->user()->id || !$order->delegate_id){
            return $this->errorResponse(__('api.order not found'));
        }
        $request->validate(['status' => 'required|in:accepted,declined']);
        $offer = DelegateNotification::where('order_id',$order->id)
            ->where('delegate_id',$order->delegate_id)
            ->where('status','price_revision')->first();
        if(!$offer){
            return $this->errorResponse(__('api.order not found'));
        }

        // Rejecting a proposal is purely a state change: the current final
        // price and the previously charged commission remain untouched.
        if($request->status === 'declined'){
            $offer->update(['status'=>'accepted', 'offer_price'=>optional($order->shipping)->actual_price]);
            return $this->successResponse(OrderResource::make($order), __('api.declined order successfully'));
        }

        $newPrice = (float) $offer->offer_price;
        $setting = app(GeneralSettings::class);
        $newCommission = round($newPrice * (max(0,(float)$setting->shipping_min_price) / 100), 2);
        $chargedCommission = (float) ($offer->commission_amount ?? 0);
        $difference = round($newCommission - $chargedCommission, 2);
        $delegate = User::where('id',$order->delegate_id)->lockForUpdate()->first();

        if(!$delegate){
            return $this->errorResponse(__('api.delegate not found'));
        }
        if($difference > 0 && (float)$delegate->balance < $difference){
            return $this->errorResponse(__('api.charge your wallet first'));
        }

        // Customer acceptance makes this proposal the new final fare. Only now
        // do we settle the commission to exactly match that accepted final fare.
        DB::transaction(function() use($order,$offer,$delegate,$newPrice,$newCommission,$difference){
            $order->shipping->update(['actual_price'=>$newPrice]);
            $order->update(['delivery_price'=>$newPrice]);

            if($difference > 0){
                $delegate->decrement('balance',$difference);
                Wallet::create([
                    'from_user'=>$delegate->id,'to_user'=>null,'status'=>'completed',
                    'payment'=>'wallet','type'=>'transfer','amount'=>$difference,'order_id'=>$order->id
                ]);
            } elseif($difference < 0){
                $refund=abs($difference);
                $delegate->increment('balance',$refund);
                Wallet::create([
                    'from_user'=>null,'to_user'=>$delegate->id,'status'=>'completed',
                    'payment'=>'wallet','type'=>'transfer','amount'=>$refund,'order_id'=>$order->id
                ]);
            }

            $offer->update([
                'status'=>'accepted',
                'offer_price'=>$newPrice,
                'commission_amount'=>$newCommission,
            ]);
        });

        app(OrderProviderDelivery::class)->event(new ShippingUpdated($order->fresh(),1,$order->user_id), (int) $order->id);
        app(OrderProviderDelivery::class)->event(new DelegateUpdated($order->fresh(),1,$order->delegate_id), (int) $order->id);
        return $this->successResponse(OrderResource::make($order->fresh()), __('api.order updated successfully'));
    }

    public function acceptDeclineOrder(Request $request,Order $order){
        if ($order->type !== 'current') return $this->acceptDeclineOrderLocked($request, $order);
        return DB::transaction(function () use ($request, $order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertApiDelegateParty($locked, in_array($request->status, ['accept', 'declined'], true));
            abort_if(in_array($locked->status, ['completed', 'cancelled', 'declined'], true), 409, 'تم إغلاق الطلب.');
            return $this->acceptDeclineOrderLocked($request, $locked);
        }, 3);
    }

    private function acceptDeclineOrderLocked(Request $request,Order $order){
        if(auth('api')->user()->status != 'accepted'){
            return $this->errorResponse(__('api.contact admin for account activation'));
        }
        if($order->type=='current'){
            if($order->delegate_id==null){
                if($request->status=='accept'){
                    
                    $order->update(['status'=>$order->status === 'shipped' ? 'shipped' : 'accepted','delegate_id'=>auth('api')->user()->id]);
                    $title=__('api.accepted order successfully');
                    $delegates=DelegateNotification::where('order_id',$order->id)->where('delegate_id','!=',auth('api')->user()->id)->get();
                    foreach($delegates as $delegate){
                     app(OrderProviderDelivery::class)->event(new DelegateUpdated($order->id,1,$delegate->delegate_id), (int) $order->id);
                    }
                }elseif($request->status=='shipped'){
                    $title=__('api.shipped order successfully');
                    $order->update(['status'=>'shipped']);
                }elseif($request->status=='declined'){
                    DB::table('notifications')
                      ->where('type', 'App\Notifications\NotifyDelegatesNewOrderNotification')
                      ->where('notifiable_id', auth('api')->user()->id)
                      ->delete();
                      
                    DelegateNotification::where('delegate_id',auth('api')->user()->id)->where('order_id',$order->id)->update(['status' => 'declined']);
                    $title=__('api.declined order successfully');
                    $order->update(['status'=>'another_delegate']);
    
                }
                // send notification for vendor to search onther delegate
                $resturant_owner = User::where('id',$order->resturant?->user_id)->first();
                if($resturant_owner){
                    Notification::send($resturant_owner,new \App\Notifications\NotifyResturantDelegateAcceptedNotification($order));
                    app(OrderProviderDelivery::class)->event(new VendorUpdated($order->id,1,$resturant_owner->id), (int) $order->id);
                    app(OrderProviderDelivery::class)->event(new UserUpdated($order->id,1,$order->user_id), (int) $order->id);

                }
                return $this->successResponse("success",$title);
            }elseif($order->delegate_id == auth('api')->user()->id ){
                if($request->status=='shipped'){
                    $title=__('api.shipped order successfully');
                    $order->update(['status'=>'shipped']);
                                // send notification for vendor to search onther delegate
                    $resturant_owner = User::where('id',$order->resturant?->user_id)->first();
                    if($resturant_owner && $order->status == 'accepted'){
                        Notification::send($resturant_owner,new \App\Notifications\NotifyResturantDelegateAcceptedNotification($order));
                         app(OrderProviderDelivery::class)->event(new VendorUpdated($order->id,1,$resturant_owner->id), (int) $order->id);
                    app(OrderProviderDelivery::class)->event(new UserUpdated($order->id,1,$order->user_id), (int) $order->id);
                    }
                    return $this->successResponse("success",$title);
        
                }
            }else{
                 return $this->errorResponse(__('api.sorry another delegate accept order'));
            }
        }elseif($order->type=='shipping'){
                                $user = User::where('id', $order->user_id)->first();

                if($order->delegate_id==null && $request->status=='accept'){
                    
                    // $order->update(['status'=>'accepted','delegate_id'=>auth('api')->user()->id]);
                    DelegateNotification::where('delegate_id',auth('api')->user()->id)->where('order_id',$order->id)->update(['status' => 'accepted']);
                    $title=__('api.accepted order successfully');
                    $delegate = User::where('id', auth('api')->user()->id)->first();
                        //notify user after delegate accepted
                    Notification::send($user,new \App\Notifications\NotifyUserAfterOrderShippingAccepted($order));
                    app(OrderProviderDelivery::class)->event(new DelegateShippingUpdated($delegate,1,$user->id,$order->grand_total), (int) $order->id);
    
                }elseif($request->status=='shipped'){
                    $title=__('api.shipped order successfully');
                    $order->update(['status'=>'shipped']);
                    
                    // broadcast(new VendorUpdated($order,1,$user->id));
                    
                   app(OrderProviderDelivery::class)->event(new ShippingUpdated($order,1,$order->user_id), (int) $order->id);
               
    
                }elseif($request->status=='declined'){
                    DB::table('notifications')
                      ->where('type', 'App\Notifications\NotifyDelegatesNewOrderNotification')
                      ->where('notifiable_id', auth('api')->user()->id)
                      ->delete();
                      
                    DelegateNotification::where('delegate_id',auth('api')->user()->id)->where('order_id',$order->id)->update(['status' => 'declined']);
                    $title=__('api.declined order successfully');
                    // $order->update(['status'=>'another_delegate']);
                    app(OrderProviderDelivery::class)->event(new VendorUpdated($order->id,1,$user->id), (int) $order->id);
                }else{
                 return $this->errorResponse(__('api.sorry another delegate accept order'));
            }
               
                return $this->successResponse("success",$title);
            
        }
    }
    
    public function orderCompleted(Order $order){
        if ($order->type === 'current') {
            DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->assertApiDelegateParty($locked);
                app(LegacyOrderCompletion::class)->complete($locked);
            }, 3);
            return $this->successResponse('success', __('api.order updated successfully'));
        }
        if($order->status!='completed'){
        $order->update(['status'=>'completed']);
               if($order->type=='shipping'){
                   app(OrderProviderDelivery::class)->event(new ShippingUpdated($order,1,$order->user_id), (int) $order->id);
               }

        if($order->type=='current'){
        // send notification to user order updated  && resturant
                    $resturant_owner = User::where('id',$order->resturant->user_id)->first();
                    if($resturant_owner){
                        Notification::send($resturant_owner,new \App\Notifications\NotifyUserOrderStatusUpdatedNotification($order));
                        
                        app(OrderProviderDelivery::class)->event(new VendorUpdated($order->id,1,$resturant_owner->id), (int) $order->id);
                        app(OrderProviderDelivery::class)->event(new UserUpdated($order->id,1,$order->user_id), (int) $order->id);
                    }
        
        
                $user_order_owner = User::where('id',$order->user_id)->first();
                if($order->payment_type!='cash'){
                $user_price=$order->total-$order->updated_total;
                
                         if($user_price>0){
                            if($user_order_owner){
                                if($user_price>0){
                            //  return $resturant_owner->id;
                                // if($user_order_owner && $resturant_owner && $resturant_owner->balance>=$user_price){
                                    $resturant_owner->update(['balance' => $resturant_owner->balance-$user_price]);
                                    $user_order_owner->update(['balance' => $user_order_owner->balance+$user_price]); 
                                     Wallet::create([
                                    'from_user'=>$resturant_owner->id,
                                    'to_user'=>$user_order_owner->id,
                                    'amount'=>$user_price,
                                    'payment'=>'wallet',
                                    'type'=>'transfer',
                                    'order_id' => $order->id,
                                    'status' => 'completed',
                                    ]);
                                    Notification::send($user_order_owner,new \App\Notifications\NotifyOrderPriceTransferToWalletNotification($order,$user_price));
                                // }
                         }
                    }
                if($user_order_owner){
                    Notification::send($user_order_owner,new \App\Notifications\NotifyUserOrderStatusUpdatedNotification($order));
                     $email = $user_order_owner->email;
                        if($email){
                            app(\App\Services\Dashboard\BestEffortOrderMail::class)->send((int) $order->id, 'emails.send_order_email', ['email' => $email, 'cart' => $order], function ($message) use ($email) {
                    			$message->to($email);
                    			$message->subject('Your order has been received!');
                    
                    	    });
                        }
                }
                $data=OrderResource::make($order);
                return $this->successResponse("success",__('api.order updated successfully'));
            }
                }
        }
        if($order->payment_type=='cash' ){
                if($order->delegate_from_out=='in_resturant'){
                                     (new \App\Http\Controllers\Api\V1\Vendor\OrderController)->transfer_order_price($order->id);
                 }elseif($order->delegate_from_out=='out_resturant'){
                    (new \App\Http\Controllers\Api\V1\Delegate\DelegateOrderController)->transfer_order_price($order->id);
                 }
        }else{
            if($order->payment_type=='cash'){
                (new \App\Http\Controllers\Api\V1\Delegate\DelegateOrderController)->transfer_order_price($order->id);
            }else{
            (new \App\Http\Controllers\Dashboard\OrderController)->transferPrice($order->id);
            }

        }
        }
         return $this->successResponse("success",__('api.order updated successfully'));
        
    }
    
    
    public function reports(){
        $user_id = auth('api')->user()->id;
        $orders = Order::query()->where('delegate_id',$user_id)->where('status','completed')->whereIn('type',['current','shipping']);
             if (request()->report_type == 'day') {
            // Get the specific day from the request or default to today
            $day = request('day') ?? Carbon::today()->format('Y-m-d');
        
            // Query to get the count of orders for the specific day
            $chart_orders = DB::table('orders')
                ->whereDate('created_at', $day)
               ->where('delegate_id',$user_id)
                ->where('type',['current','shipping'])
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
        }   elseif(request()->report_type=='week'){
         Carbon::setWeekStartsAt(Carbon::SUNDAY);
            $week =request('week')?? date('d');
            // Get the start and end of the current week
            $startOfWeek_format = Carbon::now()->startOfWeek()->format('Y-m-d');
            $endOfWeek_format = Carbon::now()->endOfWeek()->format('Y-m-d');
            
            // Query to get the count of orders per day for the current week
            $chart_orders = DB::table('orders')
                ->whereBetween('created_at', [$startOfWeek_format, $endOfWeek_format])
                ->where('delegate_id',$user_id)
                ->whereIn('type',['current','shipping'])
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
            $orders=$orders->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
        }elseif(request()->report_type=='month'){
            $month =request('day')?? date('m');
            // dd($month);
            $chart_orders = DB::table('orders')
                        ->whereMonth('created_at',$month)
                        ->where('delegate_id',$user_id)
                        ->whereIn('type',['current','shipping'])
                        ->where('status','completed')
                        ->selectRaw('day(created_at) as day')
                        ->selectRaw('count(*) as count')
                        ->groupBy('day')
                        ->orderBy('day')
                        ->pluck('count', 'day')->toArray();
                        // dd($chart_orders);
            $order_month_count=[];
            for ($i=0; $i <31; $i++) { 
                if(array_key_exists($i+1, $chart_orders)) {
                    array_push( $order_month_count, $chart_orders[$i+1]);
                }else{
                    array_push( $order_month_count, 0);
                }
            }
            $chartOrders=$order_month_count;

            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();
            $orders = $orders->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
        } elseif(request()->report_type=='year'){
            $year =request('year')?? date('Y');
            $chart_orders = DB::table('orders')
                        ->whereYear('created_at',$year)
                        ->selectRaw('month(created_at) as month')
                        ->selectRaw('count(*) as count')
                        ->where('delegate_id',$user_id)
                        ->whereIn('type',['current','shipping'])
                        ->where('status','completed')
                        ->groupBy('month')
                        ->orderBy('month')
                        ->pluck('count', 'month')->toArray();
            $order_month_count=[];
            for ($i=0; $i < 12; $i++) { 
                if(array_key_exists($i+1, $chart_orders)) {
                    array_push( $order_month_count, $chart_orders[$i+1]);
                }else{
                    array_push( $order_month_count, 0);
                }
            }
            $chartOrders = $order_month_count;
            $startOfYear = Carbon::now()->startOfYear();
            $endOfYear = Carbon::now()->endOfYear();
            $orders = $orders->whereBetween('created_at', [$startOfYear, $endOfYear]);
        }

        
        // get orders
                $orders=$orders->get();
    
    
    $delivery_price=0;
    
        // calculate delegate not payed orders
        $not_payed=$orders->whereNull('transfer_price_by')->where('payment_type','cash');
        $not_payed_total=0;
        foreach($not_payed as $not){
            $not_payed_total=$not_payed_total+$not->total;
             $delivery_price=$delivery_price+$not->delivery_price;
        }
        
        
        // calculate delegate  payed orders
        $payed=$orders->where('transfer_price_by','delegate')->where('payment_type','cash');
        $payed_total=0;
        foreach($payed as $pay){
            $payed_total=$payed_total+$pay->total;
            $delivery_price=$delivery_price+$pay->delivery_price;
        }
        
      
        
         // calculate delegate gain cash  orders price
        $all_total=$delivery_price+$payed_total+$not_payed_total;
        
        
     $delegate_orders=auth('api')->user()->delegate_orders;
     $gain_from_cash_delivery=$delegate_orders->where('status','completed')->where('payment_type','cash')->whereNotNull('transfer_price_by')->sum('delivery_price');
     $gain_from_cash1_delivery=$delegate_orders->where('status','completed')->where('payment_type','cash')->whereNotNull('delegate_id')->whereNull('transfer_price_by')->sum('delivery_price');
     $gain_from_online_delivery=$delegate_orders->where('status','completed')->where('payment_type','!=','cash')->whereNotNull('transfer_price_by')->sum('delivery_price');

        $data=[
        'chart_orders'=>$chartOrders,
        'orders'=>OrderResource::collection($orders),
        'orders_count'=>$orders->count(),
        'not_transfer_cash_orders'=>$not_payed_total,
        'transfer_cash_orders'=>$payed_total,
        'total_cash_order'=>$all_total,
        'total_gain_from_app'=>$gain_from_online_delivery+$gain_from_cash_delivery+$gain_from_cash1_delivery,
        
        ];
        return $this->successResponse($data,__('api.success data'));
    }
    
    public function transfer_order_price($id){
        return DB::transaction(function () use ($id) {
            $locked = Order::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($locked->type === 'current') $this->assertApiDelegateParty($locked);
            if ($locked->type === 'current') app(LegacyOrderCompletion::class)->lockParties($locked);
            $eligible = $locked->type === 'current' && $locked->grand_total > 0 && $locked->delegate_id
                && $locked->reason === null && $locked->transfer_price_by === null && $locked->status === 'completed';
            $result = $this->transferOrderPriceLocked($id);
            if ($result instanceof \Throwable) throw $result;
            if ($eligible && Order::whereKey($id)->value('transfer_price_by') === null) throw new \RuntimeException('Courier settlement did not commit');
            return $result;
        }, 3);
    }

    private function transferOrderPriceLocked($id){
        try{
            $order=Order::find($id);
            if ($order && auth('admin')->check()) {
                // The dashboard may settle only this scoped order. Resolve its
                // exact parties without the unrelated dashboard listing scope.
                $restaurant = Resturant::withoutGlobalScopes()->find($order->resturant_id);
                if ($restaurant) {
                    $restaurant->setRelation('user', User::withoutGlobalScope(\App\Scopes\AdminScope::class)->find($restaurant->user_id));
                }
                $order->setRelation('resturant', $restaurant);
                $order->setRelation('delegate', User::withoutGlobalScope(\App\Scopes\AdminScope::class)->find($order->delegate_id));
            }
            $delegate=$order->delegate;
            if($order && $order->grand_total>0 && $order->delegate_id !=null && $order->reason==null && $order->transfer_price_by==null  && $order->status == 'completed'){
                $vendor_price=$order->vendor_percentage;
                $app_price=$order->app_percentage;
                $total=$app_price+$vendor_price;
                // if($delegate->balance>$total){
                    $vendor=$order->resturant?->user;
                    if($vendor){
                        // transfer order price for vendor
                        $vendor->update(['balance'=>$vendor->balance+$vendor_price]);
                        Wallet::create([
                            'from_user'=>$delegate->id,
                            'to_user'=>$vendor->id,
                            'amount'=>$vendor_price,
                            'payment'=>'wallet',
                            'type'=>'transfer',
                            'status' => 'completed',
                            'order_id' => $order->id,
                            ]);
                    }
                    // transfer tax for app
                    app(LegacyOrderCompletion::class)->incrementAppBalance(\App\Services\GoServices\Money::minor(number_format($app_price, 2, '.', '')));
                        Wallet::create([
                            'from_user'=>$delegate->id,
                            'amount'=>$app_price,
                            'payment'=>'wallet',
                            'type'=>'transfer',
                            'status' => 'completed',
                            'order_id' => $order->id,
                            ]);
                    $delegate->update(['balance'=>$delegate->balance-$total]);
                    $order->update(['transfer_price_by'=>'delegate']);
                    $admin= User::findOrFail(1);
                    if($vendor){
                    //notify vendor with order percentage
                        Notification::send($vendor,new \App\Notifications\NotifyOrderPercentageNotification($order));
                    }
                    //notify admin with order percentage
                    Notification::send($admin,new \App\Notifications\NotifyOrderPercentageNotification($order));

                    if($delegate->balance<0){
                     $order->update(['expiration_date' => now()->addHours(2),'connected'=>'inactive']);   
                     Notification::send($delegate,new \App\Notifications\NotifyMinWalletBalanceNotification($delegate));
                    }
                    return $this->successResponse(OrderResource::make($order),__('api.successfully transfer')); 
                // }else{
                //       return $this->errorResponse(__('api.charge your wallet first')); 
                // }
                
            }else{
                 return $this->errorResponse(__('api.error'));
            }
        }catch(\Exception $e){
             return $this->errorResponse($e->getMessage());
          }
    }

    private function assertApiDelegateParty(Order $order, bool $allowInvitation = false): void
    {
        if (auth('admin')->check() || !auth('api')->check()) return;
        $actor = auth('api')->user();
        abort_unless($actor->account_type === 'delegate' && $actor->status === 'accepted', 403);
        $assigned = (int) $order->delegate_id === (int) $actor->id;
        $invited = $allowInvitation && !$order->delegate_id
            && \Illuminate\Support\Facades\Schema::hasTable('delegate_notifications')
            && DB::table('delegate_notifications')->where('order_id', $order->id)->where('delegate_id', $actor->id)->exists();
        abort_unless($assigned || $invited, 403);
    }
    

}
