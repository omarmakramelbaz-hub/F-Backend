"""Real temporary Git trees and live folders exercise the speed release installer."""
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location(
    'speed_release', Path(__file__).resolve().parents[1] / 'deployment/apply_go_erp_speed_release_root.py')
operator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(operator)
PHP = shutil.which('php') or '/bin/true'


class SpeedReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-speed-release-')
        base = Path(self.temporary.name)
        self.package, self.root, self.manual = base / 'package', base / 'live', base / 'manual'
        self.backups, self.lock = base / 'backups', base / 'watcher.lock'
        for path in (self.package, self.root, self.manual / 'shared', self.manual / 'requests'):
            path.mkdir(parents=True)
        self.lock.write_text('')
        self.git('init', '--quiet')
        self.git('config', 'user.email', 'test@example.invalid')
        self.git('config', 'user.name', 'Test')
        for name in operator.TARGETS:
            if name not in operator.NEW_FILES:
                self.write(self.package / name, '<?php // published ' + name + "\n")
        self.write(self.package / 'routes/web.php', '<?php // unchanged\n')
        self.base = self.commit('published preview')
        for name in operator.TARGETS:
            self.write(self.package / name, '<?php // faster ' + name + "\n")
        self.write(self.package / 'deployment/apply_go_erp_speed_release_root.py', '# operator\n')
        self.release = self.commit('speed fixes')
        self.original_base = operator.BASE
        operator.BASE = self.base
        for name in operator.TARGETS:
            if name not in operator.NEW_FILES:
                self.write(self.root / name, '<?php // published ' + name + "\n")
                os.chmod(self.root / name, 0o640)
        (self.root / 'database/migrations').mkdir(parents=True, exist_ok=True)
        self.uid = os.getuid()

    def tearDown(self):
        operator.BASE = self.original_base
        self.temporary.cleanup()

    def git(self, *arguments):
        return subprocess.run(['git', '-C', str(self.package)] + list(arguments), check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout.decode().strip()

    def write(self, path, text):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def commit(self, message):
        self.git('add', '-A')
        self.git('commit', '--quiet', '-m', message)
        return self.git('rev-parse', 'HEAD')

    def run_operator(self, mode, backup=None):
        return operator.run(self.root, self.package, self.release, self.uid, PHP, mode, backup=backup,
                            manual=self.manual, backups=self.backups, watcher_lock=self.lock)

    def live(self, name):
        path = self.root / name
        return path.read_text() if path.exists() else None

    def test_plan_reads_only(self):
        result = self.run_operator('plan')
        self.assertEqual(result['result'], 'GO_ERP_SPEED_RELEASE_PLAN')
        self.assertEqual(result['live_files'], 6)
        self.assertTrue(all(self.live(name) in (None, '<?php // published ' + name + "\n") for name in operator.TARGETS))
        self.assertFalse(self.backups.exists())

    def test_apply_writes_exactly_the_reviewed_files_and_rollback_restores_them(self):
        result = self.run_operator('apply')
        self.assertEqual(result['result'], 'GO_ERP_SPEED_RELEASE_INSTALLED')
        for name in operator.TARGETS:
            self.assertEqual(self.live(name), '<?php // faster ' + name + "\n")
        new = self.root / 'database/migrations/2026_10_29_120000_add_whatsapp_inbox_unread_index.php'
        self.assertEqual(stat.S_IMODE(new.stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE((self.root / 'desktop/router.php').stat().st_mode), 0o640,
                         'An existing file keeps its permissions')
        self.assertEqual(self.live('routes/web.php'), None, 'Nothing outside the six files is written')
        self.assertEqual(self.run_operator('plan')['result'], 'GO_ERP_SPEED_RELEASE_ALREADY_INSTALLED')
        backup = Path(result['backup'])
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        rolled = self.run_operator('rollback', backup=backup)
        self.assertEqual(rolled['result'], 'GO_ERP_SPEED_RELEASE_ROLLED_BACK')
        for name in operator.TARGETS:
            expected = None if name in operator.NEW_FILES else '<?php // published ' + name + "\n"
            self.assertEqual(self.live(name), expected)
        self.assertEqual(self.run_operator('plan')['result'], 'GO_ERP_SPEED_RELEASE_PLAN')

    def test_a_changed_live_file_stops_before_any_write(self):
        self.write(self.root / 'public/js/dashboard-meta-inbox.js', '// edited on the server\n')
        with self.assertRaisesRegex(operator.Stop, 'LIVE_FILES_DIFFER_FROM_PREVIEW_62'):
            self.run_operator('apply')
        self.assertEqual(self.live('app/Services/Dashboard/WhatsAppInboxReadState.php'),
                         '<?php // published app/Services/Dashboard/WhatsAppInboxReadState.php\n')

    def test_a_running_or_requested_app_update_is_never_interrupted(self):
        (self.manual / 'shared/status.json').write_text(json.dumps({'state': 'waiting-ci'}))
        with self.assertRaisesRegex(operator.Stop, 'APP_UPDATE_IN_PROGRESS'):
            self.run_operator('apply')
        (self.manual / 'shared/status.json').write_text(json.dumps({'state': 'ready'}))
        (self.manual / 'requests/pending.json').write_text('{}')
        with self.assertRaisesRegex(operator.Stop, 'APP_UPDATE_REQUEST_WAITING'):
            self.run_operator('apply')

    def test_plan_reports_only_the_shared_device_setup_switch(self):
        self.assertIsNone(self.run_operator('plan')['shared_device_setup_enabled'])
        (self.root / '.env').write_text('APP_KEY=base64:secret\nDESKTOP_DASHBOARD_WORKSPACE_ENABLED=true\n')
        result = self.run_operator('plan')
        self.assertIs(result['shared_device_setup_enabled'], True)
        self.assertNotIn('secret', json.dumps(result))
        (self.root / '.env').write_text('DESKTOP_DASHBOARD_WORKSPACE_ENABLED=false\n')
        self.assertIs(self.run_operator('plan')['shared_device_setup_enabled'], False)

    def test_the_kept_record_of_an_earlier_request_does_not_block(self):
        (self.manual / 'requests/last-request.json').write_text('{}')
        self.assertEqual(self.run_operator('plan')['result'], 'GO_ERP_SPEED_RELEASE_PLAN')

    def test_a_busy_update_worker_is_never_raced(self):
        held = os.open(str(self.lock), os.O_RDWR)
        try:
            fcntl.flock(held, fcntl.LOCK_EX)
            with self.assertRaisesRegex(operator.Stop, 'UPDATE_WORKER_BUSY'):
                self.run_operator('apply')
        finally:
            os.close(held)

    def test_an_unreviewed_source_change_in_the_package_is_refused(self):
        self.write(self.package / 'routes/web.php', '<?php // unexpected\n')
        self.release = self.commit('extra change')
        with self.assertRaisesRegex(operator.Stop, 'UNREVIEWED_SOURCE_DELTA'):
            self.run_operator('plan')

    def test_a_failed_write_restores_every_file(self):
        original = operator.live_write
        calls = []

        def failing(root, name, data, uid, gid, mode):
            calls.append(name)
            if len(calls) == 3:
                raise OSError('disk full')
            return original(root, name, data, uid, gid, mode)
        operator.live_write = failing
        try:
            with self.assertRaisesRegex(operator.Stop, 'APPLY_FAILED RESTORED'):
                self.run_operator('apply')
        finally:
            operator.live_write = original
        for name in operator.TARGETS:
            expected = None if name in operator.NEW_FILES else '<?php // published ' + name + "\n"
            self.assertEqual(self.live(name), expected)


if __name__ == '__main__':
    unittest.main()
