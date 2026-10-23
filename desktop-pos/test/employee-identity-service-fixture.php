<?php
// Test-only adapters: execute the production services and migration without a
// Laravel boot or a live account/database. Filesystem checks use real temp files.
namespace Illuminate\Http {
    class UploadedFile {
        public function __construct(private string $file,private string $mime='image/jpeg'){}
        public function isValid(){return is_file($this->file);}
        public function getMimeType(){return $this->mime;}
        public function getRealPath(){return $this->file;}
    }
}
namespace Illuminate\Database\Migrations {class Migration {}}
namespace Illuminate\Database\Schema {
    class Blueprint {
        public function __call($type,$args){
            if($type==='dropColumn'){foreach($args[0] as $name)unset(\Illuminate\Support\Facades\Schema::$columns[$name]);return;}
            [$name]=$args;\Illuminate\Support\Facades\Schema::$columns[$name]=['type'=>$type,'length'=>$args[1]??null,'nullable'=>false];
            return new class($name) {
                public function __construct(private $name){}
                public function nullable(){\Illuminate\Support\Facades\Schema::$columns[$this->name]['nullable']=true;return $this;}
            };
        }
    }
}
namespace Illuminate\Support\Facades {
    class Schema {
        public static array $columns=[];public static int $alters=0;
        public static function hasColumn($table,$name){return isset(self::$columns[$name]);}
        public static function hasColumns($table,$names){foreach($names as $name)if(!self::hasColumn($table,$name))return false;return true;}
        public static function hasTable($table){return false;}
        public static function table($table,$run){++self::$alters;$run(new \Illuminate\Database\Schema\Blueprint);}
    }
    class Validator {
        public static function make($values,$rules){return new class($values,$rules){
            public function __construct(private $values,private $rules){}
            public function validate(){return array_intersect_key($this->values,$this->rules);}
        };}
    }
    class DB {
        public static array $rows=[];public static int $writes=0;
        public static function table($table){return new \SyntheticQuery($table);}
        public static function transaction($run){$before=self::$rows;try{return $run();}catch(\Throwable $e){self::$rows=$before;throw $e;}}
    }
}
namespace App\Services\Dashboard {
    class BranchOperations {
        public $access;
        public function __construct(){ $this->access=new class {public function actor($actor){return $actor;}};}
        public function branches($branch,$actor){\abort_unless($branch==='f:1',403,'Synthetic scope denied.');return [['value'=>$branch]];}
        public function rules(){return ['branch'=>'required','expected_revision'=>'sometimes'];}
        public function money($value){return (int)round((float)$value*100);}
        public function revision($row,$v){\abort_unless((int)$row->revision===(int)$v['expected_revision'],409,'Synthetic revision conflict.');}
        public function write($kind,$values,$actor,$run){$branch=$this->branches($values['branch'],$actor)[0];return \Illuminate\Support\Facades\DB::transaction(fn()=>$run($branch,$actor));}
    }
}
namespace {
    use Illuminate\Support\Facades\{DB,Schema};
    use App\Services\Dashboard\{BranchOperations,BranchPayroll,EmployeeIdentity,EmployeeRegulation};
    class SyntheticHttpException extends \RuntimeException {public function __construct(public int $status,string $message){parent::__construct($message);}}
    function abort_unless($condition,$status,$message=''){if(!$condition)throw new SyntheticHttpException($status,$message);}
    function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
    function now($zone){return $GLOBALS['clock'];}
    function storage_path($path){return $GLOBALS['storage'].'/'.$path;}
    function config($key){return match($key){'filesystems.disks.local.root'=>$GLOBALS['diskRoot']??storage_path('app'),'filesystems.disks.local.driver'=>$GLOBALS['diskDriver']??'local',default=>null};}
    class SyntheticQuery {
        private array $wheres=[];
        public function __construct(private string $table){}
        public function where($column,$operator,$value=null){if(func_num_args()===2){$value=$operator;$operator='=';}$this->wheres[]=[$column,$operator,$value];return $this;}
        public function lockForUpdate(){return $this;}
        public function orderByDesc($column){return $this;}
        private function matching(){return array_filter(DB::$rows[$this->table]??[],function($row){foreach($this->wheres as [$key,$op,$value]){
            $actual=$row[$key]??null;$ok=match($op){'='=>$actual==$value,'<='=>$actual<=$value,'>='=>$actual>=$value,default=>false};if(!$ok)return false;
        }return true;});}
        public function first(){foreach($this->matching() as $row)return (object)$row;return null;}
        public function exists(){return (bool)$this->matching();}
        public function update($values){foreach($this->matching() as $key=>$row){DB::$rows[$this->table][$key]=array_replace($row,$values);++DB::$writes;}}
        public function insertGetId($values){$id=count(DB::$rows[$this->table]??[])+1;DB::$rows[$this->table][$id]=$values+['id'=>$id];++DB::$writes;return $id;}
    }
    function result($run){try{return ['ok'=>true,'value'=>$run()];}catch(SyntheticHttpException $e){return ['ok'=>false,'status'=>$e->status,'message'=>$e->getMessage()];}}
    function check($condition,$message){if(!$condition)throw new \RuntimeException($message);}
    $root=$argv[1];$mode=$argv[2];$GLOBALS['storage']=$argv[3];$GLOBALS['clock']='2026-10-23 12:00:00';
    @mkdir(storage_path('app/public'),0700,true);
    foreach(['EmployeeRegulation','EmployeeIdentity','BranchPayroll'] as $name)require $root.'/app/Services/Dashboard/'.$name.'.php';
    foreach(EmployeeIdentity::COLUMNS as $name)Schema::$columns[$name]=['type'=>'fixture'];
    $ops=new BranchOperations;$payroll=new BranchPayroll($ops);$identity=new EmployeeIdentity($ops,$payroll);
    $actor=(object)['id'=>1,'account_type'=>'admin','owner_resturant_id'=>null];
    $vendor=(object)['id'=>2,'account_type'=>'vendor','owner_resturant_id'=>null];
    DB::$rows=['branch_employees'=>[7=>['id'=>7,'branch'=>'f:1','revision'=>1,'name'=>'PRIVATE_ACCOUNT_VALUE','id_front_path'=>null,'id_front_hash'=>null,'id_front_mime'=>null,'id_back_path'=>null,'id_back_hash'=>null,'id_back_mime'=>null,
        'regulation_version'=>null,'regulation_accepted_at'=>null,'regulation_accepted_by'=>null]],
        'branch_employee_salaries'=>[['employee_id'=>7,'effective_month'=>'2026-01','amount_cents'=>10000]]];
    $profile=['branch'=>'f:1','employee_id'=>7,'expected_revision'=>1,'name'=>'PRIVATE_ACCOUNT_VALUE','phone'=>'','wallet_phone'=>'','job_title'=>'cashier','shift'=>'','hired_on'=>'2026-01-01','left_on'=>null,'notes'=>'','active'=>true,'salary'=>'100.00','effective_month'=>'2026-10'];
    $photoA=$GLOBALS['storage'].'/upload-a.jpg';$photoB=$GLOBALS['storage'].'/upload-b.jpg';
    file_put_contents($photoA,'synthetic-private-photo-A');file_put_contents($photoB,'synthetic-private-photo-B');
    $upload=fn($file,$who=null,$branch='f:1')=>$identity->upload(['branch'=>$branch,'employee_id'=>7,'side'=>'front'],new \Illuminate\Http\UploadedFile($file),$who??$actor);
    $out=match($mode){
        'consent'=>(function()use($payroll,$profile,$actor){
            $payload=$profile+['regulation_accepted'=>true,'regulation_version'=>EmployeeRegulation::VERSION,'regulation_hash'=>EmployeeRegulation::sha256()];
            // A real queued payload is serialized and later decoded before dispatch.
            $replayed=json_decode(json_encode($payload,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
            $first=$payroll->employeeSave($replayed,$actor)['employee'];
            $GLOBALS['clock']='2026-10-24 14:00:00';$replayed['expected_revision']=$first['revision'];
            $second=$payroll->employeeSave($replayed,$actor)['employee'];
            $clear=$profile;$clear['expected_revision']=$second['revision'];$clear['regulation_accepted']=false;
            $third=$payroll->employeeSave($clear,$actor)['employee'];
            return ['digest'=>EmployeeRegulation::sha256(),'first'=>$first,'second'=>$second,'cleared'=>$third];
        })(),
        'stale'=>(function()use($payroll,$profile,$actor){
            $cases=[[],['regulation_version'=>'2000-01-01','regulation_hash'=>EmployeeRegulation::sha256()],['regulation_version'=>EmployeeRegulation::VERSION,'regulation_hash'=>str_repeat('a',64)]];$responses=[];
            foreach($cases as $extra)$responses[]=result(fn()=>$payroll->employeeSave($profile+['regulation_accepted'=>true]+$extra,$actor));
            return ['responses'=>$responses,'writes'=>DB::$writes,'row'=>DB::$rows['branch_employees'][7]];
        })(),
        'repair'=>(function()use($payroll,$profile,$actor){
            DB::$rows['branch_employees'][7]['regulation_version']=EmployeeRegulation::VERSION;
            $before=EmployeeRegulation::status(DB::$rows['branch_employees'][7]);
            $row=$payroll->employeeSave($profile+['regulation_accepted'=>true,'regulation_version'=>EmployeeRegulation::VERSION,'regulation_hash'=>EmployeeRegulation::sha256()],$actor)['employee'];
            return ['before'=>$before,'after'=>EmployeeRegulation::status($row)];
        })(),
        'replace'=>(function()use($upload,$photoA,$photoB,$identity,$vendor){
            $upload($photoA);$a=DB::$rows['branch_employees'][7]['id_front_path'];$firstRevision=DB::$rows['branch_employees'][7]['revision'];
            $upload($photoB);$b=DB::$rows['branch_employees'][7]['id_front_path'];$retainedA=is_file(storage_path('app/'.$a));
            $upload($photoA);$current=DB::$rows['branch_employees'][7];
            $read=$identity->image(['branch'=>'f:1','employee_id'=>7,'side'=>'front'],$vendor);
            // Mutating the path after verification cannot change response bytes.
            file_put_contents(storage_path('app/'.$a),'changed-after-open');$served=stream_get_contents($read['stream']);fclose($read['stream']);
            return ['retained_a_after_b'=>$retainedA,'retained_b_after_a'=>is_file(storage_path('app/'.$b)),'current_a'=>$current['id_front_path']===$a,'hash'=>hash_file('sha256',$photoA),'served_hash'=>hash('sha256',$served),'first_revision'=>$firstRevision,'last_revision'=>$current['revision']];
        })(),
        'scope'=>(function()use($upload,$photoA,$vendor,$identity,$actor){
            $deniedVendor=result(fn()=>$upload($photoA,$vendor));$deniedBranch=result(fn()=>$upload($photoA,$actor,'f:2'));
            $upload($photoA);$readOther=result(fn()=>$identity->image(['branch'=>'f:2','employee_id'=>7,'side'=>'front'],$actor));
            $noPhoto=result(fn()=>$identity->image(['branch'=>'f:1','employee_id'=>7,'side'=>'back'],$actor));
            return ['vendor'=>$deniedVendor,'branch'=>$deniedBranch,'read_other'=>$readOther,'missing'=>$noPhoto];
        })(),
        'storage'=>(function()use($upload,$photoA,$identity,$actor){
            $GLOBALS['diskRoot']=storage_path('app/public');$badDisk=result(fn()=>$upload($photoA));unset($GLOBALS['diskRoot']);
            symlink(storage_path('app/public'),storage_path('app/branch-expenses'));$publicLink=result(fn()=>$upload($photoA));unlink(storage_path('app/branch-expenses'));
            $upload($photoA);$row=DB::$rows['branch_employees'][7];$file=storage_path('app/'.$row['id_front_path']);unlink($file);symlink($photoA,$file);
            $linkedFile=result(fn()=>$identity->image(['branch'=>'f:1','employee_id'=>7,'side'=>'front'],$actor));unlink($file);
            file_put_contents($file,'corrupt');$corrupt=result(fn()=>$identity->image(['branch'=>'f:1','employee_id'=>7,'side'=>'front'],$actor));
            return ['bad_disk'=>$badDisk,'public_symlink'=>$publicLink,'file_symlink'=>$linkedFile,'corrupt'=>$corrupt];
        })(),
        'migration'=>(function()use($root){
            require $root.'/database/migrations/2026_10_23_200000_add_employee_identity_and_regulation.php';
            Schema::$columns=['id_front_path'=>['type'=>'string','length'=>300,'nullable'=>true]];$before=EmployeeIdentity::ready();$migration=new \AddEmployeeIdentityAndRegulation;$migration->up();$after=Schema::$columns;$ready=EmployeeIdentity::ready();$migration->up();$alters=Schema::$alters;$missing=[];
            foreach(EmployeeIdentity::COLUMNS as $column){$metadata=Schema::$columns[$column];unset(Schema::$columns[$column]);$missing[$column]=EmployeeIdentity::ready();Schema::$columns[$column]=$metadata;}
            $migration->down();$migration->down();return ['before'=>$before,'ready'=>$ready,'columns'=>$after,'alters_after_repeat'=>$alters,'missing'=>$missing,'after_down'=>Schema::$columns];
        })(),
        default=>throw new \RuntimeException('Unknown synthetic mode.')
    };
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
