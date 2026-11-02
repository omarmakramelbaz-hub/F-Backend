"""The dashboard's sign-in page in the GO opening look: only a known page is replaced, with a way back."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('login_hud', ROOT / 'deployment/install_login_hud_root.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)

BASE = b'<!DOCTYPE html><form data-dashboard-login><input name="email"><input name="password"></form>'


class LoginHudTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-login-')
        base = Path(self.temporary.name)
        self.site = base / 'public_html'
        self.package = base / 'package'
        for folder in (self.site, self.package):
            (folder / installer.PAGE).parent.mkdir(parents=True)
        self.live = self.site / installer.PAGE
        self.live.write_bytes(BASE)
        os.chmod(str(self.live), 0o644)
        (self.package / installer.NEW_PAGE).parent.mkdir(parents=True)
        (self.package / installer.NEW_PAGE).write_bytes((ROOT / installer.NEW_PAGE).read_bytes())
        self.backups = base / 'go-erp-login-hud'
        self.calls = []
        self.original = (installer.BASE_SHA256, installer.artisan)
        installer.BASE_SHA256 = hashlib.sha256(BASE).hexdigest()
        installer.artisan = lambda site, php, user, *arguments: self.calls.append(arguments) or 0

    def tearDown(self):
        installer.BASE_SHA256, installer.artisan = self.original
        self.temporary.cleanup()

    def apply(self, served=True):
        return installer.apply(self.site, self.package, self.backups, '/php', 'nobody', check=lambda: served)

    def test_the_repository_keeps_the_live_page_and_the_new_one_apart(self):
        live = (ROOT / installer.PAGE).read_bytes()
        self.assertEqual(hashlib.sha256(live).hexdigest(), self.original[0], 'The repository has the page the live server runs')
        self.assertNotIn(installer.MARKER, live)
        self.assertIn(installer.MARKER, (ROOT / installer.NEW_PAGE).read_bytes())

    def test_the_plan_only_reads(self):
        result = installer.plan(self.site, self.package)
        self.assertEqual((result['result'], result['live_page'], result['hologram_on_server']), ('GO_ERP_LOGIN_HUD_PLAN', 'base', False))
        self.assertFalse(self.backups.exists())
        self.assertEqual(self.live.read_bytes(), BASE)

    def test_installed_with_the_previous_page_kept(self):
        result = self.apply()
        self.assertEqual(result['result'], 'GO_ERP_LOGIN_HUD_INSTALLED')
        self.assertEqual(self.live.read_bytes(), (ROOT / installer.NEW_PAGE).read_bytes())
        self.assertEqual(stat.S_IMODE(self.live.stat().st_mode), 0o644, 'The page keeps its mode')
        backup = Path(result['backup'])
        self.assertEqual((backup.read_bytes(), stat.S_IMODE(backup.stat().st_mode), stat.S_IMODE(self.backups.stat().st_mode)), (BASE, 0o600, 0o700))
        self.assertEqual(json.loads(Path(str(backup) + '.json').read_text())['mode'], 0o644)
        self.assertEqual(self.calls, [('view:clear',)])
        self.assertEqual(installer.plan(self.site, self.package)['live_page'], 'installed')
        self.assertEqual(self.apply()['result'], 'GO_ERP_LOGIN_HUD_ALREADY_INSTALLED')

    def test_a_page_changed_on_the_server_is_never_replaced(self):
        self.live.write_bytes(BASE + b'<!-- changed on the server -->')
        self.assertEqual(installer.plan(self.site, self.package)['live_page'], 'changed-on-server')
        with self.assertRaises(installer.Stop) as stopped:
            self.apply()
        self.assertEqual(str(stopped.exception), 'GO_ERP_LOGIN_HUD_LIVE_PAGE_CHANGED_ON_SERVER')
        self.assertIn(b'changed on the server', self.live.read_bytes())

    def test_a_page_not_served_puts_the_previous_one_back(self):
        with self.assertRaises(installer.Stop) as stopped:
            self.apply(served=False)
        self.assertEqual(str(stopped.exception), 'GO_ERP_LOGIN_HUD_NEW_PAGE_NOT_SERVED_PREVIOUS_PAGE_RESTORED')
        self.assertEqual(self.live.read_bytes(), BASE)
        self.assertEqual(self.calls, [('view:clear',), ('view:clear',)])

    def test_rollback_puts_the_previous_page_back(self):
        self.apply()
        result = installer.rollback(self.site, self.package, self.backups, '/php', 'nobody')
        self.assertEqual(result['result'], 'GO_ERP_LOGIN_HUD_ROLLED_BACK')
        self.assertEqual(self.live.read_bytes(), BASE)
        with self.assertRaises(installer.Stop) as stopped:
            installer.rollback(self.site, self.package, self.backups, '/php', 'nobody')
        self.assertEqual(str(stopped.exception), 'GO_ERP_LOGIN_HUD_LIVE_PAGE_IS_NOT_THIS_RELEASE')


if __name__ == '__main__':
    unittest.main()
