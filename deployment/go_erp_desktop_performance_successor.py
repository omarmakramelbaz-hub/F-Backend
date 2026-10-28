#!/usr/bin/env python3
"""Narrow failed-preview.61 successor guards; no standalone deployment entry.

This helper never claims that preview.61 was published.  A caller must first
verify the genuine preview.60 receipts through the retained original verifier.
The unchanged general native transaction migrates the seed; the unchanged
worker preserves counter 61 and naturally allocates 62.  This module owns only
an audited replacement of the exact, unauthorized failed-preview.61 hold.
"""
import copy
import hashlib
import json
import math
from pathlib import Path
import re
import time

FAILED_CORE = 'b5503e21e3af32eecaf0d2f9d68eee7d21be7439'
FAILED_SEED = 'd15942c947ac31c21a90b53856084a5de35d3cc3'
FAILED_CANDIDATE = '766f9d2d101fd639ffea8905e5b309f03eab8759'
PREVIOUS_CANDIDATE = 'd1976ce02c553939032241e7e724d26a9bbe7190'
NEW_CORE = '22f724b1ec75e8326e20305df40b91fe5640a7a7'
NEW_SEED = '6a9afe74203995a36416373528de5a29097fee83'
INVENTORY = '531ba5ab285bafc999930a78b9212640cf3c13fe83365da260ace931b49964b7'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
WORK = Path('/var/lib/go-erp-live-updates')
HOLD = WORK / 'program-rollout-hold.json'
PENDING = WORK / 'pending-program-acceptance.json'
FAILED_COMPLETED = WORK / ('program-rollout-completed-' + FAILED_CORE + '.json')
REV = re.compile(r'[a-f0-9]{40}\Z')
SAFE = 9007199254740991


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_DESKTOP_AUDIT_SUCCESSOR_' + code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def checked_pins(pins):
    """Pin reviewed code and two original-worker-generated gate dictionaries."""
    need(isinstance(pins, dict) and set(pins) == {
        'core', 'seed', 'old_runtime', 'source', 'old_expected', 'new_expected',
        'previous_published'}, 'PIN_ROSTER_CHANGED')
    need(pins['core'] == NEW_CORE and pins['seed'] == NEW_SEED
         and all(isinstance(pins[key], str) and REV.fullmatch(pins[key])
             and pins[key] not in ('0' * 40, FAILED_CORE, FAILED_SEED, FAILED_CANDIDATE)
             for key in ('core', 'seed')) and pins['core'] != pins['seed'], 'NEW_CODE_PINS_INVALID')
    need(isinstance(pins['old_runtime'], str)
         and re.fullmatch(r'policy-[a-f0-9]{64}', pins['old_runtime']), 'OLD_RUNTIME_INVALID')
    need(isinstance(pins['source'], dict), 'SOURCE_INVALID')
    old, new = pins['old_expected'], pins['new_expected']
    need(isinstance(old, dict) and isinstance(new, dict) and bool(old)
         and set(old) == set(new) and not {'format', 'kind', 'authorization'} & set(old), 'GATE_ROSTER_CHANGED')
    delta = [key for key in old if canonical(old[key]) != canonical(new[key])]
    need(len(delta) == 1 and old[delta[0]] == FAILED_CORE and new[delta[0]] == pins['core'],
         'GATE_SCOPE_CHANGED')
    previous = pins['previous_published']
    need(isinstance(previous, dict) and previous.get('commit') == PREVIOUS_CANDIDATE
         and isinstance(previous.get('release'), dict)
         and previous['release'].get('source_revision') == PREVIOUS_CANDIDATE
         and previous['release'].get('version') == '0.4.4-preview.60', 'PUBLISHED_60_BINDING_CHANGED')
    canonical(pins)
    return copy.deepcopy(pins)


def checked_failed_job(job, pins):
    pins = checked_pins(pins)
    need(isinstance(job, dict) and type(job.get('format')) is int and job['format'] == 1
         and job.get('kind') == 'go-erp-warm-start-rollout'
         and job.get('core') == FAILED_CORE and job.get('seed') == FAILED_SEED
         and job.get('runtime') == pins['old_runtime'] and job.get('phase') == 'running'
         and job.get('prepare_started') is True and job.get('baseline_counter') == 60
         and job.get('baseline_candidate') == job.get('baseline_remote') == job.get('remote_lease')
             == PREVIOUS_CANDIDATE
         and job.get('candidate') == FAILED_CANDIDATE and job.get('version') == '0.4.4-preview.61'
         and job.get('inventory') == INVENTORY and job.get('source') == pins['source'],
         'FAILED_JOB_BINDING_CHANGED')
    return job


def checked_failed_state(state, publication, pins, failed_job):
    """A consumed failed61 baseline, with genuine published60 kept separate."""
    pins = checked_pins(pins)
    checked_failed_job(failed_job, pins)
    need(isinstance(state, dict) and type(state.get('format')) is int and state['format'] == 1
         and state.get('base_revision') == FAILED_SEED and type(state.get('counter')) is int
         and state['counter'] == 61 and state.get('last_remote_sha') == FAILED_CANDIDATE,
         'FAILED_STATE_IDENTITY_CHANGED')
    desired = state.get('desired')
    need(isinstance(desired, dict) and desired.get('commit') == FAILED_CANDIDATE
         and desired.get('version') == '0.4.4-preview.61' and desired.get('pushed') is True
         and desired.get('candidatePolicy') == POLICY and desired.get('inventorySha256') == INVENTORY
         and desired.get('status') == 'retry-build' and type(desired.get('failures')) is int
         and 1 <= desired['failures'] < 3 and type(desired.get('retry_at')) in (int, float)
         and math.isfinite(desired['retry_at']) and 0 <= desired['retry_at'] <= SAFE,
         'FAILED_CANDIDATE_CHANGED')
    previous = pins['previous_published']; release = previous['release']
    need(state.get('published') == previous and state.get('version_floor') == '0.4.4-preview.60'
         and type(state.get('sequence_floor')) is int and state['sequence_floor'] == release.get('sequence')
         and isinstance(state.get('published_history'), list) and release in state['published_history']
         and (publication is None or publication == release), 'PUBLISHED_60_HISTORY_CHANGED')
    for item in state['published_history']:
        match = re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)', item.get('version', '') if isinstance(item, dict) else '')
        need(match is not None and int(match[1]) <= 60, 'LATER_PUBLICATION_PRESENT')
    observed = state.get('observed')
    need(isinstance(observed, dict) and observed.get('seedRevision') == FAILED_SEED
         and observed.get('inventorySha256') == INVENTORY, 'OBSERVED_SOURCE_CHANGED')
    return desired


def _hold(expected, authorization=None):
    return {'format': 1, 'kind': 'go-erp-program-rollout-hold', **expected, 'authorization': authorization}


def _read(context, path, optional=False):
    value = context.e.read_file(path, 16 * 1024**2, uid=0, modes=(0o600,), optional=optional)
    return None if value is None else value[0]


def replace_hold(context, io, audit, verify, validate, pins, previous_pending_raw):
    """Replace only the unauthenticated old hold, with an immutable resume WAL.

    ``validate(stage)`` is mandatory and runs under original locks.  The caller
    rechecks the genuine published60 proof, exact old records, failed CI, no61
    authorization/publication and the new transaction's progress for each stage.
    A completed transition never restores old data after seed/counter progress.
    """
    pins = checked_pins(pins)
    need(callable(verify) and callable(validate) and isinstance(previous_pending_raw, bytes),
         'TRANSITION_VERIFIERS_REQUIRED')
    audit = Path(audit)
    need(audit.is_absolute() and audit.parent == Path('/var/lib/go-erp-manual-updates/private/audits')
         and audit.name == 'desktop-audit-hold-' + pins['core'], 'HOLD_AUDIT_PATH_CHANGED')

    def guard(stage):
        need(verify() is not False, 'HELD_LOCK_CHANGED')
        need(_read(context, FAILED_COMPLETED, optional=True) is None, 'FAILED61_COMPLETED_PRESENT')
        need(validate(stage) is not False, 'BASELINE_REVALIDATION_FAILED')

    guard('read')
    # The frozen file reader's optional flag covers a missing leaf only; it
    # deliberately raises when any ancestor is absent.  Validate an existing
    # private audit directory before reading, without creating it prematurely.
    try:
        io.directory(audit, 0, 0, private=True)
    except FileNotFoundError:
        intent_raw = None
    else:
        intent_raw = _read(context, audit / 'intent.json', optional=True)
    if intent_raw is None:
        guard('fresh')
        old_raw = _read(context, HOLD)
        need(context.base.parse(old_raw) == _hold(pins['old_expected']), 'OLD_HOLD_AUTHORIZED_OR_CHANGED')
        need(_read(context, PENDING) == previous_pending_raw, 'PENDING_ACCEPTANCE_CHANGED')
        new_raw = context.e.canonical(_hold(pins['new_expected']))
        io.private_directory(audit, verify)
        for name, raw in (('hold-before.bin', old_raw), ('hold-after.bin', new_raw),
                          ('pending-before.bin', previous_pending_raw)):
            io.private_record(audit / name, raw, verify)
        intent = {'format': 1, 'kind': 'go-erp-failed61-hold-successor-intent',
                  'from_core': FAILED_CORE, 'to_core': pins['core'], 'to_seed': pins['seed'],
                  'pins_sha256': sha(canonical(pins)), 'before_sha256': sha(old_raw),
                  'after_sha256': sha(new_raw), 'pending_sha256': sha(previous_pending_raw),
                  'counter_allocation_allowed': False, 'old_records_unchanged': True}
        io.private_record(audit / 'intent.json', intent, verify)
        intent_raw = _read(context, audit / 'intent.json')
    intent = context.base.parse(intent_raw)
    old_raw, new_raw, pending_raw = [_read(context, audit / name) for name in
                                    ('hold-before.bin', 'hold-after.bin', 'pending-before.bin')]
    expected_intent = {'format': 1, 'kind': 'go-erp-failed61-hold-successor-intent',
                       'from_core': FAILED_CORE, 'to_core': pins['core'], 'to_seed': pins['seed'],
                       'pins_sha256': sha(canonical(pins)), 'before_sha256': sha(old_raw),
                       'after_sha256': sha(new_raw), 'pending_sha256': sha(pending_raw),
                       'counter_allocation_allowed': False, 'old_records_unchanged': True}
    need(intent == expected_intent and pending_raw == previous_pending_raw
         and context.base.parse(old_raw) == _hold(pins['old_expected'])
         and new_raw == context.e.canonical(_hold(pins['new_expected'])), 'HOLD_INTENT_CHANGED')
    completion = {'format': 1, 'kind': 'go-erp-failed61-hold-successor-completed',
                  'intent_sha256': sha(intent_raw), 'after_sha256': sha(new_raw)}
    completed = _read(context, audit / 'completed.json', optional=True)
    if completed is not None:
        need(context.base.parse(completed) == completion, 'HOLD_COMPLETION_CHANGED')
        guard('completed')
        active_raw = _read(context, HOLD, optional=True)
        if active_raw is None:
            need(context.ready(context.state()), 'RETIRED_HOLD_WITHOUT_COMPLETED_GATE')
        else:
            active = context.base.parse(active_raw)
            need(isinstance(active, dict) and set(active) == set(_hold(pins['new_expected']))
                 and {key: value for key, value in active.items() if key != 'authorization'}
                     == {key: value for key, value in _hold(pins['new_expected']).items() if key != 'authorization'},
                 'COMPLETED_HOLD_FOREIGN')
            if active.get('authorization') is not None:
                # The original verifier must validate this successor receipt;
                # this helper has no authority to accept or erase it.
                context.authorized()
        return False
    guard('replace')
    need(_read(context, PENDING) == pending_raw, 'PENDING_ACCEPTANCE_CHANGED')
    active_raw = _read(context, HOLD)
    need(active_raw in (old_raw, new_raw), 'HOLD_CHANGED_DURING_TRANSITION')
    if active_raw == old_raw:
        context.e.atomic_write(HOLD, new_raw, 0, 0, 0o600)
    guard('replaced')
    need(_read(context, HOLD) == new_raw and _read(context, PENDING) == pending_raw,
         'HOLD_REPLACEMENT_NOT_DURABLE')
    io.private_record(audit / 'completed.json', completion, verify)
    return True


def bind_general_context(template, pins, verify_baseline, transition_hold, workspace_acceptance,
                         verify_outer):
    """Bind explicit failed61 evidence to the frozen *general* Context.

    The caller supplies the exact configured original template and leaves its
    allocator, migration, source/candidate verifier, worker and publisher intact.
    This function does not configure modules, instantiate Context or run effects.
    """
    pins = checked_pins(pins)
    need(all(callable(value) for value in (verify_baseline, transition_hold, workspace_acceptance, verify_outer)),
         'CONTEXT_VERIFIERS_REQUIRED')
    need(template.CORE == pins['core'] and template.SEED == pins['seed']
         and template.OLD_CORE == FAILED_CORE and template.OLD_SEED == FAILED_SEED,
         'GENERAL_TEMPLATE_BINDING_CHANGED')
    original_context = template.Context

    def checked_baseline(state, publication):
        proof = verify_baseline(None, state, publication)
        need(isinstance(proof, dict) and isinstance(proof.get('failed_job'), dict)
             and isinstance(proof.get('failed_job_raw'), bytes), 'BASELINE_PROOF_REQUIRED')
        checked_failed_state(state, publication, pins, proof['failed_job'])
        return state['desired']
    template.checked_published_state = checked_baseline

    class Context(original_context):
        def live(self, state):
            # Ancestor entry activity can invalidate a stable code scan.  Every
            # attempt still executes the full original control/runtime/source
            # validation; a failed scan never supplies publication authority.
            for attempt in range(3):
                need(verify_outer() is not False, 'HELD_LOCK_CHANGED')
                try:
                    result = super().live(state)
                except Exception as error:
                    need(verify_outer() is not False, 'HELD_LOCK_CHANGED')
                    if str(error) != 'GO_ERP_LIVE_SOURCE_ANCESTRY_CHANGED' or attempt == 2:
                        raise
                    time.sleep(0.25 * (attempt + 1))
                else:
                    need(verify_outer() is not False, 'HELD_LOCK_CHANGED')
                    return result

        def verify_predecessor(self):
            proof = verify_baseline(self, None, None)
            need(isinstance(proof, dict) and isinstance(proof.get('failed_job_raw'), bytes)
                 and isinstance(proof.get('failed_job'), dict), 'PREDECESSOR_PROOF_REQUIRED')
            checked_failed_job(proof['failed_job'], pins)
            need(self.base.parse(proof['failed_job_raw']) == proof['failed_job'], 'PREDECESSOR_BYTES_CHANGED')
            return proof['failed_job_raw']

        def quiet_hold(self):
            proof = verify_baseline(self, None, None)
            need(isinstance(proof, dict) and proof.get('old_hold_unauthorized') is True,
                 'UNAUTHORIZED_OLD_HOLD_PROOF_REQUIRED')
            need(self.prior_hold(optional=True)[1] == _hold(pins['old_expected']),
                 'OLD_HOLD_AUTHORIZED_OR_CHANGED')

        def job_record(self):
            super().job_record()
            proof = verify_baseline(self, None, None)
            previous = proof.get('previous_pending_raw') if isinstance(proof, dict) else None
            need(isinstance(previous, bytes), 'PREVIOUS_PENDING_PROOF_REQUIRED')
            path = Path(self.job['audit']) / 'previous-pending-acceptance.json'
            existing = _read(self, path, optional=True)
            if existing is None:
                self.e.atomic_write(path, previous, 0, 0, 0o600)
            else:
                need(existing == previous, 'PREVIOUS_PENDING_AUDIT_CHANGED')

        def prepare(self, locked_utility):
            need(self.job is not None and self.expected == pins['new_expected'], 'NEW_JOB_REQUIRED_BEFORE_HOLD')
            transition_hold(self)
            return super().prepare(locked_utility)

        def candidate(self, state):
            need(type(state.get('counter')) is int and state['counter'] == 62
                 and (state.get('desired') or {}).get('version') == '0.4.4-preview.62',
                 'ONE_SUCCESSOR_COUNTER_REQUIRED')
            return super().candidate(state)

        def tick(self, allow, budget=2700):
            need(type(allow) is bool, 'TICK_SCOPE_INVALID')
            state = self.state()
            if allow:
                need(state.get('counter') == 61 and template.allocation_unconsumed(self.job, state)
                     and (state.get('desired') or {}).get('commit') == FAILED_CANDIDATE
                     and (state.get('desired') or {}).get('status') == 'retry-build',
                     'SUCCESSOR_ALLOCATION_ALREADY_CONSUMED')
            else:
                need(state.get('counter') == 62
                     and (state.get('desired') or {}).get('version') == '0.4.4-preview.62',
                     'ONE_SUCCESSOR_COUNTER_REQUIRED')
            return super().tick(allow, budget)

        def authorized(self):
            state = self.state(); self.live(state); desired = self.candidate(state)
            proof = workspace_acceptance(self, desired)
            need(isinstance(proof, dict) and proof.get('candidate_revision') == desired['commit']
                 and proof.get('version') == desired['version']
                 and all(proof.get(name) is True for name in
                         ('latest_required_ci_accepted', 'full_native_lifecycle_accepted')),
                 'FULL_WORKSPACE_ACCEPTANCE_REQUIRED')
            return super().authorized()

        def review_existing_ci(self):
            """Original same-candidate review, now bound to successor62 only."""
            state = self.state(); desired = state.get('desired') or {}
            if desired.get('status') not in ('retry-build', 'blocked'):
                return state
            need(self.job.get('prepare_started') is True and self.job.get('baseline_counter') == 61
                 and self.job.get('candidate') == desired.get('commit') == state.get('last_remote_sha')
                 and self.job.get('version') == desired.get('version') == '0.4.4-preview.62'
                 and desired.get('inventorySha256') == self.job['inventory'] == INVENTORY
                 and desired.get('candidatePolicy') == POLICY and desired.get('pushed') is True,
                 'LATCHED_CI_REVIEW_BINDING_CHANGED')
            self.live(state); self.candidate(state)
            config = self.worker.read_config()
            need(config.get('base_revision') == pins['seed'], 'CI_REVIEW_CONFIG_CHANGED')
            client = self.worker.GitHub(config['token']); runs = {}
            for workflow in self.worker.WORKFLOWS:
                run = self.worker.accepted_run(client, workflow, desired['commit'])
                need(isinstance(run, dict) and type(run.get('id')) is int and 1 <= run['id'] <= SAFE
                     and type(run.get('run_attempt')) is int and 1 <= run['run_attempt'] <= SAFE,
                     'SAME_CANDIDATE_SUCCESSFUL_CI_REQUIRED')
                runs[workflow] = {'id': run['id'], 'attempt': run['run_attempt']}
            self.live(state); self.candidate(state)
            verify_baseline(self, None, None)
            need(self.state() == state, 'STATE_CHANGED_DURING_CI_REVIEW')
            raw = self.e.canonical(state); audit = Path(self.job['audit'])
            review = {'format': 1, 'kind': 'go-erp-audit-successor-ci-review',
                      'candidate': desired['commit'], 'version': desired['version'],
                      'inventory': self.job['inventory'], 'core': pins['core'], 'seed': pins['seed'],
                      'before_state_sha256': sha(raw), 'runs': runs}
            for name, value in (('successor-ci-state-' + sha(raw) + '.json', raw),
                                ('successor-ci-review-' + sha(canonical(review)) + '.json', canonical(review))):
                existing = _read(self, audit / name, optional=True)
                if existing is None:
                    self.e.atomic_write(audit / name, value, 0, 0, 0o600)
                else:
                    need(existing == value, 'CI_REVIEW_AUDIT_CHANGED')
            result = copy.deepcopy(state); result['desired']['status'] = 'waiting-ci'
            result['desired']['retry_at'] = 0
            verify_baseline(self, None, None)
            self.e.atomic_write(WORK / 'state/current.json', self.e.canonical(result), 0, 0, 0o600)
            return result

        def complete(self):
            template.quiet(self.e)
            with self.utility.watcher_lock(WORK):
                if self.job['candidate'] is not None:
                    self.review_existing_ci()
                    pending = _read(self, PENDING, optional=True)
                    if pending is not None:
                        receipt = self.base.parse(pending)
                        if receipt.get('candidate_revision') == self.job['candidate']:
                            need(receipt.get('version') == self.job['version']
                                 and all(receipt.get(key) == value for key, value in self.expected.items()),
                                 'RETAINED_ACCEPTANCE_BINDING_CHANGED')
                            if not self.ready(self.state()):
                                self.authorized()
                        else:
                            need(pending == _read(self, Path(self.job['audit']) / 'previous-pending-acceptance.json'),
                                 'PENDING_ACCEPTANCE_CHANGED')
            return super().complete()
    return Context


if __name__ == '__main__':
    raise SystemExit('GO_ERP_DESKTOP_AUDIT_SUCCESSOR_USE_REVIEWED_COORDINATOR')
