#!/usr/bin/env python3
"""An approved patch cohort may select only the same complete release target."""
import copy
import json
from pathlib import Path
import sys
import unittest

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'deployment'))
import refresh_desktop_dashboard_server_manifest as release
import refresh_desktop_dashboard_expense_rejection_manifest as variant
import apply_desktop_dashboard_server as installer

class ExpensePatchCohortTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.base=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline.json').read_text())
        cls.known=json.loads((ROOT/'deployment/desktop_dashboard_known_expense_rejection.json').read_text())

    def test_exact_four_file_transform_converges_on_one_target_without_claiming_measurement(self):
        manifest,fixture=variant.derive(ROOT,self.base,self.known)
        self.assertNotEqual(fixture['source'],self.base['source'])
        self.assertEqual(release.digest(fixture['files']),fixture['source'])
        changed={name for name,value in fixture['files'].items()if value!=self.base['files'][name]}
        self.assertEqual(changed,variant.PATHS)
        self.assertEqual(fixture['provenance']['kind'],'derived-exact-patch-cohort')
        self.assertNotIn('measured_source_differences',fixture)
        self.assertEqual(fixture['gui'],self.base['gui'])
        diagnosis={'actual_head':self.base['expected_head'],'actual_source':self.base['source'],
            'source_differences':[{'path':name,'actual':value}for name,value in self.base['measured_source_differences'].items()],
            'gui':[{'path':name,'actual':value}for name,value in self.base['gui'].items()]}
        primary,_=release.generate(ROOT,self.base['files'],diagnosis)
        installer.validate_manifest_pair(primary,manifest)
        reverse=release.scan(ROOT)
        for row in manifest['sources']:
            if row['path']not in reverse:continue
            if row['before_blob']is None:reverse.pop(row['path'])
            else:reverse[row['path']]=fixture['files'][row['path']]
        self.assertEqual(reverse,fixture['files'])
        target=installer.manifest_target_profile(primary)
        for row in manifest['protected']:
            self.assertEqual(row['blob'],target[row['path']])

    def test_unknown_base_patch_or_partial_transform_is_not_an_approved_cohort(self):
        for mutate in [lambda b,k:b['source'].update(sha256='a'*64),
                       lambda b,k:b.update(expected_head='a'*40),
                       lambda b,k:k['patch'].update(commit='b'*40),
                       lambda b,k:k['changes'].pop('resources/lang/en/expenses.php'),
                       lambda b,k:k['changes']['resources/lang/en/expenses.php'].update(before_blob='c'*40)]:
            base=copy.deepcopy(self.base);known=copy.deepcopy(self.known);mutate(base,known)
            with self.subTest(base=base['source']['sha256'],paths=len(known['changes'])):
                with self.assertRaises(ValueError):variant.derive(ROOT,base,known)

if __name__=='__main__':unittest.main()
