<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */
// your api is integerated but if you want reintegrate no problem
// to configure jwt-auth visit this link https://jwt-auth.readthedocs.io/en/docs/
use App\Http\Controllers\Payment\PaymobController;

use App\Http\Controllers\Api\V1\Auth\UserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\Auth\VendorController;
use App\Http\Controllers\Api\V1\Auth\DelegateController;
use App\Http\Controllers\Api\V1\Auth\AddressController;
use App\Http\Controllers\Api\V1\User\ConversationController;
use App\Http\Controllers\Api\V1\Home\MainController;
use App\Http\Controllers\Api\V1\Home\CategoryController;
use App\Http\Controllers\Api\V1\Delegate\DelegateOrderController;
use App\Http\Controllers\Api\V1\Vendor\ResturantProductController;
use App\Http\Controllers\Api\V1\Vendor\ResturantController;
use App\Http\Controllers\Api\V1\Vendor\OrderController;
use App\Http\Controllers\Api\V1\Home\CouponWheelController;
use App\Http\Controllers\Api\V1\User\CartController;
use App\Http\Controllers\Api\V1\User\WalletController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\V1\ShippingController;
use App\Http\Controllers\Api\V1\PartnerApplicationController;
use App\Http\Controllers\Api\V1\PartnerServiceRequestController;
use App\Http\Controllers\Api\V1\PartnerEmailAuthController;

    Route::get('/pament/callback', [PaymobController::class, 'callback']);


// Social Auth Routes
Route::group(['prefix' => 'auth/social', 'namespace' => 'Api\V1\Auth'], function () {
    Route::post('{provider}/token', [SocialAuthController::class, 'handleProviderToken']);
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirectToProvider']);
    Route::get('{provider}/callback', [SocialAuthController::class, 'handleProviderCallback']);
    Route::get('back' , [SocialAuthController::class, 'back'])->name('auth.back');
});

Route::group(['namespace'  => 'Api',  'middleware' => ['CheckLang']], function () {

    Route::get('/about-main', [MainController::class, 'getAboutMain']);
    Route::post('/store-contact', [MainController::class, 'storeContact']);
    Route::get('/setting', [MainController::class, 'getSetting']);
    Route::get('/splashes', [MainController::class, 'splashes']);
    Route::get('/areas', [MainController::class, 'areas']);
    Route::get('/help', [MainController::class, 'help']);
    Route::get('/slidears', [MainController::class, 'slidears']);
    Route::get('/advertising', [MainController::class, 'advertising']);
    Route::get('/coupon_wheels', [CouponWheelController::class, 'coupon_wheels']);
    Route::get('/daily-advertising', [MainController::class, 'dailyAdvertising']);

    // GO Partners public onboarding and discovery
    Route::post('/partner-auth/email/request', [PartnerEmailAuthController::class, 'requestCode'])->middleware('throttle:10,1');
    Route::post('/partner-auth/email/verify', [PartnerEmailAuthController::class, 'verifyCode'])->middleware('throttle:20,1');
    Route::post('/partner-auth/password/reset', [PartnerEmailAuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::get('/professions', [PartnerApplicationController::class, 'indexProfessions']);
    Route::post('/partner-applications', [PartnerApplicationController::class, 'store']);
    Route::post('/partner-applications/status', [PartnerApplicationController::class, 'status']);
    Route::post('/partner-applications/activate', [PartnerApplicationController::class, 'activate']);
    Route::get('/professions/{professionKey}/partners', [PartnerApplicationController::class, 'partners']);

    Route::get('/categorys', [CategoryController::class,'getCategorys']);    
    Route::get('/products', [CategoryController::class,'getProducts']);    
    Route::get('/products/{product}', [CategoryController::class,'getSingleProduct']);    

    Route::get('/resturants', [CategoryController::class,'getResturants']);    
    Route::get('/featured_resturants', [CategoryController::class,'getFeaturedResturants']);    
    Route::get('/resturants/{resturant}', [CategoryController::class,'getResturant']);    
    Route::post('/resturants/{resturant}/wishlist', [CategoryController::class,'wishlistResturant']);    
    Route::get('/resturants/last/search', [CategoryController::class,'getLastSearch']);    
    Route::delete('/resturants/last-search/{id}/delete',[CategoryController::class,'deleteSearch']);
    Route::get('/resturants/{resturant}/products', [CategoryController::class,'getResturantProduct']);  
    Route::get('resturants/item/{menue}', [CategoryController::class,'getProduct']);


    // Insert your Api Here Start //
        Route::group(['middleware' => 'guest'], function () {
        Route::group(['prefix' => 'user'],function(){

            Route::post('/register', [UserController::class, 'register']);
            Route::post('/login', [UserController::class, 'login']);
            Route::post('/code_activate', [UserController::class, 'check_code_activate']);
            Route::post('/resend_code', [UserController::class, 'resend_code']);
            Route::post('/access_sms', [UserController::class, 'access_sms']);
            
            Route::post('/check_mobile_has_account', [UserController::class, 'check_mobile_has_account']);

            Route::post('/forget_pass', [UserController::class, 'forget_pass']);
            Route::post('/check_code', [UserController::class, 'check_code_forget_pass']);
            Route::post('/reset_password', [UserController::class, 'reset_password']);
        });
        // ===================================delegate and vendor=================================
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/signup', [AuthController::class, 'register']);
        Route::get('contract/{account_type}',[AuthController::class,'contract']);

       
    });

    Route::group(['middleware' => ['auth:api','app.scope','custom.jwt']], function () {
    
Route::post('/pusher/auth', function (Request $request) {
    if (Auth::check()) {
        $pusher = new Pusher\Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            ['cluster' => env('PUSHER_APP_CLUSTER')]
        );

        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');
        if ($channelName === 'private-user.' . Auth::id()) {
            $auth = $pusher->socket_auth($channelName, $socketId);
            $response = json_decode($auth, true);
            return response()->json([
                'auth' => $response['auth'],
                'channel_data' => [
                    'user_id' => (int) Auth::id()
                ]
            ]);
        }


        return response()->json(['error' => 'Unauthorized'], 403);
    }

    return response()->json(['error' => 'Unauthenticated'], 401);
});    
            Route::get('/getCitiesInCountry', [MainController::class, 'getCitiesInCountry']);
            Route::post('can_deliver',[CartController::class,'canDeliver']);

        Route::get('/notifications', [UserController::class, 'get_notifications']);
                Route::post('send-notification',[NotificationController::class,'send']);
        
        Route::group(['prefix' => 'delegate'],function(){
            Route::post('/connected/update', [UserController::class,'updateConnected']);
            Route::get('/service-requests', [PartnerServiceRequestController::class, 'partnerIndex']);
            Route::post('/service-requests/{serviceRequest}/status', [PartnerServiceRequestController::class, 'updateStatus']);
            Route::get('/orders', [DelegateOrderController::class,'getOrders']);
            Route::get('/orders/{order}', [DelegateOrderController::class,'getSingleOrder']);
            Route::post('accept_decline/orders/{order}', [DelegateOrderController::class,'acceptDeclineOrder']);
            Route::post('orders/{order}/completed', [DelegateOrderController::class,'orderCompleted']);
            Route::get('/reports', [DelegateOrderController::class, 'reports']);
            Route::post('transfer/order/{order}/price',[DelegateOrderController::class,'transfer_order_price']);
        });
    
        Route::group(['prefix' => 'vendor'],function(){

            Route::get('/items', [ResturantProductController::class,'index']);
            Route::post('/items', [ResturantProductController::class,'store']);
            Route::post('/items/{menu}', [ResturantProductController::class,'update']);
            Route::delete('/items/{menu}', [ResturantProductController::class,'destroy']);
            Route::post('/copy/items', [ResturantProductController::class,'copy_menu']);
            Route::get('menu/item/{menu}', [ResturantProductController::class,'show']);
            Route::put('update/menu/item/{menu}/status', [ResturantProductController::class,'update_item_status']);

            Route::post('/resturants/{resturant}', [ResturantController::class,'updateStatus']);
            Route::post('/resturants/{resturant}/update', [ResturantController::class,'updateResturant']);
            Route::post('update/{resturant}/resturant-location',[ResturantController::class,'updateResturantLocation']);

            Route::get('/orders', [OrderController::class,'getOrders']);
            Route::get('/orders/{order}', [OrderController::class,'getSingleOrder']);
            Route::post('/orders/{order}/update', [OrderController::class,'updateOrder']);
            
            Route::get('/search-delegate', [OrderController::class,'searchDelegates']);
            Route::post('update/orders/{order}/status', [OrderController::class,'updateOrderStatus']);
            Route::post('/orders/{order}/accept', [OrderController::class,'acceptOrder']);

            Route::get('/reports', [OrderController::class, 'reports']);
            Route::post('update/orders/{order}/total/price', [OrderController::class,'updateOrderTotalPrice']);
            
            Route::post('transfer/order/{order}/price',[OrderController::class,'transfer_order_price']);


        });
        Route::group(['prefix' => 'user'],function(){
            Route::post('/check-otp-order', [UserController::class, 'checkOtpFirstOrder']);
            Route::get('/partner-service-requests', [PartnerServiceRequestController::class, 'customerIndex']);
            Route::post('/partner-service-requests', [PartnerServiceRequestController::class, 'store']);

            Route::get('/profile', [UserController::class,'userProfile'])->withoutMiddleware('custom.jwt');
            Route::get('/notifications', [UserController::class,'get_notifications']);
            Route::delete('/notifications/{id}', [UserController::class,'deleteNotifications']);

            Route::post('/update-profile', [UserController::class,'userUpdateProfile']);    
            Route::put('/update-profile-avatar', [UserController::class,'userUpdateProfilePhoto']);    
            
            Route::post('update/{user}/user-location',[UserController::class,'updateUserLocation']);

            Route::get('/wishlist', [UserController::class,'userWishlist']);
            
            Route::post('deactivate_account', [UserController::class, 'deactivate_account']);
            Route::post('delete_account', [UserController::class, 'delete_account']);

            Route::get('/conversations', [ConversationController::class,'conversations']);
            Route::get('/conversation/{id}', [ConversationController::class,'conversation']);
            Route::post('send_message', [ConversationController::class,'send_message']);
            Route::get('new_conversation/{id}',[ConversationController::class,'new_conv']);
            
            
            Route::get('address',[AddressController::class,'index']);
            Route::get('address/{id}',[AddressController::class,'show']);
            Route::post('store/address',[AddressController::class,'store']);
            Route::post('update/address/{id}',[AddressController::class,'update']);
            Route::delete('delete/address/{id}',[AddressController::class,'destroy']);



            Route::post('/coupon_subscripes', [CouponWheelController::class, 'coupon_subscripes']); 

            Route::post('product_calculate_price',[CartController::class,'product_calculate_price']);
            Route::post('cart/store',[CartController::class,'store']);
            Route::post('cart/{id}/update/item',[CartController::class,'update_cart_item']);
            Route::get('cart/{id}/show',[CartController::class,'show']);
            Route::post('reorder',[CartController::class,'reorder']);
            Route::post('cart/{id}/update',[CartController::class,'update']);
            Route::get('cart/index',[CartController::class,'index']);
            Route::get('cart/count',[CartController::class,'cart_count']);
            Route::post('order/payment',[CartController::class,'order_payment']);
            Route::get('order/{id}',[CartController::class,'order']);
            Route::get('orders',[CartController::class,'orders']);
            Route::post('cart/remove',[CartController::class,'removeCart']);
            Route::post('cancel/order/{id}',[CartController::class,'cancel_order']);
            Route::get('previous/order/resturant',[CartController::class,'previous_order']);
            Route::get('previous/order/resturant/{id}',[CartController::class,'previous_order_products']);
            Route::post('review/order',[CartController::class,'review_order']);
            Route::post('commission/order/delegate',[CartController::class,'commission_order_delegate']);
            
            Route::post('charging/wallet',[WalletController::class,'charging_wallet'])->withoutMiddleware('custom.jwt');
            Route::get('get/charging/wallet',[WalletController::class,'get_wallet'])->withoutMiddleware('custom.jwt');
            Route::get('last/order',[CartController::class,'last_order']);
        });
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('update-profile/{account_type}',[AuthController::class,'UpdateProfile']);
        Route::post('update-photo/{account_type}',[AuthController::class,'UpdatePhoto']);
        
        Route::post('logout/{account_type?}',[AuthController::class,'logout']);
        Route::get('profile',[AuthController::class,'profile'])->withoutMiddleware('custom.jwt');
        Route::post('update/position',[AuthController::class,'UpdatePosition']);
        Route::post('update/phone',[AuthController::class,'UpdatePhone']);
        // transfer wallet
        Route::post('check/user/transfer',[WalletController::class,'check_user']);
        Route::post('transfer/wallet',[WalletController::class,'transfer_wallet']);
        
        // =====================shipping orders========================
       Route::group(['prefix' => 'shipping'],function(){
           Route::post('new/order',[ShippingController::class,'store']);
           Route::post('order/payment',[ShippingController::class,'order_payment']);
           Route::post('order/update/actual/price',[ShippingController::class,'updated_actual_price']);
           Route::get('search/delegates',[ShippingController::class,'search_deleagates']);
           Route::get('get/orders',[ShippingController::class,'get_orders']);
           Route::get('orders/{id}',[ShippingController::class,'single_order']);
           Route::get('{id}/accepted/delegates',[ShippingController::class,'accepted_delegates']);
           Route::post('accept/delegate',[ShippingController::class,'accept_delegate']);
           
       });
       
    });
    // Insert your Api Here End //
});
