"""Exercise release preflight without connecting to the application or production host."""
import base64
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('website_installer', Path(__file__).resolve().parents[1] / 'deployment/apply_website_app_orders_root.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)


class WebsiteInstallerTests(unittest.TestCase):
    def entry(self, old, new):
        return {'old':base64.b64encode(old).decode() if old is not None else None,
                'new':base64.b64encode(new).decode()}

    def test_drift_is_rejected_before_any_replacement(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root/'board.php').write_bytes(b'old')
            (root/'action.php').write_bytes(b'concurrent update')
            with self.assertRaisesRegex(RuntimeError, 'Unreviewed live change'):
                installer.plan(root, {'board.php':self.entry(b'old',b'new'),
                                      'action.php':self.entry(b'old action',b'new action')})
            self.assertEqual((root/'board.php').read_bytes(),b'old')
            self.assertEqual((root/'action.php').read_bytes(),b'concurrent update')

    def test_existing_new_and_repeated_install(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root/'board.php').write_bytes(b'old')
            entries = {'board.php':self.entry(b'old',b'new'), 'action.php':self.entry(None,b'action')}
            changes = installer.plan(root,entries)
            self.assertEqual(len(changes),2)
            for _,path,_,new in changes:
                path.write_bytes(new)
            self.assertEqual(installer.plan(root,entries),[])

    def test_symbolic_paths_cannot_escape_review(self):
        with tempfile.TemporaryDirectory() as temporary, tempfile.TemporaryDirectory() as external:
            root = Path(temporary)
            (root/'app').symlink_to(external,target_is_directory=True)
            with self.assertRaisesRegex(RuntimeError, 'Symbolic path'):
                installer.plan(root,{'app/new.php':self.entry(None,b'new')})
            with self.assertRaisesRegex(RuntimeError, 'Invalid release path'):
                installer.checked_path(root,'../outside.php')

    def test_payload_contains_only_reviewed_application_paths(self):
        expected = {'app/Http/Controllers/Dashboard/OrderBoardController.php',
                    'app/Services/Dashboard/OrderBoardService.php',
                    'app/Services/Dashboard/OrderBoardPrinting.php',
                    'app/Services/Dashboard/WebsiteOrderBoardActions.php',
                    'routes/order_board.php','public/dashboard/js/order-board.js','public/dashboard/js/dashboard-order-alerts.js',
                    'resources/views/admin/orders/board_card.blade.php',
                    'resources/views/admin/orders/board_detail.blade.php'}
        self.assertEqual(set(installer.files()),expected)
        root = Path(__file__).resolve().parents[1]
        for relative,entry in installer.files().items():
            self.assertEqual(base64.b64decode(entry['new']),(root/relative).read_bytes(),relative)


if __name__ == '__main__':
    unittest.main()
