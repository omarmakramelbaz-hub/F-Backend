<?php
// CLI-only, read-only image probes. Never print customer identities, message values or signed URLs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class MetaMediaDiagnostic
{
    public const MAX_BYTES = 20971520;
    public const SECONDS = 5;
    public const SCAN_LIMIT = 100;
    public const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    private $transport;
    private $userAgent;
    private array $dnsCache = [];

    public function __construct(?callable $transport = null, ?string $userAgent = null)
    {
        if ($userAgent !== null && $userAgent !== self::BROWSER_USER_AGENT) throw new InvalidArgumentException('USER_AGENT_UNSUPPORTED');
        $this->transport = $transport; $this->userAgent = $userAgent;
    }

    public static function browserComparisonEligible(string $channel, string $url, array $result): bool
    {
        return $channel === 'instagram' && self::approvedHost($url) === 'lookaside.fbsbx.com'
            && parse_url($url, PHP_URL_PATH) === '/ig_messaging_cdn/'
            && ($result['http_status'] ?? null) === 302
            && ($result['redirect_observed_host'] ?? null) === 'www.facebook.com'
            && ($result['redirect_path_class'] ?? null) === 'UNSUPPORTED_BROWSER';
    }

    private function effectiveUserAgent(string $url): ?string
    {
        if ($this->userAgent !== null) return $this->userAgent;
        $class = \App\Services\Dashboard\MetaMediaService::class;
        return method_exists($class, 'userAgentForUrl') ? $class::userAgentForUrl($url) : null;
    }

    public static function modules(): array
    {
        return ['curl' => function_exists('curl_init'), 'fileinfo' => class_exists('finfo'),
            'dns' => function_exists('dns_get_record'), 'openssl' => extension_loaded('openssl')];
    }

    public static function readOnlySession($connection): string
    {
        $driver = $connection->getDriverName();
        if ($driver === 'mysql') $result = $connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
        elseif ($driver === 'sqlite') $result = $connection->getPdo()->exec('PRAGMA query_only = ON');
        else throw new RuntimeException('READ_ONLY_UNAVAILABLE');
        if ($result === false) throw new RuntimeException('READ_ONLY_UNAVAILABLE');
        return $driver;
    }

    private static function media(): \App\Services\Dashboard\MetaMediaService
    {
        return new \App\Services\Dashboard\MetaMediaService();
    }

    private static function approvedHost($url): ?string
    {
        return self::media()->safeUrl($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : null;
    }

    private static function mime($value): ?string
    {
        if (!is_string($value)) return null;
        $value = strtolower(trim(explode(';', $value, 2)[0]));
        return in_array($value, ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/gif',
            'image/webp', 'image/avif', 'image/svg+xml', 'application/octet-stream', 'application/json',
            'text/html', 'text/plain', 'application/pdf', 'video/mp4', 'audio/mp4'], true) ? $value : 'OTHER';
    }

    /** Observation never expands the deployed media allowlist or follows a redirect. */
    private static function redirect($raw, string $sourceHost): array
    {
        $out = ['redirect_host' => null, 'redirect_destination' => 'MISSING', 'redirect_observed_host' => null,
            'redirect_scheme' => null, 'redirect_validation_reason' => 'MISSING',
            'redirect_path_class' => null, 'redirect_had_outer_ows' => false];
        if (!is_string($raw) || $raw === '') return $out;
        $out['redirect_destination'] = 'UNAPPROVED';
        if (strlen($raw) > 8192) { $out['redirect_validation_reason'] = 'TOO_LONG'; return $out; }
        $value = trim($raw, " \t"); $out['redirect_had_outer_ows'] = $value !== $raw;
        // Preserve the original approval decision, including its handling of relative references.
        $target = $raw;
        if (strncmp($target, '//', 2) === 0) $target = 'https:' . $target;
        elseif (strncmp($target, '/', 1) === 0) $target = 'https://' . $sourceHost . $target;
        $out['redirect_host'] = self::approvedHost($target);
        $out['redirect_destination'] = $out['redirect_host'] === null ? 'UNAPPROVED' : 'APPROVED';
        $absolute = preg_match('/\A([a-z][a-z0-9+.-]*):/i', $value, $match) === 1;
        $scheme = $absolute ? $match[1] : null;
        $out['redirect_scheme'] = !$absolute ? 'RELATIVE'
            : (in_array(strtolower($scheme), ['http', 'https'], true) ? strtolower($scheme) : 'OTHER');
        $observed = $value;
        if (strncmp($observed, '//', 2) === 0) $observed = 'https:' . $observed;
        elseif (strncmp($observed, '/', 1) === 0) $observed = 'https://' . $sourceHost . $observed;
        try { $parts = parse_url($observed); } catch (Throwable $ignored) { $parts = false; }
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (strlen($host) <= 253 && preg_match('/\A[a-z0-9.-]+\z/', $host)) {
            foreach (['facebook.com', 'instagram.com', 'fbcdn.net', 'fbsbx.com', 'cdninstagram.com'] as $domain) {
                if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                    $out['redirect_observed_host'] = $host; break;
                }
            }
        }
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $out['redirect_path_class'] = is_string($path) && preg_match('~\A/(?:accounts/login|login|checkpoint|dialog/oauth)(?:/|\z)~i', $path)
            ? 'LOGIN' : ($path === '/unsupportedbrowser' ? 'UNSUPPORTED_BROWSER' : 'OTHER');
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $raw)) $reason = 'WHITESPACE_OR_BACKSLASH';
        elseif (!is_array($parts)) $reason = 'PARSE_FAILED';
        elseif (isset($parts['user']) || isset($parts['pass'])) $reason = 'CREDENTIALS';
        elseif (!$absolute && strncmp($value, '/', 1) !== 0) $reason = 'RELATIVE_UNRESOLVED';
        elseif ($absolute && strtolower($scheme) !== 'https') $reason = 'SCHEME_NOT_HTTPS';
        elseif ($absolute && $scheme !== 'https') $reason = 'SCHEME_CASE_UNSUPPORTED';
        elseif (isset($parts['port']) && $parts['port'] !== 443) $reason = 'PORT_NOT_443';
        elseif (isset($parts['fragment'])) $reason = 'FRAGMENT_PRESENT';
        elseif ($out['redirect_host'] !== null) $reason = 'APPROVED';
        elseif (!filter_var($observed, FILTER_VALIDATE_URL)) $reason = 'URL_INVALID';
        else $reason = 'HOST_NOT_ALLOWLISTED';
        $out['redirect_validation_reason'] = $reason;
        return $out;
    }

    public function probe(string $url): array
    {
        $out = ['status' => 'FAILED', 'category' => 'SOURCE_REJECTED', 'source_host' => null,
            'http_status' => 0, 'redirect_host' => null, 'redirect_destination' => null,
            'claimed_mime' => null, 'detected_mime' => null, 'bytes' => 0,
            'deployed_validator_accepts' => false, 'resolver_status' => 'NOT_RUN'];
        $host = self::approvedHost($url);
        if ($host === null) return $out;
        $out['source_host'] = $host;
        try {
            $response = $this->transport !== null
                ? ($this->transport)($url, self::MAX_BYTES, self::SECONDS, $this->effectiveUserAgent($url)) : $this->download($url);
            if (!is_array($response)) throw new RuntimeException();
            if (in_array($response['resolver_status'] ?? null, ['PUBLIC_IP_PINNED', 'FAILED', 'UNSAFE', 'UNAVAILABLE'], true))
                $out['resolver_status'] = $response['resolver_status'];
            $http = $response['status'] ?? null;
            $out['http_status'] = is_int($http) && $http >= 100 && $http <= 599 ? $http : 0;
            $body = $response['body'] ?? null;
            if (is_string($body) && strlen($body) <= self::MAX_BYTES) {
                $out['bytes'] = strlen($body);
                $out['claimed_mime'] = self::mime($response['content_type'] ?? null);
                if ($body !== '' && class_exists('finfo')) $out['detected_mime'] = self::mime((new finfo(FILEINFO_MIME_TYPE))->buffer($body));
            }
            $error = $response['error'] ?? null;
            if (in_array($error, ['MISSING_CURL', 'MISSING_DNS', 'DNS_FAILURE',
                'UNSAFE_DNS', 'TIMEOUT', 'TLS_FAILURE', 'CONNECTION_FAILURE', 'OVERSIZED', 'TRANSPORT_FAILED'], true)) {
                $out['category'] = $error; return $out;
            }
            if (in_array($out['http_status'], [301, 302, 303, 307, 308], true)) {
                $out = array_replace($out, self::redirect($response['location'] ?? null, $host));
                $out['category'] = 'REDIRECT_NOT_FOLLOWED'; return $out;
            }
            if ($out['http_status'] !== 200) {
                $out['category'] = [401 => 'UPSTREAM_UNAUTHORIZED', 403 => 'UPSTREAM_FORBIDDEN',
                    404 => 'UPSTREAM_NOT_FOUND', 410 => 'UPSTREAM_GONE', 429 => 'UPSTREAM_RATE_LIMITED'][$out['http_status']]
                    ?? ($out['http_status'] === 0 ? 'TRANSPORT_FAILED' : 'UPSTREAM_HTTP_REJECTED');
                return $out;
            }
            if (!is_string($body) || $body === '' || strlen($body) > self::MAX_BYTES) {
                $out['category'] = is_string($body) && strlen($body) > self::MAX_BYTES ? 'OVERSIZED' : 'EMPTY_BODY'; return $out;
            }
            // Reuse the deployed validator without another request or any customer-authority operation.
            $media = new \App\Services\Dashboard\MetaMediaService(static fn () => $response);
            try {
                $file = $media->fetch(['type' => 'image', 'url' => $url]);
                $out['deployed_validator_accepts'] = is_string($file['mime'] ?? null)
                    && strncmp($file['mime'], 'image/', 6) === 0;
            } catch (Throwable $ignored) { /* Only fixed categories leave this diagnostic. */ }
            $out['category'] = $out['deployed_validator_accepts'] ? 'VALID_IMAGE' : 'DEPLOYED_VALIDATOR_REJECTED';
            $out['status'] = $out['deployed_validator_accepts'] ? 'READY' : 'FAILED';
        } catch (Throwable $ignored) { $out['category'] = 'CHECK_UNAVAILABLE'; }
        return $out;
    }

    private function download(string $url): array
    {
        $modules = self::modules();
        foreach (['curl' => 'MISSING_CURL', 'dns' => 'MISSING_DNS'] as $module => $error)
            if (!$modules[$module]) return ['error' => $error, 'resolver_status' => 'UNAVAILABLE'];
        $curl = null; $resolved = false;
        try {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $ips = $this->dnsCache[$host] ?? [];
            if (!$ips) {
                $records = @dns_get_record($host, DNS_A | DNS_AAAA);
                foreach (is_array($records) ? $records : [] as $record) {
                    $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                    if ($ip === null) continue;
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return ['error' => 'UNSAFE_DNS', 'resolver_status' => 'UNSAFE'];
                    $ips[] = $ip;
                }
                if ($ips) $this->dnsCache[$host] = $ips;
            }
            if (!$ips) return ['error' => 'DNS_FAILURE', 'resolver_status' => 'FAILED'];
            $resolved = true;
            $body = ''; $bytes = 0; $oversized = false; $location = null; $headers = 0;
            $curl = curl_init($url); $ip = strpos($ips[0], ':') === false ? $ips[0] : '[' . $ips[0] . ']';
            curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => self::SECONDS * 1000,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host . ':443:' . $ip],
                CURLOPT_HTTPHEADER => ['Accept: image/*, audio/*, video/*, application/pdf, application/octet-stream'],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location, &$oversized, &$headers) {
                    $headers += strlen($line); if ($headers > 65536) return 0;
                    if (preg_match('/\AContent-Length:\s*([0-9]+)/i', $line, $match) && (float) $match[1] > self::MAX_BYTES) {
                        $oversized = true; return 0;
                    }
                    if (preg_match('/\ALocation:\s*(.*?)\r?\n\z/i', $line, $match)) $location = $match[1];
                    return strlen($line);
                }, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$bytes, &$oversized) {
                    $bytes += strlen($chunk); if ($bytes > self::MAX_BYTES) { $oversized = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                }]);
            $userAgent = $this->effectiveUserAgent($url);
            if ($userAgent !== null) curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
            $ok = curl_exec($curl); $errno = curl_errno($curl);
            $out = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'content_type' => curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 'body' => $body,
                'location' => $location, 'resolver_status' => 'PUBLIC_IP_PINNED'];
            if ($oversized) $out['error'] = 'OVERSIZED';
            elseif ($ok === false) $out['error'] = [6 => 'DNS_FAILURE', 7 => 'CONNECTION_FAILURE',
                28 => 'TIMEOUT', 35 => 'TLS_FAILURE', 51 => 'TLS_FAILURE', 60 => 'TLS_FAILURE'][$errno] ?? 'TRANSPORT_FAILED';
            return $out;
        } catch (Throwable $ignored) { return ['error' => 'TRANSPORT_FAILED', 'resolver_status' => $resolved ? 'PUBLIC_IP_PINNED' : 'FAILED']; }
        finally { if ($curl !== null) curl_close($curl); }
    }

    public function run(): array
    {
        $out = ['diagnostic' => 'READ_ONLY_META_MEDIA', 'writes' => 0, 'message_sends' => 0,
            'graph_gets' => 0, 'redirects_followed' => 0, 'max_image_probes' => 3,
            'request_timeout_seconds' => self::SECONDS, 'timeout_scope' => 'HTTP_TRANSFER_EXCLUDES_RESOLVER',
            'max_bytes' => self::MAX_BYTES,
            'modules' => self::modules(), 'status' => 'FAILED', 'phase' => 'BOOTSTRAP'];
        try {
            $root = getcwd();
            if (!is_string($root) || !is_file($root . '/vendor/autoload.php') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException();
            require $root . '/vendor/autoload.php';
            $app = require $root . '/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $out['phase'] = 'READ_ONLY_SESSION';
            $out['driver'] = self::readOnlySession(\Illuminate\Support\Facades\DB::connection());
            $out['read_only_session'] = true;
            $out['phase'] = 'DEPLOYED_SOURCE';
            $path = $root . '/app/Services/Dashboard/MetaMediaService.php';
            if (!is_file($path) || is_link($path) || filesize($path) > 1048576) throw new RuntimeException();
            $bytes = file_get_contents($path); if (!is_string($bytes)) throw new RuntimeException();
            $out['service_sha256'] = hash('sha256', $bytes);
            $out['service_git_blob'] = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
            $out['phase'] = 'BOUNDED_IMAGE_SAMPLE'; $out['channels'] = [];
            $media = self::media(); $accounts = \App\Services\Dashboard\MetaInboxAccess::accounts();
            foreach (['messenger', 'instagram'] as $channel) {
                $configured = count(array_filter($accounts, static fn ($account) => $account['channel'] === $channel));
                $sample = ['channel' => $channel, 'configured_accounts' => $configured, 'scan_limit' => self::SCAN_LIMIT,
                    'scanned_messages' => 0, 'scan_window_truncated' => false,
                    'status' => $configured ? 'NO_VALID_IMAGE_DESCRIPTOR_IN_BOUNDED_SAMPLE' : 'NOT_CONFIGURED'];
                if (!$configured) { $out['channels'][] = $sample; continue; }
                $query = \App\Services\Dashboard\MetaInboxAccess::scope(
                    \Illuminate\Support\Facades\DB::table('meta_inbox_messages as m')
                        ->join('meta_inbox_conversations as t', 't.id', '=', 'm.conversation_id'), 't.');
                $rows = $query->where('t.waba_id', $channel)->orderByDesc('m.id')->limit(self::SCAN_LIMIT)
                    ->get(['m.*', 't.waba_id as sample_channel', 't.phone_number_id as sample_account',
                        't.peer_hash as sample_peer_hash', 't.customer as sample_customer']);
                $sample['scan_window_truncated'] = count($rows) === self::SCAN_LIMIT;
                if (!count($rows)) $sample['status'] = 'NO_MESSAGES_IN_BOUNDED_SAMPLE';
                foreach ($rows as $message) {
                    $sample['scanned_messages']++;
                    try {
                        if (!is_string($message->content) || strlen($message->content) > 8388608) continue;
                        $plain = \Illuminate\Support\Facades\Crypt::decryptString($message->content);
                        if (strlen($plain) > 4194304) continue;
                        $dto = json_decode($plain, true, 64, JSON_THROW_ON_ERROR); if (!is_array($dto)) continue;
                        $thread = (object) ['id' => $message->conversation_id, 'waba_id' => $message->sample_channel,
                            'phone_number_id' => $message->sample_account, 'peer_hash' => $message->sample_peer_hash,
                            'customer' => $message->sample_customer];
                        $descriptors = $media->descriptors($thread, $message, $dto);
                        foreach ($descriptors as $descriptor) {
                            if (($descriptor['type'] ?? null) !== 'image') continue;
                            $url = $dto['content']['message']['meta']['attachments'][$descriptor['index']]['payload']['url'] ?? null;
                            if (!is_string($url) || !self::approvedHost($url)) continue;
                            $sample['status'] = 'IMAGE_PROBED'; $sample['result'] = $this->probe($url);
                            if (self::browserComparisonEligible($channel, $url, $sample['result'])) {
                                $comparison = new self($this->transport, self::BROWSER_USER_AGENT);
                                $comparison->dnsCache = $this->dnsCache;
                                $sample['browser_agent_result'] = $comparison->probe($url);
                            }
                            break 2;
                        }
                    } catch (Throwable $ignored) { /* Invalid/unsupported evidence is skipped without revealing values. */ }
                }
                $out['channels'][] = $sample;
            }
            $out['phase'] = 'COMPLETE'; $out['status'] = 'COMPLETE';
        } catch (Throwable $ignored) { $out['category'] = 'CHECK_UNAVAILABLE'; }
        return $out;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
    $out = count($argv) === 1 ? (new MetaMediaDiagnostic())->run()
        : ['diagnostic' => 'READ_ONLY_META_MEDIA', 'writes' => 0, 'status' => 'FAILED', 'phase' => 'ARGUMENTS'];
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit(($out['phase'] ?? null) === 'COMPLETE' ? 0 : 1);
}
