#!/usr/bin/env python3
"""Publish pinned fulfillment order files without reactivating or processing orders."""
import contextlib
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import sys
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
PREFIX = 'app/Services/Dashboard/'
# Only the approved fulfillment extraction policy changes. Release pins are frozen after the tests.
SOURCE_PINS = {
    PREFIX+'WhatsAppOrderAiProvider.php': ('1ac4ebbaeaef12a5da7d83f1c5a6daa024441db0', 'cbaaa8ed7014da5bad05d429151afa5ff377ceab'),
}
LOCK_NAMES = ('orders-install.lock', 'inbox-install.lock')
UNITS = tuple('fasakhansta-whatsapp-'+name for name in
              ('capture.timer', 'capture.service', 'orders.timer', 'orders.service'))
PROTECTED_PINS = {
    'app/Support/WhatsAppOrderExtraction.php': '56d2b9575da152de3eac4876fd93140ee7bc9e39',
    PREFIX+'WhatsAppOrderSourceSelection.php': '03db3211b80da905f07415fd16f1e7a62e0395b1',
    PREFIX+'WhatsAppOrderConversationEvidence.php': '1bd223861e3772e10658df2d555b4bb040243a4a',
    PREFIX+'WhatsAppOrderWorkflow.php': 'b1bdc5962330c5f61a8ab6fa4c640d0fcae8799d',
    'app/Support/WhatsAppInboxProtocol.php': 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
    PREFIX+'WhatsAppNativePlaceholderHydration.php': '9e221ba052589c1ffe474958b636a77ff5817b7d',
    PREFIX+'WhatsAppInboxConsumer.php': 'e0909057496cb9f710928fd8c273b5c9d0adc97f',
    PREFIX+'WhatsAppAcceptedReplyEvidence.php': '4b571f7f4a5cef9257c414886dba515c06dae2a1',
    PREFIX+'WhatsAppInboxMessage.php': 'bedd91db5266d83bdce84a04836e988518891728',
    PREFIX+'WhatsAppReplyService.php': '1ef23df825287f8d3dedafcdb5d70ffeac7f1f29',
    'app/Http/Controllers/Dashboard/WhatsAppInboxController.php': '7ce48a97f115eab4e1f2308c411d0dbc7f5d5f3a',
    PREFIX+'WhatsAppInboxAccess.php': '186789d42113e81818de8d82a2327e3ee9d8d0b5',
    PREFIX+'FasakhanstaDeliveryOrigin.php': '1f0eb40a2b0e6590362beee71e8cbbbcb1d7535f',
    PREFIX+'PhoneDelivery.php': '227027cfdcd68720b74220e3ecd0abb9e156a5d7',
    PREFIX+'PosServiceTicket.php': 'ef4c7b6f4a1276c122634aafabb2d510926aaeef',
}
# Observed existing root-owned configuration, read only. These files are never
# publication targets, backups of configuration contents, or ownership changes.
ROOT_READ_ONLY_CONFIGURATION = frozenset((
    'config/go_services.php','config/jwt.php','config/mail.php','config/partner_auth.php',
))


def blob(content):
    return hashlib.sha1(b'blob '+str(len(content)).encode()+b'\0'+content).hexdigest()


def load_base():
    path = pathlib.Path(__file__).absolute().with_name('install_whatsapp_inbox.py')
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.geteuid()):
            raise RuntimeError('ORDER_FULFILLMENT_BASE_PATH_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid())
                or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > 1048576):
            raise RuntimeError('ORDER_FULFILLMENT_BASE_FILE_UNSAFE')
        content = os.read(fd, 1048577)
        after = os.fstat(fd)
        if (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise RuntimeError('ORDER_FULFILLMENT_BASE_CHANGED')
    finally:
        os.close(fd)
    if blob(content) != BASE_BLOB:
        raise RuntimeError('ORDER_FULFILLMENT_BASE_HASH_MISMATCH')
    name = 'verified_whatsapp_order_fulfillment_base'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path); sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


base = load_base()
SafeError = base.SafeError


def metadata(path):
    info = path.lstat()
    return (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_dev,
            info.st_ino, info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def root_configuration_snapshot(path, uid, root, expected_metadata):
    if path not in (root/name for name in ROOT_READ_ONLY_CONFIGURATION):
        raise SafeError('ORDER_FULFILLMENT_ROOT_CONFIGURATION_SCOPE_INVALID')
    base.safe_ancestors(path,uid,root)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        info=os.fstat(fd)
        def signature(value):
            return (value.st_uid,value.st_gid,stat.S_IMODE(value.st_mode),value.st_dev,
                    value.st_ino,value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_nlink!=1
                or stat.S_IMODE(info.st_mode)!=0o644 or info.st_size>8388608):
            raise SafeError('ORDER_FULFILLMENT_ROOT_CONFIGURATION_PROFILE_NEEDS_REVIEW')
        if signature(info)!=expected_metadata or metadata(path)!=expected_metadata:
            raise SafeError('ORDER_FULFILLMENT_ROOT_CONFIGURATION_CHANGED_DURING_READ')
        with os.fdopen(fd,'rb',closefd=False) as stream:
            content=stream.read(8388609)
        if (len(content)>8388608 or signature(os.fstat(fd))!=expected_metadata
                or metadata(path)!=expected_metadata):
            raise SafeError('ORDER_FULFILLMENT_ROOT_CONFIGURATION_CHANGED_DURING_READ')
        return base.Snapshot(content,info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode),info.st_dev,info.st_ino)
    finally:os.close(fd)


def guarded_snapshot(path, uid, root, absent_ok=False, configuration=False):
    try:
        before = metadata(path)
    except FileNotFoundError:
        if absent_ok:
            base.safe_ancestors(path, uid, root)
            return None, None
        raise SafeError('ORDER_FULFILLMENT_REQUIRED_FILE_MISSING') from None
    # Atomic replacement retains legacy private hardlinks without modifying them.
    private = configuration and path in (root/'.env',root/'bootstrap/cache/config.php')
    if before[2] & 0o022 or (private and (before[5] != 1 or before[2] & 0o077)):
        raise SafeError('ORDER_FULFILLMENT_MODE_OR_CONFIGURATION_LINK_UNSAFE')
    if configuration and before[0]==0 and path in (root/name for name in ROOT_READ_ONLY_CONFIGURATION):
        item=root_configuration_snapshot(path,uid,root,before)
    else:
        item = base.snapshot(path, uid, root)
    if metadata(path) != before:
        raise SafeError('ORDER_FULFILLMENT_FILE_CHANGED_DURING_READ')
    return item, before


def configuration_snapshot(root, uid):
    directory = root/'config'
    base.safe_ancestors(directory/'probe', uid, root)
    info = directory.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or info.st_mode & 0o022:
        raise SafeError('ORDER_FULFILLMENT_CONFIGURATION_DIRECTORY_UNSAFE')
    paths = ['.env', 'bootstrap/cache/config.php']
    for path in sorted(directory.iterdir()):
        # Include all existing direct files, rather than a selected WhatsApp subset.
        if path.is_dir() and not path.is_symlink():
            raise SafeError('ORDER_FULFILLMENT_NESTED_CONFIGURATION_NEEDS_REVIEW')
        paths.append(str(path.relative_to(root)))
    result = {name: guarded_snapshot(root/name, uid, root,
              absent_ok=name=='bootstrap/cache/config.php', configuration=True) for name in paths}
    return metadata(directory), result


def configuration_unchanged(root, uid, expected):
    try:
        return configuration_snapshot(root, uid) == expected
    except (SafeError, OSError):
        return False


def configuration_fingerprint(value):
    directory, files=value
    rows=[directory,[(name,signature,None if item is None else hashlib.sha256(item.content).hexdigest())
          for name,(item,signature) in sorted(files.items())]]
    return hashlib.sha256(json.dumps(rows,separators=(',',':')).encode()).hexdigest()


def require_configuration(root, uid, expected):
    if not configuration_unchanged(root, uid, expected):
        raise SafeError('ORDER_FULFILLMENT_CONFIGURATION_CHANGED')


@contextlib.contextmanager
def locks(root, uid, held_locks=None):
    parent = root.parent/'whatsapp-release-backups'
    base.private_parent(parent, uid)
    descriptors = []
    try:
        if held_locks is not None and len(held_locks) != len(LOCK_NAMES):
            raise SafeError('ORDER_FULFILLMENT_LOCK_HANDOFF_INVALID')
        for index, name in enumerate(LOCK_NAMES):
            path = parent/name
            fd = (os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
                  if held_locks is None else held_locks[index])
            if held_locks is None:
                descriptors.append(fd)
            info = os.fstat(fd)
            link = path.lstat()
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_nlink != 1
                    or stat.S_IMODE(info.st_mode) != 0o600
                    or (info.st_dev, info.st_ino) != (link.st_dev, link.st_ino)):
                raise SafeError('ORDER_FULFILLMENT_LOCK_UNSAFE')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise SafeError('ORDER_FULFILLMENT_ANOTHER_INSTALL_RUNNING') from None
        yield
    finally:
        for fd in descriptors:
            os.close(fd)


READINESS_PHP = r'''
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
try {
 $pins=json_decode($argv[1]??'',true,16,JSON_THROW_ON_ERROR);
 if(!is_array($pins)||count($pins)!==1)throw new \RuntimeException();
 // Verify the entire declared code set before executing Composer or any candidate.
 foreach($pins as $relative=>$expected){
  $path=getcwd().'/'.$relative;$info=lstat($path);
  if($expected===null){if($info!==false)throw new \RuntimeException();continue;}
  if(!$info||($info['mode']&0170000)!==0100000||realpath($path)!==$path)throw new \RuntimeException();
  $bytes=file_get_contents($path);
  if(sha1('blob '.strlen($bytes)."\0".$bytes)!==$expected)throw new \RuntimeException();
 }
 require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
 $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
 if($app->getCachedConfigPath()!==getcwd().'/bootstrap/cache/config.php')throw new \RuntimeException();
 foreach($pins as $relative=>$expected){
  if($expected===null)continue;
  $class='App\\'.str_replace('/','\\',substr($relative,4,-4));
  $r=new \ReflectionClass($class);$path=getcwd().'/'.$relative;
  if($r->getFileName()!==$path)throw new \RuntimeException();
  $bytes=file_get_contents($path);
  if(sha1('blob '.strlen($bytes)."\0".$bytes)!==$expected)throw new \RuntimeException();
 }
 $class=\App\Services\Dashboard\WhatsAppOrderWorkflow::class;
 if(!app($class)->available()||!array_key_exists('whatsapp:process-orders',$kernel->all()))throw new \RuntimeException();
 foreach([
  'whatsapp_reply_requests'=>['id','conversation_id','actor_id','waba_id','phone_number_id','state','kind','audit','response_details','completed_at'],
  'whatsapp_reply_projection_keys'=>['id','reply_id','conversation_id','waba_id','phone_number_id','kind','message_key','receipt_hash','valid'],
 ]as$table=>$required){
  if(!\Illuminate\Support\Facades\Schema::hasTable($table))throw new \RuntimeException();
  $columns=\Illuminate\Support\Facades\Schema::getColumnListing($table);
  foreach($required as$column)if(!in_array($column,$columns,true))throw new \RuntimeException();
 }
 $mode=config('whatsapp_orders.mode');
 if(!in_array($mode,['review','auto'],true))throw new \RuntimeException();
 $value=['mode'=>$mode,'fingerprint'=>hash('sha256',serialize(config()->all()))];
 while(ob_get_level()>0)ob_end_clean();echo json_encode($value,JSON_THROW_ON_ERROR);
}catch(\Throwable $e){exit(1);}
'''


def readiness(root, runner, pins):
    code, output = runner(root, [PHP, '-r', READINESS_PHP, json.dumps(pins, separators=(',', ':'))], capture=True)
    try:
        value = json.loads(output) if code == 0 else None
    except (ValueError, TypeError):
        value = None
    if (not isinstance(value, dict) or set(value) != {'mode', 'fingerprint'}
            or value['mode'] not in ('review', 'auto') or not isinstance(value['fingerprint'], str)
            or not re.fullmatch('[a-f0-9]{64}', value['fingerprint'])):
        raise SafeError('ORDER_FULFILLMENT_READ_ONLY_BOOTSTRAP_FAILED')
    return value


def require_quiet(root, runner):
    for name in UNITS:
        args=['/usr/bin/systemctl', 'show', '--no-pager', '--property=LoadState', '--property=ActiveState']
        if name.endswith('.service'):
            args.append('--property=MainPID')
        code, output = runner(root, args+[name], capture=True)
        try:
            lines=output.decode('ascii').strip().splitlines(); values=dict(line.split('=',1) for line in lines)
            expected={'LoadState','ActiveState'}|({'MainPID'} if name.endswith('.service') else set())
            if (code!=0 or len(lines)!=len(values) or set(values)!=expected
                    or values['LoadState']!='loaded' or values['ActiveState'] not in ('inactive','failed')
                    or ('MainPID' in values and values['MainPID']!='0')):
                raise ValueError()
        except (ValueError, UnicodeError):
            raise SafeError('ORDER_FULFILLMENT_WORKERS_NOT_QUIESCENT') from None


def private_write(path, content):
    temporary=path.with_name('.'+path.name+'.temporary')
    fd=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(content);stream.flush();os.fsync(stream.fileno())
        base.atomic_move_noreplace(temporary,path)
        directory=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(directory)
        finally:os.close(directory)
    finally:
        if temporary.exists():temporary.unlink()


def release_sources(root, uid, release, runner, pins):
    if not re.fullmatch('[0-9a-f]{40}', release):
        raise SafeError('ORDER_FULFILLMENT_RELEASE_INVALID')
    code, kind=runner(root,['git','cat-file','-t',release],capture=True)
    if code!=0 or kind.strip()!=b'commit':
        raise SafeError('ORDER_FULFILLMENT_RELEASE_NOT_COMMIT')
    sources={}
    for relative, (_, target) in pins.items():
        if not re.fullmatch('[0-9a-f]{40}',target):
            raise SafeError('ORDER_FULFILLMENT_PIN_INVALID')
        code, tree=runner(root,['git','ls-tree','-z',release,relative],capture=True)
        expected=b'100644 blob '+target.encode()+b'\t'+relative.encode()+b'\0'
        if code!=0 or tree!=expected:
            raise SafeError('ORDER_FULFILLMENT_RELEASE_SOURCE_MISMATCH')
        code, content=runner(root,['git','show',release+':'+relative],capture=True)
        if code!=0 or not content or len(content)>8388608 or blob(content)!=target:
            raise SafeError('ORDER_FULFILLMENT_RELEASE_BLOB_MISMATCH')
        sources[relative]=content
    return sources


def protected_fingerprint(values):
    rows=[(name,signature,blob(item.content)) for name,(item,signature) in sorted(values.items())]
    return hashlib.sha256(json.dumps(rows,separators=(',',':')).encode()).hexdigest()


def verify_state(root,uid,expected_configuration,expected_sources,expected_protected,held_locks=None):
    if (not isinstance(expected_sources,dict) or set(expected_sources)!=set(SOURCE_PINS)
            or any(value not in SOURCE_PINS[name] for name,value in expected_sources.items())
            or not isinstance(expected_configuration,str) or not re.fullmatch('[a-f0-9]{64}',expected_configuration)
            or not isinstance(expected_protected,str) or not re.fullmatch('[a-f0-9]{64}',expected_protected)):
        raise SafeError('ORDER_FULFILLMENT_STATE_SCOPE_INVALID')
    base.validate_root(root,uid)
    with locks(root,uid,held_locks):
        configuration=configuration_snapshot(root,uid)
        if configuration_fingerprint(configuration)!=expected_configuration:
            raise SafeError('ORDER_FULFILLMENT_STATE_CONFIGURATION_CHANGED')
        protected={name:guarded_snapshot(root/name,uid,root) for name in PROTECTED_PINS}
        if (any(blob(item.content)!=PROTECTED_PINS[name] for name,(item,_) in protected.items())
                or protected_fingerprint(protected)!=expected_protected):
            raise SafeError('ORDER_FULFILLMENT_STATE_PROTECTED_CHANGED')
        sources={name:guarded_snapshot(root/name,uid,root,absent_ok=expected is None)
                 for name,expected in expected_sources.items()}
        observed={name:None if item is None else blob(item.content) for name,(item,_) in sources.items()}
        if observed!=expected_sources:raise SafeError('ORDER_FULFILLMENT_STATE_SOURCE_CHANGED')
        # Bind the opened snapshot and complete config/protected roster again,
        # after all reads. The caller immediately guards each worker restart.
        require_configuration(root,uid,configuration)
        if any(guarded_snapshot(root/name,uid,root)!=value for name,value in protected.items()):
            raise SafeError('ORDER_FULFILLMENT_STATE_PROTECTED_CHANGED')
        if any(guarded_snapshot(root/name,uid,root,absent_ok=True)!=value for name,value in sources.items()):
            raise SafeError('ORDER_FULFILLMENT_STATE_SOURCE_CHANGED')
        return {'status':'WHATSAPP_ORDER_FULFILLMENT_STATE_READY',
                'configuration_fingerprint':expected_configuration,'sources':observed,
                'protected_fingerprint':expected_protected}


def apply(root, uid, release, check_only=False, runner=base.command, pins=None, held_locks=None,
          expected_configuration=None):
    pins=SOURCE_PINS if pins is None else pins
    if tuple(pins)!=tuple(SOURCE_PINS):
        raise SafeError('ORDER_FULFILLMENT_SCOPE_INVALID')
    base.validate_root(root, uid)
    with locks(root, uid, held_locks):
        config_before=configuration_snapshot(root,uid)
        config_digest=configuration_fingerprint(config_before)
        if expected_configuration is not None and config_digest!=expected_configuration:
            raise SafeError('ORDER_FULFILLMENT_CONFIGURATION_CHANGED_SINCE_PREFLIGHT')
        protected={p:guarded_snapshot(root/p,uid,root) for p in PROTECTED_PINS}
        if any(blob(value[0].content)!=PROTECTED_PINS[p] for p,value in protected.items()):
            raise SafeError('ORDER_FULFILLMENT_PROTECTED_SOURCE_MISMATCH')
        protected_digest=protected_fingerprint(protected)
        def unchanged():
            require_configuration(root,uid,config_before)
            for p,value in protected.items():
                if guarded_snapshot(root/p,uid,root)!=value:
                    raise SafeError('ORDER_FULFILLMENT_PROTECTED_SOURCE_CHANGED')
        before={relative:guarded_snapshot(root/relative,uid,root,absent_ok=old is None)
                for relative,(old,_) in pins.items()}
        observed={relative:None if value[0] is None else blob(value[0].content) for relative,value in before.items()}
        for relative, (old,target) in pins.items():
            if observed[relative] not in (old,target):
                raise SafeError('ORDER_FULFILLMENT_LIVE_SOURCE_NEEDS_REVIEW')
        sources=release_sources(root,uid,release,runner,pins)
        initial=readiness(root,runner,observed)
        unchanged()
        parent=root.parent/'whatsapp-release-backups'
        # Lint private disposable candidates before changing any application file.
        with tempfile.TemporaryDirectory(prefix='.order-fulfillment-lint-',dir=parent) as folder:
            for index,(relative,content) in enumerate(sources.items()):
                staged=pathlib.Path(folder)/(str(index)+'.php');private_write(staged,content)
                code,_=runner(root,[PHP,'-l',str(staged)])
                if code!=0 or staged.read_bytes()!=content:
                    raise SafeError('ORDER_FULFILLMENT_CANDIDATE_LINT_FAILED')
        unchanged()
        for relative,(item,signature) in before.items():
            if guarded_snapshot(root/relative,uid,root,absent_ok=True)!=(item,signature):
                raise SafeError('ORDER_FULFILLMENT_SOURCE_CHANGED')
        if check_only:
            return {'status':'WHATSAPP_ORDER_FULFILLMENT_PREFLIGHT_READY','changed':any(observed[p]!=pins[p][1] for p in pins),
                    'configuration_fingerprint':config_digest,'sources':observed,'protected_fingerprint':protected_digest}
        require_quiet(root,runner)
        if all(observed[p]==pins[p][1] for p in pins):
            final=readiness(root,runner,observed)
            unchanged()
            if final!=initial:
                raise SafeError('ORDER_FULFILLMENT_EFFECTIVE_CONFIGURATION_CHANGED')
            return {'status':'WHATSAPP_ORDER_FULFILLMENT_ALREADY_CURRENT','backup':None,
                    'configuration_fingerprint':config_digest,'sources':observed,'protected_fingerprint':protected_digest}
        backup=pathlib.Path(tempfile.mkdtemp(prefix='order-fulfillment-',dir=parent));backup.chmod(0o700)
        if backup.stat().st_dev!=root.stat().st_dev:
            raise SafeError('ORDER_FULFILLMENT_BACKUP_FILESYSTEM_MISMATCH')
        publications=backup/'publications';publications.mkdir(mode=0o700);base.probe_atomic_exchange(publications)
        private_write(backup/'receipt.json',(json.dumps({'version':1,'release':release,'sources':observed,
                      'targets':{p:v[1] for p,v in pins.items()},
                      'protected':PROTECTED_PINS,'protected_fingerprint':protected_digest,
                      'configuration_fingerprint':config_digest},sort_keys=True)+'\n').encode())
        written={};created=[]
        try:
            for relative,content in sources.items():
                if observed[relative]==pins[relative][1]:
                    continue
                unchanged()
                base.replace_file(root/relative,content,before[relative][0],uid,root,created,
                    journal=written,relative=relative,private_directory=publications)
            require_quiet(root,runner)
            unchanged()
            final=readiness(root,runner,{p:v[1] for p,v in pins.items()})
            if final!=initial:
                raise SafeError('ORDER_FULFILLMENT_EFFECTIVE_CONFIGURATION_CHANGED')
            for relative in pins:
                base.assert_snapshot(root/relative,written[relative].after if relative in written else before[relative][0],uid,root)
            unchanged()
        except BaseException:
            restored=True
            with base.blocked_signals():
                for publication in reversed(list(written.values())):
                    try:
                        if not base.recover_publication(publication,root,uid):
                            restored=False;break
                    except BaseException:
                        restored=False;break
                if restored:
                    for directory,inode in reversed(created):
                        try:
                            info=directory.lstat()
                            if stat.S_ISDIR(info.st_mode) and info.st_uid==uid and info.st_ino==inode:directory.rmdir()
                        except OSError:pass
            # The four workers are paused, but HTTP requests are not. An HTTP
            # process may retain the newly loaded AI provider after byte rollback.
            # Do not resume old order code even if no order has been processed.
            exposure=any(p in written for p in (
                PREFIX+'WhatsAppOrderAiProvider.php',))
            try:unchanged();safe=restored and not exposure
            except (SafeError,OSError):safe=False
            raise SafeError(('ORDER_FULFILLMENT_FILES_RESTORED' if safe else
                'ORDER_FULFILLMENT_ROLLBACK_REVIEW_REQUIRED')+'; BACKUP='+str(backup)) from None
        return {'status':'WHATSAPP_ORDER_FULFILLMENT_REPAIR_READY','backup':str(backup),
                'configuration_fingerprint':config_digest,'sources':{p:v[1] for p,v in pins.items()},
                'protected_fingerprint':protected_digest}


def main():
    import argparse
    parser=argparse.ArgumentParser(add_help=False)
    parser.add_argument('--root',required=True);parser.add_argument('--release',required=True)
    parser.add_argument('--check-only',action='store_true')
    args=parser.parse_args()
    try:
        def interrupted(signum,frame):raise KeyboardInterrupt()
        signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
        root=pathlib.Path(args.root);uid=pwd.getpwnam('fasakha').pw_uid
        if root!=APP_ROOT or os.geteuid()!=uid:raise SafeError('ORDER_FULFILLMENT_EXECUTION_CONTEXT_INVALID')
        result=apply(root,uid,args.release,args.check_only)
        print(json.dumps(result,sort_keys=True))
    except BaseException as error:
        message=str(error) if isinstance(error,SafeError) else 'ORDER_FULFILLMENT_STOPPED_WITHOUT_SECRET_OUTPUT'
        print(message,file=sys.stderr);raise SystemExit(1)


if __name__=='__main__':main()
