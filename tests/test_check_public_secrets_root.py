"""The public-secrets check names what can be downloaded and which key to revoke, never a secret."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('public_secrets', ROOT / 'deployment/check_public_secrets_root.py')
check = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(check)

KEY = {'type': 'service_account', 'project_id': 'demo-project', 'private_key_id': '0123456789abcdef0123456789abcdef01234567',
       'private_key': '-----BEGIN PRIVATE KEY-----\nSECRETSECRET\n-----END PRIVATE KEY-----\n',
       'client_email': 'firebase-adminsdk-x@demo-project.iam.gserviceaccount.com'}


class PublicSecretsTests(unittest.TestCase):
    def test_keys_and_backups_in_public_are_named_with_their_project_and_key_id_start_only(self):
        with tempfile.TemporaryDirectory() as folder:
            site = Path(folder)
            (site / 'public/images').mkdir(parents=True)
            (site / 'storage/app').mkdir(parents=True)
            (site / 'public/firebase_credentials.json').write_text(json.dumps(KEY))
            (site / 'public/firebase_credentials.json-old').write_text(json.dumps(dict(KEY, project_id='old-project')))
            (site / 'public/images/logo.png').write_bytes(b'png')
            (site / 'public/images/site-backup.sql').write_text('insert')
            (site / 'public/app.js').write_text('code')
            (site / 'storage/app/firebase_credentials.json').write_text(json.dumps(dict(KEY, project_id='used-project')))
            asked = []
            lines = check.report(site, ask=lambda relative: asked.append(relative) or {'status': 200, 'type': 'application/json'})
        text = '\n'.join(lines)
        self.assertNotIn('SECRETSECRET', text)
        self.assertNotIn(KEY['private_key_id'], text)
        self.assertIn('"key_id_starts": "01234567"', text)
        self.assertIn('files in public/ that look like secrets or backups: 3', text)
        self.assertEqual(['firebase_credentials.json', 'firebase_credentials.json-old', 'images/site-backup.sql'], asked)
        self.assertIn('"project": "old-project"', text)
        self.assertIn('"served": {"status": 200', text)
        self.assertIn('"project": "used-project"', lines[-1])
        self.assertNotIn('logo.png', text)
        self.assertNotIn('app.js', text)


if __name__ == '__main__':
    unittest.main()
