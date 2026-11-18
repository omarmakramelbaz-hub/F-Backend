<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Models\Order;
use App\Models\User;
use App\Services\Chat\ChatPush;
use Illuminate\Http\Request;

/**
 * POST /api/user/chat_notify: tells the other person of a customer's chat that a message arrived.
 *   - chat "order": the courier of the customer's own order (chat_id DC<order> for a branch order, VD<order> for a
 *     delivery request), on the courier's device;
 *   - chat "support": the support team (the admin account the app chats with).
 * The push carries the same fields the app used to send itself, so the courier app and the dashboard open the chat as before.
 */
class ChatPushController extends Controller
{
    use ApiResponses;

    public function __invoke(Request $request, ChatPush $push)
    {
        $values = $request->validate([
            'chat' => ['required', 'in:order,support'],
            'chat_id' => ['required_if:chat,order', 'nullable', 'string', 'regex:/^(DC|VD)[1-9][0-9]{0,18}$/D'],
            'message' => ['required', 'string', 'max:1000'],
        ]);
        $customer = auth('api')->user();
        $text = mb_substr(trim($values['message']), 0, 300);
        if ($text === '') return $this->errorResponse(__('api.empty'), 422);

        try {
            if ($values['chat'] === 'order') {
                $order = Order::query()->whereKey((int) substr($values['chat_id'], 2))->where('user_id', $customer->id)->first();
                $courier = $order?->delegate;
                if (!$courier) return $this->errorResponse(__('api.not found'), 404);
                $push->send([$courier->fcm_id], ['title' => (string) $customer->name, 'text' => $text, 'data' => [
                    'order_id' => $values['chat_id'],
                    'notification_type' => '8',
                    'account_type' => str_starts_with($values['chat_id'], 'VD') ? 'vendor' : 'user',
                    'receiver_device_token' => (string) $courier->fcm_id,
                    'sender_device_token' => (string) $customer->fcm_id,
                    'sender_name' => (string) $customer->name,
                    'receiver_name' => (string) $courier->name,
                ]]);
            } else {
                $admin = User::where('account_type', 'admin')->first();
                if (!$admin) return $this->errorResponse(__('api.not found'), 404);
                $push->send([$admin->device_token], ['title' => (string) $customer->name, 'text' => $text, 'data' => [
                    'notification_type' => '10',
                    'msg_type' => '4',
                    'sender_id' => (string) $customer->id,
                    'receiver_id' => (string) $admin->id,
                    'sender_name' => (string) $customer->name,
                    'receiver_name' => (string) $admin->name,
                    'admin_device_token' => (string) $admin->device_token,
                    'click_action' => rtrim((string) config('app.url'), '/').'/admin/chat/?user_id='.$customer->id,
                ]]);
            }
        } catch (\Throwable $error) {
            // The message itself is saved by the app; only its notice failed.
            \Log::warning('Chat push unavailable', ['user_id' => (int) $customer->id, 'chat' => $values['chat'], 'error_class' => get_class($error)]);
            return $this->errorResponse(__('api.error in sent '), 502);
        }
        return $this->successResponse(true, '');
    }
}
