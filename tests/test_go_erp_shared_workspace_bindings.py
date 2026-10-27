"""Real disposable Git lineage and offline release binding; no final refs/effects."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_compiler',ROOT/'deployment/compile_go_erp_shared_workspace_bindings.py')
subject=importlib.util.module_from_spec(spec);spec.loader.exec_module(subject)

class SeedBindingTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name)
        self.git('init','-q');self.git('config','user.name','Disposable binding test');self.git('config','user.email','test@example.invalid')
        self.old='13fd8b038bd1a853a30ba9b4140a053088efa73d';self.policy='native-close-reopen-program-contract-direct-login-accounts-v4'
        self.write(subject.POLICY_FILES[1],("CORE = '"+self.old+"'\nPOLICY = '"+self.policy+"'\ndef original_check():\n    return True\n").encode())
        self.write(subject.POLICY_FILES[2],("def check(self):\n    self.assertEqual(policy.CORE, '"+self.old+"')\n    self.assertEqual(policy.POLICY, '"+self.policy+"')\n").encode())
        self.workflow=("name: preserved feature workflow\n      - name: Load the exact reviewed snapshot transport core\n"
            "        run: git -c gc.auto=0 fetch --quiet --depth=16 --no-tags --no-recurse-submodules origin "+'7'*40+"\n"
            "      - name: Load the exact reviewed private import diagnostic core\n"
            "        run: git -c gc.auto=0 fetch --quiet --depth=16 --no-tags --no-recurse-submodules origin "+self.old+"\n")
        self.write(subject.POLICY_FILES[0],self.workflow.encode());self.core=self.commit();self.reader=subject.Git(self.root/'.git')
    def git(self,*args):
        return subprocess.check_output(['git',*args],cwd=self.root,stderr=subprocess.DEVNULL,
            env={**os.environ,'GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_SYSTEM':'/dev/null'})
    def write(self,name,raw):
        path=self.root/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw)
    def commit(self):
        self.git('add','.');self.git('commit','-qm','Disposable binding fixture');return self.git('rev-parse','HEAD').decode().strip()
    def seed(self):
        output=subject.seed_files(self.reader,self.core)
        for name,raw in output.items():self.write(name,raw)
        return self.commit()
    def test_exact_three_changes_preserve_functions_other_fetch_and_feature_workflow(self):
        output=subject.seed_files(self.reader,self.core)
        self.assertEqual(set(subject.POLICY_FILES),set(output))
        self.assertEqual({'CORE':self.core,'POLICY':subject.SHORT_POLICY},subject.constants(output[subject.POLICY_FILES[1]]))
        self.assertIn(b'def original_check():\n    return True',output[subject.POLICY_FILES[1]])
        self.assertIn(('origin '+'7'*40).encode(),output[subject.POLICY_FILES[0]])
        self.assertIn(b'name: preserved feature workflow',output[subject.POLICY_FILES[0]])
        self.assertEqual(self.core,self.git('rev-parse','HEAD').decode().strip())
        self.assertEqual(b'',self.git('status','--porcelain'))
    def test_actual_direct_seed_parent_and_exact_expected_bytes_are_required(self):
        seed=self.seed();proof=subject.verify_seed(self.reader,self.core,seed)
        self.assertEqual(seed,proof['seed']);self.assertEqual(3,len(proof['policy_files']))
        self.write('app/extra.php',b'<?php // forbidden seed change');later=self.commit()
        with self.assertRaisesRegex(subject.Stop,'SEED_SINGLE_CORE_PARENT_REQUIRED'):subject.verify_seed(self.reader,self.core,later)
    def test_same_parent_extra_source_and_changed_mode_are_refused(self):
        self.seed();self.write('app/extra.php',b'<?php // forbidden');self.git('add','.')
        tree=self.git('write-tree').decode().strip()
        commit=self.git('-c','user.name=Fixture','-c','user.email=test@example.invalid','commit-tree',tree,'-p',self.core,'-m','extra').decode().strip()
        with self.assertRaisesRegex(subject.Stop,'SEED_MODE_OR_OPERATION_CHANGED'):subject.verify_seed(self.reader,self.core,commit)
    def test_disagreeing_test_or_duplicate_diagnostic_fetch_stops_before_output(self):
        before=self.reader.file(subject.POLICY_FILES[2],self.core)
        class Different:
            def commit(_self,_revision):pass
            def file(_self,name,revision):
                raw=self.reader.file(name,revision)
                return raw.replace(self.old.encode(),b'9'*40) if name==subject.POLICY_FILES[2] else raw
        with self.assertRaisesRegex(subject.Stop,'SEED_TEST_CONTEXT'):subject.seed_files(Different(),self.core)
        self.assertEqual(before,self.reader.file(subject.POLICY_FILES[2],self.core))
    def test_seed_stage_writes_review_files_only_and_refuses_reusing_output(self):
        output=self.root/'draft-output'
        args=['seed-files','--git-dir',str(self.root/'.git'),'--core',self.core,'--output',str(output)]
        subject.main(args);manifest=json.loads((output/'seed-plan.json').read_bytes())
        self.assertIsNone(manifest['seed_revision']);self.assertEqual(self.core,manifest['required_parent'])
        with self.assertRaisesRegex(subject.Stop,'OUTPUT_MUST_BE_NEW'):subject.main(args)
    def test_null_or_zero_final_core_cannot_write_review_files(self):
        output=self.root/'blocked'
        with self.assertRaisesRegex(subject.Stop,'FINAL_CORE_UNAVAILABLE'):
            subject.main(['seed-files','--git-dir',str(self.root/'.git'),'--core','0'*40,'--output',str(output)])
        self.assertFalse(output.exists())

    def test_source_plan_additions_and_new_directories_ignore_release_only_branch_deletions(self):
        self.write('config/desktop_dashboard.php',b"<?php return ['workspace_enabled'=>env('DESKTOP_DASHBOARD_WORKSPACE_ENABLED', true)];")
        self.write('app/Existing.php',b'<?php // before');self.write('tests/branch-only.php',b'not deployed')
        baseline=self.commit()
        self.write('config/desktop_dashboard.php',b"<?php return ['workspace_enabled'=>env('DESKTOP_DASHBOARD_WORKSPACE_ENABLED', false)];")
        self.write('app/Existing.php',b'<?php // after');self.write('app/New/Nested.php',b'<?php // new')
        self.git('rm','-q','tests/branch-only.php')
        for name,tables in subject.module('go_erp_shared_workspace_plan.py').MIGRATIONS.items():
            self.write(name,b'<?php\n'+b'\n'.join(b"Schema::create('"+table.encode()+b"', function(){});" for table in tables))
        web=self.commit();plan=subject.source_plan(self.reader,baseline,web)
        self.assertIsNone(plan['paths']['app/New/Nested.php']['before'])
        self.assertIn('app/New',plan['new_directories']);self.assertNotIn('tests/branch-only.php',plan['paths'])
        self.assertEqual(2,len([name for name in plan['paths'] if name.startswith('database/')]))

class ProofAndSafetyTests(unittest.TestCase):
    def test_route_inventory_tracks_exact_provider_includes_methods_and_controller_namespace(self):
        files={'app/Providers/RouteServiceProvider.php':b"Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));\nRoute::middleware('web')->group(base_path('routes/desktop_dashboard.php'));"}
        for file,entry,prefix,namespace,rows in (
            ('routes/desktop_workspace.php','routes/desktop_dashboard.php','admin/desktop-dashboard/workspace','Dashboard',
                [('get','challenge','challenge'),('post','enroll','enroll'),('post','session','session'),('get','local-session','localSession')]),
            ('routes/desktop_workspace_api.php','routes/api.php','desktop-dashboard/workspace','Api',
                [('get','manifest','manifest'),('get','bootstrap','bootstrap'),('post','delta','delta'),('post','commands','ingest'),
                    ('post','print-outcomes','printOutcome'),('post','remote-attempts','remoteAttempt'),('get','media','media')])):
            files[entry]=("require __DIR__.'"+'/'+Path(file).name+"';").encode()
            files[file]=('use App\\Http\\Controllers\\'+namespace+"\\DesktopDashboardWorkspaceController;\nRoute::middleware([])->prefix('"+prefix+"')->group(function(){\n"+
                '\n'.join("Route::"+method+"('"+uri+"',[DesktopDashboardWorkspaceController::class,'"+action+"']);" for method,uri,action in rows)+'\n});').encode()
        git=type('Files',(),{'file':lambda _self,name,_revision:files[name]})()
        routes=subject.workspace_routes(git,'synthetic route fixture');self.assertEqual(11,len(routes))
        self.assertIn(['api/desktop-dashboard/workspace/commands',['POST'],'App\\Http\\Controllers\\Api\\DesktopDashboardWorkspaceController@ingest'],routes)
        self.assertIn(['admin/desktop-dashboard/workspace/local-session',['GET','HEAD'],'App\\Http\\Controllers\\Dashboard\\DesktopDashboardWorkspaceController@localSession'],routes)
        files['routes/api.php']=b'// peer changed route include'
        with self.assertRaisesRegex(subject.Stop,'ROUTE_INCLUDE_CONTEXT'):subject.workspace_routes(git,'synthetic route fixture')
    def fake(self):
        programs={name:b'<?php // reviewed fixture '+name.encode() for name in subject.module('go_erp_shared_workspace_acceptance_root.py').PROGRAMS}
        files={**programs,'desktop/workspace-contract.json':subject.canonical({'programs':{name:subject.sha(raw) for name,raw in programs.items()}}),
            'desktop-pos/package-lock.json':subject.canonical({'packages':{'node_modules/electron':{'version':'44.6.0'}}}),
            '.github/workflows/go-erp-shared-workspace-native.yml':b'name: native',
            '.github/workflows/go-erp-shared-workspace-tests.yml':b'name: foundation'}
        for name in ('main.cjs','workspace-native.cjs','workspace-policy.cjs','other.cjs'):files['desktop-pos/src/'+name]=b'/* '+name.encode()+b' */'
        for name in subject.STARTUP.values():files[name]=b'/* '+name.encode()+b' */'
        files['native-fixture.php']=b'<?php // source roster'
        files['.github/scripts/verify-workspace-runtime-native.cjs']=b"const fs=require('node:fs'),path=require('node:path');function sourceFiles(root){return fs.readdirSync(path.join(root,'desktop-pos/src')).filter(n=>n.startsWith('workspace-')).map(n=>'desktop-pos/src/'+n).concat(['native-fixture.php']);}module.exports={sourceFiles};"
        class Git:
            def file(self,name,_revision):return files[name]
            def files(self,_revision):return {name:('100644','blob',subject.blob(raw)) for name,raw in files.items()}
        return Git(),files
    def test_verifier_exported_roster_and_complete_asar_map_bind_actual_bytes(self):
        git,files=self.fake();startup,asar,accepted=subject.proof_maps(git,'synthetic fixture')
        self.assertEqual({'main.cjs','workspace-native.cjs','workspace-policy.cjs','other.cjs','startup-source-upgrade.cjs'},set(asar))
        self.assertEqual({'desktop-pos/src/workspace-native.cjs','desktop-pos/src/workspace-policy.cjs','native-fixture.php'},set(accepted['source_hashes']))
        files[subject.STARTUP['fixture']]+=b'/* R16 exact fixture */'
        changed=subject.proof_maps(git,'synthetic fixture')[0]
        self.assertNotEqual(startup['fixture'],changed['fixture'])
    def test_verifier_roster_cannot_read_an_unlisted_file_or_return_duplicate_paths(self):
        git,files=self.fake();name='.github/scripts/verify-workspace-runtime-native.cjs'
        files[name]=b"const fs=require('node:fs');module.exports={sourceFiles:()=>fs.readFileSync('/outside')};"
        with self.assertRaisesRegex(subject.Stop,'PINNED_SOURCE_ROSTER_FAILED'):subject.proof_maps(git,'synthetic fixture')
        files[name]=b"module.exports={sourceFiles:()=>['native-fixture.php','native-fixture.php']};"
        with self.assertRaisesRegex(subject.Stop,'NATIVE_PROOF_ROSTER_CHANGED'):subject.proof_maps(git,'synthetic fixture')
    def test_cached_seven_previous_payloads_match_real_derivation_and_changes_fail(self):
        previous=subject.previous_payloads();self.assertEqual(7,len(previous))
        payloads=subject.module('go_erp_shared_workspace_payloads.py').native(previous,subject.module('go_erp_owner_fast_open_payloads.py'),
            seed='a'*40,startup_hashes={name:'b'*64 for name in subject.STARTUP},
            packaged_native_hashes={name:'c'*64 for name in ('main.cjs','workspace-native.cjs','workspace-policy.cjs')})
        self.assertEqual(7,len(payloads));self.assertIn(b'packagedNativeHashes',payloads['go_erp_live_updates.py'])
        with patch.object(subject.Path,'read_bytes',return_value=b'{}'):
            with self.assertRaisesRegex(subject.Stop,'LEGACY_CACHE_CHANGED'):subject.previous_payloads()
    def test_missing_failed_or_rebound_final_core_acceptance_cannot_materialize(self):
        with self.assertRaisesRegex(subject.Stop,'FINAL_CORE_REVIEWED_ACCEPTANCE_REQUIRED'):subject.checked_approval(None,'a'*40)
        value={'kind':'go-erp-reviewed-final-core-acceptance','core':'a'*40,'all_latest_required_ci_accepted':True,
            'actual_native_artifact_strictly_verified':True,'installed_asar_artifact_strictly_verified':True,
            'native_receipt_sha256':'b'*64,'native_artifact_sha256':'c'*64,'installed_asar_receipt_sha256':'d'*64,
            'workflows':{name:{'head_sha':'a'*40,'event':'push','status':'completed','conclusion':'success',
                'path':'.github/workflows/'+name,'id':index+1,'attempt':1} for index,name in enumerate(subject.WORKFLOWS)}}
        subject.checked_approval(value,'a'*40)
        value['workflows'][subject.WORKFLOWS[-1]]['head_sha']='e'*40
        with self.assertRaisesRegex(subject.Stop,'FINAL_CORE_CI_IDENTITY_REQUIRED'):subject.checked_approval(value,'a'*40)

if __name__=='__main__':unittest.main()
