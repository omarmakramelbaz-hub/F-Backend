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
SPEC = importlib.util.spec_from_file_location('pos_shift_safety_installer', ROOT / 'deployment/apply_pos_shift_safety.py')
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


class PosShiftSafetyInstallerTests(unittest.TestCase):
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
        self.assertFalse(list((self.fixture.root.parent / 'pos-shift-safety-backups').glob('*/receipt.json')))

    def test_installs_only_five_files_preserves_modes_and_private_backup(self):
        backup = self.fixture.apply()
        for name, body in self.fixture.after.items():
            self.assertEqual((self.fixture.root / name).read_bytes(), body)
            self.assertEqual((self.fixture.root / name).stat().st_mode & 0o777, 0o640)
            self.assertEqual((backup / 'original' / name).read_bytes(), self.fixture.before[name])
            self.assertEqual((backup / 'original' / name).stat().st_mode & 0o777, 0o600)
        self.assertEqual((self.fixture.root / 'unrelated.txt').read_bytes(), b'Current independent dashboard work\n')
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['status'], 'installed')
        self.assertIs(receipt['print_command_run'], False)
        self.assertEqual(set(receipt['files']), set(installer.SOURCE_PATHS))

    def test_repeat_is_idempotent(self):
        self.fixture.apply()
        self.assertIsNone(self.fixture.apply())
        self.assertEqual(len(list((self.fixture.root.parent / 'pos-shift-safety-backups').glob('*/receipt.json'))), 1)

    def test_partly_installed_known_files_can_finish(self):
        name = installer.SOURCE_PATHS[0]
        self.fixture.write(name, self.fixture.after[name])
        self.fixture.apply()
        for name, body in self.fixture.after.items():
            self.assertEqual((self.fixture.root / name).read_bytes(), body)

    def test_unknown_live_content_refuses_all_changes(self):
        name = installer.SOURCE_PATHS[1]
        changed = b'Independent live POS correction\n'
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
            raise RuntimeError('POS_SHIFT_SAFETY_CANDIDATE_PARSE_FAILED')
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
        def fail_fifth(path, body, info):
            nonlocal failed
            if str(path).endswith(installer.SOURCE_PATHS[4]) and not failed:
                failed = True
                raise OSError('simulated disk failure')
            return original_replace(path, body, info)
        with mock.patch.object(installer, 'replace', fail_fifth):
            with self.assertRaises(OSError):
                self.fixture.apply()
        self.fixture.originals(self)
        receipt = json.loads(next((self.fixture.root.parent / 'pos-shift-safety-backups').glob('*/receipt.json')).read_text())
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
        receipt = json.loads(next((self.fixture.root.parent / 'pos-shift-safety-backups').glob('*/receipt.json')).read_text())
        self.assertEqual(receipt['status'], 'rollback_needs_review')
        self.assertEqual(receipt['rollback_conflicts'], [installer.SOURCE_PATHS[0]])

    def test_private_backup_symlink_is_rejected(self):
        backup = self.fixture.root.parent / 'pos-shift-safety-backups'
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

    def test_root_wrapper_does_not_invoke_print_or_application_commands(self):
        source = (ROOT / 'deployment/apply_pos_shift_safety_root.sh').read_text()
        self.assertNotIn(' artisan ', source)
        self.assertNotIn('systemctl ', source)
        self.assertNotIn('curl ', source)
        self.assertIn('deployment/apply_pos_shift_safety.py', source)
        self.assertIn('flock --nonblock "$pos_shift_lock_fd"', source)
        self.assertIn('--php "$pos_shift_php" --check', source)

    def parse_dependencies(self):
        self.fixture.write('vendor/autoload.php', b'<?php // test-only dependency marker\n')
        return self.fixture.root.parent / 'blade-cache'

    def test_source_parse_checks_do_not_bootstrap_application(self):
        commands = []
        def parse(command):
            commands.append(command)
            return subprocess.CompletedProcess(command, 0, stdout=b'', stderr=b'')
        cache = self.parse_dependencies()
        with mock.patch.object(installer, 'run', parse), mock.patch.object(installer.shutil, 'which', return_value='/fixture/node'):
            installer.lint_sources(self.fixture.root, self.fixture.root, '/fixture/php', cache)
        self.assertEqual(len(commands), 5)
        for index in [0, 2]:
            self.assertEqual(commands[index], ['/fixture/php', '-l', str(self.fixture.root / installer.SOURCE_PATHS[index])])
        for index in [1, 3]:
            self.assertEqual(commands[index], ['/fixture/node', '--check', str(self.fixture.root / installer.SOURCE_PATHS[index])])
        blade = commands[4]
        self.assertEqual(blade[:2], ['/fixture/php', '-r'])
        self.assertIn('BladeCompiler', blade[2])
        self.assertIn('token_get_all($compiler->compileString($source), TOKEN_PARSE)', blade[2])
        self.assertNotIn('bootstrap/app.php', blade[2])
        self.assertNotIn('Console\\Kernel', blade[2])
        self.assertEqual(blade[3:], [str(self.fixture.root / 'vendor/autoload.php'),
                                      str(self.fixture.root / installer.SOURCE_PATHS[4]), str(cache)])

    def test_optional_node_absence_is_reported(self):
        commands = []
        def parse(command):
            commands.append(command)
            return subprocess.CompletedProcess(command, 0, stdout=b'', stderr=b'')
        output = io.StringIO()
        cache = self.parse_dependencies()
        with mock.patch.object(installer, 'run', parse), mock.patch.object(installer.shutil, 'which', return_value=None), contextlib.redirect_stdout(output):
            installer.lint_sources(self.fixture.root, self.fixture.root, '/fixture/php', cache)
        self.assertEqual(len(commands), 3)
        self.assertEqual(output.getvalue().count('JS_PARSE_CHECK_UNAVAILABLE node_missing=1'), 2)

    def test_unknown_shift_service_is_preserved_without_publishing_other_files(self):
        name = 'app/Services/Dashboard/BranchShiftClosing.php'
        current = b'<?php // independently corrected continuous drawer implementation\n'
        self.fixture.write(name, current)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_SOURCE_CHANGED'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / name).read_bytes(), current)
        for other in installer.SOURCE_PATHS:
            if other != name:
                self.assertEqual((self.fixture.root / other).read_bytes(), self.fixture.before[other])

    def test_real_node_parser_rejects_invalid_candidate_without_publishing(self):
        node = installer.shutil.which('node')
        if not node:
            self.skipTest('Node not installed')
        self.parse_dependencies()
        for name in installer.SOURCE_PATHS:
            body = b'<?php $valid = true;\n' if name.endswith('.php') else b'const valid = true;\n'
            if name.endswith('.blade.php'):
                body = b'@if (true)<p>{{ $message }}</p>@endif\n'
            if name == 'public/dashboard/js/branch-shifts.js':
                body = b'function broken( {\n'
            self.fixture.after[name] = body
            self.fixture.write(name, body)
        self.fixture.git('add', *installer.SOURCE_PATHS)
        self.fixture.git('commit', '-qm', 'candidate with invalid JavaScript')
        self.fixture.release = self.fixture.git('rev-parse', 'HEAD').strip()
        for name, body in self.fixture.before.items():
            self.fixture.write(name, body)
        real_run = installer.run
        real_lint = installer.lint_sources
        seen_node = []
        def parse_without_application(command):
            if command[0] == 'fixture-php':
                return subprocess.CompletedProcess(command, 0, stdout=b'', stderr=b'')
            if command[0] == node:
                seen_node.append(command[-1])
            return real_run(command)
        with mock.patch.object(installer, 'run', parse_without_application):
            with self.assertRaisesRegex(RuntimeError, 'CANDIDATE_PARSE_FAILED: public/dashboard/js/branch-shifts.js'):
                self.fixture.apply(lint=real_lint)
        self.assertEqual(len(seen_node), 2)
        self.fixture.originals(self)
        self.assertFalse(list((self.fixture.root.parent / 'pos-shift-safety-backups').glob('*/receipt.json')))


class PosShiftRootWrapperConcurrencyTests(unittest.TestCase):
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
            text = (ROOT / 'deployment/apply_pos_shift_safety_root.sh').read_text()
            text = text.replace('/opt/cpanel/ea-php82/root/usr/bin/php', str(fake_php))
            text = text.replace('/home/fasakha/public_html', str(folder / 'application'))
            text = text.replace('/run/fasakhansta-pos-shift-safety.lock', str(folder / 'wrapper.lock'))
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
                self.assertIn('POS_SHIFT_SAFETY_INSTALLER_ALREADY_RUNNING', second.stdout)
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
