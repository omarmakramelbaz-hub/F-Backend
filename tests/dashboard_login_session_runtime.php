<?php
/** LOGIN_TEST_VENDOR=/path/to/laravel8/vendor php tests/dashboard_login_session_runtime.php */
namespace {
    require rtrim(getenv('LOGIN_TEST_VENDOR') ?: '', '/').'/autoload.php';
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'App\\')) {
            $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($file)) require $file;
        }
    });
    class_alias(\Illuminate\Support\Facades\Auth::class, 'Auth');
}
namespace App\Models {
    // Use the real Laravel Eloquent provider without unrelated application traits/scopes.
    class User extends \Illuminate\Foundation\Auth\User {
        protected $guarded = [];
        protected $hidden = ['password', 'remember_token'];
    }
}
namespace App\Http\Controllers { class Controller {} }
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Http\Request;
    use Illuminate\Http\Response;
    use Illuminate\Session\SessionManager;
    use Illuminate\Auth\AuthManager;
    use Illuminate\Cookie\CookieJar;
    use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
    use Illuminate\Session\Middleware\StartSession;
    use Illuminate\Routing\Redirector;
    use Illuminate\Routing\UrlGenerator;
    use Illuminate\Routing\RouteCollection;
    use App\Http\Controllers\Dashboard\HomeController;
    use App\Http\Middleware\DashboardSessionCookies;

    $app = new Container(); Container::setInstance($app); Facade::setFacadeApplication($app);
    $app->alias('auth', \Illuminate\Contracts\Auth\Factory::class);
    $app->alias('url', \Illuminate\Contracts\Routing\UrlGenerator::class);
    $baseSession = [
        'driver'=>'array', 'lifetime'=>10080, 'expire_on_close'=>false, 'encrypt'=>false,
        'cookie'=>'dashboard_test_session', 'path'=>'/', 'domain'=>null, 'secure'=>null,
        'http_only'=>true, 'same_site'=>'lax', 'lottery'=>[0,100],
    ];
    $app->instance('config', new Repository([
        'session'=>$baseSession,
        'auth'=>['defaults'=>['guard'=>'admin'], 'guards'=>[
            'admin'=>['driver'=>'session','provider'=>'admins'],
        ], 'providers'=>['admins'=>['driver'=>'eloquent','model'=>\App\Models\User::class]]],
    ]));
    $app->instance('events', new \Illuminate\Events\Dispatcher($app));
    $app->instance('hash', new \Illuminate\Hashing\BcryptHasher(['rounds'=>4]));
    $app->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('t',32),'AES-256-CBC'));
    $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(),'en');
    $app->instance('translator',$translator);
    $validator = new \Illuminate\Validation\Factory($translator,$app);
    Request::macro('validate',function ($rules) use ($validator) {
        return $validator->make($this->all(),$rules)->validate();
    });
    $db = new Manager($app);
    $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
    $db->setAsGlobal(); $db->bootEloquent(); $app->instance('db',$db->getDatabaseManager());
    $db->schema()->create('users',function (Blueprint $t) {
        $t->id(); $t->string('mobile'); $t->string('email'); $t->string('account_type');
        $t->string('password'); $t->rememberToken(); $t->timestamps();
    });
    $checks=0;
    function check($condition,$message) {
        global $checks; $checks++;
        if (!$condition) throw new \RuntimeException($message);
    }
    function dispatchLoginRequest($path,$data=[],$cookies=[]) {
        global $app,$baseSession;
        $app['config']->set('session',$baseSession);
        $request=Request::create('https://dashboard.test'.$path,$data ? 'POST':'GET',$data,$cookies);
        $app->instance('request',$request);
        $manager=new SessionManager($app);
        $app->instance('session',$manager);
        $app->instance('session.store',$manager->driver());
        $app->forgetInstance('cookie');
        $app->singleton('cookie',function ($app) {
            $c=$app['config']['session'];
            return (new CookieJar)->setDefaultPathAndDomain($c['path'],$c['domain'],$c['secure'],$c['same_site']);
        });
        $auth=new AuthManager($app); $app->instance('auth',$auth); Facade::clearResolvedInstance('auth');
        $url=new UrlGenerator(new RouteCollection,$request);
        $redirector=new Redirector($url); $redirector->setSession($manager->driver());
        $app->instance('redirect',$redirector); $app->instance('url',$url);
        $endpoint=function ($r) use ($path) {
            $controller=new HomeController;
            if ($path==='/admin/signin') return $controller->signin($r);
            if ($path==='/admin/adminLogout') return $controller->adminLogout();
            if ($path==='/admin/login') return (new \App\Http\Middleware\GuestAdmin)->handle($r,fn()=>new Response('login'));
            return (new \App\Http\Middleware\IsAdmin)->handle($r,fn()=>new Response('dashboard'));
        };
        $response=(new \App\Http\Middleware\EncryptCookies($app['encrypter']))->handle($request,
            fn($r)=>(new DashboardSessionCookies)->handle($r,
                fn($r)=>(new AddQueuedCookiesToResponse($app['cookie']))->handle($r,
                    fn($r)=>(new StartSession($manager))->handle($r,$endpoint))));
        return [$response,$manager->driver(),$auth->guard('admin')];
    }
    function rememberCookie($response) {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(),'remember_admin_') && $cookie->getExpiresTime()>time()) return $cookie;
        }
        return null;
    }
    $fixtureSecret='temporary-test-fixture';
    foreach (['admin','vendor','resturant_owner'] as $index=>$role) {
        $user=\App\Models\User::create([
            'mobile'=>'12000000'.$index,'email'=>$role.'@example.test','account_type'=>$role,
            'password'=>$app['hash']->make($fixtureSecret),
        ]);
        $credentials=['email'=>'0'.$user->mobile,'password'=>$fixtureSecret];
        [$ordinary,$ordinarySession,$ordinaryGuard]=dispatchLoginRequest('/admin/signin',$credentials);
        check($ordinaryGuard->id()===$user->id,'ordinary credentials authenticate');
        check(rememberCookie($ordinary)===null,'unchecked login has no persistent remember cookie');
        check(!str_contains(serialize($ordinarySession->all()),$fixtureSecret),'password absent from server session');
        foreach ($ordinary->headers->getCookies() as $cookie) {
            if ($cookie->getName()==='dashboard_test_session') {
                check($cookie->getExpiresTime()===0,'ordinary dashboard session ends with browser session');
                check($cookie->isSecure() && $cookie->isHttpOnly(),'session cookie secure and HttpOnly');
            }
        }
        [$empty]=dispatchLoginRequest('/admin/dashboard');
        check($empty->isRedirect('https://dashboard.test/admin/login'),'no remember cookie requires authentication after close');
        [$remembered,$session,$guard]=dispatchLoginRequest('/admin/signin',$credentials+['remember'=>'1']);
        $cookie=rememberCookie($remembered);
        check($cookie!==null,'checked login creates a remember cookie');
        check($cookie->isSecure(),'remember cookie secure');
        check($cookie->isHttpOnly(),'remember cookie HttpOnly');
        check($cookie->getSameSite()==='lax','remember cookie SameSite');
        check(!str_contains($cookie->getValue(),$fixtureSecret),'remember cookie contains no plaintext password');
        check(!str_contains(serialize($session->all()),$fixtureSecret),'remembered session contains no password');
        $jar=[$cookie->getName()=>$cookie->getValue()];
        [$restored,$restoredSession,$restoredGuard]=dispatchLoginRequest('/admin/dashboard',[],$jar);
        check($restored->getContent()==='dashboard','fresh session resumes from encrypted remember cookie');
        check($restoredGuard->viaRemember(),'real Laravel token authentication restores the user');
        check($restoredSession->get('id_user')===$user->id,'branch context restored');
        [$loginPage,$loginSession]=dispatchLoginRequest('/admin/login',[],$jar);
        $dest=['admin'=>'dashboard','vendor'=>'applies-orders','resturant_owner'=>'resturants'][$role];
        check($loginPage->isRedirect('https://dashboard.test/admin/'.$dest),'remembered login skips sign-in form for each account type');
        check($loginSession->get('id_user')===$user->id,'guest redirect restores branch context');
        [$loggedOut,$logoutSession]=dispatchLoginRequest('/admin/adminLogout',[],$jar);
        check($loggedOut->isRedirect('https://dashboard.test/admin/login'),'explicit logout returns to login');
        check(!$logoutSession->has('id_user'),'logout removes branch context');
        [$replayed]=dispatchLoginRequest('/admin/dashboard',[],$jar);
        check($replayed->isRedirect('https://dashboard.test/admin/login'),'old remember cookie cannot restore after logout');
    }
    [$bad,$badSession]=dispatchLoginRequest('/admin/signin',['email'=>'admin@example.test','password'=>'invalid-fixture','remember'=>'1']);
    check(rememberCookie($bad)===null,'failed password never issues remember cookie');
    check(!array_key_exists('password',$badSession->getOldInput()),'failed password never flashed');
    check($badSession->getOldInput('email')==='admin@example.test','failed login retains username');
    $app['config']->set('session',$baseSession);
    (new DashboardSessionCookies)->handle(Request::create('https://dashboard.test/api/orders'),fn()=>new Response);
    check($app['config']->get('session')===$baseSession,'non-dashboard session policy preserved');
    echo 'Dashboard login sessions: '.$checks." assertions passed.\n";
}
