<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** One customer identity for every branch and sales channel. */
class CustomerDirectory
{
    public const CHANNELS = ['takeaway', 'dine', 'app', 'delivery'];
    private bool $schemaReady = false;

    public static function normalizePhone(string $phone): ?string
    {
        $phone = strtr(trim($phone), [
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        ]);
        if ($phone === '' || preg_match('/[^+0-9\s().\-]/u', $phone)) return null;
        $phone = preg_replace('/[\s().\-]+/u', '', $phone);
        if (str_starts_with($phone, '00')) $phone = '+'.substr($phone, 2);
        if (preg_match('/^01[0125][0-9]{8}$/D', $phone)) $phone = '+20'.substr($phone, 1);
        elseif (preg_match('/^1[0125][0-9]{8}$/D', $phone)) $phone = '+20'.$phone;
        elseif (preg_match('/^0[2-9][0-9]{7,8}$/D', $phone)) $phone = '+20'.substr($phone, 1);
        elseif (preg_match('/^[1-9][0-9]{7,14}$/D', $phone)) $phone = '+'.$phone;
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) return null;
        // Egyptian mobile and landline lengths must survive normalization exactly.
        if (str_starts_with($phone, '+20') && !preg_match('/^\+20(?:1[0125][0-9]{8}|[2-9][0-9]{7,8})$/D', $phone)) return null;
        return $phone;
    }

    public static function phoneKey(string $phone): string
    {
        $phone = self::normalizePhone($phone);
        if ($phone === null) return '';
        return str_starts_with($phone, '+20') ? '0'.substr($phone, 3) : substr($phone, 1);
    }

    public static function requirePhone($value, string $field = 'customer_phone'): string
    {
        $phone = is_string($value) ? self::normalizePhone($value) : null;
        if ($phone === null) throw ValidationException::withMessages([$field=>'اكتب رقم هاتف صحيح للعميل قبل إنهاء الفاتورة.']);
        return $phone;
    }

    public function ready(): bool
    {
        return $this->schemaReady || ($this->schemaReady = Schema::hasTable('crm_customers') && Schema::hasTable('crm_customer_sources') && Schema::hasTable('crm_purchases'));
    }

    /** Explicit consent only; null leaves that purpose's previous choice intact. */
    public function consent(int $customerId, ?bool $service, ?bool $marketing, string $evidence, ?int $actorId = null): ?object
    {
        if (!$this->ready()) return null;
        if (trim($evidence) === '') throw new \InvalidArgumentException('Consent evidence is required.');
        return DB::transaction(function () use ($customerId, $service, $marketing, $evidence, $actorId) {
            $customer = DB::table('crm_customers')->where('id', $customerId)->lockForUpdate()->first();
            if (!$customer) return null;
            $when = now('UTC'); $changes = ['updated_at'=>$when];
            foreach (['service'=>$service, 'marketing'=>$marketing] as $purpose=>$choice) {
                if ($choice === null) continue;
                $changes[$purpose.'_consent'] = $choice;
                $changes[$purpose.'_consented_at'] = $choice ? $when : null;
                $changes[$purpose.'_consent_evidence'] = mb_substr(trim($evidence), 0, 500);
                $changes[$purpose.'_consented_by'] = $actorId;
            }
            if ($service === true || $marketing === true) $changes['opted_out_at'] = null;
            elseif (!(bool)($changes['service_consent'] ?? $customer->service_consent) && !(bool)($changes['marketing_consent'] ?? $customer->marketing_consent)) $changes['opted_out_at'] = $when;
            DB::table('crm_customers')->where('id', $customerId)->update($changes);
            return DB::table('crm_customers')->where('id', $customerId)->first();
        }, 3);
    }

    /** Partial profiles enrich existing data; consent is deliberately excluded. */
    public function capture(array $profile, string $channel, string $branch): ?object
    {
        $phone = self::normalizePhone((string)($profile['phone'] ?? $profile['customer_phone'] ?? $profile['mobile'] ?? ''));
        if ($phone === null || !$this->ready()) return null;
        $branch = substr($branch, 0, 30);
        return DB::transaction(function () use ($profile, $phone, $channel, $branch) {
            $when = now('UTC');
            DB::table('crm_customers')->insertOrIgnore([
                'phone'=>$phone, 'phone_key'=>self::phoneKey($phone),
                'first_seen_at'=>$when, 'last_seen_at'=>$when, 'created_at'=>$when, 'updated_at'=>$when,
            ]);
            $customer = DB::table('crm_customers')->where('phone', $phone)->lockForUpdate()->first();
            $changes = ['last_seen_at'=>$when, 'updated_at'=>$when];
            foreach (['name'=>100, 'address'=>500, 'area'=>150, 'delivery_notes'=>500] as $field=>$limit) {
                $value = $profile[$field] ?? ($field === 'name' ? ($profile['customer_name'] ?? null) : null);
                if (is_string($value) && trim($value) !== '') $changes[$field] = mb_substr(trim($value), 0, $limit);
            }
            $lat = $profile['latitude'] ?? $profile['lat'] ?? null;
            $lng = $profile['longitude'] ?? $profile['lng'] ?? null;
            if (is_numeric($lat) && is_numeric($lng) && abs((float)$lat)<=90 && abs((float)$lng)<=180) {
                $changes['latitude'] = (float)$lat; $changes['longitude'] = (float)$lng;
            }
            DB::table('crm_customers')->where('id', $customer->id)->update($changes);
            if (in_array($channel, self::CHANNELS, true)) {
                $source = ['customer_id'=>$customer->id, 'branch'=>$branch, 'channel'=>$channel];
                DB::table('crm_customer_sources')->insertOrIgnore($source + ['first_seen_at'=>$when, 'last_seen_at'=>$when, 'created_at'=>$when, 'updated_at'=>$when]);
                DB::table('crm_customer_sources')->where($source)->update(['last_seen_at'=>$when, 'updated_at'=>$when]);
            }
            return DB::table('crm_customers')->where('id', $customer->id)->first();
        }, 3);
    }

    /** Call only after a paid completion, within the sale transaction when possible. */
    public function purchase(array $profile, string $channel, string $branch, string $sourceType, string $sourceId, $paidAt): ?object
    {
        if (!in_array($channel, self::CHANNELS, true)) throw new \InvalidArgumentException('Unsupported customer purchase channel.');
        if ($sourceType === '' || strlen($sourceType)>40 || $sourceId === '' || strlen($sourceId)>64) throw new \InvalidArgumentException('Invalid purchase source.');
        if (!$this->ready()) return null;
        return DB::transaction(function () use ($profile, $channel, $branch, $sourceType, $sourceId, $paidAt) {
            $customer = $this->capture($profile, $channel, $branch);
            if (!$customer) return null;
            if ($paidAt === null || $paidAt === '') throw new \InvalidArgumentException('A completed payment timestamp is required.');
            $paid = Carbon::parse($paidAt, 'UTC')->setTimezone('UTC');
            $source = ['source_type'=>$sourceType, 'source_id'=>$sourceId];
            DB::table('crm_purchases')->insertOrIgnore($source + [
                'customer_id'=>$customer->id, 'branch'=>$branch, 'channel'=>$channel,
                'paid_at'=>$paid, 'feedback_due_at'=>$paid->copy()->addHours(24), 'feedback_status'=>'pending',
                'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
            ]);
            // Replays never postpone feedback or create a second message opportunity.
            return DB::table('crm_purchases')->where($source)->first();
        }, 3);
    }

    /** Recheck the original paid sale immediately before any feedback send. */
    public function purchaseIsCompleted(object $purchase): bool
    {
        if (in_array($purchase->source_type ?? '', ['app_order','go_store_order'], true)) {
            return app(CustomerDirectorySync::class)->purchaseIsCompleted($purchase);
        }
        if (($purchase->source_type ?? '') !== 'takeaway_orders' || !Schema::hasTable('takeaway_orders')) return false;
        $order = DB::table('takeaway_orders')->where('id', $purchase->source_id)->where('branch', $purchase->branch)->first();
        if (!$order || !(bool)$order->payment_confirmed) return false;
        foreach (['status','payment_status','refund_status'] as $field) {
            if (in_array(strtolower((string)($order->{$field} ?? '')), ['cancelled','canceled','failed','refunded','refund_pending','reversed'], true)) return false;
        }
        if (!empty($order->ticket_id)) {
            if (!Schema::hasTable('pos_service_tickets')) return false;
            $ticket = DB::table('pos_service_tickets')->where('id', $order->ticket_id)->where('branch', $order->branch)->where('paid_order_id', $order->id)->first();
            if (!$ticket || $ticket->payment_status !== 'paid' || !in_array($ticket->status, ['paid','finished'], true)) return false;
        }
        return true;
    }
}
