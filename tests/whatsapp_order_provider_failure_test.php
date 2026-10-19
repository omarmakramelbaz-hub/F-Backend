<?php

// Real extraction validation with private fake HTTP. No credentials, network, or ERP writes.
namespace App\Services\Dashboard {
    function function_exists(string $name): bool
    {
        return $name === 'curl_init' || \function_exists($name);
    }
    function curl_init(string $url)
    {
        $GLOBALS['waProviderHttp']['calls']++;
        $GLOBALS['waProviderHttp']['url'] = $url;
        return new \stdClass();
    }
    function curl_setopt_array($curl, array $options): bool
    {
        $GLOBALS['waProviderHttp']['options'] = $options;
        return true;
    }
    function curl_exec($curl)
    {
        $fixture = $GLOBALS['waProviderHttp']['fixture'];
        if (($fixture['exec'] ?? true) === false) return false;
        $callback = $GLOBALS['waProviderHttp']['options'][CURLOPT_WRITEFUNCTION];
        if ($callback($curl, $fixture['body']) !== strlen($fixture['body'])) return false;
        return true;
    }
    function curl_getinfo($curl, int $option): int { return $GLOBALS['waProviderHttp']['fixture']['status']; }
    function curl_errno($curl): int { return $GLOBALS['waProviderHttp']['fixture']['errno'] ?? 0; }
    function curl_close($curl): void { $GLOBALS['waProviderHttp']['closed']++; }
}

namespace {
    // The namespaced fake is also usable in a fixture PHP without the cURL extension.
    foreach (['CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER', 'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_MAXREDIRS', 'CURLOPT_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_SSL_VERIFYPEER',
        'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_VERBOSE',
        'CURLOPT_HEADER', 'CURLOPT_WRITEFUNCTION', 'CURLINFO_HTTP_CODE', 'CURLPROTO_HTTPS'] as $i => $name) {
        if (!defined($name)) define($name, 10000 + $i);
    }
    require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
    require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderAiProvider.php';

    use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
    use App\Services\Dashboard\WhatsAppOrderSourceSelection as SourceSelection;
    use App\Support\WhatsAppOrderExtraction as Extraction;

    $checks = 0;
    function providerExpect($actual, $expected, string $label): void
    {
        $GLOBALS['checks']++;
        if ($actual !== $expected) throw new \RuntimeException($label);
    }
    function providerConfiguration(string $model = 'gpt-6-luna'): array
    {
        return ['enabled' => true, 'mode' => 'review', 'model' => $model,
            'api_key' => 'sk-provider-fixture-private-never-print'];
    }
    function providerRows(): array
    {
        return [
            ['id' => 'm1', 'speaker' => 'customer', 'sent_at' => '2026-10-12 20:00:01',
                'text' => 'عايز 0.5 كيلو فسيخ من المنصورة. اسمي عميل تجريبي ورقمي 201000000001 وعنواني شارع التجربة في المنصورة.', 'location' => null],
            ['id' => 'm2', 'speaker' => 'business', 'sent_at' => '2026-10-12 20:00:02',
                'text' => 'تأكيد طلب عميل تجريبي: 0.5 كيلو فسيخ من المنصورة إلى شارع التجربة، الإجمالي التقريبي 450 جنيه.', 'location' => null],
            ['id' => 'm3', 'speaker' => 'customer', 'sent_at' => '2026-10-12 20:00:03',
                'text' => 'تمام موافق', 'location' => ['lat' => 31.04, 'long' => 31.38]],
        ];
    }
    function providerData(): array
    {
        return ['decision' => 'CONFIRMED',
            'customer' => ['name' => 'عميل تجريبي', 'phone' => '201000000001',
                'address' => 'شارع التجربة', 'area' => 'المنصورة', 'notes' => null],
            'branch_hint' => 'المنصورة', 'fulfillment' => 'DELIVERY',
            'items' => [['name' => 'فسيخ', 'quantity' => '0.5', 'quantity_mode' => 'weight',
                'option_hint' => null, 'cart_reference' => null]], 'approximate_total' => '450',
            'evidence' => ['request_ids' => ['m1'], 'confirmation_ids' => ['m2'],
                'customer_acceptance_ids' => ['m3'], 'location_id' => 'm3'],
            'issues' => ['PRICE_ESTIMATE_ONLY']];
    }
    function providerMessage(array $data): array
    {
        return ['type' => 'message', 'role' => 'assistant', 'content' => [
            ['type' => 'output_text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]];
    }
    function providerResponse(array $data): array
    {
        return ['status' => 'completed', 'output' => [
            ['type' => 'reasoning', 'summary' => []], providerMessage($data)],
            'usage' => ['input_tokens' => 1400, 'output_tokens' => 1200,
                'output_tokens_details' => ['reasoning_tokens' => 700]]];
    }
    function providerHttp(array $response): array
    {
        return ['status' => 200, 'body' => json_encode($response, JSON_THROW_ON_ERROR)];
    }
    function providerPrivateFailure(array $result, string $reason, string $label): void
    {
        providerExpect(array_keys($result), ['ok', 'reason', 'data', 'diagnostic'], $label . ' exact envelope');
        providerExpect([$result['ok'], $result['reason'], $result['data']], [false, $reason, null], $label . ' fails closed');
        $d = $result['diagnostic'];
        providerExpect(array_keys($d), ['stage', 'http_status', 'transport_category', 'curl_errno', 'elapsed_ms',
            'response_status', 'incomplete_reason', 'input_tokens', 'output_tokens', 'reasoning_tokens',
            'validation_category', 'validation_field'], $label . ' fixed fields');
        providerExpect(in_array($d['stage'], ['CONFIGURATION', 'INPUT', 'REQUEST', 'TRANSPORT', 'RESPONSE', 'EXTRACTION'], true), true, $label . ' stage enum');
        providerExpect(in_array($d['transport_category'], ['NONE', 'TIMEOUT', 'DNS', 'CONNECT', 'TLS', 'RESPONSE_LIMIT', 'OTHER', 'UNAVAILABLE', 'UNKNOWN'], true), true, $label . ' transport enum');
        providerExpect($d['response_status'] === null || in_array($d['response_status'],
            ['completed', 'incomplete', 'failed', 'cancelled', 'queued', 'in_progress', 'UNKNOWN'], true), true, $label . ' response enum');
        providerExpect($d['incomplete_reason'] === null || in_array($d['incomplete_reason'],
            ['max_output_tokens', 'content_filter', 'UNKNOWN'], true), true, $label . ' incomplete enum');
        providerExpect($d['validation_category'] === null || in_array($d['validation_category'],
            Extraction::VALIDATION_CATEGORIES, true), true, $label . ' validation category enum');
        providerExpect($d['validation_field'] === null || in_array($d['validation_field'],
            Extraction::VALIDATION_FIELDS, true), true, $label . ' validation field enum');
        foreach (['http_status' => 599, 'curl_errno' => 999, 'elapsed_ms' => 3600000,
            'input_tokens' => 1000000000, 'output_tokens' => 1000000000, 'reasoning_tokens' => 1000000000] as $field => $maximum) {
            providerExpect($d[$field] === null || (is_int($d[$field]) && $d[$field] >= 0 && $d[$field] <= $maximum), true, $label . ' bounded ' . $field);
        }
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['sk-provider-fixture-private-never-print', 'PRIVATE_PROVIDER_BODY', 'PRIVATE_REQUEST_ID',
            'PRIVATE_CUSTOMER_TEXT', 'SECRET_REASON', 'SECRET_STATUS'] as $private) {
            providerExpect(strpos($encoded, $private), false, $label . ' no private content');
        }
    }

    try {
        $rows = providerRows(); $data = providerData();
        providerExpect(Extraction::validate($data, $rows)['ok'], true, 'fixture is truly grounded');
        $GLOBALS['waProviderHttp'] = ['calls' => 0, 'closed' => 0, 'fixture' => providerHttp(providerResponse($data))];
        $native = new Provider(null, providerConfiguration());
        providerExpect($native->extract($rows), Extraction::result(true, null, $data), 'reasoning and one JSON message succeed');
        providerExpect($GLOBALS['waProviderHttp']['calls'], 1, 'one private fake HTTP attempt');
        providerExpect($GLOBALS['waProviderHttp']['closed'], 1, 'transport always closes');
        providerExpect($GLOBALS['waProviderHttp']['url'], 'https://api.openai.com/v1/responses', 'fixed HTTPS endpoint');
        $options = $GLOBALS['waProviderHttp']['options'];
        providerExpect($options[CURLOPT_TIMEOUT], 45, 'transport permits forty-five seconds while preserving ten-request worker headroom');
        providerExpect($options[CURLOPT_CONNECTTIMEOUT], 5, 'connection timeout remains bounded');
        providerExpect($options[CURLOPT_FOLLOWLOCATION], false, 'no redirects');
        providerExpect($options[CURLOPT_SSL_VERIFYPEER], true, 'certificate validation retained');
        providerExpect($options[CURLOPT_SSL_VERIFYHOST], 2, 'hostname validation retained');
        providerExpect($options[CURLOPT_VERBOSE], false, 'transport never emits authorization header');
        $request = json_decode($options[CURLOPT_POSTFIELDS], true, 64, JSON_THROW_ON_ERROR);
        providerExpect($request['model'], 'gpt-6-luna', 'approved model retained');
        providerExpect($request['reasoning'], ['effort' => 'low'], 'explicit low effort for approved model');
        providerExpect($request['max_output_tokens'], 8192, 'bounded output allowance includes reasoning');
        providerExpect($request['store'], false, 'storage disabled');
        providerExpect($request['stream'], false, 'partial response never applied');
        providerExpect(isset($request['tools']), false, 'no model tools');
        providerExpect($request['text']['format']['strict'], true, 'strict schema retained');
        $sourcePlan=SourceSelection::build($rows);
        providerExpect($sourcePlan['ok'],true,'source selection plan valid for exact qualified transcript');
        providerExpect($request['text']['format']['schema'], $sourcePlan['data']['schema'], 'strict source selection schema matches exact compiled transcript choices');
        providerExpect(strpos($request['instructions'], 'separate customer rows') !== false, true, 'prompt requires separated customer details to be cited');
        providerExpect(strpos($request['instructions'], 'every cited request_id must precede') !== false, true, 'prompt matches strict request chronology');
        providerExpect(json_decode($request['input'][0]['content'], true)['transcript'], $rows, 'transcript intact');
        foreach (['fixture-model', 'gpt-6-luna-custom', 'gpt-4o-mini'] as $otherModel) {
            $other = new Provider(static function ($body) use ($data, $otherModel) {
                $request = json_decode($body, true);
                providerExpect($request['model'], $otherModel, 'no silent model substitution');
                providerExpect(isset($request['reasoning']), false, 'other model does not inherit unsupported effort');
                return providerHttp(providerResponse($data));
            }, providerConfiguration($otherModel));
            providerExpect($other->extract($rows)['ok'], true, 'other model response supported');
        }

        $incomplete = ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [providerMessage($data)], 'usage' => ['input_tokens' => 1400, 'output_tokens' => 8192,
                'output_tokens_details' => ['reasoning_tokens' => 8192]], 'id' => 'PRIVATE_REQUEST_ID'];
        $failed = (new Provider(static fn () => providerHttp($incomplete), providerConfiguration()))->extract($rows);
        providerPrivateFailure($failed, 'INCOMPLETE', 'complete-looking partial JSON');
        providerExpect($failed['diagnostic']['response_status'], 'incomplete', 'incomplete status recorded');
        providerExpect($failed['diagnostic']['incomplete_reason'], 'max_output_tokens', 'budget reason recorded');
        providerExpect($failed['diagnostic']['reasoning_tokens'], 8192, 'reasoning usage distinguishable');

        $GLOBALS['waProviderHttp']['fixture'] = ['status' => 0, 'body' => 'PRIVATE_PROVIDER_BODY', 'errno' => 28, 'exec' => false];
        $timeout = $native->extract($rows);
        providerPrivateFailure($timeout, 'HTTP_ERROR', 'transport timeout');
        providerExpect($timeout['diagnostic']['transport_category'], 'TIMEOUT', 'curl timeout category retained');
        providerExpect($timeout['diagnostic']['curl_errno'], 28, 'numeric timeout code retained');
        providerExpect($timeout['diagnostic']['http_status'], 0, 'no HTTP response distinguishes timeout');

        foreach ([401, 429, 500, 503] as $status) {
            $http = (new Provider(static fn () => ['status' => $status,
                'body' => '{"error":{"message":"PRIVATE_PROVIDER_BODY sk-provider-fixture-private-never-print","code":"PRIVATE_REQUEST_ID"}}'], providerConfiguration()))->extract($rows);
            providerPrivateFailure($http, 'HTTP_ERROR', 'HTTP ' . $status);
            providerExpect($http['diagnostic']['http_status'], $status, 'numeric provider status retained');
        }
        foreach ([6 => 'DNS', 7 => 'CONNECT', 60 => 'TLS', 99 => 'OTHER'] as $errno => $category) {
            $error = (new Provider(static fn () => ['status' => 0, 'body' => '', 'curl_errno' => $errno], providerConfiguration()))->extract($rows);
            providerPrivateFailure($error, 'HTTP_ERROR', $category);
            providerExpect($error['diagnostic']['transport_category'], $category, 'fixed category ' . $category);
        }
        $GLOBALS['waProviderHttp']['fixture'] = ['status' => 200, 'errno' => 23, 'body' => str_repeat('x', 1048577)];
        $limited = $native->extract($rows);
        providerPrivateFailure($limited, 'HTTP_ERROR', 'native transport response limit');
        providerExpect($limited['diagnostic']['transport_category'], 'RESPONSE_LIMIT', 'response overflow distinguished');

        $refused = ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'refusal', 'refusal' => 'PRIVATE_PROVIDER_BODY']]]]];
        $tool = ['status' => 'completed', 'output' => [['type' => 'function_call', 'arguments' => 'PRIVATE_PROVIDER_BODY']]];
        $duplicate = providerResponse($data); $duplicate['output'][] = providerMessage($data);
        $duplicateContent = providerResponse($data); $duplicateContent['output'][1]['content'][] = $duplicateContent['output'][1]['content'][0];
        $failedStatus = ['status' => 'failed', 'error' => ['message' => 'PRIVATE_PROVIDER_BODY'], 'output' => []];
        $secretMetadata = ['status' => 'SECRET_STATUS', 'incomplete_details' => ['reason' => 'SECRET_REASON'],
            'usage' => ['input_tokens' => 'PRIVATE_CUSTOMER_TEXT', 'output_tokens' => -1,
                'output_tokens_details' => ['reasoning_tokens' => 1000000001]], 'output' => []];
        foreach ([[$refused, 'REFUSED'], [$tool, 'INVALID_RESPONSE'], [$duplicate, 'INVALID_RESPONSE'],
            [$duplicateContent, 'INVALID_RESPONSE'], [$failedStatus, 'INVALID_RESPONSE'], [$secretMetadata, 'INCOMPLETE']] as [$response, $reason]) {
            ob_start();
            $result = (new Provider(static fn () => providerHttp($response), providerConfiguration()))->extract($rows);
            $printed = ob_get_clean();
            providerPrivateFailure($result, $reason, $reason . ' parsing');
            providerExpect($printed, '', 'failure produces no raw response output');
        }
        $bad = providerData(); $bad['customer']['name'] = 'PRIVATE_CUSTOMER_TEXT';
        $invalid = (new Provider(static fn () => providerHttp(providerResponse($bad)), providerConfiguration()))->extract($rows);
        providerPrivateFailure($invalid, 'INVALID_EVIDENCE', 'ungrounded name');
        providerExpect($invalid['diagnostic']['stage'], 'EXTRACTION', 'semantic failure remains distinguishable');
        providerExpect($invalid['diagnostic']['validation_category'], 'LITERAL_NOT_CITED', 'semantic predicate captured');
        providerExpect($invalid['diagnostic']['validation_field'], 'CUSTOMER_NAME', 'semantic field captured without rejected value');
        $throwing = (new Provider(static function () { throw new \RuntimeException('PRIVATE_PROVIDER_BODY sk-provider-fixture-private-never-print'); }, providerConfiguration()))->extract($rows);
        providerPrivateFailure($throwing, 'TRANSPORT_ERROR', 'private exception');

        // A green synthetic greeting is not a guarantee that the next full transcript can finish.
        $calls = 0;
        $preflight = new Provider(static function () use (&$calls, $incomplete) {
            return ++$calls === 1 ? providerHttp(providerResponse(Extraction::none())) : providerHttp($incomplete);
        }, providerConfiguration());
        $greeting = [['id' => 'm1', 'speaker' => 'customer', 'sent_at' => '2026-10-12 20:00:01', 'text' => 'Hello, good morning.', 'location' => null]];
        providerExpect($preflight->extract($greeting)['data']['decision'], 'NONE', 'synthetic preflight can pass');
        providerPrivateFailure($preflight->extract($rows), 'INCOMPLETE', 'real transcript after successful preflight');
        providerExpect($calls, 2, 'each extraction spends at most one attempt');
        echo 'WHATSAPP_ORDER_PROVIDER_FAILURE_TESTS=' . $checks . " PASS\n";
    } catch (\Throwable $error) {
        echo "WHATSAPP_ORDER_PROVIDER_FAILURE_TESTS_FAILED\n";
        exit(1);
    }
}
