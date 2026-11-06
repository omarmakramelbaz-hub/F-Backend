#!/usr/bin/env python3
"""Portable dependency checks; no server, user account, or PHP install required."""
import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch


loader = importlib.util.spec_from_file_location('invoice_history_deploy', Path(__file__).with_name('apply_root.py'))
deploy = importlib.util.module_from_spec(loader)
loader.loader.exec_module(deploy)


class DependencyTests(unittest.TestCase):
    def test_established_ea82_is_preferred(self):
        ea82 = '/opt/cpanel/ea-php82/root/usr/bin/php'
        ea84 = Path('/opt/cpanel/ea-php84/root/usr/bin/php')
        ea83 = Path('/opt/cpanel/ea-php83/root/usr/bin/php')
        with patch.object(Path, 'glob', return_value=[ea83, ea84]), patch.object(deploy.shutil, 'which', side_effect=lambda name: '/usr/bin/php' if name == 'php' else None):
            candidates = deploy.php_candidates()
        self.assertEqual(candidates[:3], [ea82, str(ea84), str(ea83)])
        self.assertIn('/usr/bin/php', candidates)
        self.assertEqual(len(candidates), len(set(candidates)))

    def test_missing_node_does_not_block_available_php(self):
        with patch.object(deploy, 'php_candidates', return_value=['/ea82/php']), patch.object(deploy, 'usable_php', return_value=True), patch.object(deploy.shutil, 'which', return_value=None):
            self.assertEqual(deploy.resolve_dependencies(), ('/ea82/php', None))

    def test_unusable_preferred_php_falls_back_to_other_ea(self):
        with patch.object(deploy, 'php_candidates', return_value=['/ea82/php', '/ea83/php']), patch.object(deploy, 'usable_php', side_effect=lambda value: value == '/ea83/php'), patch.object(deploy.shutil, 'which', side_effect=lambda name: '/usr/bin/nodejs' if name == 'nodejs' else None):
            self.assertEqual(deploy.resolve_dependencies(), ('/ea83/php', '/usr/bin/nodejs'))

    def test_path_php_is_supported_without_cpanel(self):
        with patch.object(Path, 'glob', return_value=[]), patch.object(deploy.shutil, 'which', side_effect=lambda name: '/custom/bin/php' if name == 'php' else None), patch.object(deploy, 'usable_php', side_effect=lambda value: value == '/custom/bin/php'):
            self.assertEqual(deploy.resolve_dependencies(), ('/custom/bin/php', None))

    def test_missing_php_remains_fatal_even_with_node(self):
        with patch.object(deploy, 'php_candidates', return_value=['/missing/php']), patch.object(deploy, 'usable_php', return_value=False), patch.object(deploy.shutil, 'which', return_value='/usr/bin/node'):
            with self.assertRaisesRegex(RuntimeError, '^INVOICE_HISTORY_PHP_REQUIRED$'):
                deploy.resolve_dependencies()

    def test_syntax_check_without_node_runs_required_php_and_reports_skip(self):
        with patch.object(deploy, 'run') as run:
            status = deploy.syntax_check(Path('/project'), Path('/candidate'), '/php', None)
        commands = [call.args[0] for call in run.call_args_list]
        self.assertEqual(len(commands), len([path for path in deploy.PATHS if path.endswith('.php')]))
        self.assertTrue(all(command[:6] == ['runuser', '-u', 'fasakha', '--', '/php', '-l'] for command in commands))
        self.assertEqual(status, {'php': 'passed', 'javascript': 'skipped_no_node_prevalidated_locally'})

    def test_present_node_runs_additional_js_check(self):
        with patch.object(deploy, 'run') as run:
            status = deploy.syntax_check(Path('/project'), Path('/candidate'), '/php', '/node')
        commands = [call.args[0] for call in run.call_args_list]
        self.assertEqual(len([command for command in commands if command[4] == '/node']), len([path for path in deploy.PATHS if path.endswith('.js')]))
        self.assertEqual(status['javascript'], 'passed')

    def test_present_node_failure_is_not_silently_skipped(self):
        def fail_node(command, project, phase):
            if command[4] == '/node':
                raise RuntimeError('INVOICE_HISTORY_CHECK_FAILED: javascript-syntax')
        with patch.object(deploy, 'run', side_effect=fail_node):
            with self.assertRaisesRegex(RuntimeError, '^INVOICE_HISTORY_CHECK_FAILED: javascript-syntax$'):
                deploy.syntax_check(Path('/project'), Path('/candidate'), '/php', '/node')

    def test_syntax_check_cannot_skip_php(self):
        with patch.object(deploy, 'run') as run:
            with self.assertRaisesRegex(RuntimeError, '^INVOICE_HISTORY_PHP_REQUIRED$'):
                deploy.syntax_check(Path('/project'), Path('/candidate'), None, None)
        run.assert_not_called()

    def test_php_probe_requires_working_cli_as_site_user(self):
        result = subprocess.CompletedProcess([], 0, 'INVOICE_HISTORY_CLI', '')
        with patch.object(deploy.os.path, 'isfile', return_value=True), patch.object(deploy.os, 'access', return_value=True), patch.object(deploy.subprocess, 'run', return_value=result) as run:
            self.assertTrue(deploy.usable_php('/php'))
        self.assertEqual(run.call_args.args[0][:7], ['runuser', '-u', 'fasakha', '--', '/php', '-n', '-r'])

    def test_php_probe_rejects_cgi_or_failed_interpreter(self):
        with patch.object(deploy.os.path, 'isfile', return_value=True), patch.object(deploy.os, 'access', return_value=True), patch.object(deploy.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, '', '')):
            self.assertFalse(deploy.usable_php('/php-cgi'))
        with patch.object(deploy.os.path, 'isfile', return_value=True), patch.object(deploy.os, 'access', return_value=True), patch.object(deploy.subprocess, 'run', side_effect=subprocess.TimeoutExpired('/php', 10)):
            self.assertFalse(deploy.usable_php('/php'))


if __name__ == '__main__':
    unittest.main()
