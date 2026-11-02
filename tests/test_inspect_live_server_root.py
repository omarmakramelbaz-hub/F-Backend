"""Refactor step 0, second look: the live code against one commit, and facts read without printing any secret."""
import http.server
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('inspect_live_server', ROOT / 'deployment/inspect_live_server_root.py')
inspect = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(inspect)

KEY = 'base64:never-printed-app-key-0123456789abcdef='
PASSWORD = 'never-printed-database-password'


def git(folder, *arguments):
    environment = dict(os.environ, GIT_AUTHOR_NAME='t', GIT_AUTHOR_EMAIL='t@example.com', GIT_COMMITTER_NAME='t',
                       GIT_COMMITTER_EMAIL='t@example.com')
    return subprocess.run(['git', '-C', str(folder)] + list(arguments), check=True, stdin=subprocess.DEVNULL,
                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=environment).stdout.decode().strip()


def write(folder, files):
    for name, data in files.items():
        path = folder / name
        if data is None:
            path.unlink()
            continue
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)


class InspectLiveServerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix='go-erp-live-inspect-')
        base = Path(cls.temporary.name)
        cls.source = base / 'source'
        cls.source.mkdir()
        git(cls.source, 'init', '-q', '-b', 'main')
        files = {
            'app/Same.php': b'<?php // the same\n',
            'resources/views/layouts/header.blade.php': b'<head>\n<link a>\n<link b>\n</head>\n',
            'routes/web.php': b'<?php // routes\n',
            'config/services.php': b"<?php return ['key' => env('X')];\n",
            'public/logo.png': b'\x89PNG\r\n\x1a\n\0one',
            'public/js/app.js': b'run();\n',
            'app/Gone.php': b'<?php // missing on live\n',
        }
        write(cls.source, files)
        git(cls.source, 'add', '-A')
        git(cls.source, 'commit', '-q', '-m', 'baseline')
        cls.commit = git(cls.source, 'rev-parse', 'HEAD')
        cls.site = base / 'public_html'
        write(cls.site, files)
        write(cls.site, {
            'resources/views/layouts/header.blade.php': b'<head>\n<link a>\n<link hologram>\n<link b>\n</head>\n',
            'routes/web.php': b'<?php // routes\r\n',
            'config/services.php': b"<?php return ['key' => env('X'), 'client_secret' => 'abc'];\n",
            'public/logo.png': b'\x89PNG\r\n\x1a\n\0two',
            'app/Gone.php': None,
            'app/OnlyLive.php': b'<?php // only live\n',
            'vendor/x.php': b'<?php // vendor\n',
        })
        cls.result = inspect.against_commit(cls.site, 'file://' + str(cls.source), cls.commit, base / 'work')
        cls.rows = {row['path']: row for row in cls.result['differ']}

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def test_the_live_code_is_compared_with_the_commit(self):
        self.assertEqual(self.result['commit'], self.commit)
        self.assertEqual(self.result['same'], 2)
        self.assertEqual(self.result['missing'], ['app/Gone.php'])
        self.assertEqual(self.result['extra'], ['app/OnlyLive.php'])
        self.assertEqual(set(self.rows), {'resources/views/layouts/header.blade.php', 'routes/web.php', 'config/services.php', 'public/logo.png'})

    def test_each_difference_says_what_it_is(self):
        header = self.rows['resources/views/layouts/header.blade.php']
        self.assertEqual(header['state'], '1 changed lines')
        self.assertIn('+<link hologram>', header['diff'])
        self.assertEqual(len(header['sha256']), 64)
        self.assertEqual(self.rows['routes/web.php']['state'], 'the same but with Windows line endings')
        self.assertTrue(self.rows['public/logo.png']['state'].startswith('binary'))
        self.assertEqual(self.rows['public/logo.png']['diff'], [])

    def test_a_diff_that_looks_like_a_secret_is_never_printed(self):
        row = self.rows['config/services.php']
        self.assertTrue(row['state'].startswith('diff withheld'))
        self.assertEqual(row['diff'], [])
        output = inspect.report({'code': self.result})
        self.assertNotIn("'abc'", output)
        self.assertIn('+<link hologram>', output)

    def test_only_harmless_settings_are_read_from_the_cached_config(self):
        php = shutil.which('php')
        if not php:
            self.skipTest('php is not installed')
        site = Path(self.temporary.name) / 'laravel'
        write(site, {
            'bootstrap/cache/config.php': ("<?php return ['app' => ['env' => 'production', 'debug' => true, 'key' => '%s', "
                                           "'url' => 'https://fasakhaninja.com'], 'database' => ['default' => 'mysql', 'connections' => "
                                           "['mysql' => ['database' => 'fasakha_db', 'password' => '%s']]], 'cache' => ['default' => 'file']];"
                                           % (KEY, PASSWORD)).encode(),
            '.env': b'APP_KEY=' + KEY.encode(),
            'storage/logs/laravel.log': b'a log\n',
        })
        (site / 'storage/framework/sessions').mkdir(parents=True)
        found = inspect.laravel(site, php, None)
        self.assertEqual(found['app.debug'], 'true')
        self.assertEqual(found['app.env'], '"production"')
        self.assertEqual(found['cache.default'], '"file"')
        self.assertEqual(found['session.driver'], '"(not set)"')
        self.assertEqual(inspect.database_name(found), 'fasakha_db')
        output = inspect.report({'laravel': found})
        self.assertNotIn(KEY, output)
        self.assertNotIn(PASSWORD, output)
        self.assertIn('app.debug: true', output)

    def test_the_web_check_reports_status_and_redirects_without_reading_bodies(self):
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                if self.path == '/moved':
                    self.send_response(301)
                    self.send_header('Location', '/admin/login?token=abcdefghijklmnopqrstuvwxyz0123456789')
                    self.end_headers()
                elif self.path == '/.env':
                    self.send_response(404)
                    self.send_header('Content-Type', 'text/html; charset=UTF-8')
                    self.end_headers()
                else:
                    body = b'APP_KEY=' + KEY.encode()
                    self.send_response(200)
                    self.send_header('Content-Type', 'text/plain')
                    self.send_header('Content-Length', str(len(body)))
                    self.end_headers()
                    self.wfile.write(body)

            def log_message(self, *arguments):
                pass

        server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            origin = 'http://127.0.0.1:%d' % server.server_address[1]
            found = inspect.exposure({origin: ('/.env', '/moved', '/open')})
        finally:
            server.shutdown()
            server.server_close()
        statuses = dict(found[origin])
        self.assertEqual(statuses['/.env'], '404 text/html - bytes')
        self.assertTrue(statuses['/moved'].startswith('301 - - bytes -> /admin/login?token=<…>'))
        self.assertEqual(statuses['/open'], '200 text/plain {} bytes'.format(len('APP_KEY=' + KEY)))
        self.assertNotIn(KEY, inspect.report({'web': found}))

    def test_scheduler_lines_are_masked(self):
        crontab = Path(self.temporary.name) / 'crontab'
        crontab.write_text('# a comment\n* * * * * cd /home/fasakha/public_html && php artisan schedule:run\n'
                           '0 3 * * * curl https://example.com/hook?key=abcdefghijklmnopqrstuvwxyz0123 # fasakha\n')
        original = inspect.CRONTABS
        inspect.CRONTABS = (str(crontab),)
        try:
            found = inspect.schedule()
        finally:
            inspect.CRONTABS = original
        self.assertEqual(found['cron'], ['crontab: * * * * * cd /home/fasakha/public_html && php artisan schedule:run',
                                         'crontab: 0 3 * * * curl https://example.com/hook?key=<…> # fasakha'])

    def test_one_part_failing_never_hides_the_others(self):
        def broken():
            raise RuntimeError('broken')
        original = inspect.schedule
        inspect.schedule = broken
        try:
            parts = inspect.gather(self.site, 'file://' + str(self.source), self.commit, Path(self.temporary.name) / 'again',
                                   '/no/php', Path('/no/php-root'), None, {})
        finally:
            inspect.schedule = original
        self.assertEqual(parts['schedule'], 'unavailable (RuntimeError)')
        self.assertEqual(parts['code']['commit'], self.commit)
        self.assertIn('machine', parts)
        output = inspect.report(parts)
        self.assertIn('== live site code against commit', output)
        self.assertIn('scheduler, workers, units == unavailable (RuntimeError)', output)

    def test_an_unknown_commit_stops_that_part_only(self):
        with self.assertRaises(inspect.Stop) as stopped:
            inspect.against_commit(self.site, 'file://' + str(self.source), '0' * 40, Path(self.temporary.name) / 'unknown')
        self.assertEqual(str(stopped.exception), 'GO_ERP_LIVE_INSPECT_COMMIT_NOT_IN_REPOSITORY')


if __name__ == '__main__':
    unittest.main()
