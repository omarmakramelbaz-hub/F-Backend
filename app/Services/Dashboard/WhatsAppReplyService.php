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
    private WhatsAppInboxMessage $messages;
    private WhatsAppInboxMedia $attachmentMedia;

    /** Seams are for isolated fixtures; production always reloads central Owner/Admin access. */
    public function __construct(?callable $transport = null, ?callable $authorizer = null,
        ?callable $clock = null, ?array $configuration = null, ?WhatsAppVoiceMedia $voiceMedia = null,
        ?WhatsAppInboxMessage $messages = null, ?WhatsAppInboxMedia $attachmentMedia = null)
    {
        $this->transport = $transport;
        $this->authorizer = $authorizer;
        $this->clock = $clock;
        $this->configuration = $configuration;
        $this->voiceMedia = $voiceMedia ?? new WhatsAppVoiceMedia();
        $this->messages = $messages ?? new WhatsAppInboxMessage($authorizer);
        $this->attachmentMedia = $attachmentMedia ?? new WhatsAppInboxMedia(null, null, null, $this->messages);
    }

    public function state($actor, int $conversation, ?string $clientRequestId = null): array
    {
        $fresh = $this->actor($actor);
        if ($clientRequestId !== null && !$this->uuid($clientRequestId)) throw new HttpException(422);
        $attachmentLimits = $this->attachmentMedia->attachmentLimits();
        $base = ['success' => true, 'available' => false, 'can_reply' => false,
            'reason' => 'REPLIES_UNAVAILABLE', 'window_expires_at' => null, 'latest_inbound_id' => null,
            'voice_ready' => false, 'voice_mime_types' => [], 'request_state' => null,
            'attachment_ready' => false, 'attachment_types' => [],
            'attachment_max_bytes' => $attachmentLimits['attachment_max_bytes'], 'image_max_bytes' => $attachmentLimits['image_max_bytes'],
            'request_reason' => null, 'reply_id' => null, 'recent_replies' => []];
        if (!$this->tables()) return $base;
        $thread = $this->thread($conversation);
        if (!$thread) throw new HttpException(404);
        $base['recent_replies'] = $this->recent($conversation, $fresh);
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
        $base['attachment_ready'] = $base['available'] && $this->attachmentMedia->attachmentsAvailable();
        $base['attachment_types'] = $base['attachment_ready'] ? WhatsAppInboxMedia::ATTACHMENT_TYPES : [];
        $base['reason'] = !$base['available'] ? 'REPLIES_UNAVAILABLE'
            : ($this->unresolved($conversation) ? 'UNKNOWN_PENDING' : $snapshot['reason']);
        $base['can_reply'] = $base['reason'] === null;
        return $base;
    }

    public function send($actor, int $conversation, array $input): array
    {
        $fresh = $this->actor($actor);
        $request = $this->input($input, false);
        $request = $this->bindContext($fresh, $conversation, $request);
        return $this->submit($fresh, $conversation, $request, null);
    }

    /** Internal automation only; manual HTTP inputs still use the unchanged send validator. */
    public function sendOrderFollowup($actor, int $conversation, array $input, callable $guard): array
    {
        $metadata=$input['_followup']??null; $compose=$input['_compose']??null;
        unset($input['_followup'],$input['_compose']);
        $fresh=$this->actor($actor); $request=$this->input($input,false);
        if (!WhatsAppOrderFollowupService::validMarker($metadata,$conversation,$request['client_request_id'],$request['expected_inbound_id'])
            || !is_callable($compose)) throw new HttpException(422);
        $request=$this->bindContext($fresh,$conversation,$request);
        $request['_followup']=$metadata; $request['_compose']=$compose; $request['_guard']=$guard;
        return $this->submit($fresh,$conversation,$request,null);
    }

    /** Read-only cross-actor tombstones prevent both repeat generation and repeat sending. */
    public function orderFollowupState($actor,int $conversation,string $uuid,int $inbound): ?array
    {
        $this->actor($actor); if (!$this->uuid($uuid)||$inbound<1) throw new HttpException(422);
        if (!$this->tables()||!$this->thread($conversation)) throw new HttpException(503);
        $old=$this->orderFollowupReceipt($conversation,$uuid,$inbound,false);
        return $old?$this->result($this->publicState($old->state),true,(int)$old->id,$this->publicReason($old->reason)):null;
    }

    private function orderFollowupReceipt(int $conversation,string $uuid,int $inbound,bool $lock)
    {
        $q=$this->requests($conversation)->where('inbound_message_id',$inbound)->where('kind','text')->orderByDesc('id')->limit(21)
            ->select('id','actor_id','conversation_id','state','reason','request_hash')
            ->selectRaw('CASE WHEN LENGTH(audit)<=262144 THEN audit ELSE NULL END AS audit');
        $rows=($lock?$q->lockForUpdate():$q)->get(); if (count($rows)>20) throw new HttpException(409);
        foreach ($rows as $row) {
            if (!is_string($row->audit)) throw new HttpException(409); $audit=$this->decrypt($row->audit);
            if (($audit['client_request_id']??null)!==strtolower($uuid)) continue;
            if (!hash_equals((string)$row->request_hash,$this->requestHash((int)$row->actor_id,$conversation,$uuid))
                || !WhatsAppOrderFollowupService::validMarker($audit['order_followup']??null,$conversation,$uuid,$inbound)) throw new HttpException(409);
            return $row;
        }
        return null;
    }

    public function voice($actor, int $conversation, array $input, UploadedFile $file): array
    {
        $fresh = $this->actor($actor);
        $request = $this->input($input, true);
        $request = $this->bindContext($fresh, $conversation, $request);
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

    public function forward($actor, int $conversation, int $message, array $input): array
    {
        return $this->forwardFrom($actor, $conversation, $message, $input, 'message');
    }

    public function attachment($actor, int $conversation, array $input, UploadedFile $file): array
    {
        $fresh = $this->actor($actor);
        if (array_diff(array_keys($input), ['client_request_id', 'expected_inbound_id', 'caption', 'reply_to_message_id', 'reply_to_reply_id']))
            throw new HttpException(422);
        $caption = $input['caption'] ?? null;
        if ($caption !== null && (!is_string($caption) || !mb_check_encoding($caption, 'UTF-8') || mb_strlen($caption, 'UTF-8') > 1024
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $caption))) throw new HttpException(422);
        unset($input['caption']);
        $request = $this->bindContext($fresh, $conversation, $this->input($input, true));
        if (!$this->attachmentMedia->attachmentsAvailable()) return $this->result('FAILED', false, null, 'ATTACHMENT_UNAVAILABLE');
        $prepared = null;
        try {
            $prepared = $this->attachmentMedia->prepareAttachment($file);
            $request['kind'] = $prepared['kind']; $request['text'] = $caption === '' ? null : $caption;
            $request['attachment'] = ['sha256' => $prepared['input_sha256'], 'mime_type' => $prepared['mime'],
                'filename' => $prepared['filename'], 'size' => $prepared['size']];
            // The exact UUID resolves before a provider upload, including UNKNOWN attempts.
            $replay = $this->replay($fresh, $conversation, $request);
            if ($replay !== null) return $replay;
            return $this->submit($fresh, $conversation, $request, $prepared);
        } finally {
            if (is_array($prepared) && is_string($prepared['path'] ?? null)) $this->attachmentMedia->cleanupAttachment($prepared['path']);
        }
    }

    public function history($actor, int $conversation, ?int $beforeReplyId = null, array $knownReplyIds = []): array
    {
        $fresh = $this->actor($actor);
        if (!$this->tables()) throw new HttpException(503);
        if (!$this->thread($conversation)) throw new HttpException(404);
        if ($beforeReplyId !== null && $beforeReplyId < 1) throw new HttpException(422);
        if (!array_is_list($knownReplyIds) || count($knownReplyIds) > 100) throw new HttpException(422);
        foreach ($knownReplyIds as $id) if (!is_int($id) || $id < 1) throw new HttpException(422);
        $query = $this->requests($conversation)->where('state', 'ACCEPTED')->whereIn('kind', ['text', 'audio', 'image', 'video', 'document']);
        if ($beforeReplyId !== null) $query->where('id', '<', $beforeReplyId);
        $rows = $query->orderByDesc('id')->limit(21)->get(['id']);
        $more = $rows->count() > 20; $page = $rows->take(20); $last = $page->last();
        $known = $this->requests($conversation)->where('state', 'ACCEPTED')->whereIn('kind', ['text', 'audio', 'image', 'video', 'document'])
            ->whereIn('id', array_unique($knownReplyIds))->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($known) !== count(array_unique($knownReplyIds))) throw new HttpException(422);
        return ['success' => true, 'replies' => $this->recent($conversation, $fresh, $beforeReplyId, true),
            'next_before_reply_id' => $more && $last ? (int) $last->id : null, 'has_more' => $more,
            'hidden_reply_ids' => $this->messages->hiddenReplyIds($fresh, $conversation, $known)];
    }
    public function forwardReply($actor, int $conversation, int $reply, array $input): array
    {
        return $this->forwardFrom($actor, $conversation, $reply, $input, 'reply');
    }
    private function forwardFrom($actor, int $conversation, int $id, array $input, string $kind): array
    {
        $fresh = $this->actor($actor);
        if (array_diff(array_keys($input), ['client_request_id', 'expected_inbound_id', 'target_conversation_id'])
            || !array_key_exists('target_conversation_id', $input)) throw new HttpException(422);
        $target = filter_var($input['target_conversation_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($target) || !$this->thread($target)) throw new HttpException(404);
        $source = $kind === 'reply' ? $this->messages->resolveReply($fresh, $conversation, $id)
            : $this->messages->resolve($fresh, $conversation, $id);
        $request = $this->input(['client_request_id' => $input['client_request_id'] ?? null,
            'expected_inbound_id' => $input['expected_inbound_id'] ?? null, 'text' => 'forward'], false);
        $request['kind'] = $source['kind']; $request['text'] = $source['text'];
        $request['source'] = ['kind' => $kind, 'conversation' => $conversation, 'id' => $id, 'fingerprint' => $source['fingerprint']];
        $request['action'] = 'forward';
        if ($source['kind'] === 'text') {
            if (!is_string($request['text']) || trim($request['text']) === '' || mb_strlen($request['text']) > 4096) throw new HttpException(422);
        } else {
            $media = $this->messages->media($source);
            if ($media === null || ($request['text'] !== null && mb_strlen($request['text']) > 1024)) throw new HttpException(422);
            $request['media'] = $media;
        }
        return $this->submit($fresh, $target, $request, null);
    }
    public function reaction($actor, int $conversation, int $message, array $input): array
    {
        return $this->reactTo($actor, $conversation, $message, $input, 'message');
    }
    public function reactionReply($actor, int $conversation, int $reply, array $input): array
    {
        return $this->reactTo($actor, $conversation, $reply, $input, 'reply');
    }
    private function reactTo($actor, int $conversation, int $id, array $input, string $kind): array
    {
        $fresh = $this->actor($actor);
        if (array_diff(array_keys($input), ['client_request_id', 'expected_inbound_id', 'emoji'])
            || !is_string($input['emoji'] ?? null) || !in_array($input['emoji'], ['', '👍', '❤️', '😂', '😮', '😢', '🙏', '✅'], true)) throw new HttpException(422);
        $source = $kind === 'reply' ? $this->messages->resolveReply($fresh, $conversation, $id)
            : $this->messages->resolve($fresh, $conversation, $id);
        if (in_array($source['kind'], ['reaction', 'unsupported'], true)
            || strtotime($source['sent_at'] . ' UTC') < $this->now()->getTimestamp() - 2592000) throw new HttpException(422);
        $request = $this->input(['client_request_id' => $input['client_request_id'] ?? null,
            'expected_inbound_id' => $input['expected_inbound_id'] ?? null, 'text' => 'reaction'], false);
        $request['kind'] = 'reaction'; $request['text'] = null; $request['emoji'] = $input['emoji']; $request['action'] = 'reaction';
        $request['source'] = ['kind' => $kind, 'conversation' => $conversation, 'id' => $id, 'fingerprint' => $source['fingerprint']];
        $result = $this->submit($fresh, $conversation, $request, null);
        if ($result['state'] === 'ACCEPTED') {
            try { $result['reaction'] = $this->messages->reactions($fresh, $conversation, [$source['native_message_key']])[$source['native_message_key']] ?? null; }
            catch (\Throwable $error) { $result['reaction'] = null; }
        }
        return $result;
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
            if (isset($request['_followup'])) {
                $old=$this->orderFollowupReceipt($conversation,$request['client_request_id'],$request['expected_inbound_id'],true);
                if ($old) return ['result'=>$this->result($this->publicState($old->state),true,(int)$old->id,$this->publicReason($old->reason))];
                if (($request['_guard'])()!==true) throw new HttpException(409);
            }
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
            $bindings = $this->bindings($fresh, $conversation, $request, true);
            $stamp = $this->now()->format('Y-m-d H:i:s');
            $audit = ['client_request_id' => strtolower($request['client_request_id']),
                'text' => $request['text'], 'recipient' => $snapshot['recipient'],
                'payload_values' => $this->payloadValues($request),
                'voice_sha256' => $request['voice_sha256'] ?? null,
                'attachment' => $request['attachment'] ?? null,
                'reply_to' => $bindings['context'] === null ? null : $this->messages->summary($bindings['context']),
                'action' => $request['action'] ?? null, 'source' => $request['source'] ?? null,
                'source_native_key' => $bindings['source']['native_message_key'] ?? null,
                'source_native_kind' => $bindings['source']['kind'] ?? null,
                'media' => isset($request['media']) ? ['id' => $request['media']['id'], 'mime_type' => $request['media']['mime'],
                    'sha256' => $request['media']['sha256']] : null,
                'caption' => isset($request['media']) || isset($request['attachment']) ? $request['text'] : null, 'emoji' => $request['emoji'] ?? null];
            if (isset($request['media']['filename'])) $audit['media']['filename'] = $request['media']['filename'];
            if (isset($request['media']['size'])) $audit['media']['size'] = $request['media']['size'];
            if (isset($request['_followup'])) $audit['order_followup']=$request['_followup'];
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
            $reason = $this->preflight($actor, $conversation, $snapshot, $request);
            if ($reason !== null) return $this->finish($id, 'FAILED', $reason, ['CLAIMED']);
            if (isset($request['_compose'])) {
                // Reserve and commit before AI. A crash cannot spend/send again on this customer input.
                $text=($request['_compose'])(); $next=$this->input(['client_request_id'=>$request['client_request_id'],
                    'expected_inbound_id'=>$request['expected_inbound_id'],'text'=>$text],false); $request['text']=$next['text'];
                $row=$this->requests($conversation)->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')->first(['audit']);
                if (!$row) return $this->result('UNKNOWN',false,$id,'DELIVERY_UNKNOWN');
                $audit=$this->decrypt($row->audit); $audit['text']=$request['text']; $audit['payload_values']=$this->payloadValues($request);
                if ($this->requests($conversation)->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')
                    ->update(['audit'=>$this->encrypt($audit),'payload_hash'=>$this->payloadHash($request),'updated_at'=>$this->now()->format('Y-m-d H:i:s')])!==1)
                    return $this->result('UNKNOWN',false,$id,'DELIVERY_UNKNOWN');
            }
            $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
                'to' => $snapshot['recipient'], 'type' => $request['kind']];
            $uploadState = 'CLAIMED';
            if ($prepared !== null) {
                $attachment = isset($request['attachment']);
                $mime = $attachment ? $prepared['mime'] : 'audio/ogg';
                if ($attachment) {
                    if (!$this->attachmentMedia->preparedAttachmentValid($prepared)) return $this->finish($id, 'FAILED', 'INVALID_ATTACHMENT', ['CLAIMED']);
                    // Even an uncertain upload is never repeated automatically after a crash.
                    $stamp = $this->now()->format('Y-m-d H:i:s');
                    $changed = DB::table('whatsapp_reply_requests')->where('id', $id)->where('state', 'CLAIMED')
                        ->whereNull('send_started_at')->update(['state' => 'UNKNOWN', 'reason' => 'DELIVERY_UNKNOWN', 'updated_at' => $stamp]);
                    if ($changed !== 1) return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
                    $uploadState = 'UNKNOWN';
                    $reason = $this->preflight($actor, $conversation, $snapshot, $request);
                    if ($reason !== null) return $this->finish($id, 'FAILED', $reason, ['UNKNOWN']);
                }
                $upload = $this->call('media', ['messaging_product' => 'whatsapp', 'type' => $mime,
                    'file' => $prepared], true);
                $media = $this->decode($upload);
                if ($upload['status'] !== 200 || isset($media['error']) || !is_string($media['id'] ?? null)
                    || !preg_match('/\A[1-9][0-9]{0,31}\z/', $media['id'])) {
                    if ($attachment && !(in_array($upload['status'], [400, 401, 403, 404, 405, 409, 410, 413, 415, 422, 429], true)
                        && is_int($media['error']['code'] ?? null) && !isset($media['id']))) return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
                    return $this->finish($id, 'FAILED', 'MEDIA_UPLOAD_FAILED', [$uploadState]);
                }
                $payload[$request['kind']] = ['id' => $media['id']];
                if ($attachment) {
                    if ($request['text'] !== null) $payload[$request['kind']]['caption'] = $request['text'];
                    if ($request['kind'] === 'document') $payload['document']['filename'] = $prepared['filename'];
                }
                $auditRow = DB::table('whatsapp_reply_requests')->where('id', $id)->where('state', $uploadState)->first(['audit']);
                if (!$auditRow) return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
                $audit = $this->decrypt($auditRow->audit);
                $audit['media'] = ['id' => $media['id'], 'mime_type' => $mime,
                    'sha256' => $attachment ? $prepared['input_sha256'] : hash_file('sha256', $prepared['path'])];
                if ($attachment) {
                    if (!$this->attachmentMedia->preparedAttachmentValid($prepared)) return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
                    $audit['media']['filename'] = $prepared['filename']; $audit['media']['size'] = $prepared['size'];
                }
                $changed = DB::table('whatsapp_reply_requests')->where('id', $id)->where('state', $uploadState)
                    ->whereNull('send_started_at')->update(['audit' => $this->encrypt($audit)]);
                if ($changed !== 1) return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
            } elseif ($request['kind'] === 'reaction') {
                $source = $this->bindings($actor, $conversation, $request)['source'];
                $payload['reaction'] = ['message_id' => $source['native_id'], 'emoji' => $request['emoji']];
            } elseif (isset($request['media'])) {
                $payload[$request['kind']] = ['id' => $request['media']['id']];
                if (in_array($request['kind'], ['image', 'video', 'document'], true) && $request['text'] !== null)
                    $payload[$request['kind']]['caption'] = $request['text'];
                if ($request['kind'] === 'document' && isset($request['media']['filename']))
                    $payload['document']['filename'] = $request['media']['filename'];
            } else {
                $payload['text'] = ['preview_url' => false, 'body' => $request['text']];
            }
            $reason = $this->preflight($actor, $conversation, $snapshot, $request);
            if ($reason !== null) return $this->finish($id, 'FAILED', $reason, [$uploadState]);
            $context = $this->bindings($actor, $conversation, $request)['context'];
            if ($context !== null) $payload['context'] = ['message_id' => $context['native_id']];
            $reason = $this->preflight($actor, $conversation, $snapshot, $request);
            if ($reason !== null) return $this->finish($id, 'FAILED', $reason, [$uploadState]);
            // Persist uncertainty BEFORE the customer-facing call. A killed process stays blocked.
            $started = $this->now()->format('Y-m-d H:i:s');
            $changed = DB::table('whatsapp_reply_requests')->where('id', $id)->where('state', $uploadState)
                ->whereNull('send_started_at')->update(['state' => 'UNKNOWN', 'reason' => 'DELIVERY_UNKNOWN',
                    'send_started_at' => $started, 'updated_at' => $started]);
            if ($changed !== 1) return $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
            // No stale session role can authorize this final network boundary.
            $reason = $this->preflight($actor, $conversation, $snapshot, $request);
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
            if (isset($request['_followup'])) {
                $row=$this->requests($conversation)->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')->first(['id']);
                if ($row) return $this->finish($id,'FAILED','STALE_INBOUND',['CLAIMED']);
            }
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

    private function preflight($actor, int $conversation, array $snapshot, array $request = []): ?string
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
        try {
            $this->bindings($fresh, $conversation, $request);
        } catch (\Throwable $error) { return 'MESSAGE_UNAVAILABLE'; }
        if (isset($request['_guard'])) {
            try { if (($request['_guard'])()!==true) return 'STALE_INBOUND'; }
            catch (\Throwable $error) { return 'STALE_INBOUND'; }
        }
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
        if (array_diff(array_keys($input), array_merge($wanted, ['reply_to_message_id', 'reply_to_reply_id'])) || array_diff($wanted, array_keys($input))
            || !$this->uuid($input['client_request_id'] ?? null)) throw new HttpException(422);
        $expected = filter_var($input['expected_inbound_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($expected)) throw new HttpException(422);
        $text = $voice ? null : $input['text'];
        if (!$voice && (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || trim($text) === ''
            || mb_strlen($text, 'UTF-8') > 4096 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text))) throw new HttpException(422);
        $request = ['client_request_id' => strtolower($input['client_request_id']),
            'expected_inbound_id' => $expected, 'text' => $text, 'kind' => $voice ? 'audio' : 'text'];
        if (isset($input['reply_to_message_id'], $input['reply_to_reply_id'])) throw new HttpException(422);
        foreach (['reply_to_message_id', 'reply_to_reply_id'] as $field) if (array_key_exists($field, $input)) {
            $id = filter_var($input[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) throw new HttpException(422);
            $request[$field] = $id;
        }
        return $request;
    }

    private function bindings($actor, int $conversation, array $request, bool $lock = false): array
    {
        $context = null; $source = null;
        if (isset($request['reply_to_message_id'])) $context = $this->messages->resolve($actor, $conversation, $request['reply_to_message_id'], $lock);
        if (isset($request['reply_to_reply_id'])) $context = $this->messages->resolveReply($actor, $conversation, $request['reply_to_reply_id'], $lock);
        if ($context !== null && in_array($context['kind'], ['unsupported', 'reaction'], true)) throw new HttpException(422);
        if ($context !== null && isset($request['context_fingerprint'])
            && !hash_equals($request['context_fingerprint'], $context['fingerprint'])) throw new HttpException(422);
        if (isset($request['source'])) {
            $bound = $request['source'];
            $source = $bound['kind'] === 'reply' ? $this->messages->resolveReply($actor, $bound['conversation'], $bound['id'], $lock)
                : $this->messages->resolve($actor, $bound['conversation'], $bound['id'], $lock);
            if (!hash_equals($bound['fingerprint'], $source['fingerprint'])) throw new HttpException(409);
        }
        return ['context' => $context, 'source' => $source];
    }

    private function bindContext($actor, int $conversation, array $request): array
    {
        if (isset($request['reply_to_message_id']) || isset($request['reply_to_reply_id']))
            $request['context_fingerprint'] = $this->bindings($actor, $conversation, $request)['context']['fingerprint'];
        return $request;
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
        $changed = DB::transaction(function () use ($id, $from, $values, $state) {
            $changed = DB::table('whatsapp_reply_requests')->where('id', $id)->whereIn('state', $from)->update($values);
            if ($changed === 1 && $state === 'ACCEPTED' && Schema::hasTable(WhatsAppInboxMessage::INDEX)) {
                $row = DB::table('whatsapp_reply_requests')->where('id', $id)->lockForUpdate()->first();
                $this->messages->indexReply($row);
                $this->messages->indexReaction($row);
            }
            return $changed;
        }, 3);
        // Commit ACCEPTED before independent evidence work. A projection deadlock must never
        // roll back provider acceptance or make the client resend an already accepted message.
        if ($changed === 1 && $state === 'ACCEPTED') {
            try {
                $accepted = DB::table('whatsapp_reply_requests')->where('id', $id)->first();
                if ($accepted && $accepted->kind === 'text') (new WhatsAppAcceptedReplyEvidence())->projectAcceptedReply($accepted);
            } catch (\Throwable $error) { /* Bounded worker projection retries the durable receipt. */ }
        }
        return $changed === 1 ? $this->result($state, false, $id, $reason)
            : $this->result('UNKNOWN', false, $id, 'DELIVERY_UNKNOWN');
    }

    private function recent(int $conversation, $actor = null, ?int $beforeReplyId = null, bool $acceptedOnly = false): array
    {
        $items = []; $acceptedKeys = [];
        $query = $this->requests($conversation)->whereIn('kind', ['text', 'audio', 'image', 'video', 'document']);
        if ($beforeReplyId !== null) $query->where('id', '<', $beforeReplyId);
        if ($acceptedOnly) $query->where('state', 'ACCEPTED');
        $rows = $query->orderByDesc('id')->limit(20)->get();
        $hidden = $actor === null ? [] : $this->messages->hiddenReplyIds($actor, $conversation, $rows->pluck('id')->all());
        $indexes = $actor !== null && Schema::hasTable(WhatsAppInboxMessage::INDEX) ? DB::table(WhatsAppInboxMessage::INDEX)
            ->where('conversation_id', $conversation)->whereIn('reply_id', $rows->pluck('id')->all())->get()->keyBy('reply_id') : null;
        $reactions = $actor !== null && $indexes ? $this->messages->reactions($actor, $conversation,
            $indexes->filter(fn ($index) => $index->valid)->pluck('message_key')->all()) : [];
        foreach ($rows as $row) {
            if (in_array((int) $row->id, $hidden, true) || $row->kind === 'reaction') continue;
            try {
                $audit = $this->decrypt($row->audit);
                if ($actor !== null && !$this->uuid($audit['client_request_id'] ?? null)) continue;
                $acceptedAt = null;
                if ($row->state === 'ACCEPTED' && is_string($row->completed_at ?? null)) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
                        $row->completed_at, new \DateTimeZone('UTC'));
                    if ($date && $date->format('Y-m-d H:i:s') === $row->completed_at)
                        $acceptedAt = $date->format('Y-m-d\\TH:i:s\\Z');
                }
                $index = count($items);
                $items[] = ['reply_id' => (int) $row->id, 'client_request_id' => $audit['client_request_id'],
                    'state' => $this->publicState($row->state), 'kind' => $row->kind,
                    'text' => $row->kind === 'text' ? $audit['text'] : (in_array($row->kind, ['image', 'video', 'document'], true) ? ($audit['caption'] ?? null) : null),
                    'created_at' => gmdate('Y-m-d\\TH:i:s\\Z', strtotime($row->created_at . ' UTC')),
                    'actor_id' => (int) $row->actor_id, 'source' => 'dashboard_api',
                    'accepted_at' => $acceptedAt, 'projected_message_id' => null];
                if ($actor !== null) {
                    $items[$index]['reply_to'] = $this->safeSummary($audit['reply_to'] ?? null);
                    if ($items[$index]['reply_to'] !== null) {
                        try {
                            $quoted = $items[$index]['reply_to'];
                            $source = $quoted['reply_id'] !== null ? $this->messages->resolveReply($actor, $conversation, $quoted['reply_id'])
                                : $this->messages->resolve($actor, $conversation, $quoted['message_id']);
                            $items[$index]['reply_to'] = $this->messages->summary($source);
                        } catch (\Throwable $error) { $items[$index]['reply_to'] = null; }
                    }
                    $items[$index]['projection_id'] = $indexes && $indexes->get($row->id) ? (int) $indexes->get($row->id)->id : null;
                    $mapping = $indexes ? $indexes->get($row->id) : null;
                    $items[$index]['reaction'] = $mapping && $mapping->valid ? ($reactions[$mapping->message_key] ?? null) : null;
                    $items[$index]['actions'] = ['reply' => false, 'forward' => false,
                        'delete' => $row->state === 'ACCEPTED' && $items[$index]['projection_id'] !== null, 'react' => false];
                    try {
                        $mapping = $indexes ? $indexes->get($row->id) : null;
                        if ($row->state === 'ACCEPTED' && $mapping && $mapping->valid && $mapping->kind === $row->kind && $mapping->receipt_hash === hash('sha256',
                            (string) $row->response_details . ':' . (string) $row->remote_message_key . ':' . $conversation)) {
                            $nativeSource = ['kind' => $row->kind, 'text' => $row->kind === 'text' ? $audit['text'] : ($audit['caption'] ?? null),
                                'raw' => [$row->kind => $audit['media'] ?? null], 'sent_at' => $row->completed_at];
                            $items[$index]['actions']['reply'] = true;
                            $items[$index]['actions']['react'] = strtotime($nativeSource['sent_at'] . ' UTC') >= $this->now()->getTimestamp() - 2592000;
                            $nativeMedia = $this->messages->media($nativeSource);
                            $items[$index]['actions']['forward'] = $nativeSource['kind'] === 'text'
                                ? is_string($nativeSource['text']) && trim($nativeSource['text']) !== ''
                                : $nativeMedia !== null && ($nativeSource['text'] === null || (is_string($nativeSource['text']) && mb_strlen($nativeSource['text']) <= 1024));
                            if ($nativeMedia !== null && $nativeMedia['kind'] === 'image') $items[$index]['media'] = [
                                'url' => url('/admin/whatsapp/conversations/' . $conversation . '/replies/' . $row->id . '/media'),
                                'type' => 'image', 'mime_type' => $nativeMedia['mime']];
                            if ($nativeMedia !== null && $nativeMedia['kind'] === 'document'
                                && in_array($nativeMedia['mime'], ['application/pdf', 'text/plain'], true)
                                && isset($nativeMedia['filename'], $nativeMedia['size'])) $items[$index]['media'] = [
                                'url' => url('/admin/whatsapp/conversations/' . $conversation . '/replies/' . $row->id . '/attachment'),
                                'type' => 'document', 'mime_type' => $nativeMedia['mime'],
                                'filename' => $nativeMedia['filename'], 'size' => $nativeMedia['size']];
                        }
                    } catch (\Throwable $error) { /* An unavailable native receipt has no network actions. */ }
                }
                // Native message IDs stay encrypted. Their inbox hash uses its own namespace.
                if ($row->state === 'ACCEPTED' && in_array($row->kind, ['text', 'audio', 'image', 'video', 'document'], true)
                    && is_string($row->response_details ?? null)) {
                    try {
                        $response = $this->decrypt($row->response_details);
                        $native = $response['message_id'] ?? null;
                        if (is_string($native) && preg_match('/\\Awamid\\.[A-Za-z0-9_+\\/=.-]{1,500}\\z/', $native))
                            $acceptedKeys[$index] = ['key' => hash('sha256', 'whatsapp-message-v1:'
                                . self::WABA . ':' . self::PHONE . ':' . $native), 'type' => $row->kind];
                    } catch (\Throwable $error) { /* An unavailable receipt never hides the accepted audit. */ }
                }
            } catch (\Throwable $error) { /* Cipher failures never reveal stored bytes. */ }
        }
        if ($acceptedKeys !== []) {
            $projected = [];
            foreach (DB::table('whatsapp_inbox_messages as m')
                ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('c.waba_id', self::WABA)->where('c.phone_number_id', self::PHONE)
                ->where('m.conversation_id', $conversation)->where('m.direction', 'outbound')
                ->whereIn('m.type', ['text', 'audio', 'image', 'video', 'document'])
                ->whereIn('m.message_key', array_values(array_unique(array_column($acceptedKeys, 'key'))))
                ->get(['m.id', 'm.message_key', 'm.type']) as $message) {
                if ((int) $message->id > 0)
                    $projected[$message->message_key][$message->type] = (int) $message->id;
            }
            foreach ($acceptedKeys as $index => $lookup)
                $items[$index]['projected_message_id'] = $projected[$lookup['key']][$lookup['type']] ?? null;
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
        return hash_hmac('sha256', 'whatsapp-reply-payload-v1:' . json_encode($this->payloadValues($request), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->key());
    }

    private function payloadValues(array $request): array
    {
        $values = [
            $request['kind'], $request['expected_inbound_id'], $request['text'], $request['voice_sha256'] ?? null,
        ];
        if (isset($request['reply_to_message_id']) || isset($request['reply_to_reply_id']) || isset($request['source']))
            $values[] = [$request['reply_to_message_id'] ?? null, $request['reply_to_reply_id'] ?? null,
                $request['source'] ?? null, $request['media'] ?? null, $request['emoji'] ?? null, $request['context_fingerprint'] ?? null];
        if (isset($request['attachment'])) $values[] = $request['attachment'];
        return $values;
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

    private function safeSummary($summary): ?array
    {
        if (!is_array($summary) || !in_array($summary['kind'] ?? null, ['text', 'audio', 'image', 'video', 'document', 'location', 'order'], true)
            || !in_array($summary['direction'] ?? null, ['inbound', 'outbound'], true)) return null;
        $message = $summary['message_id'] ?? null; $reply = $summary['reply_id'] ?? null;
        if (($message !== null && (!is_int($message) || $message < 1)) || ($reply !== null && (!is_int($reply) || $reply < 1))
            || (($message === null) === ($reply === null))) return null;
        return ['message_id' => $message, 'reply_id' => $reply, 'kind' => $summary['kind'], 'direction' => $summary['direction'],
            'text' => is_string($summary['text'] ?? null) ? mb_substr($summary['text'], 0, 160) : null];
    }

    private function publicReason(?string $reason): ?string
    {
        return $reason === null ? null : (in_array($reason, ['REPLIES_UNAVAILABLE', 'VOICE_UNAVAILABLE', 'INVALID_VOICE', 'ATTACHMENT_UNAVAILABLE', 'INVALID_ATTACHMENT',
            'NO_INBOUND', 'RECIPIENT_UNAVAILABLE', 'WINDOW_CLOSED', 'UNKNOWN_PENDING', 'STALE_INBOUND',
            'ACCESS_REVOKED', 'MEDIA_UPLOAD_FAILED', 'META_REJECTED', 'DELIVERY_UNKNOWN', 'MESSAGE_UNAVAILABLE'], true) ? $reason : 'DELIVERY_UNKNOWN');
    }
}
