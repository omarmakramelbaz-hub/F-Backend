#!/usr/bin/env python3
"""Private Git/file fixtures; never load an application, database, or mail transport."""
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
SPEC = importlib.util.spec_from_file_location('stock_summary_installer', ROOT / 'deployment/apply_stock_summary_email.py')
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


class StockSummaryInstallerTests(unittest.TestCase):
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
        self.assertFalse(list((self.fixture.root.parent / 'stock-summary-email-backups').glob('*/receipt.json')))

    def test_installs_only_three_files_preserves_modes_and_private_backup(self):
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
        self.assertEqual(len(list((self.fixture.root.parent / 'stock-summary-email-backups').glob('*/receipt.json'))), 1)

    def test_partly_installed_known_files_can_finish(self):
        name = installer.SOURCE_PATHS[0]
        self.fixture.write(name, self.fixture.after[name])
        self.fixture.apply()
        for name, body in self.fixture.after.items():
            self.assertEqual((self.fixture.root / name).read_bytes(), body)

    def test_unknown_live_content_refuses_all_changes(self):
        name = installer.SOURCE_PATHS[1]
        changed = b'Independent live mail correction\n'
        self.fixture.write(name, changed)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_SOURCE_CHANGED'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / name).read_bytes(), changed)
        self.assertEqual((self.fixture.root / installer.SOURCE_PATHS[0]).read_bytes(), self.fixture.before[installer.SOURCE_PATHS[0]])

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
            raise RuntimeError('STOCK_SUMMARY_CANDIDATE_PARSE_FAILED')
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

    def test_publish_failure_rolls_back_already_published_files(self):
        original_replace = installer.replace
        failed = False
        def fail_second(path, body, info):
            nonlocal failed
            if str(path).endswith(installer.SOURCE_PATHS[1]) and not failed:
                failed = True
                raise OSError('simulated disk failure')
            return original_replace(path, body, info)
        with mock.patch.object(installer, 'replace', fail_second):
            with self.assertRaises(OSError):
                self.fixture.apply()
        self.fixture.originals(self)
        receipt = json.loads(next((self.fixture.root.parent / 'stock-summary-email-backups').glob('*/receipt.json')).read_text())
        self.assertEqual(receipt['status'], 'rolled_back')

    def test_rollback_preserves_concurrent_edit(self):
        original_replace = installer.replace
        def fail_second(path, body, info):
            if str(path).endswith(installer.SOURCE_PATHS[1]):
                self.fixture.write(installer.SOURCE_PATHS[0], b'Concurrent owner change after publication\n')
                raise OSError('simulated disk failure')
            return original_replace(path, body, info)
        with mock.patch.object(installer, 'replace', fail_second):
            with self.assertRaises(OSError):
                self.fixture.apply()
        self.assertEqual((self.fixture.root / installer.SOURCE_PATHS[0]).read_bytes(), b'Concurrent owner change after publication\n')
        receipt = json.loads(next((self.fixture.root.parent / 'stock-summary-email-backups').glob('*/receipt.json')).read_text())
        self.assertEqual(receipt['status'], 'rollback_needs_review')
        self.assertEqual(receipt['rollback_conflicts'], [installer.SOURCE_PATHS[0]])

    def test_private_backup_symlink_is_rejected(self):
        backup = self.fixture.root.parent / 'stock-summary-email-backups'
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

    def test_root_wrapper_does_not_invoke_artisan_or_mail(self):
        source = (ROOT / 'deployment/apply_stock_summary_email_root.sh').read_text()
        self.assertNotIn(' artisan ', source)
        self.assertIn('systemctl stop fasakhansta-stock-minimums.timer', source)
        self.assertIn('systemctl start fasakhansta-stock-minimums.timer', source)
        self.assertIn('STOCK_SUMMARY_ACTIVE_MAIL_WORKER_RETRY_LATER', source)
        self.assertLess(source.index('--php "$summary_php" --check'), source.index('systemctl stop fasakhansta-stock-minimums.timer'))


class RootWrapperConcurrencyTests(unittest.TestCase):
    def test_second_wrapper_is_blocked_until_first_timer_restart_finishes(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            mock_bin = folder / 'bin'
            mock_bin.mkdir()
            state = folder / 'state'
            state.mkdir()
            (state / 'timer-state').write_text('active')
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
elif name == 'systemctl':
    if args[0] == 'show':
        log('scheduler-read')
        if '--property=LoadState' in args:
            print('loaded')
        elif args[-1].endswith('.timer'):
            print((folder / 'timer-state').read_text())
        else:
            print('inactive')
    elif args[0] == 'stop':
        log('timer-stop')
        (folder / 'timer-state').write_text('inactive')
    elif args[0] == 'start':
        log('timer-restart-begin')
        (folder / 'restart-started').touch()
        deadline = time.monotonic() + 12
        while not (folder / 'allow-restart').exists():
            if time.monotonic() >= deadline:
                raise SystemExit(1)
            time.sleep(0.02)
        (folder / 'timer-state').write_text('active')
        log('timer-restart-end')
else:
    raise SystemExit('unexpected mock command: ' + name)
""")
            proxy.chmod(0o755)
            for command in ['id', 'hostname', 'runuser', 'git', 'python3', 'systemctl']:
                (mock_bin / command).symlink_to(proxy)
            wrapper = folder / 'apply.sh'
            text = (ROOT / 'deployment/apply_stock_summary_email_root.sh').read_text()
            text = text.replace('/opt/cpanel/ea-php82/root/usr/bin/php', str(fake_php))
            text = text.replace('/home/fasakha/public_html', str(folder / 'application'))
            text = text.replace('/run/fasakhansta-stock-summary-email.lock', str(folder / 'wrapper.lock'))
            wrapper.write_text(text)
            environment = dict(os.environ, PATH=str(mock_bin) + ':' + os.environ['PATH'],
                               TEST_STATE_DIRECTORY=str(state), TEST_RUN_TAG='first')
            first = subprocess.Popen(['bash', str(wrapper), 'b' * 40], env=environment,
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                deadline = time.monotonic() + 10
                while not (state / 'restart-started').exists():
                    if first.poll() is not None:
                        out, err = first.communicate()
                        self.fail('first wrapper exited before restart: ' + out + err)
                    if time.monotonic() >= deadline:
                        self.fail('first wrapper never reached timer restart')
                    time.sleep(0.02)
                environment['TEST_RUN_TAG'] = 'second'
                second = subprocess.run(['bash', str(wrapper), 'b' * 40], env=environment,
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=5)
                self.assertEqual(second.returncode, 1, second.stdout + second.stderr)
                self.assertIn('STOCK_SUMMARY_INSTALLER_ALREADY_RUNNING', second.stdout)
                self.assertNotIn('second ', (state / 'calls').read_text())
                self.assertEqual((state / 'timer-state').read_text(), 'inactive')
                (state / 'allow-restart').touch()
                out, err = first.communicate(timeout=5)
                self.assertEqual(first.returncode, 0, out + err)
                self.assertEqual((state / 'timer-state').read_text(), 'active')
                self.assertIn('first timer-restart-end', (state / 'calls').read_text())
                # Once cleanup has completed, another wrapper can run normally.
                environment['TEST_RUN_TAG'] = 'third'
                third = subprocess.run(['bash', str(wrapper), 'b' * 40, '--check'], env=environment,
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=5)
                self.assertEqual(third.returncode, 0, third.stdout + third.stderr)
                self.assertIn('third preflight', (state / 'calls').read_text())
            finally:
                (state / 'allow-restart').touch()
                if first.poll() is None:
                    first.kill()
                first.communicate(timeout=5)


if __name__ == '__main__':
    unittest.main()
