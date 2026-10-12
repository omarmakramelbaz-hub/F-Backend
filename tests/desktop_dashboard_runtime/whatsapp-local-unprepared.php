<?php
// Included by the original Laravel/MariaDB fixture. WhatsApp tables are absent
// from prepared data; authenticated local media must stop before touching them.
(function () use ($app, $actor, $profile) {
    $checks = 0;
    $verify = function (bool $value, string $message) use (&$checks) {
        if (!$value) throw new RuntimeException($message);
        $checks++;
    };
    $rows = fn () => [
        'commands' => \Illuminate\Support\Facades\DB::table('desktop_dashboard_commands')->orderBy('id')->get()->toJson(),
        'entities' => \Illuminate\Support\Facades\DB::table('desktop_dashboard_entities')->orderBy('id')->get()->toJson(),
    ];
    $storage = function () use ($profile) {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $files[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return $files;
    };
    $verify(!\Illuminate\Support\Facades\Schema::hasTable('whatsapp_inbox_conversations')
        && !\Illuminate\Support\Facades\Schema::hasTable('whatsapp_reply_requests'), 'Native fixture unexpectedly contains WhatsApp tables.');
    $route = $app['router']->getRoutes()->getByName('whatsapp-replies.attachment-download');
    $verify($route && $route->uri() === 'admin/whatsapp/conversations/{conversation}/replies/{reply}/attachment'
        && in_array('GET', $route->methods(), true)
        && $route->getActionName() === 'App\\Http\\Controllers\\Dashboard\\WhatsAppReplyController@downloadAttachment',
        'The original protected attachment GET route is missing.');
    $beforeRows = $rows();
    $beforeStorage = $storage();
    $previousLocal = config('desktop_dashboard.local');
    $guard = auth('admin');
    $previousUser = $guard->getUser();
    $guard->setUser($actor);
    $watch = false; $queries = []; $authorizations = 0; $providerCalls = 0;
    \Illuminate\Support\Facades\DB::listen(function ($query) use (&$watch, &$queries) {
        if ($watch) $queries[] = $query->sql;
    });
    $messages = new \App\Services\Dashboard\WhatsAppInboxMessage(function ($candidate) use ($actor, &$authorizations) {
        $authorizations++;
        if ((int) ($candidate->id ?? 0) !== (int) $actor->id) throw new \Symfony\Component\HttpKernel\Exception\HttpException(403);
        return $actor;
    });
    $media = new \App\Services\Dashboard\WhatsAppInboxMedia(
        function () use (&$providerCalls) { $providerCalls++; throw new RuntimeException('Native unprepared media reached the provider.'); },
        ['enabled' => true, 'access_token' => 'fixture-native-no-network'],
        $profile.'/private/wa-unprepared-cache', $messages, $profile.'/private/wa-unprepared-attachments');
    $refuses = function (callable $call, int $status) use ($verify) {
        try { $call(); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $verify($error->getStatusCode() === $status, 'Native unprepared WhatsApp status differs.');
            return;
        }
        throw new RuntimeException('Native unprepared WhatsApp operation unexpectedly completed.');
    };
    try {
        config(['desktop_dashboard.local' => true]);
        $watch = true;
        foreach ([false, true] as $lock) {
            $refuses(fn () => $messages->resolve($actor, 1, 1, $lock), 404);
            $refuses(fn () => $messages->resolveReply($actor, 1, 1, $lock), 404);
        }
        $refuses(fn () => $media->image($actor, 1, 1), 404);
        $refuses(fn () => $media->replyAttachment($actor, 1, 1), 404);
        $response = $app->make(\App\Http\Controllers\Dashboard\WhatsAppReplyController::class)
            ->downloadAttachment(\Illuminate\Http\Request::create('http://127.0.0.1/admin/whatsapp/conversations/1/replies/1/attachment', 'GET'), '1', '1', $media);
        $verify($response->getStatusCode() === 404 && $response->getContent() === ''
            && str_contains($response->headers->get('Cache-Control', ''), 'no-store'),
            'The original local attachment GET controller did not return a private empty404.');
        $refuses(fn () => $messages->resolveReply((object) ['id' => (int) $actor->id + 1], 1, 1), 403);
        $verify($authorizations === 8, 'Local WhatsApp media skipped actor authorization.');
        $verify($queries === [] && $providerCalls === 0, 'Local WhatsApp media performed SQL or provider work.');
        $watch = false;
        $verify($rows() === $beforeRows && $storage() === $beforeStorage,
            'Unprepared WhatsApp media changed the outbox or private files.');
        echo 'WHATSAPP_NATIVE_UNPREPARED_COMPLETE '.json_encode(['checks' => $checks,
            'local404' => true, 'authorizationRetained' => true, 'attachmentGet' => true,
            'queries' => 0, 'providerCalls' => 0, 'outboxUnchanged' => true, 'storageUnchanged' => true,
            'fullDashboard' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        $watch = false;
        config(['desktop_dashboard.local' => $previousLocal]);
        if ($previousUser) $guard->setUser($previousUser);
        else (function () { $this->user = null; })->call($guard);
    }
})();
