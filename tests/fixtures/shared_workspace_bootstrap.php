<?php
/** Disposable full-helper CI bootstrap; real framework/container/PDO, no web app. */
if (PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('APP_ENV')!=='testing') throw new RuntimeException('FIXTURE_ONLY');
$root='/home/fasakha/public_html';
require_once $root.'/vendor/autoload.php';
$app=new Illuminate\Foundation\Application($root);
$config=new Illuminate\Config\Repository([
    'app'=>['env'=>'testing','debug'=>false,'timezone'=>'UTC'],
    'desktop_dashboard'=>['local'=>false,'workspace_enabled'=>false],
    'database'=>['default'=>'mysql','connections'=>['mysql'=>[
        'driver'=>'mysql','host'=>'127.0.0.1','port'=>getenv('GO_ERP_FIXTURE_PORT'),
        'database'=>getenv('GO_ERP_FIXTURE_DATABASE'),'username'=>'root','password'=>'test-only',
        'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,
        'engine'=>'InnoDB','options'=>[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION],
    ]]],
]);
$app->instance('config',$config);
$events=new Illuminate\Events\Dispatcher($app);$app->instance('events',$events);
Illuminate\Support\Facades\Facade::clearResolvedInstances();
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\Database\DatabaseServiceProvider::class);
$app->instance(Illuminate\Contracts\Console\Kernel::class,new class($app,$events) extends Illuminate\Foundation\Console\Kernel {
    // DB provider/config above replace only application bootstrapping. The SQL
    // helper's complete guarded body and real database connection are untouched.
    public function bootstrap() {}
});
$app->boot();
$profile=getenv('GO_ERP_FIXTURE_TIMESTAMP');
if (!in_array($profile,['0','1'],true)) throw new RuntimeException('FIXTURE_TIMESTAMP_PROFILE');
Illuminate\Support\Facades\DB::connection()->statement('SET SESSION explicit_defaults_for_timestamp = '.$profile);
return $app;
