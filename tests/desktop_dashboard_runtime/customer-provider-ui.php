<?php
// Exercise the original controller boot and actual CRM content Blade on the imported account dataset.
use App\Http\Controllers\Dashboard\BranchOperationsController;
use App\Services\Dashboard\{TakeawayAccess,WhatsAppInboxAccess};
use Illuminate\Http\Request;

(function()use($p,$switch){
    $guard=auth('admin');$previousActor=$guard->getUser();$previousAccess=app(WhatsAppInboxAccess::class);
    $access=new class extends WhatsAppInboxAccess {
        public int $calls=0;
        public function canAccess($actor): bool {$this->calls++;return parent::canAccess($actor);}
    };
    app()->instance(WhatsAppInboxAccess::class,$access);
    $providers=['messaging-status','messaging-configure','feedback-template-prepare','marketing-template-prepare','campaign-queue'];
    try{
        $switch($p['local'],true,$p['id']);
        foreach([[true,1,false],[false,1,true],[false,10,false]] as [$local,$actorId,$canMessage]){
            config(['desktop_dashboard.local'=>$local]);
            $actor=\App\Models\User::withoutGlobalScopes()->findOrFail($actorId);$guard->setUser($actor);
            $request=Request::create('/admin/customers','GET',['branch'=>'f:100']);
            $route=clone app('router')->getRoutes()->getByName('customers.index');$route->bind($request);
            $request->setRouteResolver(fn()=>$route);
            $before=$access->calls;
            $view=app(BranchOperationsController::class)->index($request,app(TakeawayAccess::class));$boot=$view->getData()['boot'];
            check($view->name()==='admin.customer_crm.index'&&$boot['can_message']===$canMessage
                &&$access->calls===$before+($local?0:1),
                ($local?'local owner':'online account '.$actorId).' CRM boot retains its message authority and skips local provider access evaluation');
            check(isset($boot['urls']['directory-save'],$boot['urls']['consent'],$boot['urls']['data'],$boot['urls']['recover'])
                &&array_reduce($providers,fn($ok,$name)=>$ok&&(array_key_exists($name,$boot['urls'])===!$local),true),
                ($local?'local':'online').' CRM boot keeps profile and consent URLs while exposing provider URLs only online');
            $content=explode('@endsection',explode("@section('content')",file_get_contents($view->getPath()),2)[1],2)[0];
            $compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,sys_get_temp_dir());
            $__env=app('view');ob_start();
            try{eval('?>'.$compiler->compileString($content));$html=ob_get_contents();}finally{ob_end_clean();}
            check(str_contains($html,'data-op-add')&&str_contains($html,'data-op-marketing')===$canMessage,
                ($local?'local owner':'online account '.$actorId).' actual CRM Blade keeps customer editing and renders messaging controls only when allowed');
        }
    }finally{
        app()->instance(WhatsAppInboxAccess::class,$previousAccess);
        if($previousActor)$guard->setUser($previousActor);else (function(){$this->user=null;})->call($guard);
    }
})();
