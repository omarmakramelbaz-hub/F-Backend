import contextlib
import hashlib
import importlib.util
import io
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
RELEASE = ROOT / 'deployment/baladi-bread-purchases-20261106'
spec = importlib.util.spec_from_file_location('bread_install', RELEASE / 'apply.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
BASE = '7195396b3e136f22ef6ad6cea8ce61bbd8a5d1d8'


class BreadInstallerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / 'app'
        self.before = {}
        for relative in installer.PATCHES:
            data = subprocess.check_output(['git', '-C', str(ROOT), 'show', BASE + ':' + relative])
            self.before[relative] = data
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
            path.chmod(0o640)

    def install(self, **options):
        with patch.object(installer, 'runtime_catalog') as catalog, patch.object(installer, 'lint_candidates'), contextlib.redirect_stdout(io.StringIO()):
            installer.install(self.root, php='/usr/bin/true', revision='a' * 40, **options)
            return [call.kwargs.get('mode') for call in catalog.call_args_list]

    def test_context_patches_match_sources_and_preserve_neighboring_changes(self):
        for relative, before in self.before.items():
            with self.subTest(relative=relative):
                target = (ROOT / relative).read_bytes()
                self.assertEqual(installer.candidate(relative, before), target)
                decorated = before + b'\n// Unrelated server customization retained.\n'
                expected = target + b'\n// Unrelated server customization retained.\n'
                self.assertEqual(installer.candidate(relative, decorated), expected)
                self.assertEqual(installer.candidate(relative, expected), expected)

    def test_application_preflights_then_applies_catalog_and_verifies(self):
        self.assertEqual(self.install(), ['check', 'apply', 'verify'])
        for relative in self.before:
            self.assertEqual((self.root / relative).read_bytes(), (ROOT / relative).read_bytes())
            self.assertEqual((self.root / relative).stat().st_mode & 0o777, 0o640)
        self.assertEqual(self.install(), ['check', 'apply', 'verify'])

    def test_check_mode_writes_nothing(self):
        self.assertEqual(self.install(check=True), ['check'])
        for relative, before in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), before)
        self.assertFalse((self.root.parent / 'baladi-bread-purchases-backups').exists())

    def test_failed_catalog_preflight_writes_nothing(self):
        with patch.object(installer, 'runtime_catalog', side_effect=RuntimeError('Existing bread unit mismatch')), patch.object(installer, 'lint_candidates'):
            with self.assertRaisesRegex(RuntimeError, 'unit mismatch'):
                installer.install(self.root, php='/usr/bin/true')
        for relative, before in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), before)

    def test_unrecognized_source_stops_before_writes(self):
        relative = 'app/Services/Dashboard/BranchInventory.php'
        changed = self.before[relative].replace(b"$i['unit']==='kg'", b"$i['unit']==='other'")
        (self.root / relative).write_bytes(changed)
        with self.assertRaisesRegex(RuntimeError, 'PATCH_CONFLICT'):
            self.install()
        self.assertFalse((self.root.parent / 'baladi-bread-purchases-backups').exists())

    def test_publication_failure_rolls_back_source_before_catalog_is_applied(self):
        original = installer.atomic_write
        calls = []

        def fail_once(path, data, info):
            original(path, data, info)
            calls.append(path)
            if len(calls) == 2:
                raise RuntimeError('Injected publication failure')

        with patch.object(installer, 'atomic_write', fail_once):
            with self.assertRaisesRegex(RuntimeError, 'publication failure'):
                self.install()
        for relative, before in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), before)

    def test_live_expense_rejection_changes_are_retained(self):
        # Both requests touch the same JS file; their independent edits must coexist.
        relative = 'public/dashboard/js/branch-expenses.js'
        reviewed = self.before[relative].decode().replace(
            "reason = prompt(t('reason'))", 'reason = showExpenseReviewDialog()')
        marker = "    var reviewDialog = document.createElement('dialog');\n"
        reviewed = reviewed.replace('    async function details(id) {', marker + '    async function details(id) {')
        combined = installer.candidate(relative, reviewed.encode()).decode()
        self.assertIn('data-expense-quantity-label', combined)
        self.assertIn(marker, combined)
        self.assertIn('showExpenseReviewDialog()', combined)
        self.assertNotIn("reason = prompt(t('reason'))", combined)

    def test_launcher_and_embedded_catalog_are_pinned(self):
        self.assertEqual(installer.CATALOG_PHP, (RELEASE / 'catalog.php').read_text())
        self.assertIn(hashlib.sha256((RELEASE / 'apply.py').read_bytes()).hexdigest(), (RELEASE / 'RUN_ROOT.sh').read_text())

    def test_historical_pre_tahini_service_is_upgraded_without_adding_tahini(self):
        relative = 'app/Services/Dashboard/ExpenseStockPurchases.php'
        before = b''.join(line for line in self.before[relative].splitlines(keepends=True) if b"'purchase_tahini'" not in line)
        before = before.replace('والخل والطحينة فقط.'.encode(), 'والخل فقط.'.encode())
        # Exact upstream blob from codex/expense-stock-purchases-20261018.
        self.assertEqual(hashlib.sha1(b'blob ' + str(len(before)).encode() + b'\0' + before).hexdigest(), '651f5df0f5896b289239d9bb55b1a367ee5c6ab1')
        expected = b''.join(line for line in (ROOT / relative).read_bytes().splitlines(keepends=True) if b"'purchase_tahini'" not in line)
        upgraded = installer.candidate(relative, before)
        self.assertEqual(upgraded, expected)
        self.assertEqual(installer.candidate(relative, upgraded), upgraded)
        self.assertNotIn(b"'purchase_tahini'", upgraded)

    def test_windows_line_endings_are_preserved(self):
        for relative, before in self.before.items():
            with self.subTest(relative=relative):
                before = before.replace(b'\n', b'\r\n')
                expected = (ROOT / relative).read_bytes().replace(b'\n', b'\r\n')
                self.assertEqual(installer.candidate(relative, before), expected)
                self.assertEqual(installer.candidate(relative, expected), expected)

    def test_unreviewed_existing_bread_implementation_is_rejected(self):
        relative = 'app/Services/Dashboard/ExpenseStockPurchases.php'
        altered = (ROOT / relative).read_bytes().replace(b"? 'piece' : 'kg'", b"? 'kg' : 'kg'")
        with self.assertRaisesRegex(RuntimeError, 'UNRECOGNIZED_EXISTING_BREAD_IMPLEMENTATION'):
            installer.candidate(relative, altered)

    def test_conflict_identifies_the_failing_edit(self):
        relative = 'app/Services/Dashboard/ExpenseStockPurchases.php'
        altered = self.before[relative].replace(b'private function units($value): int', b'private function units($value, $other): int')
        with self.assertRaisesRegex(RuntimeError, 'PATCH_CONFLICT: .* edit=6 source_sha256='):
            installer.candidate(relative, altered)

    def reported_live_source(self):
        relative = 'app/Services/Dashboard/ExpenseStockPurchases.php'
        before = self.before[relative].decode()
        original = next(line for line in before.splitlines(keepends=True) if '$value = strtr(trim((string)$value)' in line)
        shared = '        $value = \\App\\Support\\Quantity::text($value);\n'
        reported = before.replace(original, shared).encode()
        # Exact complete-file fingerprint supplied by the production operator.
        self.assertEqual(hashlib.sha256(reported).hexdigest(), 'bf3985e57e4355ce7dee0113956e16a8061599ad37bdadcb2a383b1558883662')
        return relative, reported, original.encode(), shared.encode()

    def test_reported_production_service_preserves_the_shared_quantity_normalizer(self):
        relative, reported, original, shared = self.reported_live_source()
        expected = (ROOT / relative).read_bytes().replace(original, shared)
        result = installer.candidate(relative, reported)
        self.assertEqual(result, expected)
        self.assertEqual(result.count(shared), 1)
        self.assertEqual(installer.candidate(relative, result), result)

    def test_install_and_backup_accept_the_reported_production_fingerprint(self):
        relative, reported, original, shared = self.reported_live_source()
        (self.root / relative).write_bytes(reported)
        self.install()
        self.assertEqual((self.root / relative).read_bytes(), (ROOT / relative).read_bytes().replace(original, shared))
        backups = list((self.root.parent / 'baladi-bread-purchases-backups').iterdir())
        self.assertEqual(len(backups), 1)
        self.assertEqual((backups[0] / relative).read_bytes(), reported)
        self.install()
        self.assertEqual((self.root / relative).read_bytes().count(shared), 1)

    def test_shared_client_quantity_normalization_is_preserved(self):
        relative = 'public/dashboard/js/branch-expenses.js'
        before = self.before[relative].decode()
        normalizer = next(line for line in before.splitlines(keepends=True) if 'v.quantity_kg = v.quantity_kg.trim()' in line)
        replacement = '            v.quantity_kg = window.DashboardQuantity.text(v.quantity_kg);\n'
        actual = before.replace(normalizer, replacement)
        expected = (ROOT / relative).read_text().replace(normalizer, replacement)
        result = installer.candidate(relative, actual.encode())
        self.assertEqual(result, expected.encode())
        self.assertEqual(installer.candidate(relative, result), result)


if __name__ == '__main__':
    unittest.main()
