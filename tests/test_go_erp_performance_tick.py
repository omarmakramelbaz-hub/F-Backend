"""Strict startup-window/performance cohort selection keeps the real original worker guards."""
import copy
import fcntl
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
from go_erp_performance_reference import REFERENCE


def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module


subject=load('performance_tick_subject',ROOT/'deployment/go_erp_performance_tick_root.py')
# The reviewed startup-window fixture rebuilds the exact installed cohort bytes.
previous=load('performance_previous_tick_tests',REFERENCE/'tests/test_go_erp_startup_window_tick.py')
previous_tick=previous.subject
original_tests=previous.original_tests
OLD_PAYLOADS=previous.NEW_PAYLOADS
original_tests.PAYLOADS=OLD_PAYLOADS
NEW_PAYLOADS={}
for name,raw in OLD_PAYLOADS.items():
    if name in ('go_erp_live_source.py','go_erp_live_updates.py'):
        for before,after in ((subject.OLD_COHORT['seed'].encode(),subject.NEW_COHORT['seed'].encode()),
                (b'-native-close-reopen-program-contract-startup-window-v1',b'-native-close-reopen-program-contract-performance-v1')):
            assert raw.count(before)==1
            raw=raw.replace(before,after)
    NEW_PAYLOADS[name]=raw



def proxy_for(cohort):
    proxy=types.SimpleNamespace(**subject.__dict__)
    proxy.load_runtime=lambda path:subject.load_runtime(cohort,path)
    proxy.install_guards=lambda worker,inventory=None,candidate=None,allow_prepare=False:subject.install_guards(
        worker,inventory,candidate,allow_prepare,cohort)
    proxy.PINS=cohort['pins'];proxy.SEED=cohort['seed'];proxy.POLICY=cohort['policy']
    return proxy


class OldFrozenRegressionTests(original_tests.ManualTickTests):
    def setUp(self):
        changed=patch.object(original_tests,'subject',proxy_for(subject.OLD_COHORT))
        changed.start();self.addCleanup(changed.stop)
        super().setUp()


class NewFrozenRegressionTests(original_tests.ManualTickTests):
    def setUp(self):
        changed=patch.object(original_tests,'subject',proxy_for(subject.NEW_COHORT))
        changed.start();self.addCleanup(changed.stop)
        payloads=patch.object(original_tests,'PAYLOADS',NEW_PAYLOADS)
        payloads.start();self.addCleanup(payloads.stop)
        super().setUp()

    def release(self,number,commit):
        return {'version':'0.4.4-preview.'+str(number),'sequence':40054814900+number,
            'source_revision':commit,'source':{'format':1,'files':1355,'framework':'8.83.29','sha256':'a'*64},
            'gui':{},'installer':{'sha256':'b'*64,'bytes':20,
                'path':'/api/desktop-dashboard/update/installer/'+'b'*64}}

    def old_publication(self):
        release=self.release(42,original_tests.proof.subject.CANDIDATE42)
        self.f.worker.state['published']={'commit':original_tests.proof.subject.CANDIDATE42,
            'inventorySha256':original_tests.OLD,'release':release}
        self.f.worker.state.update(published_history=[release],sequence_floor=release['sequence'],
            version_floor=release['version'])
        desired=self.f.worker.state['desired']
        desired.update(commit=original_tests.proof.subject.CANDIDATE42,
            candidatePolicy=subject.OLD_COHORT['policy'],status='published',version=release['version'])
        feed=self.f.live/'storage/app/desktop-updates/current.json'
        feed.write_text(json.dumps(release));feed.chmod(0o644)
        return desired

    def test_published_old_policy_prepares_once_without_clearing_publication_or_floors(self):
        self.old_publication()
        state=self.f.worker.state
        preserved=copy.deepcopy({key:state[key] for key in ('published','published_history','sequence_floor','version_floor')})
        self.f.guards(original_tests.OLD,allow=True)
        self.assertEqual(self.f.worker.tick(),'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertEqual({key:state[key] for key in preserved},preserved)
        self.assertEqual(self.f.events.count('git-candidate'),1)
        self.assertEqual(state['desired']['candidatePolicy'],subject.NEW_COHORT['policy'])
        self.assertEqual(state['counter'],44)

    def test_same_new_policy_publication_is_current_without_allocating(self):
        release=self.release(45,'5'*40)
        state=self.f.worker.state
        state.update(counter=45,last_remote_sha='5'*40,published_history=[release],
            sequence_floor=release['sequence'],version_floor=release['version'],
            published={'commit':'5'*40,'inventorySha256':original_tests.OLD,'release':release})
        state['desired'].update(commit='5'*40,version=release['version'],status='published')
        feed=self.f.live/'storage/app/desktop-updates/current.json';feed.write_text(json.dumps(release));feed.chmod(0o644)
        self.f.guards(original_tests.OLD,'5'*40)
        self.assertEqual(self.f.worker.tick(),'GO_ERP_LIVE_UPDATE_CURRENT')
        self.assertEqual(state['counter'],45)
        self.assertNotIn('git-candidate',self.f.events)
        self.assertNotIn(('fetch','5'*40),self.f.events)

    def test_old_policy_blocked_candidate_can_prepare_but_new_policy_blocked_requires_review(self):
        self.old_publication()['status']='blocked'
        self.f.guards(original_tests.OLD,allow=True)
        self.assertEqual(self.f.worker.tick(),'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertEqual(self.f.events.count('git-candidate'),1)
        self.f.worker.state['desired']['status']='blocked'
        self.f.events.clear()
        self.assertEqual(self.f.worker.tick(),'GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED')
        self.assertNotIn('git-candidate',self.f.events)


class CohortSelectorTests(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory(prefix='go-erp-cohort-')
        self.addCleanup(temporary.cleanup);self.path=Path(temporary.name)
        self.config=self.path/'config.json';self.unit=self.path/'native.service'
        self.old=copy.deepcopy(subject.OLD_COHORT)
        self.new=copy.deepcopy(subject.NEW_COHORT)
        # Unit-file binding is exercised independently of real unit generation;
        # real old/new unit hashes are asserted against the coordinator proof.
        self.old_unit=b'[Service]\nExecStart=/fixed/old\n'
        self.new_unit=b'[Service]\nExecStart=/fixed/new\n'
        self.old['unit_sha256']=hashlib.sha256(self.old_unit).hexdigest()
        self.new['unit_sha256']=hashlib.sha256(self.new_unit).hexdigest()
        self.write(self.old,self.old_unit)

    def write(self,cohort,unit):
        self.config.write_text(json.dumps({'base_revision':cohort['seed'],'token':'PRIVATE_TOKEN_MUST_NOT_APPEAR'}))
        self.config.chmod(0o600);self.unit.write_bytes(unit);self.unit.chmod(0o644)

    def select(self):return subject.select_cohort(self.config,self.unit,(self.old,self.new))

    def test_only_matching_config_and_whole_unit_cohort_can_be_selected(self):
        selected,verify=self.select();self.assertEqual(selected['seed'],self.old['seed']);verify()
        self.write(self.new,self.new_unit)
        selected,verify=self.select();self.assertEqual(selected['seed'],self.new['seed']);verify()
        for cohort,unit in ((self.old,self.new_unit),(self.new,self.old_unit)):
            self.write(cohort,unit)
            with self.assertRaisesRegex(subject.Stop,'NATIVE_UNIT_COHORT_MISMATCH'):self.select()

    def test_unknown_duplicate_or_changed_controls_are_refused(self):
        self.config.write_text('{"base_revision":"'+'f'*40+'"}')
        with self.assertRaisesRegex(subject.Stop,'CONFIGURATION_SEED_UNREVIEWED'):self.select()
        self.config.write_text('{"base_revision":"'+self.old['seed']+'","base_revision":"'+self.old['seed']+'"}')
        with self.assertRaisesRegex(subject.Stop,'CONFIGURATION_JSON_INVALID'):self.select()
        self.write(self.old,self.old_unit);_,verify=self.select()
        self.config.write_text(json.dumps({'base_revision':self.old['seed'],'token':'DIFFERENT_PRIVATE_TOKEN'}))
        with self.assertRaisesRegex(subject.Stop,'CONTROL_COHORT_CHANGED'):verify()

    def test_symlink_or_public_private_configuration_is_refused(self):
        self.config.chmod(0o644)
        with self.assertRaisesRegex(subject.Stop,'CONTROL_FILE_UNSAFE'):self.select()
        self.config.unlink();outside=self.path/'outside.json';outside.write_text('{}')
        self.config.symlink_to(outside)
        with self.assertRaisesRegex(subject.Stop,'CONTROL_PATH_INVALID'):self.select()

    def test_control_recheck_precedes_the_real_tick_state_writes(self):
        (self.path/'fixture').mkdir()
        with patch.object(original_tests,'subject',proxy_for(subject.OLD_COHORT)):
            fixture=original_tests.Fixture(self.path/'fixture')
        selected,verify=self.select()
        subject.install_guards(fixture.module,original_tests.OLD,original_tests.CANDIDATE43,
            cohort=subject.OLD_COHORT,verify_controls=verify)
        before=fixture.worker.state_file.read_bytes()
        self.unit.write_bytes(self.new_unit)
        with self.assertRaisesRegex(subject.Stop,'CONTROL_COHORT_CHANGED'):fixture.worker.tick()
        self.assertEqual(fixture.events,[])
        self.assertEqual(fixture.worker.state_file.read_bytes(),before)

    def test_original_main_rechecks_changed_controls_under_its_watcher_lock(self):
        (self.path/'under-lock').mkdir()
        with patch.object(original_tests,'subject',proxy_for(subject.OLD_COHORT)):
            fixture=original_tests.Fixture(self.path/'under-lock')
        module=fixture.module
        _,verify=self.select()
        checks=[]
        def tracked_verify():
            lock=fixture.work/'watcher.lock'
            if lock.exists():
                with lock.open('rb') as stream:
                    try:fcntl.flock(stream,fcntl.LOCK_EX|fcntl.LOCK_NB)
                    except BlockingIOError:checks.append('locked')
                    else:checks.append('unlocked')
            else:checks.append('absent')
            verify()
        module.read_config=lambda:fixture.worker.config
        subject.install_guards(module,original_tests.OLD,original_tests.CANDIDATE43,
            cohort=subject.OLD_COHORT,verify_controls=tracked_verify)
        def construct(*args):
            self.unit.write_bytes(self.new_unit)
            return fixture.worker
        before=fixture.worker.state_file.read_bytes()
        with patch.object(module,'WORK',fixture.work), \
                patch.object(module,'load_module',return_value=fixture.worker.source), \
                patch.object(module,'Worker',side_effect=construct), \
                patch.object(module.socket,'gethostname',return_value='srv1836524'):
            with self.assertRaisesRegex(subject.Stop,'CONTROL_COHORT_CHANGED'):
                subject.run_original(module)
        self.assertEqual(checks[-1],'locked')
        self.assertEqual(fixture.events,[])
        self.assertEqual(fixture.worker.state_file.read_bytes(),before)

    def test_actual_both_payload_cohorts_and_generated_unit_hashes_match(self):
        raw=subprocess.check_output(['git','show',
            '2d9e23a5157782882382c797a44ad15c317ae845:deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py'],cwd=ROOT)
        base=types.ModuleType('close_reopen_original_unit_generator')
        base.__file__='original_unit_generator.py';sys.modules[base.__name__]=base
        exec(compile(raw,base.__file__,'exec'),base.__dict__)
        for cohort,payloads in ((subject.OLD_COHORT,original_tests.PAYLOADS),(subject.NEW_COHORT,NEW_PAYLOADS)):
            with self.subTest(seed=cohort['seed']):
                self.assertEqual(set(payloads),set(cohort['pins']))
                for name,(blob,size,digest) in cohort['pins'].items():
                    value=payloads[name]
                    self.assertEqual((len(value),hashlib.sha256(value).hexdigest()),(size,digest))
                    self.assertEqual(hashlib.sha1(b'blob '+str(size).encode()+b'\0'+value).hexdigest(),blob)
                unit=base.unit_bytes(original_tests.proof.prior_test.utility,cohort['runtime'],
                    Path('/home/fasakha/public_html'),Path('/var/lib/go-erp-live-updates'),subject.CONFIG)
                self.assertEqual(hashlib.sha256(unit).hexdigest(),cohort['unit_sha256'])

    def test_broker_variant_changes_only_the_tick_pin_and_keeps_original_frozen(self):
        original=(REFERENCE/'deployment/go_erp_startup_window_manual_root.py').read_bytes()
        self.assertEqual(hashlib.sha256(original).hexdigest(),
            'b13c56cd5f372af3940cbb7d4ce3135fc6ae4543e6defc49c06987b319a69ad9')
        old_tick=hashlib.sha256((REFERENCE/'deployment/go_erp_startup_window_tick_root.py').read_bytes()).hexdigest()
        self.assertEqual(old_tick,'ec4b82a8330431b5615803e948cadd32c0b66ffd458d0df23ac97ddc0dce945e')
        digest=hashlib.sha256((ROOT/'deployment/go_erp_performance_tick_root.py').read_bytes()).hexdigest()
        self.assertEqual(original.count(old_tick.encode()),1)
        self.assertEqual((ROOT/'deployment/go_erp_performance_manual_root.py').read_bytes(),
            original.replace(old_tick.encode(),digest.encode()))

    def test_new_payload_changes_only_frozen_seed_and_policy(self):
        for name,raw in OLD_PAYLOADS.items():
            expected=raw
            if name in ('go_erp_live_source.py','go_erp_live_updates.py'):
                expected=expected.replace(subject.OLD_COHORT['seed'].encode(),subject.NEW_COHORT['seed'].encode())
                expected=expected.replace(b'-native-close-reopen-program-contract-startup-window-v1',b'-native-close-reopen-program-contract-performance-v1')
            self.assertEqual(NEW_PAYLOADS[name],expected)

    def test_historical_program_contract_cohort_is_no_longer_accepted(self):
        self.config.write_text(json.dumps({'base_revision':previous_tick.OLD_COHORT['seed']}))
        with self.assertRaisesRegex(subject.Stop,'CONFIGURATION_SEED_UNREVIEWED'):self.select()



if __name__=='__main__':unittest.main()
