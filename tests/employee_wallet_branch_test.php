<?php
/** Real service/controller regression tests against a disposable in-memory database. */
declare(strict_types=1);
namespace {
    require $argv[1];
    $GLOBALS['wallet_patch_root']=$argv[2];
    spl_autoload_register(function($class){
        if(!str_starts_with($class,'App\\'))return;
        $relative='app/'.str_replace('\\','/',substr($class,4)).'.php';
        $path=$GLOBALS['wallet_patch_root'].'/'.$relative;
        if(!is_file($path))$path=__DIR__.'/../'.$relative;
        if(is_file($path))require $path;
    });
    function app($key=null){$container=\Illuminate\Container\Container::getInstance();return $key?$container->make($key):$container;}
    function now($zone=null){return \Carbon\Carbon::now($zone);}
    function abort_unless($condition,$code,$message=''){if(!$condition)throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$message);}
    function abort_if($condition,$code,$message=''){abort_unless(!$condition,$code,$message);}
    function walletExpect($condition,$message){if(!$condition)throw new \RuntimeException($message);}
    function walletDenied(callable $work,int $status){
        try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){walletExpect($error->getStatusCode()===$status,'Incorrect denial status: '.$error->getStatusCode());return;}
        catch(\Illuminate\Validation\ValidationException $error){walletExpect($status===422,'Unexpected validation denial');return;}
        throw new \RuntimeException('Expected refusal '.$status);
    }
    function walletKey():string{static $number=0;return sprintf('00000000-0000-4000-8000-%012d',++$number);}
    function auth($guard=null){return new class{function user(){return $GLOBALS['wallet_http_actor'];}};}
}
namespace App\Http\Controllers {
    class Controller {
        private array $middleware=[];
        protected function middleware($callback){$this->middleware[]=['middleware'=>$callback];}
        public function getMiddleware(){return $this->middleware;}
    }
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public function actor($actor){$fresh=\Illuminate\Support\Facades\DB::table('users')->where('id',$actor->id)->first();\abort_unless($fresh,403);return $fresh;}
        public function permissions($actor){$fresh=$this->actor($actor);return ['can_checkout'=>in_array($fresh->account_type,['admin','vendor','resturant_owner'],true)];}
        public function branches($actor){
            $fresh=$this->actor($actor);
            if($fresh->account_type==='admin'&&empty($fresh->owner_resturant_id))$keys=['f:94','f:301'];
            elseif(in_array($fresh->account_type,['admin','vendor','resturant_owner'],true)&&in_array((int)$fresh->owner_resturant_id,[94,301],true))$keys=['f:'.$fresh->owner_resturant_id];
            else $keys=[];
            return array_map(fn($key)=>['value'=>$key,'kind'=>'f','id'=>(int)substr($key,2),'name'=>$key],$keys);
        }
        public function branch($key,$actor,$lock=false){foreach($this->branches($actor) as $branch)if($branch['value']===$key)return $branch;\abort_unless(false,403);}
        public function canAccess($actor){return count($this->branches($actor))>0;}
    }
    class PosServiceTicket{public static function fingerprint(array $input):string{return hash('sha256',json_encode($input));}}
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\{DB,Schema,Facade};
    use App\Services\Dashboard\{BranchPayroll,BranchOperations};
    $container=new Container;Container::setInstance($container);
    $database=new Manager($container);$database->addConnection(['driver'=>'sqlite','database'=>':memory:']);$database->setAsGlobal();$database->bootEloquent();
    $container->instance('db',$database->getDatabaseManager());$container->instance('db.schema',$database->getConnection()->getSchemaBuilder());
    $container->instance('validator',new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en'),$container));Facade::setFacadeApplication($container);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19 11:00:00','UTC'));
    Schema::create('users',function(Blueprint $table){$table->id();$table->string('account_type');$table->unsignedBigInteger('owner_resturant_id')->nullable();});
    Schema::create('pos_service_tickets',function(Blueprint $table){$table->id();});
    foreach(['2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations','2026_10_06_140000_add_employee_wallet_phone.php'=>'AddEmployeeWalletPhone','2026_10_08_190000_add_employee_attendance_rules.php'=>'AddEmployeeAttendanceRules'] as $file=>$class){require __DIR__.'/../database/migrations/'.$file;(new $class)->up();}
    foreach([[1,'admin',null],[2,'admin',null],[3,'vendor',94],[4,'resturant_owner',94],[5,'admin',94],[6,'delegate',null],[7,'vendor',301]] as [$id,$type,$branch])DB::table('users')->insert(['id'=>$id,'account_type'=>$type,'owner_resturant_id'=>$branch]);
    $actors=DB::table('users')->get()->keyBy('id');$payroll=app(BranchPayroll::class);
    $create=['branch'=>'f:94','name'=>'Employee','job_title'=>'Cashier','shift'=>'Morning','phone'=>'01000000001','wallet_phone'=>'01000000001','hired_on'=>'2026-09-01','effective_month'=>'2026-09','salary'=>'5000.00','active'=>true,'notes'=>'Profile notes','idempotency_key'=>walletKey()];
    $saved=$payroll->employeeSave($create,$actors[1]);$employeeId=$saved['employee']['id'];
    $foreign=$payroll->employeeSave(array_replace($create,['branch'=>'f:301','name'=>'Foreign employee','idempotency_key'=>walletKey()]),$actors[1]);
    $foreignId=$foreign['employee']['id'];
    $payroll->dailyNotes(['branch'=>'f:94','employee_id'=>$employeeId,'day'=>'2026-10-19','notes'=>'Daily notes','idempotency_key'=>walletKey()],$actors[3]);
    $payroll->entry(['branch'=>'f:94','employee_id'=>$employeeId,'day'=>'2026-10-19','kind'=>'advance','amount'=>'20.00','reason'=>'Existing advance','idempotency_key'=>walletKey()],$actors[3]);
    $immutableTables=['branch_employee_salaries','branch_employee_days','branch_employee_entries','branch_payrolls'];
    $immutable=[];foreach($immutableTables as $table)$immutable[$table]=json_encode(DB::table($table)->orderBy('id')->get()->all());
    $original=(array)DB::table('branch_employees')->where('id',$employeeId)->first();
    $foreignBefore=json_encode(DB::table('branch_employees')->where('id',$foreignId)->first());
    $wallet=['branch'=>'f:94','employee_id'=>$employeeId,'expected_revision'=>1,'wallet_phone'=>'٠١٠٠٠٠٠٠٠٠٢','idempotency_key'=>walletKey(),
        'name'=>'INJECTED_PROFILE_NAME','salary'=>'999999.00','day'=>'2026-10-18','status'=>'unauthorized_absence','notes'=>'INJECTED_DAY_NOTES'];
    $result=$payroll->wallet($wallet,$actors[3]);
    walletExpect($result['success']===true&&$result['replayed']===false&&$result['employee']['wallet_phone']==='01000000002','Own-branch normalized wallet write failed');
    walletExpect((int)$result['employee']['revision']===2&&(int)$result['employee']['actor_id']===3,'Wallet revision/actor not updated');
    $after=(array)DB::table('branch_employees')->where('id',$employeeId)->first();
    foreach($original as $key=>$value)if(!in_array($key,['wallet_phone','revision','actor_id','updated_at'],true))walletExpect($after[$key]===$value,'Wallet changed employee profile field '.$key);
    $commandCount=DB::table('branch_operation_commands')->count();
    walletExpect($payroll->wallet($wallet,$actors[3])['replayed']===true,'Branch wallet retry not idempotent');
    walletExpect(DB::table('branch_operation_commands')->count()===$commandCount,'Wallet replay created another receipt');
    foreach($immutable as $table=>$before)walletExpect($before===json_encode(DB::table($table)->orderBy('id')->get()->all()),'Wallet changed salary/day/ledger '.$table);
    walletExpect($foreignBefore===json_encode(DB::table('branch_employees')->where('id',$foreignId)->first()),'Wallet changed another employee');

    // Central owner/admin, restaurant owner and bound branch admin keep this narrow write.
    foreach([1,2,4,5] as $offset=>$id){
        $revision=(int)DB::table('branch_employees')->where('id',$employeeId)->value('revision');
        $phone='0100000000'.($offset+3);
        $reply=$payroll->wallet(['branch'=>'f:94','employee_id'=>$employeeId,'expected_revision'=>$revision,'wallet_phone'=>$phone,'idempotency_key'=>walletKey()],$actors[$id]);
        walletExpect($reply['employee']['wallet_phone']===$phone,'Authorized wallet actor denied '.$id);
    }
    $revision=(int)DB::table('branch_employees')->where('id',$employeeId)->value('revision');
    $stable=json_encode(DB::table('branch_employees')->orderBy('id')->get()->all());$stableCommands=DB::table('branch_operation_commands')->count();
    $base=['branch'=>'f:94','employee_id'=>$employeeId,'expected_revision'=>$revision,'wallet_phone'=>'01000000009'];
    walletDenied(fn()=>$payroll->wallet($base+['idempotency_key'=>walletKey()],$actors[7]),403);
    walletDenied(fn()=>$payroll->wallet(array_replace($base,['branch'=>'f:301','employee_id'=>$foreignId,'idempotency_key'=>walletKey()]),$actors[3]),403);
    walletDenied(fn()=>$payroll->wallet(array_replace($base,['employee_id'=>$foreignId,'idempotency_key'=>walletKey()]),$actors[3]),404);
    walletDenied(fn()=>$payroll->wallet(array_replace($base,['expected_revision'=>1,'idempotency_key'=>walletKey()]),$actors[3]),409);
    foreach(['01234','not-a-phone','0100000000000'] as $bad)walletDenied(fn()=>$payroll->wallet(array_replace($base,['wallet_phone'=>$bad,'idempotency_key'=>walletKey()]),$actors[3]),422);
    walletDenied(fn()=>$payroll->wallet($base+['idempotency_key'=>walletKey()],$actors[6]),403);
    walletExpect($stable===json_encode(DB::table('branch_employees')->orderBy('id')->get()->all())&&$stableCommands===DB::table('branch_operation_commands')->count(),'Rejected wallet write mutated data');
    DB::table('users')->where('id',3)->update(['owner_resturant_id'=>301]);
    walletDenied(fn()=>$payroll->wallet($wallet,$actors[3]),403); // Persisted branch change also blocks old cached reply.
    DB::table('users')->where('id',3)->update(['owner_resturant_id'=>94]);

    // Employee profile writes remain central-only, even with a preexisting command receipt.
    $profileReplay=array_replace($create,['idempotency_key'=>walletKey()]);
    app(BranchOperations::class)->write('employee.save',$profileReplay,$actors[3],fn()=>['employee'=>['id'=>$employeeId,'name'=>'cached old profile result']]);
    foreach([3,4,5,6] as $id){
        walletDenied(fn()=>$payroll->employeeSave($profileReplay,$actors[$id]),403);
        walletDenied(fn()=>$payroll->employeeSave(array_replace($create,['employee_id'=>$employeeId,'expected_revision'=>$revision,'name'=>'Forbidden edit','idempotency_key'=>walletKey()]),$actors[$id]),403);
    }
    DB::table('users')->where('id',1)->update(['account_type'=>'vendor','owner_resturant_id'=>94]);
    walletDenied(fn()=>$payroll->employeeSave($create,$actors[1]),403); // Original owner receipt cannot bypass fresh-role check.
    DB::table('users')->where('id',1)->update(['account_type'=>'admin','owner_resturant_id'=>null]);
    foreach([2,3,4,5] as $id)walletDenied(fn()=>$payroll->entry(['branch'=>'f:94','employee_id'=>$employeeId,'day'=>'2026-10-19','kind'=>'bonus','amount'=>'5.00','reason'=>'Forbidden bonus','idempotency_key'=>walletKey()],$actors[$id]),403);
    foreach($immutable as $table=>$before)walletExpect($before===json_encode(DB::table($table)->orderBy('id')->get()->all()),'Narrow wallet policy changed accounting '.$table);
    foreach([1,2,3,4,5] as $id){$listing=$payroll->listing(['branch'=>'f:94'],$actors[$id]);walletExpect($listing['can_manage_employees']===($id<=2),'Employee profile capability changed');walletExpect($listing['can_award_bonuses']===($id===1),'Owner-only bonus capability changed');}

    $controller=app(\App\Http\Controllers\Dashboard\BranchOperationsController::class);$middleware=$controller->getMiddleware()[0]['middleware'];
    foreach(['employees.save','employees.wallet'] as $route){
        $request=new class($route){function __construct(private $name){}function route(){return new class($this->name){function __construct(private $name){}function getName(){return $this->name;}};}};
        foreach([1,2,3,4,5] as $id){
            $GLOBALS['wallet_http_actor']=$actors[$id];
            if($route==='employees.save'&&$id>2)walletDenied(fn()=>$middleware($request,fn()=>['cached_profile_reply'=>true]),403);
            else walletExpect($middleware($request,fn()=>['cached_reply'=>true])===['cached_reply'=>true],'Wallet-only route or central profile replay blocked');
        }
        $GLOBALS['wallet_http_actor']=$actors[6];walletDenied(fn()=>$middleware($request,fn()=>true),403);
    }
    echo "PASS: branch-only wallet writes normalize and replay; profile/salary/day data preserved; cross-branch/invalid/stale writes rejected; central writes remain; profile/cache and owner-only bonus restrictions retained\n";
}
