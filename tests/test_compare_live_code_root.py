"""Refactor step 0: the live code compared with every branch and the history, without printing any file's content."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('compare_live_code', ROOT / 'deployment/compare_live_code_root.py')
compare = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(compare)

SECRET = b'APP_KEY=base64:never-printed-0123456789\n'


def git(folder, *arguments):
    environment = dict(os.environ, GIT_AUTHOR_NAME='t', GIT_AUTHOR_EMAIL='t@example.com', GIT_COMMITTER_NAME='t',
                       GIT_COMMITTER_EMAIL='t@example.com')
    subprocess.run(['git', '-C', str(folder)] + list(arguments), check=True, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                   stderr=subprocess.DEVNULL, env=environment)


def write(folder, files):
    for name, data in files.items():
        path = folder / name
        if data is None:
            if path.exists():
                path.unlink()
            continue
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)


def commit(folder, files, message, when):
    write(folder, files)
    git(folder, 'add', '-A')
    environment_date = '{} +0000'.format(when)
    subprocess.run(['git', '-C', str(folder), 'commit', '-q', '-m', message], check=True, stdin=subprocess.DEVNULL,
                   env=dict(os.environ, GIT_AUTHOR_NAME='t', GIT_AUTHOR_EMAIL='t@example.com', GIT_COMMITTER_NAME='t',
                            GIT_COMMITTER_EMAIL='t@example.com', GIT_AUTHOR_DATE=environment_date, GIT_COMMITTER_DATE=environment_date))


class CompareLiveCodeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix='go-erp-live-compare-')
        base = Path(cls.temporary.name)
        cls.source = base / 'source'
        cls.source.mkdir()
        git(cls.source, 'init', '-q', '-b', 'main')
        commit(cls.source, {'app/A.php': b'<?php // a v1\n', 'app/B.php': b'<?php // b v1\n', 'routes/web.php': b'<?php // routes\n',
                            'public/js/x.js': b'x(1);\n', 'composer.json': b'{"require": {"php": "^8.0"}}\n',
                            'app/Old.php': b'<?php // old\n', 'tests/T.php': b'<?php // test\n', 'README.md': b'readme\n'},
               'first', 1760000000)
        commit(cls.source, {'app/A.php': b'<?php // a v2\n', 'app/Old.php': None}, 'second', 1760100000)
        git(cls.source, 'branch', 'other')
        git(cls.source, 'checkout', '-q', '-b', 'feature')
        commit(cls.source, {'app/B.php': b'<?php // b v2\n', 'app/New.php': b'<?php // new\n', 'public/js/x.js': b'x(2);\n',
                            'app/F1.php': b'<?php // f1\n', 'app/F2.php': b'<?php // f2\n', 'app/F3.php': b'<?php // f3\n'},
               'feature', 1760200000)
        git(cls.source, 'checkout', '-q', 'other')
        commit(cls.source, {'routes/web.php': b'<?php // routes on the other branch\n'}, 'other', 1760300000)
        git(cls.source, 'checkout', '-q', 'feature')
        # The live site: the feature branch, with every kind of difference.
        cls.site = base / 'public_html'
        cls.site.mkdir()
        listed = subprocess.run(['git', '-C', str(cls.source), 'ls-files'], check=True, stdout=subprocess.PIPE).stdout.decode().split()
        for name in listed:
            if name.startswith('tests/'):
                continue
            write(cls.site, {name: (cls.source / name).read_bytes()})
        write(cls.site, {
            'app/A.php': b'<?php // a v1\n',                                 # a version from the history
            'routes/web.php': b'<?php // routes on the other branch\n',      # another branch's version
            'public/js/x.js': b'x(2);\r\n',                                  # Windows line endings only
            'app/B.php': b'<?php // b edited on the server\n',               # never committed
            'app/New.php': None,                                             # missing on live
            'app/Old.php': b'<?php // old\n',                                # removed from the branch, still live
            'app/Secret.php': b'<?php return "' + SECRET + b'";\n',          # never in the repository
            '.env': SECRET,
            'public/uploads/photo.jpg': b'\xff\xd8 a photo',
            'faskhaNinja/lib/main.dart': b'void main() {}\n',
            'vendor/laravel/framework/x.php': b'<?php // vendor\n',
            'storage/logs/laravel.log': b'a log\n',
            'bootstrap/cache/config.php': b'<?php return [];\n',
        })
        os.symlink('../storage/app/public', str(cls.site / 'public/storage'))
        cls.work = base / 'work'
        cls.work.mkdir()
        cls.result = compare.compare(cls.site, 'file://' + str(cls.source), cls.work)
        cls.rows = {row['path']: row for row in cls.result['details']['differ']}
        cls.extra = {row['path']: row for row in cls.result['details']['extra']}

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def test_the_closest_branch_is_found(self):
        self.assertEqual(self.result['closest']['name'], 'feature')
        self.assertEqual(self.result['branches'], 3)
        self.assertIsNone(self.result['checkout'], 'The site is not a git checkout')
        feature = self.result['scores'][0]
        self.assertEqual((feature['differ'], feature['missing'], feature['extra']), (4, 1, 1))

    def test_each_difference_is_explained(self):
        self.assertEqual(self.rows['app/A.php']['kind'], 'a version from the history')
        self.assertIn('a version from 2025-10-09 (commit', self.rows['app/A.php']['live'])
        self.assertEqual(self.rows['app/A.php']['branch_version'], '2025-10-10')
        self.assertEqual(self.rows['routes/web.php']['kind'], "another branch's version")
        self.assertIn('the version on branch other', self.rows['routes/web.php']['live'])
        self.assertEqual(self.rows['public/js/x.js']['kind'], 'the same with Windows line endings')
        self.assertEqual(self.rows['app/B.php']['kind'], 'a version never committed')
        self.assertEqual(set(self.rows), {'app/A.php', 'routes/web.php', 'public/js/x.js', 'app/B.php'})
        self.assertEqual([row['path'] for row in self.result['details']['missing']], ['app/New.php'])
        self.assertEqual(self.extra['app/Old.php']['kind'], 'a version from the history')
        self.assertTrue(self.extra['app/Old.php']['live'].startswith('removed from this branch'))
        self.assertEqual(self.extra['app/Secret.php']['kind'], 'a file the repository never had')
        self.assertEqual(dict(self.result['details']['extra_groups']), {'public/uploads/': 1, 'public/storage': 1})

    def test_installed_and_runtime_folders_are_left_out(self):
        paths = set(self.rows) | set(self.extra) | set(row['path'] for row in self.result['details']['missing'])
        self.assertFalse([path for path in paths if path.startswith(('vendor/', 'storage/', 'bootstrap/cache/'))])
        outside = {row['folder']: row for row in self.result['details']['outside']}
        self.assertEqual(outside['tests/'], {'folder': 'tests/', 'branch': 1, 'live': 0, 'same': 0, 'live_only': 0})
        self.assertEqual(outside['faskhaNinja/']['live_only'], 1)
        roots = {row['path']: row['live'] for row in self.result['details']['root_files']}
        self.assertEqual(roots['README.md'], 'the same as this branch')
        self.assertEqual(roots['.env'], 'not on this branch; a file the repository never had')

    def test_the_report_never_shows_a_file_or_its_hash(self):
        found = {'system': 'test', 'deploy_target_exists': False, 'libraries': None}
        output = compare.report(self.result, found, compare.LIMIT)
        self.assertIn('GO_ERP_LIVE_CODE_COMPARE', output)
        self.assertIn('app/B.php  | live: a version never committed (changed on the server)', output)
        self.assertNotIn('never-printed', output)
        self.assertNotIn('APP_KEY', output)
        for data in (SECRET, b'<?php return "' + SECRET + b'";\n', b'<?php // b edited on the server\n'):
            self.assertNotIn(compare.blob_id(data), output)
            self.assertNotIn(compare.blob_id(data)[:10], output)

    def test_long_lists_are_cut_unless_asked(self):
        found = {'deploy_target_exists': False, 'libraries': None}
        self.assertIn('... 3 more (--all lists them)', compare.report(self.result, found, 1))
        self.assertNotIn('more (--all lists them)', compare.report(self.result, found, None))

    def test_a_site_checkout_is_named_but_never_its_remotes(self):
        site = Path(self.temporary.name) / 'checkout'
        subprocess.run(['git', 'clone', '-q', str(self.source), str(site)], check=True, stdin=subprocess.DEVNULL,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        found = compare.site_checkout(site)
        self.assertEqual(found['branch'], 'feature')
        self.assertEqual(len(found['head']), 40)
        self.assertEqual(set(found), {'head', 'branch'})

    def test_an_unreadable_repository_stops(self):
        with self.assertRaises(compare.Stop) as stopped:
            compare.fetch('file://' + str(Path(self.temporary.name) / 'missing'), Path(self.temporary.name) / 'nothing')
        self.assertEqual(str(stopped.exception), 'GO_ERP_LIVE_CODE_COMPARE_REPOSITORY_NOT_READABLE')

    def test_commands_never_read_the_heredoc_they_run_in(self):
        reading, writing = os.pipe()
        os.write(writing, b'echo the rest of the heredoc\n')
        os.close(writing)
        saved = os.dup(0)
        os.dup2(reading, 0)
        try:
            result = compare.run(['cat'])
        finally:
            os.dup2(saved, 0)
            os.close(saved)
            os.close(reading)
        self.assertEqual(result.stdout, b'')

    def test_libraries_are_read_from_vendor(self):
        site = Path(self.temporary.name) / 'libraries'
        write(site, {'composer.json': b'{"require": {"php": "^8.0", "ext-gd": "*", "laravel/framework": "^8.0"},'
                                      b' "require-dev": {"phpunit/phpunit": "^9.3"}}',
                     'vendor/composer/installed.json': b'{"packages": [{"name": "laravel/framework", "version": "v8.83.29"}], "dev": false}'})
        found = compare.libraries(site)
        self.assertEqual(found['php_wanted'], '^8.0')
        self.assertFalse(found['dev_installed'])
        self.assertEqual([(row['name'], row['installed'], row['dev']) for row in found['rows']],
                         [('laravel/framework', 'v8.83.29', False), ('phpunit/phpunit', None, True)])


if __name__ == '__main__':
    unittest.main()
