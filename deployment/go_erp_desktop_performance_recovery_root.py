#!/usr/bin/env python3
"""Durable two-record correction for the proved, unallocated rejected seed.

The separately pinned pure planner must accept all evidence before an intent
is written. Only the native job's seed/runtime and the performance journal's
binding change. Original records and every intermediate checkpoint are kept.
Manual helper installation remains exclusively in the frozen Context.manual.
"""
import copy
import hashlib
import json
from pathlib import Path
import re

CORE = '43b1498938d4550a4c63c267dbcde12b0bab7e81'
BAD_SEED = '11c739039e5c6320b3542d414682d755c7902790'
NEW_SEED = '78f862c63d0ee75871417c286512e81f962934d6'
CURRENT_SEED = 'dc06af6f8e4ef9ea03a3fb9c728d26acb2f8193a'
PRIVATE = Path('/var/lib/go-erp-manual-updates/private')
WORK = Path('/var/lib/go-erp-live-updates')
CONFIG = Path('/etc/go-erp/live-updates.json')
UNIT = Path('/etc/systemd/system/go-erp-live-updates.service')
AUDIT = PRIVATE / 'audits' / ('desktop-performance-seed-correction-' + CORE)
PERFORMANCE_AUDIT = PRIVATE / 'audits' / ('desktop-performance-' + CORE)
PERFORMANCE = PRIVATE / ('desktop-performance-' + CORE + '.json')
NATIVE_JOB = PRIVATE / ('shared-workspace-' + CORE + '.json')
BAD_MANUAL_PINS = {
    'go_erp_manual_tick_root.py': '4ea08fc1ffa3113c14a964931869a7016b69619e45ba05f272929c36a736b911',
    'go_erp_manual_updates_root.py': 'c8304a4f3ab4a4a8160e4bf687fa129343f53031fb38d695c398195ac12ed624',
    'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b',
}
FILES = ('native-before.bin', 'native-after.bin', 'performance-before.bin', 'performance-after.bin')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_CORRECTION_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def _read(rollout, path, *, optional=False, modes=(0o600,)):
    value = rollout.e.read_file(path, 16 * 1024**2, uid=0, modes=modes, optional=optional)
    return None if value is None else value[0]


def _record(rollout, path, raw, verify):
    need(verify() is not False, 'LOCK_CHANGED')
    rollout.io.private_record(path, raw, verify)


def _changed(before, after):
    need(set(before) == set(after), 'FIELD_ROSTER_CHANGED')
    return {key for key in before if before[key] != after[key]}


def _validate(rollout, planner, intent, plan, raws):
    need(intent.get('format') == 1 and type(intent.get('format')) is int
         and intent.get('kind') == 'go-erp-desktop-performance-seed-correction-intent'
         and intent.get('core') == CORE and intent.get('from_seed') == BAD_SEED
         and intent.get('to_seed') == NEW_SEED
         and intent.get('plan_sha256') == sha(canonical(plan))
         and intent.get('manual_previous_pins') == BAD_MANUAL_PINS
         and intent.get('record_hashes') == {name: sha(raws[name]) for name in FILES}, 'INTENT_CHANGED')
    need(plan.get('format') == 1 and plan.get('kind') == 'go-erp-desktop-performance-unallocated-seed-correction'
         and plan.get('core') == CORE and plan.get('from_seed') == BAD_SEED
         and plan.get('to_seed') == NEW_SEED and plan.get('database_changed') is False
         and plan.get('counter_allocation_allowed') is False
         and plan.get('manual_restore_required') is False and plan.get('manual_transition_owned') is True,
         'PLAN_SCOPE_CHANGED')
    before_job = rollout.io.parse(raws['native-before.bin'])
    after_job = rollout.io.parse(raws['native-after.bin'])
    before_performance = rollout.io.parse(raws['performance-before.bin'])
    after_performance = rollout.io.parse(raws['performance-after.bin'])
    need(_changed(before_job, after_job) == {'seed', 'runtime'}
         and before_job['seed'] == BAD_SEED and after_job['seed'] == NEW_SEED
         and before_job['core'] == after_job['core'] == CORE
         and before_job['phase'] == after_job['phase'] == 'preparing'
         and before_job['prepare_started'] is False and after_job['prepare_started'] is False
         and before_job['candidate'] is None and after_job['candidate'] is None
         and before_job['version'] is None and after_job['version'] is None
         and after_job == plan['native_job_after']
         and planner.sha(planner.canonical(before_job)) == plan['native_job_before_sha256'], 'NATIVE_RECORD_CHANGED')
    need(_changed(before_performance, after_performance) == {'binding_sha256'}
         and before_performance['binding_sha256'] == planner.OLD_BINDING_SHA256
         and after_performance['binding_sha256'] == rollout.bind_sha
         and after_performance == plan['performance_after']
         and planner.sha(planner.canonical(before_performance)) == plan['performance_before_sha256'],
         'PERFORMANCE_RECORD_CHANGED')


def read_saved(rollout, planner, verify):
    """Return private original inputs for an interrupted correction, if any."""
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
    completed = _read(rollout, AUDIT / 'completed.json', optional=True)
    expected = {'format': 1, 'kind': 'go-erp-desktop-performance-seed-correction-completed',
                'intent_sha256': sha(raw), 'native_after_sha256': sha(raws['native-after.bin']),
                'performance_after_sha256': sha(raws['performance-after.bin'])}
    need(completed is None or completed == canonical(expected), 'COMPLETION_CHANGED')
    need(verify() is not False, 'LOCK_CHANGED')
    return {'intent': intent, 'plan': plan, 'raws': raws, 'completed': completed is not None,
            'completion': expected,
            'native_job': rollout.io.parse(raws['native-before.bin']),
            'performance_journal': rollout.io.parse(raws['performance-before.bin'])}


def _original_slots(rollout, before_performance, verify):
    need(verify() is not False, 'LOCK_CHANGED')
    hashes = before_performance['current']['private_hashes']
    for path, modes in ((WORK / 'state/current.json', (0o600,)), (CONFIG, (0o600,)), (UNIT, (0o644,))):
        # Keys are the fixed production identities even in isolated FS tests.
        key = '/var/lib/go-erp-live-updates/state/current.json' if path == WORK / 'state/current.json' else \
              '/etc/go-erp/live-updates.json' if path == CONFIG else '/etc/systemd/system/go-erp-live-updates.service'
        need(sha(_read(rollout, path, modes=modes)) == hashes[key], 'ORIGINAL_NATIVE_SLOTS_CHANGED')
    need(_read(rollout, WORK / 'program-rollout-hold.json', optional=True) is None, 'PROGRAM_HOLD_PRESENT')
    rollout.o.base.checkout(WORK / 'checkout', CURRENT_SEED, rollout.o.utility)
    need(verify() is not False, 'LOCK_CHANGED')


def _cas(rollout, path, before_raw, after_raw, verify, guard):
    """Accept only the exact preimage or this intent's exact completed image."""
    guard()
    current = _read(rollout, path)
    need(current in (before_raw, after_raw), 'TARGET_NOT_OWNED')
    if current == after_raw:
        return
    need(verify() is not False, 'LOCK_CHANGED')
    need(_read(rollout, path) == before_raw, 'TARGET_CHANGED')
    rollout.e.atomic_write(path, after_raw, 0, 0, 0o600)
    need(_read(rollout, path) == after_raw, 'AFTER_WRITE_CHANGED')
    guard()


def recover(rollout, planner, verify, *, evidence=None):
    """Apply/resume the exact pure plan, returning the owned manual predecessor.

    The caller supplies complete freshly verified evidence on first entry and
    on any interrupted incomplete entry. A completed correction may accompany
    later original-native progress; that original Context validates its job.
    """
    saved = read_saved(rollout, planner, verify)
    if saved is None:
        need(isinstance(evidence, dict), 'FRESH_EVIDENCE_REQUIRED')
        plan = planner.plan(**evidence)
        native_before = _read(rollout, NATIVE_JOB)
        performance_before = _read(rollout, PERFORMANCE)
        need(rollout.io.parse(native_before) == evidence['native_job']
             and rollout.io.parse(performance_before) == evidence['performance_journal'], 'EVIDENCE_CHANGED')
        raws = {'native-before.bin': native_before,
                'native-after.bin': rollout.e.canonical(plan['native_job_after']),
                'performance-before.bin': performance_before,
                'performance-after.bin': canonical(plan['performance_after'])}
        intent = {'format': 1, 'kind': 'go-erp-desktop-performance-seed-correction-intent',
            'core': CORE, 'from_seed': BAD_SEED, 'to_seed': NEW_SEED,
            'plan_sha256': sha(canonical(plan)), 'manual_previous_pins': BAD_MANUAL_PINS,
            'record_hashes': {name: sha(raws[name]) for name in FILES}}
        _validate(rollout, planner, intent, plan, raws)
        _original_slots(rollout, evidence['performance_journal'], verify)
        rollout.io.private_directory(AUDIT, verify)
        for name, raw in raws.items():
            _record(rollout, AUDIT / name, raw, verify)
        _record(rollout, AUDIT / 'plan.json', canonical(plan), verify)
        _record(rollout, AUDIT / 'intent.json', canonical(intent), verify)
        saved = read_saved(rollout, planner, verify)
    if saved['completed']:
        job = rollout.io.parse(_read(rollout, NATIVE_JOB))
        performance = rollout.io.parse(_read(rollout, PERFORMANCE))
        need(job.get('core') == CORE and job.get('seed') == NEW_SEED
             and job.get('runtime') == saved['plan']['native_job_after']['runtime']
             and performance.get('binding_sha256') == rollout.bind_sha
             and performance.get('phase') in ('prepared', 'ready'), 'COMPLETED_BINDING_CHANGED')
        checkpoint = PERFORMANCE_AUDIT / ('checkpoint-' + sha(canonical(performance)) + '.json')
        need(_read(rollout, checkpoint) == canonical(performance), 'COMPLETED_CHECKPOINT_CHANGED')
        return copy.deepcopy(BAD_MANUAL_PINS)
    need(isinstance(evidence, dict), 'FRESH_EVIDENCE_REQUIRED')
    repeated = planner.plan(**evidence)
    need(canonical(repeated) == canonical(saved['plan']), 'REVALIDATED_PLAN_CHANGED')
    before_performance = saved['performance_journal']
    guard = lambda: _original_slots(rollout, before_performance, verify)
    raws = saved['raws']
    guard()
    # Classify both targets before changing either. A foreign second record
    # must not cause an unnecessary first-record correction on partial resume.
    for path, before_name, after_name in ((NATIVE_JOB, 'native-before.bin', 'native-after.bin'),
                                           (PERFORMANCE, 'performance-before.bin', 'performance-after.bin')):
        need(_read(rollout, path) in (raws[before_name], raws[after_name]), 'TARGET_NOT_OWNED')
    _cas(rollout, NATIVE_JOB, raws['native-before.bin'], raws['native-after.bin'], verify, guard)
    _record(rollout, AUDIT / 'native-job-written.json', {
        'format': 1, 'sha256': sha(raws['native-after.bin'])}, verify)
    after = rollout.io.parse(raws['performance-after.bin'])
    _record(rollout, PERFORMANCE_AUDIT / ('checkpoint-' + sha(canonical(after)) + '.json'),
            canonical(after), verify)
    _cas(rollout, PERFORMANCE, raws['performance-before.bin'], raws['performance-after.bin'], verify, guard)
    _record(rollout, AUDIT / 'completed.json', canonical(saved['completion']), verify)
    return copy.deepcopy(BAD_MANUAL_PINS)


if __name__ == '__main__':
    raise SystemExit('GO_ERP_DESKTOP_PERFORMANCE_CORRECTION_USE_PINNED_COORDINATOR')
