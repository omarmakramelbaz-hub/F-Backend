<?php
namespace App\Services\Dashboard;

/** In-process evidence issued only after the workspace staging importer verifies its input. */
final class DesktopDashboardWorkspaceImportProvenance
{
    private function __construct(private DesktopDashboardWorkspaceImport $issuer, private object $capability, private array $value) {}
    public static function issue(DesktopDashboardWorkspaceImport $issuer,object $capability,array $context): self
    {
        $issuer->assertIssuanceCapability($capability);
        return new self($issuer,$capability,$context);
    }
    public function assertVerified(): void { $this->issuer->assertIssuanceCapability($this->capability); }
    public function context(): array { $this->assertVerified();return $this->value; }
    public function __set(string $name,$value): void { throw new \LogicException('Workspace import provenance is immutable.'); }
    public function __serialize(): array { throw new \LogicException('Workspace import provenance cannot be serialized.'); }
    public function __unserialize(array $value): void { throw new \LogicException('Workspace import provenance cannot be deserialized.'); }
    private function __clone() {}
}
