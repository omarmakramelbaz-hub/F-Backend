"""Exercise additive POS policy deployment in disposable git repositories."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


INSTALLER = Path(__file__).resolve().parents[1] / 'deployment/apply_pos_first_print_policy.py'
SPEC = importlib.util.spec_from_file_location('pos_first_print_installer', INSTALLER)
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class FirstPrintDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='pos-first-print-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Deployment Test')
        self.git('config', 'user.email', 'deployment-test@example.invalid')
        self.manifest = {
            'app/Services/Dashboard/PosReceiptPrinting.php': 'A',
            'app/Services/Dashboard/TakeawayService.php': 'M',
            'public/dashboard/js/takeaway-pos.js': 'M',
        }
        self.before = {}
        self.after = {}
        for index, (relative, status) in enumerate(self.manifest.items()):
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            header = '<?php\n' if relative.endswith('.php') else ''
            body = header + ''.join('// Initial context %s\n' % n for n in range(12))
            body += '// Policy: legacy-%s\n' % index
            body += ''.join('// Trailing context %s\n' % n for n in range(12))
            self.after[relative] = body.replace('legacy-%s' % index, 'first-print-%s' % index).encode()
            self.before[relative] = body.encode() if status == 'M' else None
            if status == 'M':
                path.write_bytes(self.before[relative])
        self.git('add', '.')
        self.git('commit', '-qm', 'Base fixture')
        base = self.git('rev-parse', 'HEAD').strip()
        previous_base = installer.BASE_REVISION
        previous_manifest = installer.SOURCE_MANIFEST
        installer.BASE_REVISION = base
        installer.SOURCE_MANIFEST = self.manifest
        self.addCleanup(setattr, installer, 'BASE_REVISION', previous_base)
        self.addCleanup(setattr, installer, 'SOURCE_MANIFEST', previous_manifest)
        for relative, body in self.after.items():
            (self.root / relative).write_bytes(body)
        self.git('add', '.')
        self.git('commit', '-qm', 'First print fixture')
        self.revision = self.git('rev-parse', 'HEAD').strip()
        for relative, body in self.before.items():
            path = self.root / relative
            if body is None:
                path.unlink()
            else:
                path.write_bytes(body)
        self.php = self.parent / 'php-check'
        self.php.write_text('#!/usr/bin/env python3\nimport sys\nsys.exit(0)\n')
        self.php.chmod(0o755)

    def git(self, *arguments):
        return subprocess.check_output(['git', '-C', str(self.root), *arguments], text=True)

    def apply(self, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()):
            return installer.install(self.root, self.revision, str(self.php), **kwargs)

    def snapshots(self):
        return {path: installer.content(self.root / path) for path in self.manifest}

    def receipts(self):
        return list((self.parent / 'pos-first-print-policy-backups').glob('*/receipt.json'))

    def test_addition_preserves_unrelated_edits_and_is_idempotent(self):
        relative = 'public/dashboard/js/takeaway-pos.js'
        custom = b'// Previously deployed unrelated change\n'
        (self.root / relative).write_bytes(self.before[relative] + custom)
        backup = self.apply()
        self.assertEqual((self.root / relative).read_bytes(), self.after[relative] + custom)
        self.assertEqual((backup / relative).read_bytes(), self.before[relative] + custom)
        added = 'app/Services/Dashboard/PosReceiptPrinting.php'
        self.assertEqual((self.root / added).read_bytes(), self.after[added])
        self.assertFalse((backup / added).exists())
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['status'], 'installed')
        self.assertFalse(receipt['files'][0]['existed'])
        backups = sorted(backup.parent.iterdir())
        self.assertIsNone(self.apply())
        self.assertEqual(sorted(backup.parent.iterdir()), backups)

    def test_modified_file_conflict_leaves_new_helper_absent(self):
        relative = 'public/dashboard/js/takeaway-pos.js'
        (self.root / relative).write_bytes(self.before[relative].replace(b'legacy-2', b'other-release-2'))
        snapshots = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.apply()
        self.assertEqual(self.snapshots(), snapshots)
        self.assertEqual(self.receipts(), [])

    def test_conflicting_existing_new_helper_is_preserved(self):
        relative = 'app/Services/Dashboard/PosReceiptPrinting.php'
        (self.root / relative).write_bytes(b'<?php\n// Another independently added helper\n')
        snapshots = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.apply()
        self.assertEqual(self.snapshots(), snapshots)

    def test_post_publication_php_failure_restores_old_files_and_removes_new_helper(self):
        self.php.write_text('#!/usr/bin/env python3\nimport sys\n'
                            'sys.exit(1 if sys.argv[-1].startswith(%r) else 0)\n'
                            % (str(self.root) + '/'))
        with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID'):
            self.apply()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'rolled_back')

    def test_stage_php_failure_never_publishes(self):
        self.php.write_text('#!/usr/bin/env python3\nimport sys\nsys.exit(1)\n')
        with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID'):
            self.apply()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])

    def test_partial_install_with_helper_present_backs_up_only_pending_files(self):
        relative = 'app/Services/Dashboard/PosReceiptPrinting.php'
        (self.root / relative).write_bytes(self.after[relative])
        backup = self.apply()
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(len(receipt['files']), 2)
        self.assertNotIn(relative, [entry['path'] for entry in receipt['files']])
        self.assertEqual(self.snapshots(), self.after)

    def test_check_only_creates_no_helper_or_receipt(self):
        self.assertIsNone(self.apply(check_only=True))
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])

    def test_rollback_preserves_another_writers_new_helper(self):
        added = 'app/Services/Dashboard/PosReceiptPrinting.php'
        concurrent = b'<?php\n// Another writer changed the new helper during validation\n'
        original_lint = installer.lint_php

        def lint(php, directory):
            if directory == self.root:
                (self.root / added).write_bytes(concurrent)
                raise RuntimeError('DELIBERATE_FINAL_VALIDATION_FAILURE')
            original_lint(php, directory)

        with mock.patch.object(installer, 'lint_php', side_effect=lint):
            with self.assertRaisesRegex(RuntimeError, 'DELIBERATE_FINAL_VALIDATION_FAILURE'):
                self.apply()
        self.assertEqual((self.root / added).read_bytes(), concurrent)
        for relative, status in self.manifest.items():
            if status == 'M':
                self.assertEqual((self.root / relative).read_bytes(), self.before[relative])
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'rollback_review_required')
        self.assertEqual(receipt['review'], [added])

    def test_concurrent_consumer_keeps_its_installed_dependency_group(self):
        consumer = 'app/Services/Dashboard/TakeawayService.php'
        concurrent = self.after[consumer] + b'// Another writer changed a consumer\n'
        original_lint = installer.lint_php

        def lint(php, directory):
            if directory == self.root:
                (self.root / consumer).write_bytes(concurrent)
                raise RuntimeError('DELIBERATE_CONSUMER_VALIDATION_FAILURE')
            original_lint(php, directory)

        with mock.patch.object(installer, 'lint_php', side_effect=lint):
            with self.assertRaisesRegex(RuntimeError, 'DELIBERATE_CONSUMER_VALIDATION_FAILURE'):
                self.apply()
        self.assertEqual((self.root / consumer).read_bytes(), concurrent)
        for relative in self.manifest:
            if relative != consumer:
                self.assertEqual((self.root / relative).read_bytes(), self.after[relative],
                                 'supporting release files must remain with a retained consumer')
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'rollback_review_required')
        self.assertEqual(receipt['review'], [consumer])
        self.assertEqual(receipt['retained_dependency_group'], list(self.manifest))


if __name__ == '__main__':
    unittest.main()
