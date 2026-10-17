#!/usr/bin/env python3
"""Install the phone delivery refresh after the pinned inline quantity update."""
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

QUANTITY_REVISION = '6582750c2ef925500504a83d89141e49f0bd62a3'
QUANTITY_HELPER = 'deployment/apply_pos_inline_quantity.py'
QUANTITY_HELPER_BLOB = 'a438bfdcb17f43ef11605bcb48de926c190b3f73'
PHONE_PREIMAGE_REVISION = '642dbfe4be0dc1097435bdb81e2606804b8b8955'
PHONE_PREIMAGE_SHA256 = 'd0a087d974c1a319c5d5b9163730a208b0759488fcd0d3e7b76e1cd7c205e1fe'
PHONE_JS = 'public/dashboard/js/phone-orders.js'
SOURCE_MANIFEST = {
    'public/dashboard/css/phone-delivery-refresh.css': 'A',
    'app/Services/Dashboard/TakeawayService.php': 'M',
    'app/Services/Dashboard/PosServiceTicket.php': 'M',
    'app/Services/Dashboard/PhoneDeliveryBoard.php': 'M',
    'app/Http/Controllers/Dashboard/TakeawayController.php': 'M',
    'app/Http/Controllers/Dashboard/PosServiceController.php': 'M',
    'app/Http/Controllers/Dashboard/PhoneOrdersController.php': 'M',
    'resources/lang/ar/takeaway.php': 'M',
    'resources/lang/en/takeaway.php': 'M',
    'resources/lang/ar/dining.php': 'M',
    'resources/lang/en/dining.php': 'M',
    'resources/lang/ar/phone_orders.php': 'M',
    'resources/lang/en/phone_orders.php': 'M',
    'public/dashboard/js/takeaway-pos.js': 'M',
    'public/dashboard/js/dining-pos.js': 'M',
    'public/dashboard/js/phone-orders.js': 'M',
    'resources/views/admin/takeaway/index.blade.php': 'M',
    'resources/views/admin/dining/index.blade.php': 'M',
    'resources/views/admin/phone_orders/index.blade.php': 'M',
}


def load_quantity(root):
    """Load only the exact previously reviewed installer from its pinned commit."""
    result = subprocess.run(['git', '-C', str(root), 'show', QUANTITY_REVISION + ':' + QUANTITY_HELPER],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if result.returncode != 0:
        raise RuntimeError('PINNED_QUANTITY_HELPER_UNAVAILABLE')
    checked = subprocess.run(['git', 'hash-object', '--no-filters', '--stdin'], input=result.stdout,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if checked.returncode != 0 or checked.stdout.decode().strip() != QUANTITY_HELPER_BLOB:
        raise RuntimeError('PINNED_QUANTITY_HELPER_MISMATCH')
    with tempfile.TemporaryDirectory(prefix='phone-refresh-helper-') as temporary:
        helper = Path(temporary) / 'quantity.py'
        helper.write_bytes(result.stdout)
        spec = importlib.util.spec_from_file_location('pinned_phone_refresh_quantity', helper)
        common = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(common)
    return common


def phone_preimage(c, root):
    """Reconstruct the confirmed live collection flow plus the quantity layer."""
    with tempfile.TemporaryDirectory(prefix='phone-refresh-preimage-') as temporary:
        stage = Path(temporary)
        target = stage / PHONE_JS
        target.parent.mkdir(parents=True)
        target.write_bytes(c.source(root, PHONE_PREIMAGE_REVISION, PHONE_JS))
        patch = stage / 'quantity.diff'
        patch.write_bytes(c.build_plan(root, QUANTITY_REVISION)[PHONE_JS]['patch'])
        c.require(c.patch_check(stage, patch), 'PHONE_PREIMAGE_QUANTITY_CONFLICT')
        c.require(c.run(['git', 'apply', '--whitespace=nowarn', str(patch)], cwd=stage).returncode == 0,
                  'PHONE_PREIMAGE_QUANTITY_FAILED')
        body = target.read_bytes()
        c.require(c.digest(body) == PHONE_PREIMAGE_SHA256, 'PHONE_PREIMAGE_MISMATCH')
        return body


def lint_php(c, php, directory):
    for relative in SOURCE_MANIFEST:
        if relative.endswith('.php') and not relative.endswith('.blade.php'):
            c.require(c.run([php, '-l', str(directory / relative)]).returncode == 0,
                      'PHP_SYNTAX_INVALID: ' + relative)


def build_plan(c, root, revision):
    plan = {}
    with tempfile.TemporaryDirectory(prefix='phone-refresh-plan-') as temporary:
        for relative, status in SOURCE_MANIFEST.items():
            before = (phone_preimage(c, root) if relative == PHONE_JS
                      else c.source(root, QUANTITY_REVISION, relative, optional=status == 'A'))
            c.require((before is None) == (status == 'A'), 'SOURCE_STATUS_INVALID: ' + relative)
            after = c.source(root, revision, relative)
            c.require(before != after, 'RELEASE_CHANGE_MISSING: ' + relative)
            plan[relative] = {'status': status, 'patch': c.make_patch(Path(temporary), relative, before, after)}
    return plan


def prove_refresh(c, root):
    style = c.content(root / 'public/dashboard/css/phone-orders.css')
    c.require(style is not None and style.strip(), 'PHONE_LAYOUT_STYLE_REQUIRED')
    # An independently preinstalled new asset is never chmodded silently.
    assets = [path for path, status in SOURCE_MANIFEST.items() if status == 'A' and path.startswith('public/')]
    assets += [path for path, (_, status) in c.SOURCE_MANIFEST.items()
               if status == 'A' and path.startswith('public/')]
    for relative in assets:
        target = root / relative
        if c.content(target) is not None:
            c.require(stat.S_IMODE(target.stat().st_mode) & 0o444 == 0o444,
                      'PUBLIC_ASSET_READ_PERMISSION_REQUIRED: ' + relative)


def virtual_preflight(c, root, revision, php, plan):
    """Check both source layers together before either installer publishes files."""
    c.install(root, QUANTITY_REVISION, php, check_only=True)
    quantity_plan = c.build_plan(root, QUANTITY_REVISION)
    with tempfile.TemporaryDirectory(prefix='phone-refresh-combined-') as temporary:
        stage = Path(temporary)
        copied = set()
        for label, layer in (('quantity', quantity_plan), ('refresh', plan)):
            for index, (relative, entry) in enumerate(layer.items()):
                target = stage / relative
                if relative not in copied:
                    before = c.content(root / relative)
                    target.parent.mkdir(parents=True, exist_ok=True)
                    if before is not None:
                        target.write_bytes(before)
                    copied.add(relative)
                patch = stage / (label + '-' + str(index) + '.diff')
                patch.write_bytes(entry['patch'])
                if c.patch_check(stage, patch, reverse=True):
                    continue
                c.require(c.patch_check(stage, patch), 'COMBINED_LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                c.require(c.run(['git', 'apply', '--whitespace=nowarn', str(patch)], cwd=stage).returncode == 0,
                          'COMBINED_STAGED_PATCH_FAILED: ' + relative)
        lint_php(c, php, stage)
        views = [stage / path for path in SOURCE_MANIFEST if path.endswith('.blade.php')]
        c.runtime_probe(root, php, stage, 'preflight', views)
    print('PHONE_DELIVERY_REFRESH_COMBINED_PREFLIGHT_OK', flush=True)

def apply_feature(c, root, revision, php, plan, check_only=False):
    c.require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    c.require(root.is_absolute() and root.resolve() == root and root.is_dir(),
            'APPLICATION_ROOT_INVALID')
    c.safe(root)
    resolved = c.git(root, 'rev-parse', '--verify', revision + '^{commit}')
    c.require(resolved.returncode == 0 and resolved.stdout.decode().strip() == revision,
            'RELEASE_COMMIT_UNAVAILABLE')
    c.require(c.git(root, 'merge-base', '--is-ancestor', QUANTITY_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')
    prove_refresh(c, root)

    private = root.parent / 'phone-delivery-refresh-backups'
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

            lint_php(c, php, stage)
            views = [stage / path for path in SOURCE_MANIFEST if path.endswith('.blade.php')]
            c.runtime_probe(root, php, stage, 'preflight', views)
            if not pending:
                print('PHONE_DELIVERY_REFRESH_OK already_current=1 files=0', flush=True)
                return None
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(patch for _, patch, _ in pending))
            c.require(c.patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print('PHONE_DELIVERY_REFRESH_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            # Every target must still contain exactly the bytes checked above.
            for relative, _, _ in pending:
                c.require(c.content(root / relative) == originals[relative][0],
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=prefix + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': QUANTITY_REVISION,
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
                # This does not use --index, checkout, reset, or whole-file replacement.
                applied = c.run(['git', 'apply', '--whitespace=nowarn', str(combined)], cwd=root)
                c.require(applied.returncode == 0, 'LIVE_PATCH_FAILED')
                for relative, _, after in pending:
                    c.require(c.content(root / relative) == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_php(c, php, root)
                prove_refresh(c, root)
                c.runtime_probe(root, php, stage, 'installed')
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
                print(('PHONE_DELIVERY_REFRESH_ROLLBACK_REVIEW_REQUIRED' if review else
                       'PHONE_DELIVERY_REFRESH_CODE_RESTORED') + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            print('PHONE_DELIVERY_REFRESH_OK files=' + str(len(pending))
                  + '; BACKUP=' + str(backup), flush=True)
            return backup


def install(root, revision, php, check_only=False):
    c = load_quantity(root)
    c.require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    c.require(root.is_absolute() and root.resolve() == root and root.is_dir(), 'APPLICATION_ROOT_INVALID')
    c.safe(root)
    resolved = c.git(root, 'rev-parse', '--verify', revision + '^{commit}')
    c.require(resolved.returncode == 0 and resolved.stdout.decode().strip() == revision, 'RELEASE_COMMIT_UNAVAILABLE')
    c.require(c.git(root, 'merge-base', '--is-ancestor', QUANTITY_REVISION, revision).returncode == 0,
              'RELEASE_BASE_MISMATCH')
    plan = build_plan(c, root, revision)
    prove_refresh(c, root)
    virtual_preflight(c, root, revision, php, plan)
    if check_only:
        print('PHONE_DELIVERY_REFRESH_PREFLIGHT_OK check_only=1', flush=True)
        return None
    # Git applies new public assets using the process umask. Keep their tracked
    # 0644 permissions while both installers keep backup directories private.
    previous_umask = os.umask(0o022)
    try:
        c.install(root, QUANTITY_REVISION, php)
        try:
            return apply_feature(c, root, revision, php, plan)
        except BaseException:
            print('PHONE_DELIVERY_REFRESH_STOPPED quantity_update_ready=1', flush=True)
            raise
    finally:
        os.umask(previous_umask)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
        raise RuntimeError('RUN_AS_FASAKHA_REQUIRED')
    install(Path(args.root), args.revision, args.php, check_only=args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
