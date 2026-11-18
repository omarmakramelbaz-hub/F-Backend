<?php
namespace App\Services\Dashboard;

/** Internal union audience. Neither the custody actor nor request fields stand in for other members. */
final class DesktopDashboardWorkspaceSnapshotAudience
{
    private function __construct(private DesktopDashboardWorkspaceAuthority $issuer, private object $capability,
        private object $physical, private array $membership, private array $memberActors) {}
    public static function issue(DesktopDashboardWorkspaceAuthority $issuer, object $capability,
        object $physical, array $manifest, array $actors): self
    {
        $issuer->assertIssuanceCapability($capability);
        return new self($issuer,$capability,clone $physical,$manifest,array_map(fn($actor)=>clone $actor,$actors));
    }
    public function assertIssued(): void { $this->issuer->assertIssuanceCapability($this->capability); }
    public function root(): object { $this->assertIssued(); return clone $this->physical; }
    public function manifest(): array { $this->assertIssued(); return $this->membership; }
    public function actors(): array { $this->assertIssued(); return array_map(fn($actor)=>clone $actor,$this->memberActors); }
    public function context(): array
    {
        return array_intersect_key($this->membership,array_flip(['workspace_id','revision','root_fingerprint','roster_sha256']));
    }
    public function __set(string $name, $value): void { throw new \LogicException('Workspace audience is immutable.'); }
    public function __serialize(): array { throw new \LogicException('Workspace audience cannot be serialized.'); }
    public function __unserialize(array $value): void { throw new \LogicException('Workspace audience cannot be deserialized.'); }
    private function __clone() {}
}
