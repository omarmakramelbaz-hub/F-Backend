<?php

/** Deployment-only observation of the same single provider response; no application writes. */
final class WhatsAppOrderOfferVariantObserver
{
    public const STATUSES = ['PROOF_FAILED', 'NOT_APPLICABLE', 'OBSERVER_STOPPED'];
    public const CHECKS = [
        'ITEM_DECLARATION' => ['item_array', 'item_name_valid', 'declared_amount_valid'],
        'CUSTOMER_SELECTION' => ['anchor_literal', 'anchor_safe', 'offer_safe', 'positive_selection',
            'bare_selection', 'direct_offer_answer', 'offer_names_item', 'quantity_question', 'alternatives', 'amount_matches'],
        'OFFER_VARIANT' => ['selected_variant_present', 'selected_variant_offered'],
        'SUMMARY_AMOUNT' => ['clause_present', 'clause_unique', 'clause_safe', 'alternatives', 'amount_matches'],
        'SUMMARY_VARIANT' => ['summary_variant_unique', 'summary_variant_matches'],
        'SUMMARY_OPTION' => ['option_valid', 'option_in_summary'],
        'CUSTOMER_OPTION' => ['option_after_quantity', 'option_before_summary', 'option_in_customer', 'amount_matches'],
    ];
    public const SUBREASONS = ['ITEM_DECLARATION', 'CUSTOMER_SELECTION', 'OFFER_VARIANT', 'SUMMARY_AMOUNT',
        'SUMMARY_VARIANT', 'SUMMARY_OPTION', 'CUSTOMER_OPTION', 'NONE', 'NO_PROVIDER_CALL',
        'RESPONSE_NOT_INSPECTABLE', 'UNKNOWN_METADATA', 'PLAN_INVALID', 'SOURCE_DECODE_FAILED',
        'OBSERVER_EXCEPTION', 'PROOF_MISMATCH', 'SOURCE_PIN_INVALID', 'CLONE_INVALID', 'PROVIDER_MISMATCH'];
    private const SOURCE_PINS = [
        'App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence' => ['app/Services/Dashboard/WhatsAppOrderConversationEvidence.php', '1bd223861e3772e10658df2d555b4bb040243a4a'],
        'App\\Services\\Dashboard\\WhatsAppOrderSourceSelection' => ['app/Services/Dashboard/WhatsAppOrderSourceSelection.php', '03db3211b80da905f07415fd16f1e7a62e0395b1'],
        'App\\Services\\Dashboard\\WhatsAppOrderAiProvider' => ['app/Services/Dashboard/WhatsAppOrderAiProvider.php', '39f644ff97c7cf6182666a5aff3f6fb231cb0733'],
    ];
    private const PROOF = 'App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence';
    private const CLONE = 'App\\Services\\Dashboard\\WhatsAppOrderOfferVariantTrace';
    private const MAX_RESPONSE_BYTES = 1048576;
    private const MAX_OUTPUT_BYTES = 32000;
    private static bool $cloneLoaded = false;

    /** Response bytes, request body/key and the authoritative Provider result remain unchanged. */
    public static function extract(array $rows, array $configuration, ?callable $transport = null): array
    {
        $metadata = self::metadata('NOT_APPLICABLE', 'NO_PROVIDER_CALL');
        $calls = 0; $provider = null;
        $observe = static function (string $body, string $key) use (&$metadata, &$calls, &$provider, $rows, $transport) {
            if ($calls !== 0) throw new \RuntimeException();
            $calls++;
            $metadata = self::metadata('NOT_APPLICABLE', 'RESPONSE_NOT_INSPECTABLE');
            if ($transport !== null) $response = $transport($body, $key);
            else {
                $method = new \ReflectionMethod(\App\Services\Dashboard\WhatsAppOrderAiProvider::class, 'request');
                $method->setAccessible(true);
                $response = $method->invoke($provider, $body, $key);
            }
            try {
                $wire = self::responseWire($response);
                if ($wire !== null) {
                    $episode = \App\Services\Dashboard\WhatsAppOrderConversationEvidence::episodeTranscript($rows);
                    $built = \App\Services\Dashboard\WhatsAppOrderSourceSelection::build($episode);
                    if (($built['ok'] ?? null) !== true || !is_array($built['data'] ?? null)) {
                        $metadata = self::metadata('OBSERVER_STOPPED', 'PLAN_INVALID');
                    } else {
                        $decoded = \App\Services\Dashboard\WhatsAppOrderSourceSelection::decode($wire, $built['data']);
                        $metadata = ($decoded['ok'] ?? null) === true && is_array($decoded['data'] ?? null)
                            ? self::inspect($decoded['data'], $episode)
                            : self::metadata('NOT_APPLICABLE', 'SOURCE_DECODE_FAILED');
                        unset($decoded);
                    }
                    unset($episode, $built, $wire);
                }
            } catch (\JsonException $ignore) {
                $metadata = self::metadata('NOT_APPLICABLE', 'RESPONSE_NOT_INSPECTABLE');
            } catch (\Throwable $ignore) {
                $metadata = self::metadata('OBSERVER_STOPPED', 'OBSERVER_EXCEPTION');
            }
            return $response;
        };
        $provider = new \App\Services\Dashboard\WhatsAppOrderAiProvider($observe, $configuration);
        $result = $provider->extract($rows);
        if (($metadata['status'] ?? null) === 'PROOF_FAILED'
            && (($result['ok'] ?? null) !== false || ($result['reason'] ?? null) !== 'INVALID_EVIDENCE'
                || ($result['diagnostic']['validation_category'] ?? null) !== 'CONVERSATION_ITEM_UNGROUNDED'
                || ($result['diagnostic']['validation_field'] ?? null) !== 'ITEM')) {
            $metadata = self::metadata('OBSERVER_STOPPED', 'PROVIDER_MISMATCH');
        }
        return ['result' => $result, 'metadata' => self::sanitize($metadata)];
    }

    /** Inspect only a normalized candidate rejected by the unchanged authoritative validator. */
    public static function inspect(array $data, array $rows): array
    {
        try {
            $checked = \App\Support\WhatsAppOrderExtraction::validateDetailed($data, $rows);
            if (($checked['ok'] ?? null) !== false || ($checked['reason'] ?? null) !== 'INVALID_EVIDENCE'
                || ($checked['validation_category'] ?? null) !== 'CONVERSATION_ITEM_UNGROUNDED'
                || ($checked['validation_field'] ?? null) !== 'ITEM') return self::metadata('NOT_APPLICABLE', 'NONE');
            $source = null;
            foreach (self::SOURCE_PINS as $class => [$relative, $pin]) {
                $bytes = self::verifiedSource($class, $relative, $pin);
                if ($bytes === null) return self::metadata('OBSERVER_STOPPED', 'SOURCE_PIN_INVALID');
                if ($class === self::PROOF) $source = $bytes;
            }
            if (!is_string($source) || !self::loadClone($source)) return self::metadata('OBSERVER_STOPPED', 'CLONE_INVALID');
            $original = \App\Services\Dashboard\WhatsAppOrderConversationEvidence::prove($data, $rows);
            $clone = self::CLONE;
            $clone::resetObserverTrace();
            $observed = $clone::prove($data, $rows);
            $trace = $clone::observerTrace();
            if ($original !== $observed || $original !== ['ok' => false, 'reason' => 'CONVERSATION_ITEM_UNGROUNDED']
                || !is_array($trace) || !self::keys($trace, ['gate', 'ordinal'])
                || !isset(self::CHECKS[$trace['gate'] ?? '']) || !is_int($trace['ordinal'])
                || $trace['ordinal'] < 1 || $trace['ordinal'] > 30) return self::metadata('OBSERVER_STOPPED', 'PROOF_MISMATCH');
            $checks = self::predicateChecks($trace['gate'], $trace['ordinal'], $data, $rows);
            unset($source, $original, $observed, $trace);
            return self::sanitize(self::metadata('PROOF_FAILED', $clone::observerTrace()['gate'], 'ITEM',
                $clone::observerTrace()['ordinal'], $checks));
        } catch (\Throwable $ignore) { return self::metadata('OBSERVER_STOPPED', 'OBSERVER_EXCEPTION'); }
    }

    /** Reject all unknown, value-bearing or ill-shaped diagnostic metadata. */
    public static function sanitize($metadata): array
    {
        $fallback = self::metadata('NOT_APPLICABLE', 'UNKNOWN_METADATA');
        if (!is_array($metadata) || !self::keys($metadata, ['status', 'subreason', 'field', 'item_ordinal', 'checks'])
            || !in_array($metadata['status'], self::STATUSES, true)
            || !in_array($metadata['subreason'], self::SUBREASONS, true)) return $fallback;
        if ($metadata['status'] === 'PROOF_FAILED') {
            $keys = self::CHECKS[$metadata['subreason']] ?? null;
            if ($keys === null || $metadata['field'] !== 'ITEM' || !is_int($metadata['item_ordinal'])
                || $metadata['item_ordinal'] < 1 || $metadata['item_ordinal'] > 30
                || !is_array($metadata['checks']) || !self::keys($metadata['checks'], $keys)) return $fallback;
            foreach ($metadata['checks'] as $flag) if (!is_bool($flag)) return $fallback;
        } else {
            $allowed = ['NOT_APPLICABLE' => ['NONE', 'NO_PROVIDER_CALL', 'RESPONSE_NOT_INSPECTABLE', 'UNKNOWN_METADATA', 'SOURCE_DECODE_FAILED'],
                'OBSERVER_STOPPED' => ['PLAN_INVALID', 'OBSERVER_EXCEPTION', 'PROOF_MISMATCH', 'SOURCE_PIN_INVALID', 'CLONE_INVALID', 'PROVIDER_MISMATCH']];
            if (!in_array($metadata['subreason'], $allowed[$metadata['status']] ?? [], true)
                || $metadata['field'] !== null || $metadata['item_ordinal'] !== null || $metadata['checks'] !== []) return $fallback;
        }
        return self::metadata($metadata['status'], $metadata['subreason'], $metadata['field'], $metadata['item_ordinal'], $metadata['checks']);
    }

    /** Read one physically bound loaded source; no model-supplied code is ever evaluated. */
    private static function verifiedSource(string $class, string $relative, string $pin): ?string
    {
        $path = getcwd() . '/' . $relative;
        $reflection = new \ReflectionClass($class);
        $before = @lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0022)
            || $before['size'] < 1 || $before['size'] > 1048576 || $before['nlink'] < 1
            || !function_exists('posix_geteuid') || $before['uid'] !== posix_geteuid()
            || realpath($path) !== $path || $reflection->getFileName() !== $path) return null;
        $stream = @fopen($path, 'rb');
        if ($stream === false) return null;
        try {
            if (self::fileSignature(fstat($stream)) !== self::fileSignature($before)) return null;
            $bytes = stream_get_contents($stream, 1048577);
            if (!is_string($bytes) || strlen($bytes) !== $before['size']
                || self::fileSignature(fstat($stream)) !== self::fileSignature($before)
                || self::fileSignature(@lstat($path)) !== self::fileSignature($before)
                || sha1('blob ' . strlen($bytes) . "\0" . $bytes) !== $pin) return null;
            return $bytes;
        } finally { fclose($stream); }
    }

    private static function fileSignature($stat): ?array
    {
        if (!is_array($stat)) return null;
        $out = [];
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
            if (!is_int($stat[$key] ?? null)) return null;
            $out[] = $stat[$key];
        }
        return $out;
    }

    /** Trace exact pinned failure returns; every original predicate and return value is retained. */
    private static function loadClone(string $source): bool
    {
        if (self::$cloneLoaded) return class_exists(self::CLONE, false);
        if (class_exists(self::CLONE, false)) return false;
        $declaration = "final class WhatsAppOrderConversationEvidence\n{";
        $needle = "return self::failed('CONVERSATION_ITEM_UNGROUNDED');";
        if (!str_starts_with($source, "<?php\n") || substr_count($source, $declaration) !== 1
            || substr_count($source, $needle) !== 7) return false;
        $member = <<<'TRACE'

    private static ?array $observerTrace = null;
    public static function resetObserverTrace(): void { self::$observerTrace = null; }
    public static function observerTrace(): ?array { return self::$observerTrace; }
    private static function traceFailure(string $gate, int $ordinal): array
    {
        self::$observerTrace = ['gate' => $gate, 'ordinal' => $ordinal];
        return self::failed('CONVERSATION_ITEM_UNGROUNDED');
    }
TRACE;
        $parts = explode($needle, $source); $generated = array_shift($parts);
        foreach (array_keys(self::CHECKS) as $gate) {
            $generated .= "return self::traceFailure('" . $gate . "', \$index + 1);" . array_shift($parts);
        }
        if ($parts !== []) return false;
        $generated = str_replace($declaration, "final class WhatsAppOrderOfferVariantTrace\n{" . $member, $generated);
        eval(substr($generated, strlen("<?php\n")));
        if (!class_exists(self::CLONE, false)) return false;
        self::$cloneLoaded = true;
        return true;
    }

    private static function invoke(string $method, ...$arguments)
    {
        $reflection = new \ReflectionMethod(self::PROOF, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$arguments);
    }

    /** Fixed boolean observations of the failing gate; no literal, amount, option or ID leaves. */
    private static function predicateChecks(string $gate, int $ordinal, array $data, array $input): array
    {
        $item = $data['items'][$ordinal - 1];
        $amount = is_array($item) ? self::invoke('declaredAmount', $item) : null;
        if ($gate === 'ITEM_DECLARATION') return ['item_array' => is_array($item),
            'item_name_valid' => is_array($item) && self::invoke('shortText', $item['name'] ?? null, 200),
            'declared_amount_valid' => $amount !== null];
        $ledger = self::invoke('ledger', \App\Services\Dashboard\WhatsAppOrderConversationEvidence::episodeRows($input));
        if (!is_array($ledger) || $amount === null) throw new \RuntimeException();
        [$rows, $positions] = $ledger; $ref = $item['conversation_reference'];
        $anchor = $positions[$ref['name_anchor_id']]; $offer = $positions[$ref['business_offer_id']];
        $quantity = $positions[$ref['customer_quantity_id']]; $summary = $positions[$ref['business_summary_id']];
        $anchorText = $rows[$ref['name_anchor_id']]['text']; $offerText = $rows[$ref['business_offer_id']]['text'];
        $quantityText = $rows[$ref['customer_quantity_id']]['text'];
        if ($gate === 'CUSTOMER_SELECTION') return [
            'anchor_literal' => self::invoke('literal', $item['name'], $anchorText),
            'anchor_safe' => !self::invoke('unsafe', $anchorText), 'offer_safe' => !self::invoke('unsafe', $offerText),
            'positive_selection' => self::invoke('selection', $quantityText),
            'bare_selection' => self::invoke('bareSelection', $quantityText, $item['name']),
            'direct_offer_answer' => $quantity === $offer + 1,
            'offer_names_item' => self::invoke('literal', $item['name'], $offerText),
            'quantity_question' => self::invoke('quantityQuestion', $offerText),
            'alternatives' => self::invoke('alternatives', $quantityText),
            'amount_matches' => self::invoke('oneAmount', $quantityText, $amount),
        ];
        $selectedVariants = self::invoke('variants', $quantityText); $offeredVariants = self::invoke('variants', $offerText);
        $variant = $selectedVariants[0] ?? null;
        if ($variant === null && count($offeredVariants) === 1) $variant = $offeredVariants[0];
        if ($gate === 'OFFER_VARIANT') return ['selected_variant_present' => $variant !== null,
            'selected_variant_offered' => $variant !== null && in_array($variant, $offeredVariants, true)];
        $clauses = \App\Services\Dashboard\WhatsAppOrderConversationEvidence::clauses($rows[$ref['business_summary_id']]['text']);
        $clause = $clauses[$ref['summary_clause_index']] ?? null;
        if ($gate === 'SUMMARY_AMOUNT') {
            $unique = true;
            foreach (array_slice($data['items'], 0, $ordinal - 1) as $prior) {
                $other = $prior['conversation_reference'] ?? null;
                if (is_array($other) && $other['business_summary_id'] === $ref['business_summary_id']
                    && $other['summary_clause_index'] === $ref['summary_clause_index']) $unique = false;
            }
            return ['clause_present' => $clause !== null, 'clause_unique' => $unique,
                'clause_safe' => $clause !== null && !self::invoke('unsafe', $clause),
                'alternatives' => $clause !== null && self::invoke('alternatives', $clause),
                'amount_matches' => $clause !== null && self::invoke('oneAmount', $clause, $amount)];
        }
        if (!is_string($clause)) throw new \RuntimeException();
        if ($gate === 'SUMMARY_VARIANT') {
            $summaryVariants = self::invoke('variants', $clause);
            return ['summary_variant_unique' => count($summaryVariants) <= 1,
                'summary_variant_matches' => ($summaryVariants[0] ?? null) === $variant];
        }
        $hint = $item['option_hint'] ?? null;
        if ($gate === 'SUMMARY_OPTION') return ['option_valid' => self::invoke('shortText', $hint, 200),
            'option_in_summary' => is_string($hint) && self::invoke('positiveOption', $hint, $clause)];
        if ($gate !== 'CUSTOMER_OPTION' || !is_string($hint)) throw new \RuntimeException();
        $optionId = $ref['customer_option_id'] ?? $ref['customer_quantity_id'];
        $optionPosition = $positions[$optionId]; $optionText = $rows[$optionId]['text'];
        return ['option_after_quantity' => $optionPosition >= $quantity, 'option_before_summary' => $optionPosition < $summary,
            'option_in_customer' => self::invoke('positiveOption', $hint, $optionText),
            'amount_matches' => self::invoke('oneAmount', $optionText, $amount)];
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

    private static function keys(array $value, array $keys): bool
    {
        $actual = array_keys($value); sort($actual); sort($keys); return $actual === $keys;
    }
    private static function metadata(string $status, string $reason, ?string $field = null, ?int $ordinal = null, array $checks = []): array
    {
        return ['status' => $status, 'subreason' => $reason, 'field' => $field, 'item_ordinal' => $ordinal, 'checks' => $checks];
    }
}
