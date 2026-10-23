<?php
namespace {
    final class Refusal extends RuntimeException {public function __construct(public int $status,public array $headers=[]){parent::__construct('Fixed refusal.');}}
    function abort_unless($ok,$status,$message='',$headers=[]){if(!$ok)throw new Refusal($status,$headers);}
    function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
    final class Pool {public array $owners=[];public array $sql=[];}
    final class Connection {
        public function __construct(public Pool $pool,public int $id){}
        public function prepare($sql){
            check(in_array($sql,['SELECT GET_LOCK(?,0)','SELECT RELEASE_LOCK(?)'],true),'Unexpected row lock or business SQL.');
            $this->pool->sql[]=$sql;
            return new class($this,$sql) {
                private $value;
                public function __construct(private Connection $connection,private string $sql){}
                public function execute($args){
                    check(count($args)===1&&preg_match('/^go-erp-desktop-delta:slot:[12]$/D',$args[0]),'Unexpected unbounded lock name.');
                    $pool=$this->connection->pool;$id=$this->connection->id;$name=$args[0];
                    if($this->sql==='SELECT GET_LOCK(?,0)'){
                        $this->value=isset($pool->owners[$name])&&$pool->owners[$name]!==$id?0:1;
                        if($this->value===1)$pool->owners[$name]=$id;
                    }else{$this->value=($pool->owners[$name]??null)===$id?1:0;if($this->value===1)unset($pool->owners[$name]);}
                }
                public function fetchColumn(){return $this->value;}
            };
        }
    }
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardDeltaCapacity.php';
    use App\Services\Dashboard\DesktopDashboardDeltaCapacity as Capacity;
    $mysql=($argv[1]??null)==='--mysql';$pool=new Pool;
    $connect=static fn($id)=>$mysql?new PDO('mysql:host=127.0.0.1;port=13376;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]):new Connection($pool,$id);
    $first=new Capacity($connect(1));$second=new Capacity($connect(2));$third=new Capacity($connect(3));$ran=0;
    $answer=$first->run(function()use($second,$third,&$ran){
        return $second->run(function()use($third,&$ran){
            try{$third->run(function()use(&$ran){$ran++;});throw new RuntimeException('Third scan ran.');}
            catch(Refusal $error){check($error->status===429&&$error->headers===['Retry-After'=>'30'],'Capacity refusal must retry without reading account rows.');}
            return 'two-scans';
        });
    });
    check($answer==='two-scans'&&$ran===0,'Admission changed the callback result.');
    check($third->run(static fn()=>'released')==='released','Successful requests leaked a slot.');
    try{$first->run(static function(){throw new LogicException('fixture callback failed');});}catch(LogicException $error){}
    check($third->run(static fn()=>'after-error')==='after-error','Failed requests leaked a slot.');
    if(!$mysql){check($pool->owners===[],'Slots retained after all callbacks.');check(count($pool->sql)>0,'No real guard SQL was exercised.');}
    echo 'PASS '.($mysql?'real MariaDB':'isolated').' delta capacity checks'.PHP_EOL;
}
