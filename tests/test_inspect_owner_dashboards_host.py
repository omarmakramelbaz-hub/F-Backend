import importlib.util
import json
from pathlib import Path
import subprocess
import unittest
from unittest.mock import Mock, patch


PATH = Path(__file__).resolve().parents[1] / 'deployment' / 'inspect_owner_dashboards_host.py'
spec = importlib.util.spec_from_file_location('owner_dashboard_audit', PATH)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class HostAuditTests(unittest.TestCase):
    def test_only_read_only_cpanel_calls_are_allowed(self):
        with patch.object(audit.subprocess, 'run') as run:
            with self.assertRaisesRegex(ValueError, 'READ_ONLY_UAPI_REQUIRED'):
                audit.uapi('/usr/local/cpanel/bin/uapi', 'Mysql', 'create_database')
            run.assert_not_called()

    def test_cpanel_errors_are_not_forwarded(self):
        done = Mock(stdout=json.dumps({'result': {'status': 0, 'errors': ['SECRET_CREDENTIAL']}}))
        with patch.object(audit.subprocess, 'run', return_value=done):
            self.assertIsNone(audit.uapi('/usr/local/cpanel/bin/uapi', 'Mysql', 'list_databases'))
        with patch.object(audit.subprocess, 'run', side_effect=subprocess.TimeoutExpired('uapi', 30)):
            self.assertIsNone(audit.uapi('/usr/local/cpanel/bin/uapi', 'Mysql', 'list_databases'))

    def test_host_report_uses_summary_allowlist(self):
        data = [[{'database': 'SECRET_DATABASE', 'password': 'SECRET_PASSWORD'}],
                {'main_domain': 'example.invalid', 'addon_domains': ['SECRET_DOMAIN']}]
        with patch.object(audit, 'uapi', side_effect=data), \
             patch.object(Path, 'read_text', return_value='MAXSQL=100\nSECRET=SECRET_CONFIG\n'), \
             patch.object(audit.shutil, 'disk_usage', return_value=Mock(free=1024**3, total=2*1024**3)):
            report = audit.host_report()
        self.assertEqual(report['cpanel_database_count'], 1)
        self.assertEqual(report['cpanel_database_quota'], '100')
        self.assertEqual(report['main_domain'], 'example.invalid')
        self.assertEqual(report['home_free_mib'], 1024)
        self.assertFalse(report['root_cron_installed'])
        self.assertNotIn('SECRET', json.dumps(report))

    def test_runtime_errors_do_not_leak_configuration(self):
        project = Mock()
        project.is_symlink.return_value = False
        project.resolve.return_value = project
        php = Mock()
        php.is_file.return_value = True
        php.__str__ = Mock(return_value='/php')
        done = Mock(returncode=1, stderr='SQL ERROR: SECRET_PASSWORD SECRET_BINDINGS', stdout='')
        with patch.object(audit, 'PROJECT', project), patch.object(audit, 'PHP', php), \
             patch.object(audit.os, 'geteuid', return_value=0), \
             patch.object(audit.socket, 'gethostname', return_value='srv1836524.example.invalid'), \
             patch.object(audit.sys, 'argv', ['audit', str(PATH.with_name('inspect_owner_dashboards.php')), '--inspect']), \
             patch.object(audit.subprocess, 'run', return_value=done):
            with self.assertRaisesRegex(ValueError, '^GO_OWNER_AUDIT_DATABASE_OR_RUNTIME_CHECK_FAILED$'):
                audit.main()


if __name__ == '__main__':
    unittest.main()
