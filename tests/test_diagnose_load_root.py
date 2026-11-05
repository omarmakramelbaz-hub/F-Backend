"""The load diagnosis reads requests and queries without their values."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('load_diagnosis', ROOT / 'deployment/diagnose_load_root.py')
load = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(load)


class LoadDiagnosisTests(unittest.TestCase):
    def test_addresses_and_queries_keep_their_shape_never_their_values(self):
        self.assertEqual(load.path_shape('/admin/branch-expenses/1234/review?token=secret'), '/admin/branch-expenses/#/review')
        self.assertEqual(load.path_shape('/api/desktop-sync/v1/sync'), '/api/desktop-sync/v#/sync')
        self.assertEqual(load.masked('197.45.12.9'), '197.45.x.x')
        self.assertEqual(load.query_shape("select * from users where phone = '01012345678' and id in (1, 2, 3) limit 30"),
                         'select * from users where phone = ? and id in (?+) limit ?')
        self.assertEqual(load.program('Mozilla/5.0 ... GO ERP Desktop/1.18.0 Chrome/138'), 'GO ERP Desktop')

    def test_the_last_minutes_of_the_log_are_counted_by_address_and_caller(self):
        lines = []
        for minute in range(0, 20):
            for second in range(0, 60, 2):
                lines.append('41.33.1.{} - - [03/Nov/2026:23:{:02d}:{:02d} +0000] "POST /api/desktop-sync/v1/sync HTTP/2" 200 512 "-" "GO ERP Desktop/1.18.0"'
                             .format(minute % 3, minute, second))
            lines.append('156.1.2.3 - - [03/Nov/2026:23:{:02d}:30 +0000] "GET /admin/dashboard?x=1 HTTP/2" 200 9000 "-" "Mozilla Chrome"'.format(minute))
        with tempfile.NamedTemporaryFile('w', suffix='-ssl_log', delete=False) as handle:
            handle.write('first line cut\n' + '\n'.join(lines) + '\n')
        rows, newest = load.read_requests([handle.name], minutes=10)
        self.assertEqual(newest.strftime('%H:%M:%S'), '23:19:58')
        report = load.report_requests(rows, newest, minutes=10)
        self.assertIn('310 requests, 31 a minute', report[1])
        self.assertTrue(report[2].strip().startswith('300  POST /api/desktop-sync/v#/sync'))
        self.assertIn('41.33.x.x GO ERP Desktop ×300', report[-2] if 'sync' in report[-2] else '\n'.join(report))
        Path(handle.name).unlink()

    def test_running_queries_are_sampled_and_grouped(self):
        answers = iter([[["select * from notifications where notifiable_id = 5 limit 1000"]], [["select * from notifications where notifiable_id = 9 limit 1000"]], []])
        report = load.report_queries(samples=3, pause=0, run=lambda query: next(answers), sleep=lambda seconds: None)
        self.assertEqual(report[1], '    2  select * from notifications where notifiable_id = ? limit ?')


    def test_a_few_seconds_of_every_statement_are_grouped_then_the_log_is_off_and_gone(self):
        sample = ("241104  1:33:01\t   812 Connect\tfasakha@localhost as anonymous on fasakha_db\n"
                  "\t\t   812 Query\tselect * from information_schema.tables where table_schema = 'fasakha_db' and table_name = 'notifications'\n"
                  "\t\t   812 Query\tselect * from information_schema.tables where table_schema = 'fasakha_db' and table_name = 'orders'\n"
                  "\t\t   812 Query\tselect count(*) from orders where status = 'new'\n"
                  "\t\t   812 Quit\t\n")
        shapes, connects, statements = load.general_shapes(sample)
        self.assertEqual((connects, statements), (1, 3))
        self.assertEqual(shapes.most_common(1)[0], ('select * from information_schema.tables where table_schema = ? and table_name = ?', 2))
        with tempfile.TemporaryDirectory() as folder:
            log = Path(folder) / 'general.log'
            asked = []

            def run(statement):
                asked.append(statement)
                if statement.startswith('SELECT @@GLOBAL'):
                    return [['0', '/var/lib/mysql/host.log', 'FILE']]
                if "general_log = 'ON'" in statement:
                    log.write_text(sample)
                return []
            report = load.report_general_log(10, run=run, sleep=lambda seconds: None, log=log)
            self.assertFalse(log.exists(), 'Its file is removed')
        self.assertIn("SET GLOBAL general_log = 'OFF'; SET GLOBAL general_log_file = '/var/lib/mysql/host.log'; SET GLOBAL log_output = 'FILE'", asked[-1])
        self.assertIn('3 statements', report[1])
        self.assertIn('(information_schema): 2 (67%)', report[2])

    def test_the_busiest_connections_show_whether_a_long_job_or_pages_ran_the_statements(self):
        sample = ("241104  1:33:01\t   900 Connect\tfasakha@localhost on fasakha_db using Socket\n"
                  + "\t\t   900 Query\tselect * from `users` where `users`.`id` = 4 limit 1\n" * 3
                  + "\t\t   901 Query\tselect count(*) from orders where status = 'new'\n"
                  + "2026-11-04T01:33:02.000001Z\t  900 Query\tselect exists(select * from `permissions` where `name` = 'order-list') as `exists`\n")
        per_connection = {}
        load.general_shapes(sample, per_connection)
        lines = load.busiest_connections(per_connection, top=2)
        self.assertEqual('  connection 900: 4 statements', lines[1])
        self.assertIn('     3  select * from `users` where `users`.`id` = ? limit ?', lines[2])
        self.assertEqual('  connection 901: 1 statements', lines[4])
        self.assertNotIn("'new'", '\n'.join(lines))

    def test_a_php_processs_artisan_command_is_named_without_any_value(self):
        php = '/opt/cpanel/ea-php82/root/usr/bin/php'
        self.assertEqual('crm:whatsapp-process --limit', load.artisan_command([php, '/home/site/artisan', 'crm:whatsapp-process', '--limit=25', 'secret-value']))
        self.assertEqual('schedule:run', load.artisan_command([php, 'artisan', 'schedule:run']))
        self.assertEqual('tinker --execute', load.artisan_command([php, 'artisan', 'tinker', '--execute=DB::table("users")->delete()']))
        self.assertEqual('(artisan)', load.artisan_command([php, 'artisan', 'Hidden Value']))
        self.assertIsNone(load.artisan_command([php, '-r', 'echo 1;']))

if __name__ == '__main__':
    unittest.main()
