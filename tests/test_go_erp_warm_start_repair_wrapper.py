"""The wrapper exposes bounded progress and never prints an unverified success."""
import ast
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
from go_erp_warm_start_repair_reference import REFERENCE
RAW=(ROOT/'deployment/run_go_erp_warm_start_repair_root.sh').read_text()


class WrapperTests(unittest.TestCase):
    def filter(self,text):
        code=RAW.split("| /usr/bin/python3 -u -c '\n",1)[1].split("\n'; then",1)[0]
        return subprocess.run(['/usr/bin/python3','-I','-B','-c',code],input=text,text=True,capture_output=True)
    def validate(self,value,mode='--complete',marker=None):
        code=RAW.split("/usr/bin/python3 -I -B -c '\n",1)[1].split("\n' \"$go_stage/result.txt\"",1)[0]
        if marker is None:marker='GO_ERP_WARM_START_REPAIR_PUBLISHED_MANUAL_UPDATES_ONLY' if mode=='--complete' else 'GO_ERP_WARM_START_REPAIR_PREPARED_PUBLICATION_HELD'
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'result.txt';path.write_text(json.dumps(value)+'\n'+marker+'\n')
            return subprocess.run(['/usr/bin/python3','-I','-B','-c',code,str(path),mode],capture_output=True)
    def success(self):
        return {'format':1,'kind':'go-erp-warm-start-repair-rollout','ok':True,'published':True,'prepared':True,
            'manual_path_paused':False,'database_changed':False,'credentials_disclosed':False,
            'automatic_updates_enabled':False,'version':'0.4.4-preview.54','candidate_revision':'a'*40,
            'archive':{'available':True,'current_version':'0.4.4-preview.54'}}
    def test_shell_syntax_and_all_four_immutable_dependency_commits_are_fetched_and_checked(self):
        self.assertEqual(subprocess.run(['bash','-n',str(ROOT/'deployment/run_go_erp_warm_start_repair_root.sh')]).returncode,0)
        fetch=RAW.split('https://github.com/omarmakramelbaz-hub/F-Backend.git ',1)[1].split('\n',1)[0]
        checked=RAW.split('for go_commit in ',1)[1].split('; do',1)[0]
        for name in ('go_revision','go_bootstrap','go_prior','go_previous','go_input','go_core','go_archive'):
            self.assertIn('"$'+name+'"',fetch);self.assertIn('"$'+name+'"',checked)
    def test_progress_only_forwards_bounded_codes(self):
        result=self.filter('private line DO_NOT_PRINT\nGO_ERP_LIVE_UPDATE_WAITING_CI\n'
            +'GO_ERP_WARM_START_REPAIR_WAITING_WORKER seconds=30\n'
            +'GO_ERP_WARM_START_REPAIR_PUBLISHED_MANUAL_UPDATES_ONLY\n')
        self.assertEqual(result.returncode,0)
        self.assertEqual(result.stdout,'GO_ERP_LIVE_UPDATE_WAITING_CI\nGO_ERP_WARM_START_REPAIR_WAITING_WORKER seconds=30\n')
    def test_the_previous_rollout_evidence_line_is_not_forwarded(self):
        result=self.filter('GO_ERP_PROGRAM_CONTRACT_VERIFIED42 {"verified": true}\n')
        self.assertEqual(result.returncode,0);self.assertEqual(result.stdout,'')
    def test_success_requires_the_verified_new_current_download_and_safe_server_flags(self):
        self.assertEqual(self.validate(self.success()).returncode,0)
        for key,value in (('published',False),('ok',False),('database_changed',True),
                ('automatic_updates_enabled',True),('manual_path_paused',True),('format',True)):
            with self.subTest(key=key):
                candidate=self.success();candidate[key]=value
                self.assertNotEqual(self.validate(candidate).returncode,0)
        candidate=self.success();candidate['archive']['current_version']='0.4.4-preview.52'
        self.assertNotEqual(self.validate(candidate).returncode,0)
        candidate=self.success();candidate['archive']['available']=False
        self.assertNotEqual(self.validate(candidate).returncode,0)
    def test_prepare_and_complete_markers_cannot_be_swapped_or_forged(self):
        value=self.success();value.update(published=False,manual_path_paused=True)
        self.assertEqual(self.validate(value,'--apply').returncode,0)
        self.assertNotEqual(self.validate(value,'--complete').returncode,0)
        self.assertNotEqual(self.validate(self.success(),marker='GO_ERP_SOMETHING_READY').returncode,0)


    def test_final_seed_is_a_three_file_direct_child_of_the_reviewed_native_core(self):
        core='a237b4c048887c6af1b0fb53fe02c414c1d17cd6';seed='1556e002f18d00b9e8f9ba81590843e0d8697318'
        def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
        self.assertEqual(git('rev-parse',seed+'^').decode().strip(),core)
        self.assertEqual(set(git('diff-tree','-r','--no-commit-id','--name-only','--no-renames',core,seed).decode().splitlines()),
            {'.github/workflows/go-erp-update-tests.yml','deployment/verify_go_erp_preview31_seed.py','tests/test_go_erp_preview31_seed.py'})
        tree=ast.parse(git('show',seed+':deployment/verify_go_erp_preview31_seed.py'))
        values={node.targets[0].id:ast.literal_eval(node.value) for node in tree.body
            if isinstance(node,ast.Assign) and isinstance(node.targets[0],ast.Name) and node.targets[0].id in ('CORE','POLICY')}
        self.assertEqual(values,{'CORE':core,'POLICY':'native-close-reopen-program-contract-warm-start-accounts-v2'})

    def test_repaired_core_only_updates_the_reviewed_dom_fixture_and_preserves_business_source(self):
        core='a237b4c048887c6af1b0fb53fe02c414c1d17cd6'
        def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
        self.assertEqual(git('diff-tree','-r','--no-commit-id','--name-only','--no-renames',
            'c4229ff357c2feda3352c62b0171e2a0b1cb0d78',core).decode().splitlines(),['desktop-pos/test/order-board-commands.test.cjs'])
        raw=git('show',core+':desktop-pos/test/order-board-commands.test.cjs')
        self.assertEqual(hashlib.sha256(raw).hexdigest(),'656289052284631dcfd728295baa71c77f6182435d8ed5e04c11f6abea79bbce')
        self.assertEqual(git('diff-tree','-r','--no-commit-id','--name-only','--no-renames',
            'afb333bbddd9dbc908e3c3674d99c766ed5c4053',core,'--','app','bootstrap','config','database','desktop','public','resources','routes','artisan','composer.json','composer.lock'),b'')


if __name__=='__main__':unittest.main()
