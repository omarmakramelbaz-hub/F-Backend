<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Payment\PaymobController;
use App\Http\Controllers\Controller;
use App\Models\Shipping;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\User;
use App\Models\GeneralSettings;
use Illuminate\Http\Request;
use App\Http\Requests\Api\Shipping\ShippingRequest;
use App\Http\Requests\Api\Shipping\ShippingPaymentRequest;
use App\Http\Requests\Api\Shipping\UpdateActualPriceRequest;
use App\Http\Requests\Api\Shipping\AcceptDelegateRequest;
use App\Http\Traits\ApiResponses;
use JWTAuth;
use Validator;
use Auth;
use App\Http\Resources\Api\User\OrderResource;
use App\Http\Resources\Api\Auth\UserDataResource;
use App\Http\Resources\Api\Auth\UserResource;
use App\Http\Resources\Api\ShippingResource;
use Notification;
use Mail;
use App\Events\DelegateUpdated;
use App\Models\DelegateNotification;
use Illuminate\Support\Facades\DB;
class ShippingController extends Controller {

    use ApiResponses;
  
      //  ===================================================================================
      //  =============================================cart==================================
      //  ===================================================================================

    public function store(ShippingRequest $request,GeneralSettings $setting){
        $order=auth('api')->user()->orders()->has('paid')->whereNull('status')->where('type','shipping')->first();

        if($order==null){
            $order=Order::create([
                'user_id'=>auth('api')->user()->id,
                // 'status'=>'pending',
                'type'=>'shipping',
                'order_type'=>'shipping',
                // 'delegate_from_out'=>'out_resturant',
                'delivery_price'=>$request->actual_price,
                'payment_type'=>$request->payment_type
                
            ]);

            $cart= Shipping::create(array_merge([
             'user_id'=>auth('api')->user()->id,
             'order_id'=>$order->id,
             ],$request->except('payment_type')));
             

                if($request->payment_type == 'online'){
                return (new PaymobController)->checkingOut(
                        'paymob_card_payment',
                        env('PAYMOB_CARD_INTEGRATION_ID'),
                        $order->id,
                        env('PAYMOB_CARD_IFRAME_ID'));
             }else if ($request->payment_type == 'v_cash')
             {
                return (new PaymobController)->checkingOut(
                        'paymob_mobile_wallet_payment',
                        env('PAYMOB_MOBILE_WALLET_INTEGRATION_ID'),
                        $order->id,
                        $order->user_address?->mobile);
             }else if($request->payment_type == 'wallet'){
                 if(auth('api')->user()->balance >= $order->grand_total){
                     Wallet::create([
                         'from_user' => auth('api')->user()->id,
                         'to_user' => null,
                         'status' => 'completed',
                         'payment' => 'wallet',
                         'type' => 'transfer',
                         'amount' => $order->grand_total,
                         'order_id'=>$order->id
                         ]);
                    $order->update([
                        'status' => 'pending',
                        ]);
                    $user=auth('api')->user();
                    $user->update(['balance'=>$user->balance-$order->grand_total]);
                     $this->searchDelegates($order->id);
                    // (new \App\Http\Controllers\Dashboard\OrderController)->transferPrice($order->id);
                 }
                 else{
                     return $this->errorResponse(__('api.charge your wallet first'));
                 }
             }elseif($request->payment_type == 'cash'){
                 $order->update([
                        'status' => 'pending',
                        ]);
                    $this->searchDelegates($order->id);

             }
             $order_data=ShippingResource::make($order);
        return $this->successResponse($order_data,__('api.order created successfully'));
        }else{
                    return $this->errorResponse(__('api.complete old order first'));

        }
    
        
       
    }

     
         //  ===================================================================================
         //  =============================================orders================================
        //  ===================================================================================

public function order_payment(ShippingPaymentRequest $request,GeneralSettings $setting){
         $order=auth('api')->user()->orders()->where('type','shipping')->whereNull('status')->first();
        //  dd($order->carts->count());
      
         if($order  && $order->grand_total > 0){
           
             $updated = $order->update($request->input()+[ 'created_at' => now()]);
             $order_data=new OrderResource($order);
              
                
            if($request->payment_type == 'online'){
                return (new PaymobController)->checkingOut(
                        'paymob_card_payment',
                        env('PAYMOB_CARD_INTEGRATION_ID'),
                        $order->id,
                        env('PAYMOB_CARD_IFRAME_ID'));
             }else if ($request->payment_type == 'v_cash')
             {
                return (new PaymobController)->checkingOut(
                        'paymob_mobile_wallet_payment',
                        env('PAYMOB_MOBILE_WALLET_INTEGRATION_ID'),
                        $order->id,
                        $order->user_address?->mobile);
             }else if($request->payment_type == 'wallet'){
                 if(auth('api')->user()->balance >= $order->grand_total){
                     Wallet::create([
                         'from_user' => auth('api')->user()->id,
                         'to_user' => null,
                         'status' => 'completed',
                         'payment' => 'wallet',
                         'type' => 'transfer',
                         'amount' => $order->grand_total,
                         'order_id'=>$order->id
                         ]);
                    $user=auth('api')->user();
                      $order->update([
                        'status' => 'pending',
                        ]);
                    $user->update(['balance'=>$user->balance-$order->grand_total]);
                    return $this->searchDelegates($order->id);
                 }
                 else{
                     return $this->errorResponse(__('api.charge your wallet first'));
                 }
             }elseif($request->payment_type == 'cash'){
                  $order->update([
                        'status' => 'pending',
                        ]);
                return $this->searchDelegates($order->id);

             }
             
            
         }
         return $this->errorResponse(__('api.cannot create order'));
     }


     


    public function searchDelegates($order_id){
        // dd(request()->all());
        $order = Order::where('id',$order_id)->first();
        $latitude = $order->shipping?->from_lat;
        $longitude = $order->shipping?->from_lng;
        $setting=app(GeneralSettings::class);
        // return [$latitude,$longitude];
        $delegates = User::where('connected','active')->where('status','accepted')->where('account_type','delegate')->select(\DB::raw('*, ( 6367 * acos( cos( radians('.$latitude.') ) * cos( radians( lat ) ) * 
          cos( radians( lng ) - radians('.$longitude.') ) + sin( radians('.$latitude.') ) * sin( radians( lat ) ) ) ) AS distance'))
        ->having('distance', '<', 10)
        ->orderBy('distance')->get();
        $order->update(['delegate_id' => null]);
        // dd($delegates);
        //send notification for all delegates
        if($delegates){
        
            foreach($delegates as $key => $value){

                $order->update(['delegate_from_out' => 'out_resturant']);
                $delegte=DelegateNotification::where([
                    'delegate_id' => $value->id,
                    'order_id' => $order->id,
                    ])->delete();
               
                DelegateNotification::create([
                    'delegate_id' => $value->id,
                    'order_id' => $order->id,
                    ]);
                broadcast(new DelegateUpdated($order,1,$value->id));
                Notification::send($value,new \App\Notifications\NotifyDelegatesNewOrderNotification($order));
            }
        } 
       return "success";
    }
    
    
    public function search_deleagates(Request $request){
        // dd(request()->all());
        $latitude = $request->lat;
        $longitude = $request->lng;
        $setting=app(GeneralSettings::class);
        // return [$latitude,$longitude];
        $delegates = User::where('connected','active')->where('account_type','delegate')->select(\DB::raw('*, ( 6367 * acos( cos( radians('.$latitude.') ) * cos( radians( lat ) ) * 
          cos( radians( lng ) - radians('.$longitude.') ) + sin( radians('.$latitude.') ) * sin( radians( lat ) ) ) ) AS distance'))
        ->having('distance', '<', 10)
        ->orderBy('distance')->get();
        // dd($delegates);

        if (request()->wantsJson() || request()->is('api/*')) {
            $usersData=UserDataResource::collection($delegates);
            $order=Order::where('user_id',auth('api')->user()->id)->where('type','shipping')->where('status','pending')->first();
              $order_data=$order?ShippingResource::make($order):null;

            return $this->successResponse(['user_data'=>$usersData,'shipping_order_id'=>$order?$order->id:0,'order_data'=>$order_data,'shipping_min_price_precentage'=>(double)$setting->shipping_min_price,
            'shipping_km_price'=>(double)$setting->shipping_km_price,
            'default_0_1'=>(double)$setting->default_0_1,
            'default_1_2'=>(double)$setting->default_1_2,
            'default_2_3'=>(double)$setting->default_2_3,
            'go_drive_block'=>auth('api')->user()->go_drive_block,
            ],__('api.success data'));
        }else{
            
            return redirect()->back()->with('success_code', 5);
        }
    }
    public function updated_actual_price(UpdateActualPriceRequest $request,GeneralSettings $setting){
        $order=auth('api')->user()->orders()->where('type','shipping')->where('id',$request->order_id)->first();
       if($order){
           if($order->status!='pending'){
                 return $this->errorResponse(__('api.can not update price now'));
           }
           $shipping=$order->shipping;
           $expected_price=$shipping->expected_price;
           $new_price=$expected_price-($expected_price*($setting->shipping_min_price/100));
           if( $request->new_price < $new_price){
               return $this->errorResponse(__('api.price must be greater than or  equal ').$new_price);
           }else{
                    if($order->payment_type!='cash'){
                            $user_price=$shipping->actual_price-$request->new_price;
                            $user_order_owner=$order->user;
                            if($user_price>0 && $user_order_owner){
                                   
                                    $user_order_owner->update(['balance' => $user_order_owner->balance+$user_price]); 
                                     Wallet::create([
                                    'to_user'=>$user_order_owner->id,
                                    'amount'=>$user_price,
                                    'payment'=>'wallet',
                                    'type'=>'transfer',
                                    'order_id' => $order->id,
                                    'status' => 'completed',
                                    ]);
                                    Notification::send($user_order_owner,new \App\Notifications\NotifyOrderPriceTransferToWalletNotification($order,$user_price));
                           }elseif($user_price<0 && $user_order_owner){
                                   $user_price=$request->new_price-$shipping->actual_price;
                                    $user_order_owner->update(['balance' => $user_order_owner->balance-$user_price]); 
                                     Wallet::create([
                                    'from_user'=>$user_order_owner->id,
                                    'amount'=>$user_price,
                                    'payment'=>'wallet',
                                    'type'=>'transfer',
                                    'order_id' => $order->id,
                                    'status' => 'completed',
                                    ]);
                           }
                    }
               $shipping->update(['actual_price'=>$request->new_price]);
               $order->update(['delivery_price'=>$request->new_price]);
               $this->searchDelegates($order->id);
              $order_data=new OrderResource($order);
              return $this->successResponse($order_data,__('api.order updated successfully'));
           }
       
    }else{
         return $this->errorResponse(__('api.order not found'));

    }
    }

    public function get_orders(){
        $orders=Order::whereNotNull('status')->where('user_id',auth('api')->user()->id)->where('type','shipping')
        ->when(request()->has('status'),function($q){
            $q->where('status',request()->status);
        })
        ->orderBy('created_at','desc')->get();
        $order_data=ShippingResource::collection($orders);
            return $this->successResponse($order_data,__('api.success data'));

    }
    public function single_order($id){
        $orders=Order::where('user_id',auth('api')->user()->id)->where('id',$id)->where('type','shipping')->first();
        $order_data=ShippingResource::make($orders);
            return $this->successResponse($order_data,__('api.success data'));

    }
    public function accepted_delegates($order_id){
        $delegate=DelegateNotification::where('order_id',$order_id)->where('status' , 'accepted')->pluck('delegate_id')->toArray();
        $delegates=User::whereIn('id',$delegate)->get();
        $usersData=UserDataResource::collection($delegates);
        $order=Order::find($order_id);
         $order_data=ShippingResource::make($order);
        return $this->successResponse(['delegates'=>$usersData,'order'=>$order_data],__('api.success data'));
    }
    
    public function accept_delegate(AcceptDelegateRequest $request){
        $order=Order::find($request->order_id);
       
            if($request->status=='accepted'){
                 if($order->status=='pending'){
                        $delegate = User::where('account_type','delegate')
                            ->where('id', $request->delegate_id)
                            ->lockForUpdate()
                            ->first();
                        if(!$delegate){
                            return $this->errorResponse(__('api.delegate not found'));
                        }

                        // The delegate's offer is stored on the shipping order before
                        // customer confirmation. Commission is charged only here, after
                        // both sides have agreed to the request.
                        $setting = app(GeneralSettings::class);
                        $finalPrice = (float) optional($order->shipping)->actual_price;
                        $commissionRate = max(0, (float) $setting->shipping_min_price);
                        $commission = round($finalPrice * ($commissionRate / 100), 2);

                        if($commission > 0 && (float) $delegate->balance < $commission){
                            return $this->errorResponse(__('api.charge your wallet first'));
                        }

                        DB::transaction(function () use ($order, $delegate, $commission) {
                            $order->update([
                                'status'=>'accepted',
                                'delegate_id'=>$delegate->id,
                                'delivery_price'=>optional($order->shipping)->actual_price,
                            ]);

                            // Make the accepted driver exclusive; all other offers are closed.
                            DelegateNotification::where('order_id',$order->id)
                                ->where('delegate_id','!=',$delegate->id)
                                ->delete();

                            if($commission > 0){
                                $delegate->decrement('balance', $commission);
                                Wallet::create([
                                    'from_user'=>$delegate->id,
                                    'to_user'=>null,
                                    'status'=>'completed',
                                    'payment'=>'wallet',
                                    'type'=>'transfer',
                                    'amount'=>$commission,
                                    'order_id'=>$order->id,
                                ]);
                            }
                        });

                        $order->refresh();
                        $order_data=ShippingResource::make($order);
                        Notification::send($delegate,new \App\Notifications\NotifyDelegateAfterOrderAcceptedByUser($order));
                        broadcast(new DelegateUpdated($order,1,$delegate->id));
                        broadcast(new ShippingUpdated($order,1,$order->user_id));
                        return $this->successResponse($order_data,__('api.accept order successfully'));
                 }else{
                     return $this->errorResponse(__('api.already accepted delegate'));
                 }
            }else{
              $delegate=DelegateNotification::where('order_id',$request->order_id)->where('delegate_id' , $request->delegate_id)->first();  
              if($delegate){
                  $delegate->delete();
                  $value=User::find($request->delegate_id);
                     broadcast(new DelegateUpdated($order,1,$value->id));

                   Notification::send($value,new \App\Notifications\NotifyDelegatesShippingOrderStatusNotification($order,$request->status));
                    $order_data=ShippingResource::make($order);
                   return $this->successResponse($order_data,__('api.declined order successfully'));
              }
              return $this->errorResponse(__('api.delegate not found'));
            }
        
         
    }
    
    
    
  
}