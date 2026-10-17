"""Prove mixed-history source patches, prerequisite checks, and coherent rollback."""
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
SPEC = importlib.util.spec_from_file_location('inline_quantity_installer', ROOT / 'deployment/apply_pos_inline_quantity.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class InlineQuantityDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='inline-quantity-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Deployment Test')
        self.git('config', 'user.email', 'deployment-test@example.invalid')
        self.service = 'app/Services/Dashboard/OrderBoardService.php'
        self.editor = 'app/Services/Dashboard/OrderBoardItems.php'
        self.helper = 'app/Services/Dashboard/OrderBoardPrinting.php'
        self.script = 'public/dashboard/js/order-board-detail.js'
        self.client = 'public/dashboard/js/takeaway-pos.js'
        self.brand = 'resources/views/admin/partials/go_erp_logo.blade.php'
        self.view = 'resources/views/admin/orders/board_detail.blade.php'
        self.menu = 'resources/views/admin/layouts/menu.blade.php'
        self.model = 'app/Models/Order.php'
        self.workflow_style = 'public/dashboard/css/phone-orders.css'
        self.manifest = {self.helper: ('new', 'A'), self.script: ('new', 'A'),
                         self.editor: ('app', 'M'), self.service: ('composite', 'M'),
                         self.client: ('live', 'M'), self.brand: ('brand', 'M'),
                         self.view: ('live', 'M')}
        service_body = 'Foundation: old\n' + ''.join('// Separate OperatingDay context %s\n' % n for n in range(12)) + '// OperatingDay: old'
        common = {self.service: self.body(service_body),
                  self.model: self.body('Zero overrides: old'),
                  self.workflow_style: self.body('Workflow style: old'),
                  self.client: self.body('Quantity: old'), self.view: self.body('View: old'),
                  self.menu: b"@include('admin.partials.go_erp_logo')\n"}
        self.publish(common)
        common_revision = self.commit('Common source base')
        foundation = dict(common)
        foundation[self.service] = common[self.service].replace(b'Foundation: old', b'Foundation: installed')
        foundation[self.model] = common[self.model].replace(b'Zero overrides: old', b'Zero overrides: installed')
        foundation[self.editor] = self.body('POS: old')
        self.publish(foundation)
        foundation_revision = self.commit('Installed app editor foundation')
        app = dict(foundation)
        app[self.editor] = foundation[self.editor].replace(b'POS: old', b'POS: installed')
        self.publish(app)
        app_revision = self.commit('Separate application POS release')
        self.git('switch', '-q', '-c', 'branding-fixture', common_revision)
        self.publish({self.brand: self.body('Brand: installed')})
        brand_revision = self.commit('Separate installed branding release')
        self.git('switch', '-q', '-c', 'live-fixture', common_revision)
        self.publish({self.service: common[self.service].replace(b'OperatingDay: old', b'OperatingDay: live')})
        live_revision = self.commit('Main live release preserving OperatingDay')
        self.before = {self.helper: None, self.script: None, self.editor: app[self.editor],
                       self.service: foundation[self.service].replace(b'OperatingDay: old', b'OperatingDay: live'),
                       self.client: common[self.client], self.brand: self.body('Brand: installed'),
                       self.view: common[self.view], self.menu: common[self.menu],
                       self.model: foundation[self.model], self.workflow_style: common[self.workflow_style]}
        self.after = dict(self.before)
        self.after[self.helper] = self.body('Print helper: installed')
        self.after[self.script] = self.body('Detail script: installed')
        self.after[self.editor] = self.before[self.editor].replace(b'POS: installed', b'POS: inline')
        self.after[self.service] = self.before[self.service].replace(b'Foundation: installed', b'Foundation: inline')
        self.after[self.client] = self.before[self.client].replace(b'Quantity: old', b'Quantity: inline')
        self.after[self.brand] = self.before[self.brand].replace(b'Brand: installed', b'Brand: internal')
        self.after[self.view] = self.before[self.view].replace(b'View: old', b'View: filemtime detail script')
        self.publish(self.after)
        self.revision = self.commit('Inline quantities and branding without merging old branches')
        self.publish(self.before)
        settings = {'BASE_REVISION': live_revision, 'APP_BASE_REVISION': app_revision,
                    'BRAND_BASE_REVISION': brand_revision, 'FOUNDATION_BASE_REVISION': common_revision,
                    'FOUNDATION_REVISION': foundation_revision, 'SOURCE_MANIFEST': self.manifest,
                    'FOUNDATION_MANIFEST': {self.service: 'M', self.model: 'M', self.editor: 'A'},
                    'UPGRADE_PATHS': (self.editor,), 'BRAND_MENU': self.menu}
        for name, value in settings.items():
            previous = getattr(installer, name)
            setattr(installer, name, value)
            self.addCleanup(setattr, installer, name, previous)
        self.php = self.parent / 'php-check'
        self.php.write_text('#!/usr/bin/env python3\nimport sys\nsys.exit(0)\n')
        self.php.chmod(0o755)
        self.probe = mock.patch.object(installer, 'runtime_probe', return_value={'ok': True}).start()
        self.addCleanup(mock.patch.stopall)

    @staticmethod
    def body(value):
        return ('<?php\n' + ''.join('// Stable leading %s\n' % n for n in range(12))
                + '// ' + value + '\n' + ''.join('// Stable trailing %s\n' % n for n in range(12))).encode()

    def git(self, *arguments):
        return subprocess.check_output(['git', '-C', str(self.root), *arguments], text=True)

    def commit(self, message):
        self.git('add', '.')
        self.git('commit', '-qm', message)
        return self.git('rev-parse', 'HEAD').strip()

    def publish(self, files):
        for relative, body in files.items():
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            if body is None:
                path.unlink(missing_ok=True)
            else:
                path.write_bytes(body)

    def snapshots(self):
        return {relative: installer.content(self.root / relative) for relative in self.before}

    def apply(self, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()):
            return installer.install(self.root, self.revision, str(self.php), **kwargs)

    def receipts(self):
        return list((self.parent / 'pos-inline-quantity-backups').glob('*/receipt.json'))

    def released_overlay_fixture(self):
        """Model known lifecycle then OperatingDay and independent phone releases."""
        self.publish(self.after)
        self.git('switch', '-q', '-c', 'lifecycle-fixture', installer.APP_BASE_REVISION)
        lifecycle = (self.root / self.service).read_bytes().replace(
            b'Foundation: installed', b'Foundation: installed\n// Legacy lifecycle: preserved')
        self.publish({self.service: lifecycle})
        lifecycle_revision = self.commit('Known installed lifecycle release')
        self.publish({self.service: lifecycle.replace(b'OperatingDay: old', b'OperatingDay: live')})
        day_revision = self.commit('Known installed OperatingDay patch')
        self.git('switch', '-q', '-c', 'unified-fixture', installer.BASE_REVISION)
        unified = self.before[self.client].replace(b'Quantity: old', b'Quantity: old\n// Unified board: preserved')
        self.publish({self.client: unified})
        self.workflow_style = 'public/dashboard/css/phone-orders.css'
        self.workflow_dom = 'resources/views/admin/phone_orders/index.blade.php'
        self.publish({self.workflow_style: self.body('Workflow style: unified'),
                      self.workflow_dom: self.body('Unified DOM: required contract')})
        unified_revision = self.commit('Known installed unified phone release')
        self.git('switch', '-q', 'live-fixture')
        self.before[self.service] = lifecycle.replace(b'OperatingDay: old', b'OperatingDay: live')
        self.before[self.client] = unified
        self.after[self.service] = self.before[self.service].replace(b'Foundation: installed', b'Foundation: inline')
        self.after[self.client] = unified.replace(b'Quantity: old', b'Quantity: inline')
        self.manifest[self.service] = ('lifecycle-live', 'M')
        self.manifest[self.client] = ('unified', 'M')
        settings = {'LIFECYCLE_BASE_REVISION': installer.APP_BASE_REVISION,
                    'LIFECYCLE_REVISION': lifecycle_revision, 'OPERATING_DAY_REVISION': day_revision,
                    'UNIFIED_PHONE_REVISION': unified_revision,
                    'LIFECYCLE_PROOF_PATHS': (self.service,), 'UNIFIED_PHONE_PREREQUISITES': ()}
        for name, value in settings.items():
            previous = getattr(installer, name)
            setattr(installer, name, value)
            self.addCleanup(setattr, installer, name, previous)
        self.publish(self.after)
        self.revision = self.commit('Feature preserving independently installed releases')
        self.publish(self.before)

    def released_workflow_fixture(self):
        self.released_overlay_fixture()
        self.publish(self.after)
        self.git('switch', '-q', '-c', 'workflow-fixture', installer.UNIFIED_PHONE_REVISION)
        workflow = (self.root / self.client).read_bytes().replace(
            b'Stable trailing 5', b'Stable trailing 5\n// Branch tariff workflow: preserved')
        style = self.body('Workflow style: branch tariff')
        self.workflow_helper = 'app/Services/Dashboard/BranchDeliveryTariff.php'
        helper = self.body('Existing branch tariff helper')
        self.publish({self.client: workflow, self.workflow_style: style, self.workflow_helper: helper})
        workflow_revision = self.commit('Known installed branch tariff workflow')
        self.git('switch', '-q', 'live-fixture')
        self.before[self.client] = workflow
        self.after[self.client] = workflow.replace(b'Quantity: old', b'Quantity: inline')
        dom = installer.source(self.root, installer.UNIFIED_PHONE_REVISION, self.workflow_dom)
        for path, body in ((self.workflow_style, style), (self.workflow_helper, helper), (self.workflow_dom, dom)):
            self.before[path] = self.after[path] = body
        self.manifest[self.client] = ('workflow', 'M')
        settings = {'WORKFLOW_PHONE_REVISION': workflow_revision,
                    'WORKFLOW_PHONE_PREREQUISITES': (self.client, self.workflow_helper),
                    'UNIFIED_PHONE_PREREQUISITES': (self.workflow_dom,)}
        for name, value in settings.items():
            previous = getattr(installer, name)
            setattr(installer, name, value)
            self.addCleanup(setattr, installer, name, previous)
        self.publish(self.after)
        self.revision = self.commit('Inline quantities preserving installed branch tariffs')
        self.publish(self.before)

    def test_known_workflow_partial_install_preserves_readonly_pricing_and_noop(self):
        self.released_workflow_fixture()
        custom = b'// Existing independent workflow customization\n'
        (self.root / self.client).write_bytes(self.before[self.client] + custom)
        (self.root / self.workflow_style).write_bytes(self.before[self.workflow_style] + custom)
        (self.root / self.script).write_bytes(self.after[self.script])
        self.apply()
        expected = dict(self.after)
        expected[self.client] += custom
        expected[self.workflow_style] += custom
        self.assertEqual(self.snapshots(), expected)
        self.assertIn(b'Branch tariff workflow: preserved', expected[self.client])
        receipt = json.loads(self.receipts()[0].read_text())
        targets = {entry['path'] for entry in receipt['files']}
        self.assertTrue(targets <= set(self.manifest))
        self.assertNotIn(self.workflow_helper, targets)
        self.assertNotIn(self.workflow_style, targets)
        self.assertIsNone(self.apply())
        self.assertEqual(len(self.receipts()), 1)

    def test_independent_layout_replacement_and_future_sizing_are_preserved(self):
        self.released_workflow_fixture()
        layout = b'/* New separately released layout */\n#phone-orders .ph-products{grid-template-columns:repeat(2,1fr)}\n'
        layout += b'/* Future sizing overlay and unrelated EOF comment */\n@media(min-width:1400px){.ph-products{gap:18px}}\n'
        (self.root / self.workflow_style).write_bytes(layout)
        expected = dict(self.after)
        expected[self.workflow_style] = layout
        self.apply()
        self.assertEqual(self.snapshots(), expected)
        self.assertIsNone(self.apply())
        self.assertEqual((self.root / self.workflow_style).read_bytes(), layout)
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertNotIn(self.workflow_style, {entry['path'] for entry in receipt['files']})

    def test_missing_layout_or_true_dom_contract_still_stops(self):
        self.released_workflow_fixture()
        for path, changed, reason in ((self.workflow_style, b' \n', 'PHONE_LAYOUT_STYLE_REQUIRED'),
                                      (self.workflow_dom, self.body('Unified DOM: removed contract'),
                                       'UNIFIED_PHONE_PREREQUISITE_CONFLICT')):
            self.publish(self.before)
            (self.root / path).write_bytes(changed)
            before = self.snapshots()
            with self.assertRaisesRegex(RuntimeError, reason):
                self.apply()
            self.assertEqual(self.snapshots(), before)
            self.assertEqual(self.receipts(), [])

    def test_known_workflow_unknown_helper_or_missing_phone_hunk_stops(self):
        self.released_workflow_fixture()
        for path, changed in ((self.workflow_helper, self.before[self.workflow_helper] + b'// Unknown helper edit\n'),
                              (self.client, self.before[self.client].replace(
                                  b'Branch tariff workflow: preserved', b'Branch tariff workflow: changed'))):
            self.publish(self.before)
            (self.root / path).write_bytes(changed)
            before = self.snapshots()
            with self.assertRaisesRegex(RuntimeError, 'PHONE_WORKFLOW_PREREQUISITE_CONFLICT|LIVE_CHANGE_NEEDS_REVIEW'):
                self.apply()
            self.assertEqual(self.snapshots(), before)
            self.assertEqual(self.receipts(), [])

    def test_known_lifecycle_and_unified_release_install_preserves_customizations(self):
        self.released_overlay_fixture()
        custom = b'// Existing unrelated live customization\n'
        for relative in (self.service, self.client, self.brand, self.menu):
            (self.root / relative).write_bytes(self.before[relative] + custom)
        self.apply()
        expected = dict(self.after)
        for relative in (self.service, self.client, self.brand, self.menu):
            expected[relative] += custom
        self.assertEqual(self.snapshots(), expected)
        self.assertIn(b'Legacy lifecycle: preserved', expected[self.service])
        self.assertIn(b'Unified board: preserved', expected[self.client])
        self.assertIsNone(self.apply())
        self.assertEqual(len(self.receipts()), 1)

    def test_known_lifecycle_partial_install_and_unknown_overlap_fail_closed(self):
        self.released_overlay_fixture()
        self.publish({self.service: self.after[self.service], self.script: self.after[self.script]})
        before = self.snapshots()
        self.assertIsNone(self.apply(check_only=True))
        self.assertEqual(self.snapshots(), before)
        self.apply()
        self.assertEqual(self.snapshots(), self.after)
        self.assertIsNone(self.apply())
        (self.root / self.service).write_bytes(self.after[self.service].replace(
            b'Legacy lifecycle: preserved', b'Legacy lifecycle: unknown edit'))
        before = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.apply()
        self.assertEqual(self.snapshots(), before)
        self.assertEqual(len(self.receipts()), 1)

    def test_mixed_nonancestor_bases_composite_install_and_noop(self):
        self.assertNotEqual(subprocess.run(['git', '-C', str(self.root), 'merge-base', '--is-ancestor',
                                            installer.APP_BASE_REVISION, self.revision]).returncode, 0)
        custom = b'// Existing unrelated live customization\n'
        (self.root / self.client).write_bytes(self.before[self.client] + custom)
        (self.root / self.brand).write_bytes(self.before[self.brand] + custom)
        (self.root / self.menu).write_bytes(self.before[self.menu] + custom)
        backup = self.apply()
        expected = dict(self.after)
        expected[self.client] += custom
        expected[self.brand] += custom
        expected[self.menu] += custom
        self.assertEqual(self.snapshots(), expected)
        self.assertIn(b'OperatingDay: live', (self.root / self.service).read_bytes())
        self.assertEqual((backup / self.client).read_bytes(), self.before[self.client] + custom)
        self.assertIsNone(self.apply())
        self.assertEqual(len(self.receipts()), 1)
        self.assertEqual((self.root / self.menu).read_bytes(), self.before[self.menu] + custom)

    def test_unknown_hunk_overlap_stops_without_publication(self):
        (self.root / self.client).write_bytes(self.before[self.client].replace(b'Quantity: old', b'Quantity: independent'))
        before = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.apply()
        self.assertEqual(self.snapshots(), before)
        self.assertEqual(self.receipts(), [])

    def test_missing_foundation_semantics_stops_without_source_changes(self):
        (self.root / self.model).write_bytes(self.before[self.model].replace(b'Zero overrides: installed', b'Zero overrides: old'))
        before = self.snapshots()
        with self.assertRaisesRegex(RuntimeError, 'APP_FOUNDATION_PREREQUISITE_CONFLICT'):
            self.apply()
        self.assertEqual(self.snapshots(), before)

    def test_runtime_preflight_failure_publishes_nothing(self):
        self.probe.side_effect = RuntimeError('CART_QUANTITY_NOT_FRACTIONAL')
        with self.assertRaisesRegex(RuntimeError, 'CART_QUANTITY_NOT_FRACTIONAL'):
            self.apply()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])

    def test_post_install_failure_restores_all_and_removes_new_dependencies(self):
        self.probe.side_effect = [{'ok': True}, RuntimeError('ROUTE_CONTRACT_INVALID')]
        with self.assertRaisesRegex(RuntimeError, 'ROUTE_CONTRACT_INVALID'):
            self.apply()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'rolled_back')

    def test_concurrent_consumer_keeps_whole_dependency_group(self):
        changed = self.after[self.service] + b'// Concurrent consumer edit\n'

        def probe(root, php, stage, phase, views=()):
            if phase == 'installed':
                (root / self.service).write_bytes(changed)
                raise RuntimeError('DELIBERATE_CONCURRENT_EDIT')
            return {'ok': True}

        self.probe.side_effect = probe
        with self.assertRaisesRegex(RuntimeError, 'DELIBERATE_CONCURRENT_EDIT'):
            self.apply()
        expected = dict(self.after)
        expected[self.service] = changed
        self.assertEqual(self.snapshots(), expected)
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'rollback_review_required')
        self.assertEqual(receipt['retained_dependency_group'], list(self.manifest))

    def test_partial_install_and_check_only(self):
        (self.root / self.script).write_bytes(self.after[self.script])
        before = self.snapshots()
        self.assertIsNone(self.apply(check_only=True))
        self.assertEqual(self.snapshots(), before)
        backup = self.apply()
        self.assertEqual(self.snapshots(), self.after)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertNotIn(self.script, [entry['path'] for entry in receipt['files']])

    def test_new_script_is_published_before_its_filemtime_view(self):
        plan = installer.build_plan(self.root, self.revision)
        self.assertLess(list(plan).index(self.script), list(plan).index(self.view))
        self.apply()
        patch = self.receipts()[0].parent.joinpath('release.diff').read_bytes()
        self.assertLess(patch.index(('diff --git a/' + self.script).encode()),
                        patch.index(('diff --git a/' + self.view).encode()))


if __name__ == '__main__':
    unittest.main()
