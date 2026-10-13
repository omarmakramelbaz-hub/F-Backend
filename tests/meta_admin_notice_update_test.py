#!/usr/bin/env python3
"""One-file deployment fixtures seeded from the real reviewed Git blobs."""
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

REPOSITORY = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('meta_admin_notice_updater', REPOSITORY / 'deployment/update_meta_admin_notices.py')
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)
RELEASE = 'a' * 40
PHP = pathlib.Path('/tmp/meta-php-runtime-20261013/php')


def git_blob(value):
    return subprocess.run(['git', 'cat-file', 'blob', value], cwd=REPOSITORY, capture_output=True, check=True).stdout


class AdminNoticeUpdaterTests(unittest.TestCase):
    def setUp(self):
        self.actual_uid = os.geteuid()
        self.app_uid = self.actual_uid if self.actual_uid else 1001
        self.temporary = tempfile.TemporaryDirectory(prefix='meta-admin-notice-update-test-')
        self.root = pathlib.Path(self.temporary.name) / 'project'
        self.root.mkdir()
        self.owner(self.root.parent)
        self.owner(self.root)
        self.old_protocol = git_blob(updater.PREVIOUS_BLOB)
        self.new_protocol = (REPOSITORY / updater.TARGET).read_bytes()
        self.assertEqual(updater.blob(self.new_protocol), updater.RELEASE_BLOB)
        self.original = {
            updater.TARGET: self.old_protocol,
            'app/Support/WhatsAppInboxProtocol.php': b'<?php // installed WhatsApp source\n',
            'config/meta_messaging.php': b'<?php // private installed account configuration\n',
            'bootstrap/cache/config.php': b'private cached configuration\n',
            'bootstrap/cache/routes-v7.php': b'installed cached routes\n',
            'routes/meta_inbox.php': b'<?php // installed Meta routes\n',
            '.env': b'PRIVATE_SECRET=never-read-or-change\n',
            'storage/app/customer-records.dat': b'PRIVATE_CUSTOMER_RECORDS\n',
        }
        self.original.update({rel: git_blob(value) for rel, value in updater.FOUNDATIONS.items()})
        for rel, content in self.original.items(): self.write(rel, content, 0o640)
        self.backup_parent = self.root.parent / 'meta-release-backups'
        self.backup_parent.mkdir(mode=0o700)
        self.owner(self.backup_parent)
        self.calls = []
        self.fail_lint = False
        self.fail_stage_verify = False
        self.fail_final_verify = False
        self.preflight_action = None
        self.verify_action = None
        self.release_content = self.new_protocol
        self.output = ''
        self.lint_snapshots = []

    def tearDown(self):
        self.temporary.cleanup()

    def owner(self, path):
        # Managed runtimes may expose only one mapped UID. Ownership metadata is
        # adapted in update(); ordinary fixture writes retain their actual UID.
        pass

    def write(self, rel, content, mode=0o644):
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        for parent in path.parents:
            if parent == self.root.parent: break
            self.owner(parent)
        path.write_bytes(content)
        path.chmod(mode)
        self.owner(path)

    def snapshot(self):
        return {str(path.relative_to(self.root)): (path.read_bytes(), path.stat().st_mode & 0o777, path.stat().st_uid, path.stat().st_gid)
                for path in self.root.rglob('*') if path.is_file()}

    def git_file(self, root, release, rel):
        self.assertEqual(root, self.root)
        self.assertEqual(release, RELEASE)
        self.assertEqual(rel, updater.TARGET, 'Updater must read exactly one release target')
        return self.release_content

    def runner(self, args, root):
        self.calls.append(args)
        self.assertEqual(args[0], 'php')
        self.assertEqual(root, self.root)
        if args[1] == '-l':
            path = pathlib.Path(args[2])
            self.assertTrue(path.is_relative_to(self.backup_parent))
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            self.lint_snapshots.append(self.snapshot())
            return subprocess.CompletedProcess(args, int(self.fail_lint), b'', b'private PHP diagnostic')
        self.assertTrue(args[1].endswith('/verify-admin-notice-protocol.php'))
        final = args[2] == str(self.root / updater.TARGET)
        action = self.verify_action if final else self.preflight_action
        if action: action()
        failed = self.fail_final_verify if final else self.fail_stage_verify
        return subprocess.CompletedProcess(args, int(failed), json.dumps({'ok': False} if failed else updater.PROBE_RESULT).encode(), b'private fixture diagnostic')

    def update(self):
        output = io.StringIO()
        real_mkdtemp = tempfile.mkdtemp
        real_file_state = updater.file_state
        real_directory_state = updater.directory_state
        real_atomic = updater.atomic
        def application_state(reader, path):
            state = reader(path)
            return (*state[:3], self.app_uid, state[4])
        def same_owner_atomic(path, content, metadata):
            state = real_file_state(path)
            real_atomic(path, content, (*metadata[:3], state[3], state[4]))
        def owned_backup(*args, **kwargs):
            path = real_mkdtemp(*args, **kwargs)
            self.owner(path)
            return path
        with mock.patch.object(sys, 'argv', ['update', '--root', str(self.root), '--release', RELEASE, '--php', 'php']), \
             mock.patch.object(updater.os, 'geteuid', return_value=self.app_uid), \
             mock.patch.object(updater, 'git_file', side_effect=self.git_file), \
             mock.patch.object(updater, 'run', side_effect=self.runner), \
             mock.patch.object(updater, 'file_state', side_effect=lambda path: application_state(real_file_state, path)), \
             mock.patch.object(updater, 'directory_state', side_effect=lambda path: application_state(real_directory_state, path)), \
             mock.patch.object(updater, 'atomic', side_effect=same_owner_atomic), \
             mock.patch.object(updater.tempfile, 'mkdtemp', side_effect=owned_backup), contextlib.redirect_stdout(output):
            try: updater.main()
            finally: self.output = output.getvalue()

    def test_real_old_foundations_and_protocol_upgrade_only_one_file(self):
        before = self.snapshot()
        inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        self.assertEqual(updater.blob(self.old_protocol), 'ba20320835a33826b4b6bcc84f19c62569180931')
        for rel, value in updater.FOUNDATIONS.items(): self.assertEqual(updater.blob(self.original[rel]), value)
        self.update()
        expected = dict(before)
        expected[updater.TARGET] = (self.new_protocol, *before[updater.TARGET][1:])
        self.assertEqual(self.snapshot(), expected)
        for rel in before:
            if rel != updater.TARGET: self.assertEqual((self.root / rel).stat().st_ino, inodes[rel], rel)
        self.assertEqual(self.lint_snapshots, [before, before])
        self.assertEqual(len(self.calls), 4)
        backup, = self.backup_parent.iterdir()
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual(backup.stat().st_uid, self.actual_uid)
        saved = backup / 'previous-MetaMessagingProtocol.php'
        self.assertEqual(saved.read_bytes(), self.old_protocol)
        self.assertEqual(saved.stat().st_mode & 0o777, 0o600)
        self.assertIn('META_ADMIN_NOTICE_PROTOCOL_UPDATED=1', self.output)
        self.assertNotIn('private', self.output)

    def test_current_protocol_creates_audit_directory_without_rewriting_any_application_file(self):
        self.write(updater.TARGET, self.new_protocol, 0o640)
        before = self.snapshot()
        inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertEqual({rel: (self.root / rel).stat().st_ino for rel in before}, inodes)
        self.assertIn('META_ADMIN_NOTICE_PROTOCOL_UPDATED=0', self.output)
        self.assertIn('BACKUP=', self.output)
        backup, = self.backup_parent.iterdir()
        self.assertFalse((backup / 'previous-MetaMessagingProtocol.php').exists())

    def test_unknown_old_or_unreviewed_new_protocol_is_rejected_before_private_staging(self):
        self.write(updater.TARGET, b'<?php // unknown local source edit\n', 0o640)
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_INSTALLED_PROTOCOL'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.write(updater.TARGET, self.old_protocol, 0o640)
        self.release_content = b'<?php // unknown new release code\n'
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_RELEASE_PROTOCOL'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertEqual(list(self.backup_parent.iterdir()), [])
        self.assertEqual(self.calls, [])

    def test_changed_original_foundation_fails_before_protocol_publication(self):
        rel = next(iter(updater.FOUNDATIONS))
        self.write(rel, b'<?php // incompatible Meta foundation\n', 0o640)
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'META_FOUNDATION_DIFFERS'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertEqual(list(self.backup_parent.iterdir()), [])
        self.assertEqual(self.calls, [])

    def test_lint_and_staged_fixture_failures_leave_all_application_files_unchanged(self):
        before = self.snapshot()
        self.fail_lint = True
        with self.assertRaisesRegex(RuntimeError, 'STAGED_PROTOCOL_LINT_FAILED'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.fail_lint = False
        self.fail_stage_verify = True
        with self.assertRaisesRegex(RuntimeError, 'META_ADMIN_NOTICE_PROTOCOL_VERIFICATION_FAILED'): self.update()
        self.assertEqual(self.snapshot(), before)

    def test_final_fixture_failure_restores_old_protocol_bytes_ownership_and_permissions(self):
        before = self.snapshot()
        self.fail_final_verify = True
        with self.assertRaisesRegex(RuntimeError, 'META_ADMIN_NOTICE_PROTOCOL_VERIFICATION_FAILED'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertIn('META_ADMIN_NOTICE_CODE_RESTORED', self.output)

    def test_source_permission_or_directory_context_change_stops_before_publication(self):
        before = self.snapshot()
        self.preflight_action = lambda: (self.root / updater.TARGET).chmod(0o600)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_PROTOCOL_EDIT'): self.update()
        expected = dict(before)
        expected[updater.TARGET] = (self.old_protocol, 0o600, *before[updater.TARGET][2:])
        self.assertEqual(self.snapshot(), expected)
        (self.root / updater.TARGET).chmod(0o640)
        self.preflight_action = lambda: (self.root / 'app/Support').chmod(0o750)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_PROTOCOL_CONTEXT_EDIT'): self.update()
        self.assertEqual(self.snapshot(), before)

    def test_later_operator_source_edit_is_not_overwritten_by_rollback(self):
        changed = b'<?php // operator edit during protocol verification\n'
        self.verify_action = lambda: self.write(updater.TARGET, changed, 0o640)
        self.fail_final_verify = True
        with self.assertRaisesRegex(RuntimeError, 'META_ADMIN_NOTICE_PROTOCOL_VERIFICATION_FAILED'): self.update()
        self.assertEqual((self.root / updater.TARGET).read_bytes(), changed)
        self.assertIn('META_ADMIN_NOTICE_ROLLBACK_INCOMPLETE', self.output)

    def test_symlink_protocol_foundation_or_backup_is_rejected(self):
        for rel in (updater.TARGET, next(iter(updater.FOUNDATIONS))):
            with self.subTest(rel=rel):
                path = self.root / rel
                path.unlink()
                path.symlink_to(self.root / '.env')
                with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
                path.unlink()
                self.write(rel, self.original[rel], 0o640)
                self.assertEqual(list(self.backup_parent.iterdir()), [])
                self.assertEqual(self.calls, [])
        self.backup_parent.rmdir()
        self.backup_parent.symlink_to(self.root)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
        self.assertEqual((self.root / '.env').read_bytes(), self.original['.env'])

    @unittest.skipUnless(PHP.is_file() or shutil.which('php'), 'PHP runtime unavailable')
    def test_real_php_verification_accepts_reviewed_new_protocol_and_rejects_real_old_protocol(self):
        php = str(PHP) if PHP.is_file() else shutil.which('php')
        probe = self.root.parent / 'pure-protocol-check.php'
        probe.write_bytes(updater.PROTOCOL_PROBE)
        before = self.snapshot()
        old = subprocess.run([php, str(probe), str(self.root / updater.TARGET)], capture_output=True)
        self.assertNotEqual(old.returncode, 0)
        self.assertEqual(json.loads(old.stdout), {'ok': False})
        staged = self.root.parent / 'new-protocol.php'
        staged.write_bytes(self.new_protocol)
        checked = subprocess.run([php, str(probe), str(staged)], capture_output=True)
        self.assertEqual(checked.returncode, 0, checked.stderr.decode())
        self.assertEqual(json.loads(checked.stdout), updater.PROBE_RESULT)
        self.assertEqual(self.snapshot(), before, 'Pure PHP fixture must not change application files')


class RootWrapperTests(unittest.TestCase):
    """Run the wrapper against a temporary Git repository and fake owned units."""
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='meta-admin-wrapper-test-')
        self.base = pathlib.Path(self.temporary.name)
        self.home = self.base / 'home'
        self.root = self.home / 'public_html'
        self.root.mkdir(parents=True)
        self.bin = self.base / 'bin'; self.bin.mkdir()
        self.units = self.base / 'units'; self.units.mkdir()
        self.states = self.base / 'states'; self.states.mkdir()
        self.events = self.base / 'events.log'
        self.timer = 'fasakhansta-meta-messaging.timer'
        self.service = 'fasakhansta-meta-messaging.service'
        for unit in (self.timer, self.service):
            (self.units / unit).write_text('Description=Fasakhansta native Meta messaging fixture\n')
        self.tool('id', '#!/bin/sh\nprintf "0\\n"\n')
        self.tool('runuser', '#!/bin/sh\n[ "$1" = -u ] && [ "$2" = fasakha ] && [ "$3" = -- ] || exit 98\nshift 3\nexec "$@"\n')
        self.tool('systemctl', '#!' + sys.executable + '\n' + '''import os,pathlib,sys
command=sys.argv[1];unit=sys.argv[-1]
with open(os.environ['WRAPPER_EVENTS'],'a') as out: out.write('systemctl '+command+' '+unit+'\\n')
state=pathlib.Path(os.environ['WRAPPER_STATES'])/unit
if command=='is-active': raise SystemExit(0 if state.exists() else 3)
if command=='stop':
 state.unlink(missing_ok=True)
 raise SystemExit(1 if os.environ.get('WRAPPER_FAIL_STOP')==unit else 0)
if command=='start':
 state.write_text('active')
 raise SystemExit(0)
raise SystemExit(97)
''')
        self.php = self.bin / 'fixture-php'
        self.tool('fixture-php', '#!' + sys.executable + '\n' + '''import os,pathlib,sys
if sys.argv[1]=='-r':
 with open(os.environ['WRAPPER_EVENTS'],'a') as out: out.write('php runtime-preflight\\n')
 raise SystemExit(1 if os.environ.get('WRAPPER_FAIL_PREFLIGHT')=='1' else 0)
name=pathlib.Path(sys.argv[1]).name
with open(os.environ['WRAPPER_EVENTS'],'a') as out: out.write('php '+name+' '+(' '.join(sys.argv[2:]))+'\\n')
if name=='resolve_meta_admin_notices.php' and os.environ.get('WRAPPER_FAIL_RECOVERY')=='1': raise SystemExit(1)
print('{"ok":true}')
''')
        self.protocol = self.root / updater.TARGET
        self.protocol.parent.mkdir(parents=True)
        self.protocol.write_bytes(git_blob(updater.PREVIOUS_BLOB))
        deployment = self.root / 'deployment'; deployment.mkdir()
        (deployment / 'update_meta_admin_notices.py').write_text('''# FIXTURE_SOURCE_NOT_FOR_STDOUT
import os,pathlib,sys
root=pathlib.Path(sys.argv[sys.argv.index('--root')+1]);release=sys.argv[sys.argv.index('--release')+1]
audit=root.parent/'meta-release-backups'/('20261013-150000-admin-notices-'+release[:8]+'-fixture123')
audit.mkdir(parents=True,exist_ok=True);audit.chmod(0o700)
with open(os.environ['WRAPPER_EVENTS'],'a') as out: out.write('python publish\\n')
print('META_ADMIN_NOTICE_PROTOCOL_VERIFIED')
print('META_ADMIN_NOTICE_PROTOCOL_UPDATED='+('0' if os.environ.get('WRAPPER_ALREADY_CURRENT')=='1' else '1'))
print('BACKUP='+str(audit))
''')
        for name in ('resolve_meta_admin_notices.php', 'meta_inbound_diagnostic.php'):
            (deployment / name).write_text('<?php // FIXTURE_SOURCE_NOT_FOR_STDOUT\n')
        subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', 'init', '-q'], cwd=self.root, check=True, capture_output=True)
        subprocess.run(['git', 'add', 'deployment'], cwd=self.root, check=True, capture_output=True)
        subprocess.run(['git', '-c', 'user.name=fixture', '-c', 'user.email=fixture@example.invalid',
            '-c', 'core.hooksPath=/dev/null', 'commit', '-qm', 'isolated wrapper fixture'], cwd=self.root, check=True, capture_output=True)
        self.release = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=self.root, check=True, capture_output=True, text=True).stdout.strip()
        source = (REPOSITORY / 'deployment/apply_meta_admin_notices_root.sh').read_text()
        source = source.replace('/home/fasakha', str(self.home)).replace('/etc/systemd/system', str(self.units))
        source = source.replace('/opt/cpanel/ea-php82/root/usr/bin/php', str(self.php))
        self.wrapper = self.base / 'wrapper.sh'; self.wrapper.write_text(source)
        self.environment = dict(os.environ, PATH=str(self.bin) + os.pathsep + os.environ['PATH'],
            WRAPPER_EVENTS=str(self.events), WRAPPER_STATES=str(self.states))

    def tearDown(self): self.temporary.cleanup()

    def tool(self, name, content):
        path = self.bin / name; path.write_text(content); path.chmod(0o755)

    def active(self, unit): (self.states / unit).write_text('active')

    def run_wrapper(self, **variables):
        result = subprocess.run(['bash', str(self.wrapper), self.release], env={**self.environment, **variables}, capture_output=True, text=True)
        self.assertNotIn('FIXTURE_SOURCE_NOT_FOR_STDOUT', result.stdout + result.stderr)
        return result, self.events.read_text().splitlines() if self.events.exists() else []

    def test_recovery_runs_while_units_stopped_then_only_previously_active_timer_is_restored(self):
        self.active(self.timer)
        result, events = self.run_wrapper()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        recovery = next(i for i, item in enumerate(events) if item.startswith('php resolve_meta_admin_notices.php --audit-dir='))
        self.assertLess(events.index('systemctl stop ' + self.timer), recovery)
        self.assertLess(events.index('systemctl stop ' + self.service), recovery)
        self.assertLess(recovery, next(i for i, item in enumerate(events) if item.startswith('php meta_inbound_diagnostic.php --limit=200')))
        self.assertGreater(events.index('systemctl start ' + self.timer), recovery)
        self.assertNotIn('systemctl start ' + self.service, events)
        self.assertIn('META_ADMIN_NOTICE_UPDATE_COMPLETE', result.stdout)

    def test_active_service_with_inactive_timer_is_restored_even_when_recovery_fails(self):
        self.active(self.service)
        result, events = self.run_wrapper(WRAPPER_FAIL_RECOVERY='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('systemctl start ' + self.service, events)
        self.assertNotIn('systemctl start ' + self.timer, events)
        self.assertFalse(any(item.startswith('php meta_inbound_diagnostic.php') for item in events))

    def test_partial_stop_failure_still_restores_both_previously_active_units(self):
        self.active(self.timer); self.active(self.service)
        result, events = self.run_wrapper(WRAPPER_FAIL_STOP=self.service)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('systemctl start ' + self.service, events)
        self.assertIn('systemctl start ' + self.timer, events)
        self.assertNotIn('python publish', events)

    def test_already_current_protocol_still_runs_historical_recovery(self):
        result, events = self.run_wrapper(WRAPPER_ALREADY_CURRENT='1')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('META_ADMIN_NOTICE_PROTOCOL_UPDATED=0', result.stdout)
        self.assertTrue(any(item.startswith('php resolve_meta_admin_notices.php --audit-dir=') for item in events))
        self.assertFalse(any(item.startswith('systemctl start ') for item in events))

    def test_staged_helper_blob_mismatch_stops_before_units_or_protocol_are_changed(self):
        real_git = shutil.which('git')
        self.tool('git', '#!/bin/sh\nif [ "$1" = hash-object ]; then printf "0000000000000000000000000000000000000000\\n"; exit 0; fi\nexec ' + real_git + ' "$@"\n')
        result, events = self.run_wrapper()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(events, [])

    def test_missing_php_recovery_functions_leave_parser_and_active_units_unchanged(self):
        self.active(self.timer); self.active(self.service)
        before = self.protocol.read_bytes()
        result, events = self.run_wrapper(WRAPPER_FAIL_PREFLIGHT='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('META_ADMIN_NOTICE_RUNTIME_PREFLIGHT_FAILED', result.stdout)
        self.assertNotIn('META_ADMIN_NOTICE_UPDATE_COMPLETE', result.stdout)
        self.assertEqual(self.protocol.read_bytes(), before)
        self.assertEqual(events, ['php runtime-preflight'])
        self.assertTrue((self.states / self.timer).exists())
        self.assertTrue((self.states / self.service).exists())
        self.assertFalse((self.home / 'meta-release-backups').exists())


if __name__ == '__main__': unittest.main()
