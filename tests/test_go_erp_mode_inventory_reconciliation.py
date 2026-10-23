"""Real hold inode exchange with source, modes, state and old proofs immutable."""
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import types
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


@unittest.skipUnless(os.geteuid() == 0, 'actual private root-owned hold exchanges')
class ModeInventoryReconciliationTests(unittest.TestCase):
    def setUp(self):
        self.m = module('mode_reconciliation_fixture', ROOT / 'deployment/upgrade_go_erp_mode_inventory_root.py')
        self.g = module('mode_reconciliation_gate', ROOT / 'deployment/go_erp_program_rollout_gate.py')
        self.u = module('mode_reconciliation_utility', ROOT / 'deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py')
        self.p = module('mode_reconciliation_prior', ROOT / 'deployment/upgrade_go_erp_program_watcher_root.py')
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-mode-reconciliation-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.work, self.live = self.root / 'work', self.root / 'live'
        self.work.mkdir(mode=0o700); self.live.mkdir(mode=0o755)
        self.m.WORK, self.m.ROOT = self.work, self.live
        self.old_audit = self.work / self.m.PREPARED_AUDIT
        self.old_audit.mkdir(mode=0o700)
        self.old_names = ('intent.json', 'completed.json', 'application/application-intent.json',
                          'application/application-committed.json')
        for name in self.old_names:
            path = self.old_audit / name
            path.parent.mkdir(mode=0o700, exist_ok=True)
            path.write_bytes(self.m.canonical({'fixture': 'immutable old source proof', 'name': name}))
            path.chmod(0o600)
        for index, name in enumerate([*self.m.APPROVED_PINS, 'public/dashboard/js/dashboard-navigation.js']):
            path = self.live / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(('/* immutable fixture code %s */\n' % index).encode())
            path.chmod(0o755 if name in self.m.MODE_CORRECTIONS else 0o644)
        self.state = {'counter': 37, 'desired': {'commit': self.m.CANDIDATE, 'version': self.m.VERSION,
            'inventorySha256': self.m.TARGET_INVENTORY, 'pushed': True, 'status': 'waiting-ci'},
            'last_remote_sha': self.m.CANDIDATE, 'published': None}
        self.control_files = {}
        for name, data in [('config', b'{"private_fixture_token":"not-public"}\n'),
                           ('unit', b'[Service]\nExecStart=immutable fixture\n'),
                           ('state', self.m.canonical(self.state))]:
            path = self.work / (name + '.control')
            path.write_bytes(data); path.chmod(0o600)
            self.control_files[name] = path
        self.prepared = self.g.program_gate_expected(self.m.CORE, self.m.SOURCE, self.m.PREPARED_INVENTORY)
        self.expected = self.g.program_gate_expected(self.m.CORE, self.m.SOURCE, self.m.TARGET_INVENTORY)
        self.hold_path = self.work / 'program-rollout-hold.json'
        self.g.program_gate_write(self.hold_path, self.m.hold(self.prepared))
        self.old_hold = self.hold_path.read_bytes()
        self.old_hold_inode = self.hold_path.stat().st_ino
        self.publication = None
        self.ctx = types.SimpleNamespace(gate=self.g, utility=self.u,
            prior=types.SimpleNamespace(assert_quiescent=lambda: None, held_lock=self.p.held_lock),
            prepared_expected=self.prepared, expected=self.expected,
            publication=lambda: self.publication, observe=self.observe, controls=self.controls)
        self.lock_fd = os.open(self.work / 'watcher.lock', os.O_RDWR | os.O_CREAT | os.O_EXCL, 0o600)
        self.addCleanup(os.close, self.lock_fd)
        self.original = self.protected_snapshot()

    def protected_snapshot(self):
        paths = [*self.live.rglob('*'), *self.old_audit.rglob('*'), *self.control_files.values()]
        return {str(path): (path.read_bytes(), self.u.exact_key(path.stat()))
                for path in paths if path.is_file()}

    def controls(self):
        controls = {name: self.m.canonical({'sha256': self.m.sha(path.read_bytes()),
                                          'identity': self.u.exact_key(path.stat())})
                    for name, path in self.control_files.items()}
        controls['payment'] = self.m.canonical({str(path.relative_to(self.live)):
            {'sha256': self.m.sha(path.read_bytes()), 'identity': self.u.exact_key(path.stat())}
            for path in self.live.rglob('*') if path.is_file()})
        return controls, json.loads(self.control_files['state'].read_bytes())

    def evidence(self):
        return {name: {'sha256': self.m.sha((self.old_audit / name).read_bytes()),
                       'identity': list(self.u.exact_key((self.old_audit / name).stat()))}
                for name in self.old_names}

    def observe(self, lock_fd):
        controls, state = self.controls()
        return controls, state, self.evidence()

    def reconcile(self):
        return self.m.reconcile(self.ctx, self.lock_fd, lambda: None, *self.observe(self.lock_fd))

    def audits(self):
        return sorted(self.work.glob(self.m.PREFIX + '*'))

    def assert_protected(self, snapshot=None):
        self.assertEqual(self.protected_snapshot(), self.original if snapshot is None else snapshot)
        for name in self.m.MODE_CORRECTIONS:
            self.assertEqual((self.live / name).stat().st_mode & 0o777, 0o755)

    def pending_fixture(self, exchanged=False):
        path = self.work / (self.m.PREFIX + 'a' * 32)
        path.mkdir(mode=0o700)
        controls, _, proof = self.observe(self.lock_fd)
        intent = self.m.intent_value(self.ctx, controls, proof, self.u.exact_key(self.hold_path.stat()))
        self.g.program_gate_write(path / 'before-hold.json', self.m.hold(self.prepared))
        self.g.program_gate_write(path / 'after-hold.json', self.m.hold(self.expected))
        self.g.program_gate_write(path / 'intent.json', intent)
        if exchanged:
            a, b = self.u.Directory(path, 0o700), self.u.Directory(self.work, 0o700)
            try: self.u.exchange(a.fd, 'after-hold.json', b.fd, self.hold_path.name)
            finally: a.close(); b.close()
        return path

    def test_success_exchanges_only_unauthorized_hold_and_preserves_every_protected_inode(self):
        result = self.reconcile()
        self.assertTrue(result['ok']); self.assertFalse(result['already_current'])
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold(self.expected))
        path = self.audits()[0]
        self.assertTrue((path / 'completed.json').exists())
        self.assertEqual((path / 'after-hold.json').read_bytes(), self.old_hold)
        self.assertEqual((path / 'after-hold.json').stat().st_ino, self.old_hold_inode)
        self.assert_protected()
        self.assertNotIn('private_fixture_token', (path / 'intent.json').read_text())

    def test_complete_retry_does_not_exchange_hold_or_rewrite_journal_and_allows_ci_state_progress(self):
        self.reconcile()
        path = self.audits()[0]
        journal = {str(item): (item.read_bytes(), self.u.exact_key(item.stat()))
                   for item in path.iterdir() if item.is_file()}
        hold = self.u.exact_key(self.hold_path.stat())
        self.state['desired']['last_ci_observation'] = 42
        self.control_files['state'].write_bytes(self.m.canonical(self.state))
        snapshot = self.protected_snapshot()
        with mock.patch.object(self.u, 'exchange', side_effect=AssertionError('retry must not exchange')):
            self.assertTrue(self.reconcile()['already_current'])
        self.assertEqual(self.u.exact_key(self.hold_path.stat()), hold)
        self.assertEqual({str(item): (item.read_bytes(), self.u.exact_key(item.stat()))
                          for item in path.iterdir() if item.is_file()}, journal)
        self.assert_protected(snapshot)

    def test_pending_before_exchange_recovers_without_touching_code_state_or_original_proofs(self):
        path = self.pending_fixture()
        self.assertTrue(self.reconcile()['already_current'])
        self.assertTrue((path / 'completed.json').exists())
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold(self.expected))
        self.assert_protected()

    def test_pending_after_exchange_finishes_receipt_without_exchanging_again(self):
        path = self.pending_fixture(exchanged=True)
        hold = self.u.exact_key(self.hold_path.stat())
        with mock.patch.object(self.u, 'exchange', side_effect=AssertionError('must not exchange twice')):
            self.assertTrue(self.reconcile()['already_current'])
        self.assertEqual(self.u.exact_key(self.hold_path.stat()), hold)
        self.assertTrue((path / 'completed.json').exists())
        self.assert_protected()

    def test_new_hold_without_reconciliation_proof_is_never_adopted(self):
        self.g.program_gate_write(self.hold_path, self.m.hold(self.expected))
        hold = self.hold_path.read_bytes()
        with self.assertRaisesRegex(self.m.Stop, 'OLD_HOLD_DIFFERS_OR_AUTHORIZED'): self.reconcile()
        self.assertFalse(self.audits()); self.assertEqual(self.hold_path.read_bytes(), hold)
        self.assert_protected()

    def test_authorized_old_hold_or_existing_publication_cannot_be_reinterpreted(self):
        value = self.m.hold(self.prepared)
        value['authorization'] = {'candidate': self.m.CANDIDATE}
        self.g.program_gate_write(self.hold_path, value)
        with self.assertRaisesRegex(self.m.Stop, 'OLD_HOLD_DIFFERS_OR_AUTHORIZED'): self.reconcile()
        self.assertEqual(self.g.program_gate_json(self.hold_path), value)
        self.g.program_gate_write(self.hold_path, self.m.hold(self.prepared))
        self.publication = {'source_revision': self.m.CANDIDATE}
        with self.assertRaisesRegex(self.m.Stop, 'ALREADY_PUBLISHED'): self.reconcile()
        self.assertFalse(self.audits()); self.assert_protected()

    def test_failure_before_exchange_rolls_back_only_new_audit(self):
        with mock.patch.object(self.u, 'exchange', side_effect=OSError('synthetic exchange failure')):
            with self.assertRaises(OSError): self.reconcile()
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        self.assertEqual(self.hold_path.stat().st_ino, self.old_hold_inode)
        self.assertTrue((self.audits()[0] / 'rolled-back.json').exists())
        self.assert_protected()

    def test_failure_after_exchange_restores_original_hold_inode_and_preserves_protected_data(self):
        original = self.u.json_at
        def write(directory, name, value):
            if name == 'completed.json': raise OSError('synthetic receipt write failure')
            return original(directory, name, value)
        with mock.patch.object(self.u, 'json_at', side_effect=write):
            with self.assertRaises(OSError): self.reconcile()
        self.assertEqual(self.hold_path.read_bytes(), self.old_hold)
        self.assertEqual(self.hold_path.stat().st_ino, self.old_hold_inode)
        self.assertTrue((self.audits()[0] / 'rolled-back.json').exists())
        self.assert_protected()

    def test_foreign_pending_hold_inode_and_old_proof_drift_fail_closed(self):
        path = self.pending_fixture()
        self.g.program_gate_write(self.hold_path, self.m.hold(self.prepared))
        foreign_inode = self.hold_path.stat().st_ino
        with self.assertRaisesRegex(self.m.Stop, 'PENDING_RECONCILIATION_REQUIRES_REVIEW') as stopped:
            self.reconcile()
        self.assertFalse(stopped.exception.rollback_complete)
        self.assertEqual(self.hold_path.stat().st_ino, foreign_inode)
        self.assertFalse((path / 'completed.json').exists()); self.assert_protected()

    def test_complete_journal_refuses_changes_to_original_proof_or_config(self):
        self.reconcile()
        path = self.audits()[0]
        completion = (path / 'completed.json').read_bytes()
        old = self.old_audit / 'intent.json'
        old.write_bytes(b'{"concurrent_old_proof_change":true}\n')
        changed = old.read_bytes()
        with self.assertRaisesRegex(self.m.Stop, 'PENDING_RECONCILIATION_REQUIRES_REVIEW'): self.reconcile()
        self.assertEqual(old.read_bytes(), changed)
        self.assertEqual((path / 'completed.json').read_bytes(), completion)
        self.assertEqual(self.g.program_gate_json(self.hold_path), self.m.hold(self.expected))

    def test_main_private_failure_never_stringifies_error_or_reports_changed_flags(self):
        class PrivateStop(Exception):
            def __str__(self): raise AssertionError('do not stringify private errors')
        error = PrivateStop('private-account-value')
        error.rollback_complete = 'private-status'
        output = io.StringIO()
        with mock.patch.object(self.m, 'execute', side_effect=error), mock.patch('sys.stdout', output):
            status = self.m.main(['--package', str(self.work), '--mode', 'reconcile'])
        self.assertEqual(status, 78)
        result = json.loads(output.getvalue())
        self.assertIs(result['rollback_complete'], False)
        for name in ('application_contents_changed', 'modes_changed', 'database_changed', 'state_changed',
                     'configuration_changed', 'credentials_disclosed'):
            self.assertIs(result[name], False)
        self.assertNotIn('private-account-value', output.getvalue())
        self.assertNotIn('private-status', output.getvalue())

    def test_actual_prepared_evidence_identity_is_json_stable_for_retry(self):
        completed = json.loads((self.old_audit / 'completed.json').read_bytes())
        self.ctx.old = types.SimpleNamespace(validate_intent=lambda *args: None, inner_commit=lambda *args: None,
                                             completion_value=lambda *args: completed)
        self.ctx.original = object()
        self.ctx.application = types.SimpleNamespace(verify_current=lambda *args: None)
        self.ctx.owner = types.SimpleNamespace(pw_uid=0, pw_gid=0)
        self.ctx.package = self.root
        value = self.m.Context.prepared_evidence(self.ctx, self.controls()[0], self.lock_fd)
        self.assertEqual(json.loads(self.m.canonical(value)), value)
        self.assertTrue(all(type(row['identity']) is list for row in value.values()))
        self.assert_protected()


GIT = r'''#!/usr/bin/python3
import os,sys
from pathlib import Path
args=sys.argv[1:]
scenario=__import__('json').loads(Path(os.environ['MODE_TEST_SCENARIO']).read_text())
if 'rev-parse' in args:print('d'*40 if args[-1]=='FETCH_HEAD' else args[-1].split('^{',1)[0])
elif 'show' in args:
    if scenario.get('fail_export') and args[-1].endswith(':deployment/upgrade_go_erp_mode_inventory_root.py'):raise SystemExit(97)
    if args[-1].endswith(':deployment/upgrade_go_erp_mode_inventory_root.py'):
        sys.stdout.buffer.write(Path(os.environ['MODE_TEST_ADAPTER']).read_bytes())
elif 'fetch' not in args and 'init' not in args:raise SystemExit(93)
'''

SYSTEMCTL = r'''#!/usr/bin/python3
import json,os,sys
from pathlib import Path
args=sys.argv[1:];path=Path(os.environ['MODE_TEST_STATE']);state=json.loads(path.read_text())
scenario=json.loads(Path(os.environ['MODE_TEST_SCENARIO']).read_text())
with open(os.environ['MODE_TEST_EVENTS'],'a') as output:output.write(json.dumps(args)+'\n')
timer='go-erp-live-updates.timer';service='go-erp-live-updates.service'
if args==['show',timer,'-p','ActiveState','--value']:print(state['timer'])
elif args==['stop',timer]:state['timer']='inactive'
elif args==['start',timer]:state['timer']='active'
elif args==['is-active','--quiet',timer]:raise SystemExit(0 if state['timer']=='active' else 3)
elif args==['show',service,'-p','ActiveState','--value']:print('inactive')
elif args==['show',service,'-p','ExecMainStartTimestampMonotonic','--value']:print(state['started'])
elif args==['start','--no-block',service]:
    state['starts']+=1;state['polls']=0
    if not scenario.get('stale_start'):state['started']+=1
elif args==['show',service,'-p','ActiveState','-p','ExecMainStartTimestampMonotonic','-p','Result','-p','ExecMainStatus']:
    state['polls']+=1
    if scenario.get('stale_start') and not scenario.get('timeout') and state['polls']==2:state['started']+=1
    failed=scenario.get('fail_collect') and state['starts']==1
    print('ActiveState='+('failed' if failed else 'inactive'))
    print('ExecMainStartTimestampMonotonic='+str(state['started']))
    print('Result='+('exit-code' if failed else 'success'))
    print('ExecMainStatus='+('1' if failed else '0'))
else:raise SystemExit(94)
path.write_text(json.dumps(state))
'''

ADAPTER = r'''import json,os,sys
from pathlib import Path
args=sys.argv[1:];mode=args[args.index('--mode')+1]
with open(os.environ['MODE_TEST_INVOCATIONS'],'a') as output:output.write(json.dumps(args)+'\n')
scenario=json.loads(Path(os.environ['MODE_TEST_SCENARIO']).read_text())
actions={'reconcile':'collect','status':'authorize','authorize':'publish','verify':'none'}
action=actions[mode];published=mode=='verify'
if scenario.get('already') or scenario.get('published_during_collect') and mode=='status':action='none';published=True
value={'ok':True,'kind':'go-erp-mode-inventory-reconciliation','worker_request':action,'published':published}
value.update(scenario.get('changes',{}).get(mode,{}))
print(scenario['raw'] if scenario.get('raw_mode')==mode else json.dumps(value))
if mode=='authorize':
    assert args[-2:]==['--receipt',str(Path(os.environ['MODE_TEST_WORK'])/'pending-program-acceptance.json')]
raise SystemExit(scenario.get('exit_codes',{}).get(mode,0))
'''


@unittest.skipUnless(os.geteuid() == 0, 'the real Bash wrapper requires root')
class ModeReconciliationWrapperTests(unittest.TestCase):
    def invoke(self, scenario=None, timer='inactive'):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-mode-wrapper-')
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        work, bins = root / 'work', root / 'bin'
        work.mkdir(mode=0o700); bins.mkdir(mode=0o700)
        for name, text in [('git', GIT), ('systemctl', SYSTEMCTL),
                           ('hostname', '#!/bin/bash\nprintf "%s\\n" srv1836524\n'),
                           ('sleep', '#!/bin/bash\nexit 0\n')]:
            path = bins / name; path.write_text(text); path.chmod(0o700)
        state = root / 'state.json'
        state.write_text(json.dumps({'timer': timer, 'started': 100, 'starts': 0, 'polls': 0}))
        settings = root / 'scenario.json'; settings.write_text(json.dumps(scenario or {}))
        adapter = root / 'adapter.py'; adapter.write_text(ADAPTER)
        events, invocations = root / 'events.jsonl', root / 'invocations.jsonl'
        wrapper = ROOT / 'deployment/run_go_erp_mode_inventory_root.sh'
        text = wrapper.read_text()
        self.assertEqual(text.count('go_work=/var/lib/go-erp-live-updates'), 1)
        for unsafe in ('/usr/bin/systemctl', '/bin/systemctl', '/usr/bin/git', '/bin/git'):
            self.assertNotIn(unsafe, text)
        script = root / 'run.sh'
        script.write_text(text.replace('go_work=/var/lib/go-erp-live-updates', 'go_work=' + shlex.quote(str(work))))
        environment = {'PATH': str(bins) + ':/usr/bin:/bin', 'LANG': 'C.UTF-8', 'MODE_TEST_ADAPTER': str(adapter),
            'MODE_TEST_STATE': str(state), 'MODE_TEST_SCENARIO': str(settings), 'MODE_TEST_EVENTS': str(events),
            'MODE_TEST_INVOCATIONS': str(invocations), 'MODE_TEST_WORK': str(work)}
        result = subprocess.run(['/usr/bin/bash', str(script), 'd' * 40, '--complete'], env=environment,
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=25)
        calls = [json.loads(row) for row in events.read_text().splitlines()] if events.exists() else []
        entry = [json.loads(row) for row in invocations.read_text().splitlines()] if invocations.exists() else []
        self.assertFalse(any(call[:2] == ['stop', 'go-erp-live-updates.service'] for call in calls))
        return result, json.loads(state.read_text()), calls, entry, list(work.glob('mode-inventory-download.*'))

    def test_complete_collects_once_authorizes_same_receipt_publishes_once_then_verifies_and_resumes(self):
        result, state, calls, entries, stages = self.invoke()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual([args[args.index('--mode') + 1] for args in entries], ['reconcile', 'status', 'authorize', 'verify'])
        self.assertEqual(state['starts'], 2)
        self.assertEqual(state['timer'], 'active')
        self.assertEqual(calls.count(['start', 'go-erp-live-updates.timer']), 1)
        self.assertEqual(stages, [])
        self.assertIn('GO_ERP_PREVIEW37_PUBLISHED_AUTOMATIC_UPDATES_ACTIVE', result.stdout)

    def test_verified_completed_retry_resumes_timer_without_any_worker(self):
        for timer in ('inactive', 'active'):
            with self.subTest(timer=timer):
                result, state, calls, entries, stages = self.invoke({'already': True}, timer)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(state['starts'], 0)
                self.assertEqual(state['timer'], 'active')
                self.assertEqual([args[args.index('--mode') + 1] for args in entries], ['reconcile', 'verify'])
                self.assertEqual(stages, [])

    def test_stale_inactive_result_waits_for_this_invocations_new_timestamp(self):
        result, state, calls, entries, stages = self.invoke({'stale_start': True})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(state['starts'], 2)
        self.assertIn('GO_ERP_WAITING_WORKER phase=collect', result.stdout)
        self.assertIn('GO_ERP_WAITING_WORKER phase=publish', result.stdout)
        self.assertEqual(stages, [])

    def test_worker_failure_stops_before_authorization_and_leaves_timer_paused(self):
        result, state, calls, entries, stages = self.invoke({'fail_collect': True})
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(state['starts'], 1)
        self.assertEqual(state['timer'], 'inactive')
        self.assertEqual([args[args.index('--mode') + 1] for args in entries], ['reconcile'])
        self.assertNotIn(['start', 'go-erp-live-updates.timer'], calls)
        self.assertEqual(len(stages), 1)

    def test_unconfirmed_worker_completion_times_out_without_stopping_or_authorizing(self):
        result, state, calls, entries, stages = self.invoke({'stale_start': True, 'timeout': True})
        self.assertEqual(result.returncode, 75)
        self.assertEqual(state['starts'], 1)
        self.assertEqual(state['timer'], 'inactive')
        self.assertEqual(len(entries), 1)
        self.assertEqual(len(stages), 1)

    def test_untrusted_phase_result_or_failed_final_verification_never_resumes_timer(self):
        for scenario in ({'changes': {'reconcile': {'worker_request': 'publish'}}},
                         {'changes': {'verify': {'published': False, 'worker_request': 'collect'}}},
                         {'raw_mode': 'status', 'raw': '{invalid-json'},
                         {'changes': {'status': {'ok': False, 'reason': 'FIXED_PRIVATE_FAILURE'}}, 'exit_codes': {'status': 1}},
                         {'exit_codes': {'authorize': 78}}):
            with self.subTest(scenario=scenario):
                result, state, calls, entries, stages = self.invoke(scenario)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(state['timer'], 'inactive')
                self.assertNotIn(['start', 'go-erp-live-updates.timer'], calls)
                self.assertEqual(len(stages), 1)

    def test_missing_export_fails_before_reconciliation_or_service_changes(self):
        result, state, calls, entries, stages = self.invoke({'fail_export': True}, 'active')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(state['timer'], 'active')
        self.assertEqual(state['starts'], 0)
        self.assertEqual(calls, [])
        self.assertEqual(entries, [])
        self.assertEqual(len(stages), 1)


if __name__ == '__main__': unittest.main()
