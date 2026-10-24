"""Real queue/job/status files and simulated original ticks exercise recovery."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('manual_broker_subject', ROOT / 'deployment/go_erp_manual_updates_root.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)

NONCE = 'a' * 32
C43, C44 = '3' * 40, '4' * 40
OLD, NEW = '2' * 64, '3' * 64


class BrokerTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-manual-broker-')
        self.addCleanup(temporary.cleanup)
        self.path = Path(temporary.name)
        self.path.chmod(0o755)
        self.base = self.path / 'control'
        self.base.mkdir(mode=0o755)
        for name, mode in [('private',0o700), ('private/jobs',0o700), ('requests',0o700), ('shared',0o750)]:
            (self.base / name).mkdir(mode=mode)
        self.pending = self.base / 'requests/pending.json'
        self.lock = self.base / 'requests/request.lock'
        self.lock.touch(mode=0o600)
        self.now = 1000000
        self.ticks = []
        self.native = self.path / 'native.json'
        self.feed = self.path / 'feed.json'
        self.state = {'format': 1, 'counter': 43,
            'desired': {'commit': C43, 'inventorySha256': OLD, 'version': '0.4.4-preview.43',
                'status': 'blocked', 'failures': 2, 'retry_at': self.now + 500},
            'published': {'commit': '2' * 40, 'inventorySha256': OLD,
                'release': {'source_revision': '2' * 40, 'version': '0.4.4-preview.42'}}}
        self.write(self.native, self.state)
        self.write(self.feed, self.state['published']['release'], 0o644)

    def write(self, path, value, mode=0o600):
        path.write_text(json.dumps(value))
        path.chmod(mode)

    def queue(self, nonce=NONCE, **values):
        value = {'format': 1, 'kind': 'go-erp-manual-release-request', 'nonce': nonce,
            'actor_id': 1, 'requested_at': self.now}
        value.update(values)
        self.write(self.pending, value)
        return value

    def snapshot(self):
        return subject.native_snapshot(self.native, self.feed)

    def advance(self, delay):
        self.now += delay

    def tick(self, job):
        self.ticks.append(copy.deepcopy(job))
        self.state = json.loads(self.native.read_bytes())
        if len(self.ticks) == 1:
            self.assertTrue(job['_allow_prepare'])
            self.state['counter'] = 44
            self.state['desired'] = {'commit': C44, 'inventorySha256': NEW,
                'version': '0.4.4-preview.44', 'status': 'waiting-ci', 'failures': 0, 'retry_at': 0}
            self.write(self.native, self.state)
            self.feed.unlink(missing_ok=True)
            return 0, 'GO_ERP_LIVE_UPDATE_WAITING_CI'
        self.assertFalse(job['_allow_prepare'])
        self.assertEqual((job['candidate'], job['inventory']), (C44, NEW))
        self.publish44()
        return 0, 'GO_ERP_LIVE_UPDATE_PUBLISHED'

    def publish44(self):
        self.state = json.loads(self.native.read_bytes())
        self.state['desired']['status'] = 'published'
        self.state['published'] = {'commit': C44, 'inventorySha256': NEW,
            'release': {'source_revision': C44, 'version': '0.4.4-preview.44'}}
        self.write(self.native, self.state)
        self.write(self.feed, self.state['published']['release'], 0o644)

    def broker(self, tick=None):
        return subject.Broker(self.base, web_uid=os.getuid(), web_gid=os.getgid(),
            clock=lambda:self.now, sleep=self.advance, tick=tick or self.tick, snapshot=self.snapshot,
            archive=lambda *_: None)

    def stored_status(self):
        return json.loads((self.base / 'shared/status.json').read_bytes())

    def test_queue_claim_latches_one_candidate_and_publishes_with_private_metadata(self):
        self.queue()
        broker = self.broker()
        broker.run()
        self.assertEqual(self.stored_status()['state'], 'ready')
        self.assertEqual([j['_allow_prepare'] for j in self.ticks], [True, False])
        self.assertEqual(json.loads(self.native.read_bytes())['counter'], 44)
        self.assertFalse(broker.active.exists())
        self.assertFalse(self.pending.exists())
        for path in (broker.jobs / (NONCE + '.json'), broker.private / 'last-request.json', broker.private / 'worker.lock'):
            info = path.stat()
            self.assertEqual((info.st_uid, stat.S_IMODE(info.st_mode)), (0,0o600))
        info = broker.status_path.stat()
        self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)), (0,os.getgid(),0o640))

    def test_successful_download_is_retained_before_scan_then_advanced_after_publication(self):
        self.queue();broker=self.broker();retained=[]
        def archive(base,gid):
            self.assertEqual((base,gid),(self.base,os.getgid()))
            retained.append(json.loads(self.native.read_bytes())['published']['release']['version'])
        broker.archive=archive
        broker.run()
        self.assertEqual(retained,['0.4.4-preview.42','0.4.4-preview.44'])
        self.assertEqual(self.stored_status()['state'],'ready')

    def test_failed_ci_does_not_advance_the_retained_download(self):
        self.queue();retained=[]
        def failed(job):
            self.ticks.append(job)
            return 1,'GO_ERP_LIVE_CI_FAILED'
        broker=self.broker(failed)
        broker.archive=lambda *_:retained.append('old42')
        broker.run()
        self.assertEqual(retained,['old42'])
        self.assertEqual(self.stored_status()['state'],'failed')
        self.assertEqual(self.stored_status()['code'],'GO_ERP_LIVE_CI_FAILED')

    def test_archive_failure_prevents_allocation_and_never_reports_ready(self):
        self.queue();broker=self.broker()
        def refused(*_):
            raise subject.Stop('GO_ERP_MANUAL_DOWNLOAD_ARCHIVE_FAILED')
        broker.archive=refused
        broker.run()
        self.assertEqual(self.ticks,[])
        self.assertEqual(self.stored_status()['state'],'failed')
        self.assertEqual(json.loads(self.native.read_bytes())['counter'],43)

    def test_completed_nonce_replay_is_deduped_without_tick_or_new_counter(self):
        self.queue();broker=self.broker();broker.run()
        count=len(self.ticks);before=self.native.read_bytes()
        self.queue();broker.run()
        self.assertEqual(len(self.ticks),count)
        self.assertEqual(self.native.read_bytes(),before)
        self.assertEqual(self.stored_status()['state'],'ready')

    def test_root_cooldown_rejects_new_nonce_without_entering_native_worker(self):
        self.queue();broker=self.broker();broker.run()
        before=self.native.read_bytes();count=len(self.ticks)
        self.queue('b'*32);broker.run()
        self.assertEqual(len(self.ticks),count)
        self.assertEqual(self.native.read_bytes(),before)
        self.assertEqual(self.stored_status()['code'],'GO_ERP_MANUAL_COOLDOWN')
        self.assertFalse(broker.active.exists())

    def test_invalid_schema_stale_time_or_link_never_becomes_root_job(self):
        broker=self.broker()
        for values in ({'actor_id':True},{'requested_at':self.now-901},{'requested_at':self.now+31},
                {'nonce':'../secret'},{'command':'systemctl start unsafe'}):
            self.queue(**values)
            with self.subTest(values=values),self.assertRaises(subject.Stop):broker.claim()
            self.assertFalse(broker.active.exists())
        outside=self.path/'outside';outside.write_text('PRIVATE_SENTINEL')
        self.pending.symlink_to(outside)
        with self.assertRaises(subject.Stop):broker.claim()
        self.assertEqual(outside.read_text(),'PRIVATE_SENTINEL')
        self.assertFalse(broker.active.exists())

    def test_wrong_request_mode_and_status_symlink_cannot_be_used(self):
        self.queue();self.pending.chmod(0o644)
        with self.assertRaises(subject.Stop):self.broker().claim()
        self.queue()
        outside=self.path/'outside';outside.write_text('PRIVATE_SENTINEL')
        (self.base/'shared/status.json').symlink_to(outside)
        with self.assertRaises(subject.Stop):self.broker().claim()
        self.assertEqual(outside.read_text(),'PRIVATE_SENTINEL')

    def test_latched_crash_resume_never_gets_another_allocation_permit(self):
        self.queue();broker=self.broker();job=broker.claim()
        self.state['counter']=44
        self.state['desired'].update(commit=C44,inventorySha256=NEW,version='0.4.4-preview.44',status='waiting-ci')
        self.write(self.native,self.state)
        job.update(candidate=C44,inventory=NEW,version='0.4.4-preview.44',prepare_started=True,baseline_candidate=C43)
        broker.status(job,'waiting-ci')
        calls=[]
        def resumed(value):
            calls.append(copy.deepcopy(value));self.publish44()
            return 0,'GO_ERP_LIVE_UPDATE_PUBLISHED'
        self.broker(resumed).run()
        self.assertEqual(len(calls),1)
        self.assertFalse(calls[0]['_allow_prepare'])
        self.assertEqual(calls[0]['candidate'],C44)
        self.assertEqual(self.stored_status()['state'],'ready')

    def test_crash_after_allocation_before_latch_recovers44_without_permit(self):
        self.queue();broker=self.broker();job=broker.claim()
        job.update(prepare_started=True,baseline_candidate=C43)
        broker.save(job)
        self.state['counter']=44
        self.state['desired'].update(commit=C44,inventorySha256=NEW,version='0.4.4-preview.44',status='retry-build')
        self.write(self.native,self.state)
        calls=[]
        def resumed(value):
            calls.append(copy.deepcopy(value))
            return 1,'GO_ERP_LIVE_CI_FAILED'
        self.broker(resumed).run()
        self.assertEqual(len(calls),1)
        self.assertFalse(calls[0]['_allow_prepare'])
        self.assertEqual(calls[0]['candidate'],C44)
        self.assertEqual(json.loads(self.native.read_bytes())['counter'],44)
        self.assertEqual(self.stored_status()['code'],'GO_ERP_LIVE_CI_FAILED')

    def test_crash_after_permit_before_allocation_fails_for_review_without_child(self):
        self.queue();broker=self.broker();job=broker.claim()
        job.update(prepare_started=True,baseline_candidate=C43)
        broker.save(job)
        self.broker(lambda value:self.fail('Allocation permission must not be reissued')).run()
        self.assertEqual(self.stored_status()['code'],'GO_ERP_MANUAL_ALLOCATION_REVIEW_REQUIRED')
        self.assertEqual(json.loads(self.native.read_bytes())['counter'],43)

    def test_native_lock_busy_does_not_latch_cancelled43_or_fail_the_job(self):
        self.queue();calls=[]
        def busy_then_build(job):
            if not calls:
                calls.append(copy.deepcopy(job))
                return 0,'GO_ERP_LIVE_UPDATE_ALREADY_RUNNING'
            return self.tick(job)
        self.broker(busy_then_build).run()
        self.assertTrue(calls[0]['_allow_prepare'])
        self.assertEqual([j['_allow_prepare'] for j in self.ticks],[True,False])
        self.assertEqual(self.stored_status()['state'],'ready')

    def test_ci_failure_source_change_and_blocked_state_never_continue_allocating(self):
        for code,status in [('GO_ERP_LIVE_CI_FAILED','retry-build'),
                ('GO_ERP_LIVE_MANUAL_SOURCE_CHANGED','waiting-ci'),
                ('GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED','blocked')]:
            with self.subTest(code=code):
                nonce=hashlib_nonce(code)
                self.now+=301;self.queue(nonce)
                calls=[]
                def failed(job):
                    calls.append(copy.deepcopy(job))
                    self.state=json.loads(self.native.read_bytes())
                    self.state['desired']['status']=status
                    self.write(self.native,self.state)
                    return (0 if status=='blocked' else 1),code
                self.broker(failed).run()
                self.assertEqual(len(calls),1)
                self.assertEqual(self.stored_status()['state'],'failed')
                self.assertEqual(json.loads(self.native.read_bytes())['counter'],43)

    def test_deadline_and_corrupt_private_ledger_do_not_launch_worker(self):
        self.queue();broker=self.broker();job=broker.claim()
        self.now=job['deadline'];broker.process(job)
        self.assertEqual(self.stored_status()['code'],'GO_ERP_MANUAL_JOB_TIMED_OUT')
        self.assertEqual(self.ticks,[])
        self.now+=301;self.queue('c'*32)
        self.write(broker.private/'last-request.json',{'accepted_at':True})
        with self.assertRaises(subject.Stop):broker.claim()
        self.assertFalse(broker.active.exists())

    def test_original_acceptance_gate_codes_stop_without_repeated_artifact_collection(self):
        for code in ('GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED','GO_ERP_PROGRAM_PUBLICATION_REQUIRES_REVIEW'):
            with self.subTest(code=code):
                self.now+=301;self.queue(hashlib_nonce(code));calls=[]
                def held(job):
                    calls.append(copy.deepcopy(job))
                    self.assertEqual(len(calls),1,'Acceptance must not be collected repeatedly')
                    self.state=json.loads(self.native.read_bytes())
                    self.state['counter']=44
                    self.state['desired'].update(commit=C44,inventorySha256=NEW,
                        version='0.4.4-preview.44',status='waiting-ci')
                    self.write(self.native,self.state)
                    return 0,code
                self.broker(held).run()
                self.assertEqual(len(calls),1)
                self.assertEqual(self.stored_status()['code'],'GO_ERP_MANUAL_ACCEPTANCE_REVIEW_REQUIRED')

    def test_exact_generic_retry_keeps_candidate_and_native_backoff_until_ready(self):
        self.queue();calls=[];native_retry=[]
        def transient_then_ready(job):
            calls.append(copy.deepcopy(job))
            if len(calls)==1:
                self.state=json.loads(self.native.read_bytes());self.state['counter']=44
                self.state['desired']={'commit':C44,'inventorySha256':NEW,'version':'0.4.4-preview.44',
                    'status':'waiting-ci','failures':1,'retry_at':self.now+90}
                self.write(self.native,self.state);self.feed.unlink(missing_ok=True)
                return 1,'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
            desired=json.loads(self.native.read_bytes())['desired']
            native_retry.append((desired['failures'],desired['retry_at']))
            if self.now<desired['retry_at']:return 0,'GO_ERP_LIVE_UPDATE_RETRY_DEFERRED'
            self.publish44();return 0,'GO_ERP_LIVE_UPDATE_PUBLISHED'
        self.broker(transient_then_ready).run()
        self.assertEqual([value['_allow_prepare'] for value in calls],[True,False,False,False])
        self.assertEqual(len(set(native_retry)),1)
        self.assertEqual(native_retry[0],(1,1000090))
        job=json.loads((self.base/'private/jobs'/ (NONCE+'.json')).read_bytes())
        self.assertEqual(job['transient_failures'],1)
        self.assertEqual((job['candidate'],job['inventory']),(C44,NEW))
        self.assertEqual(self.stored_status()['state'],'ready')

    def test_fourth_generic_failure_stops_and_never_receives_second_allocation_permit(self):
        self.queue();calls=[]
        def repeatedly_generic(job):
            calls.append(copy.deepcopy(job))
            self.state=json.loads(self.native.read_bytes());self.state['counter']=44
            self.state['desired']={'commit':C44,'inventorySha256':NEW,'version':'0.4.4-preview.44',
                'status':'waiting-ci','failures':len(calls),'retry_at':self.now+120}
            self.write(self.native,self.state)
            return 1,'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
        self.broker(repeatedly_generic).run()
        self.assertEqual([j['_allow_prepare'] for j in calls],[True,False,False,False])
        job=json.loads((self.base/'private/jobs'/(NONCE+'.json')).read_bytes())
        self.assertEqual(job['transient_failures'],3)
        self.assertEqual(self.stored_status()['code'],'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT')
        self.assertEqual(json.loads(self.native.read_bytes())['counter'],44)

    def test_generic_retry_budget_survives_broker_restart(self):
        self.queue();broker=self.broker();job=broker.claim()
        job.update(candidate=C44,inventory=NEW,version='0.4.4-preview.44',prepare_started=True,
            baseline_candidate=C43,transient_failures=2)
        broker.status(job,'waiting-ci')
        self.state['counter']=44
        self.state['desired'].update(commit=C44,inventorySha256=NEW,version='0.4.4-preview.44',status='prepared')
        self.write(self.native,self.state)
        calls=[]
        def generic(value):
            calls.append(copy.deepcopy(value));return 1,'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
        self.broker(generic).run()
        self.assertEqual(len(calls),2)
        self.assertTrue(all(not j['_allow_prepare'] for j in calls))
        saved=json.loads((self.base/'private/jobs'/(NONCE+'.json')).read_bytes())
        self.assertEqual(saved['transient_failures'],3)
        self.assertEqual(self.stored_status()['state'],'failed')
        self.assertEqual(json.loads(self.native.read_bytes())['counter'],44)

    def test_generic_first_tick_on_cancelled43_is_terminal(self):
        self.queue();calls=[]
        def generic(job):
            calls.append(copy.deepcopy(job));return 1,'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
        self.broker(generic).run()
        self.assertEqual(len(calls),1)
        job=json.loads((self.base/'private/jobs'/(NONCE+'.json')).read_bytes())
        self.assertEqual(job['transient_failures'],0)
        self.assertEqual(self.stored_status()['state'],'failed')
        self.assertEqual(json.loads(self.native.read_bytes())['counter'],43)

    def test_private_retry_budget_requires_bounded_integer(self):
        self.queue();broker=self.broker();job=broker.claim()
        for invalid in (True,-1,4,1.5):
            value={**job,'transient_failures':invalid}
            subject.atomic(broker.active,value)
            with self.subTest(value=invalid),self.assertRaises(subject.Stop):broker.existing_job(broker.active)


def hashlib_nonce(value):
    import hashlib
    return hashlib.sha256(value.encode()).hexdigest()[:32]


if __name__=='__main__':unittest.main()
