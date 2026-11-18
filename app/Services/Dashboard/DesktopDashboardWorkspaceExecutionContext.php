<?php
namespace App\Services\Dashboard;

/** Synchronous, request-bound authority. It is never reconstructed from request actor fields. */
final class DesktopDashboardWorkspaceExecutionContext
{
    private static ?DesktopDashboardWorkspaceScope $scope=null;
    private static ?array $proof=null;
    private static bool $readOnly=false;
    private static ?string $physicalRoot=null;
    private static ?array $readMemo=null;
    private static ?\WeakMap $readConnections=null;
    private static ?\WeakMap $readDispatchers=null;
    private static int $readConnectionSequence=0;

    public static function current(): ?DesktopDashboardWorkspaceScope { return self::$scope; }
    public static function incomingProof(): ?array { return self::$proof; }
    public static function isReadOnly(): bool { return self::$readOnly; }
    public static function isRootControl(string $workspace): bool { return self::$physicalRoot===$workspace; }
    /** Caller authenticates BOTH the native control credential and the physical workspace bearer first. */
    public static function rootControl(string $workspace,callable $work)
    {
        abort_unless(config('desktop_dashboard.local')===true&&getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true'
            &&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$workspace)
            &&$workspace===(string)config('desktop_dashboard.device_id')&&self::$scope===null
            &&(self::$physicalRoot===null||self::$physicalRoot===$workspace),403);
        $previous=self::$physicalRoot;self::$physicalRoot=$workspace;
        try{return $work();}finally{self::$physicalRoot=$previous;}
    }
    public static function readOnly(callable $work)
    {
        $previous=self::$readOnly;$previousMemo=self::$readMemo;
        self::$readOnly=true;self::$readMemo=[];
        try{return $work();}finally{self::$readMemo=$previousMemo;self::$readOnly=$previous;}
    }

    /** Only a caller's stable READ ONLY snapshot may reuse facts; writes and prints always load afresh. */
    public static function memoizedRead(string $identity,callable $load)
    {
        if(!self::$readOnly||self::$readMemo===null)return $load();
        $connection=\Illuminate\Support\Facades\DB::connection();$pdo=$connection->getPdo();
        $dispatcher=$connection->getEventDispatcher();
        // A missing dispatcher cannot prove transaction boundaries. Do not turn it into a cache.
        if($connection->transactionLevel()<1||!$pdo instanceof \PDO||!$pdo->inTransaction()||$dispatcher===null)return $load();
        self::$readConnections??=new \WeakMap;self::$readDispatchers??=new \WeakMap;
        if(!isset(self::$readDispatchers[$dispatcher])){
            foreach([\Illuminate\Database\Events\TransactionBeginning::class,
                \Illuminate\Database\Events\TransactionCommitted::class,\Illuminate\Database\Events\TransactionRolledBack::class]as$event)
                $dispatcher->listen($event,static function($transaction){
                    $connection=$transaction->connection;
                    if(self::$readConnections!==null&&isset(self::$readConnections[$connection]))
                        self::$readConnections[$connection]->epoch++;
                });
            self::$readDispatchers[$dispatcher]=true;
        }
        if(!isset(self::$readConnections[$connection]))self::$readConnections[$connection]=(object)[
            'identity'=>++self::$readConnectionSequence,'pdo'=>$pdo,'epoch'=>0];
        $snapshot=self::$readConnections[$connection];
        if($snapshot->pdo!==$pdo){$snapshot->pdo=$pdo;$snapshot->epoch++;}
        $key=implode(':',[$snapshot->identity,spl_object_id($pdo),$snapshot->epoch,$connection->transactionLevel(),
            $connection->getDatabaseName(),$identity]);
        if(!array_key_exists($key,self::$readMemo))self::$readMemo[$key]=$load();
        return self::$readMemo[$key];
    }

    public static function run(DesktopDashboardWorkspaceScope $scope, callable $work, ?array $incomingProof=null)
    {
        $scope->assertIssued();
        if (self::$scope!==null) {
            abort_unless(self::$scope->id===$scope->id && self::$scope->actor_id===$scope->actor_id
                && hash_equals(self::$scope->rootFingerprint(),$scope->rootFingerprint()),403,
                'لا يمكن تبديل هوية العملية أثناء تنفيذها.');
            // Nested business services inherit the original captured proof.
            abort_unless($incomingProof===null || $incomingProof===self::$proof,403);
            return $work();
        }
        $previous=self::$scope; $previousProof=self::$proof;
        self::$scope=$scope; self::$proof=$incomingProof;
        try { return $work(); }
        finally { self::$scope=$previous; self::$proof=$previousProof; }
    }
}
