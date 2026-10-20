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
        self.assertEqual(self.fixture['source'],{'format':1,'files':1164,
            'sha256':'105f42de8dd0392af33c7d730541f860919a624b736dd9d277275aabce4487c7','framework':'8.83.29'})
        self.assertEqual(refresh.digest(self.fixture['files']),self.fixture['source'])
        self.assertEqual(len(self.fixture['measured_source_differences']),289)
        self.assertEqual(len(self.fixture['gui']),27)
        for name,pin in self.fixture['measured_source_differences'].items():
            self.assertEqual(self.fixture['files'][name]['blob'],pin,name)
        self.assertEqual(self.fixture['files']['config/whatsapp_cart.php']['blob'],'a40fd3c834734ebbf5f52410e1b0641657605750')

    def test_release_reverses_to_the_complete_measured_map_and_preserves_gui(self):
        self.assertEqual(refresh.digest(self.target),self.manifest['after_source'])
        self.assertEqual(self.manifest['after_source']['files'],1324)
        self.assertEqual(self.manifest['after_source']['sha256'],'e1c889bfd170466c7c81e1d8da5d375606eda60d7526b8a48ae348ce36cad1f7')
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
        retained={name:record['blob']for name,record in self.fixture['files'].items()
            if record==self.target[name]}
        self.assertEqual({row['path']:row['blob']for row in self.manifest['protected']},
            {**retained,**self.fixture['gui']})
        self.assertEqual(installer.manifest_target_profile(self.manifest),
            {**{name:record['blob']for name,record in self.target.items()},**self.fixture['gui']})
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

    def test_all_new_live_updates_are_retained_or_have_exact_explicit_merge_boundaries(self):
        prior=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline-20261019-173156.json').read_text())
        changed={name for name in self.fixture['files']
            if self.fixture['files'][name]!=prior['files'].get(name)}
        self.assertEqual(len(changed),27)
        self.assertEqual(len(set(self.fixture['files'])-set(prior['files'])),7)
        merged={'app/Console/Kernel.php','app/Services/Dashboard/BranchShiftClosing.php',
            'public/dashboard/js/branch-shifts.js'}
        for name in changed-merged:self.assertEqual(self.target[name],self.fixture['files'][name],name)
        rows={row['path']:row for row in self.manifest['sources']}
        for name in merged:
            self.assertEqual(rows[name]['before_blob'],self.fixture['files'][name]['blob'],name)
            self.assertEqual(rows[name]['after_blob'],self.target[name]['blob'],name)
        cache='database/migrations/2026_10_20_045322_create_cache_table.php'
        self.assertEqual(self.fixture['files'][cache]['blob'],'88cd44590c029964801da3f058d89413f728ceb1')
        self.assertNotIn(cache,rows);self.assertNotIn(cache,self.manifest['migrations'])
        self.assertEqual(next(row['blob']for row in self.manifest['protected']if row['path']==cache),self.fixture['files'][cache]['blob'])

    def test_every_retained_raw_source_byte_and_all_current_gui_are_checked_by_the_selector(self):
        by_path={row['path']:row['blob']for row in self.manifest['protected']}
        with tempfile.TemporaryDirectory(prefix='desktop-retained-baseline-')as folder:
            root=Path(folder);uid=os.geteuid()
            def exists(path):
                relative=path.relative_to(root).as_posix()
                source=next((row for row in self.manifest['sources']if row['path']==relative),None)
                return source is not None and source['before_blob']is not None
            before={row['path']:row['before_blob']for row in self.manifest['sources']if row['before_blob']is not None}
            reads={**before,**by_path}
            def source_bytes(path,*_):return reads[path.relative_to(root).as_posix()].encode()
            with patch.object(Path,'exists',exists),patch.object(Path,'is_symlink',return_value=False),\
                    patch.object(installer,'read_source',side_effect=source_bytes),\
                    patch.object(installer,'blob',side_effect=lambda value:value.decode()):
                self.assertTrue(installer.baseline_matches(root,uid,self.manifest,self.fixture['expected_head'],self.fixture['source']))
                for name in ['database/migrations/2026_10_20_045322_create_cache_table.php',
                             'app/Services/Dashboard/FasakhanstaClaudeTransport.php',
                             'public/js/dashboard-meta-ai-controls-replies-20261020-v2.js']:
                    prior=reads[name];reads[name]='f'*40
                    self.assertFalse(installer.baseline_matches(root,uid,self.manifest,self.fixture['expected_head'],self.fixture['source']),name)
                    reads[name]=prior

    def test_historical_measured_profiles_are_immutable_and_no_longer_staged_by_root(self):
        pins={
            'tests/fixtures/desktop-actual-server-baseline-20261019-173156.json':'b8d8468c92c315923084d6d27cc20acd5b0cc803',
            'deployment/desktop_dashboard_server_manifest-20261020-4288.json':'58cb5d1e1e05f4ea6b3a9730983a12a543c8059f',
            'deployment/desktop_dashboard_server_manifest_expense_rejection-20261020-4288.json':'262783253c9b4d903ce0a6f1f7aa5944fec987ed',
            'tests/fixtures/desktop-dashboard-target-source-20261020-4288.json':'aee5cb9aab835951fdfdaabc0a7567e748e79a58',
        }
        for name,pin in pins.items():self.assertEqual(refresh.blob((ROOT/name).read_bytes()),pin,name)
        source=(ROOT/'deployment/apply_desktop_dashboard_server_root.sh').read_text()
        self.assertNotIn("'deployment/desktop_dashboard_server_manifest_expense_rejection.json':",source)

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
