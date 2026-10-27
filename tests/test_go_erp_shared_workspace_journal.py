"""Disposable private journal writes only; no deployment, SQL, native state or service."""
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value

source = module('source_io', 'deployment/go_erp_shared_workspace_source_root.py')
subject = module('journal_subject', 'deployment/go_erp_shared_workspace_journal.py')


class Crash(BaseException):
    pass


@unittest.skipUnless(os.geteuid() == 0, 'Real root-private CAS requires a disposable root runtime')
class PrivateJournalTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='workspace-private-journal-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.current = self.root / 'coordinator.json'
        self.records = self.root / 'checkpoints'
        self.peer = self.root / 'native-state-untouched.json'
        self.peer.write_bytes(b'private fixture old reservation\n')
        self.peer.chmod(0o600)

    def journal(self, **options):
        return subject.PrivateJournal(self.current, self.records, source, lambda: None, **options)

    def test_cas_checkpoints_are_immutable_private_and_do_not_touch_native_state(self):
        journal = self.journal()
        self.assertIsNone(journal.load())
        first = {'revision': 1, 'pending': {'name': 'source_apply'}, 'private': 'synthetic'}
        first_sha = journal.commit(None, first)
        self.assertEqual(journal.load(), first)
        second = {'revision': 2, 'pending': None, 'private': 'synthetic'}
        second_sha = journal.commit(first_sha, second)
        self.assertEqual(journal.load(), second)
        self.assertEqual((self.records / ('checkpoint-' + first_sha + '.json')).read_bytes(), source.canonical(first))
        self.assertEqual((self.records / ('checkpoint-' + second_sha + '.json')).read_bytes(), source.canonical(second))
        for path in (self.current, *self.records.iterdir()):
            self.assertEqual((path.stat().st_uid, path.stat().st_gid, stat.S_IMODE(path.stat().st_mode)), (0, 0, 0o600))
        self.assertEqual(stat.S_IMODE(self.records.stat().st_mode), 0o700)
        self.assertEqual(self.peer.read_bytes(), b'private fixture old reservation\n')
        self.assertIsNone(subject.FINAL_JOURNAL_PIN)

    def test_stale_expected_pointer_cannot_overwrite_a_new_checkpoint(self):
        journal = self.journal()
        first = journal.commit(None, {'revision': 1})
        second = journal.commit(first, {'revision': 2})
        with self.assertRaisesRegex(subject.Stop, 'CAS_CONFLICT'):
            journal.commit(first, {'revision': 3})
        self.assertEqual(journal.load(), {'revision': 2})
        self.assertEqual(source.sha(self.current.read_bytes()), second)

    def test_current_pointer_without_matching_immutable_record_is_never_adopted(self):
        journal = self.journal()
        journal.commit(None, {'revision': 1, 'phase': 'source'})
        self.current.write_bytes(source.canonical({'revision': 1, 'phase': 'active'}))
        with self.assertRaisesRegex(subject.Stop, 'IMMUTABLE_CHECKPOINT_RECORD_CHANGED'):
            journal.load()
        self.assertEqual(self.peer.read_bytes(), b'private fixture old reservation\n')

    def test_process_death_after_pointer_rename_loads_pending_checkpoint_for_recovery(self):
        def checkpoint(phase):
            if phase == 'renamed':
                raise Crash()
        journal = self.journal(checkpoint=checkpoint)
        pending = {'revision': 1, 'pending': {'name': 'migration_apply', 'action_sha256': 'a' * 64}}
        with self.assertRaises(Crash):
            journal.commit(None, pending)
        recovered = self.journal().load()
        self.assertEqual(recovered, pending)
        self.assertEqual(len(list(self.records.glob('checkpoint-*.json'))), 1)

    def test_process_death_before_pointer_rename_never_advances_current(self):
        def checkpoint(phase):
            if phase == 'written':
                raise Crash()
        first = self.journal().commit(None, {'revision': 1})
        with self.assertRaises(Crash):
            self.journal(checkpoint=checkpoint).commit(first, {'revision': 2})
        self.assertEqual(self.journal().load(), {'revision': 1})
        self.assertEqual(len(list(self.records.glob('checkpoint-*.json'))), 2)

    def test_symlinks_hardlinks_or_nonprivate_modes_are_not_adopted(self):
        os.symlink(self.peer, self.current)
        with self.assertRaises(source.Stop):
            self.journal().load()
        self.current.unlink()
        os.link(self.peer, self.current)
        with self.assertRaises(source.Stop):
            self.journal().load()
        self.current.unlink()
        self.current.write_bytes(b'{}\n')
        self.current.chmod(0o644)
        with self.assertRaises(source.Stop):
            self.journal().load()

    def test_lock_failure_is_read_only_and_creates_no_journal(self):
        with self.assertRaisesRegex(subject.Stop, 'HELD_LOCK_NOT_VERIFIED'):
            subject.PrivateJournal(self.current, self.records, source, lambda: False)
        self.assertFalse(self.current.exists())
        self.assertFalse(self.records.exists())

    def test_concrete_coordinator_runs_with_real_private_cas_and_fake_approved_effects(self):
        fixtures = module('coordinator_fixture_integration', 'tests/test_go_erp_shared_workspace_coordinator.py')
        plan, pins, snapshot = fixtures.fixtures()
        private = self.journal()
        class Adapter:
            @property
            def state(self):
                return private.load()
            def load(self):
                return private.load()
            def commit(self, old_sha, state):
                return private.commit(old_sha, state)
        adapter = Adapter()
        fake = fixtures.FakeWorld(plan, pins, snapshot, adapter)
        coordinator = fixtures.subject.Coordinator(fixtures.states, plan, adapter,
            fake.approved(), compiled_pins=pins)
        for _ in range(80):
            if coordinator.step()['phase'] == 'active':
                break
        self.assertEqual(private.load()['phase'], 'active')
        self.assertGreater(len(list(self.records.glob('checkpoint-*.json'))), 20)
        self.assertEqual(self.peer.read_bytes(), b'private fixture old reservation\n')


if __name__ == '__main__':
    unittest.main()
