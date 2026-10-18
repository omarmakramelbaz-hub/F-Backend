#!/usr/bin/env python3
"""Read every scoped shift report source and report merge conflicts without installing."""
import argparse
import difflib
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import subprocess
import tempfile
import types

INSTALLER_PATH = 'deployment/apply_shift_report.py'
MAX_INSTALLER_BYTES = 256 * 1024
MAX_DIFF_BYTES = 192 * 1024
MAX_REPORT_BYTES = 768 * 1024
ALLOWED_SOURCE_PATHS = ('app/Services/Dashboard/BranchShiftPrinting.php', 'app/Services/Dashboard/BranchOrderSequence.php', 'app/Http/Controllers/Dashboard/BranchShiftPrintingController.php', 'database/migrations/2026_10_18_210000_create_shift_report_printing_and_numbers.php', 'app/Models/Order.php', 'app/Services/Dashboard/BranchShiftClosing.php', 'app/Services/Dashboard/TakeawayService.php', 'app/Services/Dashboard/PosServiceTicket.php', 'app/Services/Dashboard/HomeOverview.php', 'app/Services/Dashboard/DesktopDashboardSchema.php', 'app/Http/Controllers/Dashboard/BranchShiftClosingController.php', 'public/dashboard/js/branch-print-receiver.js', 'public/dashboard/js/branch-shifts.js', 'resources/views/admin/branch_shifts/index.blade.php', 'resources/views/admin/branch_shifts/receipt.blade.php', 'resources/views/admin/pos_service/receiver.blade.php', 'routes/branch_shifts.php')
ALLOWED_NEW_PATHS = ('app/Services/Dashboard/BranchShiftPrinting.php', 'app/Services/Dashboard/BranchOrderSequence.php', 'app/Http/Controllers/Dashboard/BranchShiftPrintingController.php', 'database/migrations/2026_10_18_210000_create_shift_report_printing_and_numbers.php')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run_git(root, *arguments):
    return subprocess.run(['git', '-C', str(root), *arguments], stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=180)


def load_installer(root, revision):
    """Execute only the installer blob from the requested immutable git revision."""
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    require(root.is_absolute() and root.is_dir() and root.resolve() == root, 'APPLICATION_ROOT_INVALID')
    require(all(not path.is_symlink() for path in (root, *root.parents)), 'SYMLINK_ROOT_REJECTED')
    commit = run_git(root, 'rev-parse', '--verify', revision + '^{commit}')
    require(commit.returncode == 0 and commit.stdout.decode().strip() == revision,
            'PINNED_COMMIT_UNAVAILABLE')
    tree = run_git(root, 'ls-tree', revision, '--', INSTALLER_PATH)
    fields = tree.stdout.split()
    require(tree.returncode == 0 and len(fields) == 4 and fields[0] in (b'100644', b'100755')
            and fields[1] == b'blob' and fields[3].decode() == INSTALLER_PATH,
            'PINNED_INSTALLER_NOT_REGULAR')
    size = run_git(root, 'cat-file', '-s', revision + ':' + INSTALLER_PATH)
    require(size.returncode == 0 and size.stdout.strip().isdigit()
            and 0 < int(size.stdout.strip()) <= MAX_INSTALLER_BYTES, 'PINNED_INSTALLER_SIZE_INVALID')
    blob = run_git(root, 'show', revision + ':' + INSTALLER_PATH)
    require(blob.returncode == 0 and len(blob.stdout) == int(size.stdout.strip())
            and b'\x00' not in blob.stdout, 'PINNED_INSTALLER_UNAVAILABLE')
    module = types.ModuleType('pinned_shift_report_installer')
    module.__file__ = 'git:' + revision + ':' + INSTALLER_PATH
    exec(compile(blob.stdout, module.__file__, 'exec', dont_inherit=True), module.__dict__)
    require(tuple(module.SOURCE_PATHS) == ALLOWED_SOURCE_PATHS
            and tuple(module.NEW_PATHS) == ALLOWED_NEW_PATHS, 'DIAGNOSTIC_SOURCE_ALLOWLIST_MISMATCH')
    module.validate_release(root, revision)
    return module


def sha256(body):
    return hashlib.sha256(body).hexdigest() if body is not None else '-'


def utf8_prefix(value, limit):
    """Bound the encoded bytes without cutting a multibyte UTF-8 character."""
    encoded = value.encode('utf-8')
    return encoded[:max(0, limit)].decode('utf-8', errors='ignore'), len(encoded)


def source_diff(relative, base, live):
    lines = difflib.unified_diff(base.decode('utf-8').splitlines(keepends=True),
                                 live.decode('utf-8').splitlines(keepends=True),
                                 fromfile='base/' + relative, tofile='live/' + relative, n=3)
    return ''.join(line if line.endswith('\n') else line + '\n\\ No newline at end of file\n'
                   for line in lines)


def build_report(root, revision, installer):
    """Use only read/merge helpers; install(), PHP, and application bootstrap are never called."""
    require(tuple(installer.SOURCE_PATHS) == ALLOWED_SOURCE_PATHS, 'DIAGNOSTIC_SOURCE_ALLOWLIST_MISMATCH')
    records = []
    with tempfile.TemporaryDirectory(prefix='diagnose-shift-report-') as temporary:
        stage = Path(temporary)
        for relative in ALLOWED_SOURCE_PATHS:
            record = {'path': relative, 'base': None, 'live': None, 'release': None,
                      'candidate': None, 'error': '', 'diff': '', 'stable': True}
            original = None
            try:
                record['base'] = (None if relative in ALLOWED_NEW_PATHS else installer.release_body(root, installer.BASE_REVISION, relative))
                record['release'] = installer.release_body(root, revision, relative)
                if relative == installer.OPTIONAL_PATH and not (root / relative).exists():
                    require(not (root / 'app/Services/Dashboard/DesktopDashboardBootstrap.php').exists(), 'INCOMPLETE_DESKTOP_SOURCE_NEEDS_REVIEW')
                    record['status'] = 'SKIPPED_NOT_INSTALLED'
                    records.append(record)
                    continue
                record['live'], info = installer.read_source(root / relative, absent_ok=relative in ALLOWED_NEW_PATHS)
                original = (record['live'], info)
                try:
                    legacy = installer.release_body(root, installer.LEGACY_BASE_REVISION, relative) if relative in installer.LEGACY_PATHS else None
                    record['candidate'] = installer.merge_candidate(
                        stage, relative, record['live'], record['base'], record['release'], legacy)
                    record['status'] = ('ALREADY_CURRENT' if record['candidate'] == record['live']
                                        else 'APPLICABLE')
                except (RuntimeError, OSError, ValueError, subprocess.SubprocessError) as error:
                    record['status'] = 'MERGE_CONFLICT'
                    record['error'] = str(error).replace(temporary, '<temporary>')[:512]
                    record['diff'] = source_diff(relative, record['base'] or b'', record['live'] or b'')
            except (RuntimeError, OSError, ValueError, subprocess.SubprocessError) as error:
                record['status'] = 'SOURCE_UNAVAILABLE'
                record['error'] = str(error).replace(temporary, '<temporary>')[:512]
            if original is not None:
                try:
                    record['stable'] = installer.unchanged(root / relative, original)
                except (RuntimeError, OSError, ValueError, subprocess.SubprocessError):
                    record['stable'] = False
            records.append(record)

    failed = [record for record in records if record['status'] in ('MERGE_CONFLICT', 'SOURCE_UNAVAILABLE')]
    lines = ['SHIFT_REPORT_DIAGNOSTIC_V1\n', 'mode=READ_ONLY\n',
             'root=' + json.dumps(str(root), ensure_ascii=True) + '\n',
             'revision=' + revision + '\n', 'base_revision=' + installer.BASE_REVISION + '\n',
             'files=' + str(len(records)) + ' conflicts=' + str(len(failed))
             + ' unstable=' + str(sum(not record['stable'] for record in records)) + '\n',
             '\n=== SOURCE STATUS AND SHA256 ===\n']
    for record in records:
        lines.append(record['path'] + '\n  status=' + record['status']
                     + ' stable=' + str(int(record['stable']))
                     + '\n  base_sha256=' + sha256(record['base'])
                     + '\n  live_sha256=' + sha256(record['live'])
                     + '\n  release_sha256=' + sha256(record['release'])
                     + '\n  candidate_sha256=' + sha256(record['candidate']) + '\n')
        if record['error']:
            lines.append('  error=' + json.dumps(record['error'], ensure_ascii=True) + '\n')
    lines.append('\n=== BASE TO LIVE DIFFS FOR EVERY FAILED SOURCE ===\n')
    report = ''.join(lines)
    # Reserve all remaining section headings and markers before allocating diff bytes.
    reserved = 1024 + sum(len(record['path'].encode('utf-8')) + 256 for record in failed)
    available = MAX_REPORT_BYTES - len(report.encode('utf-8')) - reserved
    require(available >= 0, 'DIAGNOSTIC_METADATA_LIMIT_EXCEEDED')
    truncated = 0
    for index, record in enumerate(failed):
        limit = min(MAX_DIFF_BYTES, available // (len(failed) - index))
        excerpt, original_size = utf8_prefix(record['diff'], limit)
        used = len(excerpt.encode('utf-8'))
        available -= used
        clipped = used < original_size
        truncated += int(clipped)
        report += ('\n--- FAILED_SOURCE ' + record['path'] + ' ---\n'
                   + 'diff_bytes=' + str(original_size) + ' shown_bytes=' + str(used)
                   + ' diff_sha256=' + sha256(record['diff'].encode('utf-8')) + '\n')
        report += excerpt
        if excerpt and not excerpt.endswith('\n'):
            report += '\n'
        if clipped:
            report += '[DIFF_TRUNCATED: per-file or total report limit reached]\n'
        elif not excerpt:
            report += '[NO_DIFF: source could not be read safely]\n'
    report += ('\n=== END DIAGNOSTIC ===\nconflicts=' + str(len(failed))
               + ' truncated_diffs=' + str(truncated) + ' source_changes_performed=0\n')
    require(len(report.encode('utf-8')) <= MAX_REPORT_BYTES, 'DIAGNOSTIC_REPORT_LIMIT_EXCEEDED')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    args = parser.parse_args()
    require(os.geteuid() == pwd.getpwnam('fasakha').pw_uid, 'RUN_AS_FASAKHA_REQUIRED')
    root = Path(args.root)
    installer = load_installer(root, args.revision)
    print(build_report(root, args.revision, installer), end='', flush=True)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
        print('SHIFT_REPORT_DIAGNOSTIC_STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
