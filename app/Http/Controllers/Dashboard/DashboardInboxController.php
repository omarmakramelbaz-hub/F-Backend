<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\SupportInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DashboardInboxController extends Controller
{
    public function notifications()
    {
        $actor = Auth::guard('admin')->user();
        $notes = $actor->unreadNotifications()->select('id', 'data', 'created_at')->latest()->limit(1000)->get();
        return response()->json(['success' => true, 'count' => $actor->unreadNotifications()->count(),
            'notifications' => $notes->map(function ($note) {
                return ['id' => $note->id, 'title' => (string) ($note->data['title'] ?? ''),
                    'created_at' => optional($note->created_at)->toIso8601String(),
                    'url' => url('/admin/notifications').'#'.$note->id];
            })->values()])->header('Cache-Control', 'no-store, private');
    }

    public function readNotifications(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|max:1000', 'ids.*' => 'required|string|max:100']);
        // Snapshot IDs leave notifications arriving after the menu opened unread.
        $marked = Auth::guard('admin')->user()->unreadNotifications()->whereIn('id', $data['ids'])
            ->update(['read_at' => now(), 'updated_at' => now()]);
        return response()->json(['success' => true, 'marked' => $marked,
            'count' => Auth::guard('admin')->user()->unreadNotifications()->count()]);
    }

    public function support(Request $request, SupportInbox $inbox)
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($inbox->canAccess($actor), 403);
        return $this->supportResponse(fn () => $inbox->unread($actor, $request->boolean('fresh')));
    }

    public function messages(Request $request, SupportInbox $inbox, int $partner)
    {
        $actor = Auth::guard('admin')->user();
        $data = $request->validate(['page_token' => 'nullable|string|max:4096', 'inbox_id' => 'nullable|integer|min:1']);
        $scope = isset($data['inbox_id']) ? (int) $data['inbox_id'] : null;
        $inbox->partner($actor, $partner, $scope);
        return $this->supportResponse(fn () => $inbox->messages($actor, $partner, $data['page_token'] ?? '', $scope));
    }

    public function readSupport(Request $request, SupportInbox $inbox, int $partner)
    {
        $actor = Auth::guard('admin')->user();
        $data = $request->validate(['inbox_id' => 'nullable|integer|min:1', 'ids' => 'required|array|min:1|max:100', 'ids.*' => ['required', 'string', 'max:150', 'regex:/^[a-zA-Z0-9_-]+$/D']]);
        $scope = isset($data['inbox_id']) ? (int) $data['inbox_id'] : null;
        $inbox->partner($actor, $partner, $scope);
        return $this->supportResponse(fn () => ['marked' => $inbox->read($actor, $partner, $data['ids'], $scope)]);
    }

    public function sendSupport(Request $request, SupportInbox $inbox, int $partner)
    {
        $actor = Auth::guard('admin')->user();
        $data = $request->validate(['message' => 'required|string|max:5000', 'inbox_id' => 'nullable|integer|min:1', 'request_key' => 'nullable|uuid']);
        $scope = isset($data['inbox_id']) ? (int) $data['inbox_id'] : null;
        $inbox->partner($actor, $partner, $scope);
        return $this->supportResponse(function () use ($request, $inbox, $actor, $partner, $data, $scope) {
            $message = $inbox->send($actor, $partner, $data['message'], $scope, $data['request_key'] ?? null);
            // Sending the push is best effort once the actual message has been stored.
            try {
                $request->merge(['user2' => $partner]);
                app(FcmNotificationsController::class)->send_chat_notification($request);
            } catch (\Throwable $error) {
                Log::warning('Support message push unavailable', ['actor_id' => $actor->id, 'exception' => get_class($error)]);
            }
            return ['message' => $message];
        });
    }

    protected function supportResponse(callable $action)
    {
        try {
            return response()->json(['success' => true] + $action())->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $error) {
            Log::warning('Dashboard support unavailable', ['exception' => get_class($error)]);
            return response()->json(['success' => false, 'message' => trans('dashboard_inbox.unavailable')], 503)
                ->header('Cache-Control', 'no-store, private');
        }
    }
}
