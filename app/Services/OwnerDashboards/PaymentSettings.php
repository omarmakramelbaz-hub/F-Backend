<?php
namespace App\Services\OwnerDashboards;

use Illuminate\Support\Facades\{Crypt,DB,Schema,Validator};

/** Tenant payment credentials live in its own encrypted settings, never in an editable database environment file. */
final class PaymentSettings
{
    public const KEYS=['PAYMOB_API_KEY','PAYMOB_CARD_INTEGRATION_ID','PAYMOB_CARD_IFRAME_ID','PAYMOB_MOBILE_WALLET_INTEGRATION_ID'];
    public static function values(): array
    {
        if(!Runtime::tenant()||!Schema::hasTable('settings'))return[];
        $payload=DB::table('settings')->where('group','owner_integrations')->where('name','paymob')->value('payload');
        if($payload===null)return[];
        $values=json_decode(Crypt::decryptString(json_decode($payload,true,16,JSON_THROW_ON_ERROR)),true,16,JSON_THROW_ON_ERROR);
        return array_intersect_key($values,array_flip(self::KEYS));
    }
    public static function save(array $input): void
    {
        abort_unless(Runtime::tenant(),403);
        $rules=[];foreach(self::KEYS as$key)$rules[$key]=$key==='PAYMOB_API_KEY'?'nullable|string|max:8192|regex:/^[^\r\n\x00]*$/D':'nullable|string|max:32|regex:/^[0-9]*$/D';
        $values=Validator::make($input,$rules)->validate();
        $payload=json_encode(Crypt::encryptString(json_encode($values,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR);
        DB::table('settings')->updateOrInsert(['group'=>'owner_integrations','name'=>'paymob'],['locked'=>0,'payload'=>$payload]);
    }
    public static function load(): void
    {
        $values=self::values();
        foreach(self::KEYS as$key){$value=(string)($values[$key]??'');putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
        config(['dashboard_payments.integrations.online'=>env('PAYMOB_CARD_INTEGRATION_ID'),
            'dashboard_payments.integrations.v_cash'=>env('PAYMOB_MOBILE_WALLET_INTEGRATION_ID')]);
    }
}
