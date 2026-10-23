"""Exercise the outer hold/navigation transaction with real temporary files.

The four-slot watcher engine is an injected boundary here; its own root-file
tests cover the native exchanges. These fixtures verify when the adapter may
restore live application/hold files around that engine's reported outcome.
"""
import contextlib
import copy
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch
import uuid

REPO = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('fullscreen_transaction_subject',
    REPO / 'deployment/upgrade_go_erp_fullscreen_watcher_root.py')
subject = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(subject)


class Directory:
    def __init__(self, path, mode=None):
        self.path = Path(path)
        self.fd = os.open(self.path, os.O_RDONLY | os.O_DIRECTORY)
    def close(self):
        os.close(self.fd)


class FilesystemUtility:
    Directory = Directory
    @staticmethod
    def exact_key(info):
        return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
    @staticmethod
    def write_at(directory, name, data):
        descriptor = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=directory.fd)
        with os.fdopen(descriptor, 'wb') as output:
            output.write(data)
    @staticmethod
    def json_at(directory, name, value):
        FilesystemUtility.write_at(directory, name, subject.canonical(value))
    @staticmethod
    def exchange(left_fd, left, right_fd, right):
        # Real path/inode exchanges, with no fake content or metadata receipts.
        temporary = '.fixture-exchange-' + uuid.uuid4().hex
        os.rename(left, temporary, src_dir_fd=left_fd, dst_dir_fd=left_fd)
        os.rename(right, left, src_dir_fd=right_fd, dst_dir_fd=left_fd)
        os.rename(temporary, right, src_dir_fd=left_fd, dst_dir_fd=right_fd)


class TransactionFixture:
    def __init__(self, folder, outcome='success', nav_applied=False, race=None, advanced=False, advanced_hold=False,
            hr_navigation=False):
        self.advanced = advanced
        self.hr_navigation = hr_navigation
        self.root, self.work = folder / 'live', folder / 'work'
        self.root.mkdir(); self.work.mkdir(mode=0o700)
        self.nav = self.root / subject.NAV
        self.nav.parent.mkdir(parents=True)
        self.nav.write_bytes(b'new-navigation' if nav_applied or advanced else b'old-navigation')
        self.paymob = self.root / 'paymob-source.php'
        self.paymob.write_bytes(b'preserve the independently reviewed Paymob source')
        self.hr_files = {}; self.hud_files = {}
        protected = {'public/dashboard/js/branch-operations.js': self.hr_files,
            'public/dashboard/css/branch-operations.css': self.hr_files}
        for name in ('app/Services/Dashboard/HomeHudLinks.php', 'public/dashboard/css/home-hud.css',
                'public/dashboard/js/home-hud.js', 'resources/views/admin/home_hud_solar.blade.php',
                'resources/views/admin/home_hud.blade.php', 'public/dashboard/js/branch-home.js',
                'public/dashboard/js/home-overview.js', 'resources/views/admin/home.blade.php',
                'resources/views/admin/home_branch.blade.php', 'app/Http/Controllers/Dashboard/HomeController.php'):
            protected[name] = self.hud_files
        for name, cohort in protected.items():
            path = self.root / name; data = ('approved frozen source ' + name).encode()
            path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(data)
            path.chmod(0o640)
            cohort[path] = (data, FilesystemUtility.exact_key(path.stat()))
        self.hold = self.work / 'program-rollout-hold.json'
        self.expected_old = subject.hold_value(subject.OLD_CORE, subject.OLD_SOURCE, subject.OLD_INVENTORY)
        self.expected_new = subject.hold_value(subject.CORE, subject.HUD_AFTER_SOURCE, subject.HUD_AFTER_INVENTORY)
        initial_hold = subject.hold_value(subject.OLD_CORE, subject.ADVANCED_SOURCE, subject.ADVANCED_INVENTORY) \
            if advanced and advanced_hold else self.expected_old
        self.hold.write_bytes(subject.canonical(initial_hold)); self.hold.chmod(0o600)
        state = {'format': 1, 'base_revision': subject.OLD_SEED, 'counter': 36,
            'version_floor': '0.4.4-preview.36', 'sequence_floor': 300,
            'last_remote_sha': subject.OLD_CANDIDATE, 'observed': None, 'published': None,
            'published_history': [], 'desired': {'commit': subject.OLD_CANDIDATE,
                'version': '0.4.4-preview.36', 'inventorySha256': subject.OLD_INVENTORY,
                'pushed': True, 'status': 'waiting-ci'}}
        if advanced:
            state.update(counter=37, version_floor='0.4.4-preview.37', last_remote_sha=subject.ADVANCED_CANDIDATE,
                desired={'commit': subject.ADVANCED_CANDIDATE, 'version': '0.4.4-preview.37',
                    'inventorySha256': subject.ADVANCED_INVENTORY, 'pushed': True, 'status': 'waiting-ci'})
        self.control_paths = {name: self.work / name for name in ('config', 'state', 'unit', 'checkout')}
        self.control_paths['config'].write_bytes(subject.canonical({'base_revision': subject.OLD_SEED}))
        self.control_paths['state'].write_bytes(subject.canonical(state))
        self.control_paths['unit'].write_bytes(b'old reviewed runtime unit')
        self.control_paths['checkout'].write_bytes(subject.OLD_SEED.encode())
        self.original_controls = self.controls()
        self.events = []; self.upgrade_calls = 0; self.nav_patch = None
        self.candidate_proof = None; self.approved_generic_proof = None; self.proof_override = {}
        self.live_cohort = None
        self.outcome, self.race, self.raced = outcome, race, False
        self.utility = FilesystemUtility()
        self.owner = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())
        self.payloads, self.pins = {'pinned': b'runtime'}, {'pinned': 'a' * 40}
        self.source = types.SimpleNamespace(scan_snapshot=self.scan)
        self.worker = types.SimpleNamespace(program_gate_json=lambda path: json.loads(Path(path).read_bytes()),
            program_gate_parse=json.loads, program_gate_bytes=lambda path: Path(path).read_bytes())
        @contextlib.contextmanager
        def held_lock(utility, work):
            with open(work / 'watcher.lock', 'w+b') as lock:
                os.chmod(lock.name, 0o600)
                fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                self.events.append('lock')
                yield lock.fileno(), lambda: self.events.append('verify-lock')
                self.events.append('unlock')
        self.prior = types.SimpleNamespace(held_lock=held_lock,
            assert_quiescent=lambda: self.events.append('quiescent'),
            require_no_pending=lambda *args: None, LockedUtility=lambda utility, work, verify: utility)
        self.base = types.SimpleNamespace(normalized_source=lambda files: files['fixture_source'], upgrade=self.upgrade)

    def bind_candidate(self, state):
        # The immutable Git/content validator has separate tests. This boundary
        # admits only the exact synthetic fixture proofs explicitly registered.
        desired = state.get('desired', {})
        known = {subject.OLD_CANDIDATE: subject.OLD_INVENTORY,
            subject.ADVANCED_CANDIDATE: subject.ADVANCED_INVENTORY}
        if self.approved_generic_proof is not None:
            proof = copy.deepcopy(self.approved_generic_proof)
            subject.need(desired.get('commit') == proof['revision'], 'HELD_CANDIDATE_UNREVIEWED')
        else:
            inventory = known.get(desired.get('commit'))
            subject.need(inventory is not None, 'HELD_CANDIDATE_UNREVIEWED')
            proof = {'revision': desired['commit'], 'base_revision': subject.OLD_SEED,
                'version': desired['version'], 'inventory_sha256': inventory,
                'source': subject.CANDIDATE_SOURCES[inventory],
                'application_changed': False, 'database_read': False}
        proof.update(copy.deepcopy(self.proof_override))
        self.remote_revision = desired['commit']
        self.candidate_proof = proof
        self.events.append('candidate-proof')
        return proof

    def controls(self):
        return {name: path.read_bytes() for name, path in self.control_paths.items()}

    def slots(self):
        controls = self.controls()
        return controls, json.loads(controls['config']), json.loads(controls['state'])

    def authorize(self):
        self.raced = True
        value = copy.deepcopy(self.expected_old)
        value['authorization'] = {'candidate': 'a' * 40, 'version': '0.4.4-preview.36'}
        replacement = self.work / 'authorized-hold.tmp'
        replacement.write_bytes(subject.canonical(value)); replacement.chmod(0o600)
        os.replace(replacement, self.hold)

    def remote(self, checkout):
        self.events.append('remote-check')
        if self.advanced:
            subject.need(self.remote_revision == subject.ADVANCED_CANDIDATE, 'FIXTURE_REMOTE_REVISION_INVALID')
        if self.race == 'capture' and not self.raced: self.authorize()

    def scan(self, root, checkout, owner, previous):
        changed = self.nav.read_bytes() == b'new-navigation'
        inventory = subject.HUD_ADVANCED_INVENTORY if self.advanced else subject.HUD_AFTER_INVENTORY if changed else subject.HUD_BEFORE_INVENTORY
        source = subject.HUD_ADVANCED_SOURCE if self.advanced else subject.HUD_AFTER_SOURCE if changed else subject.HUD_BEFORE_SOURCE
        if self.live_cohort is not None:
            inventory, source = self.live_cohort
        if self.hr_navigation:
            inventory, source = (subject.HUD_AFTER_INVENTORY, subject.HUD_AFTER_SOURCE) if changed \
                else (subject.HUD_BEFORE_INVENTORY, subject.HUD_BEFORE_SOURCE)
        return {'metadata': {'seedRevision': self.control_paths['checkout'].read_text(),
            'inventorySha256': inventory}, '_files': {'fixture_source': source}}

    def snapshot(self, inventory, source, seed=subject.OLD_SEED):
        result = self.scan(None, None, None, None)
        subject.need(result['metadata']['inventorySha256'] == inventory
            and result['_files']['fixture_source'] == source
            and result['metadata']['seedRevision'] == seed, 'FIXTURE_SNAPSHOT_MISMATCH')
        self.events.append('snapshot-' + ('new' if seed == subject.NEW_SEED else 'old'))
        return result

    def confirm_old(self, before):
        self.events.append('confirm-four-old-slots')
        subject.need(self.controls() == before == self.original_controls, 'WATCHER_ROLLBACK_REQUIRES_REVIEW')
        if self.race == 'pre-exchange' and not self.raced: self.authorize()

    def upgrade(self, payloads, **kwargs):
        self.upgrade_calls += 1; self.events.append('upgrade')
        subject.need(json.loads(self.hold.read_bytes()) == self.expected_new, 'FIXTURE_NEW_HOLD_MISSING')
        subject.need(self.nav.read_bytes() == b'new-navigation', 'FIXTURE_NAV_MISSING')
        if self.outcome == 'success':
            state = subject.migrated_state(json.loads(self.original_controls['state']), reviewed=self.candidate_proof)
            self.control_paths['config'].write_bytes(subject.canonical({'base_revision': subject.NEW_SEED}))
            self.control_paths['state'].write_bytes(subject.canonical(state))
            self.control_paths['unit'].write_bytes(b'new pinned runtime unit')
            self.control_paths['checkout'].write_bytes(subject.NEW_SEED.encode())
            return {'ok': True, 'counter': state['counter'], 'rollback_complete': True}
        self.control_paths['unit'].write_bytes(b'mixed interrupted unit')
        if self.outcome == 'rollback':
            for name, value in self.original_controls.items(): self.control_paths[name].write_bytes(value)
            self.events.append('four-slot-rollback-complete')
            return {'ok': False, 'rollback_complete': True}
        return {'ok': False, 'rollback_complete': False}

    def make_patch(self, ctx, audit, lock_fd):
        fixture = self
        class NavigationPatch:
            committed = False
            def prepare(self):
                self.before = fixture.nav.read_bytes(); self.rollbacks = 0
                self.navigation_sources = ctx.navigation_sources
                (audit / 'navigation-before.bin').write_bytes(self.before)
                fixture.events.append('nav-prepare')
            def apply(self):
                fixture.nav.write_bytes(b'new-navigation'); fixture.events.append('nav-apply')
            def verify(self):
                subject.need(fixture.nav.read_bytes() == b'new-navigation', 'FIXTURE_NAV_CHANGED')
            def rollback(self):
                subject.need(fixture.controls() == fixture.original_controls, 'FIXTURE_ROLLBACK_BEFORE_FOUR_SLOTS')
                self.rollbacks += 1
                fixture.nav.write_bytes(self.before); fixture.events.append('nav-rollback')
            def commit(self):
                self.verify(); self.committed = True; fixture.events.append('nav-commit')
            def close(self):
                fixture.events.append('nav-close')
        self.nav_patch = NavigationPatch()
        return self.nav_patch


class FullscreenOuterTransactionTests(unittest.TestCase):
    def fixture(self, **kwargs):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        return TransactionFixture(Path(directory.name), **kwargs)

    def run_apply(self, fixture):
        with patch.object(subject, 'ROOT', fixture.root), patch.object(subject, 'WORK', fixture.work), \
             patch.object(subject, 'navigation_patch', fixture.make_patch):
            return subject.apply(fixture)

    def assert_paymob_preserved(self, fixture):
        self.assertEqual(b'preserve the independently reviewed Paymob source', fixture.paymob.read_bytes())
        for path, (data, key) in {**fixture.hr_files, **fixture.hud_files}.items():
            self.assertEqual(data, path.read_bytes())
            self.assertEqual(key, FilesystemUtility.exact_key(path.stat()))

    def test_success_holds_publication_and_commits_nav_after_four_slot_success(self):
        fixture = self.fixture()
        result = self.run_apply(fixture)
        self.assertTrue(result['ok']); self.assertTrue(result['publication_held'])
        self.assertFalse(result['published']); self.assertTrue(result['timer_paused'])
        self.assertFalse(result['database_changed']); self.assertTrue(result['application_contents_changed'])
        self.assertEqual(fixture.expected_new, json.loads(fixture.hold.read_bytes()))
        self.assertEqual(b'new-navigation', fixture.nav.read_bytes())
        self.assertEqual(subject.NEW_SEED, fixture.control_paths['checkout'].read_text())
        self.assertLess(fixture.events.index('upgrade'), fixture.events.index('nav-commit'))
        self.assertEqual(0, fixture.nav_patch.rollbacks)
        self.assertTrue((Path(result['backup']) / 'before-hold.json').is_file())
        self.assert_paymob_preserved(fixture)

    def test_complete_base_rollback_must_be_independently_confirmed_before_nav_and_hold_restore(self):
        fixture = self.fixture(outcome='rollback')
        with self.assertRaisesRegex(subject.Stop, '^ROLLED_BACK_TIMER_PAUSED$'): self.run_apply(fixture)
        self.assertEqual(fixture.original_controls, fixture.controls())
        self.assertEqual(fixture.expected_old, json.loads(fixture.hold.read_bytes()))
        self.assertEqual(b'old-navigation', fixture.nav.read_bytes())
        self.assertEqual(1, fixture.nav_patch.rollbacks)
        after = fixture.events.index('four-slot-rollback-complete')
        self.assertLess(after, fixture.events.index('confirm-four-old-slots', after))
        self.assertLess(fixture.events.index('confirm-four-old-slots', after), fixture.events.index('nav-rollback'))
        self.assert_paymob_preserved(fixture)

    def test_uncertain_base_rollback_retains_new_hold_and_nav_and_requires_review(self):
        fixture = self.fixture(outcome='uncertain')
        with self.assertRaisesRegex(subject.Stop, '^RECOVERY_REQUIRED_TIMER_PAUSED$'): self.run_apply(fixture)
        self.assertNotEqual(fixture.original_controls, fixture.controls())
        self.assertEqual(fixture.expected_new, json.loads(fixture.hold.read_bytes()))
        self.assertEqual(b'new-navigation', fixture.nav.read_bytes())
        self.assertEqual(0, fixture.nav_patch.rollbacks)
        self.assertNotIn('nav-commit', fixture.events)
        self.assert_paymob_preserved(fixture)

    def test_authorization_race_at_capture_is_not_overwritten_or_patched(self):
        fixture = self.fixture(race='capture')
        with self.assertRaisesRegex(subject.Stop, '^OLD_HOLD_CHANGED$'): self.run_apply(fixture)
        self.assertIsNotNone(json.loads(fixture.hold.read_bytes())['authorization'])
        self.assertEqual(b'old-navigation', fixture.nav.read_bytes())
        self.assertEqual(0, fixture.upgrade_calls); self.assertIsNone(fixture.nav_patch)
        self.assertEqual(fixture.original_controls, fixture.controls())
        self.assert_paymob_preserved(fixture)

    def test_authorization_race_before_exchange_is_preserved_and_no_base_upgrade_occurs(self):
        fixture = self.fixture(race='pre-exchange')
        with self.assertRaisesRegex(subject.Stop, '^RECOVERY_REQUIRED_TIMER_PAUSED$'): self.run_apply(fixture)
        self.assertIsNotNone(json.loads(fixture.hold.read_bytes())['authorization'])
        self.assertEqual(b'old-navigation', fixture.nav.read_bytes())
        self.assertEqual(0, fixture.upgrade_calls)
        self.assertEqual(fixture.original_controls, fixture.controls())
        self.assert_paymob_preserved(fixture)

    def test_preexisting_authorization_refuses_all_changes(self):
        fixture = self.fixture(); fixture.authorize()
        with self.assertRaisesRegex(subject.Stop, '^OLD_HOLD_DIFFERS_OR_AUTHORIZED$'): self.run_apply(fixture)
        self.assertEqual(0, fixture.upgrade_calls); self.assertIsNone(fixture.nav_patch)
        self.assertEqual(b'old-navigation', fixture.nav.read_bytes())
        self.assertEqual(fixture.original_controls, fixture.controls())

    def test_pending_outer_transaction_is_not_hidden_by_a_new_attempt(self):
        fixture = self.fixture()
        audit = fixture.work / (subject.PREFIX + 'a' * 32); audit.mkdir(mode=0o700)
        (audit / 'intent.json').write_text('{}')
        with self.assertRaisesRegex(subject.Stop, '^PREVIOUS_FULLSCREEN_TRANSACTION_REQUIRES_REVIEW$'): self.run_apply(fixture)
        self.assertEqual(0, fixture.upgrade_calls); self.assertIsNone(fixture.nav_patch)
        self.assertEqual(fixture.expected_old, json.loads(fixture.hold.read_bytes()))
        self.assertEqual(fixture.original_controls, fixture.controls())

    def test_already_applied_navigation_is_preserved_during_a_base_rollback(self):
        fixture = self.fixture(outcome='rollback', nav_applied=True)
        with self.assertRaisesRegex(subject.Stop, '^ROLLED_BACK_TIMER_PAUSED$'): self.run_apply(fixture)
        self.assertEqual(b'new-navigation', fixture.nav.read_bytes())
        self.assertIsNone(fixture.nav_patch)
        self.assertEqual(fixture.expected_old, json.loads(fixture.hold.read_bytes()))
        self.assertEqual(fixture.original_controls, fixture.controls())
        self.assert_paymob_preserved(fixture)

    def test_new_seed_retry_after_counter_37_preserves_state_without_old_remote_or_nav_mutation(self):
        fixture = self.fixture(nav_applied=True)
        state = subject.migrated_state(json.loads(fixture.original_controls['state']))
        state.update(counter=37, version_floor='0.4.4-preview.37', sequence_floor=301,
            last_remote_sha='b' * 40,
            desired={'commit': 'b' * 40, 'version': '0.4.4-preview.37',
                'inventorySha256': subject.HUD_AFTER_INVENTORY, 'pushed': True, 'status': 'waiting-ci'})
        fixture.hold.write_bytes(subject.canonical(fixture.expected_new))
        fixture.control_paths['config'].write_bytes(subject.canonical({'base_revision': subject.NEW_SEED}))
        fixture.control_paths['state'].write_bytes(subject.canonical(state))
        fixture.control_paths['unit'].write_bytes(b'new pinned runtime unit')
        fixture.control_paths['checkout'].write_bytes(subject.NEW_SEED.encode())
        before = fixture.controls()

        def refuse_old_remote(checkout):
            self.fail('A current-seed retry must not require the superseded remote candidate')

        def current_upgrade(payloads, **kwargs):
            fixture.upgrade_calls += 1
            self.assertEqual(subject.NEW_SEED, kwargs['new_seed'])
            self.assertEqual(fixture.pins, kwargs['pins'])
            self.assertEqual(before, fixture.controls())
            return {'ok': True, 'already_current': True, 'counter': 37}

        fixture.remote = refuse_old_remote
        fixture.base.upgrade = current_upgrade
        result = self.run_apply(fixture)
        self.assertTrue(result['ok']); self.assertTrue(result['already_current'])
        self.assertEqual(37, result['counter']); self.assertTrue(result['publication_held'])
        self.assertTrue(result['timer_paused']); self.assertFalse(result['published'])
        self.assertFalse(result['database_changed']); self.assertFalse(result['application_contents_changed'])
        self.assertEqual(1, fixture.upgrade_calls); self.assertIsNone(fixture.nav_patch)
        self.assertNotIn('remote-check', fixture.events)
        self.assertEqual(before, fixture.controls())
        self.assertEqual(fixture.expected_new, json.loads(fixture.hold.read_bytes()))
        self.assertEqual(b'new-navigation', fixture.nav.read_bytes())
        self.assertFalse(any(path.name.startswith(subject.PREFIX) for path in fixture.work.iterdir()))
        self.assert_paymob_preserved(fixture)

    def assert_known_advanced_migration(self, fixture):
        result = self.run_apply(fixture)
        self.assertTrue(result['ok']); self.assertTrue(result['publication_held'])
        self.assertTrue(result['timer_paused']); self.assertFalse(result['published'])
        self.assertFalse(result['application_contents_changed']); self.assertFalse(result['database_changed'])
        self.assertEqual(37, result['counter']); self.assertEqual('0.4.4-preview.38', result['next_version'])
        self.assertEqual(subject.ADVANCED_CANDIDATE, fixture.remote_revision)
        self.assertEqual(subject.HUD_ADVANCED_SOURCE, fixture.base.EXPECTED_SOURCE)
        self.assertEqual(subject.HUD_ADVANCED_INVENTORY, fixture.base.EXPECTED_INVENTORY)
        self.assertEqual(subject.hold_value(subject.CORE, subject.HUD_ADVANCED_SOURCE, subject.HUD_ADVANCED_INVENTORY),
            json.loads(fixture.hold.read_bytes()))
        migrated = json.loads(fixture.control_paths['state'].read_bytes())
        self.assertEqual(subject.NEW_SEED, migrated['base_revision'])
        self.assertEqual(37, migrated['counter']); self.assertEqual('0.4.4-preview.37', migrated['version_floor'])
        self.assertEqual(subject.ADVANCED_CANDIDATE, migrated['last_remote_sha'])
        self.assertIsNone(migrated['desired']); self.assertIsNone(migrated['observed'])
        self.assertEqual(b'new-navigation', fixture.nav.read_bytes()); self.assertIsNone(fixture.nav_patch)
        self.assertEqual(1, fixture.upgrade_calls); self.assertEqual(1, fixture.events.count('remote-check'))
        self.assert_paymob_preserved(fixture)

    def test_known_advanced_37_with_original_hold_migrates_without_repatching_navigation(self):
        self.assert_known_advanced_migration(self.fixture(advanced=True))

    def test_known_advanced_37_with_advanced_hold_migrates_without_repatching_navigation(self):
        self.assert_known_advanced_migration(self.fixture(advanced=True, advanced_hold=True))

    def test_advanced_cohort_rejects_unknown_state_hold_and_source_before_mutation(self):
        for mismatch in ('state', 'hold', 'source'):
            with self.subTest(mismatch=mismatch):
                fixture = self.fixture(advanced=True)
                if mismatch == 'state':
                    state = json.loads(fixture.control_paths['state'].read_bytes())
                    state['desired']['commit'] = state['last_remote_sha'] = 'f' * 40
                    fixture.control_paths['state'].write_bytes(subject.canonical(state))
                    reason = 'HELD_CANDIDATE_UNREVIEWED'
                elif mismatch == 'hold':
                    hold = copy.deepcopy(fixture.expected_old); hold['core'] = 'f' * 40
                    fixture.hold.write_bytes(subject.canonical(hold))
                    reason = 'OLD_HOLD_DIFFERS_OR_AUTHORIZED'
                else:
                    fixture.live_cohort = ('f' * 64, subject.ADVANCED_SOURCE)
                    reason = 'APPLICATION_COHORT_UNREVIEWED'
                controls, hold, nav = fixture.controls(), fixture.hold.read_bytes(), fixture.nav.read_bytes()
                with self.assertRaisesRegex(subject.Stop, '^' + reason + '$'): self.run_apply(fixture)
                self.assertEqual(controls, fixture.controls()); self.assertEqual(hold, fixture.hold.read_bytes())
                self.assertEqual(nav, fixture.nav.read_bytes()); self.assertIsNone(fixture.nav_patch)
                self.assertEqual(0, fixture.upgrade_calls)
                self.assertFalse(any(path.name.startswith(subject.PREFIX) for path in fixture.work.iterdir()))
                self.assert_paymob_preserved(fixture)

    def test_generic_counter_38_content_proof_preserves_hr_and_targets_preview_39(self):
        fixture = self.fixture(nav_applied=True)
        fixture.live_cohort = (subject.HUD_ADVANCED_INVENTORY, subject.HUD_ADVANCED_SOURCE)
        state = json.loads(fixture.control_paths['state'].read_bytes())
        state.update(counter=38, version_floor='0.4.4-preview.38', last_remote_sha='c' * 40,
            desired={'commit': 'c' * 40, 'version': '0.4.4-preview.38',
                'inventorySha256': subject.HR_INVENTORY, 'pushed': True, 'status': 'waiting-ci'})
        fixture.control_paths['state'].write_bytes(subject.canonical(state))
        fixture.original_controls = fixture.controls()
        fixture.approved_generic_proof = {'revision': 'c' * 40, 'base_revision': subject.OLD_SEED,
            'version': '0.4.4-preview.38', 'inventory_sha256': subject.HR_INVENTORY,
            'source': subject.HR_SOURCE, 'application_changed': False, 'database_read': False}
        result = self.run_apply(fixture)
        self.assertTrue(result['ok']); self.assertEqual(38, result['counter'])
        self.assertEqual('0.4.4-preview.39', result['next_version'])
        self.assertEqual(subject.HUD_ADVANCED_SOURCE, fixture.base.EXPECTED_SOURCE)
        self.assertEqual(subject.HUD_ADVANCED_INVENTORY, fixture.base.EXPECTED_INVENTORY)
        self.assertEqual(subject.hold_value(subject.CORE, subject.HUD_ADVANCED_SOURCE, subject.HUD_ADVANCED_INVENTORY),
            json.loads(fixture.hold.read_bytes()))
        migrated = json.loads(fixture.control_paths['state'].read_bytes())
        self.assertEqual(38, migrated['counter']); self.assertEqual('0.4.4-preview.38', migrated['version_floor'])
        self.assertEqual('c' * 40, migrated['last_remote_sha']); self.assertIsNone(migrated['desired'])
        self.assertFalse(result['application_contents_changed']); self.assertFalse(result['database_changed'])
        self.assertTrue(result['publication_held']); self.assertFalse(result['published'])
        self.assertIsNone(fixture.nav_patch); self.assertEqual(b'new-navigation', fixture.nav.read_bytes())
        self.assert_paymob_preserved(fixture)

    def test_hr_original_navigation_patches_one_file_and_verified_rollback_preserves_hr(self):
        for outcome in ('success', 'rollback'):
            with self.subTest(outcome=outcome):
                fixture = self.fixture(hr_navigation=True, outcome=outcome)
                old_hold = fixture.hold.read_bytes()
                if outcome == 'success':
                    result = self.run_apply(fixture)
                    self.assertTrue(result['ok']); self.assertTrue(result['application_contents_changed'])
                    self.assertFalse(result['database_changed']); self.assertTrue(result['publication_held'])
                    self.assertEqual(b'new-navigation', fixture.nav.read_bytes())
                    self.assertEqual(subject.hold_value(subject.CORE, subject.HUD_AFTER_SOURCE, subject.HUD_AFTER_INVENTORY),
                        json.loads(fixture.hold.read_bytes()))
                    self.assertTrue(fixture.nav_patch.committed); self.assertEqual(0, fixture.nav_patch.rollbacks)
                else:
                    with self.assertRaisesRegex(subject.Stop, '^ROLLED_BACK_TIMER_PAUSED$'): self.run_apply(fixture)
                    self.assertEqual(fixture.original_controls, fixture.controls())
                    self.assertEqual(old_hold, fixture.hold.read_bytes())
                    self.assertEqual(b'old-navigation', fixture.nav.read_bytes())
                    self.assertEqual(1, fixture.nav_patch.rollbacks)
                    restored = fixture.events.index('four-slot-rollback-complete')
                    confirmed = fixture.events.index('confirm-four-old-slots', restored)
                    self.assertLess(confirmed, fixture.events.index('nav-rollback'))
                self.assertEqual((subject.HUD_BEFORE_SOURCE, subject.HUD_AFTER_SOURCE),
                    fixture.nav_patch.navigation_sources)
                self.assertEqual(subject.HUD_AFTER_SOURCE, fixture.base.EXPECTED_SOURCE)
                self.assertEqual(subject.HUD_AFTER_INVENTORY, fixture.base.EXPECTED_INVENTORY)
                self.assertEqual(1, fixture.upgrade_calls)
                for path, (data, key) in fixture.hr_files.items():
                    self.assertEqual(data, path.read_bytes())
                    self.assertEqual(key, FilesystemUtility.exact_key(path.stat()))
                self.assert_paymob_preserved(fixture)

    def test_hr_with_our_navigation_is_preserved_without_application_patch(self):
        fixture = self.fixture(hr_navigation=True, nav_applied=True)
        nav_before = FilesystemUtility.exact_key(fixture.nav.stat())
        result = self.run_apply(fixture)
        self.assertTrue(result['ok']); self.assertFalse(result['application_contents_changed'])
        self.assertFalse(result['database_changed']); self.assertTrue(result['publication_held'])
        self.assertEqual(subject.HUD_AFTER_SOURCE, fixture.base.EXPECTED_SOURCE)
        self.assertEqual(subject.HUD_AFTER_INVENTORY, fixture.base.EXPECTED_INVENTORY)
        self.assertEqual(subject.hold_value(subject.CORE, subject.HUD_AFTER_SOURCE, subject.HUD_AFTER_INVENTORY),
            json.loads(fixture.hold.read_bytes()))
        self.assertIsNone(fixture.nav_patch); self.assertNotIn('nav-apply', fixture.events)
        self.assertEqual(nav_before, FilesystemUtility.exact_key(fixture.nav.stat()))
        for path, (data, key) in fixture.hr_files.items():
            self.assertEqual(data, path.read_bytes())
            self.assertEqual(key, FilesystemUtility.exact_key(path.stat()))
        self.assert_paymob_preserved(fixture)

    def test_native_upgrade_refuses_every_approved_historical_live_cohort_without_the_hud(self):
        for inventory, source in subject.CANDIDATE_SOURCES.items():
            if inventory in subject.LIVE_SOURCES:
                continue
            with self.subTest(inventory=inventory):
                fixture = self.fixture(nav_applied=True)
                fixture.live_cohort = (inventory, source)
                controls, hold, nav = fixture.controls(), fixture.hold.read_bytes(), fixture.nav.read_bytes()
                with self.assertRaisesRegex(subject.Stop, '^APPLICATION_COHORT_UNREVIEWED$'): self.run_apply(fixture)
                self.assertEqual(controls, fixture.controls()); self.assertEqual(hold, fixture.hold.read_bytes())
                self.assertEqual(nav, fixture.nav.read_bytes()); self.assertIsNone(fixture.nav_patch)
                self.assertEqual(0, fixture.upgrade_calls)
                self.assert_paymob_preserved(fixture)

    def test_forged_candidate_proof_refuses_before_any_application_or_control_mutation(self):
        for change in ({'revision': 'f' * 40}, {'base_revision': 'f' * 40},
                {'version': '0.4.4-preview.39'}, {'inventory_sha256': 'f' * 64},
                {'source': subject.HR_SOURCE}, {'application_changed': True}, {'database_read': True}):
            with self.subTest(change=change):
                fixture = self.fixture(); fixture.proof_override = change
                controls, hold, nav = fixture.controls(), fixture.hold.read_bytes(), fixture.nav.read_bytes()
                with self.assertRaisesRegex(subject.Stop, '^CANDIDATE_PROOF_UNREVIEWED$'): self.run_apply(fixture)
                self.assertEqual(controls, fixture.controls()); self.assertEqual(hold, fixture.hold.read_bytes())
                self.assertEqual(nav, fixture.nav.read_bytes()); self.assertIsNone(fixture.nav_patch)
                self.assertEqual(0, fixture.upgrade_calls)
                self.assertFalse(any(path.name.startswith(subject.PREFIX) for path in fixture.work.iterdir()))
                self.assert_paymob_preserved(fixture)


if __name__ == '__main__':
    unittest.main()
