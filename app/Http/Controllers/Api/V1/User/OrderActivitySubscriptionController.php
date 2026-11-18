<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Jobs\SyncOrderLiveActivity;
use App\Models\Order;
use App\Models\OrderActivitySubscription;
use Illuminate\Http\Request;

class OrderActivitySubscriptionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|integer|min:1',
            'platform' => 'required|in:ios',
            'live_activity_id' => 'required|string|max:255',
            'live_activity_token' => ['required', 'string', 'regex:/^[a-fA-F0-9]{32,512}$/'],
            'fcm_token' => 'required|string|min:10|max:4096',
        ]);
        $order = Order::withoutGlobalScopes()->where('user_id', $request->user()->id)->findOrFail($data['order_id']);
        $hash = hash('sha256', strtolower($data['live_activity_token']));
        $existing = OrderActivitySubscription::where('token_hash', $hash)->first();
        abort_if($existing && ((int) $existing->user_id !== (int) $order->user_id || (int) $existing->order_id !== (int) $order->id), 403);
        $registration = OrderActivitySubscription::firstOrNew(['token_hash' => $hash]);
        $registration->fill([
            'user_id' => $order->user_id, 'order_id' => $order->id,
            'activity_id' => $data['live_activity_id'],
            'activity_token' => strtolower($data['live_activity_token']),
            'fcm_token' => $data['fcm_token'],
        ]);
        // An expired or ended native activity cannot be revived by registration.
        if (!$registration->exists) $registration->expires_at = now()->addHours(8);
        $registration->save();
        // Apple can rotate the push token for the same native activity.
        OrderActivitySubscription::where('user_id', $order->user_id)->where('order_id', $order->id)
            ->where('activity_id', $data['live_activity_id'])->where('token_hash', '!=', $hash)
            ->whereNull('ended_at')->update(['ended_at' => now()]);
        SyncOrderLiveActivity::dispatchAfterResponse($order->id);
        return response()->json(['status' => true, 'data' => ['order_id' => $order->id, 'activity_id' => $registration->activity_id]]);
    }

    public function unregister(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|integer|min:1',
            'live_activity_id' => 'required|string|max:255',
        ]);
        OrderActivitySubscription::where('user_id', $request->user()->id)->where('order_id', $data['order_id'])
            ->where('activity_id', $data['live_activity_id'])->update(['ended_at' => now()]);
        return response()->json(['status' => true]);
    }
}
