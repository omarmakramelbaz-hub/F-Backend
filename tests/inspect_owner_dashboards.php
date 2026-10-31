<?php
declare(strict_types=1);

require __DIR__.'/../deployment/inspect_owner_dashboards.php';
use GoErp\Operations\InspectOwnerDashboards as Audit;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,name TEXT,email TEXT,mobile TEXT,account_type TEXT,status TEXT,added_by INTEGER,owner_resturant_id INTEGER,pending_vendor_id INTEGER,password TEXT,remember_token TEXT)');
$db->exec('CREATE TABLE resturants (id INTEGER PRIMARY KEY,name TEXT)');
$db->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY,name TEXT)');
$db->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY,name TEXT)');
$db->exec("INSERT INTO resturants VALUES (7,'Existing branch'); INSERT INTO roles VALUES(1,'Super Admin'); INSERT INTO permissions VALUES(1,'admin-list')");
$insert = $db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute([1,'Owner','owner@example.invalid',null,'admin','accepted',null,null,null,'SECRET_OWNER_PASSWORD_HASH','SECRET_OWNER_REMEMBER_TOKEN']);
$insert->execute([9,'Existing admin','manager@example.invalid','+20 106-446-4499','admin','accepted',1,7,19,'SECRET_OTHER_PASSWORD_HASH','SECRET_OTHER_REMEMBER_TOKEN']);
$insert->execute([10,'Restaurant owner','restaurant@example.invalid','١٠٦٤٤٦٤٤٩٩','resturant_owner','accepted',1,7,null,'SECRET_RESTAURANT_PASSWORD_HASH','SECRET_RESTAURANT_REMEMBER_TOKEN']);
$insert->execute([11,'Customer','customer@example.invalid','01064464499','client','accepted',null,null,null,'SECRET_CLIENT_PASSWORD_HASH',null]);
$before = $db->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$report = Audit::inspect($db, '01064464499');
check($report['status'] === 'GO_OWNER_DASHBOARDS_INSPECTED' && $report['read_only'], 'Wrong status');
check($report['phone_conflict']['conflicting_account_ids'] === [9,10], 'Missed formatted/Arabic phone or included non-dashboard customer');
check($report['primary_owner']['id'] === 1 && !$report['primary_owner']['has_mobile'], 'Wrong primary owner');
check($report['phone_conflict']['matching_dashboard_accounts'][0]['restaurant_id'] === 7, 'Missing phone ownership evidence');
check(!$report['phone_conflict']['phone_reassigned'] && !$report['changes_applied'], 'Audit claims mutation');
check($before === $db->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Audit mutated accounts');
check((int)$db->query('SELECT COUNT(*) FROM resturants')->fetchColumn() === 1, 'Audit mutated branches');
check(!$db->inTransaction() && (int)$db->query('PRAGMA query_only')->fetchColumn() === 0, 'Connection state not restored');
$encoded = json_encode($report, JSON_THROW_ON_ERROR);
check(!str_contains($encoded,'SECRET_') && !str_contains($encoded,'remember_token') && !str_contains($encoded,'password'), 'Credentials in report');
check(!str_contains($encoded,'customer@example.invalid') && !str_contains($encoded,'1064464499'), 'Unrelated customer or full phone in report');
check($report['schema']['table_count'] === 4 && $report['schema']['bootstrap_table_counts']['resturants'] === 1, 'Wrong schema inventory');
check(strlen($report['schema']['definition_sha256']) === 64, 'Schema fingerprint missing');
foreach (['01064464499','1064464499','+201064464499','201064464499','00201064464499','٠١٠٦٤٤٦٤٤٩٩','۰۱۰۶۴۴۶۴۴۹۹'] as $format) {
    check(Audit::phone($format) === '1064464499', 'Normalization mismatch');
}
foreach (['+971501234567','abc1064464499','010644644990','1e064464499'] as $invalid) {
    check(Audit::phone($invalid) === null, 'Invalid phone accepted');
}
$noConflict = Audit::inspect($db, '01123456789');
check($noConflict['phone_conflict']['matching_dashboard_accounts'] === [], 'Invented a conflict');
$db->exec('PRAGMA query_only=ON'); Audit::inspect($db,'01064464499');
check((int)$db->query('PRAGMA query_only')->fetchColumn() === 1, 'Removed existing read-only state');
$db->exec('PRAGMA query_only=OFF');
$db->beginTransaction();
try { Audit::inspect($db,'01064464499'); throw new LogicException('Nested transaction accepted'); }
catch (RuntimeException $e) { check($e->getMessage() === 'GO_OWNER_AUDIT_UNSUPPORTED_CONNECTION', 'Unexpected nested error'); }
check($db->inTransaction(), 'Caller transaction was rolled back'); $db->rollBack();
$db->exec('DROP TABLE users');
try { Audit::inspect($db,'01064464499'); throw new LogicException('Missing users accepted'); }
catch (RuntimeException $e) { check($e->getMessage() === 'GO_OWNER_AUDIT_USERS_SCHEMA_REQUIRED', 'Unexpected schema error'); }
check(!$db->inTransaction() && (int)$db->query('PRAGMA query_only')->fetchColumn() === 0, 'Exception left read-only transaction open');

$privileges = Audit::privileges(["GRANT ALL PRIVILEGES ON `example`.* TO `account`@`localhost` IDENTIFIED BY PASSWORD 'SECRET_HASH'",
    'GRANT USAGE ON *.* TO `account`@`localhost`']);
check($privileges === ['global_create_database'=>false,'global_create_user'=>false,'global_grant_option'=>false], 'Schema privileges misreported as global');
$privileges = Audit::privileges(['GRANT CREATE, CREATE USER ON *.* TO `operator`@`localhost` WITH GRANT OPTION']);
check($privileges === ['global_create_database'=>true,'global_create_user'=>true,'global_grant_option'=>true], 'Global privileges not detected');
check(!str_contains(json_encode($privileges),'SECRET_HASH'), 'Grant credentials leaked');
echo 'Owner dashboard inspection: '.$checks." checks passed\n";
