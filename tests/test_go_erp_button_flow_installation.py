"""Pin42 to genuine native history and bytes without changing native release state."""
import copy
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);value=importlib.util.module_from_spec(spec)
    sys.modules[name]=value;spec.loader.exec_module(value);return value
subject=load('button_flow_subject',ROOT/'deployment/apply_go_erp_button_flow_root.py')


class ButtonFlowTests(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory(prefix='button-flow-');self.addCleanup(temporary.cleanup)
        self.work=Path(temporary.name);self.root=self.work/'app';self.root.mkdir(mode=0o755)
        self.native=self.work/'native';(self.native/'state').mkdir(mode=0o700,parents=True);self.native.chmod(0o700)
        self.control=self.work/'control';self.owner=types.SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid(),pw_name='fixture')
        self.e=load('button_file_engine_'+self.work.name,ROOT/'deployment/apply_go_erp_manual_updates_root.py');self.e.Stop=subject.Stop
        self.e.provision(self.owner,self.control);self.shared=self.control/'shared'
        self.e.directory(self.shared/'installers',0,self.owner.pw_gid,0o750)
        self.archive=load('button_archive_'+self.work.name,ROOT/'deployment/go_erp_published_download_root.py');self.archive.Stop=subject.Stop
        self.publisher=load('button_publisher_'+self.work.name,ROOT/'deployment/publish_go_erp_update.py')
        self.archive.load_publisher=lambda:self.publisher
        self.binary=b'MZ genuine accepted preview42 fixture\n';self.old_binary=b'MZ genuine previous35 fixture\n'
        self.old_js=b'old button source\n';self.new_js=b'new button source\n'
        for name,value in {'INSTALLER':subject.sha(self.binary),'BYTES':len(self.binary),
                'BEFORE':subject.sha(self.old_js),'AFTER':subject.sha(self.new_js)}.items():
            scope=patch.object(subject,name,value);scope.start();self.addCleanup(scope.stop)
        self.current=self.release('0.4.4-preview.42',subject.SEQUENCE,subject.COMMIT,self.binary)
        self.previous=self.release('0.4.4-preview.35',subject.SEQUENCE-10,'b'*40,self.old_binary)
        self.state={'format':1,'base_revision':'c'*40,'counter':44,'version_floor':'0.4.4-preview.42',
            'sequence_floor':subject.SEQUENCE,'published_history':[copy.deepcopy(self.current)],
            'published':{'release':copy.deepcopy(self.current),'commit':subject.COMMIT},
            'desired':{'version':'0.4.4-preview.44','status':'waiting-ci'},'private_fixture':'retain byte for byte'}
        self.state_path=self.native/'state/current.json';self.e.atomic_write(self.state_path,self.e.canonical(self.state),0,0,0o600)
        self.state_before=self.state_path.read_bytes()
        self.manifest_path=self.shared/'downloads.json'
        self.pointer(self.current,self.previous)
        self.target=self.shared/'installers'/(subject.INSTALLER+'.exe');self.put(self.target,self.binary,0o640)
        self.old_target=self.shared/'installers'/(subject.sha(self.old_binary)+'.exe');self.put(self.old_target,self.old_binary,0o640)
        self.canonical=self.root/'storage/app/desktop-updates'/(subject.INSTALLER+'.exe')
        self.canonical.parent.mkdir(parents=True,mode=0o755);self.put(self.canonical,self.binary,0o600)
        self.js=self.root/subject.JS;self.js.parent.mkdir(mode=0o755,parents=True,exist_ok=True);self.put(self.js,self.old_js,0o644)
        os.utime(self.js,ns=(1234567890000000000,1234567890000000000))
    def release(self,version,sequence,commit,raw):
        digest=subject.sha(raw)
        return {'version':version,'sequence':sequence,'source_revision':commit,'source':copy.deepcopy(subject.SOURCE),
            'gui':{},'installer':{'sha256':digest,'bytes':len(raw),'path':'/api/desktop-dashboard/update/installer/'+digest}}
    def put(self,path,raw,mode):
        self.e.atomic_write(path,raw,0,self.owner.pw_gid,mode)
    def pointer(self,current,previous):
        self.e.atomic_write(self.manifest_path,self.archive.canonical({'format':1,'kind':'go-erp-published-downloads',
            'current':current,'previous':previous}),0,self.owner.pw_gid,0o640)
    def perform(self):
        return subject.perform(self.e,self.archive,self.owner,self.new_js,'a'*40,self.root,self.native,self.control)
    def assert_native_unchanged(self):self.assertEqual(self.state_path.read_bytes(),self.state_before)
    def test_already_correct42_is_verified_unchanged_without_claiming_binary_repair(self):
        result=self.perform();proof=result['verified42']
        self.assertFalse(proof['binary_repaired']);self.assertFalse(proof['manifest_repaired'])
        self.assertEqual(proof['installer_sha256'],subject.sha(self.binary));self.assertTrue(result['source_changed'])
        self.assertEqual(self.js.read_bytes(),self.new_js);self.assertGreater(self.js.stat().st_mtime_ns,1234567890000000000)
        self.assert_native_unchanged()
    def test_wrong42_pointer_and_wrong_named_binary_are_repaired_from_verified_canonical_bytes(self):
        wrong=copy.deepcopy(self.previous);wrong['version']=subject.VERSION;wrong['sequence']=subject.SEQUENCE
        self.pointer(wrong,self.previous);self.put(self.target,b'MZ wrong35 bytes under42 name',0o640)
        result=self.perform()['verified42']
        self.assertTrue(result['binary_repaired']);self.assertTrue(result['manifest_repaired'])
        self.assertEqual(self.target.read_bytes(),self.binary)
        pointer=json.loads(self.manifest_path.read_bytes());self.assertEqual(pointer['current'],self.current)
        self.assertEqual(pointer['previous'],self.previous);self.assertEqual(self.old_target.read_bytes(),self.old_binary)
        self.assert_native_unchanged()
    def test_newer_current45_is_preserved_while_only_previous42_is_repaired(self):
        raw=b'MZ newest45 binary\n';new=self.release('0.4.4-preview.45',subject.SEQUENCE+20,'d'*40,raw)
        self.put(self.shared/'installers'/(subject.sha(raw)+'.exe'),raw,0o640)
        wrong=copy.deepcopy(self.previous);wrong['version']=subject.VERSION
        self.pointer(new,wrong);proof=self.perform()['verified42']
        pointer=json.loads(self.manifest_path.read_bytes());self.assertEqual(pointer['current'],new)
        self.assertEqual(pointer['previous'],self.current);self.assertEqual(proof['current_download_version'],new['version'])
        self.assert_native_unchanged()
    def test_42_not_in_either_slot_never_relabels_another_generation(self):
        raw=b'MZ newest45 binary\n';new=self.release('0.4.4-preview.45',subject.SEQUENCE+20,'d'*40,raw)
        self.put(self.shared/'installers'/(subject.sha(raw)+'.exe'),raw,0o640);self.pointer(new,self.previous)
        before=self.manifest_path.read_bytes();proof=self.perform()['verified42']
        self.assertFalse(proof['in_download_slots']);self.assertFalse(proof['manifest_repaired']);self.assertFalse(proof['verified'])
        self.assertEqual(self.manifest_path.read_bytes(),before);self.assert_native_unchanged()
    def test_42_no_longer_offered_does_not_require_pruned_history_or_old_binary(self):
        raw=b'MZ newest45 binary\n';new=self.release('0.4.4-preview.45',subject.SEQUENCE+20,'d'*40,raw)
        self.put(self.shared/'installers'/(subject.sha(raw)+'.exe'),raw,0o640);self.pointer(new,self.previous)
        self.target.unlink();self.canonical.unlink()
        changed=copy.deepcopy(self.state);changed['published_history']=[];changed['published']={'release':new}
        self.put(self.state_path,self.e.canonical(changed),0o600);before=self.state_path.read_bytes()
        proof=self.perform()['verified42']
        self.assertFalse(proof['verified']);self.assertIsNone(proof['installer_sha256'])
        self.assertEqual(proof['current_download_version'],'0.4.4-preview.45')
        self.assertEqual(self.state_path.read_bytes(),before);self.assertFalse(self.target.exists())
    def test_source42missing_fails_before_js_commit(self):
        self.target.unlink();self.canonical.unlink()
        with self.assertRaisesRegex(subject.Stop,'SOURCE42MISSING'):self.perform()
        self.assertEqual(self.js.read_bytes(),self.old_js);self.assert_native_unchanged()
    def test_incomplete_retained_ci_folder_does_not_hide_later_genuine42_source(self):
        self.target.unlink();self.canonical.unlink();parent=self.native/'program-acceptance';parent.mkdir(mode=0o700)
        (parent/(subject.COMMIT+'-a')).mkdir(mode=0o700)
        good=parent/(subject.COMMIT+'-z');(good/'ci').mkdir(mode=0o700,parents=True);good.chmod(0o700)
        self.put(good/'ci/GO-ERP-Preview-Setup.exe',self.binary,0o600)
        result=self.perform()['verified42'];self.assertEqual(result['source_kind'],'accepted-ci')
        self.assertEqual(self.target.read_bytes(),self.binary);self.assert_native_unchanged()
    def test_source_symlink_or_hardlink_is_never_followed_or_served(self):
        self.target.unlink();self.canonical.unlink();outside=self.work/'outside.exe';self.put(outside,self.binary,0o600)
        self.canonical.symlink_to(outside)
        with self.assertRaisesRegex(subject.Stop,'INSTALLER_SOURCE_UNSAFE'):self.perform()
        self.canonical.unlink();os.link(outside,self.canonical)
        with self.assertRaisesRegex(subject.Stop,'INSTALLER_SOURCE_UNSAFE'):self.perform()
        self.assertEqual(self.js.read_bytes(),self.old_js)
    def test_known42_canonical_release_must_come_from_real_native_history(self):
        changed=copy.deepcopy(self.state);changed['published_history']=[];changed['published']=None
        self.put(self.state_path,self.e.canonical(changed),0o600)
        with self.assertRaisesRegex(subject.Stop,'GENUINE42_RELEASE_NOT_PROVEN'):self.perform()
        self.assertEqual(self.js.read_bytes(),self.old_js)
    def test_conflicting_pair_or_missing_other_installer_cannot_claim_download_ready(self):
        bad=copy.deepcopy(self.previous);bad['source_revision']=self.current['source_revision'];self.pointer(self.current,bad)
        with self.assertRaisesRegex(subject.Stop,'DOWNLOAD_PAIR_IDENTITY_CONFLICT'):self.perform()
        self.pointer(self.current,self.previous);self.old_target.unlink()
        with self.assertRaises(FileNotFoundError):self.perform()
        self.assertEqual(self.js.read_bytes(),self.old_js)
    def test_js_write_failure_restores_exact_bytes_and_mtime_but_retains_verified42_repair(self):
        self.put(self.target,b'MZ wrong bytes',0o640);original=self.e.atomic_write;raised=False
        stamp=self.js.stat().st_mtime_ns
        def write(path,raw,*args,**kwargs):
            nonlocal raised
            original(path,raw,*args,**kwargs)
            if path==self.js and raw==self.new_js and not raised:raised=True;raise OSError('after rename')
        with patch.object(self.e,'atomic_write',side_effect=write),self.assertRaises(OSError):self.perform()
        self.assertEqual(self.js.read_bytes(),self.old_js);self.assertEqual(self.js.stat().st_mtime_ns,stamp)
        self.assertEqual(self.target.read_bytes(),self.binary);self.assert_native_unchanged()
    def test_rollback_io_failure_is_a_review_condition(self):
        original=self.e.atomic_write
        def write(path,raw,*args,**kwargs):
            if path==self.js:
                if raw==self.new_js:original(path,raw,*args,**kwargs)
                raise OSError('write or restore failed')
            return original(path,raw,*args,**kwargs)
        with patch.object(self.e,'atomic_write',side_effect=write),self.assertRaisesRegex(subject.Stop,'JS_ROLLBACK_REQUIRES_REVIEW'):
            self.perform()
    def test_repeat_is_idempotent_and_keeps_existing_state_and_archive_bytes(self):
        self.perform();pointer=self.manifest_path.read_bytes();stamp=self.js.stat().st_mtime_ns
        result=self.perform();self.assertFalse(result['source_changed']);self.assertFalse(result['verified42']['manifest_repaired'])
        self.assertEqual(self.manifest_path.read_bytes(),pointer);self.assertEqual(self.js.stat().st_mtime_ns,stamp)
        self.assert_native_unchanged()
    def test_path_activation_releases_worker_and_native_locks_and_changes_no_timer_or_service(self):
        active=True;commands=[]
        def runner(args):
            nonlocal active
            commands.append(tuple(args))
            if args[:3]==['/usr/bin/systemctl','stop',subject.WATCH]:active=False
            if args[:3]==['/usr/bin/systemctl','start',subject.WATCH]:
                for path in (self.control/'private/worker.lock',self.native/'watcher.lock'):
                    fd=os.open(path,os.O_RDWR)
                    try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
                    finally:os.close(fd)
                active=True
            if 'show' in args:
                if args[2]==subject.WATCH:return 'active' if active else 'inactive'
                return 'disabled' if 'UnitFileState' in args else 'inactive'
            return ''
        self.e.run=runner
        def perform(*args):
            self.assertFalse(active)
            for path in (self.control/'private/worker.lock',self.native/'watcher.lock'):
                fd=os.open(path,os.O_RDWR)
                try:
                    with self.assertRaises(BlockingIOError):fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
                finally:os.close(fd)
            return {'source_changed':True}
        with patch.object(subject,'CONTROL',self.control),patch.object(subject,'WORK',self.native),\
                patch.object(subject,'perform',side_effect=perform):
            subject.apply(self.e,self.archive,self.owner,self.new_js,'a'*40)
        self.assertTrue(active)
        mutations=[call[1:] for call in commands if call[1]!='show']
        self.assertEqual(mutations,[('stop',subject.WATCH),('start',subject.WATCH)])
        self.assert_native_unchanged()

    def test_unverified_js_rollback_keeps_manual_path_paused(self):
        active=True;commands=[]
        def runner(args):
            nonlocal active
            commands.append(tuple(args))
            if args[:3]==['/usr/bin/systemctl','stop',subject.WATCH]:active=False
            if 'show' in args:
                if args[2]==subject.WATCH:return 'active' if active else 'inactive'
                return 'disabled' if 'UnitFileState' in args else 'inactive'
            return ''
        self.e.run=runner
        with patch.object(subject,'CONTROL',self.control),patch.object(subject,'WORK',self.native),\
                patch.object(subject,'perform',side_effect=subject.Stop('JS_ROLLBACK_REQUIRES_REVIEW')),\
                self.assertRaisesRegex(subject.Stop,'JS_ROLLBACK_REQUIRES_REVIEW'):
            subject.apply(self.e,self.archive,self.owner,self.new_js,'a'*40)
        self.assertFalse(active);self.assertNotIn(('/usr/bin/systemctl','start',subject.WATCH),commands)

    def test_active_owner_job_is_refused_without_path_stop(self):
        commands=[]
        def runner(args):
            commands.append(tuple(args));return 'active' if args[2]==subject.SERVICE else 'inactive'
        self.e.run=runner
        with self.assertRaisesRegex(subject.Stop,'EXISTING_JOB_BUSY'):
            subject.apply(self.e,self.archive,self.owner,self.new_js,'a'*40)
        self.assertTrue(all('stop' not in call for call in commands));self.assertEqual(self.js.read_bytes(),self.old_js)

    def rollout(self,phase):
        path=self.control/'private'/('close-reopen-'+subject.NATIVE_CORE+'.json')
        value={'format':1,'kind':'go-erp-close-reopen-rollout','core':subject.NATIVE_CORE,
            'seed':subject.NATIVE_SEED,'phase':phase,'source':{'sha256':'old source proof must stay'}}
        self.put(path,self.e.canonical(value),0o600);return path,path.read_bytes()

    def test_unfinished_native_rollout_blocks_js_change_but_retains_independent42_repair(self):
        path,before=self.rollout('running');self.put(self.target,b'MZ wrong42 bytes',0o640)
        with self.assertRaisesRegex(subject.Stop,'FINISH_EXISTING_NATIVE_ROLLOUT_BEFORE_UI_CHANGE'):self.perform()
        self.assertEqual(self.js.read_bytes(),self.old_js);self.assertEqual(path.read_bytes(),before)
        self.assertEqual(self.target.read_bytes(),self.binary);self.assert_native_unchanged()

    def test_same_js_idempotent_reapply_is_allowed_with_an_unfinished_same_source_rollout(self):
        self.put(self.js,self.new_js,0o644);path,before=self.rollout('prepared')
        result=self.perform();self.assertFalse(result['source_changed']);self.assertEqual(path.read_bytes(),before)

    def test_completed_native_rollout_can_accept_later_js_source_change(self):
        path,before=self.rollout('ready');result=self.perform()
        self.assertTrue(result['source_changed']);self.assertEqual(path.read_bytes(),before)

    def test_real_js_payload_hash_is_pinned(self):
        fresh=load('real_button_pins',ROOT/'deployment/apply_go_erp_button_flow_root.py')
        self.assertEqual(fresh.sha((ROOT/fresh.JS).read_bytes()),fresh.AFTER)


if __name__=='__main__':unittest.main()
