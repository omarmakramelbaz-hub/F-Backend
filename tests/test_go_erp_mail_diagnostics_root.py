#!/usr/bin/env python3
"""Exact public mail diagnostics are retained privately with proven byte bindings."""
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import tempfile
import unittest
from unittest import mock

REPOSITORY=Path(__file__).resolve().parents[1]

def module(name,filename):
    spec=importlib.util.spec_from_file_location(name,REPOSITORY/'deployment'/filename)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value

subject=module('mail_diagnostics_subject','archive_go_erp_mail_diagnostics_root.py')

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

@unittest.skipUnless(os.geteuid()==0,'Private diagnostics fixture requires root.')
class MailDiagnosticsArchiveTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='go-erp-mail-diagnostics-')
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
        self.custody=self.base/'custody'
        os.chown(self.root,1001,1003);os.chown(self.backups,1001,1003)
        public=self.root/'public';public.mkdir(mode=0o755);os.chown(public,1001,1003)
        self.payloads={'public/phpmailer_test.php':b'<?php /* PRIVATE_MAIL_DIAGNOSTIC_A */\n',
            'public/smtp_test.php':b'<?php /* PRIVATE_MAIL_DIAGNOSTIC_B */\n'}
        for name,data in self.payloads.items():
            path=self.root/name;path.write_bytes(data);path.chmod(0o644);os.chown(path,1001,1003)
        self.bindings={name:hashlib.sha256(data).hexdigest() for name,data in self.payloads.items()}
        self.original={name:(self.root/name).stat() for name in self.payloads}
        self.live=self.root/'public/index.php';self.live.write_bytes(b'<?php /* CURRENT_APP */\n')
        self.live.chmod(0o644);os.chown(self.live,1001,1003);self.live_before=self.live.stat()
        for parent,name,owner in [(self.work,'watcher.lock',(0,0)),
                (self.backups,'server-install.lock',(1001,1003))]:
            path=parent/name;path.write_bytes(b'');path.chmod(0o600);os.chown(path,*owner)
        self.ops=module('mail_archive_primitives','finalize_go_erp_publication_links_root.py').__dict__
        self.archive=module('mail_archive_lock_primitives','archive_go_erp_meta_backups_root.py').__dict__

    def snapshot(self):
        result={}
        for path in self.base.rglob('*'):
            value=path.lstat()
            data=os.readlink(path) if path.is_symlink() else (None if path.is_dir() else path.read_bytes())
            result[str(path.relative_to(self.base))]=(value.st_dev,value.st_ino,value.st_mode,value.st_uid,
                value.st_gid,value.st_nlink,value.st_size,value.st_mtime_ns,data)
        return result

    def run_archive(self,requested=True,**kwargs):
        return subject.archive_files(self.root,self.work,self.backups,self.ops,self.archive,
            self.bindings,requested=requested,custody_root=self.custody,**kwargs)

    def payload_files(self,root):
        return [path for path in root.rglob('*') if path.is_file() and path.read_bytes() in self.payloads.values()]

    def copies(self):
        return [path for path in self.custody.rglob('copy-*.bin') if path.is_file()]

    def originals(self):
        return [path for path in self.custody.rglob('original-*.bin') if path.is_file()]

    def assert_live_unchanged(self):
        self.assertEqual(self.live.read_bytes(),b'<?php /* CURRENT_APP */\n')
        after=self.live.stat()
        for field in ['st_dev','st_ino','st_mode','st_uid','st_gid','st_nlink','st_size','st_mtime_ns']:
            self.assertEqual(getattr(after,field),getattr(self.live_before,field),field)

    def assert_refused_unchanged(self,**kwargs):
        before=self.snapshot()
        try:report=self.run_archive(**kwargs)
        except (subject.Stop,self.archive['Stop'],self.ops['Stop']):pass
        else:self.assertFalse(report['ok'])
        self.assertEqual(before,self.snapshot());self.assert_live_unchanged()

    def test_readonly_verifies_bindings_without_audit_or_file_changes(self):
        before=self.snapshot();report=self.run_archive(requested=False)
        self.assertTrue(report['ok']);self.assertEqual(before,self.snapshot())
        self.assertEqual(self.payload_files(self.work),[]);self.assertFalse(self.custody.exists())

    def test_exact_files_have_private_independent_copies_and_original_inodes_outside_public(self):
        for name,mode in zip(self.payloads,[0o600,0o664]):
            (self.root/name).chmod(mode);self.original[name]=(self.root/name).stat()
        report=self.run_archive();self.assertTrue(report['ok'])
        originals=self.originals();copies=self.copies()
        self.assertEqual(len(originals),2);self.assertEqual(len(copies),2)
        self.assertEqual(report['captured'],2);self.assertEqual(report['pending'],0)
        self.assertEqual(report['source_files_removed'],2);self.assertTrue(report['application_contents_changed'])
        self.assertFalse(report['database_changed']);self.assertFalse(report['credentials_disclosed'])
        for name,before in self.original.items():
            self.assertFalse((self.root/name).exists())
            held=next(path for path in originals if path.read_bytes()==self.payloads[name])
            copied=next(path for path in copies if path.read_bytes()==self.payloads[name])
            after=held.stat()
            for field in ['st_dev','st_ino','st_mode','st_uid','st_gid','st_nlink','st_size','st_mtime_ns']:
                self.assertEqual(getattr(after,field),getattr(before,field),field)
            self.assertNotEqual(copied.stat().st_ino,after.st_ino)
            self.assertEqual(copied.stat().st_uid,0);self.assertEqual(copied.stat().st_nlink,1)
            self.assertEqual(stat.S_IMODE(copied.stat().st_mode),0o600)
        self.assertEqual(self.custody.stat().st_uid,0)
        self.assertEqual(stat.S_IMODE(self.custody.stat().st_mode),0o700)
        self.assert_live_unchanged()
        encoded=json.dumps(report)
        for value in ['PRIVATE_MAIL_DIAGNOSTIC_A','PRIVATE_MAIL_DIAGNOSTIC_B','<?php']:
            self.assertNotIn(value,encoded)

    def test_binding_mismatch_refuses_both_files_before_audit(self):
        self.bindings['public/smtp_test.php']='f'*64;self.assert_refused_unchanged()

    def test_unknown_extra_binding_cannot_expand_exact_file_scope(self):
        path=self.root/'public/other_test.php';path.write_bytes(b'UNRELATED_PRIVATE_SCRIPT')
        path.chmod(0o644);os.chown(path,1001,1003)
        self.bindings['public/other_test.php']=hashlib.sha256(path.read_bytes()).hexdigest()
        self.assert_refused_unchanged()

    def test_missing_binding_cannot_authorize_partial_scope(self):
        self.bindings.pop('public/smtp_test.php');self.assert_refused_unchanged()

    def test_world_writable_diagnostic_refuses_entire_plan(self):
        (self.root/'public/smtp_test.php').chmod(0o666);self.assert_refused_unchanged()

    def test_world_writable_public_directory_refuses_entire_plan(self):
        (self.root/'public').chmod(0o777);self.assert_refused_unchanged()

    def test_unapproved_owner_refuses_entire_plan(self):
        os.chown(self.root/'public/smtp_test.php',1002,1003);self.assert_refused_unchanged()

    def test_root_owned_safe_modes_and_arbitrary_groups_are_preserved(self):
        expected={}
        for name,mode,gid in zip(self.payloads,[0o640,0o755],[0,4321]):
            path=self.root/name;path.chmod(mode);os.chown(path,0,gid);expected[name]=path.stat()
        report=self.run_archive();self.assertTrue(report['ok']);self.assertEqual(report['captured'],2)
        for name,before in expected.items():
            held=next(path for path in self.originals() if path.read_bytes()==self.payloads[name])
            for field in ['st_dev','st_ino','st_uid','st_gid','st_mode','st_size','st_mtime_ns']:
                self.assertEqual(getattr(held.stat(),field),getattr(before,field),field)
        self.assert_live_unchanged()

    def test_symlink_diagnostic_is_never_followed(self):
        path=self.root/'public/smtp_test.php';held=self.base/'retained-smtp-original'
        path.rename(held);path.symlink_to(held);self.assert_refused_unchanged()
        self.assertTrue(path.is_symlink());self.assertEqual(held.stat().st_ino,self.original['public/smtp_test.php'].st_ino)

    def test_hardlinked_diagnostic_refuses_both_files(self):
        os.link(self.root/'public/smtp_test.php',self.base/'second-link');self.assert_refused_unchanged()

    def test_busy_watcher_lock_refuses_all_changes(self):
        descriptor=os.open(self.work/'watcher.lock',os.O_RDONLY)
        try:
            fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB);self.assert_refused_unchanged()
        finally:os.close(descriptor)

    def test_busy_installer_lock_refuses_all_changes(self):
        descriptor=os.open(self.backups/'server-install.lock',os.O_RDONLY)
        try:
            fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB);self.assert_refused_unchanged()
        finally:os.close(descriptor)

    def test_both_independent_copies_are_durable_before_first_move(self):
        visits=[]
        def before_move(row):
            copies=self.copies()
            self.assertEqual(sorted(path.read_bytes() for path in copies),sorted(self.payloads.values()))
            visits.append(row)
        report=self.run_archive(before_move=before_move)
        self.assertTrue(report['ok']);self.assertEqual(len(visits),2);self.assert_live_unchanged()

    def test_partial_move_can_resume_and_third_run_is_idempotent(self):
        visits=[]
        def fail_second(row):
            visits.append(row)
            if len(visits)==2:raise RuntimeError('INJECTED_PRIVATE_MOVE_FAILURE')
        try:report=self.run_archive(before_move=fail_second)
        except subject.Stop:pass
        else:self.assertFalse(report['ok'])
        self.assertEqual(sum((self.root/name).exists() for name in self.payloads),1)
        self.assertEqual(len(self.originals()),1)
        resumed=self.run_archive();self.assertTrue(resumed['ok'])
        self.assertEqual(resumed['captured'],1);self.assertEqual(resumed['already_archived'],1)
        self.assertEqual(sum((self.root/name).exists() for name in self.payloads),0)
        self.assertEqual(len(self.originals()),2);self.assertEqual(len(self.copies()),2)
        before=self.snapshot();third=self.run_archive();self.assertTrue(third['ok'])
        self.assertEqual(third['captured'],0);self.assertEqual(third['already_archived'],2)
        self.assertEqual(before,self.snapshot());self.assert_live_unchanged()

    def test_named_replacement_before_move_cannot_archive_foreign_file(self):
        replaced=[]
        def race(row):
            if replaced:return
            path=self.root/row['path'];held=self.base/'race-retained-original'
            path.rename(held);path.write_bytes(b'FOREIGN_REPLACEMENT_SENTINEL')
            path.chmod(0o644);os.chown(path,1001,1003);replaced.append((row['path'],path,held))
        try:report=self.run_archive(before_move=race)
        except subject.Stop:pass
        else:self.assertFalse(report['ok'])
        self.assertEqual(len(replaced),1);name,path,held=replaced[0]
        self.assertEqual(path.read_bytes(),b'FOREIGN_REPLACEMENT_SENTINEL')
        self.assertEqual(held.stat().st_ino,self.original[name].st_ino)
        self.assertEqual(held.read_bytes(),self.payloads[name]);self.assert_live_unchanged()

    def test_real_source_guard_accepts_only_exact_two_projected_removals(self):
        source=module('mail_archive_actual_source_guard','go_erp_live_source.py')
        seed=self.work/'checkout';seed.mkdir(mode=0o700)
        path=seed/'app/seed.php';path.parent.mkdir(mode=0o755)
        path.write_bytes(b'<?php /* TRUSTED_SEED_SOURCE */\n');path.chmod(0o644)
        source._git(seed,['init','--quiet'])
        source._git(seed,['remote','add','origin',source.REMOTE_URL])
        source._git(seed,['add','--all']);source._git(seed,['commit','--quiet','-m','trusted fixture seed'])
        observed=source.scan_snapshot(self.root,seed,1001)['metadata'];checked=[]
        def guard(moved):
            fresh=source.scan_snapshot(self.root,seed,1001,observed)['metadata']
            self.assertEqual(fresh,subject.projected(source,observed,moved))
            checked.append(frozenset(moved))
        report=self.run_archive(guard=guard);self.assertTrue(report['ok'])
        targets=set(self.payloads);self.assertEqual(checked[0],frozenset())
        self.assertEqual(checked[-1],frozenset(targets))
        final=source.scan_snapshot(self.root,seed,1001,observed)['metadata']
        self.assertEqual(final['removedFiles'],sorted(targets))
        for name in targets:
            self.assertEqual(final['inventory'][name]['authority'],'removed')
            self.assertFalse(final['inventory'][name]['present'])
        self.assertEqual(set(observed['packagedFiles'])-set(final['packagedFiles']),targets)
        for name,row in observed['inventory'].items():
            if name not in targets:self.assertEqual(final['inventory'][name],row)
        self.assertEqual(final['retainedTrustedFiles'],observed['retainedTrustedFiles'])
        self.assert_live_unchanged()

    def test_production_execute_proves_candidate_raw_blobs_and_exact_live_removals(self):
        source=module('mail_execute_actual_source','go_erp_live_source.py')
        worker=module('mail_execute_actual_worker','go_erp_live_updates.py')
        doctor=module('mail_execute_actual_doctor','diagnose_go_erp_live_watcher_root.py')
        seed=self.work/'checkout';seed.mkdir(mode=0o700)
        trusted=seed/'app/seed.php';trusted.parent.mkdir(mode=0o755)
        trusted.write_bytes(b'<?php /* TRUSTED_EXECUTE_SEED */\n');trusted.chmod(0o644)
        source._git(seed,['init','--quiet']);source._git(seed,['remote','add','origin',source.REMOTE_URL])
        source._git(seed,['add','--all']);source._git(seed,['commit','--quiet','-m','execute fixture seed'])
        baseline=source._git(seed,['rev-parse','HEAD']).decode().strip()
        scanned=source.scan_snapshot(self.root,seed,1001);observed=scanned['metadata']
        staging=self.work/'staging';staging.mkdir(mode=0o700)
        candidate=staging/('candidate-7-'+observed['inventorySha256'][:16])
        shutil.copytree(seed,candidate)
        for name,data in scanned['_files'].items():
            path=candidate/name;path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(data);path.chmod(int(observed['inventory'][name]['mode'],8)&0o777)
        version='1.0.0-preview.7';metadata=source._candidate_metadata(observed,version,7)
        path=candidate/source.CANDIDATE_PATH;path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes(source._canonical(metadata)+b'\n');path.chmod(0o644)
        source._git(candidate,['add','--all']);source._git(candidate,['commit','--quiet','-m','execute fixture candidate'])
        commit=source._git(candidate,['rev-parse','HEAD']).decode().strip()
        self.assertNotEqual(commit,subject.REJECTION_COMMIT)
        state_dir=self.work/'state';state_dir.mkdir(mode=0o700)
        state={'format':1,'base_revision':baseline,'counter':7,'observed':observed,'published':None,
            'desired':{'commit':commit,'candidatePath':str(candidate),'status':'prepared','pushed':False,
                'inventorySha256':observed['inventorySha256'],'version':version}}
        path=state_dir/'current.json';path.write_bytes(source._canonical(state));path.chmod(0o600)
        doctor.BASE=baseline;doc=doctor.__dict__
        doc['load_installed']=mock.Mock(return_value=(source,worker))
        retry={'private_doctor':mock.Mock(return_value=doc)}
        def private_loader(path,pin):
            if path=='reviewed-retry' and pin==subject.RETRY_PIN:return retry
            if path=='reviewed-ops' and pin==subject.OPS_PIN:return self.ops
            raise AssertionError('Unapproved private loader request')
        with mock.patch.multiple(subject,ROOT=self.root,WORK=self.work,BACKUPS=self.backups,
                CUSTODY=self.custody), \
                mock.patch.object(subject,'private_module',side_effect=private_loader) as loaders, \
                mock.patch.object(subject.socket,'gethostname',return_value='srv1836524'):
            report=subject.execute(['reviewed-retry','reviewed-doctor','reviewed-ops','--archive'])
        self.assertTrue(report['ok']);self.assertEqual(report['captured'],2)
        self.assertEqual(report['source_files_removed'],2);self.assertFalse(report['credentials_disclosed'])
        plan=json.loads((Path(report['capture_directory'])/'plan.json').read_text())
        self.assertEqual(plan['context']['bound_candidate_commit'],commit)
        self.assertEqual(plan['context']['rejection_commit'],subject.REJECTION_COMMIT)
        self.assertEqual(plan['context']['commit'],subject.EXPECTED_COMMIT)
        loaders.assert_has_calls([mock.call('reviewed-retry',subject.RETRY_PIN),
            mock.call('reviewed-ops',subject.OPS_PIN)])
        retry['private_doctor'].assert_called_once_with('reviewed-doctor')
        doc['load_installed'].assert_called_once_with(doc['INSTALL'])
        final=source.scan_snapshot(self.root,seed,1001,observed)['metadata']
        self.assertEqual(final['removedFiles'],sorted(self.payloads))
        for name,data in self.payloads.items():
            self.assertFalse((self.root/name).exists());self.assertEqual((candidate/name).read_bytes(),data)
            self.assertEqual(final['inventory'][name]['authority'],'removed')
        for name,row in observed['inventory'].items():
            if name not in self.payloads:self.assertEqual(final['inventory'][name],row)
        self.assertNotIn('PRIVATE_MAIL_DIAGNOSTIC',json.dumps(report));self.assert_live_unchanged()

if __name__=='__main__':unittest.main()

