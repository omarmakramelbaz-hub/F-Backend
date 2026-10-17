"""Check exact phone patches and the preceding first-print installation chain."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]


def load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


phone = load('phone_update_installer', 'deployment/apply_phone_preparation_consent.py')
prior = load('phone_update_first_print_installer', 'deployment/apply_pos_first_print_policy.py')


class PhonePreparationDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='phone-consent-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Deployment Test')
        self.git('config', 'user.email', 'deployment-test@example.invalid')
        self.helper = 'app/Services/Dashboard/PosReceiptPrinting.php'
        self.access = 'app/Services/Dashboard/TakeawayAccess.php'
        self.controller = 'app/Http/Controllers/Dashboard/PosServiceController.php'
        self.client = 'public/dashboard/js/phone-orders.js'
        self.css = 'public/dashboard/css/phone-orders.css'
        self.prior_manifest = {self.helper: 'A', self.access: 'M', self.controller: 'M', self.client: 'M'}
        self.phone_manifest = {self.controller: 'M', self.client: 'M', self.css: 'M'}
        self.old = {}
        self.first_print = {}
        self.latest = {}
        for index, relative in enumerate(dict.fromkeys((*self.prior_manifest, *self.phone_manifest))):
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            header = '<?php\n' if relative.endswith('.php') else ''
            body = header + ''.join('// Stable leading context %s\n' % n for n in range(12))
            body += '// Policy: original-%s\n' % index
            body += ''.join('// Stable trailing context %s\n' % n for n in range(12))
            self.old[relative] = None if relative == self.helper else body.encode()
            first = body.replace('original-%s' % index, 'first-print-%s' % index) if relative in self.prior_manifest else body
            self.first_print[relative] = first.encode()
            # Overlap the same old hunk deliberately: naive prior-installer reruns fail.
            self.latest[relative] = first.replace('first-print-%s' % index, 'phone-consent-%s' % index).replace('original-%s' % index, 'phone-consent-%s' % index).encode() if relative in self.phone_manifest else first.encode()
        self.publish(self.old)
        self.commit('Simulated last-confirmed 938 release')
        old_revision = self.git('rev-parse', 'HEAD').strip()
        self.publish(self.first_print)
        self.commit('Simulated first-print 43 release')
        self.prior_revision = self.git('rev-parse', 'HEAD').strip()
        self.publish(self.latest)
        self.commit('Simulated new phone release')
        self.revision = self.git('rev-parse', 'HEAD').strip()
        self.publish(self.first_print)
        for module, values in ((phone, {'BASE_REVISION': self.prior_revision,
                                      'FIRST_PRINT_BASE_REVISION': old_revision,
                                      'FIRST_PRINT_REVISION': self.prior_revision,
                                      'FIRST_PRINT_MANIFEST': self.prior_manifest,
                                      'SOURCE_MANIFEST': self.phone_manifest}),
                               (prior, {'BASE_REVISION': old_revision,
                                        'SOURCE_MANIFEST': self.prior_manifest})):
            for name, value in values.items():
                previous = getattr(module, name)
                setattr(module, name, value)
                self.addCleanup(setattr, module, name, previous)
        self.php = self.parent / 'php-check'
        self.php.write_text('#!/usr/bin/env python3\nimport sys\nsys.exit(0)\n')
        self.php.chmod(0o755)

    def git(self, *arguments):
        return subprocess.check_output(['git', '-C', str(self.root), *arguments], text=True)

    def commit(self, message):
        self.git('add', '.')
        self.git('commit', '-qm', message)

    def publish(self, files):
        for relative, body in files.items():
            path = self.root / relative
            if body is None:
                path.unlink(missing_ok=True)
            else:
                path.write_bytes(body)

    def snapshots(self):
        return {relative: phone.content(self.root / relative) for relative in self.old}

    def proof(self):
        return phone.first_print_installed(self.root, self.revision, str(self.php))

    def chain(self):
        with contextlib.redirect_stdout(io.StringIO()):
            installed_prior = not self.proof()
            if installed_prior:
                prior.install(self.root, self.prior_revision, str(self.php))
            phone.install(self.root, self.revision, str(self.php))
        return installed_prior

    def phone_receipts(self):
        return list((self.parent / 'phone-preparation-consent-backups').glob('*/receipt.json'))

    def test_43_to_phone_preserves_unrelated_live_edits(self):
        custom = b'// Unrelated live customization\n'
        (self.root / self.client).write_bytes(self.first_print[self.client] + custom)
        (self.root / self.access).write_bytes(self.first_print[self.access] + custom)
        self.assertFalse(self.chain())
        expected = dict(self.latest)
        for path in (self.client, self.access):
            expected[path] += custom
        self.assertEqual(self.snapshots(), expected)
        self.assertEqual(len(self.phone_receipts()), 1)

    def test_fresh_938_runs_first_print_then_phone(self):
        self.publish(self.old)
        self.assertFalse(self.proof())
        self.assertTrue(self.chain())
        self.assertEqual(self.snapshots(), self.latest)
        self.assertEqual(len(list((self.parent / 'pos-first-print-policy-backups').glob('*/receipt.json'))), 1)
        self.assertEqual(len(self.phone_receipts()), 1)

    def test_current_phone_rerun_skips_overlapping_old_installer(self):
        self.chain()
        with contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                prior.install(self.root, self.prior_revision, str(self.php), check_only=True)
        before = self.snapshots()
        self.assertTrue(self.proof())
        self.assertFalse(self.chain())
        self.assertEqual(self.snapshots(), before)
        self.assertEqual(len(self.phone_receipts()), 1)

    def test_partial_phone_rerun_finishes_remaining_paths(self):
        (self.root / self.client).write_bytes(self.latest[self.client])
        self.assertTrue(self.proof())
        self.assertFalse(self.chain())
        self.assertEqual(self.snapshots(), self.latest)
        receipt = json.loads(self.phone_receipts()[0].read_text())
        self.assertEqual(len(receipt['files']), 2)

    def test_unknown_overlap_stops_both_steps_without_source_changes(self):
        (self.root / self.controller).write_bytes(self.first_print[self.controller].replace(b'first-print-', b'independent-'))
        before = self.snapshots()
        self.assertFalse(self.proof())
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.chain()
        self.assertEqual(self.snapshots(), before)
        self.assertEqual(self.phone_receipts(), [])

    def test_direct_phone_install_requires_preceding_policy(self):
        self.publish(self.old)
        before = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'FIRST_PRINT_PREREQUISITE_REQUIRED'):
            phone.install(self.root, self.revision, str(self.php))
        self.assertEqual(self.snapshots(), before)

    def test_post_publication_failure_restores_43_and_retains_prior_helper(self):
        original_lint = phone.lint_php

        def lint(php, directory, paths=None):
            if directory == self.root and paths is None:
                raise RuntimeError('DELIBERATE_FINAL_LINT_FAILURE')
            return original_lint(php, directory, paths)

        with contextlib.redirect_stdout(io.StringIO()), mock.patch.object(phone, 'lint_php', side_effect=lint):
            with self.assertRaisesRegex(RuntimeError, 'DELIBERATE_FINAL_LINT_FAILURE'):
                phone.install(self.root, self.revision, str(self.php))
        self.assertEqual(self.snapshots(), self.first_print)
        self.assertEqual(json.loads(self.phone_receipts()[0].read_text())['status'], 'rolled_back')

    def test_proof_and_check_only_do_not_mutate_source(self):
        before = self.snapshots()
        self.assertTrue(self.proof())
        self.assertEqual(self.snapshots(), before)
        with contextlib.redirect_stdout(io.StringIO()):
            phone.install(self.root, self.revision, str(self.php), check_only=True)
        self.assertEqual(self.snapshots(), before)
        self.assertEqual(self.phone_receipts(), [])


if __name__ == '__main__':
    unittest.main()
