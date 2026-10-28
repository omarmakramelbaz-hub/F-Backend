"""Pure correction-plan rejection tests; synthetic records are not receipts."""
import ast
import copy
import importlib.util
import json
from pathlib import Path
import unittest

BASE=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('pure_performance_recovery',
    BASE/'deployment/go_erp_desktop_performance_recovery.py')
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
OLD=json.loads((BASE/'tests/fixtures/go_erp_desktop_performance/bad_binding.json').read_bytes())
if r.sha(r.canonical(OLD))!=r.OLD_BINDING_SHA256:raise AssertionError('Failed public binding fixture changed')


def fixture():
    new=copy.deepcopy(OLD);new['core']=r.CORE;new['seed']=r.NEW_SEED
    for index,name in enumerate(('go_erp_live_source.py','go_erp_live_updates.py'),1):
        new['native_payloads'][name]=[str(index)*40,OLD['native_payloads'][name][1],str(index)*64]
    state={'format':1,'base_revision':r.CURRENT_SEED,'counter':60,'last_remote_sha':r.CURRENT_CANDIDATE,
        'desired':{'commit':r.CURRENT_CANDIDATE,'status':'published','pushed':True,
                   'version':'0.4.4-preview.60','inventorySha256':r.CURRENT_INVENTORY},
        'published':{'commit':r.CURRENT_CANDIDATE,'inventorySha256':r.CURRENT_INVENTORY}}
    state_raw=r.canonical(state);config_raw=r.canonical({'base_revision':r.CURRENT_SEED,'token':'SYNTHETIC_PRIVATE_TOKEN'})
    metadata_raw=r.canonical({'seedRevision':r.CURRENT_SEED,'inventorySha256':r.INVENTORY})
    current={'candidate':r.CURRENT_CANDIDATE,'version':'0.4.4-preview.60',
        'profile':{'core':r.CURRENT_CORE,'seed':r.CURRENT_SEED},
        'private_hashes':{r.WORK+'/state/current.json':r.sha(state_raw),r.CONFIG:r.sha(config_raw),r.UNIT:'a'*64}}
    previous_hash='b'*64
    flow={'format':1,'kind':'go-erp-desktop-performance-journal','phase':'prepared',
        'binding_sha256':r.OLD_BINDING_SHA256,'inventory_sha256':r.INVENTORY,
        'current':current,'predecessor':{'predecessor':{'counter':60,'candidate':r.CURRENT_CANDIDATE},
            'private_hashes':{r.PRIVATE+'/shared-workspace-'+r.CURRENT_CORE+'.json':previous_hash}},
        'captured_metadata_sha256':r.sha(metadata_raw),'invariants':{'preserved':'exact'}}
    job={'format':1,'kind':'go-erp-warm-start-rollout','core':r.OLD_CORE,'seed':r.BAD_SEED,
        'runtime':r.runtime_name(OLD['native_payloads']),
        'source':{'format':1,'files':100,'framework':'8.83.29','sha256':'c'*64},
        'inventory':r.INVENTORY,'published_inventory_at_entry':r.CURRENT_INVENTORY,
        'captured_metadata_sha256':r.sha(metadata_raw),'previous_journal_sha256':previous_hash,
        'audit':r.PRIVATE+'/audits/warm-start-'+'d'*32,'remote_lease':r.CURRENT_CANDIDATE,
        'phase':'preparing','prepare_started':False,'baseline_candidate':None,'baseline_counter':None,
        'baseline_remote':None,'candidate':None,'version':None,'transient_failures':0,'public_nonce':'e'*32}
    failure={'format':1,'kind':'go-erp-preview31-watcher-upgrade','phase':'SEED_STAGE',
        'reason':'SEED_CORE_MISMATCH','ok':False,'rollback_complete':True,
        'audit_directory':r.WORK+'/warm-start-seed-upgrade-'+'f'*32}
    failure.update({name:False for name in ('application_contents_changed','database_changed','configuration_changed',
        'state_changed','checkout_changed','unit_changed','published','credentials_disclosed')})
    commit={'format':1,'kind':'go-erp-shared-workspace-source-committed','plan_sha256':r.SOURCE_PLAN_SHA256,
        'attempt':'attempt-000000','schema_changed':False,'financial_data_changed':False,
        'feature_activated':False,'allocated':False,'published':False}
    manual={'go_erp_manual_tick_root.py':'4ea08fc1ffa3113c14a964931869a7016b69619e45ba05f272929c36a736b911',
        'go_erp_manual_updates_root.py':'c8304a4f3ab4a4a8160e4bf687fa129343f53031fb38d695c398195ac12ed624',
        'go_erp_published_download_root.py':'2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}
    units={'go-erp-manual-updates.path':'1'*64,'go-erp-manual-updates.service':'2'*64}
    receipt={'format':1,'kind':'go-erp-manual-installation','release':OLD['original_release'],
             'runtime':r.BAD_MANUAL_RUNTIME,'units':copy.deepcopy(units)}
    return dict(old_binding=copy.deepcopy(OLD),new_binding=new,performance_journal=flow,
        performance_checkpoint_raw=r.canonical(flow),native_job=job,native_state_raw=state_raw,config_raw=config_raw,
        native_unit_sha256='a'*64,previous_state_raw=state_raw,captured_metadata_raw=metadata_raw,
        failed_report=failure,intent_raw=None,pending_program_hold_raw=None,staged_checkout_head=r.BAD_SEED,
        staged_verifier_core=r.CURRENT_CORE,source_commit=commit,source_plan_sha256=r.SOURCE_PLAN_SHA256,
        bad_manual_pins=manual,manual_receipt=receipt,expected_bad_manual_unit_hashes=units)


class PureCorrection(unittest.TestCase):
    def test_exact_unallocated_case_adopts_source_without_changing_old_native_job(self):
        data=fixture();before=copy.deepcopy(data);plan=r.plan(**data)
        self.assertEqual(data,before)
        self.assertNotIn('native_job_after',plan)
        self.assertEqual(plan['native_job_before_sha256'],r.sha(r.canonical(data['native_job'])))
        self.assertEqual(r.changed(data['performance_journal'],plan['performance_after']),{'binding_sha256'})
        self.assertEqual(plan['core'],r.CORE);self.assertEqual(plan['from_core'],r.OLD_CORE)
        self.assertEqual(plan['to_seed'],r.NEW_SEED)
        self.assertTrue(plan['old_records_unchanged']);self.assertTrue(plan['new_native_job_owned_by_original'])
        self.assertTrue(plan['manual_transition_owned']);self.assertFalse(plan['manual_restore_required'])
        self.assertFalse(plan['counter_allocation_allowed']);self.assertFalse(plan['database_changed'])
        self.assertNotIn('SYNTHETIC_PRIVATE_TOKEN',json.dumps(plan))

    def test_every_allocation_marker_or_advanced_job_is_refused(self):
        for key,value in [('prepare_started',True),('candidate','a'*40),('version','0.4.4-preview.61'),
            ('baseline_candidate',r.CURRENT_CANDIDATE),('baseline_counter',60),('baseline_remote',r.CURRENT_CANDIDATE),
            ('phase','prepared'),('phase','running'),('transient_failures',1),('core','f'*40),('seed',r.NEW_SEED)]:
            data=fixture();data['native_job'][key]=value
            with self.subTest(key=key,value=value),self.assertRaises(r.Stop):r.plan(**data)

    def test_new_seed_or_native_proof_change_scope_cannot_expand(self):
        for change in ('wrongseed','core','web','nativeproof','delete'):
            data=fixture();new=data['new_binding']
            if change=='wrongseed':new['seed']='f'*40
            elif change=='core':new['core']='f'*40
            elif change=='web':new['web_release']=r.NEW_SEED
            elif change=='nativeproof':new['native_payloads']['publish_go_erp_update.py'][2]='f'*64
            else:del new['native_payloads']['go_erp_live_source.py']
            with self.subTest(change=change),self.assertRaises(r.Stop):r.plan(**data)

    def test_old_binding_and_immutable_prepared_checkpoint_cannot_be_rewritten(self):
        for key in ('oldbinding','checkpoint','flowphase','captured'):
            data=fixture()
            if key=='oldbinding':data['old_binding']['format']=True
            elif key=='checkpoint':data['performance_checkpoint_raw']+=b' '
            elif key=='flowphase':data['performance_journal']['phase']='ready'
            else:data['captured_metadata_raw']+=b' '
            with self.subTest(key=key),self.assertRaises(r.Stop):r.plan(**data)

    def test_each_native_slot_must_still_be_the_original_exact_before_image(self):
        for key in ('native_state_raw','config_raw','previous_state_raw','native_unit_sha256'):
            data=fixture();data[key]=b'{}\n' if key.endswith('_raw') else 'f'*64
            with self.subTest(key=key),self.assertRaises(r.Stop):r.plan(**data)

    def unicode_state(self):
        data=fixture()
        state=json.loads(data['native_state_raw'])
        # These three en-dash paths occur in the actual captured inventory.
        state['observed']={'inventory':{name:{'sha256':'a'*64,'present':True} for name in (
            'public/site/images/Cart – 1.png','public/site/images/Register – 2.png',
            'public/site/images/Search – 2.png')}}
        data['native_state_raw']=json.dumps(state,ensure_ascii=False,sort_keys=True,
            separators=(',',':')).encode('utf8')+b'\n'
        data['previous_state_raw']=r.canonical(state)
        flow=data['performance_journal']
        flow['current']['private_hashes'][r.WORK+'/state/current.json']=r.sha(data['native_state_raw'])
        data['performance_checkpoint_raw']=r.canonical(flow)
        return data

    def test_worker_utf8_state_matches_the_exact_escaped_engine_audit(self):
        data=self.unicode_state()
        self.assertNotEqual(data['native_state_raw'],data['previous_state_raw'])
        self.assertIn('–'.encode(),data['native_state_raw'])
        self.assertIn(b'\\u2013',data['previous_state_raw'])
        self.assertEqual(json.loads(data['native_state_raw']),json.loads(data['previous_state_raw']))
        result=r.plan(**data)
        self.assertEqual(result['performance_after']['binding_sha256'],r.sha(r.canonical(data['new_binding'])))

    def test_unicode_serialization_does_not_allow_state_or_audit_tampering(self):
        for changed in ('current','audit','audit_format'):
            data=self.unicode_state()
            if changed=='current':
                state=json.loads(data['native_state_raw']);state['counter']=61
                data['native_state_raw']=json.dumps(state,ensure_ascii=False,sort_keys=True,
                    separators=(',',':')).encode()+b'\n'
                data['previous_state_raw']=r.canonical(state)
            elif changed=='audit':
                state=json.loads(data['previous_state_raw']);state['counter']=61
                data['previous_state_raw']=r.canonical(state)
            else:
                data['previous_state_raw']=json.dumps(json.loads(data['previous_state_raw']),indent=2).encode()+b'\n'
            with self.subTest(changed=changed),self.assertRaisesRegex(r.Stop,'ORIGINAL_NATIVE_SLOTS_CHANGED'):
                r.plan(**data)

    def test_another_failure_or_exchange_intent_or_pending_hold_is_refused(self):
        for key,value in [('phase','PATH_SWITCH'),('reason','PRIVATE_FAILURE'),('rollback_complete',False),
            ('configuration_changed',True),('state_changed',True),('checkout_changed',True),('unit_changed',True),
            ('recovery_required',True),('format',True)]:
            data=fixture();data['failed_report'][key]=value
            with self.subTest(key=key),self.assertRaises(r.Stop):r.plan(**data)
        for key,value in [('intent_raw',b'{}'),('pending_program_hold_raw',b'{}'),
                          ('staged_checkout_head',r.NEW_SEED),('staged_verifier_core',r.CORE)]:
            data=fixture();data[key]=value
            with self.subTest(key=key),self.assertRaises(r.Stop):r.plan(**data)

    def test_foreign_source_wal_or_manual_receipt_is_refused(self):
        for key,value in [('allocated',True),('attempt','attempt-'+'a'*32),('plan_sha256','f'*64),('format',True)]:
            data=fixture();data['source_commit'][key]=value
            with self.subTest(key=key),self.assertRaises(r.Stop):r.plan(**data)
        for case in ('runtime','pin','unit'):
            data=fixture()
            if case=='runtime':data['manual_receipt']['runtime']='f'*64
            elif case=='pin':data['bad_manual_pins']['go_erp_manual_tick_root.py']='f'*64
            else:data['manual_receipt']['units']['go-erp-manual-updates.service']='f'*64
            with self.subTest(case=case),self.assertRaises(r.Stop):r.plan(**data)

    def test_no_effect_apis_exist_in_the_pure_plan(self):
        tree=ast.parse((BASE/'deployment/go_erp_desktop_performance_recovery.py').read_text())
        calls={node.func.attr for node in ast.walk(tree) if isinstance(node,ast.Call)
               and isinstance(node.func,ast.Attribute)}
        self.assertFalse(calls & {'open','read_bytes','write_bytes','write_text','run','atomic_write',
                                 'system','unlink','replace','mkdir','chmod','chown','execute'})


if __name__=='__main__':unittest.main()
