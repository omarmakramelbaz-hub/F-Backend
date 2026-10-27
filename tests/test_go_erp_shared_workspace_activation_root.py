"""Actual temporary inode/WAL tests; process, FPM and HTTPS effects are fakes."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import re
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, REPO / path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


subject = module('activation', 'deployment/go_erp_shared_workspace_activation_root.py')
source = module('activation_source', 'deployment/go_erp_shared_workspace_source_root.py')
plan = module('activation_plan', 'deployment/go_erp_shared_workspace_plan.py')


class Crash(BaseException):
    pass


class FakeProcesses:
    def __init__(self):
        self.workers = [{'pid': 100, 'ppid': 42, 'start': 17}]
        self.finished = True
        self.seen = []

    def capture(self, master):
        assert master == 42
        return copy.deepcopy(self.workers)

    def gone(self, workers):
        self.seen.append(copy.deepcopy(workers))
        return self.finished


class FakeRunner:
    def __init__(self, root, routes):
        self.root, self.routes = root, routes
        self.calls = []
        self.fail = False
        self.crash_reload = False

    def write_cache(self, enabled):
        path = self.root / 'bootstrap/cache/config.php'
        stage = path.with_name('fresh-config.php')
        stage.write_bytes(b'config:' + (b'true' if enabled else b'false'))
        stage.chmod(0o644)
        os.replace(stage, path)

    def php(self, program, values, **kwargs):
        assert program == subject.CACHE_PROGRAM
        assert kwargs['uid'] == 0 and kwargs['gid'] == 0
        assert kwargs['cwd'] == str(self.root)
        self.calls.append(('php', values['action']))
        if self.fail:
            return {'returncode': 43, 'stdout': b'private failure', 'stderr': b'secret traceback'}
        if values['action'] == 'config':
            self.write_cache(subject.env_enabled((self.root / '.env').read_bytes()))
        if values['action'] == 'routes':
            path = self.root / 'bootstrap/cache/routes-v7.php'
            path.write_bytes(b'reviewed routes');path.chmod(0o644)
        path = self.root / 'bootstrap/cache/config.php'
        value = {'workspace_enabled': path.read_bytes() == b'config:true', 'local': False,
            'config_sha256': subject.sha(path.read_bytes()), 'config_path': str(path),
            'routes_path': str(self.root / 'bootstrap/cache/routes-v7.php'),
            'routes_cached': (self.root / 'bootstrap/cache/routes-v7.php').exists(), 'routes': self.routes}
        return {'returncode': 0, 'stdout': json.dumps(value).encode(), 'stderr': b''}

    def systemctl(self, verb):
        assert verb == 'reload'
        self.calls.append(('systemctl', verb))
        if self.crash_reload:
            raise Crash()
        return {'returncode': 0, 'stdout': b'', 'stderr': b''}


class FakeHTTPS:
    def __init__(self, root):
        self.root, self.bad, self.calls = root, False, []

    def get(self, path, token):
        assert re.fullmatch(r'/go-erp-readiness-[a-f0-9]{32}\.php', path)
        self.calls.append((path, token))
        raw = (self.root / 'public' / path[1:]).read_text()
        assert subject.sha(token.encode()) in raw
        nonce = re.search(r"'nonce'=>'([a-f0-9]{64})'", raw)[1]
        cache = (self.root / 'bootstrap/cache/config.php').read_bytes()
        return {'nonce': nonce if not self.bad else '0' * 64,
            'workspace_enabled': cache == b'config:true', 'local': False, 'cache_sha256': subject.sha(cache)}


class ActivationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name) / 'app';self.root.mkdir(mode=0o755)
        self.private = Path(self.temp.name) / 'private';self.private.mkdir(mode=0o700)
        for name in ('public', 'bootstrap', 'bootstrap/cache', 'app'):
            (self.root / name).mkdir(mode=0o755, exist_ok=True)
        self.raw = b'APP_KEY="private literal $value"\r\n# retain comment\r\nDESKTOP_DASHBOARD_WORKSPACE_ENABLED=false\r\n'
        self.env = self.root / '.env';self.env.write_bytes(self.raw);self.env.chmod(0o600)
        self.file = self.root / 'app/reviewed.php';self.file.write_bytes(b'<?php // reviewed source\n');self.file.chmod(0o644)
        self.routes = [['admin/desktop-dashboard/workspace/challenge', ['GET', 'HEAD'], 'ReviewedController@challenge']]
        self.handler = {'configuration': {'documentroot': str(self.root / 'public'), 'files': {'pool': 'a' * 64}},
            'live': {'master_pid': 42, 'socket_inode': '77'}}
        self.pins = {'module_sha256': 'a' * 64, 'plan_sha256': 'b' * 64, 'pins_sha256': 'c' * 64,
            'binding': {'env_before_sha256': subject.sha(self.raw),
                'env_enabled_sha256': subject.sha(plan.workspace_env_bytes(self.raw, enabled=True)),
                'env_unchanged_bytes_sha256': subject.sha(plan.workspace_env_bytes(self.raw, enabled=False)),
                'php_handler_configuration_sha256': subject.digest(self.handler['configuration']),
                'source_manifest_sha256': 'd' * 64, 'route_cache_required': False},
            'source_files': {'app/reviewed.php': subject.sha(self.file.read_bytes())},
            'routes': self.routes, 'route_cache_path': 'bootstrap/cache/routes-v7.php'}
        self.runner = FakeRunner(self.root, self.routes);self.runner.write_cache(False)
        self.https = FakeHTTPS(self.root);self.processes = FakeProcesses()
        self.lock = True
        self.patches = [patch.object(subject, 'ROOT', self.root), patch.object(subject, 'PRIVATE', self.private),
            patch.object(subject, 'APP_UID', 0), patch.object(subject, 'APP_GID', 0),
            patch.object(subject.socket, 'gethostname', return_value='srv1836524')]
        for item in self.patches:item.start()
        self.engine = self.make()

    def make(self, pins=True):
        return subject.Activation(source, plan, self.runner, lambda: copy.deepcopy(self.handler),
            lambda: self.lock, compiled_pins=self.pins if pins else None, https=self.https,
            processes=self.processes, pause=lambda _seconds: None)

    def tearDown(self):
        for item in reversed(self.patches):item.stop()
        self.temp.cleanup()

    def action(self, name, facts=None):
        result = self.engine._internal(name, {'binding': self.pins['binding'],
            'activation_intent_sha256': 'e' * 64, 'action_intent_sha256': 'f' * 64,
            'false_flag_https_receipt_sha256': getattr(self, 'readiness', '0' * 64),
            'facts': facts or {}})
        return result

    def ready(self):
        self.readiness = self.engine.invoke(self.action('https_readiness'))['private_receipt_sha256']

    def activate_cache(self):
        self.ready();self.engine.invoke(self.action('activation_flag'))
        return self.engine.invoke(self.action('activation_cache'))

    def test_unknown_final_pins_block_all_files_and_commands(self):
        before = list(self.private.iterdir())
        with self.assertRaisesRegex(subject.Stop, 'FINAL_PINS_UNAVAILABLE'):
            self.make(False).invoke(self.action('activation_flag'))
        self.assertEqual(before, list(self.private.iterdir()));self.assertEqual([], self.runner.calls)

    def test_missing_false_https_receipt_prevents_flag_write(self):
        with self.assertRaisesRegex(subject.Stop, 'FALSE_FLAG_READINESS_REQUIRED'):
            self.engine.invoke(self.action('activation_flag'))
        self.assertEqual(self.raw, self.env.read_bytes())

    def test_https_false_attests_actual_cache_and_always_removes_owned_probe(self):
        self.ready()
        self.assertEqual([], list((self.root / 'public').iterdir()))
        self.assertEqual(self.raw, self.env.read_bytes());self.assertEqual(1, len(self.https.calls))
        self.assertTrue((self.private / ('readiness-' + self.readiness + '.json')).is_file())

    def test_failed_https_challenge_does_not_leave_executable_probe(self):
        self.https.bad = True
        with self.assertRaisesRegex(subject.Stop, 'HTTPS_SERVED_CONFIGURATION_CHANGED'):
            self.ready()
        self.assertEqual([], list((self.root / 'public').iterdir()))

    def test_env_only_flag_changes_and_original_metadata_is_retained(self):
        self.ready();before = self.env.stat()
        receipt = self.engine.invoke(self.action('activation_flag'))
        self.assertEqual(plan.workspace_env_bytes(self.raw, enabled=True), self.env.read_bytes())
        after = self.env.stat()
        self.assertEqual((before.st_uid, before.st_gid, before.st_mode & 0o777),
            (after.st_uid, after.st_gid, after.st_mode & 0o777))
        self.assertNotEqual(before.st_ino, after.st_ino)
        self.assertTrue(receipt['result']['only_workspace_flag_changed'])

    def test_completed_flag_recovery_does_not_replace_inode_twice(self):
        self.ready();action = self.action('activation_flag');receipt = self.engine.invoke(action)
        inode = self.env.stat().st_ino
        result = self.engine.recover(action)
        self.assertEqual('complete', result['status']);self.assertEqual(receipt, result['receipt'])
        self.assertEqual(inode, self.env.stat().st_ino)

    def test_owned_staged_env_resumes_before_rename_without_recreating_inode(self):
        self.ready();action=self.action('activation_flag')
        with patch.object(subject.os,'replace',side_effect=Crash):
            with self.assertRaises(Crash):self.engine.invoke(action)
        folder=self.engine._folder(action)
        staged=self.engine._load(folder/'staged.json')['inode']
        self.assertEqual(self.raw,self.env.read_bytes())
        result=self.engine.recover(action)
        self.assertEqual('complete',result['status'])
        self.assertEqual(staged,source.inode(self.env.stat()))

    def test_false_env_staged_resume_when_original_already_false_and_early_source_rollback(self):
        with patch.object(subject.os,'replace',side_effect=Crash):
            with self.assertRaises(Crash):self.engine.prepare_env_disabled()
        self.engine.prepare_env_disabled(recovering=True)
        self.file.write_bytes(b'<?php // restored original referrer\n')
        # No target-source/cache preparation has completed. The env-only WAL
        # still owns the original bytes and permits the real rollback barrier.
        pending=self.barrier_pending()
        proof=self.engine.addition_removal_barrier(pending,lambda:pending['restored_referrers_sha256'])
        self.assertTrue(proof['drained_before_addition_removal'])
        self.assertEqual(self.raw,self.env.read_bytes())

    def test_staged_environment_peer_inode_or_unreceipted_file_is_never_adopted(self):
        self.ready();action=self.action('activation_flag')
        with patch.object(subject.os,'replace',side_effect=Crash):
            with self.assertRaises(Crash):self.engine.invoke(action)
        folder=self.engine._folder(action);pending=self.engine._load(folder/'pending.json')
        stage=self.env.with_name(pending['stage']);peer=stage.with_name('peer')
        peer.write_bytes(stage.read_bytes());peer.chmod(0o600);os.replace(peer,stage)
        self.assertEqual('unknown',self.engine.recover(action)['status'])
        self.assertEqual(self.raw,self.env.read_bytes())

    def test_same_bytes_peer_inode_is_not_adopted_on_recovery(self):
        self.ready();action = self.action('activation_flag');self.engine.invoke(action)
        raw = self.env.read_bytes();other = self.env.with_name('peer.env');other.write_bytes(raw)
        other.chmod(0o600);os.replace(other, self.env)
        self.assertEqual('unknown', self.engine.recover(action)['status'])

    def test_unrelated_env_edit_is_never_overwritten(self):
        self.ready();self.env.write_bytes(self.raw + b'OTHER_SETTING=changed\n')
        with self.assertRaisesRegex(subject.Stop, 'ENV_UNKNOWN_BYTES'):
            self.engine.invoke(self.action('activation_flag'))
        self.assertTrue(self.env.read_bytes().endswith(b'OTHER_SETTING=changed\n'))

    def test_source_change_blocks_even_after_successful_readiness(self):
        self.ready();self.file.write_bytes(b'<?php // peer modified\n')
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_CHANGED'):
            self.engine.invoke(self.action('activation_flag'))
        self.assertEqual(self.raw, self.env.read_bytes())

    def test_cache_runs_as_application_identity_and_never_flushes_business_cache(self):
        receipt = self.activate_cache()
        self.assertTrue(receipt['result']['workspace_enabled'])
        self.assertIn(('php', 'config'), self.runner.calls)
        self.assertFalse(any('clear' in str(call) for call in self.runner.calls))
        self.assertEqual([], [call for call in self.runner.calls if call[0] == 'systemctl'])

    def test_failed_command_output_is_private(self):
        self.ready();self.engine.invoke(self.action('activation_flag'));self.runner.fail = True
        with self.assertRaisesRegex(subject.Stop, 'PRIVATE_COMMAND_FAILED') as error:
            self.engine.invoke(self.action('activation_cache'))
        self.assertNotIn('secret', str(error.exception));self.assertNotIn('private failure', str(error.exception))

    def test_fpm_waits_captured_worker_generation_and_recovery_does_not_reload_again(self):
        cache = self.activate_cache()['result']['cache_sha256']
        action = self.action('activation_fpm', {'cache_sha256': cache, 'route_cache_sha256': None})
        result = self.engine.invoke(action)
        self.assertTrue(result['result']['reload_completed'])
        self.assertEqual(self.processes.workers, self.processes.seen[0])
        self.assertEqual('complete', self.engine.recover(action)['status'])
        self.assertEqual(1, self.runner.calls.count(('systemctl', 'reload')))

    def test_crash_between_reload_and_durable_ack_stays_unknown_and_does_not_repeat(self):
        cache = self.activate_cache()['result']['cache_sha256']
        action = self.action('activation_fpm', {'cache_sha256': cache, 'route_cache_sha256': None})
        self.runner.crash_reload = True
        with self.assertRaises(Crash):self.engine.invoke(action)
        self.runner.crash_reload = False
        self.assertEqual('unknown', self.engine.recover(action)['status'])
        self.assertEqual(1, self.runner.calls.count(('systemctl', 'reload')))

    def test_conditional_route_cache_is_built_only_when_already_required(self):
        path = self.root / 'bootstrap/cache/routes-v7.php';path.write_bytes(b'old routes');path.chmod(0o644)
        self.pins['binding']['route_cache_required'] = True;self.engine = self.make()
        self.activate_cache();receipt = self.engine.invoke(self.action('activation_route_cache'))
        self.assertTrue(receipt['result']['workspace_routes_verified'])
        self.assertIn(('php', 'routes'), self.runner.calls)

    def test_false_preparation_normalizes_absent_flag_and_retains_original_secret_bytes(self):
        raw = b'APP_KEY="original $private"\r\n';self.env.write_bytes(raw)
        hashes = self.engine.preview_binding_env();self.pins['binding'].update({key: value for key,value in hashes.items() if key!='env_original_sha256'})
        self.engine = self.make();receipt = self.engine.prepare_disabled()
        self.assertEqual(plan.workspace_env_bytes(raw, enabled=False), self.env.read_bytes())
        self.assertFalse(receipt['result']['workspace_enabled']);self.assertTrue(receipt['result']['old_generation_drained'])
        records = list(self.private.glob('action-*/pending.json'))
        self.assertTrue(any('before_base64' in json.loads(path.read_bytes()) for path in records))
        self.assertNotIn('original $private', json.dumps(receipt))

    def test_explicit_true_in_supported_dotenv_forms_requires_review(self):
        for value in (b'true', b'1', b'"true"', b"'true'"):
            self.env.write_bytes(b'DESKTOP_DASHBOARD_WORKSPACE_ENABLED=' + value + b'\n')
            with self.assertRaisesRegex(subject.Stop, 'PREEXISTING_ENABLED_FLAG_REQUIRES_REVIEW'):
                self.engine.preview_binding_env()

    def test_env_symlink_is_denied(self):
        original = self.env.with_name('real.env');os.replace(self.env, original);self.env.symlink_to(original)
        with self.assertRaisesRegex(subject.Stop, 'FILE_UNSAFE'):
            self.engine.preview_binding_env()

    def test_fixed_runner_is_disabled_without_program_pins(self):
        runner = subject.ProcessRunner(lambda: True)
        with self.assertRaisesRegex(subject.Stop, 'RUNNER_PINS_UNAVAILABLE'):
            runner.php(subject.CACHE_PROGRAM, {})
        with self.assertRaisesRegex(subject.Stop, 'SYSTEMD_ACTION_INVALID'):
            runner.systemctl('restart')
        runner = subject.ProcessRunner(lambda: True, approved_program_hashes=frozenset())
        with self.assertRaisesRegex(subject.Stop, 'RUNNER_PINS_UNAVAILABLE'):
            runner.systemctl('reload')

    def test_only_exact_application_bootstrap_cache_directory_may_be_group_writable(self):
        cache = self.root / 'bootstrap/cache';cache.chmod(0o775)
        self.assertFalse(self.engine._inspect()[0]['workspace_enabled'])
        cache.chmod(0o777)
        with self.assertRaisesRegex(subject.Stop, 'CACHE_DIRECTORY_UNSAFE'):
            self.engine._inspect()
        cache.chmod(0o755);(self.root / 'app').chmod(0o775)
        with self.assertRaisesRegex(source.Stop, 'DIRECTORY_UNSAFE'):
            self.engine._source()

    def test_fixed_runner_rejects_nonpinned_php_before_spawn(self):
        runner = subject.ProcessRunner(lambda: True, approved_program_hashes=frozenset({subject.sha(subject.CACHE_PROGRAM)}))
        with self.assertRaisesRegex(subject.Stop, 'PHP_PROGRAM_NOT_PINNED'):
            runner.php(b'<?php arbitrary();', {})

    def test_pinned_php_still_cannot_execute_as_root(self):
        runner = subject.ProcessRunner(lambda: True, approved_program_hashes=frozenset({subject.sha(subject.CACHE_PROGRAM)}))
        # Disposable file tests patch only the constants; use the production
        # account pair here so this assertion cannot be satisfied by that patch.
        with patch.object(subject, 'APP_UID', 1001), patch.object(subject, 'APP_GID', 1003):
            with self.assertRaisesRegex(subject.Stop, 'PHP_PROGRAM_NOT_PINNED'):
                runner.php(subject.CACHE_PROGRAM, {}, uid=0, gid=0)

    def test_reparented_old_fpm_worker_is_still_running_until_pid_start_identity_ends(self):
        proc = subject.ProcGeneration();workers = [{'pid':100,'ppid':42,'start':17}]
        with patch.object(proc, 'identity', return_value={'pid':100,'ppid':1,'start':17}):
            self.assertFalse(proc.gone(workers))
        with patch.object(proc, 'identity', return_value={'pid':100,'ppid':42,'start':18}):
            self.assertTrue(proc.gone(workers))
        with patch.object(proc, 'identity', return_value=None):
            self.assertTrue(proc.gone(workers))

    def test_cache_changed_after_false_https_is_rejected_before_flag(self):
        self.ready();self.runner.write_cache(True)
        with self.assertRaisesRegex(subject.Stop, 'FALSE_FLAG_CACHE_CHANGED'):
            self.engine.invoke(self.action('activation_flag'))
        self.assertEqual(self.raw, self.env.read_bytes())

    def test_fpm_deadline_keeps_old_generation_and_never_claims_complete(self):
        cache = self.activate_cache()['result']['cache_sha256']
        action = self.action('activation_fpm', {'cache_sha256': cache, 'route_cache_sha256': None})
        self.processes.finished = False
        ticks = iter(range(0, 1000, 100));self.engine.clock = lambda: next(ticks)
        with self.assertRaisesRegex(subject.Stop, 'FPM_OLD_GENERATION_STILL_RUNNING'):
            self.engine.invoke(action)
        self.assertIsNone(self.engine._load(self.engine._folder(action) / 'complete.json'))
        self.processes.finished = True
        self.assertEqual('complete', self.engine.recover(action)['status'])
        self.assertEqual(1, self.runner.calls.count(('systemctl', 'reload')))

    def barrier_pending(self):
        return {'format': 1, 'kind': 'go-erp-shared-workspace-addition-removal-intent',
            'plan_sha256': '5' * 64, 'attempt': 'fixture-owned-attempt',
            'restored_referrers_sha256': '6' * 64, 'addition_inodes': {'app/New.php': [1, 2]},
            'php_handler_configuration_sha256': self.pins['binding']['php_handler_configuration_sha256'],
            'fpm_unit': subject.FPM, 'drain_required': True}

    def test_rollback_barrier_restores_original_absent_flag_before_old_worker_deletion_boundary(self):
        original = b'APP_KEY="before $private"\r\n';self.env.write_bytes(original)
        hashes = self.engine.preview_binding_env();self.pins['binding'].update({key:value for key,value in hashes.items() if key!='env_original_sha256'})
        self.engine = self.make();self.engine.prepare_disabled()
        self.activate_cache()
        pending = self.barrier_pending()
        proof = self.engine.addition_removal_barrier(pending, lambda: pending['restored_referrers_sha256'])
        self.assertEqual(original, self.env.read_bytes())
        self.assertFalse(proof['workspace_enabled']);self.assertTrue(proof['old_generation_captured_before_reload'])
        self.assertTrue(proof['drained_before_addition_removal']);self.assertTrue(proof['no_old_generation_requests'])
        self.assertNotIn('drained_before_reload', proof)
        calls = self.runner.calls.count(('systemctl', 'reload'))
        self.assertEqual(proof, self.engine.addition_removal_barrier(pending,
            lambda: pending['restored_referrers_sha256'], recovering=True))
        self.assertEqual(calls, self.runner.calls.count(('systemctl', 'reload')))

    def test_rollback_never_overwrites_an_unowned_environment_or_accepts_changed_referrers(self):
        self.engine.prepare_disabled();pending = self.barrier_pending()
        with self.assertRaisesRegex(subject.Stop, 'RESTORED_REFERRERS_CHANGED'):
            self.engine.addition_removal_barrier(pending, lambda: '7' * 64)
        self.env.write_bytes(self.raw + b'PEER_SECRET=updated\n')
        with self.assertRaisesRegex(subject.Stop, 'ROLLBACK_ENVIRONMENT_NOT_OWNED'):
            self.engine.addition_removal_barrier(pending, lambda: pending['restored_referrers_sha256'])
        self.assertTrue(self.env.read_bytes().endswith(b'PEER_SECRET=updated\n'))

    def test_false_preparation_is_idempotent_without_rebuilding_or_reloading(self):
        first = self.engine.prepare_disabled();inode = self.env.stat().st_ino
        calls = list(self.runner.calls)
        second = self.engine.prepare_disabled(recovering=True)
        self.assertEqual(first, second);self.assertEqual(inode, self.env.stat().st_ino)
        self.assertEqual(calls.count(('php', 'config')), self.runner.calls.count(('php', 'config')))
        self.assertEqual(calls.count(('systemctl', 'reload')), self.runner.calls.count(('systemctl', 'reload')))


if __name__ == '__main__':
    unittest.main()
