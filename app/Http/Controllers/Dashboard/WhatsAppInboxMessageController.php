<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\WhatsAppInboxMessage;
use App\Services\Dashboard\WhatsAppInboxMedia;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WhatsAppInboxMessageController extends Controller
{
    public function media(Request $request, string $conversation, string $message, WhatsAppInboxMedia $media)
    {
        return $this->image(fn () => $media->image(auth('admin')->user(), $this->id($conversation), $this->id($message)));
    }
    public function replyMedia(Request $request, string $conversation, string $reply, WhatsAppInboxMedia $media)
    {
        return $this->image(fn () => $media->replyImage(auth('admin')->user(), $this->id($conversation), $this->id($reply)));
    }
    public function delete(Request $request, string $conversation, string $message, WhatsAppInboxMessage $messages)
    {
        return $this->json(function () use ($request, $conversation, $message, $messages) {
            if ($request->except('_token')) throw new HttpException(422);
            return $messages->hide(auth('admin')->user(), $this->id($conversation), $this->id($message));
        });
    }
    public function selection(Request $request, string $conversation, WhatsAppInboxMessage $messages)
    {
        return $this->json(fn () => $messages->selection(auth('admin')->user(), $this->id($conversation)));
    }
    public function updates(Request $request, string $conversation, WhatsAppInboxMessage $messages)
    {
        return $this->json(function () use ($request, $conversation, $messages) {
            if (array_diff(array_keys($request->query()), ['message_ids', 'reply_ids'])) throw new HttpException(422);
            $sets = [];
            foreach (['message_ids', 'reply_ids'] as $field) {
                $values = $request->query($field, []);
                if (!is_array($values) || !array_is_list($values) || count($values) > 100) throw new HttpException(422);
                $ids = [];
                foreach ($values as $value) {
                    if (!is_string($value) && !is_int($value)) throw new HttpException(422);
                    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (!is_int($id)) throw new HttpException(422);
                    $ids[] = $id;
                }
                $sets[$field] = $ids;
            }
            return $messages->updates(auth('admin')->user(), $this->id($conversation), $sets['message_ids'], $sets['reply_ids']);
        });
    }
    public function deleteSelected(Request $request, string $conversation, WhatsAppInboxMessage $messages)
    {
        return $this->json(fn () => $messages->hideSelected(auth('admin')->user(), $this->id($conversation), $request->except('_token')));
    }
    private function image(callable $operation)
    {
        try { $image = $operation(); return response($image['bytes'], 200)->withHeaders($this->headers() + [
            'Content-Type' => $image['mime'], 'Content-Disposition' => 'inline; filename="whatsapp-image"',
            'Content-Security-Policy' => "default-src 'none'; sandbox"]); }
        catch (HttpException $error) { return response('', $error->getStatusCode())->withHeaders($this->headers()); }
        catch (\Throwable $error) { return response('', 503)->withHeaders($this->headers()); }
    }
    private function json(callable $operation)
    {
        try { return response()->json($operation())->withHeaders($this->headers()); }
        catch (HttpException $error) { return response()->json(['success' => false, 'error' => 'MESSAGE_ACTION_UNAVAILABLE'], $error->getStatusCode())->withHeaders($this->headers()); }
        catch (\Throwable $error) { return response()->json(['success' => false, 'error' => 'MESSAGE_ACTION_UNAVAILABLE'], 503)->withHeaders($this->headers()); }
    }
    private function id(string $id): int
    {
        $number = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($number)) throw new HttpException(404);
        return $number;
    }
    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-origin'];
    }
}
