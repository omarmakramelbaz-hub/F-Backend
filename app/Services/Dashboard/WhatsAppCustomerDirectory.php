<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/** Verified WhatsApp contacts, with owner-defined defaults and durable refusal handling. */
class WhatsAppCustomerDirectory
{
    private CustomerDirectory $directory;

    public function __construct(?CustomerDirectory $directory = null)
    {
        $this->directory = $directory ?? new CustomerDirectory();
    }

    /** Input identity must come from the verified inbox sender profile. */
    public function capture(array $profile): ?object
    {
        $value = $profile['phone'] ?? null;
        $phone = is_string($value) ? CustomerDirectory::normalizePhone($value) : null;
        if ($phone === null || !$this->directory->ready()) return null;
        $name = $profile['name'] ?? null;
        $name = is_string($name) && preg_match('//u', $name) === 1 ? mb_substr(trim($name), 0, 100) : '';

        return DB::transaction(function () use ($phone, $name) {
            $when = now('UTC');
            DB::table('crm_customers')->insertOrIgnore([
                'phone'=>$phone, 'phone_key'=>CustomerDirectory::phoneKey($phone),
                'first_seen_at'=>$when, 'last_seen_at'=>$when, 'created_at'=>$when, 'updated_at'=>$when,
            ]);
            $customer = DB::table('crm_customers')->where('phone', $phone)->lockForUpdate()->first();
            if (!$customer) throw new \RuntimeException('WHATSAPP_CUSTOMER_CAPTURE_NOT_PERSISTED');
            $changes = ['last_seen_at'=>$when, 'updated_at'=>$when];
            if (trim((string)($customer->name ?? '')) === '' && $name !== '') $changes['name'] = $name;
            if (!app()->bound('owner.dashboard.runtime') && !config('desktop_dashboard.local', false)) {
                $changes += self::consentDefaults((array) $customer, $when->format('Y-m-d H:i:s'));
            }
            DB::table('crm_customers')->where('id', $customer->id)->update($changes);
            $source = ['customer_id'=>$customer->id, 'branch'=>'', 'channel'=>'whatsapp'];
            DB::table('crm_customer_sources')->insertOrIgnore($source + [
                'first_seen_at'=>$when, 'last_seen_at'=>$when, 'created_at'=>$when, 'updated_at'=>$when,
            ]);
            DB::table('crm_customer_sources')->where($source)->update(['last_seen_at'=>$when, 'updated_at'=>$when]);
            return DB::table('crm_customers')->where('id', $customer->id)->first();
        }, 3);
    }

    /** Owner policy applies to undecided contacts; a recorded refusal always wins. */
    public static function consentDefaults(array $customer, string $when): array
    {
        if (($customer['opted_out_at'] ?? null) !== null) return [];
        $changes = [];
        $evidence = 'owner-policy:whatsapp-contact-20261106; business owner confirms WhatsApp conversation customers agree to follow-up and offers; recorded on capture.';
        foreach (['service', 'marketing'] as $purpose) {
            $active = (bool) ($customer[$purpose.'_consent'] ?? false);
            $stamp = $customer[$purpose.'_consented_at'] ?? null;
            $proof = trim((string) ($customer[$purpose.'_consent_evidence'] ?? ''));
            $actor = $customer[$purpose.'_consented_by'] ?? null;
            if (!$active && ($stamp !== null || $proof !== '' || $actor !== null)) continue;
            if (!$active) $changes[$purpose.'_consent'] = true;
            if (empty($stamp)) $changes[$purpose.'_consented_at'] = $when;
            if ($proof === '') $changes[$purpose.'_consent_evidence'] = $evidence;
            if ($actor === null) $changes[$purpose.'_consented_by'] = 1;
        }
        return $changes;
    }
}
