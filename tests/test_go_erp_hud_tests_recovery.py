import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
recovery=load('hud_test_recovery',ROOT/'deployment/upgrade_go_erp_hud_tests_root.py')
fixtures=load('hud_recovery_transaction_fixture',ROOT/'tests/test_go_erp_fullscreen_transaction.py')

class HudRecoveryTests(unittest.TestCase):
    def configured(self):
        return recovery.configure(load('pinned_native_recovery',ROOT/'deployment/upgrade_go_erp_fullscreen_watcher_root.py'))

    def transaction(self,outcome='success',authorize=False):
        subject=self.configured()
        directory=tempfile.TemporaryDirectory();self.addCleanup(directory.cleanup)
        with patch.object(fixtures,'subject',subject):
            f=fixtures.TransactionFixture(Path(directory.name),nav_applied=True,outcome=outcome)
            f.snapshot=types.MethodType(lambda self, inv, src, seed=subject.OLD_SEED: fixtures.TransactionFixture.snapshot(self, inv, src, seed),f)
            inventory=next(iter(subject.PREPARED_SOURCES));source=subject.PREPARED_SOURCES[inventory]
            f.live_cohort=(inventory,source)
            f.expected_old=subject.hold_value(subject.OLD_CORE,subject.HUD_ADVANCED_SOURCE,subject.HUD_ADVANCED_INVENTORY)
            f.expected_new=subject.hold_value(subject.CORE,source,inventory)
            if authorize:f.expected_old['authorization']={'candidate':subject.OLD_CANDIDATE}
            f.hold.write_bytes(subject.canonical(f.expected_old))
            state=json.loads(f.control_paths['state'].read_bytes())
            state.update(counter=38,version_floor='0.4.4-preview.37',last_remote_sha=subject.OLD_CANDIDATE,
                desired={'commit':subject.OLD_CANDIDATE,'version':'0.4.4-preview.38',
                'inventorySha256':subject.HUD_ADVANCED_INVENTORY,'pushed':True,'status':'waiting-ci'})
            f.control_paths['state'].write_bytes(subject.canonical(state));f.original_controls=f.controls()
            f.approved_generic_proof={'revision':subject.OLD_CANDIDATE,'base_revision':subject.OLD_SEED,
                'version':'0.4.4-preview.38','inventory_sha256':subject.HUD_ADVANCED_INVENTORY,
                'source':copy.deepcopy(subject.HUD_ADVANCED_SOURCE),'application_changed':False,'database_read':False}
            old_hold=f.hold.read_bytes()
            with patch.object(subject,'ROOT',f.root),patch.object(subject,'WORK',f.work),patch.object(subject,'navigation_patch',f.make_patch):
                if outcome=='success' and not authorize:
                    result=subject.apply(f)
                    self.assertEqual(result['next_version'],'0.4.4-preview.39')
                    self.assertEqual(result['counter'],38);self.assertFalse(result['published'])
                    self.assertFalse(result['application_contents_changed']);self.assertFalse(result['database_changed'])
                    self.assertEqual(json.loads(f.hold.read_bytes()),f.expected_new)
                else:
                    with self.assertRaises(subject.Stop):subject.apply(f)
                    self.assertEqual(f.hold.read_bytes(),old_hold)
                    if outcome=='rollback' or authorize:self.assertEqual(f.controls(),f.original_controls)
            for path,(data,key) in {**f.hr_files,**f.hud_files}.items():
                self.assertEqual(path.read_bytes(),data);self.assertEqual(fixtures.FilesystemUtility.exact_key(path.stat()),key)

    def test_current_preview38_migrates_to39_without_writing_the_current_ui(self):self.transaction()
    def test_four_slot_rollback_restores_current_native_hold_and_highwaters(self):self.transaction('rollback')
    def test_existing_authorization_is_never_replaced(self):self.transaction(authorize=True)

    def test_previous_source_is_candidate_evidence_only_and_current_ui_is_required_live(self):
        s=self.configured()
        self.assertIn(s.HUD_ADVANCED_INVENTORY,s.CANDIDATE_SOURCES)
        self.assertNotIn(s.HUD_ADVANCED_INVENTORY,s.LIVE_SOURCES)
        self.assertEqual(len(s.LIVE_SOURCES),1)
        self.assertEqual(next(iter(s.LIVE_SOURCES.values()))['files'],1352)
        self.assertEqual(s.NAV_TARGETS,{})

    def test_unverified_or_old_counter_cannot_be_migrated(self):
        s=self.configured()
        for value in ({'counter':37},{'counter':38}):
            with self.assertRaises(s.Stop):s.migrated_state(value)

    def test_old_runtime_payload_changes_refuse_before_rebinding(self):
        s=self.configured()
        with self.assertRaises(s.Stop):s.adapt_payloads({name:b'changed' for name in s.OLD_PINS})

if __name__=='__main__':unittest.main()
