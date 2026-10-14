<?php
require rtrim((string) getenv('WA_INBOX_TEST_VENDOR'), '/') . '/autoload.php';

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use App\Services\Dashboard\MetaCommentsReadCache;

require __DIR__ . '/../app/Services/Dashboard/MetaCommentsReadCache.php';
$container = new Container;
$store = new ArrayStore;
$container->instance('cache', new Repository($store));
$container->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
Facade::setFacadeApplication($container);
$cache = new MetaCommentsReadCache;
$checks = 0;
function check($condition, string $label): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($label);
}
$account = str_repeat('a', 64);
$binding = str_repeat('b', 64);
$query = ['fields' => 'id,message', 'limit' => 20, 'appsecret_proof' => 'private-proof'];
$key = $cache->key($binding, $account, '101/published_posts', $query);
check((bool) preg_match('/\Ameta-comments-read:v1:[a-f0-9]{64}\z/', $key), 'keys are opaque fixed identifiers');
check($cache->read($key) === null, 'empty cache falls through to live read');
$body = ['data' => [['id' => '101_501', 'message' => 'private customer text']],
    'paging' => ['cursors' => ['after' => 'opaque-cursor'], 'next' => 'https://graph.facebook.com/?access_token=private-token']];
$cache->write($key, '101/published_posts', $body);
check($cache->read($key) === $body, 'successful provider snapshot round trips');
$encrypted = Cache::get($key);
foreach (['private customer text', 'private-token', '101_501', 'opaque-cursor'] as $value) {
    check(strpos($encrypted, $value) === false, 'cache storage encrypts provider content and paging');
}
$plain = json_decode(Crypt::decryptString($encrypted), true);
check($plain['expires'] >= time() + 29 && $plain['expires'] <= time() + 30, 'post freshness bounded to thirty seconds');
check($cache->key($binding, $account, '101/published_posts', array_reverse($query, true)) === $key, 'query order does not cause duplicate provider calls');
$query['appsecret_proof'] = 'rotated-proof';
check($cache->key($binding, $account, '101/published_posts', $query) === $key, 'proof never participates in key material');
check($cache->key(str_repeat('c', 64), $account, '101/published_posts', $query) !== $key, 'token binding rotation cannot reuse old snapshots');
check($cache->key($binding, str_repeat('d', 64), '101/published_posts', $query) !== $key, 'separate account snapshots');
$query['after'] = 'next-cursor';
check($cache->key($binding, $account, '101/published_posts', $query) !== $key, 'pagination is part of the cache identity');
$commentKey = $cache->key($binding, $account, '101_501/comments', ['limit' => 25]);
$cache->write($commentKey, '101_501/comments', ['data' => []]);
$snapshot = json_decode(Crypt::decryptString(Cache::get($commentKey)), true);
check($snapshot['expires'] >= time() + 9 && $snapshot['expires'] <= time() + 10, 'comments refresh within ten seconds');
$replyKey = $cache->key($binding, $account, '700/replies', ['limit' => 25]);
$cache->write($replyKey, '700/replies', ['data' => []]);
$snapshot = json_decode(Crypt::decryptString(Cache::get($replyKey)), true);
check($snapshot['expires'] >= time() + 9 && $snapshot['expires'] <= time() + 10, 'replies refresh within ten seconds');
$cache->invalidate($account);
$newKey = $cache->key($binding, $account, '101/published_posts', ['fields' => 'id,message', 'limit' => 20]);
check($newKey !== $key && $cache->read($newKey) === null, 'accepted send invalidates every account snapshot');
$cache->write($key, '101/published_posts', $body);
check($cache->read($newKey) === null, 'inflight pre-send read cannot repopulate new generation');
$cache->write($newKey, '101/published_posts', ['error' => ['code' => 190]]);
check(Cache::get($newKey) === null, 'provider errors are never cached');
$cache->write($newKey, '101/published_posts', ['data' => 'malformed']);
check(Cache::get($newKey) === null, 'malformed data is never cached');
$cache->write($newKey, '101/published_posts', ['data' => [['message' => str_repeat('x', 524289)]]]);
check(Cache::get($newKey) === null, 'oversize content bypasses caching');
Cache::put($newKey, 'invalid-ciphertext', 30);
check($cache->read($newKey) === null, 'corrupt cache bypasses caching');
Cache::put($newKey, Crypt::encryptString(json_encode(['expires' => time() - 1, 'body' => $body])), 30);
check($cache->read($newKey) === null, 'expired payload never used even if cache backend retained it');
foreach (['https://evil.test/', '101/likes', '../101/comments', '101/comments?access_token=private'] as $invalid) {
    check($cache->key($binding, $account, $invalid, []) === null, 'invalid path cannot acquire cache identity');
}
check($cache->key('private-token', $account, '101/comments', []) === null, 'binding must be opaque credential fingerprint');
check($cache->read('attacker-chosen-key') === null, 'read keys validated');
$container->instance('cache', new class {
    public function get(...$args) { throw new RuntimeException('cache unavailable'); }
    public function put(...$args) { throw new RuntimeException('cache unavailable'); }
});
Cache::clearResolvedInstance('cache');
check($cache->key($binding, $account, '101/comments', []) === null && $cache->read($key) === null, 'cache outage fails open to live reads');
$cache->write($key, '101/comments', ['data' => []]);
$cache->invalidate($account);
echo "meta-comments-read-cache: $checks checks passed\n";
