<?php
namespace App\Services\GoPayments;

use App\Http\Controllers\Payment\PaymobController;
use App\Services\GoServices\PaymobHmac;
use Illuminate\Support\Facades\Http;

/** One hosted Paymob integration; client redirects never authorize wallet writes. */
class Gateway
{
    public static function settings(bool $services = false): array
    {
        $dedicated = config('go_services.paymob', []);
        if ($services && !empty($dedicated['enabled'])) return $dedicated;
        $config = config('go_payments', []);
        if (empty($config['enabled'])) return [];
        $config = $config + ((!empty($config['secret_key']) && !empty($config['public_key'])) ? [] : PaymobController::gatewayCredentials());
        $config['is_live'] = $config['is_live'] ?? str_contains($config['public_key'], '_live_');
        return $config;
    }

    public static function methods(bool $services = false): array
    {
        $c = self::settings($services);
        if (empty($c['enabled']) || empty($c['secret_key']) || empty($c['public_key']) || (empty($c['hmac_secret']) && empty($c['api_key']))) return [];
        return array_values(array_filter(['mobile_wallet','card'], fn($m) => filter_var($c['methods'][$m] ?? null, FILTER_VALIDATE_INT) && (int)$c['methods'][$m] > 0));
    }

    public function create(array $payload, array $config): array
    {
        $response = Http::timeout(20)->acceptJson()->withHeaders(['Authorization'=>'Token '.preg_replace('/^Token\s+/i','',(string)$config['secret_key'])])
            ->post('https://accept.paymob.com/v1/intention/', $payload)->throw()->json();
        if (empty($response['client_secret']) || empty($response['intention_order_id'])) throw new \RuntimeException('Incomplete gateway response');
        return $response;
    }

    public function verify(array $object, string $signature, array $config): array
    {
        if (!empty($config['hmac_secret'])) {
            abort_unless(PaymobHmac::valid($object, $signature, $config['hmac_secret']), 403, 'Invalid signature');
            return $object;
        }
        // Merchant-authenticated inquiry is authoritative when this existing
        // integration has no HMAC secret configured. Ignore every supplied flag.
        return $this->inquire((string)($object['id'] ?? ''), $config);
    }

    public function inquire(string $transaction, array $config): array
    {
        abort_unless(preg_match('/^\d{1,30}$/D', $transaction) && !empty($config['api_key']), 403, 'Payment verification unavailable');
        try {
            $token = Http::timeout(15)->acceptJson()->post('https://accept.paymob.com/api/auth/tokens', ['api_key'=>$config['api_key']])->throw()->json('token');
            if (!$token) throw new \RuntimeException('Missing gateway token');
            $verified = Http::timeout(15)->acceptJson()->withToken($token)->get('https://accept.paymob.com/api/acceptance/transactions/'.$transaction)->throw()->json();
        } catch (\Throwable $e) { abort(502, 'Payment verification unavailable'); }
        abort_unless(is_array($verified) && (string)($verified['id'] ?? '') === $transaction, 403, 'Transaction mismatch');
        return $verified;
    }

    public static function successful(array $o): bool
    {
        return PaymobHmac::truth($o['success'] ?? false) && !PaymobHmac::truth($o['pending'] ?? true)
            && !PaymobHmac::truth($o['is_auth'] ?? false) && !PaymobHmac::truth($o['error_occured'] ?? true)
            && (PaymobHmac::truth($o['is_capture'] ?? false) || PaymobHmac::truth($o['is_standalone_payment'] ?? false));
    }

    public static function billing(object $user): array
    {
        $names = preg_split('/\s+/', trim($user->name ?? 'GO'), 2);
        abort_unless(filter_var($user->email, FILTER_VALIDATE_EMAIL), 422, 'أضف بريدًا إلكترونيًا صحيحًا إلى حسابك للدفع.');
        return ['first_name'=>$names[0] ?: 'GO','last_name'=>$names[1] ?? $names[0], 'email'=>$user->email,
            'phone_number'=>'+20'.ltrim((string)$user->mobile,'0'), 'apartment'=>'NA','floor'=>'NA','street'=>'NA','building'=>'NA',
            'shipping_method'=>'NA','postal_code'=>'NA','city'=>'NA','state'=>'NA','country'=>'EG'];
    }
}
