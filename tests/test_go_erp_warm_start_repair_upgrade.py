"""The failed .53 repair preserves its original source, publication and authority."""
import ast
import copy
from contextlib import contextmanager
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
from go_erp_warm_start_repair_reference import REFERENCE as REPAIR_REFERENCE


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = load('repair_upgrade_subject', ROOT / 'deployment/upgrade_go_erp_warm_start_repair_root.py')
tick = load('repair_tick_subject', ROOT / 'deployment/go_erp_warm_start_repair_tick_root.py')
warm = load('repair_previous_warm_tick_tests', REPAIR_REFERENCE / 'tests/test_go_erp_warm_start_tick.py')

frozen = warm.original_tests.proof.frozen_module
OLD = warm.NEW_PAYLOADS
NEW = subject.derive_native_payloads(OLD, tick.OLD_COHORT['pins'], subject.SEED)
old_source = frozen('repair_original_source', OLD['go_erp_live_source.py'])
new_source = frozen('repair_new_source', NEW['go_erp_live_source.py'])
old_worker = frozen('repair_original_worker', OLD['go_erp_live_updates.py'])
new_worker = frozen('repair_new_worker', NEW['go_erp_live_updates.py'])
publisher = frozen('repair_actual_publisher', OLD['publish_go_erp_update.py'])


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def release():
    binary = b'MZsynthetic preserved .52 installer\n'
    digest = sha(binary)
    return {'version': subject.INPUT_VERSION, 'sequence': 40054814949,
            'source_revision': subject.INPUT_CANDIDATE,
            'source': {'format': 1, 'files': 1355, 'framework': '8.83.29', 'sha256': 'a' * 64},
            'gui': {}, 'installer': {'sha256': digest, 'bytes': len(binary),
                'path': '/api/desktop-dashboard/update/installer/' + digest}}, binary


def failed_state():
    published, _ = release()
    return {'format': 1, 'base_revision': subject.OLD_SEED, 'counter': subject.INPUT_COUNTER,
            'last_remote_sha': subject.FAILED_CANDIDATE, 'observed': None,
            'desired': {'commit': subject.FAILED_CANDIDATE, 'version': subject.FAILED_VERSION,
                'candidatePolicy': subject.OLD_POLICY, 'inventorySha256': subject.INPUT_INVENTORY,
                'pushed': True, 'status': 'retry-build', 'failures': 2, 'retry_at': 999999},
            'published': {'commit': subject.INPUT_CANDIDATE, 'inventorySha256': 'd' * 64,
                'run_id': published['sequence'], 'run_attempt': 1, 'release': published},
            'published_history': [copy.deepcopy(published)], 'version_floor': subject.INPUT_VERSION,
            'sequence_floor': published['sequence'], 'dependency_guard': {'composer.lock': 'e' * 64},
            'future_extension': {'must': ['survive', 123]}}


class FrozenPolicyTests(unittest.TestCase):
    def test_only_seed_and_v2_policy_constants_change_in_the_frozen_runtime(self):
        self.assertNotEqual(subject.POLICY, subject.OLD_POLICY)
        self.assertTrue(subject.POLICY.endswith('warm-start-accounts-v2'))
        self.assertEqual({name for name in OLD if OLD[name] != NEW[name]},
                         {'go_erp_live_source.py', 'go_erp_live_updates.py'})
        for name, raw in OLD.items():
            expected = raw
            if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
                expected = expected.replace(subject.OLD_SEED.encode(), subject.SEED.encode())
                expected = expected.replace(subject.OLD_SUFFIX, subject.SUFFIX)
            self.assertEqual(NEW[name], expected, name)
            self.assertEqual((subject.blob(NEW[name]), len(NEW[name]), sha(NEW[name])),
                             tuple(tick.NEW_COHORT['pins'][name]), name)

        def definitions(raw):
            source = raw.decode()
            return {node.name: ast.get_source_segment(source, node) for node in ast.parse(source).body
                    if isinstance(node, (ast.ClassDef, ast.FunctionDef))}
        self.assertEqual(definitions(OLD['go_erp_live_updates.py']),
                         definitions(NEW['go_erp_live_updates.py']))
        self.assertEqual(old_worker.WORKFLOWS, new_worker.WORKFLOWS)
        for name in ('publish_go_erp_update.py', 'verify_go_erp_current_candidate_ci.py',
                     'go_erp_current_candidate_ci.yml'):
            self.assertEqual(OLD[name], NEW[name])

    def test_exact_failed_candidate_and_original_capture_are_pinned(self):
        self.assertEqual(subject.FAILED_CANDIDATE, '5563faf5aa1e2e62157c4b3b9638592a02854c59')
        self.assertEqual(subject.INPUT_COUNTER, 53)
        self.assertEqual(subject.FAILED_VERSION, '0.4.4-preview.53')
        self.assertEqual(subject.INPUT_INVENTORY,
                         '525a481eb2d13dc47879b48f4a6110cbd702f2c0d5fdad5e49d663dcafe75d21')
        self.assertEqual(subject.CAPTURE_SEED, '138bc2773bf8de099b029a18550e49f0692e9806')
        self.assertEqual(subject.OLD_SEED, '9fbfa4a7bf5c5bfafb471265edc7429ec4b4f636')
        self.assertEqual(subject.OLD_CORE, 'c4229ff357c2feda3352c62b0171e2a0b1cb0d78')

    def test_bad_frozen_bytes_and_same_old_seed_are_rejected(self):
        changed = dict(OLD)
        changed['go_erp_live_updates.py'] += b'\n# drift\n'
        with self.assertRaisesRegex(subject.Stop, 'OLD_NATIVE_PAYLOAD_CHANGED'):
            subject.derive_native_payloads(changed, tick.OLD_COHORT['pins'], subject.SEED)
        with self.assertRaisesRegex(subject.Stop, 'NATIVE_RELEASE_INVALID'):
            subject.derive_native_payloads(OLD, tick.OLD_COHORT['pins'], subject.OLD_SEED)


class FailedStateTests(unittest.TestCase):
    def test_absent_feed_and_exact_old_feed_are_accepted_without_resetting_failure_state(self):
        for status in ('retry-build', 'blocked'):
            for feed_present in (False, True):
                with self.subTest(status=status, feed_present=feed_present):
                    value = failed_state()
                    value['desired']['status'] = status
                    before = copy.deepcopy(value)
                    feed = copy.deepcopy(value['published']['release']) if feed_present else None
                    self.assertEqual(subject.checked_failed_state(value, feed), value['desired'])
                    self.assertEqual(value, before)

    def test_present_different_feed_and_any_other_candidate_or_authority_are_rejected(self):
        state = failed_state()
        with self.assertRaisesRegex(subject.Stop, 'PUBLIC_FEED_NOT_CURRENT'):
            subject.checked_failed_state(state, {**state['published']['release'], 'sequence': 1})
        for key, replacement in (('base_revision', subject.SEED), ('counter', 52), ('counter', 54),
                                 ('counter', True), ('last_remote_sha', 'f' * 40),
                                 ('published_history', []), ('version_floor', '0.4.4-preview.51')):
            with self.subTest(key=key, replacement=replacement):
                changed = failed_state(); changed[key] = replacement
                with self.assertRaisesRegex(subject.Stop, 'EXACT_FAILED_WARM_START_CANDIDATE_REQUIRED'):
                    subject.checked_failed_state(changed, None)
        for key, replacement in (('commit', 'f' * 40), ('version', '0.4.4-preview.54'),
                                 ('candidatePolicy', subject.POLICY), ('inventorySha256', 'f' * 64),
                                 ('pushed', False), ('failures', 0), ('failures', True),
                                 ('retry_at', float('nan')), ('retry_at', float('inf')),
                                 ('status', 'waiting-ci'), ('status', 'prepared'), ('status', 'published')):
            with self.subTest(desired_key=key, replacement=replacement):
                changed = failed_state(); changed['desired'][key] = replacement
                with self.assertRaisesRegex(subject.Stop, 'EXACT_FAILED_WARM_START_CANDIDATE_REQUIRED'):
                    subject.checked_failed_state(changed, None)

    def test_migration_changes_only_base_and_observed_binding_including_removals(self):
        inventory = {'app/live.php': {'authority': 'live', 'present': True, 'sha256': '1' * 64,
                                      'bytes': 12, 'mode': '100644'},
                     'public/trusted.js': {'authority': 'trusted-base', 'present': True,
                                          'sha256': '2' * 64, 'bytes': 20, 'mode': '100644'},
                     'resources/removed.php': {'authority': 'removed', 'present': False,
                                               'sha256': None, 'bytes': 0, 'mode': '100644'}}
        value = failed_state(); value['observed'] = old_source._metadata(subject.OLD_SEED, inventory)
        before = copy.deepcopy(value)
        result = subject.migrate_state(value, old_source, new_source)
        self.assertEqual(value, before)
        self.assertEqual(result['base_revision'], subject.SEED)
        self.assertEqual(result['observed'], new_source._metadata(subject.SEED, inventory))
        self.assertEqual({key: item for key, item in result.items() if key not in ('base_revision', 'observed')},
                         {key: item for key, item in before.items() if key not in ('base_revision', 'observed')})
        self.assertEqual(result['desired'], before['desired'])
        for key, replacement in (('base_revision', 'f' * 40), ('counter', 52), ('counter', True)):
            changed = copy.deepcopy(before); changed[key] = replacement
            with self.assertRaisesRegex(subject.Stop, 'STATE_IDENTITY_INVALID'):
                subject.migrate_state(changed, old_source, new_source)
        rebound = copy.deepcopy(before); rebound['observed']['seedRevision'] = subject.CAPTURE_SEED
        with self.assertRaisesRegex(subject.Stop, 'OBSERVED_METADATA_UNREVIEWED'):
            subject.migrate_state(rebound, old_source, new_source)


class RootFileTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='warm-repair-files-')
        self.addCleanup(temporary.cleanup); self.path = Path(temporary.name)
        self.work = self.path / 'work'; self.control = self.path / 'control'
        self.work.mkdir(mode=0o700); (self.control / 'private/audits').mkdir(parents=True, mode=0o700)
        from go_erp_performance_reference import REFERENCE as ORIGINAL_REFERENCE
        self.engine = load('repair_actual_file_engine', ORIGINAL_REFERENCE / 'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop = subject.Stop
        for name, value in (('WORK', self.work), ('CONTROL', self.control)):
            scope = patch.object(subject, name, value); scope.start(); self.addCleanup(scope.stop)
        self.files = {'app/current.php': b'fresh website rules\n', 'public/trusted.js': b'retained vendor\n'}
        self.inventory = {name: {'authority': 'live', 'present': True, 'sha256': sha(raw),
                                'bytes': len(raw), 'mode': '100644'} for name, raw in self.files.items()}
        self.inventory['public/trusted.js']['authority'] = 'trusted-base'
        self.inventory['resources/removed.php'] = {'authority': 'removed', 'present': False,
                                                   'sha256': None, 'bytes': 0, 'mode': '100644'}
        capture = old_source._metadata(subject.CAPTURE_SEED, self.inventory)
        scope = patch.object(subject, 'INPUT_INVENTORY', capture['inventorySha256'])
        scope.start(); self.addCleanup(scope.stop)
        normal = lambda files: {'format': 1, 'files': len(files), 'framework': '8.83.29',
            'sha256': sha(json.dumps({name: sha(raw) for name, raw in sorted(files.items())}, sort_keys=True).encode())}
        self.source = normal(self.files)
        ctx = object.__new__(subject.Context); self.ctx = ctx
        ctx.e = self.engine; ctx.owner = types.SimpleNamespace(pw_gid=os.getgid())
        ctx.base = types.SimpleNamespace(parse=json.loads, normalized_source=normal)
        ctx.old_source = old_source; ctx.source = new_source; ctx.old_worker = old_worker; ctx.worker = new_worker
        ctx.publisher = publisher
        ctx.runtime = tick.NEW_COHORT['runtime']
        ctx.job_path = self.control / 'private' / ('warm-start-repair-' + subject.CORE + '.json')
        ctx.prior_job_path = self.control / 'private' / ('warm-start-' + subject.OLD_CORE + '.json')
        ctx.release = types.SimpleNamespace(file=lambda path, revision=None: subprocess.check_output(
            ['git', 'show', (revision or subject.PREVIOUS) + ':' + path], cwd=ROOT))
        self.current = failed_state(); self.current['observed'] = old_source._metadata(subject.OLD_SEED, self.inventory)
        ctx.state = lambda: self.current
        self.feed = None; ctx.publication = lambda: self.feed
        ctx.snapshot = lambda state, checkout, source: {
            'metadata': source._metadata(state['base_revision'], self.inventory), '_files': self.files}
        self.audit = self.control / 'private/audits' / ('warm-start-' + '1' * 32)
        self.audit.mkdir(mode=0o700)
        predecessor = {'format': 1, 'kind': 'go-erp-startup-session-rollout',
                       'core': '63a94e51bb6a120458623d74b46323f94438ae41',
                       'seed': subject.CAPTURE_SEED, 'phase': 'ready',
                       'source': self.current['published']['release']['source'], 'inventory': 'd' * 64,
                       'candidate': subject.INPUT_CANDIDATE, 'version': subject.INPUT_VERSION,
                       'runtime': 'policy-6333cb471cd888a59ddb00b4e6cc51327c87197200bc28edbb9eef0a97828728'}
        raw_prior = self.engine.canonical(predecessor)
        raw_capture = self.engine.canonical(capture)
        self.prior = {'format': 1, 'kind': 'go-erp-warm-start-rollout', 'core': subject.OLD_CORE,
            'seed': subject.OLD_SEED, 'runtime': subject.OLD_RUNTIME, 'phase': 'running', 'prepare_started': True,
            'candidate': subject.FAILED_CANDIDATE, 'version': subject.FAILED_VERSION,
            'inventory': subject.INPUT_INVENTORY, 'remote_lease': subject.INPUT_CANDIDATE,
            'source': self.source, 'audit': str(self.audit), 'captured_metadata_sha256': sha(raw_capture),
            'previous_journal_sha256': sha(raw_prior), 'published_inventory_at_entry': 'd' * 64,
            'baseline_counter': 52, 'baseline_remote': subject.INPUT_CANDIDATE,
            'baseline_candidate': subject.INPUT_CANDIDATE,
            'transient_failures': 1, 'public_nonce': '8' * 32}
        self.write(self.audit / 'previous-rollout.json', raw_prior, raw=True)
        self.write(self.audit / 'captured-source.json', raw_capture, raw=True)
        before = copy.deepcopy(self.current)
        before.update(base_revision=subject.CAPTURE_SEED, counter=52, last_remote_sha=subject.INPUT_CANDIDATE)
        before['observed'] = old_source._metadata(subject.CAPTURE_SEED, self.inventory)
        before['desired'].update(commit=subject.INPUT_CANDIDATE, version=subject.INPUT_VERSION,
            inventorySha256='d' * 64, status='published', failures=0, retry_at=0,
            candidatePolicy='historical-baseline-v2-native-close-reopen-program-contract-startup-session-v1')
        self.write(self.audit / 'previous-state.json', before)
        self.write(ctx.prior_job_path, self.prior)
        self.old_hold = {'format': 1, 'kind': 'go-erp-program-rollout-hold',
            **old_worker.program_gate_expected(subject.OLD_CORE, self.source, subject.INPUT_INVENTORY), 'authorization': None}
        self.write(self.work / 'program-rollout-hold.json', self.old_hold)
        shared = self.control / 'shared'; shared.mkdir(mode=0o750)
        folder = shared / 'installers'; folder.mkdir(mode=0o750)
        published, binary = release()
        self.write(shared / 'downloads.json', {'format': 1, 'kind': 'go-erp-published-downloads',
            'current': published, 'previous': None}, mode=0o640)
        self.write(folder / (published['installer']['sha256'] + '.exe'), binary, raw=True, mode=0o640)

    def write(self, path, value, raw=False, mode=0o600):
        self.engine.atomic_write(path, value if raw else self.engine.canonical(value), 0, os.getgid(), mode)

    def historical_pending(self):
        published = self.current['published']; previous = published['release']
        expected = self.ctx.worker.program_gate_expected('63a94e51bb6a120458623d74b46323f94438ae41',
            previous['source'], published['inventorySha256'])
        retained = self.work / 'program-rollout-authorizations'; retained.mkdir(mode=0o700)
        ci = self.work / 'historical-ci'; ci.mkdir(mode=0o700)
        evidence_path = self.work / 'historical-employee-evidence.json'
        self.write(evidence_path, {'historical': 'evidence bytes retained unchanged'})
        evidence = {'path': str(evidence_path), 'sha256': sha(evidence_path.read_bytes())}
        receipt = {'format': 1, 'kind': 'go-erp-program-acceptance', **expected,
            'candidate_revision': subject.INPUT_CANDIDATE, 'version': subject.INPUT_VERSION,
            'installer': previous['installer'], 'ci': {'directory': str(ci), 'run_id': published['run_id'],
                'run_attempt': published['run_attempt']}, 'employee_evidence': evidence}
        raw = self.engine.canonical(receipt); digest = sha(raw)
        receipt_path = retained / ('receipt-' + digest + '.json')
        self.write(receipt_path, raw, raw=True)
        authorization = {'candidate': subject.INPUT_CANDIDATE, 'version': subject.INPUT_VERSION,
            'installer': previous['installer'], 'release': previous, 'receipt_path': str(receipt_path),
            'receipt_sha256': digest, 'employee_evidence': evidence}
        completed = {'format': 1, 'kind': 'go-erp-program-rollout-completed', **expected,
            'release': previous, 'authorization': authorization, 'canonical_publication_verified': True}
        self.write(self.work / ('program-rollout-completed-' + expected['core'] + '.json'), completed)
        path = self.work / 'pending-program-acceptance.json'; self.write(path, raw, raw=True)
        return path, receipt_path, evidence_path

    def test_first_record_copies_original_capture_and_previous_journal_without_touching_them(self):
        old = {path: path.read_bytes() for path in self.audit.iterdir()}
        raw_job = self.ctx.prior_job_path.read_bytes(); before = copy.deepcopy(self.current)
        self.ctx.job_record(); job = self.ctx.job; audit = Path(job['audit'])
        self.assertEqual(job['remote_lease'], subject.FAILED_CANDIDATE)
        self.assertEqual(job['inventory'], subject.INPUT_INVENTORY)
        self.assertEqual(job['published_inventory_at_entry'], 'd' * 64)
        self.assertEqual(job['previous_journal_sha256'], sha(raw_job))
        self.assertEqual((audit / 'previous-rollout.json').read_bytes(), raw_job)
        self.assertEqual((audit / 'captured-source.json').read_bytes(), old[self.audit / 'captured-source.json'])
        self.assertEqual(self.ctx.captured_metadata()['seedRevision'], subject.CAPTURE_SEED)
        self.assertEqual(self.ctx.captured_metadata()['inventory'], self.inventory)
        self.assertEqual(self.ctx.prior_job_path.read_bytes(), raw_job)
        self.assertEqual({path: path.read_bytes() for path in self.audit.iterdir()}, old)
        self.assertEqual(self.current, before)
        self.assertEqual(job['candidate'], None)
        self.assertEqual(job['phase'], 'preparing')
        self.ctx.snapshot = lambda *args: (_ for _ in ()).throw(AssertionError('resume must not replan source'))
        self.ctx.job_record(); self.assertEqual(self.ctx.job, job)

    def test_previous_capture_cannot_be_reinterpreted_as_current_seed(self):
        changed = old_source._metadata(subject.OLD_SEED, self.inventory)
        raw = self.engine.canonical(changed)
        self.write(self.audit / 'captured-source.json', raw, raw=True)
        self.prior['captured_metadata_sha256'] = sha(raw); self.write(self.ctx.prior_job_path, self.prior)
        with self.assertRaisesRegex(subject.Stop, 'PREVIOUS_CAPTURE_BINDING_CHANGED'):
            self.ctx.job_record()
        self.assertFalse(self.ctx.job_path.exists())

    def test_changed_capture_old_journal_and_copied_audit_are_rejected_on_resume(self):
        self.ctx.job_record()
        copied = Path(self.ctx.job['audit']) / 'captured-source.json'
        raw = copied.read_bytes(); self.write(copied, raw + b' ', raw=True)
        with self.assertRaisesRegex(subject.Stop, 'CAPTURED_METADATA_BYTES_CHANGED'):
            self.ctx.job_record()
        self.write(copied, raw, raw=True)
        changed = copy.deepcopy(self.prior); changed['transient_failures'] = 2
        self.write(self.ctx.prior_job_path, changed)
        with self.assertRaisesRegex(subject.Stop, 'REPAIR_JOURNAL_UNREVIEWED'):
            self.ctx.job_record()

    def test_unknown_acceptance_or_authorized_old_hold_is_preserved_and_rejected(self):
        for name in ('pending-program-acceptance.json', 'program-rollout-completed-' + subject.OLD_CORE + '.json'):
            path = self.work / name; self.write(path, {'unreviewed': True}); before = path.read_bytes()
            with self.assertRaisesRegex(subject.Stop, 'PREVIOUS_ACCEPTANCE_REQUIRES_REVIEW'):
                self.ctx.job_record()
            self.assertEqual(path.read_bytes(), before); path.unlink()
            self.assertFalse(self.ctx.job_path.exists())
        authorized = copy.deepcopy(self.old_hold); authorized['authorization'] = {'unreviewed': True}
        self.write(self.work / 'program-rollout-hold.json', authorized)
        with self.assertRaisesRegex(subject.Stop, 'PREVIOUS_HOLD_UNREVIEWED'):
            self.ctx.job_record()
        self.assertFalse(self.ctx.job_path.exists())

    def test_source_drift_or_changed_published_history_prevents_a_new_record(self):
        original = self.ctx.snapshot
        self.ctx.snapshot = lambda *args: {'metadata': {'inventorySha256': 'f' * 64}, '_files': self.files}
        with self.assertRaisesRegex(subject.Stop, 'FAILED_SOURCE_CHANGED'):
            self.ctx.job_record()
        self.assertFalse(self.ctx.job_path.exists())
        self.ctx.snapshot = original
        self.current['sequence_floor'] += 1
        with self.assertRaisesRegex(subject.Stop, 'PUBLISHED_BASELINE_CHANGED'):
            self.ctx.job_record()
        self.assertFalse(self.ctx.job_path.exists())

    def test_actual_archived_installer_is_checked_while_missing_feed_remains_missing(self):
        self.assertIsNone(self.feed)
        before = {path: path.read_bytes() for path in (self.control / 'shared').rglob('*') if path.is_file()}
        self.assertEqual(self.ctx.verify_published_archive(self.current), self.current['published']['release'])
        self.assertIsNone(self.feed)
        self.assertEqual({path: path.read_bytes() for path in before}, before)
        binary = next((self.control / 'shared/installers').iterdir())
        raw = binary.read_bytes(); self.write(binary, raw[:-1] + b'!', raw=True, mode=0o640)
        with self.assertRaisesRegex(Exception, 'INSTALLER_HASH_MISMATCH'):
            self.ctx.verify_published_archive(self.current)

    def test_valid_historical_52_pending_receipt_is_copied_hash_bound_and_never_transferred(self):
        pending, receipt, evidence = self.historical_pending()
        before = {path: path.read_bytes() for path in (pending, receipt, evidence)}
        state = copy.deepcopy(self.current)
        self.assertEqual(self.ctx.quiet_hold(), pending.read_bytes())
        self.assertEqual(self.current, state)
        self.ctx.job_record()
        audit = Path(self.ctx.job['audit'])
        self.assertEqual((audit / 'previous-pending-acceptance.json').read_bytes(), before[pending])
        self.assertEqual(self.ctx.job['previous_pending_sha256'], sha(before[pending]))
        self.assertEqual({path: path.read_bytes() for path in before}, before)
        self.assertEqual(json.loads((self.work / 'program-rollout-hold.json').read_bytes())['authorization'], None)
        self.assertIsNone(self.feed)
        self.assertEqual(self.current, state)
        self.ctx.job_record()
        self.assertEqual({path: path.read_bytes() for path in before}, before)

    def test_historical_pending_requires_retained_receipt_and_employee_evidence_bytes(self):
        pending, receipt, evidence = self.historical_pending()
        original = receipt.read_bytes(); self.write(receipt, original + b' ', raw=True)
        with self.assertRaisesRegex(subject.Stop, 'PREVIOUS_ACCEPTANCE_BYTES_CHANGED'):
            self.ctx.job_record()
        self.write(receipt, original, raw=True)
        self.write(evidence, evidence.read_bytes() + b' ', raw=True)
        with self.assertRaisesRegex(Exception, 'COMPLETION_EVIDENCE_CHANGED'):
            self.ctx.job_record()
        self.assertFalse(self.ctx.job_path.exists())

    def transaction(self):
        self.ctx.payloads = NEW
        self.ctx.pins = {name: subject.blob(raw) for name, raw in NEW.items()}
        self.ctx.old_tick = types.SimpleNamespace(PINS=tick.OLD_COHORT['pins'])
        self.ctx.utility = types.SimpleNamespace()
        self.ctx.base.REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
        def git(checkout, *args, **options):
            self.assertEqual(checkout, self.work / 'checkout')
            self.assertEqual(args, ('ls-remote', '--exit-code', self.ctx.base.REMOTE,
                                   'refs/heads/codex/go-erp-live-source-20261021'))
            self.assertEqual(options, {'maximum': 4096})
            return (subject.FAILED_CANDIDATE + '\trefs/heads/codex/go-erp-live-source-20261021\n').encode()
        self.ctx.base.git = git
        def upgrade(payloads, **options):
            self.assertEqual(payloads, NEW)
            self.assertEqual(options['new_seed'], subject.SEED)
            self.assertEqual(options['core'], subject.CORE)
            self.assertEqual(options['old_pins'], {name: row[0] for name, row in tick.OLD_COHORT['pins'].items()})
            options['remote'](self.work / 'checkout')
            self.current = self.ctx.base.migrated_state(self.current, subject.OLD_SEED, subject.SEED, self.feed)
            return {'ok': True, 'audit_directory': 'native-migration-audit'}
        self.ctx.base.upgrade = upgrade
        self.ctx.live = lambda state: self.assertEqual(state['base_revision'], subject.SEED)
        self.ctx.ready = lambda state: False

    def test_migration_replaces_only_unapproved_hold_and_preserves_old_failed_state_until_worker_allocates(self):
        pending, receipt, evidence = self.historical_pending()
        historical = {path: path.read_bytes() for path in (pending, receipt, evidence, self.ctx.prior_job_path)}
        self.ctx.job_record(); before = copy.deepcopy(self.current)
        self.transaction(); self.ctx.prepare(object())
        self.assertEqual(self.current['base_revision'], subject.SEED)
        self.assertEqual(self.current['observed'], new_source._metadata(subject.SEED, self.inventory))
        self.assertEqual({key: value for key, value in self.current.items() if key not in ('base_revision', 'observed')},
                         {key: value for key, value in before.items() if key not in ('base_revision', 'observed')})
        self.assertEqual(self.current['desired']['commit'], subject.FAILED_CANDIDATE)
        self.assertEqual(self.current['desired']['retry_at'], 999999)
        self.assertEqual(self.current['counter'], 53)
        new_hold = json.loads((self.work / 'program-rollout-hold.json').read_bytes())
        self.assertEqual(new_hold, {'format': 1, 'kind': 'go-erp-program-rollout-hold',
            **new_worker.program_gate_expected(subject.CORE, self.source, subject.INPUT_INVENTORY), 'authorization': None})
        self.assertEqual({path: path.read_bytes() for path in historical}, historical)
        self.assertEqual(self.ctx.job['phase'], 'prepared')
        self.assertIsNone(self.ctx.job['candidate'])
        self.assertIsNone(self.feed)

    def test_transaction_rechecks_latched_source_before_rebinding_state(self):
        self.ctx.job_record(); before = copy.deepcopy(self.current)
        self.transaction()
        self.ctx.snapshot = lambda *args: {'metadata': {'inventorySha256': 'f' * 64}, '_files': self.files}
        with self.assertRaisesRegex(subject.Stop, 'OLD_SOURCE_CHANGED'):
            self.ctx.prepare(object())
        self.assertEqual(self.current, before)
        self.assertEqual(self.ctx.job['phase'], 'preparing')
        self.assertEqual(self.ctx.captured_metadata()['inventory'], self.inventory)

    def test_resume_cannot_migrate_after_published_metadata_drifts_from_original_audit(self):
        self.ctx.job_record(); self.transaction()
        self.current['sequence_floor'] += 1
        before = copy.deepcopy(self.current)
        held = (self.work / 'program-rollout-hold.json').read_bytes()
        with self.assertRaisesRegex(subject.Stop, 'PUBLISHED_BASELINE_CHANGED'):
            self.ctx.prepare(object())
        self.assertEqual(self.current, before)
        self.assertEqual((self.work / 'program-rollout-hold.json').read_bytes(), held)
        self.assertEqual(self.ctx.job['phase'], 'preparing')

    def test_wrong_remote_lease_leaves_old_hold_and_state_unchanged(self):
        self.ctx.job_record(); self.transaction()
        self.ctx.base.git = lambda *args, **kwargs: ('f' * 40 + '\trefs/heads/codex/go-erp-live-source-20261021\n').encode()
        before = copy.deepcopy(self.current)
        held = (self.work / 'program-rollout-hold.json').read_bytes()
        with self.assertRaisesRegex(subject.Stop, 'REMOTE_LEASE_CHANGED'):
            self.ctx.prepare(object())
        self.assertEqual(self.current, before)
        self.assertEqual((self.work / 'program-rollout-hold.json').read_bytes(), held)
        self.assertEqual(self.ctx.job['phase'], 'preparing')


class CoordinatorBackoffTests(unittest.TestCase):
    @contextmanager
    def watcher(self, *args):
        yield lambda: None

    def fixture(self):
        ctx = object.__new__(subject.Context)
        state = failed_state(); state['base_revision'] = subject.SEED
        ctx.e = object(); ctx.utility = types.SimpleNamespace(watcher_lock=self.watcher)
        ctx.job = {'candidate': None, 'phase': 'prepared', 'prepare_started': False, 'transient_failures': 0}
        ctx.state = lambda: state; ctx.live = lambda value: None; ctx.ready = lambda value: False
        ctx.publication = lambda: None; ctx.public_status = lambda *args: None; ctx.save = lambda: None
        ctx.archive_checks = []
        ctx.verify_published_archive = lambda value: ctx.archive_checks.append(copy.deepcopy(value))
        return ctx, state

    def test_exact_old_policy_backoff_reaches_original_worker_without_mutating_persisted_state(self):
        class CalledTick(Exception):
            pass
        ctx, state = self.fixture(); before = copy.deepcopy(state); calls = []
        def tick_once(allow, budget):
            calls.append((allow, budget)); raise CalledTick()
        ctx.tick = tick_once
        with patch.object(subject, 'quiet'), patch.object(subject.time, 'monotonic', return_value=1000), \
             patch.object(subject.time, 'time', return_value=1000), \
             patch.object(subject.time, 'sleep', side_effect=AssertionError('old policy backoff must not strand repair')):
            with self.assertRaises(CalledTick):
                ctx.complete()
        self.assertEqual(calls, [(True, 7200)])
        self.assertEqual(state, before)
        self.assertTrue(ctx.job['prepare_started'])
        self.assertEqual(ctx.job['baseline_candidate'], subject.FAILED_CANDIDATE)
        self.assertEqual(ctx.job['baseline_counter'], 53)
        self.assertEqual(len(ctx.archive_checks), 1)

    def test_bypass_refuses_another_old_candidate_and_preserves_new_policy_backoff(self):
        ctx, state = self.fixture(); state['last_remote_sha'] = state['desired']['commit'] = 'f' * 40
        ctx.tick = lambda *args: self.fail('unreviewed candidate must not reach worker')
        with patch.object(subject, 'quiet'), patch.object(subject.time, 'monotonic', return_value=1000), \
             patch.object(subject.time, 'time', return_value=1000):
            with self.assertRaisesRegex(subject.Stop, 'EXACT_FAILED_WARM_START_CANDIDATE_REQUIRED'):
                ctx.complete()
        ctx, state = self.fixture(); state['desired']['candidatePolicy'] = subject.POLICY
        ctx.tick = lambda *args: self.fail('new policy backoff must not be bypassed')
        class Backoff(Exception):
            pass
        with patch.object(subject, 'quiet'), patch.object(subject.time, 'monotonic', return_value=1000), \
             patch.object(subject.time, 'time', return_value=1000), \
             patch.object(subject.time, 'sleep', side_effect=Backoff):
            with self.assertRaises(Backoff):
                ctx.complete()


class OriginalAllocationTests(unittest.TestCase):
    def test_v2_policy_allocates_54_before_old_block_or_backoff_without_state_reset(self):
        original = warm.original_tests
        for old_status in ('retry-build', 'blocked'):
            with self.subTest(old_status=old_status), tempfile.TemporaryDirectory(prefix='repair-frozen-allocation-') as temporary:
                with patch.object(original, 'subject', warm.proxy_for(tick.NEW_COHORT)), \
                     patch.object(original, 'PAYLOADS', NEW):
                    fixture = original.Fixture(Path(temporary))
                    fixture.inventory = subject.INPUT_INVENTORY
                    state = fixture.worker.state
                    published, _ = release()
                    state.update(counter=53, last_remote_sha=subject.FAILED_CANDIDATE,
                        published={'commit': subject.INPUT_CANDIDATE, 'inventorySha256': 'd' * 64, 'release': published},
                        published_history=[copy.deepcopy(published)], version_floor=subject.INPUT_VERSION,
                        sequence_floor=published['sequence'])
                    state['desired'].update(commit=subject.FAILED_CANDIDATE, version=subject.FAILED_VERSION,
                        candidatePolicy=subject.OLD_POLICY, inventorySha256=subject.INPUT_INVENTORY,
                        status=old_status, failures=2, retry_at=999999)
                    fixture.worker.client.json = lambda *args, **kwargs: {'object': {'sha': subject.FAILED_CANDIDATE}}
                    preserved = copy.deepcopy({key: state[key] for key in
                        ('published', 'published_history', 'version_floor', 'sequence_floor')})
                    old_desired = copy.deepcopy(state['desired'])
                    fixture.guards(subject.INPUT_INVENTORY, allow=True)
                    self.assertEqual(fixture.worker.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
                    self.assertEqual(state['counter'], 54)
                    self.assertEqual(state['desired']['version'], '0.4.4-preview.54')
                    self.assertEqual(state['desired']['candidatePolicy'], subject.POLICY)
                    self.assertEqual(fixture.events.count('git-candidate'), 1)
                    self.assertEqual(fixture.events.count('push'), 1)
                    self.assertEqual({key: state[key] for key in preserved}, preserved)
                    self.assertEqual(old_desired['failures'], 2)
                    self.assertEqual(old_desired['retry_at'], 999999)


if __name__ == '__main__':
    unittest.main()
