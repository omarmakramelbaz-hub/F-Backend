<?php
// Installed native code, executed only by the private supervisor via PHP -r.
// The supervisor has just verified every retained source/native byte against
// its encrypted receipt. This read-only adapter binds that verified source to
// the active database without bootstrapping Laravel and scanning it again.
try {
    if (PHP_SAPI !== 'cli' || getenv('DESKTOP_DASHBOARD_LOCAL') !== 'true'
        || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_USERNAME') !== 'dashboard') {
        throw new RuntimeException('Native local verification required.');
    }
    $port = filter_var(getenv('DB_PORT'), FILTER_VALIDATE_INT);
    $database = (string)getenv('DB_DATABASE');
    $password = (string)getenv('DB_PASSWORD');
    if (!$port || $port < 1024 || $port > 65535
        || !preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D', $database)
        || !preg_match('/^[a-f0-9]{64}$/D', $password)) {
        throw new RuntimeException('Native database binding invalid.');
    }
    $raw = stream_get_contents(STDIN, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) throw new RuntimeException('Native binding too large.');
    $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value) || ($value['format'] ?? null) !== 2
        || !is_string($value['deviceId'] ?? null)
        || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value['deviceId'])
        || $value['deviceId'] !== getenv('DESKTOP_DASHBOARD_DEVICE_ID')
        || !is_int($value['actorId'] ?? null) || $value['actorId'] < 1
        || !is_string($value['snapshotId'] ?? null) || strlen($value['snapshotId']) > 128
        || !is_string($value['schemaHash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $value['schemaHash'])
        || !is_array($value['sourceFingerprint'] ?? null)) {
        throw new RuntimeException('Native snapshot binding invalid.');
    }
    $source = $value['sourceFingerprint'];
    if (count($source) !== 4 || array_diff(array_keys($source), ['format', 'files', 'sha256', 'framework'])
        || ($source['format'] ?? null) !== 1 || !is_int($source['files'] ?? null)
        || $source['files'] < 1 || $source['files'] > 20000
        || !is_string($source['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $source['sha256'])
        || !is_string($source['framework'] ?? null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $source['framework'])) {
        throw new RuntimeException('Verified native source invalid.');
    }
    $json = static fn($item): string => json_encode($item,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $pdo = new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4',
        'dashboard', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $one = static function (string $table) use ($pdo, $value): array {
        // Table names are fixed by this installed adapter, never input values.
        $query = $pdo->prepare('SELECT * FROM `'.$table.'` WHERE device_id=? LIMIT 2');
        $query->execute([$value['deviceId']]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) throw new RuntimeException('Snapshot record invalid.');
        return $rows[0];
    };
    $state = $one('desktop_dashboard_local_state');
    $manifest = $one('desktop_dashboard_media_manifest');
    $boundSource = $one('desktop_dashboard_source_manifest');
    if ($state['snapshot_id'] !== $value['snapshotId'] || $manifest['snapshot_id'] !== $state['snapshot_id']
        || $boundSource['snapshot_id'] !== $state['snapshot_id']
        || (int)$state['actor_id'] !== $value['actorId'] || $state['schema_hash'] !== $value['schemaHash']
        || !hash_equals($boundSource['sha256'], hash('sha256', $json($source)))
        || json_decode($boundSource['source'], true, 32, JSON_THROW_ON_ERROR) !== $source) {
        throw new RuntimeException('Snapshot source binding changed.');
    }
    if (($value['previewOnly'] ?? false) === true
        && (($value['fullCoverage'] ?? null) !== false
            || json_decode($state['coverage'], true, 32, JSON_THROW_ON_ERROR) !== ($value['coverage'] ?? null)
            || json_decode($state['branches'], true, 32, JSON_THROW_ON_ERROR) !== ($value['branches'] ?? null))) {
        throw new RuntimeException('Preview scope binding changed.');
    }
    if (!is_string($manifest['files']) || strlen($manifest['files']) > 20 * 1024 * 1024) {
        throw new RuntimeException('Media binding too large.');
    }
    $files = json_decode($manifest['files'], true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($files) || !array_is_list($files) || count($files) > 20000
        || !hash_equals($manifest['sha256'], hash('sha256', $json($files)))) {
        throw new RuntimeException('Media binding changed.');
    }
    if (($value['previewOnly'] ?? false) === true) {
        $canonical = array_map(static function (array $part): array { ksort($part, SORT_STRING); return $part; }, $files);
        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        if (!is_string($value['mediaManifestHash'] ?? null)
            || !hash_equals($value['mediaManifestHash'], hash('sha256', $encoded))) {
            throw new RuntimeException('Preview media binding changed.');
        }
    }
    $pdo->commit();
    echo $json(['format' => 1, 'kind' => 'native-runtime-media-binding', 'snapshotId' => $value['snapshotId'],
        'actorId' => $value['actorId'], 'sourceFingerprint' => $source, 'files' => $files]).PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "GO_ERP_RUNTIME_MEDIA_BINDING_FAILED\n");
    exit(1);
}
