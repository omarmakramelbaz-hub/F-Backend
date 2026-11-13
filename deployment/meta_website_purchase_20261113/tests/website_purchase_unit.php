<?php
/** Standalone fake-boundary tests: no Laravel bootstrap, network, orders or customer records. */
namespace Illuminate\Http {
    class Request {
        public function __construct(public array $headers = [], public array $cookies = [], public string $ua = 'Checkout browser/1.0') {}
        public function header($key) { return $this->headers[$key] ?? null; }
        public function cookie($key) { return $this->cookies[$key] ?? null; }
        public function userAgent() { return $this->ua; }
    }
}
namespace Illuminate\Support\Facades {
    class DB {
        public static array $tables = [];
        public static int $locks = 0;
        public static function table($table) { return new \FakeQuery($table); }
        public static function transaction($callback, $attempts = 1) { return $callback(); }
    }
    class Schema { public static function hasTable($table) { return array_key_exists($table, DB::$tables); } }
    class Crypt {
        public static function encryptString($value) { return 'encrypted:'.base64_encode($value); }
        public static function decryptString($value) {
            if (!str_starts_with($value, 'encrypted:')) throw new \RuntimeException('Invalid cipher');
            return base64_decode(substr($value, 10), true);
        }
    }
    class Log {
        public static array $warnings = [];
        public static function warning($message, $context) { self::$warnings[] = [$message, $context]; }
    }
    class Http {
        public static array $responses = [];
        public static array $requests = [];
        public static function withToken($token) { return new \FakeClient; }
    }
}
namespace {
    use App\Services\Site\WebsiteMetaPurchase;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\{Crypt, DB, Http, Log};

    class FakeClock extends DateTimeImmutable {
        public function __toString(): string { return $this->format('Y-m-d H:i:s'); }
        public function addSeconds($seconds): self { return $this->modify('+'.$seconds.' seconds'); }
    }
    function now($zone = null) { return new FakeClock('now', new DateTimeZone('UTC')); }
    $configuration = [
        'services.meta_conversions.enabled' => true,
        'services.meta_conversions.dataset_id' => '1183070883497428',
        'services.meta_conversions.access_token' => 'fixture-token-never-logged',
        'services.meta_conversions.api_version' => 'v24.0',
    ];
    function config($key, $default = null) { global $configuration; return $configuration[$key] ?? $default; }
    class FakeQuery {
        private array $filters = [];
        private int $maximum = PHP_INT_MAX;
        public function __construct(private string $table) {}
        public function where($field, $operator, $value = null): self {
            if (func_num_args() === 2) { $value = $operator; $operator = '='; }
            $value = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
            $this->filters[] = fn($row) => match($operator) {
                '=' => ($row[$field] ?? null) == $value,
                '<=' => ($row[$field] ?? null) <= $value,
                default => throw new RuntimeException('Unsupported test query operator'),
            };
            return $this;
        }
        public function whereNull($field): self { $this->filters[] = fn($row) => ($row[$field] ?? null) === null; return $this; }
        public function whereNotNull($field): self { $this->filters[] = fn($row) => ($row[$field] ?? null) !== null; return $this; }
        public function orderBy($field): self { return $this; }
        public function limit($maximum): self { $this->maximum = $maximum; return $this; }
        public function lockForUpdate(): self { DB::$locks++; return $this; }
        private function rows(): array {
            if (!array_key_exists($this->table, DB::$tables)) throw new RuntimeException('Missing fixture table');
            $rows = DB::$tables[$this->table];
            foreach ($this->filters as $filter) $rows = array_filter($rows, $filter);
            return array_slice($rows, 0, $this->maximum, true);
        }
        public function first($columns = ['*']) {
            $rows = $this->rows();
            $row = reset($rows);
            if ($row === false) return null;
            if ($columns !== ['*']) $row = array_intersect_key($row, array_flip($columns));
            return (object) $row;
        }
        public function pluck($field): array { return array_column($this->rows(), $field); }
        public function count(): int { return count($this->rows()); }
        public function max($field) { $values = array_filter(array_column($this->rows(), $field)); return $values ? max($values) : null; }
        public function insertOrIgnore(array $data): void {
            if (!array_key_exists($this->table, DB::$tables)) throw new RuntimeException('Missing fixture table');
            foreach (DB::$tables[$this->table] as $row) if ($row['ticket_id'] === $data['ticket_id'] || $row['event_id'] === $data['event_id']) return;
            foreach ($data as &$value) if ($value instanceof DateTimeInterface) $value = $value->format('Y-m-d H:i:s');
            unset($value);
            $data += ['id' => count(DB::$tables[$this->table]) + 1, 'sent_at' => null, 'failed_at' => null];
            DB::$tables[$this->table][] = $data;
        }
        public function update(array $changes): void {
            foreach ($changes as &$value) if ($value instanceof DateTimeInterface) $value = $value->format('Y-m-d H:i:s');
            unset($value);
            foreach ($this->rows() as $index => $row) DB::$tables[$this->table][$index] = array_replace($row, $changes);
        }
    }
    class FakeResponse {
        public function __construct(private int $code, private $received) {}
        public function status(): int { return $this->code; }
        public function json($key = null) { return $this->received; }
    }
    class FakeClient {
        public function asJson(): self { return $this; }
        public function connectTimeout($seconds): self { check($seconds <= 2, 'Connection timeout bounded'); return $this; }
        public function timeout($seconds): self { check($seconds <= 4, 'HTTP timeout bounded'); return $this; }
        public function post($url, $payload) {
            Http::$requests[] = compact('url', 'payload');
            $response = array_shift(Http::$responses);
            if ($response instanceof Throwable) throw $response;
            if (!$response) throw new RuntimeException('Unexpected HTTP request in test');
            return $response;
        }
    }
    $checks = 0;
    function check($condition, $label): void {
        global $checks; $checks++;
        if (!$condition) throw new RuntimeException($label);
    }
    require __DIR__.'/../payload/app/Services/Site/WebsiteMetaPurchase.php';

    $event = WebsiteMetaPurchase::event(91, 1794590000, 275.25, '٠١٠١٢٣٤٥٦٧٨', 'Real browser/1.0', [
        'fbp' => 'fb.1.1794590000000.123456', 'fbc' => 'fb.1.1794590000000.RealClickID_123',
    ]);
    check($event['action_source'] === 'website' && $event['event_source_url'] === 'https://fasakhaninja.com/', 'Truthful canonical website source');
    check($event['user_data']['ph'] === [hash('sha256', '201012345678')], 'Egyptian phone normalized and hashed once');
    check($event['user_data']['client_user_agent'] === 'Real browser/1.0', 'Actual browser user agent preserved');
    check($event['user_data']['fbc'] === 'fb.1.1794590000000.RealClickID_123', 'Real attribution cookie stays unhashed');
    check($event['custom_data'] === ['currency'=>'EGP','value'=>275.25,'order_id'=>'WEB-91'], 'Persisted monetary total and website order ID');
    check($event === WebsiteMetaPurchase::event(91, 1794590000, 275.25, '+20 10 1234 5678', 'Real browser/1.0', [
        'fbp'=>'fb.1.1794590000000.123456','fbc'=>'fb.1.1794590000000.RealClickID_123']), 'Stable payload and event ID across normalization/retry');
    check(WebsiteMetaPurchase::event(1, 1, 10, '01012345678', '') === null, 'Missing actual UA never fabricated');
    check(WebsiteMetaPurchase::event(1, 1, 0, '01012345678', 'UA') === null, 'Invalid order amount rejected');
    check(WebsiteMetaPurchase::event(1, 1, INF, '01012345678', 'UA') === null, 'Nonfinite amount rejected');
    check(WebsiteMetaPurchase::event(1, 1, 10, '02012345678', 'UA') === null, 'Invalid phone rejected');
    $clean = WebsiteMetaPurchase::event(1, 1, 10, '01012345678', 'UA', ['fbp'=>'invented','fbc'=>'private?query=value']);
    check(!isset($clean['user_data']['fbp'], $clean['user_data']['fbc']), 'Invalid attribution identifiers omitted');
    check(WebsiteMetaPurchase::accepted(200, 1) && !WebsiteMetaPurchase::accepted(200, 0)
        && !WebsiteMetaPurchase::accepted(400, 1) && !WebsiteMetaPurchase::accepted(200, '1'), 'Only explicit successful accepted-event count counts');

    function fixture(): void {
        DB::$tables = [
            'web_storefront_orders' => [['ticket_id'=>10,'branch_id'=>1,'created_at'=>gmdate('Y-m-d H:i:s', time()-1)]],
            'pos_service_tickets' => [['id'=>10,'branch'=>'f:1','channel'=>'phone','customer_phone'=>'01012345678','quote_snapshot'=>'{"total":"150.25"}']],
            'meta_website_purchases' => [],
        ];
        Http::$requests = []; Http::$responses = []; Log::$warnings = []; DB::$locks = 0;
    }
    $service = new WebsiteMetaPurchase;
    fixture();
    $service->enqueue(10, 1, new Request(['Sec-GPC'=>'1']));
    $service->enqueue(10, 1, new Request(['DNT'=>'1']));
    check(DB::$tables['meta_website_purchases'] === [], 'Both browser opt-out signals respected');
    $service->enqueue(10, 2, new Request);
    check(DB::$tables['meta_website_purchases'] === [], 'Wrong branch cannot create website conversion');
    unset(DB::$tables['web_storefront_orders'][0]);
    $service->enqueue(10, 1, new Request);
    check(DB::$tables['meta_website_purchases'] === [], 'POS ticket without website receipt is excluded');
    fixture();
    $service->enqueue(10, 1, new Request);
    check(count(DB::$tables['meta_website_purchases']) === 1 && Http::$requests === [], 'Checkout only writes encrypted outbox, never sends synchronously');
    $stored = DB::$tables['meta_website_purchases'][0];
    check(!str_contains($stored['payload'], '01012345678') && str_starts_with($stored['payload'], 'encrypted:'), 'Outbox payload passed through encryption');
    $service->enqueue(10, 1, new Request([], [], 'Other browser'));
    check(DB::$tables['meta_website_purchases'][0]['payload'] === $stored['payload'], 'Unique ticket retains original browser context');
    Http::$responses = [new FakeResponse(200, 1)];
    check($service->drain()['sent'] === 1 && DB::$locks === 1, 'Outbox dispatch uses row lock and acknowledged response');
    $service->drain();
    check(count(Http::$requests) === 1 && DB::$tables['meta_website_purchases'][0]['sent_at'] !== null, 'Successful event is not sent again');
    $readiness = $service->readiness();
    check($readiness['sent_count'] === 1 && $readiness['pending_count'] === 0
        && !str_contains(json_encode($readiness), 'fixture-token'), 'Read-only readiness exposes aggregate counts and no secret');
    $currentSentAt = $readiness['last_sent_at'];
    $foreign = DB::$tables['meta_website_purchases'][0];
    $foreign['dataset_id'] = '999999999999';
    $foreign['sent_at'] = '2099-01-01 00:00:00';
    DB::$tables['meta_website_purchases'][] = $foreign;
    $foreign['sent_at'] = null;
    DB::$tables['meta_website_purchases'][] = $foreign;
    $foreign['failed_at'] = '2099-01-01 00:00:00';
    DB::$tables['meta_website_purchases'][] = $foreign;
    $readiness = $service->readiness();
    check($readiness['pending_count'] === 0 && $readiness['sent_count'] === 1
        && $readiness['failed_count'] === 0 && $readiness['last_sent_at'] === $currentSentAt,
        'Readiness aggregates exclude every other dataset');
    check($readiness['test_event_code_configured'] === false, 'Production readiness identifies absent test code');
    $configuration['services.meta_conversions.test_event_code'] = 'fixture-test-code-never-exposed';
    $readiness = $service->readiness();
    check($readiness['test_event_code_configured'] === true
        && !str_contains(json_encode($readiness), 'fixture-test-code'), 'Readiness exposes test-mode boolean without its value');
    unset($configuration['services.meta_conversions.test_event_code']);

    fixture();
    $service->enqueue(10, 1, new Request(['Cookie' =>
        'laravel_session=session-do-not-share; _fbp=fb%2E1%2E1794590000000%2E123456; auth_token=auth-do-not-share; _fbc=fb.1.1794590000000.RealClickID_123']));
    $cookiePayload = json_decode(Crypt::decryptString(DB::$tables['meta_website_purchases'][0]['payload']), true);
    $cookieUser = $cookiePayload['data'][0]['user_data'];
    check($cookieUser['fbp'] === 'fb.1.1794590000000.123456'
        && $cookieUser['fbc'] === 'fb.1.1794590000000.RealClickID_123', 'Named raw-cookie fallback survives EncryptCookies removing plaintext Meta cookies');
    check(!str_contains(json_encode($cookiePayload), 'session-do-not-share')
        && !str_contains(json_encode($cookiePayload), 'auth-do-not-share')
        && !isset($cookieUser['laravel_session'], $cookieUser['auth_token']), 'Unrelated private cookies never enter payload');
    fixture();
    $service->enqueue(10, 1, new Request(['Cookie' => '_fbp=fb%252E1%252E1794590000000%252E123456; _fbc=invalid-cookie-value']));
    $cookiePayload = json_decode(Crypt::decryptString(DB::$tables['meta_website_purchases'][0]['payload']), true);
    check(!isset($cookiePayload['data'][0]['user_data']['fbp'], $cookiePayload['data'][0]['user_data']['fbc']),
        'Raw cookie values are decoded once and invalid values omitted');
    fixture();
    $service->enqueue(10, 1, new Request(['Cookie' => '_fbp=fb.1.1794590000000.999999'], ['_fbp' => 'fb.1.1794590000000.111111']));
    $cookiePayload = json_decode(Crypt::decryptString(DB::$tables['meta_website_purchases'][0]['payload']), true);
    check($cookiePayload['data'][0]['user_data']['fbp'] === 'fb.1.1794590000000.111111', 'Normal cookie getter takes precedence over raw-header fallback');

    fixture(); $service->enqueue(10, 1, new Request);
    Http::$responses = [new FakeResponse(200, 0)];
    check($service->drain()['retry'] === 1 && DB::$tables['meta_website_purchases'][0]['sent_at'] === null, 'HTTP 200 without accepted event remains unsent');
    $firstPayload = Http::$requests[0]['payload'];
    DB::$tables['meta_website_purchases'][0]['next_attempt_at'] = gmdate('Y-m-d H:i:s', time()-1);
    Http::$responses = [new FakeResponse(200, 1)];
    check($service->drain()['sent'] === 1 && Http::$requests[1]['payload'] === $firstPayload, 'Retry reuses exact original event time, ID, amount and browser context');

    fixture(); $service->enqueue(10, 1, new Request);
    $configuration['services.meta_conversions.dataset_id'] = '999999999999';
    check($service->drain()['sent'] === 0 && Http::$requests === []
        && DB::$tables['meta_website_purchases'][0]['sent_at'] === null, 'Config change never redirects events to another dataset');
    $configuration['services.meta_conversions.dataset_id'] = '1183070883497428';
    DB::$tables['meta_website_purchases'][0]['payload'] = 'corrupt';
    check($service->drain()['failed'] === 1 && Http::$requests === [], 'Corrupt payload is terminal without sending');

    fixture(); $service->enqueue(10, 1, new Request);
    DB::$tables['meta_website_purchases'][0]['event_time'] = time()-604801;
    check($service->drain()['failed'] === 1 && Http::$requests === [], 'Events older than Meta window are not backfilled');

    fixture(); $service->enqueue(10, 1, new Request);
    Http::$responses = [new RuntimeException('transport details must never be logged')];
    check($service->drain()['retry'] === 1 && DB::$tables['meta_website_purchases'][0]['last_error_code'] === 'transport_error', 'Transport failure remains retryable without leaking exception details');
    DB::$tables['meta_website_purchases'][0]['next_attempt_at'] = gmdate('Y-m-d H:i:s', time()-1);
    DB::$tables['meta_website_purchases'][0]['attempts'] = 7;
    Http::$responses = [new FakeResponse(503, null)];
    check($service->drain()['failed'] === 1 && DB::$tables['meta_website_purchases'][0]['attempts'] === 8
        && DB::$tables['meta_website_purchases'][0]['failed_at'] !== null, 'Attempts are bounded at eight');
    fixture(); unset(DB::$tables['meta_website_purchases']);
    $service->enqueue(10, 1, new Request);
    check(Log::$warnings === [['Meta website Purchase tracking',['category'=>'enqueue_failed']]], 'Tracking storage failure is swallowed with sanitized log');
    echo 'WEBSITE_META_PURCHASE_TESTS_PASSED '.$checks.PHP_EOL;
}
