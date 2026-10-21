"""Exercise the actual reviewed PHP view guards without booting production."""
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest


REPOSITORY = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
ROOT = '/home/fasakha/public_html'
FILES = ('deployment/desktop_dashboard_release.php', 'deployment/go_erp_postbackup_probe.php')
EVALUATOR = r'''
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
set_error_handler(function() { throw new ErrorException(); });
try {
    $root = $input['root']; $project = $root; $views = [];
    $results = [];
    foreach ($input['cases'] as $candidate) {
        $name = $candidate; $relative = $candidate; $target = $candidate;
        $rejected = eval('return ('.$input['predicate'].');');
        $results[] = ['rejected'=>(bool)$rejected, 'preg_error'=>preg_last_error()];
    }
    echo json_encode($results, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['runtime_guard_failure'=>get_class($error)], JSON_THROW_ON_ERROR);
    exit(1);
}
'''


def view_predicates(text):
    """Extract balanced if predicates verbatim, respecting PHP quoted strings."""
    result = {}
    for match in re.finditer(r'\bif\s*\(', text):
        begin = match.end()
        depth, quote, escaped = 1, None, False
        for index in range(begin, len(text)):
            char = text[index]
            if quote:
                if escaped:
                    escaped = False
                elif char == '\\':
                    escaped = True
                elif char == quote:
                    quote = None
            elif char in ('"', "'"):
                quote = char
            elif char == '(':
                depth += 1
            elif char == ')':
                depth -= 1
                if depth == 0:
                    predicate = text[begin:index]
                    if 'preg_match' in predicate:
                        kind = ('source' if 'resources/views/' in predicate else
                                'compiled' if 'storage/framework/views/' in predicate else None)
                        if kind:
                            if kind in result:
                                raise AssertionError('ambiguous reviewed view guard')
                            result[kind] = predicate
                    break
    if set(result) != {'source', 'compiled'}:
        raise AssertionError('both exact PHP view guards must be present')
    return result


class GuardExtractionTests(unittest.TestCase):
    def test_guards_are_extracted_verbatim_and_bind_both_scopes(self):
        for name in FILES:
            text = (REPOSITORY / name).read_text()
            predicates = view_predicates(text)
            self.assertTrue(all(predicate in text for predicate in predicates.values()))
            self.assertIn('preg_match', predicates['source'])
            self.assertIn('preg_quote(', predicates['compiled'])


class PhpViewGuardRuntimeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if PHP is None:
            raise AssertionError('PHP CLI is required; runtime guard tests must not be skipped')
        cls.manifest = json.loads(subprocess.check_output([
            'git', '-c', 'safe.directory=' + str(REPOSITORY), '-C', str(REPOSITORY),
            'cat-file', 'blob', 'da47b5e5dc50676d6046a6511b3905a0cd053ed1'], text=True))
        cls.paths = [row['path'] for row in cls.manifest['sources']
                     if row['path'].startswith('resources/views/') and row['path'].endswith('.blade.php')]
        if len(cls.paths) != 38:
            raise AssertionError('the reviewed release must exercise all 38 Blade paths')
        cls.predicates = {name: view_predicates((REPOSITORY / name).read_text()) for name in FILES}

    def evaluate(self, predicate, cases):
        result = subprocess.run([PHP, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', EVALUATOR],
            input=json.dumps({'root': ROOT, 'predicate': predicate, 'cases': cases}), text=True,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
        self.assertEqual(result.returncode, 0, result.stdout)
        self.assertEqual(result.stderr, '', 'PHP warnings are test failures')
        rows = json.loads(result.stdout)
        self.assertIsInstance(rows, list)
        self.assertEqual(len(rows), len(cases))
        self.assertTrue(all(row['preg_error'] == 0 for row in rows))
        return [row['rejected'] for row in rows]

    def test_all_38_actual_manifest_paths_pass_both_real_source_guards(self):
        for name, guards in self.predicates.items():
            with self.subTest(file=name):
                self.assertEqual(self.evaluate(guards['source'], self.paths), [False] * 38)

    def test_source_guards_reject_traversal_and_out_of_scope_paths(self):
        cases = ['resources/views/../private.blade.php', 'resources/views/admin/../../private.blade.php',
            '/resources/views/admin/index.blade.php', 'storage/framework/views/index.blade.php',
            'resources/views/admin/index.php', 'resources/views/admin/index.blade.php\n',
            'resources/views/admin/private\x00.blade.php', 'resources/views/admin/أسرار.blade.php']
        for name, guards in self.predicates.items():
            with self.subTest(file=name):
                self.assertEqual(self.evaluate(guards['source'], cases), [True] * len(cases))

    def test_valid_compiled_targets_pass_both_real_target_guards(self):
        cases = [ROOT + '/storage/framework/views/' + value * 40 + '.php' for value in ('0', 'a', 'f')]
        for name, guards in self.predicates.items():
            with self.subTest(file=name):
                self.assertEqual(self.evaluate(guards['compiled'], cases), [False] * len(cases))

    def test_compiled_guards_reject_bad_hashes_root_scope_and_traversal(self):
        good = ROOT + '/storage/framework/views/' + 'a' * 40 + '.php'
        cases = [good.replace('a' * 40, 'a' * 39), good.replace('a' * 40, 'A' * 40),
            good.replace('a' * 40, 'a' * 41), good.replace(ROOT, '/home/other/public_html'),
            good.replace('/views/', '/views/../'), good + '\n', good + '.txt',
            'storage/framework/views/' + 'a' * 40 + '.php']
        for name, guards in self.predicates.items():
            with self.subTest(file=name):
                self.assertEqual(self.evaluate(guards['compiled'], cases), [True] * len(cases))

    def test_harness_detects_original_delimiter_bug_as_runtime_failure(self):
        for name, guards in self.predicates.items():
            for kind, predicate in guards.items():
                with self.subTest(file=name, guard=kind):
                    broken = predicate.replace(r'\z#D', r'\z/D')
                    self.assertNotEqual(broken, predicate)
                    candidate = self.paths[0] if kind == 'source' else ROOT + '/storage/framework/views/' + 'a' * 40 + '.php'
                    result = subprocess.run([PHP, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', EVALUATOR],
                        input=json.dumps({'root': ROOT, 'predicate': broken, 'cases': [candidate]}), text=True,
                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
                    self.assertEqual(result.returncode, 1)
                    self.assertEqual(json.loads(result.stdout), {'runtime_guard_failure': 'ErrorException'})
                    self.assertEqual(result.stderr, '')


if __name__ == '__main__':
    unittest.main()
