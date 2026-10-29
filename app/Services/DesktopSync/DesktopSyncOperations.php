<?php

namespace App\Services\DesktopSync;

use App\Models\User;
use App\Services\Dashboard\CustomerDirectory;
use App\Services\Dashboard\DesktopPosQuote;
use App\Services\Dashboard\OperatingDay;
use App\Services\Dashboard\TakeawayAccess;
use App\Services\Dashboard\TakeawayService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Applies what a desktop device recorded while it worked locally. Each operation is applied once:
 * a resend returns the stored result. Business errors are kept as rejections; temporary server
 * errors are answered with "retry" so the device sends the same operation again later.
 */
class DesktopSyncOperations
{
    public const KINDS = ['sale'];
    private TakeawayAccess $access;
    private DesktopSyncState $state;

    public function __construct(TakeawayAccess $access, DesktopSyncState $state)
    {
        $this->access = $access;
        $this->state = $state;
    }

    public function apply(object $device, array $operations): array
    {
        $results = [];
        foreach (array_slice($operations, 0, 50) as $operation) {
            $results[] = $this->one($device, is_array($operation) ? $operation : []);
        }
        return $results;
    }

    private function one(object $device, array $operation): array
    {
        $id = is_string($operation['id'] ?? null) && Str::isUuid($operation['id']) ? $operation['id'] : null;
        if ($id === null) return ['id' => $operation['id'] ?? null, 'status' => 'rejected', 'error' => 'رقم العملية غير صالح.'];
        $hash = hash('sha256', json_encode([$operation['kind'] ?? null, $operation['actor_id'] ?? null, $operation['branch'] ?? null,
            $operation['occurred_at'] ?? null, $operation['data'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stored = DB::table('desktop_sync_operations')->where('device_id', $device->id)->where('op_id', $id)->first();
        if ($stored) {
            if (! hash_equals($stored->request_hash, $hash)) return ['id' => $id, 'status' => 'rejected', 'error' => 'رقم العملية مستخدم لبيانات مختلفة.'];
            return ['id' => $id, 'status' => $stored->status] + (json_decode($stored->result, true) ?: []);
        }
        try {
            $values = Validator::make($operation, [
                'kind' => 'required|in:'.implode(',', self::KINDS), 'actor_id' => 'required|integer|min:1',
                'branch' => ['required', 'string', 'regex:/^(f|gs):[1-9][0-9]{0,18}$/D'], 'occurred_at' => 'required|date', 'data' => 'required|array',
            ])->validate();
            abort_unless(in_array($values['branch'], $device->branch_values, true), 403, 'الفرع غير مفعّل على هذا الجهاز.');
            $when = Carbon::parse($values['occurred_at'])->setTimezone('UTC');
            abort_unless($when->lte(now('UTC')->addMinutes(10)), 422, 'ساعة الكمبيوتر غير مضبوطة.');
            $actor = User::query()->find($values['actor_id']);
            abort_unless($actor, 403, 'الحساب غير موجود.');
            $result = $this->sale($device, $actor, $values['branch'], $id, $when, $values['data']);
            $status = 'applied';
        } catch (ValidationException $error) {
            $result = ['error' => collect($error->errors())->flatten()->first() ?: 'بيانات العملية غير صحيحة.'];
            $status = 'rejected';
        } catch (HttpException $error) {
            if ($error->getStatusCode() >= 500) return ['id' => $id, 'status' => 'retry'];
            $result = ['error' => $error->getMessage() ?: 'تم رفض العملية.'];
            $status = 'rejected';
        } catch (\Throwable $error) {
            // A database or server fault is not a decision about the sale; the device sends it again.
            report($error);
            return ['id' => $id, 'status' => 'retry'];
        }
        $now = now('UTC');
        DB::table('desktop_sync_operations')->insertOrIgnore([
            'device_id' => $device->id, 'op_id' => $id, 'kind' => (string) ($operation['kind'] ?? ''),
            'actor_id' => (int) ($operation['actor_id'] ?? 0), 'branch' => substr((string) ($operation['branch'] ?? ''), 0, 40),
            'request_hash' => $hash, 'status' => $status, 'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'occurred_at' => isset($when) ? $when : $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['id' => $id, 'status' => $status] + $result;
    }

    /** The sale is priced from the menu version the device sold with, never from today's prices. */
    private function sale(object $device, User $actor, string $branch, string $id, Carbon $when, array $data): array
    {
        $data = Validator::make($data, [
            'snapshot_id' => 'required|uuid', 'channel' => 'required|in:takeaway,dine,phone',
            'items' => 'required|array|min:1|max:100', 'customer_name' => 'nullable|string|max:100',
            'customer_phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:500',
            'table_name' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:500',
            'delivery_cents' => 'required|integer|min:0|max:100000000', 'discount' => 'nullable|string|max:14',
            'discount_reason' => 'nullable|string|max:500', 'cash_received' => 'required|string|max:14',
            'total_cents' => 'required|integer|min:0|max:100000000', 'local_number' => 'nullable|string|max:40',
            'service_consent' => 'nullable|boolean', 'marketing_consent' => 'nullable|boolean',
        ])->validate();
        $actor = $this->access->actor($actor);
        $this->access->branch($branch, $actor);
        $permissions = $this->access->permissions($actor);
        abort_unless($permissions['can_checkout'], 403, 'الحساب غير مسموح له بالبيع.');
        $snapshot = $this->state->snapshot($device, $data['snapshot_id']);
        abort_unless($snapshot && ($snapshot['branch']['value'] ?? null) === $branch, 409, 'نسخة المينيو غير معروفة لهذا الجهاز.');
        $cart = app(TakeawayService::class)->canonicalCart(['branch' => $branch] + $data);
        if ($cart['discount_cents'] > 0) {
            abort_unless($permissions['can_manage'], 403, 'الخصم متاح للمالك أو الإدارة المخولة فقط.');
            abort_unless(trim($cart['discount_reason']) !== '', 422, 'اكتب سبب الخصم.');
        }
        if ($data['channel'] === 'phone') abort_unless(trim($data['customer_name'] ?? '') !== '' && trim($data['address'] ?? '') !== '', 422, 'أكمل اسم العميل وعنوانه.');
        if ($data['channel'] === 'dine') abort_unless(trim($data['table_name'] ?? '') !== '', 422, 'أدخل رقم الطاولة.');
        $quote = app(DesktopPosQuote::class)->build($snapshot, $cart, $data['channel'], (int) $data['delivery_cents']);
        abort_unless($quote['total_cents'] === (int) $data['total_cents'], 409, 'إجمالي الفاتورة لا يطابق المينيو المحفوظ على الجهاز.');
        $phone = trim((string) ($data['customer_phone'] ?? ''));
        if ($data['channel'] !== 'phone' || $phone !== '') $phone = CustomerDirectory::requirePhone($phone);
        // Everything below is derived from the operation itself, so a resend reproduces the same request.
        $context = [
            'channel' => $data['channel'], 'trusted_offline' => true, 'legacy_customer_phone_missing' => false,
            'offline_operation' => $id, 'saved_quote' => $quote, 'business_date' => OperatingDay::date($when),
            'paid_at' => $when, 'branch_snapshot' => $snapshot['branch'],
            'snapshot' => ['customer_name' => trim((string) ($data['customer_name'] ?? '')), 'customer_phone' => $phone,
                'address' => trim((string) ($data['address'] ?? '')), 'table_name' => trim((string) ($data['table_name'] ?? '')),
                'notes' => trim((string) ($data['notes'] ?? '')), 'desktop_device' => $device->id,
                'local_number' => (string) ($data['local_number'] ?? ''), 'occurred_at' => $when->toIso8601String()],
        ];
        $payment = app(TakeawayService::class)->checkout([
            'branch' => $branch, 'items' => $data['items'], 'discount' => $data['discount'] ?? null,
            'discount_reason' => $data['discount_reason'] ?? null, 'idempotency_key' => $id, 'quote_hash' => $quote['quote_hash'],
            'payment_method' => 'cash', 'cash_received' => $data['cash_received'], 'payment_confirmed' => true,
            'notes' => $data['notes'] ?? null, 'customer_phone' => $phone !== '' ? $phone : null,
            'customer_name' => $data['customer_name'] ?? null, 'service_consent' => (bool) ($data['service_consent'] ?? false),
            'marketing_consent' => (bool) ($data['marketing_consent'] ?? false),
        ], $actor, $context);
        $receipt = $payment['receipt'];
        return ['receipt' => ['id' => (int) $receipt['id'], 'number' => (string) $receipt['number'],
            'business_date' => (string) $receipt['business_date'], 'total' => (string) $receipt['total']]];
    }
}
