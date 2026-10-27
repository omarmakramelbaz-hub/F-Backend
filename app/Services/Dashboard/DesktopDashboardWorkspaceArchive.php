<?php
namespace App\Services\Dashboard;

/** Group cutover carries the already verified, all-actor replay projection without a history scan under the fence. */
final class DesktopDashboardWorkspaceArchive extends DesktopDashboardArchive
{
    public function sealWorkspace(array $receipt,array $expectedBinding,string $source,string $refreshId,string $token,
        array $sourceIdentity,array $mediaSeal,array $archiveHandoff,string $handoffToken): array
    {
        // This rejects v1 proof/receipt, verifies every exact private binding,
        // and rechecks sealed file identities before the active-state CAS.
        $verified=app(DesktopDashboardWorkspaceHandoff::class)->verifyWorkspaceHandoff($receipt,$expectedBinding,
            $mediaSeal,$archiveHandoff,$handoffToken,$source,$sourceIdentity);
        return $this->archiveVerified($receipt,$source,$refreshId,$token,$sourceIdentity,$verified);
    }
    /** A group archive never falls back to the actor-scoped, scanning v1 path. */
    public function seal(array $receipt,string $source,string $id,string $token,?array $sourceIdentity=null,?array $mediaSeal=null,?array $archiveHandoff=null,?string $handoffToken=null): array
    {
        abort(403,'مساحة عمل الجهاز تحتاج دليل تفعيل من المسار الخاص بالبرنامج.');
    }
}
