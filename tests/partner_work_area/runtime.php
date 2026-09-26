<?php
// Real SQLite/Eloquent, Validator and Blade; auth/mail/media are isolated test doubles.
namespace Illuminate\Foundation\Http {
    class FormRequest {
        public function __construct(public array $data = [], public string $method = 'POST', public $model = null) {}
        public function has($key) { return array_key_exists($key, $this->data); }
        public function input($key) { return $this->data[$key] ?? null; }
        public function __get($key) { return $this->input($key); }
        public function merge($values) { $this->data = array_merge($this->data, $values); }
        public function route($key) { return $this->model; }
        public function isMethod($method) { return $this->method === $method; }
        public function hasFile($key) { return false; }
    }
}
namespace App\Http\Traits { trait UploadImageTrait {} }
namespace {
    require __DIR__.'/vendor/autoload.php';
}
namespace App\Models {
    class PendingVendor extends \Illuminate\Database\Eloquent\Model { protected $guarded=[]; public $timestamps=false; }
    class User extends \Illuminate\Database\Eloquent\Model {
        protected $guarded=[]; public $timestamps=false;
        public function pending_vendor() { return $this->belongsTo(PendingVendor::class, 'pending_vendor_id'); }
        public function assignRole($roles) {}
    }
}
namespace {
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use App\Http\Requests\Dashboard\User\StoreUserRequest;
    use App\Models\User;
    use App\Models\PendingVendor;
    use App\Repositories\UserRepository;
    class Mail { public static $fail=false; public static function send(...$args) { if(self::$fail) throw new \RuntimeException('test-only mail failure'); } }
    function request($key=null) { return $key === null ? $GLOBALS['testRequest'] : $GLOBALS['testRequest']->input($key); }
    function auth($guard=null) { return new class { public function user() { return (object)['id'=>999]; } }; }
    function ok($test, $message) { if (!$test) throw new \RuntimeException($message); }
    $root = dirname(__DIR__, 2);
    require $root.'/app/Support/PartnerWorkArea.php';
    require $root.'/app/Http/Requests/Dashboard/User/StoreUserRequest.php';
    require $root.'/app/Interfaces/UserRepositoryInterface.php';
    require $root.'/app/Repositories/UserRepository.php';
    $db = new Capsule();
    $db->addConnection(['driver'=>'sqlite', 'database'=>':memory:']);
    $db->setAsGlobal(); $db->bootEloquent();
    $db->getContainer()->instance('db', $db->getDatabaseManager());
    \Illuminate\Support\Facades\Facade::setFacadeApplication($db->getContainer());
    class_alias(\Illuminate\Support\Facades\DB::class, 'DB');
    Capsule::schema()->create('users', function(Blueprint $t) {
        $t->increments('id');
        foreach(['name','mobile','email','password','account_type','roles_name','status'] as $col) $t->string($col)->nullable();
        $t->integer('pending_vendor_id')->nullable(); $t->integer('added_by')->nullable();
        $t->decimal('lat',10,7)->nullable(); $t->decimal('lng',10,7)->nullable();
    });
    Capsule::schema()->create('pending_vendors', function(Blueprint $t) {
        $t->increments('id');
        foreach(['full_name','type','status','mobile','email','national_id','commercial_registration_no','driving_license_no',
            'tax_no','owner_name','branches_no','location','vodafone_cash_mobile','profession_key','application_kind'] as $col) $t->string($col)->nullable();
        $t->integer('added_by')->nullable(); $t->decimal('lat',10,7)->nullable(); $t->decimal('lng',10,7)->nullable();
        $t->unsignedTinyInteger('work_radius_km')->nullable();
    });
    Capsule::schema()->create('model_has_roles', function(Blueprint $t) {$t->integer('model_id');});
    $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en');
    $validator = new \Illuminate\Validation\Factory($translator);
    $validator->setPresenceVerifier(new \Illuminate\Validation\DatabasePresenceVerifier($db->getDatabaseManager()));
    function req($data, $method='POST', $model=null) {
        $r = new StoreUserRequest($data, $method, $model);
        $prepare = new \ReflectionMethod($r, 'prepareForValidation'); $prepare->setAccessible(true); $prepare->invoke($r);
        $GLOBALS['testRequest'] = $r;
        return $r;
    }
    $valid = ['name'=>'Test Partner','account_type'=>'delegate','mobile'=>'1012345678','email'=>'partner@example.test',
        'password'=>'test-only-pass','roles_name'=>['delegate'],'work_lat'=>'٣١٫٠٤','work_lng'=>'٣١٫٣٨','work_radius_km'=>'٠٧'];
    $r = req($valid); ok($validator->make($r->data,$r->rules())->passes(), 'valid Arabic coverage rejected');
    foreach (['0','-1','256','1.5','NaN','1e1',''] as $radius) {
        $r = req(array_merge($valid,['work_radius_km'=>$radius]));
        ok($validator->make($r->data,$r->rules())->fails(), 'bad radius accepted:'.$radius);
    }
    foreach ([['work_lat'=>''],['work_lat'=>91],['work_lng'=>181],['work_lng'=>null]] as $bad) {
        $r=req(array_merge($valid,$bad)); ok($validator->make($r->data,$r->rules())->fails(), 'invalid pin accepted');
    }
    $r=req(array_merge($valid,['account_type'=>'user']));
    ok($validator->make($r->data,$r->rules())->fails(), 'non-delegate coverage accepted');
    // A mail delivery exception must not abort saving the coverage profile.
    Mail::$fail=true;
    $r=req($valid); $repo=new UserRepository(); $result=$repo->createUser($r->data);
    ok($result===false, 'mail failure should preserve legacy controller feedback');
    $u=User::first(); $p=$u->pending_vendor;
    ok($p && (float)$p->lat===31.04 && (float)$p->lng===31.38 && (int)$p->work_radius_km===7, 'create/persistence failed');
    ok($u->lat===null && $u->lng===null, 'creating coverage overwrote live GPS');
    $p->update(['national_id'=>'test-id','driving_license_no'=>'test-license','profession_key'=>'plumber','vodafone_cash_mobile'=>'test-wallet']);
    $u->update(['lat'=>30.5,'lng'=>31.5]);
    $edit=['name'=>'Test Partner','account_type'=>'delegate','mobile'=>$u->mobile,'email'=>$u->email,'roles_name'=>['delegate'],
        'work_lat'=>30,'work_lng'=>31,'work_radius_km'=>20,'pending_vendor_id'=>$p->id];
    $r=req($edit,'PUT',$u);ok($validator->make($r->data,$r->rules())->passes(), 'edit validation failed');
    $repo->updateUser($u->id,$r->data);$u->refresh();$p=$u->pending_vendor;
    ok((float)$p->lat===30.0 && (float)$p->lng===31.0 && (int)$p->work_radius_km===20, 'edit/roundtrip failed');
    ok((float)$u->lat===30.5 && (float)$u->lng===31.5, 'editing coverage overwrote live GPS');
    ok($p->national_id==='test-id' && $p->driving_license_no==='test-license' && $p->profession_key==='plumber' && $p->vodafone_cash_mobile==='test-wallet', 'edit erased unrelated profile data');
    $edit['work_radius_km']=0;$r=req($edit,'PUT',$u);ok($validator->make($r->data,$r->rules())->fails(), 'invalid edit accepted');
    require $root.'/app/Services/GoServices/Money.php';
    require $root.'/app/Services/GoServices/Marketplace.php';
    $market=new \App\Services\GoServices\Marketplace();
    $p->update(['lat'=>30, 'lng'=>31, 'work_radius_km'=>7, 'application_kind'=>'partner']);
    $actor=(object)['connected'=>'active','pending_vendor_id'=>$p->id];
    $job=(object)['profession_key'=>'plumber','lat'=>30.05,'lng'=>31]; // about 5.56 km
    ok($market->matches($job,$actor), 'custom 7 km radius excluded from marketplace');
    $job->lat=30.08; ok(!$market->matches($job,$actor), 'outside radius accepted');
    $p->update(['work_radius_km'=>0]); $job->lat=30; ok(!$market->matches($job,$actor), 'zero radius silently became 5 km');
    $p->update(['work_radius_km'=>10]); $actor->connected='inactive';ok(!$market->matches($job,$actor), 'offline restriction changed');
    // Compile the real shared form and component; also ensure includes exist for create and edit.
    $compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), sys_get_temp_dir());
    foreach (['resources/views/admin/users/form.blade.php','resources/views/admin/users/partials/work_area.blade.php'] as $file) {
        $compiled=$compiler->compileString(file_get_contents($root.'/'.$file));
        $tmp=tempnam(sys_get_temp_dir(),'work-area-blade-');file_put_contents($tmp,$compiled);
        exec(PHP_BINARY.' -l '.escapeshellarg($tmp),$out,$status);unlink($tmp);ok($status===0,'Blade syntax invalid:'.$file);
    }
    $form=file_get_contents($root.'/resources/views/admin/users/form.blade.php');
    ok(substr_count($form,"@include('admin.users.partials.work_area')")===2, 'missing create/edit component');
    ok(strpos($form,'id="location"')===false,'legacy free-text location still present');
    echo "PASS: actual request rules, Arabic numbers, SQLite create/edit roundtrip, mail-failure persistence, metadata/GPS preservation and Blade compilation\n";
}
