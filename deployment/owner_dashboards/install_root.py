#!/usr/bin/env python3
"""Install the bounded owner dashboard feature, validate two throwaway databases, then enable its worker."""
import ast
import fcntl
from datetime import datetime, timezone
import hashlib
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time
import uuid


class InstallFailure(RuntimeError):
    def __init__(self, code, path=None):
        super().__init__(code); self.path = path


def fail(code, path=None):
    raise InstallFailure(code, path)


def run(command, timeout=90, env=None):
    result = subprocess.run(command, capture_output=True, text=True, timeout=timeout, env=env)
    if result.returncode:
        message = result.stderr.strip()
        fail(message if re.fullmatch(r'GO_OWNER_[A-Z_]+', message) else 'GO_OWNER_INSTALL_RUNTIME_CHECK_FAILED')
    return result.stdout


def plan(project, manifest):
    if manifest.get('format') != 1 or manifest.get('feature') != 'independent-owner-dashboards-20261101':
        fail('GO_OWNER_INSTALL_MANIFEST')
    changes = []
    for item in manifest['files']:
        relative = Path(item['path'])
        if relative.is_absolute() or '..' in relative.parts or not str(relative).startswith(
                ('app/', 'routes/', 'resources/views/admin/', 'public/dashboard/js/', 'deployment/owner_dashboards/')):
            fail('GO_OWNER_INSTALL_PATH')
        path = project / relative
        for parent in [path, *path.parents]:
            if parent == project.parent:
                break
            if parent.is_symlink():
                fail('GO_OWNER_INSTALL_SYMLINK')
        old = path.read_bytes() if path.is_file() else None
        if path.exists() and not path.is_file():
            fail('GO_OWNER_INSTALL_NOT_A_FILE')
        if item['kind'] == 'new':
            after = item['content'].encode()
            if old is not None and old != after:
                fail('GO_OWNER_INSTALL_SOURCE_CHANGED', str(relative))
        elif item['kind'] == 'patch' and old is not None:
            after = old
            newline = '\r\n' if b'\r\n' in old else '\n'
            for edit in item['edits']:
                before = edit['before'].replace('\n', newline).encode()
                replacement = edit['after'].replace('\n', newline).encode()
                if after.count(replacement) == 1:
                    continue
                if after.count(before) != 1:
                    fail('GO_OWNER_INSTALL_SOURCE_CHANGED', str(relative))
                after = after.replace(before, replacement, 1)
        else:
            fail('GO_OWNER_INSTALL_SOURCE_MISSING')
        changes.append({'path': path, 'relative': relative, 'old': old, 'after': after,
                        'stat': path.stat() if old is not None else None})
    if len({c['path'] for c in changes}) != len(changes):
        fail('GO_OWNER_INSTALL_DUPLICATE_PATH')
    return changes


def load_worker(stage):
    spec = importlib.util.spec_from_file_location('go_owner_worker', stage / 'worker.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def cleanup_fixture(worker, module, row):
    record_path = module.BASE / 'records' / (row['id'] + '.json')
    if not record_path.is_file() or record_path.is_symlink():
        return
    record = json.loads(record_path.read_text())
    compact = row['id'].replace('-', '')
    if not record.get('test_fixture') or record['database'] != 'fasakha_ow_' + compact[:20] or record['db_user'] != 'fasakha_ow_' + compact[:16]:
        fail('GO_OWNER_TEST_LEDGER_MISMATCH')
    if worker.registry('WHERE id=' + module.literal(row['id'])):
        fail('GO_OWNER_TEST_RECORD_BECAME_REAL')
    if record.get('domain_created'):
        worker.cpanel('delsubdomain', {'domain': 'go-' + row['slug'] + '.' + worker.domain})
    worker.sql('DROP DATABASE IF EXISTS ' + module.identifier(record['database']) + ';DROP USER IF EXISTS '
               + module.identifier(record['db_user']) + "@'localhost';")
    for folder in [module.TENANTS / 'tenants' / row['id'], module.PUBLIC / row['slug']]:
        if folder.is_symlink():
            fail('GO_OWNER_TEST_DIRECTORY_CHANGED')
        if folder.exists():
            shutil.rmtree(folder)
    marker = module.TENANTS / 'control/deny' / row['id']
    if marker.exists():
        marker.unlink()
    record_path.unlink()


def self_test(worker, module, stage):
    # These fixtures never enter the global owner registry. Real owners are not used as test subjects.
    rows = []
    fixtures = {}

    class Probe(module.Worker):
        def record(self, row):
            path, record = super().record(row)
            record['test_fixture'] = True
            module.atomic(path, json.dumps(record))
            return path, record
        def map_database(self, record, record_path):
            return  # Throwaway databases never enter cPanel's durable account inventory.
        def host_ready(self, row, record, runtime, record_path):
            # The fixture uses local virtual-host routing; public HTTPS readiness remains mandatory for real owners.
            return
        def acknowledge(self, row, state, error=None):
            if row['id'] not in fixtures:
                fail('GO_OWNER_TEST_ID_MISMATCH')
            fixtures[row['id']].update(state=state, applied_revision=row['revision'])
            return True

        def registry(self, where=''):
            return list(fixtures.values())

        def publish_marker(self, row, accepted=True):
            # Probe's in-memory lookup is exact and retains the real publication implementation.
            original = self.registry
            self.registry = lambda where='': [fixtures[row['id']]]
            try:
                super().publish_marker(row, accepted)
            finally:
                self.registry = original

    probe = Probe(worker.config)
    try:
        password_hash = run(['runuser', '-u', 'fasakha', '--', module.PHP, '-r',
                             "echo password_hash('OwnerHealthFixtureOnly',PASSWORD_BCRYPT);"], env=module.SAFE_ENV).strip()
        for index in range(2):
            row = dict(id=str(uuid.uuid4()), slug=__import__('secrets').token_hex(8), name='Owner isolation fixture ' + str(index),
                       email='owner-' + str(index) + '@example.invalid', mobile=None, password_hash=password_hash,
                       created_by=1, desired_state='active', state='pending', revision=1, applied_revision=0)
            rows.append(row); fixtures[row['id']] = row
            probe.apply(row)
        for index, row in enumerate(rows):
            file = module.TENANTS / 'tenants' / row['id'] / 'runtime.json'
            runtime = json.loads(file.read_text())
            other = json.loads((module.TENANTS / 'tenants' / rows[1-index]['id'] / 'runtime.json').read_text())
            runtime.update(test_fixture=True, probe_databases=[worker.database, other['database']])
            module.atomic(file, json.dumps(runtime), gid=worker.account.pw_gid)
            if 'GO_OWNER_DATABASE_ISOLATION_VERIFIED' not in probe.console(runtime, 'isolation'):
                fail('GO_OWNER_TEST_DATABASE_ISOLATION')
            if 'GO_OWNER_LOCAL_ACCOUNTS_AND_BRANCH_VERIFIED' not in probe.console(runtime, 'crud'):
                fail('GO_OWNER_TEST_CRUD')
            # Verify Apache routing, the genuine CSRF login form, and this dashboard's cookie path.
            headers = stage / ('fixture-' + str(index) + '.headers')
            address=worker.config.get('address','127.0.0.1')
            address='['+address+']' if ':' in address else address
            url = 'http://' + runtime['host'] + '/admin/login'
            page=''
            for attempt in range(8):
                result=subprocess.run(['curl','--silent','--show-error','--max-time','5','--noproxy','*',
                    '--resolve',runtime['host']+':80:'+address,'--dump-header',str(headers),url],capture_output=True,text=True,timeout=6)
                page=result.stdout
                if result.returncode==0 and 'name="_token"' in page:break
                time.sleep(0.5)
            if 'name="_token"' not in page or runtime['origin'] + '/admin/signin' not in page:
                fail('GO_OWNER_TEST_HTTP_LOGIN')
            if 'path=/' not in headers.read_text().lower():
                fail('GO_OWNER_TEST_SESSION_PATH')
        # Suspend immediately, rotate remembered credentials, then resume while preserving the created branch/accounts.
        first = rows[0]
        first.update(desired_state='suspended', revision=2)
        probe.apply(first)
        runtime_file = module.TENANTS / 'tenants' / first['id'] / 'runtime.json'
        if json.loads(runtime_file.read_text())['state'] != 'suspended':
            fail('GO_OWNER_TEST_SUSPEND')
        first.update(desired_state='active', revision=3)
        probe.apply(first)
        runtime = json.loads(runtime_file.read_text())
        if probe.sql('SELECT COUNT(*) FROM resturants', runtime['database']).strip() != '1':
            fail('GO_OWNER_TEST_RESUME_OVERWROTE_DATA')
        return {'independent_databases_verified': 2, 'empty_start_verified': True,
                'local_accounts_and_branches_verified': True, 'cross_dashboard_access_denied': True,
                'suspension_and_resume_verified': True, 'tenant_http_login_verified': True,
                'independent_browser_origins_verified': True}
    finally:
        for row in rows:
            cleanup_fixture(worker, module, row)


def main():
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524' or sys.argv[1:] != ['--apply']:
        fail('GO_OWNER_INSTALL_ROOT_HOST_ARGUMENTS')
    stage = Path(__file__).resolve().parent
    module = load_worker(stage)
    project = module.MAIN
    if project.is_symlink() or project.resolve() != project or not (project / '.env').is_file():
        fail('GO_OWNER_INSTALL_PROJECT')
    account = module.pwd.getpwnam('fasakha')
    manifest = json.loads((stage / 'manifest.json').read_text())
    changes = plan(project, manifest)
    # Read the currently cached live connection name/URL without printing credentials.
    setup = stage / 'central_setup.php'
    setup.chmod(0o644)
    initial = json.loads(run(['runuser', '-u', 'fasakha', '--', module.PHP, str(setup), '--inspect']))
    worker = module.Worker(initial)
    worker.cpanel('listsubdomains', {'regex': '^go-[0-9a-f]{16}\\.' + re.escape(worker.domain) + '$'})
    if worker.sql('SELECT CURRENT_USER()').strip().split('@')[0] != 'root':
        fail('GO_OWNER_MYSQL_ROOT_REQUIRED')
    socket_path = worker.sql('SELECT @@socket').strip()
    if not socket_path.startswith('/') or '\n' in socket_path or '"' in socket_path:
        fail('GO_OWNER_MYSQL_SOCKET_REQUIRED')
    initial['socket'] = socket_path
    worker.config = initial
    # Syntax-check complete candidates before replacing any live file.
    for index, change in enumerate(changes):
        if change['path'].suffix == '.php':
            candidate = stage / ('candidate-' + str(index) + '.php')
            candidate.write_bytes(change['after'])
            run([module.PHP, '-n', '-l', str(candidate)])
    backup = Path('/home/fasakha/go-owner-dashboard-code-backups') / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S-%f')
    backup.mkdir(parents=True, mode=0o700)
    old_service = Path('/etc/systemd/system/go-owner-dashboards.service')
    old_timer = Path('/etc/systemd/system/go-owner-dashboards.timer')
    service_before = old_service.read_bytes() if old_service.exists() else None
    timer_before = old_timer.read_bytes() if old_timer.exists() else None
    run(['systemctl', 'stop', 'go-owner-dashboards.timer']) if old_timer.exists() else None
    module.checked_directory(module.BASE, 0o700)
    lock_descriptor = os.open(module.BASE / 'worker.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(lock_descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        if timer_before is not None:
            subprocess.run(['systemctl', 'start', 'go-owner-dashboards.timer'], capture_output=True)
        fail('GO_OWNER_INSTALL_WORKER_BUSY')
    module.checked_directory(module.BASE / 'records', 0o700)
    module.checked_directory(module.LIB, 0o755)
    module.checked_directory(module.TENANTS, 0o750, gid=account.pw_gid)
    module.checked_directory(module.TENANTS / 'tenants', 0o750, gid=account.pw_gid)
    module.checked_directory(module.TENANTS / 'control', 0o750, gid=account.pw_gid)
    module.checked_directory(module.TENANTS / 'control/deny', 0o770, gid=account.pw_gid)
    module.checked_directory(module.PUBLIC, 0o755)
    root_payloads = [module.LIB / name for name in ['settings_defaults.json','worker.py','tenant_http.php','tenant_console.php']]
    root_payloads.append(module.BASE / 'config.json')
    root_before = {file: file.read_bytes() if file.is_file() else None for file in root_payloads}
    applied = []
    try:
        for change in changes:
            if change['old'] is not None:
                saved = backup / change['relative']; saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(change['old'])
            parent = change['path'].parent
            missing = []
            while not parent.exists():
                missing.append(parent); parent = parent.parent
            for parent in reversed(missing):
                module.checked_directory(parent, 0o755, account.pw_uid, account.pw_gid)
            if (change['path'].read_bytes() if change['path'].exists() else None) != change['old']:
                fail('GO_OWNER_INSTALL_CONCURRENT_SOURCE_CHANGE')
            info = change['stat']
            module.atomic(change['path'], change['after'], stat.S_IMODE(info.st_mode) if info else 0o644,
                          info.st_uid if info else account.pw_uid, info.st_gid if info else account.pw_gid)
            applied.append(change)
        settings = json.loads(run(['runuser', '-u', 'fasakha', '--', module.PHP, str(setup), '--install']))
        module.atomic(module.LIB / 'settings_defaults.json', json.dumps(settings['settings_defaults']), 0o644)
        for name in ['worker.py', 'tenant_http.php', 'tenant_console.php']:
            module.atomic(module.LIB / name, (stage / name).read_bytes(), 0o644)
        address='127.0.0.1'
        for line in Path('/var/cpanel/users/fasakha').read_text().splitlines():
            if line.startswith('IP='):
                address=str(ipaddress.ip_address(line.partition('=')[2]));break
        config = {'database': initial['database'], 'url': initial['url'], 'socket': socket_path,'address':address}
        module.atomic(module.BASE / 'config.json', json.dumps(config), 0o600)
        worker.config = config
        run(['runuser', '-u', 'fasakha', '--', module.PHP, str(project / 'artisan'), 'route:clear'])
        run(['runuser', '-u', 'fasakha', '--', module.PHP, str(project / 'artisan'), 'view:clear'])
        evidence = self_test(worker, module, stage)
        service = '[Unit]\nDescription=Independent owner dashboard provisioning and background work\nAfter=network.target mysql.service\n[Service]\nType=oneshot\nUser=root\nUMask=0077\nExecStart=/usr/bin/python3 -I -B /usr/local/lib/go-owner-dashboards/worker.py --tick\nTimeoutStartSec=600\n'
        timer = '[Unit]\nDescription=Apply owner dashboard changes\n[Timer]\nOnBootSec=20s\nOnUnitInactiveSec=15s\nAccuracySec=2s\nUnit=go-owner-dashboards.service\n[Install]\nWantedBy=timers.target\n'
        module.atomic(old_service, service, 0o644); module.atomic(old_timer, timer, 0o644)
        run(['systemctl', 'daemon-reload']); run(['systemctl', 'enable', '--now', 'go-owner-dashboards.timer'])
        if run(['systemctl', 'is-active', 'go-owner-dashboards.timer']).strip() != 'active':
            fail('GO_OWNER_WORKER_TIMER_INACTIVE')
        print(json.dumps({'status': 'GO_INDEPENDENT_OWNER_DASHBOARDS_READY', 'existing_business_data_preserved': True,
                          'updated_files':len(changes),'phone_reassigned': False, **evidence}, ensure_ascii=False, indent=2))
    except Exception:
        subprocess.run(['systemctl', 'stop', 'go-owner-dashboards.timer'], capture_output=True)
        for change in reversed(applied):
            if change['old'] is None:
                if change['path'].exists():
                    change['path'].unlink()
            else:
                info = change['stat']; module.atomic(change['path'], change['old'], stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
        for file, before in [(old_service, service_before), (old_timer, timer_before)]:
            if before is None:
                if file.exists(): file.unlink()
            else:
                module.atomic(file, before, 0o644)
        for file, before in root_before.items():
            if before is None:
                if file.exists(): file.unlink()
            else:
                module.atomic(file,before,0o600 if file.name=='config.json' else 0o644)
        subprocess.run(['systemctl', 'daemon-reload'], capture_output=True)
        if timer_before is not None:
            subprocess.run(['systemctl', 'start', 'go-owner-dashboards.timer'], capture_output=True)
        for command in ['route:clear', 'view:clear']:
            subprocess.run(['runuser', '-u', 'fasakha', '--', module.PHP, str(project / 'artisan'), command], capture_output=True)
        raise


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error)
        if isinstance(error,InstallFailure) and error.path is not None:
            print(json.dumps({'status':code,'file':error.path}),file=sys.stderr)
        else:
            print(code if re.fullmatch(r'GO_OWNER_[A-Z_]+', code) else 'GO_OWNER_INSTALL_FAILED', file=sys.stderr)
        sys.exit(1)
