<?php
// Diagnostic only: real exporter behind a read-only SQL gate; real importer
// behind a disconnected fence that stops before its first write-side statement.
use Illuminate\Support\Facades\{DB,Schema};
use App\Services\Dashboard\{DesktopDashboardBootstrap,DesktopDashboardImport,DesktopDashboardSource};

final class GoErpPreflightReachedWriteFence extends RuntimeException {}
final class GoErpPreflightBlockedDatabaseCall extends RuntimeException {}

$phase='bootstrap';$finished=false;$reserve=str_repeat('x',1024*1024);$started=microtime(true);
register_shutdown_function(function()use(&$finished,&$reserve,&$phase){
    if($finished)return;$reserve='';$error=error_get_last();
    if(!$error||!in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
    echo json_encode(['result'=>'GO_ERP_SERVER_PREFLIGHT_FATAL','phase'=>$phase,
        'classification'=>str_contains($error['message']??'','Allowed memory size')?'memory-limit':'fatal',
        'file'=>basename($error['file']??''),'line'=>$error['line']??null],JSON_UNESCAPED_SLASHES).PHP_EOL;
});
$summary=[];
try {
    $root='/home/fasakha/public_html';
    require $root.'/vendor/autoload.php';
    // Laravel lazily constructs its typed database presence verifier.
    final class GoErpDisconnectedImportFence implements Illuminate\Database\ConnectionResolverInterface
    {
        public function getDefaultConnection() {return 'go_erp_disconnected_preflight';}
        public function setDefaultConnection($name) {$this->blocked();}
        public function connection($name=null) {if($name!==null)$this->blocked();return $this;}
        public function getConfig($key=null) {if($key!=='host')$this->blocked();return '127.0.0.1';}
        public function getDatabaseName() {return 'fasakhansta_dashboard_stage_0000000000000000';}
        public function select($query,$bindings=[],$read=true) {if($query!=='SHOW TABLES'||$bindings!==[])$this->blocked();return [];}
        public function statement($query,$bindings=[]) {
            if($query!=='SET FOREIGN_KEY_CHECKS=0'||$bindings!==[])$this->blocked();
            throw new GoErpPreflightReachedWriteFence();
        }
        public function __call($method,$arguments) {$this->blocked();}
        private function blocked() {throw new GoErpPreflightBlockedDatabaseCall();}
    }
    $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if($app->version()!=='8.83.29')throw new LogicException('Unsupported diagnostic engine.');
    if(DB::transactionLevel()!==0)throw new LogicException('Unexpected transaction.');

    // Laravel 8 sends select, cursor, statement, affectingStatement, and
    // unprepared SQL through run(). Transactions themselves only begin/commit.
    $manager=DB::getFacadeRoot();$original=$manager->connection();
    if(!($original instanceof Illuminate\Database\MySqlConnection))throw new LogicException('Unsupported connection.');
    $pdo=$original->getPdo();
    $guard=new class($pdo,$original->getDatabaseName(),$original->getTablePrefix(),$original->getConfig()) extends Illuminate\Database\MySqlConnection {
        public int $readQueries=0;
        protected function run($query,$bindings,Closure $callback) {
            $sql=trim($query);
            if((!preg_match('/\A(?:SELECT|SHOW)\b/i',$sql)
                &&$sql!=='SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')||str_contains($sql,';'))
                throw new GoErpPreflightBlockedDatabaseCall();
            $this->readQueries++;
            return parent::run($query,$bindings,$callback);
        }
    };
    $guard->setReadPdo($pdo);
    $alias='go_erp_preflight_'.bin2hex(random_bytes(8));
    config(['database.connections.'.$alias=>$original->getConfig()]);
    $manager->extend($alias,static function()use($guard){return $guard;});
    $manager->setDefaultConnection($alias);
    $app->forgetInstance('db.schema');$app->forgetInstance('db.connection');Schema::clearResolvedInstance('db.schema');
    if(DB::connection()!==$guard||Schema::getFacadeRoot()->getConnection()!==$guard
        ||(new App\Models\User)->getConnection()!==$guard)throw new LogicException('Read-only gate not active.');
    $guard->setReconnector(static function(){throw new GoErpPreflightBlockedDatabaseCall();});
    $phase='source';
    $source=app(DesktopDashboardSource::class)->fingerprint();
    if(($source['sha256']??'')!=='5f09c4ecc61909e4fe4cbc313b0ea31349e676a70d47415d6047c25ad9fba98a')throw new LogicException('Source changed.');
    $phase='enrollment';
    $devices=DB::table('desktop_dashboard_devices')->where('enabled',true)->where('actor_id',1)
        ->where('enrolled_at','2026-10-22 10:34:59')->get();
    if(count($devices)!==1)throw new LogicException('Enrollment is not unique.');
    $phase='export';
    $snapshot=app(DesktopDashboardBootstrap::class)->export($devices[0]);
    $summary=['tables'=>count($snapshot['tables']),'rows'=>array_sum(array_map(static fn($table)=>count($table['rows']),$snapshot['tables'])),
        'media_files'=>count($snapshot['media']),'media_complete'=>$snapshot['coverage']['media']??null];
    $phase='wire';
    $wire=DesktopDashboardBootstrap::json($snapshot);$summary['wire_bytes']=strlen($wire);
    unset($snapshot);
    if(strlen($wire)>256*1024*1024)throw new LogicException('Snapshot exceeds native wire bound.');
    $snapshot=json_decode($wire,true,512,JSON_THROW_ON_ERROR);unset($wire);
    $summary['decoded_memory_bytes']=memory_get_usage(true);
    $summary['guarded_read_queries']=$guard->readQueries;

    $phase='preflight';
    $beforeLocal=config('desktop_dashboard.local');$beforeDevice=config('desktop_dashboard.device_id');
    config(['desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$snapshot['device_id']]);
    DB::swap(new GoErpDisconnectedImportFence());
    try {
        app(DesktopDashboardImport::class)->import($snapshot);
        throw new LogicException('Importer unexpectedly returned.');
    } catch(GoErpPreflightReachedWriteFence $passed) {
        $summary['result']='GO_ERP_SERVER_PREFLIGHT_PASSED';
        $summary['stopped_before_first_write']=true;
    } finally {
        DB::swap($manager);config(['desktop_dashboard.local'=>$beforeLocal,'desktop_dashboard.device_id'=>$beforeDevice]);
    }
} catch(Throwable $error) {
    $summary['result']='GO_ERP_SERVER_PREFLIGHT_FAILED';
    $summary['phase']=$phase;$summary['type']=get_class($error);
    $summary['file']=basename($error->getFile());$summary['line']=$error->getLine();
    $summary['status']=$error instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():null;
}
$summary['server_peak_memory_bytes']=memory_get_peak_usage(true);
$summary['elapsed_ms']=(int)round((microtime(true)-$started)*1000);
$summary['schema_or_business_writes']=false;
$finished=true;
echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
exit(($summary['result']??null)==='GO_ERP_SERVER_PREFLIGHT_PASSED'?0:1);
