"""The same-artifact adapter must retain the original locks and no-allocation guards."""
import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

from test_go_erp_navigation_actual import final_modules

ROOT = Path(__file__).resolve().parents[1]


def load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = load('navigation_zip_resume_subject', 'deployment/resume_go_erp_navigation_zip_root.py')


def job():
    return {'format':1, 'kind':'go-erp-warm-start-rollout', 'core':subject.CORE,
        'seed':subject.SEED, 'runtime':subject.RUNTIME, 'phase':'running',
        'prepare_started':True, 'candidate':subject.CANDIDATE, 'version':subject.VERSION,
        'inventory':subject.INVENTORY, 'retained_unknown':{'history':[1,2,3]}}


class Child:
    def __init__(self, stdout=b'GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED\n', stderr=b'', returncode=0):
        self.stdout, self.stderr, self.returncode = stdout, stderr, returncode
        self.killed = False
        self.communications = []

    def __enter__(self): return self
    def __exit__(self, *args): return False
    def communicate(self, **kwargs):
        self.communications.append(kwargs)
        return self.stdout, self.stderr
    def kill(self): self.killed = True


class ResumeBindingTests(unittest.TestCase):
    def test_original_operator_and_tick_bytes_and_fixed_existing_59_are_pinned(self):
        for path, expected in (
                ('deployment/upgrade_go_erp_navigation_root.py',subject.OPERATOR_SHA256),
                ('deployment/go_erp_navigation_tick_root.py',subject.TICK_SHA256)):
            self.assertEqual(hashlib.sha256((ROOT/path).read_bytes()).hexdigest(),expected)
        self.assertEqual(subject.ORIGINAL_RELEASE,'33e08c9fec93501d2376eb88d9fd402043f8d792')
        self.assertEqual(subject.VERSION,'0.4.4-preview.59')
        self.assertEqual(subject.FIXED_JOB.name,'navigation-'+subject.CORE+'.json')

    def test_wrapper_and_driver_pins_bind_actual_bytes_and_only_same_59_success(self):
        compat = ROOT/'deployment/go_erp_navigation_zip_compat_root.py'
        driver = ROOT/'deployment/resume_go_erp_navigation_zip_root.py'
        self.assertEqual(subject.COMPAT_SHA256,hashlib.sha256(compat.read_bytes()).hexdigest())
        wrapper = (ROOT/'deployment/run_go_erp_navigation_zip_resume_root.sh').read_text()
        pins = dict(re.findall(r'^go_fetch ([A-Za-z0-9_.]+) ([a-f0-9]{64})$',wrapper,re.M))
        self.assertEqual(set(pins),{compat.name,driver.name})
        for path in (compat,driver):
            self.assertEqual(pins[path.name],hashlib.sha256(path.read_bytes()).hexdigest())
        self.assertNotIn('PENDING',wrapper)
        self.assertNotIn('PENDING',driver.read_text())
        self.assertIn('umask 077',wrapper)
        self.assertIn('chmod 0600 "$go_stage/$go_name"',wrapper)
        self.assertIn('GO_ERP_NAVIGATION_ZIP_RESUME_PACKAGE_RETAINED',wrapper)
        self.assertIn('"$go_stage/resume_go_erp_navigation_zip_root.py"',wrapper)
        validator = wrapper.split("/usr/bin/python3 -I -B -c '\n",1)[1].split("\n' \"$go_stage/result.txt\"",1)[0]
        success = {'format':1,'kind':'go-erp-navigation-rollout','ok':True,'published':True,
            'prepared':True,'manual_path_paused':False,'database_changed':False,
            'credentials_disclosed':False,'automatic_updates_enabled':False,
            'version':subject.VERSION,'candidate_revision':subject.CANDIDATE,
            'archive':{'available':True,'current_version':subject.VERSION}}
        marker = 'GO_ERP_NAVIGATION_PUBLISHED_MANUAL_UPDATES_ONLY'
        def check(value,last=marker):
            raw = json.dumps(value)+'\n'+last+'\n'
            with patch.object(sys,'argv',['validator','in-memory-result']), \
                    patch('builtins.open',return_value=io.StringIO(raw)):
                exec(compile(validator,'<original-wrapper-success-guard>','exec'),{})
        check(success)
        for field,value in [('candidate_revision','a'*40),('version','0.4.4-preview.60'),
                ('published',False),('manual_path_paused',True),('format',True)]:
            with self.subTest(field=field), self.assertRaises(SystemExit) as stopped:
                check({**success,field:value})
            self.assertEqual(stopped.exception.code,78)
        with self.assertRaises(SystemExit): check(success,'GO_ERP_LIVE_UPDATE_WAITING_CI')

    @unittest.skipUnless(os.geteuid()==0,'Real root-owned private helper file required')
    def test_private_helper_reader_rejects_symlinks_hardlinks_shared_modes_and_shared_directory(self):
        with tempfile.TemporaryDirectory(prefix='navigation-zip-private-helper-') as folder:
            parent = Path(folder); parent.chmod(0o700)
            helper = parent/'helper.py'
            helper.write_bytes(b'PINNED_HELPER_BYTES\n'); helper.chmod(0o600)
            self.assertEqual(subject.regular_bytes(helper),b'PINNED_HELPER_BYTES\n')
            symlink = parent/'link.py'; symlink.symlink_to(helper)
            with self.assertRaisesRegex(subject.Stop,'HELPER_PATH_INVALID'):
                subject.regular_bytes(symlink)
            hardlink = parent/'hardlink.py'; os.link(helper,hardlink)
            with self.assertRaisesRegex(subject.Stop,'HELPER_FILE_UNSAFE'):
                subject.regular_bytes(helper)
            hardlink.unlink(); helper.chmod(0o644)
            with self.assertRaisesRegex(subject.Stop,'HELPER_FILE_UNSAFE'):
                subject.regular_bytes(helper)
            helper.chmod(0o600); parent.chmod(0o775)
            with self.assertRaisesRegex(subject.Stop,'REPOSITORY_DIRECTORY_UNSAFE'):
                subject.regular_bytes(helper)
            parent.chmod(0o700)
            self.assertEqual(subject.regular_bytes(helper),b'PINNED_HELPER_BYTES\n')

    def test_module_sha_mismatch_refuses_execution_before_any_payload_effect(self):
        raw = b"raise AssertionError('UNREVIEWED_HELPER_EXECUTED')\n"
        with self.assertRaisesRegex(subject.Stop,'HELPER_SHA256_MISMATCH'):
            subject.module(raw,'navigation_zip_unreviewed_fixture','0'*64)
        raw = b'validated_constant = 59\n'
        loaded = subject.module(raw,'navigation_zip_reviewed_fixture',hashlib.sha256(raw).hexdigest())
        self.assertEqual(loaded.validated_constant,59)

    def test_existing_job_binding_is_pure_and_wrong_or_fresh_jobs_are_refused(self):
        value = job(); before = copy.deepcopy(value)
        self.assertIs(subject.check_job(value),value)
        self.assertEqual(value,before)
        for field, changed in [('format',True),('kind','different'),('core','a'*40),
                ('seed','a'*40),('runtime','other'),('phase','preparing'),
                ('prepare_started',False),('candidate',None),('candidate','a'*40),
                ('version','0.4.4-preview.60'),('inventory','f'*64)]:
            value = job(); value[field] = changed
            with self.subTest(field=field,changed=changed), self.assertRaisesRegex(subject.Stop,'EXISTING_59_JOB_REQUIRED'):
                subject.check_job(value)
        for value in (None,{},[],False):
            with self.assertRaises(subject.Stop): subject.check_job(value)

    def test_job_json_duplicate_keys_nonfinite_values_and_unprotected_paths_are_not_accepted(self):
        self.assertEqual(subject.parse_job(json.dumps(job())),job())
        with self.assertRaisesRegex(subject.Stop,'JOB_DUPLICATE_KEY'):
            subject.parse_job('{"format":1,"format":1}')
        with self.assertRaisesRegex(subject.Stop,'JOB_NONFINITE'):
            subject.parse_job('{"format":NaN}')
        read = unittest.mock.Mock(return_value=(json.dumps(job()).encode(),'identity'))
        self.assertEqual(subject.read_job(types.SimpleNamespace(read_control=read)),job())
        read.assert_called_once_with(subject.FIXED_JOB,16*1024**2,0o600)

    def original_tick_fixture(self, folder, *, allocate=False, wrong_desired=False):
        tick = load('navigation_zip_tick_fixture','deployment/go_erp_navigation_tick_root.py')
        worker, _, _, _ = final_modules()
        calls = []
        cohort = tick.NEW_COHORT
        unit = sys.modules['navigation_actual_pin_utility'].service_unit(cohort['runtime'])
        self.assertEqual(hashlib.sha256(unit).hexdigest(),cohort['unit_sha256'])
        snapshot = {'metadata':{'format':1,'inventorySha256':subject.INVENTORY}}

        class Worker:
            def __init__(self, config, source, publisher):
                calls.append('constructor')
                self.config = config
                self.state = {'desired':{'commit':'a'*40 if wrong_desired else subject.CANDIDATE,
                    'inventorySha256':subject.INVENTORY,'candidatePolicy':cohort['policy']}}
            def scan(self): calls.append('original-scan'); return snapshot
            def tick(self):
                calls.append('original-tick')
                self.scan()
                if allocate: self.prepare(snapshot)
                return 'GO_ERP_LIVE_UPDATE_WAITING_CI'
            def prepare(self, value): calls.append('ORIGINAL-PREPARE-MUST-NOT-RUN')

        worker.Worker = Worker
        worker.WORK = Path(folder)
        worker.read_config = lambda: {'base_revision':cohort['seed'],'token':'UNUSED'}
        worker.load_module = lambda *args: types.SimpleNamespace()
        worker_main = worker.main
        def main(arguments):
            calls.append(('original-worker-main',arguments))
            return worker_main(arguments)
        worker.main = main
        controls = {tick.CONFIG:json.dumps({'base_revision':cohort['seed']}).encode(),tick.UNIT:unit}
        def read_control(path,maximum,mode):
            self.assertIn(path,controls)
            self.assertEqual(mode,0o600 if path == tick.CONFIG else 0o644)
            calls.append(('read-control',path))
            return controls[path],('fixture-identity',str(path))
        tick.read_control = read_control
        original_select = tick.select_cohort
        def select():
            calls.append('select-cohort')
            selected,verify = original_select()
            def verify_controls(): calls.append('verify-controls'); return verify()
            return selected,verify_controls
        def original_load(selected):
            self.assertEqual(selected,cohort); calls.append('original-load-runtime'); return worker
        guards, original_run = tick.install_guards, tick.run_original
        def install_guards(*args,**kwargs): calls.append('original-install-guards'); return guards(*args,**kwargs)
        def run_original(value): calls.append('original-run-original'); return original_run(value)
        tick.select_cohort = select
        tick.load_runtime = original_load
        tick.install_guards = install_guards
        tick.run_original = run_original
        compat = types.SimpleNamespace(install=lambda value:calls.append(('compat-install',value)))
        return tick, worker, compat, calls, original_load

    def run_original_tick(self, folder, **options):
        tick, worker, compat, calls, original_load = self.original_tick_fixture(folder,**options)
        output, errors = io.StringIO(),io.StringIO()
        with patch.object(tick.os,'geteuid',return_value=0), \
                patch.object(tick.socket,'gethostname',return_value='srv1836524'), \
                patch.object(tick.os,'umask'), contextlib.redirect_stdout(output), contextlib.redirect_stderr(errors):
            result = subject.run_tick(tick,compat,subject.INVENTORY,subject.CANDIDATE)
        self.assertIs(tick.load_runtime,original_load)
        return result,calls,output.getvalue()+errors.getvalue()

    def test_same_tick_retains_original_controls_guard_install_main_and_real_watcher_lock(self):
        with tempfile.TemporaryDirectory(prefix='navigation-zip-original-lock-') as folder:
            os.chmod(folder,0o700)
            result,calls,output = self.run_original_tick(folder)
            self.assertEqual(result,0)
            self.assertEqual(output,'GO_ERP_LIVE_UPDATE_WAITING_CI\n')
            for event in ('select-cohort','original-load-runtime','original-install-guards',
                    'original-run-original',('original-worker-main',['--once']),
                    'constructor','original-tick','original-scan'):
                self.assertIn(event,calls)
            self.assertGreaterEqual(calls.count('verify-controls'),2)
            self.assertEqual(sum(isinstance(c,tuple) and c[0]=='compat-install' for c in calls),1)
            self.assertEqual(stat_mode(Path(folder)/'watcher.lock'),0o600)

    def test_original_watcher_busy_lock_is_not_bypassed(self):
        import fcntl
        with tempfile.TemporaryDirectory(prefix='navigation-zip-held-lock-') as folder:
            os.chmod(folder,0o700)
            lock = os.open(Path(folder)/'watcher.lock',os.O_RDWR|os.O_CREAT,0o600)
            try:
                fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
                result,calls,output = self.run_original_tick(folder)
            finally: os.close(lock)
            self.assertEqual(result,0)
            self.assertEqual(output,'GO_ERP_LIVE_UPDATE_ALREADY_RUNNING\n')
            self.assertNotIn('constructor',calls)

    def test_original_allocation_and_candidate_guards_still_refuse_wrong_work(self):
        for options, code in (({'allocate':True},'MANUAL_ALLOCATION_NOT_AUTHORIZED'),
                ({'wrong_desired':True},'MANUAL_CANDIDATE_CHANGED')):
            with self.subTest(options=options), tempfile.TemporaryDirectory() as folder:
                result,calls,output = self.run_original_tick(folder,**options)
                self.assertEqual(result,1)
                self.assertIn(code,output)
                self.assertNotIn('ORIGINAL-PREPARE-MUST-NOT-RUN',calls)
        tick = types.SimpleNamespace(load_runtime=lambda value:self.fail('must not load'))
        for inventory,candidate in (('f'*64,subject.CANDIDATE),(subject.INVENTORY,'a'*40)):
            with self.assertRaisesRegex(subject.Stop,'TICK_BINDING_INVALID'):
                subject.run_tick(tick,types.SimpleNamespace(),inventory,candidate)

    def test_parent_worker_and_existing_job_use_the_adapter_without_reinitializing_the_journal(self):
        calls = []; value = job()
        read = unittest.mock.Mock(return_value=(json.dumps(value).encode(),None))
        class Previous:
            def __init__(self):
                calls.append('base-init'); self.worker = object(); self.e = types.SimpleNamespace(read_file=read)
                self.job_path = subject.FIXED_JOB; self.job = None
            def job_record(self): calls.append('base-job-record'); self.job = copy.deepcopy(value); return 'retained'
        template = types.SimpleNamespace(Context=Previous)
        operator = types.SimpleNamespace(configure=lambda original,*args,**kwargs:original)
        compat = types.SimpleNamespace(install=lambda worker:calls.append(('install',worker)))
        subject.configure_parent(operator,compat,Path('/compat'),subject.FIXED_REPOSITORY)
        configured = operator.configure(template)
        ctx = configured.Context()
        self.assertEqual(calls,['base-init',('install',ctx.worker)])
        self.assertEqual(ctx.job_record(),'retained')
        self.assertEqual(ctx.job,value)
        read.assert_called_once_with(subject.FIXED_JOB,16*1024**2,uid=0,modes=(0o600,))
        with patch.object(subject,'child_tick',return_value=(0,'GO_ERP_LIVE_UPDATE_PUBLISHED')) as child:
            self.assertEqual(ctx.tick(False,123),(0,'GO_ERP_LIVE_UPDATE_PUBLISHED'))
        child.assert_called_once_with(ctx,Path('/compat'),subject.FIXED_REPOSITORY,False,123)
        calls.clear(); read.return_value = None
        with self.assertRaisesRegex(subject.Stop,'EXISTING_59_JOB_REQUIRED'): ctx.job_record()
        self.assertNotIn('base-job-record',calls)
        read.return_value = (json.dumps({**value,'candidate':None}).encode(),None)
        with self.assertRaisesRegex(subject.Stop,'EXISTING_59_JOB_REQUIRED'): ctx.job_record()
        self.assertNotIn('base-job-record',calls)

    def test_child_keeps_deadline_original_release_and_bounded_code_only_output(self):
        ctx = types.SimpleNamespace(job=job()); child = Child()
        with patch.object(subject.subprocess,'Popen',return_value=child) as spawn:
            self.assertEqual(subject.child_tick(ctx,Path('/compat'),subject.FIXED_REPOSITORY,False,60),
                (0,'GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED'))
        args = spawn.call_args.args[0]
        self.assertEqual(args[:4],['/usr/bin/python3','-I','-B',str(Path(subject.__file__).resolve())])
        self.assertEqual(args[4:],['--tick','--git-dir',str(subject.FIXED_REPOSITORY),
            '--release',subject.ORIGINAL_RELEASE,'--compat-file','/compat',
            '--inventory',subject.INVENTORY,'--candidate',subject.CANDIDATE])
        self.assertNotIn('--allow-prepare',args)
        with patch.object(subject.subprocess,'Popen') as spawn:
            with self.assertRaisesRegex(subject.Stop,'NEW_ALLOCATION_PROHIBITED'):
                subject.child_tick(ctx,Path('/compat'),subject.FIXED_REPOSITORY,True)
            spawn.assert_not_called()
        for child in (Child(b'PRIVATE_TOKEN'),Child(b''),Child(b'GO_ERP_OK\n'*17),
                Child(b'X'*8193),Child(returncode=2)):
            with self.subTest(stdout=child.stdout[:40]), patch.object(subject.subprocess,'Popen',return_value=child):
                with self.assertRaisesRegex(subject.Stop,'TICK_OUTPUT_INVALID'):
                    subject.child_tick(ctx,Path('/compat'),subject.FIXED_REPOSITORY,False)
        child = Child()
        with patch.object(subject.subprocess,'Popen',return_value=child), \
                patch.object(subject.time,'monotonic',side_effect=[0,1]):
            with self.assertRaisesRegex(subject.Stop,'WORKER_TIMED_OUT_RETRY_SAME_COMMAND'):
                subject.child_tick(ctx,Path('/compat'),subject.FIXED_REPOSITORY,False,0)
        self.assertTrue(child.killed)

    def test_main_preflights_existing_job_before_loading_or_executing_the_original_operator(self):
        calls = []; compat = object(); tick = object()
        operator = types.SimpleNamespace(main=lambda:calls.append(('operator-main',list(subject.sys.argv))))
        def module(raw,name,expected):
            calls.append(('module',name,expected))
            return compat if name.endswith('_compat') else tick if name.endswith('_tick') else operator
        regular = lambda path:b'checked private helper'
        base_patches = [patch.object(subject.os,'geteuid',return_value=0),
            patch.object(subject.socket,'gethostname',return_value='srv1836524'),
            patch.object(subject.os,'umask'),patch.object(subject,'regular_bytes',side_effect=regular),
            patch.object(subject,'git_bytes',return_value=b'pinned bytes'),
            patch.object(subject,'module',side_effect=module),
            patch.object(subject,'configure_parent',side_effect=lambda *args:calls.append('configure-parent'))]
        with contextlib.ExitStack() as stack:
            for item in base_patches: stack.enter_context(item)
            with patch.object(subject,'read_job',side_effect=FileNotFoundError):
                with self.assertRaises(FileNotFoundError):
                    subject.main(['--compat-file','/compat','--complete'])
            self.assertFalse(any(isinstance(c,tuple) and c[0]=='operator-main' for c in calls))
            self.assertNotIn('configure-parent',calls)
            self.assertFalse(any(isinstance(c,tuple) and c[1]=='go_erp_navigation_zip_original_operator' for c in calls))
            calls.clear()
            before = subject.sys.argv
            with patch.object(subject,'read_job',return_value=job()):
                self.assertEqual(subject.main(['--compat-file','/compat','--complete']),0)
            self.assertIs(subject.sys.argv,before)
            self.assertIn(('operator-main',['pinned-navigation-operator','--git-dir',
                str(subject.FIXED_REPOSITORY),'--release',subject.ORIGINAL_RELEASE,'--complete']),calls)
            self.assertIn('configure-parent',calls)


def stat_mode(path):
    import stat
    return stat.S_IMODE(path.stat().st_mode)


if __name__ == '__main__':
    unittest.main()
