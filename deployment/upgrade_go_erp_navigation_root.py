#!/usr/bin/env python3
"""Upgrade the exact published .58 native cohort through the original rollout gates."""
import argparse
import copy
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import types

TEMPLATE_RELEASE = '12d74b4f35737a2b1e271657b159137538c3edaf'
TEMPLATE_SHA256 = '70bfb190e3b53fcb63ee76eb3ebfe16621be524e65ce623b4418386550081861'
REPAIR_RELEASE = '56c8680ac8d3d5cb68eaebd623daeba897cc3a48'
OLD_CORE = 'df1860734942b5fcb95cbc634e95c4c0b3cca03c'
OLD_SEED = '6c867ad6c79b27ace92ab3a256e01b13e41137d8'
OLD_RUNTIME = 'policy-031fc1271850918331615d3e04d02f9df5ace35cdb2462215c0a2836ce948d3d'
OLD_POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-direct-login-accounts-v3'
OLD_SUFFIX = b'-native-close-reopen-program-contract-direct-login-accounts-v3'
INPUT_CANDIDATE = '02411715ca112f3d4393f1ca1b83d377aa253786'
INPUT_VERSION = '0.4.4-preview.58'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-direct-login-accounts-v4'
SUFFIX = b'-native-close-reopen-program-contract-direct-login-accounts-v4'
# The outer root command is not publishable until all final native and helper
# pins have been supplied and validated by the exact-cohort tests.
PINS_PENDING = False
CORE = '13fd8b038bd1a853a30ba9b4140a053088efa73d'
SEED = '0885a3b550f92df2b74bcd2c16d27954d8b44b7b'
WEB_PINS_PENDING = False
WEB_RELEASE = 'b199706b24146c85760b673448cb2e8bcd1f7812'
WEB_BRANCH = 'codex/go-erp-navigation-web-performance-20261026'
WEB_WORKFLOW = 'go-erp-navigation-web-tests.yml'
WEB_WORKFLOW_SHA256 = 'd0639d8c3b5072dd9fba3d741f65f57539df84d82b229d7117482100165db6f9'
WEB_RUN_ID = 40400653601
WEB_JOBS = ('php-financial-navigation', 'browser-navigation')
SOURCE_HELPER_SHA256 = 'e1f0c655dcc73284bcec289c6ffc5673fa4f2f02e129591f25f43f470ec447e4'
PHP_HELPER_SHA256 = '0fa57cb0d078186c1a156bcb5b50d385a3f0e2918628f7e8e173414994f94bea'
PHP_FPM_UNIT = 'ea-php82-php-fpm.service'
WEB_PATCHES = {
    'app/Services/Dashboard/BranchShiftClosing.php': {'before': '914f479cef4f953a5855477a342ed32bf71303be06d0772865a961991a9008ab', 'after': 'c2201682e22a73e681af2b23254dd057e0e43853c3936db77919041c80a9c2fa'},
    'resources/views/admin/index.blade.php': {'before': '04fce9fed42c33fdcbf8bb466a4b42ddf99f8761f898fde90bfe5f6468186b3e', 'after': '49c1d71508aad71a7edefba8ac86f2396374bf7a505058c05e342463bbfe5918'},
    'public/dashboard/js/dashboard-spa.js': {'before': 'd3d00281be187a137a7d375862b3be6da82dcc7e80daf2045f4d59ce01f178a9', 'after': 'dd23e797e30e222824a484a2ecd88b59d917fac3079e163764c455c086e40d70'},
}
OLD_MANUAL_PINS = {
    'go_erp_manual_tick_root.py': '5f7fca1028d5f2763f614189d79ea5998668de837452daa6cc1a1cd0dac35104',
    'go_erp_manual_updates_root.py': 'd9773d6690b436f85a12f64429a4e1270d15e15fd346873de86912e1f9235682',
    'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b',
}
MANUAL_PINS = {'go_erp_manual_tick_root.py': 'b34a7b87e716acedc50c38a871b1888113b8d10095b4fb0d9f6325ae8f192931',
 'go_erp_manual_updates_root.py': '0090ca4af527180c77895d32c75df86718229fc91ae1c427c50ac778112a42b0',
 'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
SAFE = 9007199254740991


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def public_failure(error):
    # Frozen worker/verifier exceptions have their own classes. Surface only
    # their bounded reason codes, never paths or arbitrary exception messages.
    code = str(error)
    return code if ((isinstance(error, Stop) or code.startswith('GO_ERP_'))
        and re.fullmatch(r'(?:GO_ERP_)?[A-Z0-9_]{1,150}', code)) else 'PRIVATE_FAILURE'


def load_template(raw):
    need(sha(raw) == TEMPLATE_SHA256, 'ROLLOUT_TEMPLATE_PIN_INVALID')
    value = types.ModuleType('go_erp_navigation_original_rollout')
    value.__file__ = '<pinned-go-erp-fresh-source-rollout>'
    sys.modules[value.__name__] = value
    exec(compile(raw, value.__file__, 'exec'), value.__dict__)
    value.Stop = Stop
    return value


def checked_published_state(state, publication):
    desired = state.get('desired') if isinstance(state, dict) else None
    published = state.get('published') if isinstance(state, dict) else None
    need(type(state.get('format')) is int and state['format'] == 1
        and state.get('base_revision') == OLD_SEED and type(state.get('counter')) is int
        and state['counter'] == 58 and state.get('last_remote_sha') == INPUT_CANDIDATE
        and isinstance(desired, dict) and isinstance(published, dict)
        and desired.get('status') == 'published' and desired.get('pushed') is True
        and desired.get('candidatePolicy') == OLD_POLICY
        and desired.get('commit') == published.get('commit') == INPUT_CANDIDATE
        and desired.get('inventorySha256') == published.get('inventorySha256')
        and isinstance(published.get('release'), dict)
        and published['release'].get('source_revision') == INPUT_CANDIDATE
        and published['release'].get('version') == desired.get('version') == state.get('version_floor') == INPUT_VERSION
        and type(state.get('sequence_floor')) is int and 1 <= state['sequence_floor'] <= SAFE
        and published['release'].get('sequence') == state['sequence_floor']
        and isinstance(state.get('published_history'), list) and published['release'] in state['published_history'],
        'EXACT_PUBLISHED_NAVIGATION_INPUT_REQUIRED')
    need(publication == published['release'], 'PUBLIC_FEED_NOT_CURRENT')
    return desired


def checked_previous_job(job, state):
    published = state.get('published') or {}
    release = published.get('release') or {}
    need(type(job.get('format')) is int and job['format'] == 1
        and job.get('kind') == 'go-erp-warm-start-rollout'
        and job.get('core') == OLD_CORE and job.get('seed') == OLD_SEED
        and job.get('runtime') == OLD_RUNTIME and job.get('phase') == 'ready'
        and job.get('candidate') == INPUT_CANDIDATE and job.get('version') == INPUT_VERSION
        and isinstance(job.get('source'), dict) and job['source'] == release.get('source')
        and job.get('inventory') == published.get('inventorySha256'),
        'PREVIOUS_NAVIGATION_INPUT_NOT_READY')
    return job



def reviewed_ci_state(state):
    """Keep the allocation budget, failure evidence and published history intact."""
    result = copy.deepcopy(state)
    result['desired']['status'] = 'waiting-ci'
    result['desired']['retry_at'] = 0
    return result


def review_existing_ci(ctx, template, state):
    """Resume the single latched .59 only after the original latest CI accepts it."""
    desired = state.get('desired') or {}
    if desired.get('status') not in ('retry-build', 'blocked'):
        return state
    need(ctx.job.get('prepare_started') is True and ctx.job.get('baseline_counter') == 58
        and type(ctx.job.get('baseline_counter')) is int
        and type(state.get('counter')) is int and state['counter'] == 59
        and ctx.job.get('version') == desired.get('version') == '0.4.4-preview.59'
        and ctx.job.get('candidate') == desired.get('commit') == state.get('last_remote_sha')
        and isinstance(ctx.job.get('candidate'), str) and REV.fullmatch(ctx.job['candidate'])
        and desired.get('inventorySha256') == ctx.job.get('inventory')
        and desired.get('candidatePolicy') == POLICY and desired.get('pushed') is True,
        'LATCHED_CI_REVIEW_BINDING_INVALID')
    ctx.live(state)
    ctx.candidate(state)
    config = ctx.worker.read_config()
    need(config.get('base_revision') == template.SEED, 'CI_REVIEW_CONFIG_CHANGED')
    client = ctx.worker.GitHub(config['token'])
    runs = {}
    print('GO_ERP_NAVIGATION_REVIEWING_SAME_CANDIDATE_CI', flush=True)
    for workflow in ('desktop-dashboard-tests.yml', 'go-erp-update-tests.yml', 'whatsapp-integrated-regressions.yml'):
        run = ctx.worker.accepted_run(client, workflow, ctx.job['candidate'])
        need(isinstance(run, dict) and type(run.get('id')) is int and 1 <= run['id'] <= SAFE
            and type(run.get('run_attempt')) is int and 1 <= run['run_attempt'] <= SAFE,
            'SAME_CANDIDATE_SUCCESSFUL_CI_REQUIRED')
        runs[workflow] = {'id': run['id'], 'attempt': run['run_attempt']}
    ctx.live(state)
    ctx.candidate(state)
    need(ctx.state() == state, 'STATE_CHANGED_DURING_CI_REVIEW')
    before = ctx.e.canonical(state)
    record = {'format': 1, 'kind': 'go-erp-navigation-ci-review', 'candidate': ctx.job['candidate'],
        'version': ctx.job['version'], 'inventory': ctx.job['inventory'], 'core': template.CORE,
        'seed': template.SEED, 'before_state_sha256': sha(before), 'runs': runs}
    audit = Path(ctx.job['audit'])
    for name, raw in (('navigation-ci-state-' + sha(before) + '.json', before),
            ('navigation-ci-review-' + sha(ctx.e.canonical(record)) + '.json', ctx.e.canonical(record))):
        existing = ctx.e.read_file(audit / name, 16 * 1024**2, uid=0, modes=(0o600,), optional=True)
        if existing is None:
            ctx.e.atomic_write(audit / name, raw, 0, 0, 0o600)
        else:
            need(existing[0] == raw, 'CI_REVIEW_AUDIT_CHANGED')
    result = reviewed_ci_state(state)
    ctx.e.atomic_write(template.WORK / 'state/current.json', ctx.e.canonical(result), 0, 0, 0o600)
    print('GO_ERP_NAVIGATION_SAME_CANDIDATE_CI_REVIEWED', flush=True)
    return result


def web_ci_identity(run):
    need(isinstance(run, dict) and run.get('id') == WEB_RUN_ID and type(run.get('id')) is int
        and type(run.get('run_attempt')) is int and 1 <= run['run_attempt'] <= SAFE
        and run.get('event') == 'push' and run.get('head_branch') == WEB_BRANCH
        and run.get('head_sha') == WEB_RELEASE and not run.get('pull_requests')
        and run.get('path', '').split('@')[0] == '.github/workflows/' + WEB_WORKFLOW
        and run.get('repository', {}).get('id') == 1311412084
        and run.get('repository', {}).get('full_name') == 'omarmakramelbaz-hub/F-Backend'
        and run.get('head_repository', {}).get('id') == 1311412084
        and run.get('head_repository', {}).get('full_name') == 'omarmakramelbaz-hub/F-Backend',
        'WEB_CI_RUN_IDENTITY_INVALID')
    return run


def verify_web_ci(ctx, template):
    need(not WEB_PINS_PENDING and REV.fullmatch(WEB_RELEASE)
        and type(WEB_RUN_ID) is int and 1 <= WEB_RUN_ID <= SAFE
        and SHA.fullmatch(WEB_WORKFLOW_SHA256) and SHA.fullmatch(SOURCE_HELPER_SHA256)
        and WEB_JOBS and len(set(WEB_JOBS)) == len(WEB_JOBS)
        and all(isinstance(name, str) and re.fullmatch(r'[A-Za-z0-9 _().-]{1,100}', name) for name in WEB_JOBS),
        'WEB_PINS_PENDING')
    # Only these separately tested source paths may differ from the accepted
    # native core. The native core itself retains the unchanged application guard.
    names = ctx.release.git('diff-tree', '-r', '--no-commit-id', '--name-only', '--no-renames', '--no-ext-diff',
        OLD_CORE, WEB_RELEASE, '--', 'app', 'bootstrap', 'config', 'database', 'desktop', 'public',
        'resources', 'routes', 'artisan', 'composer.json', 'composer.lock').decode().splitlines()
    need(set(names) == set(WEB_PATCHES) and len(names) == len(WEB_PATCHES), 'WEB_SOURCE_SCOPE_CHANGED')
    need(sha(ctx.release.file('.github/workflows/' + WEB_WORKFLOW, WEB_RELEASE)) == WEB_WORKFLOW_SHA256,
        'WEB_CI_WORKFLOW_PIN_CHANGED')
    payloads = {}
    for name, pin in WEB_PATCHES.items():
        need(set(pin) == {'before', 'after'} and all(SHA.fullmatch(value) for value in pin.values())
            and pin['before'] != pin['after'], 'WEB_SOURCE_PINS_PENDING')
        need(sha(ctx.release.file(name, OLD_CORE)) == pin['before']
            and sha(ctx.release.file(name, INPUT_CANDIDATE)) == pin['before'], 'WEB_SOURCE_BASE_PIN_CHANGED')
        payloads[name] = ctx.release.file(name, WEB_RELEASE)
        need(sha(payloads[name]) == pin['after'], 'WEB_SOURCE_PAYLOAD_PIN_CHANGED')
    config = ctx.base.parse(ctx.e.read_file(template.CONFIG, 16384, uid=0, modes=(0o600,))[0])
    ctx.base.checked_config(config, ctx.state()['base_revision'])
    client = ctx.worker.GitHub(config['token'])
    endpoint = '/repos/omarmakramelbaz-hub/F-Backend/actions/workflows/' + WEB_WORKFLOW + '/runs?branch=' + WEB_BRANCH.replace('/', '%2F') + '&event=push&head_sha=' + WEB_RELEASE
    rows = client.pages(endpoint, 'workflow_runs')
    candidates = [row for row in rows if row.get('head_sha') == WEB_RELEASE and row.get('event') == 'push'
        and row.get('head_branch') == WEB_BRANCH]
    need(candidates, 'WEB_CI_SUCCESS_REQUIRED')
    run = web_ci_identity(max(candidates, key=lambda value: (value.get('id', 0), value.get('run_attempt', 0))))
    need(run.get('status') == 'completed' and run.get('conclusion') == 'success', 'WEB_CI_SUCCESS_REQUIRED')
    jobs = client.pages('/repos/omarmakramelbaz-hub/F-Backend/actions/runs/' + str(run['id'])
        + '/attempts/' + str(run['run_attempt']) + '/jobs', 'jobs')
    accepted = {}
    for name in WEB_JOBS:
        matching = [job for job in jobs if job.get('name') == name]
        need(len(matching) == 1 and type(matching[0].get('id')) is int and 1 <= matching[0]['id'] <= SAFE
            and matching[0].get('status') == 'completed' and matching[0].get('conclusion') == 'success'
            and type(matching[0].get('run_id')) is int and matching[0]['run_id'] == run['id']
            and matching[0].get('head_sha') == WEB_RELEASE
            and type(matching[0].get('run_attempt')) is int and matching[0]['run_attempt'] == run['run_attempt'], 'WEB_CI_JOB_ACCEPTANCE_INCOMPLETE')
        accepted[name] = matching[0]['id']
    return payloads, {'format': 1, 'kind': 'go-erp-navigation-web-ci', 'release': WEB_RELEASE,
        'workflow_sha256': WEB_WORKFLOW_SHA256, 'run_id': run['id'], 'run_attempt': run['run_attempt'],
        'jobs': accepted}


def verify_php_handler(ctx):
    module = getattr(ctx, '_php_attestor', None)
    if module is None:
        template = load_template(ctx.release.file('deployment/upgrade_go_erp_warm_start_root.py', TEMPLATE_RELEASE))
        module = template.module(ctx.release.file('deployment/go_erp_navigation_php_root.py'),
            'go_erp_navigation_php_handler', PHP_HELPER_SHA256)
        module.Stop = Stop
        ctx._php_attestor = module
    result = module.verify(ctx.e)
    if getattr(ctx, 'php_policy', None) is not None:
        need(result['configuration'] == ctx.php_policy['configuration'], 'PHP_HANDLER_CONFIGURATION_CHANGED')
    return result


def reload_php_handler(ctx):
    ctx.php_policy = verify_php_handler(ctx)
    ctx.e.run(['/usr/bin/systemctl', 'reload', PHP_FPM_UNIT])
    verify_php_handler(ctx)


def checked_existing_manual(ctx, template):
    old_name = ctx.e.sha(ctx.e.canonical(dict(sorted(OLD_MANUAL_PINS.items()))))
    runtime = template.MANUAL_RELEASES / old_name
    ctx.e.directory(runtime, 0, 0, 0o555, False)
    need({path.name for path in runtime.iterdir()} == set(OLD_MANUAL_PINS), 'WEB_MANUAL_ROSTER_CHANGED')
    for name, expected in OLD_MANUAL_PINS.items():
        need(sha(ctx.e.read_file(runtime / name, uid=0, modes=(0o444,))[0]) == expected, 'WEB_MANUAL_HELPER_CHANGED')
    units = ctx.e.units(runtime)
    receipt = ctx.base.parse(ctx.e.read_file(template.CONTROL / 'private/installation.json', 8192,
        uid=0, modes=(0o600,))[0])
    need(receipt == {'format': 1, 'kind': 'go-erp-manual-installation', 'release': REPAIR_RELEASE,
        'runtime': old_name, 'units': {name: ctx.e.sha(raw) for name, raw in units.items()}}, 'WEB_MANUAL_RECEIPT_CHANGED')
    for name, raw in units.items():
        need(ctx.e.read_file(template.SYSTEMD / name, 65536, uid=0, modes=(0o644,))[0] == raw,
            'WEB_MANUAL_UNIT_CHANGED')


def apply_web_source(ctx, template):
    need(callable(getattr(ctx, 'held_verify', None)), 'WEB_HELD_WATCHER_LOCK_REQUIRED')
    ctx.held_verify()
    checked_published_state(ctx.state(), ctx.publication())
    ctx.quiet_hold()
    ctx.previous_proof()
    checked_existing_manual(ctx, template)
    payloads, proof = verify_web_ci(ctx, template)
    ctx.php_policy = verify_php_handler(ctx)
    proof['php_handler'] = ctx.php_policy
    module = template.module(ctx.release.file('deployment/go_erp_navigation_source_root.py'),
        'go_erp_navigation_source_transaction', SOURCE_HELPER_SHA256)
    # Keep bounded reviewed reason codes across the pinned module boundary.
    module.Stop = Stop
    proof_raw = ctx.e.canonical(proof)
    audit_parent = template.CONTROL / 'private/audits'
    proof_path = audit_parent / ('navigation-web-ci-' + sha(proof_raw) + '.json')
    existing = ctx.e.read_file(proof_path, 16384, uid=0, modes=(0o600,), optional=True)
    if existing is None:
        ctx.e.atomic_write(proof_path, proof_raw, 0, 0, 0o600)
    else:
        need(existing[0] == proof_raw, 'WEB_CI_AUDIT_CHANGED')
    print('GO_ERP_NAVIGATION_APPLYING_REVIEWED_WEB_SOURCE', flush=True)
    result = module.apply(template.ROOT, audit_parent, WEB_RELEASE, ctx.owner.pw_uid, payloads,
        pins=WEB_PATCHES, verify_locks=ctx.held_verify, after_apply=lambda: reload_php_handler(ctx),
        after_rollback=lambda: reload_php_handler(ctx))
    ctx.held_verify()
    print('GO_ERP_NAVIGATION_WEB_SOURCE_READY', flush=True)
    return result

def configure(template, core=CORE, seed=SEED, manual_pins=MANUAL_PINS, source_update=None):
    """Bind unchanged frozen functions to one reviewed old/new native cohort."""
    need(REV.fullmatch(core) and REV.fullmatch(seed) and core != seed
        and set(manual_pins) == set(OLD_MANUAL_PINS)
        and all(isinstance(value, str) and SHA.fullmatch(value) for value in manual_pins.values()),
        'NATIVE_PINS_PENDING')
    for key, value in {
        'OLD_TICK_SHA': OLD_MANUAL_PINS['go_erp_manual_tick_root.py'],
        'PREVIOUS': REPAIR_RELEASE, 'OLD_CORE': OLD_CORE, 'OLD_SEED': OLD_SEED,
        'OLD_RUNTIME': OLD_RUNTIME, 'OLD_POLICY': OLD_POLICY, 'OLD_SUFFIX': OLD_SUFFIX,
        'CORE': core, 'SEED': seed, 'POLICY': POLICY, 'SUFFIX': SUFFIX,
        'INPUT_CANDIDATE': INPUT_CANDIDATE, 'INPUT_VERSION': INPUT_VERSION,
        'OLD_MANUAL_PINS': copy.deepcopy(OLD_MANUAL_PINS), 'MANUAL_PINS': copy.deepcopy(manual_pins),
        'PACKAGE_PROGRAM_PATHS': {
            'go_erp_manual_updates_root.py': 'deployment/go_erp_navigation_manual_root.py',
            'go_erp_manual_tick_root.py': 'deployment/go_erp_navigation_tick_root.py',
            'go_erp_published_download_root.py': 'deployment/go_erp_published_download_root.py'},
    }.items():
        setattr(template, key, value)
    template.checked_published_state = checked_published_state

    def checked_native_core(release):
        # Published .58 legitimately contains later live website changes.
        # Native-only source must be compared with its actual old native core.
        need(release.git('diff-tree', '--no-commit-id', '--name-only', '--no-renames', '--no-ext-diff',
            OLD_CORE, core, '--', 'app', 'bootstrap', 'config', 'database', 'desktop', 'public',
            'resources', 'routes', 'artisan', 'composer.json', 'composer.lock') == b'',
            'NATIVE_CORE_APPLICATION_OR_DEPENDENCY_CHANGED')

    template.checked_native_core = checked_native_core
    original_release = template.Release

    class Release(original_release):
        def file(self, path, revision=None):
            if path == 'deployment/go_erp_startup_session_tick_root.py' and revision == REPAIR_RELEASE:
                path = 'deployment/go_erp_password_logout_tick_root.py'
            return super().file(path, revision)

    template.Release = Release
    original_context = template.Context

    class Context(original_context):
        def __init__(self, release, engine):
            super().__init__(release, engine)
            self.job_path = template.CONTROL / 'private' / ('navigation-' + core + '.json')
            self.prior_job_path = template.CONTROL / 'private' / ('password-logout-' + OLD_CORE + '.json')
            original_utility = self.utility
            context = self
            class HeldUtility:
                def __getattr__(self, name):
                    return getattr(original_utility, name)
                @contextmanager
                def watcher_lock(self, work):
                    with original_utility.watcher_lock(work) as verify:
                        previous = getattr(context, 'held_verify', None)
                        context.held_verify = verify
                        try:
                            yield verify
                        finally:
                            context.held_verify = previous
            self.utility = HeldUtility()

        def previous_proof(self):
            if self.job is None:
                state = self.state()
                checked_published_state(state, self.publication())
            else:
                state = self.base.parse(self.e.read_file(Path(self.job['audit']) / 'previous-state.json',
                    16 * 1024**2, uid=0, modes=(0o600,))[0])
                checked_published_state(state, (state.get('published') or {}).get('release'))
            raw = self.e.read_file(self.prior_job_path, 16 * 1024**2, uid=0, modes=(0o600,))[0]
            previous = checked_previous_job(self.base.parse(raw), state)
            expected = self.old_worker.program_gate_expected(OLD_CORE, previous['source'], previous['inventory'])
            release = state['published']['release']
            completed_raw = self.e.read_file(template.WORK / ('program-rollout-completed-' + OLD_CORE + '.json'),
                2 * 1024**2, uid=0, modes=(0o600,))[0]
            completed = self.base.parse(completed_raw)
            try:
                verified = self.old_worker.program_gate_completed(template.WORK, expected, state, lambda: release)
            except self.old_worker.ProgramGateError as error:
                code = str(error)
                raise Stop(code if re.fullmatch(r'GO_ERP_[A-Z0-9_]{1,140}', code)
                    else 'PREVIOUS_NAVIGATION_ACCEPTANCE_CHANGED') from None
            need(verified, 'PREVIOUS_NAVIGATION_ACCEPTANCE_CHANGED')
            authorization = completed.get('authorization')
            need(isinstance(authorization, dict) and authorization.get('candidate') == INPUT_CANDIDATE
                and authorization.get('version') == INPUT_VERSION and authorization.get('release') == release
                and authorization.get('installer') == release.get('installer')
                and isinstance(authorization.get('receipt_sha256'), str) and SHA.fullmatch(authorization['receipt_sha256'])
                and isinstance(authorization.get('receipt_path'), str), 'PREVIOUS_NAVIGATION_RECEIPT_UNREVIEWED')
            receipt_path = Path(authorization['receipt_path'])
            need(receipt_path.parent == template.WORK / 'program-rollout-authorizations'
                and receipt_path.name == 'receipt-' + authorization['receipt_sha256'] + '.json',
                'PREVIOUS_NAVIGATION_RECEIPT_PATH_CHANGED')
            receipt_raw = self.e.read_file(receipt_path, 2 * 1024**2, uid=0, modes=(0o600,))[0]
            need(sha(receipt_raw) == authorization['receipt_sha256'], 'PREVIOUS_NAVIGATION_RECEIPT_CHANGED')
            receipt = self.base.parse(receipt_raw)
            need(receipt.get('candidate_revision') == INPUT_CANDIDATE and receipt.get('version') == INPUT_VERSION
                and all(receipt.get(key) == value for key, value in expected.items())
                and receipt.get('installer') == release.get('installer')
                and receipt.get('employee_evidence') == authorization.get('employee_evidence'),
                'PREVIOUS_NAVIGATION_RECEIPT_BINDING_CHANGED')
            return raw, completed_raw, receipt_raw

        def verify_predecessor(self):
            return self.previous_proof()[0]

        def quiet_hold(self):
            super().quiet_hold()
            _, _, receipt = self.previous_proof()
            pending = self.e.read_file(template.WORK / 'pending-program-acceptance.json', 2 * 1024**2,
                uid=0, modes=(0o600,), optional=True)
            need(pending is not None and pending[0] == receipt, 'PREVIOUS_NAVIGATION_PENDING_RECEIPT_CHANGED')

        def job_record(self):
            initial = self.e.read_file(self.job_path, 16 * 1024**2, uid=0, modes=(0o600,), optional=True) is None
            state_raw = self.e.read_file(template.WORK / 'state/current.json', 16 * 1024**2,
                uid=0, modes=(0o600,))[0] if initial else None
            if initial:
                self.web_source = (source_update or (lambda context: apply_web_source(context, template)))(self)
            super().job_record()
            previous, completed, receipt = self.previous_proof()
            audit = Path(self.job['audit'])
            for name, raw in (('previous-rollout.json', previous), ('previous-completed.json', completed),
                    ('previous-pending-acceptance.json', receipt)):
                existing = self.e.read_file(audit / name, 16 * 1024**2, uid=0, modes=(0o600,), optional=True)
                if existing is None:
                    self.e.atomic_write(audit / name, raw, 0, 0, 0o600)
                else:
                    need(existing[0] == raw, 'PREVIOUS_NAVIGATION_AUDIT_CHANGED')
            if initial:
                need(self.base.parse(state_raw) == self.base.parse(self.e.read_file(audit / 'previous-state.json',
                    16 * 1024**2, uid=0, modes=(0o600,))[0]), 'PREVIOUS_NAVIGATION_STATE_CHANGED')
                self.e.atomic_write(audit / 'previous-state-raw.json', state_raw, 0, 0, 0o600)
            need(self.job.get('remote_lease') == INPUT_CANDIDATE, 'NAVIGATION_REMOTE_LEASE_CHANGED')

        def manual(self):
            receipt = self.base.parse(self.e.read_file(template.CONTROL / 'private/installation.json',
                8192, uid=0, modes=(0o600,))[0])
            old_runtime = self.e.sha(self.e.canonical(dict(sorted(OLD_MANUAL_PINS.items()))))
            new_runtime = self.e.sha(self.e.canonical(dict(sorted(template.MANUAL_PINS.items()))))
            need(set(receipt) == {'format', 'kind', 'release', 'runtime', 'units'}
                and ((receipt.get('release') == REPAIR_RELEASE and receipt.get('runtime') == old_runtime)
                    or (receipt.get('release') == self.release.revision and receipt.get('runtime') in (old_runtime, new_runtime))),
                'NAVIGATION_MANUAL_RECEIPT_UNREVIEWED')
            return super().manual()

        def prepare(self, locked_utility):
            # Check the exact original allocation budget before starting the
            # cohort migration or consuming any next_counter reservation.
            self.worker.require_space(template.WORK,
                sum(row.get('bytes', 0) for row in self.captured_metadata()['inventory'].values()) * 3 + 128 * 1024**2)
            self.worker.owned_directory(template.WORK / 'staging')
            return super().prepare(locked_utility)

        def complete(self):
            # A crash after collection retains the original accepted installer
            # and employee receipts. Resume their original authorization before
            # asking tick to download that same artifact again. Publication is
            # still owned by the unchanged worker and fresh GitHub provenance.
            template.quiet(self.e)
            with self.utility.watcher_lock(template.WORK):
                state = self.state()
                if self.job['candidate'] is not None and not self.ready(state):
                    desired = state.get('desired') or {}
                    need(desired.get('commit') == self.job['candidate']
                        and desired.get('version') == self.job['version']
                        and desired.get('candidatePolicy') == POLICY
                        and desired.get('inventorySha256') == self.job['inventory']
                        and desired.get('pushed') is True
                        and state.get('last_remote_sha') == self.job['candidate'],
                        'LATCHED_CANDIDATE_CHANGED')
                    state = review_existing_ci(self, template, state)
                    pending = self.e.read_file(template.WORK / 'pending-program-acceptance.json',
                        2 * 1024**2, uid=0, modes=(0o600,), optional=True)
                    if pending is not None:
                        receipt = self.base.parse(pending[0])
                        need(isinstance(receipt, dict), 'PENDING_ACCEPTANCE_UNREVIEWED')
                        if receipt.get('candidate_revision') == self.job['candidate']:
                            need(receipt.get('version') == self.job['version']
                                and all(receipt.get(key) == value for key, value in self.expected.items()),
                                'ACCEPTANCE_BINDING_INVALID')
                            print('GO_ERP_NAVIGATION_RESUMING_RETAINED_ACCEPTANCE', flush=True)
                            self.authorized()
                        else:
                            previous = self.e.read_file(Path(self.job['audit']) / 'previous-pending-acceptance.json',
                                2 * 1024**2, uid=0, modes=(0o600,))[0]
                            need(pending[0] == previous, 'PENDING_ACCEPTANCE_UNREVIEWED')
            return super().complete()

    template.Context = Context
    return template


def read_template(repository):
    result = subprocess.run(['/usr/bin/git', '--git-dir=' + str(repository), '-c', 'credential.helper=',
        '-c', 'core.hooksPath=/dev/null', 'show', TEMPLATE_RELEASE + ':deployment/upgrade_go_erp_warm_start_root.py'],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=180,
        env={'PATH': '/usr/bin:/bin', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
            'GIT_CONFIG_SYSTEM': '/dev/null', 'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_TERMINAL_PROMPT': '0',
            'GIT_OPTIONAL_LOCKS': '0'})
    need(result.returncode == 0 and len(result.stdout) <= 2 * 1024**2, 'ROLLOUT_TEMPLATE_READ_FAILED')
    return load_template(result.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir', required=True); parser.add_argument('--release', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--apply', action='store_true'); mode.add_argument('--complete', action='store_true')
    args = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524' and REV.fullmatch(args.release),
        'ROOT_HOST_RELEASE_REQUIRED')
    need(not PINS_PENDING, 'NATIVE_PINS_PENDING')
    need(not WEB_PINS_PENDING, 'WEB_PINS_PENDING')
    os.umask(0o077)
    template = configure(read_template(args.git_dir))
    release = template.Release(args.git_dir, args.release)
    engine = template.module(release.file('deployment/apply_go_erp_manual_updates_root.py', template.BOOTSTRAP),
        'navigation_file_engine', template.ENGINE_SHA)
    engine.Stop = Stop
    repository = release.repository.absolute(); engine.parents(repository)
    need(repository.resolve() == repository and repository.stat().st_uid == 0 and not repository.stat().st_mode & 0o022,
        'REPOSITORY_UNSAFE')
    need(release.git('rev-parse', args.release + '^{commit}').decode().strip() == args.release, 'RELEASE_INVALID')
    result = template.execute(release, engine, args.complete)
    print(json.dumps({'format': 1, 'kind': 'go-erp-navigation-rollout', **result,
        'database_changed': False, 'credentials_disclosed': False, 'automatic_updates_enabled': False}), flush=True)
    print('GO_ERP_NAVIGATION_PUBLISHED_MANUAL_UPDATES_ONLY' if result['published']
        else 'GO_ERP_NAVIGATION_PREPARED_PUBLICATION_HELD')


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        code = public_failure(error)
        print(json.dumps({'format': 1, 'kind': 'go-erp-navigation-rollout', 'ok': False,
            'code': 'GO_ERP_NAVIGATION_' + code, 'published': False, 'database_changed': False,
            'credentials_disclosed': False, 'automatic_updates_enabled': False}), flush=True)
        raise SystemExit(1)
