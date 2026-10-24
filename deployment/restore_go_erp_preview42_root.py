#!/usr/bin/env python3
"""Cancel only Preview 43 and restore the genuinely accepted Preview 42 feed."""
import argparse
import copy
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types
import urllib.error
import urllib.request
import uuid

BASE_NAME = 'complete_go_erp_windows_fixtures_root.py'
BASE_SHA256 = '2381202b5ea72494f7c60614211d8e18d674da5edf679321fa7b750117739673'
CANDIDATE42 = '26c6f8b6d80ab8da05b88a00789483a51a4c9a2a'
CANDIDATE43 = '2c3c98a41faba7b0edb122a19ca4b2ad48659842'
RUN42 = {'id': 40054814945, 'run_attempt': 1}
RUN43 = 40086085055
VERSION = '0.4.4-preview.42'
KIND = 'go-erp-preview42-restoration'


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
        identity = lambda value: (value.st_dev, value.st_ino, value.st_mode,
            value.st_uid, value.st_gid, value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid, before.st_nlink,
            stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o644)
            and 0 < before.st_size < 2 * 1024**2, 'BASE_MODULE_UNSAFE')
        raw = os.read(fd, 2 * 1024**2 + 1)
        need(len(raw) == before.st_size and hashlib.sha256(raw).hexdigest() == BASE_SHA256
            and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()), 'BASE_MODULE_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('pinned_preview42_restoration_completion')
    module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(raw, str(path), 'exec'), module.__dict__)
    return module


def exact_state(base, ctx, state):
    need(type(state.get('format')) is int and state['format'] == 1
        and state.get('base_revision') == base.SEED and type(state.get('counter')) is int
        and state['counter'] == 43 and state.get('last_remote_sha') == CANDIDATE43, 'STATE_IDENTITY_INVALID')
    desired = state.get('desired')
    need(isinstance(desired, dict) and desired.get('commit') == CANDIDATE43
        and desired.get('version') == '0.4.4-preview.43' and desired.get('pushed') is True
        and desired.get('candidatePolicy') == base.POLICY and desired.get('inventorySha256') == base.INVENTORY
        and desired.get('status') in ('waiting-ci', 'retry-build', 'blocked'), 'CANDIDATE43_NOT_CANCELLABLE')
    checked = copy.deepcopy(state)
    checked['desired']['status'] = 'waiting-ci'
    base.desired_state(checked)
    published = state.get('published')
    need(isinstance(published, dict) and published.get('commit') == CANDIDATE42
        and published.get('inventorySha256') == base.INVENTORY
        and (published.get('run_id'), published.get('run_attempt')) == (RUN42['id'], RUN42['run_attempt']),
        'PUBLISHED42_IDENTITY_INVALID')
    release = ctx.publisher.validate_release(published.get('release'))
    need(release.get('source_revision') == CANDIDATE42 and release.get('version') == VERSION
        and release.get('source') == base.SOURCE and state.get('version_floor') == VERSION
        and state.get('sequence_floor') == release['sequence']
        and isinstance(state.get('published_history'), list) and state['published_history']
        and state['published_history'][-1] == release, 'PUBLISHED42_HISTORY_INVALID')
    need(base.absent(base.WORK / 'program-rollout-hold.json'), 'ROLLOUT_HOLD_PRESENT')
    need(ctx.publication() in (None, release), 'UNEXPECTED_CANONICAL_PUBLICATION')
    return desired, release


def retained_proof(base, ctx, state, release):
    """Read original private evidence, then run the unchanged artifact verifier."""
    gate = ctx.gate
    path = base.WORK / ('program-rollout-completed-' + base.CORE + '.json')
    completed_raw = gate.program_gate_bytes(path)
    completed = gate.program_gate_parse(completed_raw)
    need(isinstance(completed, dict) and type(completed.get('format')) is int and completed.get('format') == 1
        and completed.get('kind') == 'go-erp-program-rollout-completed'
        and all(completed.get(key) == value for key, value in ctx.expected.items())
        and completed.get('canonical_publication_verified') is True and completed.get('release') == release,
        'COMPLETED42_IDENTITY_INVALID')
    auth = completed.get('authorization')
    need(isinstance(auth, dict) and auth.get('candidate') == CANDIDATE42
        and auth.get('version') == VERSION and auth.get('installer') == release['installer']
        and auth.get('release') == release and isinstance(auth.get('receipt_sha256'), str)
        and re.fullmatch(r'[a-f0-9]{64}', auth['receipt_sha256']), 'AUTHORIZATION42_INVALID')
    receipt_path = base.WORK / 'program-rollout-authorizations' / ('receipt-' + auth['receipt_sha256'] + '.json')
    need(auth.get('receipt_path') == str(receipt_path), 'RECEIPT_PATH_INVALID')
    raw = gate.program_gate_bytes(receipt_path)
    need(hashlib.sha256(raw).hexdigest() == auth['receipt_sha256'], 'RECEIPT42_CHANGED')
    receipt = gate.program_gate_parse(raw)
    need(isinstance(receipt, dict) and type(receipt.get('format')) is int and receipt.get('format') == 1
        and receipt.get('kind') == 'go-erp-program-acceptance'
        and all(receipt.get(key) == value for key, value in ctx.expected.items())
        and receipt.get('candidate_revision') == CANDIDATE42 and receipt.get('version') == VERSION
        and receipt.get('installer') == release['installer'], 'RECEIPT42_IDENTITY_INVALID')
    ci, evidence = receipt.get('ci'), receipt.get('employee_evidence')
    need(isinstance(ci, dict) and set(ci) == {'directory', 'run_id', 'run_attempt'}
        and type(ci.get('run_id')) is int and type(ci.get('run_attempt')) is int
        and (ci.get('run_id'), ci.get('run_attempt')) == (RUN42['id'], RUN42['run_attempt'])
        and isinstance(ci.get('directory'), str), 'CI42_REFERENCE_INVALID')
    directory = Path(ci['directory'])
    need(directory.name == 'ci' and directory.parent.parent == base.WORK / 'program-acceptance'
        and re.fullmatch(CANDIDATE42 + r'-[a-z0-9_]{8}', directory.parent.name), 'CI42_PATH_INVALID')
    gate.program_gate_directory(directory)
    need(isinstance(evidence, dict) and set(evidence) == {'path', 'sha256'}
        and evidence.get('path') == str(directory.parent / 'employee.json')
        and evidence == auth.get('employee_evidence'), 'EMPLOYEE42_REFERENCE_INVALID')
    desired42 = {'commit': CANDIDATE42, 'version': VERSION, 'inventorySha256': base.INVENTORY,
        'candidatePolicy': base.POLICY, 'pushed': True, 'status': 'published'}
    need(gate.program_gate_employee_evidence(evidence['path'], ctx.expected, desired42) == evidence['sha256'],
        'EMPLOYEE42_CHANGED')
    original_receipt, verified = ctx.worker.verify_proof(directory, RUN42, desired42, ctx.publisher)
    need(verified == release, 'ARTIFACT42_RELEASE_MISMATCH')
    pending = base.WORK / 'pending-program-acceptance.json'
    if not base.absent(pending):
        need(gate.program_gate_bytes(pending) == raw, 'OTHER_PENDING_ACCEPTANCE_PRESENT')
    historical = copy.deepcopy(state)
    historical['desired'] = desired42
    # This view checks the historic completion only; it is never written to state.
    return original_receipt, historical, (path, completed_raw, receipt_path, raw, evidence)


def cancel_ci(ctx, config):
    """A forbidden Actions write never undoes the explicit local cancellation."""
    worker = ctx.worker
    try:
        client = worker.GitHub(config['token'])
        run = client.json('/repos/' + worker.REPOSITORY + '/actions/runs/' + str(RUN43))
        worker.run_identity(run, 'desktop-dashboard-tests.yml', CANDIDATE43)
        need(run.get('id') == RUN43 and run.get('run_attempt') == 1, 'CI43_IDENTITY_INVALID')
        if run.get('status') == 'completed':
            return 'already_finished'
        need(run.get('status') in ('queued', 'in_progress', 'waiting', 'pending', 'requested'), 'CI43_STATUS_INVALID')
        url = 'https://api.github.com/repos/' + worker.REPOSITORY + '/actions/runs/' + str(RUN43) + '/cancel'
        request = urllib.request.Request(url, data=b'', method='POST', headers={
            'Authorization': 'Bearer ' + config['token'], 'Accept': 'application/vnd.github+json',
            'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'GO-ERP-Preview42-Restoration/1'})
        with client.opener.open(request, timeout=20) as response:
            need(response.status == 202 and response.geturl() == url, 'CI43_CANCEL_RESPONSE_INVALID')
        return 'requested'
    except urllib.error.HTTPError as error:
        code = error.code
        error.close()
        return 'write_forbidden' if code in (401, 403) else 'already_finished' if code == 409 else 'request_failed'
    except Exception:
        return 'request_failed'


def feed_preimage(base, ctx, audit):
    path = base.ROOT / 'storage/app/desktop-updates/current.json'
    try:
        raw = ctx.worker.regular_bytes(path, ctx.publisher.MAX_JSON)
    except FileNotFoundError:
        raw = None
    if raw is not None:
        ctx.utility.write_at(audit, 'before-feed.json', raw)
    ctx.utility.json_at(audit, 'before-feed-status.json', {'present': raw is not None})
    return raw


def rollback_feed(base, ctx, release, previous, audit):
    """Remove only our verified 42 feed when the preimage had no feed.

    An already canonical 42 publication is its own rollback state. A foreign
    publication is never overwritten. The explicit 43 cancellation survives.
    """
    folder = base.ROOT / 'storage/app/desktop-updates'
    ctx.publisher.no_link_ancestors(folder)
    before = folder.lstat()
    need(stat.S_ISDIR(before.st_mode) and before.st_uid == 0 and not before.st_mode & 0o022,
        'FEED_ROLLBACK_REQUIRES_REVIEW')
    fd = os.open(folder, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        key = lambda value: (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid)
        need(key(before) == key(os.fstat(fd)) == key(folder.lstat()), 'FEED_ROLLBACK_REQUIRES_REVIEW')
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        current = ctx.publication()
        need(current in (None, release), 'FEED_ROLLBACK_REQUIRES_REVIEW')
        if previous is None and current == release:
            path = folder / 'current.json'
            raw = ctx.worker.regular_bytes(path, ctx.publisher.MAX_JSON)
            need(ctx.publisher.validate_release(ctx.worker.parse_json(raw)) == release,
                'FEED_ROLLBACK_REQUIRES_REVIEW')
            # Both watcher and publication locks are held. The parent inode is
            # bound by the original directory helper; no source path is changed.
            ctx.publisher.no_link_ancestors(folder)
            need(key(before) == key(os.fstat(fd)) == key(folder.lstat()), 'FEED_ROLLBACK_REQUIRES_REVIEW')
            os.unlink('current.json', dir_fd=fd)
            os.fsync(fd)
        elif previous is not None:
            need(current == release and ctx.publisher.validate_release(ctx.worker.parse_json(previous)) == release,
                'FEED_ROLLBACK_REQUIRES_REVIEW')
        ctx.utility.json_at(audit, 'feed-rolled-back.json', {'format': 1, 'kind': KIND,
            'cancellation_preserved': True, 'previous_feed_present': previous is not None})
    finally:
        os.close(fd)


def restore(base, ctx):
    p, b, u = ctx.prior, ctx.base, ctx.utility
    with p.held_lock(u, base.WORK) as (lock_fd, verify):
        p.assert_quiescent()
        p.require_no_pending(b, u, base.WORK)
        before, state = ctx.controls()
        desired, release = exact_state(base, ctx, state)
        ctx.fresh(state)
        ctx.candidate(desired)
        audit_path = base.WORK / ('preview42-restoration-' + uuid.uuid4().hex)
        os.mkdir(audit_path, 0o700)
        audit = u.Directory(audit_path, 0o700)
        directory = u.Directory(base.WORK / 'state', 0o700)
        wanted = copy.deepcopy(state)
        wanted['desired']['status'] = 'blocked'
        payload = ctx.worker.canonical(wanted) + b'\n'
        publication_attempted, previous_feed = False, None
        try:
            u.write_at(audit, 'before-state.json', before['state'])
            u.write_at(audit, 'exchange-state.json', payload)
            latest, current = ctx.controls()
            need(latest == before and current == state, 'CONTROL_STATE_CHANGED')
            ctx.fresh(current)
            ctx.remote(CANDIDATE43)
            verify()
            if desired['status'] != 'blocked':
                with u.critical():
                    u.exchange(audit.fd, 'exchange-state.json', directory.fd, 'current.json')
                    os.fsync(audit.fd)
                    os.fsync(directory.fd)
                    need(u.read_at(audit, 'exchange-state.json', b.MAX_STATE, 0o600) == before['state']
                        and u.read_at(directory, 'current.json', b.MAX_STATE, 0o600) == payload, 'STATE_EXCHANGE_INVALID')
            else:
                payload = before['state']
            # Cancellation is intentionally durable even if later proof or
            # publication fails. Never restore a running Preview 43 status.
            expected_controls = {**before, 'state': payload}
            latest, current = ctx.controls()
            need(latest == expected_controls and current == wanted, 'CONTROL_STATE_CHANGED')
            u.json_at(audit, 'cancelled43.json', {'format': 1, 'kind': KIND, 'ok': True})
            controls, config, again = ctx.original.slots()
            need(controls == expected_controls and again == wanted, 'CONTROL_STATE_CHANGED')
            cancellation = cancel_ci(ctx, config)
            original_receipt, historical, evidence = retained_proof(base, ctx, current, release)
            installer = base.ROOT / 'storage/app/desktop-updates' / (release['installer']['sha256'] + '.exe')
            ctx.publisher.verify_installer(installer, release['installer'], root_owned=True)
            ctx.fresh(current)
            ctx.remote(CANDIDATE43)
            need(ctx.publication() in (None, release), 'UNEXPECTED_CANONICAL_PUBLICATION')
            previous_feed = feed_preimage(base, ctx, audit)
            verify()
            publication_attempted = True
            result = ctx.publisher.publish(base.ROOT, installer, original_receipt)
            need(result.get('release') == release and (result.get('published') is True or result.get('idempotent') is True),
                'PUBLICATION_RECEIPT_INVALID')
            latest, current = ctx.controls()
            need(latest == expected_controls and current == wanted, 'CONTROL_STATE_CHANGED')
            ctx.fresh(current)
            ctx.remote(CANDIDATE43)
            need(ctx.publication() == release, 'CANONICAL42_NOT_RESTORED')
            need(ctx.gate.program_gate_completed(base.WORK, ctx.expected, historical, ctx.publication),
                'HISTORICAL42_COMPLETION_INVALID')
            path, completed_raw, receipt_path, receipt_raw, employee = evidence
            need(ctx.gate.program_gate_bytes(path) == completed_raw
                and ctx.gate.program_gate_bytes(receipt_path) == receipt_raw
                and base.absent(base.WORK / 'program-rollout-hold.json'), 'EVIDENCE_CHANGED')
            verify()
            u.json_at(audit, 'completed.json', {'format': 1, 'kind': KIND, 'ok': True})
            return {'format': 1, 'kind': KIND, 'ok': True, 'version': VERSION,
                'published': True, 'publication_held': False, 'cancelled_version': '0.4.4-preview.43',
                'cancelled_candidate': CANDIDATE43, 'ci_cancellation': cancellation,
                'application_contents_changed': False, 'database_changed': False, 'credentials_disclosed': False}
        except BaseException:
            if publication_attempted:
                rollback_feed(base, ctx, release, previous_feed, audit)
            u.json_at(audit, 'review-required.json', {'format': 1, 'kind': KIND, 'ok': False,
                'cancellation_preserved': True})
            raise
        finally:
            directory.close()
            audit.close()


def execute(package, apply):
    need(apply is True, 'APPLY_REQUIRED')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    package = Path(package).absolute()
    info = package.lstat()
    need(package.resolve() == package and stat.S_ISDIR(info.st_mode)
        and info.st_uid == 0 and not info.st_mode & 0o022, 'PACKAGE_UNSAFE')
    os.umask(0o077)
    base = load_completion(package)
    return restore(base, base.Context(package))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package', required=True)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    try:
        result = execute(args.package, args.apply)
    except BaseException as error:
        reason = str(error) if type(error) is Stop and re.fullmatch(r'[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        result = {'format': 1, 'kind': KIND, 'ok': False, 'code': 'GO_ERP_PREVIEW42_RESTORE_' + reason,
            'published': False, 'publication_held': True, 'application_contents_changed': False,
            'database_changed': False, 'credentials_disclosed': False}
    print(json.dumps(result, sort_keys=True))
    return 0 if result.get('ok') is True else 1


if __name__ == '__main__':
    raise SystemExit(main())
