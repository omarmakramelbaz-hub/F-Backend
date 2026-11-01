<?php
declare(strict_types=1);
require __DIR__.'/../owner_mobile_runtime/vendor/autoload.php';

use App\Services\OwnerDashboards\{Bootstrap,ControlSchema,Registry,Runtime};
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Facade,Gate,Schema};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$root=dirname(__DIR__,2);$temp=sys_get_temp_dir().'/owner-dashboards-'.bin2hex(random_bytes(8));mkdir($temp,0700);
register_shutdown_function(function()use($temp){$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as$file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($temp);});
spl_autoload_register(function($class)use($root){if(str_starts_with($class,'App\\')){$file=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file))require_once$file;}});
class OwnerDashboardFixtureUser extends Authenticatable{protected $table='users';protected $guarded=[];public $timestamps=false;}
class_alias(OwnerDashboardFixtureUser::class,'App\\Models\\User');
$checks=0;
function verify(bool $condition,string $message):void{global$checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function denied(callable $call,int $status):void{try{$call();}catch(ValidationException$e){verify($status===422,'Unexpected validation failure');return;}catch(HttpException$e){verify($e->getStatusCode()===$status,'Unexpected HTTP failure');return;}throw new RuntimeException('Denial missing');}
function bootFixture(string $name,bool $tenant):object{
    global$root,$temp;
    $base=$temp.'/'.$name;mkdir($base,0700);foreach(['bootstrap/cache','storage/app','storage/framework/sessions','storage/framework/views','public']as$folder)mkdir($base.'/'.$folder,0700,true);
    touch($base.'/database.sqlite');
    $r=['format'=>1,'id'=>$name,'slug'=>$name,'prefix'=>'/dashboards/'.$name,'base'=>$base,'public'=>$base.'/public','database'=>$base.'/database.sqlite','db_user'=>'fixture-'.$name,'state'=>'active'];
    $app=$tenant?Bootstrap::application($r):new Illuminate\Foundation\Application($base);
    $app->instance('config',new Repository(['app'=>['url'=>'https://fixture.invalid','key'=>'base64:'.base64_encode(random_bytes(32)),'cipher'=>'AES-256-CBC','locale'=>'ar'],
        'database'=>['default'=>'mysql','connections'=>['mysql'=>['driver'=>'sqlite','database'=>$r['database'],'username'=>$r['db_user'],'prefix'=>'']]],
        'auth'=>['defaults'=>['guard'=>'admin'],'guards'=>['admin'=>['driver'=>'session','provider'=>'admins']],'providers'=>['admins'=>['driver'=>'eloquent','model'=>OwnerDashboardFixtureUser::class]]],
        'session'=>['driver'=>'file','files'=>$base.'/storage/framework/sessions','cookie'=>'fixture-'.$name,'path'=>'/','lifetime'=>120,'lottery'=>[0,100],'secure'=>true,'same_site'=>'lax','http_only'=>true],
        'cache'=>['default'=>'array','stores'=>['array'=>['driver'=>'array'],'database'=>['driver'=>'database','table'=>'cache']]],
        'view'=>['paths'=>[$root.'/resources/views'],'compiled'=>$base.'/storage/framework/views'],
        'filesystems'=>['default'=>'local','disks'=>['local'=>['driver'=>'local','root'=>$base.'/storage/app']]],
        'hashing'=>['driver'=>'bcrypt','bcrypt'=>['rounds'=>4]],'desktop_dashboard'=>['local'=>false]]));
    $request=Request::create('https://fixture.invalid'.($tenant?$r['prefix']:'').'/admin/owners','GET',[],[],[],['SCRIPT_NAME'=>($tenant?$r['prefix']:'').'/index.php']);$app->instance('request',$request);
    foreach([Illuminate\Events\EventServiceProvider::class,Illuminate\Filesystem\FilesystemServiceProvider::class,Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,Illuminate\Database\DatabaseServiceProvider::class,Illuminate\Hashing\HashServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,Illuminate\Cookie\CookieServiceProvider::class,Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Auth\AuthServiceProvider::class,Illuminate\Routing\RoutingServiceProvider::class,Illuminate\View\ViewServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,App\Providers\OwnerDashboardServiceProvider::class]as$provider)$app->register($provider);
    Facade::clearResolvedInstances();Facade::setFacadeApplication($app);$app->boot();
    Schema::create('users',function(Blueprint$t){$t->integer('id')->primary();$t->string('name');$t->string('email');$t->string('mobile')->nullable();$t->string('account_type');$t->string('status');$t->string('password');$t->string('remember_token')->nullable();});
    Schema::create('resturants',function(Blueprint$t){$t->increments('id');$t->string('name');$t->integer('user_id');});
    DB::table('users')->insert(['id'=>1,'name'=>'Owner '.$name,'email'=>$name.'@fixture.invalid','mobile'=>null,'account_type'=>'admin','status'=>'accepted','password'=>password_hash('FixtureSecret123',PASSWORD_BCRYPT,['cost'=>4])]);
    return$app;
}
function selectFixture($app):void{Illuminate\Container\Container::setInstance($app);Facade::clearResolvedInstances();Facade::setFacadeApplication($app);}
$central=bootFixture('central',false);ControlSchema::ensure();ControlSchema::ensure();
mkdir($temp.'/control/deny',0700,true);$registry=new Registry($temp.'/control');$owner=OwnerDashboardFixtureUser::findOrFail(1);
$before=DB::table('users')->get()->toJson();
denied(fn()=>$registry->listing(null),403);denied(fn()=>$registry->listing((object)['id'=>635,'account_type'=>'admin','status'=>'accepted']),403);
$input=['name'=>'New business','email'=>'FIRST@fixture.invalid','mobile'=>'01012345678','password'=>'NewOwnerFixture123','password_confirmation'=>'NewOwnerFixture123'];
$id=$registry->save($owner,$input);$row=DB::table('owner_dashboards')->where('id',$id)->first();
verify($row->email==='first@fixture.invalid'&&$row->mobile==='1012345678'&&password_verify('NewOwnerFixture123',$row->password_hash),'Owner credentials not validated/canonicalized');
verify($row->state==='pending'&&(int)$row->revision===1,'Owner not queued');
$public=$registry->show($owner,$id);verify(!isset($public->password_hash)&&!str_contains(json_encode($public),'NewOwnerFixture123'),'Status leaks credentials');
denied(fn()=>$registry->save($owner,$input),422);
denied(fn()=>$registry->save($owner,array_merge($input,['email'=>'other@fixture.invalid','password_confirmation'=>'wrong'])),422);
$oldHash=$row->password_hash;$registry->save($owner,['name'=>'Renamed business','email'=>'first@fixture.invalid','mobile'=>'','password'=>''],$id);
$row=DB::table('owner_dashboards')->where('id',$id)->first();verify($row->password_hash===$oldHash&&(int)$row->revision===2,'Profile edit reset password or failed revision');
verify(file_get_contents($temp.'/control/deny/'.$id)==='2','Edit did not block pending revision');
$registry->transition($owner,$id,'suspended');verify(file_get_contents($temp.'/control/deny/'.$id)==='3','Suspend marker has no revision');
verify($registry->show($owner,$id)->desired_state==='suspended','Suspend not queued');
denied(fn()=>$registry->transition($owner,'../../etc/passwd','active'),404);
verify(DB::table('users')->get()->toJson()===$before,'Control plane changed primary credentials');
$a=bootFixture('aaaaaaaaaaaaaaaa',true);$rA=Runtime::facts();
verify(config('session.path')==='/dashboards/aaaaaaaaaaaaaaaa'&&config('session.cookie')==='go_owner_aaaaaaaaaaaaaaaa','Cookie not scoped');
verify(array_keys(config('database.connections'))===['mysql'],'Alternate primary DB connection survived');
verify(!Schema::hasTable('owner_dashboards')&&DB::table('resturants')->count()===0&&DB::table('users')->count()===1,'Tenant not empty');
$ownerA=OwnerDashboardFixtureUser::findOrFail(1);verify($ownerA->can('admin-create')&&$ownerA->can('resturant-create'),'Owner lacks full local business ability');
foreach(['destroy','changeStatus']as$method){
    $action='App\\Http\\Controllers\\Dashboard\\UserController@'.$method;
    $route=new Illuminate\Routing\Route(['DELETE'],'admin/users/{user}',['uses'=>$action,'controller'=>$action]);
    $request=Request::create('https://fixture.invalid/admin/users/1','DELETE');$route->bind($request);$request->setRouteResolver(fn()=>$route);
    denied(fn()=>App\Services\OwnerDashboards\AccountPolicy::enforce($request,$ownerA),403);
}
App\Services\OwnerDashboards\AccountPolicy::enforce(Request::create('https://fixture.invalid/admin/dashboard'),$ownerA);
verify(!Runtime::primary($ownerA),'Local owner became primary owner');
denied(fn()=>$registry->listing($ownerA),403);
try{ControlSchema::ensure();throw new LogicException('Tenant created control plane');}catch(RuntimeException$e){verify($e->getMessage()==='GO_OWNER_CONTROL_PLANE_FORBIDDEN','Wrong control-plane rejection');}
DB::table('users')->insert(['id'=>77,'name'=>'Manager A','email'=>'manager-a@fixture.invalid','mobile'=>null,'account_type'=>'admin','status'=>'accepted','password'=>password_hash('ManagerOnlyA123',PASSWORD_BCRYPT,['cost'=>4])]);
DB::table('resturants')->insert(['name'=>'Branch A','user_id'=>77]);
Schema::create('settings',function(Blueprint$t){$t->string('group');$t->string('name');$t->boolean('locked');$t->text('payload');});
App\Services\OwnerDashboards\PaymentSettings::save(['PAYMOB_API_KEY'=>'OwnTenantSecretOnly','PAYMOB_CARD_INTEGRATION_ID'=>'123']);
verify(!str_contains(DB::table('settings')->value('payload'),'OwnTenantSecretOnly'),'Integration secret stored in plaintext');
verify(App\Services\OwnerDashboards\PaymentSettings::values()['PAYMOB_API_KEY']==='OwnTenantSecretOnly','Own integration secret could not be decrypted');
denied(fn()=>App\Services\OwnerDashboards\PaymentSettings::save(['PAYMOB_API_KEY'=>"key\nDB_DATABASE=primary"]),422);
verify(auth('admin')->attempt(['email'=>'manager-a@fixture.invalid','password'=>'ManagerOnlyA123']),'Tenant A staff login failed');
app('filesystem')->disk()->put('private-proof.txt','Tenant A private file');
$encryptedA=app('encrypter')->encryptString('Tenant A session cookie');
$b=bootFixture('bbbbbbbbbbbbbbbb',true);
verify(DB::table('users')->where('id',77)->first()===null&&DB::table('resturants')->count()===0,'New tenant sees A IDs/branches');
verify(App\Services\OwnerDashboards\PaymentSettings::values()===[],'Tenant inherited another integration credential');
verify(!app('filesystem')->disk()->exists('private-proof.txt'),'Tenant sees another storage root');
verify(!auth('admin')->attempt(['email'=>'manager-a@fixture.invalid','password'=>'ManagerOnlyA123']),'A credential authenticated in B');
try{app('encrypter')->decryptString($encryptedA);throw new LogicException('Cross-tenant cookie decrypted');}catch(Illuminate\Contracts\Encryption\DecryptException$e){verify(true,'Cross-tenant encryption rejected');}
DB::table('users')->insert(['id'=>77,'name'=>'Manager B','email'=>'manager-b@fixture.invalid','mobile'=>null,'account_type'=>'admin','status'=>'accepted','password'=>password_hash('ManagerOnlyB123',PASSWORD_BCRYPT,['cost'=>4])]);
DB::table('resturants')->insert(['name'=>'Branch B','user_id'=>77]);
verify(DB::table('users')->where('id',77)->value('name')==='Manager B','Direct ID did not remain local');
selectFixture($a);
verify(DB::table('users')->where('id',77)->value('name')==='Manager A'&&DB::table('resturants')->value('name')==='Branch A','B mutation changed A');
verify(app('filesystem')->disk()->get('private-proof.txt')==='Tenant A private file','B overwrote A media');
verify($a->getCachedConfigPath()!==$b->getCachedConfigPath()&&$a->getCachedRoutesPath()!==$b->getCachedRoutesPath(),'Caches shared between applications');
verify($a->publicPath()===$rA['public']&&$a->storagePath()===$rA['base'].'/storage','Application paths inherited primary roots');
verify(App\Services\OwnerDashboards\PaymentSettings::values()['PAYMOB_API_KEY']==='OwnTenantSecretOnly','Other application boot broke encrypted settings');
verify(App\Services\Dashboard\FasakhanstaStoreFulfillment::restaurantId(309)===309,'Tenant inherited primary branch mapping');
selectFixture($central);
verify(DB::table('users')->count()===1&&DB::table('resturants')->count()===0,'Tenant modified central data');
Illuminate\Support\Facades\Route::prefix('admin')->group($root.'/routes/owner_dashboards.php');$central['router']->getRoutes()->refreshNameLookups();
$session=$central['session.store'];$session->start();$central['request']->setLaravelSession($session);$central['view']->share('errors',new Illuminate\Support\ViewErrorBag);
$form=str_replace("@extends('admin.index')",'',file_get_contents($root.'/resources/views/admin/owners/form.blade.php'));
file_put_contents($temp.'/owner-form.blade.php',$form."\n@yield('content')\n@stack('custom-js')");
$public->name='<script>UntrustedOwnerName</script>';
$html=$central['view']->file($temp.'/owner-form.blade.php',['owner'=>$public])->render();
verify(str_contains($html,'&lt;script&gt;UntrustedOwnerName&lt;/script&gt;')&&!str_contains($html,'<script>UntrustedOwnerName'),'Owner form did not escape names');
verify(!str_contains($html,'NewOwnerFixture123')&&!str_contains($html,$oldHash),'Owner form disclosed credential');
verify(str_contains($html,'https://go-'.$public->slug.'.fixture.invalid/admin/login'),'Owner has no independent login origin');
verify(str_contains($html,'name="_token"')&&str_contains($html,'name="_method" value="PUT"'),'Owner form lacks CSRF/update method');
try{(new App\Http\Middleware\OwnerDashboardGuard)->handle(Request::create('https://go-aaaaaaaaaaaaaaaa.fixture.invalid/admin/dashboard'),fn()=>response('unsafe'));throw new LogicException('Primary fallback served a tenant hostname');}catch(HttpException$e){verify($e->getStatusCode()===404,'Reserved host not denied');}
$registry->transition($owner,$id,'deleted');denied(fn()=>$registry->show($owner,$id),404);
verify($registry->listing($owner)->total()===0,'Deleted owner remains in active listing');
verify(DB::table('users')->get()->toJson()===$before,'Owner deletion removed primary or other accounts');
echo 'Independent owner dashboards: '.$checks." checks passed\n";
