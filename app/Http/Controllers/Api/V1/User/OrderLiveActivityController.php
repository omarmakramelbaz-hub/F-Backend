<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\OrderLiveActivityService;
use Illuminate\Http\Request;

class OrderLiveActivityController extends Controller
{
    public function store(Request $request, int $id, OrderLiveActivityService $activities)
    {
        $actor = $request->user('api');
        abort_unless($actor, 401);
        return response()->json(['status' => true, 'data' => $activities->register($id, $actor, $request->all())]);
    }

    public function destroy(Request $request, int $id, OrderLiveActivityService $activities)
    {
        $actor = $request->user('api');
        abort_unless($actor, 401);
        $activities->unregister($id, $actor, $request->all());
        return response()->json(['status' => true]);
    }

    /**
     * The address the customer app calls (POST user/order-activity-subscriptions): the order in the body and its own
     * names (live_activity_id, live_activity_token). Without it the app's registrations never reached the server, so no
     * Live Activity was ever updated.
     */
    public function subscribe(Request $request, OrderLiveActivityService $activities)
    {
        $actor = $request->user('api');
        abort_unless($actor, 401);
        $request->validate(['order_id' => 'required|integer|min:1']);
        return response()->json(['status' => true, 'data' => $activities->register((int) $request->input('order_id'), $actor, $this->appFields($request))]);
    }

    /** The app's unregister (POST user/order-activity-subscriptions/unregister). */
    public function unsubscribe(Request $request, OrderLiveActivityService $activities)
    {
        $actor = $request->user('api');
        abort_unless($actor, 401);
        $request->validate(['order_id' => 'required|integer|min:1']);
        $activities->unregister((int) $request->input('order_id'), $actor, $this->appFields($request));
        return response()->json(['status' => true]);
    }

    private function appFields(Request $request): array
    {
        return array_filter([
            'activity_id' => $request->input('activity_id', $request->input('live_activity_id')),
            'activity_token' => $request->input('activity_token', $request->input('live_activity_token')),
            'fcm_token' => $request->input('fcm_token'),
        ], fn ($value) => $value !== null);
    }
}
