<?php
namespace App\Providers;

use App\Services\OwnerDashboards\Runtime;
use Illuminate\Support\Facades\{Gate,Route};
use Illuminate\Support\ServiceProvider;

final class OwnerDashboardServiceProvider extends ServiceProvider
{
    public function register()
    {
        if(!Runtime::tenant())return;
        $r=Runtime::facts();
        // These invariants also apply on the first uncached initialization boot.
        $this->app['config']->set('database.default','mysql');
        $connection=$this->app['config']->get('database.connections.mysql');
        if(($connection['database']??'')!==$r['database']||($connection['username']??'')!==$r['db_user'])
            throw new \RuntimeException('GO_OWNER_DATABASE_BINDING_MISMATCH');
        $this->app['config']->set('database.connections',['mysql'=>$connection]);
        $this->app['config']->set('session.cookie','go_owner_'.str_replace('-','',$r['id']));
        $this->app['config']->set('session.path',$r['prefix']?:'/');
        $this->app['config']->set('session.domain',null);
        $this->app['config']->set('session.driver','file');
        $this->app['config']->set('session.files',$r['base'].'/storage/framework/sessions');
        $this->app['config']->set('cache.default','database');
        $this->app['config']->set('cache.prefix','go_owner_'.$r['id']);
        $this->app['config']->set('queue.default','database');
        $this->app['config']->set('desktop_dashboard.enabled',false);
        $this->app['config']->set('desktop_dashboard.workspace_enabled',false);
        $this->app['config']->set('desktop_dashboard.local',false);
        $this->app['config']->set('settings.auto_discover_settings',[]);
        $this->app['config']->set('app.debug',false);
        $this->app['config']->set('services.fcm.project_id','');
        $this->app['config']->set('services.google.client_id',null);
        $this->app['config']->set('services.google.android_client_id',null);
        $this->app['config']->set('cors.allowed_origins',isset($r['origin'])?[$r['origin']]:[]);
        $this->app['config']->set('cors.supports_credentials',false);
        $publicRoot=$r['base'].'/storage/app/public';
        foreach($this->app['config']->get('filesystems.disks',[])as$name=>$disk){
            if(($disk['driver']??'')==='local'&&isset($disk['root'])&&($disk['root']===$publicRoot||str_starts_with($disk['root'],$publicRoot.'/')))
                $this->app['config']->set('filesystems.disks.'.$name.'.url',rtrim($this->app['config']->get('app.url'),'/').'/storage'.substr($disk['root'],strlen($publicRoot)));
        }
    }

    public function boot()
    {
        if(!Runtime::tenant())return;
        \App\Services\OwnerDashboards\PaymentSettings::load();
        if(isset(Runtime::facts()['origin'])){
            $this->app['url']->forceRootUrl(Runtime::facts()['origin']);
            $this->app['url']->forceScheme('https');
        }
        Gate::before(function($user,$ability){
            // A local business owner has all business abilities. Control-plane actions use Registry::authorize, not Gate.
            return (int)$user->id===1&&$user->account_type==='admin'&&$user->status==='accepted'?true:null;
        });
        Route::middleware('web')->get('storage/{path}',[\App\Http\Controllers\Dashboard\OwnerDashboardFilesController::class,'show'])->where('path','.*');
    }
}
