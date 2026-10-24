"""Transactional bridge upgrade; no native work is created or terminated."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('published_install', ROOT/'deployment/apply_go_erp_published_downloads_root.py')
subject = importlib.util.module_from_spec(spec); spec.loader.exec_module(subject)


class PublishedDownloadInstallationTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='published-install-')
        self.addCleanup(temporary.cleanup); self.work = Path(temporary.name)
        self.root = self.work/'application'; self.root.mkdir(mode=0o755)
        self.control = self.work/'control'
        self.native = self.work/'native'; (self.native/'state').mkdir(mode=0o755, parents=True)
        self.releases = self.work/'runtime/releases'; self.releases.mkdir(mode=0o755, parents=True)
        self.systemd = self.work/'systemd'; self.systemd.mkdir(mode=0o755)
        self.owner = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_name='fixture-web')
        self.engine = subject.engine_from((ROOT/'deployment/apply_go_erp_manual_updates_root.py').read_bytes())
        self.app = {name: ('new:'+name+'\n').encode() for name in subject.APP_PINS}
        self.before = {name: ('old:'+name+'\n').encode() for name in self.app}
        self.pins = {name: (self.engine.sha(self.before[name]), self.engine.sha(raw)) for name, raw in self.app.items()}
        for name, raw in self.before.items():
            path = self.root/name; path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            path.write_bytes(raw); path.chmod(0o644)
        self.old_program = {'go_erp_manual_updates_root.py': b'# old broker\n', 'go_erp_manual_tick_root.py': b'# frozen tick\n'}
        self.new_program = {**self.old_program, 'go_erp_manual_updates_root.py': b'# new broker\n',
            'go_erp_published_download_root.py': b'# archive helper\n'}
        self.old_pins = {name: self.engine.sha(raw) for name, raw in self.old_program.items()}
        self.new_pins = {name: self.engine.sha(raw) for name, raw in self.new_program.items()}
        for name, value in {'APP_PINS': self.pins, 'OLD_PROGRAM_PINS': self.old_pins,
                'PROGRAM_PINS': self.new_pins}.items():
            scope = patch.object(subject, name, value); scope.start(); self.addCleanup(scope.stop)
        self.engine.APP_PINS = self.pins; self.engine.RELEASES = self.releases
        self.engine.PROGRAM_PINS = self.old_pins
        self.old_runtime = self.engine.install_runtime(self.old_program, self.releases)
        self.engine.PROGRAM_PINS = self.new_pins
        self.old_units = self.engine.units(self.old_runtime)
        self.engine.provision(self.owner, self.control)
        for name, raw in self.old_units.items(): self.engine.atomic_write(self.systemd/name, raw, 0, 0, 0o644)
        self.receipt = self.control/'private/installation.json'
        self.engine.atomic_write(self.receipt, self.engine.canonical({'format': 1,
            'kind': 'go-erp-manual-installation', 'release': '1'*40, 'runtime': self.old_runtime.name,
            'units': {name: self.engine.sha(raw) for name, raw in self.old_units.items()}}), 0, 0, 0o600)
        self.old_receipt = self.receipt.read_bytes()
        self.publisher = (ROOT/'deployment/apply_paymob_order_confirmation_root.py').read_bytes()
        (self.root/'bootstrap/cache').mkdir(mode=0o755, parents=True)
        (self.root/'storage/framework/views').mkdir(mode=0o755, parents=True)
        self.cache = self.root/'bootstrap/cache/routes-v7.php'; self.cache.write_bytes(b'cached routes'); self.cache.chmod(0o664)
        self.view = self.root/'storage/framework/views'/('a'*40+'.php')
        self.view.write_bytes(('<?php /**PATH '+str(self.root/'resources/views/admin/desktop-dashboard.blade.php')+' ENDPATH**/').encode())
        self.view.chmod(0o640)
        self.config = self.root/'bootstrap/cache/config.php'; self.config.write_bytes(b'retained config')
        self.native_state = self.native/'state/current.json'; self.native_state.write_bytes(b'PRIVATE NATIVE STATE UNCHANGED\n')
        self.pending = self.control/'requests/pending.json'; self.pending.write_bytes(b'owner request retained\n')
        self.status = self.control/'shared/status.json'
        self.protected = {path: path.read_bytes() for path in (self.native_state, self.pending, self.status)}
        self.path_active = True; self.service_active = False; self.calls = []; self.archives = []
        self.preflight = patch.object(self.engine, 'php_preflight'); self.preflight.start(); self.addCleanup(self.preflight.stop)

    def runner(self, args, timeout=30):
        self.calls.append(tuple(args))
        if args[:3] == ['/usr/bin/systemctl', 'stop', subject.WATCH]: self.path_active = False
        if args[:3] == ['/usr/bin/systemctl', 'start', subject.WATCH]: self.path_active = True
        if args[0] == '/usr/sbin/runuser': return 'GO_ERP_PUBLISHED_WEB_DOWNLOAD_READABLE'
        if 'show' in args:
            if args[2] == subject.WATCH: return 'active' if self.path_active else 'inactive'
            if args[2] == subject.SERVICE: return 'active' if self.service_active else 'inactive'
            if 'UnitFileState' in args: return 'disabled'
            return 'inactive'
        return ''

    def archive(self, root, state, shared, gid):
        self.archives.append((root, state, shared, gid))
        self.assertFalse(self.path_active)
        for name, raw in self.before.items():
            actual = (self.root/name).read_bytes()
            self.assertIn(actual, (raw, self.app[name]))
        directory = shared/'installers'; directory.mkdir(mode=0o750, exist_ok=True)
        retained = shared/'downloads.json'
        self.engine.atomic_write(retained, b'{"verified":"42"}\n', 0, self.owner.pw_gid, 0o640)
        return {'format': 1, 'kind': 'go-erp-published-downloads-result', 'available': True,
            'changed': True, 'current_version': '0.4.4-preview.42', 'previous_version': None,
            'source': 'published-state', 'application_contents_changed': False,
            'database_changed': False, 'credentials_disclosed': False}

    def perform(self, runner=None, archiver=None):
        return subject.upgrade(self.engine, self.root, self.owner, self.publisher,
            self.app, self.new_program, '2'*40, control=self.control, native=self.native,
            releases=self.releases, systemd=self.systemd, runner=runner or self.runner,
            archiver=archiver or self.archive)

    def assert_original(self):
        for name, raw in self.before.items(): self.assertEqual((self.root/name).read_bytes(), raw, name)
        for name, raw in self.old_units.items(): self.assertEqual((self.systemd/name).read_bytes(), raw, name)
        self.assertEqual(self.receipt.read_bytes(), self.old_receipt)

    def assert_no_job_controls(self):
        self.assertFalse(any(call[:3] == ('/usr/bin/systemctl', 'stop', subject.SERVICE) for call in self.calls))
        self.assertFalse(any('go-erp-live-updates.timer' in call and any(action in call for action in ('start', 'stop', 'disable', 'enable')) for call in self.calls))
        self.assertFalse(any(call[:2] == ('/usr/bin/systemctl', 'start') and call[-1].endswith('.service') for call in self.calls))
        for path, raw in self.protected.items(): self.assertEqual(path.read_bytes(), raw, str(path))

    def test_success_archives_before_app_commit_and_installs_only_three_file_runtime(self):
        result = self.perform()
        self.assertEqual(len(self.archives), 1)
        checks = [call for call in self.calls if call[0] == '/usr/sbin/runuser']
        self.assertEqual(len(checks), 1)
        self.assertIn('publishedInstaller', checks[0][checks[0].index('-r')+1])
        self.assertEqual(checks[0][-4:], (str(self.root), '0.4.4-preview.42', 'none', str(self.owner.pw_uid)))
        runtime = Path(result['runtime'])
        self.assertEqual({path.name for path in runtime.iterdir()}, set(self.new_program))
        self.assertEqual(stat.S_IMODE(runtime.stat().st_mode), 0o555)
        for path in runtime.iterdir(): self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o444)
        for name, raw in self.app.items(): self.assertEqual((self.root/name).read_bytes(), raw)
        self.assertFalse(self.cache.exists()); self.assertFalse(self.view.exists())
        self.assertEqual(self.config.read_bytes(), b'retained config')
        self.assertEqual((self.systemd/subject.WATCH).read_bytes(), self.old_units[subject.WATCH])
        self.assertIn(str(runtime).encode(), (self.systemd/subject.SERVICE).read_bytes())
        self.assertTrue(self.path_active); self.assert_no_job_controls()

    def test_existing_job_fails_busy_before_path_stop_or_archive(self):
        self.service_active = True
        with self.assertRaisesRegex(subject.Stop, '^EXISTING_UPDATE_BUSY$'): self.perform()
        self.assertTrue(self.path_active); self.assertFalse(self.archives)
        self.assertFalse(any('stop' in call for call in self.calls)); self.assert_original(); self.assert_no_job_controls()

    def test_job_racing_path_stop_is_never_killed_or_modified(self):
        def runner(args, timeout=30):
            result = self.runner(args, timeout)
            if args[:3] == ['/usr/bin/systemctl', 'stop', subject.WATCH]: self.service_active = True
            return result
        with self.assertRaisesRegex(subject.Stop, '^EXISTING_UPDATE_BUSY$'): self.perform(runner=runner)
        self.assertTrue(self.service_active); self.assertTrue(self.path_active)
        self.assertFalse(self.archives); self.assert_original(); self.assert_no_job_controls()

    def test_archive_failure_restores_path_before_any_app_or_unit_change(self):
        def archive(*args): raise subject.Stop('ARCHIVE_UNVERIFIED')
        with self.assertRaisesRegex(subject.Stop, '^ARCHIVE_UNVERIFIED$'): self.perform(archiver=archive)
        self.assert_original(); self.assertTrue(self.path_active)
        self.assertTrue(self.cache.exists()); self.assertTrue(self.view.exists()); self.assert_no_job_controls()

    def test_unavailable_or_malformed_archive_cannot_commit_or_report_ready(self):
        for result in ({'format': 1, 'kind': 'go-erp-published-downloads-result',
                'available': False, 'changed': False, 'current_version': None,
                'previous_version': None, 'source': 'none', 'application_contents_changed': False,
                'database_changed': False, 'credentials_disclosed': False}, {'available': True}):
            with self.subTest(result=result):
                with self.assertRaisesRegex(subject.Stop, '^VERIFIED_DOWNLOAD_UNAVAILABLE$'):
                    self.perform(archiver=lambda *args: result)
                self.assert_original(); self.assertTrue(self.path_active)
                self.assertTrue(self.cache.exists()); self.assert_no_job_controls()

    def test_web_uid_download_probe_failure_rolls_back_before_unit_or_receipt_commit(self):
        def runner(args, timeout=30):
            if args[0] == '/usr/sbin/runuser':
                self.calls.append(tuple(args)); raise subject.Stop('WEB_UID_DOWNLOAD_FAILED')
            return self.runner(args, timeout)
        with self.assertRaisesRegex(subject.Stop, '^WEB_DOWNLOAD_NOT_READABLE$'):
            self.perform(runner=runner)
        self.assert_original(); self.assertTrue(self.path_active)
        self.assertTrue(self.cache.exists()); self.assertTrue(self.view.exists())
        self.assertTrue((self.control/'shared/downloads.json').exists()); self.assert_no_job_controls()

    def test_post_rename_app_failure_rolls_back_and_retains_verified_archive(self):
        original = self.engine.atomic_write; raised = False
        def write(path, raw, *args, **kwargs):
            nonlocal raised
            original(path, raw, *args, **kwargs)
            if path == self.root/'routes/desktop_dashboard.php' and not raised:
                raised = True; raise OSError('after rename')
        with patch.object(self.engine, 'atomic_write', side_effect=write), self.assertRaises(OSError): self.perform()
        self.assert_original(); self.assertTrue(self.path_active)
        self.assertTrue((self.control/'shared/downloads.json').exists())
        self.assertEqual(stat.S_IMODE(self.cache.stat().st_mode), 0o664)
        self.assertEqual(stat.S_IMODE(self.view.stat().st_mode), 0o640); self.assert_no_job_controls()

    def test_post_rename_service_failure_restores_old_service_and_app(self):
        original = self.engine.atomic_write; raised = False
        def write(path, raw, *args, **kwargs):
            nonlocal raised
            original(path, raw, *args, **kwargs)
            if path == self.systemd/subject.SERVICE and not raised:
                raised = True; raise OSError('after unit rename')
        with patch.object(self.engine, 'atomic_write', side_effect=write), self.assertRaises(OSError): self.perform()
        self.assert_original(); self.assertTrue(self.path_active); self.assert_no_job_controls()

    def test_post_receipt_failure_restores_exact_receipt_before_reactivating_path(self):
        original = self.engine.atomic_write; raised = False
        def write(path, raw, *args, **kwargs):
            nonlocal raised
            original(path, raw, *args, **kwargs)
            if path == self.receipt and not raised:
                raised = True; raise OSError('after receipt rename')
        with patch.object(self.engine, 'atomic_write', side_effect=write), self.assertRaises(OSError): self.perform()
        self.assert_original(); self.assertTrue(self.path_active); self.assert_no_job_controls()

    def test_committed_activation_failure_keeps_new_app_and_runtime_without_rollback_or_kill(self):
        def runner(args, timeout=30):
            if args[:3] == ['/usr/bin/systemctl', 'start', subject.WATCH]:
                self.calls.append(tuple(args)); self.service_active = True
                raise OSError('activation outcome ambiguous')
            return self.runner(args, timeout)
        with self.assertRaisesRegex(subject.Stop, '^INSTALLED_PATH_ACTIVATION_REQUIRED$'): self.perform(runner=runner)
        for name, raw in self.app.items(): self.assertEqual((self.root/name).read_bytes(), raw)
        receipt = json.loads(self.receipt.read_bytes())
        self.assertEqual(receipt['release'], '2'*40)
        self.assertEqual(len(list((self.releases/receipt['runtime']).iterdir())), 3)
        self.assertTrue(self.service_active); self.assert_no_job_controls()

    def test_rerun_verifies_new_runtime_and_keeps_native_queue_and_status(self):
        self.perform(); result = self.perform()
        self.assertFalse(result['source_changed']); self.assertEqual(len(self.archives), 2); self.assert_no_job_controls()

    def test_tampered_previous_runtime_or_receipt_refuses_before_path_stop(self):
        path = self.old_runtime/'go_erp_manual_updates_root.py'; path.chmod(0o644)
        path.write_bytes(b'tampered'); path.chmod(0o444)
        with self.assertRaisesRegex(subject.Stop, '^INSTALLED_RUNTIME_CHANGED$'): self.perform()
        self.assertTrue(self.path_active); self.assertFalse(self.archives); self.assert_no_job_controls()

    def test_current_cross_file_pins_match_payload_bytes(self):
        real = types.ModuleType('published_real_pins')
        exec(compile((ROOT/'deployment/apply_go_erp_published_downloads_root.py').read_bytes(), '<real-pins>', 'exec'), real.__dict__)
        self.assertEqual(real.BASE_SHA256, self.engine.sha((ROOT/'deployment/apply_go_erp_manual_updates_root.py').read_bytes()))
        for name, (_, after) in real.APP_PINS.items(): self.assertEqual(self.engine.sha((ROOT/name).read_bytes()), after, name)
        for name, after in real.PROGRAM_PINS.items(): self.assertEqual(self.engine.sha((ROOT/'deployment'/name).read_bytes()), after, name)


if __name__ == '__main__': unittest.main()
