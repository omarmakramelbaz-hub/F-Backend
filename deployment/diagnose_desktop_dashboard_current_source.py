#!/usr/bin/env python3
"""Read exact current source identities without PHP, SQL or application writes."""
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys

ROOT = Path('/home/fasakha/public_html')
PINS = {
    'desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
    'desktop-actual-server-baseline.json': 'cdb15f27e1365d188e908b983a8a78a27322505c',
    'desktop_dashboard_server_manifest.json': '35827afdfa613ebd944847062c2cd5c9b3c96c56',
    'desktop_dashboard_publication_metadata_scope.json': 'cf11abae30d20db225fbb08be34bf86d9538b0ae',
}
LIMIT = 64 * 1024 * 1024
RELATED_GUI = ('public/js/dashboard-meta-inbox.js', 'public/js/dashboard-meta-replies.js',
               'public/css/dashboard-meta-inbox.css')
ADDITIONAL_GUI_DIRECTORIES = (('public/js', '.js'), ('public/css', '.css'))


class DiagnosisError(Exception):
    pass


def release_argument(arguments):
    if len(arguments) != 3 or arguments[1] != '--release' or not re.fullmatch('[a-f0-9]{40}', arguments[2]):
        raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_REQUIRES_PINNED_RELEASE')
    return arguments[2]


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def public_metadata(value):
    return {'uid': value.st_uid, 'gid': value.st_gid,
            'mode': format(stat.S_IMODE(value.st_mode), '04o'), 'links': value.st_nlink,
            'regular': stat.S_ISREG(value.st_mode)}


def open_directory(parent_fd, name):
    before = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
    fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent_fd)
    if identity(os.fstat(fd))[:5] != identity(before)[:5]:
        os.close(fd)
        raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_DIRECTORY_CHANGED')
    return fd


def open_root(root):
    if not root.is_absolute() or '..' in root.parts:
        raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_ROOT_INVALID')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for name in root.parts[1:]:
            following = open_directory(fd, name)
            os.close(fd)
            fd = following
        return fd
    except BaseException:
        os.close(fd)
        raise


def read_file(parent_fd, name, maximum):
    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent_fd)
    try:
        before = os.fstat(fd)
        if (not stat.S_ISREG(before.st_mode) or before.st_size > maximum
                or maximum < 0):
            raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_FILE_UNSAFE')
        chunks = bytearray()
        while len(chunks) <= maximum:
            data = os.read(fd, min(65536, maximum + 1 - len(chunks)))
            if not data:
                break
            chunks.extend(data)
        after = os.fstat(fd)
        named = os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
        if (len(chunks) > maximum or identity(before) != identity(after)
                or identity(after) != identity(named)):
            raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_FILE_CHANGED')
        return bytes(chunks), after
    finally:
        os.close(fd)


def path_value(root_fd, relative, source, content=True):
    path = Path(relative)
    if path.is_absolute() or '..' in path.parts or not source['SAFE'].fullmatch(relative):
        raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_PATH_INVALID')
    fd = os.dup(root_fd)
    try:
        try:
            for name in path.parts[:-1]:
                following = open_directory(fd, name)
                os.close(fd)
                fd = following
            if content:
                data, item = read_file(fd, path.name, 8 * 1024 * 1024)
                return source['record'](data), identity(item)
            item = os.stat(path.name, dir_fd=fd, follow_symlinks=False)
            return public_metadata(item), identity(item)
        except FileNotFoundError:
            return None, None
    finally:
        os.close(fd)


def source_map(root_fd, source):
    files, metadata, cases = {}, {}, set()
    total = 0

    def visit(fd, relative):
        nonlocal total
        for name in sorted(os.listdir(fd)):
            path = relative + '/' + name
            # Skip private credentials and Laravel caches before opening them.
            if path.startswith('bootstrap/cache/') or source['SECRET'].search(path):
                continue
            if not source['SAFE'].fullmatch(path):
                raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_PATH_INVALID')
            item = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if stat.S_ISLNK(item.st_mode):
                raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_LINK')
            if stat.S_ISDIR(item.st_mode):
                if path == 'bootstrap/cache':
                    continue
                child = open_directory(fd, name)
                try:
                    visit(child, path)
                finally:
                    os.close(child)
            elif source['included'](path):
                if path.lower() in cases or len(files) >= 20000:
                    raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_SOURCE_LIMIT')
                data, checked = read_file(fd, name, LIMIT - total)
                total += len(data)
                cases.add(path.lower())
                files[path] = source['record'](data)
                metadata[path] = identity(checked)

    for relative in source['ROOTS']:
        fd = os.dup(root_fd)
        try:
            try:
                for name in Path(relative).parts:
                    following = open_directory(fd, name)
                    os.close(fd)
                    fd = following
            except FileNotFoundError:
                continue
            visit(fd, relative)
        finally:
            os.close(fd)
    try:
        data, item = read_file(root_fd, 'artisan', LIMIT - total)
        files['artisan'] = source['record'](data)
        metadata['artisan'] = identity(item)
    except FileNotFoundError:
        pass
    if not files:
        raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_SOURCE_EMPTY')
    return files, metadata


def additional_dashboard_gui(root_fd, source):
    """Inventory only immediate dashboard assets in the two public directories."""
    files, directories, cases = {}, {}, set()
    total = 0
    for relative, suffix in ADDITIONAL_GUI_DIRECTORIES:
        fd = os.dup(root_fd)
        try:
            try:
                for name in Path(relative).parts:
                    following = open_directory(fd, name)
                    os.close(fd)
                    fd = following
            except FileNotFoundError:
                directories[relative] = None
                continue
            before = identity(os.fstat(fd))
            for name in sorted(os.listdir(fd)):
                path = relative + '/' + name
                # Exclude private names before any stat/open and never recurse.
                if source['SECRET'].search(path):
                    continue
                if not (name.startswith('dashboard-') and name.endswith(suffix)):
                    continue
                if not source['SAFE'].fullmatch(path):
                    raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_PATH_INVALID')
                if path.lower() in cases or len(files) >= 20000:
                    raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_SOURCE_LIMIT')
                item = os.stat(name, dir_fd=fd, follow_symlinks=False)
                if stat.S_ISLNK(item.st_mode):
                    raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_LINK')
                data, checked = read_file(fd, name, min(8 * 1024 * 1024, LIMIT - total))
                total += len(data)
                cases.add(path.lower())
                files[path] = (source['record'](data), identity(checked))
            if identity(os.fstat(fd)) != before:
                raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_DIRECTORY_CHANGED')
            directories[relative] = before
        finally:
            os.close(fd)
    return files, directories


def snapshot(root, source, gui, rows, head_reader):
    fd = open_root(root)
    original_root = identity(os.fstat(fd))[:5]
    try:
        head = head_reader()
        files, metadata = source_map(fd, source)
        gui_values = {name: path_value(fd, name, source) for name in gui}
        additional_gui, additional_gui_directories = additional_dashboard_gui(fd, source)
        scope = {row['path']: path_value(fd, row['path'], source, content=False) for row in rows}
        fresh = open_root(root)
        try:
            if identity(os.fstat(fresh))[:5] != original_root or head_reader() != head:
                raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_ROOT_OR_HEAD_CHANGED')
        finally:
            os.close(fresh)
        return {'head': head, 'files': files, 'metadata': metadata,
                'gui': gui_values, 'scope': scope, 'additional_dashboard_gui': additional_gui,
                'additional_dashboard_gui_directories': additional_gui_directories}
    finally:
        os.close(fd)


def report(before, after, source, fixture, manifest, scope, release=None):
    if before != after:
        raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_SNAPSHOT_CHANGED')
    changed = []
    for path in sorted(fixture['files'].keys() | after['files'].keys()):
        original, actual = fixture['files'].get(path), after['files'].get(path)
        if original == actual:
            continue
        kind = ('added' if original is None else 'removed' if actual is None else
                'raw_only' if original['sha256'] == actual['sha256'] else 'normalized_changed')
        item = after['metadata'].get(path)
        changed.append({'path': path, 'change': kind, 'expected': original, 'actual': actual,
                        'metadata': None if item is None else {
                            'uid': item[3], 'gid': item[4], 'mode': format(stat.S_IMODE(item[2]), '04o'),
                            'links': item[5], 'regular': stat.S_ISREG(item[2])}})
    gui = [{'path': path, 'expected': pin, 'actual': value[0]['blob'] if value[0] else None,
            'metadata': None if value[1] is None else {
                'uid': value[1][3], 'gid': value[1][4],
                'mode': format(stat.S_IMODE(value[1][2]), '04o'), 'links': value[1][5],
                'regular': stat.S_ISREG(value[1][2])}}
           for path, pin in fixture['gui'].items() for value in [after['gui'][path]]]
    related_gui = [{'path': path, 'actual': value[0]['blob'] if value[0] else None,
                    'metadata': None if value[1] is None else {
                        'uid': value[1][3], 'gid': value[1][4],
                        'mode': format(stat.S_IMODE(value[1][2]), '04o'), 'links': value[1][5]}}
                   for path in RELATED_GUI if path in after['gui'] for value in [after['gui'][path]]]
    additional_gui = [{'path': path, 'actual': value[0],
                       'metadata': {'uid': value[1][3], 'gid': value[1][4],
                                    'mode': format(stat.S_IMODE(value[1][2]), '04o'),
                                    'links': value[1][5], 'regular': stat.S_ISREG(value[1][2])}}
                      for path, value in sorted(after.get('additional_dashboard_gui', {}).items())]
    states = {'original': 0, 'repaired': 0, 'unreviewed': 0, 'absent': 0}
    unusual = []
    for row in scope['rows']:
        value, _ = after['scope'][row['path']]
        current = [value['uid'], value['gid'], value['mode']] if value else None
        kind = ('absent' if value is None else
                'unreviewed' if not value['regular'] or value['links'] != 1 else
                'repaired' if current == row['after'] else
                'original' if current == row['before'] else 'unreviewed')
        states[kind] += 1
        if kind in ('unreviewed', 'absent'):
            unusual.append({'path': row['path'], 'metadata': value})
    actual_source = {**source['digest'](after['files']), 'framework': '8.83.29'}
    return {'kind': 'desktop-current-baseline-read-only-diagnosis', 'release': release,
            'actual_head': after['head'], 'expected_head': fixture['expected_head'],
            'actual_source': actual_source, 'expected_source': fixture['source'],
            'source_matches_before': actual_source == fixture['source'],
            'source_matches_release': actual_source == manifest['after_source'],
            'source_difference_count': len(changed), 'source_differences': changed,
            'source_differences_truncated': False, 'gui': gui,
            'related_meta_gui': related_gui,
            'additional_dashboard_gui': additional_gui,
            'publication_metadata_states': states, 'unreviewed_metadata': unusual,
            'deployment_performed': False, 'metadata_changed': False,
            'php_bootstrapped': False, 'database_read': False, 'source_content_printed': False}


def main():
    try:
        import hashlib
        uid = pwd.getpwnam('fasakha').pw_uid
        if os.geteuid() != uid:
            raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_RUN_AS_FASAKHA')
        release = release_argument(sys.argv)
        private = Path(__file__).absolute().parent
        payload = {}
        for name, pin in PINS.items():
            fd = open_root(private)
            try:
                data, item = read_file(fd, name, 2 * 1024 * 1024)
            finally:
                os.close(fd)
            actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
            if actual != pin or item.st_uid not in (0, uid) or item.st_mode & 0o022:
                raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_PAYLOAD_PIN_INVALID')
            payload[name] = data
        source = {'__name__': 'desktop_diagnosis_source_functions'}
        exec(compile(payload['desktop_dashboard_server_preflight.py'], 'pinned-source-functions', 'exec'), source)
        fixture = json.loads(payload['desktop-actual-server-baseline.json'])
        manifest = json.loads(payload['desktop_dashboard_server_manifest.json'])
        scope = json.loads(payload['desktop_dashboard_publication_metadata_scope.json'])
        if ({**source['digest'](fixture['files']), 'framework': '8.83.29'} != manifest['before_source']
                or fixture['source'] != manifest['before_source']
                or fixture['expected_head'] != manifest['expected_head']
                or fixture['gui'] != {row['path']: row['blob'] for row in manifest['protected']}
                or len(fixture['files']) != 1027 or len(fixture['gui']) != 10
                or scope['version'] != 2 or 'release' in scope or len(scope['rows']) != 38
                or scope['manifest_blob'] != PINS['desktop_dashboard_server_manifest.json']
                or any({row['path']: row['before_blob'] for row in manifest['sources']}.get(
                    row['path']) != row['blob'] for row in scope['rows'])):
            raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_BASELINE_INVALID')
        def head_reader():
            env = {**os.environ, 'GIT_OPTIONAL_LOCKS': '0', 'GIT_NO_LAZY_FETCH': '1',
                   'GIT_NO_REPLACE_OBJECTS': '1'}
            result = subprocess.run(['/usr/bin/git', '-C', str(ROOT), 'rev-parse', 'HEAD'],
                                    env=env, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                    check=True, timeout=15)
            value = result.stdout.decode('ascii').strip()
            if len(value) != 40 or any(letter not in '0123456789abcdef' for letter in value):
                raise DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_HEAD_INVALID')
            return value
        gui_names = list(dict.fromkeys([*fixture['gui'], *RELATED_GUI]))
        before = snapshot(ROOT, source, gui_names, scope['rows'], head_reader)
        after = snapshot(ROOT, source, gui_names, scope['rows'], head_reader)
        result = report(before, after, source, fixture, manifest, scope, release=release)
        print('DESKTOP_CURRENT_SOURCE_DIAGNOSIS_BEGIN')
        print(json.dumps(result, separators=(',', ':')))
        print('DESKTOP_CURRENT_SOURCE_DIAGNOSIS_END; DEPLOYMENT_PERFORMED=false')
        return 0
    except BaseException as error:
        print(str(error) if isinstance(error, DiagnosisError) else
              'DESKTOP_SOURCE_DIAGNOSIS_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
