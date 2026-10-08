<?php
// Credentials arrive on stdin from the supervisor; they never appear in SQL logs or shell commands.
try {
    if (getenv('DESKTOP_DASHBOARD_LOCAL') !== 'true') throw new RuntimeException('Desktop runtime is disabled.');
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $port = filter_var(getenv('DB_PORT'), FILTER_VALIDATE_INT);
    if (!$port || $port < 1024 || $port > 65535) throw new RuntimeException('Invalid local database port.');
    $pdo = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', $input['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>2]);
    if (($argv[1] ?? '') === 'shutdown') { $pdo->exec('SHUTDOWN'); exit(0); }
    if (!preg_match('/^[a-f0-9]{64}$/D', $input['appPassword'] ?? '')) throw new RuntimeException('Invalid local database credentials.');
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `fasakhansta_dashboard` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec("CREATE USER IF NOT EXISTS 'dashboard'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($input['appPassword']));
    $pdo->exec("GRANT ALL PRIVILEGES ON `fasakhansta_dashboard`.* TO 'dashboard'@'127.0.0.1'");
} catch (Throwable $error) {
    fwrite(STDERR, "تعذر تهيئة قاعدة الداشبورد المحلية.\n");
    exit(1);
}
