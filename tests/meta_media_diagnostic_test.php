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
