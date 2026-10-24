"""Strict old/new cohort selection retains the real original worker guards."""
import copy
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


def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module


subject=load('close_reopen_tick_subject',ROOT/'deployment/go_erp_close_reopen_tick_root.py')
original_tests=load('close_reopen_original_tick_tests',ROOT/'tests/test_go_erp_manual_tick.py')
coordinator=load('close_reopen_native_payload_builder',ROOT/'deployment/upgrade_go_erp_close_reopen_root.py')
NEW_PAYLOADS=coordinator.derive_native_payloads(original_tests.PAYLOADS,subject.PINS,subject.NEW_COHORT['seed'])


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
        fixture=original_tests.Fixture(self.path/'fixture')
        selected,verify=self.select()
        subject.install_guards(fixture.module,original_tests.OLD,original_tests.CANDIDATE43,
            cohort=subject.OLD_COHORT,verify_controls=verify)
        before=fixture.worker.state_file.read_bytes()
        self.unit.write_bytes(self.new_unit)
        with self.assertRaisesRegex(subject.Stop,'CONTROL_COHORT_CHANGED'):fixture.worker.tick()
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
        original=(ROOT/'deployment/go_erp_manual_updates_root.py').read_bytes()
        self.assertEqual(hashlib.sha256(original).hexdigest(),
            '214978522787f36aee22112e3837e63f113e0f5600eb8cee31b2a35e8223b753')
        old_tick='753baf72d9171adc0d084350fa319ef71c0fa6843c684b42fed5dbef76c588f8'
        self.assertEqual(hashlib.sha256((ROOT/'deployment/go_erp_manual_tick_root.py').read_bytes()).hexdigest(),old_tick)
        digest=hashlib.sha256((ROOT/'deployment/go_erp_close_reopen_tick_root.py').read_bytes()).hexdigest()
        self.assertEqual(original.count(old_tick.encode()),1)
        self.assertEqual((ROOT/'deployment/go_erp_close_reopen_manual_root.py').read_bytes(),
            original.replace(old_tick.encode(),digest.encode()))


if __name__=='__main__':unittest.main()
