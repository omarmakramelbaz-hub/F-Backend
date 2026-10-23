"""Execute the actual Preview38 Bash lifecycle against inert Git/services only."""
import importlib.util
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('fixture_seed_prior_shell_cases', ROOT / 'tests/test_go_erp_mode_inventory_reconciliation.py')
F = importlib.util.module_from_spec(spec); spec.loader.exec_module(F)

GIT = F.GIT.replace(':deployment/upgrade_go_erp_mode_inventory_root.py', ':deployment/upgrade_go_erp_fixture_seed_root.py')
GIT = GIT.replace('args=sys.argv[1:]\n', 'args=sys.argv[1:]\nimport json\nwith open(os.environ["FIXTURE_TEST_ORDER"],"a") as output:output.write(json.dumps({"kind":"git","args":args})+"\\n")\n')

ADAPTER = r'''import json,os,sys
from pathlib import Path
args=sys.argv[1:];mode=args[args.index('--mode')+1]
with open(os.environ['FIXTURE_TEST_ORDER'],'a') as output:output.write(json.dumps({'kind':'entry','mode':mode,'args':args})+'\n')
scenario=json.loads(Path(os.environ['MODE_TEST_SCENARIO']).read_text())
counts_path=Path(os.environ['FIXTURE_TEST_COUNTS'])
counts=json.loads(counts_path.read_text()) if counts_path.exists() else {}
counts[mode]=counts.get(mode,0)+1;counts_path.write_text(json.dumps(counts))
actions={'lease':'proof_objects' if counts[mode]>1 or scenario.get('prepare_ready') else 'prepare',
         'prepare':'authorize' if scenario.get('prepare_ready') else 'candidate',
         'status':'collect' if counts[mode]<=scenario.get('collect_rounds',0) else 'authorize',
         'authorize':'publish','verify':'none'}
action=actions[mode];published=mode=='verify'
if scenario.get('already'):action='none';published=True
value={'ok':True,'kind':'go-erp-fixture-seed-upgrade','worker_request':action,'published':published}
if scenario.get('partial') and mode=='lease' and counts[mode]==1:value['worker_request']='prepare'
if mode=='lease' and action in ('proof_objects','none'):value['candidate_revision']='8'*40
value.update(scenario.get('changes',{}).get(mode,{}))
print(scenario['raw'] if scenario.get('raw_mode')==mode else json.dumps(value))
if mode=='authorize':assert args[-2:]==['--receipt',str(Path(os.environ['MODE_TEST_WORK'])/'pending-program-acceptance.json')]
raise SystemExit(scenario.get('exit_codes',{}).get(mode,0))
'''


@unittest.skipUnless(os.geteuid() == 0, 'the real shell explicitly requires root')
class FixtureSeedWrapperTests(unittest.TestCase):
    def invoke(self, scenario=None, timer='inactive'):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-fixture-wrapper-')
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name); work = root / 'work'; bins = root / 'bin'
        work.mkdir(mode=0o700); bins.mkdir(mode=0o700)
        for name, code in [('git', GIT), ('systemctl', F.SYSTEMCTL),
                           ('hostname', '#!/bin/bash\nprintf "%s\\n" srv1836524\n'),
                           ('sleep', '#!/bin/bash\nexit 0\n')]:
            path = bins / name; path.write_text(code); path.chmod(0o700)
        state = root / 'state.json'; state.write_text(json.dumps({'timer': timer, 'started': 100, 'starts': 0, 'polls': 0}))
        settings = root / 'scenario.json'; settings.write_text(json.dumps(scenario or {}))
        adapter = root / 'adapter.py'; adapter.write_text(ADAPTER)
        order, events = root / 'order.jsonl', root / 'events.jsonl'
        wrapper = ROOT / 'deployment/run_go_erp_fixture_seed_root.sh'
        code = wrapper.read_text(); fixed = 'go_work=/var/lib/go-erp-live-updates'
        self.assertEqual(code.count(fixed), 1)
        for unsafe in ('/usr/bin/systemctl', '/bin/systemctl', '/usr/bin/git', '/bin/git'): self.assertNotIn(unsafe, code)
        script = root / 'run.sh'; script.write_text(code.replace(fixed, 'go_work=' + shlex.quote(str(work))))
        env = {'PATH': str(bins) + ':/usr/bin:/bin', 'LANG': 'C.UTF-8', 'MODE_TEST_ADAPTER': str(adapter),
            'MODE_TEST_STATE': str(state), 'MODE_TEST_SCENARIO': str(settings), 'MODE_TEST_EVENTS': str(events),
            'MODE_TEST_WORK': str(work), 'FIXTURE_TEST_COUNTS': str(root / 'counts.json'), 'FIXTURE_TEST_ORDER': str(order)}
        result = subprocess.run(['/usr/bin/bash', str(script), 'd' * 40, '--complete'], env=env,
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=35)
        calls = [json.loads(row) for row in events.read_text().splitlines()] if events.exists() else []
        ordered = [json.loads(row) for row in order.read_text().splitlines()] if order.exists() else []
        self.assertFalse(any(call[:2] == ['stop', 'go-erp-live-updates.service'] for call in calls))
        return result, json.loads(state.read_text()), calls, ordered, list(work.glob('fixture-seed-download.*'))

    @staticmethod
    def modes(order): return [row['mode'] for row in order if row['kind'] == 'entry']

    def assert_paused(self, outcome, status=None):
        result, state, calls, order, stages = outcome
        if status is None: self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        else: self.assertEqual(result.returncode, status, result.stdout + result.stderr)
        self.assertEqual(state['timer'], 'inactive')
        self.assertNotIn(['start', 'go-erp-live-updates.timer'], calls)
        self.assertEqual(len(stages), 1)
        self.assertIn('GO_ERP_AUTOMATIC_UPDATES_NOT_CONFIRMED_RETRY_SAME_COMMAND', result.stderr)

    def test_candidate_runs_once_then_exact_lease_objects_authorization_publish_verify_and_timer(self):
        result, state, calls, order, stages = self.invoke()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.modes(order), ['lease', 'prepare', 'lease', 'status', 'authorize', 'verify'])
        self.assertEqual(state['starts'], 2); self.assertEqual(state['timer'], 'active'); self.assertEqual(stages, [])
        fetches = [row['args'] for row in order if row['kind'] == 'git' and 'fetch' in row['args']]
        fixed = next(args for args in fetches if '5f2b0fecafec1c3803499284b0abcca4ecfed7e4' in args)
        self.assertIn('cd35ee36d77bc35593cac3627a33307b71309325', fixed)
        self.assertIn('4e0c33197b6d9cd635e3396e1edbf567bfb3a020', fixed)
        self.assertIn('e64e69667198b7dceddd4dfe516d963f43bbae27', fixed)
        self.assertTrue(any('8' * 40 in args for args in fetches))
        self.assertIn('GO_ERP_PREVIEW38_PUBLISHED_AUTOMATIC_UPDATES_ACTIVE', result.stdout)

    def test_ready_candidate_hydrates_lease_before_prepare_and_never_runs_candidate_worker(self):
        result, state, calls, order, stages = self.invoke({'prepare_ready': True})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(state['starts'], 1)
        lease = next(index for index, row in enumerate(order) if row.get('mode') == 'lease')
        fetched = next(index for index, row in enumerate(order) if row['kind'] == 'git' and 'fetch' in row['args'] and '8' * 40 in row['args'])
        prepare = next(index for index, row in enumerate(order) if row.get('mode') == 'prepare')
        self.assertLess(lease, fetched); self.assertLess(fetched, prepare)
        self.assertEqual(state['timer'], 'active'); self.assertEqual(stages, [])

    def test_pending_unit_or_config_first_routes_prepare_without_candidate_object_hydration(self):
        for slot in ('unit', 'config'):
            with self.subTest(slot=slot):
                result, state, calls, order, stages = self.invoke({'partial': slot})
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                prepare = next(index for index, row in enumerate(order) if row.get('mode') == 'prepare')
                before_prepare = order[:prepare]
                self.assertEqual(self.modes(before_prepare), ['lease'])
                self.assertFalse(any(row['kind'] == 'git' and 'fetch' in row['args'] and '8' * 40 in row['args']
                                     for row in before_prepare))
                self.assertEqual(self.modes(order).count('prepare'), 1)
                self.assertEqual(state['starts'], 2)
                self.assertEqual(state['timer'], 'active'); self.assertEqual(stages, [])

    def test_completed_retry_hydrates_candidate_and_resumes_without_any_worker(self):
        result, state, calls, order, stages = self.invoke({'already': True})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.modes(order), ['lease', 'prepare', 'verify'])
        self.assertEqual(state['starts'], 0); self.assertEqual(state['timer'], 'active'); self.assertEqual(stages, [])

    def test_collect_rounds_reuse_same_candidate_and_wait_for_each_new_worker_invocation(self):
        result, state, calls, order, stages = self.invoke({'collect_rounds': 2, 'stale_start': True})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(state['starts'], 4)
        self.assertEqual(self.modes(order).count('prepare'), 1)
        self.assertEqual(self.modes(order).count('authorize'), 1)
        self.assertEqual(self.modes(order).count('status'), 3)
        self.assertIn('GO_ERP_WAITING_WORKER phase=candidate', result.stdout)
        self.assertIn('GO_ERP_WAITING_WORKER phase=collect', result.stdout)
        self.assertEqual(state['timer'], 'active'); self.assertEqual(stages, [])

    def test_failed_candidate_or_ci_and_failed_final_verification_leave_timer_paused(self):
        for scenario in ({'fail_collect': True},
            {'changes': {'status': {'ok': False, 'reason': 'FIXED_CI_REVIEW_REQUIRED'}}, 'exit_codes': {'status': 1}},
            {'changes': {'verify': {'published': False, 'worker_request': 'candidate'}}},
            {'raw_mode': 'lease', 'raw': '{invalid-json'}):
            with self.subTest(scenario=scenario): self.assert_paused(self.invoke(scenario))

    def test_worker_timeout_leaves_running_worker_untouched_and_does_not_authorize(self):
        outcome = self.invoke({'stale_start': True, 'timeout': True})
        self.assert_paused(outcome, 75)
        self.assertNotIn('authorize', self.modes(outcome[3]))
        self.assertEqual(outcome[1]['starts'], 1)

    def test_ci_polling_is_bounded_and_never_reallocates_candidate_or_resumes_timer(self):
        outcome = self.invoke({'collect_rounds': 100})
        self.assert_paused(outcome, 75)
        self.assertEqual(self.modes(outcome[3]).count('status'), 41)
        self.assertEqual(self.modes(outcome[3]).count('prepare'), 1)
        self.assertEqual(outcome[1]['starts'], 41)
        self.assertNotIn('authorize', self.modes(outcome[3]))

    def test_missing_export_fails_before_any_daemon_or_state_operation(self):
        result, state, calls, order, stages = self.invoke({'fail_export': True}, 'active')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(state['timer'], 'active'); self.assertEqual(state['starts'], 0)
        self.assertEqual(calls, []); self.assertEqual(self.modes(order), [])
        self.assertEqual(len(stages), 1)


if __name__ == '__main__': unittest.main()
