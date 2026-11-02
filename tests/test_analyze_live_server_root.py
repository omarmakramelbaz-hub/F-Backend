"""After a day of the slow log: query shapes and repeating errors, never a value or a message; then the log goes."""
import datetime
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('live_analysis', ROOT / 'deployment/analyze_live_server_root.py')
analysis = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(analysis)

SLOW_LOG = """/usr/sbin/mariadbd, Version: 10.11.19-MariaDB (MariaDB Server). started with:
Tcp port: 3306  Unix socket: /var/lib/mysql/mysql.sock
Time\t\t    Id Command\tArgument
# Time: 261102 20:00:01
# User@Host: fasakha_app[fasakha_app] @ localhost []
# Thread_id: 7  Schema: fasakha_db  QC_hit: No
# Query_time: 0.600000  Lock_time: 0.000100  Rows_sent: 1  Rows_examined: 40000
# Rows_affected: 0  Bytes_sent: 120
use fasakha_db;
SET timestamp=1762113601;
select * from `users` where `mobile` = '01001234567' and `id` in (11, 12, 13) limit 1;
# User@Host: fasakha_app[fasakha_app] @ localhost []
# Query_time: 1.400000  Lock_time: 0.000100  Rows_sent: 1  Rows_examined: 60000
SET timestamp=1762117201;
select * from `users` where `mobile` = 'O\\'Brien''s 0100' and `id` in (7) limit 1;
# User@Host: fasakha_app[fasakha_app] @ localhost []
# Query_time: 0.400000  Lock_time: 0.000000  Rows_sent: 0  Rows_examined: 10
SET timestamp=1762120801;
insert into `whatsapp_webhook_events` (`payload`, `t2`) values ('{"phone":"201234567890"}', 5), ('x', -6);
# User@Host: fasakha_app[fasakha_app] @ localhost []
# Query_time: 0.300000  Lock_time: 0.000000  Rows_sent: 0  Rows_examined: 10
SET timestamp=1762120900;
select * from notes where body = 'cut off in the log
"""

LARAVEL_LOG = """[2026-10-20 10:00:00] production.ERROR: too old to count {"exception":"[object] (ErrorException(code: 0): old at /home/fasakha/public_html/app/Old.php:3)
[stacktrace]
#0 /home/fasakha/public_html/app/Old.php(3): x()
"}
[2026-11-01 09:00:00] production.ERROR: SQLSTATE[42S22]: Column not found: customer 01009998887 {"userId":5,"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 42S22): SQLSTATE[42S22]: Column not found at /home/fasakha/public_html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:712)
[stacktrace]
#0 /home/fasakha/public_html/vendor/laravel/framework/src/Illuminate/Database/Connection.php(672): run()
#1 /home/fasakha/public_html/storage/framework/views/0a1b2c.php(12): view()
#2 /home/fasakha/public_html/app/Services/Dashboard/HomeOverview.php(88): Illuminate\\\\Database\\\\Query\\\\Builder->get()
#3 {main}
"}
[2026-11-02 09:00:00] production.ERROR: SQLSTATE[42S22]: Column not found: customer 01005554443 {"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 42S22): SQLSTATE[42S22]: x at /home/fasakha/public_html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:712)
[stacktrace]
#0 /home/fasakha/public_html/app/Services/Dashboard/HomeOverview.php(88): get()
"}
[2026-11-02 10:00:00] production.ERROR: Undefined variable $order {"exception":"[object] (ErrorException(code: 0): Undefined variable $order at /home/fasakha/public_html/app/Http/Controllers/Api/OrderController.php:41)
[stacktrace]
#0 /home/fasakha/public_html/app/Http/Controllers/Api/OrderController.php(41): handleError()
"}
[2026-11-02 11:00:00] production.WARNING: a warning that is not counted
[2026-11-02 12:00:00] production.ERROR: payment refused for card 4111111111111111
"""


class AnalysisTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-analysis-')
        self.base = Path(self.temporary.name)

    def tearDown(self):
        self.temporary.cleanup()

    def test_query_shapes_never_keep_a_value(self):
        self.assertEqual(analysis.shape("select * from `t2` where a = 'x' and b in (1, 2, 3) and c > -4.5 limit 10;"),
                         'select * from `t2` where a = ? and b in (?+) and c > ? limit ?')
        self.assertEqual(analysis.shape("insert into t (a, b) values ('x', 1), ('y', 2), (0x1F, 3)"),
                         'insert into t (a, b) values (?+), ...')
        self.assertEqual(analysis.shape("select col2, t1.c3 from t1 where `2fa` = 'it''s' and d = \"q\\\"x\""),
                         'select col2, t1.c3 from t1 where `2fa` = ? and d = ?')
        self.assertIsNone(analysis.shape("select * from notes where body = 'cut off"), 'An unclosed value is never shown')

    def test_slow_queries_are_grouped_by_shape_and_ranked_by_time(self):
        log = self.base / 'slow.log'
        log.write_text(SLOW_LOG)
        found = analysis.slow_queries(log)
        self.assertEqual((found['entries'], found['withheld']), (4, 1))
        users = found['shapes']['select * from `users` where `mobile` = ? and `id` in (?+) limit ?']
        self.assertEqual((users['count'], round(users['seconds'], 1), users['examined']), (2, 2.0, 100000))
        output = analysis.report(found, analysis.site_errors(self.base), {})
        self.assertIn('4 slow queries in 3 shapes, 2025-11-02 20:00 UTC to 2025-11-02 22:01 UTC; 1 not shown', output)
        self.assertLess(output.index('`users`'), output.index('whatsapp_webhook_events'), 'Ranked by total time')
        for value in ('01001234567', 'Brien', '201234567890', 'cut off'):
            self.assertNotIn(value, output)

    def test_errors_are_grouped_by_class_and_the_sites_own_line_without_messages(self):
        logs = self.base / 'logs'
        logs.mkdir()
        (logs / 'laravel.log').write_text(LARAVEL_LOG)
        (logs / 'queue.log').write_text('Processing: App\\Jobs\\SendOrder\n')
        found = analysis.site_errors(logs, today=datetime.date(2026, 11, 2))
        self.assertEqual(found['since'], '2026-10-27')
        self.assertEqual(found['per_day'], [('2026-11-01', 1), ('2026-11-02', 3)])
        self.assertEqual(found['top'][0], {'class': 'Illuminate\\Database\\QueryException', 'code': '42S22',
                                           'where': 'app/Services/Dashboard/HomeOverview.php:88', 'count': 2, 'last': '2026-11-02',
                                           'logs': 'laravel.log'})
        self.assertIn({'class': 'ErrorException', 'code': '0', 'where': 'app/Http/Controllers/Api/OrderController.php:41', 'count': 1,
                       'last': '2026-11-02', 'logs': 'laravel.log'}, found['top'])
        self.assertIn({'class': '(logged message)', 'code': '', 'where': "not in the site's own code", 'count': 1, 'last': '2026-11-02',
                       'logs': 'laravel.log'}, found['top'])
        output = analysis.report({'missing': True}, found, {'load': '0.5 0.4 0.3'})
        self.assertIn('     2x  Illuminate\\Database\\QueryException(42S22) at app/Services/Dashboard/HomeOverview.php:88  (last 2026-11-02, laravel.log)', output)
        for value in ('01009998887', '01005554443', '4111111111111111', 'Undefined variable', 'payment refused'):
            self.assertNotIn(value, output)

    def test_finishing_puts_the_slow_log_back_and_removes_its_file(self):
        tuning = self.base / 'go-erp-server-tuning'
        (tuning / '20261102-195316-071694').mkdir(parents=True)
        (tuning / '20261102-195316-071694/manifest.json').write_text(json.dumps({'done': {'slowlog': {'previous': {
            'slow_query_log': 'OFF', 'long_query_time': '0.300000', 'slow_query_log_file': 'srv1836524-slow.log'}}}}))
        log = self.base / 'go-erp-slow.log'
        log.write_text(SLOW_LOG)
        calls = []
        original = analysis.command
        analysis.command = lambda arguments, timeout=300: calls.append(arguments) or (0, '')
        try:
            words = analysis.finish(tuning, log)
        finally:
            analysis.command = original
        self.assertEqual(calls, [['mysql', '-e', "SET GLOBAL slow_query_log=OFF; SET GLOBAL long_query_time=0.3; "
                                                 "SET GLOBAL slow_query_log_file='srv1836524-slow.log';"]])
        self.assertFalse(log.exists())
        self.assertEqual(words, 'slow query log back to OFF (as before); its file removed')


if __name__ == '__main__':
    unittest.main()
