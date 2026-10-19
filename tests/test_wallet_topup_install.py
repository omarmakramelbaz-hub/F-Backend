"""Guarded installer regression: preserve adjacent live changes and fail before writes."""
import contextlib
import importlib.util
import io
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('wallet_installer', REPO / 'deployment/install_wallet_topup_settlement.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
PHP = Path(os.environ.get('WALLET_TEST_PHP', 'php'))
BASE_BLOBS = {
    'routes/api.php': 'e6a1f9428fd9e4606b8cfc5304f14b327713670e',
    'app/Http/Controllers/CheckoutController.php': '0f874dd33545506912be1d05deb7c788925b68ba',
    'app/Http/Controllers/Payment/PaymobController.php': 'ca52f03331eedd7903b0a16ad3a706e3b4e7cbdf',
}


class WalletInstallerTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='wallet-installer-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'account/public_html'
        self.root.mkdir(parents=True)
        (self.root.parent / 'wallet-topup-settlement-backups').mkdir(mode=0o700)
        for relative, blob in BASE_BLOBS.items():
            target = self.root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            # The recorded pre-fix commit is the measured production source.
            target.write_bytes(subprocess.check_output(['git', 'show', blob], cwd=REPO))

    def apply(self):
        with contextlib.redirect_stdout(io.StringIO()):
            installer.install(self.root, REPO, PHP)

    def snapshot(self):
        return {str(p.relative_to(self.root)): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}

    def test_preserves_adjacent_live_routes_and_is_idempotent(self):
        routes = self.root / 'routes/api.php'
        routes.write_text(routes.read_text() + '\n// PRESERVE_THIS_OTHER_LIVE_UPDATE\n')
        self.apply()
        self.assertIn('PRESERVE_THIS_OTHER_LIVE_UPDATE', routes.read_text())
        self.assertEqual(routes.read_text().count(installer.ROUTES), 1)
        checkout = (self.root / 'app/Http/Controllers/CheckoutController.php').read_text()
        self.assertLess(checkout.index('WalletTopupSettlement::class'), checkout.index("$order->type === 'current'"))
        before = self.snapshot()
        backups = list((self.root.parent / 'wallet-topup-settlement-backups').iterdir())
        self.apply()
        self.assertEqual(before, self.snapshot())
        self.assertEqual(backups, list((self.root.parent / 'wallet-topup-settlement-backups').iterdir()))

    def test_source_drift_refuses_before_any_live_write(self):
        target = self.root / 'app/Http/Controllers/CheckoutController.php'
        target.write_text(target.read_text() + '\n// unreviewed live behavior\n')
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED'): self.apply()
        self.assertEqual(before, self.snapshot())

    def test_missing_route_anchor_refuses_before_any_live_write(self):
        target = self.root / 'routes/api.php'
        target.write_text(target.read_text().replace(installer.ROUTE_ANCHOR, ''))
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'ROUTE_ANCHOR_CHANGED'): self.apply()
        self.assertEqual(before, self.snapshot())

    def test_existing_unreviewed_new_file_is_preserved(self):
        target = self.root / installer.NEW_FILES[0]
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text('<?php // local customized service\n')
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'NEW_FILE_CONFLICT'): self.apply()
        self.assertEqual(before, self.snapshot())


if __name__ == '__main__':
    unittest.main()
