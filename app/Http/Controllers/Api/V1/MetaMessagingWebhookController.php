<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\MetaInboxAccess;
use App\Support\WhatsAppWebhookProtocol;
use App\Support\MetaMessagingProtocol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MetaMessagingWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $token=(string)config('meta_messaging.verify_token','');
        if ($token==='') return response('Not configured',503);
        $challenge=WhatsAppWebhookProtocol::challenge($request->query(),$token);
        return $challenge===null ? response('Forbidden',403) : response($challenge,200)->header('Content-Type','text/plain');
    }

    public function receive(Request $request)
    {
        $body=$request->getContent();
        if (strlen($body)>WhatsAppWebhookProtocol::MAX_BODY_BYTES) return response('Payload too large',413);
        $secret=(string)config('meta_messaging.app_secret',''); $accounts=MetaInboxAccess::accounts();
        if ($secret===''||!$accounts) return response('Not configured',503);
        if (!WhatsAppWebhookProtocol::authentic($body,$request->header('X-Hub-Signature-256'),$secret)) return response('Forbidden',403);
        try { $payload=MetaMessagingProtocol::scopedPayload($body,$accounts); }
        catch (\InvalidArgumentException $error) { return response('Invalid payload',400); }
        if ($payload===null) return response('EVENT_RECEIVED',200);
        try {
            $hash=hash('sha256',$payload);
            if (!DB::table('meta_webhook_events')->where('payload_hash',$hash)->exists()) {
                try { DB::table('meta_webhook_events')->insert(['payload_hash'=>$hash,'payload'=>Crypt::encryptString($payload),'received_at'=>now('UTC')]); }
                catch (\Illuminate\Database\QueryException $e) { if (!DB::table('meta_webhook_events')->where('payload_hash',$hash)->exists()) throw $e; }
            }
        } catch (\Throwable $error) { return response('Please retry',503); }
        return response('EVENT_RECEIVED',200);
    }
}
