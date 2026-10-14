#!/usr/bin/env python3
"""Pinned repair publication and worker recovery fixtures; no network or real workers."""
import hashlib
import importlib.util
import json
import os
import pathlib
import stat
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT=pathlib.Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('order_offer_variant_repair_install_tested',ROOT/'deployment/apply_whatsapp_order_offer_variant_repair.py')
repair=importlib.util.module_from_spec(SPEC);sys.modules[SPEC.name]=repair;SPEC.loader.exec_module(repair)
wrapper=types.ModuleType('order_offer_variant_repair_root_tested');wrapper.__file__=str(ROOT/'deployment/apply_whatsapp_order_offer_variant_repair_root.sh')
source=pathlib.Path(wrapper.__file__).read_text().split("<<'PY'\n",1)[1].rsplit('\nPY\n',1)[0]
exec(compile(source,wrapper.__file__,'exec'),wrapper.__dict__)
RELEASE='a'*40


class Fixture:
    def __init__(self,directory,cached=True):
        self.root=pathlib.Path(directory)/'app';self.root.mkdir(mode=0o700);(self.root/'.git').mkdir(mode=0o700)
        self.uid=os.geteuid();self.calls=[];self.hook=None;self.fail_lint=False;self.fail_target=False;self.tree_mode=b'100644'
        self.pins={};self.targets={};self.old={}
        for index,relative in enumerate(repair.SOURCE_PINS):
            old=('<?php /* original '+str(index)+' */\n').encode()
            new=('<?php /* reviewed '+str(index)+' */\n').encode()
            self.old[relative]=old;self.targets[relative]=new
            self.pins[relative]=(None if old is None else repair.blob(old),repair.blob(new))
            if old is not None:self.write(relative,old,0o640)
        self.protected={'app/Support/Immutable.php':repair.blob(b'<?php /* immutable */\n')}
        self.write('app/Support/Immutable.php',b'<?php /* immutable */\n',0o644)
        self.write('.env',b'WHATSAPP_ORDERS_MODE=auto\nACTIVATION_ID=500\n',0o600)
        self.write('config/whatsapp_orders.php',b'<?php return ["enabled"=>true,"mode"=>"auto"];\n',0o644)
        self.write('config/other.php',b'<?php return ["important"=>7];\n',0o644)
        if cached:self.write('bootstrap/cache/config.php',b'<?php return ["cached"=>true];\n',0o600)
        else:(self.root/'bootstrap/cache').mkdir(parents=True)
        self.config=repair.configuration_snapshot(self.root,self.uid)

    def write(self,relative,content,mode=0o644):
        path=self.root/relative;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(content);path.chmod(mode)

    def runner(self,root,args,capture=False,timeout=60):
        self.calls.append(tuple(args))
        if self.hook:self.hook(args)
        if args[:3]==['git','cat-file','-t']:return 0,b'commit\n'
        if args[:3]==['git','ls-tree','-z']:
            p=args[-1];return 0,self.tree_mode+b' blob '+self.pins[p][1].encode()+b'\t'+p.encode()+b'\0'
        if args[:2]==['git','show']:return 0,self.targets[args[-1].split(':',1)[1]]
        if args[:2]==[repair.PHP,'-l']:return (1 if self.fail_lint else 0),b''
        if args[:3]==[repair.PHP,'-r',repair.READINESS_PHP]:
            pins=json.loads(args[-1]);target=all(pins[p]==self.pins[p][1] for p in self.pins)
            if target and self.fail_target:return 1,b'secret-error-must-not-escape'
            return 0,json.dumps({'mode':'auto','fingerprint':hashlib.sha256(b'effective-stable').hexdigest()}).encode()
        if args[:2]==['/usr/bin/systemctl','show']:
            p=args[-1];value='LoadState=loaded\nActiveState=inactive\n'
            if p.endswith('.service'):value+='MainPID=0\n'
            return 0,value.encode()
        raise AssertionError('Unexpected repair command '+repr(args[:2]))

    def apply(self,check=False,expected=None):
        with mock.patch.object(repair,'PROTECTED_PINS',self.protected):
            return repair.apply(self.root,self.uid,RELEASE,check,runner=self.runner,pins=self.pins,expected_configuration=expected)

    def unchanged_config(self,case):case.assertEqual(repair.configuration_snapshot(self.root,self.uid),self.config)
    def originals(self,case):
        for p,old in self.old.items():
            if old is None:case.assertFalse((self.root/p).exists())
            else:case.assertEqual((self.root/p).read_bytes(),old)


class RepairPublicationTests(unittest.TestCase):
    def setUp(self):self.temp=tempfile.TemporaryDirectory();self.f=Fixture(self.temp.name)
    def tearDown(self):self.temp.cleanup()

    def test_publish_one_pinned_source_and_preserve_all_configuration_bytes_and_metadata(self):
        f=self.f;before={p:repair.base.snapshot(f.root/p,f.uid,f.root,True) for p in f.pins}
        result=f.apply();self.assertEqual(result['status'],'WHATSAPP_ORDER_OFFER_VARIANT_REPAIR_READY')
        for p,data in f.targets.items():
            self.assertEqual((f.root/p).read_bytes(),data)
            after=repair.base.snapshot(f.root/p,f.uid,f.root)
            if before[p]:self.assertEqual((after.uid,after.gid,after.mode),(before[p].uid,before[p].gid,before[p].mode))
        f.unchanged_config(self)
        self.assertEqual(stat.S_IMODE(pathlib.Path(result['backup']).stat().st_mode),0o700)
        self.assertFalse(any(a[0]!='git' and a[0] not in (repair.PHP,'/usr/bin/systemctl') for a in f.calls))
        self.assertFalse(any(word in a for a in f.calls for word in ('artisan','migrate','pull','reset','checkout','whatsapp:process-orders','whatsapp:consume-inbox')))

    def test_preflight_does_not_publish_or_operate_workers(self):
        f=self.f;r=f.apply(True);self.assertTrue(r['changed']);self.assertIn('PREFLIGHT',r['status']);f.originals(self);f.unchanged_config(self)
        self.assertFalse(any(a[0]=='/usr/bin/systemctl' for a in f.calls))
        self.assertFalse(list((f.root.parent/'whatsapp-release-backups').glob('order-offer-variant-*')))

    def test_already_current_repeat_preserves_inodes_and_has_no_second_backup(self):
        f=self.f;f.apply();before={p:repair.metadata(f.root/p) for p in f.pins}
        backups=list((f.root.parent/'whatsapp-release-backups').iterdir());result=f.apply()
        self.assertIn('ALREADY_CURRENT',result['status']);self.assertIsNone(result['backup'])
        self.assertEqual(before,{p:repair.metadata(f.root/p) for p in f.pins})
        self.assertEqual(backups,list((f.root.parent/'whatsapp-release-backups').iterdir()))

    def test_uncached_config_remains_absent(self):
        with tempfile.TemporaryDirectory() as t:
            f=Fixture(t,False);f.apply();f.unchanged_config(self)

    def test_existing_private_config_hardlinks_preserved(self):
        f=self.f;path=f.root/'config/whatsapp_orders.php';alias=f.root.parent/'config-private-copy';os.link(path,alias)
        f.config=repair.configuration_snapshot(f.root,f.uid);before=repair.metadata(alias);f.apply();f.unchanged_config(self)
        self.assertEqual(repair.metadata(alias),before)

    def test_existing_source_hardlink_is_atomically_replaced_without_changing_alias(self):
        f=self.f;p=next(iter(f.pins));source=f.root/p;alias=f.root.parent/'historical-evidence-copy'
        os.link(source,alias);before=repair.metadata(alias);content=alias.read_bytes()
        f.apply();self.assertEqual(source.read_bytes(),f.targets[p])
        self.assertEqual(alias.read_bytes(),content)
        after=repair.metadata(alias)
        self.assertEqual((after[0],after[1],after[2],after[3],after[4],after[5],after[6]),before[:7])
        self.assertNotEqual(source.stat().st_ino,alias.stat().st_ino)
        f.unchanged_config(self)

    def test_private_publication_receipt_has_only_evidence_target_and_complete_protected_roster(self):
        f=self.f;result=f.apply();receipt=pathlib.Path(result['backup'])/'receipt.json'
        value=json.loads(receipt.read_text())
        self.assertEqual(value['sources'],{p:v[0] for p,v in f.pins.items()})
        self.assertEqual(value['targets'],{p:v[1] for p,v in f.pins.items()})
        self.assertEqual(value['protected'],f.protected)
        self.assertEqual(stat.S_IMODE(receipt.stat().st_mode),0o600)
        self.assertEqual(receipt.stat().st_nlink,1)
        self.assertNotIn('WHATSAPP_ORDERS_MODE',receipt.read_text())
        f.unchanged_config(self)

    def test_unknown_live_source_rejected_before_smoke(self):
        f=self.f;p=list(f.pins)[-1];f.write(p,b'<?php /* foreign */\n')
        with self.assertRaises(repair.SafeError):f.apply()
        self.assertEqual(f.calls,[])

    def test_source_symlink_rejected(self):
        f=self.f;p=list(f.pins)[-1];path=f.root/p;path.unlink();path.symlink_to(f.root/'config/other.php')
        with self.assertRaises(repair.SafeError):f.apply()
        self.assertTrue(path.is_symlink())

    def test_wrong_git_tree_mode_rejected_before_publication(self):
        f=self.f;f.tree_mode=b'120000'
        with self.assertRaises(repair.SafeError):f.apply()
        f.originals(self);f.unchanged_config(self)

    def test_wrong_git_blob_bytes_rejected_before_publication(self):
        f=self.f;p=list(f.targets)[0];f.targets[p]+=b'changed'
        with self.assertRaises(repair.SafeError):f.apply()
        f.originals(self)

    def test_lint_failure_preserves_app_and_config(self):
        f=self.f;f.fail_lint=True
        with self.assertRaises(repair.SafeError):f.apply()
        f.originals(self);f.unchanged_config(self)

    def test_target_bootstrap_failure_rolls_back_same_original_inodes(self):
        f=self.f;before={p:repair.base.snapshot(f.root/p,f.uid,f.root,True) for p in f.pins};f.fail_target=True
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        f.originals(self);f.unchanged_config(self)
        for p,item in before.items():self.assertEqual(repair.base.snapshot(f.root/p,f.uid,f.root,True),item)

    def test_existing_source_collision_never_overwritten(self):
        f=self.f;p=list(f.pins)[0];f.write(p,b'<?php /* different helper */\n')
        with self.assertRaises(repair.SafeError):f.apply()
        self.assertEqual((f.root/p).read_bytes(),b'<?php /* different helper */\n')

    def test_existing_conversation_evidence_exposure_blocks_resuming_restored_baseline(self):
        f=self.f;f.fail_target=True
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        f.originals(self);f.unchanged_config(self)

    def test_existing_conversation_evidence_exception_after_publication_requires_exposure_review(self):
        f=self.f;original=repair.base.replace_file
        def publish(*args,**kwargs):
            original(*args,**kwargs)
            raise InterruptedError()
        with mock.patch.object(repair.base,'replace_file',publish):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        f.originals(self);f.unchanged_config(self)

    def test_configuration_changed_between_preflight_and_quiesce_rejects(self):
        f=self.f;pre=f.apply(True);f.write('config/other.php',b'<?php return ["important"=>8];\n')
        with self.assertRaisesRegex(repair.SafeError,'CHANGED_SINCE_PREFLIGHT'):f.apply(expected=pre['configuration_fingerprint'])
        f.originals(self)

    def test_configuration_race_after_first_atomic_publish_rolls_back_files_and_retains_new_config(self):
        f=self.f;original=repair.base.replace_file;count=[0]
        def publish(*a,**kw):
            result=original(*a,**kw);count[0]+=1
            if count[0]==1:f.write('config/other.php',b'<?php /* concurrent config */\n')
            return result
        with mock.patch.object(repair.base,'replace_file',publish):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        f.originals(self);self.assertEqual((f.root/'config/other.php').read_bytes(),b'<?php /* concurrent config */\n')

    def test_postpublication_foreign_entrypoint_preserved_with_its_dependencies(self):
        f=self.f;p=list(f.pins)[-1];original=repair.readiness
        def smoke(root,runner,pins):
            result=original(root,runner,pins)
            if all(pins[k]==f.pins[k][1] for k in pins):f.write(p,b'<?php /* newer external entrypoint */\n')
            return result
        with mock.patch.object(repair,'readiness',smoke):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        self.assertEqual((f.root/p).read_bytes(),b'<?php /* newer external entrypoint */\n')
        self.assertEqual(len(f.pins),1);f.unchanged_config(self)

    def test_atomic_exchange_exception_after_syscall_is_recoverable(self):
        f=self.f;original=repair.base.atomic_exchange;count=[0]
        def interrupted(a,b):
            original(a,b);count[0]+=1
            if count[0]==2:raise InterruptedError()
        with mock.patch.object(repair.base,'atomic_exchange',interrupted):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        f.originals(self);f.unchanged_config(self)

    def test_protected_erp_pin_mismatch_blocks_before_release_commands(self):
        f=self.f;f.write('app/Support/Immutable.php',b'<?php /* different ERP */\n')
        with self.assertRaisesRegex(repair.SafeError,'PROTECTED_SOURCE_MISMATCH'):f.apply()
        self.assertEqual(f.calls,[])

    def test_workers_with_nonzero_pid_block_publication(self):
        f=self.f;old=f.runner
        def runner(root,args,**kw):
            code,value=old(root,args,**kw)
            if args[0]=='/usr/bin/systemctl' and args[-1].endswith('.service'):value=value.replace(b'MainPID=0',b'MainPID=99')
            return code,value
        f.runner=runner
        with self.assertRaisesRegex(repair.SafeError,'NOT_QUIESCENT'):f.apply()
        f.originals(self)

    def test_private_config_multilink_rejected(self):
        f=self.f;os.link(f.root/'.env',f.root.parent/'env-alias')
        with self.assertRaisesRegex(repair.SafeError,'CONFIGURATION_LINK_UNSAFE'):f.apply()
        f.originals(self)

    def test_config_file_addition_during_lint_blocks_publication(self):
        f=self.f
        def hook(args):
            if args[:2]==[repair.PHP,'-l']:f.write('config/new.php',b'<?php /* concurrent */\n')
        f.hook=hook
        with self.assertRaisesRegex(repair.SafeError,'CONFIGURATION_CHANGED'):f.apply()
        f.originals(self)


class RestartPhysicalStateTests(unittest.TestCase):
    def setUp(self):self.temp=tempfile.TemporaryDirectory();self.f=Fixture(self.temp.name);self.pre=self.f.apply(True)
    def tearDown(self):self.temp.cleanup()
    def verify(self,sources=None):
        with mock.patch.object(repair,'SOURCE_PINS',self.f.pins),mock.patch.object(repair,'PROTECTED_PINS',self.f.protected):
            return repair.verify_state(self.f.root,self.f.uid,self.pre['configuration_fingerprint'],
                self.pre['sources'] if sources is None else sources,self.pre['protected_fingerprint'])
    def test_original_state_guard_is_read_only_and_performs_no_worker_or_ai_commands(self):
        before=list(self.f.calls);value=self.verify();self.assertEqual(value['status'],'WHATSAPP_ORDER_OFFER_VARIANT_STATE_READY')
        self.assertEqual(self.f.calls,before);self.f.originals(self);self.f.unchanged_config(self)
    def test_changed_configuration_or_roster_addition_blocks_restart_guard(self):
        self.f.write('config/new.php',b'<?php /* new configuration */\n')
        with self.assertRaisesRegex(repair.SafeError,'STATE_CONFIGURATION_CHANGED'):self.verify()
    def test_changed_protected_hash_or_metadata_blocks_restart_guard(self):
        self.f.write('app/Support/Immutable.php',b'<?php /* foreign protected */\n')
        with self.assertRaisesRegex(repair.SafeError,'STATE_PROTECTED_CHANGED'):self.verify()
        self.f.write('app/Support/Immutable.php',b'<?php /* immutable */\n',0o640)
        with self.assertRaisesRegex(repair.SafeError,'STATE_PROTECTED_CHANGED'):self.verify()
    def test_unknown_or_different_accepted_source_stage_blocks_restart_guard(self):
        self.f.write(list(self.f.pins)[-1],b'<?php /* foreign workflow */\n')
        with self.assertRaisesRegex(repair.SafeError,'STATE_SOURCE_CHANGED'):self.verify()
        with self.assertRaisesRegex(repair.SafeError,'STATE_SCOPE_INVALID'):self.verify({p:'e'*40 for p in self.f.pins})
    def test_exact_published_target_stage_passes_and_old_stage_cannot_be_substituted(self):
        for name,content in self.f.targets.items():self.f.write(name,content)
        with self.assertRaisesRegex(repair.SafeError,'STATE_SOURCE_CHANGED'):self.verify()
        self.assertEqual(self.verify({p:v[1] for p,v in self.f.pins.items()})['status'],'WHATSAPP_ORDER_OFFER_VARIANT_STATE_READY')


@unittest.skipUnless(os.geteuid()==0,'Observed root-owned configuration fixtures require root')
class RootReadOnlyConfigurationTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.f=Fixture(self.temp.name)
        for p in repair.ROOT_READ_ONLY_CONFIGURATION:
            self.f.write(p,('ROOT_CONFIG_FIXTURE_PRIVATE:'+p+'\n').encode(),0o644)
        self.f.config=repair.configuration_snapshot(self.f.root,self.f.uid)
    def tearDown(self):self.temp.cleanup()

    def test_exact_four_root_owned_files_preserved_through_publication_and_idempotence(self):
        f=self.f;expected={p:((f.root/p).read_bytes(),repair.metadata(f.root/p)) for p in repair.ROOT_READ_ONLY_CONFIGURATION}
        original=repair.base.snapshot
        def strict_ordinary_reader(path,*args,**kwargs):
            try:relative=str(path.relative_to(f.root))
            except ValueError:relative=None
            self.assertNotIn(relative,repair.ROOT_READ_ONLY_CONFIGURATION)
            return original(path,*args,**kwargs)
        with mock.patch.object(repair.base,'snapshot',strict_ordinary_reader):
            result=f.apply();self.assertIn('REPAIR_READY',result['status']);f.unchanged_config(self)
            self.assertIn('ALREADY_CURRENT',f.apply()['status'])
        for p,value in expected.items():
            self.assertEqual(((f.root/p).read_bytes(),repair.metadata(f.root/p)),value)
            item,_=repair.guarded_snapshot(f.root/p,f.uid,f.root,configuration=True)
            self.assertEqual(item.uid,0);self.assertEqual(item.mode,0o644)
        for path in pathlib.Path(result['backup']).rglob('*'):
            if path.is_file():self.assertNotIn(b'ROOT_CONFIG_FIXTURE_PRIVATE',path.read_bytes())

    def test_read_only_preflight_with_root_owned_configs_never_publishes_or_changes_workers(self):
        f=self.f;result=f.apply(True);self.assertIn('PREFLIGHT',result['status']);f.originals(self);f.unchanged_config(self)
        self.assertFalse(any(args[0]=='/usr/bin/systemctl' for args in f.calls))

    def test_root_config_content_race_during_lint_blocks_publication(self):
        f=self.f;changed=[False]
        def hook(args):
            if args[:2]==[repair.PHP,'-l'] and not changed[0]:
                changed[0]=True;f.write('config/mail.php',b'CONCURRENT_ROOT_CONFIG\n',0o644)
        f.hook=hook
        with self.assertRaisesRegex(repair.SafeError,'CONFIGURATION_CHANGED'):f.apply()
        f.originals(self);self.assertEqual((f.root/'config/mail.php').read_bytes(),b'CONCURRENT_ROOT_CONFIG\n')

    def test_root_config_content_race_after_first_publication_retains_foreign_values_and_rolls_back_runtime(self):
        f=self.f;original=repair.base.replace_file;changed=[False]
        def publish(*args,**kwargs):
            result=original(*args,**kwargs)
            if not changed[0]:changed[0]=True;f.write('config/jwt.php',b'CONCURRENT_JWT_CONFIG\n',0o644)
            return result
        with mock.patch.object(repair.base,'replace_file',publish):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        f.originals(self);self.assertEqual((f.root/'config/jwt.php').read_bytes(),b'CONCURRENT_JWT_CONFIG\n')

    def test_root_configuration_reader_whitelist_is_exact_and_unlisted_roots_remain_strict(self):
        f=self.f
        self.assertEqual(repair.ROOT_READ_ONLY_CONFIGURATION,frozenset((
            'config/go_services.php','config/jwt.php','config/mail.php','config/partner_auth.php')))
        for p in ('.env','bootstrap/cache/config.php','config/other.php',list(f.pins)[-1],'app/Support/Immutable.php'):
            with self.subTest(path=p),self.assertRaisesRegex(repair.SafeError,'SCOPE_INVALID'):
                repair.root_configuration_snapshot(f.root/p,f.uid,f.root,repair.metadata(f.root/p))
            # Supply a distinct application UID at the existing base-reader
            # boundary, without depending on unmapped container chown support.
            with self.subTest(path=p),mock.patch.object(repair.base,'safe_ancestors'),self.assertRaisesRegex(repair.SafeError,'FILE_OWNER_OR_TYPE'):
                repair.guarded_snapshot(f.root/p,1001,f.root,configuration=True)
        with mock.patch.object(repair.base,'safe_ancestors'),self.assertRaisesRegex(repair.SafeError,'FILE_OWNER_OR_TYPE'):
            repair.guarded_snapshot(f.root/'config/mail.php',1001,f.root,configuration=False)

    def test_changed_root_configuration_mode_or_hardlinks_rejected_before_any_release_operation(self):
        f=self.f;path=f.root/'config/mail.php'
        for mode in (0o600,0o640,0o755,0o664):
            path.chmod(mode);f.calls.clear()
            with self.subTest(mode=mode),self.assertRaises(repair.SafeError):f.apply()
            self.assertEqual(f.calls,[])
        path.chmod(0o644);alias=f.root.parent/'root-config-alias';os.link(path,alias)
        with self.assertRaisesRegex(repair.SafeError,'ROOT_CONFIGURATION_PROFILE'):f.apply()
        f.originals(self)

    def test_root_configuration_symlink_or_nonregular_type_is_refused_without_following(self):
        f=self.f;path=f.root/'config/mail.php';target=f.root/'config/other.php';target_before=repair.metadata(target)
        path.unlink();path.symlink_to(target)
        with self.assertRaises(repair.SafeError):f.apply()
        self.assertTrue(path.is_symlink());self.assertEqual(repair.metadata(target),target_before)
        path.unlink();os.mkfifo(path,0o644)
        with self.assertRaisesRegex(repair.SafeError,'ROOT_CONFIGURATION_PROFILE'):f.apply()
        self.assertTrue(stat.S_ISFIFO(path.lstat().st_mode))

    def test_root_configuration_foreign_or_null_uid_at_fd_boundary_is_refused(self):
        f=self.f;path=f.root/'config/mail.php';inode=path.stat().st_ino;original=os.fstat
        for bad_uid in (None,1,1001):
            def owner(fd):
                info=original(fd)
                if info.st_ino!=inode:return info
                values={name:getattr(info,name) for name in (
                    'st_uid','st_gid','st_mode','st_dev','st_ino','st_nlink','st_size','st_mtime_ns','st_ctime_ns')}
                values['st_uid']=bad_uid;return types.SimpleNamespace(**values)
            with self.subTest(uid=bad_uid),mock.patch.object(repair.os,'fstat',owner),self.assertRaisesRegex(repair.SafeError,'ROOT_CONFIGURATION_PROFILE'):
                repair.guarded_snapshot(path,f.uid,f.root,configuration=True)

    def test_root_configuration_fd_swap_between_outer_lstat_and_open_is_rejected_even_when_path_restored(self):
        f=self.f;path=f.root/'config/mail.php';original_path=f.root/'config/mail-original.tmp';replacement=f.root/'config/mail-replacement.tmp'
        replacement.write_bytes(b'FOREIGN_ROOT_CONFIG\n');replacement.chmod(0o644);expected=repair.metadata(path);original_open=os.open;original_metadata=repair.metadata;swapped=[False]
        def swap(name,*args,**kwargs):
            if pathlib.Path(name)==path and not swapped[0]:
                swapped[0]=True;path.rename(original_path);replacement.rename(path)
                fd=original_open(name,*args,**kwargs)
                path.rename(replacement);original_path.rename(path)
                return fd
            return original_open(name,*args,**kwargs)
        # Keep the outer path observations at A's original signature so this
        # regression specifically requires binding the opened B descriptor to A.
        def restored_path_metadata(name):
            return expected if pathlib.Path(name)==path else original_metadata(name)
        with mock.patch.object(repair.os,'open',swap),mock.patch.object(repair,'metadata',restored_path_metadata),self.assertRaisesRegex(repair.SafeError,'ROOT_CONFIGURATION_CHANGED'):
            repair.guarded_snapshot(path,f.uid,f.root,configuration=True)
        self.assertEqual(path.stat().st_ino,expected[4]);self.assertEqual(path.read_bytes(),b'ROOT_CONFIG_FIXTURE_PRIVATE:config/mail.php\n')

    def test_root_configuration_ancestor_owner_rule_and_size_bound_remain_enforced(self):
        f=self.f;path=f.root/'config/mail.php'
        with self.assertRaisesRegex(repair.SafeError,'ANCESTOR_OWNER'):
            repair.guarded_snapshot(path,1001,f.root,configuration=True)
        original=os.fstat;inode=path.stat().st_ino
        def oversized(fd):
            info=original(fd)
            if info.st_ino!=inode:return info
            values={name:getattr(info,name) for name in (
                'st_uid','st_gid','st_mode','st_dev','st_ino','st_nlink','st_size','st_mtime_ns','st_ctime_ns')}
            values['st_size']=8388609;return types.SimpleNamespace(**values)
        with mock.patch.object(repair.os,'fstat',oversized),self.assertRaisesRegex(repair.SafeError,'ROOT_CONFIGURATION_PROFILE'):
            repair.guarded_snapshot(path,f.uid,f.root,configuration=True)


class FragmentedHelperStagingTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.directory=pathlib.Path(self.temp.name)
        self.contents={'apply_whatsapp_order_offer_variant_repair.py':b'fixture publisher bytes',
                       'install_whatsapp_inbox.py':b'fixture base bytes'}
        self.pins={n:wrapper.blob(v) for n,v in self.contents.items()};self.mode=b'100644';self.tampered=False;self.calls=[]
    def tearDown(self):self.temp.cleanup()
    def runner(self,args,timeout=60):
        self.calls.append(args);relative=args[-1]
        if args[-4]=='ls-tree':
            name=pathlib.Path(relative).name
            return 0,self.mode+b' blob '+self.pins[name].encode()+b'\t'+relative.encode()+b'\0'
        name=pathlib.Path(relative.split(':',1)[1]).name
        return 0,self.contents[name]+(b'FOREIGN_PRIVATE_BYTES' if self.tampered else b'')
    def stage(self):
        with mock.patch.object(wrapper,'HELPERS',self.pins):
            wrapper.stage_helpers(self.directory,RELEASE,os.geteuid(),os.getegid(),self.runner)
    def test_only_verified_publisher_and_frozen_base_are_staged_private(self):
        self.stage();self.assertEqual(set(p.name for p in self.directory.iterdir()),set(self.contents))
        for name,content in self.contents.items():
            self.assertEqual((self.directory/name).read_bytes(),content)
            self.assertEqual(stat.S_IMODE((self.directory/name).stat().st_mode),0o600)
            self.assertEqual((self.directory/name).stat().st_nlink,1)
    def test_git_tree_mode_mismatch_prevents_any_helper_creation(self):
        self.mode=b'120000'
        with self.assertRaisesRegex(wrapper.SafeError,'HELPER_TREE_MISMATCH'):self.stage()
        self.assertEqual(list(self.directory.iterdir()),[])
    def test_private_foreign_bytes_never_leave_fixed_hash_failure(self):
        self.tampered=True
        with self.assertRaisesRegex(wrapper.SafeError,'HELPER_HASH_MISMATCH') as seen:self.stage()
        self.assertNotIn('FOREIGN_PRIVATE_BYTES',str(seen.exception));self.assertEqual(list(self.directory.iterdir()),[])
    def test_existing_staged_helper_cannot_be_overwritten(self):
        target=self.directory/'apply_whatsapp_order_offer_variant_repair.py';target.write_bytes(b'existing')
        with self.assertRaises(FileExistsError):self.stage()
        self.assertEqual(target.read_bytes(),b'existing')


class Workers:
    def __init__(self):
        self.states={name:'inactive' for name in wrapper.UNITS};self.states[wrapper.UNITS[2]]='active'
        self.calls=[];self.fail_order_start=False;self.changed=False;self.child_calls=[];self.publish_fail=None;self.phases=[];self.records=[];self.state_safe=True;self.guards=[]
    def profile(self,name):return (name,'changed' if self.changed else 'stable')
    def runner(self,args,timeout=60):
        self.calls.append(tuple(args))
        if args[1]=='show':
            name=args[-1];result='LoadState=loaded\nActiveState='+self.states[name]+'\nFragmentPath=/etc/systemd/system/'+name+'\n'
            if name.endswith('.service'):result+='MainPID='+('45' if self.states[name]=='active' else '0')+'\n'
            return 0,result.encode()
        if args[1]=='stop':
            for name in args[2:]:self.states[name]='inactive'
            return 0,b''
        if args[1]=='start':
            name=args[-1]
            if self.fail_order_start and name==wrapper.UNITS[2]:return 1,b'private-systemd-error'
            self.states[name]='active';return 0,b''
        raise AssertionError(args)
    def child(self,directory,release,uid,gid,held,check,expected=None,expected_sources=None,expected_protected=None):
        self.child_calls.append((check,expected))
        if check=='state':
            self.guards.append((expected,dict(expected_sources),expected_protected))
            if not self.state_safe:return {'ok':False,'status':'ORDER_OFFER_VARIANT_STATE_CONFIGURATION_CHANGED'}
            return {'ok':True,'result':{'status':'WHATSAPP_ORDER_OFFER_VARIANT_STATE_READY','configuration_fingerprint':expected,'sources':expected_sources,'protected_fingerprint':expected_protected}}
        if check:return {'ok':True,'result':{'status':'WHATSAPP_ORDER_OFFER_VARIANT_PREFLIGHT_READY','changed':True,'configuration_fingerprint':'b'*64,'sources':{p:v[0] for p,v in repair.SOURCE_PINS.items()},'protected_fingerprint':'c'*64}}
        if self.publish_fail:return {'ok':False,'status':self.publish_fail}
        return {'ok':True,'result':{'status':'WHATSAPP_ORDER_OFFER_VARIANT_REPAIR_READY','backup':'fixture-only','configuration_fingerprint':'b'*64,'sources':{p:v[1] for p,v in repair.SOURCE_PINS.items()},'protected_fingerprint':'c'*64}}
    def record(self,before,profiles,release,configuration,uid,sources,protected_fingerprint):
        self.records.append((dict(before),dict(profiles),release,configuration,uid));self.phases.append('RECORDED')
        return types.SimpleNamespace(path=pathlib.Path('/fixture/pipeline-backup'),mark=self.phases.append)
    def run(self):return wrapper.orchestrate(pathlib.Path('/fixture'),RELEASE,1,1,[5,6],self.runner,self.child,self.profile,self.record)



class RepairRootRecoveryTests(unittest.TestCase):
    def test_preflight_precedes_worker_pause_and_restores_only_original_active_pipeline(self):
        w=Workers();result=w.run();self.assertIn('REPAIR_READY',result['status']);self.assertEqual([v for v in w.child_calls if v[0]!='state'],[(True,None),(False,'b'*64)])
        self.assertEqual(w.states[wrapper.UNITS[2]],'active');self.assertEqual(w.states[wrapper.UNITS[0]],'inactive')
        self.assertEqual([a[-1] for a in w.calls if a[1]=='start'],[wrapper.UNITS[2]])
        self.assertEqual(w.records[0][0][wrapper.UNITS[2]]['ActiveState'],'active')
        self.assertEqual(w.records[0][0][wrapper.UNITS[0]]['ActiveState'],'inactive')
        self.assertEqual(w.phases,['RECORDED','TIMERS_PAUSED','QUIESCENT','PUBLICATION_STARTED','PUBLISHED','RESTORED'])
        self.assertEqual([a[2:] for a in w.calls if a[1]=='stop'],[(wrapper.UNITS[0],wrapper.UNITS[2])])

    def test_already_current_preflight_does_not_stop_or_start_any_unit(self):
        w=Workers();old=w.child
        def child(*a,**kw):
            result=old(*a,**kw);result['result']['changed']=False;return result
        w.child=child;self.assertIn('ALREADY_CURRENT',w.run()['status']);self.assertEqual(w.calls,[])

    def test_unsafe_source_preflight_causes_no_worker_actions(self):
        w=Workers();w.child=lambda *a,**kw:{'ok':False,'status':'ORDER_OFFER_VARIANT_LIVE_SOURCE_NEEDS_REVIEW'}
        with self.assertRaisesRegex(wrapper.SafeError,'LIVE_SOURCE'):w.run()
        self.assertEqual(w.calls,[])

    def test_confirmed_file_rollback_restores_prior_pipeline(self):
        w=Workers();w.publish_fail='ORDER_OFFER_VARIANT_FILES_RESTORED; BACKUP=/home/fasakha/whatsapp-release-backups/order-offer-variant-fixture'
        with self.assertRaisesRegex(wrapper.SafeError,'FILES_RESTORED'):w.run()
        self.assertEqual(w.states[wrapper.UNITS[2]],'active');self.assertEqual(w.states[wrapper.UNITS[0]],'inactive')

    def test_uncertain_rollback_keeps_orders_paused_and_capture_active(self):
        w=Workers();w.publish_fail='ORDER_OFFER_VARIANT_ROLLBACK_REVIEW_REQUIRED'
        with self.assertRaisesRegex(wrapper.SafeError,'ORDERS_PAUSED_CAPTURE_ACTIVE'):w.run()
        self.assertEqual(w.states[wrapper.UNITS[2]],'inactive');self.assertEqual(w.states[wrapper.UNITS[3]],'inactive');self.assertEqual(w.states[wrapper.UNITS[0]],'active')

    def test_failed_order_timer_restore_recovers_capture_without_starting_order_service(self):
        w=Workers();w.fail_order_start=True
        with self.assertRaisesRegex(wrapper.SafeError,'ORDERS_PAUSED_CAPTURE_ACTIVE'):w.run()
        self.assertEqual(w.states[wrapper.UNITS[2]],'inactive');self.assertEqual(w.states[wrapper.UNITS[0]],'active')
        self.assertFalse(any(a[1]=='start' and a[-1].endswith('.service') for a in w.calls))

    def test_changed_root_unit_is_stopped_but_never_started_during_recovery(self):
        w=Workers();old=w.child
        def child(*a,**kw):
            result=old(*a,**kw)
            if a[5] is False:w.changed=True
            return result
        w.child=child
        with self.assertRaisesRegex(wrapper.SafeError,'RECOVERY_NEEDS_REVIEW'):w.run()
        self.assertTrue(all(value=='inactive' for value in w.states.values()))
        self.assertFalse(any(a[1]=='start' for a in w.calls))

    def test_manual_service_without_timer_refused_before_pause(self):
        w=Workers();w.states[wrapper.UNITS[2]]='inactive';w.states[wrapper.UNITS[3]]='active'
        with self.assertRaisesRegex(wrapper.SafeError,'MANUAL_WORKER'):w.run()
        self.assertFalse(any(a[1] in ('stop','start') for a in w.calls))

    def test_changed_config_or_source_after_publication_keeps_all_workers_quiet(self):
        w=Workers();old=w.child
        def child(*args,**kwargs):
            result=old(*args,**kwargs)
            if args[5] is False:w.state_safe=False
            return result
        w.child=child
        with self.assertRaisesRegex(wrapper.SafeError,'RECOVERY_NEEDS_REVIEW'):w.run()
        self.assertTrue(all(v=='inactive' for v in w.states.values()))
        self.assertFalse(any(a[1]=='start' for a in w.calls))

    def test_config_or_source_change_that_causes_publisher_refusal_does_not_start_capture(self):
        w=Workers();w.publish_fail='ORDER_OFFER_VARIANT_CONFIGURATION_CHANGED_SINCE_PREFLIGHT';old=w.child
        def child(*args,**kwargs):
            if args[5] is False:w.state_safe=False
            return old(*args,**kwargs)
        w.child=child
        with self.assertRaisesRegex(wrapper.SafeError,'RECOVERY_NEEDS_REVIEW'):w.run()
        self.assertTrue(all(v=='inactive' for v in w.states.values()))
        self.assertFalse(any(a[1]=='start' for a in w.calls))

    def test_changed_original_state_at_idle_timeout_keeps_all_workers_quiet(self):
        w=Workers();w.states[wrapper.UNITS[3]]='active'
        def wait(runner):
            w.state_safe=False;raise wrapper.SafeError('ORDER_OFFER_VARIANT_WORKER_IDLE_TIMEOUT')
        with self.assertRaisesRegex(wrapper.SafeError,'RECOVERY_NEEDS_REVIEW'):
            wrapper.orchestrate(pathlib.Path('/fixture'),RELEASE,1,1,[5,6],w.runner,w.child,w.profile,w.record,wait)
        self.assertTrue(all(v=='inactive' for v in w.states.values()))
        self.assertFalse(any(a[1]=='start' for a in w.calls))

    def test_each_restored_timer_and_fallback_requires_verified_state(self):
        w=Workers();w.states[wrapper.UNITS[0]]='active';w.run()
        targets={p:v[1] for p,v in repair.SOURCE_PINS.items()}
        self.assertGreaterEqual(len(w.guards),5)
        self.assertEqual(w.guards[0],('b'*64,{p:v[0] for p,v in repair.SOURCE_PINS.items()},'c'*64))
        self.assertTrue(all(v==('b'*64,targets,'c'*64) for v in w.guards[1:]))
        w=Workers();w.publish_fail='ORDER_OFFER_VARIANT_ROLLBACK_REVIEW_REQUIRED'
        with self.assertRaises(wrapper.SafeError):w.run()
        before={p:v[0] for p,v in repair.SOURCE_PINS.items()}
        self.assertGreaterEqual(len(w.guards),2)
        self.assertTrue(all(v==('b'*64,before,'c'*64) for v in w.guards))

    def test_direct_restart_helpers_refuse_missing_guard(self):
        w=Workers();before={n:wrapper.unit_state(n,w.runner) for n in wrapper.UNITS};profiles={n:w.profile(n) for n in wrapper.UNITS}
        with self.assertRaisesRegex(wrapper.SafeError,'GUARD_REQUIRED'):wrapper.restore_timers(before,profiles,w.runner,w.profile)
        self.assertFalse(any(a[1]=='start' for a in w.calls))
        with self.assertRaisesRegex(wrapper.SafeError,'GUARD_REQUIRED'):wrapper.fail_closed(profiles,w.runner,w.profile)
        self.assertTrue(all(v=='inactive' for v in w.states.values()))

    def test_both_originally_active_timers_are_restored_and_inactive_services_not_started(self):
        w=Workers();w.states[wrapper.UNITS[0]]='active';w.run()
        self.assertEqual([a[-1] for a in w.calls if a[1]=='start'],[wrapper.UNITS[0],wrapper.UNITS[2]])
        self.assertFalse(any(a[1]=='start' and a[-1].endswith('.service') for a in w.calls))

    def test_no_original_active_timer_is_reactivated_after_publication(self):
        w=Workers();w.states[wrapper.UNITS[2]]='inactive';w.run()
        self.assertTrue(all(v=='inactive' for v in w.states.values()))
        self.assertFalse(any(a[1]=='start' for a in w.calls))

    def test_worker_idle_wait_does_not_stop_running_service(self):
        w=Workers();w.states[wrapper.UNITS[2]]='inactive';w.states[wrapper.UNITS[3]]='active'
        ticks=[0]
        def pause(value):
            ticks[0]+=value
            if ticks[0]>=0.5:w.states[wrapper.UNITS[3]]='inactive'
        wrapper.wait_quiet(w.runner,lambda:ticks[0],pause,1)
        self.assertGreaterEqual(ticks[0],0.5)
        self.assertFalse(any(a[1] in ('start','stop') for a in w.calls))

    def test_idle_timeout_before_publication_restores_original_timer_and_never_invokes_publisher(self):
        w=Workers();w.states[wrapper.UNITS[3]]='active'
        def wait(runner):raise wrapper.SafeError('ORDER_OFFER_VARIANT_WORKER_IDLE_TIMEOUT')
        with self.assertRaisesRegex(wrapper.SafeError,'WORKER_IDLE_TIMEOUT'):
            wrapper.orchestrate(pathlib.Path('/fixture'),RELEASE,1,1,[5,6],w.runner,w.child,w.profile,w.record,wait)
        self.assertEqual([v for v in w.child_calls if v[0]!='state'],[(True,None)])
        self.assertEqual(w.states[wrapper.UNITS[2]],'active')
        self.assertEqual(w.states[wrapper.UNITS[3]],'active')
        self.assertEqual(w.states[wrapper.UNITS[0]],'inactive')
        self.assertEqual(w.phases[-1],'PAUSE_CANCELLED')
        self.assertFalse(any(a[1]=='stop' and a[-1].endswith('.service') for a in w.calls))

    def test_wait_timeout_is_bounded_and_foreign_timer_restart_is_rejected(self):
        w=Workers();w.states[wrapper.UNITS[2]]='inactive';w.states[wrapper.UNITS[3]]='active';ticks=[0]
        with self.assertRaisesRegex(wrapper.SafeError,'WORKER_IDLE_TIMEOUT'):
            wrapper.wait_quiet(w.runner,lambda:ticks[0],lambda n:ticks.__setitem__(0,ticks[0]+n),0.5)
        self.assertEqual(ticks[0],0.5)
        w.states[wrapper.UNITS[0]]='active'
        with self.assertRaisesRegex(wrapper.SafeError,'TIMERS_NOT_PAUSED'):
            wrapper.wait_quiet(w.runner,lambda:0,lambda n:None,0)

    def test_private_pipeline_receipt_records_original_units_and_profiles_without_configuration_bytes(self):
        with tempfile.TemporaryDirectory() as folder:
            root=pathlib.Path(folder)/'app';root.mkdir(mode=0o700)
            parent=root.parent/'whatsapp-release-backups';parent.mkdir(mode=0o700)
            w=Workers();before={n:wrapper.unit_state(n,w.runner) for n in wrapper.UNITS};profiles={n:w.profile(n) for n in wrapper.UNITS}
            with mock.patch.object(wrapper,'ROOT',root):receipt=wrapper.record_pipeline(before,profiles,RELEASE,'b'*64,os.geteuid(),{p:v[0] for p,v in repair.SOURCE_PINS.items()},'c'*64)
            self.assertEqual(stat.S_IMODE(receipt.path.stat().st_mode),0o700)
            value=json.loads((receipt.path/'receipt.json').read_text())
            self.assertEqual(value['original_units'],before);self.assertEqual(value['configuration_fingerprint'],'b'*64)
            self.assertEqual(set(value['unit_profiles']),set(wrapper.UNITS))
            self.assertEqual(value['original_sources'],{p:v[0] for p,v in repair.SOURCE_PINS.items()})
            self.assertEqual(value['protected_fingerprint'],'c'*64)
            receipt.mark('RESTORED')
            for path in receipt.path.iterdir():
                self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600);self.assertEqual(path.stat().st_nlink,1)
            self.assertEqual(json.loads((receipt.path/'phase-00.json').read_text()),{'phase':'RECORDED'})
            self.assertEqual(json.loads((receipt.path/'phase-01.json').read_text()),{'phase':'RESTORED'})
            with self.assertRaisesRegex(wrapper.SafeError,'PHASE_INVALID'):receipt.mark('PRIVATE_ERROR_BODY')

    def test_published_candidates_and_root_publisher_binding_match_exact_real_bytes(self):
        for relative,(_,target) in repair.SOURCE_PINS.items():
            with self.subTest(path=relative):self.assertEqual(repair.blob((ROOT/relative).read_bytes()),target)
        publisher=ROOT/'deployment/apply_whatsapp_order_offer_variant_repair.py'
        self.assertEqual(wrapper.PUBLISHER_BLOB,repair.blob(publisher.read_bytes()))
        self.assertEqual(set(wrapper.HELPERS),{'apply_whatsapp_order_offer_variant_repair.py','install_whatsapp_inbox.py'})
        self.assertEqual(wrapper.HELPERS['install_whatsapp_inbox.py'],'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98')

    def test_real_pins_roster_has_exact_one_file_without_configuration(self):
        target='app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'
        self.assertEqual(len(repair.SOURCE_PINS),1)
        self.assertEqual(list(repair.SOURCE_PINS),[target])
        self.assertEqual(repair.SOURCE_PINS[target][0],'14521ca74822bb0359ccec81c5cb986feaa75da4')
        self.assertNotIn(target,repair.PROTECTED_PINS)
        self.assertEqual(repair.PROTECTED_PINS['app/Support/WhatsAppOrderExtraction.php'],'56d2b9575da152de3eac4876fd93140ee7bc9e39')
        self.assertEqual(repair.PROTECTED_PINS['app/Services/Dashboard/WhatsAppOrderSourceSelection.php'],'03db3211b80da905f07415fd16f1e7a62e0395b1')
        self.assertEqual(repair.PROTECTED_PINS['app/Services/Dashboard/WhatsAppOrderAiProvider.php'],'39f644ff97c7cf6182666a5aff3f6fb231cb0733')
        self.assertEqual(repair.PROTECTED_PINS['app/Services/Dashboard/WhatsAppOrderWorkflow.php'],'b1bdc5962330c5f61a8ab6fa4c640d0fcae8799d')
        self.assertEqual(len(repair.PROTECTED_PINS),15)
        self.assertIn('count($pins)!==1',repair.READINESS_PHP)
        for path in repair.PROTECTED_PINS:self.assertNotIn(path,repair.SOURCE_PINS)
        self.assertNotIn('projectPending(',repair.READINESS_PHP);self.assertNotIn('extract(',repair.READINESS_PHP)
        self.assertNotIn('->process(',repair.READINESS_PHP);self.assertNotIn('->consume(',repair.READINESS_PHP)
        self.assertIn("'whatsapp_reply_requests'",repair.READINESS_PHP);self.assertIn("'whatsapp_reply_projection_keys'",repair.READINESS_PHP)


if __name__=='__main__':unittest.main()
