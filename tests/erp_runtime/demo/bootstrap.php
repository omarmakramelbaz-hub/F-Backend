<?php
// This entry point is never loaded by the production application.
if (getenv('ERP_DEMO') !== '1' || !in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    http_response_code(404); exit;
}
require_once dirname(__DIR__).'/vendor/autoload.php';

class ErpTrialApplication extends \Orchestra\Testbench\Foundation\Application
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $state = __DIR__.'/state';
        $root = dirname(__DIR__, 3);
        $codespace = getenv('CODESPACE_NAME');
        $domain = getenv('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN') ?: 'app.github.dev';
        $remote = $codespace && preg_match('/^[a-z0-9-]+$/D', $codespace) && preg_match('/^[a-z0-9.-]+$/D', $domain);
        $url = $remote ? 'https://'.$codespace.'-8080.'.$domain : 'http://localhost:8080';
        $app->instance('env', 'erp-trial'); // Keep real CSRF protection enabled.
        $app->useStoragePath($state.'/storage');
        $app['config']->set([
            'app.env'=>'erp-trial', 'app.debug'=>false, 'app.timezone'=>'UTC', 'app.url'=>$url,
            'app.key'=>trim(file_get_contents($state.'/app.key')),
            'erp'=>['enabled'=>true, 'legacy_owner_id'=>1, 'timezone'=>'Africa/Cairo'],
            'auth.defaults.guard'=>'admin',
            'auth.guards'=>[
                'admin'=>['driver'=>'session','provider'=>'legacy'],
                'erp'=>['driver'=>'session','provider'=>'erp_staff'],
            ],
            'auth.providers'=>[
                'legacy'=>['driver'=>'eloquent','model'=>\ErpTests\LegacyOwner::class],
                'erp_staff'=>['driver'=>'eloquent','model'=>\App\Models\Erp\StaffUser::class],
            ],
            // No production .env, providers, connections, queues or mail transports.
            'database.default'=>'sqlite',
            'database.connections'=>['sqlite'=>['driver'=>'sqlite','database'=>$state.'/demo.sqlite','prefix'=>'','foreign_key_constraints'=>true]],
            'session.driver'=>'file', 'session.files'=>$state.'/storage/sessions',
            'session.cookie'=>'fasakhansta_erp_trial', 'session.domain'=>null,
            'session.secure'=>(bool)$remote, 'session.same_site'=>'lax',
            'cache.default'=>'file', 'cache.stores.file'=>['driver'=>'file','path'=>$state.'/storage/cache'],
            'view.paths'=>[$root.'/resources/views', __DIR__.'/views'], 'view.compiled'=>$state.'/storage/views',
            'logging.default'=>'single', 'logging.channels.single'=>['driver'=>'single','path'=>$state.'/storage/app.log'],
            'mail.default'=>'array', 'queue.default'=>'sync',
        ]);
        $app->instance('App\\Models\\GeneralSettings', (object)['site_name'=>'فسخانستا — تجربة','favicon'=>'']);
    }
}

$app = ErpTrialApplication::create(null, null, ['load_environment_variables'=>false, 'extra'=>['dont-discover'=>['*']]]);
\Illuminate\Support\Facades\URL::forceRootUrl(config('app.url'));
\Illuminate\Support\Facades\URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));
return $app;
