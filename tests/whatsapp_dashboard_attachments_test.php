<?php

// Included by the actual Laravel 8 encrypted SQLite/router suite. No real provider calls.
if (!isset($checks, $messages, $container, $configuration)) throw new RuntimeException('ATTACHMENT_PARENT_FIXTURE_REQUIRED');

$attachmentChecksBefore = $checks;
$attachmentParent = sys_get_temp_dir() . '/wa-dashboard-attachments-' . bin2hex(random_bytes(12));
$attachmentDirectory = $attachmentParent . '/staging';
if (!mkdir($attachmentParent, 0700) || !mkdir($attachmentDirectory, 0700)) throw new RuntimeException('Private attachment fixture directory unavailable');
$attachmentUploads = [];
register_shutdown_function(static function () use ($attachmentDirectory, $attachmentParent, &$attachmentUploads) {
    foreach ($attachmentUploads as $path) if (is_file($path) || is_link($path)) unlink($path);
    foreach (glob($attachmentDirectory . '/*') ?: [] as $path) if (is_file($path) || is_link($path)) unlink($path);
    if (is_file($attachmentDirectory . '/.attachments.lock')) unlink($attachmentDirectory . '/.attachments.lock');
    rmdir($attachmentDirectory);
    rmdir($attachmentParent);
});

function attachmentFile(string $bytes, string $name = 'صورة العميل.png', string $clientMime = 'application/octet-stream',
    int $error = UPLOAD_ERR_OK): \Symfony\Component\HttpFoundation\File\UploadedFile
{
    global $attachmentUploads;
    $path = tempnam(sys_get_temp_dir(), 'wa-upload-fixture-');
    if (!is_string($path) || file_put_contents($path, $bytes) !== strlen($bytes)) throw new RuntimeException('Fixture upload unavailable');
    chmod($path, 0600); $attachmentUploads[] = $path;
    return new \Illuminate\Http\UploadedFile($path, $name, $clientMime, $error, true);
}
function attachmentInput(array $fixture, ?string $caption = null): array
{
    $input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message']];
    if ($caption !== null) $input['caption'] = $caption;
    return $input;
}

$attachmentMedia = new \App\Services\Dashboard\WhatsAppInboxMedia($mediaTransport, $configuration,
    $cacheDirectory, $messages, $attachmentDirectory);
$attachmentRequests = []; $attachmentNative = null; $attachmentTransportHook = null;
$attachmentTransport = static function ($endpoint, $payload, $token, $multipart) use (&$attachmentRequests, &$attachmentTransportHook) {
    actionCheck(\Illuminate\Support\Facades\DB::connection()->transactionLevel() === 0,
        'attachment provider side effects occur outside the database transaction');
    $row = \Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->orderByDesc('id')->first();
    actionCheck($row !== null && $row->state === 'UNKNOWN', 'attachment uncertainty is durable before upload and send');
    actionCheck($token === 'fixture-only-token-no-external-use' && !str_contains($endpoint, $token),
        'attachment token is passed as authorization and never a URL query');
    $attachmentRequests[] = ['url' => $endpoint, 'payload' => $payload, 'multipart' => $multipart];
    if ($attachmentTransportHook !== null) return $attachmentTransportHook($endpoint, $payload, $token, $multipart, $row);
    if ($multipart) {
        actionCheck($endpoint === 'https://graph.facebook.com/v25.0/515388018324075/media'
            && $row->send_started_at === null, 'upload is fixed-phone media with no customer send start yet');
        actionCheck($payload['messaging_product'] === 'whatsapp' && is_array($payload['file'] ?? null),
            'provider upload contains product and a prepared server file');
        $file = $payload['file'];
        actionCheck(is_file($file['path']) && !is_link($file['path']) && (fileperms($file['path']) & 0777) === 0600
            && realpath(dirname($file['path'])) === realpath($GLOBALS['attachmentDirectory']),
            'provider upload uses an owned private staging copy outside the public tree');
        $client = \Illuminate\Support\Facades\Http::withoutRedirecting()->withToken($token)
            ->attach('file', file_get_contents($file['path']), $file['filename'], ['Content-Type' => $file['mime']]);
        $fields = $payload; unset($fields['file']);
        $response = $client->post($endpoint, $fields);
    } else {
        actionCheck($endpoint === 'https://graph.facebook.com/v25.0/515388018324075/messages'
            && $row->send_started_at !== null, 'customer attachment send is fixed phone after its durable send marker');
        $response = \Illuminate\Support\Facades\Http::withoutRedirecting()->withToken($token)->post($endpoint, $payload);
    }
    return ['status' => $response->status(), 'body' => $response->body()];
};
$attachmentService = new \App\Services\Dashboard\WhatsAppReplyService($attachmentTransport, $authorize,
    $clock, $configuration, null, $messages, $attachmentMedia);
function attachmentAcceptFake(string $mediaId = '900001111'): void
{
    global $attachmentRequests, $attachmentNative, $attachmentTransportHook;
    $attachmentRequests = []; $attachmentNative = null; $attachmentTransportHook = null;
    actionFake(static function ($request) use ($mediaId, &$attachmentNative) {
        actionCheck($request->method() === 'POST'
            && $request->header('Authorization') === ['Bearer fixture-only-token-no-external-use'],
            'actual fake HTTP attachment transport has the expected Bearer header');
        if (str_ends_with($request->url(), '/media')) return \Illuminate\Support\Facades\Http::response(['id' => $mediaId], 200);
        actionCheck(str_ends_with($request->url(), '/515388018324075/messages'), 'actual fake HTTP sends only from the bound phone');
        $payload = $request->data(); $attachmentNative = 'wamid.attachmentfixture' . actionUuid();
        return \Illuminate\Support\Facades\Http::response(['messaging_product' => 'whatsapp',
            'contacts' => [['input' => $payload['to'], 'wa_id' => $payload['to']]],
            'messages' => [['id' => $attachmentNative]]], 200);
    });
}
function attachmentNoProviderCalls(string $label): void
{
    global $httpCalls, $attachmentRequests;
    actionCheck($httpCalls === [] && $attachmentRequests === [], $label);
}

// Actual bytes win over a deliberately misleading browser MIME/name.
$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$text = "بيانات الطلب التجريبي\nاسم عميل خيالي\n";
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDmaKKK4D1D/9k=', true);
actionCheck(is_string($jpeg) && getimagesizefromstring($jpeg)['mime'] === 'image/jpeg', 'JPEG attachment fixture contains actual decoded image bytes');
foreach ([[$png, 'wrong.pdf', 'application/pdf', 'image', 'image/png'],
    [$jpeg, 'jpeg.txt', 'text/plain', 'image', 'image/jpeg'],
    [$pdf, 'ملف الطلب.pdf', 'image/png', 'document', 'application/pdf'],
    [$text, 'ملاحظات الطلب.txt', 'image/jpeg', 'document', 'text/plain']] as [$bytes, $name, $clientMime, $kind, $mime]) {
    $fixture = actionReset(); attachmentAcceptFake(); $protected = actionProtectedSnapshot();
    $file = attachmentFile($bytes, $name, $clientMime); $input = attachmentInput($fixture, 'مرفق تجريبي');
    $input['reply_to_message_id'] = $fixture['message'];
    $result = $attachmentService->attachment($actor, $fixture['conversation'], $input, $file);
    actionCheck($result['state'] === 'ACCEPTED' && count($attachmentRequests) === 2,
        'validated actual attachment uploads once and sends once');
    [$upload, $send] = $attachmentRequests;
    actionCheck($upload['multipart'] && !$send['multipart'] && $upload['payload']['type'] === $mime
        && $upload['payload']['file']['mime'] === $mime,
        'actual MIME chooses the provider multipart type regardless of browser MIME');
    actionCheck($send['payload']['to'] === $fixture['phone'] && $send['payload']['type'] === $kind
        && $send['payload'][$kind]['id'] === '900001111'
        && $send['payload'][$kind]['caption'] === $input['caption']
        && $send['payload']['context'] === ['message_id' => $fixture['native']],
        'attachment caption quote and recipient are bound to server-resolved native scope');
    $row = \Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->where('id', $result['reply_id'])->first();
    $audit = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->audit), true, 64, JSON_THROW_ON_ERROR);
    actionCheck($row->kind === $kind && $audit['attachment']['sha256'] === hash('sha256', $bytes)
        && $audit['attachment']['size'] === strlen($bytes) && $audit['attachment']['mime_type'] === $mime
        && $audit['media']['id'] === '900001111' && $audit['media']['sha256'] === hash('sha256', $bytes),
        'encrypted accepted audit stores only server-validated digest size MIME and uploaded media identity');
    actionCheck(!str_contains($row->audit, 'مرفق تجريبي') && !str_contains($row->audit, '900001111')
        && !str_contains($row->audit, $fixture['phone']), 'attachment audit remains private encrypted data');
    actionCheck(actionProtectedSnapshot() === $protected, 'attachment send preserves original raw history customer orders and read markers');
    actionCheck(!is_file($upload['payload']['file']['path']) && is_file($file->getPathname()),
        'private prepared copy is cleaned without deleting the actual UploadedFile input');
    $index = \Illuminate\Support\Facades\DB::table(\App\Services\Dashboard\WhatsAppInboxMessage::INDEX)
        ->where('reply_id', $result['reply_id'])->first();
    actionCheck($index !== null && $index->valid, 'accepted attachment receives a canonical projection acceptance index');
    $state = $attachmentService->state($actor, $fixture['conversation']);
    $history = $attachmentService->history($actor, $fixture['conversation']);
    actionCheck($state['recent_replies'][0]['kind'] === $kind
        && $history['replies'][0]['reply_id'] === $result['reply_id'], 'accepted attachment is present in both recent state and paged history');
    $descriptor = $history['replies'][0]['media'];
    actionCheck($descriptor['type'] === $kind && $descriptor['mime_type'] === $mime
        && str_ends_with($descriptor['url'], '/replies/' . $result['reply_id'] . ($kind === 'image' ? '/media' : '/attachment'))
        && !str_contains(json_encode($descriptor), '900001111') && !str_contains(json_encode($descriptor), 'graph.facebook'),
        'accepted attachment descriptor exposes scoped dashboard URL and actual type without provider secrets');
    if ($kind === 'document') actionCheck($descriptor['size'] === strlen($bytes)
        && $descriptor['filename'] === $send['payload']['document']['filename'], 'document history retains safe filename and exact byte size');
    actionCheck($attachmentService->attachment($actor, $fixture['conversation'], $input, $file)['replayed']
        && count($attachmentRequests) === 2, 'attachment same-UUID replay neither uploads nor sends again');
    $changed = $input; $changed['caption'] = 'تغيير بعد الإرسال';
    actionRefuses(fn () => $attachmentService->attachment($actor, $fixture['conversation'], $changed, $file), 409,
        'same attachment UUID cannot replace its accepted caption');
}

$fixture = actionReset(); attachmentAcceptFake(); $file = attachmentFile($png); $input = attachmentInput($fixture);
$result = $attachmentService->attachment($actor, $fixture['conversation'], $input, $file);
$changedFile = attachmentFile($pdf, 'different.pdf');
actionRefuses(fn () => $attachmentService->attachment($actor, $fixture['conversation'], $input, $changedFile), 409,
    'same UUID binds actual attachment bytes MIME size and filename');
$renamedFile = attachmentFile($png, 'another-name.png');
actionRefuses(fn () => $attachmentService->attachment($actor, $fixture['conversation'], $input, $renamedFile), 409,
    'same UUID cannot replace an accepted attachment filename while retaining the same bytes');

foreach ([['', 'empty.txt'], ["\x00binary", 'binary.txt'], ["\xff\xfe", 'bad-utf8.txt'],
    ['<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'unsafe.svg'],
    ["MZ\0 executable fixture", 'unsafe.exe'], ["%PDF-1.4\nno end marker", 'truncated.pdf'],
    [str_repeat('x', 8388609), 'too-large.txt'],
    [$png . str_repeat('x', 5242881 - strlen($png)), 'too-large-image.png'],
    [substr_replace($png, pack('N', 12001), 16, 4), 'huge-dimensions.png']] as [$bytes, $name]) {
    $fixture = actionReset(); attachmentAcceptFake();
    actionRefuses(fn () => $attachmentService->attachment($actor, $fixture['conversation'], attachmentInput($fixture),
        attachmentFile($bytes, $name)), 422, 'unsupported malformed oversized or unsafe attachment bytes fail validation');
    attachmentNoProviderCalls('invalid attachment creates no provider request');
    actionCheck(!\Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->exists(),
        'invalid attachment never creates a send claim');
}
foreach ([UPLOAD_ERR_PARTIAL, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_NO_FILE] as $uploadError) {
    $fixture = actionReset(); attachmentAcceptFake();
    actionRefuses(fn () => $attachmentService->attachment($actor, $fixture['conversation'], attachmentInput($fixture),
        attachmentFile($png, 'image.png', 'image/png', $uploadError)), 422, 'actual PHP upload errors fail closed');
    attachmentNoProviderCalls('failed multipart PHP upload never reaches a provider');
}
foreach ([str_repeat('أ', 1025), "bad\0caption", "\xff", ['not-a-string']] as $caption) {
    $fixture = actionReset(); attachmentAcceptFake(); $input = attachmentInput($fixture); $input['caption'] = $caption;
    actionRefuses(fn () => $attachmentService->attachment($actor, $fixture['conversation'], $input, attachmentFile($png)), 422,
        'attachment caption requires bounded control-free UTF-8 text');
    attachmentNoProviderCalls('bad caption creates no upload or message');
}
foreach (['to', 'media_id', 'mime_type', 'path', 'text'] as $unknownField) {
    $fixture = actionReset(); attachmentAcceptFake(); $input = attachmentInput($fixture); $input[$unknownField] = 'untrusted';
    actionRefuses(fn () => $attachmentService->attachment($actor, $fixture['conversation'], $input, attachmentFile($png)), 422,
        'browser cannot supply an attachment recipient provider media path or MIME');
    attachmentNoProviderCalls('unknown attachment fields are rejected before provider actions');
}

foreach (['revoked', 'branch', 'foreign_phone', 'stale', 'window'] as $denial) {
    $fixture = actionReset(); attachmentAcceptFake(); $input = attachmentInput($fixture);
    if ($denial === 'revoked') \Illuminate\Support\Facades\DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
    if ($denial === 'branch') \Illuminate\Support\Facades\DB::table('users')->where('id', 1)->update(['owner_resturant_id' => 301]);
    if ($denial === 'foreign_phone') \Illuminate\Support\Facades\DB::table('whatsapp_inbox_conversations')
        ->where('id', $fixture['conversation'])->update(['phone_number_id' => '111111']);
    if ($denial === 'stale') actionMessage();
    if ($denial === 'window') $clockNow = $clockNow->addDays(2);
    if (in_array($denial, ['revoked', 'branch', 'foreign_phone'], true)) {
        actionRefuses(fn () => $attachmentService->attachment($actor, $fixture['conversation'], $input, attachmentFile($png)),
            $denial === 'foreign_phone' ? 404 : 403, 'attachment access reloads persisted central role and fixed business phone');
    } else {
        $result = $attachmentService->attachment($actor, $fixture['conversation'], $input, attachmentFile($png));
        actionCheck($result['state'] === 'FAILED' && $result['reason'] === ($denial === 'stale' ? 'STALE_INBOUND' : 'WINDOW_CLOSED'),
            'attachment requires the current native inbound and open customer window');
    }
    attachmentNoProviderCalls('attachment preflight rejection makes no upload or customer send');
}

// Uncertain upload and uncertain customer delivery have separate no-resend checks.
foreach (['upload_throw', 'upload_zero', 'upload_500', 'upload_invalid_success', 'send_throw', 'send_zero', 'send_500'] as $fault) {
    $fixture = actionReset(); attachmentAcceptFake(); $input = attachmentInput($fixture); $file = attachmentFile($png);
    $attachmentTransportHook = static function ($endpoint, $payload, $token, $multipart, $row) use ($fault) {
        if ($multipart) {
            actionCheck($row->send_started_at === null, 'uncertain upload is still marked as not yet customer-facing');
            if ($fault === 'upload_throw') throw new RuntimeException('Fixture timeout after possible upload');
            if ($fault === 'upload_zero') return ['status' => 0, 'body' => ''];
            if ($fault === 'upload_500') return ['status' => 500, 'body' => '{}'];
            if ($fault === 'upload_invalid_success') return ['status' => 200, 'body' => '{"id":"untrusted/id"}'];
            return ['status' => 200, 'body' => '{"id":"900002222"}'];
        }
        actionCheck($row->send_started_at !== null, 'uncertain customer send has a durable send timestamp');
        if ($fault === 'send_throw') throw new RuntimeException('Fixture timeout after possible delivery');
        return ['status' => $fault === 'send_zero' ? 0 : 500, 'body' => '{}'];
    };
    $result = $attachmentService->attachment($actor, $fixture['conversation'], $input, $file);
    $expectedCalls = str_starts_with($fault, 'upload_') ? 1 : 2;
    actionCheck($result['state'] === 'UNKNOWN' && count($attachmentRequests) === $expectedCalls,
        'ambiguous attachment upload or message response remains durable UNKNOWN');
    actionCheck($attachmentService->attachment($actor, $fixture['conversation'], $input, $file)['replayed']
        && count($attachmentRequests) === $expectedCalls, 'ambiguous attachment UUID is never uploaded or sent again');
    $otherInput = attachmentInput($fixture);
    $otherResult = $attachmentService->attachment($otherActor, $fixture['conversation'], $otherInput, $file);
    actionCheck($otherResult['state'] === 'FAILED' && $otherResult['reason'] === 'UNKNOWN_PENDING'
        && count($attachmentRequests) === $expectedCalls, 'ambiguous attachment blocks new UUIDs across administrators');
    foreach ($attachmentRequests as $call) if ($call['multipart'])
        actionCheck(!is_file($call['payload']['file']['path']), 'ambiguous attachment always removes its private staging copy');
}
foreach (['upload', 'send'] as $stage) {
    $fixture = actionReset(); attachmentAcceptFake();
    $attachmentTransportHook = static function ($endpoint, $payload, $token, $multipart) use ($stage) {
        if ($multipart && $stage === 'send') return ['status' => 200, 'body' => '{"id":"900003333"}'];
        return ['status' => 400, 'body' => '{"error":{"code":100}}'];
    };
    $result = $attachmentService->attachment($actor, $fixture['conversation'], attachmentInput($fixture), attachmentFile($png));
    actionCheck($result['state'] === 'FAILED' && count($attachmentRequests) === ($stage === 'upload' ? 1 : 2),
        'definite provider upload or send rejection is FAILED without a phantom accepted receipt');
}

foreach (['role_after_upload', 'inbound_after_upload', 'quote_after_upload'] as $race) {
    $fixture = actionReset(); attachmentAcceptFake(); $input = attachmentInput($fixture); $input['reply_to_message_id'] = $fixture['message'];
    $attachmentTransportHook = static function ($endpoint, $payload, $token, $multipart) use ($fixture, $race) {
        actionCheck($multipart, 'mid-upload race must stop before a customer message send');
        if ($race === 'role_after_upload') \Illuminate\Support\Facades\DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
        if ($race === 'inbound_after_upload') actionMessage();
        if ($race === 'quote_after_upload') {
            $row = \Illuminate\Support\Facades\DB::table('whatsapp_inbox_messages')->where('id', $fixture['message'])->first();
            $dto = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->content), true, 64, JSON_THROW_ON_ERROR);
            $dto['text'] = 'Changed native quote'; $dto['content']['message']['text']['body'] = $dto['text'];
            \Illuminate\Support\Facades\DB::table('whatsapp_inbox_messages')->where('id', $row->id)->update([
                'content' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($dto, JSON_THROW_ON_ERROR))]);
        }
        return ['status' => 200, 'body' => '{"id":"900004444"}'];
    };
    $result = $attachmentService->attachment($actor, $fixture['conversation'], $input, attachmentFile($png));
    actionCheck($result['state'] === 'FAILED' && count($attachmentRequests) === 1,
        'role current inbound and native quote fingerprint are rechecked between upload and send');
}

// Real multipart route, persisted role authorization, CSRF and safe response headers.
$container->instance(\App\Services\Dashboard\WhatsAppReplyService::class, $attachmentService);
$container->instance(\App\Services\Dashboard\WhatsAppInboxMedia::class, $attachmentMedia);
$sessionActor = new \Illuminate\Auth\GenericUser(['id' => 1, 'account_type' => 'admin']); $auth->forgetGuards();
$fixture = actionReset(); attachmentAcceptFake();
$attachmentPath = '/admin/whatsapp/conversations/' . $fixture['conversation'] . '/attachment';
$file = attachmentFile($pdf, 'طلب تجريبي.pdf'); $input = attachmentInput($fixture, 'فاتورة تجريبية');
foreach ([null, 'incorrect-token'] as $csrf) {
    try { actionRoute('POST', $attachmentPath, $input, $csrf, ['attachment' => $file]);
        throw new RuntimeException('Attachment CSRF rejection missing'); }
    catch (\Illuminate\Session\TokenMismatchException $error) { actionCheck(true, 'actual multipart attachment route requires matching CSRF'); }
    attachmentNoProviderCalls('CSRF denial precedes attachment upload and claim');
}
$response = actionRoute('POST', $attachmentPath, $input, str_repeat('t', 40), ['attachment' => $file]);
$result = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);
actionCheck($response->getStatusCode() === 200 && $result['state'] === 'ACCEPTED' && count($attachmentRequests) === 2,
    'real multipart controller receives UploadedFile and delegates exactly one media upload and send');
actionCheck(str_contains($response->headers->get('Cache-Control'), 'no-store')
    && $response->headers->get('X-Content-Type-Options') === 'nosniff', 'real attachment send response has private headers');
$replyId = $result['reply_id'];
$downloadPath = '/admin/whatsapp/conversations/' . $fixture['conversation'] . '/replies/' . $replyId . '/attachment';
actionFake(static function ($request) use ($pdf) {
    actionCheck($request->method() === 'GET' && $request->header('Authorization') === ['Bearer fixture-only-token-no-external-use'],
        'protected document download uses authorization header and read-only transport');
    if ($request->url() === 'https://graph.facebook.com/v25.0/900001111?phone_number_id=515388018324075')
        return \Illuminate\Support\Facades\Http::response(['id' => '900001111', 'mime_type' => 'application/pdf',
            'file_size' => strlen($pdf), 'sha256' => hash('sha256', $pdf),
            'url' => 'https://lookaside.fbsbx.com/whatsapp_business/fixture/document'], 200);
    actionCheck($request->url() === 'https://lookaside.fbsbx.com/whatsapp_business/fixture/document',
        'protected download uses only a validated provider CDN URL');
    return \Illuminate\Support\Facades\Http::response($pdf, 200);
});
$protected = actionProtectedSnapshot(true);
$response = actionRoute('GET', $downloadPath);
actionCheck($response->getStatusCode() === 200 && $response->getContent() === $pdf
    && $response->headers->get('Content-Type') === 'application/pdf', 'protected accepted attachment route returns verified actual bytes');
actionCheck(str_starts_with($response->headers->get('Content-Disposition') ?? '', 'attachment;')
    && str_contains($response->headers->get('Cache-Control'), 'private')
    && str_contains($response->headers->get('Cache-Control'), 'no-store')
    && $response->headers->get('X-Content-Type-Options') === 'nosniff'
    && $response->headers->get('Content-Security-Policy') === "default-src 'none'; sandbox"
    && $response->headers->get('Referrer-Policy') === 'no-referrer', 'document download forces attachment and forbids caching active content and referrer leakage');
actionCheck(actionProtectedSnapshot(true) === $protected, 'protected attachment download changes no customer order raw read or receipt data');
$callsBefore = count($httpCalls); $other = actionMessage('201222222222', 'US.attachmentforeign');
$wrongPath = '/admin/whatsapp/conversations/' . $other['conversation'] . '/replies/' . $replyId . '/attachment';
$response = actionRoute('GET', $wrongPath);
actionCheck($response->getStatusCode() === 404 && count($httpCalls) === $callsBefore,
    'protected download cannot borrow a receipt from another conversation');
$selection = $messages->selection($actor, $fixture['conversation']);
$messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $selection['snapshot_token'],
    'select_all' => false, 'message_ids' => [], 'reply_ids' => [$replyId]]);
$response = actionRoute('GET', $downloadPath);
actionCheck($response->getStatusCode() === 404 && count($httpCalls) === $callsBefore,
    'hidden accepted document is unavailable to its hiding actor even if cached');
\Illuminate\Support\Facades\DB::table('users')->where('id', 1)->update(['owner_resturant_id' => 301]);
$response = actionRoute('POST', $attachmentPath, attachmentInput($fixture), str_repeat('t', 40), ['attachment' => $file]);
actionCheck($response->getStatusCode() === 403 && count($httpCalls) === $callsBefore,
    'real attachment controller rejects a persisted branch-bound administrator');
$sessionActor = null; $auth->forgetGuards();
$response = actionRoute('GET', $downloadPath);
actionCheck($response->getStatusCode() === 302 && str_ends_with($response->headers->get('Location'), '/admin/login')
    && count($httpCalls) === $callsBefore, 'actual legacy middleware rejects unauthenticated attachment downloads');

function attachmentDownloadFake(string $mediaId, string $bytes, string $mime, array $metadataOverrides = [],
    ?string $downloadBytes = null, ?callable $duringDownload = null): void
{
    $GLOBALS['httpCalls'] = [];
    foreach (glob($GLOBALS['cacheDirectory'] . '/*.bin') ?: [] as $path) unlink($path);
    $metadata = array_replace(['id' => $mediaId, 'mime_type' => $mime, 'file_size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes), 'url' => 'https://lookaside.fbsbx.com/whatsapp_business/fixture/' . $mediaId], $metadataOverrides);
    actionFake(static function ($request) use ($mediaId, $metadata, $bytes, $downloadBytes, $duringDownload) {
        actionCheck($request->method() === 'GET' && $request->header('Authorization') === ['Bearer fixture-only-token-no-external-use'],
            'protected attachment transport authenticates a read-only request');
        if ($request->url() === 'https://graph.facebook.com/v25.0/' . $mediaId . '?phone_number_id=515388018324075')
            return \Illuminate\Support\Facades\Http::response($metadata, 200);
        actionCheck($request->url() === $metadata['url'], 'validated attachment metadata alone selects its CDN download');
        if ($duringDownload !== null) $duringDownload();
        return \Illuminate\Support\Facades\Http::response($downloadBytes ?? $bytes, 200);
    });
}

// File metadata, late native echoes and scoped available-byte retrieval survive paging.
foreach ([[$png, 'image.png', 'image', 'image/png'], [$jpeg, 'image.jpg', 'image', 'image/jpeg'],
    [$pdf, 'طلب.pdf', 'document', 'application/pdf'], [$text, 'طلب.txt', 'document', 'text/plain']] as [$bytes, $name, $kind, $mime]) {
    $fixture = actionReset(); attachmentAcceptFake('900005555');
    $input = attachmentInput($fixture, 'وصف المرفق');
    $result = $attachmentService->attachment($actor, $fixture['conversation'], $input, attachmentFile($bytes, $name));
    $row = \Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->where('id', $result['reply_id'])->first();
    $audit = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->audit), true, 64, JSON_THROW_ON_ERROR);
    $nativeId = $attachmentNative;
    $echo = actionMessage('201111111111', 'US.actions1', $kind,
        ['id' => '900005555', 'mime_type' => $mime, 'sha256' => hash('sha256', $bytes),
            'filename' => $audit['attachment']['filename'], 'caption' => $input['caption']], 'outbound', $nativeId);
    $state = $attachmentService->state($actor, $fixture['conversation']);
    $card = $state['recent_replies'][0];
    actionCheck($card['reply_id'] === $result['reply_id'] && $card['projected_message_id'] === $echo['message'],
        'accepted file receipt deduplicates its actual late native echo without creating a fake message');
    actionCheck($messages->selection($actor, $fixture['conversation'])['total_count'] === 2,
        'accepted file and native echo count as one logical item beside the customer inbound');
    attachmentDownloadFake('900005555', $bytes, $mime);
    $protected = actionProtectedSnapshot(true);
    $file = $kind === 'image' ? $attachmentMedia->replyImage($actor, $fixture['conversation'], $result['reply_id'])
        : $attachmentMedia->replyAttachment($actor, $fixture['conversation'], $result['reply_id']);
    actionCheck($file['bytes'] === $bytes && $file['mime'] === $mime && count($httpCalls) === 2,
        'accepted PNG JPEG PDF and UTF-8 text retrieve only digest-verified protected bytes: ' . $kind . '/' . $mime . '/calls=' . count($httpCalls));
    actionCheck(actionProtectedSnapshot(true) === $protected, 'retrieving available attachment bytes preserves all encrypted originals and read markers');
    $callsBefore = count($httpCalls);
    $again = $kind === 'image' ? $attachmentMedia->replyImage($otherActor, $fixture['conversation'], $result['reply_id'])
        : $attachmentMedia->replyAttachment($otherActor, $fixture['conversation'], $result['reply_id']);
    actionCheck($again === $file && count($httpCalls) === $callsBefore, 'an independently authorized actor can use the verified private cache');
}

foreach ([['mime_type' => 'text/plain'], ['sha256' => str_repeat('0', 64)], ['file_size' => 8388609],
    ['url' => 'https://127.0.0.1/private'], ['url' => 'https://lookaside.fbsbx.com.attacker.example/private'],
    ['url' => 'http://lookaside.fbsbx.com/private'], ['url' => 'https://user:pass@lookaside.fbsbx.com/private']] as $metadata) {
    $fixture = actionReset(); attachmentAcceptFake('900006666');
    $result = $attachmentService->attachment($actor, $fixture['conversation'], attachmentInput($fixture), attachmentFile($pdf, 'file.pdf'));
    attachmentDownloadFake('900006666', $pdf, 'application/pdf', $metadata);
    actionRefuses(fn () => $attachmentMedia->replyAttachment($actor, $fixture['conversation'], $result['reply_id']), 502,
        'private document retrieval rejects wrong MIME digest size scheme credentials or untrusted hosts');
    actionCheck(count($httpCalls) === 1, 'untrusted document metadata never initiates CDN retrieval');
}
$fixture = actionReset(); attachmentAcceptFake('900006667');
$result = $attachmentService->attachment($actor, $fixture['conversation'], attachmentInput($fixture), attachmentFile($pdf, 'file.pdf'));
attachmentDownloadFake('900006667', $pdf, 'application/pdf', [], str_repeat('x', strlen($pdf)));
actionRefuses(fn () => $attachmentMedia->replyAttachment($actor, $fixture['conversation'], $result['reply_id']), 502,
    'protected document rejects changed download bytes despite matching claimed length');

foreach (['hide', 'revoke', 'audit'] as $change) {
    $fixture = actionReset(); attachmentAcceptFake('900007777');
    $result = $attachmentService->attachment($actor, $fixture['conversation'], attachmentInput($fixture), attachmentFile($pdf, 'file.pdf'));
    attachmentDownloadFake('900007777', $pdf, 'application/pdf', [], null,
        static function () use ($messages, $actor, $fixture, $result, $change) {
            if ($change === 'revoke') \Illuminate\Support\Facades\DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
            elseif ($change === 'hide') {
                $snapshot = $messages->selection($actor, $fixture['conversation']);
                $messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $snapshot['snapshot_token'],
                    'select_all' => false, 'message_ids' => [], 'reply_ids' => [$result['reply_id']]]);
            } else {
                $row = \Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->where('id', $result['reply_id'])->first();
                $audit = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->audit), true, 64, JSON_THROW_ON_ERROR);
                $audit['attachment']['filename'] = 'changed-after-download.pdf'; $audit['media']['filename'] = 'changed-after-download.pdf';
                \Illuminate\Support\Facades\DB::table('whatsapp_reply_requests')->where('id', $row->id)->update([
                    'audit' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($audit, JSON_THROW_ON_ERROR))]);
            }
        });
    actionRefuses(fn () => $attachmentMedia->replyAttachment($actor, $fixture['conversation'], $result['reply_id']),
        ['hide' => 404, 'revoke' => 403, 'audit' => 409][$change],
        'attachment visibility persisted authorization and source metadata are revalidated after downloading bytes');
    actionCheck((glob($cacheDirectory . '/*.bin') ?: []) === [], 'post-download source rejection never fills the private cache');
}

// Actual bounded private cache: full capacity and a held lock skip caching only.
foreach (['files', 'bytes', 'locked'] as $capacity) {
    $fixture = actionReset(); attachmentAcceptFake('900008888');
    $result = $attachmentService->attachment($actor, $fixture['conversation'], attachmentInput($fixture), attachmentFile($pdf, 'file.pdf'));
    attachmentDownloadFake('900008888', $pdf, 'application/pdf');
    $cacheLock = null; $fixturePaths = [];
    if ($capacity === 'locked') {
        $cacheLock = fopen($cacheDirectory . '/.cache.lock', 'c+b'); chmod($cacheDirectory . '/.cache.lock', 0600);
        actionCheck(is_resource($cacheLock) && flock($cacheLock, LOCK_EX | LOCK_NB), 'real private cache fixture holds its publication lock');
    } else {
        $count = $capacity === 'files' ? 64 : 8;
        for ($index = 0; $index < $count; $index++) {
            $path = $cacheDirectory . '/' . hash('sha256', 'capacity-fixture:' . $capacity . ':' . $index) . '.bin';
            $handle = fopen($path, 'xb'); chmod($path, 0600);
            actionCheck(is_resource($handle) && ($capacity === 'bytes' ? ftruncate($handle, 8388608) : fwrite($handle, 'fixture') === 7),
                'private cache capacity fixture has exactly valid bounded owned file metadata');
            fclose($handle); $fixturePaths[] = $path;
        }
    }
    try {
        $beforeFiles = glob($cacheDirectory . '/*.bin') ?: [];
        $file = $attachmentMedia->replyAttachment($actor, $fixture['conversation'], $result['reply_id']);
        actionCheck($file['bytes'] === $pdf && $file['mime'] === 'application/pdf'
            && count($httpCalls) === 2, 'full or locked private cache cannot suppress an authorized verified download');
        actionCheck((glob($cacheDirectory . '/*.bin') ?: []) === $beforeFiles,
            'private cache never exceeds 64 files or 64 MiB and never writes through a held publication lock');
    } finally {
        if (is_resource($cacheLock)) { flock($cacheLock, LOCK_UN); fclose($cacheLock); }
        foreach ($fixturePaths as $path) unlink($path);
    }
}

// Private staging validation cannot trust names, paths, mutable copies or hard-link aliases.
$prepared = $attachmentMedia->prepareAttachment(attachmentFile($pdf, "../../invoice\r\n<bad>.svg"));
actionCheck(str_ends_with($prepared['filename'], '.pdf') && !preg_match('/[\x00-\x1f\x7f\/\\\\<>]/', $prepared['filename'])
    && $attachmentMedia->preparedAttachmentValid($prepared), 'prepared filename is safe and its extension derives from the actual bytes');
file_put_contents($prepared['path'], str_repeat('x', $prepared['size']));
actionCheck(!$attachmentMedia->preparedAttachmentValid($prepared), 'mutable private staging bytes cannot pass their claimed digest');
$attachmentMedia->cleanupAttachment($prepared['path']);
actionCheck(!is_file($prepared['path']), 'owned invalid private staging bytes are still cleaned');
$inputFile = attachmentFile($png); $alias = $inputFile->getPathname() . '-alias';
if (!link($inputFile->getPathname(), $alias)) throw new RuntimeException('Hardlink fixture unavailable');
$attachmentUploads[] = $alias;
actionRefuses(fn () => $attachmentMedia->prepareAttachment($inputFile), 422, 'incoming hard-linked upload aliases cannot be staged');
$inputFile = attachmentFile($png); $alias = $inputFile->getPathname() . '-symlink';
if (!symlink($inputFile->getPathname(), $alias)) throw new RuntimeException('Symlink fixture unavailable');
$attachmentUploads[] = $alias;
$aliasFile = new \Illuminate\Http\UploadedFile($alias, 'image.png', 'image/png', UPLOAD_ERR_OK, true);
actionRefuses(fn () => $attachmentMedia->prepareAttachment($aliasFile), 422, 'incoming symlink upload aliases cannot be staged');

$fixture = actionReset(); attachmentAcceptFake();
$input = attachmentInput($fixture, str_repeat('أ', 1024));
$result = $attachmentService->attachment($actor, $fixture['conversation'], $input, attachmentFile($png));
actionCheck($result['state'] === 'ACCEPTED'
    && $attachmentRequests[1]['payload']['image']['caption'] === $input['caption'],
    'the exact 1024-character Unicode caption limit is accepted without truncation');
$quoteId = $result['reply_id']; attachmentAcceptFake();
$input = attachmentInput($fixture, 'رد بمرفق على رسالة سابقة'); $input['reply_to_reply_id'] = $quoteId;
$result = $attachmentService->attachment($actor, $fixture['conversation'], $input, attachmentFile($pdf, 'quote.pdf'));
actionCheck($result['state'] === 'ACCEPTED' && isset($attachmentRequests[1]['payload']['context']['message_id']),
    'attachment can quote a server-verified accepted API receipt in the same conversation');

$fixture = actionReset(); attachmentAcceptFake();
$queryHook = static function ($query) use (&$queryHook, $attachmentDirectory) {
    if (preg_match('/\A\s*insert\b/i', $query->sql) && str_contains($query->sql, 'whatsapp_reply_requests')) {
        $queryHook = null;
        foreach (glob($attachmentDirectory . '/attachment-*.bin') ?: [] as $path) file_put_contents($path, '');
    }
};
$result = $attachmentService->attachment($actor, $fixture['conversation'], attachmentInput($fixture), attachmentFile($png));
actionCheck($queryHook === null && $result['state'] === 'FAILED' && $result['reason'] === 'INVALID_ATTACHMENT'
    && !$attachmentRequests && !$httpCalls, 'staging truncation after a durable claim cannot initiate an upload');
actionCheck((glob($attachmentDirectory . '/attachment-*.bin') ?: []) === [],
    'even owned zero-byte staging files are cleaned after a failed pre-upload integrity check');

$sessionActor = new \Illuminate\Auth\GenericUser(['id' => 1, 'account_type' => 'admin']); $auth->forgetGuards();
$fixture = actionReset(); attachmentAcceptFake();
$attachmentPath = '/admin/whatsapp/conversations/' . $fixture['conversation'] . '/attachment';
foreach ([[], ['attachment' => attachmentFile($png), 'extra' => attachmentFile($pdf, 'extra.pdf')],
    ['attachment' => [attachmentFile($png), attachmentFile($png)]]] as $files) {
    $response = actionRoute('POST', $attachmentPath, attachmentInput($fixture), str_repeat('t', 40), $files);
    actionCheck($response->getStatusCode() === 422 && !$attachmentRequests && !$httpCalls,
        'real multipart attachment controller accepts exactly one file field and rejects missing extra or array uploads');
}

$limits = $attachmentMedia->attachmentLimits();
$state = $attachmentService->state($actor, $fixture['conversation']);
actionCheck($state['attachment_ready'] && $state['attachment_max_bytes'] === $limits['attachment_max_bytes']
    && $state['image_max_bytes'] === $limits['image_max_bytes']
    && $limits['attachment_max_bytes'] > 0 && $limits['attachment_max_bytes'] <= 8388608
    && $limits['image_max_bytes'] <= 5242880, 'browser readiness advertises the actual finite PHP and application upload limits');
$attachmentPhp = getenv('WHATSAPP_TEST_PHP') ?: PHP_BINARY;
foreach ([['upload' => '1M', 'post' => '8M', 'enabled' => '1', 'max' => 1048576, 'image' => 1048576, 'ready' => true],
    ['upload' => '8M', 'post' => '512K', 'enabled' => '1', 'max' => 458752, 'image' => 458752, 'ready' => true],
    ['upload' => '0', 'post' => '0', 'enabled' => '1', 'max' => 8388608, 'image' => 5242880, 'ready' => true],
    ['upload' => '1G', 'post' => '0', 'enabled' => '1', 'max' => 8388608, 'image' => 5242880, 'ready' => true],
    ['upload' => '-1', 'post' => '8M', 'enabled' => '1', 'max' => 0, 'image' => 0, 'ready' => false],
    ['upload' => '1.5M', 'post' => '8M', 'enabled' => '1', 'max' => 0, 'image' => 0, 'ready' => false],
    ['upload' => '8M', 'post' => '32K', 'enabled' => '1', 'max' => 0, 'image' => 0, 'ready' => false],
    ['upload' => '8M', 'post' => '16M', 'enabled' => '0', 'max' => 8388608, 'image' => 5242880, 'ready' => false]] as $iniCase) {
    $testProgram = 'require ' . var_export($vendor, true) . '; require ' . var_export($source . '/app/Services/Dashboard/WhatsAppInboxMessage.php', true)
        . '; require ' . var_export($source . '/app/Services/Dashboard/WhatsAppInboxMedia.php', true) . ';'
        . '$media = new \\App\\Services\\Dashboard\\WhatsAppInboxMedia();'
        . '$result = $media->attachmentLimits(); $result["ready"] = $media->attachmentsAvailable();'
        . 'if ($result["ready"] && $result["attachment_max_bytes"] < 8388608) {'
        . '$path = tempnam(sys_get_temp_dir(), "wa-cap-fixture-");'
        . 'try { file_put_contents($path, str_repeat("a", $result["attachment_max_bytes"] + 1));'
        . '$file = new \\Illuminate\\Http\\UploadedFile($path,"limit.txt","text/plain",UPLOAD_ERR_OK,true);'
        . 'try { $media->prepareAttachment($file); $result["rejects_above_actual_limit"] = false; }'
        . 'catch (\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $error) { $result["rejects_above_actual_limit"] = $error->getStatusCode() === 422; }'
        . '} finally { unlink($path); } } echo json_encode($result, JSON_THROW_ON_ERROR);';
    $process = proc_open([$attachmentPhp, '-d', 'display_errors=0', '-d', 'upload_max_filesize=' . $iniCase['upload'],
        '-d', 'post_max_size=' . $iniCase['post'], '-d', 'file_uploads=' . $iniCase['enabled'], '-r', $testProgram],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Actual PHP ini fixture subprocess unavailable');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
    actionCheck($code === 0, 'actual PHP subprocess can inspect upload limits without changing application configuration');
    $observed = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    actionCheck($observed['attachment_max_bytes'] === $iniCase['max'] && $observed['image_max_bytes'] === $iniCase['image']
        && $observed['ready'] === $iniCase['ready'], 'actual PHP request settings clamp finite attachment capability including disabled and malformed limits');
    if (isset($observed['rejects_above_actual_limit'])) actionCheck($observed['rejects_above_actual_limit'],
        'actual UploadedFile bytes above the active server limit are rejected before private staging or provider calls');
}

echo 'WHATSAPP_DASHBOARD_ATTACHMENT_CHECKS=' . ($checks - $attachmentChecksBefore) . "\n";

