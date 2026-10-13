<?php

/** One explicitly authorized extraction; all application observations remain read-only. */
final class WhatsAppOrderFragmentedItemGroundingReadOnlyProbe
{
    public const CONVERSATION = 11;
    public const MINIMUM_FLOOR = 4016;
    public const WABA = '468336579702269';
    public const PHONE = '515388018324075';
    public const SOURCE_PINS = [
        'App\\Services\\Dashboard\\WhatsAppOrderWorkflow' => ['app/Services/Dashboard/WhatsAppOrderWorkflow.php', 'b1bdc5962330c5f61a8ab6fa4c640d0fcae8799d'],
        'App\\Services\\Dashboard\\WhatsAppOrderAiProvider' => ['app/Services/Dashboard/WhatsAppOrderAiProvider.php', '479ed7e75b995ed0216ac76ccc49e471d96f6fa9'],
        'App\\Support\\WhatsAppOrderExtraction' => ['app/Support/WhatsAppOrderExtraction.php', 'ef73fbfd4beaa04aeccfda2095a98e2c4d5e6724'],
        'App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence' => ['app/Services/Dashboard/WhatsAppOrderConversationEvidence.php', '14521ca74822bb0359ccec81c5cb986feaa75da4'],
        'App\\Support\\WhatsAppInboxProtocol' => ['app/Support/WhatsAppInboxProtocol.php', 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd'],
        'App\\Services\\Dashboard\\WhatsAppAcceptedReplyEvidence' => ['app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php', '4b571f7f4a5cef9257c414886dba515c06dae2a1'],
        'App\\Services\\Dashboard\\WhatsAppInboxMessage' => ['app/Services/Dashboard/WhatsAppInboxMessage.php', 'bedd91db5266d83bdce84a04836e988518891728'],
        'App\\Services\\Dashboard\\WhatsAppReplyService' => ['app/Services/Dashboard/WhatsAppReplyService.php', '1ef23df825287f8d3dedafcdb5d70ffeac7f1f29'],
        'App\\Services\\Dashboard\\WhatsAppNativePlaceholderHydration' => ['app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php', '9e221ba052589c1ffe474958b636a77ff5817b7d'],
        'App\\Services\\Dashboard\\WhatsAppInboxConsumer' => ['app/Services/Dashboard/WhatsAppInboxConsumer.php', 'e0909057496cb9f710928fd8c273b5c9d0adc97f'],
        'App\\Services\\Dashboard\\WhatsAppInboxAccess' => ['app/Services/Dashboard/WhatsAppInboxAccess.php', '186789d42113e81818de8d82a2327e3ee9d8d0b5'],
        'App\\Services\\Dashboard\\PosServiceTicket' => ['app/Services/Dashboard/PosServiceTicket.php', 'ef4c7b6f4a1276c122634aafabb2d510926aaeef'],
    ];
    public const ITEM_FLAGS = [
        'literal_match_cited_request','literal_match_cited_confirmation','literal_match_acceptance_only',
        'literal_match_uncited_customer','literal_match_uncited_business','literal_match_any_transcript',
        'arabic_folded_any_transcript','arabic_folded_cited_request','arabic_folded_cited_confirmation',
        'arabic_folded_uncited_customer','arabic_folded_uncited_business','context_reference_present',
        'context_anchor_exists','context_anchor_customer','context_anchor_request_cited',
        'literal_match_context_anchor','arabic_folded_context_anchor','all_name_tokens_in_cited',
        'all_name_tokens_in_context_anchor','name_includes_known_family','name_includes_unit',
        'name_includes_whole','name_includes_size_count',
    ];

    public static function authorized(array $arguments): bool
    {
        return count($arguments) === 2 && ($arguments[1] ?? null) === '--probe-one';
    }

    private static function present($value): bool { return is_string($value) && trim($value) !== ''; }
    private static function kind($value): string
    {
        return $value === null ? 'NULL' : (is_array($value) ? 'ARRAY' : (is_string($value) ? 'STRING'
            : (is_int($value) ? 'INT' : (is_bool($value) ? 'BOOL' : (is_float($value) ? 'FLOAT' : 'OTHER')))));
    }

    private static function notApplicable(): array
    {
        return ['status'=>'NOT_APPLICABLE','items_count'=>0,'items'=>[]];
    }

    /** One unchanged Provider extraction; rejected model output exists only inside the observer. */
    public static function extractObserved(array $rows,array $configuration,?callable $transport=null): array
    {
        $input=\App\Support\WhatsAppOrderExtraction::validateTranscript($rows);
        if (!$input['ok'] || \App\Support\WhatsAppOrderExtraction::hasProbe($rows))
            throw new \InvalidArgumentException('PROBE_INPUT_NOT_QUALIFIED');
        // The current provider checks original rows, then analyzes this proved epoch.
        // Neither the observer nor the transport changes its input or response.
        $episode=\App\Services\Dashboard\WhatsAppOrderConversationEvidence::episodeTranscript($rows);
        if (!\App\Support\WhatsAppOrderExtraction::validateTranscript($episode)['ok'])
            throw new \InvalidArgumentException('PROBE_INPUT_NOT_QUALIFIED');
        $providerClass=\App\Services\Dashboard\WhatsAppOrderAiProvider::class;
        if ($transport===null) {
            $baseline=new $providerClass(null,$configuration);
            $request=new \ReflectionMethod($baseline,'request'); $request->setAccessible(true);
            $transport=static fn (string $body,string $key): array => $request->invoke($baseline,$body,$key);
        }
        $metadata=self::notApplicable();
        $observer=static function ($body,$key) use ($transport,$episode,&$metadata) {
            $response=$transport($body,$key);
            try { $metadata=self::requestRowsMatch($body,$episode) ? self::observe($response,$episode) : self::notApplicable(); }
            catch (\Throwable $ignored) { $metadata=self::notApplicable(); }
            // The exact response, request bytes and credential are never modified or retained.
            return $response;
        };
        $provider=new $providerClass($observer,$configuration);
        $result=$provider->extract($rows);
        return ['result'=>$result,'item_grounding'=>self::safeMetadata($metadata,$result)];
    }

    /** Compare only in memory; model input labels/text never enter the public diagnostic. */
    private static function requestRowsMatch($body,array $episode): bool
    {
        if (!is_string($body) || strlen($body)>131072) return false;
        $request=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($request) || !is_array($request['input']??null) || !array_is_list($request['input'])
            || count($request['input'])!==1 || ($request['input'][0]['role']??null)!=='user'
            || !is_string($request['input'][0]['content']??null)) return false;
        // Match the provider's exact content encoding. JSON decoding would turn
        // integral float GPS coordinates into ints and suppress valid metadata.
        $expected=json_encode(['transcript'=>$episode],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return hash_equals($expected,$request['input'][0]['content']);
    }

    private static function itemNameFailure(array $result): bool
    {
        return ($result['ok']??null)===false && ($result['reason']??null)==='INVALID_EVIDENCE'
            && ($result['diagnostic']['validation_category']??null)==='LITERAL_NOT_CITED'
            && ($result['diagnostic']['validation_field']??null)==='ITEM_NAME';
    }

    /** Bounded output parsing mirrors the pinned provider; only exact grounding rejection qualifies. */
    private static function observe($response,array $rows): array
    {
        if (!is_array($response) || ($response['status']??null)!==200 || !is_string($response['body']??null)
            || strlen($response['body'])>1048576) return self::notApplicable();
        $decoded=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || isset($decoded['error']) || ($decoded['status']??null)!=='completed'
            || !is_array($decoded['output']??null) || !array_is_list($decoded['output'])) return self::notApplicable();
        $texts=[];
        foreach ($decoded['output'] as $output) {
            if (!is_array($output) || !is_string($output['type']??null)) return self::notApplicable();
            if ($output['type']==='reasoning') continue;
            if ($output['type']!=='message' || ($output['role']??null)!=='assistant'
                || !is_array($output['content']??null) || !array_is_list($output['content'])) return self::notApplicable();
            foreach ($output['content'] as $content) {
                if (!is_array($content) || ($content['type']??null)!=='output_text' || !is_string($content['text']??null))
                    return self::notApplicable();
                $texts[]=$content['text'];
            }
        }
        if (count($texts)!==1 || strlen($texts[0])>32000) return self::notApplicable();
        $data=json_decode($texts[0],true,32,JSON_THROW_ON_ERROR); unset($decoded,$texts);
        if (!is_array($data)) return self::notApplicable();
        $checked=\App\Support\WhatsAppOrderExtraction::validateDetailed($data,$rows);
        if (($checked['ok']??null)!==false || ($checked['reason']??null)!=='INVALID_EVIDENCE'
            || ($checked['validation_category']??null)!=='LITERAL_NOT_CITED'
            || ($checked['validation_field']??null)!=='ITEM_NAME') return self::notApplicable();
        $maximum=\App\Support\WhatsAppOrderExtraction::MAX_ITEMS;
        if (!is_array($data['items']??null) || !array_is_list($data['items']) || count($data['items'])<1
            || count($data['items'])>$maximum) return self::notApplicable();
        $extractor=\App\Support\WhatsAppOrderExtraction::class;
        $backed=new \ReflectionMethod($extractor,'backed'); $backed->setAccessible(true);
        $rowText=new \ReflectionMethod($extractor,'rowText'); $rowText->setAccessible(true);
        $groups=['request'=>[],'confirmation'=>[],'acceptance'=>[],'uncited_customer'=>[],'uncited_business'=>[],'any'=>[]];
        $evidence=$data['evidence'];
        $qualified=array_merge($evidence['request_ids'],$evidence['confirmation_ids']);
        $allCited=array_merge($qualified,$evidence['customer_acceptance_ids']);
        foreach ($rows as $row) {
            $text=$rowText->invoke(null,$row); $id=$row['id']; $groups['any'][]=$text;
            if (in_array($id,$evidence['request_ids'],true)) $groups['request'][]=$text;
            if (in_array($id,$evidence['confirmation_ids'],true)) $groups['confirmation'][]=$text;
            if (in_array($id,$evidence['customer_acceptance_ids'],true) && !in_array($id,$qualified,true)) $groups['acceptance'][]=$text;
            if (!in_array($id,$allCited,true) && $row['speaker']==='customer') $groups['uncited_customer'][]=$text;
            if (!in_array($id,$evidence['confirmation_ids'],true) && $row['speaker']==='business') $groups['uncited_business'][]=$text;
        }
        $indexed=array_column($rows,null,'id');
        $items=[];
        foreach ($data['items'] as $index=>$item) {
            $name=$item['name']; $any=$backed->invoke(null,$name,$groups['any']);
            $foldedName=self::arabicFold($name);
            $context=$item['conversation_reference']??null;
            $anchorId=is_array($context) ? ($context['name_anchor_id']??null) : null;
            $anchor=is_string($anchorId) && preg_match('/\Am(?:[1-9]|[1-5][0-9]|60)\z/',$anchorId)===1
                && isset($indexed[$anchorId]) ? $indexed[$anchorId] : null;
            $anchorTexts=$anchor===null ? [] : [$rowText->invoke(null,$anchor)];
            $morphology=self::nameMorphology($name);
            $items[]=['index'=>$index+1,
                'literal_match_cited_request'=>$backed->invoke(null,$name,$groups['request']),
                'literal_match_cited_confirmation'=>$backed->invoke(null,$name,$groups['confirmation']),
                'literal_match_acceptance_only'=>$backed->invoke(null,$name,$groups['acceptance']),
                'literal_match_uncited_customer'=>$backed->invoke(null,$name,$groups['uncited_customer']),
                'literal_match_uncited_business'=>$backed->invoke(null,$name,$groups['uncited_business']),
                'literal_match_any_transcript'=>$any,
                // Diagnostic only: never used by extraction, citation, quote or authorization.
                'arabic_folded_any_transcript'=>!$any && $foldedName!==''
                    && $backed->invoke(null,$foldedName,array_map([self::class,'arabicFold'],$groups['any'])),
                'arabic_folded_cited_request'=>self::foldedOnly($name,$groups['request'],$backed),
                'arabic_folded_cited_confirmation'=>self::foldedOnly($name,$groups['confirmation'],$backed),
                'arabic_folded_uncited_customer'=>self::foldedOnly($name,$groups['uncited_customer'],$backed),
                'arabic_folded_uncited_business'=>self::foldedOnly($name,$groups['uncited_business'],$backed),
                'context_reference_present'=>is_array($context),
                'context_anchor_exists'=>$anchor!==null,
                'context_anchor_customer'=>$anchor!==null && $anchor['speaker']==='customer',
                'context_anchor_request_cited'=>$anchor!==null && in_array($anchorId,$evidence['request_ids'],true),
                'literal_match_context_anchor'=>$backed->invoke(null,$name,$anchorTexts),
                'arabic_folded_context_anchor'=>self::foldedOnly($name,$anchorTexts,$backed),
                'all_name_tokens_in_cited'=>self::tokensBacked($name,array_merge($groups['request'],$groups['confirmation']),$backed),
                'all_name_tokens_in_context_anchor'=>self::tokensBacked($name,$anchorTexts,$backed),
                'name_includes_known_family'=>$morphology['family'],
                'name_includes_unit'=>$morphology['unit'],
                'name_includes_whole'=>$morphology['whole'],
                'name_includes_size_count'=>$morphology['size'],
            ];
        }
        return ['status'=>'ITEM_NAME_GROUNDING','items_count'=>count($items),'items'=>$items];
    }

    /** Whole contiguous phrases only. Folding is diagnostic and never repairs an extraction. */
    private static function foldedOnly(string $name,array $texts,\ReflectionMethod $backed): bool
    {
        if ($backed->invoke(null,$name,$texts)) return false;
        $folded=self::arabicFold($name);
        return $folded!=='' && $backed->invoke(null,$folded,array_map([self::class,'arabicFold'],$texts));
    }

    /** Presence of every separately written token is weaker than a cited contiguous phrase. */
    private static function tokensBacked(string $name,array $texts,\ReflectionMethod $backed): bool
    {
        $tokens=preg_split('/[\s\p{P}\p{S}]+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || !$tokens || count($tokens)>200) return false;
        foreach ($tokens as $token) if (!$backed->invoke(null,$token,$texts)) return false;
        return true;
    }

    /** Fixed morphology indicators only; no name, token, digit or catalog value is exposed. */
    private static function nameMorphology(string $name): array
    {
        $digits=strtr($name,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        $prefix='(?<![\p{L}\p{N}])(?:ال)?'; $suffix='(?![\p{L}\p{N}])';
        return [
            'family'=>preg_match('/'.$prefix.'(?:فسيخ|رنجة|رنجه|سردين|بطارخ|ملوحة|أنشوجه|انشوجه|تونة|ماكريل|herring|sardines?)'.$suffix.'/iu',$name)===1,
            'unit'=>preg_match('/'.$prefix.'(?:كيلو(?:جرام|غرام)?|كجم|كغ|جرام|غرام|جم|قطعة|قطع|حبة|حبه|حبات|سمكة|سمكه|سمكات|علبة|علب|kilograms?|kilos?|kg|grams?|g|pieces?|pcs|units?|fish)'.$suffix.'/iu',$name)===1,
            'whole'=>preg_match('/'.$prefix.'(?:كامل|whole)'.$suffix.'/iu',$name)===1,
            'size'=>preg_match('/(?<![\p{L}\p{N}])[0-9]{1,2}\s*(?:حبات|حبة|حبه|سمكات|سمكة|سمكه|fish)(?![\p{L}\p{N}])/iu',$digits)===1,
        ];
    }

    private static function arabicFold(string $value): string
    {
        $value=str_replace(['أ','إ','آ','ى','ة'],['ا','ا','ا','ي','ه'],$value);
        $value=preg_replace('/[\x{0610}-\x{061a}\x{064b}-\x{065f}\x{0670}\x{06d6}-\x{06ed}\x{0640}]/u','',$value);
        return trim(preg_replace('/\s+/u',' ',$value));
    }

    /** An arbitrary callback cannot attach names, model prose or extra fields to the public probe. */
    private static function safeMetadata($metadata,array $result): array
    {
        if (!self::itemNameFailure($result) || !is_array($metadata)
            || count($metadata)!==3 || array_diff(array_keys($metadata),['status','items_count','items'])
            || ($metadata['status']??null)!=='ITEM_NAME_GROUNDING' || !is_int($metadata['items_count']??null)
            || $metadata['items_count']<1 || $metadata['items_count']>\App\Support\WhatsAppOrderExtraction::MAX_ITEMS
            || !is_array($metadata['items']??null) || !array_is_list($metadata['items'])
            || count($metadata['items'])!==$metadata['items_count']) return self::notApplicable();
        $flags=self::ITEM_FLAGS;
        foreach ($metadata['items'] as $index=>$item) {
            if (!is_array($item) || count($item)!==count($flags)+1 || array_diff(array_keys($item),array_merge(['index'],$flags))
                || ($item['index']??null)!==$index+1) return self::notApplicable();
            foreach ($flags as $flag) if (!is_bool($item[$flag]??null)) return self::notApplicable();
        }
        return $metadata;
    }

    /** Fixed envelope scope and types only. No arbitrary status, IDs, contacts or provider prose. */
    public static function statusMetadata(array $payload): array
    {
        $out = ['scope_matches' => false, 'statuses_count' => 0, 'truncated' => false, 'statuses' => []];
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') return $out;
        if (is_array($payload['entry'] ?? null) && count($payload['entry']) > 5) $out['truncated'] = true;
        foreach (array_slice(is_array($payload['entry'] ?? null) ? $payload['entry'] : [], 0, 5) as $entry) {
            if (!is_array($entry) || ($entry['id'] ?? null) !== self::WABA) continue;
            if (is_array($entry['changes'] ?? null) && count($entry['changes']) > 5) $out['truncated'] = true;
            foreach (array_slice(is_array($entry['changes'] ?? null) ? $entry['changes'] : [], 0, 5) as $change) {
                if (!is_array($change) || ($change['field'] ?? null) !== 'messages') continue;
                $value = $change['value'] ?? null;
                if (!is_array($value) || ($value['metadata']['phone_number_id'] ?? null) !== self::PHONE
                    || ($value['messaging_product'] ?? null) !== 'whatsapp') continue;
                $out['scope_matches'] = true;
                $statuses = $value['statuses'] ?? null;
                if (!is_array($statuses) || !array_is_list($statuses)) continue;
                $out['statuses_count'] += count($statuses);
                foreach (array_slice($statuses, 0, 5) as $status) {
                    if (count($out['statuses']) === 5) { $out['truncated'] = true; break; }
                    $raw = is_array($status) ? ($status['status'] ?? null) : null;
                    $id = is_array($status) ? ($status['id'] ?? null) : null;
                    $out['statuses'][] = ['status_type' => self::kind($raw), 'status' => in_array($raw,
                        ['sent','delivered','read','failed','deleted','warning','played','accepted',
                            'held_for_quality_assessment','pending','scheduled','sent_for_delivery','undeliverable','rejected'], true) ? $raw : 'OTHER',
                        'id_valid' => is_string($id) && $id !== '' && strlen($id) <= 512 && !preg_match('/[\x00-\x20\x7f]/', $id)];
                }
                if (count($statuses) > 5) $out['truncated'] = true;
            }
        }
        return $out;
    }

    private static function same(array $before, array $after): bool
    {
        foreach (['floor','ceiling','configuration_fingerprint'] as $field)
            if (!array_key_exists($field, $before) || ($before[$field] !== ($after[$field] ?? null))) return false;
        return ($after['snapshot']['complete'] ?? false) === true
            && is_string($before['snapshot']['hash'] ?? null) && is_string($after['snapshot']['hash'] ?? null)
            && hash_equals($before['snapshot']['hash'], $after['snapshot']['hash']);
    }

    /** Dependencies permit isolated fixtures; production supplies only guarded SELECT/snapshot callbacks. */
    public static function run(bool $authorized, callable $read, callable $extract, callable $safeFailure): array
    {
        $out = ['diagnostic' => 'WHATSAPP_ORDER_FRAGMENTED_ITEM_GROUNDING_READ_ONLY_PROBE', 'writes' => 0, 'ai_calls' => 0,
            'conversation_id' => self::CONVERSATION, 'snapshot_current' => null];
        if (!$authorized) return ['output' => $out + ['status' => 'STOPPED', 'reason' => 'EXPLICIT_PROBE_REQUIRED'], 'code' => 1];
        $stage = 'READ_STATE';
        try {
            $before = $read();
            $rows = $before['snapshot']['transcript'] ?? [];
            $counts = ['messages' => count($rows), 'customer' => 0, 'business' => 0, 'location' => 0, 'cart' => 0];
            foreach ($rows as $row) {
                if (($row['speaker'] ?? null) === 'customer') $counts['customer']++;
                if (($row['speaker'] ?? null) === 'business') $counts['business']++;
                if (($row['location'] ?? null) !== null) $counts['location']++;
                if (($row['cart'] ?? null) !== null) $counts['cart']++;
            }
            $out['input'] = $counts + ['complete' => ($before['snapshot']['complete'] ?? false) === true];
            $out['status_event_7215'] = $before['status_event_7215'] ?? ['status' => 'UNREADABLE'];
            $out['input']['valid'] = false;
            if (($before['configured'] ?? false) !== true) throw new \RuntimeException();
            if (($before['snapshot']['complete'] ?? false) !== true || !is_int($before['floor'] ?? null)
                || !is_int($before['ceiling'] ?? null) || $before['floor'] < self::MINIMUM_FLOOR
                || $before['ceiling'] <= $before['floor'] || !$counts['customer'] || !$counts['business'])
                return ['output' => $out + ['status' => 'STOPPED', 'reason' => 'CONTEXT_NOT_QUALIFIED'], 'code' => 1];
            $checked = \App\Support\WhatsAppOrderExtraction::validateTranscript($rows);
            if (!$checked['ok'] || \App\Support\WhatsAppOrderExtraction::hasProbe($rows))
                return ['output' => $out + ['status' => 'STOPPED', 'reason' => 'CONTEXT_NOT_QUALIFIED'], 'code' => 1];
            $out['input']['valid'] = true;
            $stage = 'PRECALL_RECHECK';
            if (!self::same($before, $read())) {
                $out['snapshot_current'] = false;
                return ['output' => $out + ['status' => 'STOPPED', 'reason' => 'STATE_CHANGED_BEFORE_PROBE'], 'code' => 1];
            }
            $stage = 'EXTRACT'; $out['ai_calls'] = 1;
            $observed = $extract($rows);
            $result = is_array($observed) && is_array($observed['result']??null) ? $observed['result'] : [];
            $stage = 'POSTCALL_RECHECK';
            $out['snapshot_current'] = self::same($before, $read());
            if (!$out['snapshot_current'])
                return ['output' => $out + ['status' => 'RESULT_DISCARDED', 'reason' => 'STATE_CHANGED_DURING_PROBE'], 'code' => 1];
            $out['item_grounding']=self::safeMetadata(is_array($observed)?($observed['item_grounding']??null):null,$result);
            $stage = 'RESULT';
            if (($result['ok'] ?? null) !== true || !is_array($result['data'] ?? null))
                return ['output' => $out + ['status' => 'AI_EXTRACTION_FAILED', 'analysis_failure' => $safeFailure($result)], 'code' => 1];
            // Reapply the application's current validator; even a fixture provider cannot bypass grounding.
            $verified = \App\Support\WhatsAppOrderExtraction::validateDetailed($result['data'], $rows);
            if (!$verified['ok']) {
                $failure = ['reason' => $verified['reason'], 'diagnostic' => ['stage' => 'EXTRACTION',
                    'validation_category' => $verified['validation_category'] ?? null,
                    'validation_field' => $verified['validation_field'] ?? null]];
                return ['output' => $out + ['status' => 'AI_EXTRACTION_FAILED', 'analysis_failure' => $safeFailure($failure)], 'code' => 1];
            }
            $data = $verified['data']; $evidence = $data['evidence']; $fields = [];
            foreach (['name','phone','address','area','notes'] as $field) $fields[$field] = self::present($data['customer'][$field]);
            $fields += ['branch_hint' => self::present($data['branch_hint']), 'approximate_total' => self::present($data['approximate_total']),
                'location_reference' => $evidence['location_id'] !== null];
            $out['extraction'] = ['decision' => $data['decision'], 'issues' => $data['issues'], 'items_count' => count($data['items']),
                'evidence_counts' => ['request' => count($evidence['request_ids']), 'confirmation' => count($evidence['confirmation_ids']),
                    'customer_acceptance' => count($evidence['customer_acceptance_ids'])], 'fields_present' => $fields, 'grounded' => true];
            return ['output' => $out + ['status' => 'AI_EXTRACTION_READY'], 'code' => 0];
        } catch (\Throwable $ignore) {
            if ($out['ai_calls'] === 1) $out['snapshot_current'] = false;
            return ['output' => $out + ['status' => 'STOPPED', 'stage' => $stage, 'reason' => 'GUARDED_PROBE_FAILED'], 'code' => 1];
        }
    }
}

if (defined('WHATSAPP_ORDER_FRAGMENTED_ITEM_GROUNDING_READ_ONLY_PROBE_TEST_ONLY') && WHATSAPP_ORDER_FRAGMENTED_ITEM_GROUNDING_READ_ONLY_PROBE_TEST_ONLY === true) return;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('display_startup_errors', '0'); ini_set('log_errors', '0'); ob_start();
$output = ['diagnostic' => 'WHATSAPP_ORDER_FRAGMENTED_ITEM_GROUNDING_READ_ONLY_PROBE', 'writes' => 0, 'ai_calls' => 0];
$stage = 'AUTHORIZATION';
try {
    if (!WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::authorized($argv)) throw new \RuntimeException();
    $stage = 'OWNER';
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) throw new \RuntimeException();
    $owner = posix_getpwnam('fasakha');
    if (!is_array($owner) || posix_geteuid() !== $owner['uid']) throw new \RuntimeException();
    $root = '/home/fasakha/public_html';
    $readFile = static function (string $path, bool $required = true) use ($root): ?string {
        $name = $root . '/' . $path; clearstatcache(true, $name); $info = @lstat($name);
        if (!$info && !$required) return null;
        if (!$info || ($info['mode'] & 0170000) !== 0100000 || $info['uid'] !== posix_geteuid()
            || ($info['mode'] & 0022) || realpath($name) !== $name || $info['size'] < 1 || $info['size'] > 8388608) throw new \RuntimeException();
        $bytes = file_get_contents($name);
        if (!is_string($bytes) || strlen($bytes) !== $info['size']) throw new \RuntimeException();
        return $bytes;
    };
    $guard = static function (bool $loaded = false) use ($root, $readFile): void {
        foreach (WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::SOURCE_PINS as $class => [$path, $hash]) {
            $bytes = $readFile($path);
            if (!preg_match('/\A[a-f0-9]{40}\z/', $hash) || !hash_equals($hash, sha1('blob ' . strlen($bytes) . "\0" . $bytes))) throw new \RuntimeException();
            if ($loaded && realpath((new \ReflectionClass($class))->getFileName()) !== $root . '/' . $path) throw new \RuntimeException();
        }
    };
    $stage = 'SOURCE_GUARD'; $guard();
    $stage = 'BOOTSTRAP';
    if (!chdir($root)) throw new \RuntimeException();
    require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $guard(true);
    $workflow = app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    $snapshot = new \ReflectionMethod($workflow, 'snapshot'); $snapshot->setAccessible(true);
    $safe = new \ReflectionMethod($workflow, 'safeAnalysisFailure'); $safe->setAccessible(true);
    $db = \Illuminate\Support\Facades\DB::class;
    $integer = static function ($value): int {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value)
            || strlen((string) $value) > strlen((string) PHP_INT_MAX)
            || (strlen((string) $value) === strlen((string) PHP_INT_MAX) && strcmp((string) $value, (string) PHP_INT_MAX) > 0)) throw new \RuntimeException();
        return (int) $value;
    };
    $readState = static function () use ($db, $workflow, $snapshot, $guard, $readFile, $integer): array {
        $guard(true);
        $configuration = config('whatsapp_orders', []); $cart = config('whatsapp_cart', []);
        if (!is_array($configuration) || !is_array($cart)) throw new \RuntimeException();
        $enabled = in_array($configuration['enabled'] ?? null, [true, 1, '1'], true);
        $model = $configuration['model'] ?? null; $key = $configuration['api_key'] ?? null;
        if (!$enabled || ($configuration['mode'] ?? null) !== 'auto' || !is_string($model)
            || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\z/', $model) || !is_string($key)
            || strlen($key) < 16 || strlen($key) > 512 || preg_match('/\s|[\x00-\x1f\x7f]/', $key)) throw new \RuntimeException();
        $activation = $integer($configuration['activation_message_id'] ?? null);
        $integer($configuration['activation_event_id'] ?? null);
        $instant = $cart['activated_at'] ?? null;
        $time = is_string($instant) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $instant, new \DateTimeZone('UTC')) : false;
        if (!$time || $time->format('Y-m-d H:i:s') !== $instant) throw new \RuntimeException();
        $fileHashes = [];
        foreach (['.env','config/whatsapp_orders.php','config/whatsapp_cart.php','bootstrap/cache/config.php'] as $path) {
            $bytes = $readFile($path, $path !== 'bootstrap/cache/config.php');
            $fileHashes[$path] = $bytes === null ? null : hash('sha256', $bytes);
        }
        $fingerprint = hash('sha256', json_encode([$configuration, $cart, $fileHashes], JSON_THROW_ON_ERROR));
        unset($key, $configuration, $cart, $fileHashes, $bytes);
        $cid = WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::CONVERSATION;
        $thread = $db::table('whatsapp_inbox_conversations')->where('id', $cid)
            ->where('waba_id', WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::WABA)->where('phone_number_id', WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::PHONE)->first(['id']);
        if (!$thread) throw new \RuntimeException();
        $scan = $db::table('whatsapp_order_scans')->where('conversation_id', $cid)->first(['auto_floor']);
        $dispatched = $db::table('whatsapp_order_drafts')->where('conversation_id', $cid)->where('status', 'DISPATCHED')->max('evidence_ceiling');
        $floor = max(WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::MINIMUM_FLOOR, $activation,
            $integer($scan->auto_floor ?? 0), $integer($dispatched ?? 0));
        $ceiling = $integer($db::table('whatsapp_inbox_messages')->where('conversation_id', $cid)->max('id') ?? 0);
        $budget = 0;
        foreach ($db::table('whatsapp_inbox_messages')->where('conversation_id', $cid)->where('id', '>', $floor)->where('id', '<=', $ceiling)
            ->orderByDesc('id')->limit(61)->select('id')->selectRaw('LENGTH(content) AS content_bytes')->get() as $row) {
            $size = $integer($row->content_bytes); $budget += $size;
            if ($size > 8388608 || $budget > 16777216) throw new \RuntimeException();
        }
        $snap = $snapshot->invoke($workflow, $cid, $floor, $ceiling, false);
        $status = ['status' => 'UNREADABLE'];
        try {
            $event = $db::table('whatsapp_webhook_events')->where('id', 7215)->select('id')
                ->selectRaw('CASE WHEN LENGTH(payload) <= 8388608 THEN payload ELSE NULL END AS payload')->first();
            if (!$event || !is_string($event->payload) || strlen($event->payload) > 8388608) throw new \RuntimeException();
            $plain = \Illuminate\Support\Facades\Crypt::decryptString($event->payload);
            if (strlen($plain) > 4194304) throw new \RuntimeException();
            $payload = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new \RuntimeException();
            $status = ['status' => 'READY'] + WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::statusMetadata($payload);
            unset($event, $plain, $payload);
        } catch (\Throwable $ignore) {}
        return ['configured' => true, 'configuration_fingerprint' => $fingerprint, 'floor' => $floor, 'ceiling' => $ceiling,
            'snapshot' => $snap, 'status_event_7215' => $status];
    };
    $stage = 'PROBE';
    $report = WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::run(true, $readState,
        static fn (array $rows): array => WhatsAppOrderFragmentedItemGroundingReadOnlyProbe::extractObserved($rows,config('whatsapp_orders', [])),
        static fn ($result): array => $safe->invoke($workflow, $result));
    $output = $report['output']; $code = $report['code'];
} catch (\Throwable $ignore) {
    $output += ['status' => 'STOPPED', 'stage' => $stage, 'reason' => 'GUARDED_PROBE_FAILED']; $code = 1;
}
while (ob_get_level() > 0) ob_end_clean();
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($code);
