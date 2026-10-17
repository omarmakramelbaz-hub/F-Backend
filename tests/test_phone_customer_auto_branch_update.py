"""Verify narrow customer branch patches and rollback without replaying earlier releases."""
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


customer_branch = module('phone_customer_branch_installer', ROOT / 'deployment/apply_phone_customer_auto_branch.py')


class PhoneCustomerBranchDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='phone-customer-branch-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Deployment Test')
        self.git('config', 'user.email', 'deployment-test@example.invalid')
        self.common = module('phone_customer_branch_utility_fixture', ROOT / 'deployment/apply_pos_inline_quantity.py')
        for name in ('install', 'build_plan', 'prove_prerequisites', 'runtime_probe'):
            setattr(self.common, name, mock.Mock(side_effect=AssertionError('old feature layer was invoked: ' + name)))
        self.backend = 'app/Services/Dashboard/PosServicePhone.php'
        self.js = 'public/dashboard/js/phone-orders.js'
        self.readonly = 'public/dashboard/css/phone-orders.css'
        self.footer = 'resources/views/admin/layouts/footer.blade.php'
        self.before = {path: self.body(path, 'Before customer branch update') for path in customer_branch.SOURCE_MANIFEST}
        self.before[self.readonly] = b'/* Independent menu and active branch layout */\n'
        self.before[self.footer] = b'{{-- Existing lifecycle footer and alert scripts --}}\n'
        self.publish(self.before)
        base = self.commit('Installed delivery refresh baseline')
        self.after = {path: (body.replace(b'Before customer branch update', b'After customer branch update')
                             if path in customer_branch.SOURCE_MANIFEST else body)
                      for path, body in self.before.items()}
        self.publish(self.after)
        self.revision = self.commit('Select the saved customer branch automatically')
        self.publish(self.before)
        self.php = self.parent / 'php'
        self.php.write_text('#!/usr/bin/env python3\nraise SystemExit(0)\n')
        self.php.chmod(0o755)
        self.addCleanup(mock.patch.stopall)
        mock.patch.object(customer_branch, 'BASE_REVISION', base).start()
        mock.patch.object(customer_branch, 'load_common', return_value=self.common).start()
        self.probe = mock.patch.object(customer_branch, 'runtime_probe', return_value=None).start()

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
            return customer_branch.install(self.root, self.revision, str(self.php), **kwargs)

    def receipts(self):
        return list((self.parent / 'phone-customer-auto-branch-backups').glob('*/receipt.json'))

    def test_check_fresh_repeat_preserve_independent_code_and_exact_backups(self):
        for path in (self.js, self.readonly, self.footer):
            self.before[path] += b'// Independent keyboard or layout customization\n'
            self.after[path] += b'// Independent keyboard or layout customization\n'
        self.publish(self.before)
        self.assertIsNone(self.install(check_only=True))
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])
        backup = self.install()
        self.assertEqual(self.snapshots(), self.after)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual([entry['path'] for entry in receipt['files']], list(customer_branch.SOURCE_MANIFEST))
        for path in customer_branch.SOURCE_MANIFEST:
            self.assertEqual((backup / path).read_bytes(), self.before[path])
            self.assertEqual((self.root / path).stat().st_uid, os.geteuid())
        self.assertIsNone(self.install())
        self.assertEqual(len(self.receipts()), 1)
        self.assertEqual(self.snapshots(), self.after)
        self.assertFalse((self.parent / 'pos-inline-quantity-backups').exists())
        self.assertFalse((self.parent / 'phone-delivery-refresh-backups').exists())

    def test_partial_update_skips_completed_leaves_and_repeats(self):
        completed = [self.backend]
        self.publish({path: self.after[path] for path in completed})
        backup = self.install()
        self.assertEqual(self.snapshots(), self.after)
        entries = {entry['path'] for entry in json.loads((backup / 'receipt.json').read_text())['files']}
        self.assertEqual(entries, set(customer_branch.SOURCE_MANIFEST) - set(completed))
        self.assertIsNone(self.install())

    def test_unknown_mutation_overlap_stops_without_publication(self):
        for path in (self.js, self.backend):
            self.publish(self.before)
            (self.root / path).write_bytes(self.before[path].replace(b'Before customer branch update', b'Unknown overlapping update'))
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
        self.probe.side_effect = RuntimeError('PHONE_CUSTOMER_SCHEMA_REQUIRED')
        with self.assertRaisesRegex(RuntimeError, 'PHONE_CUSTOMER_SCHEMA_REQUIRED'):
            self.install()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])

    def test_post_install_failure_restores_exact_originals(self):
        self.probe.side_effect = [None, RuntimeError('PHONE_CUSTOMER_ROUTE_INVALID')]
        with self.assertRaisesRegex(RuntimeError, 'PHONE_CUSTOMER_ROUTE_INVALID'):
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
        self.assertEqual(receipt['retained_dependency_group'], list(customer_branch.SOURCE_MANIFEST))

    def test_outer_private_umask_restored_public_modes_and_backups_preserved(self):
        previous = os.umask(0o077)
        try:
            self.install()
            self.assertEqual(os.umask(0o077), 0o077)
        finally:
            os.umask(previous)
        for path in customer_branch.SOURCE_MANIFEST:
            self.assertEqual(stat.S_IMODE((self.root / path).stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE((self.parent / 'phone-customer-auto-branch-backups').stat().st_mode), 0o700)


class RealSourceCompatibilityTests(unittest.TestCase):
    def test_feature_delta_preserves_latest_timing_or_earlier_refresh(self):
        common = module('phone_branch_actual_source_utility', ROOT / 'deployment/apply_pos_inline_quantity.py')
        baseline = customer_branch.BASE_REVISION
        releases = [baseline, 'a7d7b5572390bff8ca4b2503172cc63470370dae']
        source = lambda revision, path: subprocess.check_output(['git', '-C', str(ROOT), 'show', revision + ':' + path])
        before = {path: source(baseline, path) for path in customer_branch.SOURCE_MANIFEST}
        after = {path: (ROOT / path).read_bytes() for path in customer_branch.SOURCE_MANIFEST}
        for live_revision in releases:
            with self.subTest(live_revision=live_revision), tempfile.TemporaryDirectory(prefix='phone-branch-actual-') as temporary:
                parent = Path(temporary)
                root = parent / 'app'
                root.mkdir()
                def git(*args):
                    return subprocess.check_output(['git', '-C', str(root), *args], text=True).strip()
                def publish(files):
                    for path, body in files.items():
                        target = root / path
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes(body)
                git('init', '-q')
                git('config', 'user.name', 'Deployment Compatibility Test')
                git('config', 'user.email', 'deployment-test@example.invalid')
                publish(before)
                git('add', '.')
                git('commit', '-qm', 'Newest source baseline')
                base = git('rev-parse', 'HEAD')
                publish(after)
                git('add', '.')
                git('commit', '-qm', 'Actual customer branch feature delta')
                revision = git('rev-parse', 'HEAD')
                live = {path: source(live_revision, path) + b'\n// Independent live customization\n'
                        for path in customer_branch.SOURCE_MANIFEST}
                publish(live)
                php = parent / 'php'
                php.write_text('#!/usr/bin/env python3\nraise SystemExit(0)\n')
                php.chmod(0o755)
                with mock.patch.object(customer_branch, 'BASE_REVISION', base), \
                     mock.patch.object(customer_branch, 'load_common', return_value=common), \
                     mock.patch.object(customer_branch, 'runtime_probe', return_value=None), \
                     contextlib.redirect_stdout(io.StringIO()):
                    self.assertIsNone(customer_branch.install(root, revision, str(php), check_only=True))
                    self.assertEqual({path: (root / path).read_bytes() for path in live}, live)
                    backup = customer_branch.install(root, revision, str(php))
                    self.assertIsNotNone(backup)
                    for path in live:
                        self.assertEqual((backup / path).read_bytes(), live[path])
                        self.assertTrue((root / path).read_bytes().endswith(b'// Independent live customization\n'))
                    # The feature delta reverses cleanly to the entire exact live
                    # snapshot, including whichever timing release was installed.
                    reverse_stage = parent / 'reverse-proof'
                    for path in live:
                        target = reverse_stage / path
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes((root / path).read_bytes())
                    result = common.run(['git', 'apply', '--reverse', str(backup / 'release.diff')], cwd=reverse_stage)
                    self.assertEqual(result.returncode, 0, result.stderr.decode())
                    self.assertEqual({path: (reverse_stage / path).read_bytes() for path in live}, live)
                    self.assertIsNone(customer_branch.install(root, revision, str(php)))


class RuntimeProbeOutputTests(unittest.TestCase):
    def test_unknown_runtime_output_does_not_expose_details(self):
        common = module('phone_branch_probe_utility', ROOT / 'deployment/apply_pos_inline_quantity.py')
        with tempfile.TemporaryDirectory(prefix='phone-branch-probe-') as temporary:
            root = Path(temporary)
            cases = [b'not-json-with-private-details', b'{"ok":false,"reason":"PRIVATE_DATABASE_DETAILS"}', b'[]']
            for output in cases:
                with self.subTest(output=output), mock.patch.object(common, 'run', return_value=subprocess.CompletedProcess([], 1, output, b'')):
                    with self.assertRaisesRegex(RuntimeError, '^PHONE_CUSTOMER_BRANCH_RUNTIME_FAILED$'):
                        customer_branch.runtime_probe(common, root, 'php', root, 'preflight')


if __name__ == '__main__':
    unittest.main()
