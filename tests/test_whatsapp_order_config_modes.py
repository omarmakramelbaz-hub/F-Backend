#!/usr/bin/env python3
"""Real narrow fchmod, ownership, inode and race fixtures; no application execution."""
import fcntl
import importlib.util
import json
import os
import pathlib
import signal
import stat
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT=pathlib.Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('whatsapp_config_modes_tested',ROOT/'deployment/normalize_whatsapp_order_config_modes.py')
modes=importlib.util.module_from_spec(SPEC);sys.modules[SPEC.name]=modes;SPEC.loader.exec_module(modes)


def identity(path):
    item=path.lstat()
    return (item.st_dev,item.st_ino,item.st_uid,item.st_gid,stat.S_IMODE(item.st_mode),
            item.st_nlink,item.st_size,item.st_mtime_ns,item.st_ctime_ns)


class Fixture:
    def __init__(self,directory):
        self.root=pathlib.Path(directory)/'public_html';self.root.mkdir(mode=0o755)
        self.parent=self.root.parent/'whatsapp-release-backups';self.parent.mkdir(mode=0o700)
        self.uid=os.geteuid();self.before={}
        for index,p in enumerate(modes.FILES):
            self.write(p,('PRIVATE_FIXTURE_VALUE_'+str(index)+'\n').encode(),0o775)
        for p,mode in (('.env',0o600),('bootstrap/cache/config.php',0o600),
                       ('config/unrelated.php',0o775),('app/Workflow.php',0o644)):
            self.write(p,b'UNCHANGED_PRIVATE_FIXTURE\n',mode)
        for name in modes.LOCK_NAMES:
            path=self.parent/name;path.write_bytes(b'');path.chmod(0o600)
        self.remember()
    def write(self,p,data,mode):
        path=self.root/p;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data);path.chmod(mode)
    def remember(self):
        self.before={p:(self.root/p).read_bytes() for p in (*modes.FILES,'.env','bootstrap/cache/config.php','config/unrelated.php','app/Workflow.php')}
        self.metadata={p:identity(self.root/p) for p in self.before}
    def normalize(self,hook=None):return modes.normalize(self.root,self.uid,hook)
    def untouched(self,test):
        for p,data in self.before.items():
            test.assertEqual((self.root/p).read_bytes(),data,p)
            test.assertEqual(identity(self.root/p),self.metadata[p],p)


class ConfigModeTests(unittest.TestCase):
    def setUp(self):self.temp=tempfile.TemporaryDirectory();self.f=Fixture(self.temp.name)
    def tearDown(self):self.temp.cleanup()

    def test_exact_twenty_files_remove_only_group_write_and_preserve_bytes_identity_and_mtime(self):
        f=self.f;result=f.normalize();self.assertEqual(result['status'],'WHATSAPP_ORDER_CONFIG_MODES_READY')
        self.assertEqual(len(modes.FILES),20)
        for p,old in f.metadata.items():
            new=identity(f.root/p);self.assertEqual((f.root/p).read_bytes(),f.before[p],p)
            if p in modes.FILES:
                self.assertEqual(new[:4],old[:4]);self.assertEqual(new[4],old[4]&~0o020);self.assertEqual(new[5:8],old[5:8])
            else:self.assertEqual(new,old,p)
        backup=pathlib.Path(result['backup']);self.assertEqual(stat.S_IMODE(backup.stat().st_mode),0o700)
        journal=backup/'journal.jsonl';self.assertEqual(stat.S_IMODE(journal.stat().st_mode),0o600)
        content=journal.read_bytes();self.assertNotIn(b'PRIVATE_FIXTURE_VALUE',content);self.assertNotIn(b'UNCHANGED_PRIVATE_FIXTURE',content)
        rows=[json.loads(line) for line in content.splitlines()]
        self.assertEqual(rows[0]['schema'],'whatsapp-config-modes-v1');self.assertEqual(len(rows[0]['files']),20)
        self.assertEqual([row['operation'] for row in rows[1:]],['intent','complete']*20+['ready'])

    def test_second_invocation_is_idempotent_preserves_all_metadata_and_creates_no_new_backup(self):
        f=self.f;f.normalize();f.remember();backups=list(f.parent.iterdir());result=f.normalize()
        self.assertEqual(result,{'status':'WHATSAPP_ORDER_CONFIG_MODES_ALREADY_READY','backup':None});f.untouched(self)
        self.assertEqual(backups,list(f.parent.iterdir()))

    def test_mixed_confirmed_775_and_already_755_resumes_without_mutating_ready_files(self):
        f=self.f
        for p in modes.FILES[::2]:(f.root/p).chmod(0o755)
        f.remember();result=f.normalize();self.assertIn('MODES_READY',result['status'])
        for p in modes.FILES[::2]:self.assertEqual(identity(f.root/p),f.metadata[p])
        for p in modes.FILES:self.assertEqual(stat.S_IMODE((f.root/p).stat().st_mode),0o755)

    def test_unreviewed_mode_on_last_file_blocks_all_changes_and_journal_creation(self):
        f=self.f;(f.root/modes.FILES[-1]).chmod(0o664);f.remember()
        with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        f.untouched(self);self.assertFalse(list(f.parent.glob('config-modes-*')))

    def test_initial_hardlink_refused_before_any_mode_change(self):
        f=self.f;os.link(f.root/modes.FILES[-1],f.root.parent/'alias');f.remember()
        with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        f.untouched(self)

    def test_initial_symlink_refused_without_touching_its_target(self):
        f=self.f;p=f.root/modes.FILES[-1];p.unlink();p.symlink_to(f.root/'config/unrelated.php')
        target_before=identity(f.root/'config/unrelated.php')
        with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        self.assertTrue(p.is_symlink());self.assertEqual(identity(f.root/'config/unrelated.php'),target_before)
        self.assertTrue(all(stat.S_IMODE((f.root/q).stat().st_mode)==0o775 for q in modes.FILES[:-1]))

    def test_config_directory_symlink_refused_before_mutation(self):
        f=self.f;directory=f.root/'config';saved=f.root/'other-config';directory.rename(saved);directory.symlink_to(saved,target_is_directory=True)
        with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        self.assertTrue(directory.is_symlink());self.assertTrue(all(stat.S_IMODE((saved/pathlib.Path(q).name).stat().st_mode)==0o775 for q in modes.FILES))

    def test_nonregular_fifo_refused_without_blocking(self):
        f=self.f;p=f.root/modes.FILES[-1];p.unlink();os.mkfifo(p,0o775)
        with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        self.assertTrue(stat.S_ISFIFO(p.lstat().st_mode))

    def test_foreign_owner_refused_before_all_mutations(self):
        # User-namespaced containers cannot chown to an unmapped uid. Inject
        # only that foreign ownership field at the real inode's fstat boundary.
        f=self.f;inode=(f.root/modes.FILES[-1]).stat().st_ino;original=os.fstat
        def foreign_owner(fd):
            info=original(fd)
            if info.st_ino!=inode:return info
            values={name:getattr(info,name) for name in (
                'st_dev','st_ino','st_uid','st_gid','st_mode','st_nlink','st_size','st_mtime_ns','st_ctime_ns')}
            values['st_uid']=f.uid+1;return types.SimpleNamespace(**values)
        with mock.patch.object(modes.os,'fstat',foreign_owner):
            with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        f.untouched(self)

    def test_bounded_read_limit_refuses_large_file_without_mutations(self):
        f=self.f
        with mock.patch.object(modes,'MAX_BYTES',4):
            with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        f.untouched(self)

    def test_post_change_write_through_alias_is_detected_and_foreign_content_preserved(self):
        f=self.f;done=[False]
        def race(stage,p,root):
            if stage=='after_mutation' and not done[0]:
                done[0]=True;alias=root.parent/'temporary-alias';os.link(root/p,alias)
                alias.write_bytes(b'CONCURRENT_WRITER_VALUE\n');alias.unlink()
        with self.assertRaisesRegex(modes.SafeError,'PARTIAL_NEEDS_REVIEW'):f.normalize(race)
        self.assertEqual((f.root/modes.FILES[0]).read_bytes(),b'CONCURRENT_WRITER_VALUE\n')
        self.assertEqual(stat.S_IMODE((f.root/modes.FILES[0]).stat().st_mode),0o755)
        self.assertTrue(all(stat.S_IMODE((f.root/p).stat().st_mode)==0o775 for p in modes.FILES[1:]))

    def test_post_change_replacement_never_has_its_mode_or_content_overwritten(self):
        f=self.f;done=[False]
        def race(stage,p,root):
            if stage=='after_mutation' and not done[0]:
                done[0]=True;path=root/p;path.unlink();path.write_bytes(b'FOREIGN_REPLACEMENT\n');path.chmod(0o775)
        with self.assertRaisesRegex(modes.SafeError,'PARTIAL_NEEDS_REVIEW'):f.normalize(race)
        self.assertEqual((f.root/modes.FILES[0]).read_bytes(),b'FOREIGN_REPLACEMENT\n')
        self.assertEqual(stat.S_IMODE((f.root/modes.FILES[0]).stat().st_mode),0o775)

    def test_post_change_foreign_chmod_is_preserved_and_stops_batch(self):
        f=self.f;done=[False]
        def race(stage,p,root):
            if stage=='after_mutation' and not done[0]:done[0]=True;(root/p).chmod(0o644)
        with self.assertRaisesRegex(modes.SafeError,'PARTIAL_NEEDS_REVIEW'):f.normalize(race)
        self.assertEqual(stat.S_IMODE((f.root/modes.FILES[0]).stat().st_mode),0o644)

    def test_link_count_change_after_intent_blocks_remaining_mutations(self):
        f=self.f;done=[False]
        def race(stage,p,root):
            if stage=='after_mutation' and not done[0]:done[0]=True;os.link(root/p,root.parent/'external-alias')
        with self.assertRaisesRegex(modes.SafeError,'PARTIAL_NEEDS_REVIEW'):f.normalize(race)
        self.assertEqual((f.root/modes.FILES[0]).stat().st_nlink,2)
        self.assertTrue(all(stat.S_IMODE((f.root/p).stat().st_mode)==0o775 for p in modes.FILES[1:]))

    def test_error_after_actual_fchmod_keeps_restriction_and_durable_intent(self):
        f=self.f;original=os.fchmod;count=[0]
        def interrupted(fd,mode):
            original(fd,mode);count[0]+=1
            if count[0]==1:raise InterruptedError()
        with mock.patch.object(modes.os,'fchmod',interrupted):
            with self.assertRaisesRegex(modes.SafeError,'PARTIAL_NEEDS_REVIEW'):f.normalize()
        self.assertEqual(stat.S_IMODE((f.root/modes.FILES[0]).stat().st_mode),0o755)
        journal=next(f.parent.glob('config-modes-*/journal.jsonl'))
        rows=[json.loads(line) for line in journal.read_text().splitlines()]
        self.assertEqual(rows[1]['operation'],'intent');self.assertEqual(rows[-1]['operation'],'partial_needs_review')

    def test_completed_partial_restriction_can_resume_after_inert_failure(self):
        f=self.f;done=[False]
        def failure(stage,p,root):
            if stage=='after_mutation' and not done[0]:done[0]=True;raise InterruptedError()
        with self.assertRaisesRegex(modes.SafeError,'PARTIAL_NEEDS_REVIEW'):f.normalize(failure)
        first=identity(f.root/modes.FILES[0]);result=f.normalize();self.assertIn('MODES_READY',result['status'])
        self.assertEqual(identity(f.root/modes.FILES[0]),first)
        self.assertTrue(all(stat.S_IMODE((f.root/p).stat().st_mode)==0o755 for p in modes.FILES))

    def test_lock_and_private_backup_profiles_refused_without_changes(self):
        f=self.f;(f.parent/modes.LOCK_NAMES[0]).chmod(0o644)
        with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        f.untouched(self)
        (f.parent/modes.LOCK_NAMES[0]).chmod(0o600);f.parent.chmod(0o755)
        with self.assertRaisesRegex(modes.SafeError,'ROOT_OR_BACKUP_PROFILE_UNSAFE'):f.normalize()
        f.untouched(self)

    def test_busy_existing_install_lock_blocks_before_any_mode_change(self):
        f=self.f;fd=os.open(f.parent/modes.LOCK_NAMES[0],os.O_RDWR)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaisesRegex(modes.SafeError,'PREFLIGHT_NEEDS_REVIEW'):f.normalize()
        finally:os.close(fd)
        f.untouched(self)

    def test_file_contents_and_external_programs_are_absent_from_cli_output_path(self):
        source=(ROOT/'deployment/normalize_whatsapp_order_config_modes.py').read_text()
        self.assertNotIn('subprocess',source);self.assertNotIn('systemctl',source);self.assertNotIn('os.chown',source)
        self.assertNotIn('chmod(0o644)',source);self.assertNotIn('glob(',source)
        self.assertEqual(modes.NEW_MODE,modes.OLD_MODE&~0o020)


if __name__=='__main__':unittest.main()
