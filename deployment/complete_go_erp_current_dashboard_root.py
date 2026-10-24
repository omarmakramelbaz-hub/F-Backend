#!/usr/bin/env python3
"""Collect, authorize and verify the current reviewed GO ERP native rollout.

The installed worker owns CI downloads and publication. This adapter only reads
its fixed private controls and authorizes the original hash-bound acceptance.
"""
import argparse
import hashlib
import os
from pathlib import Path
import re
import socket
import stat
import sys
import tempfile
import types

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
RECOVERY_NAME = 'upgrade_go_erp_employee_dialog_root.py'
RECOVERY_SHA256 = 'a448f744e4bde967fe017af779f52ea4cd396f1fa2b915b5e91c037015cc9fd5'
OLD_SEED = 'ef60065c79c78dcec3a6ca28142dbb483ed9eed3'
OLD_CORE = 'd099f9b3889f7f2f71eeb7e778808a9430c13cdf'
SEED = 'ab6bf30cf6fa9cedd66422b6428e388f764eee35'
CORE = 'bfde969e1fb83197b9dad76ac66831b07b34a7c3'
OLD_CANDIDATE = '73828f6710eeb357271b4381c402a00247830efa'
RUNTIME = 'policy-acec2784a60f6ced80fd32c7e620df811f8de88389dfc56790afc15817acb4c2'
SOURCE = {'format': 1, 'files': 1355, 'framework': '8.83.29',
    'sha256': '47e5e8e28183d4e551cbc9ed4ad2cba6710f613e983844d152a515da94b347a5'}
INVENTORY = '8d8917aba049c8d9d0d1200296b925c078cc7516a231897b8d2d36a370fb794c'
BRANCH = 'refs/heads/codex/go-erp-live-source-20261021'
SAFE = 9007199254740991
REV = re.compile(r'[a-f0-9]{40}\Z')


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop(reason)


def load_recovery(package):
    path = package / RECOVERY_NAME
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        identity = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid,
            s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid,
            before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o644)
            and 0 < before.st_size < 2 * 1024**2, 'RECOVERY_MODULE_UNSAFE')
        raw = os.read(fd, 2 * 1024**2 + 1)
        need(len(raw) == before.st_size and hashlib.sha256(raw).hexdigest() == RECOVERY_SHA256
            and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()),
            'RECOVERY_MODULE_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('reviewed_current_dashboard_completion_recovery')
    module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(raw, str(path), 'exec'), module.__dict__)
    return module.load_base(package / 'upgrade_go_erp_fullscreen_watcher_root.py')


class Context:
    def __init__(self, package):
        self.adapter = load_recovery(package)
        need(self.adapter.NEW_SEED == SEED and self.adapter.CORE == CORE
            and self.adapter.PREPARED_SOURCES == {INVENTORY: SOURCE}, 'RELEASE_IDENTITY_INVALID')
        self.original = self.adapter.Context(package)
        for name in ('base', 'utility', 'prior', 'owner', 'pins', 'payloads', 'candidate_verifier'):
            setattr(self, name, getattr(self.original, name))
        self.runtime = self.base.RELEASES / RUNTIME
        need(self.utility.runtime_name(self.payloads) == RUNTIME, 'RUNTIME_IDENTITY_INVALID')
        directory = self.utility.Directory(self.runtime, 0o755)
        try:
            self.utility.validate_runtime(directory, self.pins)
            self.source = self.utility.module_at(directory, 'go_erp_live_source.py', self.pins['go_erp_live_source.py'])
            self.worker = self.utility.module_at(directory, 'go_erp_live_updates.py', self.pins['go_erp_live_updates.py'])
            self.publisher = self.utility.module_at(directory, 'publish_go_erp_update.py', self.pins['publish_go_erp_update.py'])
        finally:
            directory.close()
        self.gate = self.worker
        need(self.source.PREVIEW31_SEED == self.worker.PREVIEW31_SEED == SEED,
            'RUNTIME_SEED_INVALID')
        policy = self.worker.Worker.candidate_policy(types.SimpleNamespace(
            config={'base_revision': SEED}, source=self.source))
        need(policy == self.source.PREVIEW31_POLICY == self.worker.PREVIEW31_POLICY,
            'RUNTIME_POLICY_INVALID')
        self.expected = self.gate.program_gate_expected(CORE, SOURCE, INVENTORY)

    def controls(self):
        controls, config, state = self.original.slots()
        need(config.get('base_revision') == SEED and state.get('base_revision') == SEED,
            'CONFIGURATION_SEED_INVALID')
        return controls, state

    def fresh(self, state):
        info = ROOT.lstat()
        need(ROOT.resolve() == ROOT and stat.S_ISDIR(info.st_mode)
            and info.st_uid == self.owner.pw_uid and not info.st_mode & 0o022, 'APPLICATION_ROOT_UNSAFE')
        directory = self.utility.Directory(self.runtime, 0o755)
        try:
            self.utility.validate_runtime(directory, self.pins)
            dependencies = self.worker.dependency_snapshot(ROOT, self.owner.pw_uid)
            need(state.get('dependency_guard') in (None, dependencies), 'DEPENDENCIES_CHANGED')
            snapshot = self.source.scan_snapshot(ROOT, WORK / 'checkout', self.owner.pw_uid, None)
            metadata = snapshot.get('metadata', {})
            need(metadata == self.source._metadata(SEED, metadata.get('inventory'))
                and metadata.get('inventorySha256') == INVENTORY
                and self.base.normalized_source(snapshot['_files']) == SOURCE, 'LIVE_SOURCE_CHANGED')
            self.utility.validate_runtime(directory, self.pins)
        finally:
            directory.close()

    def remote(self, revision):
        need(self.base.git(WORK / 'checkout', 'ls-remote', '--exit-code', self.base.REMOTE,
            BRANCH, maximum=4096) == (revision + '\t' + BRANCH + '\n').encode(), 'REMOTE_LEASE_CHANGED')

    def candidate(self, desired):
        b, historical = self.base, WORK / 'checkout'
        # A stat-only refresh is permitted only after clean tracked contents are proved.
        b.checkout(historical, SEED, self.utility)
        staged = b.git(historical, 'ls-files', '--stage', '-z')
        b.git(historical, 'update-index', '--refresh')
        need(b.git(historical, 'ls-files', '--stage', '-z') == staged, 'HISTORICAL_INDEX_ROSTER_CHANGED')
        b.checkout(historical, SEED, self.utility)
        revision = desired['commit']
        self.remote(revision)
        b.git(historical, 'fetch', '--quiet', '--depth=2', '--no-tags', '--no-recurse-submodules',
            b.REMOTE, revision)
        header = b.git(historical, 'cat-file', 'commit', revision).split(b'\n\n', 1)[0]
        need([line[7:].decode() for line in header.splitlines() if line.startswith(b'parent ')] == [SEED],
            'CANDIDATE_PARENT_INVALID')
        candidate = b.parse(b.git(historical, 'show', revision + ':deployment/go_erp_live_source_candidate.json'))
        rows = candidate.get('inventory')
        number = state_number(desired['version'])
        need(isinstance(rows, dict) and candidate == self.prior.candidate_metadata(SEED, rows, number)
            and candidate['inventorySha256'] == INVENTORY, 'CANDIDATE_METADATA_INVALID')
        versions = {'desktop-pos/package.json', 'desktop-pos/package-lock.json'}
        changed = set(b.git(historical, 'diff', '--name-only', '--no-renames', '-z', SEED, revision,
            '--').decode().split('\0')) - {''}
        need(changed.issubset(set(rows) | versions | {'deployment/go_erp_live_source_candidate.json'}),
            'CANDIDATE_NATIVE_OR_TEST_DELTA')
        for path in versions:
            expected = b.parse(b.git(historical, 'show', SEED + ':' + path))
            actual = b.parse(b.git(historical, 'show', revision + ':' + path))
            expected['version'] = desired['version']
            if path.endswith('package-lock.json'):
                expected['packages']['']['version'] = desired['version']
            need(actual == expected, 'CANDIDATE_DEPENDENCY_DELTA')
        temporary = Path(tempfile.mkdtemp(prefix='current-dashboard-ci-proof-', dir=WORK))
        target, added = temporary / 'candidate', False
        try:
            b.git(historical, 'worktree', 'add', '--quiet', '--detach', str(target), revision)
            added = True
            proof = self.candidate_verifier.validate_checkout(target, historical, expected_base=SEED)
            need(proof.get('revision') == revision and proof.get('base_revision') == SEED
                and proof.get('version') == desired['version'] and proof.get('inventory_sha256') == INVENTORY
                and proof.get('source') == SOURCE, 'CANDIDATE_PROOF_INVALID')
        finally:
            if added:
                b.git(historical, 'worktree', 'remove', '--force', str(target))
            os.rmdir(temporary)
        b.checkout(historical, SEED, self.utility)
        self.remote(revision)

    def publication(self):
        try:
            return self.publisher.validate_release(self.publisher.read_json(
                ROOT / 'storage/app/desktop-updates/current.json', root_owned=True))
        except FileNotFoundError:
            return None


def state_number(version):
    match = re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)', version if isinstance(version, str) else '')
    need(match is not None and 40 <= int(match[1]) <= SAFE, 'CANDIDATE_VERSION_INVALID')
    return int(match[1])


def desired_state(state):
    desired = state.get('desired')
    need(type(state.get('counter')) is int and 39 <= state['counter'] <= SAFE, 'STATE_COUNTER_INVALID')
    if desired is None:
        need(state.get('last_remote_sha') == OLD_CANDIDATE and state.get('published') is None,
            'ALLOCATION_LEASE_INVALID')
        return None
    need(isinstance(desired, dict) and isinstance(desired.get('commit'), str)
        and REV.fullmatch(desired['commit']) and desired.get('commit') == state.get('last_remote_sha')
        and desired.get('pushed') is True and desired.get('inventorySha256') == INVENTORY,
        'DESIRED_CANDIDATE_INVALID')
    need(state_number(desired.get('version')) == state['counter'], 'STATE_VERSION_MISMATCH')
    need(desired.get('status') in ('waiting-ci', 'published') and desired.get('failures', 0) == 0
        and desired.get('retry_at', 0) == 0, 'CI_REVIEW_REQUIRED')
    return desired


def receipt_binding(value, expected, desired):
    need(isinstance(value, dict) and value.get('format') == 1
        and value.get('kind') == 'go-erp-program-acceptance'
        and all(value.get(key) == item for key, item in expected.items())
        and value.get('candidate_revision') == desired['commit'] and value.get('version') == desired['version'],
        'PENDING_ACCEPTANCE_MISMATCH')


def checked_hold(value, expected):
    need(isinstance(value, dict) and value == {'format': 1,
        'kind': 'go-erp-program-rollout-hold', **expected,
        'authorization': value.get('authorization')}, 'HOLD_IDENTITY_INVALID')
    return value


def absent(path):
    try:
        path.lstat()
    except FileNotFoundError:
        return True
    return False


def route(ctx, mode, receipt=None):
    need(mode in ('status', 'authorize', 'verify'), 'MODE_INVALID')
    pending = WORK / 'pending-program-acceptance.json'
    need((mode == 'authorize') == (receipt is not None), 'RECEIPT_MODE_INVALID')
    if receipt is not None:
        need(Path(receipt).absolute() == pending, 'RECEIPT_PATH_INVALID')
    p, b, u, gate = ctx.prior, ctx.base, ctx.utility, ctx.gate
    with p.held_lock(u, WORK) as (lock_fd, verify):
        p.assert_quiescent()
        p.require_no_pending(b, u, WORK)
        before, state = ctx.controls()
        ctx.fresh(state)
        desired = desired_state(state)
        if desired is None:
            need(mode == 'status', 'ALLOCATION_PENDING')
            ctx.remote(OLD_CANDIDATE)
            need(ctx.publication() is None, 'UNEXPECTED_PUBLICATION')
            hold = gate.program_gate_json(WORK / 'program-rollout-hold.json')
            need(hold == {'format': 1, 'kind': 'go-erp-program-rollout-hold',
                **ctx.expected, 'authorization': None}, 'HOLD_IDENTITY_INVALID')
            action = 'candidate'
        else:
            ctx.candidate(desired)
            completed = gate.program_gate_completed(WORK, ctx.expected, state, ctx.publication)
            if completed:
                need(absent(WORK / 'program-rollout-hold.json'), 'HOLD_NOT_RETIRED')
                action = 'none'
            else:
                need(mode != 'verify', 'PUBLICATION_NOT_COMPLETE')
                hold = checked_hold(gate.program_gate_json(WORK / 'program-rollout-hold.json'), ctx.expected)
                if mode == 'authorize':
                    value = gate.program_gate_json(pending)
                    receipt_binding(value, ctx.expected, desired)
                    gate.program_gate_authorize(WORK, pending, ctx.expected, state,
                        lambda folder, run, wanted: ctx.worker.verify_proof(folder, run, wanted, ctx.publisher),
                        ctx.publication)
                    action = 'publish'
                elif hold.get('authorization') is not None:
                    authorization = hold['authorization']
                    need(isinstance(authorization, dict) and authorization.get('candidate') == desired['commit']
                        and gate.program_gate_allows(WORK, desired, authorization.get('release')),
                        'AUTHORIZATION_INVALID')
                    action = 'publish'
                else:
                    need(hold == {'format': 1, 'kind': 'go-erp-program-rollout-hold',
                        **ctx.expected, 'authorization': None}, 'HOLD_IDENTITY_INVALID')
                    try:
                        value = gate.program_gate_json(pending)
                    except FileNotFoundError:
                        action = 'collect'
                    else:
                        receipt_binding(value, ctx.expected, desired)
                        action = 'authorize'
        verify()
        after, current_state = ctx.controls()
        need(before == after and state == current_state, 'CONTROL_STATE_CHANGED')
        ctx.fresh(current_state)
        if desired is not None:
            ctx.remote(desired['commit'])
        verify()
        return {'format': 1, 'kind': 'go-erp-current-dashboard-completion', 'ok': True,
            'worker_request': action, 'published': action == 'none',
            'candidate_revision': desired['commit'] if desired is not None else None,
            'version': desired['version'] if desired is not None else None,
            'publication_held': action != 'none', 'application_contents_changed': False,
            'database_changed': False, 'credentials_disclosed': False}


def lease(package):
    """Route old/new private slots before attempting to load the new runtime.

    The prepare adapter can read both exact unit/seed states. It does not need
    a rollout hold to read a completed successor, so reruns never reapply the
    prepare transaction after authorization or canonical publication.
    """
    adapter = load_recovery(package)
    need(adapter.OLD_SEED == OLD_SEED and adapter.OLD_CORE == OLD_CORE
        and adapter.NEW_SEED == SEED and adapter.CORE == CORE
        and adapter.PREPARED_SOURCES == {INVENTORY: SOURCE}, 'RELEASE_IDENTITY_INVALID')
    original = adapter.Context(package)
    p, b, u = original.prior, original.base, original.utility
    with p.held_lock(u, WORK) as (lock_fd, verify):
        p.assert_quiescent()
        p.require_no_pending(b, u, WORK)
        before, config, state = original.slots()
        seed = config.get('base_revision')
        need(seed in (OLD_SEED, SEED) and state.get('base_revision') == seed, 'CONFIGURATION_SEED_INVALID')
        original.snapshot(INVENTORY, SOURCE, seed)
        if seed == OLD_SEED:
            hold = original.worker.program_gate_json(WORK / 'program-rollout-hold.json')
            need(hold == {'format': 1, 'kind': 'go-erp-program-rollout-hold',
                'core': OLD_CORE, 'source': SOURCE, 'inventory_sha256': INVENTORY,
                'authorization': None}, 'OLD_HOLD_DIFFERS_OR_AUTHORIZED')
            action = 'prepare'
        else:
            action = 'status'
        verify()
        after, current_config, current_state = original.slots()
        need(before == after and current_config == config and current_state == state, 'CONTROL_STATE_CHANGED')
        original.snapshot(INVENTORY, SOURCE, seed)
        verify()
        return {'format': 1, 'kind': 'go-erp-current-dashboard-completion', 'ok': True,
            'worker_request': action, 'published': False, 'publication_held': True,
            'application_contents_changed': False, 'database_changed': False,
            'credentials_disclosed': False}


def execute(package, mode, receipt=None):
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    package = Path(package).absolute()
    info = package.lstat()
    need(package.resolve() == package and stat.S_ISDIR(info.st_mode)
        and info.st_uid == 0 and not info.st_mode & 0o022, 'PACKAGE_UNSAFE')
    os.umask(0o077)
    if mode == 'lease':
        need(receipt is None, 'RECEIPT_MODE_INVALID')
        return lease(package)
    return route(Context(package), mode, receipt)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package', required=True)
    parser.add_argument('--mode', choices=('lease', 'status', 'authorize', 'verify'), required=True)
    parser.add_argument('--receipt')
    args = parser.parse_args()
    try:
        result = execute(args.package, args.mode, args.receipt)
    except BaseException as error:
        reason = str(error) if type(error) is Stop and re.fullmatch(r'[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        result = {'format': 1, 'kind': 'go-erp-current-dashboard-completion', 'ok': False,
            'code': 'GO_ERP_CURRENT_DASHBOARD_COMPLETION_' + reason, 'published': False,
            'publication_held': True, 'application_contents_changed': False,
            'database_changed': False, 'credentials_disclosed': False}
    print(__import__('json').dumps(result, sort_keys=True))
    return 0 if result.get('ok') is True else 1


if __name__ == '__main__':
    raise SystemExit(main())
