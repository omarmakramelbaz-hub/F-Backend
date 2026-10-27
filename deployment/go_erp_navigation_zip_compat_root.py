#!/usr/bin/env python3
"""In-memory collector compatibility for one already built navigation candidate.

The pinned driver loads the original immutable worker before calling install.
Only its exact receipt roster and two proof-bound entry points are extended;
the original downloader, extractor, installer verification and publication gates
continue to run. This module never writes a runtime, state file or journal.
"""
from pathlib import Path

CANDIDATE = '5becb9bab38a91998d1b1341670b2b398e4ba70f'
VERSION = '0.4.4-preview.59'
INVENTORY = '76bd768b032994d0cb3db624c0b2ef0e5bafedb3a2cbffd394b66e31ce53c225'
SEED = '0885a3b550f92df2b74bcd2c16d27954d8b44b7b'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-direct-login-accounts-v4'
RUN_ID = 40415904540
RUN_ATTEMPT = 1
ARTIFACT_ID = 12699425143
ARTIFACT_SIZE = 432377568
ARTIFACT_DIGEST = 'sha256:04c7803fea9eba11cd27ce8278b7aa677ca08951647fc49d6f60518f9336a923'
PROOF = 'installed-startup-source-native.json'
PROOF_LIMIT = 64 * 1024
ORIGINAL_FILES = {
    'GO-ERP-Preview-Setup.exe': 1024 ** 3,
    'installer-checks.json': 512 * 1024,
    'runtime-package.json': 16 * 1024 ** 2,
    'runtime-audit.json': 8 * 1024 ** 2,
    'prerequisites/vcredist-package.json': 512 * 1024,
    'offline-print-native.json': 512 * 1024,
    'outbox-review-native.json': 512 * 1024,
    'connected-request-native.json': 512 * 1024,
    'go-erp-update-release.json': 512 * 1024,
    'go-erp-live-source-acceptance.json': 64 * 1024,
}
SOURCE_HASHES = {
    'helper': '429cd0b29eb9b4ffca3217ab455086f1783f27c21dca3675a593d8c530b68c5c',
    'fixture': '2023d82c86314daaa96dd944bfaa90df0749ce9a8ccdb4351fc7c949b40ef886',
    'nativeModule': '83d4678a4406cde40ebf67fa464acb434b38aaada13fd29632609c3718f3c5e6',
}
TRUE_FLAGS = frozenset({
    'actualWindows', 'actualPhp', 'actualMariaDB', 'actualDeltaCapture',
    'actualDeltaStage', 'actualDeltaFinalize', 'actualDeltaActivate',
    'sourceFingerprintChanged', 'preparedDataPreserved', 'originalUsersPreserved',
    'originalSessionBytesPreserved', 'historicalReplayPreserved',
    'originalPreviousDatabaseRetained', 'originalCoveragePreserved',
    'offlineZeroHttp', 'sameSourceZeroHttp', 'unapprovedPreviewDenied',
    'authorityFixture', 'previewOnly', 'completed', 'restartRetainsActivatedSource',
})
FALSE_FLAGS = frozenset({'fullSnapshotFallback', 'actualHttpsPublication', 'actualFullDashboard'})


def _need(worker, value, reason):
    worker.need(value, 'NAVIGATION_ZIP_COMPAT_' + reason)


def _run(worker, value, api=False):
    # Parent authorization reconstructs only id/attempt from the retained CI
    # receipt; artifact collection additionally has the full GitHub API run.
    if api:
        worker.run_identity(value, 'desktop-dashboard-tests.yml', CANDIDATE)
    _need(worker, isinstance(value, dict) and type(value.get('id')) is int and value['id'] == RUN_ID
        and type(value.get('run_attempt')) is int and value['run_attempt'] == RUN_ATTEMPT,
        'RUN_CHANGED')


def _proof(worker, folder):
    value = worker.parse_json(worker.regular_bytes(Path(folder) / PROOF, PROOF_LIMIT))
    names = {'format', 'kind', 'sourceRevision', 'sourceHashes', 'installedAsarCode'} | TRUE_FLAGS | FALSE_FLAGS
    _need(worker, isinstance(value, dict) and set(value) == names
        and type(value.get('format')) is int and value['format'] == 1
        and value.get('kind') == 'go-erp-startup-source-native'
        and value.get('sourceRevision') == CANDIDATE
        and value.get('installedAsarCode') is True
        and all(value.get(name) is True for name in TRUE_FLAGS)
        and all(value.get(name) is False for name in FALSE_FLAGS)
        and value.get('sourceHashes') == SOURCE_HASHES, 'INSTALLED_STARTUP_PROOF_INVALID')


def install(worker):
    """Add one strictly checked installed-ASAR receipt to a fresh exact worker."""
    _need(worker, worker.PREVIEW31_SEED == SEED and worker.PREVIEW31_POLICY == POLICY
        and worker.REPOSITORY == 'omarmakramelbaz-hub/F-Backend'
        and worker.REPOSITORY_ID == 1311412084
        and worker.BRANCH == 'codex/go-erp-live-source-20261021'
        and worker.ROOT == Path('/home/fasakha/public_html')
        and worker.WORK == Path('/var/lib/go-erp-live-updates')
        and worker.CONFIG == Path('/etc/go-erp/live-updates.json'), 'COHORT_CHANGED')
    _need(worker, type(worker.FILES) is dict and worker.FILES == ORIGINAL_FILES
        and all(type(value) is int for value in worker.FILES.values())
        and type(worker.MAX_ARCHIVE) is int and worker.MAX_ARCHIVE == 1024 ** 3 + 64 * 1024 ** 2
        and callable(worker.extract_artifact) and callable(worker.verify_proof)
        and callable(worker.accepted_artifact), 'ORIGINAL_ROSTER_CHANGED')
    original_artifact = worker.accepted_artifact
    original_verify = worker.verify_proof

    def accepted_artifact(client, run, revision):
        artifact = original_artifact(client, run, revision)
        _run(worker, run, api=True)
        _need(worker, revision == CANDIDATE and artifact.get('id') == ARTIFACT_ID
            and artifact.get('size_in_bytes') == ARTIFACT_SIZE
            and artifact.get('digest') == ARTIFACT_DIGEST, 'ARTIFACT_CHANGED')
        return artifact

    def verify_proof(folder, run, desired, publisher):
        result = original_verify(folder, run, desired, publisher)
        _run(worker, run)
        _need(worker, isinstance(desired, dict) and desired.get('commit') == CANDIDATE
            and desired.get('version') == VERSION and desired.get('inventorySha256') == INVENTORY,
            'CANDIDATE_CHANGED')
        receipt, release = result
        _need(worker, isinstance(release, dict) and release.get('source_revision') == CANDIDATE
            and release.get('version') == VERSION and release.get('sequence') == RUN_ID,
            'RELEASE_CHANGED')
        _proof(worker, folder)
        return receipt, release

    worker.FILES = {**ORIGINAL_FILES, PROOF: PROOF_LIMIT}
    worker.accepted_artifact = accepted_artifact
    worker.verify_proof = verify_proof
    return worker


if __name__ == '__main__':
    raise SystemExit('This compatibility module requires the pinned navigation resume driver.')
