#!/usr/bin/env python3
"""Refactor, step 0: which code does srv1836524 actually run? It only reads.

Compares every file of the live site (/home/fasakha/public_html, without vendor, node_modules, storage and the
framework's caches) with every branch and tag of the repository, and with every version each file ever had:
  - the branches the live code is closest to (counting only what the site runs: app, bootstrap, config, database,
    lang, public, resources, routes and the root's artisan, composer.json, index.php, server.php, .htaccess);
  - against the closest one, each file that differs: the same as another branch, a version from the history,
    line endings only, or never committed (changed on the server itself);
  - the repository's files missing on the server, and the server's files the repository does not have;
  - the platform: system, PHP, database, Composer, Laravel's caches, the scheduler, and the installed version of each
    library the site requires.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/compare_live_code_root.py           the report, each list cut at its first entries
  python3 -I -B deployment/compare_live_code_root.py --all     every entry of every list

The repository is read into a temporary folder (trees only, about 5 MB) that is removed at the end; nothing else is
written. It prints paths, versions, commit ids and counts: never a file's content or a hash of one, no password, no token.
"""
import argparse
import collections
import datetime
import glob
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import stat
import subprocess
import tempfile

HOST = 'srv1836524'
SITE = Path('/home/fasakha/public_html')
REPOSITORY = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
USERDATA = Path('/var/cpanel/userdata/fasakha')
CRONTAB = Path('/var/spool/cron/fasakha')
# Where .github/workflows/deploy.yml deploys main.
DEPLOY_TARGET = Path('/home/fasakhaninja/public_html')
# What the site runs. The rest of the repository (tests, docs, the desktop apps' sources, deployment) is counted apart.
RUNTIME_FOLDERS = (b'app/', b'bootstrap/', b'config/', b'database/', b'lang/', b'public/', b'resources/', b'routes/')
RUNTIME_FILES = (b'artisan', b'composer.json', b'index.php', b'server.php', b'.htaccess')
# Installed, rebuilt or written by the site: never compared.
SKIPPED = (b'vendor/', b'node_modules/', b'storage/', b'.git/', b'bootstrap/cache/')
CODE = (b'.php', b'.js', b'.mjs', b'.cjs', b'.css', b'.scss', b'.json', b'.lock', b'.htaccess', b'.sh', b'.py', b'.yml',
        b'.yaml', b'.xml', b'.html', b'.htm', b'.vue', b'.ts', b'.ini', b'.conf', b'.sql', b'.env')
LARGEST = 8 * 1048576
LARGEST_KNOWN = 64 * 1048576
LIMIT = 150
LARAVEL_CACHES = ('config.php', 'routes-v7.php', 'events.php', 'packages.php', 'services.php')


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_LIVE_CODE_COMPARE_' + reason)


def run(command, timeout=120, **options):
    """Never inherits stdin: the command runs inside a shell heredoc it must not read."""
    try:
        return subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              timeout=timeout, check=False, **options)
    except (OSError, subprocess.TimeoutExpired):
        return None


def git_environment():
    return dict(os.environ, GIT_TERMINAL_PROMPT='0', GIT_NO_LAZY_FETCH='1', GIT_OPTIONAL_LOCKS='0', LC_ALL='C')


def git(repository, *arguments, stdin=None):
    command = ['git', '--git-dir', str(repository)] + list(arguments)
    if stdin is None:
        result = run(command, timeout=900, env=git_environment())
    else:
        try:
            result = subprocess.run(command, input=stdin, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=900,
                                    check=False, env=git_environment())
        except (OSError, subprocess.TimeoutExpired):
            result = None
    need(result is not None and result.returncode == 0, 'GIT_FAILED: ' + arguments[0])
    return result.stdout


def text(value):
    return value.decode('utf-8', 'replace') if isinstance(value, bytes) else value


def day(stamp):
    return datetime.datetime.fromtimestamp(stamp, datetime.timezone.utc).strftime('%Y-%m-%d') if stamp else '?'


def short_name(ref):
    for prefix in ('refs/heads/', 'refs/tags/'):
        if ref.startswith(prefix):
            return ref[len(prefix):]
    return ref


def skipped(path):
    return path.startswith(SKIPPED)


def runtime(path):
    return path in RUNTIME_FILES or path.startswith(RUNTIME_FOLDERS)


def code_like(path):
    name = path.rsplit(b'/', 1)[-1]
    return name.endswith(CODE) or name.startswith(b'.env')


def blob_id(data):
    return hashlib.sha1(b'blob %d\0' % len(data) + data).hexdigest()


def fetch(url, folder):
    """Every branch and tag, trees only (a whole copy on a git without partial clones)."""
    repository = folder / 'repository.git'
    for extra, mode in ((['--filter=blob:none'], 'trees only'), ([], 'whole')):
        shutil.rmtree(str(repository), ignore_errors=True)
        result = run(['git', 'clone', '--quiet', '--bare'] + extra + [url, str(repository)], timeout=900, env=git_environment())
        if result is not None and result.returncode == 0:
            return repository, mode
    raise Stop('GO_ERP_LIVE_CODE_COMPARE_REPOSITORY_NOT_READABLE')


def history(repository):
    """Every version each path ever had, from every commit of every branch and tag: {path: {blob: (time, commit)}},
    each version with the newest commit that brought it."""
    times = {}
    for line in git(repository, 'rev-list', '--all', '--timestamp').splitlines():
        stamp, commit = line.decode().split()
        times[commit] = int(stamp)
    found = {}
    if not times:
        return found, times
    output = git(repository, 'diff-tree', '--stdin', '-m', '-r', '--root', '--no-renames', '--no-abbrev', '-z',
                 stdin=''.join(commit + '\n' for commit in times).encode())
    tokens = output.split(b'\0')
    commit, index = None, 0
    while index < len(tokens):
        token = tokens[index]
        if token.startswith(b':') and index + 1 < len(tokens):
            fields, path = token[1:].split(), tokens[index + 1]
            index += 2
            if len(fields) < 5 or fields[1] in (b'000000', b'160000'):
                continue
            blob, when = fields[3].decode(), times.get(commit, 0)
            versions = found.setdefault(path, {})
            if blob not in versions or versions[blob][0] < when:
                versions[blob] = (when, commit)
            continue
        if token.strip():
            commit = token.split()[0].decode()
        index += 1
    return found, times


def tree_entries(repository, tree, paths=()):
    """{path: blob} of a tree (submodules left out)."""
    entries = {}
    arguments = ['ls-tree', '-r', '-z', '--full-tree', tree]
    if paths:
        arguments += ['--'] + list(paths)
    for record in git(repository, *arguments).split(b'\0'):
        if not record:
            continue
        meta, _, path = record.partition(b'\t')
        fields = meta.split()
        if len(fields) == 3 and fields[1] in (b'blob',):
            entries[path] = fields[2].decode()
    return entries


def tips(repository):
    """Each branch and tag: [(name, commit, time, tree)], newest first."""
    refs = []
    for line in git(repository, 'for-each-ref', '--format=%(refname) %(objecttype) %(objectname) %(*objecttype) %(*objectname)',
                    'refs/heads', 'refs/tags').splitlines():
        fields = line.decode().split()
        if len(fields) == 3 and fields[1] == 'commit':
            refs.append((fields[0], fields[2]))
        elif len(fields) == 5 and fields[3] == 'commit':
            refs.append((fields[0], fields[4]))
    commits = sorted(set(commit for name, commit in refs))
    details = {}
    if commits:
        for line in git(repository, 'log', '--no-walk=unsorted', '--format=%H %T %ct', *commits).splitlines():
            commit, tree, stamp = line.decode().split()
            details[commit] = (tree, int(stamp))
    found = [(name, commit, details[commit][1], details[commit][0]) for name, commit in refs if commit in details]
    return sorted(found, key=lambda tip: (-tip[2], tip[0]))


def live_files(site, known):
    """{path: {'blob', 'size', 'lf'}} for every file of the site but the skipped folders. A file is read (and hashed)
    when the repository ever had its path, it is code or it sits at the site's root (up to 8 MB; 64 MB for a path the
    repository had); symbolic links are compared by where they point, as git keeps them, and never followed."""
    files = {}
    root = os.fsencode(str(site))
    for folder, folders, names in os.walk(root):
        relative = os.path.relpath(folder, root)
        prefix = b'' if relative == b'.' else relative + b'/'
        links = [name for name in folders if os.path.islink(os.path.join(folder, name))]
        folders[:] = sorted(name for name in folders if name not in links and not skipped(prefix + name + b'/'))
        for name in sorted(list(names) + links):
            path = prefix + name
            if skipped(path):
                continue
            full = os.path.join(folder, name)
            try:
                info = os.lstat(full)
            except OSError:
                continue
            entry = {'blob': None, 'size': info.st_size, 'lf': None}
            if stat.S_ISLNK(info.st_mode):
                entry['blob'] = blob_id(os.readlink(full))
            elif stat.S_ISREG(info.st_mode):
                if info.st_size <= (LARGEST_KNOWN if path in known else LARGEST if code_like(path) or b'/' not in path else -1):
                    try:
                        with open(full, 'rb') as stream:
                            data = stream.read()
                    except OSError:
                        data = None
                    if data is not None:
                        entry['blob'] = blob_id(data)
                        if b'\r\n' in data:
                            entry['lf'] = blob_id(data.replace(b'\r\n', b'\n'))
            else:
                continue
            files[path] = entry
    return files


def site_checkout(site):
    """Whether the site is itself a git checkout, and of which commit (never its remotes: they can hold a token)."""
    folder = site / '.git'
    if not folder.exists():
        return None
    base = ['git', '-c', 'safe.directory=*', '--git-dir', str(folder)]
    head = run(base + ['rev-parse', '--verify', '-q', 'HEAD'], env=git_environment())
    branch = run(base + ['symbolic-ref', '-q', '--short', 'HEAD'], env=git_environment())
    return {'head': text(head.stdout).strip() if head is not None and head.returncode == 0 else None,
            'branch': text(branch.stdout).strip() if branch is not None and branch.returncode == 0 else None}


def compare(site, url, folder):
    repository, mode = fetch(url, folder)
    versions, times = history(repository)
    every_blob = {}
    for path, found in versions.items():
        for blob, (when, commit) in found.items():
            if blob not in every_blob or every_blob[blob][0] < when:
                every_blob[blob] = (when, path)
    live = live_files(site, versions)
    checkout = site_checkout(site)
    candidates = tips(repository)
    if checkout and checkout['head'] and checkout['head'] in times:
        tree = text(git(repository, 'rev-parse', checkout['head'] + '^{tree}')).strip()
        candidates.append(('the site checkout (HEAD)', checkout['head'], times[checkout['head']], tree))
    same_path = {path: path for path in live}
    known_runtime_live = [path for path in live if runtime(path) and path in versions]
    pathspecs = [text(item).rstrip('/') for item in RUNTIME_FOLDERS + RUNTIME_FILES]
    # Which branches have exactly the live version of a file: {path: [(time, name)]}.
    holders = collections.defaultdict(list)
    scores, by_tree = [], {}
    for name, commit, when, tree in candidates:
        if tree not in by_tree:
            entries = tree_entries(repository, tree, pathspecs)
            same = differ = missing = 0
            matched = []
            for path, blob in entries.items():
                if skipped(path) or not runtime(path):
                    continue
                here = live.get(path)
                if here is None:
                    missing += 1
                elif here['blob'] == blob:
                    same += 1
                    matched.append(same_path[path])
                else:
                    differ += 1
            extra = sum(1 for path in known_runtime_live if path not in entries)
            by_tree[tree] = (same, differ, missing, extra, matched)
        same, differ, missing, extra, matched = by_tree[tree]
        for path in matched:
            holders[path].append((when, short_name(name)))
        scores.append({'name': short_name(name), 'commit': commit, 'time': when, 'same': same, 'differ': differ,
                       'missing': missing, 'extra': extra, 'distance': differ + missing + extra})
    need(scores, 'NO_BRANCH_FOUND')
    scores.sort(key=lambda score: (score['distance'], -score['time'], score['name']))
    closest = scores[0]
    entries = tree_entries(repository, closest['commit'] + '^{tree}')
    return {'mode': mode, 'branches': len(candidates), 'commits': len(times), 'checkout': checkout,
            'live_files': len(live), 'hashed': sum(1 for entry in live.values() if entry['blob']),
            'scores': scores, 'closest': closest,
            'details': details(live, entries, versions, every_blob, holders, closest['name'])}


def classify(path, here, versions, every_blob, holders, closest, branch_blob=None):
    """What the live version of a file is, compared with the repository: (kind, words, branch or None)."""
    blob = here['blob']
    if blob is None:
        return 'not read', 'not read (too large to compare)', None
    others = sorted((holder for holder in holders.get(path, ()) if holder[1] != closest), reverse=True)
    if others:
        count = len(set(name for when, name in others))
        return ("another branch's version", 'the version on branch {} ({}){}'.format(
            others[0][1], day(others[0][0]), ' and {} more'.format(count - 1) if count > 1 else ''), others[0][1])
    found = versions.get(path, {}).get(blob)
    if found:
        return 'a version from the history', 'a version from {} (commit {}), on no branch now'.format(day(found[0]), found[1][:10]), None
    if here['lf'] and branch_blob and here['lf'] == branch_blob:
        return 'the same with Windows line endings', 'the same but with Windows line endings', None
    found = versions.get(path, {}).get(here['lf']) if here['lf'] else None
    if found:
        return 'a version from the history', 'a version from {}, with Windows line endings'.format(day(found[0])), None
    if blob in every_blob:
        return ('the content of another file', 'the content of {} (from {})'.format(text(every_blob[blob][1]), day(every_blob[blob][0])),
                None)
    if path in versions:
        return 'a version never committed', 'a version never committed (changed on the server)', None
    return 'a file the repository never had', 'a file the repository never had', None


def group_of(path, depth=2):
    parts = path.split(b'/')
    return text(b'/'.join(parts[:depth]) + (b'/' if len(parts) > depth else b''))


def details(live, entries, versions, every_blob, holders, closest):
    differ, missing, extra = [], [], []
    kinds, branches = collections.Counter(), collections.Counter()
    for path, blob in sorted(entries.items()):
        if skipped(path) or not runtime(path):
            continue
        here = live.get(path)
        found = versions.get(path, {}).get(blob)
        branch_day = day(found[0]) if found else '?'
        if here is None:
            missing.append({'path': text(path), 'branch_version': branch_day})
        elif here['blob'] != blob:
            kind, words, holder = classify(path, here, versions, every_blob, holders, closest, blob)
            kinds[kind] += 1
            if holder:
                branches[holder] += 1
            differ.append({'path': text(path), 'kind': kind, 'live': words, 'branch_version': branch_day})
    groups = collections.Counter()
    for path in sorted(live):
        if path in entries or not runtime(path):
            continue
        if code_like(path) or path in versions:
            kind, words, holder = classify(path, live[path], versions, every_blob, holders, closest)
            extra.append({'path': text(path), 'kind': kind,
                          'live': ('removed from this branch; live: ' if path in versions else '') + words})
        else:
            groups[group_of(path)] += 1
    # Outside what the site runs: the repository's other folders and files, and the site's other files.
    outside = collections.OrderedDict()
    for path, blob in sorted(entries.items()):
        if runtime(path) or skipped(path) or b'/' not in path:
            continue
        row = outside.setdefault(group_of(path, 1), {'branch': 0, 'live': 0, 'same': 0, 'live_only': 0})
        row['branch'] += 1
        if path in live:
            row['live'] += 1
            row['same'] += live[path]['blob'] == blob
    root_files = []
    for path in sorted(set(path for path in entries if b'/' not in path) | set(path for path in live if b'/' not in path)):
        if runtime(path):
            continue
        if path not in live:
            root_files.append({'path': text(path), 'live': 'missing on live'})
        elif path in entries and live[path]['blob'] == entries[path]:
            root_files.append({'path': text(path), 'live': 'the same as this branch'})
        else:
            kind, words, holder = classify(path, live[path], versions, every_blob, holders, closest, entries.get(path))
            root_files.append({'path': text(path), 'live': ('' if path in entries else 'not on this branch; ') + words})
    for path in sorted(live):
        if runtime(path) or path in entries or b'/' not in path:
            continue
        row = outside.setdefault(group_of(path, 1), {'branch': 0, 'live': 0, 'same': 0, 'live_only': 0})
        row['live_only'] += 1
    return {'differ': differ, 'differ_kinds': kinds.most_common(), 'differ_branches': branches.most_common(15), 'missing': missing,
            'extra': extra, 'extra_kinds': collections.Counter(row['kind'] for row in extra).most_common(),
            'extra_groups': sorted(groups.items(), key=lambda item: (-item[1], item[0])),
            'outside': [dict(row, folder=folder) for folder, row in outside.items()], 'root_files': root_files}


def first_line(result):
    if result is None or result.returncode != 0:
        return None
    lines = text(result.stdout or result.stderr).strip().splitlines()
    return lines[0].strip() if lines else None


def libraries(site):
    """The libraries the site's composer.json asks for, with the version vendor has (names and versions only)."""
    try:
        wanted = json.loads((site / 'composer.json').read_text())
    except (OSError, ValueError):
        return None
    installed, dev = {}, None
    try:
        data = json.loads((site / 'vendor/composer/installed.json').read_text())
        packages = data.get('packages', []) if isinstance(data, dict) else data
        dev = data.get('dev') if isinstance(data, dict) else None
        for package in packages:
            installed[str(package.get('name', '')).lower()] = package.get('version')
    except (OSError, ValueError, AttributeError):
        pass
    rows = []
    for section in ('require', 'require-dev'):
        for name, constraint in sorted((wanted.get(section) or {}).items()):
            if name == 'php' or name.startswith('ext-'):
                continue
            rows.append({'name': name, 'wants': constraint, 'installed': installed.get(name.lower()), 'dev': section == 'require-dev'})
    return {'php_wanted': (wanted.get('require') or {}).get('php'), 'installed_count': len(installed), 'dev_installed': dev,
            'lock': (site / 'composer.lock').is_file(), 'rows': rows}


def platform(site, php):
    found = collections.OrderedDict()
    try:
        release = dict(line.split('=', 1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
        found['system'] = release.get('PRETTY_NAME', '').strip('"') or None
    except OSError:
        found['system'] = None
    found['php_installed'] = sorted(path.split('/')[3] for path in glob.glob('/opt/cpanel/ea-php*/root/usr/bin/php'))
    sites = []
    if USERDATA.is_dir():
        for path in sorted(USERDATA.iterdir()):
            if path.is_file() and not path.name.endswith(('.cache', '.json', '.yaml')) and path.name not in ('cache', 'main'):
                try:
                    for line in path.read_text(errors='replace').splitlines():
                        if line.startswith('phpversion:'):
                            sites.append(path.name + ' ' + line.split(':', 1)[1].strip())
                except OSError:
                    pass
    found['php_per_domain'] = sites
    found['php'] = first_line(run([php, '-r', 'echo PHP_VERSION;'])) if os.access(php, os.X_OK) else None
    modules = run([php, '-m']) if os.access(php, os.X_OK) else None
    if modules is not None and modules.returncode == 0:
        names = [line.strip() for line in text(modules.stdout).splitlines() if line.strip() and not line.startswith('[')]
        found['php_modules'] = ', '.join(sorted(set(names), key=str.lower))
    found['database'] = first_line(run(['mysql', '--version']))
    found['git'] = first_line(run(['git', '--version']))
    composer = next((path for path in (shutil.which('composer'), '/opt/cpanel/composer/bin/composer', '/usr/local/bin/composer')
                     if path and os.access(path, os.X_OK)), None)
    found['composer'] = first_line(run([composer, '--version', '--no-ansi'], cwd='/', env=dict(os.environ, COMPOSER_ALLOW_SUPERUSER='1'))) if composer else None
    found['node'] = first_line(run(['node', '--version'])) if shutil.which('node') else None
    found['laravel_caches'] = [name for name in LARAVEL_CACHES if (site / 'bootstrap/cache' / name).is_file()]
    try:
        cron = CRONTAB.read_text(errors='replace').splitlines()
        found['scheduler'] = 'yes' if any('schedule:run' in line and not line.lstrip().startswith('#') for line in cron) else 'no'
    except OSError:
        found['scheduler'] = 'no crontab'
    workers = run(['ps', '-eo', 'args'])
    found['queue_workers'] = sum(1 for line in text(workers.stdout).splitlines() if 'queue:work' in line or 'horizon' in line) if workers else None
    found['deploy_target_exists'] = DEPLOY_TARGET.exists()
    found['libraries'] = libraries(site)
    return found


def cut(rows, limit):
    return rows if limit is None else rows[:limit]


def report(result, found, limit):
    lines = ['GO_ERP_LIVE_CODE_COMPARE']
    lines.append('repository: {} branches and tags, {} commits ({})'.format(result['branches'], result['commits'], result['mode']))
    checkout = result['checkout']
    if checkout is None:
        lines.append('site: not a git checkout')
    else:
        lines.append('site: a git checkout, HEAD {} on {}'.format((checkout['head'] or '?')[:12], checkout['branch'] or 'no branch'))
    lines.append('deploy.yml target /home/fasakhaninja/public_html: {}'.format('exists' if found.get('deploy_target_exists') else 'missing'))
    lines.append('live files: {} ({} read); vendor, node_modules, storage, .git, bootstrap/cache not compared'.format(
        result['live_files'], result['hashed']))
    lines.append('')
    lines.append('== closest branches (site code only: differ + missing on live + extra on live) ==')
    for score in result['scores'][:12]:
        lines.append('{:>5}  {}  {}  {}  same={} differ={} missing={} extra={}'.format(
            score['distance'], day(score['time']), score['commit'][:10], score['name'], score['same'], score['differ'],
            score['missing'], score['extra']))
    detail = result['details']
    closest = result['closest']['name']
    lines.append('')
    lines.append('== live files that differ from {} ({}) =='.format(closest, len(detail['differ'])))
    for kind, count in detail['differ_kinds']:
        lines.append('  live is {}: {}'.format(kind, count))
    for name, count in detail['differ_branches']:
        lines.append('    of them the version on branch {}: {}'.format(name, count))
    for row in cut(detail['differ'], limit):
        lines.append('{}  | live: {} | branch: {}'.format(row['path'], row['live'], row['branch_version']))
    if limit is not None and len(detail['differ']) > limit:
        lines.append('... {} more (--all lists them)'.format(len(detail['differ']) - limit))
    lines.append('')
    lines.append('== on {} but missing on live ({}) =='.format(closest, len(detail['missing'])))
    for row in cut(detail['missing'], limit):
        lines.append('{}  | branch: {}'.format(row['path'], row['branch_version']))
    if limit is not None and len(detail['missing']) > limit:
        lines.append('... {} more (--all lists them)'.format(len(detail['missing']) - limit))
    lines.append('')
    lines.append('== site code on live that {} does not have ({} code files, other files by folder) =='.format(closest, len(detail['extra'])))
    for kind, count in detail['extra_kinds']:
        lines.append('  live is {}: {}'.format(kind, count))
    for row in cut(detail['extra'], limit):
        lines.append('{}  | {}'.format(row['path'], row['live']))
    if limit is not None and len(detail['extra']) > limit:
        lines.append('... {} more (--all lists them)'.format(len(detail['extra']) - limit))
    for group, count in cut(detail['extra_groups'], 40 if limit is not None else None):
        lines.append('  {}: {} files'.format(group, count))
    lines.append('')
    lines.append('== outside the site code (folder: on the branch / of them on live / same | only on live) ==')
    for row in detail['outside']:
        lines.append('  {}: {} / {} / {} | {}'.format(row['folder'], row['branch'], row['live'], row['same'], row['live_only']))
    for row in detail['root_files']:
        lines.append('  {}  | {}'.format(row['path'], row['live']))
    lines.append('')
    lines.append('== platform ==')
    for key, value in found.items():
        if key in ('libraries', 'deploy_target_exists'):
            continue
        if isinstance(value, list):
            value = ', '.join(value) if value else 'none'
        lines.append('{}: {}'.format(key, value if value not in (None, '') else 'unknown'))
    libraries_found = found.get('libraries')
    if libraries_found:
        lines.append('composer.json php: {}; composer.lock on live: {}; installed packages: {}; dev packages installed: {}'.format(
            libraries_found['php_wanted'], 'yes' if libraries_found['lock'] else 'no', libraries_found['installed_count'],
            libraries_found['dev_installed']))
        for row in libraries_found['rows']:
            lines.append('  {}{}  wants {}  installed {}'.format(row['name'], ' (dev)' if row['dev'] else '', row['wants'],
                                                             row['installed'] or 'no'))
    return '\n'.join(lines)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--all', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need((SITE / 'artisan').is_file(), 'SITE_NOT_FOUND')
    os.umask(0o077)
    try:
        os.nice(10)
    except OSError:
        pass
    folder = Path(tempfile.mkdtemp(prefix='go-erp-live-compare-', dir='/var/tmp'))
    try:
        result = compare(SITE, REPOSITORY, folder)
    finally:
        shutil.rmtree(str(folder), ignore_errors=True)
    print(report(result, platform(SITE, PHP), None if options.all else LIMIT), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if isinstance(error, Stop) and message.startswith('GO_ERP_')
              else 'GO_ERP_LIVE_CODE_COMPARE_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
