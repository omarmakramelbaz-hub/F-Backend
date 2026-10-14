<?php
/** Real request/session/encryption fixtures; no application boot, database or Graph requests. */
namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
    if (!is_string($autoload) || !is_file($autoload)) throw new \RuntimeException('ISOLATED_TEST_VENDOR_REQUIRED');
    require $autoload;
}
namespace App\Http\Controllers { class Controller {} }
namespace App\Models {
    class User {
        public $id; public $account_type; public $owner_resturant_id; public $active; public $superAdmin;
        public function __construct($id = 1, $type = 'admin', $branch = null, $superAdmin = false, $active = true) {
            $this->id = $id; $this->account_type = $type; $this->owner_resturant_id = $branch; $this->superAdmin = $superAdmin; $this->active = $active;
        }
        public function getKey() { return $this->id; }
        public function roles() { return new class($this->superAdmin) {
            private $superAdmin; private $filters = [];
            public function __construct($allowed) { $this->superAdmin = $allowed; }
            public function where($key, $value) { $this->filters[$key] = $value; return $this; }
            public function exists() { return $this->superAdmin && $this->filters === ['guard_name' => 'admin', 'name' => 'Super Admin']; }
        }; }
    }
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public $persisted = []; public $reloads = 0;
        public function actor($actor) {
            $this->reloads++;
            \abort_unless($actor instanceof \App\Models\User && isset($this->persisted[$actor->id]), 403);
            $fresh = clone $this->persisted[$actor->id];
            \abort_unless($fresh->active, 403);
            return $fresh;
        }
    }
    class MetaOAuthCredentials {
        public $configured = true; public $fail = false;
        public $value = ['app_id' => '2177982783065133', 'config_id' => '3007667439572336',
            'redirect_uri' => 'https://business.example.test/admin/meta/connection/callback', 'api_version' => 'v25.0'];
        public function settings() { if (!$this->configured || $this->fail) throw new \RuntimeException('PRIVATE_SETTINGS_SECRET'); return $this->value; }
        public function status() { return ['configured' => $this->configured, 'credentials_available' => true,
            'accounts' => [['channel' => 'messenger', 'account_id' => '118160256722323', 'label' => 'Known Page',
                'access_token' => 'PRIVATE_SAVED_TOKEN', 'facebook_user_id' => 'PRIVATE_USER_ID']]]; }
        public function overlay($base) { return $base; }
    }
    class MetaOAuthService {
        public $calls = []; public $failure = false;
        public function authorizationUrl($state) { $this->calls[] = ['authorization', $state]; return 'https://www.facebook.com/v25.0/dialog/oauth?state='.$state; }
        public function discover($code) { $this->calls[] = ['discover', $code]; if ($this->failure) throw new \RuntimeException('PRIVATE_GRAPH_TOKEN'); return \pendingBundle(); }
        public function activate($pending, $selected) { $this->calls[] = ['activate', $pending, $selected]; if ($this->failure) throw new \RuntimeException('PRIVATE_GRAPH_TOKEN'); return []; }
    }
}
namespace {
    use App\Http\Controllers\Dashboard\MetaConnectionController;
    use App\Http\Middleware\MetaOAuthCallbackPrivacy;
    use App\Models\User;
    use App\Services\Dashboard\MetaInboxAccess;
    use App\Services\Dashboard\MetaOAuthCredentials;
    use App\Services\Dashboard\MetaOAuthService;
    use App\Services\Dashboard\TakeawayAccess;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Encryption\Encrypter;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\Response;
    use Illuminate\Routing\Route;
    use Illuminate\Session\ArraySessionHandler;
    use Illuminate\Session\Store;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\Facade;
    use Symfony\Component\HttpKernel\Exception\HttpException;

    class OAuthFixtureApplication extends Container { public function getLocale() { return 'en'; } }
    function app($abstract = null) { $container = Container::getInstance(); return $abstract === null ? $container : $container->make($abstract); }
    function config($key = null, $default = null) {
        if (is_array($key)) { foreach ($key as $name => $value) app('config')->set($name, $value); return null; }
        return $key === null ? app('config') : app('config')->get($key, $default);
    }
    function auth($guard = null) { return new class { public function user() { return $GLOBALS['sessionActor']; } }; }
    function abort_unless($condition, $status) { if (!$condition) throw new HttpException($status); }
    function abort_if($condition, $status) { if ($condition) throw new HttpException($status); }
    function route($name) { return 'https://business.example.test/'.(['meta-connection.index' => 'admin/meta/connection',
        'meta-connection.start' => 'admin/meta/connection/start', 'meta-connection.confirm' => 'admin/meta/connection/confirm',
        'meta-inbox.index' => 'admin/meta'][$name] ?? $name); }
    function response($body = null, $status = 200) {
        if ($body !== null) return new Response($body, $status);
        return new class { public function view($name, $data) { $GLOBALS['viewData'] = $data; return new Response(json_encode($data, JSON_THROW_ON_ERROR)); } };
    }
    function redirect() { return new class {
        public function away($url, $status = 302) { return new RedirectResponse($url, $status); }
        public function route($name, $parameters = [], $status = 302) { return new RedirectResponse(\route($name), $status); }
    }; }
    $checks = 0;
    function checkOAuth($condition, $label) { global $checks; $checks++; if (!$condition) throw new \RuntimeException('META_OAUTH_CONTROLLER: '.$label); }
    function pendingBundle() { return ['accounts' => [['channel' => 'messenger', 'account_id' => '118160256722323', 'page_id' => '118160256722323',
        'label' => 'Known Page', 'access_token' => 'PRIVATE_PENDING_TOKEN', 'login' => 'facebook']],
        'facebook_user_id' => 'PRIVATE_FACEBOOK_USER', 'authorized_at' => gmdate('Y-m-d\TH:i:s\Z')]; }

    $container = new OAuthFixtureApplication; Container::setInstance($container); Facade::setFacadeApplication($container);
    $container->instance('config', new Repository(['desktop_dashboard' => ['local' => false], 'debugbar' => ['enabled' => true],
        'meta_messaging' => ['accounts' => []]]));
    $container->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
    $policy = new TakeawayAccess;
    $policy->persisted = [1 => new User, 2 => new User(2), 3 => new User(3, 'admin', null, true),
        4 => new User(4, 'admin', 18, true), 5 => new User(5, 'vendor', null, true), 6 => new User(6, 'admin', null, true, false)];
    $credentials = new MetaOAuthCredentials; $service = new MetaOAuthService;
    $container->instance(TakeawayAccess::class, $policy);
    $container->instance(MetaOAuthCredentials::class, $credentials);
    $container->instance(MetaOAuthService::class, $service);
    foreach (['Services/Dashboard/WhatsAppInboxAccess', 'Services/Dashboard/MetaInboxAccess', 'Http/Middleware/MetaOAuthCallbackPrivacy',
        'Http/Controllers/Dashboard/MetaConnectionController'] as $file) require __DIR__.'/../app/'.$file.'.php';
    $controller = new MetaConnectionController; $access = new MetaInboxAccess;
    $GLOBALS['sessionActor'] = new User;
    $session = new Store('fixture', new ArraySessionHandler(120)); $session->start();
    $request = function ($method = 'GET', $values = [], $path = '/admin/meta/connection') use ($session) {
        $request = Request::create('https://business.example.test'.$path, $method, $values); $request->setLaravelSession($session); return $request;
    };
    $clean = function ($response, $notice) use ($session) {
        checkOAuth($response->getStatusCode() === 303 && $response->headers->get('Location') === route('meta-connection.index'), 'clean callback redirect');
        checkOAuth($session->get('meta_oauth.notice') === $notice, 'fixed notice '.$notice);
        checkOAuth(!str_contains($response->getContent().$response->headers->get('Location'), 'PRIVATE_'), 'no raw secret in redirect');
        checkOAuth(str_contains($response->headers->get('Cache-Control'), 'no-store') && $response->headers->get('Referrer-Policy') === 'no-referrer', 'private redirect headers');
    };
    $start = function () use ($controller, $access, $request, $session, $service) {
        $response = $controller->start($request('POST'), $access);
        checkOAuth($response->getStatusCode() === 303, 'start creates authorization redirect');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $state = $query['state'];
        checkOAuth(preg_match('/\A[a-f0-9]{64}\z/', $state) === 1, 'random strong state');
        $saved = $session->get('meta_oauth.state');
        checkOAuth($saved['hash'] === hash('sha256', $state) && $saved['actor_id'] === (string) $GLOBALS['sessionActor']->id, 'state bound to persisted owner');
        checkOAuth(!str_contains(json_encode($saved), $state), 'session stores state digest');
        checkOAuth(!$session->has('meta_oauth.pending'), 'restart discards earlier pending credentials');
        return $state;
    };
    $callback = function ($state, $code = 'PRIVATE_CALLBACK_CODE', $error = null) use ($controller, $access, $request) {
        $values = ['state' => $state]; if ($code !== null) $values['code'] = $code; if ($error !== null) $values['error'] = $error;
        $req = $request('GET', $values, '/admin/meta/connection/callback');
        $req->setRouteResolver(function () { return (new Route(['GET'], 'admin/meta/connection/callback', function () {}))->name('meta-connection.callback'); });
        return (new MetaOAuthCallbackPrivacy)->handle($req, function ($sanitized) use ($controller, $access) { return $controller->callback($sanitized, $access); });
    };

    // The actual policy reloads persisted roles; a forged session cannot grant ownership.
    checkOAuth($access->owner(new User(1))->id === 1 && $access->owner(new User(3))->id === 3, 'persisted primary owner and admin-guard Super Admin accepted');
    foreach ([null, new User(2, 'admin', null, true), new User(4), new User(5, 'admin', null, true), new User(6), new User(99)] as $actor) {
        checkOAuth(!$access->canConnect($actor), 'non-owner, branch, vendor, revoked or missing identity denied');
    }
    $GLOBALS['sessionActor'] = new User(2, 'admin', null, true);
    checkOAuth($controller->index($request(), $access, $credentials)->getStatusCode() === 403, 'controller denies forged session ownership');
    $GLOBALS['sessionActor'] = new User;
    config(['desktop_dashboard.local' => true]);
    checkOAuth(!$access->canConnect(new User) && $controller->start($request('POST'), $access)->getStatusCode() === 403, 'desktop cannot connect');
    config(['desktop_dashboard.local' => false]);

    $state = $start(); $clean($callback($state), 'review');
    checkOAuth(!$session->has('meta_oauth.state'), 'state consumed before Graph discovery');
    $ciphertext = $session->get('meta_oauth.pending');
    checkOAuth(is_string($ciphertext) && !str_contains($ciphertext, 'PRIVATE_PENDING_TOKEN'), 'pending credentials encrypted');
    $envelope = json_decode(Crypt::decryptString($ciphertext), true);
    checkOAuth($envelope['actor_id'] === '1' && $envelope['pending']['accounts'][0]['access_token'] === 'PRIVATE_PENDING_TOKEN', 'pending bound and encrypted intact');
    $response = $controller->index($request(), $access, $credentials);
    checkOAuth($response->getStatusCode() === 200 && $GLOBALS['viewData']['metaConnection']['has_pending'], 'pending review available');
    checkOAuth(!str_contains($response->getContent(), 'PRIVATE_'), 'view DTO excludes tokens and authorized Facebook user');
    checkOAuth(array_keys($GLOBALS['viewData']['metaConnection']['pending_accounts'][0]) === ['key', 'channel', 'account_id', 'label'], 'exact safe browser account fields');
    $clean($callback($state), 'expired');
    checkOAuth(!$session->has('meta_oauth.pending'), 'callback replay discards old pending');
    checkOAuth(count(array_filter($service->calls, function ($call) { return $call[0] === 'discover'; })) === 1, 'replay never repeats Graph discovery');

    foreach (['wrong-state', 'expired', 'future', 'different-actor', 'changed-context', 'missing-code', 'cancelled'] as $case) {
        $state = $start(); $saved = $session->get('meta_oauth.state'); $before = count($service->calls);
        if ($case === 'expired') $saved['issued_at'] = time() - 601;
        if ($case === 'future') $saved['issued_at'] = time() + 60;
        if ($case === 'different-actor') $saved['actor_id'] = '3';
        if ($case === 'changed-context') $saved['context'] = str_repeat('b', 64);
        $session->put('meta_oauth.state', $saved);
        $response = $callback($case === 'wrong-state' ? str_repeat('c', 64) : $state,
            $case === 'missing-code' || $case === 'cancelled' ? null : 'PRIVATE_CALLBACK_CODE', $case === 'cancelled' ? 'access_denied' : null);
        $clean($response, $case === 'missing-code' ? 'failed' : ($case === 'cancelled' ? 'cancelled' : 'expired'));
        checkOAuth(!$session->has('meta_oauth.state') && !$session->has('meta_oauth.pending'), 'invalid callback consumes all credentials: '.$case);
        checkOAuth(count($service->calls) === $before, 'invalid callback makes no Graph call: '.$case);
    }
    $state = $start(); $service->failure = true; $clean($callback($state), 'failed'); $service->failure = false;
    checkOAuth(!$session->has('meta_oauth.pending'), 'Graph exception never persists credentials');
    $state = $start(); $policy->persisted[1]->active = false; $before = count($service->calls);
    $clean($callback($state), 'failed'); $policy->persisted[1]->active = true;
    checkOAuth(count($service->calls) === $before && !$session->has('meta_oauth.state'), 'revoked owner cannot discover and state consumed');

    $state = $start(); $clean($callback($state), 'review');
    $clean($controller->confirm($request('POST', ['accounts' => ['messenger:118160256722323']]), $access), 'saved');
    checkOAuth(!$session->has('meta_oauth.pending'), 'confirmation consumes encrypted pending');
    $last = end($service->calls); checkOAuth($last[0] === 'activate' && $last[2] === ['messenger:118160256722323'], 'only explicit selected identities activated');
    $before = count($service->calls);
    $clean($controller->confirm($request('POST', ['accounts' => ['messenger:118160256722323']]), $access), 'expired');
    checkOAuth(count($service->calls) === $before, 'confirmation replay cannot activate');

    foreach (['expired', 'future', 'different-actor', 'changed-context', 'corrupt', 'missing-selection', 'duplicate', 'invalid-selection', 'revoked-owner'] as $case) {
        $state = $start(); $clean($callback($state), 'review');
        $saved = json_decode(Crypt::decryptString($session->get('meta_oauth.pending')), true);
        if ($case === 'expired') $saved['issued_at'] = time() - 601;
        if ($case === 'future') $saved['issued_at'] = time() + 60;
        if ($case === 'different-actor') $saved['actor_id'] = '3';
        if ($case === 'changed-context') $saved['context'] = str_repeat('c', 64);
        $session->put('meta_oauth.pending', $case === 'corrupt' ? 'PRIVATE_CORRUPT_CIPHERTEXT' : Crypt::encryptString(json_encode($saved)));
        $selected = ['messenger:118160256722323'];
        if ($case === 'missing-selection') $selected = [];
        if ($case === 'duplicate') $selected[] = $selected[0];
        if ($case === 'invalid-selection') $selected = ['messenger:../../secret'];
        if ($case === 'revoked-owner') $policy->persisted[1]->active = false;
        $before = count($service->calls);
        $response = $controller->confirm($request('POST', ['accounts' => $selected]), $access);
        if ($case === 'revoked-owner') { checkOAuth($response->getStatusCode() === 403, 'revoked owner confirmation denied'); $policy->persisted[1]->active = true; }
        else $clean($response, in_array($case, ['missing-selection', 'duplicate', 'invalid-selection'], true)
            ? ($case === 'missing-selection' ? 'selection_required' : 'failed') : 'expired');
        checkOAuth(count($service->calls) === $before && !$session->has('meta_oauth.pending'), 'invalid confirmation consumes pending without activation: '.$case);
    }
    $credentials->configured = false; $session->put('meta_oauth.pending', 'OLD_PRIVATE_CIPHERTEXT');
    $response = $controller->index($request(), $access, $credentials);
    checkOAuth($response->getStatusCode() === 200 && !$GLOBALS['viewData']['metaConnection']['configured'] && !$session->has('meta_oauth.pending'), 'unconfigured connection gives honest inactive screen');
    $credentials->configured = true;
    checkOAuth($policy->reloads >= 40, 'every entry uses fresh persisted actor');
    echo 'META_OAUTH_CONTROLLER_PASS '.$checks." checks: persisted owner policy, encrypted pending, one-time session state, expiry, replay, explicit selection and credential privacy\n";
}
