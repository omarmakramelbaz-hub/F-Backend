#!/usr/bin/env python3
"""Apply cash-field Enter to dining kitchen submission incrementally."""
import argparse
import datetime
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile

BASE_REVISION = 'bc3de8f0314abe1aebe7fc4b14e09f31fa57a98a'
UTILITY_REVISION = '6582750c2ef925500504a83d89141e49f0bd62a3'
UTILITY_PATH = 'deployment/apply_pos_inline_quantity.py'
UTILITY_BLOB = 'a438bfdcb17f43ef11605bcb48de926c190b3f73'
SOURCE_MANIFEST = {'public/dashboard/js/dining-pos.js': 'M'}


def load_common(root):
    """Use pinned filesystem/Git helpers without running prior deployments."""
    source = subprocess.run(['git', '-C', str(root), 'show', UTILITY_REVISION + ':' + UTILITY_PATH],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if source.returncode != 0:
        raise RuntimeError('PINNED_UTILITY_UNAVAILABLE')
    checked = subprocess.run(['git', 'hash-object', '--no-filters', '--stdin'], input=source.stdout,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if checked.returncode != 0 or checked.stdout.decode().strip() != UTILITY_BLOB:
        raise RuntimeError('PINNED_UTILITY_MISMATCH')
    with tempfile.TemporaryDirectory(prefix='dining-cash-enter-kitchen-utility-') as temporary:
        helper = Path(temporary) / 'utility.py'
        helper.write_bytes(source.stdout)
        spec = importlib.util.spec_from_file_location('pinned_dining_cash_enter_kitchen_utility', helper)
        common = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(common)
    return common


def build_plan(c, root, revision):
    plan = {}
    with tempfile.TemporaryDirectory(prefix='dining-cash-enter-kitchen-plan-') as temporary:
        for relative, status in SOURCE_MANIFEST.items():
            before = c.source(root, BASE_REVISION, relative, optional=status == 'A')
            c.require((before is None) == (status == 'A'), 'SOURCE_STATUS_INVALID: ' + relative)
            after = c.source(root, revision, relative)
            c.require(before != after, 'RELEASE_CHANGE_MISSING: ' + relative)
            plan[relative] = {'status': status, 'patch': c.make_patch(Path(temporary), relative, before, after)}
    return plan


def apply_update(c, root, revision, plan, check_only=False):
    c.require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    c.require(root.is_absolute() and root.resolve() == root and root.is_dir(),
            'APPLICATION_ROOT_INVALID')
    c.safe(root)
    resolved = c.git(root, 'rev-parse', '--verify', revision + '^{commit}')
    c.require(resolved.returncode == 0 and resolved.stdout.decode().strip() == revision,
            'RELEASE_COMMIT_UNAVAILABLE')
    c.require(c.git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')

    private = root.parent / 'dining-cash-enter-kitchen-backups'
    c.safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    c.require(private.is_dir() and stat.S_IMODE(private.stat().st_mode) == 0o700
            and private.stat().st_uid == os.geteuid(), 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'
    c.safe(lock_path)
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            originals = {}
            pending = []
            for index, (relative, entry) in enumerate(plan.items()):
                status = entry['status']
                target = root / relative
                before = c.content(target)
                c.require(target.parent.is_dir(), 'REQUIRED_PARENT_MISSING: ' + relative)
                c.require(status == 'A' or before is not None,
                        'REQUIRED_LIVE_FILE_MISSING: ' + relative)
                mode = stat.S_IMODE(target.stat().st_mode) if before is not None else 0o644
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    staged.write_bytes(before)
                originals[relative] = (before, mode)
                patch_path = stage / ('patch-' + str(index) + '.diff')
                patch_path.write_bytes(entry['patch'])
                if c.patch_check(stage, patch_path, reverse=True):
                    continue
                c.require(c.patch_check(stage, patch_path), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                applied = c.run(['git', 'apply', '--whitespace=nowarn', str(patch_path)], cwd=stage)
                c.require(applied.returncode == 0, 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, entry['patch'], staged.read_bytes()))

            if not pending:
                print('DINING_CASH_ENTER_KITCHEN_OK already_current=1 files=0', flush=True)
                return None
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(patch for _, patch, _ in pending))
            c.require(c.patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print('DINING_CASH_ENTER_KITCHEN_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            # Every target must still contain exactly the bytes checked above.
            for relative, _, _ in pending:
                c.require(c.content(root / relative) == originals[relative][0],
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=prefix + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION,
                       'status': 'prepared', 'files': []}
            for relative, _, after in pending:
                before, mode = originals[relative]
                if before is not None:
                    saved = backup / relative
                    saved.parent.mkdir(parents=True, exist_ok=True)
                    saved.write_bytes(before)
                    saved.chmod(mode)
                receipt['files'].append({'path': relative, 'mode': mode,
                                         'existed': before is not None,
                                         'before': c.digest(before), 'after': c.digest(after)})
            receipt_path = backup / 'receipt.json'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            (backup / 'release.diff').write_bytes(combined.read_bytes())
            try:
                # Publish only the hunk-merged live bytes, atomically per file.
                # Release snapshots are never copied over live application code.
                for relative, _, after in pending:
                    target = root / relative
                    before, mode = originals[relative]
                    c.require(c.content(target) == before,
                              'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                    c.atomic(target, after, mode)
                    c.require(c.content(target) == after,
                              'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
            except BaseException:
                review = []
                # An unowned edit to an existing consumer may still need the new
                # helper or access methods. Keep the source group together instead
                # of removing dependencies underneath that preserved consumer.
                for relative, _, after in pending:
                    before, _ = originals[relative]
                    if before is None:
                        continue
                    try:
                        if c.content(root / relative) not in (before, after):
                            review.append(relative)
                    except (RuntimeError, OSError):
                        review.append(relative)
                if review:
                    receipt['retained_dependency_group'] = [relative for relative, _, _ in pending]
                else:
                    for relative, _, after in reversed(pending):
                        target = root / relative
                        before, mode = originals[relative]
                        try:
                            observed = c.content(target)
                            if observed == after:
                                if before is None:
                                    target.unlink()
                                else:
                                    c.atomic(target, before, mode)
                            elif observed != before:
                                review.append(relative)
                        except (RuntimeError, OSError):
                            # An independently changed new helper can remain unused
                            # after the original consumers have been restored.
                            review.append(relative)
                receipt['status'] = 'rollback_review_required' if review else 'rolled_back'
                receipt['review'] = review
                receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
                print(('DINING_CASH_ENTER_KITCHEN_ROLLBACK_REVIEW_REQUIRED' if review else
                       'DINING_CASH_ENTER_KITCHEN_CODE_RESTORED') + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            print('DINING_CASH_ENTER_KITCHEN_OK files=' + str(len(pending))
                  + '; BACKUP=' + str(backup), flush=True)
            return backup


def install(root, revision, check_only=False):
    c = load_common(root)
    c.require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    c.require(root.is_absolute() and root.resolve() == root and root.is_dir(), 'APPLICATION_ROOT_INVALID')
    c.safe(root)
    c.require(c.git(root, 'rev-parse', '--verify', revision + '^{commit}').stdout.decode().strip() == revision,
              'RELEASE_COMMIT_UNAVAILABLE')
    c.require(c.git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
              'RELEASE_BASE_MISMATCH')
    c.require(c.git(root, 'merge-base', '--is-ancestor', UTILITY_REVISION, BASE_REVISION).returncode == 0,
              'PINNED_UTILITY_BASE_MISMATCH')
    plan = build_plan(c, root, revision)
    previous_umask = os.umask(0o022)
    try:
        return apply_update(c, root, revision, plan, check_only)
    finally:
        os.umask(previous_umask)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
        raise RuntimeError('RUN_AS_FASAKHA_REQUIRED')
    install(Path(args.root), args.revision, check_only=args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
