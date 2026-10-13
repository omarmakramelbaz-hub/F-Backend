#!/usr/bin/env python3
"""Publish pinned order evidence files without reactivating or processing orders."""
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
# Dependency first; entrypoints last. Release pins are frozen after the tests.
SOURCE_PINS = {
    PREFIX+'WhatsAppAcceptedReplyEvidence.php': (None, '4b571f7f4a5cef9257c414886dba515c06dae2a1'),
    PREFIX+'WhatsAppInboxMessage.php': ('087cdbdf51dd87c411da3ed0cfa33980474b593f', 'bedd91db5266d83bdce84a04836e988518891728'),
    PREFIX+'WhatsAppOrderAiProvider.php': ('afac5b86bb3076a6caaea85c4a22d097a22e16b8', '65b9ce193991ae404e287ea62091ec48595bc530'),
    PREFIX+'WhatsAppInboxConsumer.php': ('f993ff1eebc9c2a7be3b0bec93ab02732b96a46d', 'e54715313901be8baefb551566b7bb88f4afe45b'),
    PREFIX+'WhatsAppReplyService.php': ('cce92c02d4a4400cd133fc1f7631fb94762f54c6', '1ef23df825287f8d3dedafcdb5d70ffeac7f1f29'),
    'app/Http/Controllers/Dashboard/WhatsAppInboxController.php': ('6f45b964d13504442539ced4c5862dfbbe921381', '7ce48a97f115eab4e1f2308c411d0dbc7f5d5f3a'),
    PREFIX+'WhatsAppOrderWorkflow.php': ('6c63b91d7fb70130ae472cea291fa2cee88b27dd', 'b30f25cf6dfc4a84061a58316db583f892a439a4'),
}
LOCK_NAMES = ('orders-install.lock', 'inbox-install.lock')
UNITS = tuple('fasakhansta-whatsapp-'+name for name in
              ('capture.timer', 'capture.service', 'orders.timer', 'orders.service'))
PROTECTED_PINS = {
    'app/Support/WhatsAppOrderExtraction.php': '0411582129f36ceb1c0d634245f37a09b445efea',
    'app/Support/WhatsAppInboxProtocol.php': 'ecde67c6a87ca8068bb077892fdbea522d89f217',
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
            raise RuntimeError('ORDER_REPAIR_BASE_PATH_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid())
                or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > 1048576):
            raise RuntimeError('ORDER_REPAIR_BASE_FILE_UNSAFE')
        content = os.read(fd, 1048577)
        after = os.fstat(fd)
        if (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise RuntimeError('ORDER_REPAIR_BASE_CHANGED')
    finally:
        os.close(fd)
    if blob(content) != BASE_BLOB:
        raise RuntimeError('ORDER_REPAIR_BASE_HASH_MISMATCH')
    name = 'verified_whatsapp_order_evidence_base'
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
        raise SafeError('ORDER_REPAIR_ROOT_CONFIGURATION_SCOPE_INVALID')
    base.safe_ancestors(path,uid,root)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        info=os.fstat(fd)
        def signature(value):
            return (value.st_uid,value.st_gid,stat.S_IMODE(value.st_mode),value.st_dev,
                    value.st_ino,value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_nlink!=1
                or stat.S_IMODE(info.st_mode)!=0o644 or info.st_size>8388608):
            raise SafeError('ORDER_REPAIR_ROOT_CONFIGURATION_PROFILE_NEEDS_REVIEW')
        if signature(info)!=expected_metadata or metadata(path)!=expected_metadata:
            raise SafeError('ORDER_REPAIR_ROOT_CONFIGURATION_CHANGED_DURING_READ')
        with os.fdopen(fd,'rb',closefd=False) as stream:
            content=stream.read(8388609)
        if (len(content)>8388608 or signature(os.fstat(fd))!=expected_metadata
                or metadata(path)!=expected_metadata):
            raise SafeError('ORDER_REPAIR_ROOT_CONFIGURATION_CHANGED_DURING_READ')
        return base.Snapshot(content,info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode),info.st_dev,info.st_ino)
    finally:os.close(fd)


def guarded_snapshot(path, uid, root, absent_ok=False, configuration=False):
    try:
        before = metadata(path)
    except FileNotFoundError:
        if absent_ok:
            base.safe_ancestors(path, uid, root)
            return None, None
        raise SafeError('ORDER_REPAIR_REQUIRED_FILE_MISSING') from None
    # Atomic replacement retains legacy private hardlinks without modifying them.
    private = configuration and path in (root/'.env',root/'bootstrap/cache/config.php')
    if before[2] & 0o022 or (private and (before[5] != 1 or before[2] & 0o077)):
        raise SafeError('ORDER_REPAIR_MODE_OR_CONFIGURATION_LINK_UNSAFE')
    if configuration and before[0]==0 and path in (root/name for name in ROOT_READ_ONLY_CONFIGURATION):
        item=root_configuration_snapshot(path,uid,root,before)
    else:
        item = base.snapshot(path, uid, root)
    if metadata(path) != before:
        raise SafeError('ORDER_REPAIR_FILE_CHANGED_DURING_READ')
    return item, before


def configuration_snapshot(root, uid):
    directory = root/'config'
    base.safe_ancestors(directory/'probe', uid, root)
    info = directory.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or info.st_mode & 0o022:
        raise SafeError('ORDER_REPAIR_CONFIGURATION_DIRECTORY_UNSAFE')
    paths = ['.env', 'bootstrap/cache/config.php']
    for path in sorted(directory.iterdir()):
        # Include all existing direct files, rather than a selected WhatsApp subset.
        if path.is_dir() and not path.is_symlink():
            raise SafeError('ORDER_REPAIR_NESTED_CONFIGURATION_NEEDS_REVIEW')
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
        raise SafeError('ORDER_REPAIR_CONFIGURATION_CHANGED')


@contextlib.contextmanager
def locks(root, uid, held_locks=None):
    parent = root.parent/'whatsapp-release-backups'
    base.private_parent(parent, uid)
    descriptors = []
    try:
        if held_locks is not None and len(held_locks) != len(LOCK_NAMES):
            raise SafeError('ORDER_REPAIR_LOCK_HANDOFF_INVALID')
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
                raise SafeError('ORDER_REPAIR_LOCK_UNSAFE')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise SafeError('ORDER_REPAIR_ANOTHER_INSTALL_RUNNING') from None
        yield
    finally:
        for fd in descriptors:
            os.close(fd)


READINESS_PHP = r'''
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
try {
 $pins=json_decode($argv[1]??'',true,16,JSON_THROW_ON_ERROR);
 if(!is_array($pins)||count($pins)!==7)throw new \RuntimeException();
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

ROLLBACK_COMPATIBILITY_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try{
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 // Web requests remain active during this narrow code publication. Never restart
 // baseline order code over a new accepted-evidence envelope created meanwhile.
 $present=\Illuminate\Support\Facades\DB::table('whatsapp_inbox_messages as m')
  ->join('whatsapp_inbox_conversations as c','c.id','=','m.conversation_id')
  ->where('c.waba_id','468336579702269')->where('c.phone_number_id','515388018324075')
  ->where('m.source','dashboard_api')->exists();
 while(ob_get_level()>0)ob_end_clean();echo json_encode(['compatible'=>!$present],JSON_THROW_ON_ERROR);
}catch(\Throwable $e){exit(1);}
'''


def rollback_compatible(root,runner):
    code,output=runner(root,[PHP,'-r',ROLLBACK_COMPATIBILITY_PHP],capture=True)
    try:value=json.loads(output) if code==0 else None
    except (ValueError,TypeError):return False
    return isinstance(value,dict) and set(value)=={'compatible'} and value['compatible'] is True


def readiness(root, runner, pins):
    code, output = runner(root, [PHP, '-r', READINESS_PHP, json.dumps(pins, separators=(',', ':'))], capture=True)
    try:
        value = json.loads(output) if code == 0 else None
    except (ValueError, TypeError):
        value = None
    if (not isinstance(value, dict) or set(value) != {'mode', 'fingerprint'}
            or value['mode'] not in ('review', 'auto') or not isinstance(value['fingerprint'], str)
            or not re.fullmatch('[a-f0-9]{64}', value['fingerprint'])):
        raise SafeError('ORDER_REPAIR_READ_ONLY_BOOTSTRAP_FAILED')
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
            raise SafeError('ORDER_REPAIR_WORKERS_NOT_QUIESCENT') from None


def private_write(path, content):
    fd=os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd,'wb') as stream:
        stream.write(content); stream.flush(); os.fsync(stream.fileno())


def release_sources(root, uid, release, runner, pins):
    if not re.fullmatch('[0-9a-f]{40}', release):
        raise SafeError('ORDER_REPAIR_RELEASE_INVALID')
    code, kind=runner(root,['git','cat-file','-t',release],capture=True)
    if code!=0 or kind.strip()!=b'commit':
        raise SafeError('ORDER_REPAIR_RELEASE_NOT_COMMIT')
    sources={}
    for relative, (_, target) in pins.items():
        if not re.fullmatch('[0-9a-f]{40}',target):
            raise SafeError('ORDER_REPAIR_PIN_INVALID')
        code, tree=runner(root,['git','ls-tree','-z',release,relative],capture=True)
        expected=b'100644 blob '+target.encode()+b'\t'+relative.encode()+b'\0'
        if code!=0 or tree!=expected:
            raise SafeError('ORDER_REPAIR_RELEASE_SOURCE_MISMATCH')
        code, content=runner(root,['git','show',release+':'+relative],capture=True)
        if code!=0 or not content or len(content)>8388608 or blob(content)!=target:
            raise SafeError('ORDER_REPAIR_RELEASE_BLOB_MISMATCH')
        sources[relative]=content
    return sources


def apply(root, uid, release, check_only=False, runner=base.command, pins=None, held_locks=None,
          expected_configuration=None):
    pins=SOURCE_PINS if pins is None else pins
    if tuple(pins)!=tuple(SOURCE_PINS):
        raise SafeError('ORDER_REPAIR_SCOPE_INVALID')
    base.validate_root(root, uid)
    with locks(root, uid, held_locks):
        config_before=configuration_snapshot(root,uid)
        config_digest=configuration_fingerprint(config_before)
        if expected_configuration is not None and config_digest!=expected_configuration:
            raise SafeError('ORDER_REPAIR_CONFIGURATION_CHANGED_SINCE_PREFLIGHT')
        protected={p:guarded_snapshot(root/p,uid,root) for p in PROTECTED_PINS}
        if any(blob(value[0].content)!=PROTECTED_PINS[p] for p,value in protected.items()):
            raise SafeError('ORDER_REPAIR_PROTECTED_SOURCE_MISMATCH')
        def unchanged():
            require_configuration(root,uid,config_before)
            for p,value in protected.items():
                if guarded_snapshot(root/p,uid,root)!=value:
                    raise SafeError('ORDER_REPAIR_PROTECTED_SOURCE_CHANGED')
        before={relative:guarded_snapshot(root/relative,uid,root,absent_ok=old is None)
                for relative,(old,_) in pins.items()}
        observed={relative:None if value[0] is None else blob(value[0].content) for relative,value in before.items()}
        for relative, (old,target) in pins.items():
            if observed[relative] not in (old,target):
                raise SafeError('ORDER_REPAIR_LIVE_SOURCE_NEEDS_REVIEW')
        sources=release_sources(root,uid,release,runner,pins)
        initial=readiness(root,runner,observed)
        unchanged()
        parent=root.parent/'whatsapp-release-backups'
        # Lint private disposable candidates before changing any application file.
        with tempfile.TemporaryDirectory(prefix='.order-evidence-lint-',dir=parent) as folder:
            for index,(relative,content) in enumerate(sources.items()):
                staged=pathlib.Path(folder)/(str(index)+'.php');private_write(staged,content)
                code,_=runner(root,[PHP,'-l',str(staged)])
                if code!=0 or staged.read_bytes()!=content:
                    raise SafeError('ORDER_REPAIR_CANDIDATE_LINT_FAILED')
        unchanged()
        for relative,(item,signature) in before.items():
            if guarded_snapshot(root/relative,uid,root,absent_ok=True)!=(item,signature):
                raise SafeError('ORDER_REPAIR_SOURCE_CHANGED')
        if check_only:
            return {'status':'WHATSAPP_ORDER_EVIDENCE_PREFLIGHT_READY','changed':any(observed[p]!=pins[p][1] for p in pins),
                    'configuration_fingerprint':config_digest}
        require_quiet(root,runner)
        if all(observed[p]==pins[p][1] for p in pins):
            final=readiness(root,runner,observed)
            unchanged()
            if final!=initial:
                raise SafeError('ORDER_REPAIR_EFFECTIVE_CONFIGURATION_CHANGED')
            return {'status':'WHATSAPP_ORDER_EVIDENCE_ALREADY_CURRENT','backup':None,
                    'configuration_fingerprint':config_digest}
        backup=pathlib.Path(tempfile.mkdtemp(prefix='order-evidence-',dir=parent));backup.chmod(0o700)
        if backup.stat().st_dev!=root.stat().st_dev:
            raise SafeError('ORDER_REPAIR_BACKUP_FILESYSTEM_MISMATCH')
        publications=backup/'publications';publications.mkdir(mode=0o700);base.probe_atomic_exchange(publications)
        private_write(backup/'receipt.json',(json.dumps({'release':release,'sources':observed,
                      'targets':{p:v[1] for p,v in pins.items()}},sort_keys=True)+'\n').encode())
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
                raise SafeError('ORDER_REPAIR_EFFECTIVE_CONFIGURATION_CHANGED')
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
            # process may retain a newly loaded entrypoint after byte rollback.
            # Do not resume old order code even if the evidence SELECT is empty.
            exposure=any(p in written for p in (
                PREFIX+'WhatsAppInboxMessage.php',PREFIX+'WhatsAppInboxConsumer.php',
                PREFIX+'WhatsAppReplyService.php',PREFIX+'WhatsAppOrderWorkflow.php',
                'app/Http/Controllers/Dashboard/WhatsAppInboxController.php'))
            try:unchanged();safe=restored and not exposure and rollback_compatible(root,runner)
            except (SafeError,OSError):safe=False
            raise SafeError(('ORDER_REPAIR_FILES_RESTORED' if safe else
                'ORDER_REPAIR_ROLLBACK_REVIEW_REQUIRED')+'; BACKUP='+str(backup)) from None
        return {'status':'WHATSAPP_ORDER_EVIDENCE_REPAIR_READY','backup':str(backup),
                'configuration_fingerprint':config_digest}


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
        if root!=APP_ROOT or os.geteuid()!=uid:raise SafeError('ORDER_REPAIR_EXECUTION_CONTEXT_INVALID')
        result=apply(root,uid,args.release,args.check_only)
        print(json.dumps(result,sort_keys=True))
    except BaseException as error:
        message=str(error) if isinstance(error,SafeError) else 'ORDER_REPAIR_STOPPED_WITHOUT_SECRET_OUTPUT'
        print(message,file=sys.stderr);raise SystemExit(1)


if __name__=='__main__':main()
