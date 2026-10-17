#!/usr/bin/env python3
"""Exercise native replay source publication without bootstrapping the application."""
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
spec = importlib.util.spec_from_file_location('unavailable_replay_installer',
    ROOT/'deployment/apply_whatsapp_unavailable_replay.py')
repair = importlib.util.module_from_spec(spec); sys.modules[spec.name] = repair; spec.loader.exec_module(repair)


def shell_module(name, relative):
    path = ROOT/relative; module = types.ModuleType(name); module.__file__ = str(path)
    source = path.read_text().split("<<'PY'\n", 1)[1].rsplit('\nPY\n', 1)[0]
    exec(compile(source, str(path), 'exec'), module.__dict__)
    return module


wrapper = shell_module('unavailable_replay_root_wrapper', 'deployment/apply_whatsapp_unavailable_replay_root.sh')
root_library = shell_module('unavailable_replay_root_library', 'deployment/apply_meta_ai_control_root.sh')


class Fixture:
    def __init__(self, folder):
        self.uid = os.geteuid(); self.root = pathlib.Path(folder)/'app'; self.root.mkdir(mode=0o700)
        (self.root/'.git').mkdir(mode=0o700); (self.root.parent/'whatsapp-release-backups').mkdir(mode=0o700)
        self.calls = []; self.fail_lint = False; self.hook = None; self.tree_mode = b'100644'; self.bad_blob = False
        self.originals = {}; self.targets = {}; self.pins = {}
        for index, (path, (old, target)) in enumerate(repair.TARGET_PINS.items()):
            previous = None if old is None else b'<?php /* installed consumer with independent CRM hooks */\n'
            candidate = ('<?php /* reviewed native replay candidate '+str(index)+' */\n').encode()
            self.originals[path] = previous; self.targets[path] = candidate
            self.pins[path] = (None if previous is None else repair.blob(previous), repair.blob(candidate))
            if previous is not None: self.write(path, previous)
        for index, path in enumerate(repair.UNCHANGED_DEPENDENCIES):
            self.write(path, ('<?php /* independently installed '+str(index)+' */\n').encode(), 0o600)
        self.write('.env', b'PRIVATE_TOKEN_MUST_NOT_CHANGE_OR_PRINT\n', 0o600)
        self.write('bootstrap/cache/config.php', b'<?php return ["cache"=>true];\n', 0o600)
        self.write('config/unrelated.php', b'<?php return ["independent"=>true];\n')
        self.write('resources/views/site/home.blade.php', b'<main>independent storefront</main>')

    def write(self, relative, data, mode=0o644):
        path = self.root/relative; path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(data); path.chmod(mode)

    def runner(self, root, args, capture=False, timeout=60):
        self.calls.append(args)
        if self.hook: self.hook(args)
        if args[:3] == ['git', 'cat-file', '-t']: return 0, b'commit\n'
        if args[:3] == ['git', 'ls-tree', '-z']:
            path = args[-1]; return 0, self.tree_mode+b' blob '+self.pins[path][1].encode()+b'\t'+path.encode()+b'\0'
        if args[:2] == ['git', 'show']:
            path = args[-1].split(':', 1)[1]; return 0, b'unreviewed' if self.bad_blob else self.targets[path]
        if args[:2] == [repair.PHP, '-l']: return (1 if self.fail_lint else 0), b''
        if args[:2] == ['/usr/bin/systemctl', 'show']:
            value = 'LoadState=loaded\nActiveState=inactive\n'
            if args[-1].endswith('.service'): value += 'MainPID=0\n'
            return 0, value.encode()
        raise AssertionError('Bootstrap, database, replay and order commands are forbidden: '+repr(args[:3]))

    def patches(self): return mock.patch.object(repair, 'TARGET_PINS', self.pins)

    def apply(self, check=False, **kwargs):
        with self.patches(): return repair.apply(self.root, self.uid, 'a'*40, check, runner=self.runner, **kwargs)

    def originals_intact(self, test):
        for path, data in self.originals.items():
            if data is None: test.assertFalse((self.root/path).exists())
            else: test.assertEqual((self.root/path).read_bytes(), data)

    def protected(self):
        return {path: repair.library.guarded_snapshot(self.root/path, self.uid, self.root)
                for path in (*repair.UNCHANGED_DEPENDENCIES, 'resources/views/site/home.blade.php')}


class WorkerFixture:
    def __init__(self):
        self.states = {name: 'inactive' for name in root_library.UNITS}
        self.states[root_library.UNITS[0]] = 'active'; self.states[root_library.UNITS[6]] = 'active'
        self.original = dict(self.states); self.commands = []; self.child_calls = []; self.fail = None
        self.before = {'sources': {path: None if i == 0 else 'a'*40 for i, path in enumerate(wrapper.SOURCE_ROSTER)},
            'configuration_fingerprint': 'b'*64, 'dependencies_fingerprint': 'd'*64, 'source_profile': 'BASELINE'}
        self.after = dict(self.before, sources={path: 'f'*40 for path in wrapper.SOURCE_ROSTER}, source_profile='CURRENT')
        self.library = types.SimpleNamespace(UNITS=root_library.UNITS, SYSTEMCTL=root_library.SYSTEMCTL,
            unit_state=lambda name, runner: root_library.unit_state(name, runner),
            unit_profile=lambda name: (name, 'same inode and hash'), owned_directory=lambda *args: None,
            write_receipt=root_library.write_receipt, PipelineReceipt=root_library.PipelineReceipt,
            wait_quiet=lambda runner: root_library.wait_quiet(runner), restore_timers=root_library.restore_timers,
            stop_all=root_library.stop_all)

    def runner(self, args, timeout=60):
        self.commands.append(args)
        if args[1] == 'show':
            name = args[-1]; text = 'LoadState=loaded\nActiveState='+self.states[name]+'\n'
            if '--property=FragmentPath' in args: text += 'FragmentPath='+str(root_library.SYSTEMD_DIR/name)+'\n'
            if name.endswith('.service'): text += 'MainPID=0\n'
            return 0, text.encode()
        if args[1] == 'stop':
            for name in args[2:]: self.states[name] = 'inactive'
            return 0, b''
        if args[1] == 'start': self.states[args[-1]] = 'active'; return 0, b''
        raise AssertionError('No install, enable, daemon-reload, database or replay command: '+repr(args))

    def child(self, directory, release, uid, gid, locks, action, expected=None):
        self.child_calls.append(action)
        if action == self.fail: return {'ok': False, 'status': 'WA_UNAVAILABLE_REPLAY_FIXTURE_STOPPED'}
        if action == 'preflight':
            return {'ok': True, 'result': dict(self.before, status='WA_UNAVAILABLE_REPLAY_PREFLIGHT_READY', changed=True)}
        if action == 'state': return {'ok': True, 'result': dict(expected, status='WA_UNAVAILABLE_REPLAY_STATE_READY')}
        return {'ok': True, 'result': dict(self.after, status='WHATSAPP_UNAVAILABLE_REPLAY_REPAIR_READY', backup='/fixture')}

    def run(self, folder):
        with mock.patch.object(wrapper, 'ROOT', pathlib.Path(folder)/'app'):
            return wrapper.orchestrate(pathlib.Path(folder), 'a'*40, 0, 0, [], self.library, self.runner, self.child)


class UnavailableReplayInstallTests(unittest.TestCase):
    def setUp(self): self.temp = tempfile.TemporaryDirectory(); self.fixture = Fixture(self.temp.name)
    def tearDown(self): self.temp.cleanup()

    def test_exact_two_targets_and_pinned_helpers_without_runtime_bootstrap(self):
        self.assertEqual(tuple(repair.TARGET_PINS), wrapper.SOURCE_ROSTER)
        self.assertEqual(tuple(repair.TARGET_PINS), (
            'app/Services/Dashboard/WhatsAppNativeUnavailableReplay.php', 'app/Services/Dashboard/WhatsAppInboxConsumer.php'))
        self.assertIsNone(repair.TARGET_PINS[wrapper.SOURCE_ROSTER[0]][0])
        self.assertEqual(repair.TARGET_PINS[wrapper.SOURCE_ROSTER[1]][0], '705734c707b3dcee53ac66067fa52d0291eda39a')
        self.assertFalse(set(repair.TARGET_PINS) & set(repair.UNCHANGED_DEPENDENCIES))
        for path, (_, pin) in repair.TARGET_PINS.items():
            self.assertRegex(pin, r'\A[a-f0-9]{40}\Z')
            self.assertEqual(pin, repair.blob((ROOT/path).read_bytes()))
        self.assertEqual(wrapper.PUBLISHER_BLOB, repair.blob((ROOT/'deployment/apply_whatsapp_unavailable_replay.py').read_bytes()))
        self.assertEqual(wrapper.ROOT_LIBRARY_BLOB, repair.blob((ROOT/'deployment/apply_meta_ai_control_root.sh').read_bytes()))
        self.assertEqual(repair.LIBRARY_BLOB, repair.blob((ROOT/'deployment/apply_meta_ai_control.py').read_bytes()))
        self.assertEqual(repair.BASE_BLOB, repair.blob((ROOT/'deployment/install_whatsapp_inbox.py').read_bytes()))
        source = (ROOT/'deployment/apply_whatsapp_unavailable_replay.py').read_text()
        for forbidden in ('bootstrap/app.php', 'vendor/autoload.php', 'control_state(', 'artisan', 'READINESS_PHP'):
            self.assertNotIn(forbidden, source)
        self.assertEqual(set(repair.profiles()), {'BASELINE', 'CURRENT'})

    def test_preflight_only_reads_and_lints_without_application_or_pipeline_mutation(self):
        before = self.fixture.protected(); config = repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid)
        result = self.fixture.apply(True)
        self.assertEqual(result['status'], 'WA_UNAVAILABLE_REPLAY_PREFLIGHT_READY')
        self.fixture.originals_intact(self); self.assertEqual(self.fixture.protected(), before)
        self.assertEqual(repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid), config)
        self.assertFalse(any(args[0] == '/usr/bin/systemctl' for args in self.fixture.calls))

    def test_publication_orders_proof_before_consumer_and_preserves_independent_sources_mode_inode(self):
        before = self.fixture.protected(); config = repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid)
        original = repair.base.replace_file; calls = []
        def record(*args, **kwargs):
            calls.append(kwargs['relative']); return original(*args, **kwargs)
        with mock.patch.object(repair.base, 'replace_file', record): result = self.fixture.apply()
        self.assertEqual(result['status'], 'WHATSAPP_UNAVAILABLE_REPLAY_REPAIR_READY')
        self.assertEqual(calls, list(wrapper.SOURCE_ROSTER)); self.assertEqual(self.fixture.protected(), before)
        self.assertEqual(repair.library.configuration_snapshot(self.fixture.root, self.fixture.uid), config)
        receipt = json.loads((pathlib.Path(result['backup'])/'receipt.json').read_text())
        self.assertEqual(receipt['publication_targets'], list(wrapper.SOURCE_ROSTER))
        self.assertEqual(receipt['changed_targets'], list(wrapper.SOURCE_ROSTER))
        self.assertNotIn('PRIVATE_TOKEN', json.dumps(receipt))
        for path in repair.UNCHANGED_DEPENDENCIES: self.assertEqual((self.fixture.root/path).stat().st_mode & 0o7777, 0o600)

    def test_repeat_preserves_current_target_bytes_inodes_and_creates_no_second_backup(self):
        self.fixture.apply(); before = {path: repair.library.guarded_snapshot(self.fixture.root/path,
            self.fixture.uid, self.fixture.root) for path in repair.TARGET_PINS}
        backups = list((self.fixture.root.parent/'whatsapp-release-backups').iterdir())
        result = self.fixture.apply(); self.assertEqual(result['status'], 'WA_UNAVAILABLE_REPLAY_ALREADY_CURRENT')
        self.assertIsNone(result['backup']); self.assertEqual(backups, list((self.fixture.root.parent/'whatsapp-release-backups').iterdir()))
        for path in repair.TARGET_PINS:
            self.assertEqual(repair.library.guarded_snapshot(self.fixture.root/path, self.fixture.uid, self.fixture.root), before[path])

    def test_private_consumer_mode_and_legacy_hardlink_original_are_preserved(self):
        consumer = wrapper.SOURCE_ROSTER[1]; current = self.fixture.root/consumer; current.chmod(0o600)
        alias = self.fixture.root.parent/'legacy-consumer.php'; os.link(current, alias)
        previous_inode = alias.stat().st_ino; previous = alias.read_bytes()
        result = self.fixture.apply()
        self.assertEqual(result['status'], 'WHATSAPP_UNAVAILABLE_REPLAY_REPAIR_READY')
        self.assertEqual(alias.read_bytes(), previous); self.assertEqual(alias.stat().st_ino, previous_inode)
        self.assertEqual(current.read_bytes(), self.fixture.targets[consumer])
        self.assertEqual(alias.stat().st_mode & 0o7777, 0o600)
        self.assertEqual(current.stat().st_mode & 0o7777, 0o600)

    def test_unknown_or_mixed_source_profiles_fail_before_publication(self):
        proof, consumer = wrapper.SOURCE_ROSTER
        variants = [(b'<?php /* unknown proof */', self.fixture.originals[consumer]),
            (None, b'<?php /* unknown consumer */'), (self.fixture.targets[proof], self.fixture.originals[consumer]),
            (None, self.fixture.targets[consumer])]
        for proof_data, consumer_data in variants:
            with self.subTest(proof=None if proof_data is None else repair.blob(proof_data)):
                if proof_data is None: (self.fixture.root/proof).unlink(missing_ok=True)
                else: self.fixture.write(proof, proof_data)
                self.fixture.write(consumer, consumer_data)
                with self.assertRaisesRegex(repair.SafeError, 'LIVE_SOURCE_NEEDS_REVIEW'): self.fixture.apply(True)

    def test_unknown_git_tree_mode_or_changed_candidate_blob_fails_before_publication(self):
        self.fixture.tree_mode = b'100755'
        with self.assertRaisesRegex(repair.SafeError, 'RELEASE_TREE_MISMATCH'): self.fixture.apply()
        self.fixture.tree_mode = b'100644'; self.fixture.bad_blob = True
        with self.assertRaisesRegex(repair.SafeError, 'RELEASE_BLOB_MISMATCH'): self.fixture.apply()
        self.fixture.originals_intact(self)

    def test_candidate_lint_failure_never_publishes(self):
        self.fixture.fail_lint = True
        with self.assertRaisesRegex(repair.SafeError, 'CANDIDATE_LINT_FAILED'): self.fixture.apply()
        self.fixture.originals_intact(self)

    def test_config_or_dependency_drift_since_preflight_fails_before_publication(self):
        before = self.fixture.apply(True)
        self.fixture.write(repair.UNCHANGED_DEPENDENCIES[0], b'<?php /* independently changed */', 0o600)
        with self.assertRaisesRegex(repair.SafeError, 'PREFLIGHT_STATE_CHANGED'):
            self.fixture.apply(expected=wrapper.expected_state(before))
        self.fixture.originals_intact(self)

    def test_dependency_change_during_lint_is_preserved_and_blocks_publication(self):
        changed = [False]; path = repair.UNCHANGED_DEPENDENCIES[0]
        def hook(args):
            if args[:2] == [repair.PHP, '-l'] and not changed[0]:
                changed[0] = True; self.fixture.write(path, b'<?php /* concurrent independent CRM update */', 0o600)
        self.fixture.hook = hook
        with self.assertRaisesRegex(repair.SafeError, 'PUBLICATION_STATE_CHANGED'): self.fixture.apply()
        self.fixture.originals_intact(self); self.assertIn(b'concurrent independent', (self.fixture.root/path).read_bytes())

    def test_unsafe_dependency_symlink_fails_before_publication(self):
        relative = repair.UNCHANGED_DEPENDENCIES[0]; path = self.fixture.root/relative
        path.unlink(); path.symlink_to(self.fixture.root/'.env')
        with self.assertRaises((repair.SafeError, OSError)): self.fixture.apply()
        self.fixture.originals_intact(self)

    def test_partial_publication_rolls_back_without_overwriting_independent_files(self):
        before = self.fixture.protected(); original = repair.base.replace_file; count = [0]
        def fail_second(*args, **kwargs):
            count[0] += 1
            if count[0] == 2: raise RuntimeError('PRIVATE_INTERNAL_FAILURE')
            return original(*args, **kwargs)
        with mock.patch.object(repair.base, 'replace_file', fail_second):
            with self.assertRaisesRegex(repair.SafeError, 'ROLLBACK_REVIEW_REQUIRED; BACKUP=') as result: self.fixture.apply()
        self.assertNotIn('PRIVATE', str(result.exception)); self.fixture.originals_intact(self)
        self.assertEqual(self.fixture.protected(), before)

    def test_restart_guard_binds_sources_config_and_all_observed_dependencies(self):
        result = self.fixture.apply(); expected = wrapper.expected_state(result)
        with self.fixture.patches():
            self.assertEqual(repair.verify_state(self.fixture.root, self.fixture.uid, expected,
                runner=self.fixture.runner)['status'], 'WA_UNAVAILABLE_REPLAY_STATE_READY')
        self.fixture.write('config/unrelated.php', b'<?php return ["new"=>true];')
        with self.fixture.patches(), self.assertRaisesRegex(repair.SafeError, 'RESTART_STATE_CHANGED'):
            repair.verify_state(self.fixture.root, self.fixture.uid, expected, runner=self.fixture.runner)

    def test_root_restores_original_timer_choices_and_never_installs_or_activates_services(self):
        worker = WorkerFixture(); result = worker.run(self.temp.name)
        self.assertEqual(result['status'], 'WHATSAPP_UNAVAILABLE_REPLAY_REPAIR_READY')
        self.assertEqual(worker.states, worker.original)
        actions = [args[1:] for args in worker.commands if args[1] != 'show']
        self.assertEqual(actions[0], ['stop', *[name for name in root_library.UNITS if name.endswith('.timer')]])
        self.assertTrue(all(args[0] != 'start' or args[-1].endswith('.timer') for args in actions))
        self.assertFalse(any(args[0] in ('enable', 'daemon-reload') for args in actions))

    def test_root_preflight_failure_does_not_pause_workers(self):
        worker = WorkerFixture(); worker.fail = 'preflight'
        with self.assertRaisesRegex(wrapper.SafeError, 'FIXTURE_STOPPED'): worker.run(self.temp.name)
        self.assertEqual(worker.commands, [])

    def test_root_publication_failure_leaves_uncertain_pipelines_paused(self):
        worker = WorkerFixture(); worker.fail = 'apply'
        with self.assertRaisesRegex(wrapper.SafeError, 'PIPELINES_PAUSED; PIPELINE_BACKUP='): worker.run(self.temp.name)
        self.assertTrue(all(value == 'inactive' for value in worker.states.values()))
        self.assertFalse(any(args[1] == 'start' for args in worker.commands))


if __name__ == '__main__': unittest.main()
