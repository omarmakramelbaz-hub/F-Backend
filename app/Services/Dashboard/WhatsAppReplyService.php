<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Private, at-most-once manual replies. UNKNOWN requires an explicit delivery review. */
class WhatsAppReplyService
{
    private const BASE = 'https://graph.facebook.com/v25.0/515388018324075/';
    private const WABA = '468336579702269';
    private const PHONE = '515388018324075';
    private $transport;
    private $authorizer;
    private $clock;
    private ?array $configuration;
    private WhatsAppVoiceMedia $voiceMedia;

    /** Seams are for isolated fixtures; production always reloads central Owner/Admin access. */
    public function __construct(?callable $transport = null, ?callable $authorizer = null,
        ?callable $clock = null, ?array $configuration = null, ?WhatsAppVoiceMedia $voiceMedia = null)
    {
        $this->transport = $transport;
        $this->authorizer = $authorizer;
        $this->clock = $clock;
        $this->configuration = $configuration;
        $this->voiceMedia = $voiceMedia ?? new WhatsAppVoiceMedia();
    }

    public function state($actor, int $conversation, ?string $clientRequestId = null): array
    {
        $fresh = $this->actor($actor);
        if ($clientRequestId !== null && !$this->uuid($clientRequestId)) throw new HttpException(422);
        $base = ['success' => true, 'available' => false, 'can_reply' => false,
            'reason' => 'REPLIES_UNAVAILABLE', 'window_expires_at' => null, 'latest_inbound_id' => null,
            'voice_ready' => false, 'voice_mime_types' => [], 'request_state' => null,
            'request_reason' => null, 'reply_id' => null, 'recent_replies' => []];
        if (!$this->tables()) return $base;
        $thread = $this->thread($conversation);
        if (!$thread) throw new HttpException(404);
        $base['recent_replies'] = $this->recent($conversation);
        if ($clientRequestId !== null) {
            $row = $this->requests($conversation)->where('request_hash',
                $this->requestHash((int) $fresh->id, $conversation, $clientRequestId))->first();
            if ($row) {
                $base['request_state'] = $this->publicState($row->state);
                $base['request_reason'] = $this->publicReason($row->reason);
                $base['reply_id'] = (int) $row->id;
            }
        }
        $snapshot = $this->snapshot($thread);
        $base['latest_inbound_id'] = $snapshot['inbound_id'];
        $base['window_expires_at'] = $snapshot['expires'];
        $base['available'] = $this->configured();
        $base['voice_ready'] = $base['available'] && $this->voiceMedia->available();
        $base['voice_mime_types'] = $base['voice_ready']
            ? ['audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm;codecs=opus'] : [];
        $base['reason'] = !$base['available'] ? 'REPLIES_UNAVAILABLE'
            : ($this->unresolved($conversation) ? 'UNKNOWN_PENDING' : $snapshot['reason']);
        $base['can_reply'] = $base['reason'] === null;
        return $base;
    }

    public function send($actor, int $conversation, array $input): array
    {
        $fresh = $this->actor($actor);
        $request = $this->input($input, false);
        return $this->submit($fresh, $conversation, $request, null);
    }

    public function voice($actor, int $conversation, array $input, UploadedFile $file): array
    {
        $fresh = $this->actor($actor);
        $request = $this->input($input, true);
        // Digest the actual uploaded bytes, never a browser-provided name/path/MIME.
        if (!$file->isValid() || is_link($file->getPathname()) || !is_file($file->getPathname())
            || $file->getSize() < 1 || $file->getSize() > WhatsAppVoiceMedia::MAX_BYTES) throw new HttpException(422);
        $digest = hash_file('sha256', $file->getPathname());
        if (!is_string($digest)) throw new HttpException(422);
        $request['voice_sha256'] = $digest;
        // An existing UUID is resolved without decoding, uploading, or sending again.
        $replay = $this->replay($fresh, $conversation, $request);
        if ($replay !== null) return $replay;
        if (!$this->voiceMedia->available()) return $this->result('FAILED', false, null, 'VOICE_UNAVAILABLE');
        $prepared = null;
        try {
            $prepared = $this->voiceMedia->prepare($file);
            if (!is_string($prepared['input_sha256'] ?? null) || !hash_equals($digest, $prepared['input_sha256'])) {
                if (is_string($prepared['path'] ?? null)) $this->voiceMedia->cleanup($prepared['path']);
                return $this->result('FAILED', false, null, 'INVALID_VOICE');
            }
        } catch (\Throwable $error) {
            return $this->result('FAILED', false, null, 'INVALID_VOICE');
        }
        try {
            return $this->submit($fresh, $conversation, $request, $prepared);
        } finally {
            if (is_array($prepared) && is_string($prepared['path'] ?? null)) $this->voiceMedia->cleanup($prepared['path']);
        }
    }

    private function submit($actor, int $conversation, array $request, ?array $prepared): array
    {
        if (!$this->tables()) return $this->result('FAILED', false, null, 'REPLIES_UNAVAILABLE');
        // Lock the thread so competing UUIDs cannot both pass the unresolved-claim gate.
        $claim = DB::transaction(function () use ($actor, $conversation, $request) {
            $fresh = $this->actor($actor);
            if ((int) $fresh->id !== (int) $actor->id) throw new HttpException(403);
            $thread = $this->thread($conversation, true);
            if (!$thread) throw new HttpException(404);
            $hash = $this->requestHash((int) $fresh->id, $conversation, $request['client_request_id']);
            $payloadHash = $this->payloadHash($request);
            // MySQL REPEATABLE READ: actor lookup may have opened a snapshot before waiting
            // for the thread lock. Durable claim gates MUST use current locking reads.
            $old = $this->requests($conversation)->where('request_hash', $hash)->lockForUpdate()->first();
            if ($old) return ['result' => $this->duplicate($old, $payloadHash)];
            $snapshot = $this->snapshot($thread, true);
            $reason = !$this->configured() ? 'REPLIES_UNAVAILABLE'
                : ($this->unresolved($conversation, true) ? 'UNKNOWN_PENDING' : $snapshot['reason']);
            if ($reason === null && $snapshot['inbound_id'] !== $request['expected_inbound_id']) $reason = 'STALE_INBOUND';
            if ($reason !== null) return ['result' => $this->result('FAILED', false, null, $reason)];
            $stamp = $this->now()->format('Y-m-d H:i:s');
            $audit = ['client_request_id' => strtolower($request['client_request_id']),
                'text' => $request['text'], 'recipient' => $snapshot['recipient'],
                'voice_sha256' => $request['voice_sha256'] ?? null];
            $id = DB::table('whatsapp_reply_requests')->insertGetId([
                'conversation_id' => $conversation, 'actor_id' => (int) $fresh->id,
                'waba_id' => self::WABA, 'phone_number_id' => self::PHONE,
                'request_hash' => $hash, 'payload_hash' => $payloadHash,
                'inbound_message_id' => $snapshot['inbound_id'], 'kind' => $request['kind'],
                'state' => 'CLAIMED', 'audit' => $this->encrypt($audit), 'reason' => null,
                'claimed_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            return ['id' => (int) $id, 'snapshot' => $snapshot];
        }, 1);
        if (isset($claim['result'])) return $claim['result'];
        $id = $claim['id'];
        $snapshot = $claim['snapshot'];
        try {
            $reason = $this->preflight($actor, $conversation, $snapshot);
            if ($reason !== null) return $this->finish($id, 'FAILED', $reason, ['CLAIMED']);
            $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
                'to' => $snapshot['recipient'], 'type' => $request['kind']];
            if ($prepared !== null) {
                $upload = $this->call('media', ['messaging_product' => 'whatsapp', 'type' => 'audio/ogg',
                    'file' => $prepared], true);
                $media = $this->decode($upload);
                if ($upload['status'] !== 200 || isset($media['error']) || !is_string($media['id'] ?? null)
                    || !preg_match('/\A[0-9]{1,32}\z/', $media['id'])) {
                    return $this->finish($id, 'FAILED', 'MEDIA_UPLOAD_FAILED', ['CLAIMED']);
                }
                $payload['audio'] = ['id' => $media['id']];
            } else {
                $payload['text'] = ['preview_url' => false, 'body' => $request['text']];
            }
            $reason = $this->preflight($actor, $conversation, $snapshot);
            if ($reason !== null) return $this->finish($id, 'FAILED', $reason, ['CLAIMED']);
            // Persist uncertainty BEFORE the customer-facing call. A killed process stays blocked.
            $started = $this->now()->format('Y-m-d H:i:s');
            $changed = DB::table('whatsapp_reply_requests')->where('id', $id)->where('state', 'CLAIMED')
                ->whereNull('send_started_at')->update(['state' => 'UNKNOWN', 'reason' => 'DELIVERY_UNKNOWN',
                    'send_started_at' => $started, 'updated_at' => $started]);
            if ($changed !== 1) return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
            // No stale session role can authorize this final network boundary.
            $reason = $this->preflight($actor, $conversation, $snapshot);
            if ($reason !== null) return $this->finish($id, 'FAILED', $reason, ['UNKNOWN']);
            $response = $this->call('messages', $payload, false);
            $body = $this->decode($response);
            $wamid = $this->accepted($response['status'], $body, $snapshot['recipient']);
            if ($wamid !== null) return $this->finish($id, 'ACCEPTED', null, ['UNKNOWN'], $wamid);
            if (in_array($response['status'], [400, 401, 403, 404, 405, 409, 410, 413, 415, 422, 429], true)
                && is_int($body['error']['code'] ?? null) && !isset($body['messages'])) {
                return $this->finish($id, 'FAILED', 'META_REJECTED', ['UNKNOWN']);
            }
            return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
        } catch (\Throwable $error) {
            // Only a persisted no-send state proves a safe failure. All crashes stay conservative.
            return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
        }
    }

    private function replay($actor, int $conversation, array $request): ?array
    {
        if (!$this->tables()) return $this->result('FAILED', false, null, 'REPLIES_UNAVAILABLE');
        if (!$this->thread($conversation)) throw new HttpException(404);
        $old = $this->requests($conversation)->where('request_hash',
            $this->requestHash((int) $actor->id, $conversation, $request['client_request_id']))->first();
        return $old ? $this->duplicate($old, $this->payloadHash($request)) : null;
    }

    private function preflight($actor, int $conversation, array $snapshot): ?string
    {
        try {
            $fresh = $this->actor($actor);
            if ((int) $fresh->id !== (int) $actor->id) return 'ACCESS_REVOKED';
        } catch (\Throwable $error) { return 'ACCESS_REVOKED'; }
        if (!$this->configured()) return 'REPLIES_UNAVAILABLE';
        $thread = $this->thread($conversation);
        if (!$thread) return 'RECIPIENT_UNAVAILABLE';
        $current = $this->snapshot($thread);
        if ($current['reason'] !== null) return $current['reason'];
        if ($current['inbound_id'] !== $snapshot['inbound_id']
            || $current['recipient'] !== $snapshot['recipient']) return 'STALE_INBOUND';
        return null;
    }

    private function snapshot($thread, bool $lock = false): array
    {
        $result = ['inbound_id' => null, 'expires' => null, 'recipient' => null, 'reason' => 'NO_INBOUND'];
        $query = DB::table('whatsapp_inbox_messages')->where('conversation_id', $thread->id)
            ->where('direction', 'inbound')->orderByDesc('sent_at')->orderByDesc('id');
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row) return $result;
        $result['inbound_id'] = (int) $row->id;
        $result['reason'] = 'RECIPIENT_UNAVAILABLE';
        try {
            $dto = $this->decrypt($row->content);
            $raw = $dto['content']['message'] ?? null;
            $user = $dto['peer_user_id'] ?? null;
            $phone = $this->phone($dto['peer_phone'] ?? null);
            $identity = is_string($user) && $user !== '' ? 'user:' . $user : ($phone !== null ? 'phone:' . $phone : null);
            $scope = self::WABA . ':' . self::PHONE . ':';
            if (!is_array($raw) || !is_string($dto['message_id'] ?? null) || strlen($dto['message_id']) > 512
                || ($raw['id'] ?? null) !== $dto['message_id'] || $identity === null
                || ($dto['peer_identity'] ?? null) !== $identity || $phone === null
                || $this->phone($raw['from'] ?? null) !== $phone
                || (isset($raw['from_user_id']) && $raw['from_user_id'] !== $user)
                || ($dto['direction'] ?? null) !== 'inbound' || ($dto['type'] ?? null) !== $row->type
                || ($dto['sent_at'] ?? null) !== $row->sent_at || ($dto['source'] ?? null) !== $row->source
                || !in_array($row->source, ['messages', 'standby'], true)
                || !hash_equals((string) $row->message_key, hash('sha256', 'whatsapp-message-v1:' . $scope . $dto['message_id']))
                || !hash_equals((string) $thread->peer_hash, hash_hmac('sha256', 'whatsapp-peer-v1:' . $scope . $identity, $this->key()))) return $result;
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row->sent_at, new \DateTimeZone('UTC'));
            if (!$time || $time->format('Y-m-d H:i:s') !== $row->sent_at
                || !preg_match('/\A[0-9]{1,10}\z/', (string) ($raw['timestamp'] ?? ''))
                || (int) $raw['timestamp'] !== $time->getTimestamp()) return $result;
            $now = $this->now()->getTimestamp();
            if ($time->getTimestamp() > $now) return $result;
            $result['expires'] = gmdate('Y-m-d\TH:i:s\Z', $time->getTimestamp() + 86400);
            $result['recipient'] = $phone;
            $result['reason'] = $now >= $time->getTimestamp() + 86400 ? 'WINDOW_CLOSED' : null;
            return $result;
        } catch (\Throwable $error) { return $result; }
    }

    private function input(array $input, bool $voice): array
    {
        $wanted = $voice ? ['client_request_id', 'expected_inbound_id'] : ['client_request_id', 'expected_inbound_id', 'text'];
        if (array_diff(array_keys($input), $wanted) || array_diff($wanted, array_keys($input))
            || !$this->uuid($input['client_request_id'] ?? null)) throw new HttpException(422);
        $expected = filter_var($input['expected_inbound_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($expected)) throw new HttpException(422);
        $text = $voice ? null : $input['text'];
        if (!$voice && (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || trim($text) === ''
            || mb_strlen($text, 'UTF-8') > 4096 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text))) throw new HttpException(422);
        return ['client_request_id' => strtolower($input['client_request_id']),
            'expected_inbound_id' => $expected, 'text' => $text, 'kind' => $voice ? 'audio' : 'text'];
    }

    private function accepted(int $status, array $body, string $recipient): ?string
    {
        if ($status !== 200 || ($body['messaging_product'] ?? null) !== 'whatsapp'
            || isset($body['error']) || !is_array($body['messages'] ?? null) || count($body['messages']) !== 1) return null;
        $id = $body['messages'][0]['id'] ?? null;
        if (!is_string($id) || !preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $id)) return null;
        if (isset($body['contacts'])) {
            if (!is_array($body['contacts']) || count($body['contacts']) !== 1
                || $this->phone($body['contacts'][0]['input'] ?? null) !== $recipient
                || $this->phone($body['contacts'][0]['wa_id'] ?? null) !== $recipient) return null;
        }
        return $id;
    }

    private function call(string $resource, array $payload, bool $multipart): array
    {
        $endpoint = self::BASE . $resource;
        $token = $this->configuration()['access_token'];
        if ($this->transport !== null) {
            $response = ($this->transport)($endpoint, $payload, $token, $multipart);
            return is_array($response) && is_int($response['status'] ?? null) && is_string($response['body'] ?? null)
                && strlen($response['body']) <= 1048576 ? $response : ['status' => 0, 'body' => ''];
        }
        $body = '';
        $handle = curl_init($endpoint);
        if ($handle === false) return ['status' => 0, 'body' => ''];
        if ($multipart) {
            $file = $payload['file'];
            $payload['file'] = new \CURLFile($file['path'], $file['mime'], $file['filename']);
            $encoded = $payload;
            $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        } else {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'];
        }
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 1048576) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        try {
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            return $ok === false ? ['status' => 0, 'body' => ''] : ['status' => $status, 'body' => $body];
        } finally { curl_close($handle); }
    }

    private function finish(int $id, string $state, ?string $reason, array $from, ?string $wamid = null): array
    {
        $stamp = $this->now()->format('Y-m-d H:i:s');
        $values = ['state' => $state, 'reason' => $reason, 'completed_at' => $stamp, 'updated_at' => $stamp];
        if ($wamid !== null) {
            $values['remote_message_key'] = hash('sha256', 'whatsapp-reply-message-v1:' . self::WABA . ':' . self::PHONE . ':' . $wamid);
            $values['response_details'] = $this->encrypt(['message_id' => $wamid]);
        }
        $changed = DB::table('whatsapp_reply_requests')->where('id', $id)->whereIn('state', $from)->update($values);
        return $changed === 1 ? $this->result($state, false, $id, $reason)
            : $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
    }

    private function recent(int $conversation): array
    {
        $items = [];
        foreach ($this->requests($conversation)->orderByDesc('id')->limit(20)->get() as $row) {
            try {
                $audit = $this->decrypt($row->audit);
                $items[] = ['reply_id' => (int) $row->id, 'client_request_id' => $audit['client_request_id'],
                    'state' => $this->publicState($row->state), 'kind' => $row->kind,
                    'text' => $row->kind === 'text' ? $audit['text'] : null,
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row->created_at . ' UTC')),
                    'actor_id' => (int) $row->actor_id];
            } catch (\Throwable $error) { /* Cipher failures never reveal stored bytes. */ }
        }
        return array_reverse($items);
    }

    private function duplicate($old, string $payloadHash): array
    {
        if (!hash_equals($old->payload_hash, $payloadHash)) throw new HttpException(409);
        return $this->result($this->publicState($old->state), true, (int) $old->id, $this->publicReason($old->reason));
    }

    private function result(string $state, bool $replayed, ?int $id, ?string $reason): array
    {
        return ['success' => $state === 'ACCEPTED', 'state' => $state, 'replayed' => $replayed,
            'reply_id' => $id, 'reason' => $reason];
    }

    private function actor($actor)
    {
        $fresh = $this->authorizer !== null ? ($this->authorizer)($actor)
            : app(WhatsAppInboxAccess::class)->actor($actor);
        if (!is_object($fresh) || !is_numeric($fresh->id ?? null) || (int) $fresh->id < 1) throw new HttpException(403);
        return $fresh;
    }

    private function thread(int $id, bool $lock = false)
    {
        $query = DB::table('whatsapp_inbox_conversations')->where('id', $id)->where('waba_id', self::WABA)
            ->where('phone_number_id', self::PHONE);
        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function requests(int $conversation)
    {
        return DB::table('whatsapp_reply_requests')->where('conversation_id', $conversation)
            ->where('waba_id', self::WABA)->where('phone_number_id', self::PHONE);
    }

    private function unresolved(int $conversation, bool $lock = false): bool
    {
        $query = $this->requests($conversation)->whereIn('state', ['CLAIMED', 'UNKNOWN']);
        return $lock ? $query->lockForUpdate()->first(['id']) !== null : $query->exists();
    }

    private function tables(): bool
    {
        return !config('desktop_dashboard.local', false) && Schema::hasTable('whatsapp_reply_requests')
            && Schema::hasTable('whatsapp_inbox_conversations') && Schema::hasTable('whatsapp_inbox_messages');
    }

    private function configuration(): array { return $this->configuration ?? (array) config('whatsapp_replies', []); }

    private function configured(): bool
    {
        $config = $this->configuration();
        $token = $config['access_token'] ?? null;
        return ($config['enabled'] ?? false) === true && is_string($token) && strlen($token) >= 16
            && strlen($token) <= 4096 && !preg_match('/[\s\x00-\x1f\x7f]/', $token)
            && ($this->transport !== null || function_exists('curl_init'));
    }

    private function now(): CarbonImmutable
    {
        return $this->clock !== null ? CarbonImmutable::instance(($this->clock)())->utc() : CarbonImmutable::now('UTC');
    }

    private function uuid($value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) === 1;
    }

    private function phone($value): ?string
    {
        return is_string($value) && preg_match('/\A\+?[1-9][0-9]{7,14}\z/', $value) ? ltrim($value, '+') : null;
    }

    private function key(): string
    {
        $key = (string) config('app.key', '');
        if (strpos($key, 'base64:') === 0) $key = base64_decode(substr($key, 7), true);
        if (!is_string($key) || strlen($key) < 16) throw new \RuntimeException('PRIVATE_CONFIGURATION');
        return $key;
    }

    private function requestHash(int $actor, int $conversation, string $uuid): string
    {
        return hash_hmac('sha256', 'whatsapp-reply-request-v1:' . $actor . ':' . $conversation . ':' . strtolower($uuid), $this->key());
    }

    private function payloadHash(array $request): string
    {
        return hash_hmac('sha256', 'whatsapp-reply-payload-v1:' . json_encode([
            $request['kind'], $request['expected_inbound_id'], $request['text'], $request['voice_sha256'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->key());
    }

    private function encrypt(array $data): string { return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); }

    private function decrypt(string $value): array
    {
        $plain = Crypt::decryptString($value);
        if (strlen($plain) > 1048576) throw new \RuntimeException('PRIVATE_DATA');
        $data = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('PRIVATE_DATA');
        return $data;
    }

    private function decode(array $response): array
    {
        try { $body = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { return []; }
        return is_array($body) ? $body : [];
    }

    private function publicState(string $state): string { return in_array($state, ['ACCEPTED', 'FAILED'], true) ? $state : 'UNKNOWN'; }

    private function publicReason(?string $reason): ?string
    {
        return $reason === null ? null : (in_array($reason, ['REPLIES_UNAVAILABLE', 'VOICE_UNAVAILABLE', 'INVALID_VOICE',
            'NO_INBOUND', 'RECIPIENT_UNAVAILABLE', 'WINDOW_CLOSED', 'UNKNOWN_PENDING', 'STALE_INBOUND',
            'ACCESS_REVOKED', 'MEDIA_UPLOAD_FAILED', 'META_REJECTED', 'DELIVERY_UNKNOWN'], true) ? $reason : 'DELIVERY_UNKNOWN');
    }
}
