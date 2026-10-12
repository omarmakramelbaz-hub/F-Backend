#!/usr/bin/env python3
"""Publish scoped Cairo delivery-origin files without changing configuration."""
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
import subprocess
import sys
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
QUIET_UNITS = tuple('fasakhansta-whatsapp-'+name for name in ('capture.timer','capture.service','orders.timer','orders.service'))
ORIGIN_PATH = 'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php'
PROTOCOL_PATH = 'app/Support/WhatsAppInboxProtocol.php'
PHONE_PATH = 'app/Services/Dashboard/PhoneDelivery.php'
POS_PATH = 'app/Services/Dashboard/PosServiceTicket.php'
WORKFLOW_PATH = 'app/Services/Dashboard/WhatsAppOrderWorkflow.php'
CONTROLLER_PATH = 'app/Http/Controllers/Dashboard/PhoneOrdersController.php'
UI_PATH = 'public/dashboard/js/phone-orders.js'
# Dependency first, workflow last. Target pins are frozen after backend tests pass.
SOURCE_PINS = {
    ORIGIN_PATH: (None, '1f0eb40a2b0e6590362beee71e8cbbbcb1d7535f'),
    PROTOCOL_PATH: ('7a2f342c86e0838fb920bfdea53d6542b01cd008', 'ecde67c6a87ca8068bb077892fdbea522d89f217'),
    PHONE_PATH: ('cd4f5daddbf1ed462d04f7fa24b4966cb94608c9', '227027cfdcd68720b74220e3ecd0abb9e156a5d7'),
    POS_PATH: ('87b24fb862d5d6fbc4be7a62f437bb50aba03be7', 'ef4c7b6f4a1276c122634aafabb2d510926aaeef'),
    CONTROLLER_PATH: ('f3c5ec3bf1a147fe8e998cff1a9f4d5297cace53', '478776e72edf67d9eaaf00abd49d97a2fa4720c8'),
    UI_PATH: ('9a8e6d959c7bc59766f9b0075f79413e2e2e530d', '66597844cc01d9371b1952f79b22fb1833bf2ac0'),
    WORKFLOW_PATH: ('76af7b3eb0f5d70ec0c35e524e74ac5cb141b126', '6c63b91d7fb70130ae472cea291fa2cee88b27dd'),
}
PROTECTED_PATHS = (
    '.env', 'bootstrap/cache/config.php', 'config/whatsapp.php',
    'config/whatsapp_orders.php', 'config/whatsapp_replies.php', 'config/whatsapp_cart.php',
    'routes/admin.php', 'routes/whatsapp_orders.php', 'app/Console/Kernel.php',
    'app/Support/WhatsAppOrderExtraction.php',
    'app/Services/Dashboard/WhatsAppOrderAiProvider.php',
    'app/Services/Dashboard/WhatsAppInboxConsumer.php',
    'app/Services/Dashboard/WhatsAppReplyService.php',
    'app/Services/Dashboard/TakeawayAccess.php', 'app/Services/Dashboard/BranchCustomers.php',
    'app/Services/Dashboard/TakeawayCatalog.php', 'app/Services/Dashboard/TakeawayService.php',
    'app/Services/Dashboard/PosServicePhone.php', 'app/Services/Dashboard/PosBranchPrinting.php',
    'app/Services/Dashboard/PhoneMapProvider.php', 'app/Models/Resturant.php', 'app/Models/Area.php',
    'app/Http/Controllers/Dashboard/PosServiceController.php',
    'resources/views/admin/index.blade.php', 'resources/views/admin/layouts/menu.blade.php',
    'resources/views/admin/layouts/footer.blade.php', 'resources/views/admin/maps/assets.blade.php',
    'resources/views/admin/whatsapp/index.blade.php', 'resources/views/admin/whatsapp/replies.blade.php',
    'resources/views/admin/whatsapp/orders.blade.php',
    'public/js/dashboard-whatsapp-inbox.js', 'public/js/dashboard-whatsapp-replies.js',
    'public/js/dashboard-whatsapp-orders.js', 'public/js/dashboard-pos-service.js',
    'public/dashboard/js/dashboard-location-picker.js',
)

PROTECTED_BLOBS = {'app/Services/Dashboard/TakeawayAccess.php': '6b27b7ac2faff6fdaf952494f90f2d8378a12484', 'app/Services/Dashboard/TakeawayCatalog.php': '49943493fffb5405c87a3001f36c339caa2edf31', 'app/Services/Dashboard/PhoneMapProvider.php': 'bb360aa46868b432a8a1cf5dff48cdc50b62c860', 'app/Services/Dashboard/PhoneDeliveryBoard.php': '07fbc51fecfe8faac20c079d560d66c724993766', 'app/Services/Dashboard/PosBranchPrinting.php': '021a53ff471c594fcef75509327aace74bcbf86c', 'app/Http/Controllers/Dashboard/PosServiceController.php': '93a3467054dcc03b82c6be99aa5bd4613bcbd4eb', 'public/dashboard/js/dashboard-location-picker.js': 'e66fc338b8921fa13368673ba4b0c61d96bfdf7a', 'public/dashboard/js/phone-address-search.js': '4c55ec1f2ff0e4fae4105a96daf5fdc76f440c93', 'resources/views/admin/phone_orders/index.blade.php': '2902da5d8aaab940640646d496a8a6f8670cf6de', 'resources/views/admin/phone_orders/batch_receipt.blade.php': '708a574088e7e9e77728efe8fd4ce0d611316bae', 'resources/views/admin/maps/assets.blade.php': 'd1654a972b5dbba699a0db88af1f038777501c3c', 'app/Models/Resturant.php': '42f548283bac10bdd0b098e782540e521abfffb2', 'app/Models/Area.php': '205999cf4ee2dae74960ce725a1aab2632dc2f64'}
LEGACY_READ_ONLY_MODELS = ('app/Models/Resturant.php', 'app/Models/Area.php')

CAIRO_ORIGIN_NAMES = {309: 'المقطم ستور', 310: 'زايد ستور', 311: 'التجمع ستور', 312: 'مدينتي ستور', 313: 'مصر الجديده', 336: 'المهندسين ستور', 337: 'مدينة  نصر ستور', 338: 'زهراء مدينة نصر ستور', 339: 'عين شمس ستور', 340: 'التجمع الثالث ستور', 341: '15مايو ستور', 342: 'المعادي ستور', 343: 'أكتوبر ستور', 344: 'حدائق أكتوبر ستور', 345: 'الجيزه ستور', 346: 'حلوان ستور', 347: 'الهرم ستور', 348: 'زمالك ستور', 349: 'الشروق ستور', 350: 'الرحاب ستور', 354: 'منصورية ستور', 355: 'قليوب ستور', 356: 'العاصمه ستور', 357: 'العاشر ستور', 358: 'عبور ستور', 359: 'شبرا ستور', 360: 'كرداسة ستور'}


def blob(content):
    return hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()


def load_base():
    path = pathlib.Path(__file__).absolute().with_name('install_whatsapp_inbox.py')
    for parent in reversed(path.parents):
        item = parent.lstat()
        if not stat.S_ISDIR(item.st_mode) or item.st_uid not in (0, os.geteuid()):
            raise RuntimeError('CAIRO_BASE_HELPER_PATH_UNSAFE')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        item = os.fstat(descriptor)
        if (not stat.S_ISREG(item.st_mode) or item.st_uid not in (0, os.geteuid())
                or item.st_nlink != 1 or item.st_mode & 0o022 or item.st_size > 1048576):
            raise RuntimeError('CAIRO_BASE_HELPER_FILE_UNSAFE')
        content = os.read(descriptor, 1048577)
        after = os.fstat(descriptor)
        if (item.st_size, item.st_mtime_ns, item.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise RuntimeError('CAIRO_BASE_HELPER_CHANGED')
    finally:
        os.close(descriptor)
    if blob(content) != BASE_BLOB:
        raise RuntimeError('CAIRO_BASE_HELPER_HASH_MISMATCH')
    name = 'verified_cairo_origin_base'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path); sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


try:
    base = load_base()
except BaseException:
    if __name__ == '__main__':
        print('CAIRO_BASE_HELPER_VERIFICATION_FAILED', file=sys.stderr); sys.exit(1)
    raise
SafeError = base.SafeError


def metadata(path):
    item = path.lstat()
    return (item.st_uid, item.st_gid, stat.S_IMODE(item.st_mode), item.st_dev,
            item.st_ino, item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)


def guarded_snapshot(path, uid, root, absent_ok=False, payload=False, legacy_read_only=False):
    base.safe_ancestors(path, uid, None if payload else root)
    try:
        first = metadata(path)
    except FileNotFoundError:
        if absent_ok:
            return None, None
        raise SafeError('CAIRO_REQUIRED_FILE_MISSING') from None
    owner = first[0]
    if payload and owner not in (0, uid):
        raise SafeError('CAIRO_PAYLOAD_OWNER_UNSAFE')
    if (first[2] & 0o022 and not legacy_read_only) or (payload and first[5] != 1):
        raise SafeError('CAIRO_FILE_MODE_OR_PAYLOAD_LINK_UNSAFE')
    if payload or legacy_read_only:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            info = os.fstat(descriptor)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != owner or info.st_size > 8388608:
                raise SafeError('CAIRO_PAYLOAD_TYPE_OR_SIZE_UNSAFE')
            with os.fdopen(descriptor, 'rb', closefd=False) as stream:
                content = stream.read(8388609)
            if len(content) > 8388608:
                raise SafeError('CAIRO_PAYLOAD_TYPE_OR_SIZE_UNSAFE')
            after = os.fstat(descriptor)
            if (info.st_ino,info.st_size,info.st_mtime_ns,info.st_ctime_ns) != (after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns):
                raise SafeError('CAIRO_PAYLOAD_CHANGED_DURING_READ')
            item = base.Snapshot(content,info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode),info.st_dev,info.st_ino)
        finally:
            os.close(descriptor)
    else:
        item = base.snapshot(path, uid, root)
    if metadata(path) != first:
        raise SafeError('CAIRO_FILE_CHANGED_DURING_READ')
    return item, first


def payload_bytes(path, uid, root):
    item, _ = guarded_snapshot(path, uid, root, payload=True)
    return item.content


def load_manifest(path, digest, uid):
    if not path.is_absolute() or not re.fullmatch('[0-9a-f]{40}', digest):
        raise SafeError('CAIRO_MANIFEST_ARGUMENT_INVALID')
    payload = path.parent.parent
    content = payload_bytes(path, uid, payload)
    if blob(content) != digest:
        raise SafeError('CAIRO_MANIFEST_HASH_MISMATCH')
    try:
        value = json.loads(content)
        if not isinstance(value, dict) or set(value) != {'version', 'sources'} or type(value['version']) is not int or value['version'] != 1:
            raise ValueError()
        if not isinstance(value['sources'], list):
            raise ValueError()
        pins = {}
        for row in value['sources']:
            if not isinstance(row, dict) or set(row) != {'path', 'before_blob', 'after_blob'} or row['path'] in pins:
                raise ValueError()
            if (row['before_blob'] is not None and not re.fullmatch('[0-9a-f]{40}', row['before_blob'])) or not re.fullmatch('[0-9a-f]{40}', row['after_blob']):
                raise ValueError()
            pins[row['path']] = (row['before_blob'], row['after_blob'])
        if pins != SOURCE_PINS:
            raise ValueError()
    except (ValueError, TypeError, KeyError):
        raise SafeError('CAIRO_MANIFEST_SCOPE_INVALID') from None
    return payload


READINESS_PHP = r'''
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
try {
    require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->getCachedConfigPath() !== getcwd().'/bootstrap/cache/config.php') exit(1);
    $mode = config('whatsapp_orders.mode');
    if (!in_array($mode, ['review','auto'], true)) exit(1);
    if (!\Illuminate\Support\Facades\Schema::hasColumn('resturants','parent_id')) exit(1);
    $expected = json_decode('__CAIRO_ORIGIN_NAMES__', true, 4, JSON_THROW_ON_ERROR);
    if (count($expected)!==27) exit(1);
    $columns = \Illuminate\Support\Facades\Schema::getColumnListing('resturants');
    $required = ['id','name','parent_id','lat','lng','km_price'];
    foreach ($required as $column) if (!in_array($column,$columns,true)) exit(1);
    $selected=$required; if(in_array('control',$columns,true)) $selected[]='control';
    $rows = \Illuminate\Support\Facades\DB::table('resturants')->select($selected)
        ->whereIn('id',array_keys($expected))->limit(28)->get();
    if (count($rows)!==27) exit(1);
    $seen=[];
    $nameKey=static fn($value)=>preg_replace('/\s+/u',' ',trim($value));
    foreach ($rows as $row) {
        $id=(int)$row->id;
        if (!isset($expected[$id]) || isset($seen[$id]) || !is_string($row->name)
            || $nameKey($row->name)!==$nameKey($expected[$id])) exit(1);
        $seen[$id]=true;
        if ($row->parent_id!==null && !in_array((string)$row->parent_id,['0','301'],true)) exit(1);
        if (!is_numeric($row->lat) || !is_numeric($row->lng)
            || abs((float)$row->lat)>90 || abs((float)$row->lng)>180
            || ((float)$row->lat===0.0 && (float)$row->lng===0.0)) exit(1);
        $rate=(string)$row->km_price;
        if (!preg_match('/\A[0-9]{1,7}(?:\.[0-9]{1,2})?\z/',$rate) || (float)$rate>1000000) exit(1);
    }

    foreach (['PhoneDelivery','PosServiceTicket','WhatsAppOrderWorkflow'] as $short) {
        $class = 'App\\Services\\Dashboard\\'.$short;
        $reflection = new \ReflectionClass($class);
        if ($reflection->getFileName() !== getcwd().'/app/Services/Dashboard/'.$short.'.php') exit(1);
    }
    if (($argv[1] ?? '') === 'target') {
        $origin = new \ReflectionClass(\App\Services\Dashboard\FasakhanstaDeliveryOrigin::class);
        if ($origin->getFileName() !== getcwd().'/app/Services/Dashboard/FasakhanstaDeliveryOrigin.php'
            || !$origin->hasMethod('check') || !$origin->getMethod('check')->isPublic()
            || $origin->getConstant('FULFILLMENT_BRANCH') !== 'f:301'
            || $origin->getConstant('CAIRO_ORIGINS') !== $expected
            || $origin->getConstant('PHYSICAL_BRANCH_IDS') !== [94,301,307,363]
            || !$origin->hasMethod('isConfiguredOrigin') || !$origin->hasMethod('matchesRegisteredName')) exit(1);
        foreach ($expected as $id=>$name) {
            if (!\App\Services\Dashboard\FasakhanstaDeliveryOrigin::isConfiguredOrigin('f:'.$id)
                || !\App\Services\Dashboard\FasakhanstaDeliveryOrigin::matchesRegisteredName('f:'.$id,$name)) exit(1);
        }
        foreach ([94,301,307,308,363,999999] as $unknown)
            if (\App\Services\Dashboard\FasakhanstaDeliveryOrigin::isConfiguredOrigin('f:'.$unknown)) exit(1);
        $hooks = [
            'app/Services/Dashboard/PhoneDelivery.php'=>['FasakhanstaDeliveryOrigin','delivery_origin_branch'],
            'app/Services/Dashboard/PosServiceTicket.php'=>['PhoneDelivery','delivery_origin_branch','inheritDeliveryOrigin'],
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>['FasakhanstaDeliveryOrigin','delivery_origin_branch'],
            'app/Http/Controllers/Dashboard/PhoneOrdersController.php'=>['delivery_origin_branch','PhoneDelivery'],
            'public/dashboard/js/phone-orders.js'=>['delivery_origin_branch','ticketDeliveryOrigin','validDeliveryOrigin'],
        ];
        foreach ($hooks as $relative=>$markers) {
            $bytes = file_get_contents(getcwd().'/'.$relative);
            foreach ($markers as $marker) if (strpos($bytes,$marker) === false) exit(1);
        }
        $controller = new \ReflectionClass(\App\Http\Controllers\Dashboard\PhoneOrdersController::class);
        if ($controller->getFileName() !== getcwd().'/app/Http/Controllers/Dashboard/PhoneOrdersController.php') exit(1);
    }
    $fingerprint = hash('sha256',serialize(app('config')->all()));
    while(ob_get_level()>0) ob_end_clean();
    echo json_encode(['mode'=>$mode,'config_fingerprint'=>$fingerprint], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) { exit(1); }
'''.replace('__CAIRO_ORIGIN_NAMES__',json.dumps(CAIRO_ORIGIN_NAMES,ensure_ascii=False,separators=(',',':')))


def readiness(root, runner, phase):
    code, output = runner(root, [PHP, '-r', READINESS_PHP, phase], capture=True)
    try:
        value = json.loads(output) if code == 0 else None
    except (ValueError, TypeError):
        value = None
    if (not isinstance(value, dict) or set(value) != {'mode', 'config_fingerprint'}
            or value['mode'] not in ('review', 'auto')
            or not isinstance(value['config_fingerprint'], str)
            or not re.fullmatch('[0-9a-f]{64}', value['config_fingerprint'])):
        raise SafeError('CAIRO_READ_ONLY_READINESS_FAILED')
    return value


def assert_workers_quiet(root, runner):
    for name in QUIET_UNITS:
        arguments = ['/usr/bin/systemctl','show','--no-pager','--property=LoadState','--property=ActiveState']
        if name.endswith('.service'):
            arguments.append('--property=MainPID')
        code, output = runner(root,arguments+[name],capture=True)
        try:
            lines = output.decode('ascii').strip().splitlines()
            values = dict(line.split('=',1) for line in lines)
            expected = {'LoadState','ActiveState'}
            if name.endswith('.service') and values.get('LoadState')=='loaded':
                expected.add('MainPID')
            if (len(lines)!=len(values) or set(values)!=expected
                    or values['LoadState'] not in ('loaded','not-found')
                    or values['ActiveState'] not in ('inactive','failed')
                    or ('MainPID' in values and values['MainPID']!='0')
                    or code not in ((0,) if values['LoadState']=='loaded' else (0,1))):
                raise ValueError()
        except (UnicodeError,ValueError,TypeError):
            raise SafeError('CAIRO_WORKERS_MUST_BE_PAUSED_AND_QUIESCENT: '+name) from None


def private_write(path, content):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(content); stream.flush(); os.fsync(stream.fileno())


def rollback_dependencies(root, uid, written, created):
    # Retained entrypoints may still need every preceding new dependency.
    with base.blocked_signals():
        for publication in reversed(list(written.values())):
            try:
                if not base.recover_publication(publication,root,uid):
                    return False
            except BaseException:
                return False
        for directory,inode in reversed(created):
            try:
                info = directory.lstat()
                if stat.S_ISDIR(info.st_mode) and info.st_uid==uid and info.st_ino==inode:
                    directory.rmdir()
            except OSError:
                pass
    return True


def apply(root, uid, manifest, digest, runner=base.command):
    base.validate_root(root, uid)
    payload = load_manifest(manifest, digest, uid)
    if payload == root or root in payload.parents:
        raise SafeError('CAIRO_PAYLOAD_REQUIRES_PRIVATE_EXTERNAL_DIRECTORY')
    parent = root.parent / 'whatsapp-release-backups'; base.private_parent(parent, uid)
    locks = []
    try:
        for name in ('orders-install.lock', 'inbox-install.lock'):
            descriptor = os.open(parent / name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            locks.append(descriptor); item = os.fstat(descriptor)
            if (not stat.S_ISREG(item.st_mode) or item.st_uid != uid or item.st_nlink != 1
                    or stat.S_IMODE(item.st_mode) != 0o600):
                raise SafeError('CAIRO_INSTALL_LOCK_UNSAFE')
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise SafeError('CAIRO_ANOTHER_INSTALL_OR_CONFIGURATION_RUNNING') from None
        def protected_snapshot(relative):
            pair = guarded_snapshot(root / relative,uid,root,absent_ok=relative not in PROTECTED_BLOBS,
                                    legacy_read_only=relative in LEGACY_READ_ONLY_MODELS)
            if relative in PROTECTED_BLOBS and (pair[0] is None or blob(pair[0].content)!=PROTECTED_BLOBS[relative]):
                raise SafeError('CAIRO_PROTECTED_SOURCE_PROFILE_CHANGED: '+relative)
            return pair

        protected = {relative: protected_snapshot(relative) for relative in dict.fromkeys((*PROTECTED_PATHS,*PROTECTED_BLOBS))}
        before, signatures, proposals = {}, {}, {}
        for relative, (old, new) in SOURCE_PINS.items():
            candidate = payload_bytes(payload / relative, uid, payload)
            if blob(candidate) != new:
                raise SafeError('CAIRO_PAYLOAD_HASH_MISMATCH: '+relative)
            original, signature = guarded_snapshot(root / relative, uid, root, absent_ok=old is None)
            if original is None:
                if old is not None:
                    raise SafeError('CAIRO_REQUIRED_SOURCE_MISSING: '+relative)
            elif blob(original.content) not in (old, new):
                raise SafeError('CAIRO_LIVE_HASH_NEEDS_REVIEW: '+relative)
            before[relative], signatures[relative], proposals[relative] = original, signature, candidate
        assert_workers_quiet(root,runner)
        initial = readiness(root, runner, 'source')

        def protected_unchanged():
            for relative, expected in protected.items():
                if protected_snapshot(relative) != expected:
                    raise SafeError('CAIRO_PROTECTED_FILE_CHANGED: '+relative)

        def source_unchanged():
            for relative, original in before.items():
                if guarded_snapshot(root / relative, uid, root, absent_ok=original is None) != (original, signatures[relative]):
                    raise SafeError('CAIRO_SOURCE_CHANGED_CONCURRENTLY: '+relative)

        backup = pathlib.Path(tempfile.mkdtemp(prefix='cairo-origins-', dir=parent)); backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('CAIRO_BACKUP_REQUIRES_SAME_FILESYSTEM')
        publications = backup / 'publications'; publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        for index, (relative, candidate) in enumerate(proposals.items()):
            if before[relative] is not None:
                private_write(backup / ('before-'+str(index)+'.php'), before[relative].content)
            path = backup / ('candidate-'+str(index)+pathlib.Path(relative).suffix); private_write(path,candidate)
            if relative.endswith('.php'):
                code, _ = runner(root, [PHP,'-l',str(path)])
            else:
                import shutil
                node = shutil.which('node')
                code, _ = runner(root,[node,'--check',str(path)]) if node else (0,b'')
            if code != 0 or payload_bytes(path,uid,backup) != candidate:
                raise SafeError('CAIRO_CANDIDATE_LINT_OR_STAGING_FAILED: '+relative)
        private_write(backup / 'receipt.json', (json.dumps({'version':1,'files':{
            relative:{'before_blob':blob(item.content) if item else None,'after_blob':blob(proposals[relative]),
                      'uid':item.uid if item else uid,'gid':item.gid if item else os.getegid(),
                      'mode':item.mode if item else 0o644}
            for relative,item in before.items()},'effective_configuration_fingerprint':initial['config_fingerprint'],
            'mode_preserved':initial['mode']},sort_keys=True)+'\n').encode())
        written, created = {}, []
        try:
            assert_workers_quiet(root,runner)
            protected_unchanged(); source_unchanged()
            for relative, original in before.items():
                if original is None or original.content != proposals[relative]:
                    base.replace_file(root / relative,proposals[relative],original,uid,root,created,
                                      journal=written,relative=relative,private_directory=publications)
            target = readiness(root,runner,'target')
            if target != initial:
                raise SafeError('CAIRO_EFFECTIVE_CONFIGURATION_CHANGED')
            protected_unchanged(); assert_workers_quiet(root,runner)
            for relative, original in before.items():
                expected = written[relative].after if relative in written else original
                base.assert_snapshot(root / relative,expected,uid,root)
        except BaseException:
            restored = rollback_dependencies(root,uid,written,created)
            status = ('CAIRO_ORIGIN_FILES_RESTORED' if restored else 'CAIRO_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED')
            raise SafeError(status+'; BACKUP='+str(backup)) from None
        return 'WHATSAPP_CAIRO_DELIVERY_ORIGINS_READY', backup
    finally:
        for descriptor in reversed(locks):
            os.close(descriptor)


def main():
    try:
        args = sys.argv[1:]
        if (len(args)!=6 or args[0]!='--root' or args[2]!='--manifest' or args[4]!='--manifest-blob'
                or pathlib.Path(args[1])!=APP_ROOT or os.geteuid()!=pwd.getpwnam('fasakha').pw_uid):
            raise SafeError('USAGE_RUN_AS_FASAKHA_ROOT_MANIFEST_MANIFEST_BLOB')
        os.umask(0o077)
        signal.signal(signal.SIGTERM,lambda *_:(_ for _ in ()).throw(SafeError('CAIRO_INSTALL_INTERRUPTED')))
        status,backup = apply(APP_ROOT,os.geteuid(),pathlib.Path(args[3]),args[5])
        print(status); print('BACKUP='+str(backup)); return 0
    except SafeError as error:
        print(str(error),file=sys.stderr)
    except BaseException:
        print('CAIRO_INSTALL_STOPPED_WITHOUT_SECRET_OUTPUT',file=sys.stderr)
    return 1


if __name__=='__main__':
    sys.exit(main())
