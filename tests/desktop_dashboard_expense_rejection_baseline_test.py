#!/usr/bin/env python3
"""An approved patch cohort may select only the same complete release target."""
import copy
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'deployment'))
import refresh_desktop_dashboard_server_manifest as release
import refresh_desktop_dashboard_expense_rejection_manifest as variant
import apply_desktop_dashboard_server as installer

class ExpensePatchCohortTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Keep exercising the original exact four-file transformation against
        # its immutable original source/GUI profile, never against today's app.
        cls.base=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline-20261019-173156.json').read_text())
        cls.target=json.loads((ROOT/'tests/fixtures/desktop-dashboard-target-source-20261020-4288.json').read_text())
        cls.known=json.loads((ROOT/'deployment/desktop_dashboard_known_expense_rejection.json').read_text())

    def historical_target(self):
        return patch.object(release,'scan',return_value=copy.deepcopy(self.target['files']))

    def historical_gui(self):
        return patch.object(release,'file_record',side_effect=lambda path:
            self.target['gui'][path.relative_to(ROOT).as_posix()])

    def test_exact_four_file_transform_converges_on_one_target_without_claiming_measurement(self):
        self.assertEqual(release.digest(self.target['files']),self.target['source'])
        self.assertEqual(self.target['source']['sha256'],'4288c371691750f5278d145b4d036be0d78d7c170d18fd7d61217954c2866a56')
        with self.historical_target(),self.historical_gui():
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
        with self.historical_target(),self.historical_gui():
            primary,_=release.generate(ROOT,self.base['files'],diagnosis)
        installer.validate_manifest_pair(primary,manifest)
        reverse=copy.deepcopy(self.target['files'])
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

    def test_current_measured_cohort_cannot_be_derived_or_selected_as_the_historical_pair(self):
        current=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline.json').read_text())
        self.assertEqual(current['source']['sha256'],'130b4e77cdff3a3b54168ebeb124a27d9bbfc92970d5d3d740c7231bd18ff8fb')
        with self.assertRaisesRegex(ValueError,'measured base'):
            variant.derive(ROOT,current,self.known)
        for path in ['deployment/desktop_dashboard_server_manifest-20261020-4288.json',
                     'deployment/desktop_dashboard_server_manifest_expense_rejection-20261020-4288.json']:
            historical=json.loads((ROOT/path).read_text())
            self.assertNotEqual(historical['before_source'],current['source'])
            with patch.object(installer,'read_source',side_effect=AssertionError('Unexpected historical source read')):
                self.assertFalse(installer.baseline_matches(ROOT,0,historical,current['expected_head'],current['source']))

if __name__=='__main__':unittest.main()
