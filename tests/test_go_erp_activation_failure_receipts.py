"""Preserve useful activation receipts while excluding framework/private output."""
import ast
import contextlib
import io
from pathlib import Path
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]

def load(name):
    text = (ROOT / 'deployment' / name).read_text()
    code = text.split("<<'PY'\n", 1)[1].rsplit('\nPY', 1)[0]
    tree = ast.parse(code)
    tree.body = [node for node in tree.body if not (isinstance(node, ast.Expr)
        and isinstance(node.value, ast.Call) and isinstance(node.value.func, ast.Attribute)
        and node.value.func.attr == 'exit')]
    namespace = {'__name__': 'reviewed_failure_receipts'}
    exec(compile(tree, name, 'exec'), namespace)
    return namespace

class FailureReceiptTests(unittest.TestCase):
    def setUp(self):
        self.modules = [load('apply_desktop_dashboard_server_root.sh'), load('go_erp_ci_publish_root.sh')]
        self.paths = {'app/Exceptions/Handler.php', '.env'}
        self.marker = 'DESKTOP_ACTIVATION_STOPPED; STAGE=CACHE; REASON=DESKTOP_PRIVATE_CHECK_FAILED; CLASS=SafeError'
        self.rollback = 'DESKTOP_ACTIVATION_FAILED; FILES_RESTORED; ADDITIVE_TABLES_AND_BUSINESS_OPERATIONS_RETAINED; BACKUP=/home/fasakha/desktop-dashboard-release-backups/server-abcdefgh'
    def test_both_layers_keep_stage_and_rollback_receipts_in_mixed_stderr(self):
        error = ('PRIVATE_TOKEN SQL password\n'+self.marker+'\n'+self.rollback+'\n'+'framework detail\n').encode()
        for module in self.modules:
            self.assertEqual(module['public_failure_lines'](error, self.paths), [self.marker, self.rollback])
    def test_only_approved_owner_paths_and_exact_compiled_targets_are_forwarded(self):
        compiled = 'storage/framework/views/'+'a'*40+'.php'
        good = ['DESKTOP_SOURCE_OWNER_NEEDS_REVIEW: '+name+'; UID=0; MODE=0644'
                for name in ['app/Exceptions/Handler.php', '.env', compiled]]
        bad = ['DESKTOP_SOURCE_OWNER_NEEDS_REVIEW: '+name+'; UID=0; MODE=0644'
               for name in ['storage/framework/views/../private.php', '/root/private', 'storage/private.key']]
        for module in self.modules:
            self.assertEqual(module['public_failure_lines'](('\n'.join(good+bad)).encode(), self.paths), good)
    def test_private_messages_invalid_backups_and_extended_markers_are_dropped(self):
        bad = [self.marker+' TOKEN=SECRET', self.rollback.replace('/home/fasakha/', '/root/'),
               'RuntimeException: SQL password', 'DESKTOP_SOURCE_OWNER_NEEDS_REVIEW: app/Exceptions/Handler.php; UID=0; MODE=0644 SECRET']
        for module in self.modules:
            self.assertEqual(module['public_failure_lines'](('\n'.join(bad)).encode(), self.paths), [])
    def test_duplicate_and_nonascii_noise_cannot_hide_safe_receipts(self):
        error = b'\xff PRIVATE\n'+(self.marker+'\n'+self.marker+'\n').encode()
        for module in self.modules:
            self.assertEqual(module['public_failure_lines'](error, self.paths), [self.marker])
    def test_operations_failure_forwards_the_fixed_report_then_raises(self):
        module = self.modules[1]
        result = types.SimpleNamespace(returncode=1, stdout=b'', stderr=(self.marker+'\n'+self.rollback+'\nPRIVATE_SECRET\n').encode())
        stream = io.StringIO()
        with patch.dict(module, run=lambda *_args, **_kwargs: result, read_regular=lambda *_: b'{"sources":[]}'):
            with contextlib.redirect_stderr(stream), self.assertRaisesRegex(module['Stop'], 'GO_ERP_CI_REVIEWED_ACTIVATION_FAILED'):
                module['activate'](Path('/tmp/reviewed'), 'a'*40, {'source': {}})
        self.assertEqual(stream.getvalue().splitlines(), [self.marker, self.rollback])

if __name__ == '__main__':
    unittest.main()
