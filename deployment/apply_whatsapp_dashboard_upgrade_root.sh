#!/bin/bash
set -euo pipefail
exec /usr/bin/python3 -B - "$0" "$@" <<'PY'
import ast, hashlib, json, os, pathlib, pwd, re, shutil, signal, stat, subprocess, sys, tempfile

ROOT = pathlib.Path('/home/fasakha/public_html')
APP = 'deployment/apply_whatsapp_dashboard_upgrade.py'
MANIFEST = 'deployment/whatsapp_dashboard_upgrade_manifest.json'
HELPER_PINS = {
    APP: 'fc4f7d7e47fed00da2d6649d5b918542608dadea',
    MANIFEST: 'f8cd3c532f606c9e5619feeba218575a877ed61a',
    'deployment/install_whatsapp_inbox.py': 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98',
}

FIXED_REASONS = frozenset(('UNSAFE_PATH','ANCESTOR_NOT_DIRECTORY_OR_SYMLINK','ANCESTOR_OWNER_NEEDS_REVIEW',
    'REQUIRED_FILE_MISSING','FILE_NOT_READABLE_OR_SYMLINK','FILE_OWNER_OR_TYPE_NEEDS_REVIEW','FILE_TOO_LARGE',
    'FILE_CHANGED_DURING_READ','FILE_CHANGED_CONCURRENTLY','VERIFIED_SOURCE_GUARD_FAILED','VERIFIED_ROOT_GUARD_FAILED',
    'PROJECT_ROOT_OWNER_OR_TYPE_NEEDS_REVIEW','GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW'))
FIXED_STAGES = frozenset(('BOOTSTRAP','ROOT','MANIFEST','SOURCES','PREFLIGHT','BACKUP','MIGRATION','BACKFILL','ROUTE_CACHE','PUBLICATION','POSTFLIGHT'))
class SafeError(Exception): pass

def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()

def command(arguments, timeout=60):
    process = subprocess.Popen(arguments, stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
    try:
        output, error = process.communicate(timeout=timeout)
    except BaseException as exception:
        try: os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError: pass
        try: process.communicate(timeout=30)
        except subprocess.TimeoutExpired:
            try: os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError: pass
            process.communicate(timeout=10)
        try: os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError: pass
        if not isinstance(exception, subprocess.TimeoutExpired): raise
        raise SafeError('DASHBOARD_ROOT_CHILD_TIMED_OUT') from None
    if len(output) > 1048576 or len(error) > 65536: raise SafeError('DASHBOARD_ROOT_CHILD_OUTPUT_LIMIT')
    return process.returncode, output, error

def git_file(release, relative, expected):
    code, data, _ = command(['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/git', '-C', str(ROOT),
                            'show', release + ':' + relative])
    if code or not data or len(data) > 8388608 or blob(data) != expected:
        raise SafeError('DASHBOARD_ROOT_PINNED_FILE_INVALID: ' + relative)
    return data

def payload_manifest(data):
    try:
        value = json.loads(data)
        if set(value) != {'version', 'sources'} or value['version'] != 1 or type(value['version']) is not int: raise ValueError()
        result = {}
        for row in value['sources']:
            if (set(row) != {'path', 'before_blob', 'after_blob'} or row['path'] in result
                    or not re.fullmatch(r'(app|routes|public|resources|database)/[A-Za-z0-9_./-]+', row['path'])
                    or '..' in pathlib.PurePosixPath(row['path']).parts
                    or not re.fullmatch('[a-f0-9]{40}', row['after_blob'])): raise ValueError()
            result[row['path']] = (row['before_blob'], row['after_blob'])
        if len(result) != 17: raise ValueError()
        return result
    except (ValueError, TypeError, KeyError): raise SafeError('DASHBOARD_ROOT_MANIFEST_SCOPE_INVALID') from None

def parsed_roster(data):
    # Never execute app code as root. Only read its immutable literal source roster.
    result = {}; namespace = {}
    for node in ast.parse(data).body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            name = node.targets[0].id
            if name in ('MIGRATION', 'MESSAGE', 'MEDIA'): namespace[name] = ast.literal_eval(node.value)
            elif name == 'SOURCE_PINS':
                for key, value in zip(node.value.keys, node.value.values):
                    relative = namespace[key.id] if isinstance(key, ast.Name) else ast.literal_eval(key)
                    result[relative] = ast.literal_eval(value)
    return result

def stage_payload(directory, release):
    staged = {relative: git_file(release, relative, expected) for relative, expected in HELPER_PINS.items()}
    roster = payload_manifest(staged[MANIFEST])
    if roster != parsed_roster(staged[APP]): raise SafeError('DASHBOARD_ROOT_HELPER_MANIFEST_DISAGREE')
    for relative, (_, expected) in roster.items(): staged[relative] = git_file(release, relative, expected)
    for relative, data in staged.items():
        path = directory / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        for ancestor in (path.parent, *path.parent.parents):
            if ancestor == directory or directory in ancestor.parents:
                ancestor.chmod(0o755)
                item = ancestor.lstat()
                if not stat.S_ISDIR(item.st_mode) or item.st_uid != 0 or stat.S_IMODE(item.st_mode) != 0o755:
                    raise SafeError('DASHBOARD_ROOT_STAGED_DIRECTORY_UNSAFE')
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data); stream.flush(); os.fchmod(stream.fileno(), 0o644); os.fsync(stream.fileno())
    return directory / MANIFEST

def approved_status_scope(data):
    tree = ast.parse(data); codes = set(); paths = set(parsed_roster(data))
    for node in ast.walk(tree):
        if isinstance(node, ast.Constant) and isinstance(node.value, str):
            text = node.value
            code = text.split(';', 1)[0].split(':', 1)[0]
            if re.fullmatch('DASHBOARD_[A-Z_]+', code): codes.add(code)
            if re.fullmatch(r'(app|routes|public|resources|database|config|bootstrap|vendor)/[A-Za-z0-9_./-]+|\.env', text): paths.add(text)
    return codes, paths

def child_status(output, error, success):
    # Fixed, scoped status only: never repeat PHP/HTTP/git private diagnostics.
    raw = output if success else error
    if len(raw) > 8192: return None
    try: text = raw.decode('ascii')
    except UnicodeError: return None
    rows = text.splitlines()
    allowed = []
    codes, roster = approved_scope if 'approved_scope' in globals() else (set(), set())
    for row in rows:
        if row == 'WHATSAPP_DASHBOARD_UPGRADE_READY': allowed.append(row)
        elif re.fullmatch(r'BACKUP=/home/fasakha/whatsapp-release-backups/dashboard-ui-[A-Za-z0-9_-]{6,32}', row): allowed.append(row)
        elif row.startswith('PROJECTION_INDEX='):
            try:
                value = json.loads(row[len('PROJECTION_INDEX='):])
                if set(value) != {'processed','valid','invalid'} or any(type(n) is not int or n < 0 for n in value.values()): return None
                allowed.append('PROJECTION_INDEX=' + json.dumps(value, sort_keys=True))
            except (ValueError, TypeError): return None
        elif re.fullmatch(r'DASHBOARD_UPGRADE_STOPPED; STAGE=[A-Z_]+; EXCEPTION_CLASS=[A-Za-z_][A-Za-z0-9_]{0,63}', row):
            if row.split('; STAGE=',1)[1].split(';',1)[0] not in FIXED_STAGES: return None
            allowed.append(row)
        else:
            # Fixed app codes can include a known public path and fixed recovery suffix.
            pattern = r'(DASHBOARD_[A-Z_]+)(?:: ([A-Za-z0-9_./-]+))?(?:; REASON=([A-Z_]+))?(?:; (FILES_RESTORED|ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED); METADATA_TABLES_RETAINED; BACKUP=(/home/fasakha/whatsapp-release-backups/dashboard-ui-[A-Za-z0-9_-]{6,32}))?'
            match = re.fullmatch(pattern, row)
            if not match or match[1] not in codes or (match[2] and match[2] not in roster) or (match[3] and match[3] not in FIXED_REASONS): return None
            allowed.append(row)
    if success and (len(allowed) != 3 or allowed[0] != 'WHATSAPP_DASHBOARD_UPGRADE_READY'
                    or not allowed[1].startswith('BACKUP=') or not allowed[2].startswith('PROJECTION_INDEX=')): return None
    return allowed if allowed and len(allowed) <= 4 else None

def main():
    global approved_scope
    directory = None
    try:
        args = sys.argv[1:]
        if len(args) != 3 or args[1] != '--release' or not re.fullmatch('[a-f0-9]{40}', args[2]) or os.geteuid() != 0:
            raise SafeError('RUN_DASHBOARD_ROOT_WITH_PINNED_RELEASE')
        helper = pathlib.Path(args[0]).absolute(); item = helper.lstat()
        if not stat.S_ISREG(item.st_mode) or item.st_uid != 0 or item.st_nlink != 1 or item.st_mode & 0o077:
            raise SafeError('DASHBOARD_ROOT_LAUNCHER_METADATA_UNSAFE')
        for parent in helper.parents:
            info = parent.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or (info.st_mode & 0o022 and parent != pathlib.Path('/tmp')):
                raise SafeError('DASHBOARD_ROOT_LAUNCHER_ANCESTOR_UNSAFE')
        uid = pwd.getpwnam('fasakha').pw_uid
        for path in (ROOT, ROOT / '.git'):
            item = path.lstat()
            if not stat.S_ISDIR(item.st_mode) or item.st_uid != uid: raise SafeError('DASHBOARD_ROOT_PROJECT_METADATA_UNSAFE')
        os.umask(0o077)
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('DASHBOARD_ROOT_INTERRUPTED')))
        directory = pathlib.Path(tempfile.mkdtemp(prefix='wa-dashboard-payload-', dir='/tmp')); directory.chmod(0o755)
        manifest = stage_payload(directory, args[2])
        approved_scope = approved_status_scope((directory / APP).read_bytes())
        code, output, error = command(['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/python3', '-B',
            str(directory / APP), '--root', str(ROOT), '--manifest', str(manifest), '--manifest-blob', HELPER_PINS[MANIFEST]], timeout=1800)
        report = child_status(output, error, code == 0)
        if report is not None:
            for row in report: print(row)
        if code or report is None: raise SafeError('DASHBOARD_ROOT_APP_UPGRADE_FAILED')
        return 0
    except SafeError as error: print(str(error), file=sys.stderr)
    except BaseException as error:
        name = type(error).__name__; name = name if re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}', name) else 'UNKNOWN'
        print('DASHBOARD_ROOT_STOPPED; EXCEPTION_CLASS=' + name, file=sys.stderr)
    finally:
        if directory is not None: shutil.rmtree(directory)
    return 1

sys.exit(main())
PY
