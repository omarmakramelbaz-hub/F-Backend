<?php
namespace App\Services\Dashboard;

/** Synchronous, request-bound authority. It is never reconstructed from request actor fields. */
final class DesktopDashboardWorkspaceExecutionContext
{
    private static ?DesktopDashboardWorkspaceScope $scope=null;
    private static ?array $proof=null;

    public static function current(): ?DesktopDashboardWorkspaceScope { return self::$scope; }
    public static function incomingProof(): ?array { return self::$proof; }

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
