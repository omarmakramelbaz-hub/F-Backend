<?php
declare(strict_types=1);

require __DIR__.'/../deployment/remove_smartvision_account.php';

use GoErp\Operations\RemoveSmartVisionAccount;

$checks = 0;
function verify(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
}
function linkedFixture(): PDO
{
    $dsn = getenv('GO_SMARTVISION_TEST_DSN') ?: 'sqlite::memory:';
    if ($dsn !== 'sqlite::memory:' && !preg_match('#^mysql:unix_socket=/workspace/scratch/[A-Za-z0-9_./-]+\.sock;dbname=smartvision_fixture_[A-Za-z0-9_]+$#D', $dsn)) {
        throw new RuntimeException('Only a disposable scratch socket/database is allowed');
    }
    $db = new PDO($dsn, $dsn === 'sqlite::memory:' ? null : 'root');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    if ($dsn === 'sqlite::memory:') { $db->exec('PRAGMA foreign_keys=ON'); }
    foreach (['linked', 'pending_vendors', 'orders', 'users'] as $table) { $db->exec('DROP TABLE IF EXISTS '.$table); }
    $db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name VARCHAR(200),email VARCHAR(200),account_type VARCHAR(30),status VARCHAR(30),password VARCHAR(200),added_by INTEGER NOT NULL,updated_at VARCHAR(30),FOREIGN KEY(added_by) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE)');
    $db->exec("INSERT INTO users VALUES(1,'fasakhaninja','owner@test.invalid','admin','accepted','owner-hash',1,'2026-10-31'),(560,'smartvision','admin@smartvision.com','admin','accepted','target-hash',1,'2026-10-31'),(900,'Fasakhansta','other@test.invalid','admin','accepted','other-hash',560,'2026-10-31'),(901,'other','untouched@test.invalid','admin','accepted','untouched-hash',1,'2026-10-31')");
    $db->exec('CREATE TABLE pending_vendors(id INTEGER PRIMARY KEY,added_by INTEGER NOT NULL,name VARCHAR(200),description VARCHAR(200),updated_at VARCHAR(30),FOREIGN KEY(added_by) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE)');
    $insert = $db->prepare('INSERT INTO pending_vendors VALUES(?,?,?,?,?)');
    for ($i = 1; $i <= 9; $i++) { $insert->execute([$i,560,'pending'.$i,'retained details '.$i,'2026-10-31']); }
    $insert->execute([99,1,'untouched','owner details','2026-10-31']);
    $db->exec('CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,amount INTEGER,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('INSERT INTO orders VALUES(7,900,12500)');
    return $db;
}
function snapshot(PDO $db): array
{
    $result = [];
    foreach (['users','pending_vendors','orders'] as $table) {
        $result[$table] = $db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}
function refusal(PDO $db, string $directory, string $expected): void
{
    $before = snapshot($db);
    try {
        RemoveSmartVisionAccount::run($db, $directory, 'base64_encode', true);
        throw new RuntimeException('Missing refusal');
    } catch (RuntimeException $error) {
        verify($error->getMessage() === $expected, 'Expected '.$expected.', got '.$error->getMessage());
    }
    verify(snapshot($db) === $before, 'Refusal changed rows');
    verify(!$db->inTransaction(), 'Refusal left transaction open');
}

$directory = sys_get_temp_dir().'/go-smartvision-preserve-fixture-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $db = linkedFixture();
    $before = snapshot($db);
    $report = RemoveSmartVisionAccount::inspect($db);
    verify($report['read_only'] && $report['owner_active'], 'Inspection failed');
    $links = []; foreach ($report['linked'] as $link) { $links[$link['table']] = $link['rows']; }
    ksort($links);
    verify($links === ['pending_vendors'=>9,'users'=>1], 'Fixture does not match the reviewed live links');
    verify(snapshot($db) === $before, 'Inspection changed data');
    try { RemoveSmartVisionAccount::run($db, $directory, 'base64_encode'); throw new RuntimeException('Plain deletion must refuse'); }
    catch (RuntimeException $error) { verify($error->getMessage() === 'SMARTVISION_LINKED_ROWS_REQUIRE_REVIEW', 'Plain deletion bypassed linked rows'); }
    $result = RemoveSmartVisionAccount::run($db, $directory, 'base64_encode', true);
    verify($result['status'] === 'SMARTVISION_ACCOUNT_DELETED' && $result['id'] === 560, 'Wrong deletion result');
    verify($result['preserved_links'] === ['pending_vendors'=>9,'users'=>1] && $result['reassigned_to_user_id'] === 1, 'Missing preservation receipt');
    $expected = $before;
    $expected['users'] = array_values(array_filter($expected['users'], static fn($row) => (int) $row['id'] !== 560));
    foreach (['users','pending_vendors'] as $table) {
        foreach ($expected[$table] as &$row) { if ((int) $row['added_by'] === 560) { $row['added_by'] = 1; } } unset($row);
    }
    verify(snapshot($db) === $expected, 'Something besides the reviewed creator links/account changed');
    $files = glob($directory.'/*.enc');
    verify(count($files) === 1, 'Missing single recovery backup');
    $saved = json_decode(base64_decode(file_get_contents($files[0]), true), true, 512, JSON_THROW_ON_ERROR);
    verify($saved['format'] === 2 && (int) $saved['row']['id'] === 560, 'Backup format/target wrong');
    $originalPending = array_values(array_filter($before['pending_vendors'], static fn($row) => (int) $row['added_by'] === 560));
    $originalUsers = array_values(array_filter($before['users'], static fn($row) => (int) $row['added_by'] === 560));
    verify($saved['reassignment']['tables']['pending_vendors']['rows'] === $originalPending, 'Pending-vendor recovery rows changed');
    verify($saved['reassignment']['tables']['users']['rows'] === $originalUsers, 'User recovery rows changed');
    verify((fileperms($files[0]) & 0077) === 0, 'Recovery backup is not private');
    verify(RemoveSmartVisionAccount::run($db, $directory, 'base64_encode', true)['status'] === 'SMARTVISION_ACCOUNT_ALREADY_ABSENT', 'Repeat is not idempotent');
    verify(snapshot($db) === $expected, 'Repeat changed rows');

    $db = linkedFixture(); $db->exec('DELETE FROM pending_vendors WHERE id=9');
    refusal($db, $directory, 'SMARTVISION_REASSIGNMENT_STATE_CHANGED');
    $db = linkedFixture(); $db->exec('UPDATE users SET added_by=560 WHERE id=901');
    refusal($db, $directory, 'SMARTVISION_REASSIGNMENT_STATE_CHANGED');
    $db = linkedFixture(); $db->exec("UPDATE users SET status='declined' WHERE id=1");
    refusal($db, $directory, 'SMARTVISION_ACTIVE_OWNER_REQUIRED');
    $db = linkedFixture();
    $db->exec('CREATE TABLE linked(id INTEGER PRIMARY KEY,user_id INTEGER,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('INSERT INTO linked VALUES(1,560)');
    refusal($db, $directory, 'SMARTVISION_UNREVIEWED_LINKS');
    verify((int) $db->query('SELECT user_id FROM linked')->fetchColumn() === 560, 'Unreviewed dependency changed');

    $db = linkedFixture();
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $db->exec($mysql ? 'CREATE TRIGGER unexpected_update AFTER UPDATE ON pending_vendors FOR EACH ROW DELETE FROM orders'
        : 'CREATE TRIGGER unexpected_update AFTER UPDATE ON pending_vendors BEGIN DELETE FROM orders; END');
    refusal($db, $directory, 'SMARTVISION_UPDATE_SIDE_EFFECTS_REQUIRE_REVIEW');

    $db = linkedFixture();
    $db->exec('CREATE TABLE linked(id INTEGER PRIMARY KEY,source INTEGER,FOREIGN KEY(source) REFERENCES users(added_by))');
    refusal($db, $directory, 'SMARTVISION_UPDATE_SIDE_EFFECTS_REQUIRE_REVIEW');

    // A constraint failure on the second move must undo the first successful move too.
    $db = linkedFixture();
    $db->exec("UPDATE pending_vendors SET name='pending2' WHERE id=99");
    $db->exec('CREATE UNIQUE INDEX unique_creator_name ON pending_vendors(added_by,name)');
    $beforeFailure = snapshot($db);
    try { RemoveSmartVisionAccount::run($db, $directory, 'base64_encode', true); throw new RuntimeException('Expected unique-constraint failure'); }
    catch (PDOException $error) { verify(true, 'Expected real database constraint failure'); }
    verify(snapshot($db) === $beforeFailure, 'Failure left a partial reassignment/deletion');
    verify(!$db->inTransaction(), 'Constraint failure left transaction open');

    $db = linkedFixture();
    refusal($db, $directory.'/missing', 'SMARTVISION_PRIVATE_BACKUP_DIRECTORY_REQUIRED');
    if ($mysql) {
        $db->exec('ALTER TABLE pending_vendors DROP FOREIGN KEY '.($db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pending_vendors' AND COLUMN_NAME='added_by'")->fetchColumn()));
        $db->exec('ALTER TABLE pending_vendors ENGINE=MyISAM');
        refusal($db, $directory, 'SMARTVISION_TRANSACTIONAL_LINKS_REQUIRED');
    }
    echo $checks.' preservation checks passed ('.$db->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
} finally {
    foreach (glob($directory.'/*') as $file) { unlink($file); }
    rmdir($directory);
}
