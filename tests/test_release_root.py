"""A refactor release ships exactly the changed site files, only over the versions the server should run, with a way back."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('release', ROOT / 'deployment/release_root.py')
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)

BASE_FILES = {
    'app/Http/Controllers/HomeController.php': b'<?php // home v1\n',
    'app/Http/Requests/StoreTypoRequest.php': b'<?php // a dead duplicate\n',
    'resources/views/home.blade.php': b'<h1>{{ $title }}</h1>\n',
    'config/app.php': b"<?php return ['name' => 'v1'];\n",
    'routes/web.php': b'<?php // routes\n',
    'index.php': b'<?php // the front door\n',
    'tests/Feature/HomeTest.php': b'<?php // a test, never shipped\n',
    'database/migrations/2026_01_01_create_x.php': b'<?php // a migration\n',
}


def git(folder, *arguments):
    environment = dict(os.environ, GIT_AUTHOR_NAME='t', GIT_AUTHOR_EMAIL='t@example.com', GIT_COMMITTER_NAME='t',
                       GIT_COMMITTER_EMAIL='t@example.com')
    return subprocess.run(['git', '-C', str(folder)] + list(arguments), check=True, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                          stderr=subprocess.DEVNULL, env=environment).stdout.decode().strip()


def write(folder, files):
    for name, data in files.items():
        path = folder / name
        if data is None:
            if path.exists():
                path.unlink()
            continue
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-release-')
        base = Path(self.temporary.name)
        self.repo = base / 'repo'
        self.repo.mkdir()
        git(self.repo, 'init', '-q', '-b', 'main')
        write(self.repo, BASE_FILES)
        git(self.repo, 'add', '-A')
        git(self.repo, 'commit', '-q', '-m', 'the server runs this')
        self.base = git(self.repo, 'rev-parse', 'HEAD')
        self.site = base / 'public_html'
        write(self.site, {name: data for name, data in BASE_FILES.items() if not name.startswith(('tests/', 'database/'))})
        for name in BASE_FILES:
            if (self.site / name).exists():
                os.chmod(str(self.site / name), 0o640)
        self.backups = base / 'go-erp-releases'
        self.calls = []
        self.serving = True
        self.original = (release.PACKAGE, release.command, release.answers)
        release.PACKAGE = self.repo
        real = release.command

        def command(arguments, user=None, cwd=None, timeout=300):
            arguments = [str(item) for item in arguments]
            if arguments[0] == 'git':
                return real(arguments, user=None, cwd=cwd, timeout=timeout)
            if '-l' in arguments:
                return (255 if b'BROKEN(' in Path(arguments[-1]).read_bytes() else 0), ''
            self.calls.append(arguments[1:])
            return 0, ''
        release.command = command
        release.answers = lambda path, wanted, attempts=5: self.serving

    def tearDown(self):
        release.PACKAGE, release.command, release.answers = self.original
        self.temporary.cleanup()

    def commit(self, files):
        write(self.repo, files)
        git(self.repo, 'add', '-A')
        git(self.repo, 'commit', '-q', '-m', 'a refactor step')
        return git(self.repo, 'rev-parse', 'HEAD')

    def refactor(self):
        return self.commit({
            'app/Http/Controllers/HomeController.php': b'<?php // home v2, the same behaviour\n',
            'app/Http/Requests/StoreTypoRequest.php': None,
            'app/Services/Home/Greeting.php': b'<?php // extracted\n',
            'resources/views/home.blade.php': b'<h1>{{ $title }}</h1>\n<p>same</p>\n',
            'tests/Feature/HomeTest.php': b'<?php // a newer test, still never shipped\n',
        })

    def test_the_plan_lists_the_shipped_changes_only(self):
        self.refactor()
        result = release.plan(self.site, self.base)
        self.assertEqual(result['counts'], {'add': 1, 'delete': 1, 'modify': 2})
        self.assertTrue(result['ready'])
        self.assertNotIn('tests/Feature/HomeTest.php', result['files'])
        self.assertEqual(result['files']['app/Services/Home/Greeting.php'], 'add (base)')
        self.assertFalse(self.backups.exists())

    def test_a_release_is_installed_with_every_previous_file_kept(self):
        target = self.refactor()
        result = release.apply(self.site, self.base, self.backups, '/php')
        self.assertEqual(result['result'], 'GO_ERP_RELEASE_INSTALLED')
        self.assertEqual(result['counts'], {'add': 1, 'delete': 1, 'modify': 2})
        self.assertEqual((self.site / 'app/Http/Controllers/HomeController.php').read_bytes(), b'<?php // home v2, the same behaviour\n')
        self.assertEqual(stat.S_IMODE((self.site / 'app/Http/Controllers/HomeController.php').stat().st_mode), 0o640, 'Mode kept')
        self.assertFalse((self.site / 'app/Http/Requests/StoreTypoRequest.php').exists())
        self.assertEqual((self.site / 'app/Services/Home/Greeting.php').read_bytes(), b'<?php // extracted\n')
        self.assertEqual(stat.S_IMODE((self.site / 'app/Services/Home').stat().st_mode), 0o755)
        self.assertFalse((self.site / 'tests').exists(), 'Tests never reach the server')
        self.assertEqual(self.calls, [['artisan', 'view:clear']])
        saved = json.loads((Path(result['backup']) / 'manifest.json').read_text())
        self.assertEqual((saved['from'], saved['to']), (self.base, target))
        self.assertEqual(saved['created'], ['app/Services', 'app/Services/Home'])
        copy = saved['files']['app/Http/Requests/StoreTypoRequest.php']['copy']
        self.assertEqual((Path(result['backup']) / copy).read_bytes(), b'<?php // a dead duplicate\n')
        self.assertEqual(release.apply(self.site, self.base, self.backups, '/php')['result'], 'GO_ERP_RELEASE_ALREADY_INSTALLED')

    def test_a_file_changed_on_the_server_stops_everything(self):
        self.refactor()
        (self.site / 'resources/views/home.blade.php').write_bytes(b'<h1>edited on the server</h1>\n')
        with self.assertRaises(release.Stop) as stopped:
            release.apply(self.site, self.base, self.backups, '/php')
        self.assertEqual(str(stopped.exception), 'GO_ERP_RELEASE_LIVE_FILES_CHANGED_ON_SERVER: resources/views/home.blade.php')
        self.assertEqual((self.site / 'app/Http/Controllers/HomeController.php').read_bytes(), BASE_FILES['app/Http/Controllers/HomeController.php'])
        self.assertTrue((self.site / 'app/Http/Requests/StoreTypoRequest.php').exists())

    def test_windows_line_endings_on_the_server_are_the_same_file(self):
        self.refactor()
        (self.site / 'resources/views/home.blade.php').write_bytes(b'<h1>{{ $title }}</h1>\r\n')
        self.assertEqual(release.apply(self.site, self.base, self.backups, '/php')['result'], 'GO_ERP_RELEASE_INSTALLED')

    def test_what_needs_more_than_files_is_refused(self):
        self.commit({'database/migrations/2026_02_01_add_y.php': b'<?php // a new migration\n'})
        with self.assertRaises(release.Stop) as stopped:
            release.plan(self.site, self.base)
        self.assertEqual(str(stopped.exception), 'GO_ERP_RELEASE_NEEDS_MORE_THAN_FILES: database/migrations/2026_02_01_add_y.php')

    def test_php_that_does_not_parse_is_never_written(self):
        self.commit({'app/Http/Controllers/HomeController.php': b'<?php BROKEN( \n'})
        with self.assertRaises(release.Stop) as stopped:
            release.apply(self.site, self.base, self.backups, '/php')
        self.assertEqual(str(stopped.exception), 'GO_ERP_RELEASE_PHP_DOES_NOT_PARSE: app/Http/Controllers/HomeController.php')
        self.assertEqual((self.site / 'app/Http/Controllers/HomeController.php').read_bytes(), BASE_FILES['app/Http/Controllers/HomeController.php'])

    def test_a_site_that_stops_answering_gets_every_previous_file_back(self):
        self.refactor()
        self.serving = False
        with self.assertRaises(release.Stop) as stopped:
            release.apply(self.site, self.base, self.backups, '/php')
        self.assertEqual(str(stopped.exception), 'GO_ERP_RELEASE_SITE_NOT_ANSWERING_PREVIOUS_FILES_RESTORED')
        self.assertEqual((self.site / 'app/Http/Controllers/HomeController.php').read_bytes(), BASE_FILES['app/Http/Controllers/HomeController.php'])
        self.assertTrue((self.site / 'app/Http/Requests/StoreTypoRequest.php').exists())
        self.assertFalse((self.site / 'app/Services').exists(), 'Folders it created are gone again')
        self.assertEqual(self.calls, [['artisan', 'view:clear'], ['artisan', 'view:clear']])

    def test_configuration_changes_are_cached_again(self):
        (self.site / 'bootstrap/cache').mkdir(parents=True)
        (self.site / 'bootstrap/cache/config.php').write_text('<?php return [];')
        self.commit({'config/app.php': b"<?php return ['name' => 'v2'];\n"})
        result = release.apply(self.site, self.base, self.backups, '/php')
        self.assertEqual(result['rebuilt'], ['view:clear', 'config:cache'])

    def test_rollback_puts_the_previous_files_back(self):
        self.refactor()
        release.apply(self.site, self.base, self.backups, '/php')
        result = release.rollback(self.site, self.backups, '/php')
        self.assertEqual(result['result'], 'GO_ERP_RELEASE_ROLLED_BACK')
        for name, data in BASE_FILES.items():
            if not name.startswith(('tests/', 'database/')):
                self.assertEqual((self.site / name).read_bytes(), data, name)
        self.assertFalse((self.site / 'app/Services').exists())
        with self.assertRaises(release.Stop) as stopped:
            release.rollback(self.site, self.backups, '/php')
        self.assertEqual(str(stopped.exception), 'GO_ERP_RELEASE_NOTHING_TO_ROLL_BACK')

    def test_rollback_refuses_files_changed_after_the_release(self):
        self.refactor()
        release.apply(self.site, self.base, self.backups, '/php')
        (self.site / 'app/Services/Home/Greeting.php').write_bytes(b'<?php // edited after the release\n')
        with self.assertRaises(release.Stop) as stopped:
            release.rollback(self.site, self.backups, '/php')
        self.assertEqual(str(stopped.exception), 'GO_ERP_RELEASE_CHANGED_SINCE_THE_RELEASE: app/Services/Home/Greeting.php')


if __name__ == '__main__':
    unittest.main()
