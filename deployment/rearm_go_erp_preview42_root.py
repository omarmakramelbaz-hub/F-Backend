#!/usr/bin/env python3
"""Rearm only the exact Preview 42 after its three original CI gates succeed.

Only desired.status changes. The original worker retains backoff, artifact
download, installer verification, acceptance authorization and publication.
"""
import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types
import uuid

BASE_NAME = 'complete_go_erp_windows_fixtures_root.py'
BASE_SHA256 = '2381202b5ea72494f7c60614211d8e18d674da5edf679321fa7b750117739673'
CANDIDATE = '26c6f8b6d80ab8da05b88a00789483a51a4c9a2a'
VERSION = '0.4.4-preview.42'
SEED = '1ce9bb30034e300e3ed693e28a4dcbd608fb66c4'
POLICY = 'historical-baseline-v2-native-windows-fixture-paths-v1'
INVENTORY = '2249c62ae01adb65af35a21b252bb249aaaa5ec7022caabb1bbf32bd07deccde'
RUNS = {'desktop-dashboard-tests.yml': (40054814945, 1),
    'go-erp-update-tests.yml': (40054814912, 2),
    'whatsapp-integrated-regressions.yml': (40054814910, 1)}
ARTIFACT = {'id': 12545838921, 'size_in_bytes': 415586397,
    'digest': 'sha256:d4ffbc1a7abbd76129a53a37c0556fcc19ffeee3e0feb0ac06332e2e49433e26'}
KIND = 'go-erp-preview42-rearm'


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop(reason)


def load_completion(package):
    path = package / BASE_NAME
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        identity = lambda info: (info.st_dev, info.st_ino, info.st_mode, info.st_uid,
            info.st_gid, info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid,
            before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o644)
            and 0 < before.st_size < 2 * 1024**2, 'BASE_MODULE_UNSAFE')
        raw = os.read(fd, 2 * 1024**2 + 1)
        need(len(raw) == before.st_size and hashlib.sha256(raw).hexdigest() == BASE_SHA256
            and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()),
            'BASE_MODULE_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('pinned_preview42_completion')
    module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(raw, str(path), 'exec'), module.__dict__)
    need(module.SEED == SEED and module.INVENTORY == INVENTORY and module.POLICY == POLICY,
        'BASE_RELEASE_IDENTITY_INVALID')
    return module


def exact_desired(base, state, statuses):
    need(isinstance(state, dict) and type(state.get('format')) is int
        and state['format'] == 1 and state.get('base_revision') == SEED
        and type(state.get('counter')) is int and state['counter'] == 42,
        'STATE_IDENTITY_INVALID')
    desired = state.get('desired')
    need(isinstance(desired, dict) and desired.get('commit') == CANDIDATE
        and state.get('last_remote_sha') == CANDIDATE and desired.get('version') == VERSION
        and desired.get('pushed') is True and desired.get('inventorySha256') == INVENTORY
        and desired.get('candidatePolicy') == POLICY, 'CANDIDATE_IDENTITY_INVALID')
    need(desired.get('status') in statuses, 'STATUS_NOT_REVIEWED')
    # The original validator checks bounded failures/backoff without resetting
    # them. Its status check is applied to a copy solely for the failed state.
    checked = copy.deepcopy(state)
    if desired['status'] in ('retry-build', 'blocked'):
        checked['desired']['status'] = 'waiting-ci'
    base.desired_state(checked)
    return desired


def no_acceptance(base, ctx, state):
    need(state.get('published') is None and ctx.publication() is None,
        'PUBLICATION_ALREADY_PRESENT')
    need(base.absent(base.WORK / 'pending-program-acceptance.json'), 'ACCEPTANCE_ALREADY_PRESENT')
    need(base.absent(base.WORK / ('program-rollout-completed-' + base.CORE + '.json')),
        'COMPLETION_ALREADY_PRESENT')
    hold_path = base.WORK / 'program-rollout-hold.json'
    raw = ctx.gate.program_gate_bytes(hold_path)
    need(ctx.gate.program_gate_parse(raw) == {'format': 1,
        'kind': 'go-erp-program-rollout-hold', **ctx.expected, 'authorization': None},
        'HOLD_DIFFERS_OR_AUTHORIZED')
    return raw


def accepted_ci(ctx, config):
    worker = ctx.worker
    need(set(worker.WORKFLOWS) == set(RUNS), 'WORKFLOW_ROSTER_INVALID')
    client = worker.GitHub(config['token'])
    runs = {}
    for workflow, identity in RUNS.items():
        run = worker.accepted_run(client, workflow, CANDIDATE)
        need(isinstance(run, dict) and type(run.get('id')) is int
            and type(run.get('run_attempt')) is int
            and (run['id'], run['run_attempt']) == identity, 'CI_NOT_ACCEPTED')
        runs[workflow] = run
    artifact = worker.accepted_artifact(client, runs['desktop-dashboard-tests.yml'], CANDIDATE)
    need(isinstance(artifact, dict) and all(artifact.get(key) == value
        and type(artifact.get(key)) is type(value) for key, value in ARTIFACT.items()),
        'ARTIFACT_NOT_REVIEWED')


def rearm(base, ctx):
    p, b, u = ctx.prior, ctx.base, ctx.utility
    with p.held_lock(u, base.WORK) as (lock_fd, verify):
        p.assert_quiescent()
        p.require_no_pending(b, u, base.WORK)
        before, state = ctx.controls()
        desired = exact_desired(base, state, ('retry-build', 'blocked', 'waiting-ci', 'published'))
        ctx.fresh(state)
        ctx.candidate(desired)
        if desired['status'] in ('waiting-ci', 'published'):
            # Original status routing must acquire its own lock, after this one
            # closes. It handles existing authorization and canonical completion.
            verify()
            return False
        hold = no_acceptance(base, ctx, state)
        controls, config, original_state = ctx.original.slots()
        need(controls == before and original_state == state, 'CONTROL_STATE_CHANGED')
        accepted_ci(ctx, config)
        verify()
        after, current = ctx.controls()
        need(before == after and state == current, 'CONTROL_STATE_CHANGED')
        ctx.fresh(current)
        ctx.remote(CANDIDATE)
        need(no_acceptance(base, ctx, current) == hold, 'HOLD_CHANGED')
        verify()
        wanted = copy.deepcopy(state)
        wanted['desired']['status'] = 'waiting-ci'
        payload = ctx.worker.canonical(wanted) + b'\n'
        need(len(payload) <= b.MAX_STATE, 'STATE_LIMIT')
        audit_path = base.WORK / ('preview42-rearm-' + uuid.uuid4().hex)
        os.mkdir(audit_path, 0o700)
        audit = u.Directory(audit_path, 0o700)
        directory = u.Directory(base.WORK / 'state', 0o700)
        swapped = False
        try:
            u.write_at(audit, 'before-state.json', before['state'])
            u.write_at(audit, 'exchange-state.json', payload)
            u.json_at(audit, 'intent.json', {'format': 1, 'kind': KIND,
                'candidate_revision': CANDIDATE, 'version': VERSION,
                'before_sha256': hashlib.sha256(before['state']).hexdigest(),
                'after_sha256': hashlib.sha256(payload).hexdigest()})
            # Recheck every private control and live proof after staging, before
            # exchanging the sole state slot. No config/hold/runtime is written.
            latest, latest_state = ctx.controls()
            need(latest == before and latest_state == state, 'CONTROL_STATE_CHANGED')
            ctx.fresh(latest_state)
            ctx.remote(CANDIDATE)
            need(no_acceptance(base, ctx, latest_state) == hold, 'HOLD_CHANGED')
            verify()
            with u.critical():
                u.exchange(audit.fd, 'exchange-state.json', directory.fd, 'current.json')
                swapped = True
                os.fsync(audit.fd)
                os.fsync(directory.fd)
                need(u.read_at(audit, 'exchange-state.json', b.MAX_STATE, 0o600) == before['state']
                    and u.read_at(directory, 'current.json', b.MAX_STATE, 0o600) == payload,
                    'STATE_EXCHANGE_INVALID')
            final, final_state = ctx.controls()
            need(final == {**before, 'state': payload} and final_state == wanted,
                'CONTROL_STATE_CHANGED')
            ctx.fresh(final_state)
            ctx.remote(CANDIDATE)
            need(no_acceptance(base, ctx, final_state) == hold, 'HOLD_CHANGED')
            verify()
            u.json_at(audit, 'completed.json', {'format': 1, 'kind': KIND, 'ok': True})
        except BaseException:
            if swapped:
                # Restore only our exact new state and the exact displaced old
                # state. An unrelated writer never gets overwritten silently.
                need(u.read_at(directory, 'current.json', b.MAX_STATE, 0o600) == payload
                    and u.read_at(audit, 'exchange-state.json', b.MAX_STATE, 0o600) == before['state'],
                    'ROLLBACK_REQUIRES_REVIEW')
                with u.critical():
                    u.exchange(audit.fd, 'exchange-state.json', directory.fd, 'current.json')
                    os.fsync(audit.fd)
                    os.fsync(directory.fd)
                need(u.read_at(directory, 'current.json', b.MAX_STATE, 0o600) == before['state'],
                    'ROLLBACK_REQUIRES_REVIEW')
            u.json_at(audit, 'rolled-back.json', {'format': 1, 'kind': KIND, 'ok': False})
            raise
        finally:
            directory.close()
            audit.close()
        return True


def execute(package, apply):
    need(apply is True, 'APPLY_REQUIRED')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
        'ROOT_AND_HOST_REQUIRED')
    package = Path(package).absolute()
    info = package.lstat()
    need(package.resolve() == package and stat.S_ISDIR(info.st_mode)
        and info.st_uid == 0 and not info.st_mode & 0o022, 'PACKAGE_UNSAFE')
    os.umask(0o077)
    base = load_completion(package)
    ctx = base.Context(package)
    changed = rearm(base, ctx)
    original_desired = base.desired_state
    # The delegated original route must never admit a different version or an
    # allocation request if controls change between the two lock acquisitions.
    def reviewed_desired(state):
        need(type(state.get('counter')) is int and state['counter'] == 42
            and state.get('base_revision') == SEED and state.get('last_remote_sha') == CANDIDATE,
            'STATE_IDENTITY_INVALID')
        desired = original_desired(state)
        need(isinstance(desired, dict) and desired.get('commit') == CANDIDATE
            and desired.get('version') == VERSION, 'CANDIDATE_IDENTITY_INVALID')
        return desired
    base.desired_state = reviewed_desired
    result = base.route(ctx, 'status')
    need(result.get('worker_request') in ('collect', 'authorize', 'publish', 'none'),
        'ALLOCATION_ACTION_FORBIDDEN')
    return {**result, 'kind': KIND, 'rearmed': changed}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package', required=True)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    try:
        result = execute(args.package, args.apply)
    except BaseException as error:
        reason = str(error) if type(error) is Stop and re.fullmatch(r'[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        result = {'format': 1, 'kind': KIND, 'ok': False,
            'code': 'GO_ERP_PREVIEW42_REARM_' + reason, 'published': False,
            'publication_held': True, 'application_contents_changed': False,
            'database_changed': False, 'credentials_disclosed': False}
    print(json.dumps(result, sort_keys=True))
    return 0 if result.get('ok') is True else 1


if __name__ == '__main__':
    raise SystemExit(main())
