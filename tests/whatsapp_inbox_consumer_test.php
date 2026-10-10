<?php

// Standalone transaction harness. Fake facades verify use of encryption and transactions;
// they do not claim to test Laravel's cipher or a real database's concurrent locks.
namespace Illuminate\Database {
    class QueryException extends \RuntimeException
    {
        public $errorInfo;
        public function __construct($detail, $known = true)
        {
            $this->errorInfo = $known ? ['23000', 1062, $detail] : ['23000', 1452, $detail];
            parent::__construct($detail);
        }
    }
}

namespace Illuminate\Contracts\Encryption {
    class DecryptException extends \RuntimeException {}
}

namespace Illuminate\Support\Facades {
    class Crypt
    {
        public static function encryptString($value) { return 'sealed:' . base64_encode($value); }
        public static function decryptString($value)
        {
            if (!is_string($value) || strpos($value, 'sealed:') !== 0) throw new \Illuminate\Contracts\Encryption\DecryptException('Invalid fixture ciphertext');
            $plain = base64_decode(substr($value, 7), true);
            if ($plain === false) throw new \Illuminate\Contracts\Encryption\DecryptException('Invalid fixture ciphertext');
            return $plain;
        }
    }

    class DB
    {
        public static $tables = [];
        public static $depth = 0;
        public static $rollbacks = 0;
        public static $locks = 0;
        public static $messageInsertCount = 0;
        public static $failMessageInsertAt = null;
        public static $failProcessedUpdate = false;
        public static $beforeTransaction = null;
        public static $uniqueMessageConflictOnce = false;
        public static $foreignKeyFailureOnce = false;

        public static function reset()
        {
            self::$tables = ['whatsapp_webhook_events' => [], 'whatsapp_inbox_conversations' => [], 'whatsapp_inbox_messages' => [], 'whatsapp_inbox_ingestion_failures' => []];
            self::$depth = self::$rollbacks = self::$locks = self::$messageInsertCount = 0;
            self::$failMessageInsertAt = null;
            self::$failProcessedUpdate = false;
            self::$beforeTransaction = null;
            self::$uniqueMessageConflictOnce = self::$foreignKeyFailureOnce = false;
        }
        public static function table($name) { return new InboxTestQuery($name); }
        public static function transaction($callback, $attempts = 1)
        {
            if (self::$beforeTransaction) {
                $hook = self::$beforeTransaction;
                self::$beforeTransaction = null;
                $hook();
            }
            $snapshot = self::$tables;
            self::$depth++;
            try {
                $result = $callback();
                self::$depth--;
                return $result;
            } catch (\Throwable $error) {
                self::$tables = $snapshot;
                self::$depth--;
                self::$rollbacks++;
                throw $error;
            }
        }
    }

    class InboxTestQuery
    {
        private $table;
        private $filters = [];
        private $order;
        private $limit;
        private $excludeFailures = false;
        public function __construct($table) { $this->table = $table; }
        public function where($column, $operator, $value = null)
        {
            if (func_num_args() === 2) { $value = $operator; $operator = '='; }
            $this->filters[] = [$column, $operator, $value];
            return $this;
        }
        public function whereNull($column) { $this->filters[] = [$column, 'null', null]; return $this; }
        public function select($column) { return $this; }
        public function from($table) { return $this; }
        public function whereColumn($left, $right) { return $this; }
        public function whereNotExists($callback)
        {
            $callback(new self('whatsapp_inbox_ingestion_failures'));
            $this->excludeFailures = true;
            return $this;
        }
        public function orderBy($column, $direction = 'asc') { $this->order = [$column, $direction]; return $this; }
        public function limit($limit) { $this->limit = $limit; return $this; }
        public function lockForUpdate()
        {
            if (DB::$depth < 1) throw new \RuntimeException('Lock outside transaction');
            DB::$locks++;
            return $this;
        }
        private function matches($row)
        {
            if ($this->excludeFailures) {
                foreach (DB::$tables['whatsapp_inbox_ingestion_failures'] as $failure) {
                    if ($failure['event_id'] === $row['id']) return false;
                }
            }
            foreach ($this->filters as [$column, $operator, $value]) {
                $actual = $row[$column] ?? null;
                if ($operator === 'null' ? $actual !== null : $actual != $value) return false;
            }
            return true;
        }
        private function rows()
        {
            $rows = array_values(array_filter(DB::$tables[$this->table], function ($row) { return $this->matches($row); }));
            if ($this->order) {
                [$column, $direction] = $this->order;
                usort($rows, function ($left, $right) use ($column, $direction) {
                    $compare = $left[$column] <=> $right[$column];
                    return $direction === 'desc' ? -$compare : $compare;
                });
            }
            return $this->limit === null ? $rows : array_slice($rows, 0, $this->limit);
        }
        public function pluck($column) { return array_column($this->rows(), $column); }
        public function first() { $rows = $this->rows(); return $rows ? (object) $rows[0] : null; }
        public function insertGetId($row)
        {
            $ids = array_column(DB::$tables[$this->table], 'id');
            $id = $ids ? max($ids) + 1 : 1;
            $row['id'] = $id;
            $this->insert($row);
            return $id;
        }
        public function insert($row)
        {
            if (DB::$depth < 1) throw new \RuntimeException('Write outside transaction');
            if ($this->table === 'whatsapp_inbox_messages') {
                DB::$messageInsertCount++;
                if (DB::$uniqueMessageConflictOnce) {
                    DB::$uniqueMessageConflictOnce = false;
                    throw new \Illuminate\Database\QueryException("Duplicate entry for key 'wa_inbox_message_unique'");
                }
                if (DB::$foreignKeyFailureOnce) {
                    DB::$foreignKeyFailureOnce = false;
                    throw new \Illuminate\Database\QueryException('Foreign key constraint failed', false);
                }
                if (DB::$messageInsertCount === DB::$failMessageInsertAt) throw new \RuntimeException('Injected insert failure');
                foreach (DB::$tables[$this->table] as $saved) {
                    if ($saved['message_key'] === $row['message_key']) throw new \Illuminate\Database\QueryException("Duplicate entry for key 'wa_inbox_message_unique'");
                }
            }
            if ($this->table === 'whatsapp_inbox_conversations') {
                foreach (DB::$tables[$this->table] as $saved) {
                    if ($saved['waba_id'] === $row['waba_id'] && $saved['phone_number_id'] === $row['phone_number_id']
                        && $saved['peer_hash'] === $row['peer_hash']) throw new \Illuminate\Database\QueryException("Duplicate entry for key 'wa_inbox_peer_unique'");
                }
            }
            if (!isset($row['id'])) {
                $ids = array_column(DB::$tables[$this->table], 'id');
                $row['id'] = $ids ? max($ids) + 1 : 1;
            }
            DB::$tables[$this->table][] = $row;
            return true;
        }
        public function update($values)
        {
            if (DB::$depth < 1) throw new \RuntimeException('Write outside transaction');
            if ($this->table === 'whatsapp_webhook_events' && DB::$failProcessedUpdate) throw new \RuntimeException('Injected processed update failure');
            $count = 0;
            foreach (DB::$tables[$this->table] as &$row) {
                if ($this->matches($row)) { $row = array_merge($row, $values); $count++; }
            }
            unset($row);
            return $count;
        }
        public function delete()
        {
            if (DB::$depth < 1) throw new \RuntimeException('Write outside transaction');
            $before = count(DB::$tables[$this->table]);
            DB::$tables[$this->table] = array_values(array_filter(DB::$tables[$this->table], function ($row) { return !$this->matches($row); }));
            return $before - count(DB::$tables[$this->table]);
        }
    }
}

namespace {
    use App\Services\Dashboard\WhatsAppInboxConsumer;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\DB;

    function config($key) { return $key === 'app.key' ? 'base64:' . base64_encode(str_repeat('fixture-key-', 3)) : null; }
    function now($timezone = null) { return '2026-10-10 00:00:00'; }
    require __DIR__ . '/../app/Support/WhatsAppInboxProtocol.php';
    require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php';
    require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxConsumer.php';

    function check($condition, $label)
    {
        if (!$condition) throw new \RuntimeException('Consumer fixture failed: ' . $label);
    }
    function message($id = 'wamid.fixture1', $text = 'Fixture message', $direction = 'inbound', $phone = '201111111111', $user = 'US.fixture1')
    {
        $row = ['id' => $id, 'timestamp' => '1691583200', 'type' => 'text', 'text' => ['body' => $text]];
        if ($direction === 'outbound') { $row['from'] = '201285545554'; $row['to'] = $phone; $row['to_user_id'] = $user; }
        else { $row['from'] = $phone; $row['from_user_id'] = $user; }
        if ($user === null) unset($row[$direction === 'outbound' ? 'to_user_id' : 'from_user_id']);
        return $row;
    }
    function payload(array $messages, $source = 'messages', $waba = '468336579702269', $phone = '515388018324075')
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => $waba, 'changes' => [[
                'field' => $source, 'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '201285545554', 'phone_number_id' => $phone],
                    $source === 'smb_message_echoes' ? 'message_echoes' : 'messages' => $messages,
                ],
            ]],
        ]]];
    }
    function event(array $body, $id = null)
    {
        if ($id === null) $id = count(DB::$tables['whatsapp_webhook_events']) + 1;
        DB::$tables['whatsapp_webhook_events'][] = [
            'id' => $id, 'payload' => Crypt::encryptString(json_encode($body, JSON_THROW_ON_ERROR)),
            'processed_at' => null, 'received_at' => '2026-10-09 22:00:00',
        ];
    }
    function consume($limit = 100, $retry = false) { return (new WhatsAppInboxConsumer())->consume($limit, $retry); }

    $tests = 0;
    DB::reset();
    event(payload([message()]));
    $metrics = consume();
    check($metrics['events_processed'] === 1 && $metrics['messages_inserted'] === 1 && $metrics['errors'] === 0, 'valid event projection');
    check(count(DB::$tables['whatsapp_inbox_conversations']) === 1 && DB::$tables['whatsapp_webhook_events'][0]['processed_at'] !== null, 'thread and event commit');
    $thread = DB::$tables['whatsapp_inbox_conversations'][0];
    $stored = DB::$tables['whatsapp_inbox_messages'][0];
    check(strpos($thread['customer'], 'sealed:') === 0 && strpos($stored['content'], 'sealed:') === 0, 'encrypted columns');
    check(!isset($thread['phone']) && !isset($stored['text']) && strpos(json_encode($stored), 'Fixture message') === false, 'no plaintext projection');
    check(strlen($thread['peer_hash']) === 64 && strlen($stored['message_key']) === 64 && DB::$locks >= 2, 'scoped keys and locked rows');
    $tests++;

    event(payload([message()], 'standby'));
    $metrics = consume();
    check($metrics['messages_replayed'] === 1 && count(DB::$tables['whatsapp_inbox_messages']) === 1, 'copy replay deduplication');
    $dto = json_decode(Crypt::decryptString(DB::$tables['whatsapp_inbox_messages'][0]['content']), true);
    check($dto['content']['sources'] === ['messages', 'standby'] && $dto['text'] === 'Fixture message', 'source union preserves content');
    check(consume()['events_seen'] === 0, 'processed replay skipped');
    $tests++;

    DB::reset();
    $copies = payload([message('wamid.bridge', 'Same message', 'inbound', '201111111111', null)]);
    $rich = payload([message('wamid.bridge', 'Same message')], 'standby');
    $copies['entry'][0]['changes'][] = $rich['entry'][0]['changes'][0];
    event($copies);
    $metrics = consume();
    $dto = json_decode(Crypt::decryptString(DB::$tables['whatsapp_inbox_messages'][0]['content']), true);
    check($metrics['messages_inserted'] === 1 && $metrics['quarantined_events'] === 0
        && $dto['peer_user_id'] === 'US.fixture1' && count($dto['content']['sources']) === 2,
        'same-event explicit paired identity enrichment projects one message');
    $tests++;

    DB::reset();
    event(payload([message('wamid.peer1', 'Same test marker', 'inbound', '201111111111', 'US.fixture1')]));
    event(payload([message('wamid.peer2', 'Same test marker', 'inbound', '201222222222', 'US.fixture2')]));
    consume();
    check(count(DB::$tables['whatsapp_inbox_conversations']) === 2 && count(DB::$tables['whatsapp_inbox_messages']) === 2,
        'shared text never merges different peers');
    $tests++;

    DB::reset();
    event(payload([message('wamid.phone-only', 'First message', 'inbound', '201111111111', null)]));
    consume();
    event(payload([message('wamid.with-user', 'Later message')]));
    consume();
    check(count(DB::$tables['whatsapp_inbox_conversations']) === 2,
        'later identity enrichment never silently combines an existing phone-only thread');
    $tests++;

    DB::reset();
    $original = message();
    $original['text']['preview_url'] = false;
    event(payload([$original]));
    consume();
    $reordered = $original;
    $reordered['text'] = array_reverse($original['text'], true);
    event(payload([$reordered]));
    $metrics = consume();
    check($metrics['messages_replayed'] === 1 && $metrics['quarantined_events'] === 0
        && count(DB::$tables['whatsapp_inbox_messages']) === 1, 'object key order does not cause a false conflict');
    $tests++;

    DB::reset();
    event(payload([message()]));
    DB::$uniqueMessageConflictOnce = true;
    $metrics = consume();
    check($metrics['events_processed'] === 1 && $metrics['errors'] === 0 && DB::$rollbacks === 1
        && count(DB::$tables['whatsapp_inbox_messages']) === 1 && count(DB::$tables['whatsapp_inbox_conversations']) === 1,
        'known unique race retries the complete event transaction');
    $tests++;

    DB::reset();
    event(payload([message()]));
    DB::$foreignKeyFailureOnce = true;
    $metrics = consume();
    check($metrics['errors'] === 1 && DB::$rollbacks === 1 && DB::$tables['whatsapp_inbox_messages'] === []
        && DB::$tables['whatsapp_inbox_ingestion_failures'] === [], 'foreign key failure is not mistaken for a retryable unique race');
    $tests++;

    foreach (['text', 'peer', 'direction', 'time', 'body'] as $kind) {
        DB::reset();
        event(payload([message()]));
        consume();
        $snapshot = DB::$tables['whatsapp_inbox_messages'];
        $copy = message();
        $source = 'messages';
        if ($kind === 'text') $copy['text']['body'] = 'Conflicting copy';
        if ($kind === 'peer') $copy = message('wamid.fixture1', 'Fixture message', 'inbound', '201222222222', 'US.fixture2');
        if ($kind === 'direction') { $copy = message('wamid.fixture1', 'Fixture message', 'outbound'); $source = 'smb_message_echoes'; }
        if ($kind === 'time') $copy['timestamp'] = '1691583201';
        if ($kind === 'body') $copy['text']['preview_url'] = true;
        event(payload([$copy], $source));
        $metrics = consume();
        check($metrics['quarantined_events'] === 1 && $metrics['events_processed'] === 0, $kind . ' conflict quarantined');
        check(DB::$tables['whatsapp_inbox_messages'] === $snapshot && DB::$tables['whatsapp_webhook_events'][1]['processed_at'] === null, $kind . ' conflict leaves raw unprocessed');
        $tests++;
    }

    DB::reset();
    event(payload([message()]));
    consume();
    event(payload([message('wamid.fixture2', 'Second message', 'inbound', '201222222222', 'US.fixture1')]));
    $metrics = consume();
    check($metrics['quarantined_events'] === 1 && count(DB::$tables['whatsapp_inbox_messages']) === 1, 'customer identity conflict rollback');
    $tests++;

    DB::reset();
    event(payload([message('wamid.first'), message('wamid.second')]));
    DB::$failMessageInsertAt = 2;
    $metrics = consume();
    check($metrics['errors'] === 1 && $metrics['messages_inserted'] === 0 && DB::$rollbacks === 1, 'failed insert does not publish success metrics');
    check(DB::$tables['whatsapp_inbox_messages'] === [] && DB::$tables['whatsapp_inbox_conversations'] === []
        && DB::$tables['whatsapp_webhook_events'][0]['processed_at'] === null, 'whole event transaction rollback');
    check(DB::$tables['whatsapp_inbox_ingestion_failures'] === [], 'transient insert failure remains retryable');
    $tests++;

    DB::reset();
    event(payload([message()]));
    DB::$failProcessedUpdate = true;
    $metrics = consume();
    check($metrics['errors'] === 1 && DB::$tables['whatsapp_inbox_messages'] === []
        && DB::$tables['whatsapp_webhook_events'][0]['processed_at'] === null, 'failed processed marker rolls projection back');
    check(DB::$tables['whatsapp_inbox_ingestion_failures'] === [], 'transient marker failure remains retryable');
    $tests++;

    DB::reset();
    event(payload([message()]));
    DB::$tables['whatsapp_webhook_events'][0]['payload'] = 'invalid-ciphertext';
    $metrics = consume(1);
    check($metrics['quarantined_events'] === 1 && count(DB::$tables['whatsapp_inbox_ingestion_failures']) === 1, 'quarantine records fixed metadata');
    $failure = DB::$tables['whatsapp_inbox_ingestion_failures'][0];
    check($failure['reason'] === 'INVALID_EVENT' && $failure['attempts'] === 1, 'fixed reason and attempt count');
    event(payload([message('wamid.next')]), 2);
    $metrics = consume(1);
    check($metrics['events_processed'] === 1 && DB::$tables['whatsapp_webhook_events'][1]['processed_at'] !== null,
        'quarantine does not starve a later event');
    DB::$tables['whatsapp_webhook_events'][0]['payload'] = Crypt::encryptString(json_encode(payload([message()]), JSON_THROW_ON_ERROR));
    check(consume(1)['events_seen'] === 0, 'default retains failure until explicit retry');
    $metrics = consume(1, true);
    check($metrics['events_processed'] === 1 && DB::$tables['whatsapp_inbox_ingestion_failures'] === []
        && DB::$tables['whatsapp_webhook_events'][0]['processed_at'] !== null, 'explicit successful retry removes marker atomically');
    $tests++;

    DB::reset();
    $body = payload([]);
    unset($body['entry'][0]['changes'][0]['value']['messages']);
    $body['entry'][0]['changes'][0]['value']['statuses'] = [['id' => 'wamid.status', 'status' => 'delivered']];
    event($body);
    $metrics = consume();
    check($metrics['events_processed'] === 1 && $metrics['status_records'] === 1 && DB::$tables['whatsapp_inbox_messages'] === [], 'legitimate status-only event');
    $tests++;

    DB::reset();
    event(payload([message()], 'messages', '1636131124838697'));
    event(payload([message()], 'messages', '468336579702269', '999'));
    $metrics = consume();
    check($metrics['events_processed'] === 2 && $metrics['ignored_records'] === 2 && DB::$tables['whatsapp_inbox_messages'] === [], 'exact real WABA and phone scope');
    $tests++;

    DB::reset();
    $bad = message();
    unset($bad['from'], $bad['from_user_id']);
    event(payload([message('wamid.valid'), $bad]));
    $metrics = consume();
    check($metrics['quarantined_events'] === 1 && DB::$tables['whatsapp_inbox_messages'] === []
        && DB::$tables['whatsapp_webhook_events'][0]['processed_at'] === null, 'malformed record quarantines complete event');
    $tests++;

    DB::reset();
    event(payload([message()]));
    DB::$tables['whatsapp_webhook_events'][0]['payload'] = 'invalid-ciphertext';
    check(consume()['quarantined_events'] === 1 && DB::$tables['whatsapp_webhook_events'][0]['processed_at'] === null, 'unreadable encrypted event retained');
    $tests++;

    DB::reset();
    event(payload([message('wamid.1')]), 1);
    event(payload([message('wamid.2')]), 2);
    $metrics = consume(1);
    check($metrics['events_seen'] === 1 && DB::$tables['whatsapp_webhook_events'][1]['processed_at'] === null, 'bounded batch');
    foreach ([0, 501] as $badLimit) {
        $caught = false;
        try { consume($badLimit); } catch (\InvalidArgumentException $error) { $caught = true; }
        check($caught, 'invalid limit rejected');
    }
    $tests++;

    DB::reset();
    event(payload([message()]));
    DB::$beforeTransaction = function () { DB::$tables['whatsapp_webhook_events'][0]['processed_at'] = '2026-10-10 00:00:00'; };
    $metrics = consume();
    check($metrics['events_skipped'] === 1 && DB::$tables['whatsapp_inbox_messages'] === [], 'processed flag rechecked inside lock');
    $tests++;

    DB::reset();
    event(payload([message()]));
    $rawBefore = DB::$tables['whatsapp_webhook_events'][0];
    $marker = ['event_id' => 1, 'reason' => 'INVALID_EVENT', 'attempts' => 1, 'last_attempted_at' => '2026-10-10 00:00:00'];
    DB::$beforeTransaction = function () use ($marker) {
        // The batch ID has already been selected. Another locked operation quarantines
        // the raw event before this consumer acquires/rechecks its row.
        DB::$tables['whatsapp_inbox_ingestion_failures'][] = $marker;
    };
    $metrics = consume();
    check(WhatsAppInboxConsumer::QUARANTINE_BATCH_RECHECK === true, 'activation guard marker available');
    check($metrics['events_seen'] === 1 && $metrics['events_skipped'] === 1 && $metrics['events_processed'] === 0
        && $metrics['messages_inserted'] === 0 && $metrics['errors'] === 0, 'quarantine added after batch selection is rechecked under raw lock');
    check(DB::$tables['whatsapp_webhook_events'][0] === $rawBefore && DB::$tables['whatsapp_inbox_ingestion_failures'] === [$marker]
        && DB::$tables['whatsapp_inbox_messages'] === [] && DB::$tables['whatsapp_inbox_conversations'] === [] && DB::$locks >= 2,
        'late quarantine preserves encrypted event and marker without projection or publication');
    $tests++;
    $metrics = consume(1, true);
    check($metrics['events_processed'] === 1 && $metrics['messages_inserted'] === 1
        && DB::$tables['whatsapp_inbox_ingestion_failures'] === [], 'explicit retry remains distinct from default guarded projection');
    $tests++;

    DB::reset();
    event(payload([message('wamid.new')]));
    $older = message('wamid.old');
    $older['timestamp'] = '1691583100';
    event(payload([$older]));
    consume();
    $thread = DB::$tables['whatsapp_inbox_conversations'][0];
    check($thread['first_message_at'] < $thread['last_message_at'] && count(DB::$tables['whatsapp_inbox_conversations']) === 1, 'out-of-order timestamps preserve thread range');
    $tests++;

    echo 'WHATSAPP_INBOX_CONSUMER_TESTS=' . $tests . "\n";
}
