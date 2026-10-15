#!/usr/bin/env python3
"""Temporary application files and mocked workers; never external APIs or real services."""
import hashlib
import importlib.util
import json
import os
import pathlib
import shutil
import stat
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT=pathlib.Path(__file__).resolve().parents[1]
TEST_PHP=os.environ.get('META_AI_TEST_PHP') or shutil.which('php')
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);module=importlib.util.module_from_spec(spec)
    sys.modules[name]=module;spec.loader.exec_module(module);return module
repair=load('meta_ai_installer_tested',ROOT/'deployment/apply_meta_ai_control.py')
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
        path=self.root/relative
        if content is None:path.unlink(missing_ok=True);return
        path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(content);path.chmod(mode)

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



wrapper=types.ModuleType('meta_ai_root_tested');wrapper.__file__=str(ROOT/'deployment/apply_meta_ai_control_root.sh')
exec(compile(pathlib.Path(wrapper.__file__).read_text().split("<<'PY'\n",1)[1].rsplit('\nPY\n',1)[0],wrapper.__file__,'exec'),wrapper.__dict__)

class AppFixture:
    def __init__(self,folder,cached=True):
        self.inner=Fixture(folder,cached=cached)
        for path,(old,_) in repair.SOURCE_PINS.items():
            if old is None:
                (self.inner.root/path).unlink();self.inner.old[path]=None
                self.inner.pins[path]=(None,self.inner.pins[path][1])
        self.baseline={path:pair[0] for path,pair in self.inner.pins.items()}
        self.previous_target_sets={}
        for profile,pins in repair.PREVIOUS_CONTROL_SOURCE_PROFILES.items():
            self.previous_target_sets[profile]={path:None if pin is None else self.inner.targets[path] if pin==repair.SOURCE_PINS[path][1]
                else ('<?php /* reviewed prior '+path+' '+pin+' */\n').encode() for path,pin in pins.items()}
        self.previous_targets=self.previous_target_sets['META_AI_CONTROL_V1']
        self.previous_profiles={profile:{path:None if data is None else repair.blob(data) for path,data in targets.items()}
            for profile,targets in self.previous_target_sets.items()}
        self.profiles={'WHATSAPP_TAIL':dict(self.baseline),'WHATSAPP_CONTEXTUAL':dict(self.baseline)}
        self.context={}
        for path in list(self.baseline)[:3]:
            data=('<?php /* reviewed prior context '+path+' */\n').encode()
            self.context[path]=data;self.profiles['WHATSAPP_CONTEXTUAL'][path]=repair.blob(data)
        self.hub={}
        for path in repair.HUB_SOURCE_OVERRIDES:
            data=('<?php /* shipped hub '+path+' */\n').encode()
            self.hub[path]=data
        for baseline in ('WHATSAPP_TAIL','WHATSAPP_CONTEXTUAL'):
            self.profiles[baseline+'_HUB']=dict(self.profiles[baseline],**{path:repair.blob(data) for path,data in self.hub.items()})
        self.hub_foundation=b'<?php /* shipped hub foundation */\n'
        self.catalog_path='app/Support/Catalog.php'
        self.catalog_original=b'<?php /* original catalog */\n'
        self.catalog_locked=b'<?php /* reviewed optional catalog lock */\n'
        self.protected[self.catalog_path]=repair.blob(self.catalog_original)
        self.write(self.catalog_path,self.catalog_original)
        self.protected_profiles={'META_UNIFIED_MESSAGES':dict(self.protected),
            'META_MESSAGE_HUB':dict(self.protected,**{'app/Support/Immutable.php':repair.blob(self.hub_foundation)})}
        for foundation in ('META_UNIFIED_MESSAGES','META_MESSAGE_HUB'):
            self.protected_profiles[foundation+'_CATALOG_LOCK']=dict(self.protected_profiles[foundation],
                **{self.catalog_path:repair.blob(self.catalog_locked)})
        self.controls={'present':False,'fingerprint':None,'enabled':False,'default_disabled':False}
        self.fail_migrate=False;self.bad_seed=False
        self.runner_original=self.inner.runner
        def runner(root,args,**kwargs):
            if args[:3]==[repair.PHP,'-r',repair.CONTROL_STATE_PHP]:
                self.inner.calls.append(tuple(args));return 0,json.dumps(self.controls).encode()
            if args[:3]==[repair.PHP,'artisan','migrate']:
                self.inner.calls.append(tuple(args))
                if self.fail_migrate:return 1,b'PRIVATE_SQL_EXCEPTION'
                self.controls={'present':True,'fingerprint':'d'*64,'enabled':self.bad_seed,'default_disabled':not self.bad_seed}
                return 0,b'PRIVATE_MIGRATION_OUTPUT'
            return self.runner_original(root,args,**kwargs)
        self.inner.runner=runner
    def __getattr__(self,key):return getattr(self.inner,key)
    def patches(self):
        return mock.patch.multiple(repair,SOURCE_PINS=self.pins,PROTECTED_PINS=self.protected,
            PROTECTED_PROFILES=self.protected_profiles,BASELINE_SOURCE_PROFILES=self.profiles,
            PREVIOUS_CONTROL_SOURCE_PROFILES=self.previous_profiles)
    def apply(self,check=False,**kwargs):
        with self.patches():return repair.apply(self.root,self.uid,'a'*40,check,runner=self.runner,pins=self.pins,**kwargs)

class WorkerFixture:
    def __init__(self):
        self.states={name:'inactive' for name in wrapper.UNITS};self.states[wrapper.UNITS[0]]='active';self.states[wrapper.UNITS[4]]='active'
        self.new_present=False;self.calls=[];self.phases=[];self.state_safe=True;self.unit_changed=False;self.fail_enable=False;self.fail_publish=False
        self.foundation='META_UNIFIED_MESSAGES'
    def profile(self,name):
        if name in wrapper.NEW_UNITS and not self.new_present:return None
        return (name,'changed' if self.unit_changed else 'reviewed')
    def runner(self,args):
        self.calls.append(tuple(args));op=args[1]
        if op=='show':
            name=args[-1];absent=name in wrapper.NEW_UNITS and not self.new_present
            data='LoadState='+('not-found' if absent else 'loaded')+'\nActiveState='+self.states[name]+'\nFragmentPath='+('' if absent else str(wrapper.SYSTEMD_DIR/name))+'\n'
            if name.endswith('.service'):data+='MainPID=0\n'
            return (1 if absent else 0),data.encode()
        if op=='stop':
            for name in args[2:]:self.states[name]='inactive'
            return 0,b''
        if op=='start':self.states[args[-1]]='active';return 0,b''
        if op=='enable':return (1 if self.fail_enable else 0),b''
        if op=='daemon-reload':return 0,b''
        raise AssertionError(args)
    def child(self,directory,release,uid,gid,held,check,expected=None,sources=None,protected=None,profile=None,control=None):
        if check=='state':
            if not self.state_safe:return {'ok':False,'status':'META_AI_CONTROL_STATE_CONTROL_CHANGED'}
            return {'ok':True,'result':{'status':'META_AI_CONTROL_STATE_READY','configuration_fingerprint':expected,
                'sources':sources,'protected_fingerprint':protected,'protected_profile':profile,'control_fingerprint':control}}
        if check:return {'ok':True,'result':{'status':'META_AI_CONTROL_PREFLIGHT_READY','changed':True,'configuration_fingerprint':'b'*64,
            'sources':{path:pair[0] for path,pair in repair.SOURCE_PINS.items()},'protected_fingerprint':'c'*64,
            'protected_profile':self.foundation,'control_fingerprint':None}}
        return {'ok':True,'result':{'status':'META_AI_CONTROL_REPAIR_READY','backup':'fixture','configuration_fingerprint':'b'*64,
            'sources':{path:pair[1] for path,pair in repair.SOURCE_PINS.items()},'protected_fingerprint':'c'*64,
            'protected_profile':self.foundation,'control_fingerprint':'d'*64}}
    def record(self,*args):return types.SimpleNamespace(path=pathlib.Path('/fixture/pipeline'),mark=self.phases.append)
    def publish(self,*args):
        if self.fail_publish:raise wrapper.SafeError('META_AI_CONTROL_UNIT_PUBLICATION_REVIEW_REQUIRED')
        self.new_present=True;return '/fixture/unit-backup'
    def run(self,child=None):
        return wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,0,0,[],self.runner,child or self.child,
            self.profile,self.record,units=lambda *args:{name:b'reviewed' for name in wrapper.NEW_UNITS},publish=self.publish)

class MetaAiInstallTests(unittest.TestCase):
    def setUp(self):self.temp=tempfile.TemporaryDirectory();self.f=AppFixture(self.temp.name)
    def tearDown(self):self.temp.cleanup()
    def test_exact_declared_scope_has_one_specific_new_schema_and_no_configuration(self):
        self.assertEqual(len(repair.SOURCE_PINS),25)
        self.assertEqual(wrapper.SOURCE_ROSTER,tuple(repair.SOURCE_PINS))
        self.assertEqual([path for path in repair.SOURCE_PINS if path.startswith('database/')],[repair.MIGRATION])
        self.assertFalse(any(path.startswith('config/') or path=='.env' for path in repair.SOURCE_PINS))
        self.assertFalse(set(repair.SOURCE_PINS)&set(repair.PROTECTED_PINS))
        self.assertEqual(repair.PROTECTED_PINS['app/Services/Dashboard/WhatsAppInboxAccess.php'],'186789d42113e81818de8d82a2327e3ee9d8d0b5')
    def test_final_pins_bind_all_runtime_and_unit_bytes(self):
        for path,(_,pin) in repair.SOURCE_PINS.items():
            with self.subTest(path=path):self.assertEqual(pin,repair.blob((ROOT/path).read_bytes()))
        self.assertEqual(wrapper.PUBLISHER_BLOB,repair.blob((ROOT/'deployment/apply_meta_ai_control.py').read_bytes()))
        for name,pin in wrapper.UNIT_PINS.items():self.assertEqual(pin,repair.blob((ROOT/'deployment/systemd'/name).read_bytes()))
    def test_catalog_compatibility_uses_only_complete_exact_protected_profiles(self):
        self.assertEqual(set(repair.PROTECTED_PROFILES),wrapper.PROFILE_NAMES)
        self.assertEqual(set(repair.PROTECTED_PROFILES),set(repair.PROTECTED_PROFILE_FOUNDATIONS))
        self.assertEqual(repair.CATALOG_LOCK_OVERRIDE,{
            'app/Services/Dashboard/TakeawayCatalog.php':'2ff0e19675d8678dfb6ca738767eeaa99f95dc47'})
        for foundation in ('META_UNIFIED_MESSAGES','META_MESSAGE_HUB'):
            profile=foundation+'_CATALOG_LOCK'
            self.assertEqual(repair.PROTECTED_PROFILES[profile],
                dict(repair.PROTECTED_PROFILES[foundation],**repair.CATALOG_LOCK_OVERRIDE))
            self.assertEqual(repair.PROTECTED_PROFILE_FOUNDATIONS[profile],foundation)
        self.assertFalse(set(repair.CATALOG_LOCK_OVERRIDE)&set(repair.SOURCE_PINS))
    def test_previous_ai_runtimes_are_complete_reviewed_upgrade_profiles(self):
        self.assertEqual(set(repair.PREVIOUS_CONTROL_SOURCE_PROFILES),
            {'META_AI_CONTROL_V1','META_AI_CONTROL_SAVE_BUTTON','META_AI_CONTROL_UNANSWERED_HOURS',
             'META_AI_CONTROL_INBOX_REPAIR','META_AI_CONTROL_CUSTOMER_ANSWERS'})
        first=repair.PREVIOUS_CONTROL_SOURCE_PROFILES['META_AI_CONTROL_V1']
        saved=repair.PREVIOUS_CONTROL_SOURCE_PROFILES['META_AI_CONTROL_SAVE_BUTTON']
        hours=repair.PREVIOUS_CONTROL_SOURCE_PROFILES['META_AI_CONTROL_UNANSWERED_HOURS']
        inbox=repair.PREVIOUS_CONTROL_SOURCE_PROFILES['META_AI_CONTROL_INBOX_REPAIR']
        answers=repair.PREVIOUS_CONTROL_SOURCE_PROFILES['META_AI_CONTROL_CUSTOMER_ANSWERS']
        viewport='public/css/dashboard-meta-inbox-viewport.css'
        for previous in (first,saved,hours):
            self.assertEqual(tuple(previous),tuple(repair.SOURCE_PINS))
            self.assertIsNone(previous[viewport])
        self.assertEqual(tuple(inbox),tuple(repair.SOURCE_PINS))
        self.assertEqual(inbox[viewport],repair.SOURCE_PINS[viewport][1])
        self.assertEqual(tuple(answers),tuple(repair.SOURCE_PINS))
        self.assertEqual(answers[viewport],repair.SOURCE_PINS[viewport][1])
        self.assertEqual(first['public/js/dashboard-meta-ai-controls.js'],'fc34fca42cf8dd80f7916bacc1780444a51afab4')
        self.assertEqual(saved['public/js/dashboard-meta-ai-controls.js'],'00589d95df07646aaa8db71309e098bccd36bd2e')
        self.assertEqual([path for path in saved if saved[path]!=first[path]],['public/js/dashboard-meta-ai-controls.js'])
        required={'app/Support/MetaAiSchedule.php','app/Services/Dashboard/MetaAiControl.php',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php','app/Services/Dashboard/MetaOrderWorkflow.php',
            'resources/views/admin/meta/ai_controls.blade.php','public/js/dashboard-meta-ai-controls.js'}
        required.add(viewport)
        dialogue={'app/Support/WhatsAppOrderDialogue.php','app/Services/Dashboard/WhatsAppOrderAiProvider.php',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php'}
        episode={'app/Support/WhatsAppOrderExtraction.php','app/Services/Dashboard/WhatsAppOrderConversationEvidence.php',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php','app/Services/Dashboard/WhatsAppOrderAiProvider.php'}
        optional={'app/Services/Dashboard/WhatsAppOrderFollowupService.php'}
        required|=dialogue|episode
        allowed=required|{'app/Console/Commands/ProcessMetaAiMessages.php'}|optional
        for previous in (first,saved):
            changed={path for path,(_,target) in repair.SOURCE_PINS.items() if previous[path]!=target}
            self.assertTrue(required<=changed<=allowed)
        hours_required=dialogue|episode|{'resources/views/admin/meta/ai_controls.blade.php',viewport}
        self.assertTrue(hours_required<={path for path,(_,target) in repair.SOURCE_PINS.items() if hours[path]!=target}<=hours_required|optional)
        self.assertTrue(dialogue|episode<={path for path,(_,target) in repair.SOURCE_PINS.items() if inbox[path]!=target}<=dialogue|episode|optional)
        self.assertEqual({path for path,(_,target) in repair.SOURCE_PINS.items() if answers[path]!=target},episode)
    @unittest.skipUnless(TEST_PHP,'PHP is required for the raw database policy shape probe')
    def test_control_probe_accepts_legacy_and_dispatch_schedules_without_normalizing_stored_rows(self):
        stub=r'''<?php
namespace Illuminate\Support\Facades {
 class Schema {
  static function hasTable($table){return in_array($table,['migrations','meta_ai_controls'],true);}
  static function getColumnListing($table){return ['id','policy','activations','handoff','revision','actor_id','updated_by','created_at','updated_at'];}
 }
 class DB { static function table($table){return new \MetaAiProbe\Query();} }
}
namespace MetaAiProbe {
 class Query {
  function where(...$args){return $this;} function count(){return 1;}
  function select(...$args){return $this;} function selectRaw(...$args){return $this;}
  function first(){return json_decode(getenv('META_AI_TEST_ROW'),false,32,JSON_THROW_ON_ERROR);}
 }
 class App {function make($class){return new Kernel();}}
 class Kernel {function bootstrap(){}}
}
'''
        schedule={'enabled':True,'days':[0,1,2,3,4,5,6],'start':'09:00','end':'00:30','all_day':False}
        legacy={'enabled':True,'channels':['whatsapp'],'schedule':schedule}
        dispatch=dict(legacy,dispatch_schedule=dict(schedule,days=[2,3],start='10:00',end='23:40'))
        dispatch_all_day=dict(dispatch,dispatch_schedule=dict(dispatch['dispatch_schedule'],all_day=True))
        cases=[(legacy,True),(dispatch,True),(dispatch_all_day,True),(dict(dispatch,enabled=False),True),(dict(legacy,unknown=schedule),False),
            (dict(legacy,dispatch_schedule='09:00'),False),(dict(dispatch,extra=True),False)]
        with tempfile.TemporaryDirectory() as folder:
            root=pathlib.Path(folder);(root/'vendor').mkdir();(root/'bootstrap').mkdir()
            (root/'vendor/autoload.php').write_text(stub)
            (root/'bootstrap/app.php').write_text('<?php return new \\MetaAiProbe\\App();\n')
            for policy,accepted in cases:
                with self.subTest(policy_keys=list(policy)):
                    row={'id':1,'revision':10,'actor_id':123,'updated_by':123,'created_at':'2026-10-14 00:00:00',
                        'updated_at':'2026-10-15 00:00:00','policy':json.dumps(policy,indent=1),
                        'activations':' {"whatsapp":{"floor":500,"revision":10}} ',
                        'handoff':' {"confirmed":true} ','handoff_oversized':0}
                    env=dict(os.environ,META_AI_TEST_ROW=json.dumps(row,separators=(',',':')))
                    result=subprocess.run([TEST_PHP,'-r',repair.CONTROL_STATE_PHP],cwd=root,env=env,
                        stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=10)
                    if not accepted:
                        self.assertNotEqual(result.returncode,0);self.assertEqual(result.stdout,b'');continue
                    self.assertEqual(result.returncode,0)
                    state=json.loads(result.stdout)
                    self.assertTrue(state['present']);self.assertEqual(state['enabled'],policy['enabled'])
                    self.assertFalse(state['default_disabled'])
                    expected=hashlib.sha256(json.dumps(row,separators=(',',':')).encode()).hexdigest()
                    self.assertEqual(state['fingerprint'],expected)
    def test_readonly_preflight_never_migrates_publishes_or_stops_workers(self):
        out=self.f.apply(True);self.assertEqual(out['status'],'META_AI_CONTROL_PREFLIGHT_READY')
        self.f.originals(self);self.f.unchanged_config(self)
        self.assertFalse(any(args[0]=='/usr/bin/systemctl' or 'artisan' in args for args in self.f.calls))
        self.assertIsNone(out['control_fingerprint'])
    def test_publication_creates_only_disabled_new_policy_and_specific_migration(self):
        out=self.f.apply();self.assertTrue(out['migration_applied']);self.assertFalse(self.f.controls['enabled'])
        migrations=[args for args in self.f.calls if 'artisan' in args]
        self.assertEqual(migrations,[(repair.PHP,'artisan','migrate','--force','--path='+repair.MIGRATION)])
        self.f.unchanged_config(self)
        self.assertEqual(out['source_profile'],'CURRENT')
        for path,content in self.f.targets.items():self.assertEqual((self.f.root/path).read_bytes(),content)
    def test_repeat_retains_all_inodes_policy_and_no_second_migration(self):
        self.f.apply();before={path:repair.metadata(self.f.root/path) for path in self.f.pins};policy=dict(self.f.controls)
        self.f.calls.clear();out=self.f.apply()
        self.assertEqual(out['status'],'META_AI_CONTROL_ALREADY_CURRENT');self.assertIsNone(out['backup'])
        self.assertEqual(policy,self.f.controls);self.assertEqual(before,{path:repair.metadata(self.f.root/path) for path in self.f.pins})
        self.assertFalse(any('artisan' in args for args in self.f.calls));self.f.unchanged_config(self)
    def test_full_reviewed_context_profile_is_accepted_without_per_file_mixing(self):
        for path,content in self.f.context.items():self.f.write(path,content)
        self.assertEqual(self.f.apply(True)['source_profile'],'WHATSAPP_CONTEXTUAL')
    def test_mixed_old_reviewed_profiles_reject_before_commands(self):
        path,content=next(iter(self.f.context.items()));self.f.write(path,content)
        with self.assertRaisesRegex(repair.SafeError,'LIVE_SOURCE_NEEDS_REVIEW'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_complete_shipped_hub_profile_preserves_readonly_foundation(self):
        for path,data in self.f.hub.items():self.f.write(path,data)
        self.f.write('app/Support/Immutable.php',self.f.hub_foundation)
        before=repair.metadata(self.f.root/'app/Support/Immutable.php')
        result=self.f.apply()
        self.assertEqual(result['protected_profile'],'META_MESSAGE_HUB')
        self.assertEqual(repair.metadata(self.f.root/'app/Support/Immutable.php'),before)
    def test_complete_contextual_hub_profile_is_accepted(self):
        for path,data in dict(self.f.context,**self.f.hub).items():self.f.write(path,data)
        self.f.write('app/Support/Immutable.php',self.f.hub_foundation)
        self.assertEqual(self.f.apply(True)['source_profile'],'WHATSAPP_CONTEXTUAL_HUB')
    def test_hub_targets_with_legacy_foundation_reject_before_commands(self):
        for path,data in self.f.hub.items():self.f.write(path,data)
        with self.assertRaisesRegex(repair.SafeError,'SOURCE_FOUNDATION_PAIR'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_hub_foundation_with_legacy_targets_reject_before_commands(self):
        self.f.write('app/Support/Immutable.php',self.f.hub_foundation)
        with self.assertRaisesRegex(repair.SafeError,'SOURCE_FOUNDATION_PAIR'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_state_restart_guard_rejects_a_crossed_complete_profile_pair(self):
        with self.f.patches(),self.assertRaisesRegex(repair.SafeError,'STATE_PROFILE_PAIR_INVALID'):
            repair.verify_state(self.f.root,self.f.uid,'b'*64,self.f.profiles['WHATSAPP_TAIL_HUB'],
                'c'*64,expected_profile='META_UNIFIED_MESSAGES')
    def test_partial_shipped_hub_target_set_is_not_adopted(self):
        path,data=next(iter(self.f.hub.items()));self.f.write(path,data)
        self.f.write('app/Support/Immutable.php',self.f.hub_foundation)
        with self.assertRaisesRegex(repair.SafeError,'LIVE_SOURCE_NEEDS_REVIEW'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_root_orchestrator_preserves_hub_profile_through_restart_guards(self):
        workers=WorkerFixture();workers.foundation='META_MESSAGE_HUB'
        self.assertEqual(workers.run()['protected_profile'],'META_MESSAGE_HUB')
    def test_catalog_profiles_preflight_publish_and_verify_current_without_catalog_writes(self):
        for hub in (False,True):
            for contextual in (False,True):
                with self.subTest(hub=hub,contextual=contextual),tempfile.TemporaryDirectory() as folder:
                    fixture=AppFixture(folder)
                    if contextual:
                        for path,data in fixture.context.items():fixture.write(path,data)
                    if hub:
                        for path,data in fixture.hub.items():fixture.write(path,data)
                        fixture.write('app/Support/Immutable.php',fixture.hub_foundation)
                    fixture.write(fixture.catalog_path,fixture.catalog_locked)
                    expected_profile=('META_MESSAGE_HUB' if hub else 'META_UNIFIED_MESSAGES')+'_CATALOG_LOCK'
                    expected_source=('WHATSAPP_CONTEXTUAL' if contextual else 'WHATSAPP_TAIL')+('_HUB' if hub else '')
                    before={path:repair.metadata(fixture.root/path) for path in fixture.protected}
                    source_before={path:(fixture.root/path).read_bytes() if (fixture.root/path).exists() else None
                        for path in fixture.pins}
                    preflight=fixture.apply(True)
                    self.assertEqual(preflight['protected_profile'],expected_profile)
                    self.assertEqual(preflight['source_profile'],expected_source)
                    self.assertEqual(source_before,{path:(fixture.root/path).read_bytes() if (fixture.root/path).exists() else None
                        for path in fixture.pins})
                    fixture.unchanged_config(self)
                    self.assertFalse(any('artisan' in args or args[0]=='/usr/bin/systemctl' for args in fixture.calls))
                    published=fixture.apply()
                    self.assertEqual(published['protected_profile'],expected_profile)
                    self.assertEqual(before,{path:repair.metadata(fixture.root/path) for path in fixture.protected})
                    receipt=json.loads((pathlib.Path(published['backup'])/'receipt.json').read_text())
                    self.assertEqual(receipt['protected_profile'],expected_profile)
                    self.assertEqual(receipt['protected'],fixture.protected_profiles[expected_profile])
                    fixture.controls={'present':True,'fingerprint':'e'*64,'enabled':True,'default_disabled':False}
                    current=fixture.apply()
                    self.assertEqual(current['status'],'META_AI_CONTROL_ALREADY_CURRENT')
                    self.assertTrue(fixture.controls['enabled'])
                    with fixture.patches(),mock.patch.object(repair.base,'command',fixture.runner):
                        verified=repair.verify_state(fixture.root,fixture.uid,current['configuration_fingerprint'],
                            current['sources'],current['protected_fingerprint'],expected_profile=expected_profile,
                            expected_control=current['control_fingerprint'])
                    self.assertEqual(verified['protected_profile'],expected_profile)
                    self.assertEqual(before,{path:repair.metadata(fixture.root/path) for path in fixture.protected})
    def test_catalog_profile_with_crossed_source_foundation_rejects_before_commands(self):
        self.f.write(self.f.catalog_path,self.f.catalog_locked)
        for path,data in self.f.hub.items():self.f.write(path,data)
        with self.assertRaisesRegex(repair.SafeError,'SOURCE_FOUNDATION_PAIR'):self.f.apply()
        self.assertEqual(self.f.calls,[])
        for path,content in self.f.old.items():
            if content is not None:self.f.write(path,content)
        self.f.write('app/Support/Immutable.php',self.f.hub_foundation)
        with self.assertRaisesRegex(repair.SafeError,'SOURCE_FOUNDATION_PAIR'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_catalog_restart_guard_refuses_crossed_complete_foundation(self):
        with self.f.patches(),self.assertRaisesRegex(repair.SafeError,'STATE_PROFILE_PAIR_INVALID'):
            repair.verify_state(self.f.root,self.f.uid,'b'*64,self.f.profiles['WHATSAPP_TAIL_HUB'],
                'c'*64,expected_profile='META_UNIFIED_MESSAGES_CATALOG_LOCK')
    def test_catalog_restart_guard_refuses_change_to_another_reviewed_catalog(self):
        self.f.write(self.f.catalog_path,self.f.catalog_locked)
        out=self.f.apply()
        self.f.write(self.f.catalog_path,self.f.catalog_original)
        with self.f.patches(),mock.patch.object(repair.base,'command',self.f.runner),\
                self.assertRaisesRegex(repair.SafeError,'STATE_PROTECTED_CHANGED'):
            repair.verify_state(self.f.root,self.f.uid,out['configuration_fingerprint'],out['sources'],
                out['protected_fingerprint'],expected_profile=out['protected_profile'],expected_control=out['control_fingerprint'])
    def test_catalog_variants_still_refuse_unreviewed_protected_drift(self):
        self.f.write(self.f.catalog_path,self.f.catalog_locked)
        self.f.write('app/Support/Immutable.php',b'<?php /* unrelated drift */\n')
        with self.assertRaisesRegex(repair.SafeError,'PROTECTED_SOURCE_MISMATCH'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_catalog_change_during_preflight_never_reaches_publication(self):
        self.f.write(self.f.catalog_path,self.f.catalog_locked)
        changed=[False]
        def hook(args):
            if args[:2]==[repair.PHP,'-l'] and not changed[0]:
                changed[0]=True;self.f.write(self.f.catalog_path,self.f.catalog_original)
        self.f.inner.hook=hook
        with self.assertRaisesRegex(repair.SafeError,'PROTECTED_SOURCE_CHANGED'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)
        self.assertFalse(any('artisan' in args or args[0]=='/usr/bin/systemctl' for args in self.f.calls))
    def test_root_orchestrator_preserves_catalog_variant_in_each_restart_guard(self):
        for foundation in ('META_UNIFIED_MESSAGES_CATALOG_LOCK','META_MESSAGE_HUB_CATALOG_LOCK'):
            with self.subTest(foundation=foundation):
                worker=WorkerFixture();worker.foundation=foundation;calls=[];original=worker.child
                def child(*args,**kwargs):
                    calls.append((args,kwargs));return original(*args,**kwargs)
                result=worker.run(child)
                self.assertEqual(result['protected_profile'],foundation)
                state_calls=[args for args,kwargs in calls if args[5]=='state']
                self.assertTrue(state_calls)
                self.assertTrue(all(args[9]==foundation for args in state_calls))
    def test_previous_ai_control_upgrade_changes_only_declared_targets_and_retains_active_or_off_policy(self):
        for previous_profile in repair.PREVIOUS_CONTROL_SOURCE_PROFILES:
            for profile in repair.PROTECTED_PROFILES:
                for enabled in (False,True):
                    with self.subTest(previous=previous_profile,profile=profile,enabled=enabled),tempfile.TemporaryDirectory() as folder:
                        fixture=AppFixture(folder)
                        for path,data in fixture.previous_target_sets[previous_profile].items():fixture.write(path,data)
                        if profile.startswith('META_MESSAGE_HUB'):
                            fixture.write('app/Support/Immutable.php',fixture.hub_foundation)
                        if profile.endswith('_CATALOG_LOCK'):
                            fixture.write(fixture.catalog_path,fixture.catalog_locked)
                        fixture.controls={'present':True,'fingerprint':'e'*64,'enabled':enabled,'default_disabled':False}
                        policy=dict(fixture.controls)
                        changes=[path for path,pair in fixture.pins.items() if fixture.previous_profiles[previous_profile][path]!=pair[1]]
                        self.assertTrue(changes)
                        before={path:repair.metadata(fixture.root/path) if (fixture.root/path).exists() else None for path in fixture.pins}
                        protected_before={path:repair.metadata(fixture.root/path) for path in fixture.protected}
                        preflight=fixture.apply(True)
                        self.assertEqual(preflight['source_profile'],previous_profile)
                        self.assertEqual(preflight['protected_profile'],profile)
                        self.assertEqual(before,{path:repair.metadata(fixture.root/path) if (fixture.root/path).exists() else None for path in fixture.pins})
                        with fixture.patches(),mock.patch.object(repair.base,'command',fixture.runner):
                            verified=repair.verify_state(fixture.root,fixture.uid,preflight['configuration_fingerprint'],
                                preflight['sources'],preflight['protected_fingerprint'],expected_profile=profile,
                                expected_control=preflight['control_fingerprint'])
                        self.assertEqual(verified['sources'],fixture.previous_profiles[previous_profile])
                        original=repair.base.replace_file
                        with mock.patch.object(repair.base,'replace_file',wraps=original) as replace:
                            out=fixture.apply(expected_configuration=preflight['configuration_fingerprint'],
                                expected_protected=preflight['protected_fingerprint'],expected_profile=profile,
                                expected_control=preflight['control_fingerprint'])
                        publication_order=sorted(changes,key=lambda path:path!='public/css/dashboard-meta-inbox-viewport.css')
                        self.assertEqual([str(call.args[0].relative_to(fixture.root)) for call in replace.call_args_list],publication_order)
                        self.assertEqual([path for path in fixture.pins if before[path]!=repair.metadata(fixture.root/path)],changes)
                        for path in changes:self.assertEqual((fixture.root/path).read_bytes(),fixture.targets[path])
                        self.assertEqual(policy,fixture.controls)
                        self.assertEqual(out['control_fingerprint'],preflight['control_fingerprint'])
                        self.assertFalse(out['migration_applied'])
                        self.assertFalse(any('artisan' in args for args in fixture.calls))
                        self.assertEqual(protected_before,{path:repair.metadata(fixture.root/path) for path in fixture.protected})
                        fixture.unchanged_config(self)
                        receipt=json.loads((pathlib.Path(out['backup'])/'receipt.json').read_text())
                        self.assertEqual(receipt['source_profile'],previous_profile)
                        self.assertEqual(receipt['sources'],fixture.previous_profiles[previous_profile])
                        self.assertEqual(receipt['control_fingerprint'],policy['fingerprint'])
                        with fixture.patches(),mock.patch.object(repair.base,'command',fixture.runner):
                            current=repair.verify_state(fixture.root,fixture.uid,out['configuration_fingerprint'],out['sources'],
                                out['protected_fingerprint'],expected_profile=profile,expected_control=out['control_fingerprint'])
                        self.assertEqual(current['sources'],{path:pair[1] for path,pair in fixture.pins.items()})
    def test_previous_ai_runtime_with_mixed_legacy_service_refuses_before_commands(self):
        for path,data in self.f.previous_targets.items():self.f.write(path,data)
        self.f.write('app/Services/Dashboard/WhatsAppOrderWorkflow.php',
            self.f.old['app/Services/Dashboard/WhatsAppOrderWorkflow.php'])
        self.f.controls={'present':True,'fingerprint':'e'*64,'enabled':True,'default_disabled':False}
        with self.assertRaisesRegex(repair.SafeError,'LIVE_SOURCE_NEEDS_REVIEW'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_referenced_viewport_asset_is_live_and_pinned_before_template_publication(self):
        asset='public/css/dashboard-meta-inbox-viewport.css'
        template='resources/views/admin/meta/ai_controls.blade.php'
        for path,data in self.f.previous_target_sets['META_AI_CONTROL_UNANSWERED_HOURS'].items():self.f.write(path,data)
        self.f.controls={'present':True,'fingerprint':'e'*64,'enabled':True,'default_disabled':False}
        self.assertFalse((self.f.root/asset).exists())
        publications=[];original=repair.base.replace_file
        def replace(path,content,*args,**kwargs):
            relative=str(path.relative_to(self.f.root))
            if relative==template:
                self.assertIn(asset,publications)
                live=(self.f.root/asset).read_bytes()
                self.assertEqual(live,self.f.targets[asset])
                self.assertEqual(repair.blob(live),self.f.pins[asset][1])
            result=original(path,content,*args,**kwargs)
            publications.append(relative);return result
        with mock.patch.object(repair.base,'replace_file',side_effect=replace):self.f.apply()
        self.assertEqual(publications[0],asset)
        self.assertLess(publications.index(asset),publications.index(template))
    def test_old_and_new_ai_runtime_mixture_is_not_an_upgrade_profile(self):
        for name,previous in self.f.previous_target_sets.items():
            with self.subTest(previous=name):
                for path,data in previous.items():self.f.write(path,data)
                self.f.write('app/Services/Dashboard/WhatsAppOrderWorkflow.php',self.f.targets['app/Services/Dashboard/WhatsAppOrderWorkflow.php'])
                self.f.controls={'present':True,'fingerprint':'e'*64,'enabled':True,'default_disabled':False}
                with self.assertRaisesRegex(repair.SafeError,'LIVE_SOURCE_NEEDS_REVIEW'):self.f.apply()
                self.assertEqual(self.f.calls,[])
    def test_partial_previous_runtime_with_only_new_viewport_file_is_not_adopted(self):
        for path,data in self.f.previous_target_sets['META_AI_CONTROL_UNANSWERED_HOURS'].items():self.f.write(path,data)
        path='public/css/dashboard-meta-inbox-viewport.css';self.f.write(path,self.f.targets[path])
        with self.assertRaisesRegex(repair.SafeError,'LIVE_SOURCE_NEEDS_REVIEW'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_previous_ai_runtime_with_unknown_javascript_refuses_before_commands(self):
        for path,data in self.f.previous_targets.items():self.f.write(path,data)
        self.f.write('public/js/dashboard-meta-ai-controls.js',b'/* unreviewed JavaScript */\n')
        with self.assertRaisesRegex(repair.SafeError,'LIVE_SOURCE_NEEDS_REVIEW'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_previous_ai_runtime_guard_refuses_a_partially_upgraded_source_map(self):
        mixed=dict(self.f.previous_profiles['META_AI_CONTROL_V1'])
        path='app/Services/Dashboard/WhatsAppOrderWorkflow.php';mixed[path]=self.f.baseline[path]
        with self.f.patches(),self.assertRaisesRegex(repair.SafeError,'STATE_SCOPE_INVALID'):
            repair.verify_state(self.f.root,self.f.uid,'b'*64,mixed,'c'*64,
                expected_profile='META_MESSAGE_HUB_CATALOG_LOCK',expected_control='e'*64)
    def test_previous_ai_runtime_policy_change_during_lint_is_preserved_and_blocks_upgrade(self):
        for path,data in self.f.previous_targets.items():self.f.write(path,data)
        self.f.controls={'present':True,'fingerprint':'e'*64,'enabled':True,'default_disabled':False}
        before={path:repair.metadata(self.f.root/path) if (self.f.root/path).exists() else None for path in self.f.pins};changed=[False]
        def hook(args):
            if args[:2]==[repair.PHP,'-l'] and not changed[0]:
                changed[0]=True;self.f.controls={'present':True,'fingerprint':'f'*64,'enabled':False,'default_disabled':False}
        self.f.inner.hook=hook
        with self.assertRaisesRegex(repair.SafeError,'POLICY_CHANGED_DURING_INSTALL'):self.f.apply()
        self.assertFalse(self.f.controls['enabled']);self.assertEqual(self.f.controls['fingerprint'],'f'*64)
        self.assertEqual(before,{path:repair.metadata(self.f.root/path) if (self.f.root/path).exists() else None for path in self.f.pins})
        self.assertFalse(any('artisan' in args or args[0]=='/usr/bin/systemctl' for args in self.f.calls))
        self.f.unchanged_config(self)
    def test_unknown_protected_source_blocks_before_commands(self):
        self.f.write('app/Support/Immutable.php',b'<?php /* unknown */\n')
        with self.assertRaisesRegex(repair.SafeError,'PROTECTED_SOURCE_MISMATCH'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_readonly_foundation_hardlink_is_preserved_exactly(self):
        path=self.f.root/'app/Support/Immutable.php';alias=self.f.root.parent/'foundation-alias';os.link(path,alias)
        before=repair.metadata(path);self.f.apply()
        self.assertEqual(repair.metadata(path),before);self.assertEqual(repair.metadata(alias),before);self.f.unchanged_config(self)
    def test_source_hardlink_is_replaced_atomically_without_changing_legacy_alias(self):
        path=next(path for path,data in self.f.old.items() if data is not None);live=self.f.root/path;alias=self.f.root.parent/'source-alias'
        os.link(live,alias);before=repair.metadata(alias);content=alias.read_bytes();self.f.apply()
        self.assertEqual(alias.read_bytes(),content);self.assertEqual(repair.metadata(alias)[:7],before[:7]);self.assertNotEqual(live.stat().st_ino,alias.stat().st_ino)
    def test_private_environment_hardlink_is_still_rejected(self):
        os.link(self.f.root/'.env',self.f.root.parent/'env-alias')
        with self.assertRaisesRegex(repair.SafeError,'CONFIGURATION_LINK_UNSAFE'):self.f.apply()
        self.assertEqual(self.f.calls,[])
    def test_configuration_concurrency_is_retained_and_blocks_before_workers(self):
        changed=[False]
        def hook(args):
            if args[:2]==[repair.PHP,'-l'] and not changed[0]:changed[0]=True;self.f.write('config/other.php',b'<?php /* foreign */\n')
        self.inner_hook=hook;self.f.inner.hook=hook
        with self.assertRaisesRegex(repair.SafeError,'CONFIGURATION_CHANGED'):self.f.apply()
        self.f.originals(self);self.assertEqual((self.f.root/'config/other.php').read_bytes(),b'<?php /* foreign */\n')
    def test_migration_failure_restores_code_without_replaying_or_dropping_schema(self):
        self.f.fail_migrate=True
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)
        self.assertFalse(any(word in args for args in self.f.calls for word in ('rollback','reset','whatsapp:process-orders','meta:process-ai')))
    def test_accidentally_enabled_new_seed_blocks_and_rolls_back_code(self):
        self.f.bad_seed=True
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)
    def test_policy_flip_during_lint_never_gets_overwritten(self):
        changed=[False]
        def hook(args):
            if args[:2]==[repair.PHP,'-l'] and not changed[0]:
                changed[0]=True;self.f.controls={'present':True,'fingerprint':'e'*64,'enabled':True,'default_disabled':False}
        self.f.inner.hook=hook
        with self.assertRaisesRegex(repair.SafeError,'POLICY_CHANGED_DURING_INSTALL'):self.f.apply()
        self.f.originals(self);self.assertTrue(self.f.controls['enabled'])
    def test_existing_active_policy_cannot_be_adopted_by_legacy_source_profile(self):
        self.f.controls={'present':True,'fingerprint':'e'*64,'enabled':True,'default_disabled':False}
        with self.assertRaisesRegex(repair.SafeError,'ENABLED_WITH_LEGACY_RUNTIME'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)
    def test_all_legacy_source_foundations_still_require_policy_off(self):
        for source_profile in repair.BASELINE_SOURCE_PROFILES:
            for catalog_lock in (False,True):
                with self.subTest(source=source_profile,catalog_lock=catalog_lock),tempfile.TemporaryDirectory() as folder:
                    fixture=AppFixture(folder)
                    if source_profile.startswith('WHATSAPP_CONTEXTUAL'):
                        for path,data in fixture.context.items():fixture.write(path,data)
                    if source_profile.endswith('_HUB'):
                        for path,data in fixture.hub.items():fixture.write(path,data)
                        fixture.write('app/Support/Immutable.php',fixture.hub_foundation)
                    if catalog_lock:fixture.write(fixture.catalog_path,fixture.catalog_locked)
                    before={path:repair.metadata(fixture.root/path) if (fixture.root/path).exists() else None for path in fixture.pins}
                    fixture.controls={'present':True,'fingerprint':'e'*64,'enabled':True,'default_disabled':False}
                    with self.assertRaisesRegex(repair.SafeError,'ENABLED_WITH_LEGACY_RUNTIME'):fixture.apply()
                    self.assertEqual(before,{path:repair.metadata(fixture.root/path) if (fixture.root/path).exists() else None for path in fixture.pins})
                    self.assertTrue(fixture.controls['enabled']);fixture.unchanged_config(self)
                    self.assertFalse(any('artisan' in args or args[0]=='/usr/bin/systemctl' for args in fixture.calls))
    def test_lint_failure_touches_no_application_or_worker(self):
        self.f.inner.fail_lint=True
        with self.assertRaisesRegex(repair.SafeError,'CANDIDATE_LINT_FAILED'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)
    def test_source_symlink_never_follows_private_configuration(self):
        path=next(path for path,data in self.f.old.items() if data is not None);live=self.f.root/path;live.unlink();live.symlink_to(self.f.root/'.env')
        with self.assertRaises(repair.SafeError):self.f.apply()
        self.assertTrue(live.is_symlink());self.assertEqual(self.f.calls,[])
    @unittest.skipUnless(os.geteuid()==0,'Root-owned system unit fixtures require root')
    def test_real_new_unit_publication_is_atomic_private_and_single_link(self):
        with tempfile.TemporaryDirectory() as folder:
            systemd=pathlib.Path(folder)/'systemd';systemd.mkdir(mode=0o755)
            contents={name:(ROOT/'deployment/systemd'/name).read_bytes() for name in wrapper.NEW_UNITS}
            pins={name:repair.blob(data) for name,data in contents.items()}
            with mock.patch.object(wrapper,'SYSTEMD_DIR',systemd),mock.patch.object(wrapper,'UNIT_PINS',pins):
                backup=wrapper.publish_units(pathlib.Path(folder),0,contents,library=repair.base)
                for name,data in contents.items():
                    live=systemd/name
                    self.assertEqual(live.read_bytes(),data);self.assertEqual(live.stat().st_nlink,1)
                    self.assertEqual(stat.S_IMODE(live.stat().st_mode),0o644)
                    self.assertEqual(live.stat().st_uid,0)
                before={name:repair.metadata(systemd/name) for name in contents}
                wrapper.publish_units(pathlib.Path(folder),0,contents,library=repair.base)
                self.assertEqual(before,{name:repair.metadata(systemd/name) for name in contents})
                self.assertEqual(stat.S_IMODE(pathlib.Path(backup).stat().st_mode),0o700)

    @unittest.skipUnless(os.geteuid()==0,'Root-owned system unit fixtures require root')
    def test_unknown_or_symlink_unit_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as folder:
            systemd=pathlib.Path(folder)/'systemd';systemd.mkdir(mode=0o755)
            contents={name:(ROOT/'deployment/systemd'/name).read_bytes() for name in wrapper.NEW_UNITS}
            pins={name:repair.blob(data) for name,data in contents.items()}
            path=systemd/wrapper.NEW_UNITS[0];path.write_bytes(b'PRIVATE_FOREIGN_UNIT');path.chmod(0o644)
            with mock.patch.object(wrapper,'SYSTEMD_DIR',systemd),mock.patch.object(wrapper,'UNIT_PINS',pins):
                with self.assertRaises(wrapper.SafeError):wrapper.publish_units(pathlib.Path(folder),0,contents,library=repair.base)
                self.assertEqual(path.read_bytes(),b'PRIVATE_FOREIGN_UNIT')
                path.unlink();outside=pathlib.Path(folder)/'foreign';outside.write_bytes(b'PRIVATE_FOREIGN_FILE');path.symlink_to(outside)
                with self.assertRaises(Exception):wrapper.publish_units(pathlib.Path(folder),0,contents,library=repair.base)
                self.assertTrue(path.is_symlink());self.assertEqual(outside.read_bytes(),b'PRIVATE_FOREIGN_FILE')

    @unittest.skipUnless(os.geteuid()==0,'Root-owned system unit fixtures require root')
    def test_interrupted_unit_publication_removes_only_our_new_files(self):
        with tempfile.TemporaryDirectory() as folder:
            systemd=pathlib.Path(folder)/'systemd';systemd.mkdir(mode=0o755)
            contents={name:(ROOT/'deployment/systemd'/name).read_bytes() for name in wrapper.NEW_UNITS}
            pins={name:repair.blob(data) for name,data in contents.items()};original=repair.base.replace_file
            def interrupt(*args,**kwargs):original(*args,**kwargs);raise InterruptedError()
            with mock.patch.object(wrapper,'SYSTEMD_DIR',systemd),mock.patch.object(wrapper,'UNIT_PINS',pins),mock.patch.object(repair.base,'replace_file',interrupt):
                with self.assertRaises(wrapper.SafeError):wrapper.publish_units(pathlib.Path(folder),0,contents,library=repair.base)
                self.assertTrue(all(not (systemd/name).exists() for name in contents))

    def test_first_install_restores_existing_timers_and_only_enables_new_gated_poller(self):
        worker=WorkerFixture();out=worker.run()
        self.assertTrue(out['polling_installed'])
        self.assertEqual([args[-1] for args in worker.calls if args[1]=='enable'],[wrapper.NEW_UNITS[0]])
        self.assertEqual([args[-1] for args in worker.calls if args[1]=='start'],[wrapper.UNITS[0],wrapper.UNITS[4],wrapper.NEW_UNITS[0]])
        self.assertFalse(any(args[1]=='start' and args[-1].endswith('.service') for args in worker.calls))
    def test_repeat_preserves_existing_disabled_new_poller(self):
        worker=WorkerFixture();worker.new_present=True;worker.run()
        self.assertFalse(any(args[1]=='enable' for args in worker.calls));self.assertEqual(worker.states[wrapper.NEW_UNITS[0]],'inactive')
    def test_unsafe_preflight_never_touches_workers(self):
        worker=WorkerFixture()
        with self.assertRaises(wrapper.SafeError):worker.run(child=lambda *args,**kwargs:{'ok':False,'status':'META_AI_CONTROL_LIVE_SOURCE_NEEDS_REVIEW'})
        self.assertEqual(worker.calls,[])
    def test_failed_root_unit_publication_holds_all_pipelines_quiet(self):
        worker=WorkerFixture();worker.fail_publish=True
        with self.assertRaisesRegex(wrapper.SafeError,'RECOVERY_NEEDS_REVIEW'):worker.run()
        self.assertTrue(all(state=='inactive' for state in worker.states.values()));self.assertFalse(any(args[1]=='start' for args in worker.calls))
    def test_failed_new_enable_never_restarts_orders_or_old_capture(self):
        worker=WorkerFixture();worker.fail_enable=True
        with self.assertRaisesRegex(wrapper.SafeError,'RECOVERY_NEEDS_REVIEW'):worker.run()
        self.assertTrue(all(state=='inactive' for state in worker.states.values()));self.assertFalse(any(args[1]=='start' for args in worker.calls))
    def test_changed_restart_guard_holds_all_pipelines_quiet(self):
        worker=WorkerFixture();original=worker.child
        def child(*args,**kwargs):
            out=original(*args,**kwargs)
            if args[5] is False:worker.state_safe=False
            return out
        with self.assertRaisesRegex(wrapper.SafeError,'RECOVERY_NEEDS_REVIEW'):worker.run(child)
        self.assertTrue(all(state=='inactive' for state in worker.states.values()));self.assertFalse(any(args[1]=='start' for args in worker.calls))

if __name__=='__main__':unittest.main()
