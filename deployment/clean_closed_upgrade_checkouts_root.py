#!/usr/bin/env python3
"""Remove archived historical checkout bodies from completed GO ERP transactions.

The caller holds the full-backup and updater locks. Transaction receipts, the
current checkout, recent rollback sources, unknown folders and unfinished work
are preserved. No code from a historical checkout is imported or executed.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import tempfile
import types

WORK = Path('/var/lib/go-erp-live-updates')
PREFIXES = ('preview31', 'import422', 'wire-hash', 'program', 'fullscreen',
            'program-contract', 'snapshot', 'close-reopen', 'fixture',
            'parallel-media', 'startup-window', 'performance', 'startup-session')
NAME = re.compile(r'(?:' + '|'.join(PREFIXES) + r')-seed-upgrade-[a-f0-9]{32}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
SLOTS = {'unit', 'checkout', 'state', 'config'}
REFERENCE_ROOTS = (Path('/etc/go-erp'), Path('/etc/systemd/system'),
                   Path('/usr/lib/systemd/system'), Path('/etc/cron.d'),
                   Path('/var/spool/cron'))


class Stop(RuntimeError):
    pass


def need(value, code):
    if not value:
        raise Stop('CLOSED_CHECKOUT_' + code)


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def read_json(path, proofs):
    directory(path.parent)
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
         and not before.st_mode & 0o077 and 0 < before.st_size <= 16 * 1024**2,
         'PRIVATE_METADATA_UNSAFE')
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(identity(os.fstat(fd)) == identity(before), 'METADATA_CHANGED')
        parts, length = [], 0
        while length <= before.st_size:
            block = os.read(fd, min(65536, before.st_size + 1 - length))
            if not block:
                break
            parts.append(block)
            length += len(block)
        raw = b''.join(parts)
        need(length == before.st_size and identity(os.fstat(fd)) == identity(before)
             and identity(path.lstat()) == identity(before), 'METADATA_CHANGED')
    finally:
        os.close(fd)
    def pairs(rows):
        result = {}
        for key, value in rows:
            need(key not in result, 'DUPLICATE_METADATA_KEY')
            result[key] = value
        return result
    value = json.loads(raw, object_pairs_hook=pairs,
                       parse_constant=lambda _: need(False, 'NONFINITE_METADATA'))
    need(isinstance(value, dict), 'METADATA_OBJECT_REQUIRED')
    proofs[str(path)] = (identity(before), hashlib.sha256(raw).hexdigest())
    return value


def check_proofs(proofs):
    for name, (before, digest) in proofs.items():
        path = Path(name)
        need(identity(path.lstat()) == before, 'METADATA_CHANGED')
        need(hashlib.sha256(path.read_bytes()).hexdigest() == digest
             and identity(path.lstat()) == before, 'METADATA_CHANGED')


def directory(path):
    for parent in (*reversed(path.parents), path):
        item = parent.lstat()
        need(stat.S_ISDIR(item.st_mode), 'LINKED_OR_SPECIAL_DIRECTORY')
    item = path.lstat()
    need(item.st_uid == 0 and not item.st_mode & 0o022, 'DIRECTORY_OWNER_OR_MODE')
    return item


def closed_transactions(work, current_seed, proofs):
    rows, skipped = [], []
    for audit in sorted(work.glob('*-seed-upgrade-*')):
        if not NAME.fullmatch(audit.name):
            skipped.append({'path': str(audit), 'reason': 'unknown_upgrade_prefix'})
            continue
        checkout = audit / 'exchange-checkout'
        if not os.path.lexists(str(checkout)):
            continue
        try:
            directory(audit)
            item = directory(checkout)
            if not (audit / 'intent.json').exists() or not (audit / 'completed.json').exists():
                skipped.append({'path': str(checkout), 'reason': 'unfinished_or_rolled_back_transaction'})
                continue
            need(not os.path.lexists(str(audit / 'rolled-back.json')), 'CONFLICTING_CLOSURE_MARKERS')
            intent = read_json(audit / 'intent.json', proofs)
            complete = read_json(audit / 'completed.json', proofs)
            need(set(intent) == {'format', 'old_seed', 'new_seed', 'core', 'runtime', 'slots'}
                 and type(intent['format']) is int and intent['format'] == 1
                 and all(isinstance(intent[key], str) and REV.fullmatch(intent[key])
                         for key in ('old_seed', 'new_seed', 'core'))
                 and isinstance(intent['runtime'], str)
                 and re.fullmatch(r'/opt/go-erp-live-updates/releases/policy-[a-f0-9]{64}', intent['runtime'])
                 and isinstance(intent['slots'], dict) and set(intent['slots']) == SLOTS,
                 'INTENT_SCHEMA_OR_BINDING')
            need(set(complete) == {'format', 'ok', 'new_seed', 'counter', 'version_floor', 'sequence_floor'}
                 and type(complete['format']) is int and complete['format'] == 1
                 and complete['ok'] is True and complete['new_seed'] == intent['new_seed']
                 and type(complete['counter']) is int and complete['counter'] >= 0
                 and type(complete['sequence_floor']) is int and complete['sequence_floor'] >= 0
                 and (complete['version_floor'] is None or isinstance(complete['version_floor'], str)),
                 'COMPLETION_SCHEMA_OR_BINDING')
            for slot in SLOTS:
                value = intent['slots'][slot]
                need(isinstance(value, dict) and set(value) == {'before', 'after'}
                     and all(isinstance(value[key], dict) and isinstance(value[key].get('identity'), list)
                             for key in ('before', 'after')), 'SLOT_SCHEMA')
            held = intent['slots']['checkout']['before']
            need(set(held) == {'identity'} and held['identity'] == list(identity(item)[:5]),
                 'DISPLACED_CHECKOUT_IDENTITY_CHANGED')
            row = {'path': checkout, 'audit': audit, 'intent': intent, 'complete': complete,
                   'rank': (complete['counter'], complete['sequence_floor'],
                            (audit / 'completed.json').stat().st_mtime_ns)}
            rows.append(row)
        except (OSError, ValueError, Stop) as error:
            skipped.append({'path': str(checkout), 'reason': str(error) or type(error).__name__})
    newest = max(rows, key=lambda row: row['rank']) if rows else None
    selected = []
    for row in rows:
        intent = row['intent']
        if row is newest:
            reason = 'latest_completed_rollback_source'
        elif current_seed in (intent['old_seed'], intent['new_seed']):
            reason = 'transaction_touches_current_seed'
        elif row['audit'].name.startswith(('performance-', 'startup-session-')):
            reason = 'recent_performance_or_startup_rollback'
        else:
            selected.append(row)
            continue
        skipped.append({'path': str(row['path']), 'reason': reason})
    return selected, skipped


def reject_references(text, candidates):
    values = [text, text.replace('\\/', '/')]
    try:
        parsed = json.loads(text)
    except ValueError:
        parsed = None
    def visit(value):
        if isinstance(value, str):
            values.append(value)
        elif isinstance(value, dict):
            for key, item in value.items():
                visit(key)
                visit(item)
        elif isinstance(value, list):
            for item in value:
                visit(item)
    visit(parsed)
    for value in values:
        for candidate in candidates:
            need(not re.search(re.escape(str(candidate)) + r'(?=$|[/\s;\x22\x27\x29\x7d\x00])', value),
                 'LIVE_PATH_REFERENCE')


def dependencies(candidates, points, split, work):
    # The original account helper expects candidates inside HOME. Inventory HOME
    # without exclusions, then pass its evidence to the reusable alias/proc/Git
    # guard for these separate root-owned runtime candidates.
    inventory = split.home_inventory([])
    for name, row in inventory.items():
        if row.get('type') == 'symlink':
            target = (Path(split.HOME) / name).resolve()
            need(not any(points.path_inside(target, path) for path in candidates), 'HOME_SYMLINK_REFERENCE')
    adapter = types.SimpleNamespace(HOME=split.HOME, dependency_check=lambda _: inventory)
    points.check_dependencies(candidates, adapter)
    roots = list(REFERENCE_ROOTS) + [work.parent / 'go-erp-manual-updates/private']
    files = set(work.glob('*.json'))
    live = Path(split.HOME) / 'public_html'
    files.update(live.glob('.env*'))
    roots.extend(live / name for name in ('app', 'config', 'routes', 'bootstrap/cache'))
    for root in roots:
        if root.is_dir() and not root.is_symlink():
            files.update(path for path in root.rglob('*') if path.is_file())
    for gitdir in (work / 'checkout/.git',):
        need(not gitdir.is_symlink(), 'CURRENT_GIT_DIRECTORY_LINK')
        if gitdir.is_file():
            raw = gitdir.read_text(errors='strict').strip()
            need(raw.startswith('gitdir:'), 'CURRENT_GIT_POINTER_INVALID')
            gitdir = Path(raw[7:].strip()) if Path(raw[7:].strip()).is_absolute() else gitdir.parent / raw[7:].strip()
        need(not any(points.path_inside(gitdir.resolve(), path) for path in candidates),
             'CURRENT_GIT_REFERENCE')
        if gitdir.is_dir():
            files.update([gitdir / 'config', gitdir / 'commondir', gitdir / 'gitdir',
                          gitdir / 'objects/info/alternates'])
            files.update(gitdir.glob('worktrees/*/gitdir'))
            for pointer in [gitdir / 'commondir', gitdir / 'gitdir', gitdir / 'objects/info/alternates'] + list(gitdir.glob('worktrees/*/gitdir')):
                if pointer.is_file():
                    for line in pointer.read_text(errors='strict').splitlines():
                        need(not line.startswith('"'), 'QUOTED_GIT_POINTER')
                        target = Path(line.strip())
                        if not target.is_absolute():
                            target = (gitdir / 'objects' if pointer.name == 'alternates' else pointer.parent) / target
                        need(not any(points.path_inside(target.resolve(), path) for path in candidates), 'CURRENT_GIT_REFERENCE')
            config = gitdir / 'config'
            if config.is_file():
                for value in re.findall(r'^\s*(?:worktree|path)\s*=\s*(.*?)\s*$',
                                        config.read_text(errors='strict'), re.M | re.I):
                    if value.startswith('"'):
                        value = json.loads(value)
                    target = Path(value).expanduser()
                    if not target.is_absolute():
                        target = gitdir / target
                    need(not any(points.path_inside(target.resolve(), path) for path in candidates),
                         'CURRENT_GIT_REFERENCE')
    for path in files:
        if path.is_file():
            need(path.stat().st_size < 16 * 1024**2, 'REFERENCE_FILE_TOO_LARGE')
            reject_references(path.read_text(errors='replace'), candidates)
    units = split.run(['systemctl', 'list-units', '--type=service', '--all',
                       '--no-legend', '--plain', '--no-pager'])
    names = [word for row in units.splitlines() for word in row.split()[:2] if word.endswith('.service')]
    if names:
        reject_references(split.run(['systemctl', 'show', '--property=WorkingDirectory',
                                     '--property=ExecStart', '--property=EnvironmentFiles'] + names), candidates)


def inventory_proof(path, snapshot, inventory):
    prefix = str(path).lstrip('/')
    selected = {name[len(prefix):].lstrip('/') or '.': row for name, row in inventory.items()
                if name == prefix or name.startswith(prefix + '/')}
    need(set(selected) == set(snapshot), 'RUNTIME_INVENTORY_COVERAGE_DIFFERS')
    for name, row in snapshot.items():
        expected = selected[name]
        original = expected.get('identity')
        need(isinstance(original, list) and len(original) == 9, 'RUNTIME_INVENTORY_IDENTITY_INVALID')
        need(expected.get('type') == row['type'] and expected.get('mode') == row['mode']
             and expected.get('uid') == row['uid'] and expected.get('gid') == row['gid']
             and original[7] == row['mtime_ns'], 'RUNTIME_INVENTORY_METADATA_DIFFERS')
        for key in ('size', 'sha256', 'target'):
            if key in row:
                need(expected.get(key) == row[key], 'RUNTIME_INVENTORY_CONTENT_DIFFERS')


def clean(verified, points, split, *, apply=False, work=WORK, guard=None):
    """Call with a locked VerifiedBackup and the master's updater guard callback."""
    need(hasattr(verified, 'runtime_inventory') and hasattr(verified, 'check'), 'VERIFIED_RUNTIME_REQUIRED')
    need(not apply or callable(guard), 'APPLY_REQUIRES_UPDATER_GUARD')
    guard = guard or (lambda: None)
    work = Path(work)
    directory(work)
    directory(work / 'checkout')
    proofs = {}
    state = read_json(work / 'state/current.json', proofs)
    seed = state.get('base_revision')
    need(isinstance(seed, str) and REV.fullmatch(seed), 'CURRENT_SEED_UNKNOWN')
    desired, published = state.get('desired'), state.get('published')
    need(isinstance(desired, dict) and desired.get('status') in
         ('prepared', 'waiting-ci', 'retry-build', 'blocked', 'published')
         and isinstance(published, dict) and isinstance(desired.get('commit'), str)
         and REV.fullmatch(desired['commit']) and isinstance(published.get('commit'), str)
         and REV.fullmatch(published['commit']), 'CURRENT_PUBLICATION_STATE_UNKNOWN')
    if desired['status'] == 'published':
        need(desired['commit'] == published['commit'], 'PUBLISHED_CANDIDATE_MISMATCH')
    manual = work.parent / 'go-erp-manual-updates/private/active.json'
    for pending in (manual, work / 'program-rollout-hold.json'):
        if os.path.lexists(str(pending)):
            read_json(pending, proofs)
    guard(); verified.check()
    rows, skipped = closed_transactions(work, seed, proofs)
    paths = [row['path'] for row in rows]
    if not paths:
        return {'closed_checkout_status': 'unchanged', 'removed_paths': [], 'skipped': skipped}
    dependencies(paths, points, split, work)
    snapshots = {}
    for path in paths:
        snapshots[str(path)] = points.snapshot(path)
        inventory_proof(path, snapshots[str(path)], verified.runtime_inventory)
    archived = points.archived_trees(verified.latest_path / 'server-runtime.tar.gz',
                                     [str(path).lstrip('/') for path in paths])
    for path in paths:
        tree = snapshots[str(path)]
        need({key: points.semantic(value) for key, value in tree.items()}
             == archived[str(path).lstrip('/')], 'ARCHIVED_CHECKOUT_DIFFERS')
    dependencies(paths, points, split, work)
    check_proofs(proofs); guard(); verified.check()
    total = sum(row.get('size', 0) for tree in snapshots.values() for row in tree.values())
    if not apply:
        return {'closed_checkout_status': 'checked', 'planned_paths': list(snapshots),
                'planned_bytes': total, 'removed_paths': [], 'skipped': skipped}
    need(getattr(shutil.rmtree, 'avoids_symlink_attacks', False), 'SAFE_RMTREE_UNAVAILABLE')
    quarantine = Path(tempfile.mkdtemp(prefix='closed-checkout-prune-', dir=str(verified.latest_path.parent)))
    need(quarantine.stat().st_uid == 0 and not quarantine.stat().st_mode & 0o077, 'QUARANTINE_UNSAFE')
    moved = []
    try:
        for index, path in enumerate(paths):
            check_proofs(proofs); guard(); verified.check()
            tree = snapshots[str(path)]
            points.assert_snapshot(path, tree)
            need(path.stat().st_dev == quarantine.stat().st_dev, 'QUARANTINE_FILESYSTEM_MISMATCH')
            target = quarantine / str(index)
            os.rename(str(path), str(target))
            moved.append((path, target, tree))
        for _, target, tree in moved:
            points.assert_snapshot(target, tree)
        dependencies([target for _, target, _ in moved], points, split, work)
        check_proofs(proofs); guard(); verified.check()
        for _, target, _ in moved:
            shutil.rmtree(str(target))
        quarantine.rmdir()
    except BaseException:
        for original, target, _ in reversed(moved):
            if target.exists() and not os.path.lexists(str(original)):
                os.rename(str(target), str(original))
        if quarantine.exists() and not any(quarantine.iterdir()):
            quarantine.rmdir()
        raise
    return {'closed_checkout_status': 'applied', 'removed_paths': [str(path) for path in paths],
            'removed_bytes': total, 'skipped': skipped, 'transaction_receipts_preserved': True,
            'latest_full_backup_preserved': True, 'active_checkout_preserved': True}
