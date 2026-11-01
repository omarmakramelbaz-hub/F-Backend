import importlib.util
import json
import os
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest
from unittest.mock import Mock, patch
import uuid

ROOT = Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location('owner_' + name, ROOT / 'deployment/owner_dashboards' / (name + '.py'))
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    return module


worker = load('worker')
installer = load('install_root')


class WorkerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        (self.base / 'control/deny').mkdir(parents=True)
        self.row = dict(id=str(uuid.uuid4()), slug='a' * 16, name='Owner fixture', email='owner@example.invalid', mobile=None,
                        password_hash='$2y$10$' + 'a' * 53, created_by=1, desired_state='active', state='active', revision=2, applied_revision=2)
        self.instance = object.__new__(worker.Worker)
        self.instance.account = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())

    def test_strict_identifiers_and_hex_data_literals(self):
        for value in ['a.b', '`root`', '../users', 'users;DROP DATABASE main']:
            with self.assertRaises(worker.Failure): worker.identifier(value)
        value = "name'); DROP DATABASE main; --"
        self.assertNotIn('DROP DATABASE', worker.literal(value))
        self.assertIn(value.encode().hex(), worker.literal(value))
        self.assertEqual(worker.literal(None), 'NULL')

    def test_untrusted_control_record_never_reaches_filesystem(self):
        for changes in [{'id': '../../etc/passwd'}, {'slug': '../private'}, {'created_by': 635},
                        {'desired_state': 'arbitrary'}, {'password_hash': 'plaintext'}, {'mobile': '123'}]:
            with self.assertRaises(worker.Failure): worker.checked_record(self.row | changes)
        self.assertEqual(worker.checked_record(self.row), self.row)

    def test_future_revision_marker_cannot_be_cleared(self):
        marker = self.base / 'control/deny' / self.row['id']; marker.write_text('3')
        self.instance.registry = lambda where: [self.row]
        with patch.object(worker, 'TENANTS', self.base): self.instance.publish_marker(self.row)
        self.assertEqual(marker.read_text(), '3')

    def test_publication_requires_matching_revision_and_ack(self):
        marker = self.base / 'control/deny' / self.row['id']; marker.write_text('2')
        self.instance.registry = lambda where: [self.row]
        with patch.object(worker, 'TENANTS', self.base):
            self.instance.publish_marker(self.row, False); self.assertEqual(marker.read_text(), '2')
            self.instance.publish_marker(self.row, True); self.assertEqual(marker.read_text(), '')
        marker.write_text('2'); self.instance.registry = lambda where: [self.row | {'revision': 3}]
        with patch.object(worker, 'TENANTS', self.base): self.instance.publish_marker(self.row)
        self.assertEqual(marker.read_text(), '2')

    def test_active_schema_is_never_rebuilt(self):
        self.instance.sql = Mock(side_effect=AssertionError('DDL attempted'))
        self.instance.clone_schema({'ever_active': True, 'schema_ready': False})
        self.instance.sql.assert_not_called()

    def test_cpanel_action_is_fixed_and_errors_are_sanitized(self):
        with self.assertRaises(worker.Failure): self.instance.cpanel('create_account', {})
        with patch.object(worker.os, 'access', return_value=True), \
             patch.object(worker.subprocess, 'run', return_value=Mock(returncode=1, stdout='SECRET_CPANEL_ERROR')):
            with self.assertRaisesRegex(worker.Failure, '^GO_OWNER_CPANEL_SUBDOMAIN_FAILED$'):
                self.instance.cpanel('addsubdomain', {'domain': 'go-' + 'a' * 16})

    def test_cpanel_arguments_are_not_shell_commands(self):
        payload = {'cpanelresult': {'event': {'result': 1}, 'data': [{'result': 1}]}}
        with patch.object(worker.os, 'access', return_value=True), \
             patch.object(worker.subprocess, 'run', return_value=Mock(returncode=0, stdout=json.dumps(payload))) as run:
            self.instance.cpanel('addsubdomain', {'dir': '/public_html/public/dashboards/' + 'a' * 16})
        args = run.call_args[0][0]
        self.assertIsInstance(args, list)
        self.assertIn('dir=%2Fpublic_html%2Fpublic%2Fdashboards%2F' + 'a' * 16, args)
        self.assertNotIn('shell', run.call_args.kwargs)

    def test_host_readiness_never_disables_tls_verification(self):
        self.instance.config = {'url': 'https://example.invalid'}
        proof_root = self.base / 'public'; proof_root.mkdir()
        record = {'host_proof': 'a' * 64, 'autossl_checked_at': 2**60}
        runtime = {'public': str(proof_root), 'origin': 'https://go-' + 'a' * 16 + '.example.invalid'}
        with patch.object(worker.subprocess, 'run', return_value=Mock(returncode=60, stdout='bad certificate')) as run:
            with self.assertRaises(worker.PendingHost): self.instance.host_ready(self.row, record, runtime, self.base / 'record.json')
        command = run.call_args[0][0]
        self.assertNotIn('--insecure', command); self.assertNotIn('-k', command)
        self.assertIn('--proto', command)


class InstallerPlanTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); (self.root / 'app').mkdir()
        self.path = self.root / 'app/Fixture.php'; self.path.write_text('before\nanchor\nafter\n')
        self.manifest = {'format': 1, 'feature': 'independent-owner-dashboards-20261101', 'files': [
            {'path': 'app/Fixture.php', 'kind': 'patch', 'edits': [{'before': 'before\nanchor\nafter\n', 'after': 'before\nnew\nanchor\nafter\n'}]}]}

    def test_plan_is_bounded_idempotent_and_preserves_newlines(self):
        result = installer.plan(self.root, self.manifest)
        self.assertEqual(self.path.read_text(), 'before\nanchor\nafter\n')
        self.path.write_bytes(result[0]['after'])
        self.assertEqual(installer.plan(self.root, self.manifest)[0]['after'], result[0]['after'])
        self.path.write_bytes(b'before\r\nanchor\r\nafter\r\n')
        self.assertEqual(installer.plan(self.root, self.manifest)[0]['after'], b'before\r\nnew\r\nanchor\r\nafter\r\n')

    def test_source_drift_and_symlinks_fail_before_apply(self):
        self.path.write_text('changed\n')
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED'): installer.plan(self.root, self.manifest)
        self.path.unlink(); self.path.symlink_to(self.root / 'outside')
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK'): installer.plan(self.root, self.manifest)

    def test_unreviewed_paths_and_duplicate_entries_fail(self):
        invalid = self.manifest | {'files': [{'path': '../../.env', 'kind': 'new', 'content': 'unsafe'}]}
        with self.assertRaisesRegex(RuntimeError, 'PATH'): installer.plan(self.root, invalid)
        duplicate = self.manifest | {'files': self.manifest['files'] * 2}
        with self.assertRaisesRegex(RuntimeError, 'DUPLICATE'): installer.plan(self.root, duplicate)


if __name__ == '__main__': unittest.main()
