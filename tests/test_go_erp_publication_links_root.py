"""Actual POSIX publication-alias finalization faults; never invokes production."""
import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

REPOSITORY = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('publication_links_subject', REPOSITORY/'deployment/finalize_go_erp_publication_links_root.py')
subject = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(subject)


class VirtualOwnership:
    """Explicit local UID0-only namespace adapter; CI demands real chown."""
    def __init__(self):
        self.values = {}
        self.stat, self.lstat, self.fstat = os.stat, os.lstat, os.fstat
    def view(self, value):
        pair = self.values.get((value.st_dev, value.st_ino))
        if pair is None:
            return value
        class View:
            st_uid, st_gid = pair
            def __getattr__(self, name):return getattr(value, name)
            def __getitem__(self, index):return pair[index-4] if index in (4,5) else value[index]
        return View()
    def chown(self, path, uid, gid, **kwargs):
        value = self.stat(path, **kwargs);self.values[(value.st_dev,value.st_ino)] = (uid,gid)
    def fchown(self, fd, uid, gid):
        value = self.fstat(fd);self.values[(value.st_dev,value.st_ino)] = (uid,gid)
    def patches(self):
        return [mock.patch.object(os,'stat',lambda *a,**k:self.view(self.stat(*a,**k))),
                mock.patch.object(os,'lstat',lambda *a,**k:self.view(self.lstat(*a,**k))),
                mock.patch.object(os,'fstat',lambda *a,**k:self.view(self.fstat(*a,**k))),
                mock.patch.object(os,'chown',self.chown),mock.patch.object(os,'fchown',self.fchown)]


def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()


@unittest.skipUnless(os.geteuid()==0,'Dedicated ownership filesystem fixture requires root.')
class PublicationLinkTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-publication-links-')
        self.addCleanup(self.temporary.cleanup);self.base=Path(self.temporary.name)
        uid_map=Path('/proc/self/uid_map').read_text().split()
        self.virtual=len(uid_map)==3 and uid_map[-1]=='1'
        if self.virtual:
            if os.environ.get('GO_ERP_TEST_REQUIRE_REAL_CHOWN')=='1':
                self.fail('CI requires real ownership; this namespace maps only UID0')
            adapter=VirtualOwnership()
            for patch in adapter.patches():patch.start();self.addCleanup(patch.stop)
        self.root=self.base/'app';self.root.mkdir()
        self.backup=self.base/'backups'/'server-abcdefgh';self.backup.mkdir(parents=True,mode=0o700)
        self.backup.parent.chmod(0o700)
        self.publications=self.backup/'publications';self.publications.mkdir(mode=0o700)
        self.audit=self.base/'audit'
        self.payloads={'app/NewOne.php':b'<?php /* immutable new one */\n',
                       'app/NewTwo.php':b'<?php /* immutable new two */\n'}
        self.rows=[];self.aliases={}
        for index,(name,data) in enumerate(self.payloads.items()):
            file=self.root/name;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(data);file.chmod(0o644)
            alias=self.publications/('published-'+str(index).zfill(8));os.link(file,alias)
            self.aliases[name]=alias
            self.rows.append({'path':name,'before_blob':None,'after_blob':blob(data),'phase':1})
        self.manifest={'version':1,'expected_head':'a'*40,
            'before_source':{'format':1,'files':1,'sha256':'b'*64,'framework':'8.83.29'},
            'after_source':{'format':1,'files':3,'sha256':'c'*64,'framework':'8.83.29'},
            'sources':self.rows,'protected':[],'migrations':[]}
        self.receipt={'version':1,'git_head':self.manifest['expected_head'],
            'before_source':self.manifest['before_source'],'after_source':self.manifest['after_source'],
            'manifest_blob':'da47b5e5dc50676d6046a6511b3905a0cd053ed1',
            'database':{'database_backup_sha256':'d'*64,'database_backup_bytes':23,'consistency':'innodb-consistent-snapshot'},
            'code_archive':{'sha256':'e'*64,'bytes':23},'files':{name:None for name in self.payloads}}
        for name in ('.env','bootstrap/cache/config.php','bootstrap/cache/routes-v7.php'):
            file=self.root/name;file.parent.mkdir(parents=True,exist_ok=True)
            file.write_bytes(b'PRIVATE_CHECKPOINT_MUST_REMAIN');file.chmod(0o600);item=file.stat()
            self.receipt['files'][name]={'blob':blob(file.read_bytes()),'uid':subject.UID,'gid':subject.GID,
                'mode':0o600,'device':item.st_dev,'inode':item.st_ino}
        self.activated={'source':self.manifest['after_source'],'authentication_checked':4,
                        'enabled':True,'local':False,'web_authentication_checked':4}
        for name,value in [('receipt.json',self.receipt),('activated.json',self.activated)]:
            file=self.backup/name;file.write_text(json.dumps(value));file.chmod(0o600)
        for name in ['code.tar','database.sql']:
            (self.backup/name).write_bytes(b'PRIVATE_BACKUP_MUST_REMAIN');(self.backup/name).chmod(0o600)
        self.unmatched=self.publications/'published-unmatched';self.unmatched.write_bytes(b'SECRET_ENV_NOT_TO_BE_READ');self.unmatched.chmod(0o600)
        self.lock=self.base/'backups'/'server-install.lock';self.lock.write_bytes(b'');self.lock.chmod(0o600)
        for tree in [self.root,self.base/'backups']:
            for folder,dirs,files in os.walk(tree):
                os.chown(folder,subject.UID,subject.GID)
                for file in files:os.chown(Path(folder)/file,subject.UID,subject.GID)
        self.baseline={name:(self.root/name).stat() for name in self.payloads}
        self.business={name:(self.backup/name).read_bytes() for name in ['code.tar','database.sql','receipt.json','activated.json']}

    def run_finalizer(self,requested=False,**options):
        return subject.finalize(self.root,self.backup,self.manifest,requested=requested,audit_parent=self.audit,**options)

    def assert_business_untouched(self):
        for name,data in self.business.items():self.assertEqual((self.backup/name).read_bytes(),data)
        self.assertEqual(self.unmatched.read_bytes(),b'SECRET_ENV_NOT_TO_BE_READ')

    def assert_live_intact(self,links=1):
        for name,data in self.payloads.items():
            file=self.root/name;before=self.baseline[name];after=file.stat()
            self.assertEqual(file.read_bytes(),data);self.assertEqual(after.st_nlink,links)
            for key in ['st_dev','st_ino','st_mode','st_uid','st_gid','st_size','st_mtime_ns']:
                self.assertEqual(getattr(after,key),getattr(before,key),key)

    def test_readonly_preflight_does_not_unlink_or_create_audit(self):
        report=self.run_finalizer()
        self.assertEqual(report['pending'],2);self.assertEqual(report['changed'],0)
        self.assertFalse(self.audit.exists());self.assert_live_intact(2);self.assert_business_untouched()

    def test_real_two_link_publication_becomes_one_without_changing_live_bytes(self):
        report=self.run_finalizer(True)
        self.assertEqual(report['changed'],2);self.assert_live_intact();self.assert_business_untouched()
        for alias in self.aliases.values():self.assertFalse(alias.exists())
        copies=list(Path(report['audit']).glob('copies/*.bin'))
        self.assertEqual(sorted(p.read_bytes() for p in copies),sorted(self.payloads.values()))
        for file in copies:
            self.assertEqual(file.stat().st_nlink,1);self.assertEqual(file.stat().st_uid,0)
            self.assertEqual(stat.S_IMODE(file.stat().st_mode),0o600)
        planned=Path(report['audit'])/'planned.json'
        self.assertTrue(planned.is_file());self.assertNotIn('SECRET_ENV_NOT_TO_BE_READ',planned.read_text())

    def test_idempotent_second_run_has_no_pending_aliases(self):
        self.run_finalizer(True)
        report=self.run_finalizer(True)
        self.assertEqual(report['pending'],0);self.assertEqual(report['changed'],0)
        self.assert_live_intact();self.assert_business_untouched()

    def test_every_live_target_is_preflighted_before_first_unlink(self):
        second=list(self.payloads)[1];os.link(self.root/second,self.base/'third-alias')
        with self.assertRaises(Exception):self.run_finalizer(True)
        for alias in self.aliases.values():self.assertTrue(alias.exists())
        self.assertEqual((self.root/list(self.payloads)[0]).stat().st_nlink,2)
        self.assert_business_untouched()

    def test_missing_known_private_alias_refuses_all_changes(self):
        second=list(self.payloads)[1];alias=self.aliases[second];alias.rename(self.base/'escaped-alias')
        with self.assertRaises(Exception):self.run_finalizer(True)
        self.assertTrue(self.aliases[list(self.payloads)[0]].exists());self.assert_business_untouched()

    def test_content_drift_before_plan_never_changes_any_alias(self):
        second=list(self.payloads)[1];(self.root/second).write_bytes(b'PEER_CONTENT_DRIFT')
        with self.assertRaises(Exception):self.run_finalizer(True)
        for alias in self.aliases.values():self.assertTrue(alias.exists())
        self.assert_business_untouched()

    def test_live_symlink_is_never_followed_or_unlinked(self):
        name=list(self.payloads)[0];file=self.root/name;file.unlink();file.symlink_to(self.unmatched)
        with self.assertRaises(Exception):self.run_finalizer(True)
        self.assertTrue(file.is_symlink());self.assertTrue(self.aliases[name].exists());self.assert_business_untouched()

    def test_alias_replacement_before_final_check_stops_without_deleting_foreign_path(self):
        changed=[]
        def race(row):
            if changed:return
            alias=self.aliases[row['path']];alias.rename(self.base/'held-original-alias')
            alias.symlink_to(self.unmatched);changed.append(alias)
        with self.assertRaises(Exception):self.run_finalizer(True,before_unlink=race)
        self.assertEqual(len(changed),1);self.assertTrue(changed[0].is_symlink())
        self.assertEqual((self.root/list(self.payloads)[0]).stat().st_nlink,2)
        self.assert_business_untouched()

    def test_raced_object_at_atomic_capture_is_retained_and_never_unlinked(self):
        original=os.rename;changed=[]
        def race(source,destination,*args,**kwargs):
            if not changed and str(source).startswith('published-'):
                changed.append(True)
                name=list(self.payloads)[0];alias=self.aliases[name]
                original(alias,self.base/'retained-original-alias')
                alias.write_bytes(b'FOREIGN_RACE_SENTINEL');alias.chmod(0o600)
                os.chown(alias,subject.UID,subject.GID)
            return original(source,destination,*args,**kwargs)
        with mock.patch.object(os,'rename',side_effect=race):
            with self.assertRaises(Exception):self.run_finalizer(True)
        self.assertEqual(changed,[True])
        captured=list((self.base/'backups').rglob('captured-*.bin'))
        self.assertTrue(any(p.read_bytes()==b'FOREIGN_RACE_SENTINEL' for p in captured))
        self.assertEqual((self.root/list(self.payloads)[0]).read_bytes(),self.payloads[list(self.payloads)[0]])
        self.assert_business_untouched()

    def test_failure_after_unlink_keeps_independent_copy_and_supports_partial_retry(self):
        def fail(row):raise RuntimeError('INJECTED_AFTER_UNLINK')
        with self.assertRaises(Exception):
            self.run_finalizer(True,after_unlink=fail)
        copies=list(self.audit.glob('*/copies/*.bin'))
        self.assertEqual(sorted(p.read_bytes() for p in copies),sorted(self.payloads.values()))
        self.assertEqual(sum((self.root/name).stat().st_nlink==1 for name in self.payloads),1)
        self.assertEqual(sum(alias.exists() for alias in self.aliases.values()),1)
        report=self.run_finalizer(True)
        self.assertEqual(report['changed'],1);self.assert_live_intact();self.assert_business_untouched()

    def test_independent_copies_exist_before_any_alias_unlink(self):
        calls=[]
        def check(row):
            copies=list(self.audit.glob('*/copies/*.bin'))
            self.assertEqual(sorted(p.read_bytes() for p in copies),sorted(self.payloads.values()))
            calls.append(row['path'])
        self.run_finalizer(True,before_unlink=check)
        self.assertEqual(set(calls),set(self.payloads));self.assert_business_untouched()

    def test_unmatched_publication_bytes_are_never_opened(self):
        original=os.open
        def protect(name,*args,**kwargs):
            if str(name)==self.unmatched.name:raise AssertionError('Unmatched backup bytes opened')
            return original(name,*args,**kwargs)
        with mock.patch.object(os,'open',side_effect=protect):self.run_finalizer(True)
        self.assert_business_untouched()

    def test_unsuccessful_or_wrong_manifest_backup_is_not_accepted(self):
        value=dict(self.activated);value['web_authentication_checked']=3
        (self.backup/'activated.json').write_text(json.dumps(value))
        with self.assertRaises(Exception):self.run_finalizer(True)
        for alias in self.aliases.values():self.assertTrue(alias.exists())

    def test_actual_setup_and_live_source_reader_reject_two_links_then_accept_finalized_files(self):
        source=(REPOSITORY/'deployment/install_go_erp_live_updates_root.sh').read_text()
        body=source.split("<<'PY'\n",1)[1].rsplit('\nPY',1)[0]
        setup={'__name__':'actual_setup_link_control'};exec(compile(body,'actual_setup_link_control','exec'),setup)
        setup['ROOT']=self.root;setup['FEED_PINS']={name:blob(data) for name,data in self.payloads.items()}
        spec=importlib.util.spec_from_file_location('actual_live_source_link_control',REPOSITORY/'deployment/go_erp_live_source.py')
        worker=importlib.util.module_from_spec(spec);spec.loader.exec_module(worker)
        def read_all():
            fd=os.open(self.root,os.O_RDONLY|os.O_DIRECTORY)
            try:
                return [worker._read_at(fd,name,web_uid=subject.UID)[0] for name in self.payloads]
            finally:os.close(fd)
        with mock.patch.object(setup['pwd'],'getpwnam',return_value=SimpleNamespace(pw_uid=subject.UID)):
            with self.assertRaises(setup['SetupError']):setup['require_installed_feed']()
            with self.assertRaises(worker.SourceError):read_all()
            self.run_finalizer(True)
            setup['require_installed_feed']()
            self.assertEqual(read_all(),list(self.payloads.values()))
        self.assert_business_untouched()

    def test_actual_162_new_source_manifest_and_unchanged_feed_pins(self):
        def local_git(arguments,maximum=2*1024*1024):
            value=subprocess.check_output(['/usr/bin/git','-C',str(REPOSITORY)]+list(arguments))
            self.assertLessEqual(len(value),maximum)
            return value
        def git_blob(pin):
            return local_git(['cat-file','blob',pin])
        self.manifest=json.loads(git_blob(subject.MANIFEST_BLOB))
        immutable_owner=git_blob(subject.OWNER_BLOB)
        self.assertEqual(blob(immutable_owner),'6ceff5c798411e42ca4d7673764eb72ded3ed2e6')
        owner={'__name__':'actual_immutable_link_owner_control'}
        exec(compile(immutable_owner,subject.OWNER,'exec'),owner)
        context=owner['reviewed_context'](local_git)
        self.assertEqual(context['manifest'],self.manifest)
        self.assertEqual(subject.B3,'75c3fe50b3609ef5822904a23d7c4ed0afd07eff')
        actual=[row for row in self.manifest['sources'] if row['before_blob'] is None]
        self.assertEqual(len(actual),162)
        for alias in self.aliases.values():alias.unlink()
        for name in self.payloads:(self.root/name).unlink()
        self.payloads={row['path']:git_blob(row['after_blob']) for row in actual}
        self.aliases={}
        for index,(name,data) in enumerate(self.payloads.items()):
            file=self.root/name;file.parent.mkdir(parents=True,exist_ok=True)
            file.write_bytes(data);file.chmod(0o644)
            alias=self.publications/('published-'+str(index).zfill(8));os.link(file,alias)
            self.aliases[name]=alias
        source=(REPOSITORY/'deployment/install_go_erp_live_updates_root.sh').read_text()
        body=source.split("<<'PY'\n",1)[1].rsplit('\nPY',1)[0]
        setup={'__name__':'actual_162_setup_control'};exec(compile(body,'actual_162_setup_control','exec'),setup)
        # Keep all original feed pins, including the existing routes/api.php.
        self.assertEqual(len(set(setup['FEED_PINS']) & set(self.payloads)),2)
        for name,pin in setup['FEED_PINS'].items():
            file=self.root/name
            if not file.exists():
                file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(git_blob(pin));file.chmod(0o644)
            self.assertEqual(blob(file.read_bytes()),pin)
        self.receipt.update(git_head=self.manifest['expected_head'],before_source=self.manifest['before_source'],
            after_source=self.manifest['after_source'])
        checkpoints={name:value for name,value in self.receipt['files'].items() if name.startswith('bootstrap/cache/') or name=='.env'}
        self.receipt['files']={row['path']:(None if row['before_blob'] is None else {'blob':row['before_blob']}) for row in self.manifest['sources']}
        self.receipt['files'].update(checkpoints)
        self.activated['source']=self.manifest['after_source']
        for name,value in [('receipt.json',self.receipt),('activated.json',self.activated)]:
            (self.backup/name).write_text(json.dumps(value))
        for folder,dirs,files in os.walk(self.root):
            os.chown(folder,subject.UID,subject.GID)
            for file in files:os.chown(Path(folder)/file,subject.UID,subject.GID)
        self.baseline={name:(self.root/name).stat() for name in self.payloads}
        self.business={name:(self.backup/name).read_bytes() for name in self.business}
        setup['ROOT']=self.root
        spec=importlib.util.spec_from_file_location('actual_162_worker_control',REPOSITORY/'deployment/go_erp_live_source.py')
        worker=importlib.util.module_from_spec(spec);spec.loader.exec_module(worker)
        def read_feeds():
            fd=os.open(self.root,os.O_RDONLY|os.O_DIRECTORY)
            try:return {name:worker._read_at(fd,name,web_uid=subject.UID)[0] for name in setup['FEED_PINS']}
            finally:os.close(fd)
        with mock.patch.object(setup['pwd'],'getpwnam',return_value=SimpleNamespace(pw_uid=subject.UID)):
            with self.assertRaises(setup['SetupError']):setup['require_installed_feed']()
            with self.assertRaises(worker.SourceError):read_feeds()
            self.assertEqual(self.run_finalizer()['pending'],162)
            report=self.run_finalizer(True)
            self.assertEqual(report['changed'],162);self.assert_live_intact()
            self.assertEqual(len(list((Path(report['audit'])/'copies').glob('*.bin'))),162)
            setup['require_installed_feed']()
            self.assertEqual({name:blob(data) for name,data in read_feeds().items()},setup['FEED_PINS'])
        self.assert_business_untouched()

if __name__=='__main__':unittest.main()
