#!/usr/bin/env python3
"""Retain verified published installers and upgrade the manual server bridge."""
import argparse
from contextlib import ExitStack
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import types
import uuid

ROOT = Path('/home/fasakha/public_html')
CONTROL = Path('/var/lib/go-erp-manual-updates')
NATIVE = Path('/var/lib/go-erp-live-updates')
RELEASES = Path('/opt/go-erp-manual-updates/releases')
SYSTEMD = Path('/etc/systemd/system')
BASE_SHA256 = '0a6da17799e88b45061442f81c730e32cdb1822ae68b2bd2f659b14d9f0610d9'
OLD_PROGRAM_PINS = {
    'go_erp_manual_updates_root.py': 'ede4bb603fb04a8251dca2e1eb603febc732c54c12316a5840795420e824c64a',
    'go_erp_manual_tick_root.py': '753baf72d9171adc0d084350fa319ef71c0fa6843c684b42fed5dbef76c588f8',
}
# Exact deployed manual-bridge preimages and this release's immutable payloads.
APP_PINS = {
    'app/Http/Controllers/Dashboard/DesktopDashboardController.php': ('c457f2b956bf8eb1b370260f96024937793bee64b41f6f8c7a27e6a513abc342', '73fe000f32364cf1735006d88d5526b723244fdd209cf12d3448b31feeec03a0'),
    'app/Services/Dashboard/DesktopDashboardUpdates.php': ('d5bcf2cf8c13bb6f0d156ce1d9c2a2698a6f8bf6ddac3d1eb2ef9ade9f81d221', '958a9fbc6d396caa1c2076b7e2db7a9de6777e8a776173634fd47f87bae50a07'),
    'public/dashboard/js/go-erp-releases.js': ('818e52a49cc88c08bd4235477a258de05c90b253e89b2f12b1f0c5a4a6c4eaf6', 'fda039de332a8cb58cca490a79b22225f5431c1eeacd910050b65791e123706a'),
    'resources/views/admin/desktop-dashboard.blade.php': ('49673d834e6e1e9a2ce9b6e579385e3ef2cfd2ba0b1fcbd2aa4dff8471ccd892', '5fd03032b11f98b5177cfc50aa94f3eaf052f8cae3fa0f7396a612221e9ce4ea'),
    'routes/desktop_dashboard.php': ('5334c2b73b7345e71b7c166b60eb11a9d963ec14de68c5280b814f52da74cc9b', '9a96d3942c02b714ae771336a0876241f93807f1f8b8b595fbc656281b06cc9b'),
}
PROGRAM_PINS = {
    'go_erp_manual_updates_root.py': '214978522787f36aee22112e3837e63f113e0f5600eb8cee31b2a35e8223b753',
    'go_erp_manual_tick_root.py': OLD_PROGRAM_PINS['go_erp_manual_tick_root.py'],
    'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b',
}
SERVICE = 'go-erp-manual-updates.service'
WATCH = 'go-erp-manual-updates.path'
MARKER = 'GO_ERP_PUBLISHED_DOWNLOADS_READY_MANUAL_UPDATES_ONLY'


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def engine_from(raw):
    need(hashlib.sha256(raw).hexdigest() == BASE_SHA256, 'BASE_INSTALLER_PIN_INVALID')
    value = types.ModuleType('published_download_installation_engine')
    exec(compile(raw, '<pinned-manual-installation-engine>', 'exec'), value.__dict__)
    value.Stop = Stop
    value.APP_PINS = APP_PINS
    value.PROGRAM_PINS = PROGRAM_PINS
    value.RELEASES = RELEASES
    return value


def inactive(engine, runner):
    for name in ('go-erp-live-updates.service', SERVICE):
        need(runner(['/usr/bin/systemctl', 'show', name, '-p', 'ActiveState', '--value'])
            in ('inactive', 'failed'), 'EXISTING_UPDATE_BUSY')
    need(runner(['/usr/bin/systemctl', 'show', 'go-erp-live-updates.timer',
        '-p', 'ActiveState', '--value']) == 'inactive', 'AUTOMATIC_TIMER_ACTIVE')
    need(runner(['/usr/bin/systemctl', 'show', 'go-erp-live-updates.timer',
        '-p', 'UnitFileState', '--value']) in ('disabled', 'masked'), 'AUTOMATIC_TIMER_ENABLED')


def installed(engine, owner, control, releases, systemd):
    """Accept only the exact prior two-file bridge or this exact upgrade."""
    engine.directory(control, 0, 0, 0o755, False)
    for name in ('private', 'private/jobs', 'private/audits'):
        engine.directory(control/name, 0, 0, 0o700, False)
    engine.directory(control/'shared', 0, owner.pw_gid, 0o750, False)
    engine.directory(control/'requests', owner.pw_uid, owner.pw_gid, 0o700, False)
    engine.directory(releases.parent, 0, 0, 0o755, False)
    engine.directory(releases, 0, 0, 0o755, False)
    systemd_info = systemd.lstat()
    need(stat.S_ISDIR(systemd_info.st_mode) and systemd_info.st_uid == 0
        and not systemd_info.st_mode & 0o022, 'SYSTEMD_DIRECTORY_UNSAFE')
    receipt = engine.read_file(control/'private/installation.json', 8192, uid=0, modes=(0o600,))
    value = json.loads(receipt[0])
    need(isinstance(value, dict) and set(value) == {'format', 'kind', 'release', 'runtime', 'units'}
        and type(value['format']) is int and value['format'] == 1
        and value['kind'] == 'go-erp-manual-installation'
        and isinstance(value['release'], str) and re.fullmatch(r'[a-f0-9]{40}', value['release'])
        and isinstance(value['runtime'], str) and re.fullmatch(r'[a-f0-9]{64}', value['runtime'])
        and isinstance(value['units'], dict) and set(value['units']) == {SERVICE, WATCH}, 'INSTALLED_RECEIPT_INVALID')
    runtime = releases/value['runtime']
    engine.directory(runtime, 0, 0, 0o555, False)
    names = {path.name for path in runtime.iterdir()}
    candidates = [pins for pins in (OLD_PROGRAM_PINS, PROGRAM_PINS) if set(pins) == names]
    need(len(candidates) == 1, 'INSTALLED_RUNTIME_ROSTER_INVALID')
    pins = candidates[0]
    need(engine.sha(engine.canonical(dict(sorted(pins.items())))) == runtime.name,
        'INSTALLED_RUNTIME_ID_INVALID')
    for name, digest in pins.items():
        need(engine.sha(engine.read_file(runtime/name, uid=0, modes=(0o444,))[0]) == digest,
            'INSTALLED_RUNTIME_CHANGED')
    original = {}
    for name, raw in engine.units(runtime).items():
        actual = engine.read_file(systemd/name, 65536, uid=0, modes=(0o644,))
        need(actual[0] == raw and value['units'][name] == engine.sha(raw), 'INSTALLED_UNIT_CHANGED')
        original[name] = actual
    return receipt, original


def load_archive(path):
    spec = importlib.util.spec_from_file_location('go_erp_published_download_installer', path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    value.Stop = Stop
    return value.archive_current


def archive_result(value):
    expected = {'format', 'kind', 'available', 'changed', 'current_version',
        'previous_version', 'source', 'application_contents_changed',
        'database_changed', 'credentials_disclosed'}
    def version(item):
        return isinstance(item, str) and re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]{0,14}', item)
    need(isinstance(value, dict) and set(value) == expected
        and type(value.get('format')) is int and value['format'] == 1
        and value.get('kind') == 'go-erp-published-downloads-result'
        and value.get('available') is True and type(value.get('changed')) is bool
        and version(value.get('current_version'))
        and (value.get('previous_version') is None or version(value['previous_version']))
        and value.get('source') in ('canonical', 'published-state', 'retained')
        and value.get('application_contents_changed') is False
        and value.get('database_changed') is False and value.get('credentials_disclosed') is False,
        'VERIFIED_DOWNLOAD_UNAVAILABLE')
    return value


def web_download_check(engine, root, owner, archive, runner):
    """Exercise the actual web UID and download service without booting Laravel."""
    code = r'''function config($key,$default=null) {
    if($key==='desktop_dashboard.enabled')return true;
    if($key==='desktop_dashboard.local')return false;
    return $default;
}
function abort_unless($value,$status=503,$message=null) {
    if(!$value)throw new RuntimeException('GO_ERP_WEB_DOWNLOAD_CHECK_FAILED');
}
function abort($status=503,$message=null) {
    throw new RuntimeException('GO_ERP_WEB_DOWNLOAD_CHECK_FAILED');
}
if(!function_exists('posix_geteuid')||posix_geteuid()!==(int)$argv[4])
    throw new RuntimeException('GO_ERP_WEB_UID_CHECK_FAILED');
require $argv[1].'/app/Services/Dashboard/DesktopDashboardSource.php';
require $argv[1].'/app/Services/Dashboard/DesktopDashboardUpdates.php';
$service=new \App\Services\Dashboard\DesktopDashboardUpdates(new \App\Services\Dashboard\DesktopDashboardSource);
$records=$service->publishedDownloads();
$previous=$argv[3]==='none'?null:$argv[3];
abort_unless(($records['current']['version']??null)===$argv[2]
    &&($records['previous']['version']??null)===$previous);
$download=$service->publishedInstaller($records['current']['installer']['sha256']);
try {
    abort_unless(is_resource($download['stream']??null)&&($download['version']??null)===$argv[2]);
}finally{if(is_resource($download['stream']??null))fclose($download['stream']);}
echo "GO_ERP_PUBLISHED_WEB_DOWNLOAD_READABLE\n";
'''
    try:
        output = runner(['/usr/sbin/runuser', '-u', owner.pw_name, '--', engine.PHP,
            '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', code, str(root),
            archive['current_version'], archive['previous_version'] or 'none', str(owner.pw_uid)], timeout=180)
    except BaseException:
        raise Stop('WEB_DOWNLOAD_NOT_READABLE') from None
    need(output == 'GO_ERP_PUBLISHED_WEB_DOWNLOAD_READABLE', 'WEB_DOWNLOAD_NOT_READABLE')


def restore_receipt(engine, path, before, desired):
    actual = engine.read_file(path, 8192, uid=0, modes=(0o600,))
    if actual[0] == before[0]:
        return
    need(actual[0] == desired, 'RECEIPT_ROLLBACK_REQUIRES_REVIEW')
    engine.atomic_write(path, before[0], 0, 0, 0o600)


def upgrade(engine, root, owner, publisher, app_payloads, program_payloads, release,
            control=CONTROL, native=NATIVE, releases=RELEASES, systemd=SYSTEMD,
            runner=None, archiver=None):
    """Caller holds install and native watcher locks. No service is ever stopped."""
    runner = runner or engine.run
    inactive(engine, runner)
    receipt, old_units = installed(engine, owner, control, releases, systemd)
    need(set(app_payloads) == set(APP_PINS) and all(engine.sha(raw) == APP_PINS[name][1]
        for name, raw in app_payloads.items()), 'APP_PAYLOAD_PINS_INVALID')
    publication = engine.publisher(publisher)
    for name, pins in APP_PINS.items():
        actual = engine.read_file(root/name, uid=owner.pw_uid, modes=(0o644,))
        need(engine.sha(actual[0]) in pins, 'APP_BASELINE_MISMATCH')
    changes = publication.plan(root, engine.app_manifest(), app_payloads, owner_uid=owner.pw_uid)
    engine.php_preflight(app_payloads, owner, runner=runner)
    runtime = engine.install_runtime(program_payloads, releases)
    desired_units = engine.units(runtime)
    need(desired_units[WATCH] == old_units[WATCH][0], 'PATH_UNIT_CHANGE_FORBIDDEN')
    receipt_path = control/'private/installation.json'
    wanted_receipt = engine.canonical({'format': 1, 'kind': 'go-erp-manual-installation',
        'release': release, 'runtime': runtime.name,
        'units': {name: engine.sha(raw) for name, raw in desired_units.items()}})
    audit = control/'private/audits'/('downloads-'+release[:12]+'-'+uuid.uuid4().hex)
    audit.mkdir(mode=0o700)
    cache = engine.cache_entries(root, owner.pw_uid)
    before_path = runner(['/usr/bin/systemctl', 'show', WATCH, '-p', 'ActiveState', '--value'])
    need(before_path in ('active', 'inactive', 'failed'), 'PATH_STATE_INVALID')
    path_stopped = service_attempted = receipt_attempted = invalidated = committed = False
    app_attempted = []
    archive = None
    try:
        engine.atomic_write(audit/'intent.json', engine.canonical({'format': 1,
            'kind': 'go-erp-published-downloads-installation', 'release': release,
            'runtime': runtime.name, 'path_was_active': before_path == 'active'}), 0, 0, 0o600)
        engine.atomic_write(audit/'before-service', old_units[SERVICE][0], 0, 0, 0o600)
        engine.atomic_write(audit/'before-receipt.json', receipt[0], 0, 0, 0o600)
        # Stop only event delivery. Recheck after the stop so a request that
        # raced the first quiescence check cannot be interrupted or overwritten.
        path_stopped = True
        runner(['/usr/bin/systemctl', 'stop', WATCH])
        need(runner(['/usr/bin/systemctl', 'show', WATCH, '-p', 'ActiveState', '--value'])
            == 'inactive', 'PATH_STILL_ACTIVE')
        inactive(engine, runner)
        archive_function = archiver or load_archive(runtime/'go_erp_published_download_root.py')
        archive = archive_result(archive_function(root, native/'state/current.json',
            control/'shared', owner.pw_gid))
        # A verified archive is independently useful and remains on rollback.
        engine.atomic_write(audit/'published-archive.json', engine.canonical(archive), 0, 0, 0o600)
        invalidated = True
        engine.invalidate_caches(cache, audit, owner.pw_uid)
        engine.write_application(root, changes, audit, owner.pw_uid, owner.pw_gid, publication, app_attempted)
        web_download_check(engine, root, owner, archive, runner)
        inactive(engine, runner)
        current_unit = engine.read_file(systemd/SERVICE, 65536, uid=0, modes=(0o644,))
        need(engine.identity(current_unit[1]) == engine.identity(old_units[SERVICE][1])
            and current_unit[0] == old_units[SERVICE][0], 'SERVICE_UNIT_CHANGED')
        if current_unit[0] != desired_units[SERVICE]:
            service_attempted = True
            engine.atomic_write(systemd/SERVICE, desired_units[SERVICE], 0, 0, 0o644)
        runner(['/usr/bin/systemctl', 'daemon-reload'])
        for name, raw in desired_units.items():
            need(engine.read_file(systemd/name, 65536, uid=0, modes=(0o644,))[0] == raw, 'UNIT_VERIFY_FAILED')
        for name, raw in app_payloads.items():
            need(engine.read_file(root/name, uid=owner.pw_uid, modes=(0o644,))[0] == raw, 'APP_VERIFY_FAILED')
        current_receipt = engine.read_file(receipt_path, 8192, uid=0, modes=(0o600,))
        need(engine.identity(current_receipt[1]) == engine.identity(receipt[1])
            and current_receipt[0] == receipt[0], 'RECEIPT_CHANGED')
        receipt_attempted = True
        engine.atomic_write(receipt_path, wanted_receipt, 0, 0, 0o600)
        engine.atomic_write(audit/'completed.json', engine.canonical({'ok': True,
            'archive_retained': True, 'source_changed': bool(changes)}), 0, 0, 0o600)
        committed = True
    except BaseException:
        if not (app_attempted or service_attempted or receipt_attempted or invalidated):
            if path_stopped and before_path == 'active':
                try:
                    runner(['/usr/bin/systemctl', 'start', WATCH])
                except BaseException:
                    raise Stop('UNCHANGED_PATH_ACTIVATION_REQUIRED') from None
            raise
        try:
            # Since path activation occurs only after commit, a service that
            # appears here belongs to an external actor: never terminate it.
            inactive(engine, runner)
            if service_attempted:
                current = engine.read_file(systemd/SERVICE, 65536, uid=0, modes=(0o644,))
                need(current[0] in (old_units[SERVICE][0], desired_units[SERVICE]), 'UNIT_ROLLBACK_REQUIRES_REVIEW')
                if current[0] != old_units[SERVICE][0]:
                    engine.atomic_write(systemd/SERVICE, old_units[SERVICE][0], 0, 0, 0o644)
                runner(['/usr/bin/systemctl', 'daemon-reload'])
            engine.rollback_application(root, app_attempted, owner.pw_uid, owner.pw_gid)
            if invalidated:
                engine.restore_caches(cache)
            if receipt_attempted:
                restore_receipt(engine, receipt_path, receipt, wanted_receipt)
            engine.atomic_write(audit/'rolled-back.json', engine.canonical({'ok': False,
                'verified_archive_retained': archive is not None}), 0, 0, 0o600)
        except BaseException:
            raise Stop('ROLLBACK_REQUIRES_REVIEW') from None
        if path_stopped and before_path == 'active':
            try:
                runner(['/usr/bin/systemctl', 'start', WATCH])
            except BaseException:
                raise Stop('ROLLED_BACK_PATH_ACTIVATION_REQUIRED') from None
        raise
    need(committed, 'COMMIT_NOT_REACHED')
    # This is the only operation after the commit point. Existing explicit
    # owner requests may now run against the consistent upgraded application.
    try:
        runner(['/usr/bin/systemctl', 'start', WATCH])
        need(runner(['/usr/bin/systemctl', 'show', WATCH, '-p', 'ActiveState', '--value'])
            == 'active', 'PATH_NOT_ACTIVE')
    except BaseException:
        raise Stop('INSTALLED_PATH_ACTIVATION_REQUIRED') from None
    return {'runtime': str(runtime), 'audit': str(audit), 'source_changed': bool(changes),
        'archive': archive, 'path_active': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    need(args.apply and os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
        'ROOT_HOST_APPLY_REQUIRED')
    need(re.fullmatch(r'[a-f0-9]{40}', args.release), 'RELEASE_INVALID')
    owner = pwd.getpwnam('fasakha')
    need(ROOT.resolve() == ROOT and ROOT.is_dir() and ROOT.stat().st_uid == owner.pw_uid
        and not ROOT.stat().st_mode & 0o022, 'APPLICATION_ROOT_UNSAFE')
    repository = Path(args.git_dir).absolute()
    need(repository.resolve() == repository and repository.is_dir() and repository.stat().st_uid == 0
        and not repository.stat().st_mode & 0o022, 'PACKAGE_UNSAFE')
    def git(*values):
        raw = subprocess.check_output(['/usr/bin/git', '--git-dir='+str(repository),
            '-c', 'credential.helper=', '-c', 'core.hooksPath=/dev/null', *values],
            stderr=subprocess.DEVNULL, timeout=30, env={'PATH': '/usr/bin:/bin',
                'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
                'GIT_CONFIG_SYSTEM': '/dev/null', 'GIT_NO_REPLACE_OBJECTS': '1',
                'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0'})
        need(len(raw) <= 2*1024**2, 'PACKAGE_LIMIT')
        return raw
    need(git('rev-parse', args.release+'^{commit}').decode().strip() == args.release, 'RELEASE_INVALID')
    engine = engine_from(git('show', args.release+':deployment/apply_go_erp_manual_updates_root.py'))
    engine.parents(repository)
    app = {name: git('show', args.release+':'+name) for name in APP_PINS}
    programs = {name: git('show', args.release+':deployment/'+name) for name in PROGRAM_PINS}
    publisher = git('show', args.release+':deployment/apply_paymob_order_confirmation_root.py')
    inactive(engine, engine.run)
    engine.directory(CONTROL, 0, 0, 0o755, False)
    engine.directory(CONTROL/'private', 0, 0, 0o700, False)
    engine.parents(NATIVE/'watcher.lock')
    native_info = NATIVE.lstat()
    need(stat.S_ISDIR(native_info.st_mode) and native_info.st_uid == 0
        and not native_info.st_mode & 0o022, 'NATIVE_DIRECTORY_UNSAFE')
    # No provisioning, timer mutation, queue creation, or native state writes.
    with ExitStack() as stack:
        engine.acquire(stack, CONTROL/'private/install.lock')
        engine.acquire(stack, NATIVE/'watcher.lock')
        result = upgrade(engine, ROOT, owner, publisher, app, programs, args.release)
    print(json.dumps({'format': 1, 'kind': 'go-erp-published-downloads-installed', 'ok': True,
        'automatic_updates_enabled': False, 'database_changed': False,
        'credentials_disclosed': False, 'source_changed': result['source_changed'],
        'runtime': result['runtime'], 'path_active': True, 'archive': result['archive']}))
    print(MARKER)


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        code = str(error) if type(error) is Stop and re.fullmatch(r'[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'format': 1, 'kind': 'go-erp-published-downloads-installed', 'ok': False,
            'code': 'GO_ERP_PUBLISHED_DOWNLOADS_INSTALL_'+code,
            'automatic_updates_enabled': False, 'database_changed': False,
            'credentials_disclosed': False}))
        raise SystemExit(1)
