#!/usr/bin/env python3
"""Exercise real atomic publication with temporary files and fake worker/DB seams."""
import hashlib
import importlib.util
import json
import os
import pathlib
import re
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('customer_confirmation_installer',
    ROOT/'deployment/apply_whatsapp_customer_confirmation.py')
repair = importlib.util.module_from_spec(spec); sys.modules[spec.name] = repair; spec.loader.exec_module(repair)


def shell_module(name, path):
    module = types.ModuleType(name); module.__file__ = str(path)
    source = path.read_text().split("<<'PY'\n", 1)[1].rsplit('\nPY\n', 1)[0]
    exec(compile(source, str(path), 'exec'), module.__dict__)
    return module


wrapper = shell_module('customer_confirmation_root_wrapper', ROOT/'deployment/apply_whatsapp_customer_confirmation_root.sh')
old_root = shell_module('customer_confirmation_old_root_library', ROOT/'deployment/apply_meta_ai_control_root.sh')


def reviewed_protocols():
    """Rebuild the independently reviewed additive CRM change, binding both bytes."""
    original = (ROOT/'app/Support/WhatsAppInboxProtocol.php').read_bytes()
    before = b"$report = ['messages' => [], 'quarantined_count' => 0, 'ignored_count' => 0, 'status_count' => 0];"
    after = b"$report = ['messages' => [], 'statuses' => [], 'quarantined_count' => 0, 'ignored_count' => 0, 'status_count' => 0];"
    anchor = b"                        $report['status_count']++;\n"
    extension = b"""                        // Receipts keep their own identity; never turn one into an inbox
                        // message or let an unscoped account update outbound records.
                        $timestamp = $status['timestamp'] ?? null;
                        if ((is_string($timestamp) || is_int($timestamp))
                            && preg_match('/\\A[0-9]{1,10}\\z/', (string) $timestamp)
                            && (int) $timestamp > 0 && (int) $timestamp <= min(2147483647, time() + 86400)) {
                            $report['statuses'][] = ['message_id' => $status['id'], 'status' => $status['status'],
                                'sent_at' => gmdate('Y-m-d H:i:s', (int) $timestamp),
                                'recipient_phone' => self::phone($status['recipient_id'] ?? null)];
                        }
"""
    if repair.blob(original) != 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd' or original.count(before) != 1 or original.count(anchor) != 1:
        raise AssertionError('Reviewed original protocol fixture changed')
    alternate = original.replace(before, after).replace(anchor, anchor+extension)
    if repair.blob(alternate) != 'f2eec9099f9a27915cda50b7c8e267efb1f0b030':
        raise AssertionError('Reviewed additive CRM protocol fixture changed')
    return original, alternate


def reviewed_installed_parser():
    current = (ROOT/'app/Support/WhatsAppCustomerOrderConfirmation.php').read_bytes()
    new = '(?:تاكد|تؤكد|ناكد)'.encode(); old = '(?:تاكد|تؤكد)'.encode()
    if repair.blob(current) != '63cd0fcd2d9056fb0e5732ddc22f4423ee349da7' or current.count(new) != 1:
        raise AssertionError('Reviewed footer-tolerance target changed')
    installed = current.replace(new, old)
    if repair.blob(installed) != '558440a772a7798c4f9c11a713685c79b7fdabda':
        raise AssertionError('Reviewed installed parser fixture changed')
    return installed


class Fixture:
    def __init__(self, folder):
        self.uid = os.geteuid(); self.root = pathlib.Path(folder)/'app'
        self.root.mkdir(mode=0o700); (self.root/'.git').mkdir(mode=0o700)
        (self.root.parent/'whatsapp-release-backups').mkdir(mode=0o700)
        self.calls = []; self.mode = 'ANALYZE_ONLY'; self.fail_lint = False; self.hook = None
        self.controls = {'present': True, 'fingerprint': 'c'*64, 'enabled': True, 'default_disabled': False}
        self.originals = {}; self.targets = {}; self.pins = {}
        for index, (path, (old, target)) in enumerate(repair.TARGET_PINS.items()):
            content = None if old is None else b'<?php /* original workflow */\n'
            new = ('<?php /* reviewed candidate '+str(index)+' */\n').encode()
            self.originals[path] = content; self.targets[path] = new
            self.pins[path] = (None if content is None else repair.blob(content), repair.blob(new))
            if content is not None: self.write(path, content)
        self.runtime = {}
        for index, path in enumerate(repair.RUNTIME_PINS):
            content = ('<?php /* installed dependency '+str(index)+' */\n').encode()
            self.write(path, content); self.runtime[path] = (repair.blob(content),)
        for path in repair.UNCHANGED_DEPENDENCIES:
            self.write(path, ('<?php /* newer independent CRM release '+path+' */\n').encode())
        self.write('.env', b'PRIVATE_TOKEN_MUST_NOT_CHANGE_OR_PRINT\n', 0o600)
        self.write('bootstrap/cache/config.php', b'<?php return ["cache"=>true];\n', 0o600)
        self.write('config/unrelated.php', b'<?php return ["independent"=>true];\n')
        self.unrelated = {
            'app/Services/Dashboard/CustomerCrm.php': b'<?php /* customer-owned CRM */\n',
            'resources/views/admin/partials/go_erp_logo.blade.php': b'<svg>private logo</svg>',
            'resources/views/site/home.blade.php': b'<main>new storefront</main>',
        }
        for path, content in self.unrelated.items(): self.write(path, content)

    def write(self, path, content, mode=0o644):
        destination = self.root/path; destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(content); destination.chmod(mode)

    def runner(self, root, args, capture=False, timeout=60):
        self.calls.append(args)
        if self.hook: self.hook(args)
        if args[:3] == ['git', 'cat-file', '-t']: return 0, b'commit\n'
        if args[:3] == ['git', 'ls-tree', '-z']:
            path = args[-1]
            return 0, b'100644 blob '+self.pins[path][1].encode()+b'\t'+path.encode()+b'\0'
        if args[:2] == ['git', 'show']: return 0, self.targets[args[-1].split(':', 1)[1]]
        if args[:2] == [repair.PHP, '-l']: return (1 if self.fail_lint else 0), b''
        if args[:3] == [repair.PHP, '-r', repair.library.CONTROL_STATE_PHP]:
            return 0, json.dumps(self.controls).encode()
        if args[:3] == [repair.PHP, '-r', repair.READINESS_PHP]:
            return ((0, json.dumps({'mode': self.mode, 'configuration_fingerprint': 'e'*64}).encode())
                    if self.mode == 'ANALYZE_ONLY' else (1, b'PRIVATE_MODE_OR_BOOTSTRAP_FAILURE'))
        if args[:2] == ['/usr/bin/systemctl', 'show']:
            text = 'LoadState=loaded\nActiveState=inactive\n'
            if args[-1].endswith('.service'): text += 'MainPID=0\n'
            return 0, text.encode()
        raise AssertionError('Unapproved installer command '+repr(args[:2]))

    def patches(self):
        return mock.patch.multiple(repair, TARGET_PINS=self.pins, RUNTIME_PINS=self.runtime)

    def installed_v1(self):
        self.pins = dict(repair.TARGET_PINS)
        self.targets = {path: (ROOT/path).read_bytes() for path in self.pins}
        parser, workflow = tuple(self.pins)
        self.originals = {parser: reviewed_installed_parser(), workflow: self.targets[workflow]}
        for path, content in self.originals.items(): self.write(path, content)

    def apply(self, check=False, **kwargs):
        with self.patches(): return repair.apply(self.root, self.uid, 'a'*40, check, runner=self.runner, **kwargs)

    def originals_intact(self, test):
        for path, content in self.originals.items():
            if content is None: test.assertFalse((self.root/path).exists())
            else: test.assertEqual((self.root/path).read_bytes(), content)


class WorkerFixture:
    def __init__(self):
        self.states = {name: 'inactive' for name in old_root.UNITS}
        self.states[old_root.UNITS[0]] = 'active'; self.states[old_root.UNITS[6]] = 'active'
        self.original = dict(self.states); self.commands = []; self.child_calls = []; self.fail = None
        self.before = {'sources': {path: None if i == 0 else 'a'*40 for i, path in enumerate(wrapper.SOURCE_ROSTER)},
            'configuration_fingerprint': 'b'*64, 'dependencies_fingerprint': 'd'*64,
            'control_fingerprint': 'c'*64, 'source_profile': 'BASELINE'}
        self.after = dict(self.before, sources={path: 'f'*40 for path in wrapper.SOURCE_ROSTER}, source_profile='CURRENT')
        self.library = types.SimpleNamespace(UNITS=old_root.UNITS, SYSTEMCTL=old_root.SYSTEMCTL,
            unit_state=lambda name, runner: old_root.unit_state(name, runner),
            unit_profile=lambda name: (name, 'same inode and hash'),
            owned_directory=lambda *args: None, write_receipt=old_root.write_receipt,
            PipelineReceipt=old_root.PipelineReceipt, wait_quiet=lambda runner: old_root.wait_quiet(runner),
            restore_timers=old_root.restore_timers, stop_all=old_root.stop_all)

    def runner(self, args, timeout=60):
        self.commands.append(args)
        if args[1] == 'show':
            name = args[-1]
            text = 'LoadState=loaded\nActiveState='+self.states[name]+'\n'
            # Root utility and publisher utility have different inventories.
            if '--property=FragmentPath' in args: text += 'FragmentPath='+str(old_root.SYSTEMD_DIR/name)+'\n'
            if name.endswith('.service'): text += 'MainPID=0\n'
            return 0, text.encode()
        if args[1] == 'stop':
            for name in args[2:]: self.states[name] = 'inactive'
            return 0, b''
        if args[1] == 'start': self.states[args[-1]] = 'active'; return 0, b''
        raise AssertionError('Unit installation/enable/daemon reload is forbidden: '+repr(args))

    def child(self, directory, release, uid, gid, locks, action, expected=None):
        self.child_calls.append(action)
        if action == self.fail: return {'ok': False, 'status': 'WA_CONFIRMATION_FIXTURE_STOPPED'}
        if action == 'preflight':
            return {'ok': True, 'result': dict(self.before, status='WA_CONFIRMATION_PREFLIGHT_READY', changed=True)}
        if action == 'state': return {'ok': True, 'result': dict(expected, status='WA_CONFIRMATION_STATE_READY')}
        return {'ok': True, 'result': dict(self.after, status='WHATSAPP_CUSTOMER_CONFIRMATION_REPAIR_READY', backup='/fixture')}

    def run(self, folder):
        with mock.patch.object(wrapper, 'ROOT', pathlib.Path(folder)/'app'):
            return wrapper.orchestrate(pathlib.Path(folder), 'a'*40, 0, 0, [], self.library, self.runner, self.child)


class CustomerConfirmationInstallTests(unittest.TestCase):
    def setUp(self): self.temp = tempfile.TemporaryDirectory(); self.fixture = Fixture(self.temp.name)
    def tearDown(self): self.temp.cleanup()

    def test_only_two_explicit_application_targets_and_no_config_or_unit_publication(self):
        self.assertEqual(tuple(repair.TARGET_PINS), wrapper.SOURCE_ROSTER)
        self.assertEqual(len(repair.TARGET_PINS), 2)
        self.assertTrue(all(path.startswith('app/') for path in repair.TARGET_PINS))
        self.assertFalse(set(repair.TARGET_PINS) & set(repair.UNCHANGED_DEPENDENCIES))
        self.assertEqual(repair.LIBRARY_BLOB, repair.blob((ROOT/'deployment/apply_meta_ai_control.py').read_bytes()))
        self.assertEqual(repair.BASE_BLOB, repair.blob((ROOT/'deployment/install_whatsapp_inbox.py').read_bytes()))
        self.assertEqual(wrapper.ROOT_LIBRARY_BLOB, repair.blob((ROOT/'deployment/apply_meta_ai_control_root.sh').read_bytes()))
        for path, (_, target) in repair.TARGET_PINS.items():
            self.assertEqual(target, repair.blob((ROOT/path).read_bytes()), 'reviewed target must bind exact final bytes')
        self.assertEqual(wrapper.PUBLISHER_BLOB, repair.blob((ROOT/'deployment/apply_whatsapp_customer_confirmation.py').read_bytes()))
        status = (ROOT/'deployment/whatsapp_customer_confirmation_status.php').read_text()
        entries = dict(re.findall(r"'([^']+)'=>'([^']+)'", re.search(r'\$pins=\[(.*?)\n    \];', status, re.S)[1]))
        for path, (_, target) in repair.TARGET_PINS.items():
            self.assertEqual(entries[path], target, 'status must guard same final published target')
        protocol = 'app/Support/WhatsAppInboxProtocol.php'
        self.assertNotIn(protocol, repair.TARGET_PINS)
        self.assertEqual(repair.RUNTIME_PINS[protocol],
            ('c64f8cf89437bb7822fc767202f7cf5112bfd7fd', 'f2eec9099f9a27915cda50b7c8e267efb1f0b030'))
        self.assertIn("$compatibleProtocolPins=['c64f8cf89437bb7822fc767202f7cf5112bfd7fd','f2eec9099f9a27915cda50b7c8e267efb1f0b030'];", status)
        self.assertEqual(set(repair.profiles()), {'BASELINE', 'INSTALLED_V1', 'CURRENT'})
        self.assertEqual(repair.INSTALLED_SOURCE_PROFILES, {'INSTALLED_V1': {
            'app/Support/WhatsAppCustomerOrderConfirmation.php': '558440a772a7798c4f9c11a713685c79b7fdabda',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': 'd91ea2f4c84357e16e0556b205484d64cfce1079'}})

    def test_installed_v1_upgrade_changes_only_parser_and_preserves_workflow_inode_and_state(self):
        self.fixture.installed_v1(); parser, workflow = tuple(repair.TARGET_PINS)
        before_workflow = repair.library.guarded_snapshot(self.fixture.root/workflow, self.fixture.uid, self.fixture.root)
        before_config = repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid)
        before_control = dict(self.fixture.controls)
        checked = self.fixture.apply(True)
        self.assertEqual(checked['source_profile'], 'INSTALLED_V1')
        result = self.fixture.apply()
        self.assertEqual(result['status'], 'WHATSAPP_CUSTOMER_CONFIRMATION_REPAIR_READY')
        self.assertEqual((self.fixture.root/parser).read_bytes(), self.fixture.targets[parser])
        self.assertEqual(repair.library.guarded_snapshot(self.fixture.root/workflow, self.fixture.uid, self.fixture.root), before_workflow)
        self.assertEqual(repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid), before_config)
        self.assertEqual(self.fixture.controls, before_control)
        receipt = json.loads((pathlib.Path(result['backup'])/'receipt.json').read_text())
        self.assertEqual(receipt['source_profile'], 'INSTALLED_V1')
        self.assertEqual(receipt['changed_targets'], [parser])
        self.assertEqual(self.fixture.apply()['status'], 'WA_CONFIRMATION_ALREADY_CURRENT')
        self.assertEqual(repair.library.guarded_snapshot(self.fixture.root/workflow, self.fixture.uid, self.fixture.root), before_workflow)

    def test_installed_profile_rejects_unknown_parser_and_unreviewed_parser_workflow_mixtures(self):
        self.fixture.installed_v1(); parser, workflow = tuple(repair.TARGET_PINS)
        variants = [
            (reviewed_installed_parser()+b'\n// unknown parser\n', self.fixture.targets[workflow]),
            (reviewed_installed_parser(), b'<?php /* unreviewed older workflow */'),
            (self.fixture.targets[parser], b'<?php /* unreviewed older workflow */'),
            (None, self.fixture.targets[workflow]),
        ]
        for parser_bytes, workflow_bytes in variants:
            with self.subTest(parser=None if parser_bytes is None else repair.blob(parser_bytes)):
                destination = self.fixture.root/parser
                if parser_bytes is None: destination.unlink(missing_ok=True)
                else: self.fixture.write(parser, parser_bytes)
                self.fixture.write(workflow, workflow_bytes)
                with self.assertRaisesRegex(repair.SafeError, 'WA_CONFIRMATION_LIVE_SOURCE_NEEDS_REVIEW'):
                    self.fixture.apply(True)

    def test_root_installed_v1_upgrade_preserves_original_timer_choices_and_policy_fingerprint(self):
        worker = WorkerFixture()
        worker.before = dict(worker.before, sources=repair.INSTALLED_SOURCE_PROFILES['INSTALLED_V1'], source_profile='INSTALLED_V1')
        worker.after = dict(worker.after, sources={path: pair[1] for path, pair in repair.TARGET_PINS.items()})
        result = worker.run(self.temp.name)
        self.assertEqual(result['status'], 'WHATSAPP_CUSTOMER_CONFIRMATION_REPAIR_READY')
        self.assertEqual(worker.states, worker.original)
        self.assertEqual(result['control_fingerprint'], worker.before['control_fingerprint'])
        self.assertFalse(any(args[1] in ('enable', 'daemon-reload') for args in worker.commands))

    def test_both_exact_reviewed_protocols_preflight_without_publishing_or_changing_them(self):
        protocol = 'app/Support/WhatsAppInboxProtocol.php'; allowed = repair.RUNTIME_PINS[protocol]
        self.fixture.runtime[protocol] = allowed
        for content in reviewed_protocols():
            with self.subTest(protocol=repair.blob(content)):
                self.fixture.write(protocol, content)
                before = repair.library.guarded_snapshot(self.fixture.root/protocol, self.fixture.uid, self.fixture.root)
                result = self.fixture.apply(True)
                self.assertEqual(result['status'], 'WA_CONFIRMATION_PREFLIGHT_READY')
                self.assertEqual(repair.library.guarded_snapshot(self.fixture.root/protocol, self.fixture.uid, self.fixture.root), before)
                self.fixture.originals_intact(self)

    def test_unknown_protocol_hash_is_rejected_even_next_to_the_reviewed_crm_extension(self):
        protocol = 'app/Support/WhatsAppInboxProtocol.php'
        self.fixture.runtime[protocol] = repair.RUNTIME_PINS[protocol]
        self.fixture.write(protocol, reviewed_protocols()[1]+b'\n// unreviewed mutation\n')
        with self.assertRaisesRegex(repair.SafeError, 'WA_CONFIRMATION_RUNTIME_SOURCE_MISMATCH'):
            self.fixture.apply(True)
        self.fixture.originals_intact(self)

    def test_full_publication_preserves_reviewed_crm_protocol_bytes_and_inode(self):
        protocol = 'app/Support/WhatsAppInboxProtocol.php'
        self.fixture.runtime[protocol] = repair.RUNTIME_PINS[protocol]
        self.fixture.write(protocol, reviewed_protocols()[1])
        before = repair.library.guarded_snapshot(self.fixture.root/protocol, self.fixture.uid, self.fixture.root)
        result = self.fixture.apply()
        self.assertEqual(result['status'], 'WHATSAPP_CUSTOMER_CONFIRMATION_REPAIR_READY')
        self.assertEqual(repair.library.guarded_snapshot(self.fixture.root/protocol, self.fixture.uid, self.fixture.root), before)
        receipt = json.loads((pathlib.Path(result['backup'])/'receipt.json').read_text())
        self.assertNotIn(protocol, receipt['publication_targets'])
        for path, content in self.fixture.targets.items():
            self.assertEqual((self.fixture.root/path).read_bytes(), content)

    def test_preflight_has_no_application_policy_order_or_service_mutation(self):
        before_config = repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid)
        result = self.fixture.apply(True)
        self.assertEqual(result['status'], 'WA_CONFIRMATION_PREFLIGHT_READY')
        self.fixture.originals_intact(self)
        self.assertEqual(repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid), before_config)
        self.assertFalse(any(args[0] == '/usr/bin/systemctl' for args in self.fixture.calls))
        self.assertFalse(any('artisan' in args for args in self.fixture.calls))

    def test_publish_preserves_newer_crm_logo_storefront_config_policy_and_hardlink_original(self):
        workflow = self.fixture.root/wrapper.SOURCE_ROSTER[1]
        original_alias = self.fixture.root.parent/'legacy-hardlink.php'; os.link(workflow, original_alias)
        config = repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid)
        controls = dict(self.fixture.controls)
        result = self.fixture.apply()
        self.assertEqual(result['status'], 'WHATSAPP_CUSTOMER_CONFIRMATION_REPAIR_READY')
        for path, content in self.fixture.targets.items(): self.assertEqual((self.fixture.root/path).read_bytes(), content)
        self.assertEqual(original_alias.read_bytes(), self.fixture.originals[wrapper.SOURCE_ROSTER[1]])
        for path, content in self.fixture.unrelated.items(): self.assertEqual((self.fixture.root/path).read_bytes(), content)
        self.assertEqual(repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid), config)
        self.assertEqual(self.fixture.controls, controls)
        receipt = json.loads((pathlib.Path(result['backup'])/'receipt.json').read_text())
        self.assertEqual(receipt['publication_targets'], list(wrapper.SOURCE_ROSTER))
        self.assertNotIn('PRIVATE_TOKEN', json.dumps(receipt))

    def test_repeat_is_noop_without_a_second_backup(self):
        self.fixture.apply(); paths = list((self.fixture.root.parent/'whatsapp-release-backups').iterdir())
        result = self.fixture.apply()
        self.assertEqual(result['status'], 'WA_CONFIRMATION_ALREADY_CURRENT')
        self.assertEqual(result['backup'], None)
        self.assertEqual(paths, list((self.fixture.root.parent/'whatsapp-release-backups').iterdir()))

    def test_unknown_workflow_and_unknown_dependency_fail_before_publication(self):
        for path in (wrapper.SOURCE_ROSTER[1], next(iter(self.fixture.runtime))):
            old = (self.fixture.root/path).read_bytes(); self.fixture.write(path, b'<?php /* unreviewed */')
            with self.assertRaisesRegex(repair.SafeError, 'SOURCE_NEEDS_REVIEW|SOURCE_MISMATCH'):
                self.fixture.apply()
            self.assertFalse((self.fixture.root/wrapper.SOURCE_ROSTER[0]).exists())
            self.fixture.write(path, old)

    def test_replies_mode_lint_failure_and_missing_control_never_publish(self):
        self.fixture.mode = 'REPLY_AND_ANALYZE'
        with self.assertRaisesRegex(repair.SafeError, 'ANALYSIS_ONLY_READINESS_FAILED'): self.fixture.apply()
        self.fixture.mode = 'ANALYZE_ONLY'; self.fixture.fail_lint = True
        with self.assertRaisesRegex(repair.SafeError, 'CANDIDATE_LINT_FAILED'): self.fixture.apply()
        self.fixture.fail_lint = False; self.fixture.controls['present'] = False; self.fixture.controls['fingerprint'] = None
        with self.assertRaisesRegex(repair.SafeError, 'EXISTING_CONTROL_REQUIRED'): self.fixture.apply()
        self.fixture.originals_intact(self)

    def test_post_preflight_config_or_policy_drift_requires_review(self):
        result = self.fixture.apply(True); expected = wrapper.expected_state(result)
        self.fixture.controls['fingerprint'] = 'f'*64
        with self.assertRaisesRegex(repair.SafeError, 'PREFLIGHT_STATE_CHANGED'):
            self.fixture.apply(expected=expected)
        self.fixture.originals_intact(self)

    def test_dependency_change_during_lint_never_gets_overwritten(self):
        changed = [False]; path = repair.UNCHANGED_DEPENDENCIES[0]
        def hook(args):
            if args[:2] == [repair.PHP, '-l'] and not changed[0]:
                changed[0] = True; self.fixture.write(path, b'<?php /* concurrent authorized CRM edit */')
        self.fixture.hook = hook
        with self.assertRaisesRegex(repair.SafeError, 'PUBLICATION_STATE_CHANGED'): self.fixture.apply()
        self.assertIn(b'concurrent authorized', (self.fixture.root/path).read_bytes())
        self.fixture.originals_intact(self)

    def test_partial_publication_restores_bytes_and_requires_pipeline_review(self):
        original = repair.base.replace_file; count = [0]
        def fail_second(*args, **kwargs):
            count[0] += 1
            if count[0] == 2: raise RuntimeError('PRIVATE_INTERNAL_FAILURE')
            return original(*args, **kwargs)
        with mock.patch.object(repair.base, 'replace_file', fail_second):
            with self.assertRaisesRegex(repair.SafeError, 'ROLLBACK_REVIEW_REQUIRED; BACKUP=') as result:
                self.fixture.apply()
        self.assertNotIn('PRIVATE', str(result.exception)); self.fixture.originals_intact(self)

    def test_restart_guard_binds_sources_dependencies_configuration_and_control(self):
        result = self.fixture.apply(); expected = wrapper.expected_state(result)
        with self.fixture.patches():
            observed = repair.verify_state(self.fixture.root, self.fixture.uid, expected, runner=self.fixture.runner)
        self.assertEqual(observed['status'], 'WA_CONFIRMATION_STATE_READY')
        self.fixture.write(repair.UNCHANGED_DEPENDENCIES[0], b'<?php /* later CRM edit */')
        with self.fixture.patches(), self.assertRaisesRegex(repair.SafeError, 'RESTART_STATE_CHANGED'):
            repair.verify_state(self.fixture.root, self.fixture.uid, expected, runner=self.fixture.runner)

    def test_pipeline_restore_preserves_original_timer_choices_and_never_installs_units(self):
        worker = WorkerFixture(); result = worker.run(self.temp.name)
        self.assertEqual(result['status'], 'WHATSAPP_CUSTOMER_CONFIRMATION_REPAIR_READY')
        self.assertEqual(worker.states, worker.original)
        self.assertEqual([args[1] for args in worker.commands if args[1] != 'show'], ['stop', 'start', 'start'])

    def test_preflight_failure_does_not_touch_workers(self):
        worker = WorkerFixture(); worker.fail = 'preflight'
        with self.assertRaisesRegex(wrapper.SafeError, 'FIXTURE_STOPPED'): worker.run(self.temp.name)
        self.assertEqual(worker.commands, [])

    def test_publication_failure_leaves_all_known_pipelines_stopped(self):
        worker = WorkerFixture(); worker.fail = 'apply'
        with self.assertRaisesRegex(wrapper.SafeError, 'PIPELINES_PAUSED; PIPELINE_BACKUP='):
            worker.run(self.temp.name)
        self.assertTrue(all(state == 'inactive' for state in worker.states.values()))
        self.assertFalse(any(args[1] == 'start' for args in worker.commands))


if __name__ == '__main__': unittest.main()
