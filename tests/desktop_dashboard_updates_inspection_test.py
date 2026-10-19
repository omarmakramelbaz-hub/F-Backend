#!/usr/bin/env python3
"""Exercise only the launcher's final output validator, never server operations."""
import copy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


REPO = Path(__file__).resolve().parent.parent
LAUNCHER = REPO / 'deployment/inspect_desktop_dashboard_updates_root.sh'
VALIDATOR_START = '/usr/bin/python3 -B - "$directory" <<\'PY\'\n'
SOURCE_BEGIN = 'DESKTOP_CURRENT_SOURCE_DIAGNOSIS_BEGIN'
SOURCE_END = 'DESKTOP_CURRENT_SOURCE_DIAGNOSIS_END; DEPLOYMENT_PERFORMED=false'
ERROR = 'DESKTOP_UPDATES_INSPECTION_OUTPUT_REJECTED_NO_PRIVATE_OUTPUT\n'
SENTINEL = 'PRIVATE_CONFIGURATION_SENTINEL_DO_NOT_PRINT'


def extract_validator(script):
    if script.count(VALIDATOR_START) != 1 or not script.endswith('\nPY\n'):
        raise ValueError('The final Python validator heredoc changed.')
    return script.split(VALIDATOR_START, 1)[1][:-len('PY\n')]


class DesktopUpdatesInspectionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.validator = extract_validator(LAUNCHER.read_text())

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='desktop-output-validator-test-')
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        record = {'blob': 'a' * 40, 'sha256': 'b' * 64}
        metadata = {'uid': 1000, 'gid': 1000, 'mode': '0644', 'links': 1, 'regular': True}
        differences = [{'path': 'app/added_%03d.php' % index, 'change': 'added',
                        'expected': None, 'actual': record, 'metadata': metadata}
                       for index in range(275)]
        self.source = {
            'kind': 'desktop-current-baseline-read-only-diagnosis', 'release': 'c' * 40,
            'actual_head': 'd' * 40, 'expected_head': 'e' * 40,
            'actual_source': {'format': 1, 'files': 1302, 'sha256': 'f' * 64,
                              'framework': '8.83.29'},
            'expected_source': {'format': 1, 'files': 1027, 'sha256': '0' * 64,
                                'framework': '8.83.29'},
            'source_matches_before': False, 'source_matches_release': False,
            'source_difference_count': len(differences), 'source_differences': differences,
            'source_differences_truncated': False,
            'gui': [{'path': 'public/dashboard/js/example.js', 'expected': 'a' * 40,
                     'actual': 'a' * 40, 'metadata': metadata}],
            'related_meta_gui': [],
            'additional_dashboard_gui': [{'path': 'public/js/dashboard-extra.js',
                                          'actual': record, 'metadata': metadata}],
            'publication_metadata_states': {'original': 38, 'repaired': 0,
                                             'unreviewed': 0, 'absent': 0},
            'unreviewed_metadata': [], 'deployment_performed': False,
            'metadata_changed': False, 'php_bootstrapped': False, 'database_read': False,
            'source_content_printed': False,
        }
        self.schema = {
            'format': 1, 'kind': 'schema-only', 'generated_at': '2026-10-19T00:00:00+00:00',
            'php_version': '8.2.30', 'laravel_version': '8.83.29',
            'database_engine': '10.6.23-MariaDB', 'extensions': ['Core', 'PDO', 'pdo_mysql'],
            'tables': {'customers': {
                'columns': [{'name': 'id', 'type': 'bigint unsigned',
                             'nullable': False, 'extra': 'auto_increment'}],
                'indexes': {'PRIMARY': {'unique': True, 'columns': ['id']}},
                'references': [{'column': 'branch_id', 'table': 'branches',
                                'target_column': 'id'}],
            }},
            'routes': [{'name': None, 'methods': ['GET', 'HEAD'], 'uri': 'dashboard',
                        'action': 'DashboardController@index', 'middleware': ['web']}],
            'desktop_migrations': {'table_exists': True, 'records': [
                {'migration': '2026_10_07_210000_create_desktop_pos', 'batch': 1}]},
            'desktop_dashboard_flags': {'enabled': False, 'local': False},
        }

    def write_reports(self, source=None, schema=None):
        source = self.source if source is None else source
        schema = self.schema if schema is None else schema
        (self.folder / 'source-report.txt').write_text(
            SOURCE_BEGIN + '\n' + json.dumps(source, separators=(',', ':')) + '\n' + SOURCE_END + '\n')
        (self.folder / 'schema-report.json').write_text(json.dumps(schema))

    def run_validator(self):
        return subprocess.run([sys.executable, '-B', '-', str(self.folder)],
                              input=self.validator, text=True, capture_output=True,
                              check=False, timeout=5)

    def assert_rejected(self):
        result = self.run_validator()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, '')
        self.assertEqual(result.stderr, ERROR)
        self.assertNotIn(SENTINEL, result.stdout + result.stderr)

    def test_valid_complete_report_preserves_all_275_differences_and_schema_metadata(self):
        self.write_reports()
        result = self.run_validator()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, '')
        lines = result.stdout.splitlines()
        self.assertEqual(len(lines), 3)
        self.assertEqual(lines[0], 'DESKTOP_DASHBOARD_UPDATES_INSPECTION_BEGIN')
        self.assertEqual(lines[2], 'DESKTOP_DASHBOARD_UPDATES_INSPECTION_END; DEPLOYMENT_PERFORMED=false')
        report = json.loads(lines[1])
        self.assertEqual(report['source'], self.source)
        self.assertEqual(report['schema'], self.schema)
        self.assertEqual(len(report['source']['source_differences']), 275)
        for flag in ['deployment_performed', 'business_rows_exported', 'application_files_changed']:
            self.assertIs(report[flag], False)

    def test_truncated_or_count_mismatched_source_is_rejected(self):
        for change in ['truncated', 'count']:
            with self.subTest(change=change):
                source = copy.deepcopy(self.source)
                if change == 'truncated':
                    source['source_differences_truncated'] = True
                else:
                    source['source_difference_count'] += 1
                self.write_reports(source=source)
                self.assert_rejected()

    def test_source_safety_flags_must_remain_false(self):
        for flag in ['deployment_performed', 'metadata_changed', 'php_bootstrapped',
                     'database_read', 'source_content_printed']:
            with self.subTest(flag=flag):
                source = copy.deepcopy(self.source)
                source[flag] = True
                self.write_reports(source=source)
                self.assert_rejected()

    def test_unexpected_top_level_schema_or_configuration_fields_are_rejected(self):
        for field in ['configuration', 'database_password', 'business_rows', 'unexpected']:
            with self.subTest(field=field):
                schema = copy.deepcopy(self.schema)
                schema[field] = SENTINEL
                self.write_reports(schema=schema)
                self.assert_rejected()

    def test_unexpected_source_configuration_fields_are_rejected(self):
        source = copy.deepcopy(self.source)
        source['configuration'] = SENTINEL
        self.write_reports(source=source)
        self.assert_rejected()

    def test_unexpected_nested_schema_or_configuration_fields_are_rejected(self):
        for section, field in [('flags', 'api_token'), ('migrations', 'configuration'),
                               ('column', 'default'), ('table', 'business_rows'),
                               ('index', 'private_configuration'), ('reference', 'password'),
                               ('route', 'private_configuration')]:
            with self.subTest(section=section, field=field):
                schema = copy.deepcopy(self.schema)
                item = {'flags': schema['desktop_dashboard_flags'],
                        'migrations': schema['desktop_migrations'],
                        'column': schema['tables']['customers']['columns'][0],
                        'table': schema['tables']['customers'],
                        'index': schema['tables']['customers']['indexes']['PRIMARY'],
                        'reference': schema['tables']['customers']['references'][0],
                        'route': schema['routes'][0]}[section]
                item[field] = SENTINEL
                self.write_reports(schema=schema)
                self.assert_rejected()

    def test_malformed_nested_schema_types_are_rejected(self):
        mutations = [
            lambda value: value['desktop_dashboard_flags'].update(enabled=1),
            lambda value: value['desktop_migrations'].update(table_exists=1),
            lambda value: value['desktop_migrations'].update(records=SENTINEL),
            lambda value: value['desktop_migrations']['records'][0].update(batch=True),
            lambda value: value.update(extensions=[SENTINEL, 1]),
            lambda value: value['tables']['customers'].update(columns=SENTINEL),
            lambda value: value['tables']['customers']['columns'][0].update(nullable=1),
            lambda value: value['tables']['customers']['indexes']['PRIMARY'].update(unique=1),
            lambda value: value['tables']['customers']['indexes']['PRIMARY'].update(columns=SENTINEL),
            lambda value: value['tables']['customers'].update(references={}),
            lambda value: value['tables']['customers']['references'][0].update(table=42),
            lambda value: value['routes'][0].update(methods=SENTINEL),
            lambda value: value['routes'][0].update(middleware=[SENTINEL, 1]),
        ]
        for index, mutate in enumerate(mutations):
            with self.subTest(mutation=index):
                schema = copy.deepcopy(self.schema)
                mutate(schema)
                self.write_reports(schema=schema)
                self.assert_rejected()

    def test_php_optional_table_metadata_and_empty_route_list_remain_valid(self):
        schema = copy.deepcopy(self.schema)
        del schema['tables']['customers']['indexes']
        del schema['tables']['customers']['references']
        schema['routes'] = []
        schema['desktop_migrations'] = {'table_exists': False, 'records': []}
        self.write_reports(schema=schema)
        result = self.run_validator()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout.splitlines()[1])['schema'], schema)

    def test_malformed_php_output_does_not_expose_private_diagnostics(self):
        self.write_reports()
        for data in [SENTINEL, 'PHP Warning: ' + SENTINEL + '\n' + json.dumps(self.schema),
                     json.dumps(self.schema) + '\n' + SENTINEL, '["' + SENTINEL + '"]']:
            with self.subTest(data=data[:20]):
                (self.folder / 'schema-report.json').write_text(data)
                self.assert_rejected()

    def test_malformed_source_wrappers_or_json_do_not_expose_private_diagnostics(self):
        self.write_reports()
        original = (self.folder / 'source-report.txt').read_text()
        for data in [original.replace(SOURCE_BEGIN, SENTINEL),
                     original.replace(SOURCE_END, SENTINEL),
                     original + SENTINEL + '\n',
                     SOURCE_BEGIN + '\n' + SENTINEL + '\n' + SOURCE_END + '\n']:
            with self.subTest(data=data[:20]):
                (self.folder / 'source-report.txt').write_text(data)
                self.assert_rejected()

    def test_oversized_source_or_schema_reports_are_rejected(self):
        oversized = SENTINEL.encode() + b'x' * (16 * 1024 * 1024)
        for filename in ['source-report.txt', 'schema-report.json']:
            with self.subTest(filename=filename):
                self.write_reports()
                (self.folder / filename).write_bytes(oversized)
                self.assert_rejected()

    def test_extraction_requires_the_exact_final_python_heredoc(self):
        script = LAUNCHER.read_text()
        for changed in [script + 'echo SHOULD_NOT_RUN\n', script.replace("<<'PY'", "<<'OTHER'"),
                        script.replace('\nPY\n', '\nOTHER\n')]:
            with self.assertRaises(ValueError):
                extract_validator(changed)


if __name__ == '__main__':
    unittest.main()
