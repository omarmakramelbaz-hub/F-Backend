#!/usr/bin/env python3
"""Real-root fault tests for the two-object ownership repair and read-only inventory."""
import importlib.util
import hashlib
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
spec=importlib.util.spec_from_file_location('reviewed_owner_repair',ROOT/'deployment/repair_desktop_dashboard_gostores_owner.py')
subject=importlib.util.module_from_spec(spec);spec.loader.exec_module(subject)
UID=60001
GID=60002
DATA=b'<?php /* exact reviewed Catalog fixture */\n'
PEER=b'<?php /* concurrent peer Catalog retained */\n'

def real_owners_available():
    if os.geteuid()!=0:return False
    with tempfile.TemporaryDirectory(prefix='desktop-owner-capability-')as folder:
        file=Path(folder)/'probe';file.write_bytes(b'owner capability only')
        try:os.chown(file,UID,GID)
        except OSError:return False
        return(file.stat().st_uid,file.stat().st_gid)==(UID,GID)

REAL_OWNERS=real_owners_available()
if '--require-real-owners'in sys.argv:
    sys.argv.remove('--require-real-owners')
    if not REAL_OWNERS:
        print('REAL_OWNER_FIXTURES_REQUIRED_BUT_UID_GID_MAPPING_UNAVAILABLE',file=sys.stderr)
        sys.exit(2)

def facts(path):
    value=path.lstat()
    return(value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,
           value.st_nlink,value.st_size,value.st_mtime_ns)

class Fixture(unittest.TestCase):
    def setUp(self):
        self.folder=Path(tempfile.mkdtemp(prefix='desktop-owner-fault-'))
        self.root=self.folder/'public_html';self.journals=self.folder/'journals'
        self.root.mkdir();self.journals.mkdir(mode=0o700)
        self.directory=self.root/subject.DIRECTORY;self.catalog=self.root/subject.CATALOG
        self.directory.mkdir(parents=True);self.directory.chmod(0o755)
        self.catalog.write_bytes(DATA);self.catalog.chmod(0o644)
        for relative in ['bootstrap/cache','storage/framework/views']:(self.root/relative).mkdir(parents=True)
        self.env=self.root/'.env';self.env.write_bytes(b'DESKTOP_DASHBOARD_ENABLED=false\n');self.env.chmod(0o600)
        self.previous_blob=subject.CATALOG_BLOB;subject.CATALOG_BLOB=subject.blob(DATA)
        self.addCleanup(setattr,subject,'CATALOG_BLOB',self.previous_blob)
        self.addCleanup(shutil.rmtree,self.folder)

@unittest.skipUnless(REAL_OWNERS,'Real root ownership fixtures require mapped UID60001/GID60002')
class OwnershipRepairTests(Fixture):
    def setUp(self):
        super().setUp()
        for path in [self.root,*self.root.rglob('*')]:os.chown(path,UID,GID)
        os.chown(self.directory,0,0);os.chown(self.catalog,0,0)
        self.sibling=self.directory/'Untouched.php';self.sibling.write_bytes(b'<?php /* root-owned sibling is untouched */\n')
        self.sibling.chmod(0o644);os.chown(self.sibling,0,0)
    def repair(self,**kwargs):return subject.repair(self.root,UID,GID,{GID},self.journals,**kwargs)
    def assert_original_owners(self):
        self.assertEqual((self.directory.stat().st_uid,self.directory.stat().st_gid),(0,0))
        self.assertEqual((self.catalog.stat().st_uid,self.catalog.stat().st_gid),(0,0))
    def test_root_directory_and_catalog_repair_preserve_inodes_bytes_modes_and_sibling(self):
        directory_before=facts(self.directory);file_before=facts(self.catalog);sibling_before=facts(self.sibling);env_before=facts(self.env)
        journal,records=self.repair()
        self.assertEqual(self.directory.stat().st_uid,UID);self.assertEqual(self.directory.stat().st_gid,0)
        self.assertEqual((self.catalog.stat().st_uid,self.catalog.stat().st_gid),(UID,GID))
        self.assertEqual(facts(self.directory)[:3],directory_before[:3]);self.assertEqual(facts(self.catalog)[:3],file_before[:3])
        self.assertEqual(self.catalog.read_bytes(),DATA);self.assertEqual(facts(self.sibling),sibling_before);self.assertEqual(facts(self.env),env_before)
        self.assertEqual({row['path']for row in records},{str(subject.DIRECTORY),str(subject.CATALOG)})
        self.assertEqual(stat.S_IMODE(journal.stat().st_mode),0o700)
        for name in ['before.json','after.json']:self.assertEqual(stat.S_IMODE((journal/name).stat().st_mode),0o600)
        self.assertTrue(json.loads((journal/'after.json').read_text())['verified'])
    def test_already_app_catalog_metadata_remains_unchanged(self):
        os.chown(self.catalog,UID,GID);before=subject.identity(self.catalog.stat())
        _,records=self.repair()
        self.assertEqual(subject.identity(self.catalog.stat()),before)
        self.assertEqual([row['path']for row in records],[str(subject.DIRECTORY)])
    def test_already_fixed_scope_is_idempotent_without_another_journal(self):
        self.repair();directory=subject.identity(self.directory.stat());file=subject.identity(self.catalog.stat());count=len(list(self.journals.iterdir()))
        self.assertEqual(self.repair(),(None,[]))
        self.assertEqual(subject.identity(self.directory.stat()),directory);self.assertEqual(subject.identity(self.catalog.stat()),file)
        self.assertEqual(len(list(self.journals.iterdir())),count)
    def test_unsupported_app_catalog_group_stops_before_directory_mutation(self):
        os.chown(self.catalog,UID,0);before=facts(self.directory);file=facts(self.catalog)
        with self.assertRaisesRegex(subject.ReviewRequired,'EXISTING_APP_FILE_GROUP_UNREVIEWED'):self.repair()
        self.assertEqual(facts(self.directory),before);self.assertEqual(facts(self.catalog),file);self.assertEqual(list(self.journals.iterdir()),[])
    def test_unexpected_catalog_bytes_stop_before_any_mutation(self):
        self.catalog.write_bytes(PEER);directory=facts(self.directory);file=facts(self.catalog)
        with self.assertRaisesRegex(subject.ReviewRequired,'CATALOG_BLOB_CHANGED'):self.repair()
        self.assertEqual(facts(self.directory),directory);self.assertEqual(facts(self.catalog),file);self.assertEqual(self.catalog.read_bytes(),PEER)
    def test_catalog_symlink_does_not_touch_its_destination(self):
        outside=self.folder/'outside.php';outside.write_bytes(DATA);before=facts(outside)
        self.catalog.unlink();self.catalog.symlink_to(outside);directory=facts(self.directory)
        with self.assertRaises((subject.ReviewRequired,OSError)):self.repair()
        self.assertEqual(facts(outside),before);self.assertEqual(facts(self.directory),directory);self.assertTrue(self.catalog.is_symlink())
    def test_symlinked_parent_is_rejected_without_touching_original_tree(self):
        services=self.root/'app/Services';moved=self.root/'app/Services-original';services.rename(moved);services.symlink_to(moved,target_is_directory=True)
        original=moved/'GoStores/Catalog.php';before=facts(original)
        with self.assertRaises((subject.ReviewRequired,OSError)):self.repair()
        self.assertEqual(facts(original),before);self.assertTrue(services.is_symlink())
    def test_unreviewed_parent_owner_is_rejected_before_any_mutation(self):
        os.chown(self.root/'app/Services',0,0);file=facts(self.catalog)
        with self.assertRaisesRegex(subject.ReviewRequired,'ANCESTOR_CHANGED'):self.repair()
        self.assertEqual(facts(self.catalog),file);self.assert_original_owners()
    def test_special_or_writable_catalog_mode_is_rejected_before_mutation(self):
        for mode in [0o6744,0o1644,0o664,0o646,0o044]:
            with self.subTest(mode=oct(mode)):
                self.catalog.chmod(mode);before=facts(self.catalog)
                with self.assertRaisesRegex(subject.ReviewRequired,'CATALOG_METADATA_UNREVIEWED'):self.repair()
                self.assertEqual(facts(self.catalog),before);self.assertEqual(self.directory.stat().st_uid,0)
        self.assertEqual(list(self.journals.iterdir()),[])
    def test_hardlinked_catalog_is_rejected_without_changing_any_alias(self):
        alias=self.folder/'catalog-alias.php';os.link(self.catalog,alias);before=facts(self.catalog)
        with self.assertRaisesRegex(subject.ReviewRequired,'CATALOG_METADATA_UNREVIEWED'):self.repair()
        self.assertEqual(facts(self.catalog),before);self.assertEqual(facts(alias),before);self.assertEqual(self.directory.stat().st_uid,0)
    def test_peer_catalog_replacement_before_changes_is_retained(self):
        moved=self.directory/'Catalog-peer-original.php'
        def peer():self.catalog.rename(moved);self.catalog.write_bytes(PEER);self.catalog.chmod(0o644)
        with self.assertRaisesRegex(subject.ReviewRequired,'CONCURRENT_CHANGE'):self.repair(before_change=peer)
        self.assertEqual(self.catalog.read_bytes(),PEER);self.assertEqual(self.catalog.stat().st_uid,0);self.assertEqual(moved.stat().st_uid,0)
        self.assertEqual(self.directory.stat().st_uid,0)
    def test_peer_directory_replacement_before_changes_is_retained(self):
        moved=self.directory.with_name('GoStores-peer-original')
        def peer():
            self.directory.rename(moved);self.directory.mkdir();self.directory.chmod(0o755);self.catalog.write_bytes(PEER)
        with self.assertRaisesRegex(subject.ReviewRequired,'CONCURRENT_CHANGE'):self.repair(before_change=peer)
        self.assertEqual(self.catalog.read_bytes(),PEER);self.assertEqual(self.directory.stat().st_uid,0)
        self.assertEqual(moved.stat().st_uid,0);self.assertEqual((moved/'Catalog.php').stat().st_uid,0)
    def test_failure_after_changes_restores_only_the_same_objects(self):
        directory=facts(self.directory);file=facts(self.catalog)
        def fail():raise RuntimeError('injected failure after ownership changes')
        with self.assertRaisesRegex(subject.ReviewRequired,'OWNER_REPAIR_STOPPED'):self.repair(after_change=fail)
        self.assertEqual(facts(self.directory),directory);self.assertEqual(facts(self.catalog),file);self.assertEqual(self.catalog.read_bytes(),DATA)
        stopped=next(self.journals.glob('*/stopped.json'));self.assertTrue(json.loads(stopped.read_text())['owner_changes_restored_or_not_started'])
    def test_peer_catalog_replacement_after_changes_is_not_overwritten(self):
        def peer():
            self.catalog.rename(self.directory/'Catalog-peer-original.php');self.catalog.write_bytes(PEER);self.catalog.chmod(0o644)
        with self.assertRaisesRegex(subject.ReviewRequired,'CONCURRENT_CHANGE'):self.repair(after_change=peer)
        self.assertEqual(self.catalog.read_bytes(),PEER);self.assertEqual((self.catalog.stat().st_uid,self.catalog.stat().st_gid),(0,0))
    def test_peer_inplace_bytes_after_changes_are_retained(self):
        def peer():self.catalog.write_bytes(PEER)
        with self.assertRaisesRegex(subject.ReviewRequired,'CATALOG_BLOB_CHANGED'):self.repair(after_change=peer)
        self.assertEqual(self.catalog.read_bytes(),PEER);self.assertEqual((self.catalog.stat().st_uid,self.catalog.stat().st_gid),(UID,GID))
    def test_peer_catalog_metadata_after_changes_is_retained(self):
        def peer():os.chown(self.catalog,UID,0);self.catalog.chmod(0o444)
        with self.assertRaisesRegex(subject.ReviewRequired,'CONCURRENT_CHANGE'):self.repair(after_change=peer)
        self.assertEqual((self.catalog.stat().st_uid,self.catalog.stat().st_gid),(UID,0));self.assertEqual(stat.S_IMODE(self.catalog.stat().st_mode),0o444)
    def test_inventory_reports_unreviewed_owner_and_gid_without_repair(self):
        other=self.root/'app/Services/Other.php';other.write_bytes(DATA);os.chown(other,UID,0);other.chmod(0o644)
        before=subject.identity(other.stat());manifest={'sources':[{'path':str(subject.CATALOG)},{'path':'app/Services/Other.php'}]}
        blocked=subject.inventory(self.root,UID,{GID},manifest);row=next(row for row in blocked if row['path']=='app/Services/Other.php')
        self.assertIn('file_group_cannot_be_preserved',row['issues']);self.assertEqual(subject.identity(other.stat()),before)
    def planned_view(self):
        relative='resources/views/admin/reviewed.blade.php';source=self.root/relative
        source.parent.mkdir(parents=True);source.write_bytes(b'<p>reviewed view fixture</p>');source.chmod(0o644)
        for path in [self.root/'resources',self.root/'resources/views',self.root/'resources/views/admin',source]:os.chown(path,UID,GID)
        key=hashlib.sha1(('v2'+str(source)).encode()).hexdigest()
        return relative,self.root/'storage/framework/views'/f'{key}.php'
    def test_exact_planned_compiled_view_owner_and_group_are_reported_without_repair(self):
        relative,target=self.planned_view();target.write_bytes(b'<?php /* existing compiled fixture */\n');target.chmod(0o644)
        before=subject.identity(target.stat());blocked=subject.inventory(self.root,UID,{GID},{'sources':[{'path':relative}]})
        self.assertEqual(len(blocked),1);row=blocked[0]
        self.assertEqual(row['path'],target.relative_to(self.root).as_posix())
        self.assertIn('owner_differs',row['issues']);self.assertIn('file_group_cannot_be_preserved',row['issues'])
        self.assertEqual(subject.identity(target.stat()),before)
    def test_unrelated_compiled_view_is_untouched_and_does_not_block(self):
        relative,target=self.planned_view();unrelated=target.with_name('f'*40+'.php');self.assertNotEqual(target,unrelated)
        unrelated.write_bytes(b'<?php /* unrelated root-owned compiled fixture */\n');unrelated.chmod(0o600)
        before=subject.identity(unrelated.stat());blocked=subject.inventory(self.root,UID,{GID},{'sources':[{'path':relative}]})
        self.assertEqual(blocked,[]);self.assertEqual(subject.identity(unrelated.stat()),before);self.assertFalse(target.exists())
    def test_unknown_after_fchown_capture_stops_with_recovery_false(self):
        file_inode=self.catalog.stat().st_ino;real_fstat=os.fstat;injected=False
        def fail_once(fd):
            nonlocal injected
            value=real_fstat(fd)
            if value.st_ino==file_inode and value.st_uid==UID and not injected:
                injected=True;raise OSError('injected post-fchown stat failure')
            return value
        with patch.object(subject.os,'fstat',side_effect=fail_once):
            with self.assertRaisesRegex(subject.ReviewRequired,'OWNER_REPAIR_STOPPED'):self.repair()
        self.assertTrue(injected);self.assertEqual(self.directory.stat().st_uid,0)
        self.assertEqual((self.catalog.stat().st_uid,self.catalog.stat().st_gid),(UID,GID));self.assertEqual(self.catalog.read_bytes(),DATA)
        stopped=next(self.journals.glob('*/stopped.json'));self.assertFalse(json.loads(stopped.read_text())['owner_changes_restored_or_not_started'])
    def test_one_rollback_chown_failure_still_restores_the_other_object(self):
        file_inode=self.catalog.stat().st_ino;real_fchown=os.fchown;injected=False
        def fail_once(fd,uid,gid):
            nonlocal injected
            if os.fstat(fd).st_ino==file_inode and uid==0 and not injected:
                injected=True;raise OSError('injected Catalog rollback failure')
            return real_fchown(fd,uid,gid)
        def fail():raise RuntimeError('force rollback after both changes')
        with patch.object(subject.os,'fchown',side_effect=fail_once):
            with self.assertRaisesRegex(subject.ReviewRequired,'OWNER_REPAIR_STOPPED'):self.repair(after_change=fail)
        self.assertTrue(injected);self.assertEqual(self.directory.stat().st_uid,0)
        self.assertEqual((self.catalog.stat().st_uid,self.catalog.stat().st_gid),(UID,GID));self.assertEqual(self.catalog.read_bytes(),DATA)
        stopped=next(self.journals.glob('*/stopped.json'));self.assertFalse(json.loads(stopped.read_text())['owner_changes_restored_or_not_started'])

class ReadOnlyInventoryTests(Fixture):
    def test_supported_publication_metadata_has_no_blockers(self):
        blocked=subject.inventory(self.root,os.geteuid(),{self.catalog.stat().st_gid},{'sources':[{'path':str(subject.CATALOG)}]})
        self.assertEqual(blocked,[])
    def test_unsupported_regular_file_group_is_reported_without_writes(self):
        before=subject.identity(self.env.stat())
        blocked=subject.inventory(self.root,os.geteuid(),{GID},{'sources':[{'path':str(subject.CATALOG)}]})
        row=next(row for row in blocked if row['path']=='.env');self.assertIn('file_group_cannot_be_preserved',row['issues'])
        self.assertEqual(subject.identity(self.env.stat()),before)
    def test_symlinked_parent_prevents_descendant_inspection(self):
        services=self.root/'app/Services';original=self.folder/'outside-services';services.rename(original);services.symlink_to(original,target_is_directory=True)
        file=original/'GoStores/Catalog.php';before=subject.identity(file.stat())
        blocked=subject.inventory(self.root,os.geteuid(),{file.stat().st_gid},{'sources':[{'path':str(subject.CATALOG)}]})
        row=next(row for row in blocked if row['path']=='app/Services');self.assertIn('unsafe_path_type',row['issues'])
        self.assertFalse(any(row['path'].startswith('app/Services/')for row in blocked));self.assertEqual(subject.identity(file.stat()),before)

if __name__=='__main__':unittest.main()
