<?php

/** Deployment-only, read-only observation of the provider's unchanged single response. */
final class WhatsAppOrderSourceReferenceObserver
{
    public const STATUSES = ['SOURCE_REFERENCE_READY', 'SOURCE_REFERENCE_FAILED', 'NOT_OBSERVED', 'OBSERVER_STOPPED'];
    public const SUBREASONS = [
        'NONE', 'NO_PROVIDER_CALL', 'RESPONSE_NOT_INSPECTABLE', 'UNKNOWN_METADATA',
        'PLAN_INVALID', 'OBSERVER_EXCEPTION', 'DECODER_MISMATCH', 'ITEM_LIMIT_EXCEEDED',
        'ITEM_SHAPE', 'SOURCE_SHAPE', 'SOURCE_ID_TYPE', 'SOURCE_KIND_INVALID',
        'TOKEN_TYPE', 'TOKEN_NEGATIVE', 'TOKEN_ORDER_INVALID', 'SOURCE_ROW_MISSING',
        'TOKEN_INDEX_MISSING', 'SOURCE_ROW_INVALID', 'SPAN_EMPTY', 'SPAN_UTF8_INVALID', 'SPAN_TOO_LONG',
        'OPTION_UNCITED', 'CONTEXT_CART_CONFLICT', 'CONTEXT_SHAPE',
        'CONTEXT_OFFER_REFERENCE_INVALID', 'CONTEXT_QUANTITY_REFERENCE_INVALID',
        'CONTEXT_NAME_SPEAKER', 'CONTEXT_NAME_FIELD', 'CONTEXT_REQUEST_LIST_INVALID', 'CONTEXT_NAME_UNCITED',
        'CONTEXT_OPTION_SPEAKER', 'CONTEXT_OPTION_FIELD', 'CONTEXT_OPTION_UNCITED', 'CONTEXT_CLAUSE_INVALID',
    ];
    private const MAX_ITEMS = 30;
    private const MAX_RESPONSE_BYTES = 1048576;
    private const MAX_OUTPUT_BYTES = 32000;
    private const FAILURE_FIELDS = [
        'ITEM_SHAPE' => ['ITEM', 'ITEM_OPTION'],
        'SOURCE_SHAPE' => ['ITEM_NAME', 'ITEM_OPTION'], 'SOURCE_ID_TYPE' => ['ITEM_NAME', 'ITEM_OPTION'],
        'SOURCE_KIND_INVALID' => ['ITEM_NAME', 'ITEM_OPTION'], 'TOKEN_TYPE' => ['ITEM_NAME', 'ITEM_OPTION'],
        'TOKEN_NEGATIVE' => ['ITEM_NAME', 'ITEM_OPTION'], 'TOKEN_ORDER_INVALID' => ['ITEM_NAME', 'ITEM_OPTION'],
        'SOURCE_ROW_MISSING' => ['ITEM_NAME', 'ITEM_OPTION'], 'TOKEN_INDEX_MISSING' => ['ITEM_NAME', 'ITEM_OPTION'],
        'SOURCE_ROW_INVALID' => ['ITEM_NAME', 'ITEM_OPTION'], 'SPAN_EMPTY' => ['ITEM_NAME', 'ITEM_OPTION'],
        'SPAN_UTF8_INVALID' => ['ITEM_NAME', 'ITEM_OPTION'], 'SPAN_TOO_LONG' => ['ITEM_NAME', 'ITEM_OPTION'],
        'OPTION_UNCITED' => ['ITEM_OPTION'], 'CONTEXT_CART_CONFLICT' => ['ITEM'], 'CONTEXT_SHAPE' => ['ITEM'],
        'CONTEXT_OFFER_REFERENCE_INVALID' => ['ITEM'], 'CONTEXT_QUANTITY_REFERENCE_INVALID' => ['ITEM'],
        'CONTEXT_NAME_SPEAKER' => ['ITEM_NAME'], 'CONTEXT_NAME_FIELD' => ['ITEM_NAME'],
        'CONTEXT_REQUEST_LIST_INVALID' => ['ITEM_NAME'], 'CONTEXT_NAME_UNCITED' => ['ITEM_NAME'],
        'CONTEXT_OPTION_SPEAKER' => ['ITEM_OPTION'], 'CONTEXT_OPTION_FIELD' => ['ITEM_OPTION'],
        'CONTEXT_OPTION_UNCITED' => ['ITEM_OPTION'], 'CONTEXT_CLAUSE_INVALID' => ['ITEM'],
    ];

    /** No response or request is changed; metadata contains no conversation or provider values. */
    public static function extract(array $rows, array $configuration, ?callable $transport = null): array
    {
        $metadata = self::metadata('NOT_OBSERVED', 'NO_PROVIDER_CALL');
        $calls = 0;
        $provider = null;
        $observe = static function (string $body, string $key) use (&$metadata, &$calls, &$provider, $rows, $transport) {
            if ($calls !== 0) throw new \RuntimeException();
            $calls++;
            $metadata = self::metadata('NOT_OBSERVED', 'RESPONSE_NOT_INSPECTABLE');
            if ($transport !== null) $response = $transport($body, $key);
            else {
                $method = new \ReflectionMethod(\App\Services\Dashboard\WhatsAppOrderAiProvider::class, 'request');
                $method->setAccessible(true);
                $response = $method->invoke($provider, $body, $key);
            }
            try {
                $wire = self::responseWire($response);
                if ($wire === null) $metadata = self::metadata('NOT_OBSERVED', 'RESPONSE_NOT_INSPECTABLE');
                else {
                    $episode = \App\Services\Dashboard\WhatsAppOrderConversationEvidence::episodeTranscript($rows);
                    $built = \App\Services\Dashboard\WhatsAppOrderSourceSelection::build($episode);
                    $metadata = ($built['ok'] ?? null) === true && is_array($built['data'] ?? null)
                        ? self::inspect($wire, $built['data']) : self::metadata('OBSERVER_STOPPED', 'PLAN_INVALID');
                    unset($episode, $built, $wire);
                }
            } catch (\JsonException $ignore) {
                $metadata = self::metadata('NOT_OBSERVED', 'RESPONSE_NOT_INSPECTABLE');
            } catch (\Throwable $ignore) {
                $metadata = self::metadata('OBSERVER_STOPPED', 'OBSERVER_EXCEPTION');
            }
            // Passing the original object preserves status, body, curl errors and timing.
            return $response;
        };
        $provider = new \App\Services\Dashboard\WhatsAppOrderAiProvider($observe, $configuration);
        $result = $provider->extract($rows);
        return ['result' => $result, 'metadata' => self::sanitize($metadata)];
    }

    /** Reproduce only source-reference decisions and verify them against the pinned decoder. */
    public static function inspect(array $wire, array $plan): array
    {
        try {
            $rows = $plan['input']['transcript'] ?? null;
            if (!is_array($rows) || count($rows) > 60) return self::metadata('OBSERVER_STOPPED', 'PLAN_INVALID');
            $rebuilt = \App\Services\Dashboard\WhatsAppOrderSourceSelection::build($rows);
            if (($rebuilt['ok'] ?? null) !== true || ($rebuilt['data'] ?? null) !== $plan)
                return self::metadata('OBSERVER_STOPPED', 'PLAN_INVALID');
            unset($rebuilt);
            if (is_array($wire['items'] ?? null) && count($wire['items']) > self::MAX_ITEMS)
                return self::metadata('OBSERVER_STOPPED', 'ITEM_LIMIT_EXCEEDED');
            $metadata = self::inspectSources($wire, $plan);
            $decoded = \App\Services\Dashboard\WhatsAppOrderSourceSelection::decode($wire, $plan);
            $failed = ($decoded['ok'] ?? null) === false
                && ($decoded['validation_category'] ?? null) === 'SOURCE_REFERENCE_INVALID';
            if (($metadata['status'] === 'SOURCE_REFERENCE_FAILED') !== $failed
                || ($failed && ($decoded['validation_field'] ?? null) !== $metadata['field']))
                return self::metadata('OBSERVER_STOPPED', 'DECODER_MISMATCH');
            unset($decoded);
            return self::sanitize($metadata);
        } catch (\Throwable $ignore) {
            return self::metadata('OBSERVER_STOPPED', 'OBSERVER_EXCEPTION');
        }
    }

    /** Independent fixed-value gate for consumers using injectable probe fixtures. */
    public static function sanitize($metadata): array
    {
        $fallback = self::metadata('NOT_OBSERVED', 'UNKNOWN_METADATA');
        if (!is_array($metadata) || !self::keys($metadata, ['status', 'subreason', 'field', 'item_ordinal'])
            || !in_array($metadata['status'], self::STATUSES, true)
            || !in_array($metadata['subreason'], self::SUBREASONS, true)) return $fallback;
        $status = $metadata['status']; $reason = $metadata['subreason'];
        if ($status === 'SOURCE_REFERENCE_FAILED') {
            if (!in_array($metadata['field'], self::FAILURE_FIELDS[$reason] ?? [], true)
                || !is_int($metadata['item_ordinal']) || $metadata['item_ordinal'] < 1
                || $metadata['item_ordinal'] > self::MAX_ITEMS) return $fallback;
        } else {
            if ($metadata['field'] !== null || $metadata['item_ordinal'] !== null) return $fallback;
            $allowed = ['SOURCE_REFERENCE_READY' => ['NONE'],
                'NOT_OBSERVED' => ['NO_PROVIDER_CALL', 'RESPONSE_NOT_INSPECTABLE', 'UNKNOWN_METADATA'],
                'OBSERVER_STOPPED' => ['PLAN_INVALID', 'OBSERVER_EXCEPTION', 'DECODER_MISMATCH', 'ITEM_LIMIT_EXCEEDED']];
            if (!in_array($reason, $allowed[$status] ?? [], true)) return $fallback;
        }
        return self::metadata($status, $reason, $metadata['field'], $metadata['item_ordinal']);
    }

    private static function inspectSources(array $wire, array $plan): array
    {
        if (!is_array($wire['items'] ?? null) || !array_is_list($wire['items']))
            return self::metadata('SOURCE_REFERENCE_READY', 'NONE');
        foreach ($wire['items'] as $index => $item) {
            if (!is_array($item) || !array_key_exists('name_source', $item)) continue;
            $ordinal = $index + 1;
            if (!self::keys($item, ['name_source','quantity','quantity_mode','option_source','cart_reference','conversation_reference']))
                return self::failure('ITEM_SHAPE', array_key_exists('option_hint', $item) ? 'ITEM_OPTION' : 'ITEM', $ordinal);
            $reason = self::sourceReason($item['name_source'], $plan);
            if ($reason !== null) return self::failure($reason, 'ITEM_NAME', $ordinal);
            $name = $item['name_source'];
            $row = $plan['sources'][$name['message_id'] . ':' . $name['source']];
            $option = $item['option_source'];
            if ($option !== null) {
                $reason = self::sourceReason($option, $plan);
                if ($reason !== null) return self::failure($reason, 'ITEM_OPTION', $ordinal);
                if (!self::cited($option['message_id'], $wire, ['request_ids','confirmation_ids']))
                    return self::failure('OPTION_UNCITED', 'ITEM_OPTION', $ordinal);
            }
            $reference = $item['conversation_reference'];
            if ($reference === null) continue;
            if ($item['cart_reference'] !== null) return self::failure('CONTEXT_CART_CONFLICT', 'ITEM', $ordinal);
            if (!is_array($reference) || !self::keys($reference, ['business_offer_id','customer_quantity_id','summary_clause_id']))
                return self::failure('CONTEXT_SHAPE', 'ITEM', $ordinal);
            foreach (['business_offer_id' => 'business','customer_quantity_id' => 'customer'] as $key => $speaker) {
                $id = $reference[$key];
                $target = is_string($id) ? ($plan['sources'][$id . ':text'] ?? null) : null;
                if (!is_array($target) || $target['speaker'] !== $speaker || $target['source'] !== 'text')
                    return self::failure($key === 'business_offer_id' ? 'CONTEXT_OFFER_REFERENCE_INVALID' : 'CONTEXT_QUANTITY_REFERENCE_INVALID', 'ITEM', $ordinal);
            }
            if ($row['speaker'] !== 'customer') return self::failure('CONTEXT_NAME_SPEAKER', 'ITEM_NAME', $ordinal);
            if ($row['source'] !== 'text') return self::failure('CONTEXT_NAME_FIELD', 'ITEM_NAME', $ordinal);
            if (!is_array($wire['evidence']['request_ids'] ?? null))
                return self::failure('CONTEXT_REQUEST_LIST_INVALID', 'ITEM_NAME', $ordinal);
            if (!in_array($name['message_id'], $wire['evidence']['request_ids'], true))
                return self::failure('CONTEXT_NAME_UNCITED', 'ITEM_NAME', $ordinal);
            if ($option !== null) {
                $optionRow = $plan['sources'][$option['message_id'] . ':' . $option['source']];
                if ($optionRow['speaker'] !== 'customer') return self::failure('CONTEXT_OPTION_SPEAKER', 'ITEM_OPTION', $ordinal);
                if ($optionRow['source'] !== 'text') return self::failure('CONTEXT_OPTION_FIELD', 'ITEM_OPTION', $ordinal);
                if (!self::cited($option['message_id'], $wire, ['request_ids']))
                    return self::failure('CONTEXT_OPTION_UNCITED', 'ITEM_OPTION', $ordinal);
            }
            $clause = is_string($reference['summary_clause_id']) ? ($plan['clauses'][$reference['summary_clause_id']] ?? null) : null;
            if (!is_array($clause)) return self::failure('CONTEXT_CLAUSE_INVALID', 'ITEM', $ordinal);
        }
        return self::metadata('SOURCE_REFERENCE_READY', 'NONE');
    }

    private static function sourceReason($source, array $plan): ?string
    {
        if (!is_array($source) || !self::keys($source, ['message_id','source','first_token','last_token'])) return 'SOURCE_SHAPE';
        if (!is_string($source['message_id'])) return 'SOURCE_ID_TYPE';
        if (!in_array($source['source'], ['text','cart_text'], true)) return 'SOURCE_KIND_INVALID';
        if (!is_int($source['first_token']) || !is_int($source['last_token'])) return 'TOKEN_TYPE';
        if ($source['first_token'] < 0) return 'TOKEN_NEGATIVE';
        if ($source['last_token'] < $source['first_token']) return 'TOKEN_ORDER_INVALID';
        $row = $plan['sources'][$source['message_id'] . ':' . $source['source']] ?? null;
        if (!is_array($row)) return 'SOURCE_ROW_MISSING';
        $first = $row['tokens'][$source['first_token']] ?? null;
        $last = $row['tokens'][$source['last_token']] ?? null;
        if (!is_array($first) || !is_array($last)) return 'TOKEN_INDEX_MISSING';
        if (!is_string($row['text'] ?? null) || ($row['message_id'] ?? null) !== $source['message_id']
            || ($row['source'] ?? null) !== $source['source'] || !in_array($row['speaker'] ?? null, ['customer','business'], true))
            return 'SOURCE_ROW_INVALID';
        $text = substr($row['text'], $first['start'], $last['end'] - $first['start']);
        if ($text === '') return 'SPAN_EMPTY';
        if (preg_match('//u', $text) !== 1) return 'SPAN_UTF8_INVALID';
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : preg_match_all('/./us', $text);
        return $length > 200 ? 'SPAN_TOO_LONG' : null;
    }

    private static function responseWire($response): ?array
    {
        if (!is_array($response) || !is_int($response['status'] ?? null) || $response['status'] !== 200
            || !is_string($response['body'] ?? null) || strlen($response['body']) > self::MAX_RESPONSE_BYTES) return null;
        $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || isset($decoded['error']) || ($decoded['status'] ?? null) !== 'completed'
            || !is_array($decoded['output'] ?? null) || !array_is_list($decoded['output'])) return null;
        $texts = [];
        foreach ($decoded['output'] as $output) {
            if (!is_array($output) || !is_string($output['type'] ?? null)) return null;
            if ($output['type'] === 'reasoning') continue;
            if ($output['type'] !== 'message' || ($output['role'] ?? null) !== 'assistant'
                || !is_array($output['content'] ?? null) || !array_is_list($output['content'])) return null;
            foreach ($output['content'] as $content) {
                if (!is_array($content) || ($content['type'] ?? null) !== 'output_text' || !is_string($content['text'] ?? null)) return null;
                $texts[] = $content['text'];
            }
        }
        if (count($texts) !== 1 || strlen($texts[0]) > self::MAX_OUTPUT_BYTES) return null;
        $wire = json_decode($texts[0], true, 32, JSON_THROW_ON_ERROR);
        return is_array($wire) ? $wire : null;
    }

    private static function cited(string $id, array $wire, array $fields): bool
    {
        foreach ($fields as $field) {
            $ids = $wire['evidence'][$field] ?? null;
            if (is_array($ids) && in_array($id, $ids, true)) return true;
        }
        return false;
    }
    private static function keys(array $value, array $keys): bool
    {
        $actual = array_keys($value); sort($actual); sort($keys); return $actual === $keys;
    }
    private static function failure(string $reason, string $field, int $ordinal): array
    {
        return self::metadata('SOURCE_REFERENCE_FAILED', $reason, $field, $ordinal);
    }
    private static function metadata(string $status, string $reason, ?string $field = null, ?int $ordinal = null): array
    {
        return ['status' => $status, 'subreason' => $reason, 'field' => $field, 'item_ordinal' => $ordinal];
    }
}
