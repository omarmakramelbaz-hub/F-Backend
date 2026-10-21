import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest


REPOSITORY = Path(__file__).resolve().parents[1]
PROBE = REPOSITORY / 'deployment' / 'go_erp_postbackup_probe.php'
PHP = shutil.which('php')


class PostbackupProbeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = PROBE.read_text()
        cls.reviewed = subprocess.check_output([
            'git', '-c', 'safe.directory=' + str(REPOSITORY), '-C', str(REPOSITORY),
            'cat-file', 'blob', '69f4efc30a440d7e80778d0618f0f0fa7f79d46d'], text=True)

    def test_exact_migration_and_table_roster_matches_reviewed_release(self):
        pattern = r"'(2026_[^']+create_desktop_dashboard[^']*)'=>\[([^\]]+)\]"
        rows = re.findall(pattern, self.text)
        reviewed = re.findall(pattern, self.reviewed)
        self.assertEqual(rows, reviewed)
        self.assertEqual(len(rows), 8)
        tables = [table for _migration, group in rows for table in re.findall(r"'(desktop_dashboard_[^']+)'", group)]
        self.assertEqual(len(tables), 11)
        self.assertEqual(len(set(tables)), 11)

    def test_transaction_is_read_only_and_always_rolled_back(self):
        self.assertLess(self.text.index("$db->statement('SET TRANSACTION READ ONLY')"), self.text.index('$db->beginTransaction()'))
        self.assertLess(self.text.index('$db->beginTransaction()'), self.text.index("$phase = 'MIGRATION_METADATA'"))
        self.assertIn('finally {', self.text)
        self.assertIn('$db->rollBack()', self.text)
        for operation in ('->commit(', '->insert(', '->update(', '->delete(', '->save(', '->create(', '->call('):
            self.assertNotIn(operation, self.text)

    def test_compiled_cache_is_only_stat_not_read_or_compile(self):
        self.assertIn("->getCompiledPath($root.'/'.$source)", self.text)
        self.assertIn('lstat($target)', self.text)
        for operation in ('compileString', '->compile(', 'file_get_contents($target)', 'file_put_contents', 'unlink(', 'chmod(', 'chown(', 'proc_open', 'exec('):
            self.assertNotIn(operation, self.text)

    def test_manifest_and_private_cache_are_fixed_and_separated(self):
        self.assertIn('da47b5e5dc50676d6046a6511b3905a0cd053ed1', self.text)
        self.assertIn("$root = '/home/fasakha/public_html'", self.text)
        self.assertIn("'APP_SERVICES_CACHE'=>'probe-services.php'", self.text)
        self.assertIn("'APP_PACKAGES_CACHE'=>'probe-packages.php'", self.text)
        self.assertIn('realpath($private) !== $private', self.text)
        self.assertIn("str_starts_with($private, $root.'/')", self.text)

    def test_errors_and_database_result_have_no_raw_private_contents(self):
        for expression in ('getMessage(', 'getTrace(', 'getTraceAsString(', "['password']", "['token']", 'getConfig(', 'getDatabaseName(', "->get(['"):
            self.assertNotIn(expression, self.text)
        self.assertIn("ini_set('display_errors', '0')", self.text)
        self.assertIn("ini_set('log_errors', '0')", self.text)
        self.assertIn('ob_end_clean()', self.text)
        self.assertIn("'error_class'=>in_array($class, $allowed, true) ? $class : 'Throwable'", self.text)

    @unittest.skipUnless(PHP, 'PHP CLI unavailable locally; required by the CI job')
    def test_php_lint(self):
        result = subprocess.run([PHP, '-l', str(PROBE)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    @unittest.skipUnless(PHP, 'PHP CLI unavailable locally; required by the CI job')
    def test_cli_invalid_private_path_is_bounded_json_without_echoing_input(self):
        result = subprocess.run([PHP, str(PROBE), '/INVALID_PRIVATE_SECRET_ROOT',
            '/INVALID_PRIVATE_SECRET_DIRECTORY', '/INVALID_PRIVATE_SECRET_MANIFEST'], capture_output=True, text=True)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, '')
        data = json.loads(result.stdout)
        self.assertEqual(data, {'kind': 'go-erp-postbackup-read-only-probe', 'available': False,
            'phase': 'ARGUMENTS', 'error_class': 'RuntimeException'})
        self.assertNotIn('SECRET', result.stdout)


if __name__ == '__main__':
    unittest.main()
