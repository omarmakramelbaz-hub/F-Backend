<?php
namespace App\Services\Dashboard;

/** Server authority and native-installed local authority issue the same immutable actor facade. */
interface DesktopDashboardWorkspaceIssuer
{
    public function assertIssuanceCapability(object $capability): void;
    public function assertScope(DesktopDashboardWorkspaceScope $scope): void;
    public function revalidateScope(DesktopDashboardWorkspaceScope $scope): DesktopDashboardWorkspaceScope;
}
