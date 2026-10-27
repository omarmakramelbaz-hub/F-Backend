#!/usr/bin/env python3
"""Derive the next immutable collector without weakening its original gates.

The native cohort migration first performs the original seed/policy replacement.
This module accepts only that exact reviewed .59 worker, with those two bindings
possibly changed to the next reviewed cohort. It extends the ZIP roster by one
installed-ASAR receipt and verifies it after all original installer checks.
No worker module is imported or modified in memory by this module.
"""
import hashlib
import re

OLD_WORKER_SHA256 = '2555209445b41641a1358d5a75d0dd9f501b8992510bfd7661573664b4a84f5f'
OLD_SEED = '0885a3b550f92df2b74bcd2c16d27954d8b44b7b'
OLD_SUFFIX = '-native-close-reopen-program-contract-direct-login-accounts-v4'
NEW_SUFFIX = '-native-close-reopen-program-contract-direct-login-accounts-v5'
PROOF = 'installed-startup-source-native.json'
PROOF_LIMIT = 64 * 1024
# These are native fixture capability hashes, not claims of production HTTPS or
# a physical user dashboard. The final core is independently checked against them.
SOURCE_HASHES = {
    'helper': '429cd0b29eb9b4ffca3217ab455086f1783f27c21dca3675a593d8c530b68c5c',
    'fixture': '2023d82c86314daaa96dd944bfaa90df0749ce9a8ccdb4351fc7c949b40ef886',
    'nativeModule': '83d4678a4406cde40ebf67fa464acb434b38aaada13fd29632609c3718f3c5e6',
}
TRUE_FLAGS = (
    'actualWindows', 'actualPhp', 'actualMariaDB', 'actualDeltaCapture',
    'actualDeltaStage', 'actualDeltaFinalize', 'actualDeltaActivate',
    'sourceFingerprintChanged', 'preparedDataPreserved', 'originalUsersPreserved',
    'originalSessionBytesPreserved', 'historicalReplayPreserved',
    'originalPreviousDatabaseRetained', 'originalCoveragePreserved',
    'offlineZeroHttp', 'sameSourceZeroHttp', 'unapprovedPreviewDenied',
    'authorityFixture', 'previewOnly', 'completed', 'restartRetainsActivatedSource',
)
FALSE_FLAGS = ('fullSnapshotFallback', 'actualHttpsPublication', 'actualFullDashboard')

ROSTER_ANCHOR = b"         'go-erp-live-source-acceptance.json': 64 * 1024}\n"
VERIFY_ANCHOR = (
    b"    publisher.verify_installer(folder / 'GO-ERP-Preview-Setup.exe', release['installer'], root_owned=True)\n"
    b"    return receipt, release\n"
)


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_OWNER_FAST_OPEN_PAYLOAD_' + code)


def _normalized_worker(raw):
    need(isinstance(raw, bytes) and len(raw) == 70031, 'WORKER_BYTES_INVALID')
    seed_pattern = rb"(?m)^PREVIEW31_SEED = '[a-f0-9]{40}'$"
    policy_pattern = rb"(?m)^PREVIEW31_POLICY = CANDIDATE_POLICY \+ '([^'\r\n]+)'$"
    seeds = re.findall(seed_pattern, raw)
    policies = re.findall(policy_pattern, raw)
    need(len(seeds) == len(policies) == 1
        and policies[0].decode('ascii') in (OLD_SUFFIX, NEW_SUFFIX), 'COHORT_BINDINGS_CHANGED')
    normalized = re.sub(seed_pattern, ("PREVIEW31_SEED = '" + OLD_SEED + "'").encode(), raw)
    normalized = re.sub(policy_pattern,
        ("PREVIEW31_POLICY = CANDIDATE_POLICY + '" + OLD_SUFFIX + "'").encode(), normalized)
    need(hashlib.sha256(normalized).hexdigest() == OLD_WORKER_SHA256,
        'ORIGINAL_WORKER_CHANGED')
    return normalized


def _proof_function():
    names = ('format', 'kind', 'sourceRevision', 'sourceHashes', 'installedAsarCode') + TRUE_FLAGS + FALSE_FLAGS
    # repr() receives only fixed reviewed constants. No source, file content,
    # request value or candidate-provided expression is interpolated here.
    return (
        "\n\ndef verify_installed_startup_source(folder, desired):\n"
        "    value = parse_json(regular_bytes(folder / " + repr(PROOF) + ", FILES[" + repr(PROOF) + "]))\n"
        "    need(exact(value, " + repr(names) + ")\n"
        "         and type(value.get('format')) is int and value['format'] == 1\n"
        "         and value.get('kind') == 'go-erp-startup-source-native'\n"
        "         and isinstance(desired.get('commit'), str) and REV.fullmatch(desired['commit'])\n"
        "         and value.get('sourceRevision') == desired['commit']\n"
        "         and value.get('installedAsarCode') is True\n"
        "         and value.get('sourceHashes') == " + repr(SOURCE_HASHES) + "\n"
        "         and all(value.get(name) is True for name in " + repr(TRUE_FLAGS) + ")\n"
        "         and all(value.get(name) is False for name in " + repr(FALSE_FLAGS) + "),\n"
        "         'CI_INSTALLED_STARTUP_PROOF_INVALID')\n"
    ).encode('ascii')


def transform(raw):
    """Return bytes for one pinned new collector; leave supplied bytes untouched."""
    _normalized_worker(raw)
    need(raw.count(ROSTER_ANCHOR) == raw.count(VERIFY_ANCHOR) == 1
        and raw.count(b'\n\nclass Git:\n') == 1, 'PATCH_CONTEXT_INVALID')
    need(set(SOURCE_HASHES) == {'helper', 'fixture', 'nativeModule'}
        and all(isinstance(value, str) and re.fullmatch(r'[a-f0-9]{64}', value)
            for value in SOURCE_HASHES.values())
        and len(TRUE_FLAGS) == 21 and len(FALSE_FLAGS) == 3
        and len(set(TRUE_FLAGS + FALSE_FLAGS)) == 24, 'RECEIPT_CONSTANTS_INVALID')
    result = raw.replace(ROSTER_ANCHOR,
        b"         'go-erp-live-source-acceptance.json': 64 * 1024,\n"
        b"         'installed-startup-source-native.json': 64 * 1024}\n")
    result = result.replace(VERIFY_ANCHOR, VERIFY_ANCHOR.replace(
        b'    return receipt, release\n',
        b'    verify_installed_startup_source(folder, desired)\n    return receipt, release\n'))
    result = result.replace(b'\n\nclass Git:\n', _proof_function() + b'\n\nclass Git:\n')
    compile(result, '<reviewed-owner-fast-open-collector>', 'exec')
    return result


if __name__ == '__main__':
    raise SystemExit('This derivation requires the pinned native cohort coordinator.')
