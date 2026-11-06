#!/usr/bin/env python3
"""Repair expense review dialogs without replacing neighboring changes."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time


PATCHES = {'public/dashboard/js/branch-expenses.js': [('    function actionButton(label, callback, icon) { var button '
                                             "= el('button'); button.type = 'button'; button.title = "
                                             "t(label); button.setAttribute('aria-label', t(label)); if "
                                             "(icon) button.appendChild(el('i', undefined, 'fas ' + icon)); "
                                             'else button.textContent = t(label); '
                                             "button.addEventListener('click', callback); return button; }\n",
                                             '    function actionButton(label, callback, icon) { var button '
                                             "= el('button'); button.type = 'button'; button.title = "
                                             "t(label); button.setAttribute('aria-label', t(label)); if "
                                             "(icon) button.appendChild(el('i', undefined, 'fas ' + icon)); "
                                             'else button.textContent = t(label); '
                                             "button.addEventListener('click', callback); return button; }\n"
                                             '    // Electron does not implement window.prompt. Keep review '
                                             'input in the page\n'
                                             '    // so rejecting or reversing an expense works in both '
                                             'desktop and browser.\n'
                                             "    var reviewDialog = el('dialog', undefined, 'ex-dialog'), "
                                             'reviewContext = null;\n'
                                             "    reviewDialog.dataset.expenseReviewDialog = ''; "
                                             "reviewDialog.setAttribute('aria-labelledby', "
                                             "'expense-review-title');\n"
                                             "    var reviewHeader = el('header'), reviewTitle = el('h2'), "
                                             "reviewClose = actionButton('close', closeReview);\n"
                                             "    reviewTitle.id = 'expense-review-title'; "
                                             "reviewClose.textContent = '×'; "
                                             "reviewClose.dataset.expenseReviewClose = '';\n"
                                             '    reviewHeader.append(reviewTitle, reviewClose);\n'
                                             "    var reviewBody = el('div', undefined, 'ex-editor'), "
                                             "reviewForm = el('form'), reviewSummary = el('p'), "
                                             "reviewMessage = el('p');\n"
                                             "    reviewForm.dataset.expenseReviewForm = ''; "
                                             'reviewForm.noValidate = true;\n'
                                             "    var reviewLabel = el('label', t('reason')), reviewReason = "
                                             "el('textarea');\n"
                                             "    reviewReason.dataset.expenseReviewReason = ''; "
                                             "reviewReason.name = 'reason'; reviewReason.required = true; "
                                             'reviewReason.maxLength = 500; reviewReason.rows = 3;\n'
                                             '    reviewLabel.appendChild(reviewReason);\n'
                                             "    var reviewError = el('p', undefined, 'ex-review-error'), "
                                             "reviewActions = el('div', undefined, 'ex-form-actions');\n"
                                             "    reviewError.dataset.expenseReviewError = ''; "
                                             "reviewError.id = 'expense-review-error'; reviewError.hidden = "
                                             "true; reviewError.setAttribute('role', 'alert');\n"
                                             "    reviewReason.setAttribute('aria-describedby', "
                                             'reviewError.id);\n'
                                             "    var reviewCancel = actionButton('cancel', closeReview), "
                                             "reviewSubmit = el('button', undefined, 'ex-primary');\n"
                                             "    reviewCancel.className = 'ex-button'; "
                                             "reviewCancel.dataset.expenseReviewCancel = ''; "
                                             "reviewSubmit.type = 'submit'; "
                                             "reviewSubmit.dataset.expenseReviewSubmit = '';\n"
                                             '    reviewActions.append(reviewCancel, reviewSubmit); '
                                             'reviewForm.append(reviewSummary, reviewMessage, reviewLabel, '
                                             'reviewError, reviewActions);\n'
                                             '    reviewBody.appendChild(reviewForm); '
                                             'reviewDialog.append(reviewHeader, reviewBody); '
                                             'root.appendChild(reviewDialog);\n'
                                             '    function closeReview() { reviewContext = null; '
                                             'reviewDialog.close(); }\n'
                                             "    on(reviewDialog, 'cancel', function (event) { "
                                             'event.preventDefault(); closeReview(); });\n'
                                             "    on(reviewReason, 'input', function () { "
                                             "reviewReason.setCustomValidity(''); "
                                             "reviewReason.removeAttribute('aria-invalid'); "
                                             'reviewError.hidden = true; });\n'
                                             "    on(reviewForm, 'submit', function (event) {\n"
                                             '        event.preventDefault(); '
                                             'event.stopImmediatePropagation();\n'
                                             '        if (locked() || !reviewContext) return;\n'
                                             '        var reason = reviewReason.value.trim(); '
                                             "reviewReason.setCustomValidity(reason ? '' : t('required'));\n"
                                             '        if (!reviewForm.reportValidity()) { '
                                             "reviewReason.setAttribute('aria-invalid', 'true'); "
                                             "reviewError.textContent = t('required'); reviewError.hidden = "
                                             'false; reviewReason.focus(); return; }\n'
                                             '        var command = reviewContext; closeReview(); '
                                             'submitReview(command.item, command.action, reason);\n'
                                             '    });\n'),
                                            ('    function review(item, action) {\n'
                                             "        if (locked()) return; var reason = ''; if (action !== "
                                             "'approve') { reason = prompt(t('reason')); if (!reason || "
                                             '!reason.trim()) return; }\n'
                                             "        if (action !== 'approve' && !confirm(t('confirm_' + "
                                             'action))) return;\n'
                                             "        rowNotice(item.id, '');\n"
                                             '        try {\n'
                                             "            execute({ type: 'review', expenseId: item.id, url: "
                                             'url(boot.urls.review, null, item.id), values: { branch: '
                                             'item.branch, expected_revision: item.revision, action: action, '
                                             'reason: reason.trim(), idempotency_key: uuid() } }, false);\n'
                                             "        } catch (error) { notice(error.message || t('error')); "
                                             "rowNotice(item.id, error.message || t('error')); }\n"
                                             '    }\n',
                                             '    function submitReview(item, action, reason) {\n'
                                             "        rowNotice(item.id, '');\n"
                                             '        try {\n'
                                             "            execute({ type: 'review', expenseId: item.id, url: "
                                             'url(boot.urls.review, null, item.id), values: { branch: '
                                             'item.branch, expected_revision: item.revision, action: action, '
                                             'reason: reason.trim(), idempotency_key: uuid() } }, false);\n'
                                             "        } catch (error) { notice(error.message || t('error')); "
                                             "rowNotice(item.id, error.message || t('error')); }\n"
                                             '    }\n'
                                             '    function review(item, action) {\n'
                                             '        if (locked() || reviewDialog.open) return;\n'
                                             "        if (action === 'approve') { submitReview(item, action, "
                                             "''); return; }\n"
                                             '        reviewContext = { item: item, action: action }; '
                                             'reviewTitle.textContent = t(action); reviewSubmit.textContent '
                                             '= t(action);\n'
                                             "        reviewSummary.textContent = item.number + ' · ' + "
                                             "item.amount + ' ' + t('currency'); reviewMessage.textContent = "
                                             "t('confirm_' + action);\n"
                                             "        reviewReason.value = ''; "
                                             "reviewReason.setCustomValidity(''); "
                                             "reviewReason.removeAttribute('aria-invalid'); "
                                             'reviewError.hidden = true;\n'
                                             '        reviewDialog.showModal(); reviewReason.focus();\n'
                                             '    }\n'),
                                            ('    '
                                             "if(window.DashboardSPA){window.DashboardSPA.onBeforeLeave(mayLeave);if(window.DashboardSPA.onIdle)window.DashboardSPA.onIdle(refreshIdle);window.DashboardSPA.onCleanup(function(){disposed=true;if(categoryController)categoryController.abort();if(controller)controller.abort();listeners.forEach(function(remove){remove();});if(window.jQuery)window.jQuery(branch).off('.branchExpenses');dialog.close();if(categoriesDialog)categoriesDialog.close();});}\n",
                                             '    '
                                             "if(window.DashboardSPA){window.DashboardSPA.onBeforeLeave(mayLeave);if(window.DashboardSPA.onIdle)window.DashboardSPA.onIdle(refreshIdle);window.DashboardSPA.onCleanup(function(){disposed=true;if(categoryController)categoryController.abort();if(controller)controller.abort();listeners.forEach(function(remove){remove();});if(window.jQuery)window.jQuery(branch).off('.branchExpenses');closeReview();reviewDialog.remove();dialog.close();if(categoriesDialog)categoriesDialog.close();});}\n")]}
MAX_BYTES = 4 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def safe_path(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for entry in (path, *path.parents):
        require(not entry.is_symlink(), 'SYMLINK_REJECTED: ' + str(entry))


def read_file(path):
    safe_path(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1,
                'REGULAR_FILE_REQUIRED: ' + str(path))
        require(info.st_uid == os.geteuid(), 'OWNER_MISMATCH: ' + str(path))
        data = stream.read(MAX_BYTES + 1)
    require(len(data) <= MAX_BYTES and b'\x00' not in data, 'INVALID_SOURCE: ' + str(path))
    return data, info


def candidate(relative, data):
    for before, after in PATCHES[relative]:
        old, new = before.encode('utf-8'), after.encode('utf-8')
        if data.count(new) == 1 and data.replace(new, b'', 1).count(old) == 0:
            continue
        require(data.count(old) == 1 and data.count(new) == 0,
                'PATCH_CONFLICT: ' + relative)
        data = data.replace(old, new, 1)
    return data


def signature(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid,
            stat.S_IMODE(info.st_mode), info.st_mtime_ns)


def unchanged(path, original):
    data, info = read_file(path)
    return data == original[0] and signature(info) == signature(original[1])


def atomic_write(path, data, info):
    safe_path(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.expense-review-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            # Blade versions these assets with filemtime(), rounded to seconds.
            modified = max(time.time_ns(), (info.st_mtime_ns // 1_000_000_000 + 1) * 1_000_000_000)
            os.utime(stream.fileno(), ns=(modified, modified))
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def lint_candidates(directory, php):
    node = shutil.which('node')
    for relative in PATCHES:
        command = None
        if relative.endswith('.php') and php:
            command = [php, '-l', str(directory / relative)]
        elif relative.endswith('.js') and node:
            command = [node, '--check', str(directory / relative)]
        if command:
            result = subprocess.run(command, capture_output=True, timeout=30)
            require(result.returncode == 0, 'SYNTAX_INVALID: ' + relative)
    print('SYNTAX_CHECKS: php=' + ('passed' if php else 'unavailable')
          + ' node=' + ('passed' if node else 'unavailable'))


def private_directory(path):
    safe_path(path)
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.stat()
    require(path.is_dir() and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700,
            'PRIVATE_DIRECTORY_REQUIRED: ' + str(path))


def backup(root, originals, revision):
    parent = root.parent / 'expense-review-dialog-backups'
    private_directory(parent)
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
    directory = Path(tempfile.mkdtemp(prefix=stamp, dir=parent))
    manifest = {'root': str(root), 'revision': revision, 'files': {}}
    for relative, (data, info) in originals.items():
        target = directory / relative
        target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        target.write_bytes(data)
        target.chmod(0o600)
        manifest['files'][relative] = {
            'sha256': hashlib.sha256(data).hexdigest(), 'uid': info.st_uid,
            'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode),
        }
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (directory / 'manifest.json').chmod(0o600)
    return directory


def install(root, php=None, check=False, revision='local'):
    safe_path(root)
    require(root.is_dir(), 'APPLICATION_ROOT_REQUIRED')
    require(root.stat().st_uid == os.geteuid(), 'APPLICATION_OWNER_MISMATCH')
    lock_path = root.parent / '.expense-review-dialog.lock'
    safe_path(lock_path)
    descriptor = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'a+b') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid(), 'INVALID_INSTALLER_LOCK')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        originals = {relative: read_file(root / relative) for relative in PATCHES}
        candidates = {relative: candidate(relative, original[0])
                      for relative, original in originals.items()}
        with tempfile.TemporaryDirectory(prefix='expense-review-check-') as temporary:
            directory = Path(temporary)
            for relative, data in candidates.items():
                target = directory / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
            lint_candidates(directory, php)
        changed = [relative for relative in PATCHES if originals[relative][0] != candidates[relative]]
        if not changed:
            print('EXPENSE_REVIEW_DIALOG_ALREADY_APPLIED')
            return
        for relative, original in originals.items():
            require(unchanged(root / relative, original), 'SOURCE_CHANGED: ' + relative)
        if check:
            print('EXPENSE_REVIEW_DIALOG_CHECK_OK: ' + ', '.join(changed))
            return
        directory = backup(root, {relative: originals[relative] for relative in changed}, revision)
        print('BACKUP: ' + str(directory), flush=True)
        written = []
        old_handler = signal.getsignal(signal.SIGTERM)

        def terminate(signum, frame):
            raise RuntimeError('INSTALL_INTERRUPTED')

        signal.signal(signal.SIGTERM, terminate)
        try:
            for relative in changed:
                require(unchanged(root / relative, originals[relative]), 'SOURCE_CHANGED: ' + relative)
                # Record before replacement so failures after os.replace also roll back.
                written.append(relative)
                atomic_write(root / relative, candidates[relative], originals[relative][1])
            for relative in PATCHES:
                require(read_file(root / relative)[0] == candidates[relative],
                        'POST_INSTALL_MISMATCH: ' + relative)
        except BaseException:
            rollback_errors = []
            for relative in reversed(written):
                try:
                    current = read_file(root / relative)[0]
                    require(current in (candidates[relative], originals[relative][0]),
                            'CONCURRENT_CHANGE_PREVENTS_ROLLBACK: ' + relative)
                    if current != originals[relative][0]:
                        atomic_write(root / relative, originals[relative][0], originals[relative][1])
                except Exception as error:
                    rollback_errors.append(str(error))
            if rollback_errors:
                raise RuntimeError('ROLLBACK_INCOMPLETE; backup=' + str(directory)
                                   + '; ' + '; '.join(rollback_errors))
            print('EXPENSE_REVIEW_DIALOG_ROLLED_BACK', flush=True)
            raise
        finally:
            signal.signal(signal.SIGTERM, old_handler)
        print('EXPENSE_REVIEW_DIALOG_APPLIED: ' + ', '.join(changed))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--revision', default='local')
    parser.add_argument('--php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    require(args.revision == 'local' or re.fullmatch(r'[0-9a-f]{40}', args.revision),
            'FULL_REVISION_REQUIRED')
    if args.php:
        require(Path(args.php).is_file() and os.access(args.php, os.X_OK), 'PHP_EXECUTABLE_REQUIRED')
    install(args.root, args.php, args.check, args.revision)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('EXPENSE_REVIEW_DIALOG_FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
