<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\WhatsAppInboxAccess;
use App\Services\Dashboard\WhatsAppInboxConsumer;
use App\Support\WhatsAppInboxProtocol;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WhatsAppInboxController extends Controller
{
    private const THREAD_LIMIT = 25;
    private const MESSAGE_LIMIT = 50;

    public function index(WhatsAppInboxAccess $access)
    {
        try {
            $access->actor(auth('admin')->user());
            $available = $access->available();
            return response()->view('admin.whatsapp.index', ['waInbox' => [
                'available' => $available,
                'conversations_url' => route('whatsapp-inbox.conversations'),
                'messages_base_url' => url('/admin/whatsapp/conversations'),
                'labels' => $this->labels(),
            ]])->withHeaders($this->privateHeaders());
        } catch (HttpException $error) {
            return response(app()->getLocale() === 'ar' ? 'غير مسموح بعرض المحادثات.' : 'Inbox access denied.',
                $error->getStatusCode())->withHeaders($this->privateHeaders());
        } catch (\Throwable $error) {
            return response(app()->getLocale() === 'ar' ? 'الرسائل غير متاحة الآن.' : 'Inbox temporarily unavailable.',
                503)->withHeaders($this->privateHeaders());
        }
    }

    public function conversations(Request $request, WhatsAppInboxAccess $access)
    {
        return $this->read($access, function () use ($request) {
            $input = $request->validate(['cursor' => 'nullable|string|max:1500']);
            $query = $this->scopedConversations();
            $total = (clone $query)->count();
            if (!empty($input['cursor'])) {
                $cursor = $this->decodeCursor($input['cursor']);
                $query->where(function ($q) use ($cursor) {
                    $q->where('last_message_at', '<', $cursor['last'])
                        ->orWhere(function ($same) use ($cursor) {
                            $same->where('last_message_at', $cursor['last'])->where('id', '<', $cursor['id']);
                        });
                });
            }
            $latestMessage = DB::table('whatsapp_inbox_messages')->select('id')
                ->whereColumn('conversation_id', 'whatsapp_inbox_conversations.id')
                ->orderByDesc('sent_at')->orderByDesc('id')->limit(1);
            $rows = $query->orderByDesc('last_message_at')->orderByDesc('id')
                ->select(['id', 'customer', 'last_message_at'])
                ->selectSub($latestMessage, 'preview_message_id')->limit(self::THREAD_LIMIT + 1)->get();
            $more = $rows->count() > self::THREAD_LIMIT;
            $rows = $rows->take(self::THREAD_LIMIT)->values();
            $ids = $rows->pluck('preview_message_id')->filter()->all();
            $previews = [];
            if ($ids) {
                // One latest-by-sent-time message per conversation; no full histories are loaded.
                foreach (DB::table('whatsapp_inbox_messages')->whereIn('id', $ids)
                    ->get(['conversation_id', 'content', 'type', 'direction']) as $message) {
                    $dto = $this->decrypt($message->content);
                    $previews[(int) $message->conversation_id] = [
                        'text' => $this->displayText($dto, 160),
                        'type' => $this->messageType($message->type),
                        'direction' => $message->direction === 'outbound' ? 'outbound' : 'inbound',
                        'unavailable' => $dto === null,
                    ];
                }
            }
            $items = [];
            foreach ($rows as $row) {
                $customer = $this->decrypt($row->customer);
                $items[] = [
                    'id' => (int) $row->id,
                    'name' => $this->safeText($customer['name'] ?? null, 100),
                    'phone' => $this->phone($customer['phone'] ?? null),
                    'last_message_at' => $this->timestamp($row->last_message_at),
                    'preview' => $previews[(int) $row->id] ?? null,
                ];
            }
            $last = $rows->last();
            return ['success' => true, 'conversations' => $items, 'total' => $total,
                'next_cursor' => $more && $last ? Crypt::encryptString(json_encode([
                    'waba' => WhatsAppInboxAccess::WABA_ID, 'phone' => WhatsAppInboxAccess::PHONE_ID,
                    'last' => $last->last_message_at, 'id' => (int) $last->id,
                ], JSON_THROW_ON_ERROR)) : null];
        });
    }

    public function messages(Request $request, string $conversation, WhatsAppInboxAccess $access)
    {
        return $this->read($access, function () use ($request, $conversation) {
            $conversation = filter_var($conversation, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless(is_int($conversation), 404);
            $input = $request->validate([
                'before_id' => 'nullable|integer|min:1|max:' . PHP_INT_MAX,
                'after_id' => 'nullable|integer|min:0|max:' . PHP_INT_MAX,
            ]);
            abort_if(isset($input['before_id'], $input['after_id']), 422);
            $thread = $this->scopedConversations()->where('id', $conversation)
                ->first(['id', 'customer', 'last_message_at']);
            abort_unless($thread, 404);
            $query = DB::table('whatsapp_inbox_messages')->where('conversation_id', $conversation);
            $newer = isset($input['after_id']);
            if ($newer) $query->where('id', '>', (int) $input['after_id']);
            if (isset($input['before_id'])) $query->where('id', '<', (int) $input['before_id']);
            $rows = $newer ? $query->orderBy('id') : $query->orderByDesc('id');
            $rows = $rows->limit(self::MESSAGE_LIMIT + 1)
                ->get(['id', 'content', 'direction', 'type', 'source', 'sent_at', 'created_at']);
            $more = $rows->count() > self::MESSAGE_LIMIT;
            $rows = $rows->take(self::MESSAGE_LIMIT)->values();
            if (!$newer) $rows = $rows->reverse()->values();
            $items = [];
            foreach ($rows as $row) {
                $dto = $this->decrypt($row->content);
                $items[] = [
                    'id' => (int) $row->id,
                    'direction' => $row->direction === 'outbound' ? 'outbound' : 'inbound',
                    'type' => $this->messageType($row->type),
                    'text' => $this->displayText($dto, 20000),
                    'cart' => $this->displayCart($dto),
                    'sent_at' => $this->timestamp($row->sent_at),
                    'received_at' => $this->timestamp($row->created_at),
                    'unavailable' => $dto === null,
                ];
            }
            $customer = $this->decrypt($thread->customer);
            return ['success' => true, 'conversation' => [
                'id' => (int) $thread->id,
                'name' => $this->safeText($customer['name'] ?? null, 100),
                'phone' => $this->phone($customer['phone'] ?? null),
            ], 'messages' => $items, 'has_more' => $more,
                'next_before_id' => !$newer && $more && $rows->first() ? (int) $rows->first()->id : null,
                'last_id' => $rows->last() ? (int) $rows->last()->id : ($newer ? (int) $input['after_id'] : 0)];
        });
    }

    private function read(WhatsAppInboxAccess $access, callable $callback)
    {
        try {
            $access->actor(auth('admin')->user());
            abort_unless($access->available(), 503);
            // Bounded local projection keeps the open inbox current without a background-worker assumption.
            // This only materializes received encrypted events; it never sends messages or creates orders.
            $warning = false;
            try {
                $projection = app(WhatsAppInboxConsumer::class)->consume(20);
                $warning = ($projection['errors'] ?? 0) > 0 || ($projection['quarantined_events'] ?? 0) > 0;
            } catch (\Throwable $error) {
                $warning = true;
            }
            $result = $callback();
            $result['projection_warning'] = $warning;
            return response()->json($result)->withHeaders($this->privateHeaders());
        } catch (ValidationException $error) {
            return $this->failure(422);
        } catch (HttpException $error) {
            return $this->failure($error->getStatusCode());
        } catch (\Throwable $error) {
            // Database errors and encrypted records must never be returned or logged with chat content.
            return $this->failure(503);
        }
    }

    private function scopedConversations()
    {
        return DB::table('whatsapp_inbox_conversations')
            ->where('waba_id', WhatsAppInboxAccess::WABA_ID)
            ->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID);
    }

    private function decodeCursor(string $value): array
    {
        try {
            $cursor = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($cursor) || ($cursor['waba'] ?? null) !== WhatsAppInboxAccess::WABA_ID
                || ($cursor['phone'] ?? null) !== WhatsAppInboxAccess::PHONE_ID
                || !is_int($cursor['id'] ?? null) || $cursor['id'] < 1
                || !is_string($cursor['last'] ?? null)
                || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $cursor['last'])) {
                abort(422);
            }
            return $cursor;
        } catch (\Throwable $error) {
            abort(422);
        }
    }

    private function decrypt(string $value): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $error) {
            return null;
        }
    }

    private function safeText($value, int $limit): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $limit) : null;
    }

    private function displayText(?array $dto, int $limit): ?string
    {
        if ($dto === null) return null;
        $text = $this->safeText($dto['text'] ?? null, $limit);
        if ($text !== null) return $text;
        $type = $dto['type'] ?? null;
        $message = $dto['content']['message'] ?? null;
        if (!is_array($message)) return null;
        // Display a small, explicit text selection from attachments; never expose the raw DTO.
        if (in_array($type, ['image', 'video', 'document'], true)) {
            return $this->safeText($message[$type]['caption'] ?? null, $limit);
        }
        if ($type === 'location') {
            $location = $message['location'] ?? null;
            if (!is_array($location)) return null;
            $parts = [];
            foreach (['name', 'address'] as $field) {
                $value = $this->safeText($location[$field] ?? null, 500);
                if ($value !== null) $parts[] = $value;
            }
            $latitude = $location['latitude'] ?? null;
            $longitude = $location['longitude'] ?? null;
            if (is_numeric($latitude) && is_numeric($longitude)
                && (float) $latitude >= -90 && (float) $latitude <= 90
                && (float) $longitude >= -180 && (float) $longitude <= 180) {
                $parts[] = number_format((float) $latitude, 6, '.', '') . ', '
                    . number_format((float) $longitude, 6, '.', '');
            }
            return $parts ? $this->safeText(implode("\n", $parts), $limit) : null;
        }
        if ($type === 'button') return $this->safeText($message['button']['text'] ?? null, $limit);
        if ($type === 'reaction') return $this->safeText($message['reaction']['emoji'] ?? null, $limit);
        if ($type === 'interactive') {
            return $this->safeText($message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title'] ?? null, $limit);
        }
        if ($type === 'order') {
            $cart = $this->displayCart($dto);
            return $cart === null ? null : $this->safeText($cart['text'] ?: count($cart['product_items'])
                . ' × ' . ($this->labels()['cart_title'] ?? 'Cart') . ' · ' . $cart['total_price'] . ' ' . $cart['currency'], $limit);
        }
        return null;
    }

    private function displayCart(?array $dto): ?array
    {
        if (($dto['type'] ?? null) !== 'order' || ($dto['direction'] ?? null) !== 'inbound') return null;
        $cart = WhatsAppInboxProtocol::cart($dto['content']['message']['order'] ?? null);
        if ($cart === null) return null;
        $names = config('whatsapp_cart.product_names', []);
        $catalogNames = is_array($names) && is_array($names[$cart['catalog_id']] ?? null)
            ? $names[$cart['catalog_id']] : [];
        foreach ($cart['product_items'] as &$item) {
            $name = $catalogNames[$item['product_retailer_id']] ?? null;
            if (is_string($name) && $name !== '' && trim($name) === $name && strlen($name) <= 800
                && preg_match('//u', $name) === 1 && mb_strlen($name, 'UTF-8') <= 200
                && !preg_match('/[\x00-\x1f\x7f]/', $name)) $item['name'] = $name;
        }
        unset($item);
        return $cart;
    }

    private function phone($value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9]{6,20}\z/', $value) ? '+' . $value : null;
    }

    private function timestamp($value): ?string
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
        } catch (\Throwable $error) {
            return null;
        }
    }

    private function messageType($value): string
    {
        return in_array($value, ['text', 'image', 'video', 'audio', 'document', 'sticker', 'location',
            'contacts', 'interactive', 'button', 'reaction', 'order', 'system'], true) ? $value : 'other';
    }

    private function failure(int $status)
    {
        return response()->json(['success' => false, 'error' => 'INBOX_UNAVAILABLE'], $status)
            ->withHeaders($this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff'];
    }

    private function labels(): array
    {
        if (app()->getLocale() !== 'ar') return [
            'title' => 'WhatsApp inbox', 'subtitle' => 'Fasakhansta · +20 12 85545554',
            'read_only' => 'Review received customer messages and outgoing business messages.',
            'no_send' => 'Reply through WhatsApp Business. This page displays received copies.',
            'central_scope' => 'Central inbox', 'refresh' => 'Refresh', 'loading' => 'Loading…',
            'connected' => 'Updated', 'offline' => 'WhatsApp requires an internet connection.',
            'unavailable' => 'The inbox is temporarily unavailable. Try refreshing.',
            'denied' => 'Your access has changed. The conversation is no longer available.',
            'projection_warning' => 'Some received messages could not be updated.',
            'empty' => 'No conversations received yet.', 'conversations' => 'Conversations',
            'choose_conversation' => 'Choose a conversation', 'messages' => 'Messages',
            'more_conversations' => 'More conversations', 'older_messages' => 'Older messages',
            'back' => 'Conversations', 'customer' => 'Customer', 'business' => 'Business message',
            'message_unavailable' => 'This message could not be read.', 'no_messages' => 'No messages in this conversation.',
            'cart_title' => 'Submitted cart', 'cart_product' => 'Catalog product', 'cart_quantity' => 'Catalog units',
            'cart_unit_price' => 'Quoted unit price', 'cart_total' => 'Quoted cart subtotal',
            'cart_note' => 'Product IDs are matched to the branch catalog before creating an order.',
            'types' => ['text' => 'Text', 'image' => 'Image', 'video' => 'Video', 'audio' => 'Voice message',
                'document' => 'Document', 'sticker' => 'Sticker', 'location' => 'Location', 'contacts' => 'Contact',
                'interactive' => 'Interactive message', 'button' => 'Button reply', 'reaction' => 'Reaction',
                'order' => 'WhatsApp order message', 'system' => 'System message', 'other' => 'Message'],
        ];
        return [
            'title' => 'رسائل واتساب', 'subtitle' => 'فسخانستا · 01285545554',
            'read_only' => 'متابعة رسائل العملاء والرسائل الصادرة من حساب النشاط.',
            'no_send' => 'الرد من واتساب بيزنس. هذه الصفحة تعرض نسخ الرسائل المستلمة.',
            'central_scope' => 'صندوق الإدارة', 'refresh' => 'تحديث', 'loading' => 'جارٍ التحميل…',
            'connected' => 'تم التحديث', 'offline' => 'رسائل واتساب تحتاج اتصالًا بالإنترنت.',
            'unavailable' => 'الرسائل غير متاحة الآن. حاول التحديث.',
            'denied' => 'تغيّرت صلاحية حسابك. المحادثة لم تعد متاحة.',
            'projection_warning' => 'تعذّر تحديث بعض الرسائل المستلمة.',
            'empty' => 'لم تصل محادثات بعد.', 'conversations' => 'المحادثات',
            'choose_conversation' => 'اختر محادثة لعرض الرسائل', 'messages' => 'الرسائل',
            'more_conversations' => 'عرض محادثات أخرى', 'older_messages' => 'رسائل أقدم',
            'back' => 'المحادثات', 'customer' => 'العميل', 'business' => 'رسالة من النشاط',
            'message_unavailable' => 'تعذّرت قراءة هذه الرسالة.', 'no_messages' => 'لا توجد رسائل في هذه المحادثة.',
            'cart_title' => 'سلة العميل', 'cart_product' => 'صنف الكتالوج', 'cart_quantity' => 'عدد وحدات الصنف',
            'cart_unit_price' => 'سعر الوحدة في السلة', 'cart_total' => 'إجمالي أصناف السلة',
            'cart_note' => 'تُطابق الأصناف مع قائمة الفرع قبل إنشاء الطلب.',
            'types' => ['text' => 'رسالة نصية', 'image' => 'صورة', 'video' => 'فيديو', 'audio' => 'رسالة صوتية',
                'document' => 'مستند', 'sticker' => 'ملصق', 'location' => 'موقع', 'contacts' => 'جهة اتصال',
                'interactive' => 'رسالة تفاعلية', 'button' => 'ردّ بزر', 'reaction' => 'تفاعل',
                'order' => 'رسالة طلب واتساب', 'system' => 'رسالة نظام', 'other' => 'رسالة'],
        ];
    }
}
