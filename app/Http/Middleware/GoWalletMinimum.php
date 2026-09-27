<?php
namespace App\Http\Middleware;

use Closure;
use App\Models\Order;
use App\Services\GoServices\WalletPolicy;
use Illuminate\Support\Facades\DB;

/** GO new-order admission only: recharge and existing-job actions stay reachable. */
class GoWalletMinimum
{
    public function handle($request, Closure $next)
    {
        if (!in_array($request->header('X-App-Scope'), ['go', 'go_partner'], true) || !$request->isMethod('POST')) return $next($request);
        $action = $request->route()?->getActionName() ?? '';
        $action = str_replace('App\\Http\\Controllers\\Api\\V1\\', '', $action);
        $new = in_array($action, ['ShippingController@store', 'ShippingController@order_payment',
            'User\\CartController@order_payment', 'User\\CartController@reorder',
            'PartnerServiceRequestController@store', 'Delegate\\DelegateOrderController@submitShippingOffer',
            'Vendor\\OrderController@acceptOrder'], true);
        $new = $new || ($action === 'ShippingController@accept_delegate' && $request->input('status') === 'accepted')
            || ($action === 'Delegate\\DelegateOrderController@acceptDeclineOrder' && in_array($request->input('status'), ['accept', 'accepted'], true))
            || ($action === 'PartnerServiceRequestController@updateStatus' && $request->input('status') === 'accepted')
            || ($action === 'Vendor\\OrderController@updateOrderStatus' && $request->input('status') === 'accepted');
        if (!$new) return $next($request);
        $actor = auth('api')->user();
        if (!$actor) return $next($request);
        WalletPolicy::requireMinimum($actor);

        $partnerId = $action === 'ShippingController@accept_delegate' ? $request->input('delegate_id')
            : ($action === 'PartnerServiceRequestController@store' ? $request->input('partner_id') : null);
        if ($partnerId) {
            $partner = DB::table('users')->where('id', $partnerId)->first();
            if ($partner && !WalletPolicy::summary($partner)['can_accept_orders']) abort(409, 'محفظة مقدم الخدمة أقل من الحد الأدنى 50 ج.م. اختر شريكًا آخر أو انتظر شحن محفظته.');
        }
        if ($action === 'User\\CartController@order_payment') {
            $order = Order::where('user_id', $actor->id)->whereNull('status')->first();
            $store = $order?->resturant?->user;
            if ($store && !WalletPolicy::summary($store)['can_accept_orders']) abort(409, 'المتجر غير متاح لقبول طلبات جديدة لحين شحن محفظته إلى 50 ج.م.');
        }
        return $next($request);
    }
}
