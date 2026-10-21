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
        cls.previous=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline-20261020-91364.json').read_text())
        cls.previous_manifest=json.loads((ROOT/'deployment/desktop_dashboard_server_manifest-20261020-91364.json').read_text())
        cls.intermediate=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline-20261020-0dc8.json').read_text())
        cls.intermediate_manifest=json.loads((ROOT/'deployment/desktop_dashboard_server_manifest-20261020-0dc8.json').read_text())
        cls.previous_evidence=json.loads((ROOT/'tests/fixtures/desktop-live-source-diagnosis-20261020-213904.json').read_text())
        cls.evidence=json.loads((ROOT/'tests/fixtures/desktop-live-source-diagnosis-20261021-160126.json').read_text())
        cls.target=refresh.scan(ROOT)
        cls.diagnosis={'actual_head':cls.fixture['expected_head'],'actual_source':cls.fixture['source'],
            'source_differences':copy.deepcopy(cls.evidence['source_differences']),
            'gui':[{'path':name,'actual':pin}for name,pin in cls.fixture['gui'].items()]}

    def test_fixture_matches_the_exact_measured_production_evidence(self):
        self.assertEqual(self.fixture['expected_head'],'ee52f1798a542d2c5625c863112315a8aaba02cf')
        self.assertEqual(self.fixture['source'],{'format':1,'files':1164,
            'sha256':'130b4e77cdff3a3b54168ebeb124a27d9bbfc92970d5d3d740c7231bd18ff8fb','framework':'8.83.29'})
        self.assertEqual(refresh.digest(self.fixture['files']),self.fixture['source'])
        self.assertEqual(len(self.fixture['measured_source_differences']),13)
        self.assertEqual(len(self.fixture['gui']),27)
        for name,pin in self.fixture['measured_source_differences'].items():
            self.assertEqual(self.fixture['files'][name]['blob'],pin,name)
        self.assertEqual(self.fixture['files']['config/whatsapp_cart.php']['blob'],'a40fd3c834734ebbf5f52410e1b0641657605750')

    def test_release_reverses_to_the_complete_measured_map_and_preserves_gui(self):
        self.assertEqual(refresh.digest(self.target),self.manifest['after_source'])
        self.assertEqual(self.manifest['after_source']['files'],1326)
        self.assertEqual(self.manifest['after_source']['sha256'],'92a8872d9de924dd97ce3c45291c47ebd38e9659a5b6a300fb88a3bf79038dae')
        self.assertIn('app/Services/Dashboard/DesktopDashboardCustomerFacts.php', self.target)
        self.assertIn('app/Services/Dashboard/DesktopDashboardCustomerDirectory.php', self.target)
        self.assertIn('resources/views/admin/contracts/pdf.blade.php', self.target)
        self.assertIn('app/Services/Dashboard/DesktopDashboardUpdates.php', self.target)
        self.assertIn('app/Http/Controllers/Dashboard/DesktopDashboardUpdateController.php', self.target)
        self.assertEqual(len(self.manifest['sources']),266)
        self.assertEqual(len(self.manifest['protected']),1087)
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

    def test_prior_27_live_updates_and_explicit_merge_boundaries_remain_proven(self):
        prior=json.loads((ROOT/'tests/fixtures/desktop-actual-server-baseline-20261019-173156.json').read_text())
        historical=self.previous
        self.assertEqual(historical['source']['sha256'],'105f42de8dd0392af33c7d730541f860919a624b736dd9d277275aabce4487c7')
        self.assertEqual(len(historical['measured_source_differences']),289)
        self.assertEqual(refresh.digest(historical['files']),historical['source'])
        changed={name for name in historical['files']
            if historical['files'][name]!=prior['files'].get(name)}
        self.assertEqual(len(changed),27)
        self.assertEqual(len(set(historical['files'])-set(prior['files'])),7)
        merged={'app/Console/Kernel.php','app/Services/Dashboard/BranchShiftClosing.php',
            'public/dashboard/js/branch-shifts.js'}
        historical_target=installer.manifest_target_profile(self.previous_manifest)
        for name in changed-merged:
            self.assertEqual(historical_target[name],historical['files'][name]['blob'],name)
            self.assertEqual(self.target[name],self.fixture['files'][name],name)
        previous_rows={row['path']:row for row in self.previous_manifest['sources']}
        rows={row['path']:row for row in self.manifest['sources']}
        for name in merged:
            self.assertEqual(previous_rows[name]['before_blob'],historical['files'][name]['blob'],name)
            self.assertEqual(previous_rows[name]['after_blob'],self.target[name]['blob'],name)
            self.assertEqual(rows[name]['before_blob'],self.fixture['files'][name]['blob'],name)
            self.assertEqual(rows[name]['after_blob'],self.target[name]['blob'],name)
        cache='database/migrations/2026_10_20_045322_create_cache_table.php'
        self.assertEqual(self.fixture['files'][cache]['blob'],'88cd44590c029964801da3f058d89413f728ceb1')
        self.assertNotIn(cache,rows);self.assertNotIn(cache,self.manifest['migrations'])
        self.assertEqual(next(row['blob']for row in self.manifest['protected']if row['path']==cache),self.fixture['files'][cache]['blob'])
        self.assertEqual(next(row['blob']for row in self.previous_manifest['protected']if row['path']==cache),historical['files'][cache]['blob'])

    def test_latest_thirteen_live_updates_reconstruct_the_exact_measured_cohort_and_are_all_protected(self):
        evidence=self.evidence
        self.assertEqual(evidence['kind'],'desktop-current-baseline-read-only-diagnosis')
        self.assertEqual(evidence['actual_head'],self.fixture['expected_head'])
        self.assertEqual(evidence['expected_source'],self.previous['source'])
        self.assertEqual(evidence['actual_source'],self.fixture['source'])
        self.assertEqual(evidence['source_difference_count'],13)
        self.assertIs(evidence['source_differences_truncated'],False)
        for key in ['deployment_performed','metadata_changed','php_bootstrapped','database_read','source_content_printed']:
            self.assertIs(evidence[key],False,key)
        actual=copy.deepcopy(self.previous['files'])
        changed={name for name in self.fixture['files']if self.fixture['files'][name]!=self.previous['files'].get(name)}
        self.assertEqual(changed,set(self.fixture['measured_source_differences']))
        self.assertEqual(len(changed),13)
        self.assertEqual(set(self.fixture['files']),set(self.previous['files']))
        protected={row['path']:row['blob']for row in self.manifest['protected']}
        old_protected={row['path']:row['blob']for row in self.previous_manifest['protected']}
        published={row['path']for row in self.manifest['sources']}
        for row in evidence['source_differences']:
            name=row['path']
            self.assertEqual(row['change'],'normalized_changed')
            self.assertEqual(row['expected'],actual[name],name)
            actual[name]=row['actual']
            self.assertEqual(actual[name],self.fixture['files'][name],name)
            self.assertEqual(actual[name],self.target[name],name)
            self.assertEqual(old_protected[name],row['expected']['blob'],name)
            self.assertEqual(protected[name],row['actual']['blob'],name)
            self.assertNotIn(name,published)
            self.assertEqual(row['metadata'],{'uid':1001,'gid':1003,'mode':'0644','links':1,'regular':True})
        self.assertEqual(actual,self.fixture['files'])
        self.assertEqual(refresh.digest(actual),evidence['actual_source'])
        self.assertEqual(self.fixture['gui'],self.previous['gui'])
        self.assertEqual(len(evidence['gui']),27)
        for row in evidence['gui']:
            self.assertEqual(row['expected'],row['actual'],row['path'])
            self.assertEqual(self.fixture['gui'][row['path']],row['actual'])
        immediate={name for name in self.fixture['gui']if name.startswith(('public/js/','public/css/'))}
        self.assertEqual({row['path']for row in evidence['additional_dashboard_gui']},immediate)
        self.assertEqual(len(immediate),26)
        provenance=self.fixture['provenance']
        self.assertEqual(provenance['diagnosis_blob'],refresh.blob((ROOT/provenance['diagnosis_fixture']).read_bytes()))
        self.assertEqual(provenance['previous_baseline_blob'],refresh.blob((ROOT/provenance['previous_baseline_fixture']).read_bytes()))

    def test_prior_nine_live_updates_remain_proven_by_the_archived_cohort(self):
        fixture=self.intermediate;evidence=self.previous_evidence
        self.assertEqual(fixture['source']['sha256'],'9ac0d2555aa6325563a7052f8aa4d304b60e7cecf4f3c515e3d736c57c279442')
        self.assertEqual(evidence['actual_source'],fixture['source'])
        self.assertEqual(evidence['expected_source'],self.previous['source'])
        self.assertEqual(evidence['actual_head'],fixture['expected_head'])
        self.assertEqual(evidence['source_difference_count'],9)
        self.assertIs(evidence['source_differences_truncated'],False)
        for key in ['deployment_performed','metadata_changed','php_bootstrapped','database_read','source_content_printed']:
            self.assertIs(evidence[key],False,key)
        reconstructed=copy.deepcopy(self.previous['files'])
        protected={row['path']:row['blob']for row in self.intermediate_manifest['protected']}
        published={row['path']for row in self.intermediate_manifest['sources']}
        historical_target=installer.manifest_target_profile(self.intermediate_manifest)
        observed=set()
        for row in evidence['source_differences']:
            name=row['path'];observed.add(name)
            self.assertEqual(row['change'],'normalized_changed')
            self.assertEqual(row['expected'],reconstructed[name],name)
            reconstructed[name]=row['actual']
            self.assertEqual(row['actual'],fixture['files'][name],name)
            self.assertEqual(protected[name],row['actual']['blob'],name)
            self.assertEqual(historical_target[name],row['actual']['blob'],name)
            self.assertNotIn(name,published)
            self.assertEqual(self.fixture['files'][name],row['actual'],name)
        self.assertEqual(len(observed),9)
        self.assertEqual(observed,set(fixture['measured_source_differences']))
        self.assertEqual(reconstructed,fixture['files'])
        self.assertEqual(refresh.digest(reconstructed),fixture['source'])
        self.assertEqual(self.intermediate_manifest['before_source'],fixture['source'])
        self.assertEqual(self.intermediate_manifest['after_source']['sha256'],'f29af750f417b6fe3bbdef0c18d540bde8f919e218b047c3f62752524e901950')
        self.assertEqual(fixture['gui'],self.previous['gui'])
        provenance=fixture['provenance']
        self.assertEqual(provenance['diagnosis_blob'],refresh.blob((ROOT/provenance['diagnosis_fixture']).read_bytes()))
        self.assertEqual(provenance['previous_baseline_blob'],refresh.blob((ROOT/provenance['previous_baseline_fixture']).read_bytes()))

    def test_four_new_live_updates_are_retained_without_replacing_their_measured_bytes(self):
        latest={
            'app/Services/Dashboard/ExpenseStockPurchases.php':'74d4fb3e881c5ba59787c4dc909a080ef146e46d',
            'public/dashboard/css/dining-pos.css':'76b66d8a658d1aa275a32573bd71bf233e0d10bc',
            'public/dashboard/css/takeaway-pos.css':'9a7f1d9ac0ac32742376c74b376b426c83e0e3f1',
            'public/dashboard/js/takeaway-pos.js':'f8acd53642dfd78a4b22c8392603b7e1c535e1ea',
        }
        changed={name for name in self.fixture['files']if self.fixture['files'][name]!=self.intermediate['files'].get(name)}
        self.assertEqual(changed,set(latest))
        self.assertEqual(set(self.fixture['files']),set(self.intermediate['files']))
        self.assertEqual(set(self.fixture['measured_source_differences'])-set(self.intermediate['measured_source_differences']),set(latest))
        protected={row['path']:row['blob']for row in self.manifest['protected']}
        published={row['path']for row in self.manifest['sources']}
        for name,pin in latest.items():
            self.assertEqual(self.fixture['files'][name]['blob'],pin,name)
            self.assertEqual(self.target[name],self.fixture['files'][name],name)
            self.assertEqual(protected[name],pin,name)
            self.assertNotIn(name,published)
        self.assertEqual(self.fixture['gui'],self.intermediate['gui'])

    def test_generator_accepts_current_hash_records_and_keeps_historical_blob_pin_format(self):
        generated,fixture=refresh.generate(ROOT,self.fixture['files'],self.diagnosis)
        self.assertEqual(generated,self.manifest)
        self.assertEqual(fixture['measured_source_differences'],self.fixture['measured_source_differences'])
        self.assertTrue(all(isinstance(pin,str)and len(pin)==40 for pin in fixture['measured_source_differences'].values()))
        historical=copy.deepcopy(self.diagnosis)
        for row in historical['source_differences']:row['actual']=row['actual']['blob']
        legacy_manifest,legacy_fixture=refresh.generate(ROOT,self.fixture['files'],historical)
        self.assertEqual(legacy_manifest,generated)
        self.assertEqual(legacy_fixture,fixture)

    def test_generator_rejects_wrong_normalized_hash_even_when_observed_raw_blob_matches(self):
        diagnosis=copy.deepcopy(self.diagnosis)
        row=diagnosis['source_differences'][0]
        self.assertEqual(row['actual']['blob'],self.fixture['files'][row['path']]['blob'])
        row['actual']['sha256']='f'*64
        with patch.object(refresh,'scan',side_effect=AssertionError('Unexpected target source read')):
            with self.assertRaisesRegex(ValueError,'normalized source hash disagrees'):
                refresh.generate(ROOT,self.fixture['files'],diagnosis)

    def test_live_confirmation_parser_has_one_explicit_compatibility_publication(self):
        name='app/Support/WhatsAppOrderExtraction.php'
        before='a44f791013642aeca4b4f50dd96ec96b59eda012'
        after='b7a5e364a0df5606ad6b97fa789bbe35fcc02151'
        self.assertEqual(self.fixture['files'][name]['blob'],before)
        self.assertEqual(self.previous['files'][name]['blob'],before)
        self.assertEqual(next(row['blob']for row in self.previous_manifest['protected']if row['path']==name),before)
        self.assertNotIn(name,self.fixture['measured_source_differences'])
        self.assertEqual(self.target[name]['blob'],after)
        self.assertEqual(next(row for row in self.manifest['sources']if row['path']==name),
            {'path':name,'before_blob':before,'after_blob':after,'phase':2})
        self.assertNotIn(name,{row['path']for row in self.manifest['protected']})
        self.assertTrue(all(changed not in {row['path']for row in self.manifest['sources']}
            for changed in self.fixture['measured_source_differences']))

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
                             'public/js/dashboard-meta-ai-controls-replies-20261020-v2.js',
                             *self.fixture['measured_source_differences']]:
                    prior=reads[name];reads[name]='f'*40
                    self.assertFalse(installer.baseline_matches(root,uid,self.manifest,self.fixture['expected_head'],self.fixture['source']),name)
                    reads[name]=prior

    def test_historical_measured_profiles_are_immutable_and_no_longer_staged_by_root(self):
        pins={
            'tests/fixtures/desktop-actual-server-baseline-20261019-173156.json':'b8d8468c92c315923084d6d27cc20acd5b0cc803',
            'deployment/desktop_dashboard_server_manifest-20261020-4288.json':'58cb5d1e1e05f4ea6b3a9730983a12a543c8059f',
            'deployment/desktop_dashboard_server_manifest_expense_rejection-20261020-4288.json':'262783253c9b4d903ce0a6f1f7aa5944fec987ed',
            'tests/fixtures/desktop-dashboard-target-source-20261020-4288.json':'aee5cb9aab835951fdfdaabc0a7567e748e79a58',
            'tests/fixtures/desktop-actual-server-baseline-20261020-91364.json':'af07f6572c8a81f0f9bf1cd7b4cdfc0a207ab416',
            'deployment/desktop_dashboard_server_manifest-20261020-91364.json':'b6bb57619504f2093430d5b0fb9baa28bb2bb2a9',
            'tests/fixtures/desktop-live-source-diagnosis-20261020-213904.json':'c559c79314588db60669a407aa5f20d627181546',
            'tests/fixtures/desktop-actual-server-baseline-20261020-0dc8.json':'72e0320189551de17b83f9cb7aa57b3fd0e41bff',
            'deployment/desktop_dashboard_server_manifest-20261020-0dc8.json':'f4a949256986a44e48708529c41f8d2ab8d8c396',
            'tests/fixtures/desktop-live-source-diagnosis-20261021-160126.json':'b4b4286168086f8b1899455f37e88c93ab0c023c',
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
                self.assertFalse(installer.baseline_matches(root,os.geteuid(),self.manifest,
                    self.previous['expected_head'],self.previous['source']))
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
