<?php
// Namespace curl substitutes exercise the real protected transport, without sockets.
namespace App\Services\Payments;

final class InquiryCurlFixture
{
    public static $responses = [];
    public static $handles = [];
    public static function reset(array $responses): void { self::$responses = $responses; self::$handles = []; }
}
function curl_init($url) {
    $handle = (object) ['url' => $url, 'options' => [], 'response' => array_shift(InquiryCurlFixture::$responses)];
    InquiryCurlFixture::$handles[] = $handle; return $handle;
}
function curl_setopt_array($handle, array $options) { $handle->options = $options; return true; }
function curl_exec($handle) {
    $body = $handle->response['body'];
    return ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $body) === strlen($body);
}
function curl_getinfo($handle, $option) { return $handle->response['status']; }
function curl_close($handle) {}

InquiryCurlFixture::reset([
    ['status' => 201, 'body' => json_encode(['token' => 'fixture_token'])],
    ['status' => 200, 'body' => json_encode(\settledGatewayTransaction())],
]);
$transaction = (new PaymobTransactionInquiry)->transactionForOrder('88001');
\settlementExpect($transaction['id'] === 99001 && count(InquiryCurlFixture::$handles) === 2,
    'real transport accepts Paymob authentication HTTP201 then transaction HTTP200');
$options = InquiryCurlFixture::$handles[1]->options;
$payload = json_decode($options[CURLOPT_POSTFIELDS], true);
\settlementExpect($payload === ['auth_token' => 'fixture_token', 'order_id' => '88001'],
    'real order inquiry sends documented auth_token in JSON body');
\settlementExpect($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0
    && $options[CURLOPT_TIMEOUT_MS] > 0 && $options[CURLOPT_TIMEOUT_MS] <= 8000,
    'real transport prohibits redirects and bounds the shared request budget');

foreach ([
    'redirect response' => ['status' => 302, 'body' => '{"token":"private"}'],
    'gateway HTTP failure' => ['status' => 500, 'body' => 'PRIVATE_PROVIDER_BODY'],
    'malformed gateway response' => ['status' => 200, 'body' => 'PRIVATE_INVALID_JSON'],
    'oversized gateway response' => ['status' => 200, 'body' => str_repeat('x', 1048577)],
] as $label => $response) {
    InquiryCurlFixture::reset([$response]); $message = '';
    try { (new PaymobTransactionInquiry)->transactionForOrder('88001'); }
    catch (\RuntimeException $error) { $message = $error->getMessage(); }
    \settlementExpect(str_starts_with($message, 'WALLET_TOPUP_GATEWAY_') && strpos($message, 'PRIVATE_') === false
        && count(InquiryCurlFixture::$handles) === 1, $label . ' stops without retries or raw payload leakage');
}
