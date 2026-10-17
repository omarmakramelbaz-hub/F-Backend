#!/usr/bin/env python3
"""Publish only the reviewed customer-recap parser and its WhatsApp workflow.

This installer does not analyze conversations, change a policy, reset scans,
create orders, send replies, migrate tables or replace systemd units.
"""
import hashlib
import importlib.util
import json
import os
import pathlib
import re
import signal
import stat
import sys
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
LIBRARY_BLOB = '65cad14ba90b505be88649da73da045746429abc'
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
# Initial installations publish both declared targets. Reviewed upgrades skip
# any target whose exact bytes are already current, retaining its original inode.
TARGET_PINS = {
    'app/Support/WhatsAppCustomerOrderConfirmation.php': (None, '63cd0fcd2d9056fb0e5732ddc22f4423ee349da7'),
    'app/Services/Dashboard/WhatsAppOrderWorkflow.php':
        ('bd0e1cc3536f7d84f3c31bc956317d3374004937', '780da6bd1024afb0166d29973e985d2412a560ee'),
}
INSTALLED_SOURCE_PROFILES = {
    # The verified d6b404 installation contains this exact parser/workflow pair.
    # Do not infer an upgrade profile from independently allowed file hashes.
    'INSTALLED_V1': {
        'app/Support/WhatsAppCustomerOrderConfirmation.php': '558440a772a7798c4f9c11a713685c79b7fdabda',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php': 'd91ea2f4c84357e16e0556b205484d64cfce1079',
    },
    # The verified footer-tolerance installation keeps this unchanged workflow.
    'INSTALLED_V2': {
        'app/Support/WhatsAppCustomerOrderConfirmation.php': '63cd0fcd2d9056fb0e5732ddc22f4423ee349da7',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php': 'd91ea2f4c84357e16e0556b205484d64cfce1079',
    },
    # The verified receipt-resume installation already has the current parser.
    'INSTALLED_V3': {
        'app/Support/WhatsAppCustomerOrderConfirmation.php': '63cd0fcd2d9056fb0e5732ddc22f4423ee349da7',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php': '63c1e6e7e48c500227e0285707611b268e50b3ad',
    },
}
RUNTIME_PINS = {
    'app/Support/MetaAiSchedule.php': ('667ffd6530444ef00dc6a05f76a5700d217fff80',),
    'app/Services/Dashboard/MetaAiControl.php': ('de44620835b22666417fc37752ab944d4419df36',),
    'app/Support/WhatsAppOrderExtraction.php': ('a44f791013642aeca4b4f50dd96ec96b59eda012',),
    'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php': ('478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',),
    'app/Services/Dashboard/WhatsAppOrderAiProvider.php': ('79970f2f9157ae718dce9004f1d3e3b4ccfd4714',),
    'app/Services/Dashboard/WhatsAppOrderFollowupService.php': ('e817b327ba1fdfd042288d20b5c4ba8bef00cc81',),
    'app/Services/Dashboard/WhatsAppOrderSourceSelection.php': ('03db3211b80da905f07415fd16f1e7a62e0395b1',),
    'app/Services/Dashboard/WhatsAppInboxAccess.php': ('186789d42113e81818de8d82a2327e3ee9d8d0b5',),
    'app/Services/Dashboard/WhatsAppInboxMessage.php': ('bedd91db5266d83bdce84a04836e988518891728',),
    'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php': ('4b571f7f4a5cef9257c414886dba515c06dae2a1',),
    # The reviewed CRM protocol only adds scoped status-report rows. Preserve
    # that independent extension instead of overwriting it with the older file.
    'app/Support/WhatsAppInboxProtocol.php':
        ('c64f8cf89437bb7822fc767202f7cf5112bfd7fd', 'f2eec9099f9a27915cda50b7c8e267efb1f0b030'),
    # Reviewed independent POS updates only extend preparation labels/options and
    # first-print access policy. Bind their exact bytes without publishing them.
    'app/Services/Dashboard/TakeawayAccess.php':
        ('6b27b7ac2faff6fdaf952494f90f2d8378a12484', 'd063ee444c347c41620916b4513d0f03785141aa'),
    'app/Services/Dashboard/TakeawayCatalog.php':
        ('49943493fffb5405c87a3001f36c339caa2edf31', '2ff0e19675d8678dfb6ca738767eeaa99f95dc47',
         '7476e436bf06a2a777977733580a73f10b04653b', 'e96fce33037a06330f95d6a91553950cdf32d763'),
    # The reviewed branch-tariff release adds returned tariff metadata only.
    'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php':
        ('1f0eb40a2b0e6590362beee71e8cbbbcb1d7535f', '836cadb027419892e4871bcfbe59edd53015dd82'),
}
# Existing ERP services have independent, user-approved CRM releases. They are
# never replaced or assigned a stale source hash here. Bind their bytes and inode
# throughout this publication, and continue to use their existing public APIs.
UNCHANGED_DEPENDENCIES = (
    'app/Services/Dashboard/WhatsAppInboxConsumer.php',
    'app/Services/Dashboard/PhoneDelivery.php',
    'app/Services/Dashboard/PosServiceTicket.php',
    'app/Services/Dashboard/BranchCustomers.php',
    'app/Services/Dashboard/PosServicePhone.php',
)


def blob(content):
    return hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()


def load_library():
    path = pathlib.Path(__file__).absolute().with_name('apply_meta_ai_control.py')
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.geteuid()):
            raise RuntimeError('WA_CONFIRMATION_LIBRARY_PATH_UNSAFE')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(descriptor); content = os.read(descriptor, 1048577); after = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid())
                or info.st_nlink != 1 or info.st_mode & 0o022 or len(content) > 1048576
                or blob(content) != LIBRARY_BLOB
                or (info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
                != (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
            raise RuntimeError('WA_CONFIRMATION_LIBRARY_HASH_OR_METADATA_CHANGED')
    finally:
        os.close(descriptor)
    name = 'verified_customer_confirmation_library'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path); sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


library = load_library()
base = library.base
SafeError = base.SafeError

READINESS_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 $pins=json_decode($argv[1]??'',true,16,JSON_THROW_ON_ERROR);
 if(!is_array($pins)||!$pins)throw new RuntimeException();
 foreach($pins as$relative=>$expected){
  $path=getcwd().'/'.$relative;$info=@lstat($path);
  if($expected===null){if($info!==false)throw new RuntimeException();continue;}
  if(!$info||($info['mode']&0170000)!==0100000||realpath($path)!==$path||$info['size']>8388608)throw new RuntimeException();
  $bytes=file_get_contents($path);
  if(sha1('blob '.strlen($bytes)."\0".$bytes)!==$expected)throw new RuntimeException();
 }
 require'vendor/autoload.php';$app=require'bootstrap/app.php';
 $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
 if($app->getCachedConfigPath()!==getcwd().'/bootstrap/cache/config.php')throw new RuntimeException();
 foreach($pins as$relative=>$expected){
  if($expected===null||!str_starts_with($relative,'app/'))continue;
  $class='App\\'.str_replace('/','\\',substr($relative,4,-4));$r=new ReflectionClass($class);
  if($r->getFileName()!==getcwd().'/'.$relative)throw new RuntimeException();
 }
 $workflow=$app->make(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
 if(!$workflow->available()||!isset($kernel->all()['meta:process-ai']))throw new RuntimeException();
 $control=$app->make(\App\Services\Dashboard\MetaAiControl::class)->state();
 if(($control['available']??null)!==true||($control['policy']['mode']??null)!=='ANALYZE_ONLY')throw new RuntimeException();
 $value=['mode'=>'ANALYZE_ONLY','configuration_fingerprint'=>hash('sha256',serialize(config()->all()))];
 while(ob_get_level())ob_end_clean();echo json_encode($value,JSON_THROW_ON_ERROR);
}catch(Throwable$ignore){exit(1);}
'''


def dependency_snapshot(root, uid):
    values = {path: library.guarded_snapshot(root/path, uid, root)
              for path in (*RUNTIME_PINS, *UNCHANGED_DEPENDENCIES)}
    for path, allowed in RUNTIME_PINS.items():
        if blob(values[path][0].content) not in allowed:
            raise SafeError('WA_CONFIRMATION_RUNTIME_SOURCE_MISMATCH')
    # Only the new origin needs this independent, existing tariff service. Bind
    # its bytes and inode without assigning an old pin or publishing that file.
    if blob(values['app/Services/Dashboard/FasakhanstaDeliveryOrigin.php'][0].content) == '836cadb027419892e4871bcfbe59edd53015dd82':
        path = 'app/Services/Dashboard/BranchDeliveryTariff.php'
        values[path] = library.guarded_snapshot(root/path, uid, root)
    return values


def fingerprint(values):
    data = [(path, signature, blob(item.content)) for path, (item, signature) in sorted(values.items())]
    return hashlib.sha256(json.dumps(data, separators=(',', ':')).encode()).hexdigest()


def sources_snapshot(root, uid):
    return {path: library.guarded_snapshot(root/path, uid, root, absent_ok=old is None)
            for path, (old, target) in TARGET_PINS.items()}


def source_hashes(values):
    return {path: None if item is None else blob(item.content) for path, (item, _) in values.items()}


def profiles():
    for pins in INSTALLED_SOURCE_PROFILES.values():
        if tuple(pins) != tuple(TARGET_PINS):
            raise SafeError('WA_CONFIRMATION_INSTALLED_PROFILE_SCOPE_INVALID')
    return dict({'BASELINE': {path: pair[0] for path, pair in TARGET_PINS.items()}},
                **INSTALLED_SOURCE_PROFILES,
                CURRENT={path: pair[1] for path, pair in TARGET_PINS.items()})


def readiness(root, runner, targets, dependencies):
    pins = dict(targets, **{path: blob(item.content) for path, (item, _) in dependencies.items()})
    code, output = runner(root, [PHP, '-r', READINESS_PHP, json.dumps(pins, separators=(',', ':'))], capture=True)
    try:
        result = json.loads(output) if code == 0 else None
    except (ValueError, TypeError):
        result = None
    if (not isinstance(result, dict) or set(result) != {'mode', 'configuration_fingerprint'}
            or result['mode'] != 'ANALYZE_ONLY'
            or not re.fullmatch('[a-f0-9]{64}', result.get('configuration_fingerprint', ''))):
        raise SafeError('WA_CONFIRMATION_ANALYSIS_ONLY_READINESS_FAILED')
    return result


def current_state(root, uid, runner):
    configuration = library.configuration_snapshot(root, uid)
    dependencies = dependency_snapshot(root, uid)
    sources = sources_snapshot(root, uid); observed = source_hashes(sources)
    profile = next((name for name, pins in profiles().items() if pins == observed), None)
    if profile is None:
        raise SafeError('WA_CONFIRMATION_LIVE_SOURCE_NEEDS_REVIEW')
    control = library.control_state(root, runner)
    if not control['present']:
        raise SafeError('WA_CONFIRMATION_EXISTING_CONTROL_REQUIRED')
    ready = readiness(root, runner, observed, dependencies)
    return {'configuration': configuration, 'dependencies': dependencies, 'sources': sources,
            'control': control, 'readiness': ready, 'profile': profile}


def public_state(value):
    return {'sources': source_hashes(value['sources']),
            'configuration_fingerprint': library.configuration_fingerprint(value['configuration']),
            'dependencies_fingerprint': fingerprint(value['dependencies']),
            'control_fingerprint': value['control']['fingerprint'], 'source_profile': value['profile']}


def require_unchanged(root, uid, runner, value, sources=True):
    if (library.configuration_snapshot(root, uid) != value['configuration']
            or dependency_snapshot(root, uid) != value['dependencies']
            or library.control_state(root, runner) != value['control']
            or sources and sources_snapshot(root, uid) != value['sources']):
        raise SafeError('WA_CONFIRMATION_PUBLICATION_STATE_CHANGED')


def verify_state(root, uid, expected, held_locks=None, runner=base.command):
    if (not isinstance(expected, dict) or set(expected) != {'sources', 'configuration_fingerprint',
            'dependencies_fingerprint', 'control_fingerprint', 'source_profile'}
            or expected['sources'] not in profiles().values()):
        raise SafeError('WA_CONFIRMATION_RESTART_SCOPE_INVALID')
    base.validate_root(root, uid)
    with library.locks(root, uid, held_locks):
        value = current_state(root, uid, runner)
        require_unchanged(root, uid, runner, value)
        observed = public_state(value)
        if observed != expected:
            raise SafeError('WA_CONFIRMATION_RESTART_STATE_CHANGED')
        return dict(observed, status='WA_CONFIRMATION_STATE_READY')


def release_sources(root, release, runner):
    if not re.fullmatch('[a-f0-9]{40}', release):
        raise SafeError('WA_CONFIRMATION_RELEASE_INVALID')
    code, kind = runner(root, ['git', 'cat-file', '-t', release], capture=True)
    if code or kind.strip() != b'commit':
        raise SafeError('WA_CONFIRMATION_RELEASE_NOT_COMMIT')
    content = {}
    for path, (_, target) in TARGET_PINS.items():
        if not re.fullmatch('[a-f0-9]{40}', target):
            raise SafeError('WA_CONFIRMATION_REVIEWED_PIN_REQUIRED')
        code, tree = runner(root, ['git', 'ls-tree', '-z', release, path], capture=True)
        if code or tree != b'100644 blob '+target.encode()+b'\t'+path.encode()+b'\0':
            raise SafeError('WA_CONFIRMATION_RELEASE_TREE_MISMATCH')
        code, data = runner(root, ['git', 'show', release+':'+path], capture=True)
        if code or not data or len(data) > 8388608 or blob(data) != target:
            raise SafeError('WA_CONFIRMATION_RELEASE_BLOB_MISMATCH')
        content[path] = data
    return content


def apply(root, uid, release, check_only=False, expected=None, held_locks=None, runner=base.command):
    base.validate_root(root, uid)
    with library.locks(root, uid, held_locks):
        before = current_state(root, uid, runner); public_before = public_state(before)
        if expected is not None and public_before != expected:
            raise SafeError('WA_CONFIRMATION_PREFLIGHT_STATE_CHANGED')
        candidates = release_sources(root, release, runner)
        parent = root.parent/'whatsapp-release-backups'; base.private_parent(parent, uid)
        with tempfile.TemporaryDirectory(prefix='.wa-confirmation-lint-', dir=parent) as directory:
            for index, (path, content) in enumerate(candidates.items()):
                candidate = pathlib.Path(directory)/(str(index)+'.php')
                library.private_write(candidate, content)
                code, _ = runner(root, [PHP, '-l', str(candidate)])
                if code or candidate.read_bytes() != content:
                    raise SafeError('WA_CONFIRMATION_CANDIDATE_LINT_FAILED')
        require_unchanged(root, uid, runner, before)
        if check_only:
            return dict(public_before, status='WA_CONFIRMATION_PREFLIGHT_READY',
                        changed=before['profile'] != 'CURRENT')
        library.require_quiet(root, runner)
        if before['profile'] == 'CURRENT':
            return dict(public_before, status='WA_CONFIRMATION_ALREADY_CURRENT', backup=None)
        backup = pathlib.Path(tempfile.mkdtemp(prefix='whatsapp-customer-confirmation-', dir=parent))
        backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('WA_CONFIRMATION_BACKUP_FILESYSTEM_MISMATCH')
        publications = backup/'publications'; publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        library.private_write(backup/'receipt.json', (json.dumps(dict(public_before,
            release=release, targets=profiles()['CURRENT'], publication_targets=list(TARGET_PINS),
            changed_targets=[path for path in TARGET_PINS if public_before['sources'][path] != TARGET_PINS[path][1]]),
            sort_keys=True)+'\n').encode())
        written = {}; created = []
        try:
            for path, content in candidates.items():
                if public_before['sources'][path] == TARGET_PINS[path][1]:
                    if library.guarded_snapshot(root/path, uid, root) != before['sources'][path]:
                        raise SafeError('WA_CONFIRMATION_CURRENT_TARGET_CHANGED')
                    continue
                require_unchanged(root, uid, runner, before, sources=False)
                base.replace_file(root/path, content, before['sources'][path][0], uid, root, created,
                    journal=written, relative=path, private_directory=publications)
            library.require_quiet(root, runner)
            require_unchanged(root, uid, runner, before, sources=False)
            after = current_state(root, uid, runner)
            if after['profile'] != 'CURRENT' or after['readiness'] != before['readiness']:
                raise SafeError('WA_CONFIRMATION_FINAL_RUNTIME_OR_CONFIGURATION_CHANGED')
            for path in TARGET_PINS:
                if path in written:
                    base.assert_snapshot(root/path, written[path].after, uid, root)
                elif library.guarded_snapshot(root/path, uid, root) != before['sources'][path]:
                    raise SafeError('WA_CONFIRMATION_CURRENT_TARGET_CHANGED')
            require_unchanged(root, uid, runner, after)
        except BaseException:
            restored = True
            with base.blocked_signals():
                for publication in reversed(list(written.values())):
                    try:
                        if not base.recover_publication(publication, root, uid):
                            restored = False; break
                    except BaseException:
                        restored = False; break
            # HTTP workers can have loaded the new parser even after byte rollback.
            # Never resume automatic orders after any partial source exposure.
            status = 'WA_CONFIRMATION_ROLLBACK_REVIEW_REQUIRED' if written else 'WA_CONFIRMATION_PREPUBLICATION_STOPPED'
            if not restored:
                status = 'WA_CONFIRMATION_RECOVERY_REVIEW_REQUIRED'
            raise SafeError(status+'; BACKUP='+str(backup)) from None
        return dict(public_state(after), status='WHATSAPP_CUSTOMER_CONFIRMATION_REPAIR_READY', backup=str(backup))


if __name__ == '__main__':
    import argparse
    import pwd
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument('--root', required=True); parser.add_argument('--release', required=True)
    parser.add_argument('--check-only', action='store_true'); arguments = parser.parse_args()
    try:
        def interrupted(signum, frame): raise KeyboardInterrupt()
        signal.signal(signal.SIGTERM, interrupted); signal.signal(signal.SIGINT, interrupted)
        uid = pwd.getpwnam('fasakha').pw_uid; root = pathlib.Path(arguments.root)
        if os.geteuid() != uid or root != APP_ROOT:
            raise SafeError('WA_CONFIRMATION_EXECUTION_CONTEXT_INVALID')
        print(json.dumps(apply(root, uid, arguments.release, arguments.check_only), sort_keys=True))
    except BaseException as error:
        print(str(error) if isinstance(error, SafeError) else 'WA_CONFIRMATION_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
        raise SystemExit(1)
