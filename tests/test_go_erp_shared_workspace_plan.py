"""Disposable Git/JSON/env tests. No server, production DB, allocation or publish."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('shared_workspace_plan',
    ROOT / 'deployment/go_erp_shared_workspace_plan.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)


class ManifestTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='workspace-release-plan-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'Disposable test')
        self.git('config', 'user.email', 'test@example.invalid')
        self.write('config/desktop_dashboard.php', b"<?php return ['enabled'=>true];\n")
        self.write('app/Existing.php', b'<?php /* before */\n')
        self.baseline = self.commit()
        self.write('config/desktop_dashboard.php', b"<?php return ['workspace_enabled'=>env('DESKTOP_DASHBOARD_WORKSPACE_ENABLED', false)];\n")
        self.write('app/Existing.php', b'<?php /* after */\n')
        self.write('app/New.php', b'<?php /* new source */\n')
        self.write('desktop-pos/src/workspace.cjs', b'/* native only */\n')
        self.write('.github/workflows/shared.yml', b'name: source CI\n')
        for name, tables in subject.MIGRATIONS.items():
            self.write(name, b'<?php\n' + b'\n'.join(
                b"Schema::create('" + table.encode() + b"', function () {});" for table in tables))

    def git(self, *args):
        return subprocess.check_output(['git', '-c', 'core.hooksPath=/dev/null', *args],
            cwd=self.root, stderr=subprocess.DEVNULL,
            env={**os.environ, 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_SYSTEM': '/dev/null'})

    def write(self, name, raw):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(raw)

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-qm', 'Disposable fixture')
        return self.git('rev-parse', 'HEAD').decode().strip()

    def plan(self):
        release = self.commit()
        return subject.source_manifest(subject.Git(self.root / '.git'), self.baseline, release)

    def test_pinned_plan_includes_new_absence_and_both_migrations_without_native_deploy(self):
        plan = self.plan()
        self.assertEqual(plan['paths']['app/New.php']['operation'], 'add')
        self.assertIsNone(plan['paths']['app/New.php']['before_sha256'])
        self.assertEqual(plan['paths']['app/Existing.php']['before_sha256'], subject.sha(b'<?php /* before */\n'))
        self.assertEqual(plan['paths']['app/Existing.php']['after_sha256'], subject.sha(b'<?php /* after */\n'))
        self.assertEqual(set(name for name, row in plan['paths'].items()
            if row['scope'] == 'production_migration'), set(subject.MIGRATIONS))
        self.assertEqual(len(plan['production_table_additions']), 4)
        self.assertIn('desktop-pos/src/workspace.cjs', plan['release_only_paths'])
        self.assertIn('.github/workflows/shared.yml', plan['release_only_paths'])
        digest = plan.pop('manifest_sha256')
        self.assertEqual(digest, subject.sha(subject.canonical(plan)))
        self.assertFalse(plan['feature_activation_allowed'])
        self.assertFalse(plan['allocation_allowed'])
        self.assertFalse(plan['publication_allowed'])
        self.assertTrue(plan['final_release_pins_pending'])

    def test_working_tree_edits_cannot_change_commit_plan(self):
        release = self.commit()
        git = subject.Git(self.root / '.git')
        before = subject.source_manifest(git, self.baseline, release)
        self.write('app/New.php', b'private uncommitted bytes')
        after = subject.source_manifest(git, self.baseline, release)
        self.assertEqual(before, after)
        self.assertEqual((self.root / 'app/New.php').read_bytes(), b'private uncommitted bytes')

    def test_full_backend_roster_is_not_limited_to_old_seven_path_transaction(self):
        for index in range(110):
            self.write('app/Services/Workspace/Source' + str(index) + '.php',
                b'<?php /* individually pinned source */\n')
        plan = self.plan()
        self.assertGreater(len(plan['paths']), 110)
        self.assertEqual(sum(row['operation'] == 'add' for row in plan['paths'].values()), 113)

    def test_unknown_migration_rejected(self):
        self.write('database/migrations/2026_10_27_040000_other.php', b'<?php /* no */')
        with self.assertRaisesRegex(subject.Stop, 'UNREVIEWED_DATABASE'):
            self.plan()

    def test_existing_migration_rewrite_rejected(self):
        for name in subject.MIGRATIONS:
            self.write(name, b'<?php /* existing */')
        self.baseline = self.commit()
        for name, tables in subject.MIGRATIONS.items():
            self.write(name, b'<?php\n' + b'\n'.join(
                b"Schema::create('" + table.encode() + b"', function () {});" for table in tables))
        with self.assertRaisesRegex(subject.Stop, 'MIGRATION_MUST_BE_NEW'):
            self.plan()

    def test_private_native_table_is_never_allowed_in_production_migration(self):
        name = next(iter(subject.MIGRATIONS))
        path = self.root / name
        path.write_bytes(path.read_bytes() + b"\nSchema::create('desktop_dashboard_workspace_local_members', function () {});")
        with self.assertRaisesRegex(subject.Stop, 'TABLE_SCOPE_CHANGED'):
            self.plan()

    def test_missing_migration_default_true_dependency_change_or_deletion_stops(self):
        mutations = (
            lambda: (self.root / next(iter(subject.MIGRATIONS))).unlink(),
            lambda: self.write('config/desktop_dashboard.php', b"<?php return ['workspace_enabled'=>env('DESKTOP_DASHBOARD_WORKSPACE_ENABLED', true)];"),
            lambda: self.write('composer.lock', b'{}'),
            lambda: (self.root / 'app/Existing.php').unlink(),
        )
        # Each disposable instance has its own pinned pair.
        for mutation in mutations:
            with self.subTest(mutation=mutation):
                mutation()
                with self.assertRaises(subject.Stop):
                    self.plan()
                self.git('reset', '--hard', self.baseline)
                self.git('clean', '-fdq')
                # Restore the valid candidate content after the reset.
                self.write('config/desktop_dashboard.php', b"<?php return ['workspace_enabled'=>env('DESKTOP_DASHBOARD_WORKSPACE_ENABLED', false)];")
                self.write('app/Existing.php', b'<?php /* after */\n')
                for name, tables in subject.MIGRATIONS.items():
                    self.write(name, b'<?php\n' + b'\n'.join(
                        b"Schema::create('" + table.encode() + b"', function () {});" for table in tables))

    def test_symlink_executable_path_escape_and_symbolic_revision_denied(self):
        os.symlink('/etc/passwd', self.root / 'app/Unsafe.php')
        with self.assertRaisesRegex(subject.Stop, 'NONREGULAR'):
            self.plan()
        (self.root / 'app/Unsafe.php').unlink()
        self.write('app/Unsafe.php', b'<?php')
        (self.root / 'app/Unsafe.php').chmod(0o755)
        with self.assertRaisesRegex(subject.Stop, 'NONREGULAR_OR_EXECUTABLE'):
            self.plan()
        for name in ('app/../x', '/tmp/x', 'app//x', 'app/secret\n.php', 'app/.env', '.env', '.github/workflows/../x'):
            with self.subTest(name=name), self.assertRaises(subject.Stop):
                subject.path_scope(name)
        with self.assertRaisesRegex(subject.Stop, 'COMMIT_PIN_REQUIRED'):
            subject.Git(self.root / '.git').commit('HEAD')


def checkpoint():
    release = {'source_revision': subject.INPUT_CANDIDATE, 'version': '0.4.4-preview.59', 'sequence': 59}
    desired = {'commit': subject.INPUT_CANDIDATE, 'version': release['version'],
        'inventorySha256': 'a' * 64, 'status': 'published', 'pushed': True}
    state = {'format': 1, 'counter': 59, 'base_revision': subject.OLD_SEED,
        'last_remote_sha': subject.INPUT_CANDIDATE, 'desired': desired,
        'published': {'commit': subject.INPUT_CANDIDATE, 'inventorySha256': 'a' * 64, 'release': release},
        'published_history': [release], 'version_floor': release['version'], 'sequence_floor': 59}
    return state, copy.deepcopy(release)


def performance_job():
    return {'format': 1, 'kind': 'go-erp-warm-start-rollout', 'core': subject.FAST_CORE,
        'seed': subject.FAST_SEED, 'remote_lease': subject.INPUT_CANDIDATE,
        'phase': 'running', 'prepare_started': True, 'baseline_counter': 59,
        'inventory': 'b' * 64, 'candidate': 'c' * 40, 'version': '0.4.4-preview.60'}


class CheckpointTests(unittest.TestCase):
    def test_observed_59_never_infers_permission_to_allocate(self):
        state, feed = checkpoint()
        before = copy.deepcopy((state, feed))
        result = subject.checkpoint_advice(state, feed)
        self.assertEqual(result['observed_counter'], 59)
        self.assertFalse(result['allocation_allowed'])
        self.assertEqual((state, feed), before)

    def test_known_prepared_59_keeps_performance_checkpoint(self):
        state, feed = checkpoint()
        state['base_revision'] = subject.FAST_SEED
        job = performance_job()
        job.update(candidate=None, version=None, phase='prepared')
        result = subject.checkpoint_advice(state, feed, job)
        self.assertEqual(result['decision'], 'resume_performance_checkpoint_before_any_workspace_allocation')
        with self.assertRaisesRegex(subject.Stop, 'WITHOUT_JOB'):
            subject.checkpoint_advice(state, feed)

    def test_reserved_60_requires_same_performance_job_and_stays_immutable(self):
        state, feed = checkpoint()
        job = performance_job()
        state.update(counter=60, base_revision=subject.FAST_SEED, last_remote_sha=job['candidate'])
        state['desired'].update(commit=job['candidate'], version=job['version'],
            inventorySha256=job['inventory'], candidatePolicy=subject.FAST_POLICY, status='blocked')
        before = copy.deepcopy((state, feed, job))
        result = subject.checkpoint_advice(state, feed, job)
        self.assertIn('do_not_replace_or_reallocate', result['decision'])
        self.assertEqual((state, feed, job), before)
        with self.assertRaisesRegex(subject.Stop, 'WITHOUT_PERFORMANCE_JOB'):
            subject.checkpoint_advice(state, feed)
        job['candidate'] = 'd' * 40
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_60_BINDING'):
            subject.checkpoint_advice(state, feed, job)

    def test_consumed_allocation_before_parent_latch_is_recognized_without_new_permit(self):
        state, feed = checkpoint()
        job = performance_job()
        state.update(counter=60, base_revision=subject.FAST_SEED, last_remote_sha=job['candidate'])
        state['desired'].update(commit=job['candidate'], version=job['version'],
            inventorySha256=job['inventory'], candidatePolicy=subject.FAST_POLICY, status='waiting-ci')
        job.update(candidate=None, version=None)
        self.assertFalse(subject.checkpoint_advice(state, feed, job)['allocation_allowed'])

    def test_published_60_still_requires_original_acceptance_before_new_predecessor(self):
        state, _ = checkpoint()
        job = performance_job()
        feed = {'source_revision': job['candidate'], 'version': job['version'], 'sequence': 60}
        state.update(counter=60, base_revision=subject.FAST_SEED, last_remote_sha=job['candidate'],
            version_floor=job['version'], sequence_floor=60)
        state['desired'].update(commit=job['candidate'], version=job['version'],
            inventorySha256=job['inventory'], candidatePolicy=subject.FAST_POLICY)
        state['published'] = {'commit': job['candidate'], 'inventorySha256': job['inventory'], 'release': feed}
        state['published_history'].append(feed)
        result = subject.checkpoint_advice(state, feed, job)
        self.assertEqual(result['decision'], 'verify_original_60_acceptance_then_pin_new_predecessor')
        self.assertTrue(result['previous_acceptance_verification_required'])

    def test_unknown_counter_bool_counter_feed_drift_or_unpushed_desired_denied(self):
        for field, value in (('counter', 61), ('counter', True), ('last_remote_sha', 'd' * 40)):
            state, feed = checkpoint()
            state[field] = value
            with self.subTest(field=field), self.assertRaises(subject.Stop):
                subject.checkpoint_advice(state, feed)
        state, feed = checkpoint()
        state['desired']['pushed'] = False
        with self.assertRaises(subject.Stop):
            subject.checkpoint_advice(state, feed)
        state, feed = checkpoint()
        feed['version'] = '0.4.4-preview.60'
        with self.assertRaises(subject.Stop):
            subject.checkpoint_advice(state, feed)


class EnvTests(unittest.TestCase):
    def test_exact_flag_preserves_every_other_secret_byte_crlf_comments_and_export(self):
        raw = (b'APP_KEY="synthetic-$value-`literal`"\r\n# DESKTOP_DASHBOARD_WORKSPACE_ENABLED=false\r\n'
            b'export DESKTOP_DASHBOARD_WORKSPACE_ENABLED = "false"  # held\r\n'
            b'DB_PASSWORD="synthetic = value # literal"\r\n')
        expected = raw.replace(b' = "false"  # held', b' = true  # held')
        self.assertEqual(subject.workspace_env_bytes(raw, enabled=True), expected)
        self.assertEqual(subject.workspace_env_bytes(expected, enabled=True), expected)
        self.assertEqual(subject.workspace_env_bytes(expected, enabled=False), expected.replace(b' = true ', b' = false '))

    def test_absent_flag_appends_without_changing_existing_bytes(self):
        for raw in (b'', b'APP_KEY=synthetic', b'APP_KEY=synthetic\n', b'APP_KEY=synthetic\r\n'):
            with self.subTest(raw=raw):
                result = subject.workspace_env_bytes(raw, enabled=False)
                self.assertTrue(result.startswith(raw))
                self.assertEqual(result.count(subject.FLAG + b'=false'), 1)

    def test_duplicate_dynamic_malformed_bom_nul_flag_values_fail_closed(self):
        for raw in (subject.FLAG + b'=false\n' + subject.FLAG + b'=true\n',
                subject.FLAG + b'=${OTHER}\n', subject.FLAG + b' yes\n',
                b'\xef\xbb\xbfAPP_KEY=synthetic\n', b'APP_KEY=synthetic\0'):
            with self.subTest(raw=raw), self.assertRaises(subject.Stop):
                subject.workspace_env_bytes(raw, enabled=True)

    def test_json_duplicate_or_nonfinite_rejected_without_values_in_error(self):
        for raw in (b'{"a":1,"a":2}', b'{"a":NaN}', b'[]'):
            with self.assertRaises(subject.Stop):
                subject.parse(raw)


if __name__ == '__main__':
    unittest.main()
