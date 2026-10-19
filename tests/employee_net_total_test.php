<?php
/** Real period calculations and listings against an isolated in-memory database. */
declare(strict_types=1);
namespace {
    require $argv[1];
    $GLOBALS['net_patch_root']=$argv[2];
    spl_autoload_register(function($class){
        if(!str_starts_with($class,'App\\'))return;
        $relative='app/'.str_replace('\\','/',substr($class,4)).'.php';
        $file=$GLOBALS['net_patch_root'].'/'.$relative;
        if(!is_file($file))$file=__DIR__.'/../'.$relative;
        if(is_file($file))require $file;
    });
    function app($key=null){$c=\Illuminate\Container\Container::getInstance();return $key?$c->make($key):$c;}
    function now($zone=null){return \Carbon\Carbon::now($zone);}
    function abort_unless($ok,$status,$message=''){if(!$ok)throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);}
    function abort_if($ok,$status,$message=''){abort_unless(!$ok,$status,$message);}
    function netExpect($ok,$message){if(!$ok)throw new \RuntimeException($message);}
    function netDenied(callable $work){try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){netExpect($error->getStatusCode()===403,'Wrong scope denial status');return;}throw new \RuntimeException('Foreign scope was allowed');}
    function netKey(){static $n=0;return sprintf('00000000-0000-4000-8000-%012d',++$n);}
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public function actor($actor){$fresh=\Illuminate\Support\Facades\DB::table('users')->where('id',$actor->id)->first();\abort_unless($fresh,403);return $fresh;}
        public function permissions($actor){return ['can_checkout'=>in_array($this->actor($actor)->account_type,['admin','vendor'],true)];}
        public function branches($actor){$a=$this->actor($actor);$keys=$a->account_type==='admin'&&empty($a->owner_resturant_id)?['f:94','f:301']:['f:'.$a->owner_resturant_id];return array_map(fn($key)=>['value'=>$key,'kind'=>'f','id'=>(int)substr($key,2),'name'=>$key],$keys);}
        public function branch($key,$actor,$lock=false){foreach($this->branches($actor) as $branch)if($key===$branch['value'])return $branch;\abort_unless(false,403);}
    }
    class PosServiceTicket {public static function fingerprint(array $data):string{return hash('sha256',json_encode($data));}}
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\{DB,Schema,Facade};
    use App\Services\Dashboard\BranchPayroll;
    use App\Services\GoServices\Money;

    $container=new Container;Container::setInstance($container);
    $db=new Manager($container);$db->addConnection(['driver'=>'sqlite','database'=>':memory:']);$db->setAsGlobal();$db->bootEloquent();
    $container->instance('db',$db->getDatabaseManager());$container->instance('db.schema',$db->getConnection()->getSchemaBuilder());
    $container->instance('validator',new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en'),$container));Facade::setFacadeApplication($container);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19 11:00:00','UTC'));
    Schema::create('users',function(Blueprint $t){$t->id();$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
    Schema::create('pos_service_tickets',function(Blueprint $t){$t->id();});
    foreach(['2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations','2026_10_06_140000_add_employee_wallet_phone.php'=>'AddEmployeeWalletPhone','2026_10_08_190000_add_employee_attendance_rules.php'=>'AddEmployeeAttendanceRules'] as $file=>$class){require __DIR__.'/../database/migrations/'.$file;(new $class)->up();}
    foreach([[1,'admin',null],[2,'admin',null],[3,'vendor',94],[4,'vendor',301]] as [$id,$kind,$branch])DB::table('users')->insert(['id'=>$id,'account_type'=>$kind,'owner_resturant_id'=>$branch]);
    $actors=DB::table('users')->get()->keyBy('id');$service=app(BranchPayroll::class);$employees=[];
    for($i=1;$i<=37;$i++){
        $branch=$i<=34?'f:94':'f:301';$salary=310001+$i*317;
        $values=['branch'=>$branch,'name'=>sprintf('Team %02d',$i),'job_title'=>$i%2?'Cashier':'Cook','shift'=>$i%3?'Morning':'Evening','hired_on'=>'2026-09-01','effective_month'=>'2026-09','salary'=>Money::decimal($salary),'active'=>true,'wallet_phone'=>'01000000001','idempotency_key'=>netKey()];
        $row=$service->employeeSave($values,$actors[1])['employee'];$employees[$i]=$row;
        // Fixture entries exercise cents, voided rows and months independently of the daily summaries.
        foreach([['2026-10-02','bonus',17+$i,false],['2026-10-19','deduction',11*$i,false],['2026-09-02','advance',100+$i,false],['2026-10-19','bonus',999999,true]] as [$day,$kind,$amount,$void])DB::table('branch_employee_entries')->insert(['branch'=>$branch,'employee_id'=>$row['id'],'day'=>$day,'kind'=>$kind,'amount_cents'=>$amount,'reason'=>'Fixture entry','voided_at'=>$void?now('UTC'):null,'actor_id'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    // Negative open net deliberately lies on page two, so page-local sums cannot pass.
    DB::table('branch_employee_entries')->insert(['branch'=>'f:94','employee_id'=>$employees[34]['id'],'day'=>'2026-10-03','kind'=>'advance','amount_cents'=>987654,'reason'=>'Negative balance fixture','actor_id'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    $negative=$service->statement(['branch'=>'f:94','employee_id'=>$employees[34]['id'],'month'=>'2026-10'],$actors[1])['statement'];
    netExpect($negative['net_cents']<0,'Missing negative net fixture');
    $manual=intdiv((310001+34*317)*19+15,31)+(17+34)-11*34-987654;
    netExpect($negative['net_cents']===$manual&&$negative['net']===Money::decimal($manual),'Real period cents/negative calculation changed');

    // Close through the real service, pay one snapshot, then change the underlying ledger.
    $snapshots=[];
    foreach([1,2] as $i){
        $args=['branch'=>'f:94','employee_id'=>$employees[$i]['id'],'month'=>'2026-10'];
        $preview=$service->statement($args,$actors[1])['statement'];
        $closed=$service->close($args+['preview_hash'=>$preview['preview_hash'],'idempotency_key'=>netKey()],$actors[1])['statement'];
        if($i===2)$closed=$service->pay(['branch'=>'f:94','payroll_id'=>$closed['payroll_id'],'expected_revision'=>$closed['revision'],'payment_method'=>'cash','payment_confirmed'=>true,'idempotency_key'=>netKey()],$actors[1])['statement'];
        $snapshots[$i]=$closed;
        DB::table('branch_employee_salaries')->where('employee_id',$employees[$i]['id'])->update(['amount_cents'=>99999999]);
        DB::table('branch_employee_entries')->where('employee_id',$employees[$i]['id'])->where('day','like','2026-10-%')->update(['amount_cents'=>888888]);
        $again=$service->statement($args,$actors[1])['statement'];
        netExpect($again['net_cents']===$closed['net_cents']&&$again['net']===$closed['net']&&$again['status']===($i===2?'paid':'closed'),'Closed/paid snapshot net recomputed from mutable ledger');
    }
    $stable=[];foreach(['branch_employees','branch_employee_salaries','branch_employee_entries','branch_employee_days','branch_payrolls','branch_operation_commands'] as $table)$stable[$table]=json_encode(DB::table($table)->orderBy('id')->get()->all());
    $check=function(array $filters,$actor)use($service,$employees){
        $filters+=['branch'=>'all','day'=>'2026-10-19','month'=>'2026-10'];$sum=0;$ids=[];
        foreach($employees as $employee){
            if($filters['branch']!=='all'&&$employee['branch']!==$filters['branch'])continue;
            if(!empty($filters['search'])&&!str_contains($employee['name'],trim($filters['search'])))continue;
            foreach(['job_title','shift'] as $key)if(!empty($filters[$key])&&$employee[$key]!==$filters[$key])continue 2;
            $statement=$service->statement(['branch'=>$employee['branch'],'employee_id'=>$employee['id'],'month'=>$filters['month']],$actor)['statement'];
            $sum+=$statement['net_cents'];$ids[]=$employee['id'];
        }
        $reply=$service->listing($filters,$actor);
        netExpect($reply['summary']['net']===Money::decimal($sum),'Net total differs from all filtered period cents: '.json_encode($filters));
        netExpect(is_string($reply['summary']['net'])&&preg_match('/^-?[0-9]+\.[0-9]{2}$/D',$reply['summary']['net']),'Net summary lost exact decimal digits');
        netExpect($reply['summary']['employees']===count($ids),'Filtered employee count changed');
        foreach($reply['items'] as $item)netExpect(in_array($item['id'],$ids,true),'Listing leaked a filtered/foreign employee');
        return $reply;
    };
    $pageOne=$check(['branch'=>'f:94','page'=>1],$actors[3]);$pageTwo=$check(['branch'=>'f:94','page'=>2],$actors[3]);
    netExpect(count($pageOne['items'])===30&&count($pageTwo['items'])===4,'Pagination fixture does not span two pages');
    netExpect($pageOne['summary']['net']===$pageTwo['summary']['net'],'Total varies across pages');
    netExpect($pageOne['summary']['net']!==Money::decimal(array_sum(array_column(array_column($pageOne['items'],'statement'),'net_cents'))),'Fixture cannot detect page-only total');
    foreach([['branch'=>'all'],['branch'=>'f:301'],['branch'=>'all','search'=>'Team 3'],['branch'=>'all','job_title'=>'Cashier'],['branch'=>'all','shift'=>'Evening'],['branch'=>'f:94','search'=>'Team 1','job_title'=>'Cook','shift'=>'Morning'],['branch'=>'f:94','month'=>'2026-09'],['branch'=>'f:94','day'=>'2026-10-03','month'=>'2026-09'],['branch'=>'all','search'=>'No matching employee']] as $filters)$check($filters,$actors[1]);
    netExpect($check(['branch'=>'all'],$actors[2])['summary']===$check(['branch'=>'all'],$actors[1])['summary'],'Central admin scope differs from owner');
    $other=$check(['branch'=>'f:301'],$actors[4]);netExpect(count($other['items'])===3,'Branch employee scope incorrect');
    foreach(['all','f:301'] as $branch)netDenied(fn()=>$service->listing(['branch'=>$branch],$actors[3]));
    // A stale account object cannot retain the former branch's aggregate.
    DB::table('users')->where('id',3)->update(['owner_resturant_id'=>301]);netDenied(fn()=>$service->listing(['branch'=>'f:94'],$actors[3]));DB::table('users')->where('id',3)->update(['owner_resturant_id'=>94]);
    foreach($stable as $table=>$before)netExpect($before===json_encode(DB::table($table)->orderBy('id')->get()->all()),'Read-only total listing changed '.$table);
    netExpect($pageOne['can_manage_employees']===false&&$pageOne['can_award_bonuses']===false,'Branch profile/bonus permissions changed');
    echo "PASS: exact full-filter period net across 37 employees, both pages, scope/search/job/shift/month; negative and closed/paid snapshots; read-only listing and prior permissions\n";
}
