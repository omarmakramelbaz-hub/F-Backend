<?php
/** Real Symfony/Illuminate request fixture; no accounts, application boot or network. */
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
if (!is_string($autoload) || !is_file($autoload)) throw new RuntimeException('ISOLATED_TEST_VENDOR_REQUIRED');
require $autoload;

use App\Http\Middleware\MetaOAuthCallbackPrivacy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

function app($abstract = null) { $container = Container::getInstance(); return $abstract === null ? $container : $container->make($abstract); }
function config($key = null, $default = null) {
    if (is_array($key)) { foreach ($key as $name => $value) app('config')->set($name, $value); return null; }
    return $key === null ? app('config') : app('config')->get($key, $default);
}
$checks = 0;
function verifyPrivacy($condition, string $label): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException('META_OAUTH_PRIVACY: '.$label);
}
require __DIR__.'/../app/Http/Middleware/MetaOAuthCallbackPrivacy.php';

$container = new Container;
Container::setInstance($container);
$container->instance('config', new Repository(['debugbar' => ['enabled' => true]]));
$debugbar = new class { public $disabled = 0; public function disable() { $this->disabled++; } };
$container->instance('debugbar', $debugbar);
$nonce = str_repeat('a', 64);
$callbackPath = '/admin/meta/connection/callback';

function callbackRequest(string $query, string $name = 'meta-connection.callback'): Request {
    global $callbackPath;
    $request = Request::create('https://business.example.test'.$callbackPath.($query === '' ? '' : '?'.$query), 'GET');
    $request->headers->set('referer', 'https://business.example.test'.$callbackPath.'?code=REFERER_SECRET');
    $request->server->set('HTTP_REFERER', 'https://business.example.test'.$callbackPath.'?code=REFERER_SECRET');
    $route = (new Route(['GET'], ltrim($callbackPath, '/'), function () {}))->name($name);
    $request->setRouteResolver(function () use ($route) { return $route; });
    // Prime all relevant Symfony URI caches before the middleware executes.
    $request->getUri(); $request->getRequestUri(); $request->getQueryString(); $request->getPathInfo();
    $_GET = $request->query->all();
    $_REQUEST = array_merge($_GET, ['cookie_fixture' => 'preserved']);
    $_SERVER['QUERY_STRING'] = $query;
    $_SERVER['REQUEST_URI'] = $callbackPath.($query === '' ? '' : '?'.$query);
    $_SERVER['HTTP_REFERER'] = 'https://business.example.test'.$callbackPath.'?code=REFERER_SECRET';
    return $request;
}

function invokePrivacy(string $query, bool $invalid, ?string $code = null, bool $cancelled = false): void {
    global $nonce, $callbackPath;
    $middleware = new MetaOAuthCallbackPrivacy;
    $request = callbackRequest($query);
    $response = $middleware->handle($request, function (Request $sanitized) use ($middleware, $invalid, $code, $cancelled, $nonce, $callbackPath) {
        verifyPrivacy($sanitized->query->all() === [], 'callback query bag removed');
        verifyPrivacy($sanitized->getQueryString() === null, 'cached query string removed');
        verifyPrivacy($sanitized->getRequestUri() === $callbackPath, 'cached request URI removed');
        verifyPrivacy($sanitized->getUri() === 'https://business.example.test'.$callbackPath, 'cached full URI removed');
        verifyPrivacy(!$sanitized->headers->has('referer') && !$sanitized->server->has('HTTP_REFERER'), 'referer removed from header and server bag');
        verifyPrivacy($_GET === [] && $_SERVER['QUERY_STRING'] === '' && $_SERVER['REQUEST_URI'] === $callbackPath, 'PHP URL superglobals scrubbed');
        verifyPrivacy(!array_key_exists('state', $_REQUEST) && !array_key_exists('code', $_REQUEST)
            && !array_key_exists('error', $_REQUEST), 'PHP request superglobal carries no OAuth inputs');
        verifyPrivacy(!isset($_SERVER['HTTP_REFERER']), 'PHP referer superglobal removed');
        verifyPrivacy(app(MetaOAuthCallbackPrivacy::class) === $middleware, 'controller receives this request instance');
        $payload = $middleware->consume();
        verifyPrivacy(($payload['invalid'] ?? null) === $invalid, 'callback validity');
        if (!$invalid) verifyPrivacy($payload === ['invalid' => false, 'state' => $nonce, 'code' => $code, 'cancelled' => $cancelled], 'bounded private payload');
        verifyPrivacy($middleware->consume() === ['invalid' => true], 'private payload consumed once');
        return new Response('CLEAN_RESPONSE', 303, ['Location' => '/admin/meta/connection']);
    });
    verifyPrivacy($middleware->consume() === ['invalid' => true], 'no callback data remains after request');
    verifyPrivacy(config('debugbar.enabled') === false, 'debugbar config disabled');
    verifyPrivacy(str_contains($response->headers->get('Cache-Control'), 'no-store'), 'response not stored');
    verifyPrivacy($response->headers->get('Referrer-Policy') === 'no-referrer', 'response prevents referrer forwarding');
    verifyPrivacy($response->headers->get('X-Content-Type-Options') === 'nosniff', 'response content sniffing blocked');
    verifyPrivacy($response->headers->get('X-Robots-Tag') === 'noindex, nofollow', 'connection excluded from indexing');
    verifyPrivacy(!str_contains($response->getContent().$response->headers->get('Location'), 'SECRET'), 'callback credentials never enter response');
}

invokePrivacy('state='.$nonce.'&code=CODE_SECRET', false, 'CODE_SECRET');
invokePrivacy('state='.$nonce.'&error=access_denied&error_description=PRIVATE_DESCRIPTION', false, null, true);
invokePrivacy('state='.$nonce.'&code=CODE_SECRET&unused=OTHER_SECRET', false, 'CODE_SECRET');
foreach ([
    'code=CODE_SECRET',
    'state=bad&code=CODE_SECRET',
    'state[]='.$nonce.'&code=CODE_SECRET',
    'state='.$nonce.'&code[]=CODE_SECRET',
    'state='.$nonce.'&code=',
    'state='.$nonce.'&code='.str_repeat('b', 4097),
    'state='.$nonce.'&error[]='.rawurlencode('access_denied'),
    'state='.$nonce.'&error='.str_repeat('c', 129),
    'state='.$nonce.'&code=FIRST_SECRET&code=SECOND_SECRET',
    'state='.$nonce.'&code=FIRST_SECRET&%63ode=SECOND_SECRET',
    'state='.$nonce.'&state='.$nonce.'&code=CODE_SECRET',
    'state='.$nonce.'&error=one&error=two',
    'state='.$nonce.'&code=CODE_SECRET&padding='.str_repeat('d', 12000),
] as $query) invokePrivacy($query, true);

// A downstream failure must discard the payload and leave the code-bearing browser URL.
$middleware = new MetaOAuthCallbackPrivacy;
$failureResponse = $middleware->handle(callbackRequest('state='.$nonce.'&code=CODE_SECRET'), function () { throw new RuntimeException('PRIVATE_EXCEPTION_SECRET'); });
verifyPrivacy($failureResponse->getStatusCode() === 303 && $failureResponse->headers->get('Location') === '/admin/meta/connection', 'unexpected callback failure redirects to a clean path');
verifyPrivacy(!str_contains($failureResponse->getContent().json_encode($failureResponse->headers->all()), 'PRIVATE_EXCEPTION_SECRET'), 'unexpected exception never rendered');
verifyPrivacy(str_contains($failureResponse->headers->get('Cache-Control'), 'no-store')
    && $failureResponse->headers->get('Referrer-Policy') === 'no-referrer', 'failure redirect receives private headers');
verifyPrivacy($middleware->consume() === ['invalid' => true], 'finally discards callback data after downstream failure');

// On a regular connection page there is no callback payload and normal parameters survive.
$request = callbackRequest('view=help', 'meta-connection.index');
$middleware = new MetaOAuthCallbackPrivacy;
$middleware->handle($request, function (Request $normal) use ($middleware) {
    verifyPrivacy($normal->query('view') === 'help', 'non-callback query untouched');
    verifyPrivacy($middleware->consume() === ['invalid' => true], 'non-callback contains no private payload');
    return new Response('INDEX');
});
verifyPrivacy($debugbar->disabled >= 17, 'debugbar disabled on every connection request');
try {
    $middleware->handle(callbackRequest('view=help', 'meta-connection.index'), function () { throw new RuntimeException('NORMAL_PAGE_FAILURE'); });
    verifyPrivacy(false, 'non-callback failure propagated');
} catch (RuntimeException $error) { verifyPrivacy($error->getMessage() === 'NORMAL_PAGE_FAILURE', 'non-callback failure keeps normal error handling'); }
echo 'META_OAUTH_PRIVACY_PASS '.$checks." checks: cached URL scrubbing, PHP globals, one-time payload, malformed inputs, debugbar and private headers\n";
