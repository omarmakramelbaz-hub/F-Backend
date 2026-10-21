#!/usr/bin/env bash
# One console entry: classify exact server state, prepare, activate, then set up
# the minute watcher. Existing installed source is verified before being reused.
set -euo pipefail
umask 077
exec /usr/bin/python3 -I -B - "$0" "$@" <<'PY'
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import subprocess
import sys
import tempfile

ROOT = Path('/home/fasakha/public_html')
APP_RELEASE = '3b7d53e6021ce825dd6fc56218cb6ce25f72b3b4'
AUTOMATION_BRANCH = 'refs/heads/codex/go-erp-current-activation-20261021-r2'
SELF = 'deployment/activate_go_erp_complete_root.sh'
REPAIR = 'deployment/diagnose_go_erp_activation_permissions_root.sh'
SETUP = 'deployment/install_go_erp_live_updates_root.sh'
OPERATIONS = 'deployment/go_erp_ci_publish_root.sh'
MANIFEST = 'deployment/desktop_dashboard_server_manifest.json'
SCANNER = 'deployment/diagnose_desktop_dashboard_current_source.py'
SOURCE = 'deployment/desktop_dashboard_server_preflight.py'
APP_FILES = {
    MANIFEST: 'da47b5e5dc50676d6046a6511b3905a0cd053ed1',
    SCANNER: 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
    SOURCE: '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
    'deployment/apply_desktop_dashboard_server_root.sh': '1db5e75d92cde2ffa13539cd2c04a2ec76a61a91',
    'deployment/desktop_dashboard_release.php': '69f4efc30a440d7e80778d0618f0f0fa7f79d46d',
}
AUTOMATION_FILES = {
    REPAIR: '5754f78b1c145c30e6f39770d30a888528b4c51d',
    SETUP: 'c624d2c68a459c359f7f5c79adffc88cdd018035',
    OPERATIONS: '1549c97c8513265db3188a5c24ccc8c753abd103',
}
REVISION = re.compile(r'[a-f0-9]{40}\Z')
ENV = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha', 'GIT_OPTIONAL_LOCKS': '0',
       'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_NO_LAZY_FETCH': '1'}


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_COMPLETE_' + reason)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def run(arguments, timeout=120):
    try:
        value = subprocess.run(arguments, env=ENV, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise Stop('GO_ERP_COMPLETE_CHILD_UNAVAILABLE_OR_TIMED_OUT') from None
    need(len(value.stdout) <= 768 * 1024 and len(value.stderr) <= 65536, 'CHILD_OUTPUT_LIMIT')
    return value


def git(*arguments):
    value = run(['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i',
        *[key + '=' + value for key, value in ENV.items()], '/usr/bin/git',
        '--no-optional-locks', '-c', 'core.fsmonitor=false', '-C', str(ROOT), *arguments])
    need(value.returncode == 0, 'REVIEWED_GIT_OPERATION_FAILED')
    return value.stdout


def source_file(revision, name, expected=None):
    row = git('ls-tree', '-z', revision, name)
    match = re.fullmatch(rb'100(?:644|755) blob ([a-f0-9]{40})\t' + re.escape(name.encode()) + rb'\0', row)
    need(match is not None, 'REVIEWED_FILE_INVALID')
    pin = match[1].decode()
    need(expected is None or expected == pin, 'REVIEWED_FILE_PIN_MISMATCH')
    data = git('show', revision + ':' + name)
    need(0 < len(data) <= 512 * 1024 and blob(data) == pin, 'REVIEWED_BYTES_INVALID')
    return data


def private_entry(path):
    need(path.is_absolute() and path.resolve() == path, 'ENTRYPOINT_UNSAFE')
    for parent in path.parents:
        value = parent.lstat()
        sticky = parent in (Path('/tmp'), Path('/var/tmp')) and value.st_mode & stat.S_ISVTX
        need(stat.S_ISDIR(value.st_mode) and value.st_uid == 0
             and (not value.st_mode & 0o022 or sticky), 'ENTRYPOINT_ANCESTOR_UNSAFE')
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
         and stat.S_IMODE(before.st_mode) == 0o600 and 0 < before.st_size <= 512 * 1024,
         'ENTRYPOINT_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        key = lambda v: (v.st_dev, v.st_ino, v.st_mode, v.st_uid, v.st_gid, v.st_nlink,
                         v.st_size, v.st_mtime_ns, v.st_ctime_ns)
        data = os.read(fd, 512 * 1024 + 1)
        need(len(data) == before.st_size and key(before) == key(os.fstat(fd)) == key(path.lstat()),
             'ENTRYPOINT_CHANGED')
        return data
    finally:
        os.close(fd)


def payload(revision, entry):
    git('fetch', '--quiet', '--no-tags', '--no-prune', '--no-recurse-submodules', '--refmap=',
        'origin', AUTOMATION_BRANCH)
    need(git('rev-parse', 'FETCH_HEAD').decode('ascii').strip() == revision, 'RELEASE_SUPERSEDED')
    git('merge-base', '--is-ancestor', APP_RELEASE, revision)
    need(private_entry(entry) == source_file(revision, SELF), 'ENTRYPOINT_NOT_REVIEWED')
    result = {name: source_file(APP_RELEASE, name, pin) for name, pin in APP_FILES.items()}
    result.update({name: source_file(revision, name, pin) for name, pin in AUTOMATION_FILES.items()})
    return result


def python_payload(data):
    # Only the exact pinned console script is admitted to this extraction.
    marker = b"<<'PY'\n"
    need(data.count(marker) == 1 and data.endswith(b'\nPY\n'), 'REVIEWED_PYTHON_PAYLOAD_INVALID')
    return data.split(marker, 1)[1][:-4]


def namespace(data, name, shell=False):
    value = {'__name__': name}
    exec(compile(python_payload(data) if shell else data, name, 'exec'), value)
    return value


def classifier(root, manifest, scanner, source, head_reader):
    gui = [row['path'] for row in manifest['protected'] if row['path'] != 'artisan'
        and not any(row['path'].startswith(prefix + '/') for prefix in source['ROOTS'])]
    first = scanner['snapshot'](root, source, gui, [], head_reader)
    second = scanner['snapshot'](root, source, gui, [], head_reader)
    need(first == second, 'SERVER_STATE_CHANGED_DURING_INSPECTION')
    if second['head'] != manifest['expected_head']:
        return 'UNKNOWN'
    expected_gui = {name for name in gui if name.startswith(('public/js/', 'public/css/'))}
    if set(second['additional_dashboard_gui']) != expected_gui:
        return 'UNKNOWN'
    def actual(name):
        value = second['files'].get(name)
        if value is None:
            pair = second['gui'].get(name)
            value = pair[0] if pair is not None else None
        return None if value is None else value['blob']
    if any(actual(row['path']) != row['blob'] for row in manifest['protected']):
        return 'UNKNOWN'
    fingerprint = {**source['digest'](second['files']), 'framework': '8.83.29'}
    matches = []
    for label, state, pin in (('BEFORE', 'before_source', 'before_blob'), ('AFTER', 'after_source', 'after_blob')):
        if fingerprint == manifest[state] and all(actual(row['path']) == row[pin] for row in manifest['sources']):
            matches.append(label)
    return matches[0] if len(matches) == 1 else 'UNKNOWN'


def stage_payload(folder, files):
    for name, data in files.items():
        path = folder / Path(name).name
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())


def repair(folder):
    value = run(['/usr/bin/bash', str(folder / Path(REPAIR).name), '--repair'], timeout=180)
    rows = value.stdout.decode('ascii').splitlines()
    if rows:
        need(len(rows) == 3 and rows[0] == 'GO_ERP_ACTIVATION_PERMISSIONS_BEGIN'
             and rows[2] == 'GO_ERP_ACTIVATION_PERMISSIONS_END; APPLICATION_CONTENTS_CHANGED=false',
             'PERMISSION_RECEIPT_INVALID')
        report = json.loads(rows[1])
        need(report.get('release') == APP_RELEASE and report.get('private_or_source_contents_printed') is False
             and report.get('application_file_contents_changed', False) is False, 'PERMISSION_RECEIPT_INVALID')
        if value.returncode:
            for row in rows:
                print(row)
        else:
            need(report.get('repair', {}).get('blocked') is False, 'PERMISSION_RECEIPT_INVALID')
    need(value.returncode == 0 and bool(rows), 'PERMISSION_PREPARATION_STOPPED')


def setup(folder, revision):
    value = run(['/usr/bin/bash', str(folder / Path(SETUP).name), '--release', revision], timeout=240)
    if value.returncode:
        for line in value.stderr.decode('ascii', 'ignore').splitlines():
            if re.fullmatch(r'GO_ERP_LIVE_SETUP_[A-Z_]+', line):
                print(line, file=sys.stderr)
        raise Stop('GO_ERP_COMPLETE_WATCHER_SETUP_STOPPED')
    need(value.stdout.splitlines() == [b'GO_ERP_LIVE_UPDATES_TIMER_INSTALLED', b'INTERVAL_SECONDS=60',
        b'STATE=AWAITING_FIRST_ACCEPTED_BUILD'], 'WATCHER_RECEIPT_INVALID')
    for line in value.stdout.decode('ascii').splitlines():
        print(line)


def execute(classify, prepare, activate, postflight, install):
    state = classify()
    need(state in ('BEFORE', 'AFTER'), 'UNREVIEWED_SERVER_STATE_NO_APPLICATION_CHANGES')
    if state == 'BEFORE':
        prepare()
        need(classify() == 'BEFORE', 'SERVER_CHANGED_AFTER_PERMISSION_PREPARATION')
        activate()
        need(classify() == 'AFTER', 'ACTIVATED_SOURCE_NOT_EXACT')
    # Exact source alone does not prove enabled flags, schema or route caches.
    # Reuse the reviewed current PHP checks for both newly and already installed servers.
    postflight()
    need(classify() == 'AFTER', 'SERVER_CHANGED_DURING_POSTFLIGHT')
    install()
    return {'initial_state': state, 'activation_ran': state == 'BEFORE'}


def main():
    parser = argparse.ArgumentParser(description='Safely finish GO ERP activation and minute watcher setup from the root console.')
    parser.add_argument('--release', required=True)
    arguments = parser.parse_args(sys.argv[2:])
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    need(REVISION.fullmatch(arguments.release), 'PINNED_RELEASE_REQUIRED')
    actor = pwd.getpwnam('fasakha')
    need(ROOT.resolve() == ROOT, 'PROJECT_UNSAFE')
    for path in (ROOT, ROOT / '.git'):
        value = path.lstat()
        need(stat.S_ISDIR(value.st_mode) and value.st_uid == actor.pw_uid, 'PROJECT_UNSAFE')
    files = payload(arguments.release, Path(sys.argv[1]).absolute())
    manifest = json.loads(files[MANIFEST])
    scanner = namespace(files[SCANNER], 'reviewed_complete_scanner')
    source = namespace(files[SOURCE], 'reviewed_complete_source')
    operations = namespace(files[OPERATIONS], 'reviewed_complete_operations', shell=True)
    folder = Path(tempfile.mkdtemp(prefix='go-erp-complete-', dir='/tmp'))
    folder.chmod(0o700)
    try:
        stage_payload(folder, files)
        head = lambda: git('rev-parse', 'HEAD').decode('ascii').strip()
        result = execute(lambda: classifier(ROOT, manifest, scanner, source, head),
            lambda: repair(folder),
            lambda: operations['activate'](folder, APP_RELEASE, {'source': manifest['after_source']}),
            lambda: operations['postflight'](folder, {'source': manifest['after_source']}, actor.pw_uid, actor.pw_gid),
            lambda: setup(folder, arguments.release))
        print('GO_ERP_COMPLETE_SERVER_AND_TIMER_READY')
        print('SERVER_SOURCE=' + manifest['after_source']['sha256'])
        print('CHECK_INTERVAL_SECONDS=60')
        return 0
    finally:
        shutil.rmtree(folder)


if __name__ == '__main__':
    try:
        result = main()
    except Stop as error:
        print(str(error), file=sys.stderr)
        result = 1
    except Exception as error:
        value = str(error)
        print(value if re.fullmatch(r'GO_ERP_CI_[A-Z_]+', value)
              else 'GO_ERP_COMPLETE_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
        result = 1
    sys.exit(result)
PY
