<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Vendor\OrderController as VendorOrders;
use App\Models\PartnerServiceRequest;
use App\Events\OrderFinishedUpdated;
use App\Notifications\NotifyUserOrderStatusUpdatedNotification;
use App\Services\Dashboard\GoStoreBoardActions;
use App\Services\Dashboard\OrderBoardService;
use App\Services\GoServices\Marketplace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Notification;

class OrderBoardController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(app(OrderBoardService::class)->canAccess(auth('admin')->user()), 403);
            return $next($request);
        });
    }

    public function board(Request $request, OrderBoardService $service)
    {
        return view('admin.orders.board', ['board'=>$service->data($request, auth('admin')->user())]);
    }

    public function feed(Request $request, OrderBoardService $service)
    {
        $board = $service->data($request, auth('admin')->user());
        $html = view('admin.orders.board_columns', compact('board'))->render();
        return response()->json(['html'=>$html, 'view'=>$html, 'count'=>$board['count'],
            'counts'=>$board['counts'], 'updated_at'=>$board['updated_at']]);
    }

    public function details(string $source, int $id, OrderBoardService $service)
    {
        return view('admin.orders.board_detail', ['card'=>$service->detail($source, $id, auth('admin')->user()), 'printing'=>false]);
    }

    public function print(string $source, int $id, OrderBoardService $service)
    {
        return view('admin.orders.board_detail', ['card'=>$service->detail($source, $id, auth('admin')->user()), 'printing'=>true]);
    }

    public function action(Request $request, string $source, int $id, OrderBoardService $service, GoStoreBoardActions $stores)
    {
        $values = $request->validate([
            'action'=>'required|in:accept,reject,prepare,ready,dispatch,complete',
            'expected_status'=>'required|string|max:32', 'expected_accepted_notify'=>'nullable|string|max:10',
            'expected_revision'=>'nullable|integer|min:0', 'reason'=>'nullable|string|max:500',
        ]);
        $actor = auth('admin')->user();
        // Recheck scope at every endpoint, independently of any displayed card or caller-supplied owner.
        $card = $service->actionState($source, $id, $actor, $stores);
        abort_unless(in_array($values['action'], $card['actions'], true), 409, 'تغيرت حالة الطلب أو الإجراء غير متاح. حدّث الصفحة.');
        if ($source === 'store') {
            abort_unless(array_key_exists('expected_revision', $values), 422, 'راجع حالة الطلب قبل تنفيذ الإجراء.');
            $owner = $service->isAdmin($actor) ? DB::table('go_store_orders')->where('id', $id)->value('store_id') : $actor->id;
            $changed = $stores->transition($id, (int) $owner, $values['action'], (int) $values['expected_revision'], $values['reason'] ?? 'رفض الإدارة');
            if ($changed) $stores->notifyCustomer($id);
        } else {
            $shippingRejected = null;
            DB::transaction(function () use ($request, $source, $id, $service, $values, $actor, &$shippingRejected) {
                if ($source === 'legacy') {
                    $order = $service->scopedLegacy($id, $actor, true);
                    abort_unless($order->status === $values['expected_status'], 409, 'تغيرت حالة الطلب. حدّث الصفحة.');
                    $expectedAccepted = $values['expected_accepted_notify'] ?? '';
                    abort_unless(($order->accepted_notify ?? '') === $expectedAccepted, 409, 'تم التعامل مع هذا الطلب. حدّث الصفحة.');
                    if ($order->type === 'shipping') {
                        // Existing shipping cancellation before a delegate is chosen has no financial movement.
                        abort_unless($values['action'] === 'reject' && $service->isAdmin($actor)
                            && $order->status === 'pending' && !$order->delegate_id, 409);
                        $order->update(['status'=>'cancelled']);
                        if (Schema::hasTable('delegate_notifications')) DB::table('delegate_notifications')->where('order_id', $id)->delete();
                        if (Schema::hasTable('notifications')) {
                            DB::table('notifications')->where('type', 'App\\Notifications\\NotifyDelegatesNewOrderNotification')
                                ->where('data->data->order_id', $id)->delete();
                        }
                        $shippingRejected = $order;
                        return;
                    }
                    $restaurant = $order->resturant;
                    abort_unless($restaurant && in_array($values['action'], $service->legacyActions($order, true), true), 409);
                    $vendor = app(VendorOrders::class);
                    // The current restaurant lifecycle accepts first, then chooses the delivery assignment.
                    if ($values['action'] === 'accept') $vendor->acceptOrder($request, $order);
                    elseif ($values['action'] === 'reject') {
                        $request->merge(['status'=>'declined']);
                        $vendor->updateOrderStatus($request, $order);
                    } elseif ($values['action'] === 'complete') {
                        $request->merge(['status'=>'completed']);
                        $vendor->updateOrderStatus($request, $order);
                    } else {
                        $request->merge(['type'=>$values['action'] === 'prepare' ? 'in_resturant' : 'out_resturant',
                            'order_id'=>$id, 'resturant_id'=>$order->resturant_id]);
                        $vendor->updateOrder($request, $order);
                    }
                } elseif ($source === 'partner_service') {
                    abort_unless($service->isAdmin($actor), 403);
                    $serviceRequest = PartnerServiceRequest::where('id', $id)->lockForUpdate()->firstOrFail();
                    abort_unless($serviceRequest->status === $values['expected_status'], 409, 'تغيرت حالة الطلب.');
                    $action = $values['action'];
                    abort_unless(($serviceRequest->status === 'pending' && in_array($action, ['accept','reject'], true))
                        || ($serviceRequest->status === 'accepted' && $action === 'complete'), 409);
                    $serviceRequest->status = ['accept'=>'accepted','reject'=>'declined','complete'=>'completed'][$action];
                    if ($action === 'accept') $serviceRequest->accepted_at = now();
                    if ($action === 'complete') $serviceRequest->completed_at = now();
                    $serviceRequest->save();
                } elseif ($source === 'service') {
                    abort_unless($service->isAdmin($actor) && $values['action'] === 'reject', 403);
                    $job = DB::table('go_service_jobs')->where('id', $id)->lockForUpdate()->first();
                    abort_unless($job && $job->status === $values['expected_status'] && $job->status === 'searching'
                        && !$job->accepted_offer_id, 409, 'تم الاتفاق على الطلب؛ لا يمكن رفضه من شاشة استقبال الطلبات.');
                    // Reuse cancellation while still searching: no accepted quote or cancellation fee exists.
                    app(Marketplace::class)->transition($id, (int) $job->customer_id, 'cancelled', $values['reason'] ?? 'رفض الإدارة');
                } else {
                    abort(404);
                }
            }, 3);
            if ($shippingRejected) {
                // A push provider failure must not turn a committed cancellation into an error.
                try {
                    app(\App\Services\Dashboard\OrderProviderDelivery::class)->event(
                        new OrderFinishedUpdated($shippingRejected, 1, $shippingRejected->user_id), (int) $shippingRejected->id);
                    if ($shippingRejected->user) Notification::send($shippingRejected->user, new NotifyUserOrderStatusUpdatedNotification($shippingRejected));
                } catch (\Throwable $error) {
                    \Log::warning('Shipping order cancellation notification unavailable', ['order_id'=>$id]);
                }
            }
        }
        if ($request->expectsJson()) {
            $payload = ['success'=>true, 'message'=>'تم تحديث الطلب.'];
            try {
                // Return the committed card so the board does not wait for a second,
                // expensive four-column feed before showing the new phase.
                $updated = $service->detail($source, $id, $actor);
                $payload['card'] = ['key'=>$updated['key'], 'from_group'=>$card['group'], 'group'=>$updated['group'],
                    'html'=>view('admin.orders.board_card', ['card'=>$updated])->render()];
            } catch (\Throwable $error) {
                // Rendering cannot turn an already committed mutation into a retry.
                \Log::warning('Committed order card refresh unavailable', ['source'=>$source, 'order_id'=>$id,
                    'exception'=>get_class($error)]);
            }
            return response()->json($payload);
        }
        return redirect()->route('orders.applies')->with('success', 'تم تحديث الطلب.');
    }
}
