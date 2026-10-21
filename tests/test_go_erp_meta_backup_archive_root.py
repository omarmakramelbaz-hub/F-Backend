#!/usr/bin/env python3
"""Bounded archival of known live-source backup leftovers, never production."""
import hashlib
import fcntl
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

REPOSITORY = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('meta_backup_archive_subject',
    REPOSITORY/'deployment/archive_go_erp_meta_backups_root.py')
subject = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(subject)


class VirtualOwnership:
    """Explicit UID0-only local adapter; CI requires real ownership operations."""
    def __init__(self):
        self.values = {}; self.stat, self.lstat, self.fstat = os.stat, os.lstat, os.fstat
    def view(self, value):
        pair = self.values.get((value.st_dev, value.st_ino))
        if pair is None: return value
        class View:
            st_uid, st_gid = pair
            def __getattr__(self, name): return getattr(value, name)
            def __getitem__(self, index): return pair[index-4] if index in (4,5) else value[index]
        return View()
    def chown(self, path, uid, gid, **keywords):
        value = self.stat(path, **keywords); self.values[(value.st_dev,value.st_ino)] = (uid,gid)
    def patches(self):
        return [mock.patch.object(os,'stat',lambda *a,**k:self.view(self.stat(*a,**k))),
            mock.patch.object(os,'lstat',lambda *a,**k:self.view(self.lstat(*a,**k))),
            mock.patch.object(os,'fstat',lambda *a,**k:self.view(self.fstat(*a,**k))),
            mock.patch.object(os,'chown',self.chown)]


def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()


@unittest.skipUnless(os.geteuid()==0, 'Private archive fixture requires root.')
class MetaBackupArchiveTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-meta-backup-archive-')
        self.addCleanup(self.temporary.cleanup); self.base = Path(self.temporary.name)
        mapped = Path('/proc/self/uid_map').read_text().split()
        if len(mapped)==3 and mapped[-1]=='1':
            if os.environ.get('GO_ERP_TEST_REQUIRE_REAL_CHOWN')=='1':
                self.fail('CI requires real ownership; local namespace maps UID0 only')
            adapter=VirtualOwnership()
            for patch in adapter.patches(): patch.start(); self.addCleanup(patch.stop)
        self.root=self.base/'app'; self.root.mkdir(mode=0o750)
        self.backups=self.base/'backups'; self.backups.mkdir(mode=0o700)
        self.audit=self.base/'audit'
        self.live_contents={'app/Console/Kernel.php':b'<?php /* CURRENT_CONSOLE */\n',
            'app/Http/Kernel.php':b'<?php /* CURRENT_HTTP */\n'}
        self.payloads={}; self.original={}
        self.suffix='.bak-meta-20261020-044957'
        self.manifest={'sources':[],'protected':[]}
        for index,(name,data) in enumerate(self.live_contents.items()):
            live=self.root/name; live.parent.mkdir(parents=True,exist_ok=True)
            live.write_bytes(data); live.chmod(0o644)
            backup=self.root/(name+self.suffix)
            private=b'PRIVATE_HISTORICAL_BACKUP_'+str(index).encode()+b'\n'
            backup.write_bytes(private); backup.chmod(0o644)
            self.payloads[name+self.suffix]=private
            self.manifest['sources'].append({'path':name,'before_blob':'a'*40,
                'after_blob':blob(data),'phase':2})
        for folder,dirs,files in os.walk(self.root):
            os.chown(folder,1001,1003)
            for name in files: os.chown(Path(folder)/name,1001,1003)
        os.chown(self.backups,1001,1003)
        self.original={name:(self.root/name).stat() for name in self.payloads}
        self.canonical={name:(self.root/name).stat() for name in self.live_contents}
        self.seed=self.base/'seed'; self.seed.mkdir(mode=0o700)
        for name,data in self.live_contents.items():
            path=self.seed/name; path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(data); path.chmod(0o644)
        def git(*args):
            return subprocess.check_output(['git','-c','user.name=Test','-c','user.email=test@localhost',
                '-c','commit.gpgSign=false','-C',str(self.seed),*args],stderr=subprocess.DEVNULL)
        git('init','--quiet'); git('remote','add','origin','https://github.com/omarmakramelbaz-hub/F-Backend.git')
        git('add','.'); git('commit','--quiet','-m','Root-owned test seed')
        spec=importlib.util.spec_from_file_location('actual_meta_archive_source',REPOSITORY/'deployment/go_erp_live_source.py')
        self.source=importlib.util.module_from_spec(spec); spec.loader.exec_module(self.source)
        spec=importlib.util.spec_from_file_location('actual_meta_archive_ops',REPOSITORY/'deployment/finalize_go_erp_publication_links_root.py')
        module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        self.ops=module.__dict__

    def run_archive(self,requested=False,**options):
        return subject.run_archive(self.root,self.backups,self.manifest,self.source,self.seed,
            requested=requested,audit_root=self.audit,ops=self.ops,**options)

    def diagnostic_modules(self):
        modules=[]
        for name in ['diagnose_go_erp_live_watcher_root.py','go_erp_live_updates.py']:
            spec=importlib.util.spec_from_file_location('archive_diagnostic_test_'+name[:-3],REPOSITORY/'deployment'/name)
            module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module); modules.append(module)
        return modules[0].__dict__,modules[1]

    def assert_canonical_unchanged(self):
        for name,data in self.live_contents.items():
            path=self.root/name; after=path.stat(); before=self.canonical[name]
            self.assertEqual(path.read_bytes(),data)
            for field in ['st_dev','st_ino','st_mode','st_uid','st_gid','st_size','st_mtime_ns','st_nlink']:
                self.assertEqual(getattr(after,field),getattr(before,field),field)

    def tree(self):
        result={}
        for path in self.base.rglob('*'):
            value=path.lstat()
            result[str(path.relative_to(self.base))]=(value.st_ino,value.st_mode,value.st_uid,
                value.st_gid,value.st_nlink,value.st_size,value.st_mtime_ns,
                None if path.is_dir() else path.read_bytes())
        return result

    def captured(self):
        return [path for path in self.backups.rglob('*') if path.is_file()
            and path.read_bytes() in self.payloads.values()]

    def copies(self):
        return [path for path in self.audit.rglob('*') if path.is_file()
            and path.read_bytes() in self.payloads.values()] if self.audit.exists() else []

    def test_readonly_plan_verifies_actual_postarchive_scanner_without_moving_files(self):
        before=self.tree(); excluded=self.source._excluded
        with self.assertRaises(self.source.SourceError):
            self.source.scan_snapshot(self.root,self.seed,1001)
        report=self.run_archive()
        self.assertEqual(report['pending'],2); self.assertEqual(report['captured'],0)
        self.assertEqual(before,self.tree()); self.assertIs(self.source._excluded,excluded)
        self.assertFalse(self.audit.exists()); self.assert_canonical_unchanged()

    def test_archives_original_inodes_and_independent_complete_private_copies(self):
        report=self.run_archive(True)
        self.assertEqual(report['captured'],2); self.assertEqual(report['pending'],0)
        self.assert_canonical_unchanged()
        originals=self.captured(); copies=self.copies()
        self.assertEqual(sorted(p.read_bytes() for p in originals),sorted(self.payloads.values()))
        self.assertEqual(sorted(p.read_bytes() for p in copies),sorted(self.payloads.values()))
        for name,before in self.original.items():
            self.assertFalse((self.root/name).exists())
            matches=[p for p in originals if p.read_bytes()==self.payloads[name]]
            self.assertEqual(len(matches),1)
            self.assertEqual(matches[0].stat().st_ino,before.st_ino)
        for path in copies:
            self.assertEqual(path.stat().st_uid,0); self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600)
            self.assertEqual(path.stat().st_nlink,1)
        self.source.scan_snapshot(self.root,self.seed,1001)

    def test_all_plan_metadata_checked_before_any_move(self):
        name=list(self.payloads)[1]; os.link(self.root/name,self.base/'extra-hardlink')
        before=self.tree()
        with self.assertRaises(Exception): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_symlink_backup_is_never_followed_or_moved(self):
        name=list(self.payloads)[0]; path=self.root/name; path.unlink()
        path.symlink_to(self.root/list(self.live_contents)[0]); before=self.tree()
        with self.assertRaises(Exception): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assertTrue(path.is_symlink()); self.assert_canonical_unchanged()

    def test_world_writable_candidate_refuses_whole_plan(self):
        (self.root/list(self.payloads)[1]).chmod(0o666); before=self.tree()
        with self.assertRaises(Exception): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_unknown_backup_family_is_not_hidden_or_archived(self):
        path=self.root/'app/Console/Kernel.php.bak-other-date'
        path.write_bytes(b'UNRELATED_PRIVATE_BACKUP'); path.chmod(0o644)
        os.chown(path,1001,1003); before=self.tree()
        with self.assertRaises(Exception): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_exact_suffix_on_unknown_canonical_stem_remains_unclassified(self):
        path=self.root/('app/Unreviewed.php'+self.suffix)
        path.write_bytes(b'UNKNOWN_STEM_PRIVATE'); path.chmod(0o644)
        os.chown(path,1001,1003); before=self.tree()
        with self.assertRaises(Exception): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_other_source_failure_stops_before_creating_copies_or_moving_any_file(self):
        before=self.tree(); excluded=self.source._excluded
        with mock.patch.object(self.source,'scan_snapshot',side_effect=self.source.SourceError('GO_ERP_LIVE_SOURCE_WORLD_WRITABLE')):
            with self.assertRaises(Exception): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assertIs(self.source._excluded,excluded)
        self.assert_canonical_unchanged()

    def test_complete_after_source_guard_refusal_precedes_all_mutations(self):
        before=self.tree()
        def refuse(): raise RuntimeError('EXACT_AFTER_PROOF_REFUSED')
        with self.assertRaises(Exception): self.run_archive(True,guard=refuse)
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())
        self.assert_canonical_unchanged()

    def test_every_independent_copy_is_durable_before_first_capture(self):
        calls=[]
        def check(row):
            copies=self.copies()
            self.assertEqual(sorted(p.read_bytes() for p in copies),sorted(self.payloads.values()))
            calls.append(row['path'])
        self.run_archive(True,before_capture=check)
        self.assertEqual(len(calls),2); self.assert_canonical_unchanged()

    def test_atomic_rename_race_retains_foreign_object_without_unlinking_anything(self):
        rename=os.rename; replaced=[]
        def race(source,destination,*args,**kwargs):
            if not replaced and str(source).endswith(self.suffix):
                name=list(self.payloads)[0]; old=self.root/name
                rename(old,self.base/'retained-original')
                old.write_bytes(b'FOREIGN_PRIVATE_SENTINEL'); old.chmod(0o644)
                os.chown(old,1001,1003); replaced.append(True)
            return rename(source,destination,*args,**kwargs)
        with mock.patch.object(os,'rename',side_effect=race), \
             mock.patch.object(os,'unlink',side_effect=AssertionError('Nothing may be unlinked')):
            with self.assertRaises(Exception): self.run_archive(True)
        self.assertEqual(replaced,[True])
        self.assertTrue(any(p.is_file() and p.read_bytes()==b'FOREIGN_PRIVATE_SENTINEL' for p in self.backups.rglob('*')))
        self.assertEqual(sorted(p.read_bytes() for p in self.copies()),sorted(self.payloads.values()))
        self.assert_canonical_unchanged()
        before_retry=self.tree()
        with self.assertRaises(subject.Stop): self.run_archive(True)
        self.assertEqual(before_retry,self.tree())
        self.assertTrue(any(p.is_file() and p.read_bytes()==b'FOREIGN_PRIVATE_SENTINEL' for p in self.backups.rglob('*')))

    def test_partial_capture_retry_preserves_originals_and_finishes_remaining_backup(self):
        count=[]
        def fail_second(row):
            count.append(row['path'])
            if len(count)==2: raise RuntimeError('INJECTED_BEFORE_SECOND_CAPTURE')
        with self.assertRaises(Exception): self.run_archive(True,before_capture=fail_second)
        self.assertEqual(sum((self.root/name).exists() for name in self.payloads),1)
        self.assertEqual(len(self.captured()),1)
        report=self.run_archive(True)
        self.assertEqual(report['captured'],1); self.assertEqual(report['pending'],0)
        self.assertEqual(len(self.captured()),2); self.assert_canonical_unchanged()
        before_third=self.tree()
        third=self.run_archive(True)
        self.assertEqual(third['captured'],0); self.assertEqual(third['pending'],0)
        self.assertEqual(before_third,self.tree()); self.assert_canonical_unchanged()

    def test_idempotent_after_all_backups_are_archived(self):
        self.run_archive(True); before=self.tree()
        report=self.run_archive(True)
        self.assertEqual(report['captured'],0); self.assertEqual(report['pending'],0)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_existing_watcher_and_install_locks_are_exclusive_and_never_created(self):
        work=self.base/'work'; work.mkdir(mode=0o700)
        cases=[(work,'watcher.lock',(0,0)),(self.backups,'server-install.lock',(1001,1003))]
        for parent,name,owner in cases:
            path=parent/name
            with self.assertRaises(Exception):
                with subject.locked(parent,name,owner,self.ops): pass
            self.assertFalse(path.exists())
            path.write_bytes(b''); path.chmod(0o600); os.chown(path,*owner)
            descriptor=os.open(path,os.O_RDONLY)
            try:
                fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB)
                with self.assertRaises(subject.Stop):
                    with subject.locked(parent,name,owner,self.ops): pass
            finally: os.close(descriptor)
            with subject.locked(parent,name,owner,self.ops) as guard: guard()

    def test_actual_foreign_directory_guard_reports_plan_and_numeric_metadata(self):
        directory=self.root/'app/Console'; os.chown(directory,0,0); directory.chmod(0o775)
        before=self.tree(); diagnostic,worker=self.diagnostic_modules()
        with self.assertRaises(self.ops['Stop']) as caught: self.run_archive(True)
        report=subject.diagnostic_failure(caught.exception,diagnostic,self.source,worker,self.ops)
        self.assertEqual(report['phase'],'PLAN')
        self.assertEqual(report['reason'],'GO_ERP_META_BACKUP_GUARD_DIRECTORY_UNSAFE')
        self.assertEqual(report['safe_path'],'app/Console')
        self.assertEqual(report['directory_metadata'],{'uid':0,'gid':0,'mode':'0775'})
        self.assertNotIn(str(self.base),json.dumps(report))
        self.assertNotIn('PRIVATE_HISTORICAL_BACKUP',json.dumps(report))
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists()); self.assert_canonical_unchanged()

    def test_source_error_keeps_projected_phase_and_fixed_reason_without_mutation(self):
        diagnostic,worker=self.diagnostic_modules(); before=self.tree()
        with mock.patch.object(self.source,'scan_snapshot',side_effect=self.source.SourceError('GO_ERP_LIVE_SOURCE_WORLD_WRITABLE')):
            with self.assertRaises(self.source.SourceError) as caught: self.run_archive(True)
        report=subject.diagnostic_failure(caught.exception,diagnostic,self.source,worker,self.ops)
        self.assertEqual(report['phase'],'PROJECTED_SOURCE')
        self.assertEqual(report['reason'],'GO_ERP_LIVE_SOURCE_WORLD_WRITABLE')
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())

    def test_same_named_stop_or_unlisted_message_never_bypasses_output_allowlist(self):
        diagnostic,worker=self.diagnostic_modules()
        foreign=type('Stop',(Exception,),{})
        for error in [foreign('DIRECTORY_UNSAFE'),self.ops['Stop']('SECRET_SERVER_PASSWORD'),TypeError('SECRET_SERVER_PASSWORD')]:
            report=subject.diagnostic_failure(error,diagnostic,self.source,worker,self.ops)
            self.assertEqual(report['reason'],'PRIVATE_FAILURE')
            self.assertNotIn('SECRET_SERVER_PASSWORD',json.dumps(report))

    def test_existing_own_stop_reason_is_preserved_by_main(self):
        output=io.StringIO()
        with mock.patch.object(subject,'execute',side_effect=subject.Stop('GO_ERP_META_BACKUP_EXACT_AFTER_SOURCE_REQUIRED')):
            status=subject.main(['--release','a'*40],out=output)
        report=json.loads(output.getvalue())
        self.assertEqual(status,1); self.assertEqual(report['reason'],'GO_ERP_META_BACKUP_EXACT_AFTER_SOURCE_REQUIRED')
        self.assertFalse(report['application_contents_changed']); self.assertFalse(report['database_changed'])

    def test_root_group_writable_parent_without_a_backup_does_not_block_other_archive(self):
        stem='app/Http/Kernel.php'; path=self.root/(stem+self.suffix)
        retained=self.base/'retained-http-backup'; path.rename(retained)
        self.payloads.pop(stem+self.suffix)
        directory=self.root/'app/Http'; os.chown(directory,0,0); directory.chmod(0o775)
        report=self.run_archive(True)
        self.assertTrue(report['ok']); self.assertEqual(report['captured'],1)
        self.assertEqual(retained.read_bytes(),b'PRIVATE_HISTORICAL_BACKUP_1\n')
        self.assert_canonical_unchanged()
        self.source.scan_snapshot(self.root,self.seed,1001)

    def test_world_writable_parent_without_backup_still_blocks_before_any_archive(self):
        stem='app/Http/Kernel.php'; path=self.root/(stem+self.suffix)
        path.rename(self.base/'retained-http-backup')
        directory=self.root/'app/Http'; os.chown(directory,0,0); directory.chmod(0o777)
        before=self.tree(); diagnostic,worker=self.diagnostic_modules()
        with self.assertRaises(self.source.SourceError) as caught: self.run_archive(True)
        report=subject.diagnostic_failure(caught.exception,diagnostic,self.source,worker,self.ops)
        self.assertEqual(report['phase'],'PROJECTED_SOURCE')
        self.assertEqual(report['reason'],'GO_ERP_LIVE_SOURCE_WORLD_WRITABLE')
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())
        self.assert_canonical_unchanged()

    def test_exact_complete_guard_prefix_is_reported_but_other_text_is_private(self):
        diagnostic,worker=self.diagnostic_modules()
        complete_stop=type('Stop',(Exception,),{})
        context={'complete':{'Stop':complete_stop}}
        error=complete_stop('GO_ERP_COMPLETE_SERVER_STATE_CHANGED_DURING_INSPECTION')
        error.go_erp_archive_phase='AFTER_SOURCE'
        report=subject.diagnostic_failure(error,diagnostic,self.source,worker,self.ops,context)
        self.assertEqual(report['phase'],'AFTER_SOURCE')
        self.assertEqual(report['reason'],'GO_ERP_META_BACKUP_GUARD_SERVER_STATE_CHANGED_DURING_INSPECTION')
        private=subject.diagnostic_failure(complete_stop('SECRET_SERVER_PASSWORD'),diagnostic,self.source,worker,self.ops,context)
        self.assertEqual(private['reason'],'PRIVATE_FAILURE')
        self.assertNotIn('SECRET_SERVER_PASSWORD',json.dumps(private))

    def add_classic_backups(self):
        for index,stem in enumerate(self.live_contents):
            name=stem+'.bak'; path=self.root/name
            data=b'PRIVATE_CLASSIC_BACKUP_'+str(index).encode()+b'\n'
            path.write_bytes(data); path.chmod(0o644); os.chown(path,1001,1003)
            self.payloads[name]=data; self.original[name]=path.stat()

    def legacy_plan(self, capture):
        plan=capture/'plan.json'; record=json.loads(plan.read_text())
        record['format']=1; record['suffix']=self.suffix; record.pop('suffixes',None)
        encoded=json.dumps(record)
        plan.write_text(encoded)
        (Path(record['audit'])/'planned.json').write_text(encoded)
        return record

    def test_both_observed_backup_families_archive_together_with_original_source_checks(self):
        self.add_classic_backups()
        with self.assertRaises(self.source.SourceError):
            self.source.scan_snapshot(self.root,self.seed,1001)
        before=self.tree(); readonly=self.run_archive()
        self.assertEqual(readonly['pending'],4); self.assertEqual(before,self.tree())
        report=self.run_archive(True)
        self.assertTrue(report['ok']); self.assertEqual(report['captured'],4)
        self.assertEqual(report['pending'],0); self.assert_canonical_unchanged()
        self.assertEqual(sorted(p.read_bytes() for p in self.captured()),sorted(self.payloads.values()))
        self.assertEqual(sorted(p.read_bytes() for p in self.copies()),sorted(self.payloads.values()))
        for name,before in self.original.items():
            self.assertFalse((self.root/name).exists())
            captured=[p for p in self.captured() if p.read_bytes()==self.payloads[name]]
            self.assertEqual(len(captured),1); self.assertEqual(captured[0].stat().st_ino,before.st_ino)
        record=json.loads((Path(report['capture_directory'])/'plan.json').read_text())
        self.assertEqual(record['format'],3)
        self.assertEqual(record['suffixes'],list(subject.SUFFIXES))
        self.source.scan_snapshot(self.root,self.seed,1001)
        before_retry=self.tree(); retry=self.run_archive(True)
        self.assertEqual(retry['captured'],0); self.assertEqual(before_retry,self.tree())

    def test_unapproved_stem_with_classic_backup_is_not_archived(self):
        path=self.root/'app/Unknown.php.bak'
        path.write_bytes(b'PRIVATE_UNAPPROVED_CLASSIC'); path.chmod(0o644); os.chown(path,1001,1003)
        before=self.tree()
        with self.assertRaises(self.source.SourceError): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())
        self.assert_canonical_unchanged()

    def test_classic_backup_followed_by_an_unapproved_suffix_stops_whole_batch(self):
        self.add_classic_backups()
        path=self.root/'app/Console/Kernel.php.bak.new'
        path.write_bytes(b'PRIVATE_UNAPPROVED_SUFFIX'); path.chmod(0o644); os.chown(path,1001,1003)
        before=self.tree()
        with self.assertRaises(self.source.SourceError): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())
        self.assert_canonical_unchanged()

    def test_original_format_one_meta_capture_remains_readable(self):
        report=self.run_archive(True)
        record=self.legacy_plan(Path(report['capture_directory']))
        self.assertTrue(all(row['path'].endswith(self.suffix) for row in record['rows']))
        before=self.tree(); retry=self.run_archive(True)
        self.assertTrue(retry['ok']); self.assertEqual(retry['captured'],0)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_format_one_record_cannot_retroactively_authorize_classic_backup_rows(self):
        self.add_classic_backups(); report=self.run_archive(True)
        self.legacy_plan(Path(report['capture_directory']))
        before=self.tree()
        with self.assertRaises(subject.Stop): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_legacy_partial_capture_resumes_into_new_family_record_and_stays_idempotent(self):
        calls=[]
        def fail_second(row):
            calls.append(row['path'])
            if len(calls)==2: raise RuntimeError('INJECTED_LEGACY_PARTIAL')
        with self.assertRaises(Exception): self.run_archive(True,before_capture=fail_second)
        captures=list(self.backups.glob('.go-erp-meta-backup-archive-*'))
        self.assertEqual(len(captures),1); self.legacy_plan(captures[0])
        self.add_classic_backups()
        report=self.run_archive(True)
        self.assertEqual(report['captured'],3); self.assertEqual(len(self.captured()),4)
        self.assert_canonical_unchanged(); self.source.scan_snapshot(self.root,self.seed,1001)
        before=self.tree(); third=self.run_archive(True)
        self.assertEqual(third['captured'],0); self.assertEqual(before,self.tree())

    def test_readonly_inventory_lists_all_future_unclassified_paths_without_archiving(self):
        names=['app/Console/Kernel.php.orig','app/Http/Kernel.php.bak-20261019']
        for name in names:
            path=self.root/name; path.write_bytes(b'PRIVATE_UNCLASSIFIED_BACKUP')
            path.chmod(0o644); os.chown(path,1001,1003)
        before=self.tree()
        report=subject.unclassified_inventory(self.source,self.root,self.seed,set(self.payloads))
        self.assertTrue(report['inventory_complete'])
        self.assertEqual(report['unclassified_count'],2)
        self.assertEqual(set(report['unclassified_additions']),set(names))
        self.assertEqual(report['redacted_paths'],0)
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())
        with self.assertRaises(self.source.SourceError): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_readonly_inventory_never_opens_backup_bytes_or_excluded_private_paths(self):
        paths=['app/Console/Kernel.php.orig','config/.env',
            'bootstrap/cache/PRIVATE_CONFIG.php','public/uploads/PRIVATE_CUSTOMER.json']
        for name in paths:
            path=self.root/name; path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(b'SECRET_FILE_CONTENT_MUST_NOT_BE_OPENED'); path.chmod(0o644)
            os.chown(path,1001,1003)
        real_open,real_stat=os.open,os.stat
        forbidden_open={'Kernel.php.orig','.env','cache','uploads'}
        def safe_open(name,*args,**kwargs):
            if Path(str(name)).name in forbidden_open: raise AssertionError('Private or backup file opened')
            return real_open(name,*args,**kwargs)
        def safe_stat(name,*args,**kwargs):
            if Path(str(name)).name in {'.env','cache','uploads'}: raise AssertionError('Excluded path inspected')
            return real_stat(name,*args,**kwargs)
        before=self.tree()
        with mock.patch.object(os,'open',side_effect=safe_open),mock.patch.object(os,'stat',side_effect=safe_stat):
            report=subject.unclassified_inventory(self.source,self.root,self.seed,set(self.payloads))
        self.assertTrue(report['inventory_complete'])
        self.assertEqual(report['unclassified_additions'],['app/Console/Kernel.php.orig'])
        encoded=json.dumps(report)
        for secret in ['PRIVATE_CONFIG','PRIVATE_CUSTOMER','SECRET_FILE_CONTENT','.env']:
            self.assertNotIn(secret,encoded)
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())

    def test_incomplete_readonly_inventory_hides_exception_and_does_not_authorize_archive(self):
        path=self.root/'app/Console/Kernel.php.orig'; path.write_bytes(b'PRIVATE_UNKNOWN')
        path.chmod(0o644); os.chown(path,1001,1003); before=self.tree()
        with self.assertRaises(self.source.SourceError): self.run_archive(True)
        with mock.patch.object(self.source,'_git',side_effect=RuntimeError('SECRET_GIT_CREDENTIAL')):
            report=subject.unclassified_inventory(self.source,self.root,self.seed,set(self.payloads))
        self.assertFalse(report['inventory_complete'])
        self.assertNotIn('SECRET_GIT_CREDENTIAL',json.dumps(report))
        self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())
        self.assert_canonical_unchanged()

    def add_new_observed_backups(self):
        for index,stem in enumerate(self.live_contents):
            for family,suffix in enumerate(('.bak.1785856307','.bak-20261020-042159')):
                name=stem+suffix; path=self.root/name
                data=b'PRIVATE_NEW_FAMILY_'+str(index).encode()+b'_'+str(family).encode()+b'\n'
                path.write_bytes(data); path.chmod(0o644); os.chown(path,1001,1003)
                self.payloads[name]=data; self.original[name]=path.stat()

    def downgrade_plan_to_format_two(self,capture):
        plan=capture/'plan.json'; record=json.loads(plan.read_text())
        record['format']=2; record['suffixes']=[self.suffix,'.bak']; record.pop('suffix',None)
        encoded=json.dumps(record); plan.write_text(encoded)
        (Path(record['audit'])/'planned.json').write_text(encoded)
        return record

    def test_new_two_observed_exact_families_preserve_originals_and_independent_copies(self):
        self.add_new_observed_backups()
        with self.assertRaises(self.source.SourceError): self.source.scan_snapshot(self.root,self.seed,1001)
        before=self.tree(); readonly=self.run_archive()
        self.assertEqual(readonly['pending'],6); self.assertEqual(before,self.tree())
        report=self.run_archive(True)
        self.assertTrue(report['ok']); self.assertEqual(report['captured'],6)
        self.assertEqual(sorted(p.read_bytes() for p in self.captured()),sorted(self.payloads.values()))
        self.assertEqual(sorted(p.read_bytes() for p in self.copies()),sorted(self.payloads.values()))
        for name,value in self.original.items():
            captured=[p for p in self.captured() if p.read_bytes()==self.payloads[name]]
            self.assertEqual(len(captured),1); self.assertEqual(captured[0].stat().st_ino,value.st_ino)
        record=json.loads((Path(report['capture_directory'])/'plan.json').read_text())
        self.assertEqual(record['format'],3)
        self.assertEqual(record['suffixes'],list(subject.SUFFIXES))
        self.assert_canonical_unchanged(); self.source.scan_snapshot(self.root,self.seed,1001)

    def test_other_epoch_and_timestamp_suffixes_remain_unapproved_without_mutation(self):
        for index,suffix in enumerate(('.bak.1785856308','.bak-20261020-042158')):
            with self.subTest(suffix=suffix):
                path=self.root/('app/Console/Kernel.php'+suffix)
                path.write_bytes(b'PRIVATE_UNAPPROVED_DATE'); path.chmod(0o644); os.chown(path,1001,1003)
                before=self.tree()
                with self.assertRaises(self.source.SourceError): self.run_archive(True)
                self.assertEqual(before,self.tree()); self.assertFalse(self.audit.exists())
                self.assert_canonical_unchanged()
                path.rename(self.base/('retained-unapproved-'+str(index)))

    def test_original_format_two_mixed_capture_remains_readable_and_idempotent(self):
        self.add_classic_backups(); report=self.run_archive(True)
        record=self.downgrade_plan_to_format_two(Path(report['capture_directory']))
        self.assertTrue(all(any(row['path'].endswith(suffix) for suffix in [self.suffix,'.bak']) for row in record['rows']))
        before=self.tree(); retry=self.run_archive(True)
        self.assertTrue(retry['ok']); self.assertEqual(retry['captured'],0)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_format_two_record_cannot_retroactively_authorize_new_exact_family_rows(self):
        self.add_new_observed_backups(); report=self.run_archive(True)
        self.downgrade_plan_to_format_two(Path(report['capture_directory']))
        before=self.tree()
        with self.assertRaises(subject.Stop): self.run_archive(True)
        self.assertEqual(before,self.tree()); self.assert_canonical_unchanged()

    def test_known_sensitive_named_php_backup_is_preserved_without_public_path_disclosure(self):
        stem='config/firebase.php'; current=b'<?php /* CURRENT_FIREBASE_CONFIG */\n'
        path=self.root/stem; path.parent.mkdir(parents=True); path.write_bytes(current); path.chmod(0o644)
        os.chown(path.parent,1001,1003); os.chown(path,1001,1003)
        self.live_contents[stem]=current; self.canonical[stem]=path.stat()
        self.manifest['sources'].append({'path':stem,'before_blob':'a'*40,'after_blob':blob(current),'phase':2})
        name=stem+'.bak.1785856307'; backup=self.root/name; data=b'PRIVATE_FIREBASE_BACKUP_CONTENT'
        backup.write_bytes(data); backup.chmod(0o644); os.chown(backup,1001,1003)
        self.payloads[name]=data; self.original[name]=backup.stat()
        report=self.run_archive(True)
        self.assertTrue(report['ok']); self.assertEqual(report['captured'],3)
        self.assertTrue(any(p.read_bytes()==data for p in self.captured()))
        self.assertTrue(any(p.read_bytes()==data for p in self.copies()))
        encoded=json.dumps(report)
        self.assertNotIn(stem,encoded); self.assertNotIn('PRIVATE_FIREBASE_BACKUP_CONTENT',encoded)
        self.assert_canonical_unchanged(); self.source.scan_snapshot(self.root,self.seed,1001)


if __name__=='__main__': unittest.main()
