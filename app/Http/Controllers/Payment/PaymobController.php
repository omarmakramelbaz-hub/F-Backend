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
        // Creation only. Signed callbacks/inquiry remain the payment authority.
        $failure = static function (string $code, int $status, bool $uncertain = false) {
            $arabic = app()->getLocale() === 'ar';
            $message = $uncertain
                ? ($arabic ? 'تعذّر تأكيد تجهيز صفحة الدفع. لم يتم تأكيد أي دفع؛ راجع المحفظة قبل المحاولة مجددًا.'
                    : 'Checkout preparation could not be confirmed. No payment is confirmed; check your wallet before trying again.')
                : ($arabic ? 'تعذّر تجهيز صفحة الدفع. راجع بيانات حسابك ووسيلة الدفع ثم حاول مجددًا.'
                    : 'Could not prepare checkout. Check your account details and payment method before trying again.');
            if (auth('api')->check()) {
                return response()->json([
                    'status' => 'Error', 'message' => $message, 'data' => null,
                    'checkout_error' => $code, 'checkout_outcome_uncertain' => $uncertain,
                ], $status);
            }
            return redirect()->back()->with(['error' => $message]);
        };

        $order = Order::findOrFail($order_id);
        $user = Auth::user();
        if (!$user) return $failure('authentication_required', 401);
        if (!preg_match('/\A[1-9][0-9]{0,14}\z/', (string) $integration_id)) {
            return $failure('invalid_payment_method', 422);
        }
        // Parse the server-owned decimal directly: ten whole digits, at most
        // two decimal places, and integer arithmetic only. Do not depend on a
        // shared utility that may differ between deployed server revisions.
        $amount = $order->grand_total;
        if (!(is_string($amount) || is_int($amount) || is_float($amount))
            || !preg_match('/\A(-?)([0-9]{1,10})(?:\.([0-9]{1,2}))?\z/', trim((string) $amount), $amountParts)) {
            return $failure('invalid_amount', 422);
        }
        $amountCents = ((int) $amountParts[2]) * 100
            + (int) str_pad($amountParts[3] ?? '', 2, '0', STR_PAD_RIGHT);
        if ($amountParts[1] === '-') $amountCents = -$amountCents;
        if ($amountCents <= 0) return $failure('invalid_amount', 422);
        $amountDecimal = intdiv($amountCents, 100) . '.'
            . str_pad((string) ($amountCents % 100), 2, '0', STR_PAD_LEFT);

        // These placeholders are filled privately from the exact guarded live
        // baseline, never from package contents, output, or request parameters.
        $paymobAuthorization = 'Token egy_sk_live_71b7c9d07765512751d560dd95ae32c68b5324fca323bdeace5098ffee2bd0a3';
        $paymobPublicKey = 'egy_pk_live_dJsMt2Cx3qwi11KgJlNmXpoQnouGYyyX';
        $names = preg_split('/\s+/', trim((string) $user->name), 2);
        $firstName = $names[0] ?? 'NA';
        $lastName = $names[1] ?? $firstName;
        $payload = [
            'expiration' => 36000,
            'amount' => $amountCents,
            'currency' => 'EGP',
            'payment_methods' => [(int) $integration_id],
            // Preserve the existing merchant correlation and callback consumers.
            'metadata' => ['merchant_order_id' => $order->id],
            'billing_data' => [
                'first_name' => $firstName ?: 'NA', 'last_name' => $lastName ?: 'NA',
                'phone_number' => $user->mobile ?: 'NA', 'email' => $user->email ?: 'NA',
                'apartment' => 'NA', 'floor' => 'NA', 'street' => 'NA', 'building' => 'NA',
                'shipping_method' => 'NA', 'postal_code' => 'NA', 'city' => 'NA',
                'state' => 'NA', 'country' => 'NA',
            ],
        ];
        $base = rtrim((string) config('app.url'), '/');
        if ($order->type === 'wallet') {
            $payload['notification_url'] = $base . '/api/wallet-topup/paymob/notify';
            $payload['redirection_url'] = url('/api/wallet-topup/paymob/return'); // back to the domain the app called
        } elseif ($order->type === 'current') {
            $payload['notification_url'] = $base . '/api/order-payments/paymob/notify';
            $payload['redirection_url'] = url('/api/order-payments/paymob/return'); // back to the domain the app called
        }

        try {
            // Intention already creates its Paymob order. Never replay this POST
            // automatically: a lost response does not prove creation failed.
            $response = Http::withHeaders([
                'Content-Type' => 'application/json', 'Accept' => 'application/json',
                'Authorization' => $paymobAuthorization,
            ])->withOptions([
                'connect_timeout' => 3, 'timeout' => 15, 'allow_redirects' => false,
            ])->post('https://accept.paymob.com/v1/intention/', $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            return $failure('gateway_timeout', 504, true);
        } catch (\Throwable $error) {
            return $failure('gateway_unavailable', 502, true);
        }
        if (!$response->successful()) {
            return $failure('gateway_rejected', 502, $response->status() >= 500);
        }
        try {
            $result = $response->json();
        } catch (\Throwable $error) {
            return $failure('invalid_gateway_response', 502, true);
        }
        $clientSecret = is_array($result) ? ($result['client_secret'] ?? null) : null;
        $gatewayOrder = is_array($result) ? ($result['intention_order_id'] ?? null) : null;
        $detail = is_array($result) ? ($result['intention_detail'] ?? null) : null;
        $gatewayAmount = is_array($detail) ? ($detail['amount'] ?? null) : null;
        // JSON permits integral numbers encoded as floats. Compare only against
        // the bounded exact integer cents; never round or convert gateway money.
        $amountMatches = in_array($gatewayAmount, [$amountCents, (string) $amountCents], true)
            || (is_float($gatewayAmount) && is_finite($gatewayAmount) && $gatewayAmount == $amountCents);
        if (!is_string($clientSecret) || !preg_match('/\A[A-Za-z0-9_-]{10,512}\z/', $clientSecret)
            || !(is_int($gatewayOrder) || is_string($gatewayOrder))
            || !preg_match('/\A[1-9][0-9]{0,29}\z/', (string) $gatewayOrder)
            || !is_array($detail) || ($detail['currency'] ?? null) !== 'EGP'
            || !$amountMatches) {
            return $failure('invalid_gateway_response', 502, true);
        }
        try {
            Payment::create([
                'order_id' => $order->id, 'user_id' => $order->user_id,
                'intention_order_id' => $gatewayOrder,
                'total_price' => $amountDecimal,
            ]);
        } catch (\Throwable $error) {
            return $failure('checkout_record_unavailable', 503, true);
        }
        $link = 'https://accept.paymob.com/unifiedcheckout/?publicKey='
            . rawurlencode($paymobPublicKey) . '&clientSecret=' . rawurlencode($clientSecret);
        if (auth('api')->check()) {
            return $this->successResponse(['order_id' => $order->id, 'link' => $link], __('api.wallet charge successfully'));
        }
        return redirect($link);
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
            try {
                app(\App\Services\Payments\LegacyPaymentFailure::class)->record($request);
            } catch (\Throwable $exception) {
                // Failure evidence must not block the existing customer redirect.
                \Log::warning('Paymob failure evidence could not be recorded', ['exception' => get_class($exception)]);
            }
            // dd($payment_details,$request);
                    return redirect()->route('payFailed');

            // return $this->errorResponse('error in pay');
        }
    }

}
