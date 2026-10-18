<?php
/** Controller boot fixtures only: no app boot, credentials, database or network. */
namespace App\Http\Controllers {
    class Controller { public function middleware(...$arguments) {} }
}
namespace Illuminate\Http {
    class Request {
        public function __construct(private array $values=[]) {}
        public function validate(array $rules): array {
            if(!array_key_exists('branch',$this->values)||$this->values['branch']===null||$this->values['branch']==='')return [];
            $branch=$this->values['branch'];
            if(!is_string($branch)||!preg_match('/^(all|(f|gs):[1-9][0-9]{0,18})$/D',$branch))throw new \RuntimeException('validation',422);
            return ['branch'=>$branch];
        }
        public function input($key,$default=null) {return $this->values[$key]??$default;}
        public function query($key,$default=null) {return $this->input($key,$default);}
    }
}
namespace Illuminate\Support\Facades {
    class Schema {public static function hasTable($table) {return true;}}
    class DB {
        public static array $rows=[];
        public static function table($table) {return new \BranchDefaultQuery(self::$rows[$table]??[]);}
    }
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public array $branchChecks=[];
        public function actor($actor) {return $actor;}
        public function canAccess($actor) {return count($this->branches($actor))>0;}
        public function branches($actor) {return $actor->branches;}
        public function branch($value,$actor) {
            $this->branchChecks[]=$value;
            foreach($actor->branches as $branch)if($branch['value']===$value)return $branch;
            \abort_unless(false,404);
        }
        public function permissions($actor) {return ['can_checkout'=>true,'can_manage'=>true];}
        public function ready() {return true;}
        public function receiverBranch($actor) {return $actor->owner_resturant_id?'f:'.$actor->owner_resturant_id:null;}
    }
    class PosServiceTicket {
        public array $summaryCalls=[];
        public function summary($channel,$branch,$actor) {
            $this->summaryCalls[]=[$channel,$branch];
            return ['ready'=>true,'register'=>['branch'=>$branch],'today'=>['count'=>1],'policy'=>[]];
        }
    }
    class PosServiceTable {public function ready() {return true;}}
    class PhoneMapProvider {public function enabled() {return false;}}
    class OperatingDay {public static function date() {return '2026-10-18';}}
    class DesktopPosInstaller {public function canUpload($actor,$access) {return false;}}
}
namespace {
    function abort_unless($condition,$status,$message='') {if(!$condition)throw new \RuntimeException($message,$status);}
    function auth($guard=null) {return new class {public function user() {return $GLOBALS['branchDefaultActor'];}};}
    function app($name) {return new $name;}
    function route($name,$parameters=[]) {return '/admin/'.$name.(isset($parameters['id'])?'/'.$parameters['id']:'');}
    function config($name) {return ['desktop_pos.enabled'=>true,'desktop_pos.installer'=>'/not-an-installer.exe'][$name]??null;}
    function view($name,$data) {return ['view'=>$name,'data'=>$data];}
    function collect() {return [];}
    class BranchDefaultQuery {
        public function __construct(private array $rows) {}
        public function whereIn($key,$values) {$this->rows=array_values(array_filter($this->rows,fn($row)=>in_array($row->$key,$values,true)));return $this;}
        public function select(...$columns) {return $this;}
        public function orderByDesc($column) {return $this;}
        public function get() {return $this->rows;}
        public function paginate($size) {return $this->rows;}
    }
    require __DIR__.'/../app/Http/Controllers/Dashboard/PosServiceController.php';
    require __DIR__.'/../app/Http/Controllers/Dashboard/DesktopPosController.php';
    class BranchDefaultPhone extends \App\Http\Controllers\Dashboard\PosServiceController {
        protected string $channel='phone';protected string $prefix='phone-orders';protected string $view='admin.phone_orders.index';protected string $variable='phonePos';
    }
    class BranchDefaultDining extends \App\Http\Controllers\Dashboard\PosServiceController {
        protected string $channel='dine';protected string $prefix='dining';protected string $view='admin.dining.index';protected string $variable='dining';
    }
    $checks=0;
    function checkBranchDefault($condition,$message) {global $checks;$checks++;if(!$condition)throw new \RuntimeException('DASHBOARD_BRANCH_DEFAULT: '.$message);}
    function denyBranchDefault(callable $action,$status,$message) {
        try{$action();}catch(\RuntimeException $error){checkBranchDefault($error->getCode()===$status,$message);return;}
        throw new \RuntimeException('Expected rejection: '.$message);
    }
    $branches=[['id'=>100,'value'=>'f:100','kind'=>'f','name'=>'المنصورة'],['id'=>101,'value'=>'f:101','kind'=>'f','name'=>'المحلة']];
    $actor=fn($id,$parent=null,$scope=null)=>(object)['id'=>$id,'name'=>'fixture','account_type'=>'admin','owner_resturant_id'=>$parent,'branches'=>$scope??$branches];
    $access=new \App\Services\Dashboard\TakeawayAccess;
    foreach([new BranchDefaultPhone,new BranchDefaultDining] as $controller){
        $key=$controller instanceof BranchDefaultPhone?'phonePos':'dining';
        foreach([1,2] as $id){
            $GLOBALS['branchDefaultActor']=$actor($id);$service=new \App\Services\Dashboard\PosServiceTicket;$access->branchChecks=[];
            $boot=$controller->index(new \Illuminate\Http\Request,$access,$service)['data'][$key];
            checkBranchDefault($boot['selected_branch']===''&&$boot['can_view_all_branches'],'owner and admin omit branch => all');
            checkBranchDefault($service->summaryCalls===[]&&$access->branchChecks===[],'aggregate boot does not read or validate a first branch');
            $boot=$controller->index(new \Illuminate\Http\Request(['branch'=>'f:101']),$access,$service)['data'][$key];
            checkBranchDefault($boot['selected_branch']==='f:101'&&count($service->summaryCalls)===1,'explicit permitted branch retained');
            $boot=$controller->index(new \Illuminate\Http\Request(['branch'=>'all']),$access,$service)['data'][$key];
            checkBranchDefault($boot['selected_branch']===''&&count($service->summaryCalls)===1,'explicit all normalizes without branch summary');
        }
        $GLOBALS['branchDefaultActor']=$actor(3,100,[$branches[0]]);$service=new \App\Services\Dashboard\PosServiceTicket;
        $boot=$controller->index(new \Illuminate\Http\Request,$access,$service)['data'][$key];
        checkBranchDefault($boot['selected_branch']==='f:100'&&!$boot['can_view_all_branches'],'branch manager defaults to own branch');
        denyBranchDefault(fn()=>$controller->index(new \Illuminate\Http\Request(['branch'=>'all']),$access,$service),403,'branch manager cannot choose all');
        denyBranchDefault(fn()=>$controller->index(new \Illuminate\Http\Request(['branch'=>'f:101']),$access,$service),404,'branch manager cannot choose another branch');
        $GLOBALS['branchDefaultActor']=$actor(2,null,[$branches[0]]);$service=new \App\Services\Dashboard\PosServiceTicket;
        $boot=$controller->index(new \Illuminate\Http\Request,$access,$service)['data'][$key];
        checkBranchDefault($boot['selected_branch']===''&&$boot['can_choose_branch']&&$service->summaryCalls===[],'central admin with one branch still defaults all');
    }
    \Illuminate\Support\Facades\DB::$rows=[
        'desktop_pos_devices'=>[(object)['id'=>'one','branch'=>'f:100'],(object)['id'=>'two','branch'=>'f:101'],(object)['id'=>'foreign','branch'=>'f:999']],
        'desktop_pos_operations'=>[(object)['id'=>1,'branch'=>'f:100'],(object)['id'=>2,'branch'=>'f:101'],(object)['id'=>3,'branch'=>'f:999']],
    ];
    $desktop=new \App\Http\Controllers\Dashboard\DesktopPosController;$uploads=new \App\Services\Dashboard\DesktopPosInstaller;
    $GLOBALS['branchDefaultActor']=$actor(1);
    $boot=$desktop->index(new \Illuminate\Http\Request,$access,$uploads)['data'];
    checkBranchDefault($boot['selected']==='all'&&$boot['allowAll'],'desktop owner defaults all');
    checkBranchDefault(array_column($boot['devices'],'branch')===['f:100','f:101']&&array_column($boot['operations'],'branch')===['f:100','f:101'],'desktop aggregate queries contain only authorized branches');
    $boot=$desktop->index(new \Illuminate\Http\Request(['branch'=>'f:101']),$access,$uploads)['data'];
    checkBranchDefault(count($boot['devices'])===1&&$boot['devices'][0]->branch==='f:101','desktop concrete filter scopes devices');
    $GLOBALS['branchDefaultActor']=$actor(3,100,[$branches[0]]);
    $boot=$desktop->index(new \Illuminate\Http\Request,$access,$uploads)['data'];
    checkBranchDefault($boot['selected']==='f:100'&&!$boot['allowAll']&&count($boot['operations'])===1,'desktop branch manager remains scoped');
    denyBranchDefault(fn()=>$desktop->index(new \Illuminate\Http\Request(['branch'=>'all']),$access,$uploads),403,'desktop manager cannot choose all');
    denyBranchDefault(fn()=>$desktop->index(new \Illuminate\Http\Request(['branch'=>'f:101']),$access,$uploads),403,'desktop manager cannot choose another branch');
    echo 'DASHBOARD_BRANCH_DEFAULT_CONTROLLERS_OK checks='.$checks.PHP_EOL;
}
