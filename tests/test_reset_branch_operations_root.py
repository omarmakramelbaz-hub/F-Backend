"""Clearing the trial operations: the backup is on disk before anything is cleared, and only it can be put back."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('branch_reset', ROOT / 'deployment/reset_branch_operations_root.py')
reset = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(reset)

FINGERPRINT = {'takeaway_orders': [3, 41], 'takeaway_tills.balance_cents': [1, '27100', 1]}
BACKUP = {'format': 1, 'employees': False, 'tables': {'takeaway_orders': [{'id': 39}, {'id': 40}, {'id': 41}]},
          'zeroed': {'takeaway_tills': {'column': 'balance_cents', 'values': {'1': 27100}}}, 'sequences': [], 'fingerprint': FINGERPRINT}


class FakePhp:
    def __init__(self, folder):
        self.folder = folder
        self.calls = []
        self.applied = None

    def __call__(self, mode, payload=None, flags=()):
        self.calls.append((mode, list(flags)))
        if mode == 'plan':
            return {'cleared': {'takeaway_orders': {'rows': 3}}, 'zeroed': {}, 'order_numbers_restarting': 1, 'kept': {}, 'missing': []}
        if mode == 'export':
            return BACKUP
        if mode == 'apply':
            # The copy is already on disk when the clearing is asked for.
            backups = list(self.folder.glob('backup-*.json'))
            assert len(backups) == 1 and json.loads(backups[0].read_text())['fingerprint'] == FINGERPRINT
            self.applied = json.loads(payload.decode('utf-8'))
            return {'deleted': {'takeaway_orders': 3}, 'zeroed': {'takeaway_tills': 1}, 'order_numbers_restarted': 1}
        if mode == 'restore':
            return {'restored': {'takeaway_orders': len(json.loads(payload.decode('utf-8'))['tables']['takeaway_orders'])}}
        raise AssertionError(mode)


class BranchResetTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-reset-')
        self.folder = Path(self.temporary.name) / 'go-erp-branch-reset'
        self.php = FakePhp(self.folder)

    def tearDown(self):
        self.temporary.cleanup()

    def test_the_plan_changes_nothing(self):
        result = reset.plan(self.php)
        self.assertEqual(result['result'], 'GO_ERP_BRANCH_RESET_PLAN')
        self.assertEqual(self.php.calls, [('plan', [])])
        self.assertFalse(self.folder.exists())

    def test_apply_keeps_the_backup_first_then_clears_only_what_it_holds(self):
        chosen = reset.flags(employees=True, through='2026-11-03')
        result = reset.apply(self.php, self.folder, chosen)
        self.assertEqual(self.php.calls, [('export', ['--employees', '--through=2026-11-03']), ('apply', ['--employees', '--through=2026-11-03'])])
        self.assertEqual(self.php.applied, FINGERPRINT, 'The clearing checks the tables still match the backup')
        self.assertEqual(result['result'], 'GO_ERP_BRANCH_RESET_DONE')
        self.assertEqual((result['backup_rows'], result['deleted'], result['order_numbers_restarted']), (3, {'takeaway_orders': 3}, 1))
        backup = Path(result['backup'])
        self.assertEqual(stat.S_IMODE(self.folder.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o600)
        self.assertEqual(json.loads(backup.read_text())['tables'], BACKUP['tables'])

    def test_only_a_backup_of_this_script_is_put_back(self):
        done = reset.apply(self.php, self.folder)
        result = reset.restore(self.php, self.folder, done['backup'])
        self.assertEqual(result, {'result': 'GO_ERP_BRANCH_RESET_RESTORED', 'backup': done['backup'], 'restored': {'takeaway_orders': 3}})
        elsewhere = Path(self.temporary.name) / 'backup-elsewhere.json'
        elsewhere.write_text(json.dumps(BACKUP))
        with self.assertRaises(reset.Stop) as stopped:
            reset.restore(self.php, self.folder, str(elsewhere))
        self.assertEqual(str(stopped.exception), 'GO_ERP_BRANCH_RESET_NOT_A_BACKUP_OF_THIS_SCRIPT')

    def test_an_answer_of_the_php_part_that_is_an_error_stops_it(self):
        def failing(mode, payload=None, flags=()):
            raise reset.Stop('GO_ERP_BRANCH_RESET_PHP: CHANGED_SINCE_BACKUP')
        with self.assertRaises(reset.Stop):
            reset.apply(failing, self.folder)

    def test_the_options_reach_the_php_part_and_a_day_must_be_a_day(self):
        self.assertEqual(reset.flags(), [])
        self.assertEqual(reset.flags(employees=True, keep_stock=True, through='2026-11-03'), ['--employees', '--keep-stock', '--through=2026-11-03'])
        for wrong in ('2026-13-01', '3-11-2026', '2026-11-03; rm', ''):
            with self.assertRaises((reset.Stop, ValueError)):
                reset.flags(through=wrong)
        backup = dict(BACKUP, employees=True)
        self.folder.mkdir(mode=0o700)
        os.chmod(str(self.folder), 0o700)
        saved = self.folder / 'backup-20261103-000000.json'
        saved.write_text(json.dumps(backup))
        reset.restore(self.php, self.folder, str(saved))
        self.assertEqual(self.php.calls[-1], ('restore', ['--employees']), 'A backup is put back with the tables it was taken with')

    def test_the_backup_folder_must_be_roots_alone(self):
        self.folder.mkdir(mode=0o755)
        os.chmod(str(self.folder), 0o755)
        with self.assertRaises(reset.Stop) as stopped:
            reset.apply(self.php, self.folder)
        self.assertEqual(str(stopped.exception), 'GO_ERP_BRANCH_RESET_BACKUP_FOLDER')


if __name__ == '__main__':
    unittest.main()
