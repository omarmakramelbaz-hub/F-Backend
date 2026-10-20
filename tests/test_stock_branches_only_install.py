#!/usr/bin/env python3
"""Private Git/file fixtures; never load an application, database, or printer."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import time
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('stock_branches_only_installer', ROOT / 'deployment/apply_stock_branches_only.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class Fixture:
    def __init__(self, temporary):
        self.root = Path(temporary) / 'app'
        self.root.mkdir()
        self.before = {name: ('original ' + name + '\n').encode() for name in installer.SOURCE_PATHS}
        self.after = {name: ('reviewed ' + name + '\n').encode() for name in installer.SOURCE_PATHS}
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        for name, body in self.before.items():
            self.write(name, body)
        self.write('unrelated.txt', b'Current independent dashboard work\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'before')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for name, body in self.after.items():
            self.write(name, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'reviewed release')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        for name in self.before:
            (self.root / name).chmod(0o640)
        self.hashes = {name: installer.digest(body) for name, body in self.before.items()}
        self.lint_calls = 0

    def git(self, *arguments):
        return subprocess.check_output(['git', '-C', str(self.root), *arguments], stderr=subprocess.DEVNULL).decode()

    def write(self, name, body):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)

    def lint(self, root, stage, php, cache):
        self.lint_calls += 1
        for name, body in self.after.items():
            assert (stage / name).read_bytes() == body

    def apply(self, check=False, lint=None):
        with mock.patch.object(installer, 'BASE_REVISION', self.base), \
             mock.patch.object(installer, 'BEFORE_SHA256', self.hashes), \
             mock.patch.object(installer, 'lint_sources', lint or self.lint), \
             contextlib.redirect_stdout(io.StringIO()):
            return installer.install(self.root, self.release, 'fixture-php', check)

    def originals(self, case):
        for name, body in self.before.items():
            case.assertEqual((self.root / name).read_bytes(), body)


class StockBranchesOnlyInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = Fixture(self.temporary.name)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def test_preflight_lints_without_changing_application(self):
        self.assertIsNone(self.fixture.apply(check=True))
        self.fixture.originals(self)
        self.assertEqual(self.fixture.lint_calls, 1)
        self.assertFalse(list((self.fixture.root.parent / 'stock-branches-only-backups').glob('*/receipt.json')))

    def test_installs_only_one_file_preserves_modes_and_private_backup(self):
        backup = self.fixture.apply()
        for name, body in self.fixture.after.items():
            self.assertEqual((self.fixture.root / name).read_bytes(), body)
            self.assertEqual((self.fixture.root / name).stat().st_mode & 0o777, 0o640)
            self.assertEqual((backup / 'original' / name).read_bytes(), self.fixture.before[name])
            self.assertEqual((backup / 'original' / name).stat().st_mode & 0o777, 0o600)
        self.assertEqual((self.fixture.root / 'unrelated.txt').read_bytes(), b'Current independent dashboard work\n')
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['status'], 'installed')
        self.assertIs(receipt['mail_command_run'], False)
        self.assertEqual(set(receipt['files']), set(installer.SOURCE_PATHS))

    def test_repeat_is_idempotent(self):
        self.fixture.apply()
        self.assertIsNone(self.fixture.apply())
        self.assertEqual(len(list((self.fixture.root.parent / 'stock-branches-only-backups').glob('*/receipt.json'))), 1)

    def test_unknown_live_content_refuses_all_changes(self):
        name = installer.SOURCE_PATHS[0]
        changed = b'Independent live POS correction\n'
        self.fixture.write(name, changed)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_SOURCE_CHANGED'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / name).read_bytes(), changed)
        self.assertEqual((self.fixture.root / 'unrelated.txt').read_bytes(), b'Current independent dashboard work\n')

    def test_symlink_rejected(self):
        target = self.fixture.root / installer.SOURCE_PATHS[0]
        saved = target.with_suffix('.saved')
        target.rename(saved)
        target.symlink_to(saved)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply()
        self.assertEqual(saved.read_bytes(), self.fixture.before[installer.SOURCE_PATHS[0]])

    def test_hardlink_rejected(self):
        target = self.fixture.root / installer.SOURCE_PATHS[0]
        os.link(target, target.with_suffix('.hardlink'))
        with self.assertRaisesRegex(RuntimeError, 'REGULAR_FILE_REQUIRED'):
            self.fixture.apply()
        self.fixture.originals(self)

    def test_lint_failure_leaves_source_untouched(self):
        def fail(*args):
            raise RuntimeError('STOCK_BRANCHES_ONLY_CANDIDATE_PARSE_FAILED')
        with self.assertRaisesRegex(RuntimeError, 'PARSE_FAILED'):
            self.fixture.apply(lint=fail)
        self.fixture.originals(self)

    def test_source_change_after_preflight_is_not_overwritten(self):
        name = installer.SOURCE_PATHS[0]
        def changing_lint(*args):
            self.fixture.write(name, b'Concurrent live change\n')
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED_DURING_PREFLIGHT'):
            self.fixture.apply(lint=changing_lint)
        self.assertEqual((self.fixture.root / name).read_bytes(), b'Concurrent live change\n')
        for other in installer.SOURCE_PATHS[1:]:
            self.assertEqual((self.fixture.root / other).read_bytes(), self.fixture.before[other])

    def receipt(self):
        return json.loads(next((self.fixture.root.parent / 'stock-branches-only-backups').glob('*/receipt.json')).read_text())

    def test_failure_before_rename_leaves_original_and_records_clean_rollback(self):
        def fail_before_replace(path, body, info):
            raise OSError('simulated write failure')
        with mock.patch.object(installer, 'replace', fail_before_replace):
            with self.assertRaises(OSError):
                self.fixture.apply()
        self.fixture.originals(self)
        self.assertEqual(self.receipt()['status'], 'rolled_back')
        self.assertEqual(self.receipt()['rollback_conflicts'], [])

    def test_failure_after_atomic_rename_rolls_back_single_published_file(self):
        original_replace = installer.replace
        failed = False
        def fail_after_rename(path, body, info):
            nonlocal failed
            original_replace(path, body, info)
            if not failed:
                failed = True
                raise OSError('simulated directory sync failure after rename')
        with mock.patch.object(installer, 'replace', fail_after_rename):
            with self.assertRaises(OSError):
                self.fixture.apply()
        self.fixture.originals(self)
        self.assertEqual(self.receipt()['status'], 'rolled_back')
        self.assertEqual(self.receipt()['rollback_conflicts'], [])

    def test_rollback_preserves_concurrent_edit_after_publication(self):
        original_replace = installer.replace
        name = installer.SOURCE_PATHS[0]
        current = b'Concurrent owner change after publication\n'
        def concurrent_edit(path, body, info):
            original_replace(path, body, info)
            self.fixture.write(name, current)
        with mock.patch.object(installer, 'replace', concurrent_edit):
            with self.assertRaisesRegex(RuntimeError, 'PUBLISH_VERIFICATION_FAILED'):
                self.fixture.apply()
        self.assertEqual((self.fixture.root / name).read_bytes(), current)
        self.assertEqual(self.receipt()['status'], 'rollback_needs_review')
        self.assertEqual(self.receipt()['rollback_conflicts'], [name])

    def test_private_backup_symlink_is_rejected(self):
        backup = self.fixture.root.parent / 'stock-branches-only-backups'
        other = self.fixture.root.parent / 'elsewhere'
        other.mkdir()
        backup.symlink_to(other)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply()
        self.fixture.originals(self)

    def test_release_requires_full_revision(self):
        with self.assertRaisesRegex(RuntimeError, 'FULL_REVISION_REQUIRED'):
            installer.install(self.fixture.root, 'main', 'fixture-php')
        self.fixture.originals(self)

    def test_root_wrapper_does_not_invoke_application_or_mail_commands(self):
        source = (ROOT / 'deployment/apply_stock_branches_only_root.sh').read_text()
        self.assertNotIn(' artisan ', source)
        self.assertNotIn('systemctl ', source)
        self.assertNotIn('curl ', source)
        self.assertIn('deployment/apply_stock_branches_only.py', source)
        self.assertIn('flock --nonblock "$stock_branches_lock_fd"', source)
        self.assertIn('--php "$stock_branches_php" --check', source)

    def test_source_parse_uses_only_php_syntax_without_vendor_or_bootstrap(self):
        commands = []
        def parse(command):
            commands.append(command)
            return subprocess.CompletedProcess(command, 0, stdout=b'', stderr=b'')
        with mock.patch.object(installer, 'run', parse):
            installer.lint_sources(self.fixture.root, self.fixture.root, '/fixture/php', Path('/unused/cache'))
        self.assertEqual(commands, [['/fixture/php', '-l', str(self.fixture.root / installer.SOURCE_PATHS[0])]])
        self.assertFalse((self.fixture.root / 'vendor').exists())

    def test_pinned_before_hash_matches_parent_commit(self):
        name = installer.SOURCE_PATHS[0]
        source = subprocess.check_output(['git', '-C', str(ROOT), 'show', installer.BASE_REVISION + ':' + name])
        self.assertEqual(installer.digest(source), installer.BEFORE_SHA256[name])



class StockBranchesRootWrapperConcurrencyTests(unittest.TestCase):
    def test_second_wrapper_is_blocked_during_publication(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            mock_bin = folder / 'bin'
            mock_bin.mkdir()
            state = folder / 'state'
            state.mkdir()
            fake_php = folder / 'php'
            fake_php.write_text('#!/bin/sh\nexit 0\n')
            fake_php.chmod(0o755)
            proxy = mock_bin / 'proxy'
            proxy.write_text(r"""#!/usr/bin/python3
import os
from pathlib import Path
import sys
import time
name = Path(sys.argv[0]).name
args = sys.argv[1:]
folder = Path(os.environ['TEST_STATE_DIRECTORY'])
tag = os.environ['TEST_RUN_TAG']
def log(event):
    with (folder / 'calls').open('a') as stream:
        stream.write(tag + ' ' + event + '\n')
if name == 'id':
    print('0')
elif name == 'hostname':
    print('srv1836524')
elif name == 'runuser':
    os.execvp(args[3], args[3:])
elif name == 'git':
    if 'show' in args:
        print('# fixture installer')
    elif 'rev-parse' in args or 'hash-object' in args:
        print('a' * 40)
elif name == 'python3':
    if args[0] == '-':
        os.execv(sys.executable, [sys.executable, *args])
    log('preflight' if '--check' in args else 'install')
    if '--check' not in args:
        (folder / 'publication-started').touch()
        deadline = time.monotonic() + 12
        while not (folder / 'allow-publication').exists():
            if time.monotonic() >= deadline:
                raise SystemExit(1)
            time.sleep(0.02)
        log('publication-end')
else:
    raise SystemExit('unexpected mock command: ' + name)
""")
            proxy.chmod(0o755)
            for command in ['id', 'hostname', 'runuser', 'git', 'python3']:
                (mock_bin / command).symlink_to(proxy)
            wrapper = folder / 'apply.sh'
            text = (ROOT / 'deployment/apply_stock_branches_only_root.sh').read_text()
            text = text.replace('/opt/cpanel/ea-php82/root/usr/bin/php', str(fake_php))
            text = text.replace('/home/fasakha/public_html', str(folder / 'application'))
            text = text.replace('/run/fasakhansta-stock-branches-only.lock', str(folder / 'wrapper.lock'))
            wrapper.write_text(text)
            environment = dict(os.environ, PATH=str(mock_bin) + ':' + os.environ['PATH'],
                               TEST_STATE_DIRECTORY=str(state), TEST_RUN_TAG='first')
            first = subprocess.Popen(['bash', str(wrapper), 'b' * 40], env=environment,
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                deadline = time.monotonic() + 10
                while not (state / 'publication-started').exists():
                    if first.poll() is not None:
                        out, err = first.communicate()
                        self.fail('first wrapper exited before publication: ' + out + err)
                    if time.monotonic() >= deadline:
                        self.fail('first wrapper never reached publication')
                    time.sleep(0.02)
                environment['TEST_RUN_TAG'] = 'second'
                second = subprocess.run(['bash', str(wrapper), 'b' * 40], env=environment,
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=5)
                self.assertEqual(second.returncode, 1, second.stdout + second.stderr)
                self.assertIn('STOCK_BRANCHES_ONLY_INSTALLER_ALREADY_RUNNING', second.stdout)
                self.assertNotIn('second ', (state / 'calls').read_text())
                (state / 'allow-publication').touch()
                out, err = first.communicate(timeout=5)
                self.assertEqual(first.returncode, 0, out + err)
                self.assertIn('first publication-end', (state / 'calls').read_text())
                # Once cleanup has completed, another wrapper can run normally.
                environment['TEST_RUN_TAG'] = 'third'
                third = subprocess.run(['bash', str(wrapper), 'b' * 40, '--check'], env=environment,
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=5)
                self.assertEqual(third.returncode, 0, third.stdout + third.stderr)
                self.assertIn('third preflight', (state / 'calls').read_text())
            finally:
                (state / 'allow-publication').touch()
                if first.poll() is None:
                    first.kill()
                first.communicate(timeout=5)


if __name__ == '__main__':
    unittest.main()
