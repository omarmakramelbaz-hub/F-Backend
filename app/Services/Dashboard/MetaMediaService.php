<?php

namespace App\Services\Dashboard;

use App\Support\MetaMessagingProtocol;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Authorized media copies only; arbitrary URLs and customer credentials never enter the proxy. */
class MetaMediaService
{
    public const MAX_BYTES = 20971520;
    public const HIDES_TABLE = 'meta_message_hides';
    private $transport;

    public function __construct(?callable $transport = null) { $this->transport = $transport; }

    public function hiddenIds($actor, array $ids): array
    {
        $actor = app(MetaInboxAccess::class)->actor($actor);
        if (!$ids || !Schema::hasTable(self::HIDES_TABLE)) return [];
        return DB::table(self::HIDES_TABLE)->where('actor_id', (int) $actor->id)->whereIn('message_id', $ids)
            ->pluck('message_id')->map(static fn ($id) => (int) $id)->all();
    }

    public function descriptors(object $thread, object $message, ?array $dto): array
    {
        if (!$this->validMessage($thread, $message, $dto)) return [];
        // An accepted text receipt has no native attachment envelope.
        if (($message->source ?? null) === 'dashboard_api') return [];
        $attachments = $dto['content']['message']['meta']['attachments'] ?? [];
        if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) > 30) return [];
        $items = [];
        foreach ($attachments as $index => $attachment) {
            $item = $this->attachment($attachment, $index);
            if ($item === null) continue;
            $items[] = ['message_id' => (int) $message->id, 'index' => $index, 'type' => $item['type'],
                'url' => '/admin/meta/conversations/' . (int) $thread->id . '/messages/' . (int) $message->id . '/media/' . $index,
                'name' => $item['name']];
        }
        return $items;
    }

    /** Source references remain server-only, including when forwarding a received attachment. */
    public function source(int $conversation, int $message, int $index, $actor): array
    {
        abort_unless($index >= 0 && $index < 30, 404);
        $snapshot = $this->message($conversation, $message, $actor);
        abort_unless(!$snapshot['hidden'], 404);
        $attachments = $snapshot['dto']['content']['message']['meta']['attachments'] ?? [];
        abort_unless(is_array($attachments) && array_is_list($attachments) && count($attachments) <= 30, 404);
        $attachment = $this->attachment($attachments[$index] ?? null, $index);
        abort_unless($attachment !== null, 404);
        return ['conversation_id' => $conversation, 'message_id' => $message, 'index' => $index,
            'channel' => $snapshot['thread']->waba_id, 'account_id' => $snapshot['thread']->phone_number_id] + $attachment;
    }

    public function message(int $conversation, int $message, $actor): array
    {
        $actor = app(MetaInboxAccess::class)->actor($actor);
        abort_unless(app(MetaInboxAccess::class)->available(), 503);
        abort_unless($conversation > 0 && $message > 0, 404);
        $thread = MetaInboxAccess::scope(DB::table('meta_inbox_conversations'))->where('id', $conversation)->first();
        abort_unless($thread, 404);
        $row = DB::table('meta_inbox_messages')->where('conversation_id', $conversation)->where('id', $message)->first();
        abort_unless($row, 404);
        $dto = $this->decrypt($row->content);
        abort_unless($this->validMessage($thread, $row, $dto), 404);
        $hidden = in_array($message, $this->hiddenIds($actor, [$message]), true);
        return ['thread' => $thread, 'message' => $row, 'dto' => $dto, 'actor' => $actor, 'hidden' => $hidden];
    }

    /** Bounded byte fetch; no redirects, bearer token, cookies, or client-provided URL. */
    public function fetch(array $source): array
    {
        abort_unless($this->safeUrl($source['url'] ?? null), 404);
        if ($this->transport !== null) $response = ($this->transport)($source['url'], self::MAX_BYTES);
        else $response = $this->download($source['url']);
        abort_unless(is_array($response) && ($response['status'] ?? null) === 200
            && is_string($response['body'] ?? null) && $response['body'] !== ''
            && strlen($response['body']) <= self::MAX_BYTES, 502);
        $claimed = $this->canonicalMime(strtolower(trim(explode(';', (string) ($response['content_type'] ?? ''), 2)[0])));
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($response['body']);
        $mime = is_string($detected) ? $this->canonicalMime($detected) : null;
        if (($source['type'] ?? '') === 'audio') {
            if ($mime === 'video/mp4') $mime = 'audio/mp4';
            if ($claimed === 'video/mp4') $claimed = 'audio/mp4';
        }
        abort_unless(is_string($mime) && $this->allowedMime($source['type'] ?? '', $mime)
            && $this->compatibleMime($source['type'] ?? '', $claimed, $mime), 415);
        return ['body' => $response['body'], 'mime' => $mime,
            'inline' => ($source['type'] ?? '') !== 'document', 'name' => $this->filename($source['type'], $mime)];
    }

    private function validMessage(object $thread, object $message, ?array $dto): bool
    {
        if (($message->source ?? null) === 'dashboard_api') {
            try {
                if ($dto === null || ($message->direction ?? null) !== 'outbound' || ($message->type ?? null) !== 'text'
                    || !is_string($message->content ?? null) || $dto !== $this->decrypt($message->content)) return false;
                $receipt = app(MetaReplyService::class)->unwrapAcceptedEvidence($thread, $message);
                // Keep the authenticated receipt capsule intact. Forwarding uses its text;
                // neither media actions nor hide may manufacture a native Meta message.
                return is_string($dto['text'] ?? null) && $dto['text'] === ($receipt['text'] ?? null);
            } catch (\Throwable $error) { return false; }
        }
        if ($dto === null || ($dto['channel'] ?? null) !== $thread->waba_id
            || ($dto['account_id'] ?? null) !== $thread->phone_number_id
            || (!is_int($message->id) && !ctype_digit((string) $message->id))
            || (int) $message->conversation_id !== (int) $thread->id
            || !is_string($dto['message_id'] ?? null) || $dto['message_id'] === ''
            || strlen($dto['message_id']) > 512
            || !in_array($dto['direction'] ?? null, ['inbound', 'outbound'], true)
            || ($dto['direction'] ?? null) !== $message->direction
            || ($dto['type'] ?? null) !== $message->type
            || ($dto['sent_at'] ?? null) !== $message->sent_at) return false;
        $customer = $this->decrypt($thread->customer);
        $peer = $customer['user_id'] ?? null;
        if (!MetaMessagingProtocol::id($peer) || ($dto['peer_identity'] ?? null) !== 'user:' . $peer) return false;
        $scope = $thread->waba_id . ':' . $thread->phone_number_id . ':';
        return hash_equals($thread->peer_hash, hash_hmac('sha256', 'whatsapp-peer-v1:' . $scope . 'user:' . $peer, Crypt::getKey()))
            && hash_equals($message->message_key, hash('sha256', 'whatsapp-message-v1:' . $scope . $dto['message_id']));
    }

    private function attachment($attachment, int $index): ?array
    {
        if (!is_array($attachment)) return null;
        $type = $attachment['type'] ?? null;
        $type = $type === 'file' ? 'document' : $type;
        if (!in_array($type, ['image', 'audio', 'video', 'document'], true)) return null;
        $url = $attachment['payload']['url'] ?? null;
        if (!$this->safeUrl($url)) return null;
        return ['type' => $type, 'url' => $url, 'name' => 'meta-' . $type . '-' . ($index + 1)];
    }

    public function safeUrl($url): bool
    {
        if (!is_string($url) || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) return false;
        $host = strtolower($parts['host']);
        if (!preg_match('/\A[a-z0-9.-]+\z/', $host)) return false;
        foreach (['fbcdn.net', 'fbsbx.com', 'cdninstagram.com'] as $domain) {
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) return true;
        }
        return false;
    }

    private function download(string $url): array
    {
        abort_unless(function_exists('curl_init'), 503);
        $host = parse_url($url, PHP_URL_HOST);
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) continue;
            abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 502);
            $ips[] = $ip;
        }
        abort_unless($ips !== [], 502);
        $body = ''; $bytes = 0; $curl = curl_init($url);
        $resolved = strpos($ips[0], ':') === false ? $ips[0] : '[' . $ips[0] . ']';
        curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$host . ':443:' . $resolved],
            CURLOPT_HTTPHEADER => ['Accept: image/*, audio/*, video/*, application/pdf, application/octet-stream'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) {
                if (preg_match('/\AContent-Length:\s*([0-9]+)/i', $line, $match)
                    && (float) $match[1] > self::MAX_BYTES) return 0;
                return strlen($line);
            }, CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$bytes) {
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        try {
            $ok = curl_exec($curl);
            $response = ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'content_type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 'body' => $body];
        } finally { curl_close($curl); }
        return $response;
    }

    private function allowedMime(string $type, string $mime): bool
    {
        $types = [
            'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
            'audio' => ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'video/webm'],
            'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
            'document' => ['application/pdf', 'text/plain', 'application/zip', 'application/x-zip', 'application/x-zip-compressed',
                'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/octet-stream'],
        ];
        return in_array($mime, $types[$type] ?? [], true);
    }

    private function canonicalMime(string $mime): string
    {
        return ['audio/x-hx-aac-adts' => 'audio/aac', 'audio/x-amr' => 'audio/amr', 'image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'audio/x-m4a' => 'audio/mp4',
            'audio/m4a' => 'audio/mp4', 'audio/x-wav' => 'audio/wav', 'audio/wave' => 'audio/wav',
            'application/ogg' => 'audio/ogg', 'application/x-zip' => 'application/zip',
            'application/x-zip-compressed' => 'application/zip'][$mime] ?? $mime;
    }

    private function compatibleMime(string $type, string $claimed, string $detected): bool
    {
        if ($claimed === $detected || $claimed === 'application/octet-stream') return true;
        // WebM audio is the same verified container when libmagic reports its generic video MIME.
        if ($type === 'audio' && in_array($claimed, ['audio/webm', 'video/webm'], true)
            && in_array($detected, ['audio/webm', 'video/webm'], true)) return true;
        // Office files are ZIP containers. Serve them as a forced ZIP download when libmagic
        // does not recognize the Office subtype; never trust the claimed subtype for execution.
        return $type === 'document' && $detected === 'application/zip' && in_array($claimed, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'], true);
    }

    private function filename(string $type, string $mime): string
    {
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'image/avif' => 'avif', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
            'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/webm' => 'webm',
            'video/webm' => 'webm', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'application/pdf' => 'pdf',
            'text/plain' => 'txt', 'application/zip' => 'zip', 'application/x-zip' => 'zip', 'application/x-zip-compressed' => 'zip'];
        return 'meta-' . $type . '.' . ($extensions[$mime] ?? 'bin');
    }

    private function decrypt(string $value): ?array
    {
        try {
            $plain = Crypt::decryptString($value);
            if (strlen($plain) > 4194304) return null;
            $decoded = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $error) { return null; }
    }
}
