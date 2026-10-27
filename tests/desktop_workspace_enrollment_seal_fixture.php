<?php
// Disposable CLI interoperability boundary. Never boot Laravel or read application data.
require_once __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';
require_once __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceAuthority.php';
require_once __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceEnrollmentSeal.php';
use App\Services\Dashboard\DesktopDashboardWorkspaceEnrollmentSeal as Seal;
try {
    $raw = stream_get_contents(STDIN, Seal::MAX_ENVELOPE_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > Seal::MAX_ENVELOPE_BYTES) throw new \InvalidArgumentException('INPUT_INVALID');
    $request = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($request) || count($request) !== 4
        || array_diff(array_keys($request), ['payload', 'public_key', 'workspace_id', 'nonce'])) throw new \InvalidArgumentException('INPUT_INVALID');
    $envelope = (new Seal)->seal($request['payload'], $request['public_key'], $request['workspace_id'], $request['nonce']);
    echo Seal::encode($envelope);
} catch (\Throwable $error) {
    fwrite(STDERR, "WORKSPACE_ENROLLMENT_SEAL_FIXTURE_REJECTED\n"); exit(1);
}
