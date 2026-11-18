<?php
namespace App\Services\Dashboard;

/** Original business replay and idempotency, with the captured original actor instead of custody actor. */
final class DesktopDashboardWorkspaceReconciliation
{
    public function __construct(private DesktopDashboardWorkspaceAuthority $authority,
        private DesktopDashboardWorkspaceCommandAuthorities $proofs, private DesktopDashboardJournal $journal,
        private DesktopDashboardReconciliation $original) {}

    public function ingest(object $authenticatedRoot,array $values): array
    {
        $proof=$this->proofs->proof($values['workspace_authority']??null,$authenticatedRoot->id,
            $this->authority->rootFingerprint($authenticatedRoot));
        abort_unless(($values['actor_id']??null)===$proof['actor_id']&&is_string($values['route_name']??null)
            &&is_array($values['payload']??null)&&is_array($values['dependencies']??null),422);
        abort_unless(hash_equals($proof['command_sha256'],$this->journal->fingerprint([
            $values['actor_id'],$values['route_name'],$values['payload'],$values['dependencies']])),409,
            'بيانات العملية لا تطابق الدليل المحفوظ؛ لم تُنفذ العملية.');
        $scope=$this->authority->scopeForReplay($authenticatedRoot,$proof['actor_id'],$proof['grant'],$proof['manifest_revision']);
        unset($values['workspace_authority']);
        return DesktopDashboardWorkspaceExecutionContext::run($scope,
            fn()=>$this->original->ingest($scope,$values),$proof);
    }
}
