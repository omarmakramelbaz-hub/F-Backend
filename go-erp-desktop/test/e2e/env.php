<?php
// Settings for the end-to-end check's local dashboard: a throwaway SQLite database, no .env file needed.
$work = getenv('GOERP_E2E_DIR') ?: sys_get_temp_dir().'/goerp-e2e';
if (!is_dir($work)) mkdir($work, 0777, true);
$values = [
    'APP_ENV' => 'local', 'APP_DEBUG' => 'true', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('d', 32)),
    'APP_URL' => 'http://127.0.0.1', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $work.'/server.sqlite',
    'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_DRIVER' => 'log',
    'LOG_CHANNEL' => 'single', 'MAIL_MAILER' => 'log',
];
foreach ($values as $key => $value) { putenv("$key=$value"); $_ENV[$key] = $value; $_SERVER[$key] = $value; }
define('GOERP_ROOT', dirname(__DIR__, 3));
require GOERP_ROOT.'/vendor/autoload.php';

function goerp_settings($app): void
{
    $settings = [];
    foreach ((new \ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
        $settings[$property->getName()] = (string) $property->getType() === 'bool' ? false : '';
    }
    $settings['site_name'] = 'فسخانستا';
    $settings['service_fees'] = '0';
    $app->instance(\App\Models\GeneralSettings::class, new \App\Models\GeneralSettings($settings));
}
