"""Real-Git coverage for the historical checkout stat-cache recovery boundary."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('current_dashboard_index_recovery',
    ROOT / 'deployment/upgrade_go_erp_current_dashboard_root.py')
recovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recovery)


class Refused(Exception):
    pass


class HistoricalIndexRecoveryTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-index-test-')
        self.addCleanup(temporary.cleanup)
        self.work = Path(temporary.name)
        self.checkout = self.work / 'checkout'
        self.checkout.mkdir()
        self.env = {**os.environ, 'GIT_OPTIONAL_LOCKS': '0',
            'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
            'GIT_CONFIG_SYSTEM': '/dev/null'}
        self.events = []
        self.git_run('init', '--quiet', '--template=')
        self.git_run('config', 'core.filemode', 'true')
        self.git_run('remote', 'add', 'origin', 'https://github.com/omarmakramelbaz-hub/F-Backend.git')
        self.source = self.checkout / 'fixture.php'
        self.source.write_bytes(b'<?php return "reviewed source";\n')
        self.source.chmod(0o644)
        self.git_run('add', 'fixture.php')
        self.git_run('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
            'commit', '--quiet', '-m', 'immutable historical fixture')
        self.seed = self.git_run('rev-parse', 'HEAD').decode().strip()
        self.events = []
        self.race = None
        self.base = types.SimpleNamespace(WORK=self.work, OLD_SEED=self.seed, need=self.need)
        self.context = types.SimpleNamespace(base=types.SimpleNamespace(
            git=self.guarded_git, checkout=self.checked_checkout), utility=object())

    @staticmethod
    def need(value, reason):
        if not value:
            raise Refused(reason)

    def git_run(self, *args):
        result = subprocess.run(['git', '-c', 'core.fsmonitor=false', '-c',
            'core.hooksPath=/dev/null', '-C', str(self.checkout), *args],
            env=self.env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, check=False)
        self.need(result.returncode == 0, 'GIT_OPERATION_FAILED')
        return result.stdout

    def guarded_git(self, root, *args):
        self.assertEqual(Path(root), self.checkout)
        self.events.append(args)
        if args == ('update-index', '--refresh') and self.race:
            self.race()
        return self.git_run(*args)

    def checked_checkout(self, root, revision, utility):
        self.assertEqual(Path(root), self.checkout)
        self.assertEqual(revision, self.seed)
        self.assertIs(utility, self.context.utility)
        self.events.append(('checked-checkout',))
        self.need(self.git_run('rev-parse', 'HEAD').decode().strip() == revision
            and self.git_run('remote', 'get-url', 'origin').decode().strip()
                == 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
            and not self.git_run('status', '--porcelain', '--untracked-files=all'),
            'CHECKOUT_CHANGED')

    def refresh(self):
        recovery.refresh_historical_index(self.base, self.context)

    def stale_stat(self):
        before = self.source.stat()
        os.utime(self.source, ns=(before.st_atime_ns, before.st_mtime_ns + 2000000000))
        # This is the production failure: accurate status proves clean source,
        # while the original verifier's diff-index still sees stale index stats.
        self.assertEqual(self.git_run('status', '--porcelain', '--untracked-files=all'), b'')
        self.assertEqual(self.git_run('diff-index', '--name-only', self.seed, '--'), b'fixture.php\n')

    def test_stale_index_recovers_without_changing_source_or_staged_entries(self):
        self.stale_stat()
        entries = self.git_run('ls-files', '--stage', '-z')
        source = self.source.read_bytes()
        info = self.source.stat()
        head = self.git_run('rev-parse', 'HEAD')
        self.refresh()
        self.assertEqual(self.git_run('diff-index', '--name-only', self.seed, '--'), b'')
        self.assertEqual(self.git_run('ls-files', '--stage', '-z'), entries)
        self.assertEqual(self.git_run('rev-parse', 'HEAD'), head)
        self.assertEqual(self.source.read_bytes(), source)
        after = self.source.stat()
        self.assertEqual((after.st_ino, after.st_mode, after.st_size,
            after.st_mtime_ns, after.st_ctime_ns),
            (info.st_ino, info.st_mode, info.st_size, info.st_mtime_ns, info.st_ctime_ns))
        self.assertEqual(self.events, [('checked-checkout',), ('ls-files', '--stage', '-z'),
            ('update-index', '--refresh'), ('ls-files', '--stage', '-z'), ('checked-checkout',)])

    def assert_refuses_before_refresh(self):
        index = (self.checkout / '.git/index').read_bytes()
        with self.assertRaisesRegex(Refused, '^CHECKOUT_CHANGED$'):
            self.refresh()
        self.assertNotIn(('update-index', '--refresh'), self.events)
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)

    def test_changed_tracked_content_is_never_repaired_or_discarded(self):
        self.source.write_bytes(b'<?php return "unreviewed edit";\n')
        self.assert_refuses_before_refresh()
        self.assertIn(b'unreviewed edit', self.source.read_bytes())

    def test_staged_edit_is_never_repaired_or_discarded(self):
        self.source.write_bytes(b'<?php return "staged edit";\n')
        self.git_run('add', 'fixture.php')
        self.assert_refuses_before_refresh()
        self.assertIn(b'staged edit', self.git_run('show', ':fixture.php'))

    def test_untracked_content_is_never_deleted(self):
        extra = self.checkout / 'unreviewed.php'
        extra.write_bytes(b'keep untracked source')
        self.assert_refuses_before_refresh()
        self.assertEqual(extra.read_bytes(), b'keep untracked source')

    def test_executable_mode_edit_is_rejected(self):
        self.source.chmod(0o755)
        self.assert_refuses_before_refresh()
        self.assertEqual(self.source.stat().st_mode & 0o777, 0o755)

    def test_content_race_between_clean_check_and_refresh_fails_closed(self):
        self.stale_stat()
        self.race = lambda: self.source.write_bytes(b'changed during refresh\n')
        with self.assertRaisesRegex(Refused, '^GIT_OPERATION_FAILED$'):
            self.refresh()
        self.assertEqual(self.source.read_bytes(), b'changed during refresh\n')
        self.assertEqual(self.events.count(('checked-checkout',)), 1)
        self.assertNotEqual(self.git_run('diff-index', '--name-only', self.seed, '--'), b'')


if __name__ == '__main__':
    unittest.main()
