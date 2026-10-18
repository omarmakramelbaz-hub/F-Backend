<?php
// Standard-PHP fixtures only: no application bootstrap, database or network.
final class MetaMediaDiagnosticAbort extends RuntimeException
{
    public function __construct(public int $status) { parent::__construct('SUPPRESSED'); }
}
function abort_unless($condition, $status, $message = '') { if (!$condition) throw new MetaMediaDiagnosticAbort($status); }
require __DIR__ . '/../app/Services/Dashboard/MetaMediaService.php';
require __DIR__ . '/../deployment/meta_media_diagnostic.php';
$checks = 0;
function mediaDiagnosticCheck($ok, string $label): void
{
    global $checks; $checks++; if (!$ok) throw new RuntimeException($label);
}
$url = 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=private-id&signature=secret-signature';
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
$cases = [
    [['status' => 200, 'content_type' => 'image/gif', 'body' => $gif], 'VALID_IMAGE', 'READY'],
    [['status' => 302, 'location' => 'https://scontent.fbcdn.net/private?signature=never-print', 'body' => ''], 'REDIRECT_NOT_FOLLOWED', 'FAILED'],
    [['status' => 403, 'content_type' => 'text/html', 'body' => 'expired private body'], 'UPSTREAM_FORBIDDEN', 'FAILED'],
    [['status' => 404, 'body' => 'private'], 'UPSTREAM_NOT_FOUND', 'FAILED'],
    [['error' => 'DNS_FAILURE'], 'DNS_FAILURE', 'FAILED'],
    [['error' => 'UNSAFE_DNS'], 'UNSAFE_DNS', 'FAILED'],
    [['error' => 'TIMEOUT'], 'TIMEOUT', 'FAILED'],
    [['status' => 200, 'content_type' => 'image/gif', 'body' => '<html>private</html>'], 'DEPLOYED_VALIDATOR_REJECTED', 'FAILED'],
    [['status' => 200, 'content_type' => 'text/plain', 'body' => $gif], 'DEPLOYED_VALIDATOR_REJECTED', 'FAILED'],
    [['status' => 200, 'content_type' => 'image/gif', 'body' => str_repeat('x', MetaMediaDiagnostic::MAX_BYTES + 1)], 'OVERSIZED', 'FAILED'],
];
foreach ($cases as [$response, $category, $status]) {
    $calls = 0;
    $probe = new MetaMediaDiagnostic(function ($source, $bytes, $seconds) use ($response, &$calls, $url) {
        $calls++; mediaDiagnosticCheck($source === $url && $bytes === 20971520 && $seconds === 5, 'fixed source and probe bounds'); return $response;
    });
    $result = $probe->probe($url);
    mediaDiagnosticCheck($result['category'] === $category && $result['status'] === $status && $calls === 1, $category);
    $serialized = json_encode($result);
    foreach (['private', 'secret-signature', 'never-print', 'asset_id', 'signature', 'https://'] as $sensitive)
        mediaDiagnosticCheck(strpos($serialized, $sensitive) === false, 'source values and bodies remain private');
}
$probe = new MetaMediaDiagnostic(fn () => ['status' => 302, 'location' => 'https://evil.test/private', 'body' => '']);
$result = $probe->probe($url);
mediaDiagnosticCheck($result['redirect_host'] === null && $result['redirect_destination'] === 'UNAPPROVED', 'unapproved redirect host suppressed');
$probe = new MetaMediaDiagnostic(fn () => ['status' => 302, 'location' => '/private?signature=secret', 'body' => '']);
$result = $probe->probe($url);
mediaDiagnosticCheck($result['redirect_host'] === 'lookaside.fbsbx.com' && $result['redirect_destination'] === 'APPROVED', 'relative redirect reveals approved host only');
$redirectCases = [
    ['https://lookaside.instagram.com/photo?secret=never-print', 'lookaside.instagram.com', 'https', 'HOST_NOT_ALLOWLISTED', 'OTHER', false, 'UNAPPROVED'],
    ['https://www.facebook.com/login/?secret=never-print', 'www.facebook.com', 'https', 'HOST_NOT_ALLOWLISTED', 'LOGIN', false, 'UNAPPROVED'],
    ['https://www.facebook.com/unsupportedbrowser?secret=never-print', 'www.facebook.com', 'https', 'HOST_NOT_ALLOWLISTED', 'UNSUPPORTED_BROWSER', false, 'UNAPPROVED'],
    ['https://customer-123456.evil.test/login?secret=never-print', null, 'https', 'HOST_NOT_ALLOWLISTED', 'LOGIN', false, 'UNAPPROVED'],
    ['https://scontent.fbcdn.net/photo?secret=never-print#fragment-private', 'scontent.fbcdn.net', 'https', 'FRAGMENT_PRESENT', 'OTHER', false, 'UNAPPROVED'],
    ["https://scontent.fbcdn.net/photo?secret=never-print \t", 'scontent.fbcdn.net', 'https', 'WHITESPACE_OR_BACKSLASH', 'OTHER', true, 'UNAPPROVED'],
    ['HTTPS://scontent.fbcdn.net/photo?secret=never-print', 'scontent.fbcdn.net', 'https', 'SCHEME_CASE_UNSUPPORTED', 'OTHER', false, 'UNAPPROVED'],
    ['http://scontent.fbcdn.net/photo?secret=never-print', 'scontent.fbcdn.net', 'http', 'SCHEME_NOT_HTTPS', 'OTHER', false, 'UNAPPROVED'],
    ['/photo?secret=never-print', 'lookaside.fbsbx.com', 'RELATIVE', 'APPROVED', 'OTHER', false, 'APPROVED'],
    ['//scontent.fbcdn.net/photo?secret=never-print', 'scontent.fbcdn.net', 'RELATIVE', 'APPROVED', 'OTHER', false, 'APPROVED'],
    ['../photo?secret=never-print', null, 'RELATIVE', 'RELATIVE_UNRESOLVED', 'OTHER', false, 'UNAPPROVED'],
    ['?secret=never-print', null, 'RELATIVE', 'RELATIVE_UNRESOLVED', 'OTHER', false, 'UNAPPROVED'],
    ['https://name:password@scontent.fbcdn.net/photo?secret=never-print', 'scontent.fbcdn.net', 'https', 'CREDENTIALS', 'OTHER', false, 'UNAPPROVED'],
    ['https://scontent.fbcdn.net:444/photo?secret=never-print', 'scontent.fbcdn.net', 'https', 'PORT_NOT_443', 'OTHER', false, 'UNAPPROVED'],
];
foreach ($redirectCases as [$location, $host, $scheme, $reason, $pathClass, $ows, $approval]) {
    $probe = new MetaMediaDiagnostic(fn () => ['status' => 302, 'location' => $location, 'body' => '']);
    $result = $probe->probe($url);
    mediaDiagnosticCheck($result['redirect_observed_host'] === $host && $result['redirect_scheme'] === $scheme
        && $result['redirect_validation_reason'] === $reason && $result['redirect_path_class'] === $pathClass
        && $result['redirect_had_outer_ows'] === $ows && $result['redirect_destination'] === $approval, 'redirect classification ' . $reason);
    foreach (['secret', 'never-print', 'fragment-private', 'customer-123456', 'password', 'https://'] as $sensitive)
        mediaDiagnosticCheck(strpos(json_encode($result), $sensitive) === false, 'redirect values remain private');
}
$probe = new MetaMediaDiagnostic(fn () => ['status' => 302, 'location' => 'https://scontent.fbcdn.net/' . str_repeat('sensitive', 1100), 'body' => '']);
mediaDiagnosticCheck($probe->probe($url)['redirect_validation_reason'] === 'TOO_LONG', 'overlong redirect classified without exposure');
$userAgents = [];
$transport = function ($source, $bytes, $seconds, $userAgent) use (&$userAgents, $gif, $url) {
    mediaDiagnosticCheck($source === $url && $bytes === 20971520 && $seconds === 5, 'browser comparison preserves source and bounds');
    $userAgents[] = $userAgent;
    return count($userAgents) > 1
        ? ['status' => 200, 'content_type' => 'image/gif', 'body' => $gif]
        : ['status' => 302, 'location' => 'https://www.facebook.com/unsupportedbrowser?secret=never-print', 'body' => ''];
};
$baseline = (new MetaMediaDiagnostic($transport))->probe($url);
mediaDiagnosticCheck(MetaMediaDiagnostic::browserComparisonEligible('instagram', $url, $baseline), 'exact Instagram unsupported-browser response enables comparison');
$candidate = (new MetaMediaDiagnostic($transport, MetaMediaDiagnostic::BROWSER_USER_AGENT))->probe($url);
$expectedDefault = method_exists(\App\Services\Dashboard\MetaMediaService::class, 'userAgentForUrl')
    ? \App\Services\Dashboard\MetaMediaService::userAgentForUrl($url) : null;
mediaDiagnosticCheck($candidate['category'] === 'VALID_IMAGE' && $userAgents === [$expectedDefault, MetaMediaDiagnostic::BROWSER_USER_AGENT], 'deployed default and explicit browser agent are passed to the probes');
$otherAgent = false;
(new MetaMediaDiagnostic(function ($source, $bytes, $seconds, $userAgent) use (&$otherAgent, $gif) {
    $otherAgent = $userAgent; return ['status' => 200, 'content_type' => 'image/gif', 'body' => $gif];
}))->probe('https://scontent.fbcdn.net/photo');
mediaDiagnosticCheck($otherAgent === null, 'ordinary CDN probe preserves default agent');
foreach ([['messenger', $url, $baseline], ['instagram', 'https://evil.test/ig_messaging_cdn/', $baseline],
    ['instagram', 'https://lookaside.fbsbx.com/other/', $baseline],
    ['instagram', $url, array_replace($baseline, ['http_status' => 301])],
    ['instagram', $url, array_replace($baseline, ['redirect_observed_host' => 'evil.test'])],
    ['instagram', $url, array_replace($baseline, ['redirect_path_class' => 'LOGIN'])]] as [$channel, $source, $observed])
    mediaDiagnosticCheck(!MetaMediaDiagnostic::browserComparisonEligible($channel, $source, $observed), 'comparison is narrowly gated');
$before = count($userAgents);
mediaDiagnosticCheck((new MetaMediaDiagnostic($transport, MetaMediaDiagnostic::BROWSER_USER_AGENT))->probe('https://evil.test/private')['category'] === 'SOURCE_REJECTED'
    && count($userAgents) === $before, 'browser agent never bypasses source allowlist');
try { new MetaMediaDiagnostic($transport, 'arbitrary-agent-private'); mediaDiagnosticCheck(false, 'arbitrary user agent must fail'); }
catch (InvalidArgumentException $expected) { mediaDiagnosticCheck(true, 'only fixed browser user agent accepted'); }
$probe = new MetaMediaDiagnostic(fn () => throw new RuntimeException('private signed URL'));
mediaDiagnosticCheck($probe->probe($url)['category'] === 'CHECK_UNAVAILABLE', 'exception details suppressed');
$calls = 0; $probe = new MetaMediaDiagnostic(function () use (&$calls) { $calls++; });
mediaDiagnosticCheck($probe->probe('https://evil.test/private')['category'] === 'SOURCE_REJECTED' && $calls === 0, 'unapproved source makes no request');
$pdo = new PDO('sqlite::memory:'); $pdo->exec('CREATE TABLE evidence (id INTEGER)');
$connection = new class($pdo) {
    public function __construct(private PDO $pdo) {} public function getDriverName() { return 'sqlite'; } public function getPdo() { return $this->pdo; }
};
mediaDiagnosticCheck(MetaMediaDiagnostic::readOnlySession($connection) === 'sqlite', 'SQLite read-only session enabled');
try { $pdo->exec('INSERT INTO evidence VALUES (1)'); mediaDiagnosticCheck(false, 'writes must fail'); }
catch (PDOException $expected) { mediaDiagnosticCheck(true, 'SQLite rejects writes'); }
$unsupported = new class { public function getDriverName() { return 'other'; } public function getPdo() { throw new RuntimeException('must not access'); } };
try { MetaMediaDiagnostic::readOnlySession($unsupported); mediaDiagnosticCheck(false, 'unsupported driver must fail'); }
catch (RuntimeException $expected) { mediaDiagnosticCheck($expected->getMessage() === 'READ_ONLY_UNAVAILABLE', 'unsupported driver fails closed'); }
echo "meta-media-diagnostic: $checks checks passed\n";
