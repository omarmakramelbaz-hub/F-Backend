<?php
/** Read-only customer invoice diagnosis. Never invokes a mailer, job or application termination. */
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();
$diagnosticBuffer = ob_get_level();
$output = ['mode'=>'read_only_diagnostic', 'mail_send_attempted'=>false];
$temporaryCache = null;

function invoiceDiagnosticCategory(Throwable $error, int $missingFeatures = 0): string
{
    $details = '';
    for ($i=0; $error && $i<5; ++$i, $error=$error->getPrevious()) {
        $details .= ' '.get_class($error).' '.substr($error->getMessage(), 0, 6000);
    }
    if (stripos($details, 'MissingSettings') !== false || stripos($details, 'GeneralSettings') !== false) return 'missing_settings';
    if ($missingFeatures && preg_match('/(?:property.*name.*null|property.*non-object)/is', $details)) return 'missing_product_feature';
    if (preg_match('/(?:QueryException|SQLSTATE|no such table|unknown column|Base table)/i', $details)) return 'database_schema';
    return 'template_render_failed';
}

function invoiceDiagnosticLogs(string $root): array
{
    $result = ['files_scanned'=>0, 'email_failed_events'=>0, 'categories'=>array_fill_keys(['auth','connection','recipient','template','unknown'], 0)];
    $files = [];
    foreach (glob($root.'/storage/logs/*.log') ?: [] as $path) {
        if (is_file($path) && !is_link($path)) $files[] = ['path'=>$path, 'mtime'=>(int)filemtime($path)];
    }
    usort($files, fn($a,$b)=>$b['mtime'] <=> $a['mtime']);
    foreach (array_slice($files, 0, 2) as $file) {
        $stream = fopen($file['path'], 'rb');
        if (!$stream) continue;
        try {
            $info = fstat($stream);
            if (!$info || ($info['mode'] & 0170000) !== 0100000) continue;
            $start = max(0, (int)$info['size'] - 524288);
            if (fseek($stream, $start) !== 0) continue;
            $text = fread($stream, 524288);
            if (!is_string($text)) continue;
            if ($start > 0) $text = substr($text, (strpos($text, "\n") ?: 0) + 1);
            ++$result['files_scanned'];
            foreach (preg_split('/(?=^\[[0-9]{4}-[0-9]{2}-[0-9]{2} )/m', $text) ?: [] as $entry) {
                if (strpos($entry, 'ORDER_AFTER_RESPONSE_EMAIL_FAILED') === false) continue;
                ++$result['email_failed_events'];
                $category = 'unknown';
                if (preg_match('/(?:authentication|authenticate|credentials|535[ -]|534[ -])/i', $entry)) $category = 'auth';
                elseif (preg_match('/(?:connection|timed out|timeout|could not resolve|network is unreachable|refused|stream_socket)/i', $entry)) $category = 'connection';
                elseif (preg_match('/(?:recipient|mailbox|address.*(?:invalid|reject)|550[ -]|553[ -])/i', $entry)) $category = 'recipient';
                elseif (preg_match('/(?:ViewException|Blade|property|undefined|MissingSettings|syntax error|send_order_email)/i', $entry)) $category = 'template';
                ++$result['categories'][$category];
            }
        } finally { fclose($stream); }
    }
    return $result;
}

try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI_REQUIRED');
    $arguments = getopt('', ['root:']);
    $root = $arguments['root'] ?? null;
    if (!is_string($root) || $root !== '/home/fasakha/public_html' || realpath($root) !== $root || is_link($root)) throw new RuntimeException('EXPECTED_ROOT_REQUIRED');
    $sources = [
        'app/Jobs/SendOrderCreatedAfterResponse.php',
        'app/Http/Controllers/Api/V1/User/CartController.php',
        'app/Http/Controllers/CheckoutController.php',
        'resources/views/emails/send_order_email.blade.php',
    ];
    $output['source_blobs'] = [];
    foreach ($sources as $relative) {
        $path = $root.'/'.$relative;
        $body = is_file($path) && !is_link($path) && filesize($path) <= 4194304 ? file_get_contents($path) : false;
        $output['source_blobs'][$relative] = is_string($body) ? sha1('blob '.strlen($body)."\0".$body) : null;
    }
    chdir($root);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $knownMailers = ['smtp','sendmail','ses','mailgun','postmark','log','array','failover','roundrobin','mail'];
    $mailer = (string)config('mail.default', config('mail.driver', 'smtp'));
    $transport = (string)config('mail.mailers.'.$mailer.'.transport', $mailer);
    $output['mail'] = [
        'mailer'=>in_array($mailer, $knownMailers, true) ? $mailer : 'custom',
        'transport'=>in_array($transport, $knownMailers, true) ? $transport : 'custom',
        'from_address_configured'=>(bool)filter_var(trim((string)config('mail.from.address')), FILTER_VALIDATE_EMAIL),
    ];
    $rows = Illuminate\Support\Facades\DB::table('orders as o')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
        ->where('o.type', 'current')->whereNotNull('o.status')->orderByDesc('o.id')->limit(30)->get(['o.id','u.email']);
    $summary = ['sample_size'=>count($rows), 'valid_email'=>0, 'missing_email'=>0, 'invalid_email'=>0];
    $latest = null;
    foreach ($rows as $row) {
        $email = trim((string)($row->email ?? ''));
        if ($email === '') ++$summary['missing_email'];
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) ++$summary['invalid_email'];
        else { ++$summary['valid_email']; if ($latest === null) $latest = (int)$row->id; }
    }
    $output['recent_orders'] = $summary;
    $ids = $rows->pluck('id')->all();
    $missingFeatures = 0;
    if ($ids) {
        $missingFeatures = Illuminate\Support\Facades\DB::table('carts as c')->leftJoin('product_features as f', 'f.id', '=', 'c.product_feature')
            ->whereIn('c.order_id', $ids)->whereNotNull('c.product_feature')->where('c.product_feature', '!=', '')
            ->where('c.product_feature', '!=', '0')->whereNull('f.id')->count();
    }
    $output['missing_product_feature_references'] = $missingFeatures;
    $output['recent_log_summary'] = invoiceDiagnosticLogs($root);
    $output['template_render'] = ['attempted'=>false, 'reason'=>'no_recent_order_with_valid_email'];
    if ($latest !== null) {
        try {
            $order = App\Models\Order::withoutGlobalScopes()->with(['user','resturant','user_address','carts.resturant_product'])->find($latest);
            if (!$order) throw new RuntimeException('ORDER_DISAPPEARED');
            $temporaryCache = sys_get_temp_dir().'/invoice-email-check-'.bin2hex(random_bytes(12));
            if (!mkdir($temporaryCache, 0700)) throw new RuntimeException('CACHE_UNAVAILABLE');
            $files = new Illuminate\Filesystem\Filesystem;
            $compiler = new Illuminate\View\Compilers\BladeCompiler($files, $temporaryCache);
            $engines = new Illuminate\View\Engines\EngineResolver;
            $engines->register('blade', fn()=>new Illuminate\View\Engines\CompilerEngine($compiler, $files));
            $finder = new Illuminate\View\FileViewFinder($files, [$root.'/resources/views']);
            $factory = new Illuminate\View\Factory($engines, $finder, new Illuminate\Events\Dispatcher($app));
            $factory->setContainer($app);
            $html = $factory->make('emails.send_order_email', ['email'=>$order->user?->email, 'cart'=>$order])->render();
            $output['template_render'] = ['attempted'=>true, 'ok'=>true, 'html_bytes'=>strlen($html)];
            unset($html);
        } catch (Throwable $error) {
            $output['template_render'] = ['attempted'=>true, 'ok'=>false, 'error_class'=>get_class($error), 'category'=>invoiceDiagnosticCategory($error, $missingFeatures)];
        }
    }
    $output['ok'] = true;
} catch (Throwable $error) {
    $output['ok'] = false;
    $output['error_class'] = get_class($error);
    $output['category'] = in_array($error->getMessage(), ['CLI_REQUIRED','EXPECTED_ROOT_REQUIRED'], true)
        ? $error->getMessage() : invoiceDiagnosticCategory($error);
} finally {
    if (is_string($temporaryCache) && is_dir($temporaryCache) && !is_link($temporaryCache)) {
        foreach (glob($temporaryCache.'/*') ?: [] as $file) if (is_file($file) && !is_link($file)) unlink($file);
        rmdir($temporaryCache);
    }
    while (ob_get_level() >= $diagnosticBuffer) ob_end_clean();
}
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit(!empty($output['ok']) ? 0 : 1);
