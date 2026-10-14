<?php
// Real Laravel request validation and controller/routes; runtime service is an isolated contract fixture.
// WA_INBOX_TEST_VENDOR=/path/vendor php tests/meta_comment_ai_controller_test.php
namespace {
    require rtrim(getenv('WA_INBOX_TEST_VENDOR') ?: '', '/').'/autoload.php';
    function app($name = null) { $c=\Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function auth($guard = null) { return new class { public function user() { return $GLOBALS['aiTestActor']; } }; }
    function response() { return new class { public function json($body,$status=200) { return new \Illuminate\Http\JsonResponse($body,$status); } }; }
    $GLOBALS['aiTestActor']=(object)['id'=>1,'account_type'=>'admin'];
}
namespace App\Http\Controllers { class Controller extends \Illuminate\Routing\Controller {} }
namespace App\Services\Dashboard {
    class MetaCommentAiControl {
        public $callback; public array $calls=[];
        public function status($actor): array { $this->calls[]=['status',$actor]; return ($this->callback)($actor,null); }
        public function update($actor,array $values): array { $this->calls[]=['update',$actor,$values]; return ($this->callback)($actor,$values); }
    }
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Http\Request;
    use Illuminate\Validation\ValidationException;
    use App\Services\Dashboard\MetaCommentAiControl;
    use App\Http\Controllers\Dashboard\MetaCommentAiController;
    $c=new Container(); Container::setInstance($c);
    \Illuminate\Support\Facades\Facade::setFacadeApplication($c);
    $c->instance('validator',new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(),'en'),$c));
    Request::macro('validate',function ($rules) { $v=app('validator')->make($this->all(),$rules); if ($v->fails()) throw new ValidationException($v); return $v->validated(); });
    require __DIR__.'/../app/Services/Dashboard/MetaCommentsFailure.php';
    require __DIR__.'/../app/Http/Controllers/Dashboard/MetaCommentAiController.php';
    $default=['success'=>true,'can_manage'=>true,'control'=>['enabled'=>false,'mode'=>'always','days'=>[1,2,3,4,5,6,7],
        'start'=>'10:00','end'=>'22:00','timezone'=>'Africa/Cairo','revision'=>3,'active_now'=>false,'configured'=>true,
        'worker_seen_at'=>null,'enabled_at'=>null,'last_reason'=>null]];
    $input=['enabled'=>true,'mode'=>'scheduled','days'=>[5,6],'start'=>'22:00','end'=>'02:00','expected_revision'=>3];
    $checks=0;
    function check($ok,$label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function execute($action,array $input,callable $callback) {
        $service=new MetaCommentAiControl(); $service->callback=$callback;
        $request=Request::create('/admin/meta/comments/ai/settings',$action==='status'?'GET':'POST',$input);
        $response=(new MetaCommentAiController())->$action($request,$service);
        check(str_contains($response->headers->get('Cache-Control'),'no-store'),'settings responses never cache');
        check($response->headers->get('Referrer-Policy')==='no-referrer' && $response->headers->get('X-Content-Type-Options')==='nosniff','privacy headers on success and errors');
        return [$response->getStatusCode(),json_decode($response->getContent(),true),$service->calls];
    }
    [$status,$body,$calls]=execute('status',[],fn()=>$default);
    check($status===200 && $body===$default,'default off status returned without enabling anything');
    check($calls[0][1]===$GLOBALS['aiTestActor'],'GET uses actual current authenticated actor');
    [$status,$body,$calls]=execute('update',$input+['_token'=>'fixture'],function($actor,$values) use ($default,$input) {
        check($actor===$GLOBALS['aiTestActor'],'update uses fresh current actor');
        check($values===$input,'only validated input reaches control service'); return $default;
    });
    check($status===200 && count($calls)===1,'overnight schedule passes validated control contract');
    [$status,$body,$calls]=execute('update',['enabled'=>false,'mode'=>'always','days'=>[1],'start'=>'10:00','end'=>'22:00','expected_revision'=>3],fn()=>$default);
    check($status===200 && count($calls)===1,'always/off configuration preserves saved day values');
    foreach ([['actor_id'=>999],['access_token'=>'private-token'],['timezone'=>'UTC'],['unexpected'=>'x']] as $extra) {
        [$status,$body,$calls]=execute('update',$input+$extra,fn()=>$default);
        check($status===422 && !$calls,'identity, credential, timezone and unknown overrides rejected before service: '.json_encode([$status,$body,$calls]));
    }
    foreach ([['enabled'=>'yes'],['mode'=>'unknown'],['days'=>[0]],['days'=>[1,1]],['days'=>range(1,8)],['start'=>'25:00'],['end'=>'2:00'],['expected_revision'=>-1]] as $change) {
        [$status,$body,$calls]=execute('update',array_replace($input,$change),fn()=>$default);
        check($status===422 && !$calls,'invalid control values are rejected before mutation');
    }
    [$status,$body,$calls]=execute('status',['actor_id'=>2],fn()=>$default);
    check($status===422 && !$calls,'GET cannot accept actor/config overrides');
    foreach ([403=>'ACCESS_DENIED',409=>'SETTINGS_CHANGED',503=>'AI_CONTROL_UNAVAILABLE'] as $code=>$reason) {
        [$status,$body]=execute('update',$input,function() use ($code) { throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,'private-token provider response'); });
        check($status===$code && $body===['success'=>false,'reason'=>$reason],'owner denial/stale/unavailable responses are sanitized');
    }
    [$status,$body]=execute('update',$input,function() { throw new \Symfony\Component\HttpKernel\Exception\HttpException(422,'AI_NOT_CONFIGURED'); });
    check($status===422 && $body['reason']==='AI_NOT_CONFIGURED','AI readiness has a distinct safe result');
    [$status,$body]=execute('update',$input,function() { throw new \App\Services\Dashboard\MetaCommentsFailure('AI_BUSY',409); });
    check($status===409 && $body['reason']==='AI_BUSY','in-flight dispatch busy state has a distinct safe result');
    [$status,$body]=execute('update',$input,function() { throw ValidationException::withMessages(['settings'=>'private provider text']); });
    check($status===422 && $body['reason']==='INVALID_SETTINGS','service validation messages are never reflected');
    [$status,$body]=execute('update',$input,function() { throw new \RuntimeException('private-token'); });
    check($status===503 && !str_contains(json_encode($body),'private-token'),'unexpected save failure leaks no exception');
    $extra=$default; $extra['access_token']='private-token'; $extra['control']['api_key']='private-token';
    [$status,$body]=execute('status',[],fn()=>$extra);
    check($status===200 && !str_contains(json_encode($body),'private-token'),'successful service output filters credential/extra fields');
    $health=$default; $health['control']['last_reason']='RECONNECT_REQUIRED';
    [$status,$body]=execute('status',[],fn()=>$health);
    check($status===200 && $body['control']['last_reason']==='RECONNECT_REQUIRED','known safe operational health reason is exposed');
    foreach (['INACTIVE','private-token','<img src=x onerror=alert(1)>'] as $reason) {
        $health['control']['last_reason']=$reason; [$status,$body]=execute('status',[],fn()=>$health);
        check($body['control']['last_reason']===null,'inactive/unknown/untrusted worker detail is not exposed');
    }
    $readonly=$default; $readonly['can_manage']=false;
    [$status,$body]=execute('status',[],fn()=>$readonly);
    check($status===200 && $body['can_manage']===false,'central admin can read state without management permission');

    $router=new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher($c),$c);
    \Illuminate\Support\Facades\Facade::setFacadeApplication($c); \Illuminate\Support\Facades\Route::swap($router);
    require __DIR__.'/../routes/meta_comment_ai.php';
    $router->getRoutes()->refreshNameLookups();
    foreach (['meta-comment-ai.status'=>['GET','status'],'meta-comment-ai.update'=>['POST','update']] as $name=>[$method,$action]) {
        $route=$router->getRoutes()->getByName($name);
        check($route && $route->uri()==='admin/meta/comments/ai/settings' && in_array($method,$route->methods(),true),'registered exact settings endpoint');
        check($route->getActionName()===MetaCommentAiController::class.'@'.$action,'settings route targets its control controller');
        check($route->gatherMiddleware()===['lang','IsAdmin'],'settings inherits central dashboard middleware');
    }
    echo "meta-comment-ai-controller: $checks checks passed\n";
}
