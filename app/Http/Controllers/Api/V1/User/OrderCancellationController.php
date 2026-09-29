<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Events\OrderFinishedUpdated;
use App\Events\VendorUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\User\OrderResource;
use App\Http\Traits\ApiResponses;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

class OrderCancellationController extends Controller
{
    use ApiResponses;

    public function cancel($id)
    {
        if (app(\App\Services\GoPayments\OrderPayments::class)->cancel((int)$id,(int)auth('api')->id())) return $this->successResponse(true,__('api.cancelled successfully'));

        $order = auth('api')->user()->orders()->where('id', $id)->first();

        if (!$order) {
            return $this->errorResponse(__('api.cannot cancel order'));
        }

        // Keep the existing shipping-order cancellation behaviour untouched.
        if ($order->type === 'shipping') {
            return app(CartController::class)->cancel_order($id);
        }

        if ($order->type !== 'current') {
            return $this->errorResponse(__('api.cannot cancel order'));
        }

        // Make cancellation idempotent for stale client screens / repeated taps.
        if ($order->status === 'cancelled') {
            return $this->successResponse(
                new OrderResource($order),
                __('api.already cancelled')
            );
        }

        if ($order->status !== 'pending') {
            return $this->errorResponse(__('api.cannot cancel order'));
        }

        if (!$order->update(['status' => 'cancelled'])) {
            return $this->errorResponse(__('api.error'));
        }

        $order->refresh();

        // Cancellation itself must succeed even if a notification, broadcast,
        // or restaurant e-mail service is temporarily unavailable.
        $this->runSideEffect('customer cancellation broadcast', function () use ($order) {
            broadcast(new OrderFinishedUpdated($order, 1, $order->user_id));
        }, $order->id);

        $resturantOwner = User::whereHas('base_resturant', function ($query) use ($order) {
            $query->where('id', $order->resturant_id);
        })->first();

        if ($resturantOwner) {
            $this->runSideEffect('vendor cancellation broadcast', function () use ($order, $resturantOwner) {
                $orderCount = $order->resturant->orders()
                    ->where('status', 'pending')
                    ->whereDate('created_at', now()->toDateString())
                    ->count();

                broadcast(new VendorUpdated($order, $orderCount, $resturantOwner->id));
            }, $order->id);

            $this->runSideEffect('restaurant cancellation notification', function () use ($order, $resturantOwner) {
                Notification::send(
                    $resturantOwner,
                    new \App\Notifications\NotifyResturantOrderCancelledNotification($order)
                );
            }, $order->id);

            if (!empty($resturantOwner->email)) {
                $this->runSideEffect('restaurant cancellation email', function () use ($order, $resturantOwner) {
                    $toEmail = $resturantOwner->email;
                    Mail::send(
                        'emails.resturant_schedule_order',
                        ['cart' => $order],
                        function ($message) use ($toEmail) {
                            $message->to($toEmail);
                            $message->subject('Order cancelled');
                        }
                    );
                }, $order->id);
            }
        }

        return $this->successResponse(
            new OrderResource($order),
            __('api.cancelled successfully')
        );
    }

    private function runSideEffect(string $name, callable $callback, int $orderId): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('Order cancellation side effect failed', [
                'order_id' => $orderId,
                'side_effect' => $name,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
