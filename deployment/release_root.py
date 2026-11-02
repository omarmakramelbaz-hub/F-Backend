#!/usr/bin/env python3
"""Ships one refactor release to srv1836524: the site files that changed between the commit the server runs and
this checkout's commit, every one of them guarded.

Run as root on srv1836524 from a clean checkout of the release commit (the commit the server runs fetched too):
  python3 -I -B deployment/release_root.py --from <commit the server runs>            checks only, changes nothing
  python3 -I -B deployment/release_root.py --from <commit the server runs> --apply    installs the release
  python3 -I -B deployment/release_root.py --rollback                                 puts the last release's files back

It ships only what the site runs (app, bootstrap, config, lang, public, resources, routes and the root's artisan,
index.php, server.php, .htaccess) and refuses a release that needs more than files: database migrations, the
libraries (composer.json, composer.lock), .env. --apply also refuses:
  - a live file that is not the version the server should run (changed on the server since), unless it already is
    this release's version (Windows line endings on the server are accepted as the same file);
  - PHP that does not parse (each changed PHP file is checked with the site's PHP before anything is written).
Every file it replaces or removes is kept in /var/lib/go-erp-releases (root only); new files are written atomically,
keeping each file's owner and mode; compiled views are cleared (and the configuration cached again when it changed);
then the site must answer, or every previous file is put back by itself.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

HOST = 'srv1836524'
WEB_USER = 'fasakha'
SITE = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BACKUPS = Path('/var/lib/go-erp-releases')
ORIGIN = 'https://fasakhaninja.com'
PROBES = (('/admin/login', 200), ('/', 200))
PACKAGE = Path(__file__).resolve().parent.parent
SHIPPED_FOLDERS = ('app/', 'bootstrap/', 'config/', 'lang/', 'public/', 'resources/', 'routes/')
SHIPPED_FILES = ('artisan', 'index.php', 'server.php', '.htaccess')
# A release of files cannot ship these: they need their own guarded step.
REFUSED = ('database/', 'composer.json', 'composer.lock', '.env', 'vendor/', 'storage/', 'bootstrap/cache/')


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_RELEASE_' + reason)


def sha256(data):
    return hashlib.sha256(data).hexdigest()


# What touches the machine; the tests replace these. -----------------------------------------------------------------

def command(arguments, user=None, cwd=None, timeout=300):
    """(exit code, output); as the site user when given; stdin never inherited (this runs inside a shell heredoc)."""
    arguments = [str(item) for item in arguments]
    if user is not None:
        info = pwd.getpwnam(user)
        if info.pw_uid != os.geteuid():
            arguments = ['runuser', '-u', user, '--', 'env', 'HOME=' + info.pw_dir] + arguments
    try:
        result = subprocess.run(arguments, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                cwd=None if cwd is None else str(cwd), timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return 127, ''
    return result.returncode, result.stdout.decode('utf-8', 'replace')


def answers(path, wanted, attempts=5):
    """Whether a page answers with the wanted status (redirects not followed, nothing of it kept)."""
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *arguments, **options):
            return None
    for attempt in range(attempts):
        request = urllib.request.Request(ORIGIN + path + ('&' if '?' in path else '?') + 'go_erp_release=' + str(time.time()),
                                         headers={'User-Agent': 'go-erp-release'})
        try:
            with urllib.request.build_opener(NoRedirect).open(request, timeout=20) as response:
                status = response.status
        except urllib.error.HTTPError as error:
            status = error.code
        except (urllib.error.URLError, OSError):
            status = None
        if status == wanted:
            return True
        if attempt + 1 < attempts:
            time.sleep(2)
    return False


# The release, read from git -----------------------------------------------------------------------------------------

def git(*arguments):
    code, output = command(['git', '-C', str(PACKAGE), '-c', 'core.quotepath=off'] + list(arguments))
    need(code == 0, 'GIT_FAILED: ' + arguments[0])
    return output


def blob(commit, path):
    result = subprocess.run(['git', '-C', str(PACKAGE), 'show', commit + ':' + path], stdin=subprocess.DEVNULL,
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    need(result.returncode == 0, 'NOT_IN_COMMIT: ' + path)
    return result.stdout


def shipped(path):
    return path in SHIPPED_FILES or path.startswith(SHIPPED_FOLDERS)


def refused(path):
    return path in REFUSED or path.startswith(tuple(item for item in REFUSED if item.endswith('/')))


def release(base):
    """{path: (action, git mode)} of the shipped files that differ between the server's commit and this checkout."""
    need(re.match(r'^[0-9a-f]{40}$', base or ''), 'FROM_MUST_BE_A_FULL_COMMIT')
    target = git('rev-parse', 'HEAD').strip()
    need(command(['git', '-C', str(PACKAGE), 'cat-file', '-e', base + '^{commit}'])[0] == 0, 'FROM_COMMIT_NOT_FETCHED')
    changes, blocked = {}, []
    raw = subprocess.run(['git', '-C', str(PACKAGE), 'diff', '--raw', '--no-renames', '--no-abbrev', '-z', base, target],
                         stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    need(raw.returncode == 0, 'GIT_FAILED: diff')
    tokens = raw.stdout.split(b'\0')
    for index in range(0, len(tokens) - 1, 2):
        meta, path = tokens[index].decode(), tokens[index + 1].decode('utf-8', 'surrogateescape')
        if not meta.startswith(':'):
            continue
        old_mode, new_mode, _, _, status = meta[1:].split()
        if refused(path):
            blocked.append(path)
        elif shipped(path):
            need('120000' not in (old_mode, new_mode) and '160000' not in (old_mode, new_mode), 'LINKS_NOT_SHIPPED: ' + path)
            changes[path] = ({'A': 'add', 'D': 'delete'}.get(status[0], 'modify'), new_mode)
    need(not blocked, 'NEEDS_MORE_THAN_FILES: ' + ', '.join(sorted(blocked)[:20]))
    return target, changes


# The live files -----------------------------------------------------------------------------------------------------

def live(site, path):
    full = site / path
    try:
        info = full.lstat()
    except FileNotFoundError:
        return None, None
    need(stat.S_ISREG(info.st_mode), 'LIVE_NOT_A_FILE: ' + path)
    return full.read_bytes(), info


def same(data, wanted):
    """The same file, also when the server keeps it with Windows line endings."""
    return data == wanted or (data is not None and wanted is not None and data.replace(b'\r\n', b'\n') == wanted)


def states(site, base, target, changes):
    """Where each live file stands: 'base' (ready), 'release' (already this release's) or 'changed-on-server'."""
    found = {}
    for path, (action, mode) in sorted(changes.items()):
        data, info = live(site, path)
        before = blob(base, path) if action != 'add' else None
        after = blob(target, path) if action != 'delete' else None
        if action == 'delete':
            found[path] = 'release' if data is None else ('base' if same(data, before) else 'changed-on-server')
        elif action == 'add':
            found[path] = 'base' if data is None else ('release' if same(data, after) else 'changed-on-server')
        else:
            found[path] = 'release' if same(data, after) else ('base' if same(data, before) else 'changed-on-server')
    return found


def lint(target, changes, php):
    """Each changed PHP file must parse, before anything is written."""
    broken = []
    with tempfile.TemporaryDirectory(prefix='go-erp-release-lint-') as folder:
        for index, (path, (action, mode)) in enumerate(sorted(changes.items())):
            if action == 'delete' or not path.endswith('.php'):
                continue
            copy = Path(folder) / ('file-%d.php' % index)
            copy.write_bytes(blob(target, path))
            if command([php, '-n', '-l', copy])[0] != 0:
                broken.append(path)
    need(not broken, 'PHP_DOES_NOT_PARSE: ' + ', '.join(broken))


def write_atomic(path, data, uid, gid, mode):
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-release-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fchown(output.fileno(), uid, gid)
            os.fchmod(output.fileno(), mode)
            os.fsync(output.fileno())
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def folders_for(site, path, created):
    """Creates the missing folders of a new file, owned like the nearest existing one; remembers them."""
    missing, folder = [], (site / path).parent
    while not folder.exists():
        missing.append(folder)
        folder = folder.parent
    info = folder.lstat()
    for item in reversed(missing):
        item.mkdir()
        os.chown(str(item), info.st_uid, info.st_gid)
        os.chmod(str(item), 0o755)
        created.append(str(item.relative_to(site)))
    return info


def owner_for_new(site, path, mode):
    """A new file takes the owner of its neighbours (else of its folder); 0755 when git marks it executable."""
    folder = (site / path).parent
    for sibling in sorted(folder.iterdir()):
        if sibling.is_file() and not sibling.is_symlink():
            info = sibling.lstat()
            return info.st_uid, info.st_gid, 0o755 if mode == '100755' else 0o644
    info = folder.lstat()
    return info.st_uid, info.st_gid, 0o755 if mode == '100755' else 0o644


def caches(site, php, paths):
    """What the site must rebuild after files changed: compiled views always, the configuration when it changed."""
    steps = ['view:clear']
    if any(path.startswith('config/') for path in paths) and (site / 'bootstrap/cache/config.php').exists():
        steps.append('config:cache')
    for step in steps:
        need(command([php, 'artisan', step], user=WEB_USER, cwd=site)[0] == 0, 'ARTISAN_FAILED: ' + step)
    return steps


def served():
    return all(answers(path, status) for path, status in PROBES)


# Plan, apply, rollback ----------------------------------------------------------------------------------------------

def plan(site, base):
    target, changes = release(base)
    found = states(site, base, target, changes)
    counts = {}
    for path, (action, mode) in changes.items():
        counts[action] = counts.get(action, 0) + 1
    return {'result': 'GO_ERP_RELEASE_PLAN', 'from': base[:12], 'to': target[:12], 'counts': counts,
            'ready': all(state in ('base', 'release') for state in found.values()),
            'changed_on_server': sorted(path for path, state in found.items() if state == 'changed-on-server'),
            'files': {path: '{} ({})'.format(changes[path][0], state) for path, state in sorted(found.items())[:150]}}


def backups_folder(backups):
    if not backups.exists():
        backups.mkdir(mode=0o700, parents=True)
    info = backups.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid(), 'BACKUP_FOLDER')
    os.chmod(str(backups), 0o700)
    folder = backups / datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S-%f')
    folder.mkdir(mode=0o700)
    return folder


def restore(site, saved):
    """Puts back what one release replaced or removed, removes what it added, then the folders it created."""
    for path, entry in saved['files'].items():
        full = site / path
        if entry['existed']:
            write_atomic(full, (Path(saved['folder']) / entry['copy']).read_bytes(), entry['uid'], entry['gid'], entry['mode'])
        elif full.exists():
            full.unlink()
    for folder in sorted(saved.get('created', []), key=len, reverse=True):
        try:
            (site / folder).rmdir()
        except OSError:
            pass


def apply(site, base, backups, php):
    target, changes = release(base)
    need(changes, 'NOTHING_TO_SHIP')
    found = states(site, base, target, changes)
    drift = sorted(path for path, state in found.items() if state == 'changed-on-server')
    need(not drift, 'LIVE_FILES_CHANGED_ON_SERVER: ' + ', '.join(drift[:20]))
    todo = {path: changes[path] for path, state in found.items() if state == 'base'}
    if not todo:
        return {'result': 'GO_ERP_RELEASE_ALREADY_INSTALLED', 'to': target[:12]}
    lint(target, todo, php)
    folder = backups_folder(backups)
    saved = {'folder': str(folder), 'from': base, 'to': target, 'files': {}, 'created': []}
    for index, path in enumerate(sorted(todo)):
        data, info = live(site, path)
        if data is None:
            saved['files'][path] = {'existed': False}
            continue
        copy = 'file-%d' % index
        fd = os.open(str(folder / copy), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
        saved['files'][path] = {'existed': True, 'copy': copy, 'uid': info.st_uid, 'gid': info.st_gid,
                                'mode': stat.S_IMODE(info.st_mode), 'sha256': sha256(data)}
    (folder / 'manifest.json').write_text(json.dumps(saved, sort_keys=True))
    # Everything the release touches is kept before anything changes.
    try:
        for path, (action, mode) in sorted(todo.items()):
            full = site / path
            entry = saved['files'][path]
            if action == 'delete':
                full.unlink()
                continue
            if entry['existed']:
                uid, gid, file_mode = entry['uid'], entry['gid'], entry['mode']
            else:
                folders_for(site, path, saved['created'])
                uid, gid, file_mode = owner_for_new(site, path, mode)
            write_atomic(full, blob(target, path), uid, gid, file_mode)
        (folder / 'manifest.json').write_text(json.dumps(saved, sort_keys=True))
        steps = caches(site, php, todo)
        need(served(), 'SITE_NOT_ANSWERING_PREVIOUS_FILES_RESTORED')
    except Exception:
        restore(site, saved)
        try:
            caches(site, php, todo)
        except Exception:  # noqa: BLE001 - the original failure is the one to report
            pass
        (folder / 'manifest.json').write_text(json.dumps(dict(saved, rolled_back=True), sort_keys=True))
        raise
    counts = {}
    for path, (action, mode) in todo.items():
        counts[action] = counts.get(action, 0) + 1
    return {'result': 'GO_ERP_RELEASE_INSTALLED', 'from': base[:12], 'to': target[:12], 'counts': counts, 'rebuilt': steps,
            'backup': str(folder)}


def rollback(site, backups, php):
    sets = sorted(item for item in backups.iterdir() if (item / 'manifest.json').is_file()) if backups.exists() else []
    sets = [item for item in sets if not json.loads((item / 'manifest.json').read_text()).get('rolled_back')]
    need(sets, 'NOTHING_TO_ROLL_BACK')
    saved = json.loads((sets[-1] / 'manifest.json').read_text())
    for path, entry in saved['files'].items():
        data, info = live(site, path)
        expected = None
        if data is not None:
            code = subprocess.run(['git', '-C', str(PACKAGE), 'cat-file', '-e', saved['to'] + ':' + path], stdin=subprocess.DEVNULL,
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False).returncode
            expected = blob(saved['to'], path) if code == 0 else None
        need(data is None or same(data, expected), 'CHANGED_SINCE_THE_RELEASE: ' + path)
    restore(site, saved)
    caches(site, php, saved['files'])
    saved['rolled_back'] = True
    (sets[-1] / 'manifest.json').write_text(json.dumps(saved, sort_keys=True))
    return {'result': 'GO_ERP_RELEASE_ROLLED_BACK', 'release': saved['to'][:12], 'answers': served()}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--from', dest='base')
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--rollback', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need((SITE / 'artisan').is_file() and os.access(PHP, os.X_OK), 'SITE_NOT_FOUND')
    os.umask(0o077)
    if options.rollback:
        result = rollback(SITE, BACKUPS, PHP)
    else:
        need(options.base, 'FROM_REQUIRED')
        result = apply(SITE, options.base, BACKUPS, PHP) if options.apply else plan(SITE, options.base)
    print(json.dumps(result, sort_keys=True, ensure_ascii=False), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Stop as error:
        print(str(error), flush=True)
        raise SystemExit(1)
    except Exception as error:  # noqa: BLE001
        print('GO_ERP_RELEASE_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
