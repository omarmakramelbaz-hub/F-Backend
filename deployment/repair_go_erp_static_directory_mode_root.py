#!/usr/bin/env python3
"""Remove only world-write from public/site/js; bootstrap pins this and the doctor."""
import errno, json, os, re, runpy, socket, stat, sys, uuid

if len(sys.argv) != 2 or os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
    raise SystemExit('GO_ERP_DIRECTORY_MODE_REPAIR_ROOT_AND_HOST_REQUIRED')

doc = None
fds = []
work = None
phase = 'LOCK'
audit_created = None
COMPONENTS = ('/', '/home', '/home/fasakha', '/home/fasakha/public_html',
              '/home/fasakha/public_html/public', '/home/fasakha/public_html/public/site',
              '/home/fasakha/public_html/public/site/js')
REASONS = frozenset(('DIRECTORY_OWNER_INVALID', 'ANCESTOR_WORLD_WRITABLE',
                    'HELD_DIRECTORY_CHANGED', 'NAMED_DIRECTORY_CHANGED', 'AUDIT_WRITE_INCOMPLETE'))
DOC_REASONS = frozenset('GO_ERP_LIVE_DIAGNOSIS_' + value for value in
    ('WATCHER_BUSY', 'DIRECTORY_UNSAFE', 'DIRECTORY_MODE_INVALID', 'DIRECTORY_CHANGED',
     'LOCK_UNSAFE', 'LOCK_CHANGED'))
ERRNOS = frozenset(getattr(errno, name) for name in
    ('EPERM', 'ENOENT', 'EINTR', 'EIO', 'EBADF', 'EAGAIN', 'EACCES', 'EEXIST',
     'ENOTDIR', 'EINVAL', 'ENFILE', 'EMFILE', 'ENOSPC', 'EROFS', 'ELOOP', 'ESTALE', 'EDQUOT'))

class ModeGuard(Exception):
    pass

def need(ok, reason, component=None, value=None):
    if not ok:
        error = ModeGuard()
        error.reason = reason
        if component in COMPONENTS and value is not None:
            error.guard_metadata = {'component':component, 'uid':value.st_uid,
                                    'gid':value.st_gid, 'mode':stat.S_IMODE(value.st_mode)}
        raise error
def identity(value, mode=None):
    return (value.st_dev, value.st_ino, value.st_mode if mode is None else stat.S_IFMT(value.st_mode)|mode,
            value.st_uid, value.st_gid, value.st_nlink, value.st_size, value.st_mtime_ns)
try:
    doc = runpy.run_path(sys.argv[1], run_name='reviewed_directory_mode')
    with doc['existing_lock'](doc['WORK']):
        phase = 'OPEN_ANCESTRY'
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
        fd = os.open('/', flags)
        fds.append(fd)
        rows = [(None, None, fd, os.fstat(fd))]
        for part in ('home', 'fasakha', 'public_html', 'public', 'site', 'js'):
            parent = fd
            fd = os.open(part, flags, dir_fd=parent)
            fds.append(fd)
            value = os.fstat(fd)
            need((value.st_uid, value.st_gid) in ((0, 0), (1001, 1003)),
                 'DIRECTORY_OWNER_INVALID', COMPONENTS[len(rows)], value)
            if part != 'js':
                need(not value.st_mode & 0o002, 'ANCESTOR_WORLD_WRITABLE', COMPONENTS[len(rows)], value)
            rows.append((parent, part, fd, value))
        before = rows[-1][3]
        old_mode = stat.S_IMODE(before.st_mode)
        new_mode = old_mode & ~0o002
        def verify(mode):
            for index, (parent, name, held, saved) in enumerate(rows):
                expected = identity(saved, mode if index == len(rows)-1 else None)
                actual = os.fstat(held)
                need(identity(actual) == expected, 'HELD_DIRECTORY_CHANGED', COMPONENTS[index], actual)
                if parent is not None:
                    actual = os.stat(name, dir_fd=parent, follow_symlinks=False)
                    need(identity(actual) == expected, 'NAMED_DIRECTORY_CHANGED', COMPONENTS[index], actual)
        phase = 'VERIFY_BEFORE'
        verify(old_mode)
        phase = 'AUDIT_OPEN'
        work = doc['Directory'](doc['WORK'], 0o700)
        audit = 'directory-mode-' + uuid.uuid4().hex + '.json'
        record = {'kind':'go-erp-directory-mode-repair', 'path':'public/site/js',
                  'before':format(old_mode,'04o'), 'after':format(new_mode,'04o'),
                  'uid':before.st_uid, 'gid':before.st_gid,
                  'device':before.st_dev, 'inode':before.st_ino}
        output = os.open(audit, os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW, 0o600, dir_fd=work.fd)
        audit_created = audit
        try:
            phase = 'AUDIT_WRITE'
            data = memoryview((json.dumps(record, sort_keys=True)+'\n').encode())
            while data:
                count = os.write(output, data)
                need(count > 0, 'AUDIT_WRITE_INCOMPLETE')
                data = data[count:]
            os.fsync(output)
        finally:
            os.close(output)
        os.fsync(work.fd)
        phase = 'VERIFY_PRE_CHANGE'
        work.verify()
        verify(old_mode)
        phase = 'MODE_APPLY'
        if old_mode != new_mode:
            os.fchmod(fd, new_mode)
            os.fsync(fd)
        phase = 'VERIFY_POST'
        verify(new_mode)
        print(json.dumps({'ok':True, **record, 'changed':old_mode != new_mode,
                          'application_contents_changed':False, 'database_changed':False,
                          'audit':str(doc['WORK']/audit)}, sort_keys=True))
        phase = 'LOCK_EXIT'
except Exception as error:
    classes = (OSError, PermissionError, FileNotFoundError, NotADirectoryError,
               FileExistsError, BlockingIOError, ValueError, KeyError, TypeError, RuntimeError)
    result = {'kind':'go-erp-directory-mode-repair', 'ok':False, 'phase':phase,
              'exception_class':type(error).__name__ if type(error) in classes else 'Exception',
              'reason':'PRIVATE_FAILURE', 'application_contents_changed':False, 'database_changed':False}
    if type(error) is ModeGuard and getattr(error, 'reason', None) in REASONS:
        result.update(exception_class='ModeGuard', reason='GO_ERP_DIRECTORY_MODE_REPAIR_' + error.reason)
        result.update(getattr(error, 'guard_metadata', {}))
    elif doc is not None and type(error) is doc.get('Stop'):
        result['exception_class'] = 'Stop'
        if len(error.args) == 1 and isinstance(error.args[0], str) and error.args[0] in DOC_REASONS:
            result['reason'] = error.args[0]
    if isinstance(error, OSError) and type(error.errno) is int and error.errno in ERRNOS:
        result['errno'] = error.errno
    if isinstance(audit_created, str) and re.fullmatch(r'directory-mode-[a-f0-9]{32}\.json', audit_created):
        result['audit'] = audit_created
    print(json.dumps(result, sort_keys=True))
    raise SystemExit(1) from None
finally:
    if work is not None:
        work.close()
    for held in reversed(fds):
        os.close(held)
