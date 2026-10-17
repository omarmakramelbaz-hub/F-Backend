"""Check the two deployment layers together and preserve independent phone changes."""
import contextlib
import importlib.util
import io
import json
import os
import stat
from pathlib import Path
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


refresh = module('phone_refresh_installer', ROOT / 'deployment/apply_phone_delivery_refresh.py')
VERIFIED_QUANTITY_LOADER = refresh.load_quantity


class PhoneRefreshDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='phone-refresh-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Deployment Test')
        self.git('config', 'user.email', 'deployment-test@example.invalid')
        self.js = 'public/dashboard/js/phone-orders.js'
        self.css = 'public/dashboard/css/phone-orders.css'
        self.new_css = 'public/dashboard/css/phone-delivery-refresh.css'
        self.view = 'resources/views/admin/phone_orders/index.blade.php'
        self.helper = 'app/Services/Dashboard/OrderBoardPrinting.php'
        self.quantity_asset = 'public/dashboard/js/order-board-detail.js'
        self.guard = 'app/Services/Dashboard/TakeawayService.php'
        self.common = module('quantity_refresh_fixture', ROOT / 'deployment/apply_pos_inline_quantity.py')
        lines = ['// Stable source %s' % n for n in range(70)]
        lines[10] = '// Quantity: old'
        lines[35] = '// Timer: old'
        lines[60] = '// Independent keyboard: Enter behavior'
        lines[65] = '// Independently removed collection approval'
        self.before = {self.js: ('\n'.join(lines) + '\n').encode(), self.new_css: None,
                       self.css: b'/* Current separately released menu sizing */\n.ph-products{gap:17px}\n',
                       self.view: b'@extends("admin.index")\n' + b'{{-- Stable view line --}}\n' * 15,
                       self.helper: None, self.quantity_asset: None, self.guard: b'<?php\n// Final consent: old\n'}
        self.publish(self.before)
        base = self.commit('Before quantity update')
        self.quantity_after = dict(self.before)
        self.quantity_after[self.js] = self.before[self.js].replace(b'Quantity: old', b'Quantity: installed')
        self.quantity_after[self.helper] = b'<?php\n// Existing quantity helper\n'
        self.quantity_after[self.quantity_asset] = b'// Existing public quantity editor asset\n'
        self.publish(self.quantity_after)
        self.quantity_revision = self.commit('Pinned quantity release')
        self.after = dict(self.quantity_after)
        self.after[self.js] = self.quantity_after[self.js].replace(b'Timer: old', b'Timer: installed')
        self.after[self.new_css] = b'.ph-preparation-timer{color:green}\n'
        self.after[self.guard] = self.before[self.guard].replace(b'Final consent: old', b'Final consent: required service only')
        self.after[self.view] = self.before[self.view].replace(b'@extends("admin.index")\n',
            b'@extends("admin.index")\n<link data-refresh-css>\n')
        self.publish(self.after)
        self.revision = self.commit('Phone refresh and preparing timer')
        self.publish(self.before)
        self.common.BASE_REVISION = base
        self.common.SOURCE_MANIFEST = {self.helper: ('new', 'A'), self.quantity_asset: ('new', 'A'), self.js: ('live', 'M')}
        self.common.prove_prerequisites = mock.Mock()

        def quantity_plan(root, revision):
            plan = {}
            with tempfile.TemporaryDirectory(prefix='quantity-fixture-plan-') as temporary:
                for path, (_, status) in self.common.SOURCE_MANIFEST.items():
                    old = self.common.source(root, base, path, optional=status == 'A')
                    new = self.common.source(root, revision, path)
                    plan[path] = {'status': status, 'patch': self.common.make_patch(Path(temporary), path, old, new)}
            return plan

        self.common.build_plan = quantity_plan
        self.probe = mock.Mock(return_value={'ok': True})
        self.common.runtime_probe = self.probe
        self.php = self.parent / 'php'
        self.php.write_text('#!/usr/bin/env python3\nraise SystemExit(0)\n')
        self.php.chmod(0o755)
        self.addCleanup(mock.patch.stopall)
        mock.patch.object(refresh, 'QUANTITY_REVISION', self.quantity_revision).start()
        mock.patch.object(refresh, 'PHONE_PREIMAGE_REVISION', base).start()
        mock.patch.object(refresh, 'PHONE_PREIMAGE_SHA256', self.common.digest(self.quantity_after[self.js])).start()
        mock.patch.object(refresh, 'SOURCE_MANIFEST', {self.new_css: 'A', self.guard: 'M', self.js: 'M', self.view: 'M'}).start()
        mock.patch.object(refresh, 'load_quantity', return_value=self.common).start()

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], text=True)

    def commit(self, message):
        self.git('add', '.')
        self.git('commit', '-qm', message)
        return self.git('rev-parse', 'HEAD').strip()

    def publish(self, files):
        for path, body in files.items():
            target = self.root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            if body is None:
                target.unlink(missing_ok=True)
            else:
                target.write_bytes(body)

    def snapshots(self):
        return {path: self.common.content(self.root / path) for path in self.before}

    def install(self, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()):
            return refresh.install(self.root, self.revision, str(self.php), **kwargs)

    def receipts(self, group='phone-delivery-refresh-backups'):
        return list((self.parent / group).glob('*/receipt.json'))

    def test_verified_module_only_drops_independent_phone_workflow_proof(self):
        baseline = module('unmodified_quantity_dependency_fixture', ROOT / 'deployment/apply_pos_inline_quantity.py')
        with mock.patch.object(refresh, 'QUANTITY_REVISION', '6582750c2ef925500504a83d89141e49f0bd62a3'):
            configured = VERIFIED_QUANTITY_LOADER(ROOT)
        self.assertEqual(configured.WORKFLOW_PHONE_PREREQUISITES,
                         tuple(path for path in baseline.WORKFLOW_PHONE_PREREQUISITES if path != refresh.PHONE_JS))
        self.assertIn(refresh.PHONE_JS, configured.SOURCE_MANIFEST)
        for name in ('SOURCE_MANIFEST', 'FOUNDATION_MANIFEST', 'LIFECYCLE_PROOF_PATHS',
                     'UNIFIED_PHONE_PREREQUISITES', 'BASE_REVISION', 'APP_BASE_REVISION',
                     'BRAND_BASE_REVISION', 'UPGRADE_PATHS'):
            self.assertEqual(getattr(configured, name), getattr(baseline, name))
        for name in ('install', 'build_plan', 'prove_prerequisites', 'patch_check'):
            self.assertEqual(getattr(configured, name).__code__.co_code,
                             getattr(baseline, name).__code__.co_code)

    def test_fresh_chain_repeat_and_independent_changes_preserved(self):
        custom = b'// Another chat preserved keyboard customization\n'
        for state in (self.before, self.quantity_after, self.after):
            state[self.js] = state[self.js].replace(b'Independent keyboard: Enter behavior',
                                                   b'Independent keyboard: Later focus navigation')
        self.before[self.js] += custom
        self.quantity_after[self.js] += custom
        self.after[self.js] += custom
        self.before[self.css] += b'/* Future sizing and EOF comment */\n'
        self.after[self.css] = self.before[self.css]
        self.publish(self.before)
        backup = self.install()
        self.assertEqual(self.snapshots(), self.after)
        self.assertEqual((backup / self.js).read_bytes(), self.quantity_after[self.js])
        self.assertEqual((backup / self.view).read_bytes(), self.before[self.view])
        self.assertFalse((backup / self.new_css).exists())
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual([row['path'] for row in receipt['files']], list(refresh.SOURCE_MANIFEST))
        self.assertIsNone(self.install())
        self.assertEqual(len(self.receipts()), 1)
        self.assertEqual(len(self.receipts('pos-inline-quantity-backups')), 1)

    def test_restrictive_launcher_umask_keeps_new_public_assets_readable(self):
        previous = os.umask(0o077)
        try:
            self.install()
            restored = os.umask(0o077)
            self.assertEqual(restored, 0o077)
        finally:
            os.umask(previous)
        for path in (self.quantity_asset, self.new_css):
            self.assertEqual(stat.S_IMODE((self.root / path).stat().st_mode), 0o644)
        for group in ('pos-inline-quantity-backups', 'phone-delivery-refresh-backups'):
            self.assertEqual(stat.S_IMODE((self.parent / group).stat().st_mode), 0o700)

    def test_preinstalled_private_new_asset_is_reported_without_chmod_or_publication(self):
        for path, body in ((self.new_css, self.after[self.new_css]),
                           (self.quantity_asset, self.quantity_after[self.quantity_asset])):
            self.publish(self.before)
            target = self.root / path
            target.write_bytes(body)
            target.chmod(0o600)
            before = self.snapshots()
            with self.assertRaisesRegex(RuntimeError, 'PUBLIC_ASSET_READ_PERMISSION_REQUIRED'):
                self.install()
            self.assertEqual(self.snapshots(), before)
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o600)
            self.assertEqual(self.receipts(), [])
            self.assertEqual(self.receipts('pos-inline-quantity-backups'), [])

    def test_combined_check_and_timer_conflict_publish_no_quantity_files(self):
        self.assertIsNone(self.install(check_only=True))
        self.assertEqual(self.snapshots(), self.before)
        (self.root / self.js).write_bytes(self.before[self.js].replace(b'Timer: old', b'Timer: unknown'))
        before = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'COMBINED_LIVE_CHANGE_NEEDS_REVIEW'):
            self.install()
        self.assertEqual(self.snapshots(), before)
        self.assertEqual(self.receipts(), [])
        self.assertEqual(self.receipts('pos-inline-quantity-backups'), [])

    def test_new_backend_lint_failure_stops_before_quantity_publication(self):
        self.php.write_text('#!/usr/bin/env python3\nimport sys\nraise SystemExit(1 if sys.argv[-1].endswith("TakeawayService.php") else 0)\n')
        with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID.*TakeawayService.php'):
            self.install()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])
        self.assertEqual(self.receipts('pos-inline-quantity-backups'), [])

    def test_unknown_quantity_overlap_stops_both_layers(self):
        (self.root / self.js).write_bytes(self.before[self.js].replace(b'Quantity: old', b'Quantity: unknown'))
        before = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.install()
        self.assertEqual(self.snapshots(), before)
        self.assertEqual(self.receipts(), [])
        self.assertEqual(self.receipts('pos-inline-quantity-backups'), [])

    def test_partial_new_stylesheet_and_existing_quantity_are_idempotent(self):
        self.publish(self.quantity_after)
        (self.root / self.new_css).write_bytes(self.after[self.new_css])
        self.install()
        self.assertEqual(self.snapshots(), self.after)
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertNotIn(self.new_css, [row['path'] for row in receipt['files']])
        self.assertIsNone(self.install())
        self.assertEqual(self.receipts('pos-inline-quantity-backups'), [])

    def test_unknown_new_stylesheet_stops_before_quantity_publication(self):
        (self.root / self.new_css).write_bytes(self.after[self.new_css] + b'/* Independent edit */\n')
        before = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'COMBINED_LIVE_CHANGE_NEEDS_REVIEW'):
            self.install()
        self.assertEqual(self.snapshots(), before)
        self.assertEqual(self.receipts('pos-inline-quantity-backups'), [])

    def test_refresh_failure_rolls_back_only_refresh_keeps_quantity_success(self):
        def probe(root, php, stage, phase, views=()):
            if phase == 'installed' and b'Timer: installed' in (root / self.js).read_bytes():
                raise RuntimeError('DELIBERATE_REFRESH_FAILURE')
            return {'ok': True}
        self.probe.side_effect = probe
        with self.assertRaisesRegex(RuntimeError, 'DELIBERATE_REFRESH_FAILURE'):
            self.install()
        self.assertEqual(self.snapshots(), self.quantity_after)
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'rolled_back')
        self.assertEqual(json.loads(self.receipts('pos-inline-quantity-backups')[0].read_text())['status'], 'installed')

    def test_concurrent_consumer_retains_stylesheet_dependency(self):
        def probe(root, php, stage, phase, views=()):
            if phase == 'installed' and b'Timer: installed' in (root / self.js).read_bytes():
                (root / self.view).write_bytes(self.after[self.view] + b'{{-- Concurrent edit --}}\n')
                raise RuntimeError('DELIBERATE_CONCURRENT_EDIT')
            return {'ok': True}
        self.probe.side_effect = probe
        with self.assertRaisesRegex(RuntimeError, 'DELIBERATE_CONCURRENT_EDIT'):
            self.install()
        self.assertEqual((self.root / self.new_css).read_bytes(), self.after[self.new_css])
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'rollback_review_required')
        self.assertEqual(receipt['retained_dependency_group'], list(refresh.SOURCE_MANIFEST))

    def test_new_asset_precedes_view_filemtime_reference(self):
        self.install()
        patch = self.receipts()[0].parent.joinpath('release.diff').read_bytes()
        self.assertLess(patch.index(('diff --git a/' + self.new_css).encode()),
                        patch.index(('diff --git a/' + self.view).encode()))


if __name__ == '__main__':
    unittest.main()
