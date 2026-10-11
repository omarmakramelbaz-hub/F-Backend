<?php
// Isolated read-side fixtures: no Laravel bootstrap, customer data, network or database mutations.
namespace Illuminate\Support\Facades {
    final class Crypt {
        public static function decryptString(string $value): string {
            if (!str_starts_with($value, 'fixture:')) throw new \RuntimeException('FIXTURE_CIPHER');
            return substr($value, 8);
        }
    }
    final class DB {
        public static array $rows = [];
        public static array $reads = [];
        public static function table(string $name): \ReplyFeedQuery {
            self::$reads[] = $name;
            return new \ReplyFeedQuery($name);
        }
    }
}
namespace {
    use Illuminate\Support\Facades\DB;
    use App\Services\Dashboard\WhatsAppReplyService;

    final class ReplyFeedQuery {
        private array $rows;
        private ?int $limit = null;
        public function __construct(string $name) {
            [$table, $alias] = array_pad(explode(' as ', $name, 2), 2, null);
            $this->rows = [];
            foreach (DB::$rows[$table] ?? [] as $row) {
                $expanded = $row;
                foreach ($row as $key => $value) $expanded[($alias ?? $table) . '.' . $key] = $value;
                $this->rows[] = $expanded;
            }
        }
        public function join(string $name, string $left, string $operator, string $right): self {
            if ($operator !== '=') throw new \RuntimeException('FIXTURE_OPERATOR');
            $other = new self($name); $joined = [];
            foreach ($this->rows as $row) foreach ($other->rows as $candidate) {
                if ((array_key_exists($right, $row) && array_key_exists($left, $candidate)
                        && $row[$right] === $candidate[$left])
                    || (array_key_exists($left, $row) && array_key_exists($right, $candidate)
                        && $row[$left] === $candidate[$right]))
                    $joined[] = array_merge($row, $candidate);
            }
            $this->rows = $joined;
            return $this;
        }
        public function where(string $key, $value): self {
            $this->rows = array_values(array_filter($this->rows,
                static fn ($row) => array_key_exists($key, $row) && $row[$key] === $value));
            return $this;
        }
        public function whereIn(string $key, array $values): self {
            $this->rows = array_values(array_filter($this->rows,
                static fn ($row) => array_key_exists($key, $row) && in_array($row[$key], $values, true)));
            return $this;
        }
        public function orderByDesc(string $key): self {
            usort($this->rows, static fn ($left, $right) => $right[$key] <=> $left[$key]);
            return $this;
        }
        public function limit(int $limit): self { $this->limit = $limit; return $this; }
        public function get(?array $columns = null): array {
            $rows = $this->limit === null ? $this->rows : array_slice($this->rows, 0, $this->limit);
            return array_map(static function ($row) use ($columns) {
                if ($columns === null) return (object) $row;
                $result = [];
                foreach ($columns as $key) {
                    $name = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
                    $result[$name] = $row[$key];
                }
                return (object) $result;
            }, $rows);
        }
    }

    require __DIR__ . '/../app/Services/Dashboard/WhatsAppReplyService.php';
    $reflection = new \ReflectionClass(WhatsAppReplyService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $recent = $reflection->getMethod('recent');
    $recent->setAccessible(true);
    $checks = 0;
    function check(bool $condition, string $label): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($label);
        $checks++;
    }
    function cipher(array $value): string { return 'fixture:' . json_encode($value, JSON_THROW_ON_ERROR); }
    function requestRow(int $id, string $state = 'ACCEPTED', string $kind = 'text', int $conversation = 7): array {
        return ['id' => $id, 'state' => $state, 'kind' => $kind, 'conversation_id' => $conversation,
            'waba_id' => '468336579702269', 'phone_number_id' => '515388018324075', 'actor_id' => 1,
            'audit' => cipher(['client_request_id' => 'fixture-uuid-' . $id, 'text' => 'Fixture accepted reply ' . $id]),
            'response_details' => cipher(['message_id' => 'wamid.private-fixture-' . $id]),
            'created_at' => '2026-10-11 22:01:00', 'completed_at' => '2026-10-11 22:01:09'];
    }
    function inboxRow(int $request, int $id, int $conversation = 7, string $type = 'text', string $direction = 'outbound'): array {
        return ['id' => $id, 'conversation_id' => $conversation, 'direction' => $direction, 'type' => $type,
            'message_key' => hash('sha256', 'whatsapp-message-v1:468336579702269:515388018324075:wamid.private-fixture-' . $request)];
    }
    function resetFixture(array $requests, array $messages = []): void {
        DB::$rows = ['whatsapp_reply_requests' => $requests, 'whatsapp_inbox_messages' => $messages,
            'whatsapp_inbox_conversations' => [
                ['id' => 7, 'waba_id' => '468336579702269', 'phone_number_id' => '515388018324075'],
                ['id' => 8, 'waba_id' => '468336579702269', 'phone_number_id' => '515388018324075'],
                ['id' => 9, 'waba_id' => 'another-waba', 'phone_number_id' => '515388018324075'],
                ['id' => 10, 'waba_id' => '468336579702269', 'phone_number_id' => 'another-phone'],
            ]];
        DB::$reads = [];
    }
    function readFeed(): array {
        global $recent, $service;
        return $recent->invoke($service, 7);
    }

    try {
        resetFixture([requestRow(1)]);
        $rows = readFeed();
        check(count($rows) === 1 && $rows[0]['text'] === 'Fixture accepted reply 1', 'accepted-without-echo-retained');
        check($rows[0]['source'] === 'dashboard_api', 'api-source-explicit');
        check($rows[0]['projected_message_id'] === null, 'missing-echo-is-null');
        check($rows[0]['accepted_at'] === '2026-10-11T22:01:09Z', 'acceptance-time-utc');
        check($rows[0]['state'] === 'ACCEPTED', 'acceptance-not-delivery');
        check(!str_contains(json_encode($rows), 'wamid.') && !str_contains(json_encode($rows), 'message_key'), 'native-identifiers-private');

        resetFixture([requestRow(1), requestRow(2)], [inboxRow(1, 100), inboxRow(2, 101)]);
        $rows = readFeed();
        check(array_column($rows, 'reply_id') === [1, 2], 'existing-chronological-order');
        check(array_column($rows, 'projected_message_id') === [100, 101], 'matching-outbound-copy-linked');
        check(count(array_filter(DB::$reads, static fn ($name) => $name === 'whatsapp_inbox_messages as m')) === 1, 'one-batch-lookup');

        foreach ([
            inboxRow(1, 200, 8), inboxRow(1, 201, 9), inboxRow(1, 202, 10),
            inboxRow(1, 203, 7, 'text', 'inbound'), inboxRow(1, 204, 7, 'unsupported'),
            inboxRow(1, 205, 7, 'audio'),
        ] as $mismatch) {
            resetFixture([requestRow(1)], [$mismatch]);
            check(readFeed()[0]['projected_message_id'] === null, 'unrelated-copy-does-not-hide-api-reply-' . $mismatch['id']);
        }
        foreach (['waba_id' => 'another-waba', 'phone_number_id' => 'another-phone'] as $field => $value) {
            resetFixture([requestRow(1)], [inboxRow(1, 207)]);
            DB::$rows['whatsapp_inbox_conversations'][0][$field] = $value;
            check(readFeed()[0]['projected_message_id'] === null, 'current-conversation-' . $field . '-scope-enforced');
        }
        $wrongNamespace = inboxRow(1, 206);
        $wrongNamespace['message_key'] = hash('sha256', 'whatsapp-reply-message-v1:468336579702269:515388018324075:wamid.private-fixture-1');
        resetFixture([requestRow(1)], [$wrongNamespace]);
        check(readFeed()[0]['projected_message_id'] === null, 'reply-hash-namespace-not-inbox-hash');

        resetFixture([requestRow(1, 'FAILED'), requestRow(2, 'UNKNOWN'), requestRow(3, 'CLAIMED')],
            [inboxRow(1, 300), inboxRow(2, 301), inboxRow(3, 302)]);
        $rows = readFeed();
        check(array_column($rows, 'state') === ['FAILED', 'UNKNOWN', 'UNKNOWN'], 'uncertain-states-preserved');
        check(array_column($rows, 'projected_message_id') === [null, null, null], 'only-accepted-lookup');
        check(array_column($rows, 'accepted_at') === [null, null, null], 'nonaccepted-has-no-acceptance-time');
        check(!in_array('whatsapp_inbox_messages as m', DB::$reads, true), 'no-dedupe-query-for-unaccepted');

        foreach ([null, 'bad-cipher', cipher(['message_id' => 'not-a-native-message']), cipher(['message_id' => 'wamid.' . str_repeat('x', 501)])] as $receipt) {
            $request = requestRow(1); $request['response_details'] = $receipt;
            resetFixture([$request], [inboxRow(1, 400)]);
            $rows = readFeed();
            check(count($rows) === 1 && $rows[0]['projected_message_id'] === null, 'unusable-receipt-retains-audit');
        }
        $audio = requestRow(1, 'ACCEPTED', 'audio');
        resetFixture([$audio], [inboxRow(1, 500, 7, 'audio')]);
        $rows = readFeed();
        check($rows[0]['text'] === null && $rows[0]['kind'] === 'audio' && $rows[0]['projected_message_id'] === 500, 'voice-audit-and-copy-preserved');
        $invalidDate = requestRow(1); $invalidDate['completed_at'] = '2026-02-31 22:01:09';
        resetFixture([$invalidDate]);
        check(readFeed()[0]['accepted_at'] === null, 'invalid-completion-date-not-normalized');

        $badAudit = requestRow(1); $badAudit['audit'] = 'bad-cipher';
        $otherConversation = requestRow(3, 'ACCEPTED', 'text', 8);
        $otherScope = requestRow(4); $otherScope['waba_id'] = 'another-waba';
        resetFixture([$badAudit, requestRow(2), $otherConversation, $otherScope]);
        check(array_column(readFeed(), 'reply_id') === [2], 'audit-decrypt-and-conversation-scope-preserved');

        $requests = [];
        for ($id = 1; $id <= 25; $id++) $requests[] = requestRow($id);
        resetFixture($requests);
        $before = serialize(DB::$rows); $rows = readFeed();
        check(count($rows) === 20 && $rows[0]['reply_id'] === 6 && $rows[19]['reply_id'] === 25, 'twenty-reply-bound-preserved');
        check(serialize(DB::$rows) === $before, 'read-side-does-not-mutate-state');
        echo 'WHATSAPP_REPLY_FEED_CHECKS=' . $checks . "\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, 'WHATSAPP_REPLY_FEED_TEST_FAILED:' . $error->getMessage() . "\n"); exit(1);
    }
}
