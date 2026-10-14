<?php
namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_dir($autoload)) $autoload .= '/autoload.php';
    require $autoload;
    class TestCommentsContainer extends \Illuminate\Container\Container { public function getLocale() { return 'en'; } }
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function auth($guard = null) { return new class { public function user() { return new \stdClass; } }; }
    function response() { return new class { public function json($body, $status = 200) { return new \Illuminate\Http\JsonResponse($body, $status); } }; }
    set_error_handler(function ($s, $m, $f, $l) { if ($s & E_ALL) throw new \ErrorException($m, 0, $s, $f, $l); return false; });
}
namespace App\Http\Controllers { class Controller extends \Illuminate\Routing\Controller {} }
namespace {
    use App\Http\Controllers\Dashboard\MetaCommentsController;
    use App\Services\Dashboard\MetaCommentsFailure;
    use App\Services\Dashboard\MetaCommentsService;
    use Illuminate\Container\Container;
    use Illuminate\Http\Request;
    use Illuminate\Translation\ArrayLoader;
    use Illuminate\Translation\Translator;
    use Illuminate\Validation\Factory;
    use Illuminate\Validation\ValidationException;
    $c = new TestCommentsContainer; Container::setInstance($c);
    $c->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $c));
    Request::macro('validate', function ($rules) {
        $validator = app('validator')->make($this->all(), $rules);
        if ($validator->fails()) throw new ValidationException($validator);
        return $validator->validated();
    });
    require __DIR__ . '/../app/Services/Dashboard/MetaCommentsFailure.php';
    require __DIR__ . '/../app/Services/Dashboard/MetaCommentsService.php';
    require __DIR__ . '/../app/Http/Controllers/Dashboard/MetaCommentsController.php';
    class FakeCommentsService extends MetaCommentsService {
        public $callback; public int $calls = 0;
        public function __construct(callable $callback) { $this->callback = $callback; }
        public function send($actor, array $input): array { $this->calls++; return ($this->callback)($input); }
    }
    class FakeCommentsReadService extends MetaCommentsService {
        public int $calls = 0;
        public function __construct() {}
        public function posts($actor, string $accountKey, ?string $cursor = null): array {
            $this->calls++;
            return ['success' => true, 'posts' => [], 'next_cursor' => null];
        }
    }
    $checks = 0;
    function check($ok, $label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function sendTest(callable $callback, ?array $input = null): array {
        $input = $input ?? ['comment_handle' => 'fixture-handle', 'message' => 'Thanks', 'client_request_id' => '12345678-1234-4234-8234-123456789abc'];
        $service = new FakeCommentsService($callback);
        $response = (new MetaCommentsController)->reply(Request::create('/admin/meta/comments/reply', 'POST', $input), $service);
        check(strpos($response->headers->get('Cache-Control'), 'no-store') !== false, 'private no-store response');
        check($response->headers->get('Referrer-Policy') === 'no-referrer'
            && $response->headers->get('X-Content-Type-Options') === 'nosniff', 'privacy and content headers');
        check(preg_match('/\Areply;dur=[0-9]+\.[0-9]\z/', $response->headers->get('Server-Timing')) === 1,
            'success and failures carry sanitized numeric reply latency');
        return [$response->getStatusCode(), json_decode($response->getContent(), true), $service->calls];
    }
    [$status, $body] = sendTest(function () { throw new MetaCommentsFailure('PERMISSION_REQUIRED', 403, ['pages_manage_engagement']); });
    check($status === 403 && $body['state'] === 'FAILED' && $body['safe_to_retry'] === true, 'known pre-send scope error explicitly safe');
    check($body['required_permissions'] === ['pages_manage_engagement'] && strpos($body['message'], 'Grant') !== false, 'actionable scope names preserved');
    [$status, $body] = sendTest(function () { throw new MetaCommentsFailure('INVALID_HANDLE', 422); });
    check($status === 422 && $body['state'] === 'FAILED' && $body['safe_to_retry'], 'expired selection is safe pre-send failure');
    [$status, $body] = sendTest(function () { throw new MetaCommentsFailure('REPLY_IN_PROGRESS', 409); });
    check($status === 409 && $body['state'] === 'FAILED' && $body['safe_to_retry']
        && strpos($body['message'], 'being sent') !== false, 'another active reply is known unsent for this caller and never invents an uncertain receipt');
    [$status, $body, $calls] = sendTest(fn () => [], ['comment_handle' => 'fixture-handle', 'client_request_id' => '12345678-1234-4234-8234-123456789abc']);
    check($status === 422 && $body['state'] === 'FAILED' && $calls === 0 && $body['safe_to_retry'], 'request validation failure never sends or blocks target');
    [$status, $body, $calls] = sendTest(fn () => [], ['comment_handle' => 'fixture', 'message' => 'Thanks',
        'client_request_id' => '12345678-1234-4234-8234-123456789abc', 'account_id' => '999']);
    check($status === 422 && $body['state'] === 'FAILED' && $calls === 0, 'unexpected account override rejected before service');
    [$status, $body] = sendTest(function () { throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'secret role detail'); });
    check($status === 403 && $body['state'] === 'FAILED' && $body['safe_to_retry'], 'central access denial safe before send');
    [$status, $body, $calls] = sendTest(function () { throw new \RuntimeException('private-token Graph message customer text'); });
    check($status === 202 && $body['state'] === 'UNKNOWN' && !$body['safe_to_retry'] && $calls === 1, 'unexpected send/storage failure remains uncertain');
    check($body['reason'] === 'DELIVERY_UNKNOWN' && strpos(json_encode($body), 'private-token') === false, 'raw exception and content are sanitized');
    [$status, $body] = sendTest(fn () => ['success' => false, 'state' => 'UNKNOWN', 'reason' => 'DELIVERY_UNKNOWN', 'reply' => null]);
    check($status === 202 && $body['state'] === 'UNKNOWN' && !isset($body['safe_to_retry']), 'durable uncertain service receipt stays 202 without safe flag');
    [$status, $body] = sendTest(fn () => ['success' => true, 'state' => 'ACCEPTED', 'reason' => null,
        'reply' => ['text' => 'Thanks', 'key' => 'opaque-key'], 'replayed' => false]);
    check($status === 200 && $body['success'] && $body['reply']['text'] === 'Thanks', 'accepted reply returned immediately');
    $readService = new FakeCommentsReadService;
    $read = (new MetaCommentsController)->posts(Request::create('/admin/meta/comments/posts', 'GET', ['account_key' => str_repeat('a', 64)]), $readService);
    check($read->getStatusCode() === 200 && $readService->calls === 1, 'GET posts request executes once');
    check(preg_match('/\Aread;dur=[0-9]+\.[0-9](?:, [a-z_]+;dur=[0-9]+\.[0-9])*\z/', $read->headers->get('Server-Timing')) === 1,
        'read latency exposes fixed numeric metrics without account IDs or data');
    check(strpos($read->headers->get('Cache-Control'), 'no-store') !== false, 'provider snapshot caching never enables shared HTTP caching');
    echo "meta-comments-controller: $checks checks passed\n";
}
