#!/usr/bin/env python3
"""Isolated publication tests; never use the production root or a real installer."""
import copy
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

MODULE = Path(__file__).resolve().parents[1] / 'deployment/publish_go_erp_update.py'
SPEC = importlib.util.spec_from_file_location('go_erp_update_publication', MODULE)
publisher = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(publisher)


@unittest.skipUnless(os.geteuid() == 0, 'The publication ownership contract requires isolated root fixtures.')
class PublicationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-update-publication-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'project'
        for name in ('app', 'desktop', 'storage/app'):
            (self.root / name).mkdir(parents=True, exist_ok=True)
        (self.root / 'app/example.php').write_text('<?php echo 1;\n')
        (self.root / 'desktop/source-contract.json').write_text(json.dumps({
            'format': 1, 'framework': '8.83.29', 'supportedRuntimeFrameworks': ['8.83.29', '12.70.0']}))
        (self.root / 'composer.lock').write_text(json.dumps({'packages': [
            {'name': 'laravel/framework', 'version': 'v8.83.29'}]}))
        self.installer = Path(self.temporary.name) / 'reviewed.exe'
        self.installer.write_bytes(b'MZ' + b'fixture-executable-bytes' * 25)
        self.source = publisher.source_snapshot(self.root)['fingerprint']
        self.receipt = {
            'format': 1, 'kind': 'go-erp-desktop-release', 'version': '0.4.5-preview.1',
            'sequence': 1, 'source_revision': 'a' * 40, 'source': self.source, 'gui': {},
            'installer': {'sha256': hashlib.sha256(self.installer.read_bytes()).hexdigest(), 'bytes': self.installer.stat().st_size},
            'acceptance': {'windows_installer': True, 'runtime': True, 'connected': True, 'source_revision': 'a' * 40}}
        self.directory = self.root / 'storage/app/desktop-updates'

    def publish(self, receipt=None, **kwargs):
        return publisher.publish(self.root, self.installer, receipt or self.receipt, **kwargs)

    def assertRejected(self, operation, reason):
        with self.assertRaisesRegex(publisher.PublicationError, reason):
            operation()

    def test_exact_bytes_published_and_idempotent(self):
        before = publisher.source_snapshot(self.root)
        result = self.publish()
        release = publisher.read_json(self.directory / 'current.json', root_owned=True)
        artifact = self.directory / (self.receipt['installer']['sha256'] + '.exe')
        self.assertTrue(result['published'])
        self.assertEqual(release, result['release'])
        self.assertEqual(artifact.read_bytes(), self.installer.read_bytes())
        self.assertEqual(self.directory.stat().st_mode & 0o777, 0o755)
        for path in (artifact, self.directory / 'current.json'):
            self.assertEqual(path.stat().st_uid, 0)
            self.assertEqual(path.stat().st_mode & 0o777, 0o644)
            self.assertEqual(path.stat().st_nlink, 1)
        manifest_identity = publisher.identity((self.directory / 'current.json').stat())
        self.assertTrue(self.publish()['idempotent'])
        self.assertEqual(manifest_identity, publisher.identity((self.directory / 'current.json').stat()))
        self.assertEqual(before, publisher.source_snapshot(self.root))

    def test_higher_sequence_keeps_prior_artifact(self):
        old = self.publish()['release']
        self.installer.write_bytes(b'MZsecond-accepted-installer')
        updated = copy.deepcopy(self.receipt)
        updated.update(version='0.4.5-preview.2', sequence=2)
        updated['installer'] = {'sha256': hashlib.sha256(self.installer.read_bytes()).hexdigest(), 'bytes': self.installer.stat().st_size}
        self.assertTrue(self.publish(updated)['published'])
        self.assertTrue((self.directory / (old['installer']['sha256'] + '.exe')).exists())
        self.assertEqual(len(list(self.directory.glob('*.exe'))), 2)

    def test_downgrade_and_same_sequence_divergence_rejected(self):
        self.publish()
        for sequence in (0, 1):
            receipt = copy.deepcopy(self.receipt)
            receipt.update(sequence=sequence, version='0.4.5-preview.2')
            self.assertRejected(lambda: self.publish(receipt), 'SEQUENCE')

    def test_higher_sequence_cannot_reuse_or_downgrade_version(self):
        self.publish()
        for version in ('0.4.4', '0.4.5-preview.0', '0.4.5-preview.1', '0.4.5-preview.1+different-build'):
            receipt = copy.deepcopy(self.receipt)
            receipt.update(sequence=2, version=version)
            self.assertRejected(lambda: self.publish(receipt), 'VERSION_ROLLBACK_OR_REUSE')
        self.assertEqual(publisher.read_json(self.directory / 'current.json')['version'], '0.4.5-preview.1')

    def test_prerelease_numeric_precedence_and_release_promotion(self):
        self.receipt['version'] = '0.4.5-preview.9'
        self.publish()
        updated = copy.deepcopy(self.receipt)
        updated.update(sequence=2, version='0.4.5-preview.10')
        self.assertTrue(self.publish(updated)['published'])
        updated.update(sequence=3, version='0.4.5')
        self.assertTrue(self.publish(updated)['published'])
        self.assertEqual(publisher.compare_versions('0.4.5-preview.10', '0.4.5-preview.9'), 1)
        self.assertEqual(publisher.compare_versions('0.4.5-alpha.1', '0.4.5-alpha.beta'), -1)
        self.assertEqual(publisher.compare_versions('0.4.5-alpha', '0.4.5-alpha.1'), -1)
        self.assertEqual(publisher.compare_versions('0.4.5+build.1', '0.4.5+build.2'), 0)

    def test_semver_rejects_leading_zeros_and_unsafe_numeric_parts(self):
        for version in ('0.04.5', '0.4.5-preview.01', '9007199254740992.0.0',
                        '0.4.5-preview.9007199254740992', '0.4.5-preview..1', '0.4.5+'):
            receipt = copy.deepcopy(self.receipt)
            receipt['version'] = version
            self.assertRejected(lambda: self.publish(receipt), 'VERSION_INVALID')

    def test_incomplete_or_other_revision_acceptance_rejected(self):
        for key in ('windows_installer', 'runtime', 'connected', 'source_revision'):
            receipt = copy.deepcopy(self.receipt)
            receipt['acceptance'][key] = False if key != 'source_revision' else 'b' * 40
            self.assertRejected(lambda: self.publish(receipt), 'ACCEPTANCE_INCOMPLETE')
        self.assertFalse(self.directory.exists())

    def test_unknown_fields_and_boolean_integers_rejected(self):
        for target, key, value in (
                ('receipt', 'extra', True), ('receipt', 'sequence', True),
                ('source', 'files', True), ('installer', 'url', 'https://foreign.invalid/payload.exe')):
            receipt = copy.deepcopy(self.receipt)
            (receipt if target == 'receipt' else receipt[target])[key] = value
            self.assertRejected(lambda: self.publish(receipt), 'INVALID|FIELDS')

    def test_current_server_source_must_match(self):
        (self.root / 'app/example.php').write_text('<?php echo 2;\n')
        self.assertRejected(self.publish, 'SERVER_SOURCE_MISMATCH')
        self.assertFalse(self.directory.exists())

    def add_gui(self):
        directory = self.root / 'public/js'
        directory.mkdir(parents=True, exist_ok=True)
        gui = directory / 'dashboard-example.js'
        gui.write_bytes(b'console.log("approved");\r\n')
        self.receipt['gui'] = publisher.snapshot_gui(publisher.source_snapshot(self.root))
        return gui

    def test_gui_map_published_for_matching_packaged_application(self):
        gui = self.add_gui()
        self.assertEqual(self.source, publisher.source_snapshot(self.root)['fingerprint'])
        release = self.publish()['release']
        self.assertEqual(release['gui'], {'public/js/dashboard-example.js':
                         hashlib.sha256(gui.read_bytes().replace(b'\r\n', b'\n')).hexdigest()})
        self.assertEqual(publisher.read_json(self.directory / 'current.json')['gui'], release['gui'])

    def test_added_changed_or_removed_gui_refuses_publication_with_same_core(self):
        gui = self.add_gui()
        self.publish()
        previous = (self.directory / 'current.json').read_bytes()
        for action in ('changed', 'deleted', 'added'):
            if action == 'changed':
                gui.write_bytes(b'console.log("new");\n')
            elif action == 'deleted':
                gui.unlink()
            else:
                gui.write_bytes(b'console.log("approved");\r\n')
                (gui.parent / 'dashboard-new.js').write_bytes(b'new-release-only();\n')
            self.assertEqual(self.source, publisher.source_snapshot(self.root)['fingerprint'])
            self.assertRejected(self.publish, 'SERVER_GUI_MISMATCH')
            self.assertEqual(previous, (self.directory / 'current.json').read_bytes())

    def test_undeclared_gui_refuses_first_publication(self):
        self.add_gui()
        self.receipt['gui'] = {}
        self.assertRejected(self.publish, 'SERVER_GUI_MISMATCH')
        self.assertFalse(self.directory.exists())

    def test_gui_change_during_copy_cannot_commit_manifest(self):
        gui = self.add_gui()
        calls = 0
        def changing(root):
            nonlocal calls
            calls += 1
            if calls == 2:
                gui.write_bytes(b'console.log("new");\n')
            return publisher.source_snapshot(root)
        self.assertRejected(lambda: self.publish(snapshot=changing), 'SERVER_SOURCE_CHANGED')
        self.assertFalse((self.directory / 'current.json').exists())

    def test_gui_scope_and_hashes_are_strict(self):
        for gui in ({'public/js/dashboard-ok.js': 'uppercase'},
                    {'public/js/not-dashboard.js': 'a' * 64},
                    {'public/js/dashboard-child/nested.js': 'a' * 64},
                    {'public/css/dashboard-wrong.js': 'a' * 64},
                    {'public/js/dashboard-case.js': 'a' * 64, 'public/js/dashboard-CASE.js': 'b' * 64},
                    {'public/js/dashboard-ok.js': True}, []):
            receipt = copy.deepcopy(self.receipt)
            receipt['gui'] = gui
            self.assertRejected(lambda: self.publish(receipt), 'GUI_INVALID')
        receipt = copy.deepcopy(self.receipt)
        del receipt['gui']
        self.assertRejected(lambda: self.publish(receipt), 'RECEIPT_INVALID')

    def test_gui_hardlinks_and_excessive_count_rejected(self):
        gui = self.add_gui()
        os.link(gui, gui.parent / 'unrelated-link.js')
        self.assertRejected(self.publish, 'SERVER_GUI_INVALID')
        receipt = copy.deepcopy(self.receipt)
        receipt['gui'] = {'public/js/dashboard-{}.js'.format(i): 'a' * 64 for i in range(2001)}
        self.assertRejected(lambda: self.publish(receipt), 'GUI_INVALID')

    def test_internal_gui_json_bound_and_scanner_policy_match(self):
        receipt = copy.deepcopy(self.receipt)
        receipt['gui'] = {'public/js/dashboard-{}.js'.format(i): 'a' * 64 for i in range(2000)}
        receipt['gui']['public/css/dashboard-.css'] = receipt['gui'].pop('public/js/dashboard-1999.js')
        path = Path(self.temporary.name) / 'large-reviewed-receipt.json'
        path.write_text(json.dumps(receipt))
        self.assertGreater(path.stat().st_size, 16384)
        self.assertLess(path.stat().st_size, publisher.MAX_JSON)
        self.assertEqual(publisher.validate_receipt(publisher.read_json(path))['gui'], receipt['gui'])

    def test_source_change_during_publication_keeps_previous_manifest(self):
        self.publish()
        manifest = (self.directory / 'current.json').read_bytes()
        changed = copy.deepcopy(self.receipt)
        changed.update(sequence=2, version='0.4.5-preview.2')
        calls = 0
        def changing(root):
            nonlocal calls
            calls += 1
            if calls == 2:
                (root / 'app/example.php').write_text('<?php echo 3;\n')
            return publisher.source_snapshot(root)
        self.assertRejected(lambda: self.publish(changed, snapshot=changing), 'SERVER_SOURCE_CHANGED')
        self.assertEqual((self.directory / 'current.json').read_bytes(), manifest)

    def test_malformed_installer_and_tampered_hash_rejected(self):
        for data, reason in ((b'not-a-Windows-file', 'NOT_WINDOWS_EXE'),
                             (b'MZ' + b'x' * (self.installer.stat().st_size - 2), 'HASH_MISMATCH')):
            self.installer.write_bytes(data)
            receipt = copy.deepcopy(self.receipt)
            receipt['installer']['bytes'] = len(data)
            self.assertRejected(lambda: self.publish(receipt), reason)
            self.assertFalse((self.directory / 'current.json').exists())
            self.assertEqual(list(self.directory.glob('*.tmp')), [])

    def test_existing_hash_addressed_artifact_cannot_be_replaced(self):
        self.publish()
        artifact = self.directory / (self.receipt['installer']['sha256'] + '.exe')
        artifact.write_bytes(b'MZ' + b'tampered')
        self.assertRejected(self.publish, 'SIZE_MISMATCH')
        self.assertEqual(artifact.read_bytes(), b'MZtampered')

    def test_publication_busy_refuses_second_writer(self):
        self.directory.mkdir(mode=0o755)
        fd = os.open(str(self.directory), os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, fd)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.assertRejected(self.publish, 'PUBLICATION_BUSY')
        self.assertEqual(list(self.directory.iterdir()), [])

    def test_symlink_and_writable_publication_directory_rejected(self):
        external = Path(self.temporary.name) / 'external'
        external.mkdir()
        self.directory.symlink_to(external, target_is_directory=True)
        self.assertRejected(self.publish, 'OWNERSHIP_UNSAFE')
        self.assertEqual(list(external.iterdir()), [])
        self.directory.unlink()
        self.directory.mkdir(mode=0o777)
        self.directory.chmod(0o777)
        self.assertRejected(self.publish, 'OWNERSHIP_UNSAFE')

    def test_input_and_manifest_links_rejected(self):
        linked = Path(self.temporary.name) / 'linked.exe'
        linked.symlink_to(self.installer)
        self.assertRejected(lambda: publisher.publish(self.root, linked, self.receipt), 'FILE_UNSAFE')
        self.publish()
        current = self.directory / 'current.json'
        saved = Path(self.temporary.name) / 'current-saved.json'
        current.rename(saved)
        current.symlink_to(saved)
        self.assertRejected(self.publish, 'FILE_UNSAFE')

    def test_hardlinks_rejected(self):
        link = Path(self.temporary.name) / 'duplicate.exe'
        os.link(self.installer, link)
        self.assertRejected(self.publish, 'FILE_UNSAFE')

    def test_duplicate_json_keys_rejected(self):
        path = Path(self.temporary.name) / 'duplicate.json'
        path.write_text('{"sequence":1,"sequence":2}')
        self.assertRejected(lambda: publisher.read_json(path), 'DUPLICATE_JSON_FIELD')

    def test_credentials_and_caches_not_selected(self):
        before = publisher.source_snapshot(self.root)
        (self.root / 'app/.env').symlink_to('/does-not-exist-private')
        (self.root / 'bootstrap/cache').mkdir(parents=True)
        (self.root / 'bootstrap/cache/private.php').symlink_to('/does-not-exist-private')
        self.assertEqual(before, publisher.source_snapshot(self.root))
        self.assertTrue(self.publish()['published'])

    def test_framework_contract_mismatch_rejected(self):
        (self.root / 'composer.lock').write_text(json.dumps({'packages': [
            {'name': 'laravel/framework', 'version': 'v99.0.0'}]}))
        self.assertRejected(self.publish, 'FRAMEWORK_CONTRACT_INVALID')

    def test_cli_has_no_project_override_and_refuses_wrong_host(self):
        with self.assertRaises(SystemExit):
            publisher.main(['--root', str(self.root), '--installer', str(self.installer), '--receipt', 'unused'])
        with mock.patch.object(publisher.socket, 'gethostname', return_value='somewhere-else'):
            self.assertRejected(lambda: publisher.main(['--installer', str(self.installer), '--receipt', 'unused']), 'ROOT_AND_HOST_REQUIRED')


if __name__ == '__main__':
    unittest.main()
