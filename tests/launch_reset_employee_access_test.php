<?php
declare(strict_types=1);
namespace {
    require $argv[1];
    $GLOBALS['employee_patch_root']=$argv[2];
    spl_autoload_register(function($class){
        if(!str_starts_with($class,'App\\'))return;
        $relative='app/'.str_replace('\\','/',substr($class,4)).'.php';
        $path=$GLOBALS['employee_patch_root'].'/'.$relative;
        if(!is_file($path))$path=__DIR__.'/../'.$relative;
        if(is_file($path))require $path;
    });
    function app($key=null){$c=\Illuminate\Container\Container::getInstance();return $key?$c->make($key):$c;}
    function now($zone=null){return \Carbon\Carbon::now($zone);}
    function abort_unless($condition,$code,$message=''){if(!$condition)throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$message);}
    function abort_if($condition,$code,$message=''){abort_unless(!$condition,$code,$message);}
    function employeeAssert($condition,$message){if(!$condition)throw new \RuntimeException($message);}
    function employeeDenied(callable $callback){try{$callback();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){employeeAssert($error->getStatusCode()===403,'Wrong denial status');return;}throw new \RuntimeException('Expected employee profile denial');}
    function auth($guard=null){return new class{function user(){return $GLOBALS['employee_http_actor'];}};}
}
namespace App\Http\Controllers {
    // The isolated Illuminate runtime has no router; capture the actual production
    // middleware closure and invoke it with the request route used by HTTP/replay.
    class Controller {
        private array $middleware=[];
        protected function middleware($callback){$this->middleware[]=['middleware'=>$callback];}
        public function getMiddleware(){return $this->middleware;}
    }
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public function actor($actor){$row=\Illuminate\Support\Facades\DB::table('users')->where('id',$actor->id)->first();\abort_unless($row,403);return $row;}
        public function permissions($actor){return ['can_checkout'=>true];}
        public function canAccess($actor){return (bool)$this->actor($actor);}
        public function branches($actor){$this->actor($actor);return [['value'=>'f:94','kind'=>'f','id'=>94,'name'=>'Test branch']];}
        public function branch($branch,$actor,$lock=false){$this->actor($actor);\abort_unless($branch==='f:94',403);return $this->branches($actor)[0];}
    }
    class PosServiceTicket {public static function fingerprint(array $input):string{return hash('sha256',json_encode($input));}}
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\{DB,Schema,Facade};
    use App\Services\Dashboard\BranchPayroll;
    $container=new Container;Container::setInstance($container);
    $database=new Manager($container);$database->addConnection(['driver'=>'sqlite','database'=>':memory:']);$database->setAsGlobal();$database->bootEloquent();
    $container->instance('db',$database->getDatabaseManager());$container->instance('db.schema',$database->getConnection()->getSchemaBuilder());
    $container->instance('validator',new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en'),$container));Facade::setFacadeApplication($container);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19 11:00:00','UTC'));
    Schema::create('users',function(Blueprint $t){$t->id();$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
    Schema::create('pos_service_tickets',function(Blueprint $t){$t->id();});
    foreach(['2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations','2026_10_06_140000_add_employee_wallet_phone.php'=>'AddEmployeeWalletPhone','2026_10_08_190000_add_employee_attendance_rules.php'=>'AddEmployeeAttendanceRules'] as $file=>$class){require __DIR__.'/../database/migrations/'.$file;(new $class)->up();}
    foreach([[1,'admin',null],[2,'admin',null],[3,'vendor',94],[4,'resturant_owner',94],[5,'admin',94],[6,'delegate',null]] as [$id,$type,$branch])DB::table('users')->insert(['id'=>$id,'account_type'=>$type,'owner_resturant_id'=>$branch]);
    $actors=DB::table('users')->get()->keyBy('id');$payroll=app(BranchPayroll::class);
    $create=['branch'=>'f:94','name'=>'Employee','job_title'=>'Cashier','shift'=>'Morning','phone'=>'01000000001','wallet_phone'=>'01000000001','hired_on'=>'2026-09-01','effective_month'=>'2026-09','salary'=>'5000.00','active'=>true,'idempotency_key'=>'00000000-0000-4000-8000-000000000001'];
    foreach([3,4,5,6] as $id){employeeAssert(!$payroll->canManageEmployees($actors[$id]),'Branch profile capability granted');employeeDenied(fn()=>$payroll->employeeSave($create,$actors[$id]));employeeDenied(fn()=>$payroll->employeeSave([],$actors[$id]));employeeDenied(fn()=>$payroll->wallet([],$actors[$id]));}
    employeeAssert(DB::table('branch_employees')->count()===0&&DB::table('branch_operation_commands')->count()===0,'Denied creation changed data');
    foreach([1,2] as $id)employeeAssert($payroll->canManageEmployees($actors[$id]),'Owner/admin capability missing');
    $saved=$payroll->employeeSave($create,$actors[1]);$employee=$saved['employee'];
    employeeAssert($payroll->employeeSave($create,$actors[1])['replayed']===true,'Allowed profile idempotency changed');
    $update=array_replace($create,['employee_id'=>$employee['id'],'expected_revision'=>1,'name'=>'Edited by admin','salary'=>'6000.00','idempotency_key'=>'00000000-0000-4000-8000-000000000002']);
    $payroll->employeeSave($update,$actors[2]);
    $wallet=['branch'=>'f:94','employee_id'=>$employee['id'],'expected_revision'=>2,'wallet_phone'=>'01000000002','idempotency_key'=>'00000000-0000-4000-8000-000000000003'];
    $payroll->wallet($wallet,$actors[2]);
    employeeAssert(DB::table('branch_employees')->where('id',$employee['id'])->value('wallet_phone')==='01000000002','Central wallet update failed');
    foreach([3,4,5,6] as $id){employeeDenied(fn()=>$payroll->employeeSave($update,$actors[$id]));employeeDenied(fn()=>$payroll->wallet($wallet,$actors[$id]));}
    $before=json_encode(DB::table('branch_employees')->get()->all());$commandCount=DB::table('branch_operation_commands')->count();
    DB::table('users')->where('id',2)->update(['account_type'=>'vendor','owner_resturant_id'=>94]);
    employeeDenied(fn()=>$payroll->employeeSave($update,$actors[2]));employeeDenied(fn()=>$payroll->wallet($wallet,$actors[2]));
    employeeAssert(!$payroll->canManageEmployees($actors[2]),'Cached admin identity bypassed persisted role');
    employeeAssert($before===json_encode(DB::table('branch_employees')->get()->all())&&$commandCount===DB::table('branch_operation_commands')->count(),'Rejected replay mutated employee or receipt');
    DB::table('users')->where('id',2)->update(['account_type'=>'admin','owner_resturant_id'=>null]);
    foreach([1,2,3,4,5] as $id){$list=$payroll->listing(['branch'=>'f:94'],$actors[$id]);employeeAssert(count($list['items'])===1,'Daily branch listing was blocked');employeeAssert($list['can_manage_employees']===($id<=2),'Listing has incorrect employee profile capability');}
    $daily=['branch'=>'f:94','employee_id'=>$employee['id'],'day'=>'2026-10-19','kind'=>'advance','amount'=>'10.00','reason'=>'Daily branch work','idempotency_key'=>'00000000-0000-4000-8000-000000000004'];
    $payroll->entry($daily,$actors[3]);
    $payroll->dailyNotes(['branch'=>'f:94','employee_id'=>$employee['id'],'day'=>'2026-10-19','notes'=>'Branch note','idempotency_key'=>'00000000-0000-4000-8000-000000000005'],$actors[3]);
    employeeAssert(DB::table('branch_employee_entries')->count()===1&&DB::table('branch_employee_days')->value('notes')==='Branch note','Daily branch operations changed');
    $controller=app(\App\Http\Controllers\Dashboard\BranchOperationsController::class);$middleware=$controller->getMiddleware()[0]['middleware'];
    foreach(['employees.save','employees.wallet'] as $route){
        $request=new class($route){function __construct(private $name){}function route(){return new class($this->name){function __construct(private $name){}function getName(){return $this->name;}};}};
        foreach([3,4,5,6] as $id){$GLOBALS['employee_http_actor']=$actors[$id];employeeDenied(fn()=>$middleware($request,fn()=>['cached_profile_reply'=>true]));}
        foreach([1,2] as $id){$GLOBALS['employee_http_actor']=$actors[$id];employeeAssert($middleware($request,fn()=>true)===true,'Central middleware blocked employee profile');}
    }
    $request=new class{function route(){return new class{function getName(){return 'employees.data';}};}};$GLOBALS['employee_http_actor']=$actors[3];
    employeeAssert($middleware($request,fn()=>true)===true,'Daily employee screen was denied');
    echo "PASS: owner/admin employee creation, editing and wallet updates; branch/bound-admin/delegate denial; stale actor and cached replay rejection; daily branch operations retained; direct route middleware enforced\n";
}
