#!/usr/bin/env bash
# Enable only the reviewed pipeline, preserving capture availability on failure.
set -euo pipefail
exec /usr/bin/python3 - "$@" <<'PY'
import hashlib
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import subprocess
import sys
import tempfile

ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
SYSTEMCTL = '/usr/bin/systemctl'
RUNUSER = '/usr/sbin/runuser'
CAPTURE_TIMER = 'fasakhansta-whatsapp-capture.timer'
CAPTURE_SERVICE = 'fasakhansta-whatsapp-capture.service'
ORDER_TIMER = 'fasakhansta-whatsapp-orders.timer'
ORDER_SERVICE = 'fasakhansta-whatsapp-orders.service'
HELPER_PINS = {
    'activate_whatsapp_automatic.py':'008fff7a56e4c9d2ff849df6f653dfb6b6427371',
    'configure_whatsapp_orders.py':'a0780b40adc404f81b5bd2b9934b50d13dc0797a',
    'rotate_whatsapp_messaging_token.py':'b824ea3c09f0314d5debdb278a0d02d37f41bfce',
    'rotate_whatsapp_catalog_token.py':'eaf40b872ec1dde0cbffa08e507e56a03f62591f',
    'install_whatsapp_inbox.py':'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98',
    'install_whatsapp_automatic_root.sh':'4ac9bb0426407b349fba7e1ac8fabb830c566617',
    'install_whatsapp_capture_root.sh':'c3571f9cf4ab37171c9d6cbf2fc8ddae9e067316',
    'whatsapp_ai_connection_check.php':'fa0bf7a51bff932c274c34398bddbc6aeed04e30',
}
SOURCE_PINS = {
    'app/Services/Dashboard/WhatsAppReplyService.php':'7047caefba93935a25029ea99715dac645d9c24d',
    'public/js/dashboard-whatsapp-inbox.js':'19117235ba454c5e73bbd291920f9f56c84ebea8',
    'public/js/dashboard-whatsapp-replies.js':'304a0bc6acd2474adb7850664708f30bddbd6a29',
    'resources/views/admin/whatsapp/index.blade.php':'d56fe43423728bb9d48820cc51b34522cc1ad7cc',
    'resources/views/admin/whatsapp/replies.blade.php':'ac114cfb5524f91a7e8753e75a751d82b43af965',
    'resources/views/admin/layouts/footer.blade.php':'886f076b7d57dc4d71fd24e63c27bd9fcf34f11e',
    'app/Services/Dashboard/WhatsAppOrderWorkflow.php':'76af7b3eb0f5d70ec0c35e524e74ac5cb141b126',
    'app/Services/Dashboard/WhatsAppOrderAiProvider.php':'afac5b86bb3076a6caaea85c4a22d097a22e16b8',
    'app/Support/WhatsAppOrderExtraction.php':'0411582129f36ceb1c0d634245f37a09b445efea',
    'app/Console/Commands/ProcessWhatsAppOrders.php':'b7b18533efe8eb608315e0efbc6740080b25e1a7',
    'app/Services/Dashboard/WhatsAppInboxConsumer.php':'f993ff1eebc9c2a7be3b0bec93ab02732b96a46d',
    'app/Console/Commands/ConsumeWhatsAppInbox.php':'dbd621a5117f2e92fd2f80d004305fcbcc467d4a',
    'app/Support/WhatsAppInboxProtocol.php':'7a2f342c86e0838fb920bfdea53d6542b01cd008',
    'config/whatsapp_orders.php':'88e3366e17d0d011b5c118220423e444adc9c2ad',
    'config/whatsapp_cart.php':'fc02576f4e7896c7cee33152561ca76c69b669cd',
    'config/whatsapp_replies.php':'c5ede60634743a6170b143a4fd4fd382d6afe9d6',
    'routes/whatsapp_orders.php':'b1932548cd0cd3af5d35bf2cc489df42f9faa8e9',
    'database/migrations/2026_10_10_000002_create_whatsapp_order_drafts.php':'5e8a0cb6b4f2f085fb6b35cc930570c7cc94a87b',
    'app/Services/Dashboard/PhoneDelivery.php':'cd4f5daddbf1ed462d04f7fa24b4966cb94608c9',
    'app/Services/Dashboard/BranchCustomers.php':'6889e9517410708fd4738d290d5551b32b467c5d',
    'app/Services/Dashboard/PosServiceTicket.php':'87b24fb862d5d6fbc4be7a62f437bb50aba03be7',
    'app/Services/Dashboard/TakeawayCatalog.php':'49943493fffb5405c87a3001f36c339caa2edf31',
    'app/Services/Dashboard/TakeawayAccess.php':'6b27b7ac2faff6fdaf952494f90f2d8378a12484',
    'app/Services/Dashboard/PosServicePhone.php':'23e9226064ade62b28b0d4fde2ded631ca7b8d73',
}
CART_PATH = 'config/whatsapp_cart.php'
CAPTURE_PINS = {path: SOURCE_PINS[path] for path in (
    'app/Services/Dashboard/WhatsAppInboxConsumer.php', 'app/Console/Commands/ConsumeWhatsAppInbox.php')}


class SafeError(Exception):
    pass


def blob(content):
    return hashlib.sha1(b'blob '+str(len(content)).encode()+b'\0'+content).hexdigest()


def terminate_group(process):
    """Quiesce runuser and every descendant before recovery touches either timer."""
    try:os.killpg(process.pid,signal.SIGTERM)
    except ProcessLookupError:pass
    try:
        output=process.communicate(timeout=20)
    except subprocess.TimeoutExpired:
        try:os.killpg(process.pid,signal.SIGKILL)
        except ProcessLookupError:pass
        output=process.communicate(timeout=10)
    # A child may redirect/close both pipes and ignore TERM after its leader exits.
    # Kill remaining group members before allowing any timer recovery to begin.
    try:os.killpg(process.pid,signal.SIGKILL)
    except ProcessLookupError:pass
    process.wait(timeout=10)
    return output


def command(arguments, root=ROOT, timeout=60):
    try:
        process=subprocess.Popen(arguments,cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                                 start_new_session=True)
    except OSError:
        return 1,b''
    try:
        stdout,stderr=process.communicate(timeout=timeout);code=process.returncode
    except subprocess.TimeoutExpired:
        stdout,stderr=terminate_group(process);code=1
    except BaseException:
        terminate_group(process)
        raise
    output=stdout+stderr
    return code,output if len(output)<=1048576 else b''


def read_guarded(path, owner, expected, helper=False):
    for parent in reversed(path.parents):
        s=parent.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid not in (0,owner):
            raise SafeError('AUTOMATIC_GUARDED_PARENT_UNSAFE')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        s=os.fstat(fd)
        if (not stat.S_ISREG(s.st_mode) or s.st_uid!=owner or s.st_mode&0o022
                or (helper and s.st_nlink!=1) or s.st_size>2097152):
            raise SafeError('AUTOMATIC_GUARDED_FILE_UNSAFE')
        content=os.read(fd,2097153); after=os.fstat(fd)
        signature=lambda x:(x.st_uid,x.st_gid,stat.S_IMODE(x.st_mode),x.st_dev,x.st_ino,x.st_nlink,
                            x.st_size,x.st_mtime_ns,x.st_ctime_ns)
        if signature(after)!=signature(s) or blob(content)!=expected:
            raise SafeError('AUTOMATIC_GUARDED_BLOB_OR_RACE_FAILED')
        return content,signature(s)
    finally:
        os.close(fd)


def guard_sources(root,uid,pins=SOURCE_PINS):
    result={}
    for relative,expected in pins.items():
        try: result[relative]=read_guarded(root/relative,uid,expected)[1]
        except (SafeError,OSError): raise SafeError('AUTOMATIC_LIVE_SOURCE_NEEDS_REVIEW: '+relative) from None
    return result


def verify_helpers(payload):
    return {name:read_guarded(payload/name,0,pin,helper=True)[0] for name,pin in HELPER_PINS.items()}


def stage_helpers(directory,release,runner=command):
    code,kind=runner([RUNUSER,'-u','fasakha','--','git','-C',str(ROOT),'cat-file','-t',release])
    if code!=0 or kind.strip()!=b'commit':raise SafeError('AUTOMATIC_RELEASE_NOT_A_COMMIT')
    for name,pin in HELPER_PINS.items():
        code,content=runner([RUNUSER,'-u','fasakha','--','git','-C',str(ROOT),'show',release+':deployment/'+name])
        if code!=0 or not content or blob(content)!=pin:raise SafeError('AUTOMATIC_HELPER_PIN_FAILED: '+name)
        fd=os.open(directory/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
        with os.fdopen(fd,'wb')as stream:
            stream.write(content);stream.flush();os.fchmod(stream.fileno(),0o644);os.fsync(stream.fileno())
    verify_helpers(directory)


def unit_validator(payload):
    programs={}
    for kind,name in (('capture','install_whatsapp_capture_root.sh'),('orders','install_whatsapp_automatic_root.sh')):
        content=read_guarded(payload/name,0,HELPER_PINS[name],helper=True)[0].decode()
        marker='exec /usr/bin/python3 - "$@" <<\'PY\'\n'
        if content.count(marker)!=1 or not content.endswith('\nPY\n'):raise SafeError('AUTOMATIC_UNIT_HELPER_FORMAT_INVALID')
        code=content.split(marker,1)[1][:-len('\nPY\n')]
        namespace={'__name__':'verified_automatic_unit_'+kind,'__file__':str(payload/name)}
        exec(compile(code,str(payload/name),'exec'),namespace)
        programs[kind]=namespace
    def validate(kind):
        try: programs[kind]['check_units']()
        except BaseException:raise SafeError('AUTOMATIC_UNIT_BYTES_CHANGED: '+kind) from None
    return validate


def unit_state(name,runner):
    code,output=runner([SYSTEMCTL,'show',name,'--property=LoadState','--property=ActiveState','--property=UnitFileState'])
    try: state=dict(line.split('=',1)for line in output.decode('ascii').splitlines())
    except (UnicodeError,ValueError):state={}
    if code!=0 or set(state)!={'LoadState','ActiveState','UnitFileState'}:raise SafeError('AUTOMATIC_UNIT_STATE_UNAVAILABLE')
    return state


def require_inactive(runner):
    for name in (ORDER_TIMER,ORDER_SERVICE):
        state=unit_state(name,runner)
        if state['LoadState']!='loaded' or state['ActiveState'] not in ('inactive','failed'):
            raise SafeError('AUTOMATIC_ORDER_WORKER_NOT_QUIESCENT')


def run_unit(payload,kind,flag,runner):
    verify_helpers(payload)
    name='install_whatsapp_'+('capture' if kind=='capture' else 'automatic')+'_root.sh'
    code,output=runner(['/usr/bin/bash',str(payload/name),flag])
    if code!=0:raise SafeError('AUTOMATIC_UNIT_ACTION_FAILED: '+kind+' '+flag)
    return output


def restore_enabled(name,before,runner,validate,kind):
    current=unit_state(name,runner)
    enabled=lambda s:s in ('enabled','enabled-runtime')
    if enabled(current['UnitFileState'])==enabled(before['UnitFileState']):return
    if before['UnitFileState'] not in ('enabled','enabled-runtime','disabled'):
        raise SafeError('AUTOMATIC_PRIOR_ENABLED_STATE_REQUIRES_REVIEW')
    validate(kind)
    op='enable' if enabled(before['UnitFileState']) else 'disable'
    args=[SYSTEMCTL,op]
    if before['UnitFileState']=='enabled-runtime':args.append('--runtime')
    code,_=runner(args+[name])
    if code!=0:raise SafeError('AUTOMATIC_ENABLED_STATE_RESTORE_FAILED')


def activation_backup(output):
    found=re.findall(rb'BACKUP=(/home/fasakha/whatsapp-release-backups/automatic-activation-[A-Za-z0-9_-]+)',output)
    if len(set(found))==1:return found[0].decode('ascii')
    return None


def orchestrate(payload,uid,runner=command,validate=None,root=ROOT,pins=SOURCE_PINS):
    verify_helpers(payload)
    baseline=guard_sources(root,uid,pins)
    validate=validate or unit_validator(payload)
    validate('capture')
    run_unit(payload,'orders','--install',runner)
    for kind in ('orders','capture'):validate(kind)
    capture_before=unit_state(CAPTURE_TIMER,runner);orders_before=unit_state(ORDER_TIMER,runner)
    require_inactive(runner)
    code,output=runner([RUNUSER,'-u','fasakha','--',PHP,str(payload/'whatsapp_ai_connection_check.php')],timeout=240)
    try: ai=json.loads(output)
    except (ValueError,TypeError):ai=None
    if not isinstance(ai,dict) or set(ai)-{'status','ai_calls','reason'} or ai.get('status') not in ('AI_CONNECTION_READY','AI_CONNECTION_FAILED'):
        raise SafeError('AUTOMATIC_AI_CHECK_REPORT_INVALID_CAPTURE_REMAINS_RUNNING')
    safe_ai={'status':ai['status'],'ai_calls':ai.get('ai_calls') if type(ai.get('ai_calls'))is int else 0}
    if isinstance(ai.get('reason'),str)and re.fullmatch('[A-Z_]{1,80}',ai['reason']):safe_ai['reason']=ai['reason']
    print('AI_CONNECTION='+json.dumps(safe_ai,sort_keys=True),flush=True)
    if code!=0 or ai.get('status')!='AI_CONNECTION_READY' or ai.get('ai_calls')!=1:
        raise SafeError('AUTOMATIC_AI_CHECK_FAILED_CAPTURE_REMAINS_RUNNING')
    if guard_sources(root,uid,pins)!=baseline:raise SafeError('AUTOMATIC_SOURCE_CHANGED_BEFORE_PAUSE')
    paused=False;backup=None
    try:
        validate('capture');paused=True
        code,_=runner([SYSTEMCTL,'stop',CAPTURE_TIMER,CAPTURE_SERVICE])
        if code!=0:raise SafeError('AUTOMATIC_CAPTURE_PAUSE_FAILED')
        for name in (CAPTURE_TIMER,CAPTURE_SERVICE):
            if unit_state(name,runner)['ActiveState']not in('inactive','failed'):
                raise SafeError('AUTOMATIC_CAPTURE_NOT_QUIESCENT')
        run_unit(payload,'orders','--stop',runner);require_inactive(runner)
        if guard_sources(root,uid,pins)!=baseline:raise SafeError('AUTOMATIC_SOURCE_CHANGED_AFTER_PAUSE')
        verify_helpers(payload)
        code,output=runner([RUNUSER,'-u','fasakha','--','/usr/bin/env',
            'PATH=/opt/cpanel/ea-php82/root/usr/bin:/usr/local/bin:/usr/bin:/bin',
            '/usr/bin/python3','-B',str(payload/'activate_whatsapp_automatic.py'),
            '--root',str(ROOT),'--actor','1','--branches','94,301,307,363'],timeout=300)
        backup=activation_backup(output)
        if code!=0 or not output.startswith(b'WHATSAPP_AUTOMATIC_CONFIGURED_TIMER_START_REQUIRED\n')or backup is None:
            raise SafeError('AUTOMATIC_ACTIVATION_FAILED')
        unchanged={k:v for k,v in pins.items()if k!=CART_PATH}
        if guard_sources(root,uid,unchanged)!={k:v for k,v in baseline.items()if k!=CART_PATH}:
            raise SafeError('AUTOMATIC_PROTECTED_SOURCE_CHANGED_AFTER_ACTIVATION')
        validate('orders');run_unit(payload,'orders','--start',runner)
        if unit_state(ORDER_TIMER,runner)['ActiveState']!='active':raise SafeError('AUTOMATIC_TIMER_ACTIVE_CHECK_FAILED')
        # Keep capture enabled through the full cutover; disable it only once its replacement is live.
        validate('capture');code,_=runner([SYSTEMCTL,'disable',CAPTURE_TIMER])
        if code!=0:raise SafeError('AUTOMATIC_CAPTURE_BOOT_HANDOFF_FAILED')
        if unit_state(ORDER_TIMER,runner)['ActiveState']!='active':raise SafeError('AUTOMATIC_TIMER_POST_HANDOFF_CHECK_FAILED')
        return 'WHATSAPP_AUTOMATIC_ENABLED',backup
    except BaseException as error:
        recovered=True
        if paused:
            try:
                validate('orders');run_unit(payload,'orders','--stop',runner);require_inactive(runner)
                restore_enabled(ORDER_TIMER,orders_before,runner,validate,'orders')
                validate('capture');guard_sources(root,uid,CAPTURE_PINS)
                restore_enabled(CAPTURE_TIMER,capture_before,runner,validate,'capture')
                if capture_before['ActiveState']=='active':
                    code,_=runner([SYSTEMCTL,'start',CAPTURE_TIMER])
                    if code!=0 or unit_state(CAPTURE_TIMER,runner)['ActiveState']!='active':
                        raise SafeError('AUTOMATIC_CAPTURE_RECOVERY_FAILED')
            except BaseException:recovered=False
        reason=str(error)if isinstance(error,SafeError)else'AUTOMATIC_INTERRUPTED'
        status='CAPTURE_AVAILABILITY_RESTORED'if recovered else'CAPTURE_RECOVERY_REQUIRES_REVIEW'
        suffix='; BACKUP='+backup if backup else''
        raise SafeError(reason+'; '+status+suffix)from None


def main():
    try:
        args=sys.argv[1:]
        if os.geteuid()!=0 or len(args)!=2 or args[0]!='--release'or not re.fullmatch('[a-f0-9]{40}',args[1]):
            raise SafeError('RUN_ROOT_ENABLE_HELPER_WITH_PINNED_RELEASE')
        uid=pwd.getpwnam('fasakha').pw_uid
        s=ROOT.lstat()
        if not stat.S_ISDIR(s.st_mode)or s.st_uid!=uid:raise SafeError('AUTOMATIC_APPLICATION_ROOT_UNSAFE')
        tmp=pathlib.Path('/tmp');t=tmp.lstat()
        if not stat.S_ISDIR(t.st_mode)or t.st_uid!=0 or not t.st_mode&stat.S_ISVTX:
            raise SafeError('AUTOMATIC_STICKY_ROOT_TEMP_UNAVAILABLE')
        signal.signal(signal.SIGTERM,lambda *_:(_ for _ in ()).throw(SafeError('AUTOMATIC_ENABLE_INTERRUPTED')))
        with tempfile.TemporaryDirectory(prefix='fasakhansta-whatsapp-enable-',dir=tmp)as folder:
            payload=pathlib.Path(folder);payload.chmod(0o755)
            stage_helpers(payload,args[1]);status,backup=orchestrate(payload,uid)
        print(status);print('BACKUP='+backup)
        return 0
    except SafeError as error:print(str(error),file=sys.stderr)
    except BaseException:print('AUTOMATIC_ENABLE_STOPPED_WITHOUT_SECRET_OUTPUT',file=sys.stderr)
    return 1


if __name__=='__main__':sys.exit(main())
PY
