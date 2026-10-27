<?php
namespace App\Services\Dashboard;

use App\Models\User;

/** Internal effective actor authority; the physical enrollment is never rebound. */
final class DesktopDashboardWorkspaceScope
{
    private function __construct(
        private DesktopDashboardWorkspaceAuthority $issuer,
        private object $capability,
        private object $physical,
        private User $effectiveActor,
        private array $membership,
        private string $fingerprint,
        private int $revision,
        private ?array $acceptedMembership,
        private ?int $acceptedRevision
    ) {}

    /** Only an issuing authority's private object capability can create this facade. */
    public static function issue(DesktopDashboardWorkspaceAuthority $issuer, object $capability,
        object $physical, User $actor, array $member, string $fingerprint, int $revision,
        ?array $acceptedMembership = null, ?int $acceptedRevision = null): self
    {
        $issuer->assertIssuanceCapability($capability);
        return new self($issuer, $capability, clone $physical, clone $actor, $member, $fingerprint, $revision,
            $acceptedMembership, $acceptedRevision);
    }

    public function assertIssued(): void { $this->issuer->assertIssuanceCapability($this->capability); }
    public function root(): object { $this->assertIssued(); return clone $this->physical; }
    public function actor(): User { $this->assertIssued(); return clone $this->effectiveActor; }
    public function member(): array { $this->assertIssued(); return $this->membership; }
    public function rootFingerprint(): string { return $this->fingerprint; }
    public function manifestRevision(): int { return $this->revision; }
    public function acceptedMember(): ?array { $this->assertIssued(); return $this->acceptedMembership; }
    public function acceptedManifestRevision(): ?int { return $this->acceptedRevision; }
    public function branchGrants(): array
    {
        return $this->acceptedMembership === null ? $this->membership['branches']
            : array_values(array_intersect($this->membership['branches'],$this->acceptedMembership['branches']));
    }
    /** Replay can never adopt a permission added after its original offline acceptance. */
    public function permissionFacts(): array
    {
        $now=$this->membership['permissions']; if ($this->acceptedMembership===null) return $now;
        $then=$this->acceptedMembership['permissions'];
        return ['super_admin'=>$now['super_admin'] && $then['super_admin'],
            'names'=>array_values(array_intersect($now['names'],$then['names'])),
            'roles'=>array_values(array_intersect($now['roles'],$then['roles']))];
    }

    public function __get(string $name)
    {
        $this->assertIssued();
        if ($name === 'actor_id') return $this->membership['actor_id'];
        if ($name === 'branches') return DesktopDashboardWorkspaceAuthority::canonical($this->branchGrants());
        if ($name === 'workspace_protocol' || $name === 'protocol') return 2;
        if ($name === 'device_id' || $name === 'workspace_id') return $this->physical->id;
        if (in_array($name, ['id','name','enabled','enrollment_hash','token_hash','enrolled_at','custodian_actor_id'], true))
            return $this->physical->$name;
        throw new \LogicException('Unknown workspace authority field.');
    }
    public function __isset(string $name): bool
    {
        return in_array($name, ['actor_id','branches','workspace_protocol','protocol','device_id','workspace_id',
            'id','name','enabled','enrollment_hash','token_hash','enrolled_at','custodian_actor_id'], true);
    }
    public function __set(string $name, $value): void { throw new \LogicException('Workspace authority is immutable.'); }
    public function __unset(string $name): void { throw new \LogicException('Workspace authority is immutable.'); }
    public function __serialize(): array { throw new \LogicException('Workspace authority cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Workspace authority cannot be deserialized.'); }
    public function __debugInfo(): array
    {
        return ['protocol'=>2, 'workspace_id'=>$this->physical->id, 'actor_id'=>$this->membership['actor_id'], 'revision'=>$this->revision];
    }
    private function __clone() {}
}
