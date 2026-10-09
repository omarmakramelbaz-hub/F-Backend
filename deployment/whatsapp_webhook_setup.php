<?php

// Run from the application root as its owner. Never put secrets in CLI arguments.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

function atomicWrite(string $path, string $content): void
{
    $temporary = tempnam(dirname($path), '.whatsapp-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create temporary file');
    }
    try {
        if (file_put_contents($temporary, $content) !== strlen($content)) {
            throw new RuntimeException('Incomplete write');
        }
        chmod($temporary, fileperms($path) & 0777);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Cannot replace file');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

try {
    $mode = $argv[1] ?? '';
    if ($mode === 'configure') {
        $path = $root.'/.env';
        $content = file_get_contents($path);
        $values = \Dotenv\Dotenv::parse($content);
        $secret = trim(stream_get_contents(STDIN));
        if ($secret !== '' && preg_match('/\A[a-fA-F0-9]{32}\z/', $secret) !== 1) {
            throw new RuntimeException('Expected the 32-character Meta App Secret');
        }
        $updates = [];
        if (empty($values['WHATSAPP_VERIFY_TOKEN'])) {
            $updates['WHATSAPP_VERIFY_TOKEN'] = bin2hex(random_bytes(32));
        }
        if ($secret !== '') {
            $updates['WHATSAPP_APP_SECRET'] = $secret;
        } elseif (empty($values['WHATSAPP_APP_SECRET'])) {
            throw new RuntimeException('Meta App Secret is required; rerun and paste it at the hidden prompt');
        }
        if (empty($values['WHATSAPP_ALLOWED_ACCOUNT_IDS'])) {
            // Observed Meta test WABA. Replace/add the real WABA when production onboarding is confirmed.
            $updates['WHATSAPP_ALLOWED_ACCOUNT_IDS'] = '1636131124838697';
        }
        foreach ($updates as $key => $value) {
            $pattern = '/^[\t ]*(?:export[\t ]+)?'.preg_quote($key, '/').'[\t ]*=.*$/m';
            $line = $key.'="'.$value.'"';
            $count = preg_match_all($pattern, $content);
            if ($count > 1) {
                throw new RuntimeException('Duplicate WhatsApp environment setting');
            }
            $content = $count === 1 ? preg_replace($pattern, $line, $content) : rtrim($content)."\n".$line."\n";
        }
        atomicWrite($path, $content);
        echo "WhatsApp configuration saved.\n";
        exit(0);
    }
    if ($mode === 'attach-route') {
        $path = $root.'/routes/api.php';
        $content = file_get_contents($path);
        if (strpos($content, '// WhatsApp webhook integration') !== false) {
            echo "Route loader already present.\n";
            exit(0);
        }
        if (strpos($content, 'whatsapp.php') !== false) {
            throw new RuntimeException('Existing WhatsApp route loader needs review');
        }
        $phpOpen = false;
        foreach (token_get_all($content) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
                $phpOpen = true;
            } elseif (is_array($token) && $token[0] === T_CLOSE_TAG) {
                $phpOpen = false;
            }
        }
        $content .= ($phpOpen ? "\n" : "\n<?php\n")."// WhatsApp webhook integration\nrequire __DIR__.'/whatsapp.php';\n";
        atomicWrite($path, $content);
        echo "WhatsApp route loader added.\n";
        exit(0);
    }

    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($mode === 'smoke') {
        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $token = (string) config('whatsapp.verify_token', '');
        if ($token === '' || config('whatsapp.app_secret', '') === ''
            || config('whatsapp.allowed_account_ids', []) === []) {
            throw new RuntimeException('Missing WhatsApp configuration');
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('whatsapp_webhook_events')) {
            throw new RuntimeException('Missing event table');
        }
        // No signed fake events, messages, orders, or customer records are created.
        $challenge = 'whatsapp-setup-'.bin2hex(random_bytes(8));
        $query = http_build_query(['hub.mode'=>'subscribe', 'hub.verify_token'=>$token, 'hub.challenge'=>$challenge]);
        $request = \Illuminate\Http\Request::create('/api/whatsapp/webhook?'.$query, 'GET');
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== 200 || $response->getContent() !== $challenge) {
            throw new RuntimeException('Webhook handshake failed');
        }
        $kernel->terminate($request, $response);
        $request = \Illuminate\Http\Request::create('/api/whatsapp/webhook?'.http_build_query([
            'hub.mode'=>'subscribe', 'hub.verify_token'=>bin2hex(random_bytes(16)), 'hub.challenge'=>$challenge,
        ]), 'GET');
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== 403) {
            throw new RuntimeException('Wrong verification token was not rejected');
        }
        $kernel->terminate($request, $response);
        $request = \Illuminate\Http\Request::create('/api/whatsapp/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE'=>'application/json',
        ], '{"object":"whatsapp_business_account","entry":[]}');
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== 403) {
            throw new RuntimeException('Unsigned webhook was not rejected');
        }
        $kernel->terminate($request, $response);
        echo "PASS: real Laravel route, Meta challenge, wrong token, unsigned POST, event table.\n";
        exit(0);
    }
    if ($mode === 'details') {
        $url = rtrim((string) config('app.url'), '/');
        if (strpos($url, 'https://') !== 0) {
            throw new RuntimeException('APP_URL must be the public HTTPS application URL');
        }
        echo 'CALLBACK_URL='.$url.'/api/whatsapp/webhook'.PHP_EOL;
        echo 'VERIFY_TOKEN='.config('whatsapp.verify_token').PHP_EOL;
        echo 'ALLOWED_WABA_IDS='.implode(',', config('whatsapp.allowed_account_ids')).PHP_EOL;
        echo "Copy VERIFY_TOKEN directly into Meta. Do not share it in chat/screenshots.\n";
        exit(0);
    }
    throw new RuntimeException('Unknown setup action');
} catch (Throwable $error) {
    // Runtime messages above are fixed strings. Vendor errors can contain credentials.
    $message = get_class($error) === RuntimeException::class ? $error->getMessage() : get_class($error);
    fwrite(STDERR, 'WhatsApp setup failed: '.$message.PHP_EOL);
    exit(1);
}
