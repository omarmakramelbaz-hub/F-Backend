<?php
namespace Tests\Feature;

use App\Services\Dashboard\DesktopDashboardWorkspaceReadRoutes as Reads;
use Illuminate\Routing\Route;
use Tests\TestCase;

class DesktopDashboardWorkspaceReadRouteInventoryTest extends TestCase
{
    public function test_every_resolved_local_get_head_and_read_post_has_a_reviewed_action_uri_and_method_policy():void
    {
        $unclassified=[];$inventory=[];
        foreach(app('router')->getRoutes()as$route){
            if(!Reads::isRead($route))continue;
            $row=Reads::descriptor($route);$inventory[]=$row;
            if($row['policy']===null)$unclassified[]=$row;
        }
        $this->assertSame([],$unclassified,"Unreviewed resolved local reads:\n".json_encode($unclassified,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $desktop=array_values(array_filter($inventory,fn($row)=>$row['policy']!=='outside-desktop'));
        $this->assertGreaterThan(150,count($desktop));
        foreach(['typed-target','actor-scoped','public-reference','central-private','central-report','central-financial',
            'native-control','journal-mutation','session','connected-only','unavailable']as$policy)
            $this->assertNotEmpty(array_filter($desktop,fn($row)=>$row['policy']===$policy),'Missing reviewed family '.$policy);
        foreach(['admin/customers/data','admin/employees/statement','admin/dining/tickets/{id}',
            'admin/phone-orders/tickets/{id}','admin/desktop-dashboard/workspace/challenge',
            'admin/desktop-dashboard/workspace/local-session','api/desktop-dashboard/workspace/manifest']as$uri)
            $this->assertNotEmpty(array_filter($desktop,fn($row)=>$row['uri']===$uri),'Missing resolved foreach/native route '.$uri);
    }
    public function test_new_action_alias_or_method_cannot_inherit_an_existing_read_policy():void
    {
        foreach([new Route(['GET','HEAD'],'admin/accidental-private-page',['uses'=>'App\\Http\\Controllers\\Dashboard\\UserController@index']),
            new Route(['GET','HEAD'],'admin/users',['uses'=>'App\\Http\\Controllers\\Dashboard\\UserController@newUnionDump']),
            new Route(['GET','HEAD'],'admin/users/{different_binding}',['uses'=>'App\\Http\\Controllers\\Dashboard\\UserController@show']),
            new Route(['GET','HEAD','POST'],'admin/users',['uses'=>'App\\Http\\Controllers\\Dashboard\\UserController@index'])]as$route)
            $this->assertNull(Reads::classify($route));
        $route=new Route(['GET','HEAD'],'admin/users',['uses'=>'App\\Http\\Controllers\\Dashboard\\UserController@index']);
        $this->assertSame('typed-target',Reads::classify($route));
    }

    public function test_native_read_gate_rejects_unknown_or_unavailable_routes_before_a_controller_runs():void
    {
        $old=getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');
        putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');config(['desktop_dashboard.local'=>true]);
        try{
            foreach([['admin/private-unreviewed','App\\Http\\Controllers\\Dashboard\\UserController@unreviewed',503],
                ['admin/env-setting','App\\Http\\Controllers\\Dashboard\\SettingsController@enviroSetting',501]]as[$uri,$action,$status]){
                $route=new Route(['GET','HEAD'],$uri,['uses'=>$action]);
                $request=\Illuminate\Http\Request::create('/'.$uri,'GET');$request->setRouteResolver(fn()=>$route);
                $caught=null;
                try{app(\App\Http\Middleware\DesktopDashboardWorkspaceReadGate::class)->handle($request,
                    function(){throw new \RuntimeException('The unreviewed controller must never run.');});}
                catch(\Symfony\Component\HttpKernel\Exception\HttpException$error){$caught=$error;}
                $this->assertNotNull($caught);$this->assertSame($status,$caught->getStatusCode());
            }
        }finally{putenv($old===false?'DESKTOP_DASHBOARD_WORKSPACE_LOCAL':'DESKTOP_DASHBOARD_WORKSPACE_LOCAL='.$old);}
    }
}
