"""The four approved server fixes: each made and checked on its own, undone alone when it fails, all undone by --rollback."""
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('server_tuning', ROOT / 'deployment/tune_server_root.py')
tuning = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(tuning)

ENV = b'APP_NAME=Fasakhansta\r\nAPP_ENV=production\r\nAPP_KEY=base64:never-printed\r\nAPP_DEBUG=true\r\nAPP_URL=https://fasakhaninja.com\r\n'
ROOT_CRONTAB = ('MAILTO=""\n0 3 * * * /usr/local/cpanel/scripts/upcp --cron\n'
                '* * * * * cd /home/fasakha/public_html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1\n'
                '30 2 * * * /usr/local/cpanel/bin/backup\n')
SITE_CRONTAB = '* * * * * cd /home/fasakha/public_html && php artisan schedule:run >> /dev/null 2>&1\n'


class FakeServer:
    """The database, crontabs, PHP, packages and PHP-FPM as the script sees them."""
    def __init__(self, site):
        self.site = site
        self.variables = {'slow_query_log': 'OFF', 'long_query_time': '0.300000', 'slow_query_log_file': 'srv1836524-slow.log'}
        self.crontabs = {'root': ROOT_CRONTAB, tuning.WEB_USER: SITE_CRONTAB}
        self.cached = {'debug': True, 'rest': 'fingerprint'}
        self.other_settings_change = False
        self.installed = False
        self.install_works = True
        self.loaded = False
        self.calls = []

    def env_debug(self):
        found = re.search(br'^APP_DEBUG=(\w+)', (self.site / '.env').read_bytes(), re.M)
        return found.group(1) == b'true'

    def command(self, arguments, user=None, cwd=None, timeout=900, data=None):
        arguments = [str(item) for item in arguments]
        self.calls.append(arguments)
        if arguments[0] == 'mysql' and arguments[-1].startswith('SHOW GLOBAL VARIABLES'):
            return 0, ''.join('{}\t{}\n'.format(key, value) for key, value in self.variables.items())
        if arguments[0] == 'mysql':
            for name, value in re.findall(r"SET GLOBAL (\w+)='?([^';]+)'?;", arguments[-1]):
                self.variables[name] = '{:.6f}'.format(float(value)) if name == 'long_query_time' else value
            return 0, ''
        if arguments[:2] == ['crontab', '-l']:
            user = arguments[-1]
            return (0, self.crontabs[user]) if user in self.crontabs else (1, '')
        if arguments[:2] == ['crontab', '-u']:
            self.crontabs[arguments[2]] = Path(arguments[3]).read_text()
            return 0, ''
        if arguments[1:3] == ['-r', tuning.READ_CONFIG]:
            return 0, json.dumps(self.cached)
        if arguments[1:] == ['artisan', 'config:cache']:
            self.cached = {'debug': self.env_debug(), 'rest': 'changed' if self.other_settings_change else 'fingerprint'}
            return 0, ''
        if arguments[:2] == ['rpm', '-q']:
            return (0 if self.installed else 1), ''
        if arguments[0] == 'dnf':
            if 'install' in arguments and self.install_works:
                self.installed = True
            if 'remove' in arguments:
                self.installed = False
            return (0 if self.install_works or 'remove' in arguments else 1), ''
        if arguments[0].endswith('php-fpm'):
            return 0, ''
        if arguments[0] == 'systemctl':
            self.loaded = self.installed and tuning.OPCACHE_INI.exists()
            return 0, ''
        raise AssertionError('Unexpected command: {}'.format(arguments))

    def page(self, path, timeout=20):
        if path.startswith('/_ignition/health-check'):
            return (200 if self.cached['debug'] else 404), 0.01
        return 200, (0.05 if self.loaded else 0.4)


class TuningTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-tuning-')
        base = Path(self.temporary.name)
        self.site = base / 'public_html'
        for name in ('bootstrap/cache', 'storage/logs', 'storage/framework/views', 'storage/app'):
            (self.site / name).mkdir(parents=True)
        (self.site / 'bootstrap/cache/config.php').write_text('<?php return [];')
        (self.site / '.env').write_bytes(ENV)
        os.chmod(str(self.site / '.env'), 0o640)
        (self.site / 'storage/logs/laravel.log').write_text('a log')
        (self.site / 'storage/app/upload.jpg').write_text('an upload')
        (base / 'php/etc/php.d').mkdir(parents=True)
        self.backups = base / 'go-erp-server-tuning'
        self.server = FakeServer(self.site)
        self.original = (tuning.command, tuning.page, tuning.fpm_opcache_loaded, tuning.sleep, tuning.OPCACHE_INI, tuning.PHP_ROOT,
                         tuning.CRONTABS, tuning.WEB_USER)
        tuning.WEB_USER = 'nobody'
        self.server.crontabs = {'root': ROOT_CRONTAB, 'nobody': SITE_CRONTAB}
        tuning.command, tuning.page, tuning.sleep = self.server.command, self.server.page, lambda seconds: None
        tuning.fpm_opcache_loaded = lambda: self.server.loaded
        tuning.OPCACHE_INI, tuning.PHP_ROOT, tuning.CRONTABS = base / 'php/etc/php.d/zz-go-erp-opcache.ini', base / 'php', base / 'cron'

    def tearDown(self):
        (tuning.command, tuning.page, tuning.fpm_opcache_loaded, tuning.sleep, tuning.OPCACHE_INI, tuning.PHP_ROOT, tuning.CRONTABS,
         tuning.WEB_USER) = self.original
        self.temporary.cleanup()

    def test_the_plan_only_reads(self):
        lines = tuning.plan(self.site)
        self.assertEqual(lines[0], 'GO_ERP_SERVER_TUNING_PLAN')
        self.assertIn('slowlog: slow_query_log OFF, long_query_time 0.300000, its own file', lines)
        self.assertIn('debug: APP_DEBUG cached: true', lines)
        self.assertIn('opcache: package not installed; loaded in the running PHP-FPM: no', lines)
        self.assertTrue(lines[2].startswith('schedule: root runs the scheduler: 1 line(s); the site user: 1 line(s)'))
        self.assertEqual((self.site / '.env').read_bytes(), ENV)
        self.assertFalse(self.backups.exists())
        self.assertFalse([call for call in self.server.calls if call[0] in ('dnf', 'systemctl') or call[:2] == ['crontab', '-u']])

    def test_the_four_changes_are_made_and_checked(self):
        lines, ok = tuning.apply(self.site, self.backups)
        self.assertTrue(ok, lines)
        self.assertEqual(lines[-1], 'GO_ERP_SERVER_TUNING_DONE (4 of 4)')
        self.assertEqual((self.server.variables['slow_query_log'], self.server.variables['slow_query_log_file']), ('ON', tuning.SLOW_LOG))
        self.assertNotIn('schedule:run', self.server.crontabs['root'], 'Root no longer runs the scheduler')
        self.assertIn('upcp --cron', self.server.crontabs['root'], 'Root keeps its other jobs')
        self.assertIn('backup', self.server.crontabs['root'])
        self.assertEqual(self.server.crontabs['nobody'], SITE_CRONTAB, 'The site user keeps its scheduler')
        self.assertEqual((self.site / '.env').read_bytes(), ENV.replace(b'APP_DEBUG=true\r\n', b'APP_DEBUG=false\r\n'),
                         'Only APP_DEBUG changes, Windows line endings kept')
        self.assertEqual(oct((self.site / '.env').stat().st_mode & 0o777), oct(0o640))
        self.assertFalse(self.server.cached['debug'])
        self.assertTrue(self.server.installed and self.server.loaded)
        self.assertEqual(tuning.OPCACHE_INI.read_bytes(), tuning.OPCACHE_SETTINGS)
        self.assertIn('opcache: done; on: ea-php82-php-opcache installed, PHP-FPM reloaded with it; sign-in page 400 ms -> 50 ms', lines)
        folder = Path(lines[-2].split(': ', 1)[1])
        self.assertEqual(sorted(json.loads((folder / 'manifest.json').read_text())['done']), sorted(tuning.STEPS))
        self.assertEqual((folder / 'env').read_bytes(), ENV)
        self.assertEqual(oct((folder / 'env').stat().st_mode & 0o777), oct(0o600))
        self.assertNotIn('never-printed', '\n'.join(lines))

    def test_rollback_puts_everything_back(self):
        tuning.apply(self.site, self.backups)
        lines, ok = tuning.rollback(self.site, self.backups)
        self.assertTrue(ok, lines)
        self.assertEqual(lines[-1], 'GO_ERP_SERVER_TUNING_ROLLED_BACK')
        self.assertEqual(self.server.variables, {'slow_query_log': 'OFF', 'long_query_time': '0.300000', 'slow_query_log_file': 'srv1836524-slow.log'})
        self.assertEqual(self.server.crontabs['root'], ROOT_CRONTAB)
        self.assertEqual((self.site / '.env').read_bytes(), ENV)
        self.assertTrue(self.server.cached['debug'])
        self.assertFalse(self.server.installed)
        self.assertFalse(tuning.OPCACHE_INI.exists())
        with self.assertRaises(tuning.Stop) as stopped:
            tuning.rollback(self.site, self.backups)
        self.assertEqual(str(stopped.exception), 'GO_ERP_SERVER_TUNING_ALREADY_ROLLED_BACK')

    def test_debug_is_put_back_when_any_other_setting_would_change(self):
        self.server.other_settings_change = True
        lines, ok = tuning.apply(self.site, self.backups)
        self.assertFalse(ok)
        self.assertIn('debug: not done (GO_ERP_SERVER_TUNING_OTHER_SETTINGS_WOULD_CHANGE); left as it was', lines)
        self.assertEqual((self.site / '.env').read_bytes(), ENV)
        self.assertEqual(lines[-1], 'GO_ERP_SERVER_TUNING_PARTIAL (3 of 4)')
        self.assertTrue(self.server.loaded, 'The other changes still happen')

    def test_opcache_that_cannot_be_installed_leaves_nothing_behind(self):
        self.server.install_works = False
        lines, ok = tuning.apply(self.site, self.backups)
        self.assertFalse(ok)
        self.assertIn('opcache: not done (GO_ERP_SERVER_TUNING_PACKAGE_NOT_INSTALLED); left as it was', lines)
        self.assertFalse(tuning.OPCACHE_INI.exists())
        self.assertFalse(self.server.installed)

    def test_root_keeps_the_scheduler_when_the_site_user_has_none(self):
        self.server.crontabs['nobody'] = ''
        lines, ok = tuning.apply(self.site, self.backups)
        self.assertFalse(ok)
        self.assertIn('schedule: not done (GO_ERP_SERVER_TUNING_SITE_SCHEDULER_MISSING); left as it was', lines)
        self.assertEqual(self.server.crontabs['root'], ROOT_CRONTAB)

    def test_files_root_left_where_the_site_writes_go_back_to_the_site_user(self):
        if os.geteuid() != 0:
            self.skipTest('changing owners needs root')
        log, upload = self.site / 'storage/logs/laravel.log', self.site / 'storage/app/upload.jpg'
        os.chown(str(log), 0, 0)
        os.chown(str(upload), 0, 0)
        tuning.apply(self.site, self.backups)
        nobody = pwd.getpwnam('nobody')
        self.assertEqual((log.stat().st_uid, log.stat().st_gid), (nobody.pw_uid, nobody.pw_gid))
        self.assertEqual(upload.stat().st_uid, 0, 'Uploads are never touched')
        tuning.rollback(self.site, self.backups)
        self.assertEqual(log.stat().st_uid, 0)

    def test_env_editing_changes_only_app_debug(self):
        changed, old = tuning.env_with_debug(b'A=1\nAPP_DEBUG="TRUE"\nB=2', b'false')
        self.assertEqual((changed, old), (b'A=1\nAPP_DEBUG=false\nB=2', b'true'))
        with self.assertRaises(tuning.Stop):
            tuning.env_with_debug(b'APP_DEBUG=true\nAPP_DEBUG=false\n', b'false')
        with self.assertRaises(tuning.Stop):
            tuning.env_with_debug(b'APP_ENV=production\n', b'false')


if __name__ == '__main__':
    unittest.main()
