#!/usr/bin/env python3
"""Temporary public files only; no server, DB, PHP boot or worker actions."""
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('confirmation_source_observer', ROOT/'deployment/whatsapp_customer_confirmation_sources.py')
observer = importlib.util.module_from_spec(spec); spec.loader.exec_module(observer)


class SourceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.root = pathlib.Path(self.temp.name)/'app'; self.root.mkdir()
        self.uid = os.geteuid(); self.files = {}
        for path in ('app/Support/Example.php', 'app/Services/Dashboard/Example.php', *observer.TARGETS):
            content = ('<?php /* PRIVATE_SOURCE_CONTENT '+path+' */\n').encode(); self.files[path] = content
            target = self.root/path; target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(content)
        self.runtime = {path: (observer.blob(self.files[path]),) for path in list(self.files)[:2]}
        self.targets = {path: (None, observer.blob(self.files[path])) for path in observer.TARGETS}
        self.profiles = {'BASELINE': {path: None for path in observer.TARGETS},
                         'CURRENT': {path: pair[1] for path, pair in self.targets.items()}}
        self.parsed = self.runtime, self.targets, self.profiles

    def tearDown(self): self.temp.cleanup()

    def inspect(self):
        value = observer.inspect(self.root, self.uid, self.parsed)
        self.assertNotIn('PRIVATE_SOURCE_CONTENT', json.dumps(value))
        for key in ('writes', 'ai_calls', 'message_sends', 'database_queries', 'worker_actions'):
            self.assertEqual(value[key], 0)
        return value

    def test_pinned_manifest_is_parsed_as_literals_without_loading_any_code(self):
        source = (ROOT/observer.MANIFEST).read_bytes()
        self.assertEqual(observer.blob(source), observer.MANIFEST_BLOB)
        runtime, targets, profiles = observer.parse_manifest(source)
        self.assertEqual(len(runtime), 14); self.assertEqual(tuple(targets), observer.TARGETS)
        self.assertEqual(observer.RELEASE, '0f7b9c141f3fb339624887aca76797e50e859ea4')
        self.assertEqual(observer.MANIFEST_BLOB, 'c5f04fded5a6806e70ec677d43067c20af17193e')
        self.assertEqual(set(profiles), {'BASELINE', 'INSTALLED_V1', 'INSTALLED_V2', 'INSTALLED_V3', 'CURRENT'})
        self.assertEqual(profiles['INSTALLED_V3']['app/Services/Dashboard/WhatsAppOrderWorkflow.php'],
                         '63c1e6e7e48c500227e0285707611b268e50b3ad')
        with self.assertRaisesRegex(observer.Blocked, 'MANIFEST_HASH_MISMATCH'):
            observer.parse_manifest(source+b'\n# unknown change\n')
        malicious = b"RUNTIME_PINS = __import__('os').system('false')\nTARGET_PINS = {}\nINSTALLED_SOURCE_PROFILES = {}\n"
        with mock.patch.object(observer, 'MANIFEST_BLOB', observer.blob(malicious)):
            with self.assertRaisesRegex(observer.Blocked, 'MANIFEST_LITERAL_SCOPE_INVALID'):
                observer.parse_manifest(malicious)

    def test_manifest_loader_requires_exact_release_tree_size_and_blob(self):
        source = (ROOT/observer.MANIFEST).read_bytes(); calls = []
        def runner(root, arguments):
            calls.append(arguments)
            if arguments[0] == 'ls-tree':
                return b'100644 blob '+observer.MANIFEST_BLOB.encode()+b'\t'+observer.MANIFEST.encode()+b'\0'
            if arguments[:2] == ['cat-file', '-s']: return str(len(source)).encode()
            if arguments[:2] == ['cat-file', 'blob']: return source
            raise AssertionError(arguments)
        observer.manifest(self.root, runner)
        self.assertEqual(calls[0], ['ls-tree', '-z', observer.RELEASE, observer.MANIFEST])
        with self.assertRaisesRegex(observer.Blocked, 'MANIFEST_TREE_MISMATCH'):
            observer.manifest(self.root, lambda root, arguments: b'wrong tree')

    def test_exact_profile_and_hardlinked_regular_file_are_observed_without_changes(self):
        path = self.root/next(iter(self.runtime)); alias = self.root.parent/'alias.php'; os.link(path, alias)
        before = observer.signature(path.lstat()); value = self.inspect()
        self.assertEqual(value['status'], 'READ_READY'); self.assertEqual(value['runtime']['mismatch_count'], 0)
        self.assertEqual(value['targets']['profile_matches'], ['CURRENT'])
        self.assertEqual(observer.signature(path.lstat()), before); self.assertEqual(alias.read_bytes(), path.read_bytes())

    def test_unknown_runtime_reports_actual_allowed_and_metadata_without_source_text(self):
        path = next(iter(self.runtime)); data = b'<?php /* PRIVATE_SOURCE_CONTENT unknown */'; (self.root/path).write_bytes(data)
        row = self.inspect()['runtime']['differences'][0]
        self.assertEqual(row['path'], path); self.assertEqual(row['actual'], observer.blob(data))
        self.assertEqual(row['allowed'], list(self.runtime[path])); self.assertEqual(row['metadata']['uid'], self.uid)

    def test_missing_file_and_unknown_target_pair_remain_diagnostic_observations(self):
        path = next(iter(self.runtime)); (self.root/path).unlink()
        value = self.inspect(); self.assertEqual(value['runtime']['differences'][0]['state'], 'MISSING')
        self.assertIsNone(value['runtime']['differences'][0]['actual'])
        (self.root/observer.TARGETS[0]).write_bytes(b'<?php /* unreviewed target */')
        self.assertFalse(self.inspect()['targets']['recognized_profile'])

    def test_symlink_file_and_parent_are_never_followed(self):
        path = self.root/next(iter(self.runtime)); path.unlink(); path.symlink_to(self.root.parent/'private-unread-file')
        value = self.inspect(); row = value['runtime']['differences'][0]
        self.assertEqual(row['state'], 'SYMLINK_FILE'); self.assertIsNone(row['actual'])
        path.unlink(); parent = path.parent; parent.rename(parent.with_name('Support-real')); parent.symlink_to(parent.with_name('Support-real'), target_is_directory=True)
        value = self.inspect(); row = value['runtime']['differences'][0]
        self.assertEqual(row['state'], 'SYMLINK_PARENT'); self.assertIsNone(row['actual'])

    def test_size_limit_and_concurrent_change_do_not_report_partial_hash(self):
        path = next(iter(self.runtime))
        with mock.patch.object(observer, 'MAX_SOURCE', 8):
            row, _ = observer.read_source(self.root, path, self.uid)
        self.assertEqual(row['state'], 'SOURCE_TOO_LARGE'); self.assertIsNone(row['actual'])
        original = os.read; changed = [False]
        def reader(descriptor, count):
            result = original(descriptor, count)
            if result and not changed[0]:
                changed[0] = True
                with (self.root/path).open('ab') as stream: stream.write(b' changed')
            return result
        with mock.patch.object(observer.os, 'read', reader):
            row, _ = observer.read_source(self.root, path, self.uid)
        self.assertEqual(row['state'], 'CHANGED_DURING_READ'); self.assertIsNone(row['actual'])

    def test_final_metadata_guard_marks_later_source_change(self):
        original = observer.read_source; count = [0]
        def reader(root, relative, uid):
            value = original(root, relative, uid); count[0] += 1
            if count[0] == len(self.files): (self.root/next(iter(self.runtime))).write_bytes(b'changed after read')
            return value
        with mock.patch.object(observer, 'read_source', reader): value = self.inspect()
        self.assertEqual(value['status'], 'OBSERVATION_CHANGED')
        self.assertFalse(value['source_metadata_unchanged_during_read'])

    def test_main_owner_guard_and_no_arguments_fail_closed_without_git_or_reads(self):
        with mock.patch.object(sys, 'argv', ['sources.py', '--force']), mock.patch.object(observer, 'git') as git:
            with contextlib.redirect_stdout(io.StringIO()) as output: self.assertEqual(observer.main(), 1)
        self.assertEqual(json.loads(output.getvalue())['stage'], 'ARGUMENTS'); git.assert_not_called()
        with mock.patch.object(sys, 'argv', ['sources.py']), mock.patch.object(observer.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=self.uid+1)):
            with contextlib.redirect_stdout(io.StringIO()) as output: self.assertEqual(observer.main(), 1)
        self.assertEqual(json.loads(output.getvalue())['stage'], 'OWNER')


if __name__ == '__main__': unittest.main()
