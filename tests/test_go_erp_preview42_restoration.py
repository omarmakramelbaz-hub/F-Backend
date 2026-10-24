"""Restore only accepted 42 using the actual frozen gate and real publisher."""
import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import types
import unittest
import urllib.error
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


subject = load('restore42_subject', ROOT / 'deployment/restore_go_erp_preview42_root.py')
CANCEL_CI = subject.cancel_ci
prior_test = load('restore42_private_fixture', ROOT / 'tests/test_go_erp_preview42_rearm.py')
base = prior_test.completion


def frozen_module(name, raw):
    module = types.ModuleType(name)
    module.__file__ = name + '.py'
    sys.modules[name] = module
    exec(compile(raw, module.__file__, 'exec'), module.__dict__)
    return module


def frozen_payloads():
    def show(path):
        return subprocess.check_output(['git', 'show',
            '2d9e23a5157782882382c797a44ad15c317ae845:' + path], cwd=ROOT)
    program = frozen_module('restoration42_original_program',
        show('deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py'))
    payloads = program.adapt_payloads({name: show('deployment/wire-hash-prior/payload/' + name)
        for name in program.RAW_PINS}, show('deployment/go_erp_program_rollout_gate.py'))
    raw = (ROOT / 'deployment/upgrade_go_erp_fullscreen_watcher_root.py').read_bytes()
    transaction = frozen_module('restoration42_original_fullscreen', raw)
    payloads = transaction.adapt_payloads(payloads)
    for label in ('current_dashboard', 'employee_dialog', 'blue_sound', 'windows_fixtures'):
        helper = load('restoration42_' + label, ROOT / ('deployment/upgrade_go_erp_' + label + '_root.py'))
        assert helper.OLD_RUNTIME == prior_test.utility.runtime_name(payloads)
        transaction = frozen_module('restoration42_transaction_' + label, raw)
        helper.configure(transaction)
        payloads = transaction.adapt_payloads(payloads)
    assert prior_test.utility.runtime_name(payloads) == base.RUNTIME
    assert hashlib.sha256(payloads['go_erp_live_updates.py']).hexdigest() == '5ab35b610efd7d4dfce6e3f8bb10f8dc3a841767eb396c6a2b0dbcee13403e33'
    return payloads


PAYLOADS = frozen_payloads()
gate = frozen_module('restoration42_actual_worker', PAYLOADS['go_erp_live_updates.py'])
publisher = frozen_module('restoration42_actual_publisher', PAYLOADS['publish_go_erp_update.py'])


class RestorationTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='restore42-proof-')
        self.addCleanup(temporary.cleanup)
        self.work = Path(temporary.name)
        self.f = prior_test.Fixture(self.work)
        self.f.remote_revision = subject.CANDIDATE43
        (self.work / 'program-rollout-hold.json').unlink()
        self.live = self.work / 'live'
        self.folder = self.live / 'storage/app/desktop-updates'
        self.folder.mkdir(mode=0o755, parents=True)
        content = b'MZactual-offline-restoration-fixture'
        sha = hashlib.sha256(content).hexdigest()
        self.original_receipt = {'format': 1, 'kind': 'go-erp-desktop-release',
            'version': subject.VERSION, 'sequence': subject.RUN42['id'],
            'source_revision': subject.CANDIDATE42, 'source': copy.deepcopy(base.SOURCE), 'gui': {},
            'installer': {'sha256': sha, 'bytes': len(content)},
            'acceptance': {'windows_installer': True, 'runtime': True, 'connected': True,
                'source_revision': subject.CANDIDATE42}}
        self.release = publisher.validate_receipt(self.original_receipt)
        (self.folder / (sha + '.exe')).write_bytes(content)
        self.f.state.update(counter=43, last_remote_sha=subject.CANDIDATE43,
            version_floor=subject.VERSION, sequence_floor=self.release['sequence'],
            published_history=[self.release], published={'commit': subject.CANDIDATE42,
                'inventorySha256': base.INVENTORY, 'run_id': subject.RUN42['id'],
                'run_attempt': 1, 'release': self.release})
        self.f.state['desired'].update(commit=subject.CANDIDATE43,
            version='0.4.4-preview.43', status='waiting-ci')
        self.f.write_state(self.f.state)
        self.ci = self.work / 'program-acceptance' / (subject.CANDIDATE42 + '-abcdefgh') / 'ci'
        self.ci.mkdir(mode=0o700, parents=True)
        for parent in (self.ci.parent, self.ci.parent.parent): parent.chmod(0o700)
        (self.ci / 'GO-ERP-Preview-Setup.exe').write_bytes(content)
        (self.ci / 'GO-ERP-Preview-Setup.exe').chmod(0o600)
        gate.program_gate_write(self.ci / 'go-erp-update-release.json', self.original_receipt)
        employee = {'kind': 'original-employee-identity-browser',
            'sourceRevision': subject.CANDIDATE42, 'fixtureRevision': subject.CANDIDATE42,
            'sourceFingerprint': base.SOURCE, 'helperBinding': 'exact-git-revision',
            'sourceBinding': 'runtime-manifest', 'schemaInventory': 106, 'checks': 46,
            'browserProof': {}}
        for key in ('completed','privateDatabasesDropped','httpStopped','browserStopped','sourceVerifiedAfter',
            'helpersVerifiedAfter','verifiedImport','privateMediaHttpVerified','consentJournalVerified',
            'consentReplayVerified','duplicateReplayVerified','staleQueuedConsentRejected'): employee[key] = True
        for key in ('fullDashboard','providersReplaced','domInjection'): employee[key] = False
        cases = {'central': ('realUpload','immutableABA','staleConsentRejected','firstAcceptanceDatePreserved',
            'withdrawal','branchReadOnly','outOfScopeDenied','regulationRendered'),
            'local': ('privatePhotos','onlineUploadExplanation','consentJournal')}
        for phase, checks in cases.items():
            entry = {'browser': 'Windows Edge', 'browserPlatform': 'win32', 'phase': phase,
                'domInjection': False, 'providersReplaced': False, 'newPageErrors': 0,
                'newEmployeeConsoleErrors': 0, 'privatePublicUrlDenied': True, 'listingRedacted': True,
                'sourceCommit': subject.CANDIDATE42, 'fixtureRevision': subject.CANDIDATE42,
                'imagesDecoded': True, 'displayedPhotoSourcesBound': True, 'externalRequestsBlocked': 0}
            entry.update({name: True for name in checks})
            employee['browserProof'][phase] = entry
        self.employee = self.ci.parent / 'employee.json'
        gate.program_gate_write(self.employee, employee)
        evidence = {'path': str(self.employee), 'sha256': hashlib.sha256(self.employee.read_bytes()).hexdigest()}
        acceptance = {'format': 1, 'kind': 'go-erp-program-acceptance', **self.f.expected,
            'candidate_revision': subject.CANDIDATE42, 'version': subject.VERSION,
            'installer': self.release['installer'], 'ci': {'directory': str(self.ci), **subject.RUN42},
            'employee_evidence': evidence}
        acceptance['ci'] = {'directory': str(self.ci), 'run_id': subject.RUN42['id'], 'run_attempt': 1}
        raw = gate.json.dumps(acceptance, sort_keys=True).encode() + b'\n'
        digest = hashlib.sha256(raw).hexdigest()
        parent = self.work / 'program-rollout-authorizations'
        parent.mkdir(mode=0o700)
        self.receipt_path = parent / ('receipt-' + digest + '.json')
        self.receipt_path.write_bytes(raw)
        self.receipt_path.chmod(0o600)
        auth = {'candidate': subject.CANDIDATE42, 'version': subject.VERSION,
            'installer': self.release['installer'], 'release': self.release,
            'receipt_path': str(self.receipt_path), 'receipt_sha256': digest, 'employee_evidence': evidence}
        self.completed = self.work / ('program-rollout-completed-' + base.CORE + '.json')
        gate.program_gate_write(self.completed, {'format': 1, 'kind': 'go-erp-program-rollout-completed',
            **self.f.expected, 'release': self.release, 'authorization': auth,
            'canonical_publication_verified': True})
        self.proof_calls = []
        def proof(folder, run, desired, pub):
            self.proof_calls.append((folder, run, desired))
            self.assertEqual(folder, self.ci)
            self.assertEqual(run, subject.RUN42)
            self.assertEqual(desired['commit'], subject.CANDIDATE42)
            # The production helper delegates the full original native verifier.
            # These offline fixtures exercise its rejection/publication boundary
            # and real installer SHA checks, not Windows execution.
            value = publisher.read_json(folder / 'go-erp-update-release.json', root_owned=True)
            release = publisher.validate_receipt(value)
            publisher.verify_installer(folder / 'GO-ERP-Preview-Setup.exe', release['installer'], root_owned=True)
            return value, release
        self.f.worker = types.SimpleNamespace(**gate.__dict__)
        self.f.worker.verify_proof = proof
        self.f.gate = gate
        self.f.publisher = types.SimpleNamespace(**publisher.__dict__)
        self.f.publisher.publish = lambda root, installer, receipt: publisher.publish(root, installer, receipt,
            snapshot=lambda root: {'fingerprint': base.SOURCE, 'snapshot': {'additional_dashboard_gui': {}}})
        def publication():
            try:return publisher.validate_release(publisher.read_json(self.folder / 'current.json', root_owned=True))
            except FileNotFoundError:return None
        self.f.publication = publication
        for name, value in [('WORK', self.work), ('ROOT', self.live)]:
            changed = patch.object(base, name, value)
            changed.start();self.addCleanup(changed.stop)
        cancelled = patch.object(subject, 'cancel_ci', return_value='write_forbidden')
        cancelled.start();self.addCleanup(cancelled.stop)

    def restore(self):
        return subject.restore(base, self.f)

    def assert_cancelled_only(self, original):
        actual = json.loads(self.f.state_path.read_bytes())
        wanted = copy.deepcopy(original)
        wanted['desired']['status'] = 'blocked'
        self.assertEqual(actual, wanted)
        return actual

    def test_real_completed_gate_and_publisher_restore42_without_rewriting43_history(self):
        original = copy.deepcopy(self.f.state)
        self.assertTrue(self.restore()['published'])
        actual = self.assert_cancelled_only(original)
        self.assertEqual(self.f.publication(), self.release)
        self.assertEqual(len(self.proof_calls), 1)
        self.assertFalse(gate.program_gate_completed(self.work, self.f.expected, actual, self.f.publication))
        historical = copy.deepcopy(actual)
        historical['desired'] = self.proof_calls[0][2]
        self.assertEqual(historical['counter'], 43)
        self.assertTrue(gate.program_gate_completed(self.work, self.f.expected, historical, self.f.publication))
        self.assertEqual(actual['desired']['retry_at'], original['desired']['retry_at'])
        self.assertEqual(actual['desired']['failures'], original['desired']['failures'])

    def test_rerun_with_already_blocked43_and_canonical42_is_idempotent(self):
        self.restore()
        raw = self.f.state_path.read_bytes()
        self.assertTrue(self.restore()['published'])
        self.assertEqual(self.f.state_path.read_bytes(), raw)

    def test_tampered_employee_or_failed_native_proof_never_publish_and_keep43_cancelled(self):
        self.employee.write_bytes(self.employee.read_bytes() + b' ')
        with self.assertRaisesRegex(subject.Stop, 'EMPLOYEE42_CHANGED'):self.restore()
        self.assert_cancelled_only(self.f.state)
        self.assertIsNone(self.f.publication())

    def test_original_verifier_failure_is_not_replaced_with_state_receipt(self):
        self.f.worker.verify_proof = lambda *args: (_ for _ in ()).throw(RuntimeError('private proof failure'))
        with self.assertRaises(RuntimeError):self.restore()
        self.assert_cancelled_only(self.f.state)
        self.assertIsNone(self.f.publication())

    def test_failure_after_publication_rolls_feed_back_but_keeps_cancelled43(self):
        publish = self.f.publisher.publish
        def changed(*args):
            value = publish(*args)
            self.f.source_error = True
            return value
        self.f.publisher.publish = changed
        with self.assertRaises(prior_test.subject.Stop):self.restore()
        self.assert_cancelled_only(self.f.state)
        self.assertIsNone(self.f.publication())

    def test_foreign_state_or_feed_cannot_be_overwritten(self):
        self.f.state['counter'] = 44
        self.f.write_state(self.f.state)
        before = self.f.state_path.read_bytes()
        with self.assertRaises(subject.Stop):self.restore()
        self.assertEqual(self.f.state_path.read_bytes(), before)
        self.assertIsNone(self.f.publication())

    def test_retained_installer_is_required_and_completion_pin_matches(self):
        self.assertEqual(hashlib.sha256((ROOT / 'deployment' / subject.BASE_NAME).read_bytes()).hexdigest(), subject.BASE_SHA256)
        (self.ci / 'GO-ERP-Preview-Setup.exe').unlink()
        with self.assertRaises(FileNotFoundError):self.restore()
        self.assert_cancelled_only(self.f.state)
        self.assertIsNone(self.f.publication())

    def test_cancel_post_is_fixed_and_actions_write_denial_is_sanitized(self):
        url = 'https://api.github.com/repos/' + gate.REPOSITORY + '/actions/runs/' + str(subject.RUN43) + '/cancel'
        run = {'id': subject.RUN43, 'run_attempt': 1, 'event': 'push', 'head_branch': gate.BRANCH,
            'head_sha': subject.CANDIDATE43, 'pull_requests': [],
            'path': '.github/workflows/desktop-dashboard-tests.yml', 'status': 'in_progress',
            'repository': {'id': gate.REPOSITORY_ID, 'full_name': gate.REPOSITORY},
            'head_repository': {'id': gate.REPOSITORY_ID, 'full_name': gate.REPOSITORY}}
        requests = []
        def open_request(request, timeout):
            requests.append(request)
            self.assertEqual(request.full_url, url)
            self.assertEqual(request.method, 'POST')
            self.assertEqual(timeout, 20)
            raise urllib.error.HTTPError(url, 403, 'PRIVATE_TOKEN', {}, io.BytesIO(b'PRIVATE_TOKEN'))
        client = types.SimpleNamespace(json=lambda path: run, opener=types.SimpleNamespace(open=open_request))
        self.f.worker.GitHub = lambda token: client
        self.assertEqual(CANCEL_CI(self.f, {'token': 'PRIVATE_TOKEN'}), 'write_forbidden')
        self.assertEqual(len(requests), 1)
        run['head_sha'] = 'f' * 40
        self.assertEqual(CANCEL_CI(self.f, {'token': 'PRIVATE_TOKEN'}), 'request_failed')
        self.assertEqual(len(requests), 1)

    def test_operator_interrupt_stops_restoration_instead_of_becoming_network_retry(self):
        def interrupted(path):
            raise KeyboardInterrupt()
        self.f.worker.GitHub = lambda token: types.SimpleNamespace(json=interrupted)
        with self.assertRaises(KeyboardInterrupt):
            CANCEL_CI(self.f, {'token': 'PRIVATE_TOKEN'})


if __name__ == '__main__':
    unittest.main()
