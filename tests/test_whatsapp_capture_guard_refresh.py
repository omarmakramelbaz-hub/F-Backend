#!/usr/bin/env python3
"""Exercise real file publication and durable resume with a bounded fake manager."""
import hashlib
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('capture_guard_tested', ROOT / 'deployment/refresh_whatsapp_capture_guard.py')
m = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = m
spec.loader.exec_module(m)
COMMAND = b'''<?php

namespace App\\Console\\Commands;

use App\\Services\\Dashboard\\WhatsAppInboxConsumer;
use Illuminate\\Console\\Command;
use Throwable;

class ConsumeWhatsAppInbox extends Command
{
    protected $signature = 'whatsapp:consume-inbox {--limit=100 : Maximum encrypted events to inspect (1-500)} {--retry-quarantined : Explicitly retry retained quarantined events}';
    protected $description = 'Project captured WhatsApp messages into the encrypted dashboard inbox.';

    public function handle(WhatsAppInboxConsumer $consumer)
    {
        $limit = (string) $this->option('limit');
        if (!preg_match('/\\A[0-9]{1,3}\\z/', $limit) || (int) $limit < 1 || (int) $limit > 500) {
            $this->line('{"error":"INVALID_LIMIT"}');
            return 1;
        }
        try {
            $metrics = $consumer->consume((int) $limit, (bool) $this->option('retry-quarantined'));
            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));
            return $metrics['errors'] > 0 ? 1 : ($metrics['quarantined_events'] > 0 ? 2 : 0);
        } catch (Throwable $error) {
            $this->line('{"error":"INBOX_CONSUMER_FAILED"}');
            return 1;
        }
    }
}
'''


class CaptureGuardTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='wa-capture-guard-test-', dir=pathlib.Path.home())
        self.addCleanup(self.temp.cleanup)
        self.base = pathlib.Path(self.temp.name)
        self.base.chmod(0o700)
        self.root = self.base / 'app'
        self.root.mkdir()
        self.units = self.base / 'units'
        self.units.mkdir()
        self.pipeline = self.base / 'pipeline'
        self.pipeline.mkdir(mode=0o700)
        self.checkpoint = self.pipeline / 'capture-guard'
        self.original_journal = self.pipeline / 'journal.jsonl'
        self.original_journal.write_bytes(b'{"phase":"ORIGINAL_STATE_IMMUTABLE"}\n')
        self.original_journal.chmod(0o600)
        self.uid = os.geteuid()
        self.addCleanup(mock.patch.stopall)
        mock.patch.object(m, 'SYSTEM_UID', self.uid).start()
        mock.patch.object(m, 'UNIT_DIRECTORY', self.units).start()
        self.legacy = (ROOT / 'deployment/install_whatsapp_capture_root.sh').read_bytes()
        self.before, self.after = m.frozen_service(self.legacy)
        for name in m.UNITS:
            path = self.units / name
            path.write_bytes(self.before if name == m.SERVICE else b'unchanged ' + name.encode() + b'\n')
            path.chmod(0o644)
        self.original = m.unit_profile(m.SERVICE)
        self.other_profiles = {n: m.unit_profile(n) for n in m.UNITS if n != m.SERVICE}
        source_data = {
            'app/Services/Dashboard/WhatsAppInboxConsumer.php':
                (ROOT / 'app/Services/Dashboard/WhatsAppInboxConsumer.php').read_bytes(),
            'app/Console/Commands/ConsumeWhatsAppInbox.php': COMMAND,
            'app/Support/Fixture.php': b'<?php /* reviewed and preserved */\n',
        }
        for name, content in source_data.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(content)
            path.chmod(0o644)
        self.policy = {name: m.blob(data) for name, data in source_data.items()}
        self.source_bytes = source_data
        self.calls = []
        self.reload_error = False
        self.reload_pending = False
        self.states = {n: 'inactive' for n in m.UNITS}
        self.manager_hook = None

    def runner(self, args):
        self.calls.append(args)
        if self.manager_hook:
            self.manager_hook(args)
        if args == [m.SYSTEMCTL, 'daemon-reload']:
            if self.reload_error:
                return 1, b''
            self.reload_pending = False
            return 0, b''
        self.assertEqual(args[:3], [m.SYSTEMCTL, 'show', '--no-pager'])
        name = args[-1]
        self.assertIn(name, m.UNITS)
        values = {'LoadState': 'loaded', 'ActiveState': self.states[name],
                  'FragmentPath': str(self.units / name), 'DropInPaths': '',
                  'NeedDaemonReload': 'yes' if self.reload_pending and name == m.SERVICE else 'no'}
        if name.endswith('.service'):
            values['MainPID'] = '0'
        return 0, ('\n'.join(k + '=' + v for k, v in values.items()) + '\n').encode()

    def validate(self, **kw):
        return m.validate_capture_guard(self.root, self.checkpoint, self.original, self.policy,
                                       kw.get('runner', self.runner), legacy_source=self.legacy, source_uid=self.uid)

    def refresh(self, **kw):
        return m.refresh_capture_guard(self.root, self.checkpoint, self.original, self.policy,
                                      kw.get('runner', self.runner), legacy_source=self.legacy, source_uid=self.uid)

    def assert_other_files_unchanged(self):
        self.assertEqual({n: m.unit_profile(n) for n in self.other_profiles}, self.other_profiles)
        self.assertEqual(self.original_journal.read_bytes(), b'{"phase":"ORIGINAL_STATE_IMMUTABLE"}\n')
        self.assertEqual({n: (self.root / n).read_bytes() for n in self.source_bytes}, self.source_bytes)

    def test_only_one_literal_and_required_sources_match_reviewed_pins(self):
        self.assertEqual(m.blob(self.before), '272dc4265e6cfea850db3699463f46501d58e7b5')
        self.assertEqual(hashlib.sha256(self.before).hexdigest(), '4438eeaad9b9273b8f0f925a792563d666df6d026a552448ee6fa1d3f55e3e56')
        self.assertEqual(self.before.replace(m.OLD_CONSUMER_SHA, m.NEW_CONSUMER_SHA, 1), self.after)
        for name, (pin, sha) in m.REQUIRED_SOURCES.items():
            self.assertEqual(m.blob(self.source_bytes[name]), pin)
            self.assertEqual(hashlib.sha256(self.source_bytes[name]).hexdigest(), sha)

    def test_validation_original_is_read_only_and_allows_running_capture_timer(self):
        self.states[m.UNITS[0]] = 'active'
        self.assertEqual(self.validate(), self.original)
        self.assertFalse(self.checkpoint.exists())
        self.assertTrue(all(c[1] == 'show' for c in self.calls))
        self.assert_other_files_unchanged()

    def test_refresh_actual_atomic_exchange_and_durable_receipt_preserve_others(self):
        profile = self.refresh()
        self.assertEqual((self.units / m.SERVICE).read_bytes(), self.after)
        self.assertNotEqual(profile[1], self.original[1])
        self.assertEqual(self.validate(), profile)
        self.assertEqual((self.checkpoint / 'before.service').read_bytes(), self.before)
        self.assertEqual((self.checkpoint / 'intent.json').stat().st_mode & 0o777, 0o600)
        self.assertEqual((self.checkpoint / 'before.service').stat().st_mode & 0o777, 0o600)
        self.assertTrue((self.checkpoint / 'complete.json').is_file())
        self.assertEqual([c for c in self.calls if c[1] != 'show'], [[m.SYSTEMCTL, 'daemon-reload']])
        self.assert_other_files_unchanged()

    def test_beforeimage_and_intent_are_durable_before_exchange(self):
        real = m.exchange
        with mock.patch.object(m, 'exchange') as exchange:
            def check(a, b):
                self.assertEqual((self.checkpoint / 'before.service').read_bytes(), self.before)
                intent = json.loads((self.checkpoint / 'intent.json').read_bytes())
                self.assertEqual(intent['original_profile'], list(self.original))
                self.assertEqual(a.name, intent['stage_name'])
                self.assertEqual(m.unit_profile(m.SERVICE), self.original)
                return real(a, b)
            exchange.side_effect = check
            self.refresh()

    def test_each_worker_must_be_quiet(self):
        for name in m.UNITS:
            with self.subTest(name=name):
                self.states[name] = 'active'
                with self.assertRaisesRegex(m.SafeError, 'WORKERS_NOT_QUIESCENT'):
                    self.refresh()
                self.states[name] = 'inactive'
                self.assertEqual(m.unit_profile(m.SERVICE), self.original)
                self.assertFalse(self.checkpoint.exists())

    def test_unknown_manager_states_dropins_and_reload_flag_are_rejected(self):
        for key, value in [('LoadState', 'not-found'), ('ActiveState', 'reloading'),
                           ('FragmentPath', '/unknown/unit'), ('DropInPaths', '/override.conf'),
                           ('NeedDaemonReload', 'yes'), ('MainPID', '5')]:
            def runner(args, key=key, value=value):
                code, raw = self.runner(args)
                if args[-1] == m.SERVICE:
                    lines = dict(line.split('=', 1) for line in raw.decode().strip().splitlines())
                    lines[key] = value
                    raw = ('\n'.join(k + '=' + v for k, v in lines.items()) + '\n').encode()
                return code, raw
            with self.subTest(key=key), self.assertRaises(m.SafeError):
                self.refresh(runner=runner)
            self.assertEqual(m.unit_profile(m.SERVICE), self.original)

    def test_unknown_live_service_content_cannot_be_overwritten(self):
        p = self.units / m.SERVICE
        p.write_bytes(self.before + b'Unexpected=1\n')
        with self.assertRaisesRegex(m.SafeError, 'UNEXPLAINED_LIVE_PROFILE'):
            self.refresh()
        self.assertTrue(p.read_bytes().endswith(b'Unexpected=1\n'))
        self.assertFalse(self.checkpoint.exists())

    def test_file_links_modes_and_symlinks_are_rejected(self):
        p = self.units / m.SERVICE
        os.link(p, self.units / 'link')
        with self.assertRaises(m.SafeError):
            self.refresh()
        (self.units / 'link').unlink()
        p.chmod(0o666)
        with self.assertRaises(m.SafeError):
            self.refresh()
        p.chmod(0o644)
        p.rename(self.units / 'retained')
        p.symlink_to(self.units / 'retained')
        with self.assertRaises(m.SafeError):
            self.refresh()

    def test_reviewed_source_mismatch_refuses_publication_but_read_only_hardlinks_are_supported(self):
        p = self.root / 'app/Support/Fixture.php'
        p.write_bytes(b'changed')
        with self.assertRaisesRegex(m.SafeError, 'REVIEWED_SOURCE_MISMATCH'):
            self.refresh()
        p.write_bytes(self.source_bytes['app/Support/Fixture.php'])
        os.link(p, self.root / 'linked.php')
        self.refresh()
        self.assertEqual((self.root / 'linked.php').read_bytes(), self.source_bytes['app/Support/Fixture.php'])

    def test_read_only_public_source_directories_0775_are_preserved(self):
        paths = [self.root, self.root / 'app', self.root / 'app/Services',
                 self.root / 'app/Services/Dashboard', self.root / 'app/Console',
                 self.root / 'app/Console/Commands', self.root / 'app/Support']
        for path in paths:
            path.chmod(0o775)
        self.refresh()
        self.assertTrue(all(path.stat().st_mode & 0o777 == 0o775 for path in paths))
        self.assert_other_files_unchanged()

    def test_group_write_allowance_does_not_extend_to_private_or_unit_paths_or_source_leaves(self):
        for path in (self.units, self.pipeline, self.root / 'app/Support/Fixture.php'):
            mode = path.stat().st_mode & 0o777
            path.chmod(mode | 0o020)
            with self.subTest(path=path.name), self.assertRaises(m.SafeError):
                self.refresh()
            path.chmod(mode)
            self.assertEqual(m.unit_profile(m.SERVICE), self.original)
        (self.root / 'app').chmod(0o777)
        with self.assertRaises(m.SafeError):
            self.refresh()
        self.assertEqual(m.unit_profile(m.SERVICE), self.original)

    def test_source_race_after_intent_prevents_exchange(self):
        real = m.write_new
        def writer(path, data, mode=0o600):
            real(path, data, mode)
            if path.name == 'intent.json':
                (self.root / 'app/Support/Fixture.php').write_bytes(b'changed concurrently')
        with mock.patch.object(m, 'write_new', side_effect=writer), self.assertRaises(m.SafeError):
            self.refresh()
        self.assertEqual(m.unit_profile(m.SERVICE), self.original)
        self.assertNotIn([m.SYSTEMCTL, 'daemon-reload'], self.calls)

    def test_concurrent_service_writer_is_retained_in_its_live_path(self):
        real = m.exchange
        count = 0
        competitor = self.before + b'Concurrent=1\n'
        def raced(a, b):
            nonlocal count
            count += 1
            if count == 1:
                b.write_bytes(competitor)
            return real(a, b)
        with mock.patch.object(m, 'exchange', side_effect=raced), self.assertRaisesRegex(m.SafeError, 'CONCURRENT_UNIT_RETAINED'):
            self.refresh()
        self.assertEqual((self.units / m.SERVICE).read_bytes(), competitor)
        self.assertNotIn([m.SYSTEMCTL, 'daemon-reload'], self.calls)

    def test_journal_failure_before_exchange_does_not_modify_service(self):
        real = m.write_new
        def writer(path, data, mode=0o600):
            if path.name == 'intent.json':
                raise OSError('intent durable write failed')
            return real(path, data, mode)
        with mock.patch.object(m, 'write_new', side_effect=writer), self.assertRaises(OSError):
            self.refresh()
        self.assertEqual(m.unit_profile(m.SERVICE), self.original)
        with self.assertRaises(m.SafeError):
            self.validate()

    def test_reload_failure_keeps_new_guard_and_explicit_resume_authorizes_it(self):
        real = m.exchange
        def exchange(a, b):
            real(a, b)
            self.reload_pending = True
        self.reload_error = True
        with mock.patch.object(m, 'exchange', side_effect=exchange), self.assertRaisesRegex(m.SafeError, 'DAEMON_RELOAD_UNVERIFIED'):
            self.refresh()
        self.assertEqual((self.units / m.SERVICE).read_bytes(), self.after)
        self.assertFalse((self.checkpoint / 'complete.json').exists())
        authorized = self.validate()
        self.assertEqual(authorized, m.unit_profile(m.SERVICE))
        self.reload_error = False
        self.assertEqual(self.refresh(), authorized)
        self.assert_other_files_unchanged()

    def test_reload_no_success_without_manager_confirmation(self):
        self.reload_pending = True
        def runner(args):
            if args == [m.SYSTEMCTL, 'daemon-reload']:
                return 0, b''
            return self.runner(args)
        # Initial manager is loaded; only make it stale at publication.
        self.reload_pending = False
        real = m.exchange
        def exchange(a, b):
            real(a, b)
            self.reload_pending = True
        with mock.patch.object(m, 'exchange', side_effect=exchange), self.assertRaisesRegex(m.SafeError, 'MANAGER_STATE_UNVERIFIED'):
            self.refresh(runner=runner)
        self.assertFalse((self.checkpoint / 'complete.json').exists())

    def test_completion_journal_failure_leaves_target_explicitly_resumable(self):
        real = m.write_new
        def writer(path, data, mode=0o600):
            if path.name == 'complete.json':
                raise OSError('completion unavailable')
            return real(path, data, mode)
        with mock.patch.object(m, 'write_new', side_effect=writer), self.assertRaises(OSError):
            self.refresh()
        profile = self.validate()
        self.assertEqual(self.refresh(), profile)

    def test_explicit_resume_from_original_checkpoint_does_not_rewrite_intent(self):
        with mock.patch.object(m, 'exchange', side_effect=m.SafeError('CAPTURE_GUARD_ATOMIC_EXCHANGE_FAILED')):
            with self.assertRaises(m.SafeError):
                self.refresh()
        before = (self.checkpoint / 'before.service').stat()
        intent = (self.checkpoint / 'intent.json').read_bytes()
        self.assertEqual(self.validate(), self.original)
        self.refresh()
        self.assertEqual((self.checkpoint / 'intent.json').read_bytes(), intent)
        self.assertEqual((self.checkpoint / 'before.service').stat().st_ino, before.st_ino)

    def test_resume_rejects_tampered_checkpoint_and_unknown_target_inode(self):
        self.refresh()
        p = self.units / m.SERVICE
        temp = self.units / 'replacement'
        temp.write_bytes(self.after)
        temp.chmod(0o644)
        os.replace(temp, p)
        with self.assertRaisesRegex(m.SafeError, 'PUBLISHED_IDENTITY_UNVERIFIED'):
            self.validate()

    def test_beforeimage_corruption_cannot_authorize_target(self):
        self.refresh()
        (self.checkpoint / 'before.service').write_bytes(b'corrupted')
        with self.assertRaisesRegex(m.SafeError, 'CHECKPOINT_PROOF_INVALID'):
            self.validate()

    def test_other_unit_change_blocks_resume_even_with_valid_capture_checkpoint(self):
        self.refresh()
        (self.units / m.UNITS[2]).write_bytes(b'changed timer\n')
        with self.assertRaisesRegex(m.SafeError, 'OTHER_UNIT_CHANGED'):
            self.validate()

    def test_frozen_helper_or_original_profile_mismatch_is_rejected(self):
        self.legacy += b'\n'
        with self.assertRaisesRegex(m.SafeError, 'FROZEN_INSTALLER_MISMATCH'):
            self.validate()
        self.legacy = (ROOT / 'deployment/install_whatsapp_capture_root.sh').read_bytes()
        self.original = (*self.original[:7], '0' * 64)
        with self.assertRaisesRegex(m.SafeError, 'ORIGINAL_PROFILE_INVALID'):
            self.validate()

    def test_module_never_starts_stops_consumes_or_analyzes(self):
        self.refresh()
        self.assertTrue(all(c[1] in ('show', 'daemon-reload') for c in self.calls))
        self.assert_other_files_unchanged()


if __name__ == '__main__':
    unittest.main()
