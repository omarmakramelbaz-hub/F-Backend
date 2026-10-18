"""Verify atomic incremental follow-up deployment and preservation of live customizations."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


followup = module('pos_followup_once_installer', ROOT / 'deployment/apply_pos_followup_once.py')
REAL_RUNTIME_PROBE = followup.runtime_probe


class PosFollowupOnceDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='pos-followup-once-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Deployment Test')
        self.git('config', 'user.email', 'deployment-test@example.invalid')
        self.common = module('pos_followup_once_utility_fixture', ROOT / 'deployment/apply_pos_inline_quantity.py')
        for name in ('install', 'build_plan', 'prove_prerequisites', 'runtime_probe'):
            setattr(self.common, name, mock.Mock(side_effect=AssertionError('old feature layer was invoked: ' + name)))
        self.backend = 'app/Services/Dashboard/PhoneDeliveryBoard.php'
        self.js = 'public/dashboard/js/phone-orders.js'
        self.dining = 'public/dashboard/js/dining-pos.js'
        self.readonly = 'public/dashboard/css/phone-orders.css'
        self.takeaway = 'public/dashboard/js/takeaway-pos.js'
        self.before = {path: self.body(path, 'Before follow-up update') for path in followup.SOURCE_MANIFEST}
        self.before[self.readonly] = b'/* Independent menu and active branch layout */\n'
        self.before[self.takeaway] = b'// Existing takeaway Enter behavior stays unchanged\n'
        self.publish(self.before)
        base = self.commit('Installed single-consent baseline')
        self.after = {path: (body.replace(b'Before follow-up update', b'After follow-up update')
                             if path in followup.SOURCE_MANIFEST else body)
                      for path, body in self.before.items()}
        self.publish(self.after)
        self.revision = self.commit('Dining Enter and phone closure single consent')
        self.publish(self.before)
        self.php = self.parent / 'php'
        self.php.write_text('#!/usr/bin/env python3\nraise SystemExit(0)\n')
        self.php.chmod(0o755)
        self.addCleanup(mock.patch.stopall)
        mock.patch.object(followup, 'BASE_REVISION', base).start()
        mock.patch.object(followup, 'UTILITY_REVISION', base).start()
        mock.patch.object(followup, 'load_common', return_value=self.common).start()
        self.probe = mock.patch.object(followup, 'runtime_probe', return_value=None).start()

    @staticmethod
    def body(path, marker):
        start = '<?php\n' if path.endswith('.php') else ''
        return (start + ''.join('// Stable prefix %s\n' % n for n in range(12))
                + '// ' + marker + '\n' + ''.join('// Stable suffix %s\n' % n for n in range(40))).encode()

    def git(self, *arguments):
        return subprocess.check_output(['git', '-C', str(self.root), *arguments], text=True)

    def commit(self, message):
        self.git('add', '.')
        self.git('commit', '-qm', message)
        return self.git('rev-parse', 'HEAD').strip()

    def publish(self, files):
        for path, body in files.items():
            target = self.root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            target.chmod(0o644)

    def snapshots(self):
        return {path: self.common.content(self.root / path) for path in self.before}

    def install(self, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()):
            return followup.install(self.root, self.revision, str(self.php), **kwargs)

    def receipts(self):
        return list((self.parent / 'pos-followup-once-backups').glob('*/receipt.json'))

    def test_check_fresh_repeat_preserve_independent_code_and_exact_backups(self):
        for path in (self.js, self.dining, self.readonly, self.takeaway):
            self.before[path] += b'// Independent keyboard or layout customization\nconst optionId = Number(p.id) || Number(p.option_id);\n'
            self.after[path] += b'// Independent keyboard or layout customization\nconst optionId = Number(p.id) || Number(p.option_id);\n'
        self.publish(self.before)
        self.assertIsNone(self.install(check_only=True))
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])
        backup = self.install()
        self.assertEqual(self.snapshots(), self.after)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual([entry['path'] for entry in receipt['files']], list(followup.SOURCE_MANIFEST))
        for path in followup.SOURCE_MANIFEST:
            self.assertEqual((backup / path).read_bytes(), self.before[path])
        self.assertIsNone(self.install())
        self.assertEqual(len(self.receipts()), 1)
        self.assertEqual(self.snapshots(), self.after)
        self.assertFalse((self.parent / 'pos-inline-quantity-backups').exists())
        self.assertFalse((self.parent / 'phone-delivery-refresh-backups').exists())

    def test_partial_update_skips_completed_leaves_and_repeats(self):
        completed = [self.backend, self.dining]
        self.publish({path: self.after[path] for path in completed})
        backup = self.install()
        self.assertEqual(self.snapshots(), self.after)
        entries = {entry['path'] for entry in json.loads((backup / 'receipt.json').read_text())['files']}
        self.assertEqual(entries, set(followup.SOURCE_MANIFEST) - set(completed))
        self.assertIsNone(self.install())

    def test_unknown_mutation_overlap_stops_without_publication(self):
        for path in (self.js, self.dining, self.backend):
            self.publish(self.before)
            (self.root / path).write_bytes(self.before[path].replace(b'Before follow-up update', b'Unknown overlapping update'))
            before = self.snapshots()
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                self.install()
            self.assertEqual(self.snapshots(), before)
            self.assertEqual(self.receipts(), [])

    def test_php_syntax_failure_blocks_all_source_changes(self):
        self.php.write_text('#!/usr/bin/env python3\nraise SystemExit(1)\n')
        with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID'):
            self.install()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])
        self.probe.assert_not_called()

    def test_runtime_preflight_failure_blocks_all_source_changes(self):
        self.probe.side_effect = RuntimeError('POS_FOLLOWUP_ONCE_SCHEMA_REQUIRED')
        with self.assertRaisesRegex(RuntimeError, 'POS_FOLLOWUP_ONCE_SCHEMA_REQUIRED'):
            self.install()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])

    def test_post_install_failure_restores_exact_originals(self):
        self.probe.side_effect = [None, RuntimeError('POS_FOLLOWUP_ONCE_ROUTE_INVALID')]
        with self.assertRaisesRegex(RuntimeError, 'POS_FOLLOWUP_ONCE_ROUTE_INVALID'):
            self.install()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'rolled_back')

    def test_concurrent_operator_edit_retains_the_source_group(self):
        def runtime(c, root, php, stage, phase):
            if phase == 'installed':
                (root / self.js).write_bytes(self.after[self.js] + b'// Concurrent operator code\n')
                raise RuntimeError('DELIBERATE_CONCURRENT_EDIT')
        self.probe.side_effect = runtime
        with self.assertRaisesRegex(RuntimeError, 'DELIBERATE_CONCURRENT_EDIT'):
            self.install()
        expected = dict(self.after)
        expected[self.js] += b'// Concurrent operator code\n'
        self.assertEqual(self.snapshots(), expected)
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'rollback_review_required')
        self.assertEqual(receipt['retained_dependency_group'], list(followup.SOURCE_MANIFEST))

    def test_outer_private_umask_restored_public_modes_and_backups_preserved(self):
        previous = os.umask(0o077)
        try:
            self.install()
            self.assertEqual(os.umask(0o077), 0o077)
        finally:
            os.umask(previous)
        for path in followup.SOURCE_MANIFEST:
            self.assertEqual(stat.S_IMODE((self.root / path).stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE((self.parent / 'pos-followup-once-backups').stat().st_mode), 0o700)

    def test_changed_file_after_preflight_blocks_publication(self):
        def change_after_preflight(c, root, php, stage, phase):
            if phase == 'preflight':
                (root / self.dining).write_bytes(self.before[self.dining] + b'// Operator edit\n')
        self.probe.side_effect = change_after_preflight
        with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_DURING_PREFLIGHT'):
            self.install()
        expected = dict(self.before)
        expected[self.dining] += b'// Operator edit\n'
        self.assertEqual(self.snapshots(), expected)
        self.assertEqual(self.receipts(), [])

    def test_atomic_publication_failure_restores_only_owned_changes(self):
        real_atomic = self.common.atomic
        publications = []
        def interrupted_atomic(target, body, mode):
            publications.append((target.relative_to(self.root).as_posix(), body))
            if len(publications) == 2:
                raise OSError('DELIBERATE_PUBLICATION_FAILURE')
            return real_atomic(target, body, mode)
        with mock.patch.object(self.common, 'atomic', side_effect=interrupted_atomic):
            with self.assertRaisesRegex(OSError, 'DELIBERATE_PUBLICATION_FAILURE'):
                self.install()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(len(publications), 3)
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'rolled_back')

    def test_symlink_target_is_rejected_before_any_publication(self):
        (self.root / self.dining).unlink()
        (self.root / self.dining).symlink_to(self.root / self.js)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.install()
        self.assertTrue((self.root / self.dining).is_symlink())
        self.assertEqual((self.root / self.js).read_bytes(), self.before[self.js])
        self.assertEqual(self.receipts(), [])

    def test_runtime_probe_timeout_and_output_are_bounded_and_redacted(self):
        with mock.patch.object(subprocess, 'run', side_effect=subprocess.TimeoutExpired(['php'], 45)) as runner:
            with self.assertRaisesRegex(RuntimeError, '^POS_FOLLOWUP_ONCE_RUNTIME_FAILED$'):
                REAL_RUNTIME_PROBE(self.common, self.root, str(self.php), self.parent, 'preflight')
            self.assertEqual(runner.call_args.kwargs['timeout'], 45)
        result = subprocess.CompletedProcess(['php'], 1, b'{"ok":false,"reason":"sensitive runtime message"}', b'secret')
        with mock.patch.object(subprocess, 'run', return_value=result):
            with self.assertRaisesRegex(RuntimeError, '^POS_FOLLOWUP_ONCE_RUNTIME_FAILED$'):
                REAL_RUNTIME_PROBE(self.common, self.root, str(self.php), self.parent, 'installed')
        result = subprocess.CompletedProcess(['php'], 0, b' ' * 4097, b'')
        with mock.patch.object(subprocess, 'run', return_value=result):
            with self.assertRaisesRegex(RuntimeError, '^POS_FOLLOWUP_ONCE_RUNTIME_FAILED$'):
                REAL_RUNTIME_PROBE(self.common, self.root, str(self.php), self.parent, 'installed')


class PinnedUtilityTests(unittest.TestCase):
    def test_pinned_utility_is_hash_verified_and_in_baseline_ancestry(self):
        real = module('unpatched_followup_installer', ROOT / 'deployment/apply_pos_followup_once.py')
        subprocess.run(['git', '-C', str(ROOT), 'merge-base', '--is-ancestor', real.UTILITY_REVISION,
                        real.BASE_REVISION], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        common = real.load_common(ROOT)
        self.assertTrue(callable(common.atomic))
        self.assertTrue(callable(common.patch_check))


if __name__ == '__main__':
    unittest.main()
