#!/usr/bin/env python3
"""Bind the frozen original native transaction/collector to one shared workspace.

No replacement publisher is implemented here. The original pinned Context keeps
its runtime installation, source capture, state migration, candidate validation,
CI, installer, employee/program acceptance and publication functions. Default
pins are absent, so importing this module cannot prepare or allocate a release.
"""
import copy
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types

TEMPLATE_RELEASE = '12d74b4f35737a2b1e271657b159137538c3edaf'
TEMPLATE_SHA256 = '70bfb190e3b53fcb63ee76eb3ebfe16621be524e65ce623b4418386550081861'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
SUFFIX = b'-native-close-reopen-program-contract-shared-workspace-v1'
PINS = None
SHA = re.compile(r'[a-f0-9]{64}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
SAFE = 9007199254740991
BACKEND = ('app', 'bootstrap', 'config', 'database', 'desktop', 'public', 'resources',
    'routes', 'artisan', 'composer.json', 'composer.lock')
MANUAL_NAMES = frozenset(('go_erp_manual_tick_root.py', 'go_erp_manual_updates_root.py',
    'go_erp_published_download_root.py'))
ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
CONTROL = Path('/var/lib/go-erp-manual-updates')
CONFIG = Path('/etc/go-erp/live-updates.json')
REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
REMOTE_REF = 'refs/heads/codex/go-erp-live-source-20261021'
WORKFLOWS = ('desktop-dashboard-tests.yml', 'go-erp-update-tests.yml', 'whatsapp-integrated-regressions.yml')
PROFILES = {
    59: {'core': '13fd8b038bd1a853a30ba9b4140a053088efa73d',
        'seed': '0885a3b550f92df2b74bcd2c16d27954d8b44b7b',
        'runtime': 'policy-4d1bb8ea0a62b0be48dcaed76dc6e5bdabc15e7c6c123c921eb211c5ca7fab71',
        'policy': 'historical-baseline-v2-native-close-reopen-program-contract-direct-login-accounts-v4',
        'operator_release': '33e08c9fec93501d2376eb88d9fd402043f8d792',
        'tick_path': 'deployment/go_erp_navigation_tick_root.py', 'job_prefix': 'navigation-',
        'manual_pins': {'go_erp_manual_tick_root.py': 'b34a7b87e716acedc50c38a871b1888113b8d10095b4fb0d9f6325ae8f192931',
            'go_erp_manual_updates_root.py': '0090ca4af527180c77895d32c75df86718229fc91ae1c427c50ac778112a42b0',
            'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}},
    60: {'core': '9867dc29357e16290b3a1eeda996d916af701915',
        'seed': 'f3a8b4af4ec27042a93328c1fde200cfd8fe1da3',
        'runtime': 'policy-62e6a1e796d123024b77582f0e1e6fe8e8c14b8a8396a7ec0363dda7ff2fb7d6',
        'policy': 'historical-baseline-v2-native-close-reopen-program-contract-direct-login-accounts-v5',
        'operator_release': '8db5adb538834a2152f74f6e55336223a4c95908',
        'tick_path': 'deployment/go_erp_owner_fast_open_tick_root.py', 'job_prefix': 'owner-fast-open-',
        'manual_pins': {'go_erp_manual_tick_root.py': '2b4c0c501accfdf1d08dc0ec90206da1cf0a2a22b799e7eac723f3d7c816cdb6',
            'go_erp_manual_updates_root.py': 'ca2e2488956aa40a17ceb883a119117e72ba2f5f25935e9ee974d9f1cd989953',
            'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}},
}


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_NATIVE_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def blob(raw):
    return hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()


def _module(raw, name, expected):
    need(isinstance(raw, bytes) and sha(raw) == expected, 'READ_ONLY_HELPER_PIN_CHANGED')
    value = types.ModuleType(name);value.__file__ = '<pinned-' + name + '>'
    sys.modules[name] = value
    exec(compile(raw, value.__file__, 'exec'), value.__dict__)
    return value


def _old_runtime(release, engine, counter):
    profile = PROFILES[counter]
    tick = _module(release.file(profile['tick_path'], profile['operator_release']),
        'workspace_previous_' + str(counter), profile['manual_pins']['go_erp_manual_tick_root.py'])
    cohort = tick.NEW_COHORT
    need(cohort['seed'] == profile['seed'] and cohort['policy'] == profile['policy']
        and cohort['runtime'].name == profile['runtime'], 'PREVIOUS_COHORT_CHANGED')
    worker = tick.load_runtime(cohort)  # The original reader validates every installed runtime byte.
    raw = engine.read_file(cohort['runtime'] / 'publish_go_erp_update.py',
        2 * 1024**2, uid=0, modes=(0o644,))[0]
    publisher = _module(raw, 'workspace_previous_publisher_' + str(counter),
        cohort['pins']['publish_go_erp_update.py'][2])
    return tick, worker, publisher


def _legacy_source_checkpoint(release, engine, evidence):
    """Recognize only the exact old seven-file transaction, without changing it."""
    old = _module(release.file('deployment/upgrade_go_erp_owner_fast_open_root.py', PROFILES[60]['operator_release']),
        'workspace_legacy_source_operator', '8986876c57ced091db35bcdcfbd3efcba3cbd03b07eeb91f0d66fd203df82426')
    helper = _module(release.file('deployment/go_erp_owner_fast_open_source_root.py', PROFILES[60]['operator_release']),
        'workspace_legacy_source_reader', old.SOURCE_HELPER_SHA256)
    payloads = {name:release.file(name, old.WEB_RELEASE) for name in old.WEB_PATCHES}
    plan = helper.checked_plan(old.WEB_RELEASE, old.WEB_PATCHES, payloads)
    plan.update(root=str(ROOT), owner_uid=1001)
    audit = CONTROL / 'private/audits' / ('navigation-source-' + sha(canonical(plan)))
    if not os.path.lexists(audit):return False
    helper.directory(audit, {0}, 0o700)
    def private(path):
        raw = helper.read_file(path, {0}, private=True)[0]
        evidence[str(path)] = sha(raw)
        return raw
    stored=helper.read_file(audit / 'intent.json', {0}, optional=True, private=True)
    if stored is None:
        # The old helper may have durably created its directory/backups before
        # writing intent. It can resume only while every source is still before.
        need(helper.read_file(audit/'committed.json',{0},optional=True,private=True) is None,
            'LEGACY_SOURCE_COMMIT_WITHOUT_INTENT')
        for name,pin in old.WEB_PATCHES.items():
            helper.directory((ROOT/name).parent,{0,1001})
            raw,_info=helper.read_file(ROOT/name,{0,1001})
            need(sha(raw)==pin['before'],'LEGACY_SOURCE_NOT_OWNED')
            evidence[str(ROOT/name)]=sha(raw)
            backup=audit/('before-'+sha(name.encode())+'.bin')
            previous=helper.read_file(backup,{0},optional=True,private=True)
            if previous is not None:
                need(sha(previous[0])==pin['before'],'LEGACY_SOURCE_BACKUP_CHANGED')
                evidence[str(backup)]=sha(previous[0])
        return True
    evidence[str(audit/'intent.json')]=sha(stored[0])
    intent = helper.parse(stored[0])
    need(set(intent) == {'plan','files'} and intent['plan'] == plan
        and set(intent['files']) == set(old.WEB_PATCHES), 'LEGACY_SOURCE_INTENT_CHANGED')
    current = {}
    for name, pin in old.WEB_PATCHES.items():
        facts = intent['files'][name];backup = 'before-' + sha(name.encode()) + '.bin'
        need(isinstance(facts,dict) and set(facts) == {'uid','gid','mode','backup'}
            and facts['backup'] == backup, 'LEGACY_SOURCE_METADATA_CHANGED')
        need(sha(private(audit / backup)) == pin['before'], 'LEGACY_SOURCE_BACKUP_CHANGED')
        helper.directory((ROOT/name).parent,{0,1001})
        raw,info = helper.read_file(ROOT / name, {0,1001})
        need((info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode)) == (facts['uid'],facts['gid'],facts['mode'])
            and sha(raw) in (pin['before'],pin['after']), 'LEGACY_SOURCE_CURRENT_CHANGED')
        current[name] = sha(raw)
        evidence[str(ROOT / name)] = sha(raw)
    commit = helper.read_file(audit / 'committed.json', {0}, optional=True, private=True)
    if commit is not None:
        evidence[str(audit / 'committed.json')] = sha(commit[0])
        need(helper.parse(commit[0]) == {'format':1,'kind':'go-erp-navigation-source-committed',
            'plan_sha256':sha(canonical(plan)),'paths':sorted(old.WEB_PATCHES),'business_data_changed':False}
            and all(current[name] == pin['after'] for name,pin in old.WEB_PATCHES.items()),
            'LEGACY_SOURCE_COMMIT_CHANGED')
    return True


def bootstrap_predecessor(release, engine, *, verify_locks):
    """Read and prove the actual retained predecessor; never allocate or deploy.

    Runtime-only receipt hashes are discovered on the server, not guessed or
    requested from the user. The loader persists this returned observation in
    its root-private immutable WAL, then calls revalidate_bootstrap before any
    source/native write. No credential or raw private record is returned.
    """
    need(callable(verify_locks) and verify_locks() is not False, 'HELD_LOCK_VERIFIER_REQUIRED')
    evidence = {}
    def read(path, maximum=16 * 1024**2, optional=False, modes=(0o600,)):
        result = engine.read_file(path, maximum, uid=0, modes=modes, optional=optional)
        if result is None:
            return None, None
        raw = result[0]
        need(isinstance(raw, bytes), 'PRIVATE_READER_INVALID')
        evidence[str(path)] = sha(raw)
        try:value = json.loads(raw)
        except (ValueError, UnicodeError):raise Stop('GO_ERP_SHARED_WORKSPACE_NATIVE_PRIVATE_RECORD_INVALID')
        need(isinstance(value, dict), 'PRIVATE_RECORD_INVALID')
        return raw, value
    state_raw, state = read(WORK / 'state/current.json')
    need(type(state.get('counter')) is int and state['counter'] in (59, 60), 'UNKNOWN_PREDECESSOR_REQUIRES_REVIEW')
    config_raw, config = read(CONFIG, 16384)
    need(config.get('base_revision') == state.get('base_revision'), 'PREDECESSOR_CONFIG_BASE_CHANGED')
    desired, published = state.get('desired'), state.get('published')
    need(isinstance(desired, dict) and isinstance(published, dict), 'PREDECESSOR_STATE_INVALID')
    # An existing performance command must finish its own retained .60 first.
    # This branch grants no new workspace source write or version allocation.
    fast = PROFILES[60]
    _raw, job = read(CONTROL / 'private' / ('owner-fast-open-' + fast['core'] + '.json'), optional=True)
    job_pending = job is not None and job.get('phase') in ('preparing','prepared','running')
    source_pending = state['counter'] == 59 and not job_pending and _legacy_source_checkpoint(release, engine, evidence)
    if (state['counter'] == 60 and desired.get('status') != 'published') \
            or (state['counter'] == 59 and (state.get('base_revision') == fast['seed'] or job_pending or source_pending)):
        if not source_pending:
            need(job is not None, 'RESERVED_PERFORMANCE_JOB_CHANGED')
        if job is not None:
            need(isinstance(job,dict), 'RESERVED_PERFORMANCE_JOB_CHANGED')
        if not source_pending:
            need(job is not None and type(job.get('format')) is int and job['format'] == 1
                and job.get('kind') == 'go-erp-warm-start-rollout' and job.get('core') == fast['core']
                and job.get('seed') == fast['seed'] and job.get('runtime') == fast['runtime']
                and job.get('phase') in ('preparing', 'prepared', 'running')
                and type(job.get('prepare_started')) is bool and job.get('remote_lease')
                    == '5becb9bab38a91998d1b1341670b2b398e4ba70f'
                and state.get('base_revision') in (fast['seed'],PROFILES[59]['seed'])
                and published.get('commit') == '5becb9bab38a91998d1b1341670b2b398e4ba70f', 'RESERVED_PERFORMANCE_JOB_CHANGED')
        if state['counter'] == 60:
            need(state.get('base_revision') == fast['seed'] and job.get('prepare_started') is True and job.get('baseline_counter') == 59
                and job.get('baseline_candidate') == job.get('baseline_remote')
                    == '5becb9bab38a91998d1b1341670b2b398e4ba70f'
                and desired.get('candidatePolicy') == fast['policy'] and desired.get('pushed') is True
                and desired.get('status') in ('prepared', 'waiting-ci', 'retry-build', 'blocked')
                and isinstance(desired.get('commit'), str) and REV.fullmatch(desired['commit'])
                and desired.get('commit') == state.get('last_remote_sha')
                and desired.get('version') == '0.4.4-preview.60'
                and desired.get('inventorySha256') == job.get('inventory')
                and job.get('candidate') in (None, desired['commit'])
                and job.get('version') in (None, desired['version']), 'RESERVED_60_BINDING_CHANGED')
        need(verify_locks() is not False, 'HELD_LOCK_CHANGED')
        result = {'format': 1, 'kind': 'go-erp-workspace-predecessor-observation',
            'status': 'resume-legacy-required', 'legacy_operator_release': fast['operator_release'],
            'legacy_core': fast['core'], 'counter': state['counter'], 'private_hashes': evidence,
            'new_allocation_allowed': False, 'artifact_replacement_allowed': False}
        return {**result, 'observation_sha256': sha(canonical(result))}
    counter = state['counter'];profile = PROFILES[counter]
    need(state.get('base_revision') == profile['seed'] and desired.get('status') == 'published',
        'UNKNOWN_PREDECESSOR_REQUIRES_REVIEW')
    _tick, worker, publisher = _old_runtime(release, engine, counter)
    release_raw, publication = read(ROOT / 'storage/app/desktop-updates/current.json', 512 * 1024,
        modes=(0o600, 0o644))
    publication = publisher.validate_release(publication)
    candidate = desired.get('commit')
    need(isinstance(candidate, str) and REV.fullmatch(candidate), 'PREDECESSOR_CANDIDATE_INVALID')
    if counter == 59:need(candidate == '5becb9bab38a91998d1b1341670b2b398e4ba70f', 'PUBLISHED_59_CHANGED')
    job_raw, job = read(CONTROL / 'private' / (profile['job_prefix'] + profile['core'] + '.json'))
    need(job.get('kind') == 'go-erp-warm-start-rollout' and job.get('core') == profile['core']
        and job.get('seed') == profile['seed'] and job.get('runtime') == profile['runtime']
        and job.get('phase') == 'ready' and job.get('candidate') == candidate
        and job.get('version') == '0.4.4-preview.' + str(counter)
        and job.get('inventory') == desired.get('inventorySha256')
        and job.get('source') == publication.get('source'), 'PREDECESSOR_JOB_NOT_READY')
    expected = worker.program_gate_expected(profile['core'], job['source'], job['inventory'])
    completed_raw, completed = read(WORK / ('program-rollout-completed-' + profile['core'] + '.json'), 2 * 1024**2)
    need(worker.program_gate_completed(WORK, expected, state, lambda: publication), 'PREDECESSOR_PROGRAM_ACCEPTANCE_CHANGED')
    authorization = completed.get('authorization')
    need(isinstance(authorization, dict) and authorization.get('candidate') == candidate
        and authorization.get('version') == publication.get('version') and authorization.get('release') == publication
        and authorization.get('installer') == publication.get('installer')
        and isinstance(authorization.get('receipt_sha256'), str) and SHA.fullmatch(authorization['receipt_sha256'])
        and isinstance(authorization.get('receipt_path'), str), 'PREDECESSOR_RECEIPT_BINDING_CHANGED')
    path = Path(authorization['receipt_path'])
    need(path.parent == WORK / 'program-rollout-authorizations'
        and path.name == 'receipt-' + authorization['receipt_sha256'] + '.json', 'PREDECESSOR_RECEIPT_PATH_CHANGED')
    receipt_raw, receipt = read(path, 2 * 1024**2)
    need(sha(receipt_raw) == authorization['receipt_sha256']
        and receipt.get('candidate_revision') == candidate and receipt.get('version') == publication.get('version')
        and all(receipt.get(name) == value for name, value in expected.items())
        and receipt.get('installer') == publication.get('installer')
        and receipt.get('employee_evidence') == authorization.get('employee_evidence'), 'PREDECESSOR_ACCEPTANCE_BYTES_CHANGED')
    pending_raw, _pending = read(WORK / 'pending-program-acceptance.json', 2 * 1024**2)
    need(pending_raw == receipt_raw, 'PREDECESSOR_PENDING_RECEIPT_CHANGED')
    hold, _unused = read(WORK / 'program-rollout-hold.json', 2 * 1024**2, optional=True)
    need(hold is None, 'PREDECESSOR_HOLD_NOT_RETIRED')
    previous = {'counter': counter, 'candidate': candidate, 'version': publication['version'],
        'inventory_sha256': job['inventory'], 'release_sha256': sha(canonical(publication)),
        'acceptance_receipt_sha256': sha(receipt_raw)}
    checked_published_state(state, publication, {'predecessor': previous})
    need(release.git('ls-remote', '--exit-code', REMOTE, REMOTE_REF)
        == (candidate + '\t' + REMOTE_REF + '\n').encode(), 'PREDECESSOR_CANONICAL_REMOTE_CHANGED')
    original_config = worker.read_config()
    need(original_config.get('base_revision') == profile['seed'], 'ORIGINAL_WORKER_CONFIG_CHANGED')
    client = worker.GitHub(original_config['token']);runs = {}
    for workflow in WORKFLOWS:
        run = worker.accepted_run(client, workflow, candidate)
        need(isinstance(run, dict) and type(run.get('id')) is int and 1 <= run['id'] <= SAFE
            and type(run.get('run_attempt')) is int and 1 <= run['run_attempt'] <= SAFE,
            'ORIGINAL_PREDECESSOR_CI_REQUIRED')
        runs[workflow] = {'id': run['id'], 'attempt': run['run_attempt']}
    need(verify_locks() is not False and engine.read_file(WORK / 'state/current.json',
        16 * 1024**2, uid=0, modes=(0o600,))[0] == state_raw
        and engine.read_file(CONFIG, 16384, uid=0, modes=(0o600,))[0] == config_raw,
        'PREDECESSOR_CHANGED_DURING_READ')
    result = {'format': 1, 'kind': 'go-erp-workspace-predecessor-observation', 'status': 'accepted-published',
        'predecessor': previous, 'private_hashes': evidence, 'original_ci': runs,
        'new_allocation_allowed': False, 'artifact_replacement_allowed': False}
    return {**result, 'observation_sha256': sha(canonical(result))}


def revalidate_bootstrap(observation, release, engine, *, verify_locks):
    need(isinstance(observation, dict) and observation.get('observation_sha256') == sha(canonical({
        key:value for key,value in observation.items() if key != 'observation_sha256'})), 'OBSERVATION_BYTES_CHANGED')
    current = bootstrap_predecessor(release, engine, verify_locks=verify_locks)
    need(canonical(current) == canonical(observation), 'OBSERVED_PREDECESSOR_CHANGED')
    return current


def checked_pins(value):
    need(isinstance(value, dict) and set(value) == {'core', 'seed', 'web_release', 'policy',
        'inventory_sha256', 'contract_sha256', 'context_sha256', 'native_payloads', 'manual_programs',
        'predecessor'}, 'FINAL_PINS_UNAVAILABLE')
    need(all(isinstance(value[name], str) and REV.fullmatch(value[name]) for name in ('core', 'seed', 'web_release'))
        and value['core'] != value['seed'] and value['policy'] == POLICY
        and all(isinstance(value[name], str) and SHA.fullmatch(value[name]) for name in
            ('inventory_sha256', 'contract_sha256', 'context_sha256')), 'FINAL_PINS_INVALID')
    previous = value['predecessor']
    need(isinstance(previous, dict) and set(previous) == {'counter', 'candidate', 'version',
        'inventory_sha256', 'release_sha256', 'acceptance_receipt_sha256'}
        and type(previous['counter']) is int and previous['counter'] in PROFILES
        and previous['version'] == '0.4.4-preview.' + str(previous['counter'])
        and isinstance(previous['candidate'], str) and REV.fullmatch(previous['candidate'])
        and all(isinstance(previous[name], str) and SHA.fullmatch(previous[name]) for name in
            ('inventory_sha256', 'release_sha256', 'acceptance_receipt_sha256')),
        'PREDECESSOR_PINS_INVALID')
    if previous['counter'] == 59:
        need(previous['candidate'] == '5becb9bab38a91998d1b1341670b2b398e4ba70f', 'PUBLISHED_59_CHANGED')
    payloads = value['native_payloads']
    need(isinstance(payloads, dict) and set(payloads) == {'desktop_dashboard_server_preflight.py',
        'diagnose_desktop_dashboard_current_source.py', 'go_erp_current_candidate_ci.yml',
        'go_erp_live_source.py', 'go_erp_live_updates.py', 'publish_go_erp_update.py',
        'verify_go_erp_current_candidate_ci.py'}, 'NATIVE_PAYLOAD_ROSTER_CHANGED')
    for pin in payloads.values():
        need(isinstance(pin, dict) and set(pin) == {'blob', 'size', 'sha256'}
            and isinstance(pin['blob'], str) and REV.fullmatch(pin['blob'])
            and type(pin['size']) is int and 0 < pin['size'] <= 2 * 1024**2
            and isinstance(pin['sha256'], str) and SHA.fullmatch(pin['sha256']), 'NATIVE_PAYLOAD_PIN_INVALID')
    need(isinstance(value['manual_programs'], dict) and set(value['manual_programs']) == MANUAL_NAMES,
        'MANUAL_PROGRAM_ROSTER_CHANGED')
    for name, pin in value['manual_programs'].items():
        need(isinstance(pin, dict) and set(pin) == {'path', 'sha256'}
            and isinstance(pin['path'], str) and re.fullmatch(r'deployment/go_erp_[a-z0-9_]+\.py', pin['path'])
            and isinstance(pin['sha256'], str) and SHA.fullmatch(pin['sha256']), 'MANUAL_PROGRAM_PIN_INVALID')
    need(len({pin['path'] for pin in value['manual_programs'].values()}) == len(MANUAL_NAMES),
        'MANUAL_PROGRAM_PATH_COLLISION')
    need(value['manual_programs']['go_erp_published_download_root.py']['path']
        == 'deployment/go_erp_published_download_root.py', 'PUBLISHED_ARCHIVE_PROGRAM_CHANGED')
    return copy.deepcopy(value)


def checked_published_state(state, publication, pins):
    previous = pins['predecessor'];profile = PROFILES[previous['counter']]
    need(isinstance(state, dict) and type(state.get('format')) is int and state['format'] == 1
        and state.get('base_revision') == profile['seed']
        and type(state.get('counter')) is int and state['counter'] == previous['counter']
        and state.get('last_remote_sha') == previous['candidate'], 'EXACT_PUBLISHED_INPUT_REQUIRED')
    desired, published = state.get('desired'), state.get('published')
    need(isinstance(desired, dict) and isinstance(published, dict)
        and desired.get('status') == 'published' and desired.get('pushed') is True
        and desired.get('candidatePolicy') == profile['policy']
        and desired.get('commit') == published.get('commit') == previous['candidate']
        and desired.get('inventorySha256') == published.get('inventorySha256') == previous['inventory_sha256']
        and desired.get('version') == previous['version'] == state.get('version_floor')
        and isinstance(publication, dict) and publication == published.get('release')
        and publication.get('source_revision') == previous['candidate'] and publication.get('version') == previous['version']
        and type(state.get('sequence_floor')) is int and 1 <= state['sequence_floor'] <= SAFE
        and publication.get('sequence') == state['sequence_floor']
        and publication in state.get('published_history', [])
        and sha(canonical(publication)) == previous['release_sha256'], 'PUBLICATION_HISTORY_OR_RECEIPT_CHANGED')
    return desired


def configure(template_raw, release, engine, *, pins=None, package_payloads=None,
        native_transform=None, verify_locks=None, workspace_acceptance=None):
    """Return the actual configured original module without constructing Context.

    The loader must verify this module's own context_sha256 before calling it.
    Transform callbacks cannot substitute arbitrary worker bytes: every resulting
    payload must match its independently compiled blob/size/SHA256 triple.
    """
    pins = checked_pins(PINS if pins is None else pins)
    need(isinstance(template_raw, bytes) and sha(template_raw) == TEMPLATE_SHA256,
        'FROZEN_TEMPLATE_PIN_CHANGED')
    need(callable(verify_locks) and verify_locks() is not False, 'HELD_LOCK_VERIFIER_REQUIRED')
    need(isinstance(package_payloads, dict) and set(package_payloads) == MANUAL_NAMES,
        'APPROVED_MANUAL_PROGRAMS_REQUIRED')
    for name, raw in package_payloads.items():
        need(isinstance(raw, bytes) and 0 < len(raw) <= 2 * 1024**2
            and sha(raw) == pins['manual_programs'][name]['sha256'], 'MANUAL_PROGRAM_BYTES_CHANGED')
        compile(raw, name, 'exec')
    template = types.ModuleType('go_erp_workspace_original_' + pins['core'])
    template.__file__ = '<sha256-pinned-original-native-rollout>'
    sys.modules[template.__name__] = template
    exec(compile(template_raw, template.__file__, 'exec'), template.__dict__)
    template.Stop = Stop
    previous = pins['predecessor'];profile = PROFILES[previous['counter']]
    tick_sha = profile['manual_pins']['go_erp_manual_tick_root.py']
    for key, value in {'OLD_TICK_SHA': tick_sha, 'PREVIOUS': profile['operator_release'],
        'OLD_CORE': profile['core'], 'OLD_SEED': profile['seed'], 'OLD_RUNTIME': profile['runtime'],
        'OLD_POLICY': profile['policy'], 'OLD_SUFFIX': profile['policy'].removeprefix('historical-baseline-v2').encode(),
        'CORE': pins['core'], 'SEED': pins['seed'], 'POLICY': POLICY, 'SUFFIX': SUFFIX,
        'INPUT_CANDIDATE': previous['candidate'], 'INPUT_VERSION': previous['version'],
        'OLD_MANUAL_PINS': copy.deepcopy(profile['manual_pins']),
        'MANUAL_PINS': {name: pin['sha256'] for name, pin in pins['manual_programs'].items()},
        'PACKAGE_PROGRAM_PATHS': {name: pin['path'] for name, pin in pins['manual_programs'].items()}}.items():
        setattr(template, key, value)
    template.checked_published_state = lambda state, publication: checked_published_state(state, publication, pins)

    def checked_native_core(actual_release):
        need(verify_locks() is not False, 'HELD_LOCK_CHANGED')
        # This preserves an exact backend guard. The new native core contains
        # the separately reviewed web tree, while dependency changes stay closed.
        need(actual_release.git('diff-tree', '-r', '--no-commit-id', '--name-only', '--no-renames', '--no-ext-diff',
            pins['web_release'], pins['core'], '--', *BACKEND) == b'', 'CORE_BACKEND_DIFFERS_FROM_REVIEWED_WEB')
        need(actual_release.git('diff-tree', '-r', '--no-commit-id', '--name-only', '--no-renames', '--no-ext-diff',
            profile['core'], pins['core'], '--', 'composer.json', 'composer.lock') == b'',
            'DEPENDENCY_GUARD_CHANGED')
        need(verify_locks() is not False, 'HELD_LOCK_CHANGED')
    template.checked_native_core = checked_native_core
    original_derive = template.derive_native_payloads

    def derive(payloads, old_pins, seed):
        need(seed == pins['seed'], 'DERIVED_SEED_CHANGED')
        result = original_derive(payloads, old_pins, seed)
        if native_transform is not None:
            need(callable(native_transform), 'NATIVE_TRANSFORM_INVALID')
            result = native_transform(copy.deepcopy(result))
        need(isinstance(result, dict) and set(result) == set(pins['native_payloads']), 'DERIVED_NATIVE_ROSTER_CHANGED')
        for name, raw in result.items():
            pin = pins['native_payloads'][name]
            need(isinstance(raw, bytes) and (blob(raw), len(raw), sha(raw))
                == (pin['blob'], pin['size'], pin['sha256']), 'DERIVED_NATIVE_PAYLOAD_CHANGED')
            if name.endswith('.py'):compile(raw, name, 'exec')
        return result
    template.derive_native_payloads = derive
    original_context, original_release = template.Context, template.Release

    class BoundRelease(original_release):
        def file(self, path, revision=None):
            if path == 'deployment/go_erp_startup_session_tick_root.py' and revision == profile['operator_release']:
                raw = super().file(profile['tick_path'], revision)
                need(sha(raw) == tick_sha, 'PREVIOUS_TICK_BYTES_CHANGED')
                return raw
            for name, pin in pins['manual_programs'].items():
                if path == pin['path']:
                    return package_payloads[name]
            return super().file(path, revision)
    template.Release = BoundRelease

    class Context(original_context):
        def __init__(self, actual_release, actual_engine):
            need(verify_locks() is not False, 'HELD_LOCK_CHANGED')
            need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
                'ROOT_HOST_REQUIRED')
            if not isinstance(actual_release, BoundRelease):
                actual_release = BoundRelease(actual_release.repository, actual_release.revision)
            super().__init__(actual_release, actual_engine)
            need((self.owner.pw_uid, self.owner.pw_gid) == (1001, 1003), 'APPLICATION_IDENTITY_CHANGED')
            self.job_path = template.CONTROL / 'private' / ('shared-workspace-' + pins['core'] + '.json')
            self.prior_job_path = template.CONTROL / 'private' / (profile['job_prefix'] + profile['core'] + '.json')
            self.approved_worker_sha256 = pins['native_payloads']['go_erp_live_updates.py']['sha256']
            self.approved_context_sha256 = pins['context_sha256']
            original_utility = self.utility;context = self
            class HeldUtility:
                def __getattr__(self, name):return getattr(original_utility, name)
                @contextmanager
                def watcher_lock(self, work):
                    need(work == template.WORK and verify_locks() is not False, 'WATCHER_LOCK_CHANGED')
                    with original_utility.watcher_lock(work) as verify:
                        old = getattr(context, 'held_verify', None);context.held_verify = verify
                        try:yield verify
                        finally:context.held_verify = old
            self.utility = HeldUtility()

        def previous_proof(self):
            need(verify_locks() is not False, 'HELD_LOCK_CHANGED')
            state = self.state() if self.job is None else self.base.parse(self.e.read_file(
                Path(self.job['audit']) / 'previous-state.json', 16 * 1024**2, uid=0, modes=(0o600,))[0])
            checked_published_state(state, (state.get('published') or {}).get('release'), pins)
            raw = self.e.read_file(self.prior_job_path, 16 * 1024**2, uid=0, modes=(0o600,))[0]
            prior = self.base.parse(raw);publication = state['published']['release']
            need(type(prior.get('format')) is int and prior['format'] == 1
                and prior.get('kind') == 'go-erp-warm-start-rollout' and prior.get('core') == profile['core']
                and prior.get('seed') == profile['seed'] and prior.get('runtime') == profile['runtime']
                and prior.get('phase') == 'ready' and prior.get('candidate') == previous['candidate']
                and prior.get('version') == previous['version'] and prior.get('source') == publication.get('source')
                and prior.get('inventory') == previous['inventory_sha256'], 'PREDECESSOR_JOB_NOT_READY')
            expected = self.old_worker.program_gate_expected(profile['core'], prior['source'], prior['inventory'])
            completed_raw = self.e.read_file(template.WORK / ('program-rollout-completed-' + profile['core'] + '.json'),
                2 * 1024**2, uid=0, modes=(0o600,))[0]
            need(self.old_worker.program_gate_completed(template.WORK, expected, state, lambda: publication),
                'PREDECESSOR_PROGRAM_ACCEPTANCE_CHANGED')
            completed = self.base.parse(completed_raw);authorization = completed.get('authorization')
            need(isinstance(authorization, dict) and authorization.get('candidate') == previous['candidate']
                and authorization.get('version') == previous['version'] and authorization.get('release') == publication
                and authorization.get('installer') == publication.get('installer')
                and authorization.get('receipt_sha256') == previous['acceptance_receipt_sha256']
                and isinstance(authorization.get('receipt_path'), str), 'PREDECESSOR_RECEIPT_BINDING_CHANGED')
            receipt_path = Path(authorization['receipt_path'])
            need(receipt_path.parent == template.WORK / 'program-rollout-authorizations'
                and receipt_path.name == 'receipt-' + previous['acceptance_receipt_sha256'] + '.json',
                'PREDECESSOR_RECEIPT_PATH_CHANGED')
            receipt_raw = self.e.read_file(receipt_path, 2 * 1024**2, uid=0, modes=(0o600,))[0]
            receipt = self.base.parse(receipt_raw)
            need(sha(receipt_raw) == previous['acceptance_receipt_sha256']
                and receipt.get('candidate_revision') == previous['candidate'] and receipt.get('version') == previous['version']
                and all(receipt.get(name) == value for name, value in expected.items())
                and receipt.get('installer') == publication.get('installer')
                and receipt.get('employee_evidence') == authorization.get('employee_evidence'),
                'PREDECESSOR_ACCEPTANCE_BYTES_CHANGED')
            return raw, completed_raw, receipt_raw

        def verify_predecessor(self):return self.previous_proof()[0]

        def quiet_hold(self):
            super().quiet_hold()
            pending = self.e.read_file(template.WORK / 'pending-program-acceptance.json',
                2 * 1024**2, uid=0, modes=(0o600,), optional=True)
            need(pending is not None and pending[0] == self.previous_proof()[-1], 'PREDECESSOR_PENDING_RECEIPT_CHANGED')

        def job_record(self):
            super().job_record()
            need(self.job['inventory'] == pins['inventory_sha256']
                and self.job['remote_lease'] == previous['candidate'], 'CAPTURED_SOURCE_OR_REMOTE_CHANGED')
            old, completed, receipt = self.previous_proof();audit = Path(self.job['audit'])
            for name, raw in (('previous-rollout.json', old), ('previous-completed.json', completed),
                    ('previous-pending-acceptance.json', receipt)):
                current = self.e.read_file(audit / name, 16 * 1024**2, uid=0, modes=(0o600,), optional=True)
                if current is None:self.e.atomic_write(audit / name, raw, 0, 0, 0o600)
                else:need(current[0] == raw, 'PREDECESSOR_AUDIT_CHANGED')

        def prepare(self, locked_utility):
            need(verify_locks() is not False, 'HELD_LOCK_CHANGED')
            self.worker.require_space(template.WORK,
                sum(row.get('bytes', 0) for row in self.captured_metadata()['inventory'].values()) * 3 + 128 * 1024**2)
            self.worker.owned_directory(template.WORK / 'staging')
            return super().prepare(locked_utility)

        def candidate(self, state):
            desired = state.get('desired') or {}
            need(type(state.get('counter')) is int and state['counter'] == previous['counter'] + 1
                and desired.get('version') == '0.4.4-preview.' + str(previous['counter'] + 1),
                'ONE_TARGET_COUNTER_REQUIRED')
            return super().candidate(state)

        def tick(self, allow, budget=2700):
            need(type(allow) is bool and verify_locks() is not False, 'TICK_SCOPE_INVALID')
            state = self.state()
            if allow:
                need(type(state.get('counter')) is int and state['counter'] == previous['counter']
                    and template.allocation_unconsumed(self.job, state), 'BASELINE_ALLOCATION_ALREADY_CONSUMED')
            else:
                need(type(state.get('counter')) is int and state['counter'] == previous['counter'] + 1
                    and (state.get('desired') or {}).get('version') == '0.4.4-preview.' + str(previous['counter'] + 1),
                    'ONE_TARGET_COUNTER_REQUIRED')
            return super().tick(allow, budget)

        def authorized(self):
            # The inherited complete loop reaches this override too. It cannot
            # publish through the older receipt alone before workspace coverage
            # and installed-native lifecycle acceptance are proved.
            need(callable(workspace_acceptance), 'WORKSPACE_ACCEPTANCE_VERIFIER_REQUIRED')
            state = self.state();self.live(state);desired = self.candidate(state)
            proof = workspace_acceptance(desired['commit'], desired['version'], pins['contract_sha256'])
            need(isinstance(proof, dict) and proof.get('candidate_revision') == desired['commit']
                and proof.get('version') == desired['version'] and proof.get('contract_sha256') == pins['contract_sha256']
                and all(proof.get(name) is True for name in ('latest_required_ci_accepted',
                    'full_native_lifecycle_accepted')),
                'WORKSPACE_ACCEPTANCE_REQUIRED')
            return super().authorized()

        def review_existing_ci(self):
            state = self.state();desired = state.get('desired') or {}
            if desired.get('status') not in ('retry-build', 'blocked'):
                return state
            need(self.job.get('prepare_started') is True and self.job.get('baseline_counter') == previous['counter']
                and self.job.get('candidate') == desired.get('commit') == state.get('last_remote_sha')
                and self.job.get('version') == desired.get('version')
                and desired.get('inventorySha256') == self.job['inventory']
                and desired.get('candidatePolicy') == POLICY and desired.get('pushed') is True,
                'LATCHED_CI_REVIEW_BINDING_CHANGED')
            self.live(state);self.candidate(state)
            config = self.worker.read_config()
            need(config.get('base_revision') == pins['seed'], 'CI_REVIEW_CONFIG_CHANGED')
            client = self.worker.GitHub(config['token']);runs = {}
            for workflow in WORKFLOWS:
                run = self.worker.accepted_run(client, workflow, desired['commit'])
                need(isinstance(run, dict) and type(run.get('id')) is int and 1 <= run['id'] <= SAFE
                    and type(run.get('run_attempt')) is int and 1 <= run['run_attempt'] <= SAFE,
                    'SAME_CANDIDATE_SUCCESSFUL_CI_REQUIRED')
                runs[workflow] = {'id': run['id'], 'attempt': run['run_attempt']}
            self.live(state);self.candidate(state)
            need(self.state() == state and verify_locks() is not False, 'STATE_CHANGED_DURING_CI_REVIEW')
            raw = self.e.canonical(state);audit = Path(self.job['audit'])
            review = {'format': 1, 'kind': 'go-erp-shared-workspace-ci-review',
                'candidate': desired['commit'], 'version': desired['version'], 'inventory': self.job['inventory'],
                'core': pins['core'], 'seed': pins['seed'], 'before_state_sha256': sha(raw), 'runs': runs}
            for name, value in (('workspace-ci-state-' + sha(raw) + '.json', raw),
                    ('workspace-ci-review-' + sha(canonical(review)) + '.json', canonical(review))):
                current = self.e.read_file(audit / name, 16 * 1024**2, uid=0, modes=(0o600,), optional=True)
                if current is None:self.e.atomic_write(audit / name, value, 0, 0, 0o600)
                else:need(current[0] == value, 'CI_REVIEW_AUDIT_CHANGED')
            result = copy.deepcopy(state);result['desired']['status'] = 'waiting-ci';result['desired']['retry_at'] = 0
            self.e.atomic_write(template.WORK / 'state/current.json', self.e.canonical(result), 0, 0, 0o600)
            return result

        def complete(self):
            template.quiet(self.e)
            with self.utility.watcher_lock(template.WORK):
                if self.job['candidate'] is not None:
                    self.review_existing_ci()
                    pending = self.e.read_file(template.WORK / 'pending-program-acceptance.json',
                        2 * 1024**2, uid=0, modes=(0o600,), optional=True)
                    if pending is not None:
                        receipt = self.base.parse(pending[0])
                        if receipt.get('candidate_revision') == self.job['candidate']:
                            need(receipt.get('version') == self.job['version']
                                and all(receipt.get(key) == value for key, value in self.expected.items()),
                                'RETAINED_ACCEPTANCE_BINDING_CHANGED')
                            if not self.ready(self.state()):
                                self.authorized()
                        else:
                            old = self.e.read_file(Path(self.job['audit']) / 'previous-pending-acceptance.json',
                                2 * 1024**2, uid=0, modes=(0o600,))[0]
                            need(pending[0] == old, 'PENDING_ACCEPTANCE_CHANGED')
            return super().complete()

    template.Context = Context
    template.approved_pins = copy.deepcopy(pins)
    template.original_context = original_context
    return template


def create_context(template_raw, release, engine, *, pins=None, package_payloads=None,
        native_transform=None, verify_locks=None, workspace_acceptance=None):
    template = configure(template_raw, release, engine, pins=pins, package_payloads=package_payloads,
        native_transform=native_transform, verify_locks=verify_locks, workspace_acceptance=workspace_acceptance)
    return template, template.Context(release, engine)


if __name__ == '__main__':
    raise SystemExit('GO_ERP_SHARED_WORKSPACE_NATIVE_FINAL_PINS_UNAVAILABLE')
