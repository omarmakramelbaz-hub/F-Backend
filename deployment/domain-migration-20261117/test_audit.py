import contextlib
import hashlib
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('audit', Path(__file__).with_name('audit.py'))
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class AuditTests(unittest.TestCase):
    def test_environment_redaction(self):
        result = audit.env_inventory('''APP_URL=https://name:SECRET_A@fasakhaninja.com/private?key=SECRET_B
ASSET_URL=https://www.faskhansta.com/images?secret=SECRET_C
SESSION_DOMAIN=.fasakhaninja.com
PAYMOB_SECRET_KEY=SECRET_D_fasakhaninja.com
DB_PASSWORD=SECRET_E_fasakhaninja.com
META_CALLBACK_URL=https://fasakhaninja.com/callback?token=SECRET_F
''')
        output = str(result)
        self.assertNotIn('SECRET_', output)
        self.assertNotIn('DB_PASSWORD', output)
        self.assertEqual(result['settings']['APP_URL'], 'https://fasakhaninja.com')
        self.assertEqual(result['settings']['ASSET_URL'], 'https://www.faskhansta.com')
        self.assertEqual(result['other_domain_setting_names'], ['META_CALLBACK_URL'])

    def test_inventory_does_not_change_files_or_emit_code(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / 'app').mkdir()
            source = root / 'app/Sample.php'
            source.write_text('<?php $secret="DO_NOT_PRINT"; $url="https://fasakhaninja.com/payment/private-token";')
            (root / '.env').write_text('APP_URL=https://fasakhaninja.com\nDB_PASSWORD=DO_NOT_PRINT')
            (root / 'public').mkdir()
            (root / 'public/leak.php').symlink_to('/etc/passwd')
            (root / 'public/uploads').mkdir()
            (root / 'public/uploads/private.php').write_text('DO_NOT_PRINT fasakhaninja.com')
            before = {p: hashlib.sha256(p.read_bytes()).hexdigest() for p in [source, root / '.env']}
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                audit.inspect(root)
            self.assertNotIn('DO_NOT_PRINT', output.getvalue())
            self.assertNotIn('private-token', output.getvalue())
            self.assertNotIn('uploads/private.php', output.getvalue())
            self.assertIn('app/Sample.php', output.getvalue())
            self.assertIn('"source_changed": false', output.getvalue())
            for p, digest in before.items():
                self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(), digest)

    def test_exact_domain_matching(self):
        matches = audit.DOMAIN.findall('fasakhaninja.com www.fasakhaninja.com faskhansta.com test.fasakhaninja.com evilfasakhaninja.com fasakhaninja.com.attacker.test user@fasakhaninja.com')
        self.assertEqual(matches, ['fasakhaninja.com', 'www.fasakhaninja.com', 'faskhansta.com', 'fasakhaninja.com'])

    def test_bad_url_is_not_exposed(self):
        self.assertEqual(audit.safe_origin('https://name:SECRET@[bad'), '(configured; value omitted)')
        self.assertEqual(audit.safe_origin('https://name:SECRET@faskhansta.com:443/secret'), 'https://faskhansta.com:443')


if __name__ == '__main__':
    unittest.main()
