<?php
/** Isolated checkout behavior checks: no Laravel boot, network, or real records. */
namespace Illuminate\Http {
    class Request {
        public function __construct(private array $input, private object $session) {}
        public function all(): array { return $this->input; }
        public function session(): object { return $this->session; }
    }
}
namespace Illuminate\Support\Facades {
    class Validator {
        public static function make(array $input, array $rules): object {
            return new class($input) {
                public function __construct(private array $input) {}
                public function validate(): array { return $this->input; }
            };
        }
    }
    class Crypt { public static function decryptString(string $value): string { return $value; } }
    class Schema { public static function hasTable(string $table): bool { return true; } }
    class Log { public static function warning(...$args): void { \HookState::$warnings++; } }
    class DB {
        public static function table(string $table): \HookQuery { return new \HookQuery($table); }
        public static function transactionLevel(): int { return \HookState::$level; }
        public static function transaction(callable $callback, int $attempts = 1) {
            $original = [\HookState::$receipts, \HookState::$tickets];
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                \HookState::$level = 1;
                try {
                    $result = $callback();
                    if (\HookState::$mode === 'fail') throw new \RuntimeException('simulated commit failure');
                    if ($attempt === 1 && in_array(\HookState::$mode, ['retry', 'retry_replay'], true)) {
                        [\HookState::$receipts, \HookState::$tickets] = $original;
                        if (\HookState::$mode === 'retry_replay') \HookState::seedReceipt(99);
                        continue;
                    }
                    \HookState::$level = 0;
                    return $result;
                } catch (\Throwable $error) {
                    [\HookState::$receipts, \HookState::$tickets] = $original;
                    \HookState::$level = 0;
                    throw $error;
                }
            }
            throw new \RuntimeException('exhausted simulated retries');
        }
    }
}
namespace App\Services\Dashboard {
    class PosServiceTicket {
        public function save(string $channel, array $values, $actor): array {
            \HookState::$saves++;
            $id = \HookState::$nextTicket++;
            \HookState::seedTicket($id);
            return ['ticket' => ['id' => $id]];
        }
    }
}
namespace {
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;

    final class HookState {
        public static array $receipts = [], $tickets = [], $calls = [];
        public static int $level = 0, $saves = 0, $nextTicket = 17, $warnings = 0;
        public static string $mode = '', $token = '';
        public static bool $enqueueThrows = false;
        public static function reset(string $mode = ''): Request {
            self::$receipts = self::$tickets = self::$calls = [];
            self::$level = self::$saves = self::$warnings = 0;
            self::$nextTicket = 17;
            self::$mode = $mode;
            self::$enqueueThrows = false;
            $context = ['nonce' => str_repeat('a', 32), 'customer_phone' => '01000000000',
                'address' => 'Synthetic test address', 'area' => 'Test area', 'latitude' => 31.0, 'longitude' => 31.0];
            $session = new class($context) {
                public function __construct(private array $context) {}
                public function getId(): string { return 'synthetic-browser-session'; }
                public function get(string $key) { return $this->context; }
            };
            self::$token = json_encode(['v' => 2,
                'session' => hash_hmac('sha256', $session->getId(), config('app.key')),
                'context' => hash_hmac('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), config('app.key')),
                'expires' => time() + 600,
                'values' => ['branch_id' => 3, 'notes' => '', 'customer_phone' => $context['customer_phone']]], JSON_THROW_ON_ERROR);
            return new Request(['token' => self::$token, 'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa'], $session);
        }
        public static function seedTicket(int $id): void {
            self::$tickets[$id] = (object)['id' => $id, 'branch' => 'f:3', 'status' => 'new',
                'quote_snapshot' => json_encode(['total' => '371.25'])];
        }
        public static function seedReceipt(int $id): void {
            self::seedTicket($id);
            self::$receipts[] = (object)['session_hash' => hash_hmac('sha256', 'synthetic-browser-session', config('app.key')),
                'request_key' => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', 'payload_hash' => hash('sha256', self::$token),
                'ticket_id' => $id, 'branch_id' => 3];
        }
    }
    final class HookQuery {
        private array $conditions = [];
        public function __construct(private string $table) {}
        public function where($field, $value = null): self {
            if (is_callable($field)) {
                $group = new self($this->table); $field($group);
                $this->conditions[] = ['AND', fn($row) => $group->matches($row)];
            } else $this->conditions[] = ['AND', fn($row) => ($row->$field ?? null) === $value];
            return $this;
        }
        public function orWhere(string $field, $value): self {
            $this->conditions[] = ['OR', fn($row) => ($row->$field ?? null) === $value]; return $this;
        }
        public function whereNotNull(string $field): self { return $this; }
        public function lockForUpdate(): self { return $this; }
        public function matches(object $row): bool {
            $result = true;
            foreach ($this->conditions as [$join, $predicate]) $result = $join === 'OR' ? $result || $predicate($row) : $result && $predicate($row);
            return $result;
        }
        public function first(): ?object {
            $rows = $this->table === 'web_storefront_orders' ? HookState::$receipts : HookState::$tickets;
            foreach ($rows as $row) if ($this->matches($row)) return $row;
            return null;
        }
        public function get(array $columns): array {
            return [(object)['resturant_id' => 3, 'lat' => 31, 'lng' => 31, 'expected_delivery' => 10]];
        }
        public function insert(array $row): void { HookState::$receipts[] = (object)$row; }
    }
    final class HookMetaRecorder {
        public function enqueue(int $ticket, int $branch, Request $request): void {
            expect(DB::transactionLevel() === 0, 'tracking ran before commit');
            expect(isset(HookState::$tickets[$ticket]), 'tracking used a rolled-back ticket');
            HookState::$calls[] = [$ticket, $branch];
            if (HookState::$enqueueThrows) throw new \RuntimeException('synthetic outbox failure');
        }
    }
    function config(string $key, $default = null) { return $key === 'app.key' ? 'synthetic-unit-key' : $default; }
    function now(...$args): \DateTimeImmutable { return new \DateTimeImmutable('2026-11-13T20:00:00Z'); }
    function app(string $class) {
        if ($class === 'App\\Services\\Site\\WebsiteMetaPurchase') return new HookMetaRecorder();
        if ($class === 'App\\Services\\Dashboard\\PosServiceTicket') return new \App\Services\Dashboard\PosServiceTicket();
        throw new \RuntimeException('unexpected service resolution: '.$class);
    }
    function abort_unless($condition, int $status, string $message = ''): void { if (!$condition) throw new \RuntimeException('abort '.$status); }
    function abort_if($condition, int $status, string $message = ''): void { abort_unless(!$condition, $status, $message); }
    function abort(int $status, string $message = ''): void { throw new \RuntimeException('abort '.$status); }
    function expect(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    require __DIR__.'/../payload/app/Services/Site/WebStorefront.php';
    final class HookStorefront extends \App\Services\Site\WebStorefront {
        protected function branch(int $id, bool $open = false, bool $lock = false): array {
            return [(object)['id' => 3, 'effective_status' => 'opened', 'lat' => 31, 'lng' => 31], (object)['id' => 8]];
        }
    }
    function expectedConfirmation(int $id): array {
        return ['success' => true, 'order' => ['number' => 'WEB-'.$id, 'status' => 'new', 'total' => '371.25',
            'payment' => 'cash_on_delivery', 'branch_id' => 3]];
    }
    $storefront = new HookStorefront();
    $request = HookState::reset();
    expect($storefront->checkout($request) === expectedConfirmation(17), 'fresh confirmation changed');
    expect(HookState::$calls === [[17, 3]] && HookState::$saves === 1, 'fresh order not tracked once');

    HookState::$calls = [];
    expect($storefront->checkout($request) === expectedConfirmation(17), 'replay confirmation changed');
    expect(HookState::$calls === [] && HookState::$saves === 1, 'replay created or tracked another order');

    $request = HookState::reset('fail');
    try { $storefront->checkout($request); throw new \LogicException('failed transaction unexpectedly succeeded'); }
    catch (\RuntimeException $error) { expect($error->getMessage() === 'simulated commit failure', 'wrong failure'); }
    expect(HookState::$calls === [] && HookState::$tickets === [], 'rolled-back order was tracked');

    $request = HookState::reset('retry');
    expect($storefront->checkout($request) === expectedConfirmation(18), 'retry confirmation changed');
    expect(HookState::$calls === [[18, 3]] && HookState::$saves === 2, 'retry tracked an uncommitted ticket');

    $request = HookState::reset('retry_replay');
    expect($storefront->checkout($request) === expectedConfirmation(99), 'concurrent replay confirmation changed');
    expect(HookState::$calls === [], 'stale fresh context survived a replay after retry');

    $request = HookState::reset(); HookState::$enqueueThrows = true;
    expect($storefront->checkout($request) === expectedConfirmation(17), 'tracking failure broke checkout');
    expect(count(HookState::$tickets) === 1, 'tracking failure lost the committed order');
    echo "WEBSITE_CHECKOUT_HOOK_TESTS_PASSED (6 scenarios)\n";
}
