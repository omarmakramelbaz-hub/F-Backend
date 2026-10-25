<?php
// Disposable fixture only. Every ordinary request still runs the packaged router.
$application=getenv('DESKTOP_TEST_APPLICATION');
if(!$application||getenv('APP_ENV')!=='desktop'||getenv('DESKTOP_DASHBOARD_LOCAL')!=='true'){
    http_response_code(403);exit;
}
if(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)!=='/__desktop_bootstrap_probe'){
    require $application.'/desktop/router.php';return;
}
$origin=getenv('DESKTOP_DASHBOARD_ORIGIN');$token=getenv('DESKTOP_DASHBOARD_TOKEN');
$expectedHost=parse_url($origin,PHP_URL_HOST).':'.parse_url($origin,PHP_URL_PORT);
if(!$token||!hash_equals($token,$_SERVER['HTTP_X_FASAKHANSTA_DESKTOP']??'')
    ||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'
    ||($_SERVER['HTTP_HOST']??'')!==$expectedHost){
    http_response_code(403);exit;
}
$app=require $application.'/desktop/bootstrap.php';
$boundPath=$app['request']->getPathInfo();
$settings=app(\Spatie\LaravelSettings\SettingsContainer::class);
if(($_GET['baseline']??'')==='1'){
    // Reconstruct the two former eager paths in this disposable worker only.
    // No application data, middleware or production configuration is changed.
    config(['settings.auto_discover_settings'=>[app()->path()]]);
    $settings->clearCache()->getSettingClasses();
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}
$includedAppFiles=0;$prefix=str_replace('\\','/',realpath($application.'/app')).'/';
foreach(get_included_files() as $file)if(str_starts_with(str_replace('\\','/',$file),$prefix))$includedAppFiles++;
header('Content-Type: application/json');header('Cache-Control: no-store');
echo json_encode([
    'sapi'=>PHP_SAPI,'boundPath'=>$boundPath,'discovery'=>config('settings.auto_discover_settings'),
    'settings'=>$settings->getSettingClasses()->values()->all(),'includedAppFiles'=>$includedAppFiles,
    'privateProvider'=>get_class(auth('admin')->getProvider()),
    'consoleCommandLoaded'=>class_exists(\App\Console\Commands\CouponWheelCommand::class,false),
    'unrelatedWorkflowLoaded'=>class_exists(\App\Services\Dashboard\WhatsAppOrderWorkflow::class,false),
],JSON_THROW_ON_ERROR);
