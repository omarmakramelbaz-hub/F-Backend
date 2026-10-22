import importlib.util
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

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
                          ('deployment/verify_go_erp_media_capacity_seed.py','# previous verifier\n'),
                          ('deployment/apply_go_erp_media_capacity_root.py','# unchanged deployment helper\n'),
                          ('.github/workflows/go-erp-update-tests.yml','original tests\n')]:self.write(name,data)
        self.core=self.commit('fixed reviewed native core')
        self.write('deployment/verify_go_erp_media_capacity_seed.py','# approved seed policy\n')
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
        self.assertEqual(len(result['policy_files']),2)
        self.write('docs/go-erp-media-capacity-seed-20261022.md','operator instructions\n')
        self.assertEqual(policy.validate_seed(self.root,self.commit('document policy'),core=self.core)['core'],self.core)
    def test_application_native_and_dependency_changes_cannot_enter_through_the_seed_policy(self):
        for name in ['app/source.php','desktop-pos/src/dashboard-media.cjs',
                     'desktop-pos/src/dashboard-download.cjs','desktop-pos/src/local-runtime.cjs',
                     'desktop-pos/test/snapshot-transport.test.cjs','composer.lock',
                     'desktop-pos/runtime/composer.lock','desktop-pos/package.json','desktop-pos/package-lock.json']:
            self.run_git('reset','--hard',self.seed)
            self.write(name,'unreviewed change\n')
            with self.subTest(name=name),self.assertRaisesRegex(RuntimeError,'UNREVIEWED_SEED_DELTA'):
                policy.validate_seed(self.root,self.commit('unexpected source'),core=self.core)
    def test_seed_must_change_the_independent_verifier_and_workflow_but_not_the_old_upgrade(self):
        helper=self.run_git('rev-parse',self.core+':deployment/apply_go_erp_media_capacity_root.py')
        self.assertEqual(helper,self.run_git('rev-parse',self.seed+':deployment/apply_go_erp_media_capacity_root.py'))
        self.assertEqual(len(policy.validate_seed(self.root,self.seed,core=self.core)['policy_files']),2)
        self.run_git('reset','--hard',self.core)
        self.write('.github/workflows/go-erp-update-tests.yml','only a workflow change\n')
        with self.assertRaisesRegex(RuntimeError,'POLICY_INCOMPLETE'):
            policy.validate_seed(self.root,self.commit('incomplete'),core=self.core)
        with self.assertRaisesRegex(RuntimeError,'POLICY_SEED_REQUIRED'):
            policy.validate_seed(self.root,self.core,core=self.core)
    def test_the_legacy_capacity_core_keeps_its_three_changed_file_requirement(self):
        with patch.object(policy,'LEGACY_CAPACITY_CORE',self.core):
            with self.assertRaisesRegex(RuntimeError,'POLICY_INCOMPLETE'):
                policy.validate_seed(self.root,self.seed,core=self.core)
            self.write('deployment/apply_go_erp_media_capacity_root.py','# original capacity rollout change\n')
            self.assertEqual(len(policy.validate_seed(self.root,self.commit('complete old policy'),core=self.core)['policy_files']),3)
    def test_original_historical_candidate_admission_is_unchanged(self):
        original=types.SimpleNamespace(BASE_REVISION='75c3fe50b3609ef5822904a23d7c4ed0afd07eff')
        original.validate_checkout=lambda root,historical:{'original':True,'root':root,'historical':historical}
        candidate=self.root/'deployment/go_erp_live_source_candidate.json'
        candidate.write_text('{"baseRevision":"'+original.BASE_REVISION+'"}')
        historical=self.root/'historical'
        with patch.object(policy,'module',return_value=original),\
             patch.object(policy,'validate_seed',side_effect=AssertionError('Historical proof must not use the new seed policy')):
            self.assertEqual(policy.verify(self.root,historical),{'original':True,'root':self.root,'historical':historical})
    def test_a_link_or_executable_cannot_replace_a_policy_file(self):
        path=self.root/'deployment/apply_go_erp_media_capacity_root.py';path.chmod(0o755)
        with self.assertRaisesRegex(RuntimeError,'UNREVIEWED_SEED_DELTA'):
            policy.validate_seed(self.root,self.commit('mode changed'),core=self.core)

class ActualReviewedCoreTests(unittest.TestCase):
    """Exercise the immutable real Git tree without a checkout or live application."""
    def setUp(self):
        self.assertEqual(policy.CORE,'a0178ead801e8d39185cd0830171804141767995')
        try:policy.git(ROOT,'cat-file','-e',policy.CORE+'^{commit}')
        except RuntimeError:
            self.fail('Fetch the exact reviewed core '+policy.CORE+' before running the real seed lineage proof.')
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name)
        policy.git(self.root,'init','--quiet','--template=')
        objects=Path(policy.git(ROOT,'rev-parse','--git-path','objects').decode().strip())
        if not objects.is_absolute():objects=ROOT/objects
        # Read source objects through a private alternate; all new index/commit
        # objects belong to this fixture, never the caller's checkout or index.
        (self.root/'.git/objects/info/alternates').write_text(str(objects.resolve())+'\n')

    def git(self,*args,input=None):
        result=subprocess.run(['git','-c','credential.helper=','-c','core.hooksPath=/dev/null',
            '-c','core.fsmonitor=false','-C',str(self.root),*args],env=policy.ENV,
            input=input,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30,check=False)
        self.assertEqual(result.returncode,0,'The isolated real-core Git fixture must complete.')
        return result.stdout

    def child(self,parent,changes):
        self.git('read-tree',parent)
        for name,data,mode in changes:
            object_id=self.git('hash-object','-w','--stdin',input=data).decode().strip()
            self.git('update-index','--add','--cacheinfo',mode,object_id,name)
        tree=self.git('write-tree').decode().strip()
        return self.git('-c','user.name=Fixture','-c','user.email=fixture@localhost',
            'commit-tree',tree,'-p',parent,input=b'private reviewed seed fixture\n').decode().strip()

    def seed(self):
        names=['.github/workflows/go-erp-update-tests.yml','deployment/verify_go_erp_media_capacity_seed.py']
        changes=[(name,self.git('show',policy.CORE+':'+name)+b'\n# private policy-only proof\n','100644') for name in names]
        return self.child(policy.CORE,changes)

    def test_actual_fixed_core_accepts_only_a_policy_child_and_retains_all_native_bytes(self):
        seed=self.seed()
        proof=policy.validate_seed(self.root,seed)
        self.assertEqual(proof['core'],policy.CORE)
        self.assertEqual(proof['policy_files'],['.github/workflows/go-erp-update-tests.yml',
                                              'deployment/verify_go_erp_media_capacity_seed.py'])
        for name in ['desktop-pos/src/dashboard-download.cjs','desktop-pos/src/local-runtime.cjs',
                     'desktop-pos/test/snapshot-transport.test.cjs','desktop-pos/test/generation-native.cjs',
                     'desktop-pos/package.json','desktop-pos/package-lock.json','tests/desktop_dashboard_runtime/run.php']:
            with self.subTest(name=name):
                self.assertEqual(self.git('rev-parse',seed+':'+name),self.git('rev-parse',policy.CORE+':'+name))

    def test_actual_fixed_core_refuses_native_application_dependency_or_version_changes(self):
        seed=self.seed()
        for name in ['desktop-pos/src/dashboard-download.cjs','desktop-pos/src/local-runtime.cjs',
                     'app/Services/Dashboard/DesktopDashboardMedia.php','desktop-pos/runtime/composer.lock',
                     'desktop-pos/package.json','desktop-pos/package-lock.json']:
            changed=self.child(seed,[(name,self.git('show',seed+':'+name)+b'\n','100644')])
            with self.subTest(name=name),self.assertRaisesRegex(RuntimeError,'UNREVIEWED_SEED_DELTA'):
                policy.validate_seed(self.root,changed)

    def test_actual_legacy_seed_remains_exact_and_cannot_admit_the_new_native_core(self):
        proof=policy.validate_seed(self.root,policy.LEGACY_CAPACITY_SEED,core=policy.LEGACY_CAPACITY_CORE)
        self.assertEqual(proof['core'],policy.LEGACY_CAPACITY_CORE)
        self.assertIn('deployment/apply_go_erp_media_capacity_root.py',proof['policy_files'])
        with self.assertRaisesRegex(RuntimeError,'UNREVIEWED_SEED_DELTA'):
            policy.validate_seed(self.root,policy.CORE,core=policy.LEGACY_CAPACITY_CORE)

if __name__=='__main__':unittest.main()
