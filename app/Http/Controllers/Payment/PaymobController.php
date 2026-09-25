<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\Payment;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Http\Traits\ApiResponses;
use App\Http\Resources\Api\User\OrderResource;

class PaymobController extends Controller
{
    use ApiResponses;
    /**
     * Display checkout page.
     *
     * @param $payment_method
     * @param $integration_id
     * @param $order_id
     * @param $iframe_id_or_wallet_number
     * @return RedirectResponse
     */
  
    public function checkingOut($payment_method, $integration_id, $order_id, $iframe_id_or_wallet_number)
    {
        // step 1: login to paymob
        $response = Http::withHeaders([
            'content-type' => 'application/json'
        ])->post('https://accept.paymob.com/api/auth/tokens',[
            "api_key"=> env('PAYMOB_API_KEY')
        ]);
        $json=$response->json();       
$token = $json['token'];
        $order = Order::findOrFail($order_id);
        // $grand_total = $order->total+$order->delivery_price??0;
             $order_data=$order->user_tax;
        \Log::info($order_data);
        // step 2: send order data
        $response_final=Http::withHeaders([
            'content-type' => 'application/json'
        ])->post('https://accept.paymob.com/api/ecommerce/orders',[
            "auth_token"=>$json['token'],
            "delivery_needed"=>"false",
            "amount_cents"=>ceil($order->grand_total*100),
            "merchant_order_id" => $order->id
        ]);

        $json_final=$response_final->json();
        $json['token'] = 'Token egy_sk_live_71b7c9d07765512751d560dd95ae32c68b5324fca323bdeace5098ffee2bd0a3';
         if (isset($json_final['message']) && $json_final['message'] === 'duplicate') {
            //  dd($json_final);
            // Fetch existing order details from Paymob
            $existing_order = Http::withHeaders([
                'content-type' => 'application/json',
                'Authorization' => $json['token'] // Add token in the header
            ])->get('https://accept.paymob.com/api/ecommerce/orders', [
                "merchant_order_id" => $order->id
            ])->json();
            $existing_order = Http::withHeaders([
                'content-type' => 'application/json',
                'Authorization' => 'Bearer ' . $token // Add token in the header

            ])->get('https://accept.paymob.com/api/ecommerce/orders', [
                "merchant_order_id" => $order->id
            ])->json();

// dd($existing_order);
            $paymob_order_id = $existing_order['results'][0]['id'];
                        $amount_cents = ceil($order->grand_total*100);
            // $amount_cents = $existing_order['results'][0]['amount_cents'];
        }else{
            $paymob_order_id = $json_final['id'];
            $amount_cents = $json_final['amount_cents'];
        }
        // dd($json_final,$order->grand_total*100,$order->id);
        \Log::info(ceil($order->grand_total*100));
        $user = Auth::user();
        $name = $user->name;
        if ((count(explode(" ",$name)) == 1)) {
            $first_name = $name;$last_name=$name;
        } else {
            $first_name = explode(" ",$name)[0];
            $last_name = explode(" ",$name)[1];
        }
        // dd($amount_cents);
        //  step 3: send customer data
        $response_final_final=Http::withHeaders([
            'content-type' => 'application/json',
            "Authorization"=>$json['token'],
        ])->post('https://accept.paymob.com/v1/intention',[
            "expiration"=> 36000,
            "amount"=>$amount_cents,
            "payment_methods" =>[(int)$integration_id],
            "metadata"=>[
                "merchant_order_id"=> $order->id,  // Your custom order ID
            ],
            "billing_data"=>[
                "first_name"            => $first_name ?: "NA",
                "last_name"             => $last_name ?: "NA",
                "phone_number"          => $user->mobile ?: "NA",
                "email"                 => $user->email ?: "NA",
                "apartment"             => "NA",
                "floor"                 => "NA",
                "street"                => "NA",
                "building"              => "NA",
                "shipping_method"       => "NA",
                "postal_code"           => "NA",
                "city"                  => "NA",
                "state"                 => "NA",
                "country"               => "NA",
            ],
            "currency"=>"EGP",
            "integration_id"=>$integration_id
        ]);

        $response_final_final_json=$response_final_final->json();
        // dd($response_final_final_json);
        $client_secret=$response_final_final_json['client_secret'];
        $intention_order_id = $response_final_final_json['intention_order_id'];
        if($client_secret){
            $pay = Payment::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'intention_order_id' => $intention_order_id,
            ]);
            if(auth('api')->check()){
            return $this->successResponse(['order_id' => $order->id ,'link' => 'https://accept.paymob.com/unifiedcheckout/?publicKey=egy_pk_live_dJsMt2Cx3qwi11KgJlNmXpoQnouGYyyX&clientSecret='.$client_secret],__('api.wallet charge successfully'));
            }
            return redirect('https://accept.paymob.com/unifiedcheckout/?publicKey=egy_pk_live_dJsMt2Cx3qwi11KgJlNmXpoQnouGYyyX&clientSecret='.$client_secret);
                
        }
       
    }
    
    // public function checkingOut($payment_method, $integration_id, $order_id, $iframe_id_or_wallet_number)
    // {
    //     // step 1: login to paymob
    //     $response = Http::withHeaders([
    //         'content-type' => 'application/json'
    //     ])->post('https://accept.paymob.com/api/auth/tokens',[
    //         "api_key"=> env('PAYMOB_API_KEY')
    //     ]);
    //     $json=$response->json();       

    //     $order = Order::findOrFail($order_id);
    //     // $grand_total = $order->total+$order->delivery_price??0;

    //     // step 2: send order data
    //     $response_final=Http::withHeaders([
    //         'content-type' => 'application/json'
    //     ])->post('https://accept.paymob.com/api/ecommerce/orders',[
    //         "auth_token"=>$json['token'],
    //         "delivery_needed"=>"false",
    //         "amount_cents"=>ceil($order->grand_total*100),
    //         "merchant_order_id" => $order->id
    //     ]);

    //     $json_final=$response_final->json();
        
    //      if (isset($json_final['message']) && $json_final['message'] === 'duplicate') {
    //         // Fetch existing order details from Paymob
    //         $existing_order = Http::withHeaders([
    //             'content-type' => 'application/json',
    //             'Authorization' => 'Bearer ' . $json['token'] // Add token in the header

    //         ])->get('https://accept.paymob.com/api/ecommerce/orders', [
    //             "merchant_order_id" => $order->id
    //         ])->json();

    //         $paymob_order_id = $existing_order['results'][0]['id'];
    //         $amount_cents = $existing_order['results'][0]['amount_cents'];
    //     }else{
    //         $paymob_order_id = $json_final['id'];
    //         $amount_cents = $json_final['amount_cents'];
    //     }
    //     // dd($json_final,$order->grand_total*100,$order->id);
    //     \Log::info(ceil($order->grand_total*100));
    //     $user = Auth::user();
    //     $name = $user->name;
    //     if ((count(explode(" ",$name)) == 1)) {
    //         $first_name = $name;$last_name=$name;
    //     } else {
    //         $first_name = explode(" ",$name)[0];
    //         $last_name = explode(" ",$name)[1];
    //     }
    //     //  step 3: send customer data
    //     $response_final_final=Http::withHeaders([
    //         'content-type' => 'application/json'
    //     ])->post('https://accept.paymob.com/api/acceptance/payment_keys',[
    //         "auth_token"=>$json['token'],
    //         "expiration"=> 36000,
    //         "amount_cents"=>$amount_cents,
    //         "order_id"=>$paymob_order_id,
    //         "billing_data"=>[
    //             "first_name"            => $first_name ?: "NA",
    //             "last_name"             => $last_name ?: "NA",
    //             "phone_number"          => $user->mobile ?: "NA",
    //             "email"                 => $user->email ?: "NA",
    //             "apartment"             => "NA",
    //             "floor"                 => "NA",
    //             "street"                => "NA",
    //             "building"              => "NA",
    //             "shipping_method"       => "NA",
    //             "postal_code"           => "NA",
    //             "city"                  => "NA",
    //             "state"                 => "NA",
    //             "country"               => "NA",
    //         ],
    //         "currency"=>"EGP",
    //         "integration_id"=>$integration_id
    //     ]);

    //     $response_final_final_json=$response_final_final->json();
    //     // dd($response_final_final_json);
    //     if ($payment_method == 'paymob_mobile_wallet_payment') {
    //         $response_iframe =Http::withHeaders([
    //             'content-type' => 'application/json'
    //         ])->post('https://accept.paymob.com/api/acceptance/payments/pay',[
    //             "source"=>[
    //                 // "identifier"=> $iframe_id_or_wallet_number,
    //             "identifier"=> "01010101010",
    //                 "subtype"=> "WALLET"
    //             ],
    //             "payment_token"=>$response_final_final_json['token'],
    //         ]);
    //         // dd($response_iframe);
    //         if(auth('api')->check()){
    //             $link = $response_iframe->json()['redirect_url'];
    //             return $this->successResponse(['link' => $link,'order_id' => $order->id], __('api.link payment'));
    //         }
    //         return redirect($response_iframe->json()['redirect_url']);
    //     } else {
    //         if(auth('api')->check()){
    //             $link = 'https://accept.paymob.com/api/acceptance/iframes/'. $iframe_id_or_wallet_number .'?payment_token=' . $response_final_final_json['token'] ;
    //             return $this->successResponse(['link'=>$link,'order_id'=>$order->id], __('api.link payment'));
    //         }
    //         return redirect('https://accept.paymob.com/api/acceptance/iframes/'. $iframe_id_or_wallet_number .'?payment_token=' . $response_final_final_json['token']);
    //     }
    // }
    
//     public function checkingOut($payment_method, $integration_id, $order_id, $iframe_id_or_wallet_number)
// {
//     // Step 1: Login to Paymob
//     $response = Http::withHeaders([
//         'content-type' => 'application/json'
//     ])->post('https://accept.paymob.com/api/auth/tokens', [
//         "api_key" => env('PAYMOB_API_KEY')
//     ]);
//     $json = $response->json();

//     if (!isset($json['token'])) {
//         return response()->json(['error' => 'Failed to authenticate with Paymob'], 500);
//     }

//     // Step 2: Get the order from the database
//     $order = Order::findOrFail($order_id);

//     // Step 3: Check for an existing Paymob order
//     if ($order->id) {
//         // If a Paymob order already exists, reuse it
//         $paymob_order_id = $order->id;
//         $amount_cents = ceil($order->grand_total * 100);
//     } else {
//         // Create a unique merchant order ID
//         $unique_merchant_order_id = $order->id . '_' . time();

//         // Step 4: Send the order data to Paymob
//         $response_final = Http::withHeaders([
//             'content-type' => 'application/json'
//         ])->post('https://accept.paymob.com/api/ecommerce/orders', [
//             "auth_token" => $json['token'],
//             "delivery_needed" => "false",
//             "amount_cents" => ceil($order->grand_total * 100),
//             "merchant_order_id" => $unique_merchant_order_id
//         ]);

//         $json_final = $response_final->json();

//         // Handle duplicate error
//         if (isset($json_final['message']) && $json_final['message'] === 'duplicate') {
//             // Fetch existing order details from Paymob
//             $existing_order = Http::withHeaders([
//                 'content-type' => 'application/json'
//             ])->get('https://accept.paymob.com/api/ecommerce/orders', [
//                 "auth_token" => $json['token'],
//                 "merchant_order_id" => $unique_merchant_order_id
//             ])->json();

//             $paymob_order_id = $existing_order['id'];
//             $amount_cents = $existing_order['amount_cents'];
//         } else {
//             $paymob_order_id = $json_final['id'];
//             $amount_cents = $json_final['amount_cents'];

//             // Save the Paymob order ID in the database
//             $order->update(['paymob_order_id' => $paymob_order_id]);
//         }
//     }

//     // Step 5: Send customer billing data
//     $user = Auth::user();
//     $name = $user->name;
//     $first_name = explode(" ", $name)[0] ?? "NA";
//     $last_name = explode(" ", $name)[1] ?? $first_name;

//     $response_final_final = Http::withHeaders([
//         'content-type' => 'application/json'
//     ])->post('https://accept.paymob.com/api/acceptance/payment_keys', [
//         "auth_token" => $json['token'],
//         "expiration" => 36000,
//         "amount_cents" => $amount_cents,
//         "order_id" => $paymob_order_id,
//         "billing_data" => [
//             "first_name" => $first_name ?: "NA",
//             "last_name" => $last_name ?: "NA",
//             "phone_number" => $user->mobile ?: "NA",
//             "email" => $user->email ?: "NA",
//             "apartment" => "NA",
//             "floor" => "NA",
//             "street" => "NA",
//             "building" => "NA",
//             "shipping_method" => "NA",
//             "postal_code" => "NA",
//             "city" => "NA",
//             "state" => "NA",
//             "country" => "NA",
//         ],
//         "currency" => "EGP",
//         "integration_id" => $integration_id
//     ]);
// dd($response_final_final);

//     $response_final_final_json = $response_final_final->json();
//     if (!isset($response_final_final_json['token'])) {
//         return response()->json(['error' => 'Failed to generate payment token'], 500);
//     }

//     // Step 6: Handle payment method
//     if ($payment_method == 'paymob_mobile_wallet_payment') {
//         // Mobile wallet payment
//         $response_iframe = Http::withHeaders([
//             'content-type' => 'application/json'
//         ])->post('https://accept.paymob.com/api/acceptance/payments/pay', [
//             "source" => [
//                 "identifier" => "01010101010",
//                 "subtype" => "WALLET"
//             ],
//             "payment_token" => $response_final_final_json['token'],
//         ]);

//         if (auth('api')->check()) {
//             $link = $response_iframe->json()['redirect_url'];
//             return $this->successResponse(['link' => $link, 'order_id' => $order->id], __('api.link payment'));
//         }

//         return redirect($response_iframe->json()['redirect_url']);
//     } else {
//         // Card payment using iframe
//         if (auth('api')->check()) {
//             $link = 'https://accept.paymob.com/api/acceptance/iframes/' . $iframe_id_or_wallet_number . '?payment_token=' . $response_final_final_json['token'];
//             return $this->successResponse(['link' => $link, 'order_id' => $order->id], __('api.link payment'));
//         }

//         return redirect('https://accept.paymob.com/api/acceptance/iframes/' . $iframe_id_or_wallet_number . '?payment_token=' . $response_final_final_json['token']);
//     }
// }

    public function callback(Request $request)
    {
        $payment_details = json_encode($request->all());
                    // return (new CheckoutController)->checkout_done($request->merchant_order_id, $payment_details);
        if ($request->success === "true")
        {
            
            // dd($request->order);

            return (new CheckoutController)->checkout_done($request->order, $payment_details);

        } else {
            // dd($payment_details,$request);
                    return redirect()->route('payFailed');

            // return $this->errorResponse('error in pay');
        }
    }

}