#!/usr/bin/env python3
"""Remove only world-write from public/site/js; bootstrap pins this and the doctor."""
import json, os, runpy, socket, stat, sys, uuid

if len(sys.argv) != 2 or os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
    raise SystemExit('GO_ERP_DIRECTORY_MODE_REPAIR_ROOT_AND_HOST_REQUIRED')

doc = runpy.run_path(sys.argv[1], run_name='reviewed_directory_mode')
fds = []
work = None
def need(ok):
    if not ok:
        raise RuntimeError('MODE_GUARD')
def identity(value, mode=None):
    return (value.st_dev, value.st_ino, value.st_mode if mode is None else stat.S_IFMT(value.st_mode)|mode,
            value.st_uid, value.st_gid, value.st_nlink, value.st_size, value.st_mtime_ns)
try:
    with doc['existing_lock'](doc['WORK']):
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
        fd = os.open('/', flags)
        fds.append(fd)
        rows = [(None, None, fd, os.fstat(fd))]
        for part in ('home', 'fasakha', 'public_html', 'public', 'site', 'js'):
            parent = fd
            fd = os.open(part, flags, dir_fd=parent)
            fds.append(fd)
            value = os.fstat(fd)
            need((value.st_uid, value.st_gid) in ((0, 0), (1001, 1003)))
            if part != 'js':
                need(not value.st_mode & 0o002)
            rows.append((parent, part, fd, value))
        before = rows[-1][3]
        old_mode = stat.S_IMODE(before.st_mode)
        new_mode = old_mode & ~0o002
        def verify(mode):
            for index, (parent, name, held, saved) in enumerate(rows):
                expected = identity(saved, mode if index == len(rows)-1 else None)
                need(identity(os.fstat(held)) == expected)
                if parent is not None:
                    need(identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == expected)
        verify(old_mode)
        work = doc['Directory'](doc['WORK'], 0o700)
        audit = 'directory-mode-' + uuid.uuid4().hex + '.json'
        record = {'kind':'go-erp-directory-mode-repair', 'path':'public/site/js',
                  'before':format(old_mode,'04o'), 'after':format(new_mode,'04o'),
                  'uid':before.st_uid, 'gid':before.st_gid,
                  'device':before.st_dev, 'inode':before.st_ino}
        output = os.open(audit, os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW, 0o600, dir_fd=work.fd)
        try:
            data = memoryview((json.dumps(record, sort_keys=True)+'\n').encode())
            while data:
                count = os.write(output, data)
                need(count > 0)
                data = data[count:]
            os.fsync(output)
        finally:
            os.close(output)
        os.fsync(work.fd)
        work.verify()
        verify(old_mode)
        if old_mode != new_mode:
            os.fchmod(fd, new_mode)
            os.fsync(fd)
        verify(new_mode)
        print(json.dumps({'ok':True, **record, 'changed':old_mode != new_mode,
                          'application_contents_changed':False, 'database_changed':False,
                          'audit':str(doc['WORK']/audit)}, sort_keys=True))
except Exception:
    print('GO_ERP_DIRECTORY_MODE_REPAIR_STOPPED_REVIEW_AUDIT')
    raise SystemExit(1) from None
finally:
    if work is not None:
        work.close()
    for held in reversed(fds):
        os.close(held)
