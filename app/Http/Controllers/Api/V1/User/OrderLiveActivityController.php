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
}
