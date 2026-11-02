"""The dashboard changes of 2026-11-02: only known live files are replaced, all together, with a way back."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('dashboard_updates', ROOT / 'deployment/install_dashboard_branch_updates_root.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)

# The live files as the server has them (stand-ins), and this release's versions (with their markers).
LIVE = {
    'public/dashboard/js/dashboard-order-alerts.js': b'(function(){ /* alerts */ })();',
    'public/dashboard/js/order-board.js': b'(function(){ /* board */ })();',
    'resources/views/admin/orders/board_card.blade.php': b'<article>card</article>',
    'app/Services/Dashboard/HomeHologramMetrics.php': b'<?php class HomeHologramMetrics {}',
}
NEW = {
    'public/dashboard/js/dashboard-order-alerts.js': b'(function(){ var tone = "/sounds/go-delivery-order.wav"; })();',
    'public/dashboard/js/order-board.js': b'(function(){ var printAfter; })();',
    'resources/views/admin/orders/board_card.blade.php': b'<form data-print-after="prepare"></form>',
    'app/Services/Dashboard/HomeHologramMetrics.php': b'<?php class HomeHologramMetrics { function ownerDrawerBalance() {} }',
    'public/sounds/go-delivery-order.wav': b'RIFF\x24\x00\x00\x00WAVEfmt ',
}


class DashboardUpdatesTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-dashboard-updates-')
        base = Path(self.temporary.name)
        self.site = base / 'public_html'
        self.package = base / 'package'
        self.backups = base / 'go-erp-dashboard-updates'
        for name, data in LIVE.items():
            path = self.site / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
            os.chmod(str(path), 0o644)
        sounds = self.site / 'public/sounds'
        sounds.mkdir(parents=True)
        (sounds / 'mixkit-correct-answer-reward-952.wav').write_bytes(b'RIFF')
        os.chmod(str(sounds / 'mixkit-correct-answer-reward-952.wav'), 0o640)
        for name, data in NEW.items():
            path = self.package / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        self.calls = []
        self.original = (installer.FILES, installer.artisan)
        installer.FILES = tuple((name, hashlib.sha256(LIVE[name]).hexdigest() if base_sha else None, marker)
                                for name, base_sha, marker in self.original[0])
        installer.artisan = lambda site, php, user, *arguments: self.calls.append(arguments) or 0

    def tearDown(self):
        installer.FILES, installer.artisan = self.original
        self.temporary.cleanup()

    def apply(self, served=True):
        return installer.apply(self.site, self.package, self.backups, '/php', 'nobody', check=lambda: served)

    def live(self):
        return {name: (self.site / name).read_bytes() if (self.site / name).exists() else None for name in NEW}

    def test_the_release_covers_the_files_asked_for(self):
        names = [name for name, base, marker in self.original[0]]
        self.assertEqual(names, list(NEW))
        self.assertEqual([base is None for name, base, marker in self.original[0]], [False, False, False, False, True], 'Only the tone is new')
        # The repository's own files are this release's versions.
        installer.FILES = self.original[0]
        self.assertTrue(all(marker in (ROOT / name).read_bytes() for name, base, marker in installer.FILES if (ROOT / name).exists()))

    def test_the_plan_only_reads(self):
        result = installer.plan(self.site, self.package)
        self.assertEqual(result['result'], 'GO_ERP_DASHBOARD_UPDATES_PLAN')
        self.assertEqual(set(result['files'].values()), {'base', 'new'})
        self.assertTrue(result['ready'])
        self.assertFalse(self.backups.exists())
        self.assertEqual(self.live()['public/sounds/go-delivery-order.wav'], None)

    def test_installed_together_with_every_previous_file_kept(self):
        result = self.apply()
        self.assertEqual(result['result'], 'GO_ERP_DASHBOARD_UPDATES_INSTALLED')
        self.assertEqual(self.live(), NEW)
        for name in LIVE:
            self.assertEqual(stat.S_IMODE((self.site / name).stat().st_mode), 0o644, 'Each file keeps its mode')
        self.assertEqual(stat.S_IMODE((self.site / 'public/sounds/go-delivery-order.wav').stat().st_mode), 0o640, 'The tone takes its neighbours\' mode')
        folder = Path(result['backup'])
        saved = json.loads((folder / 'manifest.json').read_text())
        self.assertEqual(stat.S_IMODE(self.backups.stat().st_mode), 0o700)
        for name, data in LIVE.items():
            entry = saved['files'][name]
            self.assertEqual(((folder / entry['copy']).read_bytes(), stat.S_IMODE((folder / entry['copy']).stat().st_mode)), (data, 0o600))
        self.assertEqual(saved['files']['public/sounds/go-delivery-order.wav'], {'existed': False})
        self.assertEqual(self.calls, [('view:clear',)])
        self.assertTrue(installer.plan(self.site, self.package)['already_installed'])
        self.assertEqual(self.apply()['result'], 'GO_ERP_DASHBOARD_UPDATES_ALREADY_INSTALLED')

    def test_a_file_changed_on_the_server_stops_everything(self):
        (self.site / 'public/dashboard/js/order-board.js').write_bytes(b'changed on the server')
        self.assertFalse(installer.plan(self.site, self.package)['ready'])
        with self.assertRaises(installer.Stop) as stopped:
            self.apply()
        self.assertEqual(str(stopped.exception), 'GO_ERP_DASHBOARD_UPDATES_LIVE_FILE_CHANGED_ON_SERVER: public/dashboard/js/order-board.js')
        live = self.live()
        self.assertEqual(live['public/dashboard/js/dashboard-order-alerts.js'], LIVE['public/dashboard/js/dashboard-order-alerts.js'], 'Nothing else changed')
        self.assertIsNone(live['public/sounds/go-delivery-order.wav'])

    def test_files_not_served_put_everything_back(self):
        with self.assertRaises(installer.Stop) as stopped:
            self.apply(served=False)
        self.assertEqual(str(stopped.exception), 'GO_ERP_DASHBOARD_UPDATES_NOT_SERVED_PREVIOUS_FILES_RESTORED')
        self.assertEqual(self.live(), dict(LIVE, **{'public/sounds/go-delivery-order.wav': None}))
        self.assertEqual(self.calls, [('view:clear',), ('view:clear',)])

    def test_rollback_puts_the_previous_files_back(self):
        self.apply()
        result = installer.rollback(self.site, self.package, self.backups, '/php', 'nobody')
        self.assertEqual(result['result'], 'GO_ERP_DASHBOARD_UPDATES_ROLLED_BACK')
        self.assertEqual(self.live(), dict(LIVE, **{'public/sounds/go-delivery-order.wav': None}))
        with self.assertRaises(installer.Stop) as stopped:
            installer.rollback(self.site, self.package, self.backups, '/php', 'nobody')
        self.assertEqual(str(stopped.exception), 'GO_ERP_DASHBOARD_UPDATES_LIVE_FILES_ARE_NOT_THIS_RELEASE')

    def test_a_package_from_another_release_is_refused(self):
        (self.package / 'public/dashboard/js/order-board.js').write_bytes(b'an older board')
        with self.assertRaises(installer.Stop) as stopped:
            installer.plan(self.site, self.package)
        self.assertEqual(str(stopped.exception), 'GO_ERP_DASHBOARD_UPDATES_PACKAGE_NOT_THIS_RELEASE: public/dashboard/js/order-board.js')


if __name__ == '__main__':
    unittest.main()
