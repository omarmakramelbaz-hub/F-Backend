<?php

namespace App\Http\Controllers\Api\V1\User;
use App\Http\Controllers\Payment\PaymobController;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Resturant;
use App\Models\ResturantProduct;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\Review;
use App\Models\Commission;
use App\Models\User;
use App\Models\Area;
use App\Models\ResturantArea;
use App\Models\DelegateNotification;
use App\Models\GeneralSettings;
use Illuminate\Http\Request;
use App\Http\Resources\Api\User\CartResource;
use App\Http\Requests\Api\User\CartRequest;
use App\Http\Requests\Api\User\ReorderRequest;
use App\Http\Traits\ApiResponses;
use JWTAuth;
use Validator;
use Auth;
use App\Interfaces\ServiceRepositoryInterface;
use App\Http\Requests\Api\User\OrderRequest;
use App\Http\Requests\Api\User\ReviewRequest;
use App\Http\Requests\Api\User\ComessionOrderDelegateRequest;
use App\Http\Resources\Api\User\ResturantResource;
use App\Http\Resources\Api\Vendor\ResturantProductResource;
use App\Http\Resources\Api\User\OrderResource;
use App\Http\Resources\Api\User\ResturantCartResource;
use Notification;
use Mail;
use DB;
use App\Events\OrderFinishedUpdated;

use App\Events\VendorUpdated;
use App\Events\DelegateUpdated;
use App\Events\OrderUpdated;
use App\Services\OrderBroadcastService;
use App\Jobs\SendOrderCreatedAfterResponse;
class CartController extends Controller
{

    use ApiResponses;

    //  ===================================================================================
    //  =============================================cart==================================
    //  ===================================================================================

    public function store(CartRequest $request)
    {
        $product = ResturantProduct::find($request->resturant_product_id);
        // $order=auth('api')->user()->orders()->whereNull('status')->where('type','current')->first();


        // if(!$order){
        //     $order=Order::create([
        //         'user_id'=>auth('api')->user()->id,
        //         // 'status'=>'pending',
        //         'status' => null,
        //         'type'=>'current',
        //         'resturant_id'=>$product->resturant_id,

        //     ]);
        // }
        //  return $order->id;
        // if($order->resturant_id ==null || ($order->resturant_id !=null && $order->resturant_id == $product->resturant_id)){
        if ($product) {
            $cart = Cart::where([
                'user_id' => auth('api')->user()->id,
                'is_order' => false,
                //  'order_id'=>$order->id,
                'resturant_id' => $product->resturant_id,
                'resturant_product_id' => $product->id,
                'product_clean' => $request->product_clean,
                'product_feature' => $request->product_feature,
            ])->first();
            if ($cart) {
                $cart->update(['qty' => $cart->qty + $request->qty ?? 1]);
            } else {
                $cart = Cart::create([
                    'user_id' => auth('api')->user()->id,
                    //  'order_id'=>$order->id,
                    'qty' => $request->qty ?? 1,
                    'price' => $product->calculate_price($request->product_feature, $request->product_clean),
                    'resturant_id' => $product->resturant_id,
                    'resturant_product_id' => $product->id,
                    'product_clean' => $request->product_clean,
                    'product_feature' => $request->product_feature,
                ]);
            }
            // $order->update(['resturant_id'=>$product->resturant_id]);

            // }

            $cart_data = new CartResource($cart);
        return $this->successResponse($cart_data, __('api.AddToCartSuccessfully'));
        } else {
            return $this->msgResponse(__('api.empty your cart first'));
        }
    }
    public function update_cart_item($id, CartRequest $request)
    {
        $cart = Cart::find($id);
        $product = ResturantProduct::find($request->resturant_product_id);
        $cart->update([
            'qty' => $request->qty ?? 1,
            'price' => $product->calculate_price($request->product_feature, $request->product_clean),
            'resturant_id' => $product->resturant_id,
            'resturant_product_id' => $product->id,
            'product_clean' => $request->product_clean,
            'product_feature' => $request->product_feature,
        ]);

        $cart_data = new CartResource($cart);
        return $this->successResponse($cart_data, __('api.UpdatedCartSuccessfully'));

    }
    public function show($id)
    {
        $cart = Cart::find($id);
        $cart_data = new CartResource($cart);
        return $this->successResponse($cart_data, __('api.AddToCartSuccessfully'));

    }
    public function reorder(ReorderRequest $request)
    {
        $cartItems = collect();
        $product = ResturantProduct::find($request->resturant_product_id[0]);
        // $order=auth('api')->user()->orders()->whereNull('status')->where('type','current')->first();


        // if(!$order){
        //     $order=Order::create([
        //         'user_id'=>auth('api')->user()->id,
        //         // 'status'=>'pending',
        //         'status' => null,
        //         'type'=>'current',
        //         'resturant_id'=>$product->resturant_id,

        //     ]);
        // }
        $hasItemInCartWithSameRest = Cart::isNotOrder()->where('resturant_id', $product->resturant_id)->exists();
        if ($hasItemInCartWithSameRest) {
            return $this->msgResponse(__('api.empty your cart first'));
        }
        // if($order->resturant_id ==null || ($order->resturant_id !=null && $order->resturant_id == $product->resturant_id)){

        foreach ($request->resturant_product_id as $i => $product_id) {
            $product = ResturantProduct::find($product_id);
            if ($product) {
                $cart = Cart::where([
                    'user_id' => auth('api')->user()->id,
                    'is_order'=>false,
                    //  'order_id'=>$order->id,
                    'resturant_id' => $product->resturant_id,
                    'resturant_product_id' => $product->id,
                    'product_clean' => $request->product_clean[$i] ?? null,
                    'product_feature' => $request->product_feature[$i] ?? null,
                ])->first();
                if ($cart) {
                    $cart->update(['qty' => $cart->qty + $request->qty[$i] ?? 1]);
                } else {
                    $cart = Cart::create([
                        'user_id' => auth('api')->user()->id,
                        'is_order'=>false,
                        //  'order_id'=>$order->id,
                        'qty' => $request->qty[$i] ?? 1,
                        'price' => $product->calculate_price($request->product_feature[$i], $request->product_clean[$i]),
                        'resturant_id' => $product->resturant_id,
                        'resturant_product_id' => $product->id,
                        'product_clean' => $request->product_clean[$i] ?? null,
                        'product_feature' => $request->product_feature[$i] ?? null,
                    ]);
                }
                $cartItems->push($cart);
                // $order->update(['resturant_id'=>$product->resturant_id]);

                // }


            }
        }
        $cart_data = CartResource::collection($cartItems);
        return $this->successResponse($cart_data, __('api.AddToCartSuccessfully'));
        // }else{
        //     return $this->msgResponse(__('api.empty your cart first'));
        // }
    }
    public function product_calculate_price(CartRequest $request)
    {
        $product = ResturantProduct::find($request->resturant_product_id);
        $price = $product->calculate_price($request->product_feature, $request->product_clean);

        return $this->successResponse($price, __('api.AddToCartSuccessfully'));
    }

    public function index(GeneralSettings $setting)
    {
        $cartItems = Cart::isNotOrder()->get();
        if ($cartItems->count() > 0) {
            $resturant = $cartItems->first()->resturant;
            $total = 0;
            foreach ($cartItems as $cart) {
                $total = $total + ($cart->price * $cart->qty);
            }
            $carts = CartResource::collection($cartItems);
            $resturant = new ResturantResource($resturant);
            $user_tax = $total * ($setting->tax / 100);
            // Get product IDs already in the user's cart
            $cartProductIds = $cartItems->pluck('resturant_product_id');

            // Fetch products not in the cart, prioritizing highest_rated = 'yes'
            $recommendedProducts = ResturantProduct::where('status', 'show')->whereNotIn('id', $cartProductIds)
                ->where('resturant_id', $cartItems->first()->resturant_id)
                ->orderByRaw("FIELD(highest_rated, 'yes', 'no')") // 'yes' first, then 'no'
                ->take(3) // Limit the number of recommendations
                ->get();
            return $this->successResponse(['resturant' => $resturant, 'total_cart' => $total, 'user_tax' => $user_tax, 'carts' => $carts, 'recommendedProducts' => ResturantProductResource::collection($recommendedProducts)], __('api.success data'));
        }
        return $this->successResponse(null, __('api.empty'));
    }



    public function cart_count()
    {
        $cartItems = Cart::isNotOrder()->get();
        if ($cartItems->count() > 0) {

            return $this->successResponse($cartItems->count(), __('api.success data'));
        }
        return $this->successResponse(null, __('api.empty'));
    }

    public function update($id, Request $request)
    {
        $cart = Cart::find($id);
        if ($cart) {
            if ($request->qty == 0) {
                $cart->delete();

            } else {
                $cart->update([
                    'qty' => $request->qty ?? $cart->qty,
                ]);
            }
            $cartItems = Cart::isNotOrder()->get();
            if (!$cartItems || $cartItems->count() == 0) {
                return $this->successResponse(null, __('api.UpdatedCartSuccessfully'));

            } else {
                $cart_data = CartResource::collection($cartItems->fresh());
            }
            return $this->successResponse($cart_data, __('api.UpdatedCartSuccessfully'));
        }
        return $this->errorResponse(__('api.empty'));

    }
    public function removeCart(Request $request)
    {
        $cartItems = Cart::isNotOrder()->get();
        if ($cartItems) {
            $usercart = $cartItems;
            if ($usercart->count() == 0) {
                return $this->successResponse(null, __('api.UpdatedCartSuccessfully'));
            }
            if ($usercart) {
                foreach ($usercart as $cart) {
                    $cart->delete();
                }
                return $this->successResponse(null, __('api.remove all cart'));
            }
        } else {
            return $this->successResponse(null, __('api.empty'));
        }
    }
    //  ===================================================================================
    //  =============================================orders================================
    //  ===================================================================================


    public function order_payment(OrderRequest $request, GeneralSettings $setting){
        $__perf = microtime(true);
        if (auth('api')->user()->otp_first_order == 0) {
            $user = auth('api')->user();
            $user->otp_first_no = mt_rand(1111, 9999);
            // $user->otp_first_no= '1234';
            $user->save();
            $user_mobile = '20' . auth('api')->user()->mobile;
            $username = 'fe082b557b6f5466588dc3f2a025207b784eeba854d78c78cb2f3d9562f88bfd';
            $password = '2d5f17adaadabd5f2e025001f055a1cf155aa32f15fae09e6d19a83e05e23f84';
            $senderId = '8b42c51d209b1e952e5e692135c282978f540200fe8c870332c0e594e89a0534';
            $link =
                \Http::post("https://smsmisr.com/api/SMS/?environment=1&username=$username&password=$password&language=2&sender=$senderId&mobile=$user_mobile&message=فسخانينجا.. من فضلك أدخل كود التحقق المرسل ($user->otp_first_no)");
            // return $link->status();
            if ($link->status() == 200) {
                // $user->otp_first_order= 1;
                // $user->save();
                return $this->successResponse(1, __('api.code has been sent'));
            } else {
                return $this->errorResponse(__('api.error in sent '));
            }
        } else {
            $carts = Cart::isNotOrder()->get();
            if ($carts->count() == 0) {
                return $this->errorResponse(__('api.empty'));
            }
            $order = Order::create([
                'user_id' => auth('api')->user()->id,
                // 'status'=>'pending',
                'status' => null,
                'type' => 'current',
                'resturant_id' => $carts->first()->resturant_id,
            ]);

            Cart::whereIn('id', $carts->pluck('id')->all())->update([
                'order_id' => $order->id,
                'is_order' => true,
            ]);
            // $order = auth('api')->user()->orders()->where('type', 'current')->whereNull('status')->first();
            //  dd($order->carts->count());
            $orderCount = $order->resturant->orders()->where('status', 'pending')->whereDate('created_at', '=', now()->toDateString())->count();

            if ($order && $order->carts->count() > 0 && $order->grand_total >= $order->resturant->min_order_price) {
                $updated = $order->update($request->input());

                //          $order->notes=$request->notes;

                // $order->save();
                //  dd($order->id);
                $resturant = $order->resturant;
                $vendor_tax = $order->total * ($resturant->service_fees / 100);
                $tax = $vendor_tax * ($setting->tax / 100);
                $user_tax = $order->total * ($setting->tax / 100);

                $order->user_tax = $user_tax;
                $order->save();
                // The checkout client only needs the order ID here.
                // Full order details are fetched on the success/tracking screen.
                // Avoid serializing OrderResource in this hot path because it performs
                // multiple relationship/count queries.
                //send notification for resturant has new order
                $resturant_owner = User::with('base_resturant.parent.owner')->whereHas('base_resturant', function ($q) use ($order) {
                    $q->where('id', $order->resturant_id);
                })->first();
                $userRestOwnerParent = null;
                if ($resturant_owner?->base_resturant?->parent?->owner) {
                    $userRestOwnerParent = $resturant_owner->base_resturant->parent->owner;
                }

                if ($request->payment_type == 'online') {
                    return (new PaymobController)->checkingOut(
                        'paymob_card_payment',
                        env('PAYMOB_CARD_INTEGRATION_ID'),
                        $order->id,
                        env('PAYMOB_CARD_IFRAME_ID')
                    );
                } else if ($request->payment_type == 'v_cash') {
                    return (new PaymobController)->checkingOut(
                        'paymob_mobile_wallet_payment',
                        env('PAYMOB_MOBILE_WALLET_INTEGRATION_ID'),
                        $order->id,
                        $order->user_address?->mobile
                    );
                } else if ($request->payment_type == 'wallet') {
                    if (auth('api')->user()->balance >= $order->grand_total) {
                        Wallet::create([
                            'from_user' => auth('api')->user()->id,
                            'to_user' => null,
                            'status' => 'completed',
                            'payment' => 'wallet',
                            'type' => 'transfer',
                            'amount' => $order->grand_total,
                            'order_id' => $order->id
                        ]);
                        $order->update(['status' => 'pending']);
                        $user = auth('api')->user();
                        $user->update(['balance' => $user->balance - $order->grand_total]);
                        // (new \App\Http\Controllers\Dashboard\OrderController)->transferPrice($order->id);
                    } else {
                        return $this->errorResponse(__('api.charge your wallet first'));
                    }
                } elseif ($request->payment_type == 'cash') {
                    $order->update(['status' => 'pending']);

                    //  if($order->delegate_from_out=='in_resturant'){
                    //                      (new \App\Http\Controllers\Api\V1\Vendor\OrderController)->transfer_order_price($order->id);
                    //  }elseif($order->delegate_from_out=='out_resturant'){
                    //     (new \App\Http\Controllers\Api\V1\Delegate\DelegateOrderController)->transfer_order_price($order->id);
                    //  }

                }

                if ($request->payment_type == 'wallet' || $request->payment_type == 'cash') {

                    $updated = $order->update(['tax' => $tax, 'vendor_tax' => $vendor_tax, 'user_tax' => $user_tax, 'resturant_id' => $order->carts()->first()->resturant_id, 'created_at' => now()]);
                    // Keep order.updated synchronous so the restaurant/admin
                    // dashboards receive the new order and alert sound immediately.
                    if ($resturant_owner) {
                        broadcast(new OrderUpdated($order, $orderCount, $resturant_owner->id));
                    }
                    if ($userRestOwnerParent) {
                        broadcast(new OrderUpdated($order, $orderCount, $userRestOwnerParent->id));
                    }

                    // Keep the administrative dashboard live even when branch
                    // ownership data is not attached to the administrative user.
                    OrderBroadcastService::newOrder($order);

                    // FCM, database notification, customer email, and the legacy
                    // vendor.updated event run after the HTTP response is sent.
                    // Laravel dispatchAfterResponse runs this without a queue worker.
                    SendOrderCreatedAfterResponse::dispatchAfterResponse(
                        (int) $order->id,
                        $resturant_owner ? (int) $resturant_owner->id : null,
                        $userRestOwnerParent ? (int) $userRestOwnerParent->id : null,
                        (int) $orderCount
                    );

                    return $this->successResponse(
                        ['id' => (int) $order->id],
                        __('api.order sent successfully')
                    );
                }
            }
            return $this->errorResponse(__('api.cannot create order'));
        }
    }
    public function order($id)
    {
        $order = auth('api')->user()->orders()->where('id', $id)->first();
        if ($order) {
            $order_data = new OrderResource($order);
            return $this->successResponse($order_data, __('api.success data'));
        }
        return $this->errorResponse(__('api.order not found'));
    }
    public function orders()
    {
        $orders = Order::where('type', 'current')->where('user_id', auth('api')->user()->id)->whereNotNull('status')->when(request()->has('status'), function ($q) {
            $q->where('status', request()->status);
        })->latest()->get();
        $order_data = OrderResource::collection($orders);
        return $this->successResponse($order_data, __('api.success data'));

        return $this->errorResponse(__('api.error'));
    }

    public function cancel_order($id)
    {
        $order = auth('api')->user()->orders()->where('id', $id)->first();
        if ($order && $order->status == 'cancelled') {
            return $this->errorResponse(__('api.already cancelled'));

        }

        if ($order && $order->type == 'current' && $order->status == 'pending') {

            $up = $order->update(['status' => 'cancelled']);
            broadcast(new OrderFinishedUpdated($order, 1, $order->user_id));
            $resturant_owner = User::whereHas('base_resturant', function ($q) use ($order) {
                $q->where('id', $order->resturant_id);
            })->first();
            if ($resturant_owner) {
                $orderCount = $order->resturant->orders()->where('status', 'pending')->whereDate('created_at', '=', now()->toDateString())->count();

                broadcast(new VendorUpdated($order, $orderCount, $resturant_owner->id));
            }
            $order_data = new OrderResource($order);
            //send notification for resturant has order cancelled
            $resturant_owner = User::whereHas('base_resturant', function ($q) use ($order) {
                $q->where('id', $order->resturant_id);
            })->first();
            if ($resturant_owner) {
                Notification::send($resturant_owner, new \App\Notifications\NotifyResturantOrderCancelledNotification($order));
                $to_email = $resturant_owner->email;
                if ($to_email) {
                    $mail = Mail::send('emails.resturant_schedule_order', ['cart' => $order], function ($message) use ($to_email) {
                        $message->to($to_email);
                        $message->subject('reminder schedule order');
                    });
                }
            }
            return $this->successResponse($order_data, __('api.cancelled successfully'));
        } elseif ($order && $order->type == 'shipping' && $order->status != 'cancelled') {
            if ($order->status == 'pending' && $order->delegate_id == null) {
                //  cancelled and not discount actual_price
                $order->update(['status' => 'cancelled']);
                $resturant_owner = User::whereHas('base_resturant', function ($q) use ($order) {
                    $q->where('id', $order->resturant_id);
                })->first();
                if ($resturant_owner) {
                    $orderCount = $order->resturant->orders()->where('status', 'pending')->whereDate('created_at', '=', now()->toDateString())->count();

                    broadcast(new VendorUpdated($order, $orderCount, $resturant_owner->id));
                }

                $delegate = DelegateNotification::where('order_id', $order->id)->pluck('delegate_id')->toArray();
                DelegateNotification::where('order_id', $order->id)->delete();
                DB::table('notifications')
                    ->where('type', 'App\Notifications\NotifyDelegatesNewOrderNotification')
                    ->whereIn('notifiable_id', $delegate)
                    ->delete();
            } elseif ($order->status == 'accepted' || $order->delegate_id != null) {
                //  if payment cash will discount order from wallet and send notication to delegate and his delegate_precentage(command)
                $order->update(['status' => 'declined', 'shipping_cancelled_block' => now()]);
                $delegate = DelegateNotification::where('order_id', $order->id)->pluck('delegate_id')->toArray();
                $resturant_owner = User::whereHas('base_resturant', function ($q) use ($order) {
                    $q->where('id', $order->resturant_id);
                })->first();
                if ($resturant_owner) {
                    $orderCount = $order->resturant->orders()->where('status', 'pending')->whereDate('created_at', '=', now()->toDateString())->count();

                    broadcast(new VendorUpdated($order, $orderCount, $resturant_owner->id));
                }
                DelegateNotification::where('order_id', $order->id)->delete();
                DB::table('notifications')
                    ->where('type', 'App\Notifications\NotifyDelegatesNewOrderNotification')
                    ->whereIn('notifiable_id', $delegate)
                    ->delete();

                //   notify delegate order cancelled
                if ($order->delegate) {
                    broadcast(new DelegateUpdated($order, 1, $order->delegate_id));
                    Notification::send($order->delegate, new \App\Notifications\NotifyDelegateShippingCancelledNotification($order));
                }

                //if payment cash 
                if ($order->payment_type == 'cash') {
                    Wallet::create([
                        'from_user' => auth('api')->user()->id,
                        'to_user' => null,
                        'status' => 'completed',
                        'payment' => 'wallet',
                        'type' => 'transfer',
                        'amount' => $order->grand_total,
                        'order_id' => $order->id
                    ]);
                }
                $user = auth('api')->user();
                $user->update(['balance' => $user->balance - $order->grand_total]);
            } else {
                return $this->errorResponse(__('api.cannot cancel order'));
            }
            $resturant_owner = User::whereHas('base_resturant', function ($q) use ($order) {
                $q->where('id', $order->resturant_id);
            })->first();
            if ($resturant_owner) {
                $orderCount = $order->resturant->orders()->where('status', 'pending')->whereDate('created_at', '=', now()->toDateString())->count();

                broadcast(new VendorUpdated($order, $orderCount, $resturant_owner->id));
            }
            $order_data = new OrderResource($order);
            return $this->successResponse($order_data, __('api.cancelled successfully'));
        }

    }
    //  =============================================previous orders==================================

    public function previous_order()
    {
        $orders = Cart::where('user_id', auth('api')->user()->id)->whereHas('order', function ($q) {
            $q->where('status', 'completed');
        })
            ->groupBy('resturant_id')->pluck('resturant_id')->toArray();
        $resturants = ResturantResource::collection(Resturant::whereIn('id', $orders)->get());
        return $this->successResponse($resturants, __('api.success data'));
    }
    public function previous_order_products($id)
    {
        $orders = Cart::where('user_id', auth('api')->user()->id)->where('resturant_id', $id)->whereHas('order', function ($q) {
            $q->where('status', 'completed');
        })
            ->groupBy('resturant_product_id')->pluck('resturant_product_id')->toArray();
        $resturants = ResturantProductResource::collection(ResturantProduct::whereIn('id', $orders)->get());
        return $this->successResponse($resturants, __('api.success data'));

    }
    //  =============================================rate order==================================

    public function review_order(ReviewRequest $request)
    {
        $order = Order::find($request->order_id);
        if ($order && $order->status == 'completed') {
            $review = Review::where('user_id', auth('api')->user()->id)->where('order_id', $order->id)->first();
            if ($review) {
                return $this->errorResponse(__('api.already review order'));
            }
            $review = Review::create($request->input() + (['user_id' => auth('api')->user()->id]));
            $resturant = $review->resturant;
            $resturant->calcualte_star_rate();
            //send notification for resturant -> user sent make a review
            $resturant_owner = User::whereHas('base_resturant', function ($q) use ($review) {
                $q->where('id', $review->resturant_id);
            })->first();
            if ($resturant_owner) {
                Notification::send($resturant_owner, new \App\Notifications\NotifyResturantReviewSentNotification($review));
            }

            return $this->successResponse($review, __('api.Your review sent successfully'));
        }
        return $this->errorResponse(__('api.cannot review order now'));
    }
    //  =============================================commesion for delegate order==================================

    public function Commission_order_delegate(ComessionOrderDelegateRequest $request)
    {
        $order = Order::find($request->order_id);
        $user = auth('api')->user();
        if ($order && $order->status == 'completed' && $order->delegate) {
            if ($user->balance >= $request->commission) {
                $commission = Commission::create([
                    'user_id' => $user->id,
                    'delegate_id' => $order->delegate_id,
                    'order_id' => $request->order_id,
                    'commission' => $request->commission,
                ]);
                Wallet::create([
                    'order_id' => $order->id,
                    'from_user' => $user->id,
                    'to_user' => $order->delegate_id,
                    'amount' => $request->commission,
                    'type' => 'transfer',
                    'payment' => 'wallet',
                    'status' => 'completed',
                ]);
                $delegate = $order->delegate;
                $delegate->balance = $delegate->balance + $request->commission;
                $delegate->save();
                $user->balance = $user->balance - $request->commission;
                $user->save();
                //send notification for delegate -> user sent commission
                $delegate = User::where('id', $order->delegate_id)->first();
                if ($delegate) {
                    Notification::send($delegate, new \App\Notifications\NotifyDelegateCommissionSentNotification($commission));
                }
                return $this->successResponse($commission, __('api.Your commesion sent successfully'));
            }
            return $this->errorResponse(__('api.cannot add commesion charge your wallet first'));
        }
        return $this->errorResponse(__('api.cannot add commesion now'));
    }


    //     public function canDeliver(Request $request)
//     {
//         $restaurant_id = $request->input('restaurant_id');
//         $customer_lat = $request->input('customer_lat');
//         $customer_lng = $request->input('customer_lng');

    //         $restaurant = Resturant::findOrFail($restaurant_id);
// $restaurantGovernorate = Area::whereIn('id', $restaurant->resturant_areas()->pluck('area_id'))->value('cairo_id');
//         dd($restaurantGovernorate);
//         $city_name = getCityName($customer_lat,$customer_lng);         

    //         $customerArea = Area::where('title_ar', 'like', '%' . $city_name . '%')->orWhere('title_en', 'like', '%' . $city_name . '%')->first();

    //         // $customerArea = Area::select('id', 'cairo_id')
//         //                     ->whereRaw("ST_Contains(geo_boundary, POINT(?, ?))", [$customer_lat, $customer_lng])
//         //                     ->first();
//     // dd($customerArea);
//         if (!$customerArea) {
//             return response()->json(['message' => 'لم يتم تحديد منطقتك، يرجى التأكد من GPS.'], 400);
//         }
//         $customerGovernorate = $customerArea->cairo_id;

    //         if ($restaurantGovernorate ==408 && $customerGovernorate == 408) { 
//             return response()->json(['can_deliver' => true]);
//         }
//         $canDeliver = ResturantArea::where('resturant_id', $restaurant_id)
//             ->where('area_id', $customerArea->id)
//             ->exists();

    //         return response()->json(['can_deliver' => $canDeliver]);
//     }

    public function canDeliver(Request $request)
    {
        $restaurant_id = $request->input('restaurant_id');
        $customer_lat = $request->input('customer_lat');
        $customer_lng = $request->input('customer_lng');

        $restaurant = Resturant::findOrFail($restaurant_id);

        // Get all restaurant areas with lat/lng and expected_delivery
        $restaurantAreas = ResturantArea::where('resturant_id', $restaurant_id)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereNotNull('expected_delivery')
            ->get();

        // Check if customer location is within delivery range of any restaurant area
        foreach ($restaurantAreas as $restaurantArea) {
            // Calculate distance between customer location and restaurant area using Haversine formula
            $earthRadius = 6371; // kilometers
            $lat1 = deg2rad($customer_lat);
            $lng1 = deg2rad($customer_lng);
            $lat2 = deg2rad($restaurantArea->lat);
            $lng2 = deg2rad($restaurantArea->lng);

            $latDiff = $lat2 - $lat1;
            $lngDiff = $lng2 - $lng1;

            $a = sin($latDiff / 2) * sin($latDiff / 2) +
                cos($lat1) * cos($lat2) *
                sin($lngDiff / 2) * sin($lngDiff / 2);

            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            $distance = $earthRadius * $c;

            // If customer location is within expected_delivery range (in km), restaurant can deliver
            if ($distance <= $restaurantArea->expected_delivery) {
                return response()->json(['can_deliver' => true]);
            }
        }

        // If no areas are within range, restaurant cannot deliver
        return response()->json(['can_deliver' => false]);
    }

    public function last_order()
    {
        $order = Order::where('type', 'current')->where('user_id', auth('api')->user()->id)->whereNotNull('status')->when(request()->has('status'), function ($q) {
            $q->where('status', request()->status);
        })->latest()->first();
        if ($order) {
            $order_data = OrderResource::make($order);
        } else {
            $order_data = null;
        }
        return $this->successResponse($order_data, __('api.success data'));
    }

}
