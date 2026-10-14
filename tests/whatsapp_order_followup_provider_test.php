<?php

// Private fake transport: no network, customer facts, WhatsApp sends or ERP writes.
namespace App\Services\Dashboard {
    function function_exists(string $name): bool
    {
        if ($name === 'curl_init') return $GLOBALS['waFollowupHttp']['available'] ?? true;
        return $name === 'config' || \function_exists($name);
    }
    function config(string $name, $default = null) { return $GLOBALS['waFollowupConfiguration'] ?? $default; }
    // Test-only fallback for the pure PHP CI job; Arabic has no uppercase letters.
    function mb_strtolower(string $text, string $encoding = 'UTF-8'): string
    {
        return \function_exists('mb_strtolower') ? \mb_strtolower($text, $encoding) : strtolower($text);
    }
    function curl_init(string $url)
    {
        $GLOBALS['waFollowupHttp']['calls']++;
        $GLOBALS['waFollowupHttp']['url'] = $url;
        return ($GLOBALS['waFollowupHttp']['init'] ?? true) ? new \stdClass() : false;
    }
    function curl_setopt_array($handle, array $options): bool
    {
        $GLOBALS['waFollowupHttp']['options'] = $options;
        return true;
    }
    function curl_exec($handle)
    {
        $fixture = $GLOBALS['waFollowupHttp']['fixture'];
        if (($fixture['exec'] ?? true) === false) return false;
        $callback = $GLOBALS['waFollowupHttp']['options'][CURLOPT_WRITEFUNCTION];
        return $callback($handle, $fixture['body']) === strlen($fixture['body']);
    }
    function curl_getinfo($handle, int $option): int { return $GLOBALS['waFollowupHttp']['fixture']['status']; }
    function curl_errno($handle): int { return $GLOBALS['waFollowupHttp']['fixture']['errno'] ?? 0; }
    function curl_close($handle): void { $GLOBALS['waFollowupHttp']['closed']++; }
}

namespace {
    foreach (['CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER', 'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_MAXREDIRS', 'CURLOPT_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_SSL_VERIFYPEER',
        'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_VERBOSE',
        'CURLOPT_HEADER', 'CURLOPT_WRITEFUNCTION', 'CURLINFO_HTTP_CODE', 'CURLPROTO_HTTPS'] as $i => $name)
        if (!defined($name)) define($name, 10000 + $i);
    require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
    require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
    require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderAiProvider.php';

    use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
    use App\Support\WhatsAppOrderExtraction as Extraction;
    $checks = 0;
    $configuration = ['enabled' => true, 'model' => 'gpt-6-luna', 'api_key' => 'sk-followup-fixture-private-never-print'];
    $fields = ['NAME', 'PHONE', 'ADDRESS', 'LOCATION', 'ITEMS', 'QUANTITY',
        'QUANTITY_MODE', 'FULFILLMENT', 'BRANCH', 'CONFIRMATION'];
    function followupExpect($actual, $expected, string $label): void
    {
        $GLOBALS['checks']++;
        if ($actual !== $expected) throw new \RuntimeException($label);
    }
    function followupEnvelope($text = '{"variant":"SHORT"}'): array
    {
        return ['status' => 'completed', 'output' => [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [
                ['type' => 'output_text', 'text' => $text]]]]];
    }
    function followupHttp(array $response): array
    {
        return ['status' => 200, 'body' => json_encode($response, JSON_THROW_ON_ERROR)];
    }
    function followupFailure(array $actual, string $reason, string $label): void
    {
        followupExpect($actual, ['ok' => false, 'variant' => null, 'reason' => $reason], $label);
        foreach (['sk-followup-fixture-private-never-print', 'PRIVATE_RESPONSE', 'PRIVATE_EXCEPTION',
            'CUSTOMER_FACT', 'CUSTOMER_PHONE', 'CUSTOMER_ADDRESS'] as $private)
            followupExpect(strpos(json_encode($actual), $private), false, $label . ' private data omitted');
    }
    function followupModeRows(string $choice = 'شكرا'): array
    {
        $rows = [];
        foreach ([['customer', 'عايز 0.5 كيلو فسيخ'], ['customer', 'اسمي عميل مثال ورقمي 201000000001'],
            ['customer', 'عنواني شارع المثال في مدينة المثال'], ['customer', $choice],
            ['business', 'تأكيد طلب عميل مثال: 0.5 كيلو فسيخ إلى شارع المثال في مدينة المثال.'],
            ['customer', 'تمام موافق']] as $i => [$speaker, $text])
            $rows[] = ['id' => 'm' . ($i + 1), 'speaker' => $speaker,
                'sent_at' => '2026-10-10 10:00:0' . ($i + 1), 'text' => $text, 'location' => null];
        return $rows;
    }
    function followupModeData(string $decision = 'DRAFT'): array
    {
        return ['decision' => $decision, 'customer' => ['name' => 'عميل مثال', 'phone' => '201000000001',
            'address' => 'شارع المثال', 'area' => 'مدينة المثال', 'notes' => null], 'branch_hint' => null,
            'fulfillment' => 'UNKNOWN', 'items' => [['name' => 'فسيخ', 'quantity' => '0.5', 'quantity_mode' => 'weight',
                'option_hint' => null, 'cart_reference' => null]], 'approximate_total' => null,
            'evidence' => ['request_ids' => ['m1', 'm2', 'm3'], 'confirmation_ids' => ['m5'],
                'customer_acceptance_ids' => ['m6'], 'location_id' => null],
            'issues' => $decision === 'DRAFT' ? ['UNCONFIRMED'] : ['MISSING_BRANCH']];
    }
    function followupModeExpect(array $data, array $rows, string $mode, string $label): void
    {
        $before = $data; $beforeRows = $rows;
        followupExpect(Provider::followupFulfillment($data, $rows), $mode, $label);
        followupExpect($data, $before, $label . ' extraction unchanged');
        followupExpect($rows, $beforeRows, $label . ' conversation unchanged');
    }

    try {
        $GLOBALS['waFollowupHttp'] = ['calls' => 0, 'closed' => 0, 'fixture' => followupHttp(followupEnvelope())];
        $native = new Provider(null, $configuration);
        followupExpect($native->followupVariant(['LOCATION']), ['ok' => true, 'variant' => 'SHORT', 'reason' => null],
            'strict short variant accepted');
        followupExpect($GLOBALS['waFollowupHttp']['calls'], 1, 'one HTTP call');
        followupExpect($GLOBALS['waFollowupHttp']['closed'], 1, 'handle closed');
        followupExpect($GLOBALS['waFollowupHttp']['url'], 'https://api.openai.com/v1/responses', 'fixed endpoint');
        $options = $GLOBALS['waFollowupHttp']['options'];
        foreach ([CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 45, CURLOPT_VERBOSE => false, CURLOPT_HEADER => false]
            as $option => $expected)
            followupExpect($options[$option], $expected, 'secure bounded transport option ' . $option);
        $request = json_decode($options[CURLOPT_POSTFIELDS], true, 16, JSON_THROW_ON_ERROR);
        followupExpect($request['store'], false, 'response storage disabled');
        followupExpect($request['stream'], false, 'stream disabled');
        followupExpect($request['max_output_tokens'], 512, 'small finite token budget');
        followupExpect($request['reasoning'], ['effort' => 'low'], 'approved model bounded reasoning');
        followupExpect(json_decode($request['input'][0]['content'], true), ['missing_fields' => ['LOCATION']],
            'only missing-field labels sent');
        followupExpect(count($request['input']), 1, 'one enum input');
        followupExpect(isset($request['tools']), false, 'no tools');
        followupExpect(strpos($options[CURLOPT_POSTFIELDS], $configuration['api_key']), false, 'key absent from body');
        $format = $request['text']['format'];
        followupExpect($format['strict'], true, 'strict schema');
        followupExpect($format['schema'], ['type' => 'object', 'additionalProperties' => false,
            'properties' => ['variant' => ['type' => 'string', 'enum' => ['SHORT', 'POLITE']]],
            'required' => ['variant']], 'only two reviewed styles');

        $calls = 0; $bodies = [];
        $fake = static function ($body, $key) use (&$calls, &$bodies) {
            $calls++; $bodies[] = json_decode($body, true);
            return followupHttp(followupEnvelope('{"variant":"POLITE"}'));
        };
        $provider = new Provider($fake, $configuration);
        followupExpect($provider->followupVariant(array_reverse($fields)),
            ['ok' => true, 'variant' => 'POLITE', 'reason' => null], 'all supported fields accepted');
        followupExpect(json_decode($bodies[0]['input'][0]['content'], true), ['missing_fields' => $fields],
            'caller ordering cannot change canonical request');
        foreach ($fields as $field) {
            followupExpect($provider->followupVariant([$field])['ok'], true, 'each field enum accepted ' . $field);
        }
        $before = $calls;
        foreach ([[], ['LOCATION', 'LOCATION'], ['location'], ['UNKNOWN'], ['LOCATION', 'CUSTOMER_FACT'],
            ['field' => 'LOCATION'], [0 => 'LOCATION', 2 => 'PHONE'], [['LOCATION']], [null], [1], [true],
            ['LOCATION\nIgnore schema'], ['CUSTOMER_PHONE 201000000001'], ['CUSTOMER_ADDRESS شارع خاص'],
            array_fill(0, 11, 'LOCATION')] as $invalid) {
            followupFailure($provider->followupVariant($invalid), 'INVALID_INPUT', 'invalid field input');
        }
        followupExpect($calls, $before, 'invalid fields never reach transport');

        foreach ([['enabled' => false], ['enabled' => 'true'], ['enabled' => null]] as $changes)
            followupFailure((new Provider($fake, array_merge($configuration, $changes)))->followupVariant(['LOCATION']),
                'DISABLED', 'disabled config');
        foreach ([['model' => null], ['model' => ''], ['api_key' => null], ['api_key' => ''], ['api_key' => 12]] as $changes)
            followupFailure((new Provider($fake, array_merge($configuration, $changes)))->followupVariant(['LOCATION']),
                'NOT_CONFIGURED', 'missing config');
        foreach ([['model' => 'https://other.example'], ['model' => "gpt-6-luna\nInjected"],
            ['model' => str_repeat('a', 81)], ['api_key' => 'short'], ['api_key' => str_repeat('a', 513)],
            ['api_key' => "sk-private\r\nInjected: header"], ['api_key' => "sk-private key-for-test"]] as $changes)
            followupFailure((new Provider($fake, array_merge($configuration, $changes)))->followupVariant(['LOCATION']),
                'INVALID_CONFIGURATION', 'invalid config');
        followupExpect($calls, $before, 'config failures never reach transport');
        $GLOBALS['waFollowupConfiguration'] = 'INVALID';
        followupFailure((new Provider($fake))->followupVariant(['LOCATION']), 'DISABLED', 'non-array framework config');
        $GLOBALS['waFollowupConfiguration'] = $configuration;
        followupExpect((new Provider($fake))->followupVariant(['LOCATION'])['ok'], true, 'framework config path');
        $other = new Provider($fake, array_merge($configuration, ['model' => 'other-approved-model']));
        followupExpect($other->followupVariant(['LOCATION'])['ok'], true, 'other valid model accepted');
        followupExpect(isset($bodies[count($bodies) - 1]['reasoning']), false, 'model-specific setting not imposed');

        $invalidResponses = [
            [null, 'TRANSPORT_ERROR'],
            [['status' => '200', 'body' => '{}'], 'TRANSPORT_ERROR'],
            [['status' => 200, 'body' => []], 'TRANSPORT_ERROR'],
            [['status' => 0, 'body' => 'PRIVATE_RESPONSE'], 'TRANSPORT_ERROR'],
            [['status' => 200, 'body' => '{}', 'curl_errno' => 28], 'TRANSPORT_ERROR'],
            [['status' => 200, 'body' => '{}', 'curl_errno' => '0'], 'TRANSPORT_ERROR'],
            [['status' => 200, 'body' => '{}', 'transport_unavailable' => true], 'TRANSPORT_ERROR'],
            [['status' => 429, 'body' => 'PRIVATE_RESPONSE'], 'HTTP_ERROR'],
            [['status' => 500, 'body' => 'PRIVATE_RESPONSE'], 'HTTP_ERROR'],
            [['status' => 200, 'body' => str_repeat('x', 16385)], 'RESPONSE_TOO_LARGE'],
            [['status' => 0, 'body' => '', 'response_limit' => true], 'RESPONSE_TOO_LARGE'],
            [['status' => 200, 'body' => '{'], 'INVALID_RESPONSE'],
            [followupHttp(['status' => 'completed', 'error' => ['message' => 'PRIVATE_RESPONSE']]), 'INVALID_RESPONSE'],
            [followupHttp(['status' => 'PRIVATE_RESPONSE', 'output' => []]), 'INCOMPLETE'],
            [followupHttp(['status' => 'incomplete', 'output' => []]), 'INCOMPLETE'],
            [followupHttp(['status' => 'completed', 'output' => ['x' => []]]), 'INVALID_RESPONSE'],
            [followupHttp(followupEnvelope('PRIVATE_RESPONSE')), 'INVALID_RESPONSE'],
            [followupHttp(followupEnvelope('{"variant":"CUSTOMER_FACT"}')), 'INVALID_RESPONSE'],
            [followupHttp(followupEnvelope('{"variant":"SHORT","reply":"PRIVATE_RESPONSE"}')), 'INVALID_RESPONSE'],
            [followupHttp(followupEnvelope('{"variant":"SHORT","variant":"POLITE"}')), 'INVALID_RESPONSE'],
            [followupHttp(followupEnvelope('{"variant":null}')), 'INVALID_RESPONSE'],
            [followupHttp(followupEnvelope('["SHORT"]')), 'INVALID_RESPONSE'],
            [followupHttp(followupEnvelope('{"variant":["SHORT"]}')), 'INVALID_RESPONSE'],
            [followupHttp(followupEnvelope(str_repeat('x', 129))), 'INVALID_RESPONSE'],
        ];
        foreach (['function_call', 'tool_call', 'web_search_call'] as $type)
            $invalidResponses[] = [followupHttp(['status' => 'completed', 'output' => [['type' => $type]]]), 'INVALID_RESPONSE'];
        foreach (['role' => 'user', 'status' => 'incomplete', 'content' => ['x' => []]] as $key => $value) {
            $response = followupEnvelope(); $response['output'][1][$key] = $value;
            $invalidResponses[] = [followupHttp($response), 'INVALID_RESPONSE'];
        }
        foreach ([null, ['type' => 'refusal', 'refusal' => 'PRIVATE_RESPONSE'], ['type' => 'output_text', 'text' => 1],
            ['type' => 'other', 'text' => 'PRIVATE_RESPONSE']] as $content) {
            $response = followupEnvelope(); $response['output'][1]['content'] = [$content];
            $invalidResponses[] = [followupHttp($response), ($content['type'] ?? null) === 'refusal' ? 'REFUSED' : 'INVALID_RESPONSE'];
        }
        $duplicate = followupEnvelope(); $duplicate['output'][] = $duplicate['output'][1];
        $invalidResponses[] = [followupHttp($duplicate), 'INVALID_RESPONSE'];
        foreach ($invalidResponses as [$response, $reason])
            followupFailure((new Provider(static fn () => $response, $configuration))->followupVariant(['LOCATION']),
                $reason, 'invalid provider output fails closed');
        followupFailure((new Provider(static function () { throw new \RuntimeException('PRIVATE_EXCEPTION'); }, $configuration))
            ->followupVariant(['LOCATION']), 'TRANSPORT_ERROR', 'exception content hidden');

        $GLOBALS['waFollowupHttp']['fixture'] = ['exec' => false, 'status' => 0, 'body' => '', 'errno' => 28];
        followupFailure($native->followupVariant(['LOCATION']), 'TRANSPORT_ERROR', 'native cURL timeout');
        $GLOBALS['waFollowupHttp']['available'] = false;
        followupFailure($native->followupVariant(['LOCATION']), 'TRANSPORT_ERROR', 'unavailable cURL');
        $GLOBALS['waFollowupHttp']['available'] = true; $GLOBALS['waFollowupHttp']['init'] = false;
        followupFailure($native->followupVariant(['LOCATION']), 'TRANSPORT_ERROR', 'failed cURL initialization');
        $GLOBALS['waFollowupHttp']['init'] = true;
        $GLOBALS['waFollowupHttp']['fixture'] = ['status' => 200, 'body' => str_repeat('x', 1048577)];
        followupFailure($native->followupVariant(['LOCATION']), 'RESPONSE_TOO_LARGE', 'inherited hard transport limit');

        $modeRows = followupModeRows(); $modeData = followupModeData();
        $modeHttpCalls = $GLOBALS['waFollowupHttp']['calls'];
        foreach (['DRAFT', 'CONFIRMED'] as $decision) {
            $modeData = followupModeData($decision);
            followupExpect(Extraction::validateDetailed($modeData, $modeRows)['ok'], true, 'mode fixture validated ' . $decision);
            followupModeExpect($modeData, $modeRows, 'DELIVERY', 'literal cited contacts imply delivery ' . $decision);
            foreach (['الطلب تيك أواي', 'الأوردر هايكون في الصالة', 'هستلمه من الفرع', 'مش دليفري، تيك أواي',
                'I want the order for takeaway', 'عايز تيك أواي بدون شطة'] as $choice)
                followupModeExpect($modeData, followupModeRows($choice), 'PICKUP', 'explicit branch priority ' . $decision . $choice);
            foreach (['الطلب دليفري، الطلب تيك أواي', 'مش عايز توصيل', 'دليفري أو تيك أواي', 'لا توصل الطلب'] as $choice)
                followupModeExpect($modeData, followupModeRows($choice), 'UNKNOWN', 'uncertain mode ' . $decision . $choice);
            foreach (['ينفع تيك أواي؟', 'عنواني جنب الصالة', 'لو الطلب تيك أواي', '"الطلب تيك أواي"', 'مش تيك أواي'] as $mention)
                followupModeExpect($modeData, followupModeRows($mention), 'DELIVERY', 'nonselection does not override contacts ' . $decision . $mention);
            foreach (['phone', 'address'] as $field) {
                $missing = $modeData; $missing['customer'][$field] = null;
                followupModeExpect($missing, $modeRows, 'UNKNOWN', 'missing contact ' . $decision . $field);
                followupModeExpect($missing, followupModeRows('الطلب دليفري'), 'DELIVERY', 'explicit delivery can guide remaining questions ' . $decision . $field);
            }
            $uncited = $modeData; $uncited['evidence']['request_ids'] = ['m1'];
            followupModeExpect($uncited, $modeRows, 'UNKNOWN', 'uncited contacts cannot imply delivery ' . $decision);
            $wrongPhone = $modeData; $wrongPhone['customer']['phone'] = '201000000009';
            followupModeExpect($wrongPhone, $modeRows, 'UNKNOWN', 'different contact number never inferred ' . $decision);
            $businessOnly = $modeRows; $businessOnly[1]['text'] = 'اسمي عميل مثال'; $businessOnly[2]['text'] = 'مدينة المثال';
            $businessOnly[4]['text'] .= ' رقم التواصل 201000000001';
            followupModeExpect($modeData, $businessOnly, 'UNKNOWN', 'business contact facts do not substitute for customer ' . $decision);
            $arabic = $modeRows; $arabic[1]['text'] = 'اسمي عميل مثال ورقمي ٠١٠٠٠٠٠٠٠٠١';
            followupModeExpect($modeData, $arabic, 'DELIVERY', 'Arabic customer phone proof ' . $decision);
            $formatted = $modeRows; $formatted[1]['text'] = 'اسمي عميل مثال ورقمي +20 100 000 0001';
            followupModeExpect($modeData, $formatted, 'DELIVERY', 'formatted customer phone proof ' . $decision);
            $spurious = $modeData; $spurious['fulfillment'] = 'PICKUP';
            followupModeExpect($spurious, $modeRows, 'DELIVERY', 'grounded contacts override unsupported pickup hint ' . $decision);
            foreach (['PICKUP', 'DELIVERY'] as $hint) {
                $unsupported = $modeData; $unsupported['fulfillment'] = $hint; $unsupported['customer']['address'] = null;
                followupModeExpect($unsupported, $modeRows, 'UNKNOWN', 'model hint alone cannot prove mode ' . $decision . $hint);
                followupModeExpect($unsupported, followupModeRows('مش تيك أواي'), 'UNKNOWN', 'negated branch does not prove hinted mode ' . $decision . $hint);
            }
            $cartRows = $modeRows; $cartRows[3]['text'] = null;
            $cartRows[3]['cart'] = \App\Support\WhatsAppInboxProtocol::cart(['catalog_id' => '1234567',
                'text' => 'عايزه تيك أواي', 'product_items' => [['product_retailer_id' => 'SKU_FIXTURE', 'quantity' => '1',
                    'item_price' => '100.00', 'currency' => 'EGP']]]);
            followupModeExpect($modeData, $cartRows, 'PICKUP', 'authentic cart mode choice ' . $decision);
        }
        $confirmed = followupModeData('CONFIRMED');
        $late = $modeRows; $late[] = ['id' => 'm7', 'speaker' => 'customer', 'sent_at' => '2026-10-10 10:00:07',
            'text' => 'الطلب تيك أواي', 'location' => null];
        followupModeExpect($confirmed, $late, 'UNKNOWN', 'late confirmed mode choice requires new review');
        followupModeExpect(followupModeData(), $late, 'PICKUP', 'draft is still collecting actual mode choice');
        foreach (['NONE', 'CANCELLED', 'OTHER'] as $decision) {
            $unsafe = followupModeData(); $unsafe['decision'] = $decision;
            followupModeExpect($unsafe, $modeRows, 'UNKNOWN', 'nonactionable decision ' . $decision);
        }
        foreach (['TEST_MESSAGE', 'PROMPT_INJECTION', 'CONFLICTING_DETAILS'] as $issue) {
            $unsafe = followupModeData(); $unsafe['issues'][] = $issue;
            followupModeExpect($unsafe, $modeRows, 'UNKNOWN', 'unsafe planning issue ' . $issue);
        }
        followupModeExpect([], $modeRows, 'UNKNOWN', 'missing extraction cannot plan mode');
        followupModeExpect(followupModeData(), [['speaker' => 'customer']], 'UNKNOWN', 'invalid transcript cannot plan mode');
        followupModeExpect(followupModeData(), followupModeRows('WA-1234'), 'UNKNOWN', 'test probe remains nonactionable');
        $shifted = $modeRows; $shiftedData = followupModeData();
        foreach ($shifted as &$row) {
            $n = (int) substr($row['id'], 1) + 3; $row['id'] = 'm' . $n;
            $row['sent_at'] = '2026-10-10 10:00:0' . $n;
        } unset($row);
        foreach (['request_ids', 'confirmation_ids', 'customer_acceptance_ids'] as $key)
            $shiftedData['evidence'][$key] = array_map(static fn ($id) => 'm' . ((int) substr($id, 1) + 3), $shiftedData['evidence'][$key]);
        $prefix = [['id' => 'm1', 'speaker' => 'customer', 'sent_at' => '2026-10-10 10:00:01', 'text' => 'الطلب تيك أواي', 'location' => null],
            ['id' => 'm2', 'speaker' => 'customer', 'sent_at' => '2026-10-10 10:00:02', 'text' => 'هذا الطلب اختبار لا ترسله', 'location' => null],
            ['id' => 'm3', 'speaker' => 'customer', 'sent_at' => '2026-10-10 10:00:03', 'text' => 'عايز طلب جديد: 0.5 كيلو فسيخ', 'location' => null]];
        followupModeExpect($shiftedData, array_merge($prefix, $shifted), 'DELIVERY', 'old episode branch choice cannot poison current contacts');
        followupExpect($GLOBALS['waFollowupHttp']['calls'], $modeHttpCalls, 'pure mode planning makes no HTTP calls');
        echo 'WhatsApp followup provider checks passed: ' . $checks . PHP_EOL;
    } catch (\Throwable $error) {
        fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL); exit(1);
    }
}
