<?php
declare(strict_types=1);

require __DIR__.'/../deployment/remove_smartvision_account.php';

use GoErp\Operations\RemoveSmartVisionAccount;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
}
function fixture(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT, email TEXT, account_type TEXT, status TEXT, password TEXT)');
    $db->exec("INSERT INTO users VALUES(1,'fasakhaninja','owner@test.invalid','admin','accepted','owner-hash'),(560,'smartvision','admin@smartvision.com','admin','accepted','target-hash'),(900,'Fasakhansta','other@test.invalid','admin','accepted','other-hash')");
    $db->exec('CREATE TABLE orders(id INTEGER PRIMARY KEY,admin_id INTEGER,total INTEGER)');
    $db->exec('INSERT INTO orders VALUES(7,560,12500)');
    return $db;
}
function deny(PDO $db, string $directory, string $expected, ?callable $encrypt = null): void
{
    $before = $db->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    try {
        RemoveSmartVisionAccount::run($db, $directory, $encrypt ?? 'base64_encode');
        throw new RuntimeException('Expected refusal: '.$expected);
    } catch (RuntimeException $error) {
        check($error->getMessage() === $expected, 'Expected '.$expected.', received '.$error->getMessage());
    }
    check($db->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'Refusal changed accounts');
    check(!$db->inTransaction(), 'Refusal left an open transaction');
}

$directory = sys_get_temp_dir().'/go-smartvision-fixture-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $db = fixture();
    $others = $db->query('SELECT * FROM users WHERE id<>560 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $orders = $db->query('SELECT * FROM orders')->fetchAll(PDO::FETCH_ASSOC);
    $result = RemoveSmartVisionAccount::run($db, $directory, 'base64_encode');
    check($result['status'] === 'SMARTVISION_ACCOUNT_DELETED', 'Account not deleted');
    check($result['id'] === 560, 'Wrong account removed');
    check($db->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $others, 'Other accounts changed');
    check($db->query('SELECT * FROM orders')->fetchAll(PDO::FETCH_ASSOC) === $orders, 'Business history changed');
    $files = glob($directory.'/*.enc');
    check(count($files) === 1, 'Missing backup');
    $backup = json_decode(base64_decode(file_get_contents($files[0]), true), true, 512, JSON_THROW_ON_ERROR);
    check($backup['row']['password'] === 'target-hash' && $backup['row']['id'] === 560, 'Backup cannot recover exact row');
    check((fileperms($files[0]) & 0077) === 0, 'Backup is not private');
    check(RemoveSmartVisionAccount::run($db, $directory, 'base64_encode')['status'] === 'SMARTVISION_ACCOUNT_ALREADY_ABSENT', 'Repeat is not safe');
    check(count(glob($directory.'/*.enc')) === 1, 'Repeat created another backup');

    $db = fixture(); $db->exec("UPDATE users SET status='declined' WHERE id=1");
    deny($db, $directory, 'SMARTVISION_ACTIVE_OWNER_REQUIRED');
    $db = fixture(); $db->exec("UPDATE users SET name='another account' WHERE id=560");
    deny($db, $directory, 'SMARTVISION_IDENTITY_MISMATCH');
    $db = fixture(); $db->exec("UPDATE users SET account_type='user' WHERE id=560");
    deny($db, $directory, 'SMARTVISION_IDENTITY_MISMATCH');
    $db = fixture(); $db->exec("UPDATE users SET email='other@test.invalid' WHERE id=560; UPDATE users SET email='admin@smartvision.com',name='smartvision' WHERE id=1");
    deny($db, $directory, 'SMARTVISION_IDENTITY_MISMATCH');
    $db = fixture(); $db->exec("INSERT INTO users VALUES(561,'smartvision','ADMIN@SMARTVISION.COM','admin','accepted','duplicate')");
    deny($db, $directory, 'SMARTVISION_AMBIGUOUS_EMAIL');
    $db = fixture();
    deny($db, $directory.'/missing', 'SMARTVISION_PRIVATE_BACKUP_DIRECTORY_REQUIRED');
    deny($db, $directory, 'SMARTVISION_ENCRYPTED_BACKUP_REQUIRED', static fn($json) => $json);
    deny($db, $directory, 'SMARTVISION_ENCRYPTED_BACKUP_REQUIRED', static fn($json) => '');

    foreach (['CASCADE', 'SET NULL', 'RESTRICT'] as $action) {
        $db = fixture();
        $db->exec('CREATE TABLE linked(id INTEGER PRIMARY KEY,user_id INTEGER REFERENCES users(id) ON DELETE '.$action.')');
        $db->exec('INSERT INTO linked VALUES(5,560)');
        deny($db, $directory, 'SMARTVISION_LINKED_ROWS_REQUIRE_REVIEW');
        check($db->query('SELECT user_id FROM linked')->fetchColumn() == 560, $action.' modified linked history');
    }
    $db = fixture();
    $db->exec("CREATE TRIGGER destructive AFTER DELETE ON users BEGIN DELETE FROM orders; END");
    deny($db, $directory, 'SMARTVISION_DELETE_TRIGGER_REQUIRES_REVIEW');
    check($db->query('SELECT COUNT(*) FROM orders')->fetchColumn() == 1, 'Trigger deleted history');

    $db = fixture();
    $db->exec("UPDATE users SET email=' ADMIN@SMARTVISION.COM ',name=' SmartVision ' WHERE id=560");
    check(RemoveSmartVisionAccount::run($db, $directory, 'base64_encode')['status'] === 'SMARTVISION_ACCOUNT_DELETED', 'Case/space-normalized identity failed');
    echo $checks." checks passed\n";
} finally {
    foreach (glob($directory.'/*') as $file) { unlink($file); }
    rmdir($directory);
}
