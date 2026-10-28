#!/usr/bin/env python3
"""A reviewed successor to the exact audit-failed preview.61 transaction.

No old journal is edited.  The original native four-slot migration, worker
allocator, full Windows acceptance and publisher retain ownership of their
effects.  The only application dependency change lives in the new Git seed.
"""
import copy
import hashlib
import json
from pathlib import Path
import re
import socket
import os

CORE = '22f724b1ec75e8326e20305df40b91fe5640a7a7'
SEED = '6a9afe74203995a36416373528de5a29097fee83'
CORE_PARENT = '6e39001095dbf5909c7b58cff69914c50da0f200'
POLICY_VERIFIER_SHA = '9ea3aee1475ea1753ec67fddbc01bb0e68c60024dfb7b9afcefc70d6a48b3033'
RUNTIME_LOCK_SHA = '0e7d39b178783346527bd1fdd2beb47233e56de2d84bd7be6770d101f0cf9ee4'
ROOT_RELEASE = '1e651117deabb4c6935933ee424237d82bd316cb'
WORK = Path('/var/lib/go-erp-live-updates')
PRIVATE = Path('/var/lib/go-erp-manual-updates/private')
AUDIT = PRIVATE / 'audits' / ('desktop-audit-successor-' + CORE)
HOLD_AUDIT = PRIVATE / 'audits' / ('desktop-audit-hold-' + CORE)
JOURNAL = PRIVATE / ('desktop-audit-successor-' + CORE + '.json')
FAILED_CI_RUN = 40764223785
FAILED_CI_JOB = 123084808821
VERSION = '0.4.4-preview.62'
REV = re.compile(r'[a-f0-9]{40}\Z')
PHP_HANDLER_RELEASE = '8db5adb538834a2152f74f6e55336223a4c95908'
PHP_HANDLER_ORIGINAL_SHA = 'dc26aa53fb8c2636ff079a43d0f8062e75b837154315a7a3693b1e1e18828634'
PHP_HANDLER_DERIVED_SHA = '8f4d740fc56e5352d35c3d7439521f7a29262c2f0e65bec845ecf3c848353b21'
PHP_SOCKET_BEFORE = b"""            need(re.fullmatch(r'[1-9][0-9]*', parts[6]))
            # Linux copies a listening stream's address to its accepted peers.
            # Those rows may share this pathname, but cannot prove a listener.
            if parts[3] == '00000000':
                need(parts[4] == '0001' and parts[5] in ('01', '02', '03', '04'))
                continue
            need(parts[3] == '00010000' and parts[4] == '0001' and parts[5] == '01')
"""
PHP_SOCKET_AFTER = b"""            # Linux copies a listening stream's address to its accepted peers.
            # Queued peers can have inode zero before accept grafts a socket.
            # They cannot prove a listener or satisfy the master's FD check.
            if parts[3] == '00000000':
                need(re.fullmatch(r'(?:0|[1-9][0-9]*)', parts[6])
                    and parts[4] == '0001' and parts[5] in ('01', '02', '03', '04'))
                continue
            need(re.fullmatch(r'[1-9][0-9]*', parts[6]))
            need(parts[3] == '00010000' and parts[4] == '0001' and parts[5] == '01')
"""


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_DESKTOP_AUDIT_SUCCESSOR_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def php_handler_bytes(raw):
    """Derive one reviewed peer-row correction; preserve every other proof."""
    need(isinstance(raw, bytes) and sha(raw) == PHP_HANDLER_ORIGINAL_SHA,
         'PHP_HANDLER_ORIGINAL_CHANGED')
    need(raw.count(PHP_SOCKET_BEFORE) == 1, 'PHP_HANDLER_SOCKET_CONTEXT_CHANGED')
    result = raw.replace(PHP_SOCKET_BEFORE, PHP_SOCKET_AFTER)
    need(sha(result) == PHP_HANDLER_DERIVED_SHA, 'PHP_HANDLER_DERIVATION_CHANGED')
    return result


class Successor:
    def __init__(self, package, operator, modules):
        self.package, self.o, self.modules = package, operator, modules
        self.h = modules['successor_plan']
        self.e, self.io = operator.engine, operator.io
        self.old = modules['coordinator'].Rollout(package, operator, modules['current'], modules['payloads'],
            modules['source'], recovery={'plan': modules['recovery_plan'],
                'evidence': modules['recovery_evidence'], 'effects': modules['recovery_effects']})
        need(package.revision == ROOT_RELEASE and self.h.NEW_CORE == CORE and self.h.NEW_SEED == SEED,
             'PACKAGE_BINDING_CHANGED')
        self.proof = None
        self.failed = None
        self.observation = None
        self.pins = None
        self.context = None
        self.holder = {}
        self._install_php_handler()
        self._review_seed()

    def _install_php_handler(self):
        # This is an instance-local attestation adapter. The frozen root and
        # original seven bundled modules retain their independently pinned bytes.
        need(self.old.root_api.LEGACY_RELEASE == PHP_HANDLER_RELEASE
             and self.old.root_api.PHP_HANDLER_SHA == PHP_HANDLER_ORIGINAL_SHA,
             'PHP_HANDLER_ROOT_BINDING_CHANGED')
        raw = self.package.file('deployment/go_erp_navigation_php_root.py', PHP_HANDLER_RELEASE)
        module = self.old.root_api.load(php_handler_bytes(raw),
            'audit_successor_php_handler', PHP_HANDLER_DERIVED_SHA)
        def handler():
            try:
                return module.verify(self.e)
            except module.Stop as error:
                need(str(error) == 'PHP_HANDLER_REVIEW_REQUIRED', 'PHP_HANDLER_ERROR_CODE_CHANGED')
                raise Stop('GO_ERP_DESKTOP_AUDIT_SUCCESSOR_PHP_HANDLER_REVIEW_REQUIRED') from None
        self.o._handler = handler

    def read(self, path, *, optional=False, modes=(0o600,), limit=16*1024**2):
        result = self.e.read_file(path, limit, uid=0, modes=modes, optional=optional)
        return None if result is None else result[0]

    def record(self, name, raw, verify):
        self.io.private_record(AUDIT / name, raw, verify)

    def _review_seed(self):
        # Revision walking hides parent edges at shallow boundaries even when
        # the parent object was separately fetched.  Read the immutable commit
        # headers, exactly as the original policy-seed verifier does.
        headers = self.package.git('cat-file', 'commit', CORE).split(b'\n\n', 1)[0].splitlines()
        need([line[7:].decode('ascii') for line in headers if line.startswith(b'parent ')]
             == [CORE_PARENT], 'CORE_PARENT_CHANGED')
        need(self.package.git('diff-tree', '-r', '--no-commit-id', '--name-only', '--no-renames',
             '--no-ext-diff', CORE_PARENT, CORE).decode().splitlines()
             == ['desktop-pos/runtime/composer.lock'], 'CORE_DEPENDENCY_SCOPE_CHANGED')
        need(sha(self.package.file('desktop-pos/runtime/composer.lock', CORE)) == RUNTIME_LOCK_SHA,
             'RUNTIME_LOCK_CHANGED')
        verifier = self.old.root_api.load(self.package.file('deployment/verify_go_erp_preview31_seed.py', SEED),
            'audit_successor_seed_verifier', POLICY_VERIFIER_SHA)
        proof = verifier.validate_seed(self.package.repository, SEED, core=CORE)
        need(verifier.CORE == CORE and proof.get('seed') == SEED and proof.get('core') == CORE,
             'POLICY_SEED_CHANGED')
        for name in ('desktop-pos/package.json', 'desktop-pos/package-lock.json'):
            value = self.io.parse(self.package.file(name, SEED))
            need(value.get('version') == '0.4.4-preview.60', 'SEED_VERSION_BASELINE_CHANGED')
            if name.endswith('package-lock.json'):
                need(value.get('packages', {}).get('', {}).get('version') == '0.4.4-preview.60',
                     'SEED_LOCK_VERSION_CHANGED')
        need(self.package.git('diff-tree', '-r', '--no-commit-id', '--name-only', '--no-renames',
             '--no-ext-diff', self.old.WEB_RELEASE if hasattr(self.old, 'WEB_RELEASE') else self.modules['coordinator'].WEB_RELEASE,
             CORE, '--', *self.old.native.BACKEND) == b'', 'LIVE_BACKEND_CHANGED')

    def outer(self):
        self.old.root_api.verify_outer_locks(self.io)
        self.o.template.quiet(self.e)
        self.old.invariant()
        return True

    def _ci_failure(self):
        config = self.failed.worker.read_config()
        client = self.failed.worker.GitHub(config['token'])
        run = client.json('/repos/omarmakramelbaz-hub/F-Backend/actions/runs/' + str(FAILED_CI_RUN))
        self.failed.worker.run_identity(run, 'desktop-dashboard-tests.yml', self.h.FAILED_CANDIDATE)
        need(run.get('id') == FAILED_CI_RUN and run.get('run_attempt') == 1
             and run.get('status') == 'completed' and run.get('conclusion') == 'failure',
             'EXACT_FAILED_CI_CHANGED')
        jobs = client.pages('/repos/omarmakramelbaz-hub/F-Backend/actions/runs/'
            + str(FAILED_CI_RUN) + '/attempts/1/jobs', 'jobs')
        matching = [job for job in jobs if job.get('id') == FAILED_CI_JOB]
        need(len(matching) == 1 and matching[0].get('name') == 'original-runtime'
             and matching[0].get('conclusion') == 'failure' and matching[0].get('head_sha') == self.h.FAILED_CANDIDATE
             and [step.get('name') for step in matching[0].get('steps', []) if step.get('conclusion') == 'failure']
                 == ['Assemble and audit the installer runtime'], 'EXACT_AUDIT_FAILURE_CHANGED')
        artifacts = client.pages('/repos/omarmakramelbaz-hub/F-Backend/actions/runs/'
            + str(FAILED_CI_RUN) + '/artifacts', 'artifacts')
        need(not any(item.get('name') == self.failed.worker.ARTIFACT for item in artifacts),
             'FAILED_CANDIDATE_INSTALLER_PRESENT')
        self.outer()
        return {'run': FAILED_CI_RUN, 'attempt': 1, 'job': FAILED_CI_JOB,
                'candidate': self.h.FAILED_CANDIDATE, 'failed_step': 'Assemble and audit the installer runtime'}

    def reconstruct_failed(self, verify):
        self.old.correct_pending(verify)
        self.observation = self.old.prepare_source(verify)
        configured = self.old.configure(self.observation, self.outer, lambda *_args: None)
        self.failed = configured.Context(configured.Release(self.package.repository, self.package.revision), self.e)
        self.failed.job_record()
        self.failed_manual = self.old.manual_values(self.observation, self.h.FAILED_SEED)
        self.failed_values = copy.deepcopy(self.old.values)
        need(self.failed.payloads == self.failed_values
             and self.failed.runtime.name == self.o.utility.runtime_name(self.failed_values),
             'FAILED_NATIVE_DERIVATION_CHANGED')
        self.failed_job_path = self.failed.job_path
        self.failed_job_raw = self.read(self.failed_job_path)
        need(self.io.parse(self.failed_job_raw) == self.failed.job, 'FAILED_JOB_BYTES_CHANGED')
        previous_raw = self.read(Path(self.failed.job['audit']) / 'previous-state.json')
        previous = self.io.parse(previous_raw)
        # Genuine published60 acceptance is checked against its retained actual
        # state and receipts; failed61 is never passed to a published validator.
        self.failed.previous_proof()
        self.previous_pending = self.failed.previous_proof()[-1]
        need(self.read(Path(self.failed.job['audit']) / 'previous-pending-acceptance.json')
             == self.previous_pending, 'PUBLISHED60_PENDING_AUDIT_CHANGED')
        self.new_values = {name: self.modules['payloads'].rebind_seed(raw, self.h.FAILED_SEED, SEED)
                           if name in ('go_erp_live_source.py', 'go_erp_live_updates.py') else raw
                           for name, raw in self.failed_values.items()}
        self.new_native_pins = {name: (self.modules['payloads'].blob(raw), len(raw), sha(raw))
                               for name, raw in self.new_values.items()}
        self.new_runtime = self.o.template.NATIVE_RELEASES / self.o.utility.runtime_name(self.new_values)
        old_expected = self.failed.worker.program_gate_expected(self.h.FAILED_CORE,
            self.failed.job['source'], self.h.INVENTORY)
        new_expected = self.failed.worker.program_gate_expected(CORE, self.failed.job['source'], self.h.INVENTORY)
        self.pins = self.h.checked_pins({'core': CORE, 'seed': SEED,
            'old_runtime': self.failed.runtime.name, 'source': copy.deepcopy(self.failed.job['source']),
            'old_expected': old_expected, 'new_expected': new_expected,
            'previous_published': copy.deepcopy(previous['published'])})
        self.h.checked_failed_job(self.failed.job, self.pins)
        need(self.read(self.h.FAILED_COMPLETED, optional=True) is None, 'FAILED61_COMPLETED_PRESENT')
        verify()

    def baseline(self, verify):
        try:
            self.io.directory(AUDIT, 0, 0, private=True)
        except FileNotFoundError:
            saved = None
        else:
            saved = self.read(AUDIT / 'intent.json', optional=True)
        if saved is None:
            state_raw = self.read(WORK / 'state/current.json'); state = self.io.parse(state_raw)
            self.h.checked_failed_state(state, self.failed.publication(), self.pins, self.failed.job)
            need(self.read(self.h.HOLD) is not None
                 and self.io.parse(self.read(self.h.HOLD)) == self.h._hold(self.pins['old_expected'])
                 and self.read(self.h.PENDING) == self.previous_pending, 'FAILED_HOLD_OR_PENDING_CHANGED')
            failure = self._ci_failure()
            self.io.private_directory(AUDIT, verify)
            for name, raw in (('failed-state.bin', state_raw), ('failed-job.bin', self.failed_job_raw),
                              ('previous-pending.bin', self.previous_pending)):
                self.record(name, raw, verify)
            intent = {'format': 1, 'kind': 'go-erp-audit-failed61-successor-intent',
                'core': CORE, 'seed': SEED, 'failed_core': self.h.FAILED_CORE,
                'failed_candidate': self.h.FAILED_CANDIDATE, 'pins_sha256': sha(canonical(self.pins)),
                'state_sha256': sha(state_raw), 'job_sha256': sha(self.failed_job_raw),
                'pending_sha256': sha(self.previous_pending), 'failed_ci': failure,
                'consumed_counter': 61, 'next_counter': 62, 'old_records_unchanged': True}
            self.record('intent.json', intent, verify)
            saved = self.read(AUDIT / 'intent.json')
        intent = self.io.parse(saved)
        state_raw = self.read(AUDIT / 'failed-state.bin')
        expected = {'format': 1, 'kind': 'go-erp-audit-failed61-successor-intent',
            'core': CORE, 'seed': SEED, 'failed_core': self.h.FAILED_CORE,
            'failed_candidate': self.h.FAILED_CANDIDATE, 'pins_sha256': sha(canonical(self.pins)),
            'state_sha256': sha(state_raw), 'job_sha256': sha(self.failed_job_raw),
            'pending_sha256': sha(self.previous_pending), 'failed_ci': {
                'run': FAILED_CI_RUN, 'attempt': 1, 'job': FAILED_CI_JOB,
                'candidate': self.h.FAILED_CANDIDATE, 'failed_step': 'Assemble and audit the installer runtime'},
            'consumed_counter': 61, 'next_counter': 62, 'old_records_unchanged': True}
        need(intent == expected and self.read(AUDIT / 'failed-job.bin') == self.failed_job_raw
             and self.read(AUDIT / 'previous-pending.bin') == self.previous_pending,
             'SUCCESSOR_INTENT_CHANGED')
        self.h.checked_failed_state(self.io.parse(state_raw), None, self.pins, self.failed.job)
        self.proof = {'failed_job': copy.deepcopy(self.failed.job), 'failed_job_raw': self.failed_job_raw,
            'failed_state_raw': state_raw, 'previous_pending_raw': self.previous_pending,
            'old_hold_unauthorized': True}
        verify()

    def verify_baseline(self, context, state, publication):
        self.outer()
        need(self.proof is not None and self.read(self.failed_job_path) == self.failed_job_raw
             and self.read(self.h.FAILED_COMPLETED, optional=True) is None, 'FAILED_RECORDS_CHANGED')
        self.failed.previous_proof()
        if state is not None:
            self.h.checked_failed_state(state, publication, self.pins, self.failed.job)
            need(state == self.io.parse(self.proof['failed_state_raw']), 'MIGRATION_BASELINE_CHANGED')
        if context is not None and context.job is not None:
            original = self.read(Path(context.job['audit']) / 'previous-state.json')
            need(self.io.parse(original) == self.io.parse(self.proof['failed_state_raw']),
                 'SUCCESSOR_PREVIOUS_STATE_CHANGED')
        self.outer()
        return copy.deepcopy(self.proof)

    def transition_hold(self, context):
        def validate(stage):
            self.verify_baseline(context, None, None)
            if stage in ('fresh', 'replace', 'replaced'):
                need(self.io.parse(self.read(WORK / 'state/current.json'))
                     == self.io.parse(self.proof['failed_state_raw']), 'HOLD_BASELINE_STATE_CHANGED')
            return True
        return self.h.replace_hold(context, self.io, HOLD_AUDIT, self.outer, validate,
            self.pins, self.previous_pending)

    def configure(self):
        template = self.old.root_api.load(self.package.file('deployment/upgrade_go_erp_warm_start_root.py',
            self.old.native.TEMPLATE_RELEASE), 'audit_successor_original', self.old.native.TEMPLATE_SHA256)
        tick_raw = self.failed_manual['go_erp_manual_tick_root.py']
        tick = template.module(tick_raw, 'audit_successor_previous_tick', sha(tick_raw))
        previous = tick.NEW_COHORT
        need(previous['seed'] == self.h.FAILED_SEED and previous['runtime'] == self.failed.runtime
             and previous['pins'] == self.new_cohort_pins(self.failed_values), 'FAILED_MANUAL_COHORT_CHANGED')
        cohort = {'seed': SEED, 'policy': self.h.POLICY, 'runtime': self.new_runtime,
            'unit_sha256': sha(self.o.utility.service_unit(self.new_runtime)), 'pins': self.new_native_pins}
        manual = self.o.modules['payloads'].manual(
            self.package.file('deployment/go_erp_owner_fast_open_tick_root.py', self.old.root_api.LEGACY_RELEASE),
            self.package.file('deployment/go_erp_owner_fast_open_manual_root.py', self.old.root_api.LEGACY_RELEASE),
            previous=previous, new=cohort)
        manual['go_erp_published_download_root.py'] = self.failed_manual['go_erp_published_download_root.py']
        paths = {'go_erp_manual_tick_root.py': 'deployment/go_erp_shared_workspace_tick_root.py',
            'go_erp_manual_updates_root.py': 'deployment/go_erp_shared_workspace_manual_root.py',
            'go_erp_published_download_root.py': 'deployment/go_erp_published_download_root.py'}
        for key, value in {'OLD_TICK_SHA': sha(tick_raw), 'PREVIOUS': ROOT_RELEASE,
            'OLD_CORE': self.h.FAILED_CORE, 'OLD_SEED': self.h.FAILED_SEED,
            'OLD_RUNTIME': self.failed.runtime.name, 'OLD_POLICY': self.h.POLICY,
            'OLD_SUFFIX': self.h.POLICY.removeprefix('historical-baseline-v2').encode(),
            'CORE': CORE, 'SEED': SEED, 'POLICY': self.h.POLICY,
            'SUFFIX': self.h.POLICY.removeprefix('historical-baseline-v2').encode(),
            'INPUT_CANDIDATE': self.h.FAILED_CANDIDATE, 'INPUT_VERSION': '0.4.4-preview.61',
            'OLD_MANUAL_PINS': {name: sha(raw) for name, raw in self.failed_manual.items()},
            'MANUAL_PINS': {name: sha(raw) for name, raw in manual.items()},
            'PACKAGE_PROGRAM_PATHS': paths}.items():
            setattr(template, key, value)
        original_release = template.Release
        package = self.package
        class Release(original_release):
            def file(self, name, revision=None):
                if revision == ROOT_RELEASE and name == 'deployment/go_erp_startup_session_tick_root.py':
                    return tick_raw
                for item, path in paths.items():
                    if name == path:
                        return manual[item]
                return package.file(name, revision)
        template.Release = Release
        def checked_core(_release):
            self.outer(); self._review_seed()
        template.checked_native_core = checked_core
        derive = template.derive_native_payloads
        def exact_derive(payloads, pins, seed):
            need(payloads == self.failed_values and pins == self.new_cohort_pins(self.failed_values)
                 and seed == SEED, 'FAILED_NATIVE_INPUT_CHANGED')
            values = derive(payloads, pins, seed)
            need(values == self.new_values, 'SUCCESSOR_NATIVE_DERIVATION_CHANGED')
            return values
        template.derive_native_payloads = exact_derive
        def accept(context, desired):
            return self.holder['acceptance'](desired['commit'], desired['version'],
                self.old.acceptance_pins['contract_sha256'])
        bound = self.h.bind_general_context(template, self.pins, self.verify_baseline,
            self.transition_hold, accept, self.outer)
        owner = self
        class Context(bound):
            def __init__(self, release, engine):
                super().__init__(release, engine)
                need((self.owner.pw_uid, self.owner.pw_gid) == (1001, 1003)
                     and self.payloads == owner.new_values and self.runtime == owner.new_runtime,
                     'SUCCESSOR_CONTEXT_BINDING_CHANGED')
                self.approved_worker_sha256 = sha(owner.new_values['go_erp_live_updates.py'])
                owner.holder['acceptance'] = owner.o.modules['acceptance'].WorkspaceAcceptance(
                    owner.io, self, AUDIT, owner.outer, pins=owner.old.acceptance_pins)
        template.Context = Context
        self.template = template
        return template

    def new_cohort_pins(self, values):
        return {name: (self.modules['payloads'].blob(raw), len(raw), sha(raw)) for name, raw in values.items()}

    def run(self):
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_HOST_REQUIRED')
        with self.o.held() as verify:
            self.reconstruct_failed(verify)
            self.baseline(verify)
            template = self.configure()
        print('GO_ERP_DESKTOP_AUDIT_SUCCESSOR_EXACT_FAILED61_VERIFIED', flush=True)
        result = template.execute(template.Release(self.package.repository, ROOT_RELEASE), self.e, True)
        need(result.get('ok') is True and result.get('published') is True and result.get('version') == VERSION,
             'ORIGINAL_PUBLICATION_NOT_READY')
        with self.o.held() as verify:
            context = template.Context(template.Release(self.package.repository, ROOT_RELEASE), self.e)
            context.job_record(); context.live(context.state())
            need(context.ready(context.state()), 'ORIGINAL_PROGRAM_GATE_NOT_COMPLETE')
            desired = context.candidate(context.state())
            proof = self.holder['acceptance'](desired['commit'], desired['version'],
                self.old.acceptance_pins['contract_sha256'])
            need(proof.get('installed_asar_accepted') is True
                 and proof.get('original_program_gate_accepted') is True
                 and proof.get('employee_acceptance_accepted') is True, 'FINAL_ACCEPTANCE_NOT_COMPLETE')
            self.old.invariant()
            self.io.private_record(AUDIT / 'published.json', result, verify)
        self.e.run(['/usr/bin/systemctl', 'start', self.o.template.WATCH])
        need(self.e.run(['/usr/bin/systemctl', 'show', self.o.template.WATCH, '-p', 'ActiveState', '--value'])
             == 'active', 'PUBLISHED_MANUAL_PATH_ACTIVATION_REQUIRED')
        self.o.template.quiet(self.e)
        return {'format': 1, 'kind': 'go-erp-desktop-performance-rollout', 'ok': True, 'published': True,
            'version': VERSION, 'candidate_revision': result['candidate_revision'], 'workspace_enabled': True,
            'manual_only': True, 'automatic_updates_enabled': False, 'database_changed': False,
            'financial_data_changed': False, 'credentials_disclosed': False}


def run(package, operator, modules):
    return Successor(package, operator, modules).run()


if __name__ == '__main__':
    raise SystemExit('GO_ERP_DESKTOP_AUDIT_SUCCESSOR_USE_PINNED_LOADER')
