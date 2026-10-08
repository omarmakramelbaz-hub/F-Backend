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
    $database=(string)getenv('DB_DATABASE');$stage=($argv[1]??'')==='stage';
    if(!preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$database)
        ||($stage&&!preg_match('/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D',$database)))throw new RuntimeException('Invalid private database name.');
    if($stage){$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
    elseif($database==='fasakhansta_dashboard'){$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
    else{$exists=$pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$database]);
        if(!$exists->fetchColumn())throw new RuntimeException('Active database is missing.');}
    $pdo->exec("CREATE USER IF NOT EXISTS 'dashboard'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($input['appPassword']));
    $pdo->exec("GRANT ALL PRIVILEGES ON `".$database."`.* TO 'dashboard'@'127.0.0.1'");
} catch (Throwable $error) {
    fwrite(STDERR, "تعذر تهيئة قاعدة الداشبورد المحلية.\n");
    exit(1);
}
