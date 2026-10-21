#!/usr/bin/env python3
"""Push-protection diagnosis exposes only validated source locations and finite types."""
import importlib.util
import hashlib
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest import mock

REPOSITORY=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('push_protection_subject',
    REPOSITORY/'deployment/diagnose_go_erp_push_protection_root.py')
subject=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(subject)

class PushProtectionParserTests(unittest.TestCase):
    candidate='1'*40
    baseline='2'*40
    ancestor='3'*40
    path='config/firebase.php'

    def rejection(self,commit=None,path=None,label='Google API Key',line=23):
        return ('remote: —— '+label+' ——————————————————————\n'
            'remote:  locations:\nremote:    - commit: '+(commit or self.candidate)+'\n'
            'remote:      path: '+(path or self.path)+':'+str(line)+'\n').encode()

    def parse(self,data,**kwargs):
        return subject.parse_rejection(data,{self.path,'public/dashboard/js/main.js'},
            self.candidate,self.baseline,**kwargs)

    def assert_private(self,report):
        encoded=json.dumps(report)
        for text in ['PRIVATE_TOKEN_SENTINEL','PRIVATE_VALUE_SENTINEL','https://private.invalid',
                'private-unknown-path','.env','\\u001b','\\u0000']:
            self.assertNotIn(text,encoded)
        self.assertLessEqual(set(report),{'locations','location_count','redacted_count','complete','origin_counts'})
        for origin,count in report.get('origin_counts',{}).items():
            self.assertIn(origin,{'CANDIDATE_COMMIT','SEED_COMMIT','SEED_ANCESTOR','UNVERIFIED_COMMIT'})
            self.assertIs(type(count),int);self.assertGreaterEqual(count,0)
        for row in report['locations']:
            self.assertEqual(set(row),{'secret_type','commit','path','line','origin'})
            self.assertIs(type(row['line']),int);self.assertGreater(row['line'],0)

    def test_candidate_location_is_exact_known_source_with_finite_type(self):
        report=self.parse(self.rejection())
        self.assertEqual(report['locations'],[{'secret_type':'GOOGLE_API_KEY','commit':self.candidate,
            'path':self.path,'line':23,'origin':'CANDIDATE_COMMIT'}])
        self.assertTrue(report['complete']);self.assertEqual(report['redacted_count'],0)
        self.assert_private(report)

    def test_baseline_commit_location_is_seed_without_ancestry_lookup(self):
        lookup=mock.Mock(side_effect=AssertionError('Unexpected ancestry lookup'))
        report=self.parse(self.rejection(commit=self.baseline),commit_kind=lookup)
        self.assertEqual(report['locations'][0]['origin'],'SEED_COMMIT');lookup.assert_not_called()

    def test_other_commit_requires_positive_seed_ancestor_proof(self):
        lookup=mock.Mock(return_value='SEED_ANCESTOR')
        report=self.parse(self.rejection(commit=self.ancestor),commit_kind=lookup)
        self.assertEqual(report['locations'][0]['origin'],'SEED_ANCESTOR')
        self.assertEqual(report['locations'][0]['commit'],self.ancestor);lookup.assert_called_once_with(self.ancestor)

    def test_unverified_commit_location_and_commit_are_redacted(self):
        report=self.parse(self.rejection(commit=self.ancestor))
        self.assertEqual(report['locations'],[]);self.assertEqual(report['redacted_count'],1)
        self.assertNotIn(self.ancestor,json.dumps(report));self.assert_private(report)

    def test_unknown_source_path_is_redacted_even_for_candidate_commit(self):
        report=self.parse(self.rejection(path='private-unknown-path/.env'))
        self.assertEqual(report['locations'],[]);self.assertEqual(report['redacted_count'],1)
        self.assert_private(report)

    def test_unknown_heading_never_exposes_untrusted_secret_label(self):
        report=self.parse(self.rejection(label='PRIVATE_TOKEN_SENTINEL'))
        self.assertEqual([row['secret_type'] for row in report['locations']],['UNKNOWN_SECRET_TYPE'])
        self.assert_private(report)

    def test_secret_value_url_and_control_messages_are_not_returned(self):
        extra=(b'remote: PRIVATE_VALUE_SENTINEL PRIVATE_TOKEN_SENTINEL\x00\x1b\n'
            b'remote: https://private.invalid/unblock-secret?token=PRIVATE_TOKEN_SENTINEL\n')
        report=self.parse(self.rejection()+extra)
        self.assert_private(report)

    def test_invalid_commit_and_nonpositive_line_are_never_returned(self):
        for commit,line in [('not-a-valid-commit',23),(self.candidate,0),(self.candidate,-1)]:
            with self.subTest(commit=commit,line=line):
                report=self.parse(self.rejection(commit=commit,line=line))
                self.assertEqual(report['locations'],[]);self.assert_private(report)

    def test_locations_are_capped_and_incompleteness_is_explicit(self):
        data=b''.join(self.rejection(line=line) for line in range(1,71))
        report=self.parse(data)
        self.assertLessEqual(len(report['locations']),64);self.assertFalse(report['complete'])
        self.assert_private(report)

    def test_oversized_binary_input_is_bounded_without_raw_disclosure(self):
        report=self.parse(self.rejection()+b'\xffPRIVATE_TOKEN_SENTINEL\x00'*10000)
        self.assertFalse(report['complete']);self.assertLessEqual(len(report['locations']),64)
        self.assert_private(report)

    def test_upstream_capture_boundary_marks_exact_65536_bytes_incomplete(self):
        payload=self.rejection()
        below=self.parse(payload+b' '*(65535-len(payload)))
        boundary=self.parse(payload+b' '*(65536-len(payload)))
        self.assertTrue(below['complete']);self.assertFalse(boundary['complete'])
        self.assertEqual(len(below['locations']),1);self.assertEqual(len(boundary['locations']),1)
        self.assert_private(below);self.assert_private(boundary)

class PushProtectionWrapperTests(unittest.TestCase):
    def fixture(self,protection=True,failure=False):
        calls=[];retry={};doc,source,worker,publisher=object(),object(),object(),object()
        def original_classify(code,stdout,stderr):
            calls.append(('classify',code))
            return 'PUSH_PROTECTION' if protection else 'AUTHENTICATION_FAILED'
        def retry_once(*args):
            calls.append(('retry',args))
            if failure:raise RuntimeError('PRIVATE_RETRY_FAILURE')
            category=retry['classify'](1,b'PRIVATE_VALUE_SENTINEL',b'PRIVATE_TOKEN_SENTINEL')
            return {'ok':False,'category':category,'credentials_disclosed':False}
        retry.update(classify=original_classify,retry_once=retry_once)
        return retry,doc,source,worker,publisher,calls,original_classify

    def test_wrapper_delegates_original_retry_once_and_adds_only_structured_details(self):
        retry,doc,source,worker,publisher,calls,original=self.fixture()
        details={'locations':[],'location_count':0,'redacted_count':1,'complete':True}
        with mock.patch.object(subject,'protection_details',return_value=details) as inspect:
            report=subject.run_wrapper(retry,doc,source,worker,publisher)
        self.assertEqual(report['category'],'PUSH_PROTECTION');self.assertEqual(report['push_protection'],details)
        self.assertEqual([row[0] for row in calls],['retry','classify'])
        self.assertEqual(calls[0][1],(doc,source,worker,publisher));self.assertIs(retry['classify'],original)
        inspect.assert_called_once_with(doc,source,worker,b'PRIVATE_TOKEN_SENTINEL')
        self.assertNotIn('PRIVATE_',json.dumps(report))

    def test_other_categories_do_not_inspect_protection_output(self):
        retry,doc,source,worker,publisher,calls,original=self.fixture(protection=False)
        with mock.patch.object(subject,'protection_details',side_effect=AssertionError('Unexpected scan')) as inspect:
            report=subject.run_wrapper(retry,doc,source,worker,publisher)
        self.assertEqual(report['category'],'AUTHENTICATION_FAILED');inspect.assert_not_called()
        self.assertNotIn('push_protection',report);self.assertIs(retry['classify'],original)

    def test_original_classifier_is_restored_when_retry_raises(self):
        retry,doc,source,worker,publisher,calls,original=self.fixture(failure=True)
        report=subject.run_wrapper(retry,doc,source,worker,publisher)
        self.assertIs(retry['classify'],original);self.assertEqual([row[0] for row in calls],['retry'])
        self.assertFalse(report['ok']);self.assertNotIn('PRIVATE_RETRY_FAILURE',json.dumps(report))

    def test_details_failure_is_private_and_does_not_prevent_original_retry_result(self):
        retry,doc,source,worker,publisher,calls,original=self.fixture()
        with mock.patch.object(subject,'protection_details',side_effect=RuntimeError('PRIVATE_DETAILS_TOKEN')):
            report=subject.run_wrapper(retry,doc,source,worker,publisher)
        self.assertEqual(report['category'],'PUSH_PROTECTION');self.assertIs(retry['classify'],original)
        self.assertFalse(report['push_protection']['complete'])
        self.assertEqual(report['push_protection']['reason'],'UNKNOWN_PRIVATE')
        self.assertNotIn('PRIVATE_',json.dumps(report))

@unittest.skipUnless(os.geteuid()==0,'Trusted candidate fixtures require root ownership.')
class PushProtectionBindingTests(unittest.TestCase):
    def load(self,name,filename):
        spec=importlib.util.spec_from_file_location(name,REPOSITORY/'deployment'/filename)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module

    def test_real_candidate_metadata_tree_and_rejection_bindings_through_wrapper(self):
        source=self.load('protection_bound_source','go_erp_live_source.py')
        doctor=self.load('protection_bound_doctor','diagnose_go_erp_live_watcher_root.py')
        installed_worker=self.load('protection_bound_worker','go_erp_live_updates.py')
        with tempfile.TemporaryDirectory(prefix='go-erp-protection-binding-') as temporary:
            work=Path(temporary)/'work';work.mkdir(mode=0o700)
            staging=work/'staging';staging.mkdir(mode=0o700)
            state_dir=work/'state';state_dir.mkdir(mode=0o700)
            checkout=staging/'building';checkout.mkdir(mode=0o700)
            source._git(checkout,['init','--quiet'])
            source._git(checkout,['remote','add','origin',source.REMOTE_URL])
            old={'app/known.php':b'<?php // OLD_PRIVATE_SOURCE\n',
                'credentials/seed.json':b'{"dummy":"PRIVATE_VALUE_SENTINEL"}\n'}
            def write(name,data):
                path=checkout/name;path.parent.mkdir(parents=True,exist_ok=True)
                path.write_bytes(data);path.chmod(0o644)
            for name,data in old.items():write(name,data)
            source._git(checkout,['add','--all']);source._git(checkout,['commit','--quiet','-m','fixture seed'])
            baseline=source._git(checkout,['rev-parse','HEAD']).decode().strip()
            current={**old,'app/known.php':b'<?php // MODIFIED_PRIVATE_SOURCE\n',
                'app/new.php':b'<?php // ADDED_PRIVATE_SOURCE\n'}
            inventory={name:{'authority':'trusted-base' if name=='credentials/seed.json' else 'live',
                'present':True,'sha256':hashlib.sha256(data).hexdigest(),'bytes':len(data),'mode':'100644'}
                for name,data in current.items()}
            observed=source._metadata(baseline,inventory);version='1.0.0-preview.7'
            destination=staging/('candidate-7-'+observed['inventorySha256'][:16])
            checkout.rename(destination);checkout=destination
            for name,data in current.items():write(name,data)
            metadata=source._candidate_metadata(observed,version,7)
            write(source.CANDIDATE_PATH,source._canonical(metadata)+b'\n')
            source._git(checkout,['add','--all']);source._git(checkout,['commit','--quiet','-m','fixture candidate'])
            candidate=source._git(checkout,['rev-parse','HEAD']).decode().strip()
            state={'counter':7,'observed':observed,'desired':{'commit':candidate,'candidatePath':str(checkout),
                'inventorySha256':observed['inventorySha256'],'version':version,'status':'prepared','pushed':False}}
            state_file=state_dir/'current.json'
            def save():
                state_file.write_bytes(source._canonical(state));state_file.chmod(0o600)
            save()
            worker=types.SimpleNamespace(WORK=work,MAX_JSON=installed_worker.MAX_JSON,
                parse_json=installed_worker.parse_json)
            doc={'Directory':doctor.Directory,'BASE':baseline,'safe_state':lambda value:{'counter':value['counter']}}
            def location(commit,path):
                return ('remote: —— Google API Key ——————————————\nremote: - commit: '+commit+
                    '\nremote: path: '+path+':2\n').encode()
            rejection=(location(candidate,'app/known.php')+location(candidate,'app/new.php')+
                location(candidate,'credentials/seed.json')+location(baseline,'credentials/seed.json'))
            retry={'classify':lambda code,stdout,stderr:'PUSH_PROTECTION'}
            def retry_once(*args):
                return {'ok':False,'category':retry['classify'](1,b'',rejection),'credentials_disclosed':False}
            retry['retry_once']=retry_once
            valid=subject.run_wrapper(retry,doc,source,worker,object())
            details=valid['push_protection'];self.assertTrue(details['complete'])
            self.assertEqual([(row['origin'],row['change']) for row in details['locations']],
                [('CANDIDATE_COMMIT','MODIFIED_IN_CANDIDATE'),('CANDIDATE_COMMIT','ADDED_TO_CANDIDATE'),
                 ('CANDIDATE_COMMIT','INHERITED_SEED_BLOB'),('SEED_COMMIT','SEED_HISTORY')])
            self.assertNotIn('PRIVATE_',json.dumps(valid));self.assertNotIn(str(work),json.dumps(valid))
            state['desired']['commit']='f'*40;save()
            mismatch=subject.run_wrapper(retry,doc,source,worker,object())
            self.assertEqual(mismatch['push_protection'],{'complete':False,'reason':'UNKNOWN_PRIVATE'})
            self.assertNotIn('PRIVATE_',json.dumps(mismatch))
            state['desired']['commit']=candidate;save();doc['BASE']='e'*40
            wrong_parent=subject.run_wrapper(retry,doc,source,worker,object())
            self.assertEqual(wrong_parent['push_protection'],{'complete':False,'reason':'UNKNOWN_PRIVATE'})
            for name,data in current.items():self.assertEqual((checkout/name).read_bytes(),data)

if __name__=='__main__':unittest.main()
