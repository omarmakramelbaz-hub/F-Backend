#!/usr/bin/env python3
"""Exact static-source mode repair with unchanged data and private audit proofs."""
import fcntl
import errno
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

REPOSITORY=Path(__file__).resolve().parents[1]

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value

subject=module('static_source_modes_subject',REPOSITORY/'deployment/repair_go_erp_static_source_modes_root.py')

class VirtualOwnership:
    def __init__(self):
        self.values={};self.stat,self.lstat,self.fstat=os.stat,os.lstat,os.fstat
    def view(self,value):
        pair=self.values.get((value.st_dev,value.st_ino))
        if pair is None:return value
        class View:
            st_uid,st_gid=pair
            def __getattr__(self,name):return getattr(value,name)
            def __getitem__(self,index):return pair[index-4] if index in (4,5) else value[index]
        return View()
    def chown(self,path,uid,gid,**kwargs):
        value=self.stat(path,**kwargs);self.values[(value.st_dev,value.st_ino)]=(uid,gid)
    def patches(self):
        return [mock.patch.object(os,'stat',lambda *a,**k:self.view(self.stat(*a,**k))),
            mock.patch.object(os,'lstat',lambda *a,**k:self.view(self.lstat(*a,**k))),
            mock.patch.object(os,'fstat',lambda *a,**k:self.view(self.fstat(*a,**k))),
            mock.patch.object(os,'chown',self.chown)]

@unittest.skipUnless(os.geteuid()==0,'Private filesystem fixture requires root.')
class StaticSourceModesTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='go-erp-static-source-modes-')
        self.addCleanup(self.temporary.cleanup);self.base=Path(self.temporary.name)
        mapping=Path('/proc/self/uid_map').read_text().split()
        if len(mapping)==3 and mapping[-1]=='1':
            if os.environ.get('GO_ERP_TEST_REQUIRE_REAL_CHOWN')=='1':
                self.fail('CI requires real ownership; namespace maps UID0 only')
            adapter=VirtualOwnership()
            for patch in adapter.patches():patch.start();self.addCleanup(patch.stop)
        self.root=self.base/'app';self.root.mkdir(mode=0o750)
        self.work=self.base/'work';self.work.mkdir(mode=0o700)
        self.backups=self.base/'backups';self.backups.mkdir(mode=0o700)
        os.chown(self.root,1001,1003);os.chown(self.backups,1001,1003)
        self.payloads={}
        for name,pin in subject.FILE_PINS.items():
            data=subprocess.check_output(['git','cat-file','blob',pin],cwd=REPOSITORY)
            self.assertEqual(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest(),pin)
            path=self.root/name;path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(data);path.chmod(0o666);self.payloads[name]=data
        for directory,dirs,files in os.walk(self.root):
            os.chown(directory,1001,1003)
            for name in files:os.chown(Path(directory)/name,1001,1003)
        for parent,name,owner in [(self.work,'watcher.lock',(0,0)),
                (self.backups,'server-install.lock',(1001,1003))]:
            path=parent/name;path.write_bytes(b'');path.chmod(0o600);os.chown(path,*owner)
        self.seed_payloads=dict(self.payloads)
        self.before={name:(self.root/name).stat() for name in self.payloads}
        self.source=module('actual_static_source_reader',REPOSITORY/'deployment/go_erp_live_source.py')
        self.ops=module('actual_static_source_primitives',REPOSITORY/'deployment/finalize_go_erp_publication_links_root.py').__dict__
        self.archive=module('actual_static_source_archive',REPOSITORY/'deployment/archive_go_erp_meta_backups_root.py').__dict__

    def run_repair(self,requested=True,**kwargs):
        kwargs.setdefault('approved_blobs',subject.approved_variants(
            {name:(data,'100644') for name,data in self.seed_payloads.items()}))
        return subject.run_repair(self.root,self.work,self.backups,self.source,self.ops,self.archive,
            requested=requested,**kwargs)

    def snapshot(self):
        result={}
        for path in self.base.rglob('*'):
            value=path.lstat()
            data=None if path.is_dir() else (os.readlink(path) if path.is_symlink() else path.read_bytes())
            result[str(path.relative_to(self.base))]=(value.st_dev,value.st_ino,value.st_mode,value.st_uid,
                value.st_gid,value.st_nlink,value.st_size,value.st_mtime_ns,data)
        return result

    def assert_data_unchanged(self,expected_modes=None):
        for name,data in self.payloads.items():
            path=self.root/name;after=path.stat();before=self.before[name]
            self.assertEqual(path.read_bytes(),data)
            for key in ['st_dev','st_ino','st_uid','st_gid','st_nlink','st_size','st_mtime_ns']:
                self.assertEqual(getattr(after,key),getattr(before,key),key)
            if expected_modes is not None:self.assertEqual(stat.S_IMODE(after.st_mode),expected_modes[name])

    def assert_refused_unchanged(self):
        before=self.snapshot()
        try:result=self.run_repair()
        except (subject.Stop,self.archive['Stop'],self.ops['Stop']):pass
        else:self.assertFalse(result['ok'])
        self.assertEqual(before,self.snapshot())
        self.assertEqual(list(self.work.glob('static-source-modes-*')),[])

    def test_readonly_preflight_does_not_change_modes_or_create_audit(self):
        before=self.snapshot();report=self.run_repair(False)
        self.assertEqual(report['pending'],len(self.payloads));self.assertEqual(report['changed'],0)
        self.assertEqual(before,self.snapshot())

    def test_exact_raw_blobs_only_lose_other_write_permission(self):
        report=self.run_repair()
        self.assertTrue(report['ok']);self.assertEqual(report['changed'],len(self.payloads))
        self.assert_data_unchanged({name:0o664 for name in self.payloads})
        self.assertFalse(report['application_contents_changed']);self.assertFalse(report['database_changed'])
        audit=Path(report['audit']);self.assertEqual(audit.stat().st_uid,0)
        self.assertEqual(stat.S_IMODE(audit.stat().st_mode),0o700)
        self.assertTrue((audit/'plan.json').is_file())
        for path in audit.iterdir():
            if path.is_file():
                self.assertEqual(path.stat().st_uid,0);self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600)

    def test_group_and_execute_bits_are_preserved(self):
        pattern=[0o777,0o646,0o702,0o602,0o662,0o666]
        modes=[pattern[index%len(pattern)] for index in range(len(self.payloads))]
        for name,mode in zip(self.payloads,modes):
            (self.root/name).chmod(mode);self.before[name]=(self.root/name).stat()
        self.run_repair();self.assert_data_unchanged({name:mode&~0o002 for name,mode in zip(self.payloads,modes)})

    def test_second_run_is_idempotent_and_does_not_create_another_audit(self):
        self.run_repair();before=self.snapshot();report=self.run_repair()
        self.assertTrue(report['ok']);self.assertEqual(report['changed'],0)
        self.assertEqual(before,self.snapshot())

    def test_already_safe_modified_asset_is_allowed_and_left_unchanged(self):
        name=next(iter(self.payloads));path=self.root/name
        data=b'// APPROVED_NEW_LIVE_SOURCE\n';path.write_bytes(data);path.chmod(0o644)
        self.payloads[name]=data;self.before[name]=path.stat()
        report=self.run_repair();self.assertTrue(report['ok']);self.assertEqual(report['changed'],len(self.payloads)-1)
        expected={other:(0o644 if other==name else 0o664) for other in self.payloads}
        self.assert_data_unchanged(expected)

    def test_changed_world_writable_asset_blocks_entire_plan(self):
        path=self.root/next(iter(self.payloads));path.write_bytes(b'// CHANGED_UNSAFE_SOURCE\n')
        self.assert_refused_unchanged()

    def test_unknown_direct_world_writable_javascript_blocks_entire_plan(self):
        path=self.root/'public/site/js/new.js';path.write_bytes(b'// UNKNOWN\n');path.chmod(0o666);os.chown(path,1001,1003)
        self.assert_refused_unchanged()

    def test_unknown_world_writable_css_blocks_entire_plan(self):
        path=self.root/'public/site/styles/new.css';path.write_bytes(b'/* UNKNOWN */\n')
        path.chmod(0o666);os.chown(path,1001,1003);self.assert_refused_unchanged()

    def test_trusted_crlf_variants_and_two_exact_directories_are_repaired_together(self):
        for name,raw in self.seed_payloads.items():
            self.assertNotIn(b'\r\n',raw)
            data=raw.replace(b'\n',b'\r\n');path=self.root/name;path.write_bytes(data)
            self.payloads[name]=data;self.before[name]=path.stat()
        directories={}
        for name in subject.DIRECTORY_TARGETS:
            path=self.root/name;path.chmod(0o777);directories[name]=path.stat()
        report=self.run_repair();self.assertTrue(report['ok'])
        self.assertEqual(report['changed'],13);self.assertEqual(report['verified_changed'],13)
        self.assertEqual(report['attempted'],13);self.assertEqual(report['pending'],0)
        self.assertFalse(report['metadata_change_possible']);self.assertEqual(report['uncertain_paths'],[])
        self.assert_data_unchanged({name:0o664 for name in self.payloads})
        for name,before in directories.items():
            after=(self.root/name).stat();self.assertEqual(stat.S_IMODE(after.st_mode),0o775)
            for key in ['st_dev','st_ino','st_uid','st_gid','st_nlink','st_mtime_ns']:
                self.assertEqual(getattr(after,key),getattr(before,key),key)
        self.assertEqual(report['remaining_world_writable_count'],0)
        self.assertTrue(report['inventory_complete'])

    def test_default_raw_only_policy_refuses_crlf_without_trusted_seed_variants(self):
        name=next(name for name,data in self.seed_payloads.items() if b'\n' in data)
        path=self.root/name;path.write_bytes(self.seed_payloads[name].replace(b'\n',b'\r\n'))
        before=self.snapshot()
        report=subject.run_repair(self.root,self.work,self.backups,self.source,self.ops,self.archive)
        self.assertFalse(report['ok']);self.assertEqual(report['changed'],0)
        self.assertEqual(before,self.snapshot())
        self.assertEqual(list(self.work.glob('static-source-modes-*')),[])

    def test_approved_css_directory_symlink_blocks_all_repairs(self):
        path=self.root/subject.DIRECTORY_TARGETS[0];path.chmod(0o777)
        retained=self.base/'retained-styles';path.rename(retained);path.symlink_to(retained)
        self.assert_refused_unchanged();self.assertTrue(path.is_symlink())
        self.assertEqual(stat.S_IMODE(retained.stat().st_mode),0o777)

    def test_partial_directory_first_repair_resumes_without_repeating_changed_directory(self):
        for name in subject.DIRECTORY_TARGETS:(self.root/name).chmod(0o777)
        visited=[]
        def fail_second(row):
            visited.append((row['kind'],row['path']))
            if len(visited)==2:raise RuntimeError('INJECTED_DIRECTORY_REPAIR_FAILURE')
        report=self.run_repair(before_chmod=fail_second)
        self.assertFalse(report['ok']);self.assertEqual(report['changed'],1)
        self.assertEqual(report['verified_changed'],1);self.assertEqual(report['attempted'],1)
        self.assertEqual(visited,[('directory',name) for name in subject.DIRECTORY_TARGETS])
        self.assertEqual(stat.S_IMODE((self.root/subject.DIRECTORY_TARGETS[0]).stat().st_mode),0o775)
        self.assertEqual(stat.S_IMODE((self.root/subject.DIRECTORY_TARGETS[1]).stat().st_mode),0o777)
        self.assert_data_unchanged({name:0o666 for name in self.payloads})
        resumed=self.run_repair();self.assertTrue(resumed['ok']);self.assertEqual(resumed['changed'],12)
        for name in subject.DIRECTORY_TARGETS:
            self.assertEqual(stat.S_IMODE((self.root/name).stat().st_mode),0o775)
        self.assert_data_unchanged({name:0o664 for name in self.payloads})
        before=self.snapshot();third=self.run_repair();self.assertEqual(third['changed'],0)
        self.assertEqual(before,self.snapshot())

    def test_unknown_safe_direct_javascript_is_not_modified(self):
        path=self.root/'public/site/js/new.js';data=b'// CURRENT_SAFE_ADDITION\n'
        path.write_bytes(data);path.chmod(0o644);os.chown(path,1001,1003);before=path.stat()
        report=self.run_repair();self.assertTrue(report['ok']);self.assertEqual(report['changed'],len(self.payloads))
        self.assertEqual(path.read_bytes(),data);self.assertEqual(path.stat().st_mode,before.st_mode)
        self.assertEqual(path.stat().st_ino,before.st_ino)

    def test_target_symlink_is_never_followed_or_changed(self):
        name=next(iter(self.payloads));path=self.root/name;retained=self.base/'retained-asset'
        path.rename(retained);path.symlink_to(retained);self.assert_refused_unchanged()
        self.assertTrue(path.is_symlink());self.assertEqual(stat.S_IMODE(retained.stat().st_mode),0o666)

    def test_target_hardlink_blocks_entire_plan(self):
        os.link(self.root/next(iter(self.payloads)),self.base/'extra-link');self.assert_refused_unchanged()

    def test_unknown_owner_blocks_entire_plan(self):
        os.chown(self.root/next(iter(self.payloads)),1002,1003);self.assert_refused_unchanged()

    def test_busy_watcher_lock_prevents_all_changes(self):
        descriptor=os.open(self.work/'watcher.lock',os.O_RDONLY)
        try:
            fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB);self.assert_refused_unchanged()
        finally:os.close(descriptor)

    def test_busy_installer_lock_prevents_all_changes(self):
        descriptor=os.open(self.backups/'server-install.lock',os.O_RDONLY)
        try:
            fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB);self.assert_refused_unchanged()
        finally:os.close(descriptor)

    def test_partial_failure_retains_audit_and_repair_can_resume(self):
        calls=[]
        def fail_second(row):
            calls.append(row)
            if len(calls)==2:raise RuntimeError('INJECTED_MODE_REPAIR_FAILURE')
        try:result=self.run_repair(before_chmod=fail_second)
        except subject.Stop:pass
        else:self.assertFalse(result['ok'])
        self.assertEqual(sum(not (self.root/name).stat().st_mode&0o002 for name in self.payloads),1)
        audits=list(self.work.glob('static-source-modes-*'));self.assertEqual(len(audits),1)
        self.assertTrue((audits[0]/'plan.json').is_file());self.assert_data_unchanged()
        report=self.run_repair();self.assertTrue(report['ok']);self.assertEqual(report['changed'],len(self.payloads)-1)
        self.assert_data_unchanged({name:0o664 for name in self.payloads})
        before=self.snapshot();third=self.run_repair();self.assertEqual(third['changed'],0)
        self.assertEqual(before,self.snapshot())

    def test_fsync_failure_after_chmod_reports_uncertain_actual_change(self):
        first=sorted(self.payloads)[0];saved=self.before[first]
        real_fsync=os.fsync;failures=[]
        def fail_after_change(descriptor):
            current=os.fstat(descriptor)
            if (not failures and stat.S_ISREG(current.st_mode)
                    and (current.st_dev,current.st_ino)==(saved.st_dev,saved.st_ino)
                    and not current.st_mode&0o002):
                failures.append(descriptor);raise OSError(errno.EIO,'INJECTED_FSYNC_FAILURE')
            return real_fsync(descriptor)
        with mock.patch.object(os,'fsync',side_effect=fail_after_change):
            report=self.run_repair()
        self.assertEqual(len(failures),1);self.assertFalse(report['ok'])
        self.assertEqual(report['attempted'],1);self.assertEqual(report['changed'],1)
        self.assertEqual(report['verified_changed'],0);self.assertEqual(report['pending'],len(self.payloads))
        self.assertTrue(report['metadata_change_possible']);self.assertEqual(report['uncertain_paths'],[first])
        stopped=json.loads((Path(report['audit'])/'stopped.json').read_text())
        self.assertEqual(stopped['attempted'],1);self.assertEqual(stopped['applied'],1)
        self.assertEqual(stopped['verified_changed'],0)
        self.assertTrue(stopped['metadata_change_possible']);self.assertEqual(stopped['uncertain_paths'],[first])
        self.assert_data_unchanged({name:0o664 if name==first else 0o666 for name in self.payloads})
        self.assertEqual(report['remaining_world_writable_count'],len(self.payloads)-1)
        self.assertEqual(report['inventory_omitted_count'],0)
        self.assertEqual(report['inventory_omitted_planned_paths'],[])
        self.assertTrue(report['inventory_complete'])

    def test_world_writable_source_outside_js_is_reported_and_never_modified(self):
        name='app/Console/UnchangedLive.php';path=self.root/name
        path.parent.mkdir(parents=True);path.write_bytes(b'<?php // LIVE_SOURCE_UNCHANGED\n')
        path.chmod(0o666);os.chown(path,1001,1003);saved=path.stat();data=path.read_bytes()
        report=self.run_repair();self.assertTrue(report['ok']);self.assertEqual(report['changed'],len(self.payloads))
        self.assertEqual(report['remaining_world_writable_count'],1)
        self.assertEqual([row['path'] for row in report['remaining_world_writable']],[name])
        self.assertEqual(path.read_bytes(),data)
        self.assertEqual(subject.identity(path.stat()),subject.identity(saved))
        self.assertTrue(report['inventory_complete'])
        self.assert_data_unchanged({name:0o664 for name in self.payloads})

    def test_inode_replacement_before_chmod_cannot_change_foreign_or_displaced_file(self):
        replaced=[]
        def race(row):
            if replaced:return
            name=row['path'];path=self.root/name;held=self.base/'displaced-original'
            path.rename(held);path.write_bytes(b'FOREIGN_RACE_SENTINEL');path.chmod(0o666);os.chown(path,1001,1003)
            replaced.append((path,held))
        try:result=self.run_repair(before_chmod=race)
        except subject.Stop:pass
        else:self.assertFalse(result['ok'])
        self.assertEqual(len(replaced),1);path,held=replaced[0]
        self.assertEqual(path.read_bytes(),b'FOREIGN_RACE_SENTINEL')
        self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o666)
        self.assertEqual(stat.S_IMODE(held.stat().st_mode),0o666)

    def test_private_and_runtime_paths_are_excluded_before_stat_or_open(self):
        names=['config/.env','bootstrap/cache/PRIVATE_CONFIG.php','public/uploads/PRIVATE_UPLOAD.json']
        for name in names:
            path=self.root/name;path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(b'PRIVATE_SECRET_CONTENT');path.chmod(0o666);os.chown(path,1001,1003)
        real_open,real_stat=os.open,os.stat
        def safe_open(name,*args,**kwargs):
            if Path(str(name)).name in {'.env','cache','uploads'}:raise AssertionError('Private path opened')
            return real_open(name,*args,**kwargs)
        def safe_stat(name,*args,**kwargs):
            if Path(str(name)).name in {'.env','cache','uploads'}:raise AssertionError('Private path inspected')
            return real_stat(name,*args,**kwargs)
        with mock.patch.object(os,'open',side_effect=safe_open),mock.patch.object(os,'stat',side_effect=safe_stat):
            report=self.run_repair()
        self.assertTrue(report['ok']);encoded=json.dumps(report)
        for text in ['PRIVATE_SECRET_CONTENT','PRIVATE_CONFIG','PRIVATE_UPLOAD','.env']:
            self.assertNotIn(text,encoded)
        self.assert_data_unchanged({name:0o664 for name in self.payloads})

if __name__=='__main__':unittest.main()
