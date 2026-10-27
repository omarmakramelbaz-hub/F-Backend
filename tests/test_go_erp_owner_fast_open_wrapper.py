"""The explicit owner fast-open command retains its reviewed package on interruption."""
import ast
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
WRAPPER = ROOT / 'deployment/run_go_erp_owner_fast_open_root.sh'


class WrapperTests(unittest.TestCase):
    def test_syntax_https_host_and_exact_59_predecessor_are_preserved(self):
        subprocess.run(['/bin/bash', '-n', str(WRAPPER)], check=True)
        raw = WRAPPER.read_text()
        for required in ('set -euo pipefail', 'umask 077', 'test "$(id -u)" -eq 0',
                'test "$(hostname -s)" = srv1836524', "'exit 129' HUP", "'exit 143' TERM",
                'https://github.com/omarmakramelbaz-hub/F-Backend.git',
                'go_input=5becb9bab38a91998d1b1341670b2b398e4ba70f',
                'go_previous=33e08c9fec93501d2376eb88d9fd402043f8d792',
                'go_old_seed=0885a3b550f92df2b74bcd2c16d27954d8b44b7b',
                'go_old_core=13fd8b038bd1a853a30ba9b4140a053088efa73d',
                'GIT_NO_REPLACE_OBJECTS=1', 'GIT_CONFIG_GLOBAL=/dev/null',
                'credential.helper=', 'go_done=0', 'if ((go_done)); then',
                'GO_ERP_OWNER_FAST_OPEN_PACKAGE_RETAINED',
                'GO_ERP_OWNER_FAST_OPEN_MANUAL_PATH_MAY_BE_PAUSED_RETRY_SAME_COMMAND'):
            self.assertIn(required, raw)
        self.assertNotIn('go-erp-navigation.p5p1DAmt', raw)
        self.assertNotIn('restart', raw)
        self.assertNotIn('--force ', raw)
        self.assertNotIn('systemctl', raw)
        self.assertNotIn('retry_at', raw)
        self.assertEqual(raw.count('go_done=1'), 1)
        self.assertGreater(raw.index('go_done=1'), raw.index('"$go_stage/result.txt" "$go_mode"'))

    def validator(self):
        raw=WRAPPER.read_text()
        code=raw.split("/usr/bin/python3 -I -B -c '\n",1)[1].split("\n' \"$go_stage/result.txt\"",1)[0]
        compile(code,'<wrapper-result-validator>','exec')
        return code

    def validate(self, result, marker='GO_ERP_OWNER_FAST_OPEN_PUBLISHED_MANUAL_UPDATES_ONLY', mode='--complete'):
        with tempfile.NamedTemporaryFile(mode='w') as value:
            value.write(json.dumps(result)+'\n'+marker+'\n'); value.flush()
            return subprocess.run(['python3','-I','-B','-c',self.validator(),value.name,mode],
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode

    def result(self):
        return {'format':1,'kind':'go-erp-owner-fast-open-rollout','ok':True,'published':True,
            'prepared':True,'manual_path_paused':False,'database_changed':False,
            'credentials_disclosed':False,'automatic_updates_enabled':False,
            'version':'0.4.4-preview.60','candidate_revision':'a'*40,
            'archive':{'available':True,'current_version':'0.4.4-preview.60'}}

    def test_success_requires_final_complete_60_and_available_canonical_archive(self):
        self.assertEqual(self.validate(self.result()),0)
        for field,value in [('format',True),('kind','go-erp-navigation-rollout'),('ok',False),
                ('published',False),('prepared',False),('manual_path_paused',True),
                ('database_changed',True),('credentials_disclosed',True),('automatic_updates_enabled',True),
                ('version','0.4.4-preview.59'),('version','0.4.4-preview.61'),
                ('candidate_revision','private'),('archive',{'available':False,'current_version':'0.4.4-preview.60'}),
                ('archive',{'available':True,'current_version':'0.4.4-preview.59'})]:
            changed=self.result();changed[field]=value
            with self.subTest(field=field,value=value):
                self.assertNotEqual(self.validate(changed),0)
        for marker in ('GO_ERP_LIVE_UPDATE_PUBLISHED','GO_ERP_OWNER_FAST_OPEN_PREPARED_PUBLICATION_HELD',''):
            self.assertNotEqual(self.validate(self.result(),marker),0)

    def test_apply_status_cannot_claim_publication(self):
        prepared=self.result();prepared.update(published=False,manual_path_paused=True)
        marker='GO_ERP_OWNER_FAST_OPEN_PREPARED_PUBLICATION_HELD'
        self.assertEqual(self.validate(prepared,marker,'--apply'),0)
        self.assertNotEqual(self.validate(prepared,marker,'--complete'),0)

    def test_manual_broker_and_guard_functions_are_the_original_59_functions(self):
        def definitions(path):
            raw=path.read_text()
            return {n.name:ast.get_source_segment(raw,n) for n in ast.parse(raw).body
                if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
        self.assertEqual(definitions(ROOT/'deployment/go_erp_owner_fast_open_manual_root.py'),
            definitions(ROOT/'deployment/go_erp_navigation_manual_root.py'))
        actual=definitions(ROOT/'deployment/go_erp_owner_fast_open_tick_root.py')
        original=definitions(ROOT/'deployment/go_erp_navigation_tick_root.py')
        self.assertEqual(actual.keys(),original.keys())
        for name in actual:
            self.assertEqual(actual[name].replace('go_erp_owner_fast_open_pinned_source',
                'go_erp_navigation_pinned_source'),original[name],name)


if __name__=='__main__':unittest.main()
