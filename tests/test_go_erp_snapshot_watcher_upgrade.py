#!/usr/bin/env python3
"""Root-owned filesystem, real Git seed, four-path rollback and high-water tests."""
import copy
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import types
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('snapshot_watcher_upgrade',
    REPO / 'deployment/upgrade_go_erp_snapshot_watcher_root.py')
subject = importlib.util.module_from_spec(spec); spec.loader.exec_module(subject)


class StatePlanTests(unittest.TestCase):
    def state(self):
        return {'format': 1, 'base_revision': subject.OLD_SEED, 'counter': 24,
                'version_floor': '0.4.4-preview.24', 'sequence_floor': 39846005957,
                'published_history': [{'sequence': 39846005957, 'version': '0.4.4-preview.24'}],
                'last_remote_sha': 'a' * 40, 'dependency_guard': {'retained': 'fingerprint'},
                'observed': {'old': True}, 'desired': {'status': 'waiting-ci'},
                'published': {'old': True}, 'future_compatible_field': {'retained': True}}

    def test_only_seed_specific_capture_is_reset_and_every_high_water_value_is_preserved(self):
        original = self.state(); before = copy.deepcopy(original)
        result = subject.migrated_state(original)
        self.assertEqual(original, before)
        self.assertEqual(result['base_revision'], subject.NEW_SEED)
        self.assertEqual(result['counter'], 25)
        self.assertEqual(result['version_floor'], '0.4.4-preview.25')
        for name in ('observed', 'desired', 'published'):
            self.assertIsNone(result[name])
        for name in ('sequence_floor', 'published_history', 'last_remote_sha', 'dependency_guard', 'future_compatible_field'):
            self.assertEqual(result[name], original[name])

    def test_higher_counter_and_version_floor_are_never_lowered(self):
        for counter, floor, expected in [(40, 31, 40), (27, 60, 60)]:
            value = self.state(); value.update(counter=counter, version_floor='0.4.4-preview.' + str(floor))
            result = subject.migrated_state(value)
            self.assertEqual(result['counter'], expected)
            self.assertEqual(result['version_floor'], value['version_floor'])
            self.assertEqual(result['sequence_floor'], value['sequence_floor'])

    def test_malformed_states_and_unreviewed_seed_are_refused(self):
        for name, value in [('counter', True), ('counter', -1), ('sequence_floor', True),
                            ('sequence_floor', -1), ('version_floor', '1.0.0'),
                            ('version_floor', '0.4.4-preview.0'), ('last_remote_sha', 'foreign'),
                            ('published_history', {}), ('base_revision', 'f' * 40)]:
            with self.subTest(name=name, value=value):
                state = self.state(); state[name] = value
                with self.assertRaises(subject.Stop):
                    subject.migrated_state(state)

    def test_duplicate_configuration_fields_are_rejected(self):
        with self.assertRaisesRegex(subject.Stop, 'DUPLICATE_JSON_FIELD'):
            subject.parse(b'{"base_revision":"one","base_revision":"two"}')


@unittest.skipUnless(os.geteuid() == 0, 'Private watcher fixture requires root.')
class SnapshotWatcherMigrationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-snapshot-upgrade-', dir='/var/tmp')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        # sudo executes these fixtures against a runner-owned checkout. The
        # production package is root-owned, so reproduce that package boundary
        # before invoking the unchanged strict dependency loader.
        self.utility_package = self.base / 'upgrade_go_erp_capacity_watcher_root.py'
        self.utility_package.write_bytes((REPO / 'deployment/upgrade_go_erp_capacity_watcher_root.py').read_bytes())
        self.utility_package.chmod(0o644)
        self.u = subject.load_utility(self.utility_package)
        self.live, self.work = self.base / 'live', self.base / 'work'
        self.live.mkdir(mode=0o750); self.work.mkdir(mode=0o700)
        (self.live / 'application-marker').write_text('current application remains byte-identical\n')
        (self.work / 'state').mkdir(mode=0o700)
        (self.work / 'watcher.lock').write_bytes(b''); (self.work / 'watcher.lock').chmod(0o600)
        self.config_parent = self.base / 'config'; self.config_parent.mkdir(mode=0o700)
        self.config = self.config_parent / 'live-updates.json'
        self.state = self.work / 'state/current.json'
        self.unit_parent = self.base / 'systemd'; self.unit_parent.mkdir(mode=0o755)
        self.unit = self.unit_parent / 'go-erp-live-updates.service'
        self.releases = self.base / 'releases'; self.releases.mkdir(mode=0o755)
        self.old_runtime = self.releases / subject.OLD_RUNTIME; self.old_runtime.mkdir(mode=0o755)
        for name in subject.ROSTER:
            target = self.old_runtime / name
            target.write_bytes((REPO / 'deployment/capacity-watcher' / name).read_bytes()); target.chmod(0o644)
        self.repo = self.base / 'public-seed'; self.repo.mkdir(mode=0o700)
        self.run_git(self.repo, 'init', '--quiet', '--template=')
        (self.repo / 'original-marker').write_text('old seed retained\n')
        self.commit('old seed'); self.old_seed = self.run_git(self.repo, 'rev-parse', 'HEAD').decode().strip()
        (self.repo / 'new-marker').write_text('native transport fix\n')
        deployment = self.repo / 'deployment'; deployment.mkdir()
        (deployment / 'verify_go_erp_media_capacity_seed.py').write_text(
            'import subprocess\nCORE=' + repr(self.old_seed) + '\n'
            'def validate_seed(root,revision,core=CORE):\n'
            '    assert core==CORE\n'
            '    assert subprocess.check_output(["git","-C",str(root),"rev-parse","HEAD"]).decode().strip()==revision\n'
            '    subprocess.run(["git","-C",str(root),"merge-base","--is-ancestor",core,revision],check=True)\n'
            '    return {"core":core,"seed":revision}\n')
        self.commit('exact new native seed'); self.new_seed = self.run_git(self.repo, 'rev-parse', 'HEAD').decode().strip()
        self.run_git(self.repo, 'clone', '--quiet', '--no-local', '--no-hardlinks', str(self.repo), str(self.work / 'checkout'))
        self.run_git(self.work / 'checkout', 'remote', 'set-url', 'origin', subject.REMOTE)
        self.run_git(self.work / 'checkout', 'checkout', '--quiet', '--detach', self.old_seed)
        self.before_config = {'format': 1, 'repository': 'omarmakramelbaz-hub/F-Backend',
            'repository_id': 1311412084, 'branch': 'codex/go-erp-live-source-20261021',
            'base_revision': self.old_seed, 'token': 'PRIVATE_FIXTURE_TOKEN_NEVER_DISCLOSED'}
        self.before_state = StatePlanTests().state()
        self.before_state.update(base_revision=self.old_seed, dependency_guard={'fixture': 'fixed'})
        self.write(self.config, self.before_config); self.write(self.state, self.before_state)
        self.payloads = {
            'go_erp_live_source.py': ('TRANSPORT_SEED=' + repr(self.new_seed) + '\n'
                'TRANSPORT_POLICY="historical-baseline-v2-snapshot-transport-v1"\n'
                'def scan_snapshot(live,seed,uid,previous):\n'
                '    assert uid==1001 and previous is None\n'
                '    assert (seed/"new-marker").read_text()=="native transport fix\\n"\n'
                '    return {"metadata":{"inventory":{"application-marker":{"fixed":True}}}}\n').encode(),
            'go_erp_live_updates.py': ('TRANSPORT_SEED=' + repr(self.new_seed) + '\n'
                'TRANSPORT_POLICY="historical-baseline-v2-snapshot-transport-v1"\n'
                'def dependency_snapshot(live,uid):return {"fixture":"fixed"}\n'
                'class Worker:\n'
                '    def candidate_policy(self):\n'
                '        assert self.config["base_revision"]==TRANSPORT_SEED\n'
                '        assert self.source.TRANSPORT_SEED==TRANSPORT_SEED\n'
                '        return TRANSPORT_POLICY\n').encode(),
            'publish_go_erp_update.py': b'# fixture unchanged publisher\n',
            'go_erp_current_candidate_ci.yml': b'name: fixed historical template\n',
            'verify_go_erp_current_candidate_ci.py': b'# fixture independent original verifier\n',
        }
        self.pins = {name: subject.blob(data) for name, data in self.payloads.items()}
        self.target = self.releases / self.u.runtime_name(self.payloads)
        self.unit.write_bytes(subject.unit_bytes(self.u, self.old_runtime, self.live, self.work, self.config))
        self.unit.chmod(0o644)
        self.before_bytes = {name: path.read_bytes() for name, path in [('config', self.config), ('state', self.state), ('unit', self.unit)]}
        self.before_inodes = {name: path.stat().st_ino for name, path in [('config', self.config), ('state', self.state), ('unit', self.unit), ('checkout', self.work / 'checkout')]}
        self.application = (self.live / 'application-marker').read_bytes()
        self.loaded_unit = self.base / 'loaded-systemd-unit'
        self.loaded_unit.write_bytes(self.unit.read_bytes())
        self.commands, self.unit_checks = [], []
        original_git = subject.git
        def local_public_fetch(root, *arguments, **kwargs):
            if arguments and arguments[0] == 'fetch':
                self.assertEqual(arguments[-2], 'origin')
                self.assertEqual(arguments[-1], self.new_seed)
                arguments = (*arguments[:-2], str(self.repo), arguments[-1])
            return original_git(root, *arguments, **kwargs)
        for patch in (mock.patch.object(subject, 'OLD_SEED', self.old_seed),
                      mock.patch.object(subject, 'git', local_public_fetch)):
            patch.start(); self.addCleanup(patch.stop)

    def run_git(self, root, *arguments):
        return subprocess.check_output(['git', '-c', 'core.hooksPath=/dev/null', '-C', str(root), *arguments], stderr=subprocess.PIPE)

    def commit(self, message):
        self.run_git(self.repo, 'add', '.')
        self.run_git(self.repo, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', message)

    def write(self, path, value):
        path.write_bytes(subject.canonical(value)); path.chmod(0o600)

    def command(self, arguments):
        self.commands.append(arguments)
        if arguments[:2] == ['/usr/bin/systemd-analyze', 'verify']:
            path = Path(arguments[2]); self.assertEqual(path.suffix, '.service')
            self.assertEqual(path.stat().st_mode & 0o777, 0o644)
        if arguments == ['/usr/bin/systemctl', 'daemon-reload']:
            self.loaded_unit.write_bytes(self.unit.read_bytes())

    def verify_unit(self, unit, config, runtimes, allow_pending_reload=False):
        self.assertEqual(unit, self.unit); self.assertEqual(config, self.config)
        self.unit_checks.append((tuple(runtimes), allow_pending_reload))
        self.assertIn(self.loaded_unit.read_bytes(), [
            subject.unit_bytes(self.u, runtime, self.live, self.work, self.config) for runtime in runtimes])

    def upgrade(self, **kwargs):
        kwargs.setdefault('command', self.command); kwargs.setdefault('verify_unit', self.verify_unit)
        return subject.upgrade(self.payloads, self.pins, utility=self.u, live=self.live, work=self.work,
            releases=self.releases, unit=self.unit, config=self.config, new_seed=self.new_seed,
            core=self.old_seed, **kwargs)

    def audits(self):
        return list(self.work.glob(subject.PREFIX + '*'))

    def assert_original(self):
        for name, path in [('config', self.config), ('state', self.state), ('unit', self.unit)]:
            self.assertEqual(path.read_bytes(), self.before_bytes[name])
            self.assertEqual(path.stat().st_ino, self.before_inodes[name])
        self.assertEqual((self.work / 'checkout').stat().st_ino, self.before_inodes['checkout'])
        subject.checkout(self.work / 'checkout', self.old_seed, self.u)
        self.assertEqual((self.live / 'application-marker').read_bytes(), self.application)

    def test_migrates_private_paths_only_and_retains_exact_original_backups(self):
        report = self.upgrade(); self.assertTrue(report['ok'], report)
        self.assertTrue(all(report[name] for name in ('configuration_changed', 'state_changed', 'unit_changed', 'checkout_changed')))
        self.assertFalse(report['application_contents_changed']); self.assertFalse(report['database_changed'])
        self.assertNotIn(self.before_config['token'], json.dumps(report))
        saved_config = json.loads(self.config.read_bytes()); self.assertEqual(saved_config['token'], self.before_config['token'])
        self.assertEqual(saved_config['base_revision'], self.new_seed)
        saved_state = json.loads(self.state.read_bytes())
        self.assertEqual(saved_state, subject.migrated_state(self.before_state, self.old_seed, self.new_seed))
        subject.checkout(self.work / 'checkout', self.new_seed, self.u)
        self.assertEqual(self.unit.read_bytes(), subject.unit_bytes(self.u, self.target, self.live, self.work, self.config))
        audit = self.audits()[0]
        self.assertEqual(audit.stat().st_mode & 0o777, 0o700)
        for name in ('config', 'state', 'unit'):
            self.assertEqual((audit / ('before-' + name + '.bin')).read_bytes(), self.before_bytes[name])
            self.assertEqual((audit / ('before-' + name + '.bin')).stat().st_mode & 0o777, 0o600)
        self.assertEqual((audit / 'exchange-checkout').stat().st_ino, self.before_inodes['checkout'])
        self.assertTrue((audit / 'completed.json').is_file())
        self.assertEqual((self.live / 'application-marker').read_bytes(), self.application)

    def test_private_entry_umask_preserves_strict_staged_verifier_validation(self):
        previous = os.umask(0o077)
        try:
            report = self.upgrade()
        finally:
            os.umask(previous)
        self.assertTrue(report['ok'], report)
        verifier = self.work / 'checkout/deployment/verify_go_erp_media_capacity_seed.py'
        self.assertEqual(verifier.stat().st_mode & 0o777, 0o644)
        self.assertEqual((self.work / 'checkout').stat().st_mode & 0o777, 0o700)
        self.assertEqual(self.config.stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.state.stat().st_mode & 0o777, 0o600)
        self.assertEqual(Path(report['audit_directory']).stat().st_mode & 0o777, 0o700)

    def test_idempotence_retains_newer_worker_progress_and_does_not_reset_capture_again(self):
        self.assertTrue(self.upgrade()['ok']); audits = self.audits()
        state = json.loads(self.state.read_bytes()); state.update(counter=60, version_floor='0.4.4-preview.59',
            sequence_floor=99999999999, desired={'version': '0.4.4-preview.60'}, observed={'new': True}, published={'new': True})
        self.write(self.state, state); config = self.config.read_bytes(); state_bytes = self.state.read_bytes()
        result = self.upgrade(); self.assertTrue(result['ok'], result); self.assertTrue(result['already_current'])
        self.assertEqual(self.audits(), audits); self.assertEqual(self.state.read_bytes(), state_bytes)
        self.assertEqual(self.config.read_bytes(), config); self.assertEqual(result['counter'], 60)

    def test_each_exchange_failure_restores_original_inodes_and_private_bytes(self):
        for slot in subject.SLOTS:
            with self.subTest(slot=slot):
                def fail(phase, current):
                    if phase == 'after-exchange' and current == slot:
                        raise OSError('fixture error includes PRIVATE_FIXTURE_TOKEN_NEVER_DISCLOSED')
                report = self.upgrade(event=fail)
                self.assertFalse(report['ok']); self.assertTrue(report['rollback_complete'], report)
                self.assertNotIn(self.before_config['token'], json.dumps(report)); self.assert_original()

    def test_postflight_source_change_rolls_back_all_paths(self):
        original = subject.probe_runtime; calls = 0
        def changed(*arguments, **kwargs):
            nonlocal calls
            calls += 1; result = original(*arguments, **kwargs)
            if calls == 2:
                result = copy.deepcopy(result); result['inventory']['changed'] = {}
            return result
        report = self.upgrade(probe=changed)
        self.assertFalse(report['ok']); self.assertEqual(report['reason'], 'SOURCE_CHANGED')
        self.assertTrue(report['rollback_complete']); self.assert_original()

    def test_failed_daemon_reload_rolls_back_and_reloads_old_unit(self):
        failed = False
        def command(arguments):
            nonlocal failed
            self.command(arguments)
            if arguments == ['/usr/bin/systemctl', 'daemon-reload'] and not failed:
                failed = True; raise OSError('one transient daemon failure')
        report = self.upgrade(command=command)
        self.assertFalse(report['ok']); self.assertTrue(report['rollback_complete'], report)
        self.assert_original(); self.assertEqual(self.commands.count(['/usr/bin/systemctl', 'daemon-reload']), 2)

    def test_higher_concurrent_state_is_retained_and_stale_rollback_is_refused(self):
        replacement = None
        def fail(phase, slot):
            nonlocal replacement
            if phase == 'after-exchange' and slot == 'state':
                value = json.loads(self.state.read_bytes()); value['counter'] = 88
                temp = self.state.with_suffix('.changed'); self.write(temp, value); temp.replace(self.state)
                replacement = self.state.read_bytes(); raise OSError('concurrent writer')
        report = self.upgrade(event=fail)
        self.assertFalse(report['ok']); self.assertFalse(report['rollback_complete'])
        self.assertTrue(report['recovery_required']); self.assertEqual(self.state.read_bytes(), replacement)
        second = self.upgrade(); self.assertFalse(second['ok']); self.assertTrue(second['recovery_required'])
        self.assertEqual(self.state.read_bytes(), replacement)

    def test_preflight_or_staging_failure_never_exchanges_private_paths(self):
        for kind in ('seed', 'dependency'):
            with self.subTest(kind=kind):
                if kind == 'seed':
                    def fail(*_args):
                        raise subject.Stop('SEED_PROOF_INVALID')
                    result = self.upgrade(stage=fail)
                else:
                    state = copy.deepcopy(self.before_state); state['dependency_guard'] = {'unexpected': True}
                    self.write(self.state, state); self.before_bytes['state'] = self.state.read_bytes()
                    result = self.upgrade()
                self.assertFalse(result['ok']); self.assert_original()

    def test_private_file_symlinks_hardlinks_and_dirty_checkout_are_rejected(self):
        marker = self.work / 'checkout/concurrent-untracked-file'; marker.write_text('preserve this')
        result = self.upgrade(); self.assertFalse(result['ok']); self.assertEqual(result['reason'], 'CHECKOUT_CHANGED')
        self.assertTrue(marker.is_file()); marker.unlink()
        backup = self.config.with_suffix('.original'); self.config.rename(backup); self.config.symlink_to(backup)
        result = self.upgrade(); self.assertFalse(result['ok']); self.config.unlink(); backup.rename(self.config)
        os.link(self.state, self.state.with_suffix('.hardlink'))
        result = self.upgrade(); self.assertFalse(result['ok']); self.assert_original()

    def test_existing_watcher_lock_rejects_before_any_private_path_changes(self):
        lock = os.open(self.work / 'watcher.lock', os.O_RDONLY)
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.upgrade(); self.assertFalse(result['ok']); self.assertEqual(result['reason'], 'WATCHER_BUSY')
        finally:
            os.close(lock)
        self.assert_original(); self.assertEqual(self.audits(), [])

    def crash_and_recover(self, slot):
        pid = os.fork()
        if pid == 0:
            def die(phase, current):
                if phase == 'after-exchange' and current == slot:
                    os._exit(77)
            self.upgrade(event=die)
            os._exit(2)
        _, status = os.waitpid(pid, 0)
        self.assertTrue(os.WIFEXITED(status)); self.assertEqual(os.WEXITSTATUS(status), 77)
        first = self.audits()[0]
        self.assertTrue((first / 'intent.json').is_file()); self.assertFalse((first / 'completed.json').exists())
        report = self.upgrade(); self.assertTrue(report['ok'], report)
        self.assertTrue(report['recovered_previous_interruption'])
        self.assertTrue((first / 'rolled-back.json').is_file())
        self.assertEqual(json.loads(self.state.read_bytes())['counter'], 25)
        self.assertEqual(json.loads(self.state.read_bytes())['sequence_floor'], self.before_state['sequence_floor'])
        subject.checkout(self.work / 'checkout', self.new_seed, self.u)
        self.assertEqual((self.live / 'application-marker').read_bytes(), self.application)

    def test_unrecorded_process_exit_mid_transition_is_recovered_before_fresh_migration(self):
        self.crash_and_recover('checkout')

    def test_unrecorded_process_exit_after_all_exchanges_is_recovered_without_losing_floors(self):
        self.crash_and_recover('config')

    def test_crash_after_config_leaves_new_runtime_loaded_before_a_worker_can_advance_state(self):
        pid = os.fork()
        if pid == 0:
            def die(phase, slot):
                if phase == 'after-exchange' and slot == 'config':
                    expected = subject.unit_bytes(self.u, self.target, self.live, self.work, self.config)
                    if self.loaded_unit.read_bytes() != expected:
                        os._exit(3)
                    os._exit(77)
            self.upgrade(event=die)
            os._exit(2)
        _, status = os.waitpid(pid, 0)
        self.assertEqual(os.WEXITSTATUS(status), 77)
        self.assertEqual(self.loaded_unit.read_bytes(),
            subject.unit_bytes(self.u, self.target, self.live, self.work, self.config))
        # Model the subsequent timer tick using the actually loaded new runtime's
        # policy. It must select transport proof before state can advance.
        directory = self.u.Directory(self.target, 0o755)
        try:
            source = self.u.module_at(directory, 'go_erp_live_source.py', self.pins['go_erp_live_source.py'])
            worker = self.u.module_at(directory, 'go_erp_live_updates.py', self.pins['go_erp_live_updates.py'])
            import types
            policy = worker.Worker.candidate_policy(types.SimpleNamespace(
                config=json.loads(self.config.read_bytes()), source=source))
            self.assertEqual(policy, source.TRANSPORT_POLICY)
        finally:
            directory.close()
        advanced = json.loads(self.state.read_bytes())
        advanced.update(counter=26, desired={'version': '0.4.4-preview.26', 'candidatePolicy': policy})
        replacement = self.state.with_suffix('.worker'); self.write(replacement, advanced); replacement.replace(self.state)
        result = self.upgrade()
        self.assertFalse(result['ok']); self.assertTrue(result['recovery_required'])
        self.assertEqual(json.loads(self.state.read_bytes()), advanced)

    def test_concurrent_state_before_exchange_is_kept_and_does_not_leave_pending_rollback(self):
        def change(phase, _slot):
            if phase == 'before-exchange':
                state = json.loads(self.state.read_bytes()); state['counter'] = 42
                self.write(self.state, state)
        result = self.upgrade(event=change)
        self.assertFalse(result['ok'])
        self.assertEqual(json.loads(self.state.read_bytes())['counter'], 42)
        self.assertTrue((self.audits()[0] / 'rolled-back.json').is_file())
        result = self.upgrade(); self.assertTrue(result['ok'], result)
        self.assertEqual(result['counter'], 42)

    def test_private_credential_rotation_during_staging_is_never_overwritten(self):
        rotated = {**self.before_config, 'token': 'ROTATED_PRIVATE_FIXTURE_TOKEN_RETAINED'}
        def stage(*arguments):
            subject.stage_seed(*arguments)
            replacement = self.config.with_suffix('.rotated')
            self.write(replacement, rotated); replacement.replace(self.config)
        result = self.upgrade(stage=stage)
        self.assertFalse(result['ok']); self.assertEqual(result['reason'], 'BEFORE_PATH_CHANGED')
        self.assertEqual(json.loads(self.config.read_bytes()), rotated)
        self.assertNotIn(rotated['token'], json.dumps(result))
        self.before_bytes['config'] = self.config.read_bytes()
        self.before_inodes['config'] = self.config.stat().st_ino
        self.assert_original()
        result = self.upgrade(); self.assertTrue(result['ok'], result)
        self.assertEqual(json.loads(self.config.read_bytes())['token'], rotated['token'])

    def test_unit_override_and_modified_old_runtime_are_refused(self):
        def reject(*_args, **_kwargs):
            raise self.u.Stop('EFFECTIVE_UNIT_UNREVIEWED')
        result = self.upgrade(verify_unit=reject)
        self.assertFalse(result['ok']); self.assertEqual(result['reason'], 'EFFECTIVE_UNIT_UNREVIEWED')
        target = self.old_runtime / subject.ROSTER[0]; target.write_bytes(target.read_bytes() + b'\n# changed\n')
        result = self.upgrade(); self.assertFalse(result['ok']); self.assertEqual(result['reason'], 'RUNTIME_PIN_INVALID')
        self.assert_original()

    def test_utility_is_pinned_before_any_code_can_execute(self):
        path = self.base / 'unreviewed-utility.py'
        marker = self.base / 'must-not-exist'
        path.write_text('from pathlib import Path\nPath(' + repr(str(marker)) + ').write_text("executed")\n')
        path.chmod(0o644)
        with self.assertRaisesRegex(subject.Stop, 'UTILITY_PIN_INVALID'):
            subject.load_utility(path)
        self.assertFalse(marker.exists())

    def test_cold_utility_load_ignores_legitimate_access_time_updates(self):
        target = self.base / 'reviewed-utility.py'
        target.write_bytes((REPO / 'deployment/upgrade_go_erp_capacity_watcher_root.py').read_bytes())
        target.chmod(0o644)
        modified = target.stat().st_mtime_ns
        os.utime(target, ns=(modified - 86400 * 10**9, modified))
        loaded = subject.load_utility(target)
        self.assertEqual(loaded.blob(target.read_bytes()), subject.UTILITY_BLOB)

    def test_runner_owned_checkout_requires_a_verified_root_owned_package_copy(self):
        checkout_file = self.base / 'runner-owned-utility.py'
        checkout_file.write_bytes(self.utility_package.read_bytes()); checkout_file.chmod(0o644)
        # Some root test containers map only uid 0. Model the checkout file's
        # owner metadata without requiring an unavailable host uid mapping.
        inode = checkout_file.stat().st_ino
        fstat = os.fstat
        def checkout_owner(fd):
            value = fstat(fd)
            if value.st_ino != inode:
                return value
            fields = {name: getattr(value, name) for name in dir(value) if name.startswith('st_')}
            fields.update(st_uid=1001, st_gid=1001)
            return types.SimpleNamespace(**fields)
        with mock.patch.object(subject.os, 'fstat', checkout_owner):
            with self.assertRaisesRegex(subject.Stop, 'UTILITY_UNSAFE'):
                subject.load_utility(checkout_file)
        packaged = self.base / 'root-owned-utility.py'
        packaged.write_bytes(checkout_file.read_bytes()); packaged.chmod(0o644)
        loaded = subject.load_utility(packaged)
        self.assertEqual(loaded.blob(packaged.read_bytes()), subject.UTILITY_BLOB)


if __name__ == '__main__':
    unittest.main()
