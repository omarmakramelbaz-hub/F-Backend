"""Filesystem and isolation safeguards; no production host, PHP or database required."""
import base64
from contextlib import ExitStack
import errno
import gzip
import importlib.util
import os
from pathlib import Path
import pwd
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

PROJECT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('website_safe_installer', PROJECT / 'deployment/apply_website_app_orders_20261025_root.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class SafeInstallTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='website-safe-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / 'app').mkdir()
        self.file = self.root / 'app/board.php'
        self.file.write_bytes(b'old')

    def target(self, stack, relative='app/board.php'):
        return installer.Target(self.root, relative, stack)

    def test_dirfd_existing_replacement_preserves_requested_permissions(self):
        with ExitStack() as stack:
            target = self.target(stack)
            target.atomic(b'new', os.geteuid(), os.getegid(), 0o640, b'old')
            self.assertTrue(target.committed)
            self.assertEqual(target.read(), b'new')
        self.assertEqual(stat.S_IMODE(self.file.stat().st_mode), 0o640)
        self.assertEqual(self.file.stat().st_nlink, 1)

    def test_new_file_commits_without_replacing_an_existing_entry(self):
        with ExitStack() as stack:
            target = self.target(stack, 'app/new.php')
            target.atomic(b'new', os.geteuid(), os.getegid(), 0o644, None)
            self.assertEqual(target.read(), b'new')
            self.assertEqual((self.root / 'app/new.php').stat().st_nlink, 1)
            with self.assertRaisesRegex(RuntimeError, 'Concurrent source change'):
                target.atomic(b'other', os.geteuid(), os.getegid(), 0o644, None)

    def test_parent_replacement_cannot_write_through_new_symlink(self):
        outside = self.root / 'outside'
        outside.mkdir()
        (outside / 'board.php').write_bytes(b'important')
        with ExitStack() as stack:
            target = self.target(stack)
            (self.root / 'app').rename(self.root / 'original-app')
            (self.root / 'app').symlink_to(outside, target_is_directory=True)
            with self.assertRaisesRegex(RuntimeError, 'directory changed'):
                target.atomic(b'new', os.geteuid(), os.getegid(), 0o644, b'old')
            self.assertFalse(target.committed)
        self.assertEqual((outside / 'board.php').read_bytes(), b'important')
        self.assertEqual((self.root / 'original-app/board.php').read_bytes(), b'old')

    def test_target_link_is_rejected_without_touching_outside(self):
        outside = self.root / 'important'
        outside.write_bytes(b'important')
        with ExitStack() as stack:
            target = self.target(stack)
            self.file.unlink()
            self.file.symlink_to(outside)
            with self.assertRaisesRegex(RuntimeError, 'Unsafe application file'):
                target.atomic(b'new', os.geteuid(), os.getegid(), 0o644, b'old')
        self.assertEqual(outside.read_bytes(), b'important')

    def test_hardlinked_source_is_rejected(self):
        os.link(self.file, self.root / 'second-link')
        with ExitStack() as stack:
            target = self.target(stack)
            with self.assertRaisesRegex(RuntimeError, 'Unsafe application file'):
                target.read()
        self.assertEqual(self.file.read_bytes(), b'old')

    def test_source_drift_denies_write_and_rollback_delete(self):
        with ExitStack() as stack:
            target = self.target(stack)
            self.file.write_bytes(b'concurrent')
            with self.assertRaisesRegex(RuntimeError, 'Concurrent source change'):
                target.atomic(b'new', os.geteuid(), os.getegid(), 0o644, b'old')
            with self.assertRaisesRegex(RuntimeError, 'Concurrent source change'):
                target.remove(b'new')
        self.assertEqual(self.file.read_bytes(), b'concurrent')

    def test_committed_flag_survives_post_commit_failure_for_rollback(self):
        with ExitStack() as stack:
            target = self.target(stack)
            original = target.check
            def check():
                if target.committed:
                    raise RuntimeError('post-commit failure')
                original()
            with mock.patch.object(target, 'check', check):
                with self.assertRaisesRegex(RuntimeError, 'post-commit failure'):
                    target.atomic(b'new', os.geteuid(), os.getegid(), 0o644, b'old')
            self.assertTrue(target.committed)
            self.assertEqual(self.file.read_bytes(), b'new')
            target.atomic(b'old', os.geteuid(), os.getegid(), 0o644, b'new')
        self.assertEqual(self.file.read_bytes(), b'old')

    def test_private_copy_rejects_links_and_does_not_follow_target(self):
        source = self.root / 'source'
        source.mkdir()
        (source / 'link.php').symlink_to(self.file)
        with self.assertRaisesRegex(RuntimeError, 'linked test source'):
            installer.private_tree(source, self.root / 'stage', os.getegid())
        self.assertEqual(self.file.read_bytes(), b'old')

    def test_source_ancestor_swap_cannot_copy_outside_private_bytes(self):
        source = self.root / 'source'
        source.mkdir()
        (source / 'file.php').write_bytes(b'public source')
        outside = self.root / 'outside-private'
        outside.mkdir(mode=0o700)
        secret = b'root-only-secret-never-stage'
        (outside / 'file.php').write_bytes(secret)
        (outside / 'file.php').chmod(0o600)
        stage = self.root / 'stage'
        source_inode = source.stat().st_ino
        original_open = installer.os.open
        swapped = False
        def opened(path, flags, *args, **kwargs):
            nonlocal swapped
            from_source_fd = (str(path) == 'file.php' and kwargs.get('dir_fd') is not None
                              and os.fstat(kwargs['dir_fd']).st_ino == source_inode)
            through_absolute_source = str(path) == str(source / 'file.php')
            if not swapped and (from_source_fd or through_absolute_source):
                swapped = True
                source.rename(self.root / 'original-source')
                source.symlink_to(outside, target_is_directory=True)
            return original_open(path, flags, *args, **kwargs)
        with mock.patch.object(installer.os, 'open', opened):
            with self.assertRaisesRegex(RuntimeError, 'Test source'):
                installer.private_tree(source, stage, os.getegid())
        self.assertTrue(swapped)
        for file in stage.rglob('*'):
            if file.is_file():
                self.assertNotIn(secret, file.read_bytes())
        self.assertEqual((outside / 'file.php').read_bytes(), secret)

    def test_private_source_hardlink_is_rejected_before_copy(self):
        source = self.root / 'source'
        source.mkdir()
        private = self.root / 'private.php'
        private.write_bytes(b'private data')
        os.link(private, source / 'file.php')
        stage = self.root / 'stage'
        with self.assertRaisesRegex(RuntimeError, 'hardlinked'):
            installer.private_tree(source, stage, os.getegid())
        self.assertFalse((stage / 'file.php').exists())

    def test_owner_failure_withholds_child_output(self):
        result = subprocess.CompletedProcess([], 1, 'secret stdout', 'secret stderr')
        with mock.patch.object(installer.subprocess, 'run', return_value=result), \
             mock.patch.object(installer, 'private_failure', return_value='/private/log') as logged:
            with self.assertRaisesRegex(RuntimeError, 'private log') as raised:
                installer.run_owner(Path('/fake/php'), self.root, ['-r', 'test'])
        self.assertNotIn('secret', str(raised.exception))
        self.assertEqual(logged.call_args.args[1:], ('secret stdout', 'secret stderr'))

    def test_isolated_checks_block_network_and_use_only_explicit_live_autoloader(self):
        (self.root / 'database/migrations').mkdir(parents=True)
        (self.root / 'database/migrations/test.php').write_bytes(b'<?php')
        stage = self.root / 'candidate'
        stage.mkdir(mode=0o750)
        entries = {'app/board.php': {'old': base64.b64encode(b'old').decode(),
                                     'new': base64.b64encode(b'<?php').decode()}}
        payload = base64.b64encode(gzip.compress(b'<?php /* isolated test */')).decode()
        previous_umask = os.umask(0o077)
        try:
            with mock.patch.object(installer, 'TEST_PAYLOAD', payload), \
                 mock.patch.object(installer, 'run_owner', side_effect=['READY', '', 'Website order board checks passed: 49\n']) as runner:
                self.assertEqual(installer.isolated_checks(Path('/fake/php'), self.root, entries, stage, os.getegid()), 49)
        finally:
            os.umask(previous_umask)
        arguments = runner.call_args.args[2]
        self.assertIn('allow_url_fopen=0', arguments)
        blocked = arguments[arguments.index('-d', 4) + 1]
        self.assertIn('disable_functions=', blocked)
        self.assertIn('mail', blocked)
        self.assertIn('curl_exec', blocked)
        self.assertEqual(runner.call_args.args[3]['WEBSITE_BOARD_TEST_AUTOLOAD'], str(self.root / 'vendor/autoload.php'))
        self.assertEqual(stat.S_IMODE((stage / 'app/board.php').stat().st_mode), 0o640)
        for directory in ('app', 'database', 'database/migrations', 'tests'):
            info = (stage / directory).stat()
            self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)),
                             (0, os.getegid(), 0o750), directory)
        for relative in ('app/board.php', 'database/migrations/test.php',
                         'tests/website_order_board_test.php'):
            info = (stage / relative).stat()
            self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)),
                             (0, os.getegid(), 0o640), relative)
        self.assertEqual(self.file.read_bytes(), b'old')

    @unittest.skipUnless(os.geteuid() == 0 and shutil.which('runuser'), 'requires root and runuser')
    def test_isolated_migrations_are_readable_by_service_user_under_private_umask(self):
        reader = pwd.getpwnam('nobody')
        try:
            os.chown(str(self.root), 0, reader.pw_gid)
        except OSError as error:
            if error.errno in (errno.EINVAL, errno.EPERM):
                self.skipTest('nonroot service IDs are unavailable in this user namespace')
            raise
        os.chmod(str(self.root), 0o750)
        (self.root / 'database/migrations').mkdir(parents=True)
        migration = 'database/migrations/2026_10_03_150000_create_pos_service_tickets.php'
        (self.root / migration).write_bytes(b'<?php /* migration fixture */')
        stage = self.root / 'candidate'
        stage.mkdir()
        os.chown(str(stage), 0, reader.pw_gid)
        os.chmod(str(stage), 0o750)
        entries = {'app/board.php': {'old': base64.b64encode(b'old').decode(),
                                     'new': base64.b64encode(b'<?php').decode()}}
        payload = base64.b64encode(gzip.compress(b'<?php /* isolated test */')).decode()

        def run_as_service(php, root, args, environment=None, timeout=180):
            if args[0] == '-r':
                return 'READY'
            if args[0] == '-l':
                return ''
            script = ('from pathlib import Path; '
                      'root=Path(__import__("sys").argv[1]); '
                      'assert (root / "' + migration + '").read_bytes() == b"<?php /* migration fixture */"; '
                      'assert (root / "app/board.php").read_bytes() == b"<?php"; '
                      'assert (root / "tests/website_order_board_test.php").read_bytes(); '
                      'print("Website order board checks passed: 49")')
            result = subprocess.run(['runuser', '-u', reader.pw_name, '--', sys.executable,
                                     '-c', script, str(stage)], cwd=str(root),
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    universal_newlines=True, timeout=timeout)
            self.assertEqual(result.returncode, 0, result.stderr)
            return result.stdout

        previous_umask = os.umask(0o077)
        try:
            with mock.patch.object(installer, 'TEST_PAYLOAD', payload), \
                 mock.patch.object(installer, 'run_owner', side_effect=run_as_service):
                self.assertEqual(installer.isolated_checks(Path('/fake/php'), self.root, entries,
                                                            stage, reader.pw_gid), 49)
        finally:
            os.umask(previous_umask)
        for directory in ('app', 'database', 'database/migrations', 'tests'):
            info = (stage / directory).stat()
            self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)),
                             (0, reader.pw_gid, 0o750))

    def test_production_verifier_reads_membership_without_financial_actions(self):
        self.assertIn('website_query_verified', installer.VERIFY)
        self.assertIn('whereNotIn', installer.VERIFY)
        self.assertNotIn('->transition(', installer.VERIFY)
        self.assertNotIn('->settle(', installer.VERIFY)
        self.assertNotIn('->data(', installer.VERIFY)
        self.assertNotIn("->insert(", installer.VERIFY)
        self.assertNotIn("->update(", installer.VERIFY)


if __name__ == '__main__':
    unittest.main()
