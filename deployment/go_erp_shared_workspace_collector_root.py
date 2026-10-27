#!/usr/bin/env python3
"""Bind the original root collector to one immutable workspace candidate.

The final loader supplies the unchanged hash-verified original Context/worker.
This adapter adds identity/checkpoint constraints, not another publisher. Native
prepare remains the original one-allocation transaction and every resume calls
the original same-candidate CI/authorization/installer/program gate functions.
"""
import copy
import re

PINS = None
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
SHA = re.compile(r'[a-f0-9]{64}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
SAFE = 9007199254740991
WORKFLOWS = ('desktop-dashboard-tests.yml', 'go-erp-update-tests.yml', 'whatsapp-integrated-regressions.yml')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_COLLECTOR_' + code)


class OriginalCollector:
    def __init__(self, io, context, private_journal, verify_locks, workspace_acceptance,
            *, pins=None):
        self.io, self.context, self.journal = io, context, private_journal
        self.verify_locks, self.workspace_acceptance = verify_locks, workspace_acceptance
        self.pins = PINS if pins is None else copy.deepcopy(pins)
        self.original_authorized = context.authorized

    def verify(self):
        pins = self.pins
        need(isinstance(pins, dict) and set(pins) == {'core', 'seed', 'policy', 'inventory_sha256',
                'predecessor', 'worker_sha256', 'context_sha256', 'workspace_contract_sha256'}
            and all(isinstance(pins[key], str) and REV.fullmatch(pins[key]) for key in ('core', 'seed'))
            and all(isinstance(pins[key], str) and SHA.fullmatch(pins[key]) for key in
                ('inventory_sha256', 'worker_sha256', 'context_sha256', 'workspace_contract_sha256'))
            and pins['policy'] == POLICY, 'FINAL_PINS_UNAVAILABLE')
        previous = pins['predecessor']
        need(isinstance(previous, dict) and set(previous) == {'counter', 'candidate', 'version',
                'inventory_sha256', 'release_sha256', 'acceptance_receipt_sha256'}
            and type(previous['counter']) is int and previous['counter'] in (59, 60)
            and previous['version'] == '0.4.4-preview.' + str(previous['counter'])
            and isinstance(previous['candidate'], str) and REV.fullmatch(previous['candidate'])
            and all(isinstance(previous[key], str) and SHA.fullmatch(previous[key])
                for key in ('inventory_sha256', 'release_sha256', 'acceptance_receipt_sha256')),
            'PREDECESSOR_PIN_INVALID')
        need(callable(self.verify_locks) and self.verify_locks() is not False
            and callable(self.workspace_acceptance), 'LOCK_OR_WORKSPACE_VERIFIER_REQUIRED')
        # Loader attests actual loaded module bytes before constructing Context;
        # these immutable attestations must stay bound on every original call.
        need(getattr(self.context, 'approved_worker_sha256', None) == pins['worker_sha256']
            and getattr(self.context, 'approved_context_sha256', None) == pins['context_sha256'],
            'ORIGINAL_CODE_BINDING_CHANGED')

    def observe(self):
        self.verify()
        state = self.context.state()
        publication = self.context.publication()
        need(isinstance(state, dict) and type(state.get('counter')) is int and state['counter'] >= self.pins['predecessor']['counter'],
            'STATE_COUNTER_INVALID')
        desired, published = state.get('desired'), state.get('published')
        need(isinstance(desired, dict) and isinstance(published, dict)
            and published.get('release') == publication
            and publication in state.get('published_history', []), 'PUBLICATION_HISTORY_CHANGED')
        return state, desired, publication

    def _latched(self, job, state, desired):
        previous = self.pins['predecessor']
        need(isinstance(job, dict) and job.get('format') == 1 and type(job.get('format')) is int
            and job.get('kind') == 'go-erp-shared-workspace-one-candidate'
            and job.get('core') == self.pins['core'] and job.get('seed') == self.pins['seed']
            and job.get('inventory_sha256') == self.pins['inventory_sha256']
            and job.get('predecessor') == previous
            and type(job.get('prepare_started')) is bool and type(job.get('attempts')) is int
            and 1 <= job['attempts'] <= 3, 'COLLECTOR_JOB_CHANGED')
        if job.get('candidate') is not None:
            need(desired.get('commit') == job['candidate'] == state.get('last_remote_sha')
                and desired.get('version') == job.get('version') == '0.4.4-preview.' + str(previous['counter'] + 1)
                and state.get('counter') == previous['counter'] + 1
                and desired.get('inventorySha256') == self.pins['inventory_sha256']
                and desired.get('candidatePolicy') == POLICY and desired.get('pushed') is True,
                'SAME_CANDIDATE_BINDING_CHANGED')

    def _original_job(self):
        job = self.context.job
        need(isinstance(job, dict) and job.get('core') == self.pins['core']
            and job.get('seed') == self.pins['seed'] and job.get('inventory') == self.pins['inventory_sha256'],
            'ORIGINAL_JOB_BINDING_CHANGED')
        return job

    def _save_original_baseline(self, state):
        job = self._original_job()
        need(job.get('candidate') is None, 'ORIGINAL_CANDIDATE_ALREADY_LATCHED')
        job.update(prepare_started=True, baseline_counter=state['counter'],
            baseline_candidate=state['desired']['commit'], baseline_remote=state['last_remote_sha'], phase='running')
        self.context.save()

    def _save_original_candidate(self, job):
        original = self._original_job()
        need(original.get('candidate') in (None, job['candidate'])
            and original.get('version') in (None, job['version']), 'ORIGINAL_CANDIDATE_CHANGED')
        original.update(candidate=job['candidate'], version=job['version'], phase='running')
        self.context.save()

    def prepare_once(self):
        """First observe; never authorize prepare over an existing old reservation."""
        state, desired, publication = self.observe()
        previous = self.pins['predecessor']
        job = self.journal.load()
        if job is None:
            need(state['counter'] == previous['counter'] and desired.get('status') == 'published'
                and desired.get('commit') == previous['candidate'] == state.get('last_remote_sha')
                and desired.get('version') == previous['version']
                and desired.get('inventorySha256') == previous['inventory_sha256']
                and self.io.sha(self.io.canonical(publication)) == previous['release_sha256'],
                'EXISTING_RESERVATION_RESUME_REQUIRED')
            # The original Context must prove the retained program/employee
            # acceptance and installer before any allocation permit is saved.
            previous_proof = self.context.previous_proof()
            need(self.io.sha(previous_proof[-1]) == previous['acceptance_receipt_sha256'],
                'ORIGINAL_PREDECESSOR_ACCEPTANCE_CHANGED')
            job = {'format': 1, 'kind': 'go-erp-shared-workspace-one-candidate',
                'core': self.pins['core'], 'seed': self.pins['seed'],
                'inventory_sha256': self.pins['inventory_sha256'], 'predecessor': copy.deepcopy(previous),
                'prepare_started': True, 'candidate': None, 'version': None, 'attempts': 1}
            need(self.journal.commit(None, job) == self.io.sha(self.io.canonical(job)), 'JOURNAL_ACK_REQUIRED')
            self._save_original_baseline(state)
            allowed = True
        else:
            self._latched(job, state, desired)
            # A child may have consumed next_counter before its parent latched
            # the result. Recognize only the reviewed new policy/inventory.
            if job['candidate'] is None:
                consumed = (state['counter'] == previous['counter'] + 1
                    and desired.get('candidatePolicy') == POLICY and desired.get('inventorySha256') == self.pins['inventory_sha256']
                    and desired.get('pushed') is True and desired.get('commit') == state.get('last_remote_sha'))
                if consumed:
                    allowed = False
                else:
                    original = self._original_job()
                    need(job['prepare_started'] is True and job['attempts'] < 3
                        and state['counter'] == previous['counter'] and desired.get('status') == 'published'
                        and desired.get('commit') == previous['candidate'] == state.get('last_remote_sha')
                        and original.get('prepare_started') is True and original.get('baseline_counter') == state['counter']
                        and original.get('baseline_candidate') == desired['commit']
                        and original.get('baseline_remote') == state['last_remote_sha'], 'ALLOCATION_OUTCOME_REQUIRES_REVIEW')
                    self.context.live(state)
                    after = {**job, 'attempts': job['attempts'] + 1}
                    need(self.journal.commit(self.io.sha(self.io.canonical(job)), after)
                        == self.io.sha(self.io.canonical(after)), 'JOURNAL_ACK_REQUIRED')
                    job = after; allowed = True
            else:
                allowed = False
        if allowed:
            # The original worker owns counter consumption, build creation,
            # branch lease, source inventory and every publication hold.
            self.context.tick(True)
            state, desired, _ = self.observe()
        if job['candidate'] is None:
            need(state['counter'] == previous['counter'] + 1
                and desired.get('candidatePolicy') == POLICY
                and desired.get('inventorySha256') == self.pins['inventory_sha256']
                and desired.get('version') == '0.4.4-preview.' + str(previous['counter'] + 1)
                and isinstance(desired.get('commit'), str) and REV.fullmatch(desired['commit'])
                and desired.get('commit') == state.get('last_remote_sha') and desired.get('pushed') is True,
                'ALLOCATION_RESULT_UNREVIEWED')
            after = {**job, 'candidate': desired['commit'], 'version': desired['version']}
            need(self.journal.commit(self.io.sha(self.io.canonical(job)), after)
                == self.io.sha(self.io.canonical(after)), 'JOURNAL_ACK_REQUIRED')
            job = after
        self._latched(job, state, desired)
        self._save_original_candidate(job)
        return copy.deepcopy(job)

    def resume(self):
        state, desired, _ = self.observe()
        job = self.journal.load(); self._latched(job, state, desired)
        need(job['candidate'] is not None, 'CANDIDATE_NOT_LATCHED')
        self.context.live(state)
        self.context.candidate(state)
        # Do not reset blocked/retry-build counters. Original CI review must
        # approve the latest same-candidate runs before its status can change.
        need(desired.get('status') not in ('blocked', 'retry-build'), 'ORIGINAL_CI_REVIEW_REQUIRED')
        self.context.tick(False)
        return copy.deepcopy(job)

    def authorize(self):
        state, desired, _ = self.observe(); job = self.journal.load(); self._latched(job, state, desired)
        need(job['candidate'] is not None, 'CANDIDATE_NOT_LATCHED')
        self.context.live(state); self.context.candidate(state)
        proof = self.workspace_acceptance(job['candidate'], job['version'], self.pins['workspace_contract_sha256'])
        need(isinstance(proof, dict) and proof.get('candidate_revision') == job['candidate']
            and proof.get('version') == job['version'] and proof.get('contract_sha256') == self.pins['workspace_contract_sha256']
            and all(proof.get(key) is True for key in ('latest_required_ci_accepted', 'full_native_lifecycle_accepted')),
            'WORKSPACE_ACCEPTANCE_REQUIRED')
        # Calls the unchanged verifier, retained receipt authorization and
        # original program gate; it does not manufacture an installer receipt.
        self.original_authorized()
        return copy.deepcopy(job)
