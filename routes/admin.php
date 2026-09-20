<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\AdminsController;
use App\Http\Controllers\Dashboard\PendingVendorController;
use App\Http\Controllers\Dashboard\RolesController;
use App\Http\Controllers\Dashboard\OrderController;
use App\Http\Controllers\Dashboard\ComplaintController;
use App\Http\Controllers\Dashboard\UserController;
use App\Http\Controllers\Dashboard\ProductController;
use App\Http\Controllers\Dashboard\ResturantController;
use App\Http\Controllers\Dashboard\CategoryController;
use App\Http\Controllers\Dashboard\ContractController;
use App\Http\Controllers\Dashboard\ContactController;
use App\Http\Controllers\Dashboard\FeatureController;
use App\Http\Controllers\Dashboard\BannerController;
use App\Http\Controllers\Dashboard\BlogController;
use App\Http\Controllers\Dashboard\SubscriberController;
use App\Http\Controllers\Dashboard\ReportController;
use App\Http\Controllers\Dashboard\QuestionAnswerController;
use App\Http\Controllers\Dashboard\AreaController;
use App\Http\Controllers\Dashboard\SlidearController;
use App\Http\Controllers\Dashboard\FcmNotificationsController;
use App\Http\Controllers\Dashboard\AdvertisingController;
use App\Http\Controllers\Dashboard\WalletController;
use App\Http\Controllers\Dashboard\CouponWheelController;
use App\Http\Traits\FcmFirebase;

Route::get('/clear-cache-admin', function() {
    $exitCode0 = Artisan::call('route:clear');
    $exitCode1 = Artisan::call('view:clear');
    // $exitCode3 = Artisan::call('config:cache');
    $exitCode4 = Artisan::call('cache:clear');
    $exitCode2 = Artisan::call('config:clear');
    $exitCode = Artisan::call('clear-compiled');
    
    return 'done';
});
Route::get('test-notification' , [FcmNotificationsController::class , 'testNotification']);
Route::get('/change-language/{lang}', [HomeController::class,'changeLang'])->middleware('lang');
Route::get('/generate-qr/{ticket}', [HomeController::class, 'generatQr'])->name('generate-qr');
Route::get('download-daily-report-pdf', [OrderController::class,'download_daily_report'])->name('download-daily-report-pdf');
  Route::get('/broadcasting/auth', function () {
            return response()->json('Auth route working');
        })->middleware('IsAdmin');

Route::group(['prefix' => 'admin', 'middleware' => 'lang'], function () {
Route::post('save-token', [FcmNotificationsController::class, 'SaveToken']);
Route::post('send_chat_notification', [FcmNotificationsController::class, 'send_chat_notification']);


    Route::get('/login', [HomeController::class, 'loginPage'])->middleware('adminGuest');
    Route::post('/signin', [HomeController::class, 'signin'])->name('admin.login')->middleware('adminGuest');

    Route::group([ 'middleware' => 'IsAdmin'], function () {
        
        Route::get('/chat', [FcmNotificationsController::class, 'chat']);
        
                        Route::get('/resturantControl', [HomeController::class, 'resturantControl'])->name('resturantControl');
        Route::get('/getOrders', [OrderController::class, 'getOrders'])->name('getOrders');
       
        Route::post('/updateorders/{order}', ['App\Http\Controllers\Api\V1\Vendor\OrderController','updateOrder'])->name('vendor.updateOrder');
        Route::post('/acceptOrder/{order}', ['App\Http\Controllers\Api\V1\Vendor\OrderController','acceptOrder'])->name('vendor.acceptOrder');
        
        Route::post('update/orders/{order}', ['App\Http\Controllers\Api\V1\Vendor\OrderController','updateOrderStatus'])->name('vendor.updateOrderStatus');
        Route::get('/resturant-reports', ['App\Http\Controllers\Api\V1\Vendor\OrderController', 'reports'])->name('vendor.report');
        Route::get('/vendororders/{order}', ['App\Http\Controllers\Api\V1\Vendor\OrderController','getSingleOrder'])->name('vendor.getSingleOrder');
        Route::post('charging/wallet',['App\Http\Controllers\Api\V1\User\WalletController','charging_wallet'])->name('vendor.charging_wallet');
            Route::get('get/charging/wallet',['App\Http\Controllers\Api\V1\User\WalletController','get_wallet'])->name('vendor.get_wallet');
        Route::post('update/phone',['App\Http\Controllers\Api\V1\Auth\AuthController','UpdatePhone'])->name('vendor.UpdatePhone');
            Route::post('update/orders/{order}/total/price', ['App\Http\Controllers\Api\V1\Vendor\OrderController','updateOrderTotalPrice'])->name('updateOrderTotalPrice');

        Route::get('/adminLogout', [HomeController::class, 'adminLogout']);
        Route::resource('/fcm_notifications', FcmNotificationsController::class);
        Route::get('/choose_type', [HomeController::class, 'chooseType'])->name('chooseType');
        Route::get('/choose_type/change', [HomeController::class, 'chooseTypeChange'])->name('chooseTypeChange');
        Route::get('/admin_wallet', [HomeController::class, 'adminWallet'])->name('adminWallet');

        // Route::get('/coupon_wheels/{coupon_wheel}', [HomeController::class, 'couponWheel'])->name('couponWheel');
        // Route::post('/coupon_wheels/{coupon_wheel}/update', [HomeController::class, 'couponWheelUpdate'])->name('couponWheelUpdate');
        Route::resource('/coupon_wheels', CouponWheelController::class);
        Route::delete('/coupon_wheelsDeleteAll', [CouponWheelController::class,'deleteAll']);

        Route::get('/dashboard', [HomeController::class, 'index'])->name('admin_dash');
        Route::get('/notifications', [HomeController::class, 'notifications'])->name('notifications');
        Route::put('/read/{id}', [HomeController::class, 'read'])->name('read_notify');
        Route::get('/bulk-notifications', [HomeController::class, 'bulk_notifications'])->name('bulk-notifications');
        Route::post('/for-send-notify', [HomeController::class,'sendNotify'])->name('notifications.sendNotify');
        Route::get('read/all/notification',[HomeController::class,'mark_all_as_read'])->name('mark_all_as_read');

        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings/update', [SettingsController::class, 'update'])->name('updateSetting');
        Route::get('/env-setting', [SettingsController::class, 'enviroSetting']);
        Route::put('/settings/updateEnv', [SettingsController::class, 'updateEnv'])->name('updateEnv');
        Route::put('/settings/updatePaymentActivation', [SettingsController::class, 'updatePaymentActivation'])->name('updatePaymentActivation');

        Route::get('/advertising', [SettingsController::class, 'advertising']);
        Route::put('/advertising/update', [SettingsController::class, 'update_advertising'])->name('updateAdvertising');

        Route::resource('/roles', RolesController::class);
        Route::delete('rolesDeleteAll', [RolesController::class,'deleteAll']);

        Route::resource('/admins', AdminsController::class);
        Route::delete('adminsDeleteAll', [AdminsController::class,'deleteAll']);
        
        Route::resource('/pending_vendors', PendingVendorController::class);
        Route::delete('pending_vendorsDeleteAll', [PendingVendorController::class,'deleteAll']);
        Route::get('pending_vendors/{pending}/addVendor', [PendingVendorController::class,'addVendor'])->name('pending_vendors.addVendor');

        Route::post('pending_vendors/transferVendor', [PendingVendorController::class,'transferVendor'])->name('pending_vendors.transferVendor');
        Route::post('pending_vendors/{pending_vendor}/approve-partner', [PendingVendorController::class,'approvePartner'])->name('pending_vendors.approvePartner');
        Route::post('sending-decline-mail/{pending_vendor}', [PendingVendorController::class,'sendingDeclineMail'])->name('sendingDeclineMail');

        Route::resource('/advertisings', AdvertisingController::class);
        Route::delete('advertisingsDeleteAll', [AdvertisingController::class,'deleteAll']);
        Route::delete('advertisings/del/image',[AdvertisingController::class,'delete_image']);

        Route::resource('/categorys', CategoryController::class);
        Route::delete('categorysDeleteAll', [CategoryController::class,'deleteAll']);
        Route::get('categorys/{id}/services/prices',[CategoryController::class,'edit_services_prices'])->name('categorys.services.prices');
        Route::put('categorys/{id}/update/services/prices',[CategoryController::class,'update_services_prices'])->name('categorys.services.prices.update');
        Route::post('post-sortable',[CategoryController::class,'updateColumns']);

        Route::resource('/contracts', ContractController::class);
        Route::get('pdfview-contract', [ContractController::class,'pdfviewContract']);

        Route::resource('/orders', OrderController::class);
        Route::delete('ordersDeleteAll', [OrderController::class,'deleteAll']);
        Route::post('ordersChangeStatus/{order}', [OrderController::class,'changeStatus'])->name('orders.change_status');
        Route::post('ordersTransferPrice/{order}', [OrderController::class,'transferPrice'])->name('orders.transfer_price');
        Route::get('applies-orders', [OrderController::class,'applies'])->name('orders.applies');
        Route::get('cancel/order/{id}/delegate', [OrderController::class,'cancel_order_delegate'])->name('orders.cancel_order_delegate');
        Route::get('/fetch-product',[OrderController::class,'fetchProduct'])->name('fetch-product');

        Route::resource('/products', ProductController::class);
        Route::delete('productsDeleteAll', [ProductController::class,'deleteAll']);
        Route::post('/fetch-subcategory', [ProductController::class, 'fetchSubcategory']);
        Route::post('/fetch-product', [ProductController::class, 'fetchProduct']);
        Route::post('/fetch-feature', [ProductController::class, 'fetchFeature']);
        Route::get('/preview-menu', [ProductController::class, 'previewMenu']);

       
        Route::get('download-pdf', [OrderController::class,'download_fatora'])->name('download-pdf');
        Route::get('print-pdf', [OrderController::class,'print_fatora'])->name('print-pdf');


        Route::resource('/resturants', ResturantController::class);
        Route::post('resturantchangeStatus/{resturant}', [ResturantController::class,'changeStatus'])->name('resturants.changeStatus');
                Route::post('update_sorting_is_featured/{resturant}', [ResturantController::class,'update_sorting_is_featured'])->name('update_sorting_is_featured');

        Route::post('updateStatus/{resturant}', [ResturantController::class,'updateStatus'])->name('resturants.updateStatus');
        Route::delete('resturantsDeleteAll', [ResturantController::class,'deleteAll']);
        Route::post('resturant_products', [ResturantController::class,'resturantProductsCreate'])->name('resturant_products.store');
        Route::put('resturant_products/update', [ResturantController::class,'resturantProductsUpdate'])->name('resturant_products.update');
        Route::get('resturant_products/{id}/update/status', [ResturantController::class,'resturantProductsUpdateStatus'])->name('resturant_products.update.status');
        Route::get('resturant_products/{id}/update/highest_rated', [ResturantController::class,'resturantProductsUpdateHighestRated'])->name('resturant_products.update.highest_rated');
        
        Route::delete('resturant_products/{resturant}', [ResturantController::class,'resturantProductsDelete'])->name('resturant_products.destroy');
        Route::post('resturantchangeUnderContract/{resturant}', [ResturantController::class,'changeUnderContract'])->name('resturants.changeUnderContract');
        Route::delete('resturant_reviews/{review}', [ResturantController::class,'resturantReviewsDelete'])->name('resturant_reviews.destroy');
        Route::post('resturant/copy/items', ['App\Http\Controllers\Api\V1\Vendor\ResturantProductController','copy_menu'])->name('copy_menu');

        Route::resource('/users', UserController::class);
        Route::delete('usersDeleteAll', [UserController::class,'deleteAll']);
        Route::delete('userAddressDelete/{id}', [UserController::class,'userAddressDelete'])->name('useraddresses.destroy');
        Route::delete('userWishlistsDelete/{id}', [UserController::class,'userWishlistsDelete'])->name('userwishlists.destroy');
        Route::post('user-fetch-gate', [UserController::class,'fetchGate'])->name('users.fetchGate');
        Route::get('/resturant_map', [UserController::class,'resturantMap']);
        Route::get('/delegate_map', [UserController::class,'delegateMap']);
        Route::get('/change-status/{user}', [UserController::class,'changeStatus'])->name('users.change-status');
        Route::get('users/{id}/go_drive_activation',[UserController::class,'go_drive_activation']);

        Route::resource('/features', FeatureController::class);
        Route::delete('featuresDeleteAll', [FeatureController::class,'deleteAll']);
        Route::resource('/blogs', BlogController::class);
        Route::delete('blogsDeleteAll', [BlogController::class,'deleteAll']);
        Route::resource('/banners', BannerController::class);
        Route::delete('bannersDeleteAll', [BannerController::class,'deleteAll']);


        Route::get('/complaints', [ComplaintController::class,'index'])->name('complaints.index');
        Route::get('/complaints/{id}', [ComplaintController::class,'show'])->name('complaints.show');
        Route::delete('/complaints/{id}', [ComplaintController::class,'destroy'])->name('complaints.destroy');
        Route::delete('complaintsDeleteAll', [ComplaintController::class,'deleteAll']);

        Route::get('/contacts', [ContactController::class,'index'])->name('contacts.index');
        Route::get('/contacts/{id}', [ContactController::class,'show'])->name('contacts.show');
        Route::delete('/contacts/{contact}', [ContactController::class,'destroy'])->name('contacts.destroy');
        Route::delete('contactsDeleteAll', [ContactController::class,'deleteAll']);


        Route::resource('/subscribers', SubscriberController::class);
        Route::delete('subscribersDeleteAll', [SubscriberController::class,'deleteAll']);
        Route::post('send-subscriber-email', [SubscriberController::class,'sendSubscriberEmail'])->name('sendSubscriberEmail');
        Route::get('/show-all-mails', [SubscriberController::class, 'showAllMails'])->name('showAllMails');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/orders', [ReportController::class, 'getOrders'])->name('reports.orders');
        Route::get('/reports/delegates', [ReportController::class, 'getDelegates'])->name('reports.delegates');
        Route::get('/reports/customers', [ReportController::class, 'getCustomers'])->name('reports.customers');
        Route::get('/reports/vendors', [ReportController::class, 'getVendors'])->name('reports.vendors');
        Route::get('/reports/resturants', [ReportController::class, 'getResturants'])->name('reports.resturants');
        
        
        Route::get('reports/packages/export/excel', [ReportController::class,'exportPackageExcelFile'])->name('exportPackage.excel');
        Route::get('reports/tickets/export/excel', [ReportController::class,'exportTicketExcelFile'])->name('exportTicket.excel');
        Route::get('reports/valet-trackers/export/excel', [ReportController::class,'exportValetTrackerExcelFile'])->name('exportTrackers.excel');
        
        Route::get('/wallet/transactions', [WalletController::class, 'wallet_transactions'])->name('wallets.transactions');
        Route::get('/wallets', [WalletController::class, 'index'])->name('wallets.index');
        Route::post('/wallets/transfer', [WalletController::class, 'store'])->name('wallets.transfer');
        
        Route::get('/wallet/withdraw', [WalletController::class, 'withdraw'])->name('wallets.withdraw');
        Route::post('/wallets/withdraw/store', [WalletController::class, 'store_withdraw'])->name('wallets.withdraw.store');

        //====================================================
        //===========================areas=====================
        //=====================================================
        Route::resource('areas',AreaController::class);
        Route::delete('areasDeleteAll',[AreaController::class,'delete_all']);
        
        
         //====================================================
        //===========================slidear=====================
        //=====================================================
        Route::resource('slidears',SlidearController::class);
        Route::delete('slidearsDeleteAll',[SlidearController::class,'delete_all']);
        Route::delete('slidears/del/image',[SlidearController::class,'delete_image']);
        
        //====================================================
        //===========================question_answers=====================
        //=====================================================
        Route::resource('question_answers',QuestionAnswerController::class);
        Route::delete('question_answersDeleteAll',[QuestionAnswerController::class,'delete_all']);

    });
});
