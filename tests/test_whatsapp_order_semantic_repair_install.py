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
SPEC=importlib.util.spec_from_file_location('order_semantic_repair_install_tested',ROOT/'deployment/apply_whatsapp_order_semantic_repair.py')
repair=importlib.util.module_from_spec(SPEC);sys.modules[SPEC.name]=repair;SPEC.loader.exec_module(repair)
wrapper=types.ModuleType('order_semantic_repair_root_tested');wrapper.__file__=str(ROOT/'deployment/apply_whatsapp_order_semantic_repair_root.sh')
source=pathlib.Path(wrapper.__file__).read_text().split("<<'PY'\n",1)[1].rsplit('\nPY\n',1)[0]
exec(compile(source,wrapper.__file__,'exec'),wrapper.__dict__)
RELEASE='a'*40


class Fixture:
    def __init__(self,directory,cached=True):
        self.root=pathlib.Path(directory)/'app';self.root.mkdir(mode=0o700);(self.root/'.git').mkdir(mode=0o700)
        self.uid=os.geteuid();self.calls=[];self.hook=None;self.fail_lint=False;self.fail_target=False;self.tree_mode=b'100644';self.rollback_compatible=True
        self.pins={};self.targets={};self.old={}
        for index,relative in enumerate(repair.SOURCE_PINS):
            old=None if index==0 else ('<?php /* original '+str(index)+' */\n').encode()
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
        if args[:3]==[repair.PHP,'-r',repair.ROLLBACK_COMPATIBILITY_PHP]:return 0,json.dumps({'compatible':self.rollback_compatible}).encode()
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

    def test_publish_nine_pinned_sources_and_preserve_all_configuration_bytes_and_metadata(self):
        f=self.f;before={p:repair.base.snapshot(f.root/p,f.uid,f.root,True) for p in f.pins}
        result=f.apply();self.assertEqual(result['status'],'WHATSAPP_ORDER_SEMANTIC_REPAIR_READY')
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
        self.assertFalse(list((f.root.parent/'whatsapp-release-backups').glob('order-semantic-*')))

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

    def test_existing_new_dependency_collision_never_overwritten(self):
        f=self.f;p=list(f.pins)[0];f.write(p,b'<?php /* different helper */\n')
        with self.assertRaises(repair.SafeError):f.apply()
        self.assertEqual((f.root/p).read_bytes(),b'<?php /* different helper */\n')

    def test_new_evidence_created_by_web_request_blocks_resuming_restored_baseline(self):
        f=self.f;f.fail_target=True;f.rollback_compatible=False
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        f.originals(self);f.unchanged_config(self)

    def test_empty_current_evidence_does_not_excuse_inflight_http_compatibility_race(self):
        f=self.f;f.fail_target=True;f.rollback_compatible=True
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):f.apply()
        f.originals(self)

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
        self.assertEqual((f.root/list(f.pins)[0]).read_bytes(),f.targets[list(f.pins)[0]])

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


class Workers:
    def __init__(self):
        self.states={name:'inactive' for name in wrapper.UNITS};self.states[wrapper.UNITS[2]]='active'
        self.calls=[];self.fail_order_start=False;self.changed=False;self.child_calls=[];self.publish_fail=None
        self.pending=0;self.recovery_calls=[];self.recovery_fail=None
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
    def child(self,directory,release,uid,gid,held,check,expected=None,recovery_scope=None,backup=None,recover_only=False):
        self.child_calls.append((check,expected))
        if check:return {'ok':True,'result':{'status':'WHATSAPP_ORDER_SEMANTIC_PREFLIGHT_READY','changed':True,'configuration_fingerprint':'b'*64,'recovery_pending':self.pending,'recovery_scope':'c'*64}}
        if recover_only:
            self.recovery_calls.append((recovery_scope,backup))
            if self.recovery_fail:return {'ok':False,'status':self.recovery_fail}
            return {'ok':True,'result':{'status':'WHATSAPP_ORDER_SEMANTIC_RECOVERY_READY','backup':'fixture-recovery',
                    'metrics':{'events_seen':13,'messages_hydrated':13},'configuration_fingerprint':'b'*64}}
        if self.publish_fail:return {'ok':False,'status':self.publish_fail}
        return {'ok':True,'result':{'status':'WHATSAPP_ORDER_SEMANTIC_REPAIR_READY','backup':'fixture-only','configuration_fingerprint':'b'*64}}
    def run(self):return wrapper.orchestrate(pathlib.Path('/fixture'),RELEASE,1,1,[5,6],self.runner,self.child,self.profile)


class RepairRootRecoveryTests(unittest.TestCase):
    def test_preflight_precedes_worker_pause_and_restores_only_original_active_pipeline(self):
        w=Workers();result=w.run();self.assertIn('REPAIR_READY',result['status']);self.assertEqual(w.child_calls,[(True,None),(True,'b'*64),(False,'b'*64)])
        self.assertEqual(w.states[wrapper.UNITS[2]],'active');self.assertEqual(w.states[wrapper.UNITS[0]],'inactive')
        self.assertEqual([a[-1] for a in w.calls if a[1]=='start'],[wrapper.UNITS[2]])

    def test_already_current_preflight_does_not_stop_or_start_any_unit(self):
        w=Workers();old=w.child
        def child(*a,**kw):
            result=old(*a,**kw);result['result']['changed']=False;return result
        w.child=child;self.assertIn('ALREADY_CURRENT',w.run()['status']);self.assertEqual(w.calls,[])

    def test_unsafe_source_preflight_causes_no_worker_actions(self):
        w=Workers();w.child=lambda *a,**kw:{'ok':False,'status':'ORDER_SEMANTIC_REPAIR_LIVE_SOURCE_NEEDS_REVIEW'}
        with self.assertRaisesRegex(wrapper.SafeError,'LIVE_SOURCE'):w.run()
        self.assertEqual(w.calls,[])

    def test_confirmed_file_rollback_restores_prior_pipeline(self):
        w=Workers();w.publish_fail='ORDER_SEMANTIC_REPAIR_FILES_RESTORED; BACKUP=/home/fasakha/whatsapp-release-backups/order-semantic-fixture'
        with self.assertRaisesRegex(wrapper.SafeError,'FILES_RESTORED'):w.run()
        self.assertEqual(w.states[wrapper.UNITS[2]],'active');self.assertEqual(w.states[wrapper.UNITS[0]],'inactive')

    def test_uncertain_rollback_keeps_orders_paused_and_capture_active(self):
        w=Workers();w.publish_fail='ORDER_SEMANTIC_REPAIR_ROLLBACK_REVIEW_REQUIRED'
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

    def test_real_pins_roster_has_exact_nine_files_without_configuration(self):
        self.assertEqual(len(repair.SOURCE_PINS),9)
        self.assertTrue(all((p.startswith('app/Services/Dashboard/') or p=='app/Support/WhatsAppOrderExtraction.php' or
            p=='app/Http/Controllers/Dashboard/WhatsAppInboxController.php') and p.endswith('.php') for p in repair.SOURCE_PINS))
        self.assertNotIn('projectPending(',repair.READINESS_PHP);self.assertNotIn('extract(',repair.READINESS_PHP)
        self.assertNotIn('->process(',repair.READINESS_PHP);self.assertNotIn('->consume(',repair.READINESS_PHP)
        self.assertIn("'whatsapp_reply_requests'",repair.READINESS_PHP);self.assertIn("'whatsapp_reply_projection_keys'",repair.READINESS_PHP)

    def test_code_publication_and_fixed_recovery_share_one_pause_before_original_timer_restore(self):
        w=Workers();w.pending=13;result=w.run()
        self.assertEqual(w.recovery_calls,[('c'*64,'fixture-only')]);self.assertEqual(result['recovery_backup'],'fixture-recovery')
        self.assertEqual(len([args for args in w.calls if args[1]=='stop']),1)
        self.assertEqual([args[-1] for args in w.calls if args[1]=='start'],[wrapper.UNITS[2]])

    def test_quiescent_database_scope_refresh_is_used_for_recovery_cas(self):
        w=Workers();w.pending=13;old=w.child;count=[0]
        def refreshed(*args,**kwargs):
            result=old(*args,**kwargs)
            if args[5]:
                count[0]+=1
                if count[0]==2:result['result']['recovery_scope']='d'*64
            return result
        w.child=refreshed;w.run();self.assertEqual(w.recovery_calls[0][0],'d'*64)

    def test_recovery_failure_retains_new_code_and_keeps_orders_paused_capture_active(self):
        w=Workers();w.pending=13;w.recovery_fail='ORDER_SEMANTIC_REPAIR_RECOVERY_NEEDS_REVIEW'
        with self.assertRaisesRegex(wrapper.SafeError,'ORDERS_PAUSED_CAPTURE_ACTIVE'):w.run()
        self.assertEqual(w.states[wrapper.UNITS[2]],'inactive');self.assertEqual(w.states[wrapper.UNITS[0]],'active')
        self.assertEqual(len([call for call in w.child_calls if call[0] is False]),2)

    def test_pipeline_journal_records_prior_state_quiescence_and_recovery_before_resume(self):
        w=Workers();w.pending=13;rows=[]
        wrapper.orchestrate(pathlib.Path('/fixture'),RELEASE,1,1,[5,6],w.runner,w.child,w.profile,rows.append)
        self.assertEqual([row['phase'] for row in rows],['PRIOR_PIPELINES','QUIESCENT','CODE_PUBLISHED','RECOVERY_COMMITTED','PRIOR_PIPELINES_RESTORED'])
        self.assertEqual(rows[0]['before'][wrapper.UNITS[2]]['ActiveState'],'active')

    def test_fixed_recovery_roster_excludes_unresolved_status_event(self):
        self.assertEqual(repair.RECOVERY_EVENT_IDS,(5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338))
        self.assertNotIn(7215,repair.RECOVERY_EVENT_IDS)


if __name__=='__main__':unittest.main()
