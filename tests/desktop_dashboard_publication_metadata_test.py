#!/usr/bin/env python3
"""Real-root tests for the exact38-file metadata repair and concurrent failures."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'deployment'))
spec=importlib.util.spec_from_file_location('publication_metadata_subject',ROOT/'deployment/repair_desktop_dashboard_publication_metadata.py')
subject=importlib.util.module_from_spec(spec);spec.loader.exec_module(subject)
common=subject.common
SCOPE=json.loads((ROOT/'deployment/desktop_dashboard_publication_metadata_scope.json').read_text())
UID=60001;GID=60002;PEER=b'<?php /* concurrent peer bytes are retained */\n'

def real_owners_available():
    if os.geteuid()!=0:return False
    with tempfile.TemporaryDirectory(prefix='metadata-owner-capability-')as folder:
        path=Path(folder)/'probe';path.write_bytes(b'capability')
        try:os.chown(path,UID,GID)
        except OSError:return False
        return(path.stat().st_uid,path.stat().st_gid)==(UID,GID)

REAL_OWNERS=real_owners_available()
if '--require-real-owners'in sys.argv:
    sys.argv.remove('--require-real-owners')
    if not REAL_OWNERS:
        print('REAL_OWNER_FIXTURES_REQUIRED_BUT_UID_GID_MAPPING_UNAVAILABLE',file=sys.stderr);sys.exit(2)

def facts(path):
    value=path.stat()
    return(value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,value.st_nlink,value.st_size,value.st_mtime_ns)

class Fixture(unittest.TestCase):
    uid=UID;gid=GID
    def setUp(self):
        self.folder=Path(tempfile.mkdtemp(prefix='metadata-publication-fault-'));self.addCleanup(shutil.rmtree,self.folder)
        self.root=self.folder/'public_html';self.root.mkdir();self.journals=self.folder/'journals';self.journals.mkdir(mode=0o700)
        self.rows=copy.deepcopy(SCOPE['rows']);self.files={};self.data={}
        for row in self.rows:
            for key in ['before','after']:
                row[key][0]=self.uid if row[key][0]==1001 else row[key][0]
                row[key][1]=self.gid if row[key][1]==1003 else row[key][1]
            path=self.root/row['path'];path.parent.mkdir(parents=True,exist_ok=True)
            data=('<?php /* reviewed fixture '+row['path']+' */\n').encode();path.write_bytes(data)
            row['blob']=common.blob(data);self.files[row['path']]=path;self.data[row['path']]=data
        self.untouched=self.root/'.env';self.untouched.write_bytes(b'DESKTOP_DASHBOARD_ENABLED=false\n');self.untouched.chmod(0o600)
        for path in [self.root,*self.root.rglob('*')]:os.chown(path,self.uid,self.gid)
        for row in self.rows:
            path=self.files[row['path']];os.chown(path,row['before'][0],row['before'][1]);path.chmod(int(row['before'][2],8))
    def repair(self,**kwargs):return subject.repair(self.root,self.uid,self.gid,self.rows,self.journals,**kwargs)
    def states(self):return{key:common.identity(path.stat())for key,path in self.files.items()}
    def set_target(self,row):
        path=self.files[row['path']];os.chown(path,row['after'][0],row['after'][1]);path.chmod(int(row['after'][2],8))
    def stopped(self):return json.loads(next(self.journals.glob('*/stopped.json')).read_text())
    def assert_all_unchanged(self,before):self.assertEqual(self.states(),before)

class MetadataCases:
    def test_full_scope_preserves_bytes_inodes_execute_bits_and_unreviewed_files(self):
        before={key:facts(path)for key,path in self.files.items()};outsider=common.identity(self.untouched.stat())
        planned=sum(subject.metadata(path.stat())!=subject.expected(row,'after')for row in self.rows for path in [self.files[row['path']]])
        journal,records=self.repair();self.assertEqual(len(records),planned)
        for row in self.rows:
            path=self.files[row['path']];after=facts(path);old=before[row['path']]
            self.assertEqual(subject.metadata(path.stat()),subject.expected(row,'after'))
            self.assertEqual(after[:2],old[:2]);self.assertEqual(after[5:],old[5:]);self.assertEqual(after[2]&0o111,old[2]&0o111)
            self.assertEqual(path.read_bytes(),self.data[row['path']])
        self.assertEqual(common.identity(self.untouched.stat()),outsider);self.assertEqual(stat.S_IMODE(journal.stat().st_mode),0o700)
        for name in ['before.json','after.json']:self.assertEqual(stat.S_IMODE((journal/name).stat().st_mode),0o600)
    def test_repeated_scope_is_idempotent_without_another_journal(self):
        self.repair();before=self.states();count=len(list(self.journals.iterdir()))
        self.assertEqual(self.repair(),(None,[]));self.assert_all_unchanged(before);self.assertEqual(len(list(self.journals.iterdir())),count)
    def test_already_fixed_subset_is_not_changed_again(self):
        fixed=self.rows[::3]
        for row in fixed:self.set_target(row)
        before={row['path']:common.identity(self.files[row['path']].stat())for row in fixed}
        _,records=self.repair();changed={row['path']for row in records}
        for name,state in before.items():self.assertNotIn(name,changed);self.assertEqual(common.identity(self.files[name].stat()),state)
    def test_last_wrong_blob_rejects_all_before_any_mutation(self):
        self.files[self.rows[-1]['path']].write_bytes(PEER);before=self.states()
        with self.assertRaisesRegex(common.ReviewRequired,'SOURCE_CHANGED'):self.repair()
        self.assert_all_unchanged(before);self.assertEqual(list(self.journals.iterdir()),[])
    def test_wrong_current_mode_rejects_all_before_any_mutation(self):
        self.files[self.rows[-1]['path']].chmod(0o777);before=self.states()
        with self.assertRaisesRegex(common.ReviewRequired,'UNREVIEWED_METADATA'):self.repair()
        self.assert_all_unchanged(before);self.assertEqual(list(self.journals.iterdir()),[])
    def test_hardlink_is_rejected_without_changing_any_alias(self):
        path=self.files[self.rows[-1]['path']];alias=self.folder/'source-alias';os.link(path,alias);before=self.states()
        with self.assertRaisesRegex(common.ReviewRequired,'FILE_UNSAFE'):self.repair()
        self.assert_all_unchanged(before);self.assertEqual(facts(alias),facts(path))
    def test_special_mode_and_missing_owner_read_are_rejected_before_mutation(self):
        path=self.files[self.rows[-1]['path']]
        for mode in [0o4775,0o1775,0o375]:
            with self.subTest(mode=oct(mode)):
                path.chmod(mode);before=self.states()
                # A constrained root namespace may deny opening mode0375
                # before the explicit owner-read guard; both stop unchanged.
                with self.assertRaisesRegex(common.ReviewRequired,'FILE_UNSAFE|METADATA_REPAIR_STOPPED'):self.repair()
                self.assert_all_unchanged(before)
        self.assertEqual(list(self.journals.iterdir()),[])
    def test_invalid_scope_duplicate_traversal_or_execute_change_rejects_before_mutation(self):
        original=copy.deepcopy(self.rows)
        for variant in ['duplicate','traversal','execute']:
            with self.subTest(variant=variant):
                self.rows=copy.deepcopy(original)
                if variant=='duplicate':self.rows.append(copy.deepcopy(self.rows[-1]))
                elif variant=='traversal':self.rows[-1]['path']='app/../../outside.php'
                else:self.rows[-1]['after'][2]='0754'
                before=self.states()
                with self.assertRaisesRegex(common.ReviewRequired,'SCOPE_INVALID'):self.repair()
                self.assert_all_unchanged(before);self.assertEqual(list(self.journals.iterdir()),[])
        self.rows=original
    def test_before_change_peer_bytes_are_retained_without_any_metadata_changes(self):
        path=self.files[self.rows[-1]['path']];expected={}
        def peer():path.write_bytes(PEER);expected.update(self.states())
        with self.assertRaisesRegex(common.ReviewRequired,'CONCURRENT_CHANGE|SOURCE_CHANGED'):self.repair(before_change=peer)
        self.assert_all_unchanged(expected);self.assertEqual(path.read_bytes(),PEER)
    def test_unrelated_ancestor_directory_activity_does_not_block_reviewed_repair(self):
        sibling=self.folder/'unrelated-peer-state';before={key:facts(path)[:2]for key,path in self.files.items()}
        def peer():sibling.mkdir(mode=0o700);(sibling/'note').write_bytes(PEER)
        self.repair(before_change=peer)
        for row in self.rows:
            path=self.files[row['path']];self.assertEqual(facts(path)[:2],before[row['path']])
            self.assertEqual(subject.metadata(path.stat()),subject.expected(row,'after'));self.assertEqual(path.read_bytes(),self.data[row['path']])
        self.assertEqual((sibling/'note').read_bytes(),PEER);self.assertEqual(stat.S_IMODE(sibling.stat().st_mode),0o700)
    def test_midbatch_failure_restores_every_completed_metadata_change(self):
        before={key:facts(path)for key,path in self.files.items()}
        def fail(index):
            if index==2:raise RuntimeError('injected midbatch failure')
        with self.assertRaisesRegex(common.ReviewRequired,'METADATA_REPAIR_STOPPED'):self.repair(after_row=fail)
        self.assertEqual({key:facts(path)for key,path in self.files.items()},before);self.assertTrue(self.stopped()['metadata_restored_or_not_started'])
    def test_after_change_peer_bytes_are_retained_while_other_metadata_recovers(self):
        path=self.files[self.rows[-1]['path']]
        def peer():path.write_bytes(PEER)
        with self.assertRaisesRegex(common.ReviewRequired,'SOURCE_CHANGED'):self.repair(after_change=peer)
        self.assertEqual(path.read_bytes(),PEER);self.assertEqual(subject.metadata(path.stat()),subject.expected(self.rows[-1],'after'))
        self.assertFalse(self.stopped()['metadata_restored_or_not_started'])
    def test_detached_replacement_is_retained_and_old_inode_metadata_is_not_reset(self):
        row=self.rows[0]if self.uid else self.rows[2];path=self.files[row['path']];moved=path.with_name(path.name+'.peer-original');peer_facts=[]
        def peer():
            path.rename(moved);path.write_bytes(PEER);path.chmod(0o644);peer_facts.append(facts(path))
        with self.assertRaisesRegex(common.ReviewRequired,'CONCURRENT_CHANGE|SOURCE_CHANGED'):self.repair(after_change=peer)
        self.assertEqual(path.read_bytes(),PEER);self.assertEqual(facts(path),peer_facts[0])
        self.assertEqual(subject.metadata(moved.stat()),subject.expected(row,'after'));self.assertFalse(self.stopped()['metadata_restored_or_not_started'])
    def test_concurrent_chmod_before_poststat_is_retained_and_recovery_false(self):
        row=next(row for row in self.rows if row['before'][2]=='0775');path=self.files[row['path']];inode=path.stat().st_ino;real_fchmod=os.fchmod;injected=False
        def peer(fd,mode):
            nonlocal injected
            result=real_fchmod(fd,mode)
            if os.fstat(fd).st_ino==inode and mode==0o755 and not injected:injected=True;real_fchmod(fd,0o700)
            return result
        with patch.object(subject.os,'fchmod',side_effect=peer):
            with self.assertRaisesRegex(common.ReviewRequired,'CONCURRENT_CHANGE'):self.repair()
        self.assertTrue(injected);self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o700);self.assertEqual(path.read_bytes(),self.data[row['path']])
        self.assertFalse(self.stopped()['metadata_restored_or_not_started'])
    def test_one_rollback_chmod_failure_does_not_prevent_other_restores(self):
        row=next(row for row in self.rows if row['before'][2]=='0775');path=self.files[row['path']];inode=path.stat().st_ino;real_fchmod=os.fchmod;injected=False
        def fail_once(fd,mode):
            nonlocal injected
            if os.fstat(fd).st_ino==inode and mode==0o775 and not injected:injected=True;raise OSError('injected rollback failure')
            return real_fchmod(fd,mode)
        def fail():raise RuntimeError('force full rollback')
        with patch.object(subject.os,'fchmod',side_effect=fail_once):
            with self.assertRaisesRegex(common.ReviewRequired,'METADATA_REPAIR_STOPPED'):self.repair(after_change=fail)
        self.assertTrue(injected);self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o755)
        for other in self.rows:
            if other['path']!=row['path']:self.assertEqual(subject.metadata(self.files[other['path']].stat()),subject.expected(other,'before'))
        self.assertFalse(self.stopped()['metadata_restored_or_not_started'])
    def test_safe0755_journal_parent_keeps_private_child_and_records(self):
        self.journals.chmod(0o755);journal,_=self.repair()
        self.assertEqual(stat.S_IMODE(self.journals.stat().st_mode),0o755);self.assertEqual(stat.S_IMODE(journal.stat().st_mode),0o700)
        for name in ['before.json','after.json']:self.assertEqual(stat.S_IMODE((journal/name).stat().st_mode),0o600)

@unittest.skipUnless(REAL_OWNERS,'Real root ownership fixtures require mapped UID60001/GID60002')
class RealOwnerMetadataTests(MetadataCases,Fixture):
    def test_wrong_current_uid_or_gid_rejects_before_any_mutation(self):
        path=self.files[self.rows[-1]['path']]
        for uid,gid in [(0,0),(self.uid,0)]:
            with self.subTest(uid=uid,gid=gid):
                os.chown(path,uid,gid);before=self.states()
                with self.assertRaisesRegex(common.ReviewRequired,'UNREVIEWED_METADATA'):self.repair()
                self.assert_all_unchanged(before);self.assertEqual(list(self.journals.iterdir()),[])
    def test_leaf_symlink_does_not_touch_its_destination(self):
        path=self.files[self.rows[-1]['path']];outside=self.folder/'outside.php';outside.write_bytes(PEER);before=common.identity(outside.stat())
        path.unlink();path.symlink_to(outside)
        with self.assertRaises(common.ReviewRequired):self.repair()
        self.assertTrue(path.is_symlink());self.assertEqual(common.identity(outside.stat()),before);self.assertEqual(list(self.journals.iterdir()),[])
    def test_symlinked_parent_prevents_any_repair(self):
        path=self.files[self.rows[-1]['path']];parent=path.parent;moved=parent.with_name(parent.name+'-peer-original');parent.rename(moved);parent.symlink_to(moved,target_is_directory=True)
        before=self.states()
        with self.assertRaises(common.ReviewRequired):self.repair()
        self.assert_all_unchanged(before);self.assertTrue(parent.is_symlink());self.assertEqual(list(self.journals.iterdir()),[])
    def test_fifo_is_rejected_without_blocking_or_mutating_other_files(self):
        path=self.files[self.rows[-1]['path']];path.unlink();os.mkfifo(path,0o775);os.chown(path,self.uid,self.gid);path.chmod(0o775);before=self.states()
        with self.assertRaisesRegex(common.ReviewRequired,'FILE_UNSAFE'):self.repair()
        self.assert_all_unchanged(before);self.assertTrue(stat.S_ISFIFO(path.stat().st_mode));self.assertEqual(list(self.journals.iterdir()),[])
    def test_peer_change_to_already_fixed_file_is_detected_before_first_change(self):
        row=self.rows[-1];self.set_target(row);path=self.files[row['path']];expected={}
        def peer():path.chmod(0o700);expected.update(self.states())
        with self.assertRaisesRegex(common.ReviewRequired,'CONCURRENT_CHANGE'):self.repair(before_change=peer)
        self.assert_all_unchanged(expected);self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o700)

@unittest.skipUnless(os.geteuid()==0,'Real chmod policy checks require root')
class LocalModeMetadataTests(MetadataCases,Fixture):
    # The local namespace supports real chmod syscalls with UID/GID0. The
    # separate required CI class proves all four actual owner/group transitions.
    uid=0;gid=0

class ScopeEvidenceTests(unittest.TestCase):
    def test_release_must_be_one_full_immutable_commit_argument(self):
        pin='1'*40
        self.assertEqual(subject.release_argument(['repair.py','--release',pin]),pin)
        for arguments in [['repair.py'],['repair.py','--release'],['repair.py','--release','HEAD'],
                          ['repair.py','--release','A'*40],['repair.py','--release',pin,'extra'],
                          ['repair.py','--other',pin]]:
            with self.subTest(arguments=arguments),self.assertRaisesRegex(common.ReviewRequired,'REQUIRES_PINNED_RELEASE'):
                subject.release_argument(arguments)

    def test_scope_binds_exact_manifest_without_embedding_future_release(self):
        self.assertEqual(SCOPE['version'],2);self.assertNotIn('release',SCOPE)
        raw=(ROOT/'deployment/desktop_dashboard_server_manifest-20261019-1323.json').read_bytes()
        self.assertEqual(common.blob(raw),subject.MANIFEST_BLOB)
        self.assertEqual(SCOPE['manifest_blob'],subject.MANIFEST_BLOB)

    def test_old_metadata_scope_cannot_authorize_the_current_production_capture(self):
        raw=(ROOT/'deployment/desktop_dashboard_server_manifest.json').read_bytes()
        self.assertNotEqual(common.blob(raw),subject.MANIFEST_BLOB)

    def test_all38_rows_are_exact_manifest_before_blobs_with_four_owner_and34_mode_changes(self):
        raw=(ROOT/'deployment/desktop_dashboard_publication_metadata_scope.json').read_bytes();self.assertEqual(common.blob(raw),subject.SCOPE_BLOB)
        manifest=json.loads((ROOT/'deployment/desktop_dashboard_server_manifest-20261019-1323.json').read_text());by_path={row['path']:row for row in manifest['sources']}
        self.assertEqual(len(SCOPE['rows']),38);owners=modes=0
        for row in SCOPE['rows']:
            self.assertEqual(row['blob'],by_path[row['path']]['before_blob']);self.assertEqual(by_path[row['path']]['phase'],2)
            self.assertEqual(row['after'][:2],[1001,1003]);self.assertEqual(int(row['before'][2],8)&~0o022,int(row['after'][2],8))
            if row['before'][:2]==[0,0]:owners+=1
            else:self.assertEqual(row['before'],[1001,1003,'0775']);modes+=1
        self.assertEqual((owners,modes),(4,34))

if __name__=='__main__':unittest.main()
