#!/usr/bin/env python3
"""Install the owner-requested GO ERP release bridge; never start a new build."""
import argparse
from contextlib import ExitStack
import fcntl
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
import types
import uuid

ROOT = Path('/home/fasakha/public_html')
CONTROL = Path('/var/lib/go-erp-manual-updates')
RELEASES = Path('/opt/go-erp-manual-updates/releases')
SYSTEMD = Path('/etc/systemd/system')
NATIVE = Path('/var/lib/go-erp-live-updates')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
PUBLISHER_BLOB = '190abfe6ff0f9d11024a495e7a1aa3d79e879dc4'
APP_PINS = {'app/Services/Dashboard/DesktopDashboardManualRelease.php': (None, '42f7ce6660876e6baf59ad9c41b226d7ebb63017a48bca1c6b23b4a0b2f1238c'), 'app/Http/Controllers/Dashboard/DesktopDashboardController.php': ('ed54e2958302bfb2ffb0c7680eac93fbfa0ad0a46ddb587bb6c4f90ba17d50c2', 'c457f2b956bf8eb1b370260f96024937793bee64b41f6f8c7a27e6a513abc342'), 'routes/desktop_dashboard.php': ('b775c2b5c514ffea3866bd9d2e97f19e6eb6a00fa8680845689220b6c9b19849', '5334c2b73b7345e71b7c166b60eb11a9d963ec14de68c5280b814f52da74cc9b'), 'resources/views/admin/desktop-dashboard.blade.php': ('7a490dfb9093cfd67cdf91d7e1ed37d7a22370caf58abe0c8121e98990d7e72b', '49673d834e6e1e9a2ce9b6e579385e3ef2cfd2ba0b1fcbd2aa4dff8471ccd892'), 'public/dashboard/js/go-erp-releases.js': (None, '818e52a49cc88c08bd4235477a258de05c90b253e89b2f12b1f0c5a4a6c4eaf6')}
PROGRAM_PINS = {'go_erp_manual_updates_root.py': 'ede4bb603fb04a8251dca2e1eb603febc732c54c12316a5840795420e824c64a',
    'go_erp_manual_tick_root.py': '753baf72d9171adc0d084350fa319ef71c0fa6843c684b42fed5dbef76c588f8'}
UNIT_NAMES = ('go-erp-manual-updates.service', 'go-erp-manual-updates.path')
SAFE = 9007199254740991


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode() + b'\n'


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid,
        value.st_gid, value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def parents(path):
    need(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for item in path.parents:
        need(stat.S_ISDIR(item.lstat().st_mode), 'SYMLINK_PARENT_REJECTED')


def parent_fd(path):
    """Walk every ancestor through bound, non-following directory descriptors."""
    need(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for part in path.parent.parts[1:]:
            need(part not in ('', '.', '..'), 'PATH_INVALID')
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd); fd = child
        return fd
    except BaseException:
        os.close(fd)
        raise


def read_file(path, limit=2*1024**2, uid=None, modes=None, optional=False):
    path = Path(path)
    parent = parent_fd(path)
    try:
        try:
            before = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            if optional:
                return None
            raise Stop('FILE_MISSING') from None
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
            and 0 <= before.st_size <= limit and (uid is None or before.st_uid == uid)
            and (modes is None or stat.S_IMODE(before.st_mode) in modes), 'FILE_UNSAFE')
        fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            need(identity(before) == identity(os.fstat(fd)), 'FILE_CHANGED')
            chunks, total = [], 0
            while total <= limit:
                chunk = os.read(fd, min(65536, limit+1-total))
                if not chunk:
                    break
                chunks.append(chunk); total += len(chunk)
            need(total == before.st_size and identity(before) == identity(os.fstat(fd))
                == identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)), 'FILE_CHANGED')
            return b''.join(chunks), before
        finally:
            os.close(fd)
    finally:
        os.close(parent)


def atomic_write(path, raw, uid, gid, mode, advance=False):
    parent = parent_fd(path)
    temporary = None
    try:
        parent_info = os.fstat(parent)
        try:
            old = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
            need(stat.S_ISREG(old.st_mode) and old.st_nlink == 1, 'WRITE_TARGET_UNSAFE')
            previous = old.st_mtime_ns
        except FileNotFoundError:
            previous = 0
        temporary = '.manual-update-'+uuid.uuid4().hex
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(raw); stream.flush()
            os.fchown(stream.fileno(), uid, gid); os.fchmod(stream.fileno(), mode)
            if advance:
                stamp = max(time.time_ns(), (previous//1000000000+1)*1000000000)
                os.utime(stream.fileno(), ns=(stamp, stamp))
            os.fsync(stream.fileno())
        named = parent_fd(path)
        try:
            need((os.fstat(named).st_dev, os.fstat(named).st_ino) == (parent_info.st_dev, parent_info.st_ino), 'WRITE_PARENT_CHANGED')
        finally: os.close(named)
        os.replace(temporary, path.name, src_dir_fd=parent, dst_dir_fd=parent)
        os.fsync(parent)
    finally:
        if temporary is not None:
            try: os.unlink(temporary, dir_fd=parent)
            except FileNotFoundError: pass
        os.close(parent)


def directory(path, uid, gid, mode, create=True):
    parents(path)
    try:
        before = path.lstat()
    except FileNotFoundError:
        need(create, 'DIRECTORY_MISSING')
        path.mkdir(mode=mode)
        os.chown(path, uid, gid); os.chmod(path, mode)
        before = path.lstat()
    need(stat.S_ISDIR(before.st_mode) and before.st_uid == uid and before.st_gid == gid
        and stat.S_IMODE(before.st_mode) == mode, 'DIRECTORY_UNSAFE')
    return before


def acquire(stack, path):
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    stream = stack.enter_context(os.fdopen(fd, 'r+b'))
    value = os.fstat(fd)
    need(stat.S_ISREG(value.st_mode) and value.st_uid == 0 and value.st_nlink == 1
        and stat.S_IMODE(value.st_mode) == 0o600, 'LOCK_UNSAFE')
    try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError: raise Stop('INSTALLATION_BUSY') from None


def publisher(raw):
    blob = hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
    need(blob == PUBLISHER_BLOB, 'PUBLISHER_PIN_INVALID')
    value = types.ModuleType('manual_update_publication')
    exec(compile(raw, '<pinned-publication-engine>', 'exec'), value.__dict__)
    value.PATHS = tuple(APP_PINS)
    value.replace_file = lambda target, content, uid, gid, mode: atomic_write(target, content, uid, gid, mode, True)
    return value


def app_manifest():
    return {name: {'before': [] if before is None else [before], 'after': after}
        for name, (before, after) in APP_PINS.items()}


def units(runtime):
    need(runtime.parent == RELEASES and re.fullmatch(r'[a-f0-9]{64}', runtime.name), 'RUNTIME_PATH_INVALID')
    return {
        UNIT_NAMES[0]: ("""[Unit]
Description=GO ERP owner-requested accepted release
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
UMask=0077
NoNewPrivileges=yes
TimeoutStartSec=7500
ExecStart=/usr/bin/python3 -I -B {runtime}/go_erp_manual_updates_root.py --run
""").format(runtime=runtime).encode(),
        UNIT_NAMES[1]: b"""[Unit]
Description=GO ERP explicit update requests

[Path]
PathExists=/var/lib/go-erp-manual-updates/requests/pending.json
PathExists=/var/lib/go-erp-manual-updates/private/active.json
Unit=go-erp-manual-updates.service

[Install]
WantedBy=multi-user.target
"""}


def install_runtime(payloads, releases=RELEASES):
    need(set(payloads) == set(PROGRAM_PINS) and all(isinstance(raw, bytes)
        and sha(raw) == PROGRAM_PINS[name] for name, raw in payloads.items()), 'RUNTIME_PINS_INVALID')
    roster = {name: sha(raw) for name, raw in sorted(payloads.items())}
    name = sha(canonical(roster))
    target = releases / name
    if target.exists():
        directory(target, 0, 0, 0o555, False)
        need({p.name for p in target.iterdir()} == set(payloads), 'RUNTIME_ROSTER_INVALID')
        for item, raw in payloads.items():
            need(read_file(target/item, uid=0, modes=(0o444,))[0] == raw, 'RUNTIME_CONTENT_CHANGED')
        return target
    temporary = releases / ('.stage-'+uuid.uuid4().hex)
    temporary.mkdir(mode=0o700)
    try:
        for item, raw in payloads.items():
            atomic_write(temporary/item, raw, 0, 0, 0o444)
        os.chmod(temporary, 0o555)
        os.rename(temporary, target)
    except BaseException:
        if temporary.exists():
            os.chmod(temporary, 0o700)
            for item in temporary.iterdir(): item.unlink()
            temporary.rmdir()
        raise
    return target


def provision(owner, control=CONTROL):
    directory(control, 0, 0, 0o755)
    directory(control/'private', 0, 0, 0o700)
    directory(control/'private/jobs', 0, 0, 0o700)
    directory(control/'private/audits', 0, 0, 0o700)
    directory(control/'requests', owner.pw_uid, owner.pw_gid, 0o700)
    directory(control/'shared', 0, owner.pw_gid, 0o750)
    lock = control/'requests/request.lock'
    current_lock=read_file(lock, uid=owner.pw_uid, modes=(0o600,), optional=True)
    if current_lock is None:
        # Request lock content is immaterial, but the expected file is regular
        # and owned by the web account before PHP or root takes its flock.
        atomic_write(lock, b'', owner.pw_uid, owner.pw_gid, 0o600)
    else:
        need(current_lock[1].st_gid==owner.pw_gid,'REQUEST_LOCK_GROUP_INVALID')
    status = control/'shared/status.json'
    current = read_file(status, 8192, uid=0, modes=(0o640,), optional=True)
    if current is None:
        initial = {'format': 1, 'kind': 'go-erp-manual-release-status', 'state': 'idle',
            'nonce': None, 'updated_at': int(time.time()), 'version': None, 'code': None}
        atomic_write(status, canonical(initial), 0, owner.pw_gid, 0o640)
    else:
        need(current[1].st_gid==owner.pw_gid,'STATUS_GROUP_INVALID')
        value = json.loads(current[0])
        need(isinstance(value, dict) and set(value) == {'format','kind','state','nonce','updated_at','version','code'}
            and type(value.get('format')) is int and value['format']==1
            and value.get('kind')=='go-erp-manual-release-status'
            and value.get('state') in ('idle','queued','running','waiting-ci','publishing','ready','failed')
            and (value.get('nonce') is None or isinstance(value['nonce'],str) and re.fullmatch(r'[a-f0-9]{32}',value['nonce']))
            and type(value.get('updated_at')) is int and 1<=value['updated_at']<=SAFE
            and (value.get('version') is None or isinstance(value['version'],str)
                and re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]{0,14}',value['version']))
            and (value.get('code') is None or isinstance(value['code'],str)
                and re.fullmatch(r'GO_ERP_[A-Z0-9_]{1,100}',value['code'])),'STATUS_UNSAFE')


def cache_entries(root, uid):
    entries = []
    for name in ('routes-v7.php', 'routes.php'):
        path = root/'bootstrap/cache'/name
        old = read_file(path, 4*1024**2, uid=uid, modes=(0o644,0o600,0o640,0o660,0o664), optional=True)
        if old is not None: entries.append((path, old))
    folder = root/'storage/framework/views'
    if folder.exists():
        need(folder.is_dir() and not folder.is_symlink(), 'VIEW_CACHE_UNSAFE')
        names = list(folder.iterdir()); need(len(names) <= 10000, 'VIEW_CACHE_LIMIT')
        marker = ('/**PATH '+str(root/'resources/views/admin/desktop-dashboard.blade.php')+' ENDPATH**/').encode()
        total=0
        for path in names:
            if not re.fullmatch(r'[a-f0-9]{40}\.php', path.name): continue
            data = read_file(path, 4*1024**2, uid=uid, modes=(0o644,0o600,0o640,0o660,0o664))
            total+=len(data[0]);need(total<=64*1024**2,'VIEW_CACHE_BYTE_LIMIT')
            if marker in data[0][-1024:]: entries.append((path, data))
    return entries


def invalidate_caches(entries, audit, uid):
    records = []
    for index, (path, (raw, info)) in enumerate(entries):
        current = read_file(path, 4*1024**2, uid=uid, modes=(0o644,0o600,0o640,0o660,0o664))
        need(identity(current[1]) == identity(info) and current[0] == raw, 'CACHE_CHANGED')
        atomic_write(audit/('cache-'+str(index)+'.php'), raw, 0, 0, 0o600)
        path.unlink()
        records.append({'path':str(path),'uid':info.st_uid,'gid':info.st_gid,'mode':stat.S_IMODE(info.st_mode),'sha256':sha(raw)})
    atomic_write(audit/'cache-preimages.json', canonical(records), 0, 0, 0o600)


def restore_caches(entries):
    for path, (raw, info) in entries:
        current = read_file(path, 4*1024**2, uid=info.st_uid, modes=(0o644,0o600,0o640,0o660,0o664), optional=True)
        if current is None:
            atomic_write(path, raw, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        else:
            need(current[0] == raw, 'CACHE_ROLLBACK_REQUIRES_REVIEW')


def write_application(root, changes, audit, uid, gid, engine, attempted):
    folder = audit/'application'; folder.mkdir(mode=0o700)
    metadata = {}
    for name, original, desired, old in changes:
        metadata[name] = {'before': sha(original) if original is not None else None,
            'after':sha(desired),'uid':old.st_uid if old else uid,
            'gid':old.st_gid if old else gid,'mode':stat.S_IMODE(old.st_mode) if old else 0o644}
        if original is not None:
            saved=folder/name;saved.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
            atomic_write(saved,original,0,0,0o600)
    atomic_write(folder/'metadata.json',canonical(metadata),0,0,0o600)
    ordered=sorted(changes,key=lambda row:(row[0].endswith('desktop-dashboard.blade.php'),row[3] is not None))
    for change in ordered:
        target=engine.unchanged_source(root,change)
        name,original,desired,old=change;record=metadata[name]
        attempted.append(change)
        atomic_write(target,desired,record['uid'],record['gid'],record['mode'],True)
    for name,original,desired,old in changes:
        current=read_file(root/name,uid=old.st_uid if old else uid,modes=(old.st_mode&0o777 if old else 0o644,))
        need(current[0]==desired,'APP_VERIFY_FAILED')


def rollback_application(root, attempted, uid, gid):
    for name, original, desired, old in reversed(attempted):
        path=root/name
        expected=(old.st_uid,old.st_gid,stat.S_IMODE(old.st_mode)) if old else (uid,gid,0o644)
        current=read_file(path,optional=True)
        if current is None:
            need(original is None,'APP_ROLLBACK_REQUIRES_REVIEW')
            continue
        attrs=(current[1].st_uid,current[1].st_gid,stat.S_IMODE(current[1].st_mode))
        if original is not None and current[0]==original and attrs==expected:
            continue
        need(current[0]==desired and attrs==expected,'APP_ROLLBACK_REQUIRES_REVIEW')
        if original is None:path.unlink()
        else:atomic_write(path,original,*expected,True)


def run(command, timeout=30):
    result = subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL, timeout=timeout, check=False)
    need(result.returncode == 0 and len(result.stdout) < 8192, 'COMMAND_FAILED')
    return result.stdout.decode('utf8', errors='strict').strip()


def quiescent(runner=run, systemd=SYSTEMD):
    need(runner(['/usr/bin/systemctl','show','go-erp-live-updates.timer','-p','ActiveState','--value'])=='inactive','LEGACY_TIMER_ACTIVE')
    names=['go-erp-live-updates.service']
    if (systemd/UNIT_NAMES[0]).exists():names.append(UNIT_NAMES[0])
    for unit in names:
        need(runner(['/usr/bin/systemctl','show',unit,'-p','ActiveState','--value']) in ('inactive','failed'),'UPDATER_ACTIVE')


def php_preflight(payloads, owner, php=PHP, runner=run):
    runner(['/usr/sbin/runuser','-u',owner.pw_name,'--',php,'-d','display_errors=0','-d','log_errors=0',
        '-r','exit(function_exists("posix_geteuid") ? 0 : 78);'])
    temporary = Path(tempfile.mkdtemp(prefix='go-erp-manual-php-', dir='/var/tmp'))
    os.chmod(temporary, 0o755)
    try:
        for index, (name, raw) in enumerate(payloads.items()):
            if not name.endswith('.php') or '.blade.' in name: continue
            path = temporary/(str(index)+'.php'); atomic_write(path, raw, 0, 0, 0o644)
            runner(['/usr/sbin/runuser','-u',owner.pw_name,'--',php,'-d','display_errors=0','-d','log_errors=0','-l',str(path)])
    finally:
        for path in temporary.iterdir(): path.unlink()
        temporary.rmdir()


def perform(root, owner, package, app_payloads, runtime_payloads, release,
            control=CONTROL, releases=RELEASES, systemd=SYSTEMD, runner=run):
    """The caller holds installer and original watcher locks through activation."""
    quiescent(runner,systemd)
    engine=publisher(package)
    for name in APP_PINS:
        read_file(root/name,uid=owner.pw_uid,modes=(0o644,),optional=APP_PINS[name][0] is None)
    changes=engine.plan(root,app_manifest(),app_payloads,owner_uid=owner.pw_uid)
    php_preflight(app_payloads,owner,runner=runner)
    provision(owner,control)
    directory(releases.parent,0,0,0o755);directory(releases,0,0,0o755)
    runtime=install_runtime(runtime_payloads,releases)
    wanted_units=units(runtime)
    existing={name:read_file(systemd/name,65536,uid=0,modes=(0o644,),optional=True) for name in UNIT_NAMES}
    installation=control/'private/installation.json'
    receipt_preimage=read_file(installation,8192,uid=0,modes=(0o600,),optional=True)
    previous=json.loads(receipt_preimage[0]) if receipt_preimage else None
    for name,old in existing.items():
        need(old is None or old[0]==wanted_units[name] or (isinstance(previous,dict)
            and previous.get('units',{}).get(name)==sha(old[0])),'FOREIGN_MANUAL_UNIT')
    cache=cache_entries(root,owner.pw_uid)
    audit=control/'private/audits'/('install-'+release[:12]+'-'+uuid.uuid4().hex)
    audit.mkdir(mode=0o700)
    unit_attempted,app_attempted=[],[]
    invalidated=activation_attempted=receipt_attempted=False
    try:
        atomic_write(audit/'intent.json',canonical({'format':1,'kind':'go-erp-manual-installation',
            'release':release,'runtime':runtime.name,'units':{name:sha(raw) for name,raw in wanted_units.items()}}),0,0,0o600)
        for name,old in existing.items():
            if old is not None:atomic_write(audit/('before-'+name),old[0],0,0,0o600)
        invalidated=True
        invalidate_caches(cache,audit,owner.pw_uid)
        write_application(root,changes,audit,owner.pw_uid,owner.pw_gid,engine,app_attempted)
        quiescent(runner,systemd)
        for name,raw in wanted_units.items():
            old=existing[name];current=read_file(systemd/name,65536,uid=0,modes=(0o644,),optional=True)
            need((current is None and old is None) or (current is not None and old is not None
                and identity(current[1])==identity(old[1]) and current[0]==old[0]),'UNIT_CHANGED')
            if current is None or current[0]!=raw:
                unit_attempted.append(name);atomic_write(systemd/name,raw,0,0,0o644)
        runner(['/usr/bin/systemctl','daemon-reload'])
        for name,raw in wanted_units.items():
            need(read_file(systemd/name,65536,uid=0,modes=(0o644,))[0]==raw,'UNIT_VERIFY_FAILED')
        for name,raw in app_payloads.items():
            need(read_file(root/name,uid=owner.pw_uid)[0]==raw,'APP_VERIFY_FAILED')
        activation_attempted=True
        activate(runner)
        # An existing request may start the broker, which waits for the original
        # watcher lock held by this installer. No request is created here.
        receipt_attempted=True
        atomic_write(installation,canonical({'format':1,'kind':'go-erp-manual-installation',
            'release':release,'runtime':runtime.name,'units':{name:sha(raw) for name,raw in wanted_units.items()}}),0,0,0o600)
        atomic_write(audit/'completed.json',canonical({'ok':True}),0,0,0o600)
    except BaseException:
        try:
            if activation_attempted:
                runner(['/usr/bin/systemctl','stop','go-erp-manual-updates.path'])
                # Quiescence proved this service was not running before our
                # activation. A queued owner request may have started it while
                # the watcher lock is held; stop that new child before rollback.
                runner(['/usr/bin/systemctl','stop','go-erp-manual-updates.service'])
                need(runner(['/usr/bin/systemctl','show','go-erp-manual-updates.service',
                    '-p','ActiveState','--value']) in ('inactive','failed'),'MANUAL_SERVICE_STILL_RUNNING')
                runner(['/usr/bin/systemctl','disable','go-erp-manual-updates.path'])
            for name in reversed(unit_attempted):
                current=read_file(systemd/name,65536,uid=0,modes=(0o644,),optional=True)
                old=existing[name]
                if old is not None and current is not None and current[0]==old[0]:continue
                if old is None and current is None:continue
                need(current is not None and current[0]==wanted_units[name],'UNIT_ROLLBACK_REQUIRES_REVIEW')
                if old is None:(systemd/name).unlink()
                else:atomic_write(systemd/name,old[0],0,0,0o644)
            if unit_attempted:runner(['/usr/bin/systemctl','daemon-reload'])
            rollback_application(root,app_attempted,owner.pw_uid,owner.pw_gid)
            if invalidated:restore_caches(cache)
            if receipt_attempted:
                current=read_file(installation,8192,uid=0,modes=(0o600,),optional=True)
                if receipt_preimage is None:
                    if current is not None:installation.unlink()
                else:atomic_write(installation,receipt_preimage[0],0,0,0o600)
            atomic_write(audit/'rolled-back.json',canonical({'ok':False,'automatic_updates_disabled':True}),0,0,0o600)
        except BaseException:
            raise Stop('INSTALL_ROLLBACK_REQUIRES_REVIEW') from None
        raise
    return {'runtime':str(runtime),'audit':str(audit),'source_changed':bool(changes)}


def activate(runner=run):
    runner(['/usr/bin/systemctl','enable','--now','go-erp-manual-updates.path'])
    need(runner(['/usr/bin/systemctl','show','go-erp-manual-updates.path','-p','ActiveState','--value']) == 'active', 'MANUAL_PATH_NOT_ACTIVE')
    need(runner(['/usr/bin/systemctl','show','go-erp-live-updates.timer','-p','ActiveState','--value']) == 'inactive', 'LEGACY_TIMER_ACTIVE')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir',required=True);parser.add_argument('--release',required=True)
    parser.add_argument('--apply',action='store_true');args=parser.parse_args()
    need(args.apply and os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_HOST_APPLY_REQUIRED')
    need(re.fullmatch(r'[a-f0-9]{40}',args.release),'RELEASE_INVALID')
    owner=pwd.getpwnam('fasakha');root=ROOT
    need(root.resolve()==root and root.is_dir() and root.stat().st_uid==owner.pw_uid
        and not root.stat().st_mode&0o022,'APPLICATION_ROOT_UNSAFE')
    repository=Path(args.git_dir).absolute();parents(repository)
    need(repository.resolve()==repository and repository.is_dir() and repository.stat().st_uid==0
        and not repository.stat().st_mode&0o022,'PACKAGE_UNSAFE')
    def git(*values):
        value=subprocess.check_output(['/usr/bin/git','--git-dir='+str(repository),'-c','credential.helper=',
            '-c','core.hooksPath=/dev/null',*values],stderr=subprocess.DEVNULL,timeout=30,
            env={'PATH':'/usr/bin:/bin','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null',
                'GIT_CONFIG_SYSTEM':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_TERMINAL_PROMPT':'0','GIT_OPTIONAL_LOCKS':'0'})
        need(len(value)<=2*1024**2,'PACKAGE_LIMIT');return value
    need(git('rev-parse',args.release+'^{commit}').decode().strip()==args.release,'RELEASE_INVALID')
    app={name:git('show',args.release+':'+name) for name in APP_PINS}
    program={name:git('show',args.release+':deployment/'+name) for name in PROGRAM_PINS}
    package=git('show',args.release+':deployment/apply_paymob_order_confirmation_root.py')
    run(['/usr/bin/systemctl','disable','--now','go-erp-live-updates.timer'])
    path_was_active=False
    if (SYSTEMD/UNIT_NAMES[1]).exists():
        path_was_active=run(['/usr/bin/systemctl','show',UNIT_NAMES[1],'-p','ActiveState','--value'])=='active'
        run(['/usr/bin/systemctl','stop',UNIT_NAMES[1]])
    try:
        quiescent()
        provision(owner)
        directory(RELEASES.parent,0,0,0o755);directory(RELEASES,0,0,0o755)
        with ExitStack() as stack:
            acquire(stack,CONTROL/'private/install.lock')
            acquire(stack,NATIVE/'watcher.lock')
            result=perform(root,owner,package,app,program,args.release)
    except BaseException as error:
        if path_was_active and str(error)!='INSTALL_ROLLBACK_REQUIRES_REVIEW':
            run(['/usr/bin/systemctl','enable','--now',UNIT_NAMES[1]])
        raise
    print(json.dumps({'format':1,'kind':'go-erp-manual-updates-installed','ok':True,
        'automatic_updates_enabled':False,'database_changed':False,'credentials_disclosed':False,
        'source_changed':result['source_changed'],'runtime':result['runtime'],'manual_request_service':'active'}))
    print('GO_ERP_MANUAL_UPDATES_READY_AUTOMATIC_TIMER_DISABLED')


if __name__=='__main__':
    try: main()
    except BaseException as error:
        code=str(error) if type(error) is Stop and re.fullmatch(r'[A-Z0-9_]{1,100}',str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'format':1,'kind':'go-erp-manual-updates-installed','ok':False,
            'code':'GO_ERP_MANUAL_INSTALL_'+code,'automatic_updates_enabled':False,
            'database_changed':False,'credentials_disclosed':False}))
        raise SystemExit(1)
