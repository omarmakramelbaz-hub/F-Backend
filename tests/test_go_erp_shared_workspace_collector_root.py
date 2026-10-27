"""Original-collector boundary fixtures; no allocation, build, server or publication."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
def module(name, path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path); value=importlib.util.module_from_spec(spec); spec.loader.exec_module(value); return value
io=module('collector_io','deployment/go_erp_shared_workspace_source_root.py')
subject=module('collector_subject','deployment/go_erp_shared_workspace_collector_root.py')

class Crash(BaseException): pass
class Journal:
    def __init__(self): self.value=None
    def load(self): return copy.deepcopy(self.value)
    def commit(self,old,value):
        assert old==(None if self.value is None else io.sha(io.canonical(self.value)))
        self.value=copy.deepcopy(value); return io.sha(io.canonical(value))

class Context:
    def __init__(self,pins,publication):
        self.pins=pins;self.feed=publication;self.calls=[];self.crash=None
        self.approved_worker_sha256=pins['worker_sha256'];self.approved_context_sha256=pins['context_sha256']
        old=pins['predecessor']
        self.value={'counter':old['counter'],'last_remote_sha':old['candidate'],'published_history':[publication],
            'published':{'release':publication},'desired':{'status':'published','commit':old['candidate'],
                'version':old['version'],'inventorySha256':old['inventory_sha256'],'pushed':True}}
        self.job={'core':pins['core'],'seed':pins['seed'],'inventory':pins['inventory_sha256'],
            'candidate':None,'version':None,'prepare_started':False}
    def state(self): return copy.deepcopy(self.value)
    def publication(self): return copy.deepcopy(self.feed)
    def previous_proof(self): self.calls.append('previous_proof');return b'prior',b'completed',b'accepted original receipt'
    def save(self): self.calls.append('save')
    def live(self,state): self.calls.append('live')
    def candidate(self,state): self.calls.append('candidate')
    def tick(self,allow):
        self.calls.append(('tick',allow))
        if self.crash=='before': self.crash=None;raise Crash()
        if allow:
            old=self.pins['predecessor'];self.value.update(counter=old['counter']+1,last_remote_sha='c'*40)
            self.value['desired']={'status':'waiting-ci','commit':'c'*40,'version':'0.4.4-preview.'+str(old['counter']+1),
                'inventorySha256':self.pins['inventory_sha256'],'candidatePolicy':subject.POLICY,'pushed':True}
        if self.crash=='after': self.crash=None;raise Crash()
        return 0,'GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED'
    def authorized(self): self.calls.append('original_authorized')

class CollectorTests(unittest.TestCase):
    def setUp(self):
        publication={'version':'0.4.4-preview.59','source_revision':'a'*40,'sequence':12}
        previous={'counter':59,'candidate':'a'*40,'version':publication['version'],'inventory_sha256':'1'*64,
            'release_sha256':io.sha(io.canonical(publication)),
            'acceptance_receipt_sha256':io.sha(b'accepted original receipt')}
        self.pins={'core':'d'*40,'seed':'e'*40,'policy':subject.POLICY,'inventory_sha256':'2'*64,
            'predecessor':previous,'worker_sha256':'3'*64,'context_sha256':'4'*64,'workspace_contract_sha256':'5'*64}
        self.ctx=Context(self.pins,publication);self.journal=Journal();self.proof=True
    def acceptance(self,candidate,version,contract):
        return {'candidate_revision':candidate,'version':version,'contract_sha256':contract,
            **{name:self.proof for name in ('latest_required_ci_accepted','full_native_lifecycle_accepted',
                'installed_asar_accepted','original_program_gate_accepted','employee_acceptance_accepted')}}
    def collector(self,pins=True):
        return subject.OriginalCollector(io,self.ctx,self.journal,lambda:None,self.acceptance,
            pins=self.pins if pins else None)
    def test_one_original_prepare_then_same_candidate_resume_and_original_authorization(self):
        job=self.collector().prepare_once();self.assertEqual(job['candidate'],'c'*40)
        self.assertEqual(self.ctx.job['candidate'],'c'*40)
        self.collector().prepare_once();self.collector().resume();self.collector().authorize()
        self.assertEqual(self.ctx.calls.count(('tick',True)),1)
        self.assertEqual(self.ctx.calls.count(('tick',False)),1)
        self.assertEqual(self.ctx.calls.count('original_authorized'),1)
    def test_existing_reserved_performance60_is_never_replaced_or_allocated_again(self):
        self.ctx.value.update(counter=60,last_remote_sha='f'*40)
        self.ctx.value['desired'].update(status='blocked',commit='f'*40,version='0.4.4-preview.60')
        before=copy.deepcopy(self.ctx.value)
        with self.assertRaisesRegex(subject.Stop,'EXISTING_RESERVATION_RESUME_REQUIRED'):self.collector().prepare_once()
        self.assertEqual(self.ctx.value,before);self.assertIsNone(self.journal.load());self.assertEqual(self.ctx.calls,[])
    def test_crash_after_consumed_allocation_latches_same_child_candidate_without_new_prepare(self):
        self.ctx.crash='after'
        with self.assertRaises(Crash):self.collector().prepare_once()
        self.assertIsNone(self.journal.load()['candidate'])
        self.assertEqual(self.collector().prepare_once()['candidate'],'c'*40)
        self.assertEqual(self.ctx.calls.count(('tick',True)),1)
    def test_proved_unconsumed_allocation_resumes_original_checkpoint_with_bounded_attempt(self):
        self.ctx.crash='before'
        with self.assertRaises(Crash):self.collector().prepare_once()
        self.assertEqual(self.ctx.value['counter'],59)
        job=self.collector().prepare_once();self.assertEqual(job['attempts'],2);self.assertEqual(self.ctx.value['counter'],60)
    def test_failed_ci_does_not_reset_retry_or_failure_evidence(self):
        self.collector().prepare_once();self.ctx.value['desired'].update(status='blocked',retry_at=999,failures=3)
        before=copy.deepcopy(self.ctx.value)
        with self.assertRaisesRegex(subject.Stop,'ORIGINAL_CI_REVIEW_REQUIRED'):self.collector().resume()
        self.assertEqual(self.ctx.value,before);self.assertEqual(self.ctx.calls.count(('tick',False)),0)
    def test_workspace_gate_cannot_be_replaced_by_original_program_success(self):
        self.collector().prepare_once();self.proof=False
        with self.assertRaisesRegex(subject.Stop,'WORKSPACE_ACCEPTANCE_REQUIRED'):self.collector().authorize()
        self.assertNotIn('original_authorized',self.ctx.calls)
    def test_null_pins_block_before_any_original_function(self):
        with self.assertRaisesRegex(subject.Stop,'FINAL_PINS_UNAVAILABLE'):self.collector(False).prepare_once()
        self.assertEqual(self.ctx.calls,[]);self.assertIsNone(self.journal.load())

if __name__=='__main__':unittest.main()
