<?php
namespace App\Services\Dashboard;

/** Synchronous, request-bound authority. It is never reconstructed from request actor fields. */
final class DesktopDashboardWorkspaceExecutionContext
{
    private static ?DesktopDashboardWorkspaceScope $scope=null;
    private static ?array $proof=null;
    private static bool $readOnly=false;
    private static ?string $physicalRoot=null;

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
        $previous=self::$readOnly;self::$readOnly=true;
        try{return $work();}finally{self::$readOnly=$previous;}
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
