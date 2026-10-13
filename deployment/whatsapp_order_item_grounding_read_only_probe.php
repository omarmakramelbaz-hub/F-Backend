<?php

/** One explicitly authorized extraction; all application observations remain read-only. */
final class WhatsAppOrderItemGroundingReadOnlyProbe
{
    public const CONVERSATION = 11;
    public const MINIMUM_FLOOR = 4016;
    public const WABA = '468336579702269';
    public const PHONE = '515388018324075';
    public const SOURCE_PINS = [
        'App\\Services\\Dashboard\\WhatsAppOrderWorkflow' => ['app/Services/Dashboard/WhatsAppOrderWorkflow.php', 'e87d94154e5f9871c959c23c8c9868f53b5dd5d0'],
        'App\\Services\\Dashboard\\WhatsAppOrderAiProvider' => ['app/Services/Dashboard/WhatsAppOrderAiProvider.php', '3bf588abd359c3927fdd18a6d6e4f36c8dd9ada9'],
        'App\\Support\\WhatsAppOrderExtraction' => ['app/Support/WhatsAppOrderExtraction.php', '6f5e125c7372dfb1f74f2c2d6e629fb7461a88f4'],
        'App\\Support\\WhatsAppInboxProtocol' => ['app/Support/WhatsAppInboxProtocol.php', 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd'],
        'App\\Services\\Dashboard\\WhatsAppAcceptedReplyEvidence' => ['app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php', '4b571f7f4a5cef9257c414886dba515c06dae2a1'],
        'App\\Services\\Dashboard\\WhatsAppInboxMessage' => ['app/Services/Dashboard/WhatsAppInboxMessage.php', 'bedd91db5266d83bdce84a04836e988518891728'],
        'App\\Services\\Dashboard\\WhatsAppReplyService' => ['app/Services/Dashboard/WhatsAppReplyService.php', '1ef23df825287f8d3dedafcdb5d70ffeac7f1f29'],
        'App\\Services\\Dashboard\\WhatsAppNativePlaceholderHydration' => ['app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php', '9e221ba052589c1ffe474958b636a77ff5817b7d'],
        'App\\Services\\Dashboard\\WhatsAppInboxConsumer' => ['app/Services/Dashboard/WhatsAppInboxConsumer.php', 'e0909057496cb9f710928fd8c273b5c9d0adc97f'],
        'App\\Services\\Dashboard\\WhatsAppInboxAccess' => ['app/Services/Dashboard/WhatsAppInboxAccess.php', '186789d42113e81818de8d82a2327e3ee9d8d0b5'],
        'App\\Services\\Dashboard\\PosServiceTicket' => ['app/Services/Dashboard/PosServiceTicket.php', 'ef4c7b6f4a1276c122634aafabb2d510926aaeef'],
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
        $providerClass=\App\Services\Dashboard\WhatsAppOrderAiProvider::class;
        if ($transport===null) {
            $baseline=new $providerClass(null,$configuration);
            $request=new \ReflectionMethod($baseline,'request'); $request->setAccessible(true);
            $transport=static fn (string $body,string $key): array => $request->invoke($baseline,$body,$key);
        }
        $metadata=self::notApplicable();
        $observer=static function ($body,$key) use ($transport,$rows,&$metadata) {
            $response=$transport($body,$key);
            try { $metadata=self::observe($response,$rows); }
            catch (\Throwable $ignored) { $metadata=self::notApplicable(); }
            // The exact response, request bytes and credential are never modified or retained.
            return $response;
        };
        $provider=new $providerClass($observer,$configuration);
        $result=$provider->extract($rows);
        return ['result'=>$result,'item_grounding'=>self::safeMetadata($metadata,$result)];
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
        $items=[];
        foreach ($data['items'] as $index=>$item) {
            $name=$item['name']; $any=$backed->invoke(null,$name,$groups['any']);
            $foldedName=self::arabicFold($name);
            $items[]=['index'=>$index+1,
                'literal_match_cited_request'=>$backed->invoke(null,$name,$groups['request']),
                'literal_match_cited_confirmation'=>$backed->invoke(null,$name,$groups['confirmation']),
                'literal_match_acceptance_only'=>$backed->invoke(null,$name,$groups['acceptance']),
                'literal_match_uncited_customer'=>$backed->invoke(null,$name,$groups['uncited_customer']),
                'literal_match_uncited_business'=>$backed->invoke(null,$name,$groups['uncited_business']),
                'literal_match_any_transcript'=>$any,
                // Diagnostic only: never used by extraction, citation, quote or authorization.
                'arabic_folded_any_transcript'=>!$any && $foldedName!==''
                    && $backed->invoke(null,$foldedName,array_map([self::class,'arabicFold'],$groups['any']))];
        }
        return ['status'=>'ITEM_NAME_GROUNDING','items_count'=>count($items),'items'=>$items];
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
        $flags=['literal_match_cited_request','literal_match_cited_confirmation','literal_match_acceptance_only',
            'literal_match_uncited_customer','literal_match_uncited_business','literal_match_any_transcript','arabic_folded_any_transcript'];
        foreach ($metadata['items'] as $index=>$item) {
            if (!is_array($item) || count($item)!==8 || array_diff(array_keys($item),array_merge(['index'],$flags))
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
        $out = ['diagnostic' => 'WHATSAPP_ORDER_ITEM_GROUNDING_READ_ONLY_PROBE', 'writes' => 0, 'ai_calls' => 0,
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

if (defined('WHATSAPP_ORDER_ITEM_GROUNDING_READ_ONLY_PROBE_TEST_ONLY') && WHATSAPP_ORDER_ITEM_GROUNDING_READ_ONLY_PROBE_TEST_ONLY === true) return;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('display_startup_errors', '0'); ini_set('log_errors', '0'); ob_start();
$output = ['diagnostic' => 'WHATSAPP_ORDER_ITEM_GROUNDING_READ_ONLY_PROBE', 'writes' => 0, 'ai_calls' => 0];
$stage = 'AUTHORIZATION';
try {
    if (!WhatsAppOrderItemGroundingReadOnlyProbe::authorized($argv)) throw new \RuntimeException();
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
        foreach (WhatsAppOrderItemGroundingReadOnlyProbe::SOURCE_PINS as $class => [$path, $hash]) {
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
        $cid = WhatsAppOrderItemGroundingReadOnlyProbe::CONVERSATION;
        $thread = $db::table('whatsapp_inbox_conversations')->where('id', $cid)
            ->where('waba_id', WhatsAppOrderItemGroundingReadOnlyProbe::WABA)->where('phone_number_id', WhatsAppOrderItemGroundingReadOnlyProbe::PHONE)->first(['id']);
        if (!$thread) throw new \RuntimeException();
        $scan = $db::table('whatsapp_order_scans')->where('conversation_id', $cid)->first(['auto_floor']);
        $dispatched = $db::table('whatsapp_order_drafts')->where('conversation_id', $cid)->where('status', 'DISPATCHED')->max('evidence_ceiling');
        $floor = max(WhatsAppOrderItemGroundingReadOnlyProbe::MINIMUM_FLOOR, $activation,
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
            $status = ['status' => 'READY'] + WhatsAppOrderItemGroundingReadOnlyProbe::statusMetadata($payload);
            unset($event, $plain, $payload);
        } catch (\Throwable $ignore) {}
        return ['configured' => true, 'configuration_fingerprint' => $fingerprint, 'floor' => $floor, 'ceiling' => $ceiling,
            'snapshot' => $snap, 'status_event_7215' => $status];
    };
    $stage = 'PROBE';
    $report = WhatsAppOrderItemGroundingReadOnlyProbe::run(true, $readState,
        static fn (array $rows): array => WhatsAppOrderItemGroundingReadOnlyProbe::extractObserved($rows,config('whatsapp_orders', [])),
        static fn ($result): array => $safe->invoke($workflow, $result));
    $output = $report['output']; $code = $report['code'];
} catch (\Throwable $ignore) {
    $output += ['status' => 'STOPPED', 'stage' => $stage, 'reason' => 'GUARDED_PROBE_FAILED']; $code = 1;
}
while (ob_get_level() > 0) ob_end_clean();
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($code);
