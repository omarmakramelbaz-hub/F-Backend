"""Exact predecessor selection, metadata/remote and monotonic history checks; no account or services."""
import copy
import importlib.util
import json
from pathlib import Path
import types
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('program_adapter_test', ROOT / 'deployment/upgrade_go_erp_program_watcher_root.py')
M = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(M)


class CohortTests(unittest.TestCase):
    def test_exact_two_predecessors_only(self):
        self.assertEqual(set(M.COHORTS), {'preview35', 'wire'})
        for name, cohort in M.COHORTS.items():
            self.assertEqual(M.select_cohort(cohort['seed']), name)
        for seed in [None, '', 'f' * 40, M.NEW_SEED]:
            with self.subTest(seed=seed), self.assertRaisesRegex(M.Stop, 'CONFIGURATION_SEED_UNREVIEWED'):
                M.select_cohort(seed)

    def test_raw_predecessor_pins_are_distinct_from_wire_installed_runtime(self):
        raw = {name: (ROOT / 'deployment/wire-hash-prior/payload' / name).read_bytes() for name in M.RAW_PINS}
        self.assertEqual({name: M.blob(value) for name, value in raw.items()}, M.RAW_PINS)
        with mock.patch.object(M, 'NEW_SEED', 'f' * 40):
            target = M.adapt_payloads(raw)
        self.assertEqual([name for name in sorted(raw) if raw[name] != target[name]],
                         ['go_erp_live_source.py', 'go_erp_live_updates.py'])
        for name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
            self.assertIn(b"'-native-program-center-seed-v1'", target[name])
            self.assertIn(b'f' * 40, target[name])
        changed = dict(raw); changed['go_erp_live_source.py'] += b'\n# changed\n'
        with mock.patch.object(M, 'NEW_SEED', 'f' * 40), self.assertRaisesRegex(M.Stop, '^PAYLOAD_PIN_INVALID$'):
            M.adapt_payloads(changed)
        self.assertNotEqual(M.COHORTS['wire']['pins'], M.RAW_PINS)

    def test_exact_runtime_names_match_the_reviewed_payload_rosters(self):
        import hashlib
        raw = {name: (ROOT / 'deployment/wire-hash-prior/payload' / name).read_bytes() for name in M.RAW_PINS}
        def runtime(payloads):
            value = {name: {'blob': M.blob(data), 'sha256': hashlib.sha256(data).hexdigest()}
                     for name, data in sorted(payloads.items())}
            return 'policy-' + hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
        self.assertEqual(runtime(raw), M.COHORTS['preview35']['runtime'])
        wire = {}
        for name, data in raw.items():
            if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
                data = data.replace(M.RAW_SEED.encode(), M.COHORTS['wire']['seed'].encode())
                data = data.replace(b"'-native-import422-diagnostic-seed-v1'", b"'-native-import-wire-hash-seed-v1'")
            wire[name] = data
        self.assertEqual({name: M.blob(data) for name, data in wire.items()}, M.COHORTS['wire']['pins'])
        self.assertEqual(runtime(wire), M.COHORTS['wire']['runtime'])

    def test_actual_preview35_metadata_is_reconstructed_without_a_schema_shortcut(self):
        import subprocess
        data = subprocess.check_output(['git', '-c', 'safe.directory=' + str(ROOT), 'show',
            M.COHORTS['preview35']['reference'] + ':' + M.VERSIONS[2]], cwd=ROOT)
        actual = json.loads(data)
        self.assertEqual(actual, M.candidate_metadata(M.COHORTS['preview35']['seed'], actual['inventory'], 35))

    def test_cohort_configuration_skips_identical_files(self):
        targets = {'app/new.php': 'a' * 40, 'desktop/snapshot-row-hashes.php': 'b' * 40}
        before = {'preview35': {'app/new.php': None, 'desktop/snapshot-row-hashes.php': None},
                  'wire': {'app/new.php': None, 'desktop/snapshot-row-hashes.php': 'b' * 40}}
        base = types.SimpleNamespace(); application = mock.Mock()
        with mock.patch.object(M, 'TARGET_PINS', targets), mock.patch.object(M, 'BEFORE_PINS', before):
            M.configure_cohort('wire', base, application)
        application.configure.assert_called_once_with(M.COHORTS['wire']['source'], M.NEW_SOURCE,
                                                       {'app/new.php': (None, 'a' * 40)})
        self.assertEqual(base.OLD_SEED, M.COHORTS['wire']['seed'])
        self.assertEqual(base.OLD_PINS, M.COHORTS['wire']['pins'])


class FakeGit:
    REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
    parse = staticmethod(json.loads)
    def __init__(self, cohort, cohorts):
        self.cohort = cohort; self.cohorts = cohorts
        self.revision = 'c' * 40; self.number = cohorts[cohort]['minimum']
        self.parents = [cohorts[cohort]['seed']]
        self.changed_names = []
        self.inventory = {'app/example.php': {'authority': 'live', 'present': True, 'mode': '100644',
            'bytes': 8, 'sha256': 'd' * 64}}
        self.candidate_mutation = None
    def git(self, checkout, *args, **options):
        if args[0] == 'fetch': return b''
        if args[0] == 'ls-remote': return (self.revision + '\trefs/heads/codex/go-erp-live-source-20261021\n').encode()
        if args[0] == 'cat-file':
            return ('tree ' + 'e' * 40 + '\n' + ''.join('parent ' + value + '\n' for value in self.parents) + '\nfixture\n').encode()
        if args[0] == 'diff': return b'\0'.join(value.encode() for value in self.changed_names) + b'\0'
        revision, path = args[1].split(':', 1)
        if path == M.VERSIONS[2]:
            value = M.candidate_metadata(self.cohorts[self.cohort]['seed'], self.inventory, self.number)
            if self.candidate_mutation: self.candidate_mutation(value)
        else:
            value = {'version': '0.4.4-preview.' + str(self.number if revision == self.revision else 35), 'same': True}
            if path.endswith('package-lock.json'): value['packages'] = {'': {'version': value['version']}}
        return json.dumps(value).encode()


class GuardTests(unittest.TestCase):
    def setUp(self):
        self.cohorts = copy.deepcopy(M.COHORTS)
        inventory = {'app/example.php': {'authority': 'live', 'present': True, 'mode': '100644',
            'bytes': 8, 'sha256': 'd' * 64}}
        digest = M.candidate_metadata('a' * 40, inventory, 35)['inventorySha256']
        for cohort in self.cohorts.values(): cohort['inventory'] = digest
        self.patch = mock.patch.object(M, 'COHORTS', self.cohorts); self.patch.start()
        self.active = M.ACTIVE_COHORT
        M.ACTIVE_COHORT = 'wire'
    def tearDown(self):
        M.ACTIVE_COHORT = self.active; self.patch.stop()
    def test_accepts_reviewed_version_only_candidate_for_both_parents(self):
        for name in self.cohorts:
            base = FakeGit(name, self.cohorts); guard = M.ReleaseGuard(base)
            guard(Path('/private/checkout'))
            self.assertEqual(guard.number, base.number)
            self.assertEqual(guard.candidate_sources[base.revision], self.cohorts[name]['source'])
    def test_wire_before_first_build_accepts_old_preview35_remote(self):
        base = FakeGit('preview35', self.cohorts); guard = M.ReleaseGuard(base)
        guard(Path('/private/checkout'))
        self.assertEqual(guard.number, 35)
    def test_preview35_cohort_cannot_accept_already_wire_remote(self):
        M.ACTIVE_COHORT = 'preview35'; base = FakeGit('wire', self.cohorts)
        with self.assertRaisesRegex(M.Stop, '^REMOTE_COHORT_UNREVIEWED$'):
            M.ReleaseGuard(base)(Path('/private/checkout'))
    def test_freezes_remote_revision(self):
        base = FakeGit('wire', self.cohorts); guard = M.ReleaseGuard(base)
        guard(Path('/private/checkout')); base.revision = 'b' * 40
        with self.assertRaisesRegex(M.Stop, '^REMOTE_BRANCH_CHANGED$'):
            guard(Path('/private/checkout'))
    def test_rejects_changed_code_or_noncanonical_metadata(self):
        for mutation in [lambda b: b.changed_names.append('desktop-pos/src/main.cjs'),
                         lambda b: b.parents.append('e' * 40),
                         lambda b: setattr(b, 'number', 34),
                         lambda b: setattr(b, 'candidate_mutation', lambda v: v.update(private='unexpected')),
                         lambda b: setattr(b, 'candidate_mutation', lambda v: v['packagedFiles'].update(extra='d' * 64)),
                         lambda b: setattr(b, 'candidate_mutation', lambda v: v['inventory']['app/example.php'].update(bytes=9))]:
            base = FakeGit('wire', self.cohorts); mutation(base)
            with self.subTest(mutation=mutation), self.assertRaises(M.Stop):
                M.ReleaseGuard(base)(Path('/private/checkout'))


class StateTests(unittest.TestCase):
    def setUp(self):
        self.active = M.ACTIVE_COHORT
        self.globals = (M.OLD_SEED, M.OLD_SOURCE, M.OLD_INVENTORY)
        M.ACTIVE_COHORT = 'wire'
        M.OLD_SEED = M.COHORTS['wire']['seed']; M.OLD_SOURCE = M.COHORTS['wire']['source']
        M.OLD_INVENTORY = M.COHORTS['wire']['inventory']
    def tearDown(self):
        M.ACTIVE_COHORT = self.active
        M.OLD_SEED, M.OLD_SOURCE, M.OLD_INVENTORY = self.globals
    def state(self):
        return {'format': 1, 'base_revision': M.OLD_SEED, 'counter': 42,
            'version_floor': '0.4.4-preview.41', 'sequence_floor': 1000, 'last_remote_sha': 'a' * 40,
            'observed': None, 'desired': None, 'published': None,
            'published_history': [{'preserved': 'release history'}], 'dependency_guard': {'preserved': 'pins'}}
    def guard(self):
        class G:
            revision = 'a' * 40; number = 35
            candidate_sources = {}; candidate_inventories = {}
            @staticmethod
            def validate_release(value): return value
            def candidate(self, checkout, revision):
                M.need(revision in ('a' * 40, 'b' * 40), 'REMOTE_REVISION_INVALID')
                self.candidate_sources[revision] = M.COHORTS['preview35']['source']
                self.candidate_inventories[revision] = M.COHORTS['preview35']['inventory']
                return '0.4.4-preview.35', 35
        return G()
    @staticmethod
    def base():
        def checked(value, seed): M.need(value['base_revision'] == seed, 'STATE_IDENTITY_INVALID')
        return types.SimpleNamespace(checked_state=checked)
    def migrate(self, value, publication=None):
        return M.migrated_state(self.base(), self.guard(), Path('/private/checkout'), value,
                                M.OLD_SEED, M.NEW_SEED, publication)
    def test_migration_before_wire_worker_preserves_all_consumed_floors(self):
        value = self.state(); original = copy.deepcopy(value)
        result = self.migrate(value)
        self.assertEqual(value, original)
        self.assertEqual((result['counter'], result['version_floor'], result['sequence_floor']),
                         (42, '0.4.4-preview.42', 1000))
        for key in ['published_history', 'dependency_guard']: self.assertEqual(result[key], original[key])
    def test_older_public35_feed_is_validated_and_floor_advanced(self):
        public = {'version': '0.4.4-preview.35', 'source_revision': 'b' * 40,
            'source': M.COHORTS['preview35']['source'], 'sequence': 1200}
        result = self.migrate(self.state(), public)
        self.assertEqual(result['sequence_floor'], 1200)
    def test_published35_history_validates_exact_original_source(self):
        value = self.state(); value['published'] = {'commit': 'b' * 40,
            'inventorySha256': M.COHORTS['preview35']['inventory'],
            'release': {'version': '0.4.4-preview.35', 'source_revision': 'b' * 40,
                'source': M.COHORTS['preview35']['source'], 'sequence': 900}}
        self.assertIsNone(self.migrate(value)['published'])
        value['published']['release']['source'] = M.COHORTS['wire']['source']
        with self.assertRaisesRegex(M.Stop, '^PUBLISHED_HISTORY_UNREVIEWED$'): self.migrate(value)
    def test_missing_public_feed_keeps_validated_published_sequence(self):
        value = self.state(); value['published'] = {'commit': 'b' * 40,
            'inventorySha256': M.COHORTS['preview35']['inventory'],
            'release': {'version': '0.4.4-preview.35', 'source_revision': 'b' * 40,
                'source': M.COHORTS['preview35']['source'], 'sequence': 1500}}
        self.assertEqual(self.migrate(value)['sequence_floor'], 1500)

    def test_wrong_observed_source_or_counter_is_refused(self):
        for mutation in [lambda v: v.update(observed={'seedRevision': M.OLD_SEED,
                              'inventorySha256': M.COHORTS['preview35']['inventory']}),
                         lambda v: v.update(counter=34), lambda v: v.update(last_remote_sha='c' * 40),
                         lambda v: v.update(version_floor='0.4.4-preview.43')]:
            value = self.state(); mutation(value)
            with self.subTest(mutation=mutation), self.assertRaises(M.Stop): self.migrate(value)


# The adapter deliberately reuses the audited transaction/lock engine. Execute its
# real lock lifetime, rollback ordering and commit-failure cases on this adapter.
_prior_spec = importlib.util.spec_from_file_location('program_prior_lock_cases', ROOT / 'tests/fixtures/program_prior_watcher.py')
_prior_cases = importlib.util.module_from_spec(_prior_spec); _prior_spec.loader.exec_module(_prior_cases)
_prior_cases.M = M


class ProgramLockTests(_prior_cases.LockTests):
    def test_new_program_audit_prefix_is_also_blocked(self):
        import os
        import tempfile
        utility = self.utility()
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory); os.chmod(work, 0o700)
            audit = work / ('program-application-' + 'f' * 32); audit.mkdir(mode=0o700)
            (audit / 'application-intent.json').write_text('{}')
            base = types.SimpleNamespace(PREFIX='retained-', pending_audit=lambda *_: None)
            with self.assertRaisesRegex(M.Stop, '^PRIOR_MIGRATION_REQUIRES_REVIEW$'):
                M.require_no_pending(base, utility, work)
            self.assertEqual(base.PREFIX, 'retained-')


class EmployeeMigrationOrderingTests(unittest.TestCase):
    def transaction(self, schema, watcher=None, commit_fails=False):
        import os
        import tempfile
        events=[]
        class Patch:
            def __init__(self,*args): pass
            def prepare(self):events.append('prepare')
            def apply(self):events.append('apply')
            def verify(self):events.append('verify')
            def rollback(self):events.append('source-rollback')
            def commit(self):
                events.append('commit')
                if commit_fails:raise RuntimeError('private cleanup failure')
        def upgrade(*args,**kwargs):events.append('watcher');return watcher or {'ok':True,'counter':40}
        def migrate(audit):events.append('migration');return schema
        with tempfile.TemporaryDirectory() as temp,mock.patch.object(M,'WORK',Path(temp)):
            try:
                return M.run_transaction(types.SimpleNamespace(upgrade=upgrade),types.SimpleNamespace(),
                    types.SimpleNamespace(ApplicationPatch=Patch),Path(temp),types.SimpleNamespace(pw_uid=0,pw_gid=0),
                    None,lambda:None,{}, {},None,lambda:events.append('watcher-rollback-confirmed'),migrate),events
            except BaseException as error:error.fixture_events=events;raise
    def test_migration_between_applied_source_and_watcher_retains_database_success(self):
        result,events=self.transaction({'ok':True,'database_changed':True})
        self.assertLess(events.index('apply'),events.index('migration'));self.assertLess(events.index('migration'),events.index('watcher'))
        self.assertIs(result['database_changed'],True);self.assertTrue(result['publication_held']);self.assertTrue(result['timer_paused'])
    def test_failed_ddl_never_enters_watcher_and_preserves_unknown_after_source_rollback(self):
        with self.assertRaisesRegex(M.Stop,'EMPLOYEE_MIGRATION_REQUIRES_REVIEW') as caught:self.transaction({'ok':False,'database_changed':'unknown'})
        self.assertEqual(caught.exception.database_changed,'unknown');self.assertNotIn('watcher',caught.exception.fixture_events)
        self.assertIn('source-rollback',caught.exception.fixture_events)
    def test_watcher_source_rollback_cannot_claim_ddl_rollback(self):
        result,events=self.transaction({'ok':True,'database_changed':True},{'ok':False,'rollback_complete':True})
        self.assertIs(result['database_changed'],True);self.assertFalse(result['application_contents_changed'])
        self.assertIn('source-rollback',events)
    def test_commit_failure_keeps_true_database_change_for_public_error(self):
        with self.assertRaisesRegex(M.Stop,'APPLICATION_COMMIT_REQUIRES_REVIEW') as caught:self.transaction({'ok':True,'database_changed':True},commit_fails=True)
        self.assertIs(caught.exception.database_changed,True);self.assertNotIn('source-rollback',caught.exception.fixture_events)
    def test_uncertain_watcher_rollback_reports_installed_application_and_database_additions(self):
        result,events=self.transaction({'ok':True,'database_changed':True},{'ok':False,'rollback_complete':False,'application_contents_changed':False})
        self.assertIs(result['application_contents_changed'],True);self.assertIs(result['database_changed'],True)
        self.assertNotIn('source-rollback',events)


if __name__ == '__main__': unittest.main()
