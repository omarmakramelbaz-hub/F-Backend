<?php

require __DIR__.'/../app/Support/WhatsAppWebhookProtocol.php';

use App\Support\WhatsAppWebhookProtocol as Protocol;

$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
}
function rejects($body) {
    try {
        Protocol::scopedPayload($body, ['123']);
        return false;
    } catch (InvalidArgumentException $error) {
        return true;
    }
}
$query = ['hub.mode' => 'subscribe', 'hub.verify_token' => 'test-token', 'hub.challenge' => '42'];
check(Protocol::challenge($query, 'test-token') === '42', 'Meta challenge');
check(Protocol::challenge(['hub_mode'=>'subscribe','hub_verify_token'=>'test-token','hub_challenge'=>'42'], 'test-token') === '42', 'PHP dotted query normalization');
check(Protocol::challenge($query, 'wrong') === null, 'Wrong token');
check(Protocol::challenge($query, '') === null, 'Unconfigured token');
$query['hub.verify_token'] = ['test-token'];
check(Protocol::challenge($query, 'test-token') === null, 'Reject query arrays');
$body = '{"object":"whatsapp_business_account","entry":[{"id":"123","changes":[{"value":{"messages":[{"id":"wamid.test","text":{"body":"طلب تجريبي"}}],"empty":{}}}]},{"id":"999","changes":[]}]}';
$signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');
check(Protocol::authentic($body, $signature, 'test-secret'), 'Valid HMAC');
check(!Protocol::authentic($body.' ', $signature, 'test-secret'), 'Authenticate exact raw bytes');
check(!Protocol::authentic($body, $signature, 'wrong'), 'Wrong app secret');
check(!Protocol::authentic($body, null, 'test-secret'), 'Missing signature');
check(!Protocol::authentic($body, $signature.'\n', 'test-secret'), 'Malformed signature');
check(!Protocol::authentic($body, $signature, ''), 'Unconfigured secret');
$scoped = Protocol::scopedPayload($body, ['123']);
$decoded = json_decode($scoped);
check(count($decoded->entry) === 1 && $decoded->entry[0]->id === '123', 'Account isolation');
check($decoded->entry[0]->changes[0]->value->empty instanceof stdClass, 'Preserve JSON objects');
check($decoded->entry[0]->changes[0]->value->messages[0]->text->body === 'طلب تجريبي', 'Preserve Arabic message');
check(Protocol::scopedPayload($body, ['888']) === null, 'Ignore other accounts');
check(rejects('{'), 'Invalid JSON');
check(rejects('{"object":"page","entry":[]}'), 'Wrong object');
check(rejects('{"object":"whatsapp_business_account","entry":{}}'), 'Malformed entries');
check(rejects('{"object":"whatsapp_business_account","entry":[{"id":["123"]}]}'), 'Malformed account ID');
$large = str_repeat('a', Protocol::MAX_BODY_BYTES + 1);
check(!Protocol::authentic($large, 'sha256='.hash_hmac('sha256', $large, 'test-secret'), 'test-secret'), 'Reject oversized signed request');
check(rejects($large), 'Reject oversized JSON');
echo $checks." protocol checks passed\n";
