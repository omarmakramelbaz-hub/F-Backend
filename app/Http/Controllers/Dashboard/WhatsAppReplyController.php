<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\WhatsAppReplyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WhatsAppReplyController extends Controller
{
    public function state(Request $request, string $conversation, WhatsAppReplyService $replies)
    {
        return $this->respond(function () use ($request, $conversation, $replies) {
            if (array_diff(array_keys($request->query()), ['client_request_id'])) throw new HttpException(422);
            $uuid = $request->query('client_request_id');
            if ($uuid !== null && !is_string($uuid)) throw new HttpException(422);
            return $replies->state(auth('admin')->user(), $this->conversation($conversation), $uuid);
        });
    }

    public function send(Request $request, string $conversation, WhatsAppReplyService $replies)
    {
        return $this->respond(function () use ($request, $conversation, $replies) {
            return $replies->send(auth('admin')->user(), $this->conversation($conversation), $request->except('_token'));
        });
    }

    public function history(Request $request, string $conversation, WhatsAppReplyService $replies)
    {
        return $this->respond(function () use ($request, $conversation, $replies) {
            if (array_diff(array_keys($request->query()), ['before_reply_id', 'known_reply_ids'])) throw new HttpException(422);
            $before = $request->query('before_reply_id');
            if ($before !== null && !is_string($before)) throw new HttpException(422);
            $known = $request->query('known_reply_ids', []);
            if (!is_array($known) || !array_is_list($known) || count($known) > 100) throw new HttpException(422);
            $ids = [];
            foreach ($known as $id) {
                if (!is_string($id) && !is_int($id)) throw new HttpException(422);
                $number = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!is_int($number)) throw new HttpException(422);
                $ids[] = $number;
            }
            return $replies->history(auth('admin')->user(), $this->conversation($conversation),
                $before === null ? null : $this->conversation($before), $ids);
        });
    }

    public function voice(Request $request, string $conversation, WhatsAppReplyService $replies)
    {
        return $this->respond(function () use ($request, $conversation, $replies) {
            $file = $request->file('audio');
            if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) throw new HttpException(422);
            return $replies->voice(auth('admin')->user(), $this->conversation($conversation),
                $request->except(['_token', 'audio']), $file);
        });
    }

    public function forward(Request $request, string $conversation, string $message, WhatsAppReplyService $replies)
    {
        return $this->respond(fn () => $replies->forward(auth('admin')->user(), $this->conversation($conversation),
            $this->conversation($message), $request->except('_token')));
    }
    public function forwardReply(Request $request, string $conversation, string $reply, WhatsAppReplyService $replies)
    {
        return $this->respond(fn () => $replies->forwardReply(auth('admin')->user(), $this->conversation($conversation),
            $this->conversation($reply), $request->except('_token')));
    }
    public function reaction(Request $request, string $conversation, string $message, WhatsAppReplyService $replies)
    {
        return $this->respond(fn () => $replies->reaction(auth('admin')->user(), $this->conversation($conversation),
            $this->conversation($message), $request->except('_token')));
    }
    public function reactionReply(Request $request, string $conversation, string $reply, WhatsAppReplyService $replies)
    {
        return $this->respond(fn () => $replies->reactionReply(auth('admin')->user(), $this->conversation($conversation),
            $this->conversation($reply), $request->except('_token')));
    }

    private function respond(callable $operation)
    {
        try {
            $body = $operation();
            $reason = $body['reason'] ?? null;
            $status = ($body['state'] ?? null) === 'UNKNOWN' ? 202 : 200;
            if (($body['state'] ?? null) === 'FAILED') {
                $status = in_array($reason, ['STALE_INBOUND', 'WINDOW_CLOSED', 'UNKNOWN_PENDING'], true) ? 409
                    : ($reason === 'ACCESS_REVOKED' ? 403
                        : (in_array($reason, ['REPLIES_UNAVAILABLE', 'VOICE_UNAVAILABLE'], true) ? 503
                            : (in_array($reason, ['META_REJECTED', 'MEDIA_UPLOAD_FAILED'], true) ? 502 : 422)));
            }
        } catch (HttpException $error) {
            $status = $error->getStatusCode();
            $reason = [403 => 'ACCESS_DENIED', 404 => 'CONVERSATION_UNAVAILABLE',
                409 => 'REQUEST_CONFLICT', 422 => 'INVALID_INPUT'][$status] ?? 'REPLIES_UNAVAILABLE';
            $body = ['success' => false, 'state' => 'FAILED', 'replayed' => false,
                'reply_id' => null, 'reason' => $reason];
        } catch (\Throwable $error) {
            $status = 503;
            // A crash may follow an actual send; clients resolve their UUID through reply-state.
            $body = ['success' => false, 'state' => 'UNKNOWN', 'replayed' => false,
                'reply_id' => null, 'reason' => 'REPLIES_UNAVAILABLE'];
        }
        return response()->json($body, $status)->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function conversation(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) throw new HttpException(404);
        return $id;
    }
}
