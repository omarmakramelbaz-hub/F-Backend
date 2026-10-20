#!/usr/bin/env python3
"""Check the measured production cohort and the complete release transformation."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('desktop_manifest_refresh',ROOT/'deployment/refresh_desktop_dashboard_server_manifest.py')
refresh=importlib.util.module_from_spec(spec);spec.loader.exec_module(refresh)
spec=importlib.util.spec_from_file_location('desktop_measured_selector',ROOT/'deployment/apply_desktop_dashboard_server.py')
installer=importlib.util.module_from_spec(spec);spec.loader.exec_module(installer)

class MeasuredBaselineTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline.json').read_text())
        cls.manifest=json.loads((ROOT/'deployment/desktop_dashboard_server_manifest.json').read_text())
        cls.target=refresh.scan(ROOT)
        cls.diagnosis={'actual_head':cls.fixture['expected_head'],'actual_source':cls.fixture['source'],
            'source_differences':[{'path':name,'actual':pin}for name,pin in cls.fixture['measured_source_differences'].items()],
            'gui':[{'path':name,'actual':pin}for name,pin in cls.fixture['gui'].items()]}

    def test_fixture_matches_the_exact_measured_production_evidence(self):
        self.assertEqual(self.fixture['expected_head'],'ee52f1798a542d2c5625c863112315a8aaba02cf')
        self.assertEqual(self.fixture['source'],{'format':1,'files':1157,
            'sha256':'2ef5e2b7cae03101046094627e9f19d94165d75c7981cbff097de14eb13fab9f','framework':'8.83.29'})
        self.assertEqual(refresh.digest(self.fixture['files']),self.fixture['source'])
        self.assertEqual(len(self.fixture['measured_source_differences']),282)
        self.assertEqual(len(self.fixture['gui']),25)
        for name,pin in self.fixture['measured_source_differences'].items():
            self.assertEqual(self.fixture['files'][name]['blob'],pin,name)
        self.assertEqual(self.fixture['files']['config/whatsapp_cart.php']['blob'],'a40fd3c834734ebbf5f52410e1b0641657605750')

    def test_release_reverses_to_the_complete_measured_map_and_preserves_gui(self):
        self.assertEqual(refresh.digest(self.target),self.manifest['after_source'])
        self.assertEqual(self.manifest['after_source']['files'],1317)
        self.assertIn('app/Services/Dashboard/DesktopDashboardCustomerFacts.php', self.target)
        self.assertIn('app/Services/Dashboard/DesktopDashboardCustomerDirectory.php', self.target)
        self.assertIn('resources/views/admin/contracts/pdf.blade.php', self.target)
        reversed_map=copy.deepcopy(self.target)
        for row in self.manifest['sources']:
            name=row['path']
            self.assertEqual(refresh.file_record(ROOT/name)['blob'],row['after_blob'],name)
            if name not in reversed_map:continue
            if row['before_blob']is None:
                self.assertNotIn(name,self.fixture['files']);del reversed_map[name]
            else:
                self.assertEqual(self.fixture['files'][name]['blob'],row['before_blob'],name)
                reversed_map[name]=self.fixture['files'][name]
        self.assertEqual(reversed_map,self.fixture['files'])
        self.assertEqual(self.manifest['before_source'],self.fixture['source'])
        self.assertEqual(self.manifest['expected_head'],self.fixture['expected_head'])
        self.assertEqual({row['path']:row['blob']for row in self.manifest['protected']},self.fixture['gui'])
        for name,pin in self.fixture['gui'].items():self.assertEqual(refresh.file_record(ROOT/name)['blob'],pin,name)
        migrations=[row for row in self.manifest['sources']if row['path'].startswith('database/migrations/')]
        additive=[row for row in migrations if row['path']in refresh.MIGRATIONS]
        self.assertEqual({row['path']for row in additive},set(refresh.MIGRATIONS))
        self.assertTrue(all(row['before_blob']is None and row['phase']==0 for row in additive))
        historical=[row for row in migrations if row['path']not in refresh.MIGRATIONS]
        self.assertEqual(len(historical),1)
        for row in historical:
            self.assertEqual((row['before_blob'],row['after_blob']),refresh.SOURCE_ONLY_MIGRATIONS[row['path']])
            self.assertEqual(row['phase'],2);self.assertNotIn(row['path'],self.manifest['migrations'])
        generated,_=refresh.generate(ROOT,self.fixture['files'],self.diagnosis)
        self.assertEqual(generated,self.manifest)

    def test_semantically_mixed_source_cohort_is_rejected(self):
        mixed=copy.deepcopy(self.fixture['files'])
        changed=next(row['path']for row in self.manifest['sources']if row['path']in mixed
            and mixed[row['path']]['sha256']!=self.target[row['path']]['sha256'])
        mixed[changed]=self.target[changed]
        with self.assertRaisesRegex(ValueError,'does not match observed fingerprint'):
            refresh.generate(ROOT,mixed,self.diagnosis)

    def test_actual_selector_rejects_wrong_head_and_mixed_full_map_before_file_reads(self):
        mixed=copy.deepcopy(self.fixture['files'])
        changed=next(row['path']for row in self.manifest['sources']if row['path']in mixed
            and mixed[row['path']]['sha256']!=self.target[row['path']]['sha256'])
        mixed[changed]=self.target[changed]
        with tempfile.TemporaryDirectory(prefix='desktop-measured-selector-')as folder:
            root=Path(folder)
            with patch.object(installer,'read_source',side_effect=AssertionError('Unexpected source read')):
                self.assertFalse(installer.baseline_matches(root,os.geteuid(),self.manifest,'0'*40,self.fixture['source']))
                self.assertFalse(installer.baseline_matches(root,os.geteuid(),self.manifest,
                    self.fixture['expected_head'],refresh.digest(mixed)))
            self.assertEqual(list(root.iterdir()),[])

    def test_raw_crlf_only_mixed_source_is_rejected_even_with_same_fingerprint(self):
        mixed=copy.deepcopy(self.fixture['files']);name='bootstrap/app.php'
        self.assertNotEqual(mixed[name]['blob'],self.target[name]['blob'])
        self.assertEqual(mixed[name]['sha256'],self.target[name]['sha256'])
        mixed[name]=self.target[name]
        self.assertEqual(refresh.digest(mixed),self.fixture['source'])
        diagnosis=copy.deepcopy(self.diagnosis)
        diagnosis['source_differences'].append({'path':name,'actual':self.fixture['files'][name]['blob']})
        with self.assertRaisesRegex(ValueError,'raw source blob disagrees'):
            refresh.generate(ROOT,mixed,diagnosis)

    def test_prior_measurement_is_preserved_and_new_runtime_changes_do_not_publish_hardlinked_evidence(self):
        prior=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline-20261012.json').read_text())
        self.assertEqual(refresh.digest(prior['files']),prior['source']);self.assertEqual(prior['source']['files'],1020)
        self.assertEqual(prior['source']['sha256'],'dc731529de802c50bdb266fd52c6361d8e460587143a894b077539da811cc47e')
        for name in ['bootstrap/app.php','config/whatsapp_cart.php']:self.assertEqual(self.fixture['files'][name],prior['files'][name])
        name='app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'
        self.assertEqual(json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline-20261019-1323.json').read_text())['provenance']['reported_hardlinks'],{name:2})
        self.assertEqual(self.fixture['files'][name],self.target[name])
        self.assertNotIn(name,{row['path']for row in self.manifest['sources']})

if __name__=='__main__':unittest.main()
