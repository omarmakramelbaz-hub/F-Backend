<?php
namespace App\Services\Dashboard;

use Illuminate\Routing\Route;

/** Reviewed local read surface. New actions or aliases require an explicit policy review. */
final class DesktopDashboardWorkspaceReadRoutes
{
    public static function isRead(Route $route): bool
    {
        return count(array_intersect(['GET','HEAD'],$route->methods()))>0
            || (in_array('POST',$route->methods(),true) && (in_array($route->getName(),DesktopDashboardRoutes::READ_POSTS,true)
                || in_array($route->getActionName(),DesktopDashboardRoutes::READ_POST_ACTIONS,true)));
    }
    public static function desktop(Route $route): bool
    {
        return $route->uri()==='admin'||str_starts_with($route->uri(),'admin/')
            || str_starts_with(ltrim($route->getActionName(),'\\'),'App\\Http\\Controllers\\Dashboard\\')
            || preg_match('#^api/desktop-(?:dashboard|pos)(?:/|$)#D',$route->uri())===1
            || in_array('IsAdmin',$route->middleware(),true)
            || in_array($route->uri(),['clear-cache-admin','clear-cache','clear-compiled','linkstoragse','send_order_email','test-test','broadcasting/auth'],true);
    }
    public static function classify(Route $route): ?string
    {
        if (!self::isRead($route)) return null;
        if (!self::desktop($route)) return 'outside-desktop';
        $uri=self::uri($route->uri());$action=ltrim($route->getActionName(),'\\');
        foreach (self::reviewed() as $entry) {
            if ($entry['action']===$action && in_array($uri,$entry['uris'],true)
                && $route->methods()===$entry['methods']) return $entry['policy'];
        }
        return null;
    }
    public static function descriptor(Route $route): array
    {
        return ['methods'=>$route->methods(),'uri'=>$route->uri(),'action'=>$route->getActionName(),
            'policy'=>self::classify($route)];
    }
    private static function uri(string $uri): string { return trim($uri,'/'); }

    /** The methods are pinned too: changing a read into another method is not inherited coverage. */
    private static function reviewed(): array
    {
        $rows=[];$dashboard='App\\Http\\Controllers\\Dashboard\\';
        $add=function(string $controller,string $method,array $uris,string $policy,array $methods=['GET','HEAD'])use(&$rows,$dashboard){
            $rows[]=['action'=>(str_starts_with($controller,'App\\')?$controller:$dashboard.$controller).'@'.$method,
                'uris'=>array_map([self::class,'uri'],$uris),'policy'=>$policy,'methods'=>$methods];
        };
        $resource=function(string $controller,string $path,string $policy)use($add){
            $parameters=['users'=>'user','resturants'=>'resturant','advertisings'=>'advertising','pending_vendors'=>'pending_vendor',
                'contracts'=>'contract','products'=>'product','categorys'=>'category','features'=>'feature','roles'=>'role','banners'=>'banner',
                'slidears'=>'slidear','areas'=>'area','question_answers'=>'question_answer','admins'=>'admin','blogs'=>'blog',
                'subscribers'=>'subscriber','coupon_wheels'=>'coupon_wheel','fcm_notifications'=>'fcm_notification'];
            $parameter=$parameters[basename($path)];
            $add($controller,'index',[$path],$policy);$add($controller,'create',[$path.'/create'],$controller==='PendingVendorController'?'unavailable':$policy);
            $add($controller,'show',[$path.'/{'.$parameter.'}'],$policy);$add($controller,'edit',[$path.'/{'.$parameter.'}/edit'],$policy);
        };
        $add('MetaReplyController','temporaryMedia',['api/meta/messaging/media/{ticket}'],'connected-only');
        foreach(['index'=>'admin/dashboard','overview'=>'admin/dashboard/overview','notifications'=>'admin/notifications',
            'chooseType'=>'admin/choose_type','chooseTypeChange'=>'admin/choose_type/change','loginPage'=>'admin/login',
            'adminLogout'=>'admin/adminLogout','changeLang'=>'change-language/{lang}']as$method=>$uri)
            $add('HomeController',$method,[$uri],in_array($method,['loginPage','adminLogout','chooseType','chooseTypeChange','changeLang'],true)?'session':'actor-scoped');
        $add('HomeController','adminWallet',['admin/admin_wallet'],'central-financial');
        $add('HomeController','resturantControl',['admin/resturantControl'],'journal-mutation');
        $add('HomeController','generatQr',['generate-qr/{ticket}'],'unavailable');
        $add('HomeController','bulk_notifications',['admin/bulk-notifications'],'connected-only');
        foreach(['index'=>'admin/wallets','withdraw'=>'admin/wallet/withdraw','wallet_transactions'=>'admin/wallet/transactions']as$method=>$uri)$add('WalletController',$method,[$uri],'central-financial');
        foreach(['index'=>'admin/reports','getOrders'=>'admin/reports/orders','getResturants'=>'admin/reports/resturants',
            'getCustomers'=>'admin/reports/customers','getDelegates'=>'admin/reports/delegates','getVendors'=>'admin/reports/vendors']as$method=>$uri)
            $add('ReportController',$method,[$uri],in_array($method,['getCustomers','getDelegates','getVendors'],true)?'central-report':'actor-scoped');
        foreach(['exportTicketExcelFile'=>'tickets','exportPackageExcelFile'=>'packages','exportValetTrackerExcelFile'=>'valet-trackers']as$method=>$path)$add('ReportController',$method,['admin/reports/'.$path.'/export/excel'],'unavailable');
        foreach(['UserController'=>'users','ResturantController'=>'resturants','AdvertisingController'=>'advertisings','PendingVendorController'=>'pending_vendors',
            'ContractController'=>'contracts']as$controller=>$path)$resource($controller,'admin/'.$path,'typed-target');
        $add('UserController','resturantMap',['admin/resturant_map'],'typed-target');$add('UserController','delegateMap',['admin/delegate_map'],'central-private');
        $add('UserController','changeStatus',['admin/change-status/{user}'],'journal-mutation');
        $add('UserController','go_drive_activation',['admin/users/{id}/go_drive_activation'],'unavailable');
        $add('PendingVendorController','addVendor',['admin/pending_vendors/{pending}/addVendor'],'central-private');
        $add('ContractController','pdfviewContract',['admin/pdfview-contract'],'central-private');
        $add('ResturantController','resturantProductsUpdateStatus',['admin/resturant_products/{id}/update/status'],'journal-mutation');
        $add('ResturantController','resturantProductsUpdateHighestRated',['admin/resturant_products/{id}/update/highest_rated'],'journal-mutation');
        $add('ContactController','index',['admin/contacts'],'central-private');$add('ContactController','show',['admin/contacts/{id}'],'unavailable');
        foreach(['ProductController'=>'products','CategoryController'=>'categorys','FeatureController'=>'features','RolesController'=>'roles',
            'BannerController'=>'banners','SlidearController'=>'slidears','AreaController'=>'areas','QuestionAnswerController'=>'question_answers']as$controller=>$path)$resource($controller,'admin/'.$path,'public-reference');
        $add('CategoryController','edit_services_prices',['admin/categorys/{id}/services/prices'],'public-reference');
        $add('ProductController','previewMenu',['admin/preview-menu'],'public-reference');
        foreach(['fetchSubcategory'=>'fetch-subcategory','fetchProduct'=>'fetch-product','fetchFeature'=>'fetch-feature']as$method=>$path)
            $add('ProductController',$method,['admin/'.$path],$method==='fetchFeature'?'typed-target':'public-reference',['POST']);
        foreach(['AdminsController'=>'admins','BlogController'=>'blogs','SubscriberController'=>'subscribers']as$controller=>$path)$resource($controller,'admin/'.$path,'unavailable');
        $resource('CouponWheelController','admin/coupon_wheels','central-private');
        $add('ComplaintController','index',['admin/complaints'],'unavailable');$add('ComplaintController','show',['admin/complaints/{id}'],'unavailable');
        $add('SubscriberController','showAllMails',['admin/show-all-mails'],'unavailable');
        $resource('FcmNotificationsController','admin/fcm_notifications','connected-only');
        $add('FcmNotificationsController','campaignStatus',['admin/fcm_notifications/campaigns/{campaign}'],'connected-only');
        $add('FcmNotificationsController','testNotification',['test-notification'],'unavailable');
        $add('FcmNotificationsController','chat',['admin/chat'],'actor-scoped');
        foreach(['index'=>'admin/settings','advertising'=>'admin/advertising','enviroSetting'=>'admin/env-setting']as$method=>$uri)$add('SettingsController',$method,[$uri],$method==='enviroSetting'?'connected-only':'public-reference');
        foreach(['index'=>'admin/go-stores','create'=>'admin/go-stores/create','show'=>'admin/go-stores/{owner}']as$method=>$uri)$add('GoStores\\StoreController',$method,[$uri],'typed-target');
        $add('GoStores\\StoreController','editProduct',['admin/go-stores/{owner}/products/create','admin/go-stores/{owner}/products/{product}/edit'],'typed-target');
        foreach(['index'=>'admin/orders','create'=>'admin/orders/create','show'=>'admin/orders/{order}','edit'=>'admin/orders/{order}/edit',
            'fetchProduct'=>'admin/fetch-product','download_fatora'=>'admin/download-pdf','print_fatora'=>'admin/print-pdf',
            'download_daily_report'=>'download-daily-report-pdf']as$method=>$uri)$add('OrderController',$method,[$uri],in_array($method,['create','edit'],true)?'unavailable':'actor-scoped');
        $add('OrderController','cancel_order_delegate',['admin/cancel/order/{id}/delegate'],'unavailable');
        foreach(['board'=>'admin/applies-orders','feed'=>'admin/getOrders','details'=>'admin/order-board/{source}/{id}',
            'print'=>'admin/order-board/{source}/{id}/print','items'=>'admin/order-board/{source}/{id}/items']as$method=>$uri)$add('OrderBoardController',$method,[$uri],'actor-scoped');
        $add('OrderBoardMenuController','index',['admin/order-board/menu'],'actor-scoped');
        foreach(['index'=>'admin/takeaway','catalog'=>'admin/takeaway/catalog','receipts'=>'admin/takeaway/receipts',
            'details'=>'admin/takeaway/receipts/{id}/details','print'=>'admin/takeaway/receipts/{id}/print','till'=>'admin/takeaway/till']as$method=>$uri)$add('TakeawayController',$method,[$uri],'actor-scoped');
        $add('TakeawayController','quote',['admin/takeaway/quote'],'actor-scoped',['POST']);
        foreach(['DineInController'=>'dining','PhoneOrdersController'=>'phone-orders']as$controller=>$path){
            foreach(['index'=>'','catalog'=>'/catalog','tickets'=>'/tickets','recover'=>'/recover','show'=>'/tickets/{id}',
                'details'=>'/tickets/{id}/details','print'=>'/tickets/{id}/print','kitchen'=>'/kitchen/{id}/print']as$method=>$suffix)$add($controller,$method,['admin/'.$path.$suffix],'actor-scoped');
            $add($controller,'quote',['admin/'.$path.'/quote'],'actor-scoped',['POST']);
        }
        $add('DineInController','tables',['admin/dining/tables'],'actor-scoped');
        foreach(['deliverySettings'=>'delivery-settings','customers'=>'customers','board'=>'board','batchPrint'=>'batches/{id}/print','printJobs'=>'print-jobs']as$method=>$path)$add('PhoneOrdersController',$method,['admin/phone-orders/'.$path],'actor-scoped');
        foreach(['deliveryQuote'=>'delivery-quote','addressSuggestions'=>'address-suggestions']as$method=>$path)$add('PhoneOrdersController',$method,['admin/phone-orders/'.$path],'actor-scoped',['POST']);
        $add('BranchOrdersController','index',['admin/branch-orders'],'actor-scoped');
        foreach(['index'=>'','data'=>'/data','pendingCount'=>'/pending-count','recover'=>'/recover','export'=>'/export','report'=>'/report',
            'show'=>'/{id}','attachment'=>'/{id}/attachment','print'=>'/{id}/print']as$method=>$suffix)$add('BranchExpensesController',$method,['admin/branch-expenses'.$suffix],'actor-scoped');
        foreach(['index','data']as$method)$add('BranchOperationsController',$method,array_map(fn($module)=>'admin/'.$module.($method==='index'?'':'/data'),['customers','delivery-companies','employees']),'actor-scoped');
        $add('BranchOperationsController','recover',['admin/branch-operations/recover'],'actor-scoped');
        foreach(['statement','entries','export','identityImage','regulation']as$method)$add('BranchOperationsController',$method,['admin/employees/'.($method==='identityImage'?'identity-image':$method)],'actor-scoped');
        $add('BranchOperationsController','messagingStatus',['admin/customers/messaging-status'],'connected-only');
        foreach(['index'=>'','data'=>'/data','recipes'=>'/recipes','basicRecipes'=>'/basic-recipes']as$method=>$suffix)$add('BranchStockController',$method,['admin/branch-stock'.$suffix],'actor-scoped');
        foreach(['index'=>'','data'=>'/data','recover'=>'/recover','details'=>'/{id}/details','print'=>'/{id}/print']as$method=>$suffix)$add('BranchShiftClosingController',$method,['admin/branch-shifts'.$suffix],'actor-scoped');
        $add('BranchShiftPrintingController','jobs',['admin/branch-shift-print/jobs'],'actor-scoped');$add('BranchShiftPrintingController','receipt',['admin/branch-shift-print/{id}/receipt'],'actor-scoped');
        $add('PrintSettingsController','index',['admin/print-settings'],'actor-scoped');$add('PrintSettingsController','test',['admin/print-settings/test'],'actor-scoped');
        foreach(['notifications'=>'notifications','support'=>'support','messages'=>'support/{partner}/messages']as$method=>$suffix)$add('DashboardInboxController',$method,['admin/dashboard-inbox/'.$suffix],'actor-scoped');
        foreach(['index'=>'','download'=>'/download','publishedDownload'=>'/published/{sha}','releaseStatus'=>'/release/status','session'=>'/session']as$method=>$suffix)$add('DesktopDashboardController',$method,['admin/desktop-dashboard'.$suffix],'native-control');
        foreach(['challenge','localSession']as$method)$add('DesktopDashboardWorkspaceController',$method,['admin/desktop-dashboard/workspace/'.($method==='localSession'?'local-session':$method)],'native-control');
        $add('DesktopPosController','index',['admin/desktop-pos'],'native-control');$add('DesktopPosController','download',['admin/desktop-pos/download'],'native-control');
        $add('DesktopDashboardUpdateController','status',['api/desktop-dashboard/update'],'connected-only');$add('DesktopDashboardUpdateController','installer',['api/desktop-dashboard/update/installer/{sha}'],'connected-only');
        foreach(['bootstrap','media']as$method)$add('App\\Http\\Controllers\\Api\\DesktopDashboardController',$method,['api/desktop-dashboard/'.$method],'connected-only');
        foreach(['manifest','bootstrap','media']as$method)$add('App\\Http\\Controllers\\Api\\DesktopDashboardWorkspaceController',$method,['api/desktop-dashboard/workspace/'.$method],'connected-only');
        foreach(['snapshot','health']as$method)$add('App\\Http\\Controllers\\Api\\DesktopPosController',$method,['api/desktop-pos/'.$method],'connected-only');
        $add('App\\Http\\Controllers\\Api\\V1\\Vendor\\OrderController','reports',['admin/resturant-reports'],'actor-scoped');
        $add('App\\Http\\Controllers\\Api\\V1\\Vendor\\OrderController','getSingleOrder',['admin/vendororders/{order}'],'actor-scoped');
        $add('App\\Http\\Controllers\\Api\\V1\\User\\WalletController','get_wallet',['admin/get/charging/wallet'],'actor-scoped');
        // Provider families remain connected-only, with their existing service checks retained online.
        $providers=[
            'MetaConnectionController'=>['index'=>['admin/meta/connection'],'callback'=>['admin/meta/connection/callback']],
            'MetaInboxController'=>['index'=>['admin/meta'],'conversations'=>['admin/meta/conversations'],'messages'=>['admin/meta/conversations/{conversation}/messages']],
            'MetaMessageActionsController'=>['media'=>['admin/meta/conversations/{conversation}/messages/{message}/media/{index}']],
            'MetaInboxReadController'=>['unread'=>['admin/meta/unread']],
            'MetaReplyController'=>['state'=>['admin/meta/conversations/{conversation}/reply-state']],
            'MetaAiControlController'=>['state'=>['admin/meta/ai-control']],
            'MetaCommentAiController'=>['status'=>['admin/meta/comments/ai/settings']],
            'MetaCommentsController'=>['index'=>['admin/meta/comments'],'accounts'=>['admin/meta/comments/accounts'],'posts'=>['admin/meta/comments/posts'],'comments'=>['admin/meta/comments/comments'],'replies'=>['admin/meta/comments/replies']],
            'MetaOrderController'=>['meta'=>['admin/meta/orders/meta'],'catalog'=>['admin/meta/orders/catalog'],'customers'=>['admin/meta/orders/customers'],'deliverySettings'=>['admin/meta/orders/delivery-settings'],'addressSuggestions'=>['admin/meta/orders/address-suggestions'],'state'=>['admin/meta/conversations/{conversation}/orders']],
            'WhatsAppInboxController'=>['index'=>['admin/meta/whatsapp','admin/whatsapp'],'conversations'=>['admin/whatsapp/conversations'],'messages'=>['admin/whatsapp/conversations/{conversation}/messages']],
            'WhatsAppInboxMessageController'=>['selection'=>['admin/whatsapp/conversations/{conversation}/messages/selection'],'updates'=>['admin/whatsapp/conversations/{conversation}/messages/updates'],'media'=>['admin/whatsapp/conversations/{conversation}/messages/{message}/media'],'replyMedia'=>['admin/whatsapp/conversations/{conversation}/replies/{reply}/media']],
            'WhatsAppInboxReadController'=>['unread'=>['admin/whatsapp/unread']],
            'WhatsAppReplyController'=>['state'=>['admin/whatsapp/conversations/{conversation}/reply-state'],'history'=>['admin/whatsapp/conversations/{conversation}/replies/history'],'downloadAttachment'=>['admin/whatsapp/conversations/{conversation}/replies/{reply}/attachment']],
            'WhatsAppOrderController'=>['meta'=>['admin/whatsapp/orders/meta'],'catalog'=>['admin/whatsapp/orders/catalog'],'customers'=>['admin/whatsapp/orders/customers'],'deliverySettings'=>['admin/whatsapp/orders/delivery-settings'],'addressSuggestions'=>['admin/whatsapp/orders/address-suggestions'],'state'=>['admin/whatsapp/conversations/{conversation}/orders']],
        ];
        foreach($providers as$controller=>$methods)foreach($methods as$method=>$uris)$add($controller,$method,$uris,'connected-only');
        foreach(['clear-cache-admin','clear-cache','clear-compiled','linkstoragse','send_order_email','test-test','broadcasting/auth']as$uri)
            $rows[]=['action'=>'Closure','uris'=>[$uri],'policy'=>'unavailable','methods'=>['GET','HEAD']];
        return $rows;
    }
}
