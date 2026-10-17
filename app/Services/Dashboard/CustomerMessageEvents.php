<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Local CRM projections only. This service never sends a message or invokes AI. */
class CustomerMessageEvents
{
    private CustomerDirectory $directory;

    public function __construct(?CustomerDirectory $directory = null)
    {
        $this->directory = $directory ?? new CustomerDirectory();
    }

    /** A whole directive is required: cancelling an order is not unsubscribing. */
    public static function isOptOut(?string $text): bool
    {
        if ($text === null || preg_match('//u', $text) !== 1) return false;
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['أ'=>'ا', 'إ'=>'ا', 'آ'=>'ا', 'ـ'=>'']);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = preg_replace('/^[\s.!?؟،,؛;]+|[\s.!?؟،,؛;]+$/u', '', $text);
        return in_array($text, ['stop', 'unsubscribe', 'الغاء', 'توقف', 'الغاء الاشتراك'], true);
    }

    /** Called only after a new verified inbox message or proven text hydration. */
    public function inbound(array $message): void
    {
        if (($message['direction'] ?? null) !== 'inbound' || !$this->directory->ready()) return;

        DB::transaction(function () use ($message) {
            $profile = $this->profile($message);
            if ($profile === null) return;
            // WhatsApp contact is not evidence of a completed delivery purchase.
            $customer = $this->directory->capture($profile, 'unclassified', '');
            if (!$customer) return;
            $text = $message['text'] ?? null;
            if (self::isOptOut(is_string($text) ? $text : null)) {
                $this->directory->consent((int)$customer->id, false, false, 'whatsapp-inbound:opt-out');
                return;
            }
            if (!is_string($text) || trim($text) === ''
                || !is_string($message['message_id'] ?? null)
                || !$this->timestamp($message['sent_at'] ?? null)
                || !Schema::hasTable('crm_whatsapp_deliveries')
                || !Schema::hasTable('crm_customer_feedback_replies')) return;

            $query = DB::table('crm_whatsapp_deliveries')->where('customer_id', $customer->id)
                ->where('kind', 'feedback')->where('state', 'accepted')
                ->whereNotNull('accepted_at')->where('accepted_at', '<=', $message['sent_at']);
            // An explicit reply must match its own feedback message. An unrelated
            // quoted order message cannot be attached to the latest survey.
            $context = $message['content']['message']['context']['id'] ?? null;
            if (is_string($context) && $context !== '') $query->where('provider_message_id', $context);
            $delivery = $query->orderByDesc('accepted_at')->orderByDesc('id')->lockForUpdate()->first();
            if (!$delivery) return;

            $inboxId = null;
            if (Schema::hasTable('whatsapp_inbox_messages')) {
                $inboxId = DB::table('whatsapp_inbox_messages')->where('message_key', $this->inboxKey($message['message_id']))->value('id');
            }
            DB::table('crm_customer_feedback_replies')->insertOrIgnore([
                'delivery_id'=>$delivery->id, 'customer_id'=>$customer->id,
                'inbox_message_id'=>$inboxId, 'provider_message_id'=>$message['message_id'],
                'body'=>Crypt::encryptString($text), 'replied_at'=>$message['sent_at'],
                'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
            ]);
        }, 3);
    }

    /** Update accepted outbound receipts; a delivery failure never permits a resend. */
    public function status(array $status): void
    {
        $name = $status['status'] ?? null;
        if (!is_string($status['message_id'] ?? null)
            || !in_array($name, ['sent', 'delivered', 'read', 'failed', 'deleted'], true)
            || !$this->timestamp($status['sent_at'] ?? null)
            || !Schema::hasTable('crm_whatsapp_deliveries')) return;

        DB::transaction(function () use ($status, $name) {
            $this->lockDispatchGate();
            if (Schema::hasTable('crm_whatsapp_status_receipts')) {
                $this->rememberStatus($status);
                $this->reconcile($status['message_id']);
                // Retain unrelated invoice receipts too, with bounded seven-day cleanup.
                $old = DB::table('crm_whatsapp_status_receipts')->where('received_at', '<',
                    \Carbon\Carbon::parse(now('UTC'), 'UTC')->subDays(7)->format('Y-m-d H:i:s'))
                    ->orderBy('id')->limit(250)->pluck('id')->all();
                if ($old) DB::table('crm_whatsapp_status_receipts')->whereIn('id', $old)->delete();
                return;
            }
            // During a staged deployment, keep the previous receipt projection working.
            $deliveries = DB::table('crm_whatsapp_deliveries')
                ->where('provider_message_id', $status['message_id'])->lockForUpdate()->get();
            foreach ($deliveries as $delivery) {
                $recipient = $status['recipient_phone'] ?? null;
                if (is_string($recipient) && Schema::hasTable('crm_customers')) {
                    $phone = DB::table('crm_customers')->where('id', $delivery->customer_id)->value('phone');
                    if (CustomerDirectory::normalizePhone($recipient) !== $phone) continue;
                }
                $changes = [];
                $column = ['delivered'=>'delivered_at', 'read'=>'read_at', 'failed'=>'failed_at'][$name] ?? null;
                if ($column && empty($delivery->{$column})) $changes[$column] = $status['sent_at'];
                // Preserve progress when Meta retries or delivers receipts out of order.
                $rank = ['sent'=>1, 'failed'=>2, 'delivered'=>3, 'read'=>4, 'deleted'=>5];
                if (($rank[$name] ?? 0) > ($rank[$delivery->delivery_status ?? ''] ?? 0)) {
                    $changes['delivery_status'] = $name;
                }
                if ($changes) DB::table('crm_whatsapp_deliveries')->where('id', $delivery->id)
                    ->update($changes + ['updated_at'=>now('UTC')]);
            }
        }, 3);
    }

    /** Acceptance calls this after storing its native message ID, under the same gate. */
    public function reconcile(string $messageId): void
    {
        if (!Schema::hasTable('crm_whatsapp_status_receipts') || !Schema::hasTable('crm_whatsapp_deliveries')) return;
        DB::transaction(function () use ($messageId) {
            $this->lockDispatchGate();
            $receipt = DB::table('crm_whatsapp_status_receipts')->where('provider_message_id', $messageId)->lockForUpdate()->first();
            if (!$receipt) return;
            $deliveries = DB::table('crm_whatsapp_deliveries')->where('provider_message_id', $messageId)->lockForUpdate()->get();
            foreach ($deliveries as $delivery) {
                if ($receipt->recipient_hash !== null) {
                    $phone = DB::table('crm_customers')->where('id', $delivery->customer_id)->value('phone');
                    if (!is_string($phone) || !hash_equals($receipt->recipient_hash, hash('sha256', $phone))) continue;
                }
                $changes = [];
                foreach (['delivered_at', 'read_at', 'failed_at'] as $column) {
                    if ($receipt->{$column} !== null && (empty($delivery->{$column}) || $receipt->{$column} < $delivery->{$column})) {
                        $changes[$column] = $receipt->{$column};
                    }
                }
                if ($this->statusRank($receipt->delivery_status) > $this->statusRank($delivery->delivery_status ?? null)) {
                    $changes['delivery_status'] = $receipt->delivery_status;
                }
                if ($changes) DB::table('crm_whatsapp_deliveries')->where('id', $delivery->id)->update($changes + ['updated_at'=>now('UTC')]);
            }
        }, 3);
    }

    private function rememberStatus(array $status): void
    {
        $when = now('UTC');
        $recipient = $status['recipient_phone'] ?? null;
        $recipientHash = is_string($recipient)
            ? hash('sha256', CustomerDirectory::normalizePhone($recipient) ?? ('invalid:'.$recipient)) : null;
        DB::table('crm_whatsapp_status_receipts')->insertOrIgnore([
            'provider_message_id'=>$status['message_id'], 'recipient_hash'=>$recipientHash,
            'delivery_status'=>$status['status'], 'status_at'=>$status['sent_at'],
            'received_at'=>$when, 'created_at'=>$when, 'updated_at'=>$when,
        ]);
        $receipt = DB::table('crm_whatsapp_status_receipts')->where('provider_message_id', $status['message_id'])->lockForUpdate()->first();
        if ($receipt->recipient_hash !== null && $recipientHash !== null && !hash_equals($receipt->recipient_hash, $recipientHash)) return;
        $changes = ['received_at'=>$when, 'updated_at'=>$when];
        if ($receipt->recipient_hash === null && $recipientHash !== null) $changes['recipient_hash'] = $recipientHash;
        if ($this->statusRank($status['status']) >= $this->statusRank($receipt->delivery_status)) {
            $changes['delivery_status'] = $status['status']; $changes['status_at'] = $status['sent_at'];
        }
        $column = ['delivered'=>'delivered_at', 'read'=>'read_at', 'failed'=>'failed_at'][$status['status']] ?? null;
        if ($column && (empty($receipt->{$column}) || $status['sent_at'] < $receipt->{$column})) $changes[$column] = $status['sent_at'];
        DB::table('crm_whatsapp_status_receipts')->where('id', $receipt->id)->update($changes);
    }

    private function statusRank(?string $status): int
    {
        return ['sent'=>1, 'failed'=>2, 'delivered'=>3, 'read'=>4, 'deleted'=>5][$status ?? ''] ?? 0;
    }

    private function lockDispatchGate(): void
    {
        if (Schema::hasTable('crm_whatsapp_dispatch_gate')) DB::table('crm_whatsapp_dispatch_gate')->where('id', 1)->lockForUpdate()->first();
    }

    private function inboxKey(string $messageId): string
    {
        $scope = WhatsAppInboxConsumer::WABA_ID.':'.WhatsAppInboxConsumer::PHONE_ID.':';
        return hash('sha256', 'whatsapp-message-v1:'.$scope.$messageId);
    }

    /** BUID-only events may reuse a previously verified phone from the same thread. */
    private function profile(array $message): ?array
    {
        if (is_string($message['peer_phone'] ?? null)) {
            return ['phone'=>$message['peer_phone'], 'name'=>$message['customer_name'] ?? null];
        }
        if (!is_string($message['message_id'] ?? null) || !is_string($message['peer_user_id'] ?? null)
            || !Schema::hasTable('whatsapp_inbox_messages') || !Schema::hasTable('whatsapp_inbox_conversations')) return null;
        $saved = DB::table('whatsapp_inbox_messages')->where('message_key', $this->inboxKey($message['message_id']))
            ->where('direction', 'inbound')->first();
        if (!$saved) return null;
        $thread = DB::table('whatsapp_inbox_conversations')->where('id', $saved->conversation_id)
            ->where('waba_id', WhatsAppInboxConsumer::WABA_ID)->where('phone_number_id', WhatsAppInboxConsumer::PHONE_ID)->first();
        if (!$thread) return null;
        $customer = json_decode(Crypt::decryptString($thread->customer), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($customer) || !is_string($customer['phone'] ?? null)
            || ($customer['user_id'] ?? null) !== $message['peer_user_id']) return null;
        return ['phone'=>$customer['phone'], 'name'=>$message['customer_name'] ?? ($customer['name'] ?? null)];
    }

    private function timestamp($value): bool
    {
        if (!is_string($value)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value;
    }
}
