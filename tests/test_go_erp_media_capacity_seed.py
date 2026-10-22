import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('capacity_seed',ROOT/'deployment/verify_go_erp_media_capacity_seed.py')
policy=importlib.util.module_from_spec(spec);spec.loader.exec_module(policy)

class CapacitySeedTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name)
        self.run_git('init','-q')
        for name,data in [('app/source.php','<?php // accepted application\n'),
                          ('desktop-pos/src/dashboard-media.cjs','const MAX_TOTAL=2*1024**3;\n'),
                          ('.github/workflows/go-erp-update-tests.yml','original tests\n')]:self.write(name,data)
        self.core=self.commit('fixed capacity core')
        for name in ['deployment/verify_go_erp_media_capacity_seed.py','deployment/apply_go_erp_media_capacity_root.py']:
            self.write(name,'# approved seed policy\n')
        self.write('.github/workflows/go-erp-update-tests.yml','original tests plus capacity seed proof\n')
        self.seed=self.commit('policy seed')
    def write(self,name,data):
        p=self.root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(data)
    def run_git(self,*args):return policy.git(self.root,*args)
    def commit(self,message):
        self.run_git('add','.')
        self.run_git('-c','user.name=Fixture','-c','user.email=fixture@localhost','commit','-qm',message)
        return self.run_git('rev-parse','HEAD').decode().strip()
    def test_exact_native_and_application_capacity_core_can_receive_only_bounded_policy_changes(self):
        result=policy.validate_seed(self.root,self.seed,core=self.core)
        self.assertEqual(result['core'],self.core)
        self.assertEqual(len(result['policy_files']),3)
        self.write('docs/go-erp-media-capacity-seed-20261022.md','operator instructions\n')
        self.assertEqual(policy.validate_seed(self.root,self.commit('document policy'),core=self.core)['core'],self.core)
    def test_application_native_and_dependency_changes_cannot_enter_through_the_seed_policy(self):
        for name in ['app/source.php','desktop-pos/src/dashboard-media.cjs','composer.lock','desktop-pos/package.json']:
            self.run_git('reset','--hard',self.seed)
            self.write(name,'unreviewed change\n')
            with self.subTest(name=name),self.assertRaisesRegex(RuntimeError,'UNREVIEWED_SEED_DELTA'):
                policy.validate_seed(self.root,self.commit('unexpected source'),core=self.core)
    def test_seed_must_include_the_upgrade_and_independent_candidate_verifier(self):
        self.run_git('reset','--hard',self.core)
        self.write('.github/workflows/go-erp-update-tests.yml','only a workflow change\n')
        with self.assertRaisesRegex(RuntimeError,'POLICY_INCOMPLETE'):
            policy.validate_seed(self.root,self.commit('incomplete'),core=self.core)
        with self.assertRaisesRegex(RuntimeError,'POLICY_SEED_REQUIRED'):
            policy.validate_seed(self.root,self.core,core=self.core)
    def test_a_link_or_executable_cannot_replace_a_policy_file(self):
        path=self.root/'deployment/apply_go_erp_media_capacity_root.py';path.chmod(0o755)
        with self.assertRaisesRegex(RuntimeError,'UNREVIEWED_SEED_DELTA'):
            policy.validate_seed(self.root,self.commit('mode changed'),core=self.core)

if __name__=='__main__':unittest.main()
