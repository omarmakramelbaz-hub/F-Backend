"""Run the real PHP download/source guards with safe publication fixtures."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'deployment/diagnose_go_erp_download.php'
PHP = os.environ.get('GO_ERP_DIAGNOSTIC_PHP') or shutil.which('php')
SECRET = 'PRIVATE_CONFIG_AND_SOURCE_SENTINEL'


class DownloadDiagnosticTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not PHP:
            raise RuntimeError('Set GO_ERP_DIAGNOSTIC_PHP to an executable PHP 8.2+ runtime.')

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-download-diagnostic-')
        self.addCleanup(temporary.cleanup)
        self.work = Path(temporary.name)
        self.work.chmod(0o755)
        self.root = self.work / 'application'
        self.root.mkdir(mode=0o755)
        self.write('vendor/autoload.php', '''<?php
namespace Illuminate\\Foundation { class Application {const VERSION='8.83.29';} }
''')
        for name in ('DesktopDashboardUpdates', 'DesktopDashboardSource'):
            self.write('app/Services/Dashboard/' + name + '.php',
                (ROOT / 'app/Services/Dashboard' / (name + '.php')).read_bytes())
        self.write('desktop/source-contract.json', json.dumps({'format': 1,
            'framework': '8.83.29', 'supportedRuntimeFrameworks': ['8.83.29']}))
        self.write('bootstrap/cache/config.php', "<?php return ['desktop_dashboard'=>['enabled'=>true,'local'=>false], 'private'=>'" + SECRET + "'];")
        self.write('config/example.php', '<?php // public application fixture\n')
        self.write('.env', 'PRIVATE=' + SECRET + '\n')
        self.driver = self.work / 'driver.php'
        self.driver.write_text("<?php define('GO_ERP_DOWNLOAD_DIAGNOSTIC_LIBRARY',true); require $argv[1]; echo json_encode(\\GoErpDownloadDiagnostic\\inspect($argv[2]),JSON_THROW_ON_ERROR);")
        self.driver.chmod(0o644)
        self.folder = self.root / 'storage/app/desktop-updates'
        self.folder.mkdir(mode=0o755, parents=True)
        actual = self.inspect()['actual_source']
        self.assertIsInstance(actual, dict)
        content = b'MZfixture installer bytes'
        digest = hashlib.sha256(content).hexdigest()
        self.installer = self.folder / (digest + '.exe')
        self.installer.write_bytes(content)
        self.installer.chmod(0o644)
        self.release = {'version': '0.4.4-preview.42', 'sequence': 40054814945,
            'source_revision': '26c6f8b6d80ab8da05b88a00789483a51a4c9a2a',
            'source': actual, 'gui': {}, 'installer': {'sha256': digest,
                'bytes': len(content), 'path': '/api/desktop-dashboard/update/installer/' + digest}}
        self.publish()

    def write(self, name, content):
        path = self.root / name
        path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        path.write_bytes(content if isinstance(content, bytes) else content.encode())
        path.chmod(0o644)
        return path

    def publish(self):
        self.write('storage/app/desktop-updates/current.json', json.dumps(self.release))

    def inspect(self):
        result = subprocess.run([PHP, '-d', 'display_errors=0', '-d', 'log_errors=0',
            str(self.driver), str(SCRIPT), str(self.root)], text=True,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn(SECRET, result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_ready_uses_real_guards_without_modifying_any_file(self):
        before = {str(p.relative_to(self.root)): (p.read_bytes(), p.stat().st_mtime_ns)
            for p in self.root.rglob('*') if p.is_file()}
        result = self.inspect()
        self.assertEqual(result['status'], {'state': 'ready', 'version': '0.4.4-preview.42'})
        self.assertTrue(result['source_matches'])
        self.assertTrue(result['gui']['matches'])
        self.assertTrue(all(v['ok'] for v in result['checks'].values()))
        self.assertEqual(result['configuration'], {'source': 'cache', 'enabled': True, 'local': False})
        after = {str(p.relative_to(self.root)): (p.read_bytes(), p.stat().st_mtime_ns)
            for p in self.root.rglob('*') if p.is_file()}
        self.assertEqual(before, after)
        self.assertFalse(result['application_writes'])
        self.assertFalse(result['database_accessed'])

    def test_absent_feed_reports_unavailable_instead_of_inventing_release(self):
        (self.folder / 'current.json').unlink()
        result = self.inspect()
        self.assertEqual(result['status']['state'], 'unavailable')
        self.assertFalse(result['publication']['manifest']['exists'])
        self.assertIsNone(result['release_source'])

    def test_source_or_gui_drift_is_identified_by_real_comparison(self):
        self.write('config/example.php', '<?php // changed application\n')
        result = self.inspect()
        self.assertFalse(result['source_matches'])
        self.assertEqual(result['status']['state'], 'unavailable')
        self.release['source'] = result['actual_source']
        self.publish()
        self.write('public/js/dashboard-example.js', 'changedGui();\n')
        result = self.inspect()
        self.assertTrue(result['source_matches'])
        self.assertFalse(result['gui']['matches'])
        self.assertEqual(result['status']['state'], 'unavailable')

    def test_bad_manifest_and_source_link_have_fixed_stage_errors(self):
        self.write('storage/app/desktop-updates/current.json', '{' + SECRET)
        result = self.inspect()
        self.assertEqual(result['checks']['release'], {'ok': False, 'http_status': 503})
        self.assertEqual(result['checks']['status'], {'ok': False, 'http_status': 503})
        self.publish()
        protected = self.write('outside-private.php', '<?php // ' + SECRET)
        (self.root / 'app/Unsafe.php').symlink_to(protected)
        result = self.inspect()
        self.assertEqual(result['checks']['source'], {'ok': False, 'http_status': 503})
        self.assertEqual(result['status']['state'], 'error')

    def test_disabled_config_is_reported_without_provider_boot(self):
        self.write('bootstrap/cache/config.php', "<?php return ['desktop_dashboard'=>['enabled'=>false,'local'=>false], 'private'=>'" + SECRET + "'];")
        result = self.inspect()
        self.assertFalse(result['configuration']['enabled'])
        self.assertEqual(result['checks']['status'], {'ok': False, 'http_status': 503})


if __name__ == '__main__':
    unittest.main()
