#!/usr/bin/env python3
"""Incremental, hash-guarded website Purchase installation; never sends events."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import tempfile

APP = Path('/home/fasakha/public_html')
BACKUPS = Path('/home/fasakha/backups')
CRON = Path('/etc/cron.d/fasakhansta-meta-website-purchases')
LOG = Path('/home/fasakha/logs/meta-website-purchases.log')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
CONFIG_CHECK = r'''try {
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->bootstrapWith([
Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);
$config = $app->make('config');
echo json_encode([
'enabled' => filter_var($config->get('services.meta_conversions.enabled', false), FILTER_VALIDATE_BOOLEAN),
'dataset_id' => preg_match('/^\d{5,32}$/D', (string) $config->get('services.meta_conversions.dataset_id', ''))
    ? (string) $config->get('services.meta_conversions.dataset_id', '') : 'nonstandard',
'token_configured' => trim((string) $config->get('services.meta_conversions.access_token', '')) !== '',
'test_event_code_configured' => trim((string) $config->get('services.meta_conversions.test_event_code', '')) !== '',
], JSON_THROW_ON_ERROR);
} catch (Throwable $error) { fwrite(STDERR, "CONFIG_CHECK_FAILED\n"); exit(1); }'''
MIGRATION = 'database/migrations/2026_11_13_200000_create_meta_website_purchases_table.php'
PATHS = {
    'app/Services/Site/WebStorefront.php',
    'app/Services/Site/WebsiteMetaPurchase.php',
    'app/Console/Commands/SendWebsiteMetaPurchases.php', MIGRATION,
}
CRON_DATA = ("SHELL=/bin/bash\nPATH=/usr/local/bin:/usr/bin:/bin\n"
    "* * * * * fasakha cd /home/fasakha/public_html && /usr/bin/flock -n "
    "/home/fasakha/.meta-website-purchases.lock /opt/cpanel/ea-php82/root/usr/bin/php "
    "artisan meta:website-purchases --limit=25 >> "
    "/home/fasakha/logs/meta-website-purchases.log 2>&1\n").encode()


class PublishError(RuntimeError):
    pass


def digest(data):
    return hashlib.sha256(data).hexdigest()


def plain(path, missing=True):
    """Refuse symlink components, directories at file destinations, and special files."""
    path = Path(path).absolute()
    for component in [path, *path.parents]:
        if component.is_symlink():
            raise PublishError('SYMLINK_REFUSED')
        if component != path and component.exists() and not component.is_dir():
            raise PublishError('NONDIRECTORY_PARENT_REFUSED')
    if path.exists() and not path.is_file():
        raise PublishError('NONREGULAR_FILE_REFUSED')
    if not missing and not path.is_file():
        raise PublishError('REQUIRED_FILE_MISSING')


def current(path):
    plain(path)
    return path.read_bytes() if path.exists() else None


def atomic(path, data, mode, uid, gid):
    path = Path(path)
    plain(path)
    missing = []
    parent = path.parent
    while not parent.exists():
        missing.append(parent)
        parent = parent.parent
    for parent in reversed(missing):
        parent.mkdir(mode=0o755)
        os.chown(parent, uid, gid)
    plain(path)
    fd, name = tempfile.mkstemp(prefix='.' + path.name + '.', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
            os.fchmod(handle.fileno(), mode)
            os.fchown(handle.fileno(), uid, gid)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


class Publisher:
    def __init__(self, package, app=APP, backups=BACKUPS, cron=CRON,
                 log=LOG, runner=subprocess.run, identity=None, cron_owner=(0, 0)):
        self.package, self.app = Path(package), Path(app)
        self.backups, self.cron, self.log = Path(backups), Path(cron), Path(log)
        self.runner = runner
        account = identity or pwd.getpwnam('fasakha')
        self.uid, self.gid = account.pw_uid, account.pw_gid
        self.cron_owner = cron_owner
        plain(self.package / 'manifest.json', False)
        self.manifest = json.loads((self.package / 'manifest.json').read_text())
        if not isinstance(self.manifest, dict) or set(self.manifest) != PATHS:
            raise PublishError('INVALID_MANIFEST_PATHS')
        for name, spec in self.manifest.items():
            if not isinstance(spec, dict) or set(spec) != {'before', 'after'}:
                raise PublishError('INVALID_MANIFEST_ENTRY')
            if not isinstance(spec['after'], str) or not re.fullmatch('[a-f0-9]{64}', spec['after']):
                raise PublishError('INVALID_PAYLOAD_HASH')
            before = spec['before']
            if before is not None and (not isinstance(before, str) or not re.fullmatch('[a-f0-9]{64}', before)):
                raise PublishError('INVALID_BASE_HASH')
            if (name == 'app/Services/Site/WebStorefront.php') != (before is not None):
                raise PublishError('INVALID_BASE_POLICY')

    def command(self, args):
        result = self.runner(args, cwd=self.app, capture_output=True, text=True)
        if result.returncode:
            raise PublishError('COMMAND_FAILED')
        return result.stdout

    def preflight(self):
        states = {}
        for name, spec in sorted(self.manifest.items()):
            source = self.package / 'payload' / name
            plain(source, False)
            if digest(source.read_bytes()) != spec['after']:
                raise PublishError('PAYLOAD_HASH_MISMATCH')
            old = current(self.app / name)
            old_hash = digest(old) if old is not None else None
            if old_hash not in (spec['before'], spec['after']):
                raise PublishError('TARGET_CHANGED')
            states[name] = old_hash
        cron = current(self.cron)
        if cron not in (None, CRON_DATA):
            raise PublishError('UNEXPECTED_CRON')
        plain(self.log)
        for name in sorted(self.manifest):
            self.command([PHP, '-l', str(self.package / 'payload' / name)])
        self.validate_readiness(self.command(['runuser', '-u', 'fasakha', '--', PHP,
                                             '-d', 'display_errors=0', '-r', CONFIG_CHECK]), False)
        return states, cron

    def validate_readiness(self, output, table=True):
        try:
            result = json.loads(output)
        except (TypeError, ValueError):
            raise PublishError('INVALID_READINESS_OUTPUT') from None
        if not (result.get('enabled') is True and (not table or result.get('table_present') is True)
                and result.get('token_configured') is True
                and result.get('test_event_code_configured') is False
                and str(result.get('dataset_id')) == '1183070883497428'):
            raise PublishError('TRACKING_NOT_READY')
        return result

    def readiness(self):
        return self.validate_readiness(self.command(['runuser', '-u', 'fasakha', '--', PHP, 'artisan',
                               'meta:website-purchases', '--check']))

    def backup(self):
        for parent in [self.backups, *self.backups.parents]:
            if parent.is_symlink():
                raise PublishError('SYMLINK_REFUSED')
        self.backups.mkdir(parents=True, exist_ok=True, mode=0o700)
        folder = Path(tempfile.mkdtemp(prefix='meta-website-purchase-' +
            dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-', dir=self.backups))
        os.chmod(folder, 0o700)
        entries = {}
        for key, target in [(name, self.app / name) for name in sorted(PATHS)] + [('cron', self.cron)]:
            data = current(target)
            expected = (None, CRON_DATA) if key == 'cron' else (self.manifest[key]['before'], self.manifest[key]['after'])
            candidate = data if key == 'cron' else digest(data) if data is not None else None
            if candidate not in expected:
                raise PublishError('TARGET_CHANGED_BEFORE_BACKUP')
            if data is None:
                entries[key] = None
                continue
            info = target.stat()
            backup_name = 'cron' if key == 'cron' else 'files/' + key
            atomic(folder / backup_name, data, 0o600, os.getuid(), os.getgid())
            entries[key] = dict(hash=digest(data), mode=stat.S_IMODE(info.st_mode),
                                uid=info.st_uid, gid=info.st_gid, file=backup_name)
        record = {'version': 1, 'manifest': self.manifest, 'entries': entries}
        atomic(folder / 'receipt.json', json.dumps(record, indent=2).encode(),
               0o600, os.getuid(), os.getgid())
        return folder

    def rollback(self, folder):
        folder = Path(folder).absolute()
        if folder.parent != self.backups.absolute() or not folder.name.startswith('meta-website-purchase-'):
            raise PublishError('INVALID_BACKUP_PATH')
        if folder.is_symlink() or not folder.is_dir():
            raise PublishError('INVALID_BACKUP_DIRECTORY')
        info = folder.stat()
        if info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) != 0o700:
            raise PublishError('INSECURE_BACKUP')
        record_path = folder / 'receipt.json'
        plain(record_path, False)
        record = json.loads(record_path.read_text())
        if record.get('version') != 1 or record.get('manifest') != self.manifest:
            raise PublishError('BACKUP_RELEASE_MISMATCH')
        entries = record.get('entries', {})
        if set(entries) != PATHS | {'cron'}:
            raise PublishError('INVALID_BACKUP_ENTRIES')
        restores = []
        for key, entry in entries.items():
            target = self.cron if key == 'cron' else self.app / key
            data = current(target)
            actual = digest(data) if data is not None else None
            after = digest(CRON_DATA) if key == 'cron' else self.manifest[key]['after']
            if actual not in (after, entry['hash'] if entry else None):
                raise PublishError('ROLLBACK_TARGET_CHANGED')
            old = None
            if entry:
                expected = 'cron' if key == 'cron' else 'files/' + key
                if entry.get('file') != expected:
                    raise PublishError('INVALID_BACKUP_FILE')
                plain(folder / expected, False)
                old = (folder / expected).read_bytes()
                if digest(old) != entry['hash']:
                    raise PublishError('BACKUP_HASH_MISMATCH')
            restores.append((target, old, entry))
        # Stop the dedicated sender before restoring any application code.
        restores.sort(key=lambda item: item[0] != self.cron)
        for target, old, entry in restores:
            if old is None:
                if target.exists():
                    target.unlink()
            else:
                atomic(target, old, entry['mode'], entry['uid'], entry['gid'])
        return {'status': 'ROLLED_BACK', 'backup': str(folder), 'schema_retained': True}

    def apply(self):
        states, cron = self.preflight()
        if all(states[name] == spec['after'] for name, spec in self.manifest.items()) and cron == CRON_DATA:
            return {'status': 'ALREADY_APPLIED', 'readiness': self.readiness()}
        folder = self.backup()
        try:
            receipt = json.loads((folder / 'receipt.json').read_text())

            def install(name):
                target = self.app / name
                saved = receipt['entries'][name]
                actual = current(target)
                if (digest(actual) if actual is not None else None) != (saved['hash'] if saved else None):
                    raise PublishError('TARGET_CHANGED_DURING_APPLY')
                previous = target.stat() if target.exists() else None
                atomic(target, (self.package / 'payload' / name).read_bytes(),
                       stat.S_IMODE(previous.st_mode) if previous else 0o644,
                       previous.st_uid if previous else self.uid, previous.st_gid if previous else self.gid)

            website = 'app/Services/Site/WebStorefront.php'
            # Keep checkout unchanged until its sender, command and schema are ready.
            for name in sorted(PATHS - {website}):
                install(name)
            self.command(['runuser', '-u', 'fasakha', '--', PHP, 'artisan', 'migrate',
                          '--force', '--path=' + MIGRATION])
            ready = self.readiness()
            if not self.log.parent.exists():
                self.log.parent.mkdir(mode=0o750)
                os.chown(self.log.parent, self.uid, self.gid)
            plain(self.log)
            fd = os.open(self.log, os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'ab') as handle:
                os.fchmod(handle.fileno(), 0o600)
                os.fchown(handle.fileno(), self.uid, self.gid)
            if current(self.cron) != cron:
                raise PublishError('CRON_CHANGED_DURING_APPLY')
            install(website)
            atomic(self.cron, CRON_DATA, 0o644, *self.cron_owner)
            return {'status': 'APPLIED', 'backup': str(folder), 'readiness': ready}
        except BaseException:
            try:
                self.rollback(folder)
            except Exception:
                raise PublishError('APPLY_FAILED_ROLLBACK_BLOCKED_BACKUP_' + str(folder)) from None
            raise PublishError('APPLY_FAILED_CODE_RESTORED_BACKUP_' + str(folder)) from None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['check', 'apply', 'rollback'], nargs='?', default='check')
    parser.add_argument('backup', nargs='?')
    args = parser.parse_args()
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise PublishError('WRONG_HOST_OR_USER')
    publisher = Publisher(Path(__file__).resolve().parent)
    if args.mode == 'check':
        states, _ = publisher.preflight()
        result = {'status': 'PREFLIGHT_OK', 'files': len(states), 'sends_events': False}
    elif args.mode == 'rollback':
        if not args.backup:
            raise PublishError('BACKUP_REQUIRED')
        result = publisher.rollback(args.backup)
    else:
        result = publisher.apply()
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(str(error) if isinstance(error, PublishError) else 'PUBLICATION_FAILED')
        raise SystemExit(1)
