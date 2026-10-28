#!/usr/bin/env python3
"""Adopt proved committed source without changing the rejected rollout.

Only a new prepared source checkpoint and captured-metadata copy are created.
The original Context creates the new native job and owns every allocation,
manual helper transition, native transaction, acceptance and publication.
"""
import copy
import hashlib
import json
from pathlib import Path

OLD_CORE = '43b1498938d4550a4c63c267dbcde12b0bab7e81'
CORE = 'b5503e21e3af32eecaf0d2f9d68eee7d21be7439'
BAD_SEED = '11c739039e5c6320b3542d414682d755c7902790'
NEW_SEED = 'd15942c947ac31c21a90b53856084a5de35d3cc3'
CURRENT_SEED = 'dc06af6f8e4ef9ea03a3fb9c728d26acb2f8193a'
PRIVATE = Path('/var/lib/go-erp-manual-updates/private')
WORK = Path('/var/lib/go-erp-live-updates')
CONFIG = Path('/etc/go-erp/live-updates.json')
UNIT = Path('/etc/systemd/system/go-erp-live-updates.service')
AUDIT = PRIVATE / 'audits' / ('desktop-performance-source-adoption-' + CORE)
OLD_PERFORMANCE_AUDIT = PRIVATE / 'audits' / ('desktop-performance-' + OLD_CORE)
PERFORMANCE_AUDIT = PRIVATE / 'audits' / ('desktop-performance-' + CORE)
OLD_PERFORMANCE = PRIVATE / ('desktop-performance-' + OLD_CORE + '.json')
PERFORMANCE = PRIVATE / ('desktop-performance-' + CORE + '.json')
NATIVE_JOB = PRIVATE / ('shared-workspace-' + OLD_CORE + '.json')
NEW_NATIVE_JOB = PRIVATE / ('shared-workspace-' + CORE + '.json')
BAD_MANUAL_PINS = {
    'go_erp_manual_tick_root.py': '4ea08fc1ffa3113c14a964931869a7016b69619e45ba05f272929c36a736b911',
    'go_erp_manual_updates_root.py': 'c8304a4f3ab4a4a8160e4bf687fa129343f53031fb38d695c398195ac12ed624',
    'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b',
}
FILES = ('native-before.bin', 'performance-before.bin', 'performance-after.bin', 'captured-source.bin')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_ADOPTION_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def _read(rollout, path, *, optional=False, modes=(0o600,)):
    value = rollout.e.read_file(path, 16 * 1024**2, uid=0, modes=modes, optional=optional)
    return None if value is None else value[0]


def _record(rollout, path, value, verify):
    need(verify() is not False, 'LOCK_CHANGED')
    rollout.io.private_record(path, value, verify)


def _validate(rollout, planner, intent, plan, raws):
    need(intent.get('format') == 1 and type(intent.get('format')) is int
         and intent.get('kind') == 'go-erp-desktop-performance-source-adoption-intent'
         and intent.get('core') == CORE and intent.get('from_core') == OLD_CORE
         and intent.get('from_seed') == BAD_SEED and intent.get('to_seed') == NEW_SEED
         and intent.get('plan_sha256') == sha(canonical(plan))
         and intent.get('manual_previous_pins') == BAD_MANUAL_PINS
         and intent.get('record_hashes') == {name: sha(raws[name]) for name in FILES}, 'INTENT_CHANGED')
    need(plan.get('format') == 1 and plan.get('kind') == 'go-erp-desktop-performance-owned-source-adoption'
         and plan.get('core') == CORE and plan.get('from_core') == OLD_CORE
         and plan.get('from_seed') == BAD_SEED and plan.get('to_seed') == NEW_SEED
         and plan.get('database_changed') is False and plan.get('counter_allocation_allowed') is False
         and plan.get('manual_restore_required') is False and plan.get('manual_transition_owned') is True
         and plan.get('old_records_unchanged') is True and plan.get('new_native_job_owned_by_original') is True
         and 'native_job_after' not in plan, 'PLAN_SCOPE_CHANGED')
    before_job = rollout.io.parse(raws['native-before.bin'])
    before_performance = rollout.io.parse(raws['performance-before.bin'])
    after_performance = rollout.io.parse(raws['performance-after.bin'])
    need(before_job['seed'] == BAD_SEED and before_job['core'] == OLD_CORE
         and before_job['phase'] == 'preparing' and before_job['prepare_started'] is False
         and before_job['candidate'] is None and before_job['version'] is None
         and planner.sha(planner.canonical(before_job)) == plan['native_job_before_sha256'], 'NATIVE_RECORD_CHANGED')
    need(set(before_performance) == set(after_performance)
         and {key for key in before_performance
              if canonical(before_performance[key]) != canonical(after_performance[key])} == {'binding_sha256'}
         and before_performance['binding_sha256'] == planner.OLD_BINDING_SHA256
         and after_performance['binding_sha256'] == rollout.bind_sha
         and after_performance == plan['performance_after']
         and planner.sha(planner.canonical(before_performance)) == plan['performance_before_sha256'],
         'PERFORMANCE_RECORD_CHANGED')
    need(sha(raws['captured-source.bin']) == plan['captured_metadata_sha256']
         == before_performance['captured_metadata_sha256'] == before_job['captured_metadata_sha256'],
         'CAPTURED_METADATA_CHANGED')


def read_saved(rollout, planner, verify):
    """Return private original evidence for a partially created adoption."""
    need(verify() is not False, 'LOCK_CHANGED')
    try:
        AUDIT.lstat()
    except FileNotFoundError:
        return None
    rollout.io.directory(AUDIT, 0, 0, private=True)
    raw = _read(rollout, AUDIT / 'intent.json', optional=True)
    if raw is None:
        return None
    intent = rollout.io.parse(raw)
    plan = rollout.io.parse(_read(rollout, AUDIT / 'plan.json'))
    raws = {name: _read(rollout, AUDIT / name) for name in FILES}
    _validate(rollout, planner, intent, plan, raws)
    expected = {'format': 1, 'kind': 'go-erp-desktop-performance-source-adoption-completed',
                'intent_sha256': sha(raw), 'performance_sha256': sha(raws['performance-after.bin']),
                'captured_metadata_sha256': sha(raws['captured-source.bin'])}
    completed = _read(rollout, AUDIT / 'completed.json', optional=True)
    need(completed is None or completed == canonical(expected), 'COMPLETION_CHANGED')
    need(verify() is not False, 'LOCK_CHANGED')
    return {'intent': intent, 'plan': plan, 'raws': raws, 'completed': completed is not None,
            'completion': expected, 'native_job': rollout.io.parse(raws['native-before.bin']),
            'performance_journal': rollout.io.parse(raws['performance-before.bin'])}


def _old_records(rollout, raws):
    need(_read(rollout, NATIVE_JOB) == raws['native-before.bin']
         and _read(rollout, OLD_PERFORMANCE) == raws['performance-before.bin']
         and _read(rollout, OLD_PERFORMANCE_AUDIT / 'captured-source.json') == raws['captured-source.bin'],
         'OLD_RECORDS_CHANGED')


def _original_slots(rollout, before_performance, verify):
    need(verify() is not False, 'LOCK_CHANGED')
    hashes = before_performance['current']['private_hashes']
    for path, key, modes in (
        (WORK / 'state/current.json', '/var/lib/go-erp-live-updates/state/current.json', (0o600,)),
        (CONFIG, '/etc/go-erp/live-updates.json', (0o600,)),
        (UNIT, '/etc/systemd/system/go-erp-live-updates.service', (0o644,))):
        need(sha(_read(rollout, path, modes=modes)) == hashes[key], 'ORIGINAL_NATIVE_SLOTS_CHANGED')
    need(_read(rollout, WORK / 'program-rollout-hold.json', optional=True) is None, 'PROGRAM_HOLD_PRESENT')
    rollout.o.base.checkout(WORK / 'checkout', CURRENT_SEED, rollout.o.utility)
    need(verify() is not False, 'LOCK_CHANGED')


def recover(rollout, planner, verify, *, evidence=None):
    """Create/resume only the new prepared source checkpoint; allocate nothing."""
    saved = read_saved(rollout, planner, verify)
    if saved is None:
        need(isinstance(evidence, dict), 'FRESH_EVIDENCE_REQUIRED')
        plan = planner.plan(**evidence)
        native_before = _read(rollout, NATIVE_JOB)
        performance_before = _read(rollout, OLD_PERFORMANCE)
        captured = _read(rollout, OLD_PERFORMANCE_AUDIT / 'captured-source.json')
        need(rollout.io.parse(native_before) == evidence['native_job']
             and rollout.io.parse(performance_before) == evidence['performance_journal']
             and captured == evidence['captured_metadata_raw'], 'EVIDENCE_CHANGED')
        raws = {'native-before.bin': native_before, 'performance-before.bin': performance_before,
                'performance-after.bin': canonical(plan['performance_after']), 'captured-source.bin': captured}
        intent = {'format': 1, 'kind': 'go-erp-desktop-performance-source-adoption-intent',
            'core': CORE, 'from_core': OLD_CORE, 'from_seed': BAD_SEED, 'to_seed': NEW_SEED,
            'plan_sha256': sha(canonical(plan)), 'manual_previous_pins': BAD_MANUAL_PINS,
            'record_hashes': {name: sha(raws[name]) for name in FILES}}
        _validate(rollout, planner, intent, plan, raws)
        _original_slots(rollout, evidence['performance_journal'], verify)
        need(_read(rollout, NEW_NATIVE_JOB, optional=True) is None, 'NEW_NATIVE_JOB_ALREADY_EXISTS')
        need(_read(rollout, PERFORMANCE, optional=True) is None, 'NEW_PERFORMANCE_ALREADY_EXISTS')
        rollout.io.private_directory(AUDIT, verify)
        for name, raw in raws.items():
            _record(rollout, AUDIT / name, raw, verify)
        _record(rollout, AUDIT / 'plan.json', canonical(plan), verify)
        _record(rollout, AUDIT / 'intent.json', canonical(intent), verify)
        saved = read_saved(rollout, planner, verify)
    raws = saved['raws']
    _old_records(rollout, raws)
    if saved['completed']:
        native_raw = _read(rollout, NEW_NATIVE_JOB, optional=True)
        if native_raw is not None:
            native = rollout.io.parse(native_raw)
            need(native.get('core') == CORE and native.get('seed') == NEW_SEED
                 and native.get('runtime') == planner.runtime_name(rollout.bind['native_payloads']),
                 'COMPLETED_NATIVE_BINDING_CHANGED')
        performance = rollout.io.parse(_read(rollout, PERFORMANCE))
        need(performance.get('binding_sha256') == rollout.bind_sha
             and performance.get('phase') in ('prepared', 'ready'), 'COMPLETED_BINDING_CHANGED')
        checkpoint = PERFORMANCE_AUDIT / ('checkpoint-' + sha(canonical(performance)) + '.json')
        need(_read(rollout, checkpoint) == canonical(performance)
             and _read(rollout, PERFORMANCE_AUDIT / 'captured-source.json') == raws['captured-source.bin'],
             'COMPLETED_CHECKPOINT_CHANGED')
        return copy.deepcopy(BAD_MANUAL_PINS)
    need(isinstance(evidence, dict), 'FRESH_EVIDENCE_REQUIRED')
    need(canonical(planner.plan(**evidence)) == canonical(saved['plan']), 'REVALIDATED_PLAN_CHANGED')
    def guard():
        _original_slots(rollout, saved['performance_journal'], verify)
        _old_records(rollout, raws)
        need(_read(rollout, NEW_NATIVE_JOB, optional=True) is None, 'NEW_NATIVE_JOB_ALREADY_EXISTS')
        current = _read(rollout, PERFORMANCE, optional=True)
        need(current is None or current == raws['performance-after.bin'], 'NEW_PERFORMANCE_NOT_OWNED')
    guard()
    rollout.io.private_directory(PERFORMANCE_AUDIT, verify)
    _record(rollout, PERFORMANCE_AUDIT / 'captured-source.json', raws['captured-source.bin'], verify)
    guard()
    after = rollout.io.parse(raws['performance-after.bin'])
    _record(rollout, PERFORMANCE_AUDIT / ('checkpoint-' + sha(canonical(after)) + '.json'), canonical(after), verify)
    guard()
    # The original no-replace writer creates the new journal exactly once.
    # The normal coordinator subsequently owns its checkpointed updates.
    _record(rollout, PERFORMANCE, raws['performance-after.bin'], verify)
    guard()
    _record(rollout, AUDIT / 'completed.json', canonical(saved['completion']), verify)
    return copy.deepcopy(BAD_MANUAL_PINS)


if __name__ == '__main__':
    raise SystemExit('GO_ERP_DESKTOP_PERFORMANCE_ADOPTION_USE_PINNED_COORDINATOR')
