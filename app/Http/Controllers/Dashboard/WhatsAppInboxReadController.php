<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\WhatsAppInboxAccess;
use App\Services\Dashboard\WhatsAppInboxReadState;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WhatsAppInboxReadController extends Controller
{
    public function unread(Request $request, WhatsAppInboxReadState $reads)
    {
        return $this->respond(function () use ($request, $reads) {
            $input = $request->validate(['ids' => 'sometimes|array|max:100',
                'ids.*' => 'required|integer|min:1|max:' . PHP_INT_MAX]);
            return $reads->unread(auth('admin')->user(), $input['ids'] ?? null);
        });
    }

    public function read(Request $request, string $conversation, WhatsAppInboxReadState $reads)
    {
        return $this->respond(function () use ($request, $conversation, $reads) {
            $id = filter_var($conversation, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless(is_int($id), 404);
            $input = $request->validate(['seen_message_id' => 'required|integer|min:1|max:' . PHP_INT_MAX]);
            return $reads->markRead($id, $input['seen_message_id'], auth('admin')->user());
        });
    }

    private function respond(callable $callback)
    {
        try {
            app(WhatsAppInboxAccess::class)->actor(auth('admin')->user());
            return response()->json($callback())->withHeaders($this->privateHeaders());
        } catch (ValidationException $error) {
            return $this->failure(422);
        } catch (HttpException $error) {
            return $this->failure($error->getStatusCode());
        } catch (\Throwable $error) {
            return $this->failure(503);
        }
    }

    private function failure(int $status)
    {
        return response()->json(['success' => false, 'error' => 'INBOX_READS_UNAVAILABLE'], $status)
            ->withHeaders($this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff'];
    }
}
