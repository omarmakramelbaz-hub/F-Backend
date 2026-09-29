<?php
namespace App\Http\Controllers\Api\V1\GoStores;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Services\GoPayments\Gateway;
use App\Services\GoStores\Orders;
use App\Services\GoStores\Payments;
use App\Services\GoStores\Notices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    use ApiResponses;

    public function capabilities()
    {
        return $this->successResponse(['schema_ready' => Orders::ready(), 'payment_methods' => array_merge(['cash', 'wallet'], Gateway::methods()), 'currency' => 'EGP']);
    }

    private function actor(Request $request, bool $partner = false): int
    {
        abort_unless(Orders::ready(), 503, 'جاري تجهيز طلبات المتاجر. حاول لاحقًا.');
        $scope = $partner ? 'go_partner' : 'go';
        abort_unless($request->header('X-App-Scope') === $scope, 403);
        return (int)(new Orders())->actor((int)auth('api')->id(), $scope)->id;
    }

    private function cart(Request $request, bool $submit = false): array
    {
        return $request->validate([
            'store_id' => 'required|integer|min:1', 'fulfillment' => 'required|in:delivery,pickup',
            'address_id' => 'nullable|required_if:fulfillment,delivery|integer|min:1', 'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|integer|min:1',
            'items.*.option_id' => 'nullable|uuid', 'items.*.quantity' => 'required|integer|min:1|max:99',
        ] + ($submit ? ['request_key' => 'required|uuid', 'quote_token' => 'required|string|max:4096',
            'payment_method' => 'required|in:cash,wallet,mobile_wallet,card'] : []));
    }

    public function quote(Request $request, Orders $orders)
    {
        $actor = $this->actor($request);
        return $this->successResponse($orders->quote($actor, $this->cart($request)));
    }

    public function store(Request $request, Orders $orders)
    {
        $actor = $this->actor($request);
        $data = $this->cart($request, true);
        $existing = DB::table('go_store_orders')->where('customer_id', $actor)->where('request_key', $data['request_key'])->exists();
        $order = $orders->create($actor, $data);
        if (!$existing && $order['status'] === 'pending') Notices::send($order['id'], true);
        return $this->successResponse(['order' => $order]);
    }

    public function index(Request $request, Orders $orders)
    {
        $partner = $request->header('X-App-Scope') === 'go_partner';
        $actor = $this->actor($request, $partner);
        $request->validate(['page' => 'sometimes|integer|min:1', 'history' => 'sometimes|boolean']);
        $q = DB::table('go_store_orders')->where($partner ? 'store_id' : 'customer_id', $actor);
        if ($partner) $q->where('status', '!=', 'awaiting_payment');
        $closed = ['completed', 'cancelled', 'rejected'];
        if ($request->boolean('history')) $q->whereIn('status', $closed); else $q->whereNotIn('status', $closed);
        $page = $q->orderByDesc('id')->paginate(30);
        return $this->successResponse(['orders' => $page->getCollection()->map(fn($o) => $orders->present($o, $partner))->all(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function show(Request $request, Orders $orders, int $order)
    {
        $partner = $request->header('X-App-Scope') === 'go_partner';
        return $this->successResponse(['order' => $orders->present($orders->visible($order, $this->actor($request, $partner), $partner), $partner)]);
    }

    public function action(Request $request, Orders $orders, int $order)
    {
        $partner = $request->header('X-App-Scope') === 'go_partner';
        $actor = $this->actor($request, $partner);
        $data = $request->validate(['action' => $partner ? 'required|in:accept,reject,ready,out_for_delivery,complete' : 'required|in:cancel',
            'revision' => 'required|integer|min:1', 'reason' => 'nullable|required_if:action,reject|string|max:500']);
        $result = $orders->transition($order, $actor, $partner, $data['action'], (int)$data['revision'], $data['reason'] ?? null);
        Notices::send($order, !$partner);
        return $this->successResponse(['order' => $result]);
    }

    public function checkout(Request $request, Payments $payments, int $order)
    {
        return $this->successResponse($payments->checkout($order, $this->actor($request)));
    }

    public function webhook(Request $request, Payments $payments)
    {
        abort_unless(Orders::ready(), 503);
        $request->validate(['obj' => 'required|array']);
        $payments->callback($request->input('obj'), (string)$request->query('hmac'));
        return response()->json(['received' => true]);
    }

    public function paymentReturn(Request $request, Payments $payments)
    {
        if ($request->filled('id') && Orders::ready()) {
            try { $payments->settleVerified((new Gateway())->inquire((string)$request->query('id'), Gateway::settings()), true); }
            catch (\Throwable $e) { /* Only authenticated server status confirms payment. */ }
        }
        return response('<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GO</title><h2>ارجع إلى التطبيق لمتابعة حالة الطلب</h2><p>Return to the app to check your verified payment status.</p></html>')
            ->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }
}
