import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


SPEC = importlib.util.spec_from_file_location('comparison', Path(__file__).with_name('dashboard_failure_comparison.py'))
COMPARISON = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(COMPARISON)


class FailureComparisonTest(unittest.TestCase):
    def test_stack_paths_and_durations_do_not_change_the_failure_signature(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            reports = []
            for label, duration in [('baseline', '1.2'), ('candidate', '9.3')]:
                report = directory / (label + '.xml')
                report.write_text('<testsuites><testsuite><testcase class="Tests\\Fixture" name="test_one" time="' + duration + '"><failure type="Assertion">Tests\\Fixture::test_one\nFailed asserting that 0 is identical to 1.\n\n/runner/' + label + '/test.php:20\n</failure></testcase></testsuite></testsuites>')
                reports.append(COMPARISON.collect(report, 1))
            self.assertEqual(reports[0], reports[1])
            for engine in ('sqlite', 'mysql'):
                for label, report in zip(('baseline', 'candidate'), reports):
                    (directory / (label + '-' + engine + '.json')).write_text(json.dumps(report))
            self.assertTrue(all(item['no_new_failures'] for item in COMPARISON.compare(directory)))
            reports[1]['issues'][0]['message'] = 'Different failure'
            (directory / 'candidate-mysql.json').write_text(json.dumps(reports[1]))
            self.assertFalse(COMPARISON.compare(directory)[1]['no_new_failures'])

    def test_missing_test_results_cannot_be_reported_as_clean(self):
        with tempfile.TemporaryDirectory() as temporary:
            report = Path(temporary) / 'empty.xml'
            report.write_text('<testsuites/>')
            with self.assertRaises(ValueError):
                COMPARISON.collect(report, 2)


if __name__ == '__main__':
    unittest.main()
