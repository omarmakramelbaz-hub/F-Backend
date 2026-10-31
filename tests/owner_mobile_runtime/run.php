<?php
// Uses an in-memory database and explicitly registered Laravel providers; never loads .env.
require __DIR__.'/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Facade, DB, Schema, Auth, Route};
use Illuminate\Support\ViewErrorBag;
use App\Services\Dashboard\{OwnerMobile, DashboardLoginIdentity};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

final class OwnerMobileFixtureUser extends Illuminate\Foundation\Auth\User
{
    protected $table = 'users';
    public function getRolesAttribute() { return collect([(object)['id'=>1]]); }
    public function getFirstMediaUrl($collection = '', $conversion = '') { return ''; }
}
class_alias(OwnerMobileFixtureUser::class, 'App\\Models\\User');
class_alias(Auth::class, 'Auth');
class_alias(Route::class, 'Route');
$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir().'/owner-mobile-'.bin2hex(random_bytes(8));
mkdir($temp, 0700); mkdir($temp.'/views', 0700);
$app = new Application($root);
$app->instance('config', new Repository([
    'app'=>['key'=>'base64:'.base64_encode(random_bytes(32)), 'cipher'=>'AES-256-CBC', 'locale'=>'ar', 'fallback_locale'=>'en', 'url'=>'https://fixture.invalid'],
    'auth'=>['defaults'=>['guard'=>'admin'], 'guards'=>['admin'=>['driver'=>'session','provider'=>'users']], 'providers'=>['users'=>['driver'=>'eloquent','model'=>OwnerMobileFixtureUser::class]]],
    'database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]],
    'session'=>['driver'=>'array','lifetime'=>120,'cookie'=>'owner_mobile_fixture','path'=>'/','domain'=>null,'lottery'=>[0,100],'secure'=>true,'http_only'=>true,'same_site'=>'lax'],
    'hashing'=>['driver'=>'bcrypt','bcrypt'=>['rounds'=>4]],
    'view'=>['paths'=>[$root.'/resources/views'],'compiled'=>$temp.'/views'],
    'desktop_dashboard'=>['local'=>false],
]));
$app->instance('request', Request::create('https://fixture.invalid/admin/login'));
foreach ([
    Illuminate\Events\EventServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class, Illuminate\Validation\ValidationServiceProvider::class,
    Illuminate\Database\DatabaseServiceProvider::class, Illuminate\Hashing\HashServiceProvider::class,
    Illuminate\Encryption\EncryptionServiceProvider::class, Illuminate\Cookie\CookieServiceProvider::class,
    Illuminate\Session\SessionServiceProvider::class, Illuminate\Auth\AuthServiceProvider::class,
    Illuminate\Routing\RoutingServiceProvider::class, Illuminate\View\ViewServiceProvider::class,
    Illuminate\Foundation\Providers\FoundationServiceProvider::class,
] as $provider) { $app->register($provider); }
Facade::setFacadeApplication($app); $app->boot();
$session = $app->make('session.store'); $session->start();
$app->make('redirect')->setSession($session);
require $root.'/app/Http/Controllers/Controller.php';
require $root.'/app/Http/Controllers/Dashboard/HomeController.php';
require $root.'/app/Http/Controllers/Dashboard/OwnerMobileController.php';
Route::middleware('web')->group($root.'/routes/admin.php');
$app->make('router')->getRoutes()->refreshNameLookups();

Schema::create('users', function (Blueprint $t) {
    $t->integer('id')->primary(); $t->string('name'); $t->string('email'); $t->string('mobile')->nullable();
    $t->string('country_code')->nullable(); $t->string('account_type'); $t->string('status');
    $t->string('password'); $t->string('remember_token')->nullable(); $t->timestamps();
});
Schema::create('model_has_roles', function (Blueprint $t) { $t->integer('model_id'); $t->integer('role_id'); });
Schema::create('orders', function (Blueprint $t) { $t->integer('id'); $t->integer('total'); });
$password = 'FixtureOnly123';
foreach ([[1,'admin','01012345678@1',null,$password],[2,'vendor','vendor@test.invalid','1112345678','VendorOnly123'],
    [3,'resturant_owner','restaurant@test.invalid','1512345678','RestaurantOnly123'],[4,'admin','other@test.invalid',null,'OtherOnly123'],
    [5,'admin','legacy@test.invalid','7777777','LegacyOnly123']] as [$id,$type,$email,$phone,$secret]) {
    DB::table('users')->insert(['id'=>$id,'name'=>'Fixture '.$id,'email'=>$email,'mobile'=>$phone,'country_code'=>'20','account_type'=>$type,'status'=>'accepted',
        'password'=>password_hash($secret,PASSWORD_BCRYPT,['cost'=>4]),'remember_token'=>str_repeat((string)$id,60),'created_at'=>'2026-10-31 12:00:00','updated_at'=>'2026-10-31 12:00:00']);
}
DB::table('model_has_roles')->insert(['model_id'=>1,'role_id'=>1]); DB::table('orders')->insert(['id'=>10,'total'=>12345]);
$checks = 0;
function verified(bool $condition, string $message): void { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++; }
function denied(callable $work, int $status): void {
    try { $work(); } catch (ValidationException $e) { verified($status === 422, 'Unexpected validation denial'); return; }
    catch (HttpException $e) { verified($e->getStatusCode() === $status, 'Unexpected HTTP denial'); return; }
    throw new RuntimeException('Expected denial '.$status);
}
function dataState(): string { return json_encode([DB::table('users')->orderBy('id')->get(),DB::table('model_has_roles')->get(),DB::table('orders')->get()]); }
function requestFor(string $uri, string $method = 'POST', array $data = []): Request {
    global $app, $session;
    $request = Request::create('https://fixture.invalid'.$uri,$method,$data,[],[],['HTTP_REFERER'=>'https://fixture.invalid/admin/users/1/edit?account_type=admin']);
    $request->setLaravelSession($session); $app->instance('request',$request); $app->make('url')->setRequest($request);
    return $request;
}
function signin(string $identity, string $password, bool $remember = false): object {
    global $app, $session;
    $session->flush(); $app->make('auth')->forgetGuards();
    $request = requestFor('/admin/signin','POST',['email'=>$identity,'password'=>$password,'remember'=>$remember]);
    $before = $session->getId();
    $result = (new App\Http\Controllers\Dashboard\HomeController)->signin($request);
    if (auth('admin')->check()) { verified($session->getId() !== $before, 'Successful login did not regenerate the session'); }
    return $result;
}

$owner = OwnerMobileFixtureUser::findOrFail(1); $service = new OwnerMobile;
foreach (['01012345678','1012345678','+201012345678','201012345678','00201012345678','٠١٠١٢٣٤٥٦٧٨','۰۱۰۱۲۳۴۵۶۷۸',' 010 1234-5678 '] as $phone) {
    verified(DashboardLoginIdentity::mobile($phone) === '1012345678', 'Phone normalization failed');
}
foreach (['123','010123456789','+971501234567','phone@example.test','abc1012345678','+20+1012345678'] as $phone) {
    verified(DashboardLoginIdentity::mobile($phone) === null, 'Invalid number accepted');
}
$before = dataState(); denied(fn()=>$service->update($owner,'01012345678','wrong password'),422);
verified(dataState() === $before, 'Wrong password changed data');
denied(fn()=>$service->update(OwnerMobileFixtureUser::findOrFail(4),'01012345678',$password),403);
verified(dataState() === $before, 'Other admin changed owner phone');
config(['desktop_dashboard.local'=>true]); denied(fn()=>$service->update($owner,'01012345678',$password),403); config(['desktop_dashboard.local'=>false]);
verified(dataState() === $before, 'Offline rejection changed credentials');
denied(fn()=>$service->update($owner,'+971501234567',$password),422);

// Legacy email with a leading zero and multiple NULL-mobile admins authenticate by exact ID.
$result = signin('01012345678@1',$password); verified((int)auth('admin')->id() === 1, 'Legacy owner email failed');
verified(str_ends_with($result->getTargetUrl(),'/admin/dashboard'), 'Wrong owner destination');
signin('other@test.invalid','OtherOnly123'); verified((int)auth('admin')->id() === 4, 'NULL-mobile admin authenticated as another account');
signin('01012345678@1','wrong'); verified(!auth('admin')->check(), 'Wrong password signed in');

// First-time owner phone can be added without changing its legacy email or credentials.
$ownerBefore = (array)DB::table('users')->where('id',1)->first();
$otherBefore = DB::table('users')->where('id','<>',1)->orderBy('id')->get()->toJson();
verified($service->update($owner,'٠١٠١٢٣٤٥٦٧٨',$password) === '1012345678', 'First phone save failed');
$ownerAfter = (array)DB::table('users')->where('id',1)->first();
verified($ownerAfter['mobile'] === '1012345678' && $ownerAfter['country_code'] === '20', 'Wrong stored phone format');
foreach (['password','remember_token','email','account_type','status','created_at'] as $field) { verified($ownerBefore[$field] === $ownerAfter[$field], 'Phone save changed '.$field); }
verified(DB::table('users')->where('id','<>',1)->orderBy('id')->get()->toJson() === $otherBefore, 'Phone save changed other accounts');
verified(DB::table('model_has_roles')->where('model_id',1)->value('role_id') === 1 && DB::table('orders')->value('total') === 12345, 'Phone save changed roles or business records');
foreach (['01012345678','+201012345678','1012345678','201012345678','00201012345678','٠١٠١٢٣٤٥٦٧٨'] as $phone) {
    signin($phone,$password); verified((int)auth('admin')->id() === 1, 'Phone login failed: '.$phone);
}
signin('01012345678',$password,true); verified(app('cookie')->hasQueued(auth('admin')->getRecallerName()), 'Remember-me cookie missing');
signin('+201112345678','VendorOnly123'); verified((int)auth('admin')->id() === 2, 'Vendor phone login regressed');
signin('restaurant@test.invalid','RestaurantOnly123'); verified((int)auth('admin')->id() === 3, 'Restaurant-owner email login regressed');
signin('7777777','LegacyOnly123'); verified((int)auth('admin')->id() === 5, 'Existing legacy mobile identifier login regressed');

foreach (['1112345678','01112345678','+201112345678','201112345678','00201112345678'] as $collision) {
    DB::table('users')->where('id',2)->update(['mobile'=>$collision]); $before = dataState();
    denied(fn()=>$service->update($owner,'01112345678',$password),422); verified(dataState() === $before, 'Duplicate-phone rejection changed data');
}

$controller = new App\Http\Controllers\Dashboard\OwnerMobileController;
auth('admin')->setUser($owner);
$request = requestFor('/admin/owner/mobile','POST',['mobile'=>'01012345678','current_password'=>'do-not-flash-this-password']);
$controller($request,$service);
verified(!$session->hasOldInput('current_password'), 'Current password was flashed to the session');
verified($session->get('errors')->getBag('ownerMobile')->has('current_password') && $session->get('owner_mobile_open'), 'Password validation did not reopen the owner modal');
$request = requestFor('/admin/owner/mobile','POST',['mobile'=>[],'current_password'=>$password]); $controller($request,$service);
verified(!$session->hasOldInput('current_password') && $session->get('errors')->getBag('ownerMobile')->has('mobile'), 'Malformed input leaked password or bypassed validation');
$request = requestFor('/admin/owner/mobile','POST',['mobile'=>'01098765432','current_password'=>$password,'password'=>'UnexpectedNewPassword','user_id'=>4,'roles_name'=>['other']]);
$controller($request,$service);
verified(DB::table('users')->where('id',1)->value('mobile') === '1098765432', 'Controller did not save owner mobile');
verified(DB::table('users')->where('id',1)->value('password') === $ownerBefore['password'], 'Controller changed the password');
verified(DB::table('users')->where('id',4)->value('mobile') === null, 'Body user_id redirected the update to another account');
$route = Route::getRoutes()->getByName('owner.mobile.update');
verified($route->uri() === 'admin/owner/mobile' && $route->methods() === ['POST'] && in_array('IsAdmin',$route->gatherMiddleware(),true), 'Owner route is not authenticated POST');

// Render the actual form and edit view with Laravel Blade, without the unrelated dashboard layout.
$editSource = str_replace("@extends('admin.index')", '', file_get_contents($root.'/resources/views/admin/users/edit.blade.php'));
$editFile = $temp.'/edit.blade.php'; file_put_contents($editFile,$editSource."\n@yield('content')\n@stack('custom-js')\n");
$render = function (int $actorId) use ($app,$session,$editFile) {
    auth('admin')->setUser(OwnerMobileFixtureUser::findOrFail($actorId));
    $app->make('view')->share('errors',$session->get('errors') ?: new ViewErrorBag);
    return $app->make('view')->file($editFile,['user'=>OwnerMobileFixtureUser::findOrFail(1),'roles'=>collect([]),'areas'=>collect([]),'userRole'=>null])->render();
};
Route::get('/fixture-owner-profile',fn()=> $render(1))->name('users.edit');
DB::table('users')->where('id',1)->update(['mobile'=>null]);
$session->forget('_old_input');
$request = requestFor('/fixture-owner-profile?account_type=admin','GET');
$html = app('router')->dispatch($request)->getContent();
verified(str_contains($html,'data-bs-target="#changeMobile"'), 'Empty owner phone has no add button');
verified(str_contains($html,'/admin/owner/mobile'), 'Owner modal submits to wrong endpoint');
verified(str_contains($html,'id="change-mobile"') && str_contains($html,'autocomplete="current-password"'), 'Modal inputs were not rendered');
verified(str_contains($html,'bootstrap.Modal.getOrCreateInstance'), 'Validation does not reopen the modal');
verified(!str_contains($html,'do-not-flash-this-password') && !str_contains($html,$ownerBefore['password']), 'Rendered view exposed a secret');
$otherHtml = $render(4); verified(!str_contains($otherHtml,'data-bs-target="#changeMobile"'), 'Another admin sees an owner phone-change button');

echo $checks." owner mobile checks passed\n";
