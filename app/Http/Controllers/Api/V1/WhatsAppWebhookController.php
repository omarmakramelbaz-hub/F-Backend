<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\WhatsAppWebhookProtocol as Protocol;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $token = (string) config('whatsapp.verify_token', '');
        if ($token === '') {
            return response('Webhook is not configured', 503);
        }
        $challenge = Protocol::challenge($request->query(), $token);

        return $challenge === null
            ? response('Forbidden', 403)
            : response($challenge, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function receive(Request $request)
    {
        $body = $request->getContent();
        if (strlen($body) > Protocol::MAX_BODY_BYTES) {
            return response('Payload too large', 413);
        }
        $secret = (string) config('whatsapp.app_secret', '');
        $accounts = config('whatsapp.allowed_account_ids', []);
        if ($secret === '' || !is_array($accounts) || $accounts === []) {
            return response('Webhook is not configured', 503);
        }
        if (!Protocol::authentic($body, $request->header('X-Hub-Signature-256'), $secret)) {
            return response('Forbidden', 403);
        }
        try {
            $payload = Protocol::scopedPayload($body, $accounts);
        } catch (InvalidArgumentException $error) {
            return response('Invalid payload', 400);
        }
        if ($payload === null) {
            return response('EVENT_RECEIVED', 200);
        }

        try {
            $hash = hash('sha256', $payload);
            if (!DB::table('whatsapp_webhook_events')->where('payload_hash', $hash)->exists()) {
                try {
                    DB::table('whatsapp_webhook_events')->insert([
                        'payload_hash' => $hash,
                        'payload' => Crypt::encryptString($payload),
                        'received_at' => now('UTC'),
                    ]);
                } catch (QueryException $error) {
                    // Concurrent delivery is successful only if the event really exists.
                    if (!DB::table('whatsapp_webhook_events')->where('payload_hash', $hash)->exists()) {
                        throw $error;
                    }
                }
            }
        } catch (Throwable $error) {
            Log::warning('WhatsApp webhook persistence failed', ['type' => get_class($error)]);
            return response('Please retry', 503);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
