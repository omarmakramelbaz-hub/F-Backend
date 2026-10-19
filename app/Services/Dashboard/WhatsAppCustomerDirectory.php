<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/** Sender contact capture only: no purchases, address inference, or consent changes. */
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
            DB::table('crm_customers')->where('id', $customer->id)->update($changes);
            $source = ['customer_id'=>$customer->id, 'branch'=>'', 'channel'=>'whatsapp'];
            DB::table('crm_customer_sources')->insertOrIgnore($source + [
                'first_seen_at'=>$when, 'last_seen_at'=>$when, 'created_at'=>$when, 'updated_at'=>$when,
            ]);
            DB::table('crm_customer_sources')->where($source)->update(['last_seen_at'=>$when, 'updated_at'=>$when]);
            return DB::table('crm_customers')->where('id', $customer->id)->first();
        }, 3);
    }
}
