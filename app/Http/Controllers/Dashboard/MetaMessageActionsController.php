<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\MetaMediaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MetaMessageActionsController extends Controller
{
    public function media(string $conversation, string $message, string $index, MetaMediaService $media)
    {
        try {
            $source = $media->source($this->id($conversation), $this->id($message), $this->index($index), auth('admin')->user());
            $file = $media->fetch($source);
            // Recheck scope/hide after network I/O before returning customer bytes.
            $media->source((int) $source['conversation_id'], (int) $source['message_id'], (int) $source['index'], auth('admin')->user());
            return response($file['body'])->withHeaders($this->privateHeaders() + [
                'Content-Type' => $file['mime'], 'Content-Length' => (string) strlen($file['body']),
                'Content-Disposition' => ($file['inline'] ? 'inline' : 'attachment') . '; filename="' . $file['name'] . '"',
                'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer']);
        } catch (HttpException $error) { return $this->failure($error->getStatusCode()); }
        catch (\Throwable $error) { return $this->failure(503); }
    }

    public function hide(string $conversation, string $message, MetaMediaService $media)
    {
        try {
            $conversation = $this->id($conversation); $message = $this->id($message);
            abort_unless(Schema::hasTable(MetaMediaService::HIDES_TABLE), 503);
            DB::transaction(function () use ($conversation, $message, $media) {
                $snapshot = $media->message($conversation, $message, auth('admin')->user());
                // Append an immutable per-actor audit marker; never edit or erase raw messages or order evidence.
                DB::table(MetaMediaService::HIDES_TABLE)->insertOrIgnore([
                    'actor_id' => (int) $snapshot['actor']->id, 'conversation_id' => $conversation,
                    'message_id' => $message, 'message_key' => $snapshot['message']->message_key,
                    'hidden_at' => now('UTC')]);
            }, 3);
            return response()->json(['success' => true, 'conversation_id' => $conversation,
                'message_id' => $message, 'hidden' => true])->withHeaders($this->privateHeaders());
        } catch (HttpException $error) { return $this->failure($error->getStatusCode()); }
        catch (\Throwable $error) { return $this->failure(503); }
    }

    private function id(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless(is_int($value), 404); return $value;
    }

    private function index(string $index): int
    {
        $value = filter_var($index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 29]]);
        abort_unless(is_int($value), 404); return $value;
    }

    private function failure(int $status)
    {
        return response()->json(['success' => false, 'error' => 'META_MESSAGE_ACTION_UNAVAILABLE'], $status)
            ->withHeaders($this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff'];
    }
}
