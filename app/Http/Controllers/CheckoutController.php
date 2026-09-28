<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Payment;
use App\Models\Order;
use App\Models\Wallet;
use Mail;
use Auth;
use App\Http\Traits\ApiResponses;
use Notification;
use App\Http\Controllers\Dashboard\OrderController;
            use App\Events\OrderUpdated;
            use App\Models\GeneralSettings;
             use App\Events\VendorUpdated;
class CheckoutController extends Controller
{
    use ApiResponses;
    public function __construct()
    {
        //
    }

    //redirects to this method after a successfull checkout
    public function checkout_done($order_id, $payment)
    {
         // GO purchases can only be settled by verified Paymob callbacks/inquiry.
         if (\Illuminate\Support\Facades\Schema::hasTable('go_order_payments') && \Illuminate\Support\Facades\DB::table('go_order_payments')->where('gateway_order_id',(string)$order_id)->exists()) {
             return redirect()->route('go-orders.payment-return');
         }
         $pay = Payment::where('intention_order_id',$order_id)->first();
        $order = Order::findOrFail($pay->order_id);
        if($order->resturant_id){
            $setting=app(GeneralSettings::class);
             $resturant=$order->resturant;  
             $vendor_tax=$order->total*($resturant->service_fees/100);
             $tax=$vendor_tax*($setting->tax/100);
             $user_tax=$order->total*($setting->tax/100);
            $updated = $order->update(['tax'=>$tax,'vendor_tax'=>$vendor_tax,'user_tax'=>$user_tax,'resturant_id'=>$order->carts()->first()->resturant_id, 'created_at' => now()]);
            $orderCount = $order->resturant->orders()->where('status','pending')->whereDate('created_at', '=', now()->toDateString())->count();

           
 

// Broadcast the orders using Pusher
broadcast(new OrderUpdated($order->id,$orderCount,$order->user_id));
              $resturant_owner = User::whereHas('base_resturant',function($q) use ($order){
                    $q->where('id',$order->resturant_id);
                })->first();

                             broadcast(new VendorUpdated($order->id,$orderCount,$resturant_owner->id));
// for resturant owner dashboard
 broadcast(new OrderUpdated($order->id,$orderCount,$resturant_owner->id));
        }
         $resturant_owner = User::whereHas('base_resturant',function($q) use ($order){
                    $q->where('id',$order->resturant_id);
                })->first();
        $data = $pay->update([
            // 'order_id' => $order->id,
            'user_id' => $order->user_id,
            'status' => json_decode($payment)->success,
            'transaction_id' => json_decode($payment)->id,
            ]);
        if($pay->status == true){
            $order->update(['status' => 'pending']);
        }else{
            $order->update(['status' => null]);
        }
        if($order->type == 'wallet'){
            // dd('cvb');
            $amount = $order->wallet?->amount;
            $wallet=$order->wallet;
            $wallet->update(['status'=>'completed']);
            $user_balance = $order->user?->balance + $amount;
            $user=User::findOrFail($order->user_id);
            $user->update(['balance' => $user_balance]);
            if($user->account_type == 'vendor' && $user->balance >= $user->min_wallet && ($user->base_resturant?->status == 'disabled' || $user->base_resturant?->status == 'closed')){
                Notification::send($user,new \App\Notifications\NotifyResturantOpenAgainNotification($user));
                 $up = $user->base_resturant?->update(['status' => 'closed']);  
                 $up = $user->update(['status' => 'accepted','decline_reason' => null, 'expiration_date' => null]);
            }elseif($user->account_type == 'delegate' && $user->balance >= $user->min_wallet && $user->status == 'disabled'){
                 Notification::send($user,new \App\Notifications\NotifyDelegateStatusNotification($user));
                  $up = $user->update(['status' => 'accepted','decline_reason' => null, 'expiration_date' => null]);
            }
            
        }
        Wallet::where('id', $order->wallet_id)->update(['status' => 'completed']);
                  //send notification for resturant has new order
               
        if($order->type == 'current'){
           if($resturant_owner){
                            Notification::send($resturant_owner,new \App\Notifications\NotifyResturantOrderCreatedNotification($order));
                        }
                        $email = $order->user?->email;
                        if($email){
                            Mail::send('emails.send_order_email', ['email' => $email, 'cart' => $order], function ($message) use ($email) {
                    			$message->to($email);
                    			$message->subject('Your order has been received!');
                    
                    	    });
                        }
        }
        
        if($order->type=='shipping'){
           (new \App\Http\Controllers\Api\V1\ShippingController)->searchDelegates($order->id);

        }
        return redirect()->route('paySuccess');
        // return $this->successResponse(true, 'done');
    }
}
