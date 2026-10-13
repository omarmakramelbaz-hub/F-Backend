<?php
// Included by the original Laravel/MariaDB fixture after the unchanged WA13
// module. Meta stays server-only even when media/reply settings are enabled.
(function () use ($app, $actor, $profile) {
    $checks = 0;
    $verify = function (bool $value, string $message) use (&$checks) {
        if (!$value) throw new RuntimeException($message);
        $checks++;
    };
    $refuses = function (callable $operation) use ($verify) {
        try { $operation(); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $verify($error->getStatusCode() === 403, 'The native Meta actor restriction changed.');
            return;
        }
        throw new RuntimeException('A native Meta actor unexpectedly acquired access.');
    };
    $tables = ['meta_webhook_events', 'meta_inbox_conversations', 'meta_inbox_messages',
        'meta_inbox_ingestion_failures', 'meta_order_scans', 'meta_order_drafts',
        'meta_reply_requests', 'meta_inbox_reads', 'meta_message_hides',
        'whatsapp_webhook_events', 'whatsapp_inbox_conversations', 'whatsapp_inbox_messages',
        'whatsapp_inbox_ingestion_failures', 'whatsapp_order_scans', 'whatsapp_order_drafts',
        'whatsapp_reply_requests', 'whatsapp_inbox_reads', 'whatsapp_inbox_hidden_messages',
        'whatsapp_reply_projection_keys', 'whatsapp_reply_reaction_targets'];
    $verify(array_intersect($tables, \App\Services\Dashboard\DesktopDashboardSchema::TABLES) === [],
        'A server-only Meta/WhatsApp table entered the native snapshot whitelist.');
    $present = array_filter($tables, fn ($table) => \Illuminate\Support\Facades\Schema::hasTable($table));
    $verify($present === [], 'The unprepared native fixture unexpectedly has Meta/WhatsApp tables.');

    $specifications = [
        'meta-inbox.index' => 'GET', 'meta-inbox.conversations' => 'GET',
        'meta-inbox.messages' => 'GET', 'meta-inbox.media' => 'GET', 'meta-inbox.hide' => 'POST',
        'meta-inbox.unread' => 'GET', 'meta-inbox.read' => 'POST',
        'meta-replies.state' => 'GET', 'meta-replies.send' => 'POST',
        'meta-replies.voice' => 'POST', 'meta-replies.attachment' => 'POST',
        'meta-replies.forward' => 'POST', 'meta-replies.temporary-media' => 'GET',
        'meta-messaging.webhook.verify' => 'GET', 'meta-messaging.webhook.receive' => 'POST',
    ];
    $ticket = str_repeat('a', 48).'.'.(time() + 300).'.'.str_repeat('b', 64);
    $routes = [];
    foreach ($specifications as $name => $method) {
        $route = $app['router']->getRoutes()->getByName($name);
        $verify($route && in_array($method, $route->methods(), true)
            && (str_starts_with($route->uri(), 'admin/meta')
                || str_starts_with($route->uri(), 'api/meta/messaging/')),
            'The original native Meta route contract is missing: '.$name);
        $routes[$name] = $route;
    }
    $request = function (string $name) use ($routes, $specifications, $ticket) {
        $path = strtr($routes[$name]->uri(), ['{conversation}' => '1', '{message}' => '1',
            '{index}' => '0', '{ticket}' => $ticket]);
        $value = \Illuminate\Http\Request::create('http://127.0.0.1/'.$path, $specifications[$name]);
        $value->headers->set('Accept', 'application/json');
        $value->setRouteResolver(fn () => $routes[$name]);
        return $value;
    };
    $privateFiles = function () use ($profile) {
        $files = [];
        $directory = $profile.'/private';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return $files;
    };
    $rows = fn () => [
        'commands' => \Illuminate\Support\Facades\DB::table('desktop_dashboard_commands')->orderBy('sequence')->get()->toJson(),
        'entities' => \Illuminate\Support\Facades\DB::table('desktop_dashboard_entities')->orderBy('id')->get()->toJson(),
    ];
    $realAccess = $app->make(\App\Services\Dashboard\MetaInboxAccess::class);
    $fresh = $realAccess->actor($actor);
    $verify((int) $fresh->id === (int) $actor->id && $fresh->account_type === 'admin'
        && empty($fresh->owner_resturant_id), 'The persisted central Meta actor is unavailable.');
    // A selected-user object cannot change the vendor's persisted role.
    $forgedVendor = \App\Models\User::withoutGlobalScopes()->findOrFail(10);
    $forgedVendor->account_type = 'admin';
    $forgedVendor->owner_resturant_id = null;
    $refuses(fn () => $realAccess->actor($forgedVendor));
    $refuses(fn () => $realAccess->actor(null));

    $guard = auth('admin');
    $previousUser = $guard->getUser();
    $previousLocal = config('desktop_dashboard.local');
    $queries = []; $watch = false; $authorizations = 0; $providerCalls = 0; $callbacks = 0;
    \Illuminate\Support\Facades\DB::listen(function ($event) use (&$watch, &$queries) {
        if ($watch) $queries[] = $event->sql;
    });
    $access = new class($actor, $authorizations) extends \App\Services\Dashboard\MetaInboxAccess {
        private $expectedActor;
        private $calls;
        public function __construct($actor, &$calls) { $this->expectedActor = $actor; $this->calls =& $calls; }
        public function actor($candidate): \App\Models\User
        {
            $this->calls++;
            if ((int) ($candidate->id ?? 0) !== (int) $this->expectedActor->id)
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(403);
            return $this->expectedActor;
        }
    };
    $provider = function () use (&$providerCalls) {
        $providerCalls++;
        throw new RuntimeException('The unprepared native Meta route reached a provider.');
    };
    $upload = new class($providerCalls, $profile.'/private/meta-unprepared') extends \App\Services\Dashboard\MetaUploadMedia {
        private $calls;
        public function __construct(&$calls, string $directory)
        {
            $this->calls =& $calls;
            parent::__construct(null, $directory);
        }
        public function temporary(string $ticket): array
        {
            $this->calls++;
            throw new RuntimeException('The native temporary-media controller ran.');
        }
    };
    $app->instance(\App\Services\Dashboard\MetaInboxAccess::class, $access);
    $app->instance(\App\Services\Dashboard\MetaUploadMedia::class, $upload);
    $app->instance(\App\Services\Dashboard\MetaMediaService::class,
        new \App\Services\Dashboard\MetaMediaService($provider));
    $app->instance(\App\Services\Dashboard\MetaReplyService::class,
        new \App\Services\Dashboard\MetaReplyService($provider, $upload));
    $assert404 = function ($response) use ($verify) {
        $cache = $response->headers->get('Cache-Control', '');
        $verify($response->getStatusCode() === 404 && $response->getContent() === ''
            && str_contains($cache, 'private') && str_contains($cache, 'no-store')
            && $response->headers->get('X-Content-Type-Options') === 'nosniff',
            'An unprepared native Meta request did not return the private empty404.');
    };
    try {
        config(['desktop_dashboard.local' => true]);
        $guard->setUser($actor);
        $beforeRows = $rows();
        $beforePrivate = $privateFiles();
        $watch = true;
        $middleware = $app->make(\App\Http\Middleware\DesktopDashboardJournal::class);
        foreach (array_keys($specifications) as $name) {
            $assert404($middleware->handle($request($name), function () use (&$callbacks) {
                $callbacks++;
                throw new RuntimeException('An unprepared Meta route callback ran.');
            }));
        }
        // Resolve the actual original HTTP Kernel and route stack as well as
        // the direct boundary. API capability/Webhook POST never needs a login.
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        foreach (['meta-inbox.media', 'meta-replies.state', 'meta-replies.temporary-media',
            'meta-messaging.webhook.verify', 'meta-messaging.webhook.receive'] as $name) {
            $assert404($kernel->handle($request($name)));
        }
        $guard->setUser($forgedVendor);
        $refuses(fn () => $middleware->handle($request('meta-inbox.media'), function () use (&$callbacks) {
            $callbacks++;
            throw new RuntimeException('An unauthorized native Meta callback ran.');
        }));
        $guard->setUser($actor);
        $metrics = (new \App\Services\Dashboard\MetaProfileService($provider))->refresh(1);
        $verify(($metrics['unavailable'] ?? null) === 1 && ($metrics['scanned'] ?? null) === 0
            && ($metrics['attempted'] ?? null) === 0 && ($metrics['updated'] ?? null) === 0,
            'The native Meta profile worker did not remain unavailable.');
        $verify($authorizations === 15, 'The native Meta fence skipped or duplicated actor authorization.');
        $verify($queries === [] && $providerCalls === 0 && $callbacks === 0,
            'The local Meta boundary performed SQL, provider, capability or route-callback work.');
        $watch = false;
        $verify($rows() === $beforeRows && $privateFiles() === $beforePrivate,
            'An unprepared native Meta request changed the outbox or private files.');
        echo 'META_NATIVE_UNPREPARED_COMPLETE '.json_encode(['checks' => $checks,
            'routes' => 15, 'httpKernelRoutes' => 5, 'local404' => true,
            'authorizationRetained' => true, 'serverOnlyTablesExcluded' => true,
            'queries' => 0, 'providerCalls' => 0, 'callbacks' => 0,
            'outboxUnchanged' => true, 'privateStorageUnchanged' => true,
            'profileWorkerUnavailable' => true, 'fullDashboard' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        $watch = false;
        config(['desktop_dashboard.local' => $previousLocal]);
        $app->instance(\App\Services\Dashboard\MetaInboxAccess::class, $realAccess);
        foreach ([\App\Services\Dashboard\MetaUploadMedia::class, \App\Services\Dashboard\MetaMediaService::class,
            \App\Services\Dashboard\MetaReplyService::class] as $class) $app->forgetInstance($class);
        if ($previousUser) $guard->setUser($previousUser);
        else (function () { $this->user = null; })->call($guard);
    }
})();
