#!/usr/bin/env python3
"""Upgrade the exact published .55 native cohort through the original rollout gates."""
import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import types

TEMPLATE_RELEASE = '12d74b4f35737a2b1e271657b159137538c3edaf'
TEMPLATE_SHA256 = '70bfb190e3b53fcb63ee76eb3ebfe16621be524e65ce623b4418386550081861'
REPAIR_RELEASE = '0adf41c64aa587b495a55b7ec058bf5cc54d1e2a'
OLD_CORE = 'a237b4c048887c6af1b0fb53fe02c414c1d17cd6'
OLD_SEED = '1556e002f18d00b9e8f9ba81590843e0d8697318'
OLD_RUNTIME = 'policy-c5fca0a576be84564425d7de160a95d0825a20f5545d52ef911b4349778ebbe6'
OLD_POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-warm-start-accounts-v2'
OLD_SUFFIX = b'-native-close-reopen-program-contract-warm-start-accounts-v2'
INPUT_CANDIDATE = '825f3f6437d5d7e6bcb90182f69a9af1828706c7'
INPUT_VERSION = '0.4.4-preview.55'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-direct-login-accounts-v1'
SUFFIX = b'-native-close-reopen-program-contract-direct-login-accounts-v1'
# The outer root command is not publishable until all final native and helper
# pins have been supplied and validated by the exact-cohort tests.
PINS_PENDING = False
CORE = '84529f73fc63af98375ca8f7d0e3210ec1f108bc'
SEED = '5404d97ae473cec5e6248ea4bcf494844bd3d6e5'
OLD_MANUAL_PINS = {
    'go_erp_manual_tick_root.py': '361df24ea239a54833c575a27feaa6b403b745ea76f2349d6bc707e35d919179',
    'go_erp_manual_updates_root.py': '36b408d398644f34c4ca931897f1bacaf9002705636568213bcbb9654efa4b55',
    'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b',
}
MANUAL_PINS = {'go_erp_manual_tick_root.py': '86f3bbc88bc862072bb263d7e2bdefac549c7cb7532f12e7ff4e406a99d5c314',
 'go_erp_manual_updates_root.py': '0a1f5ee9232862962e7367bde0ab6029cac3b80eee02857b9449f5a8bb49a874',
 'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
SAFE = 9007199254740991


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def load_template(raw):
    need(sha(raw) == TEMPLATE_SHA256, 'ROLLOUT_TEMPLATE_PIN_INVALID')
    value = types.ModuleType('go_erp_direct_login_original_rollout')
    value.__file__ = '<pinned-go-erp-fresh-source-rollout>'
    sys.modules[value.__name__] = value
    exec(compile(raw, value.__file__, 'exec'), value.__dict__)
    value.Stop = Stop
    return value


def checked_published_state(state, publication):
    desired = state.get('desired') if isinstance(state, dict) else None
    published = state.get('published') if isinstance(state, dict) else None
    need(type(state.get('format')) is int and state['format'] == 1
        and state.get('base_revision') == OLD_SEED and type(state.get('counter')) is int
        and state['counter'] == 55 and state.get('last_remote_sha') == INPUT_CANDIDATE
        and isinstance(desired, dict) and isinstance(published, dict)
        and desired.get('status') == 'published' and desired.get('pushed') is True
        and desired.get('candidatePolicy') == OLD_POLICY
        and desired.get('commit') == published.get('commit') == INPUT_CANDIDATE
        and desired.get('inventorySha256') == published.get('inventorySha256')
        and isinstance(published.get('release'), dict)
        and published['release'].get('source_revision') == INPUT_CANDIDATE
        and published['release'].get('version') == desired.get('version') == state.get('version_floor') == INPUT_VERSION
        and type(state.get('sequence_floor')) is int and 1 <= state['sequence_floor'] <= SAFE
        and published['release'].get('sequence') == state['sequence_floor']
        and isinstance(state.get('published_history'), list) and published['release'] in state['published_history'],
        'EXACT_PUBLISHED_DIRECT_LOGIN_INPUT_REQUIRED')
    need(publication == published['release'], 'PUBLIC_FEED_NOT_CURRENT')
    return desired


def checked_previous_job(job, state):
    published = state.get('published') or {}
    release = published.get('release') or {}
    need(type(job.get('format')) is int and job['format'] == 1
        and job.get('kind') == 'go-erp-warm-start-repair-rollout'
        and job.get('core') == OLD_CORE and job.get('seed') == OLD_SEED
        and job.get('runtime') == OLD_RUNTIME and job.get('phase') == 'ready'
        and job.get('candidate') == INPUT_CANDIDATE and job.get('version') == INPUT_VERSION
        and isinstance(job.get('source'), dict) and job['source'] == release.get('source')
        and job.get('inventory') == published.get('inventorySha256'),
        'PREVIOUS_DIRECT_LOGIN_INPUT_NOT_READY')
    return job


def configure(template, core=CORE, seed=SEED, manual_pins=MANUAL_PINS):
    """Bind unchanged frozen functions to one reviewed old/new native cohort."""
    need(REV.fullmatch(core) and REV.fullmatch(seed) and core != seed
        and set(manual_pins) == set(OLD_MANUAL_PINS)
        and all(isinstance(value, str) and SHA.fullmatch(value) for value in manual_pins.values()),
        'NATIVE_PINS_PENDING')
    for key, value in {
        'OLD_TICK_SHA': OLD_MANUAL_PINS['go_erp_manual_tick_root.py'],
        'PREVIOUS': REPAIR_RELEASE, 'OLD_CORE': OLD_CORE, 'OLD_SEED': OLD_SEED,
        'OLD_RUNTIME': OLD_RUNTIME, 'OLD_POLICY': OLD_POLICY, 'OLD_SUFFIX': OLD_SUFFIX,
        'CORE': core, 'SEED': seed, 'POLICY': POLICY, 'SUFFIX': SUFFIX,
        'INPUT_CANDIDATE': INPUT_CANDIDATE, 'INPUT_VERSION': INPUT_VERSION,
        'OLD_MANUAL_PINS': copy.deepcopy(OLD_MANUAL_PINS), 'MANUAL_PINS': copy.deepcopy(manual_pins),
        'PACKAGE_PROGRAM_PATHS': {
            'go_erp_manual_updates_root.py': 'deployment/go_erp_direct_login_manual_root.py',
            'go_erp_manual_tick_root.py': 'deployment/go_erp_direct_login_tick_root.py',
            'go_erp_published_download_root.py': 'deployment/go_erp_published_download_root.py'},
    }.items():
        setattr(template, key, value)
    template.checked_published_state = checked_published_state

    def checked_native_core(release):
        # Published .55 legitimately contains later live website changes.
        # Native-only source must be compared with its actual old native core.
        need(release.git('diff-tree', '--no-commit-id', '--name-only', '--no-renames', '--no-ext-diff',
            OLD_CORE, core, '--', 'app', 'bootstrap', 'config', 'database', 'desktop', 'public',
            'resources', 'routes', 'artisan', 'composer.json', 'composer.lock') == b'',
            'NATIVE_CORE_APPLICATION_OR_DEPENDENCY_CHANGED')

    template.checked_native_core = checked_native_core
    original_release = template.Release

    class Release(original_release):
        def file(self, path, revision=None):
            if path == 'deployment/go_erp_startup_session_tick_root.py' and revision == REPAIR_RELEASE:
                path = 'deployment/go_erp_warm_start_repair_tick_root.py'
            return super().file(path, revision)

    template.Release = Release
    original_context = template.Context

    class Context(original_context):
        def __init__(self, release, engine):
            super().__init__(release, engine)
            self.job_path = template.CONTROL / 'private' / ('direct-login-' + core + '.json')
            self.prior_job_path = template.CONTROL / 'private' / ('warm-start-repair-' + OLD_CORE + '.json')

        def previous_proof(self):
            if self.job is None:
                state = self.state()
                checked_published_state(state, self.publication())
            else:
                state = self.base.parse(self.e.read_file(Path(self.job['audit']) / 'previous-state.json',
                    16 * 1024**2, uid=0, modes=(0o600,))[0])
                checked_published_state(state, (state.get('published') or {}).get('release'))
            raw = self.e.read_file(self.prior_job_path, 16 * 1024**2, uid=0, modes=(0o600,))[0]
            previous = checked_previous_job(self.base.parse(raw), state)
            expected = self.old_worker.program_gate_expected(OLD_CORE, previous['source'], previous['inventory'])
            release = state['published']['release']
            completed_raw = self.e.read_file(template.WORK / ('program-rollout-completed-' + OLD_CORE + '.json'),
                2 * 1024**2, uid=0, modes=(0o600,))[0]
            completed = self.base.parse(completed_raw)
            try:
                verified = self.old_worker.program_gate_completed(template.WORK, expected, state, lambda: release)
            except self.old_worker.ProgramGateError as error:
                code = str(error)
                raise Stop(code if re.fullmatch(r'GO_ERP_[A-Z0-9_]{1,140}', code)
                    else 'PREVIOUS_DIRECT_LOGIN_ACCEPTANCE_CHANGED') from None
            need(verified, 'PREVIOUS_DIRECT_LOGIN_ACCEPTANCE_CHANGED')
            authorization = completed.get('authorization')
            need(isinstance(authorization, dict) and authorization.get('candidate') == INPUT_CANDIDATE
                and authorization.get('version') == INPUT_VERSION and authorization.get('release') == release
                and authorization.get('installer') == release.get('installer')
                and isinstance(authorization.get('receipt_sha256'), str) and SHA.fullmatch(authorization['receipt_sha256'])
                and isinstance(authorization.get('receipt_path'), str), 'PREVIOUS_DIRECT_LOGIN_RECEIPT_UNREVIEWED')
            receipt_path = Path(authorization['receipt_path'])
            need(receipt_path.parent == template.WORK / 'program-rollout-authorizations'
                and receipt_path.name == 'receipt-' + authorization['receipt_sha256'] + '.json',
                'PREVIOUS_DIRECT_LOGIN_RECEIPT_PATH_CHANGED')
            receipt_raw = self.e.read_file(receipt_path, 2 * 1024**2, uid=0, modes=(0o600,))[0]
            need(sha(receipt_raw) == authorization['receipt_sha256'], 'PREVIOUS_DIRECT_LOGIN_RECEIPT_CHANGED')
            receipt = self.base.parse(receipt_raw)
            need(receipt.get('candidate_revision') == INPUT_CANDIDATE and receipt.get('version') == INPUT_VERSION
                and all(receipt.get(key) == value for key, value in expected.items())
                and receipt.get('installer') == release.get('installer')
                and receipt.get('employee_evidence') == authorization.get('employee_evidence'),
                'PREVIOUS_DIRECT_LOGIN_RECEIPT_BINDING_CHANGED')
            return raw, completed_raw, receipt_raw

        def verify_predecessor(self):
            return self.previous_proof()[0]

        def quiet_hold(self):
            super().quiet_hold()
            _, _, receipt = self.previous_proof()
            pending = self.e.read_file(template.WORK / 'pending-program-acceptance.json', 2 * 1024**2,
                uid=0, modes=(0o600,), optional=True)
            need(pending is not None and pending[0] == receipt, 'PREVIOUS_DIRECT_LOGIN_PENDING_RECEIPT_CHANGED')

        def job_record(self):
            initial = self.e.read_file(self.job_path, 16 * 1024**2, uid=0, modes=(0o600,), optional=True) is None
            state_raw = self.e.read_file(template.WORK / 'state/current.json', 16 * 1024**2,
                uid=0, modes=(0o600,))[0] if initial else None
            super().job_record()
            previous, completed, receipt = self.previous_proof()
            audit = Path(self.job['audit'])
            for name, raw in (('previous-rollout.json', previous), ('previous-completed.json', completed),
                    ('previous-pending-acceptance.json', receipt)):
                existing = self.e.read_file(audit / name, 16 * 1024**2, uid=0, modes=(0o600,), optional=True)
                if existing is None:
                    self.e.atomic_write(audit / name, raw, 0, 0, 0o600)
                else:
                    need(existing[0] == raw, 'PREVIOUS_DIRECT_LOGIN_AUDIT_CHANGED')
            if initial:
                need(self.base.parse(state_raw) == self.base.parse(self.e.read_file(audit / 'previous-state.json',
                    16 * 1024**2, uid=0, modes=(0o600,))[0]), 'PREVIOUS_DIRECT_LOGIN_STATE_CHANGED')
                self.e.atomic_write(audit / 'previous-state-raw.json', state_raw, 0, 0, 0o600)
            need(self.job.get('remote_lease') == INPUT_CANDIDATE, 'DIRECT_LOGIN_REMOTE_LEASE_CHANGED')

        def manual(self):
            receipt = self.base.parse(self.e.read_file(template.CONTROL / 'private/installation.json',
                8192, uid=0, modes=(0o600,))[0])
            old_runtime = self.e.sha(self.e.canonical(dict(sorted(OLD_MANUAL_PINS.items()))))
            new_runtime = self.e.sha(self.e.canonical(dict(sorted(template.MANUAL_PINS.items()))))
            need(set(receipt) == {'format', 'kind', 'release', 'runtime', 'units'}
                and ((receipt.get('release') == REPAIR_RELEASE and receipt.get('runtime') == old_runtime)
                    or (receipt.get('release') == self.release.revision and receipt.get('runtime') in (old_runtime, new_runtime))),
                'DIRECT_LOGIN_MANUAL_RECEIPT_UNREVIEWED')
            return super().manual()

        def prepare(self, locked_utility):
            # Check the exact original allocation budget before starting the
            # cohort migration or consuming any next_counter reservation.
            self.worker.require_space(template.WORK,
                sum(row.get('bytes', 0) for row in self.captured_metadata()['inventory'].values()) * 3 + 128 * 1024**2)
            self.worker.owned_directory(template.WORK / 'staging')
            return super().prepare(locked_utility)

    template.Context = Context
    return template


def read_template(repository):
    result = subprocess.run(['/usr/bin/git', '--git-dir=' + str(repository), '-c', 'credential.helper=',
        '-c', 'core.hooksPath=/dev/null', 'show', TEMPLATE_RELEASE + ':deployment/upgrade_go_erp_warm_start_root.py'],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=180,
        env={'PATH': '/usr/bin:/bin', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
            'GIT_CONFIG_SYSTEM': '/dev/null', 'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_TERMINAL_PROMPT': '0',
            'GIT_OPTIONAL_LOCKS': '0'})
    need(result.returncode == 0 and len(result.stdout) <= 2 * 1024**2, 'ROLLOUT_TEMPLATE_READ_FAILED')
    return load_template(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir', required=True); parser.add_argument('--release', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--apply', action='store_true'); mode.add_argument('--complete', action='store_true')
    args = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524' and REV.fullmatch(args.release),
        'ROOT_HOST_RELEASE_REQUIRED')
    need(not PINS_PENDING, 'NATIVE_PINS_PENDING')
    os.umask(0o077)
    template = configure(read_template(args.git_dir))
    release = template.Release(args.git_dir, args.release)
    engine = template.module(release.file('deployment/apply_go_erp_manual_updates_root.py', template.BOOTSTRAP),
        'direct_login_file_engine', template.ENGINE_SHA)
    engine.Stop = Stop
    repository = release.repository.absolute(); engine.parents(repository)
    need(repository.resolve() == repository and repository.stat().st_uid == 0 and not repository.stat().st_mode & 0o022,
        'REPOSITORY_UNSAFE')
    need(release.git('rev-parse', args.release + '^{commit}').decode().strip() == args.release, 'RELEASE_INVALID')
    result = template.execute(release, engine, args.complete)
    print(json.dumps({'format': 1, 'kind': 'go-erp-direct-login-rollout', **result,
        'database_changed': False, 'credentials_disclosed': False, 'automatic_updates_enabled': False}), flush=True)
    print('GO_ERP_DIRECT_LOGIN_PUBLISHED_MANUAL_UPDATES_ONLY' if result['published']
        else 'GO_ERP_DIRECT_LOGIN_PREPARED_PUBLICATION_HELD')


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        code = str(error) if isinstance(error, Stop) and re.fullmatch(r'(?:GO_ERP_)?[A-Z0-9_]{1,150}', str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'format': 1, 'kind': 'go-erp-direct-login-rollout', 'ok': False,
            'code': 'GO_ERP_DIRECT_LOGIN_' + code, 'published': False, 'database_changed': False,
            'credentials_disclosed': False, 'automatic_updates_enabled': False}), flush=True)
        raise SystemExit(1)
