"""Protocol/privacy/SQL guard tests; no server, database or application boot."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import struct
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('probe', ROOT / 'deployment/diagnose_go_erp_import422_root.py')
PROBE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PROBE)
PHP = os.environ.get('GO_ERP_TEST_PHP') or shutil.which('php')


class FragmentedSocket:
    def __init__(self, response):
        self.response = response
        self.sent = b''
    def settimeout(self, timeout):
        pass
    def sendall(self, data):
        self.sent += data
    def recv(self, count):
        result, self.response = self.response[:min(count, 3)], self.response[min(count, 3):]
        return result


def response(kind, data=b'', request=1, padding=0):
    return struct.pack('!BBHHBB', 1, kind, request, len(data), padding, 0) + data + b'\0' * padding


def end():
    return response(3, struct.pack('!IB3x', 0, 0))


class FastCgiTests(unittest.TestCase):
    def test_fragmented_response_and_padding(self):
        sock = FragmentedSocket(response(6, b'one', padding=7) + response(6, b'two') + end())
        self.assertEqual(PROBE.fastcgi(sock, {'SCRIPT_FILENAME': '/private/test.php'}), b'onetwo')
        self.assertIn(b'/private/test.php', sock.sent)
        self.assertNotIn(b'PHP_VALUE', sock.sent)
        self.assertNotIn(b'PHP_ADMIN_VALUE', sock.sent)

    def test_stderr_is_not_forwarded(self):
        with self.assertRaisesRegex(PROBE.Stop, '^FCGI_PRIVATE_SCRIPT_FAILED$'):
            PROBE.fastcgi(FragmentedSocket(response(7, b'private account value') + end()), {})

    def test_wrong_request_id(self):
        with self.assertRaisesRegex(PROBE.Stop, '^FCGI_RESPONSE_HEADER_INVALID$'):
            PROBE.fastcgi(FragmentedSocket(response(6, b'ok', request=2) + end()), {})

    def test_truncated_response(self):
        with self.assertRaisesRegex(PROBE.Stop, '^FCGI_TRUNCATED_RESPONSE$'):
            PROBE.fastcgi(FragmentedSocket(response(6, b'text')[:-1]), {})

    def test_response_byte_cap(self):
        with self.assertRaisesRegex(PROBE.Stop, '^FCGI_RESPONSE_LIMIT$'):
            PROBE.fastcgi(FragmentedSocket(response(6, b'x' * (PROBE.MAX_FCGI_BYTES + 1)) + end()), {})

    def test_unexpected_record_and_failed_end(self):
        for wire, error in [(response(4), 'FCGI_RESPONSE_KIND_INVALID'),
                            (response(3, struct.pack('!IB3x', 1, 0)), 'FCGI_REQUEST_FAILED')]:
            with self.subTest(error=error), self.assertRaisesRegex(PROBE.Stop, '^' + error + '$'):
                PROBE.fastcgi(FragmentedSocket(wire), {})

    def test_pool_matches_only_exact_account_and_rejects_duplicate(self):
        self.assertEqual(PROBE.parse_pool('[a]\nuser=other\nlisten=/x\n[b]\nuser=fasakha\nlisten=/y'),
                         [{'user': 'fasakha', 'listen': '/y'}])
        with self.assertRaisesRegex(PROBE.Stop, '^FPM_POOL_DUPLICATE_SETTING$'):
            PROBE.parse_pool('[a]\nuser=fasakha\nuser=other\nlisten=/y')


def valid_cli_report():
    return {'result': 'GO_ERP_IMPORT422_READ_ONLY_CHECK',
        'server_cli': {'sapi': 'cli', 'php_version': '8.2.30', 'precision': '14', 'serialize_precision': '-1'},
        'matched_devices': 1, 'base_branch_count': 2, 'actor_rows_in_users_query': 1,
        'actor_row_check_passes': True, 'tables_checked': 119, 'ddl_guard_rejected_tables': [],
        'schema_hash_precision_invariant': True, 'numeric_tables': {'orders': {'rows_checked': 1,
            'columns': {'tax': {'sql_type': 'double', 'php_types': {'float': 1},
                'is_numeric_projection': True, 'json_float_values': 0}},
            'numeric_projection_mismatch_rows': {'17=>-1': 1, '17=>17': 0, '-1=>-1': 0, '-1=>17': 1}}},
        'source_precision_variants': [17, -1], 'target_precision_variants': [-1, 17],
        'projection_scope': 'base account queries; selected numeric/JSON columns; before redaction and FK closure',
        'catalog_grant_hashes_checked': False,
        'catalog_reason': 'grant export requires locks; actual wire snapshot is not available',
        'actual_snapshot_checked': False, 'desktop_actual_settings_checked': False,
        'guarded_read_queries': 400, 'credentials_disclosed': False, 'schema_or_business_writes': False}


class ReportTests(unittest.TestCase):
    def test_aggregate_report_passes(self):
        value = valid_cli_report()
        self.assertEqual(PROBE.validate_cli_report(value, 17), value)

    def test_extra_child_data_is_not_forwarded(self):
        for mutate in [lambda x: x.update({'private_row': 'secret'}),
                       lambda x: x['numeric_tables']['orders']['columns']['tax'].update({'value': 'secret'}),
                       lambda x: x.update({'catalog_reason': 'private account value'}),
                       lambda x: x['numeric_tables']['orders']['numeric_projection_mismatch_rows'].update({'17=>-1': 'secret'}),
                       lambda x: x['server_cli'].update({'php_version': 'secret'})]:
            value = copy.deepcopy(valid_cli_report())
            mutate(value)
            with self.assertRaises(PROBE.Stop):
                PROBE.validate_cli_report(value, 17)


@unittest.skipUnless(PHP, 'set GO_ERP_TEST_PHP or install PHP for pure helper checks')
class PhpGuardTests(unittest.TestCase):
    def run_php(self, body, options=()):
        source = '<?php require ' + json.dumps(str(ROOT / 'deployment/diagnose_go_erp_import422.php')) + ';\n' + body
        result = subprocess.run([PHP, '-n', *options], input=source, text=True, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, check=True)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def test_read_sql_guard_denies_writes_locks_and_function_side_effects(self):
        allowed = ['SELECT 1', 'SHOW CREATE TABLE `orders`', 'select COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME=?']
        denied = ['UPDATE users SET x=1', 'DROP TABLE orders', 'SET SESSION TRANSACTION READ WRITE',
            'SELECT * FROM users FOR UPDATE', 'SELECT * FROM users LOCK IN SHARE MODE',
            "SELECT * INTO OUTFILE '/tmp/leak' FROM users", 'SELECT GET_LOCK(?,1)', 'SELECT SLEEP(1)',
            'SELECT 1; DELETE FROM orders', 'SELECT /*!50000 SLEEP(1) */ 1', 'SELECT LOAD_FILE(?)']
        result = self.run_php('echo json_encode(array_map("goErp422ReadSql", ' + json.dumps(allowed + denied) + '));')
        self.assertEqual(result, [True] * len(allowed) + [False] * len(denied))

    def test_cross_precision_float_mismatch_and_decimal_string_stability(self):
        result = self.run_php('''$before=ini_get('serialize_precision');
            echo json_encode([goErp422Roundtrip(['value'=>0.1],17,-1),
              goErp422Roundtrip(['value'=>0.1],-1,17),goErp422Roundtrip(['value'=>0.1],17,17),
              goErp422Roundtrip(['value'=>'0.10'],17,-1),ini_get('serialize_precision')===$before]);''')
        self.assertEqual(result, [False, False, True, True, True])

    def test_ddl_guard_accepts_valid_actions_and_rejects_unsafe_syntax(self):
        accepted = ["CREATE TABLE `orders` (`id` int NOT NULL, `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT `x` FOREIGN KEY (`id`) REFERENCES `users` (`id`) ON DELETE CASCADE) ENGINE=InnoDB",
                    "CREATE TABLE `orders` (`name` varchar(50) DEFAULT 'DROP; UPDATE') ENGINE=InnoDB"]
        denied = ["CREATE TABLE `orders` (`id` int) ENGINE=InnoDB; DROP TABLE users",
                  "CREATE TABLE `orders` (`id` int, FOREIGN KEY (`id`) REFERENCES `other`.`users` (`id`)) ENGINE=InnoDB",
                  "CREATE TABLE `orders` (`id` int) ENGINE=FEDERATED",
                  "CREATE TABLE `users` (`id` int) ENGINE=InnoDB"]
        result = self.run_php('$items=' + json.dumps(accepted + denied) + '; echo json_encode(array_map(fn($ddl)=>goErp422DdlSafe("orders",$ddl),$items));')
        self.assertEqual(result, [True] * len(accepted) + [False] * len(denied))

    def test_standalone_source_check_does_not_execute_application(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory).resolve()
            (base / 'app').mkdir()
            (base / 'bootstrap/cache').mkdir(parents=True)
            (base / 'config').mkdir()
            marker = base / 'must-not-exist'
            content = '<?php file_put_contents(' + json.dumps(str(marker)) + ', "executed");\r\n'
            (base / 'app/Example.php').write_bytes(content.encode())
            (base / 'bootstrap/cache/config.php').write_text('ignored cache')
            (base / 'config/.env').write_text('ignored confidential content')
            file_hash = hashlib.sha256(content.replace('\r\n', '\n').encode()).hexdigest()
            expected = hashlib.sha256(('desktop-dashboard-source:1\napp/Example.php\0' + file_hash + '\n').encode()).hexdigest()
            actual = self.run_php('echo json_encode(goErp422SourceDigest(' + json.dumps(str(base)) + '));')
            self.assertEqual(actual, expected)
            self.assertFalse(marker.exists())
            (base / 'app/Example.php').write_text('<?php // source changed')
            changed = self.run_php('echo json_encode(goErp422SourceDigest(' + json.dumps(str(base)) + '));')
            self.assertNotEqual(changed, expected)

    @unittest.skipUnless(os.environ.get('GO_ERP_TEST_FRAMEWORK') and os.environ.get('GO_ERP_TEST_PDO'),
                         'set framework and PDO paths to exercise the real Laravel connection')
    def test_real_laravel_connection_blocks_before_resolving_pdo_and_reconnect(self):
        framework = str(Path(os.environ['GO_ERP_TEST_FRAMEWORK']) / 'local-autoload.php')
        result = self.run_php('require ' + json.dumps(framework) + ''';
            $queries=0; $pdoCalls=0; $unsafeReconnect=0;
            $connection=goErp422GuardedConnection(function()use(&$pdoCalls){$pdoCalls++;throw new RuntimeException('NO_DATABASE');},'probe','',[],$queries);
            $errors=[];
            foreach(['UPDATE users SET name=?','SELECT * FROM users FOR UPDATE','DROP TABLE orders'] as $sql){
                try{$connection->statement($sql,['private']);}catch(GoErpImport422ProbeStopped $e){$errors[]=$e->getMessage();}
            }
            $connection->setReconnector(function()use(&$unsafeReconnect){$unsafeReconnect++;});
            try{$connection->reconnect();}catch(GoErpImport422ProbeStopped $e){$errors[]=$e->getMessage();}
            echo json_encode([$errors,$queries,$pdoCalls,$unsafeReconnect]);''',
            ['-d', 'extension=' + os.environ['GO_ERP_TEST_PDO']])
        self.assertEqual(result, [['SQL_WRITE_OR_LOCK_BLOCKED'] * 3 + ['SQL_RECONNECT_BLOCKED'], 0, 0, 0])


if __name__ == '__main__':
    unittest.main()
