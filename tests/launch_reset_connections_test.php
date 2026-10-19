<?php
declare(strict_types=1);
require __DIR__.'/../deployment/launch_reset/check_connections.php';
function launchConnectionExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$secret = 'password=SECRET-credential SELECT customer_phone FROM crm_customers remote.example';
$base = ['Id' => '42', 'db' => 'private_db', 'Command' => 'Sleep', 'Time' => '80', 'State' => '', 'Host' => $secret, 'User' => $secret, 'Info' => $secret];
$item = launchConnectionSummary($base, 'private_db', '41', ['42' => true], true);
launchConnectionExpect($item === ['id' => '42', 'command' => 'Sleep', 'seconds' => 80, 'state_class' => 'idle', 'open_transaction' => true], 'Sleeping transaction was not identified');
launchConnectionExpect(launchConnectionSummary($base, 'private_db', '42', [], true) === null, 'Self connection included');
launchConnectionExpect(launchConnectionSummary(array_replace($base, ['db' => 'unrelated_db']), 'private_db', '41', [], true) === null, 'Unrelated database included');
$unknown = launchConnectionSummary(array_replace($base, ['Command' => $secret, 'Time' => $secret, 'State' => $secret]), 'private_db', '41', [], false);
launchConnectionExpect($unknown['command'] === 'Other' && $unknown['seconds'] === null && $unknown['state_class'] === 'working' && $unknown['open_transaction'] === null, 'Unknown fields or missing privilege misreported');
$locked = launchConnectionSummary(array_replace($base, ['Command' => 'Query', 'State' => 'Waiting for table metadata lock']), 'private_db', '41', [], true);
launchConnectionExpect($locked['state_class'] === 'lock_wait' && $locked['open_transaction'] === false, 'Lock wait not classified');
foreach ([$secret, '-2', '0', '4.2'] as $id) launchConnectionExpect(launchConnectionSummary(array_replace($base, ['Id' => $id]), 'private_db', '41', [], true) === null, 'Untrusted connection ID included');
$settings = launchConnectionSettings(['innodb_table_locks' => '1', 'autocommit' => '1', 'version' => '10.11.19-MariaDB-cll-lve', 'session_wait_timeout' => '28800', 'global_wait_timeout' => '28800']);
launchConnectionExpect($settings === ['innodb_table_locks' => 1, 'autocommit' => 1, 'session_wait_timeout' => 28800, 'global_wait_timeout' => 28800, 'version' => '10.11.19-MariaDB'], 'Server settings normalization wrong');
$unsafeSettings = launchConnectionSettings(['version' => '10.11.19-'.$secret, 'session_wait_timeout' => $secret]);
$encoded = json_encode([$item, $unknown, $locked, $settings, $unsafeSettings], JSON_THROW_ON_ERROR);
foreach (['SECRET', 'password', 'SELECT', 'customer_phone', 'remote.example', 'private_db', 'unrelated_db'] as $forbidden) launchConnectionExpect(!str_contains($encoded, $forbidden), 'Sensitive field leaked');
echo "PASS: read-only connection summary filters self/other databases, preserves transaction uncertainty, classifies lock waits and omits SQL/hosts/users/credentials\n";
