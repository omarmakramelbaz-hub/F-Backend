#!/usr/bin/env python3
"""Read-only watcher diagnosis boundaries; never accesses a production service."""
import copy
import fcntl
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

REPOSITORY = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('watcher_readonly_subject',
    REPOSITORY/'deployment/diagnose_go_erp_live_watcher_root.py')
subject = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(subject)


@unittest.skipUnless(os.geteuid() == 0, 'Private ownership fixture requires root.')
class WatcherReadOnlyTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-watcher-readonly-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.installed = self.base/'installed'; self.installed.mkdir(mode=0o755)
        for name in subject.PINNED:
            target = self.installed/name
            target.write_bytes((REPOSITORY/'deployment'/name).read_bytes())
            target.chmod(0o644)
        self.work = self.base/'work'; self.work.mkdir(mode=0o700)
        (self.work/'state').mkdir(mode=0o700)
        self.lock = self.work/'watcher.lock'; self.lock.write_bytes(b''); self.lock.chmod(0o600)
        self.live = self.base/'live'; self.live.mkdir(mode=0o755)
        for name in ['composer.json','composer.lock','vendor/composer/installed.json']:
            target = self.live/name; target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(b'{}\n'); target.chmod(0o644)
        self.state = {'format':1,'base_revision':'75c3fe50b3609ef5822904a23d7c4ed0afd07eff',
            'counter':0,'observed':None,'desired':None,'published':None,
            'dependency_guard':None,'token':'SHOULD_NEVER_BE_DISCLOSED',
            'last_remote_sha':None,'sequence_floor':0,'version_floor':None,'published_history':[]}
        self.write_state()

    def write_state(self):
        target = self.work/'state/current.json'
        target.write_text(json.dumps(self.state)); target.chmod(0o600)

    def modules(self):
        result = subject.load_installed(self.installed)
        if isinstance(result, dict):
            return result.get('source') or result['go_erp_live_source.py'], result.get('worker') or result['go_erp_live_updates.py']
        return result

    def snapshot(self):
        return {'metadata':{'inventorySha256':'a'*64,
            'inventory':{'app/Test.php':{'authority':'live','present':True,'sha256':'b'*64,'bytes':7,'mode':'100644'}},
            'packagedFiles':{'app/Test.php':'b'*64},'authoritativeLiveFiles':{'app/Test.php':'b'*64}},
            '_files':{'app/Test.php':b'<?php\n'}}

    def tree_snapshot(self):
        result = {}
        for path in self.base.rglob('*'):
            value = path.lstat()
            result[str(path.relative_to(self.base))] = (value.st_ino, value.st_mode,
                value.st_uid, value.st_gid, value.st_nlink, value.st_size,
                value.st_mtime_ns, None if path.is_dir() else path.read_bytes())
        return result

    def test_actual_installed_helpers_are_pinned_and_loaded_without_worker_construction(self):
        source, worker = self.modules()
        self.assertTrue(callable(source.scan_snapshot)); self.assertTrue(callable(worker.dependency_snapshot))
        self.assertEqual(subject.PINNED['go_erp_live_source.py'], '4ddb4c9a6dd4011b8308075ca330fe165304ae31')
        self.assertEqual(subject.PINNED['go_erp_live_updates.py'], 'a916fd72c20699ca1710ca188a6999db02dfbe07')
        with mock.patch.object(worker, 'Worker', side_effect=AssertionError('Worker constructed')):
            with mock.patch.object(source, 'scan_snapshot', return_value=self.snapshot()) as scan:
                before = self.tree_snapshot()
                report = subject.probe(self.live, self.work, source, worker)
                self.assertTrue(report['ok']); self.assertEqual(report['phase'], 'DONE')
                self.assertEqual(before, self.tree_snapshot())
                scan.assert_called_once()

    def test_changed_helper_bytes_stop_before_execution(self):
        target = self.installed/'go_erp_live_source.py'
        target.write_bytes(target.read_bytes()+b'\nraise RuntimeError("SECRET_UNREVIEWED")\n')
        with self.assertRaises(subject.Stop): subject.load_installed(self.installed)

    def test_helper_symlink_hardlink_and_shared_mode_are_rejected(self):
        target = self.installed/'go_erp_live_source.py'
        saved = self.base/'saved.py'; target.rename(saved); target.symlink_to(saved)
        with self.assertRaises(Exception): subject.load_installed(self.installed)
        target.unlink(); os.link(saved, target)
        with self.assertRaises(Exception): subject.load_installed(self.installed)
        target.unlink(); target.write_bytes(saved.read_bytes()); target.chmod(0o666)
        with self.assertRaises(Exception): subject.load_installed(self.installed)

    def test_existing_lock_is_not_created_and_exclusive_service_lock_is_respected(self):
        self.lock.unlink()
        with self.assertRaises(Exception):
            with subject.existing_lock(self.work): pass
        self.assertFalse(self.lock.exists())
        self.lock.write_bytes(b''); self.lock.chmod(0o600)
        descriptor = os.open(self.lock, os.O_RDONLY)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(subject.Stop):
                with subject.existing_lock(self.work): pass
        finally: os.close(descriptor)

    def test_private_configuration_is_never_opened_and_no_pipeline_work_is_started(self):
        source, worker = self.modules(); real_open = os.open
        def guard(name, *arguments, **keywords):
            if str(name) == '/etc/go-erp/live-updates.json':
                raise AssertionError('Credentials opened')
            return real_open(name, *arguments, **keywords)
        with mock.patch.object(os, 'open', side_effect=guard), \
             mock.patch.object(worker, 'Worker', side_effect=AssertionError('Worker started')), \
             mock.patch.object(worker, 'Git', side_effect=AssertionError('Git pipeline started')), \
             mock.patch.object(source, 'scan_snapshot', return_value=self.snapshot()):
            report = subject.probe(self.live, self.work, source, worker)
        self.assertTrue(report['ok']); self.assertFalse(report['credentials_read'])
        self.assertFalse(report['application_contents_changed']); self.assertFalse(report['database_changed'])

    def test_state_fields_are_allowlisted_and_previous_source_authority_is_preserved(self):
        source, worker = self.modules()
        observed = {'inventory':{'app/PreviouslyLive.php':{'present':True}}}
        self.state['observed'] = observed
        self.state['desired'] = {'commit':'c'*40,'version':'0.4.4-preview.7','status':'prepared',
            'pushed':False,'failures':0,'candidatePath':'/PRIVATE/CANDIDATE','token':'SECRET_DESIRED'}
        self.write_state()
        with mock.patch.object(source, 'scan_snapshot', return_value=self.snapshot()) as scan:
            report = subject.probe(self.live, self.work, source, worker)
        self.assertEqual(scan.call_args.args[3], observed)
        encoded = json.dumps(report)
        for secret in ['SHOULD_NEVER_BE_DISCLOSED','SECRET_DESIRED','/PRIVATE/CANDIDATE']:
            self.assertNotIn(secret, encoded)

    def test_source_reason_is_bounded_and_does_not_include_private_exception_text(self):
        source, worker = self.modules()
        with mock.patch.object(source, 'scan_snapshot', side_effect=source.SourceError('GO_ERP_LIVE_SOURCE_UNCLASSIFIED_ADDITION')):
            report = subject.probe(self.live, self.work, source, worker)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'SOURCE')
        self.assertEqual(report['reason'], 'GO_ERP_LIVE_SOURCE_UNCLASSIFIED_ADDITION')
        with mock.patch.object(source, 'scan_snapshot', side_effect=source.SourceError('SECRET_TOKEN SQL credentials')):
            report = subject.probe(self.live, self.work, source, worker)
        self.assertNotIn('SECRET', json.dumps(report)); self.assertNotIn('SQL', json.dumps(report))

    def test_unexpected_exception_discloses_class_only_and_preserves_files(self):
        source, worker = self.modules(); before = self.tree_snapshot()
        with mock.patch.object(source, 'scan_snapshot', side_effect=TypeError('SECRET_SERVER_PASSWORD')):
            report = subject.probe(self.live, self.work, source, worker)
        self.assertFalse(report['ok']); self.assertEqual(report['exception_class'], 'TypeError')
        self.assertNotIn('SECRET_SERVER_PASSWORD', json.dumps(report))
        self.assertEqual(before, self.tree_snapshot())

    def test_error_path_is_only_a_classified_source_name_and_excludes_private_paths(self):
        source, worker = self.modules()
        for name in ['app/Problem.php', '.env', 'public/uploads/PRIVATE_CUSTOMER.json']:
            code = ('def scan_snapshot(live, seed, uid, previous=None):\n'
                '    name = '+repr(name)+'\n'
                '    raise SourceError("GO_ERP_LIVE_SOURCE_LIVE_FILE_TYPE")\n')
            exec(compile(code, 'diagnostic_fixture_source', 'exec'), source.__dict__)
            report = subject.probe(self.live, self.work, source, worker)
            if name == 'app/Problem.php':
                self.assertEqual(report.get('safe_path'), name)
            else:
                self.assertNotIn(name, json.dumps(report))

    def test_dependency_failure_stops_before_source_scan(self):
        source, worker = self.modules()
        (self.live/'composer.lock').unlink()
        with mock.patch.object(source, 'scan_snapshot', side_effect=AssertionError('Scan must not run')) as scan:
            report = subject.probe(self.live, self.work, source, worker)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'DEPENDENCIES')
        self.assertEqual(report['reason'], 'GO_ERP_LIVE_DEPENDENCY_MANIFEST_MISSING')
        scan.assert_not_called()

    def test_dependency_guard_mismatch_stops_before_source_without_changing_files(self):
        source, worker = self.modules()
        self.state['dependency_guard'] = {'wrong': 'UNAPPROVED_DEPENDENCY'}
        self.write_state(); before = self.tree_snapshot()
        with mock.patch.object(source, 'scan_snapshot', side_effect=AssertionError('Scan must not run')) as scan:
            report = subject.probe(self.live, self.work, source, worker)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'DEPENDENCIES')
        self.assertFalse(report['dependency_guard_matches'])
        self.assertEqual(report['reason'], 'GO_ERP_LIVE_DEPENDENCY_CHANGE_REQUIRES_REVIEWED_SEED')
        scan.assert_not_called(); self.assertEqual(before, self.tree_snapshot())

    def test_private_state_symlink_or_overshared_mode_are_refused(self):
        source, worker = self.modules(); path = self.work/'state/current.json'
        saved = self.work/'state/saved.json'; path.rename(saved); path.symlink_to(saved)
        report = subject.probe(self.live, self.work, source, worker)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'STATE')
        path.unlink(); path.write_bytes(saved.read_bytes()); path.chmod(0o644)
        report = subject.probe(self.live, self.work, source, worker)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'STATE')

    def test_invocation_has_no_arbitrary_path_mutation_or_configuration_switch(self):
        for arguments in [[],['--release','not-sha'],['--release','a'*40,'--repair'],
                ['--config','/etc/go-erp/live-updates.json'],['--release','a'*40,'--root','/tmp/foreign']]:
            with self.assertRaises(subject.Stop): subject.execute(arguments)


if __name__ == '__main__': unittest.main()
