#!/usr/bin/env python3
"""Remove only group-write from the twenty observed configuration code files."""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import signal
import stat
import sys
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
FILES = tuple('config/'+name+'.php' for name in (
    'app','auth','broadcasting','cache','charts','ckeditor','cors','database',
    'filesystems','firebase','hashing','logging','media-library','permission',
    'queue','session','settings','snappy','translatable','view'))
LOCK_NAMES = ('orders-install.lock','inbox-install.lock')
OLD_MODE = 0o775
NEW_MODE = 0o755
MAX_BYTES = 8388608


class SafeError(Exception):
    """Fixed status codes only; no configuration values may enter diagnostics."""


def metadata(info):
    return (info.st_dev,info.st_ino,info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode),
            info.st_nlink,info.st_size,info.st_mtime_ns,info.st_ctime_ns)


def ancestors(path,uid,root=None):
    if not path.is_absolute() or '..' in path.parts:
        raise SafeError('CONFIG_MODES_PATH_UNSAFE')
    for parent in reversed(path.parents):
        info=parent.lstat()
        owners=(uid,) if root is not None and (parent==root or root in parent.parents) else (0,uid)
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in owners:
            raise SafeError('CONFIG_MODES_ANCESTOR_UNSAFE')


def snapshot(fd,uid):
    first=os.fstat(fd)
    if (not stat.S_ISREG(first.st_mode) or first.st_uid!=uid or first.st_nlink!=1
            or stat.S_IMODE(first.st_mode) not in (OLD_MODE,NEW_MODE)
            or first.st_size>MAX_BYTES):
        raise SafeError('CONFIG_MODES_FILE_PROFILE_NEEDS_REVIEW')
    os.lseek(fd,0,os.SEEK_SET);digest=hashlib.sha256();size=0
    while True:
        chunk=os.read(fd,min(65536,MAX_BYTES+1-size))
        if not chunk:break
        size+=len(chunk)
        if size>MAX_BYTES:raise SafeError('CONFIG_MODES_FILE_TOO_LARGE')
        digest.update(chunk)
    second=os.fstat(fd)
    if metadata(first)!=metadata(second) or size!=first.st_size:
        raise SafeError('CONFIG_MODES_FILE_CHANGED_DURING_READ')
    return metadata(second),digest.hexdigest()


def assert_path(path,fd,uid,root):
    ancestors(path,uid,root)
    item=path.lstat();opened=os.fstat(fd)
    if not stat.S_ISREG(item.st_mode) or metadata(item)!=metadata(opened):
        raise SafeError('CONFIG_MODES_PATH_CHANGED_CONCURRENTLY')


def assert_directory(path,fd,uid,private=False):
    item=path.lstat();opened=os.fstat(fd)
    if (not stat.S_ISDIR(item.st_mode) or item.st_uid!=uid
            or (item.st_dev,item.st_ino)!=(opened.st_dev,opened.st_ino)
            or (private and stat.S_IMODE(item.st_mode)!=0o700)):
        raise SafeError('CONFIG_MODES_DIRECTORY_CHANGED_CONCURRENTLY')


@contextlib.contextmanager
def blocked_signals():
    previous=signal.pthread_sigmask(signal.SIG_BLOCK,{signal.SIGINT,signal.SIGTERM})
    try:yield
    finally:signal.pthread_sigmask(signal.SIG_SETMASK,previous)


def append_journal(fd,value):
    encoded=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
    offset=0
    while offset<len(encoded):
        amount=os.write(fd,encoded[offset:])
        if amount<1:raise SafeError('CONFIG_MODES_JOURNAL_WRITE_FAILED')
        offset+=amount
    os.fsync(fd)


def metadata_digest(value):
    return hashlib.sha256(json.dumps(value,separators=(',',':')).encode()).hexdigest()


def normalize(root,uid,hook=None):
    """Test fixtures may supply an isolated root and an inert race-injection hook."""
    root=pathlib.Path(root)
    parent=root.parent/'whatsapp-release-backups'
    ancestors(root/'probe',uid,root)
    ancestors(parent/'probe',uid)
    original_root=root.lstat();original_parent=parent.lstat()
    if (not stat.S_ISDIR(original_root.st_mode) or original_root.st_uid!=uid
            or not stat.S_ISDIR(original_parent.st_mode) or original_parent.st_uid!=uid
            or stat.S_IMODE(original_parent.st_mode)!=0o700):
        raise SafeError('CONFIG_MODES_ROOT_OR_BACKUP_PROFILE_UNSAFE')
    descriptors=[];locked=[];journal=None;backup=None;backup_fd=None;changed=False
    try:
        for name in LOCK_NAMES:
            path=parent/name;fd=os.open(path,os.O_RDWR|os.O_NOFOLLOW|os.O_NONBLOCK)
            locked.append(fd);item=os.fstat(fd);entry=path.lstat()
            if (not stat.S_ISREG(item.st_mode) or item.st_uid!=uid or item.st_nlink!=1
                    or stat.S_IMODE(item.st_mode)!=0o600
                    or (item.st_dev,item.st_ino)!=(entry.st_dev,entry.st_ino)):
                raise SafeError('CONFIG_MODES_LOCK_PROFILE_UNSAFE')
            try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise SafeError('CONFIG_MODES_ANOTHER_INSTALL_RUNNING') from None
        root_fd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);descriptors.append(root_fd)
        config_fd=os.open('config',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=root_fd);descriptors.append(config_fd)
        parent_fd=os.open(parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);descriptors.append(parent_fd)
        assert_directory(root,root_fd,uid);assert_directory(root/'config',config_fd,uid)
        assert_directory(parent,parent_fd,uid,True)
        opened={};states={}
        # Validate every exact path before creating a journal or changing a mode.
        for relative in FILES:
            path=root/relative;ancestors(path,uid,root)
            fd=os.open(path.name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=config_fd);descriptors.append(fd)
            opened[relative]=fd;states[relative]=snapshot(fd,uid);assert_path(path,fd,uid,root)
        def assert_all():
            assert_directory(root,root_fd,uid);assert_directory(root/'config',config_fd,uid)
            assert_directory(parent,parent_fd,uid,True)
            if backup_fd is not None:assert_directory(backup,backup_fd,uid,True)
            for relative,fd in opened.items():
                assert_path(root/relative,fd,uid,root)
                if snapshot(fd,uid)!=states[relative]:raise SafeError('CONFIG_MODES_FILE_CHANGED_CONCURRENTLY')
        assert_all()
        if all(value[0][4]==NEW_MODE for value in states.values()):
            return {'status':'WHATSAPP_ORDER_CONFIG_MODES_ALREADY_READY','backup':None}
        backup=pathlib.Path(tempfile.mkdtemp(prefix='config-modes-',dir=parent));backup.chmod(0o700)
        assert_directory(parent,parent_fd,uid,True)
        info=backup.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid!=uid or info.st_nlink<2 or stat.S_IMODE(info.st_mode)!=0o700:
            raise SafeError('CONFIG_MODES_NEW_BACKUP_UNSAFE')
        backup_fd=os.open(backup,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW);descriptors.append(backup_fd)
        assert_directory(backup,backup_fd,uid,True)
        journal=os.open('journal.jsonl',os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=backup_fd)
        item=os.fstat(journal)
        if not stat.S_ISREG(item.st_mode) or item.st_uid!=uid or item.st_nlink!=1 or stat.S_IMODE(item.st_mode)!=0o600:
            raise SafeError('CONFIG_MODES_NEW_JOURNAL_UNSAFE')
        append_journal(journal,{'schema':'whatsapp-config-modes-v1','files':[
            {'path':p,'old_mode':format(value[0][4],'04o'),'target_mode':'0755','metadata_digest':metadata_digest(value)}
            for p,value in states.items()]})
        for relative,fd in opened.items():
            if states[relative][0][4]==NEW_MODE:continue
            if hook:hook('before_mutation',relative,root)
            assert_all();previous=states[relative]
            # The intent is durable before fchmod. SIGKILL may leave an intent
            # without completion; a subsequent invocation revalidates all files.
            append_journal(journal,{'operation':'intent','path':relative,'old_mode':'0775','target_mode':'0755',
                                   'metadata_digest':metadata_digest(previous)})
            with blocked_signals():
                assert_path(root/relative,fd,uid,root)
                if snapshot(fd,uid)!=previous:raise SafeError('CONFIG_MODES_FILE_CHANGED_CONCURRENTLY')
                os.fchmod(fd,NEW_MODE);changed=True
                current=snapshot(fd,uid);assert_path(root/relative,fd,uid,root)
                old_meta,old_hash=previous;new_meta,new_hash=current
                if (new_hash!=old_hash or new_meta[:4]!=old_meta[:4] or new_meta[4]!=NEW_MODE
                        or new_meta[5:8]!=old_meta[5:8]):
                    raise SafeError('CONFIG_MODES_POST_CHANGE_NEEDS_REVIEW')
                states[relative]=current
                append_journal(journal,{'operation':'complete','path':relative,'mode':'0755',
                                       'metadata_digest':metadata_digest(current)})
            if hook:hook('after_mutation',relative,root)
        if hook:hook('before_final',None,root)
        assert_all();append_journal(journal,{'operation':'ready','files_total':len(FILES)})
        return {'status':'WHATSAPP_ORDER_CONFIG_MODES_READY','backup':str(backup)}
    except BaseException:
        # Never re-add group-write on failure. Already restricted files retain
        # owner-write and application-read; foreign bytes/inodes are untouched.
        if backup is not None:
            if journal is not None:
                try:append_journal(journal,{'operation':'partial_needs_review','mode_change_attempted':changed})
                except BaseException:pass
            raise SafeError('CONFIG_MODES_PARTIAL_NEEDS_REVIEW; BACKUP='+str(backup)) from None
        raise SafeError('CONFIG_MODES_PREFLIGHT_NEEDS_REVIEW') from None
    finally:
        if journal is not None:os.close(journal)
        for fd in reversed(descriptors):os.close(fd)
        for fd in reversed(locked):os.close(fd)


def main():
    parser=argparse.ArgumentParser(add_help=False);parser.add_argument('--root',required=True)
    args=parser.parse_args()
    try:
        def interrupted(signum,frame):raise KeyboardInterrupt()
        signal.signal(signal.SIGINT,interrupted);signal.signal(signal.SIGTERM,interrupted)
        os.umask(0o077)
        root=pathlib.Path(args.root);uid=pwd.getpwnam('fasakha').pw_uid
        if root!=APP_ROOT or os.geteuid()!=uid:raise SafeError('CONFIG_MODES_EXECUTION_CONTEXT_INVALID')
        result=normalize(root,uid);print(result['status'])
        if result.get('backup'):print('BACKUP='+result['backup'])
    except BaseException as error:
        print(str(error) if isinstance(error,SafeError) else 'CONFIG_MODES_STOPPED_WITHOUT_SECRET_OUTPUT',file=sys.stderr)
        raise SystemExit(1)


if __name__=='__main__':main()
