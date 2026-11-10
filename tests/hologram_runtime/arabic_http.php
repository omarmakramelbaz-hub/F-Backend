<?php
/** Actual Laravel router, CSRF, session guard, controller and throttle; disposable speech. */
$autoload = getenv('HOLOGRAM_TEST_AUTOLOAD');
if (!$autoload || !is_file($autoload)) throw new RuntimeException('Disposable Laravel autoload required');
$loader = require $autoload;
$root = dirname(__DIR__, 2);
$loader->addPsr4('App\\', $root.'/app/', true);
$app = new Illuminate\Foundation\Application($root);
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['env'=>'local', 'timezone'=>'Africa/Cairo', 'locale'=>'ar', 'url'=>'https://fixture.invalid',
        'key'=>'base64:'.base64_encode(str_repeat('a',32)), 'cipher'=>'AES-256-CBC'],
    'auth' => ['defaults'=>['guard'=>'admin'], 'guards'=>['admin'=>['driver'=>'session','provider'=>'fixture']],
        'providers'=>['fixture'=>['driver'=>'eloquent','model'=>ArabicHttpUser::class]]],
    'session' => ['driver'=>'array','lifetime'=>120,'cookie'=>'arabic_fixture','path'=>'/','domain'=>null,'secure'=>true,'lottery'=>[0,100]],
    'cache' => ['default'=>'array','stores'=>['array'=>['driver'=>'array']]],
    'desktop_dashboard'=>['local'=>false],
]));
class ArabicHttpUser extends Illuminate\Foundation\Auth\User { protected $guarded=[]; }
foreach ([Illuminate\Events\EventServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class, Illuminate\Encryption\EncryptionServiceProvider::class,
    Illuminate\Cookie\CookieServiceProvider::class, Illuminate\Session\SessionServiceProvider::class,
    Illuminate\Database\DatabaseServiceProvider::class, Illuminate\Auth\AuthServiceProvider::class,
    Illuminate\Cache\CacheServiceProvider::class, Illuminate\Routing\RoutingServiceProvider::class,
    Illuminate\Foundation\Providers\FoundationServiceProvider::class] as $provider) $app->register($provider);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
if (!class_exists('Auth',false)) class_alias(Illuminate\Support\Facades\Auth::class,'Auth');
if (!class_exists('App',false)) class_alias(Illuminate\Support\Facades\App::class,'App');
$app->instance('request', Illuminate\Http\Request::create('https://fixture.invalid/'));
$app->boot();
$session=$app->make('session.store'); $session->start(); $session->regenerateToken();
$app->make('redirect')->setSession($session);
$router=$app->make('router');
$router->middlewareGroup('web',[App\Http\Middleware\VerifyCsrfToken::class]);
$router->aliasMiddleware('IsAdmin',App\Http\Middleware\IsAdmin::class);
$router->aliasMiddleware('lang',App\Http\Middleware\lang::class);
$router->aliasMiddleware('throttle',Illuminate\Routing\Middleware\ThrottleRequests::class);
class ArabicHttpRoutes extends Illuminate\Support\ServiceProvider { public function load($path) { $this->loadRoutesFrom($path); } }
$provider=new ArabicHttpRoutes($app); $provider->load($root.'/routes/hologram_arabic_voice.php');
$provider->load($root.'/routes/hologram_arabic_voice.php');
$router->getRoutes()->refreshNameLookups();
class ArabicHttpVoice extends App\Services\Dashboard\HomeHologramArabicVoice {
    public int $calls=0; public string $state='ready'; public array $actors=[]; public array $files=[];
    public function synthesize($actor,$text): array {
        $this->calls++; $this->actors[]=$actor->id;
        if ($this->state!=='ready') return ['state'=>$this->state];
        $path=tempnam(sys_get_temp_dir(),'go-arabic-http-');
        file_put_contents($path,'RIFF'.str_repeat('x',76)); $this->files[]=$path;
        return ['state'=>'ready','path'=>$path];
    }
}
$app->instance(App\Services\Dashboard\HomeHologramArabicVoice::class,$voice=new ArabicHttpVoice());
$checks=0;
function check($condition,$message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function requestSpeech($authenticated=true,$csrf=true,$method='POST',$server=[]) {
    global $app,$router,$session;
    $request=Illuminate\Http\Request::create('https://fixture.invalid/admin/dashboard/hologram-arabic/speak',$method,
        ['text'=>'العملاء 50,803 عميل.','actor_id'=>999],[],[], $server);
    $request->setLaravelSession($session);
    if ($csrf) $request->headers->set('X-CSRF-TOKEN',$session->token());
    $app->instance('request',$request);
    $guard=Auth::guard('admin'); $guard->forgetUser(); $guard->setRequest($request);
    if ($authenticated) $guard->setUser(new ArabicHttpUser(['id'=>1,'account_type'=>'admin']));
    $request->setUserResolver(fn($name=null)=>Auth::guard($name ?: 'admin')->user());
    return $router->dispatch($request);
}
try {
    $matches=array_filter(iterator_to_array($router->getRoutes()),fn($route)=>$route->getName()==='dashboard.hologram-arabic.speak');
    check(count($matches)===1,'Repeated provider loading creates exactly one route');
    $response=requestSpeech(false);
    check($response->getStatusCode()===302 && str_ends_with($response->headers->get('Location'),'/admin/login'),'Anonymous requests redirect before speech generation');
    check($voice->calls===0,'An anonymous request cannot invoke inference');
    try { requestSpeech(true,false); throw new RuntimeException('CSRF accepted missing token'); }
    catch (Illuminate\Session\TokenMismatchException $expected) { $checks++; }
    check($voice->calls===0,'A rejected CSRF request cannot invoke inference');
    try { requestSpeech(true,true,'GET'); throw new RuntimeException('GET accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $expected) { $checks++; }
    $response=requestSpeech();
    check($response instanceof Symfony\Component\HttpFoundation\BinaryFileResponse,'Authenticated valid POST returns a binary response');
    check($response->headers->get('Content-Type')==='audio/wav','The private response uses the audio type');
    check($response->headers->hasCacheControlDirective('private') && $response->headers->hasCacheControlDirective('no-store'),'Speech is never shared or stored by HTTP caches');
    check($response->headers->get('X-Content-Type-Options')==='nosniff','Audio cannot be MIME sniffed');
    check($voice->actors===[1],'The controller uses the session actor despite a conflicting request field');
    $path=$response->getFile()->getPathname(); ob_start(); $response->sendContent(); $bytes=ob_get_clean();
    check(strlen($bytes)===80 && str_starts_with($bytes,'RIFF'),'The response streams its generated private file');
    check(!file_exists($path),'The generated file is deleted after delivery');
    foreach (['invalid'=>422,'busy'=>429,'unavailable'=>503] as $state=>$code) {
        $voice->state=$state; $response=requestSpeech();
        check($response->getStatusCode()===$code,'Service state '.$state.' has the correct HTTP status');
        check($response->headers->hasCacheControlDirective('no-store'),'Error responses cannot retain private replies');
    }
    $calls=$voice->calls; $response=requestSpeech(true,true,'POST',['CONTENT_LENGTH'=>8193]);
    check($response->getStatusCode()===422 && $voice->calls===$calls,'Oversized requests are rejected before inference');
    $app->make('cache')->store()->flush(); $voice->state='unavailable';
    for ($n=0;$n<12;$n++) check(requestSpeech()->getStatusCode()===503,'A request within the per-account rate allowance reaches the service');
    $calls=$voice->calls;
    try { requestSpeech(); throw new RuntimeException('Throttle accepted a thirteenth request'); }
    catch (Illuminate\Http\Exceptions\ThrottleRequestsException $expected) { $checks++; }
    check($voice->calls===$calls,'A throttled request cannot invoke inference');
    echo "GO Arabic voice HTTP assertions passed: $checks\n";
} finally { foreach ($voice->files as $file) if (is_file($file)) unlink($file); }
