#!/usr/bin/env python3
"""Read-only protocol-2 release planning; this is deliberately not a root installer.

Git reads never fetch. Plans contain source hashes, never payloads or credentials.
Pure checkpoint/env helpers are intended for a separately pinned coordinator.
No function in this module deploys, migrates, allocates, activates or publishes.
"""
import argparse
import hashlib
import json
import os
from pathlib import PurePosixPath
import re
import subprocess
import sys

REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
SAFE = 9007199254740991
MAX_FILE = 2 * 1024**2
MAX_PLAN_BYTES = 64 * 1024**2
INPUT_CANDIDATE = '5becb9bab38a91998d1b1341670b2b398e4ba70f'
OLD_SEED = '0885a3b550f92df2b74bcd2c16d27954d8b44b7b'
FAST_SEED = 'f3a8b4af4ec27042a93328c1fde200cfd8fe1da3'
FAST_CORE = '9867dc29357e16290b3a1eeda996d916af701915'
FAST_POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-direct-login-accounts-v5'
FLAG = b'DESKTOP_DASHBOARD_WORKSPACE_ENABLED'
MIGRATIONS = {
    'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php': (
        'desktop_dashboard_workspaces', 'desktop_dashboard_workspace_members',
        'desktop_dashboard_workspace_manifests'),
    'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php': (
        'desktop_dashboard_ws_command_auth',),
}
PRIVATE_TABLES = (
    'desktop_dashboard_workspace_local_authority',
    'desktop_dashboard_workspace_local_members',
    'desktop_dashboard_ws_authority_history',
)
REQUIRED_RELEASE_PINS = (
    'native_core', 'seed', 'web_release', 'policy', 'runtime_sha256',
    'source_helper_sha256', 'migration_helper_sha256', 'activation_helper_sha256',
    'payload_helper_sha256', 'manual_helper_sha256', 'tick_helper_sha256',
    'workflow_sha256', 'acceptance_contract_sha256',
)


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_PLAN_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def parse(raw):
    def unique(items):
        result = {}
        for key, value in items:
            need(key not in result, 'JSON_DUPLICATE_KEY')
            result[key] = value
        return result
    def nonfinite(_):
        raise Stop('GO_ERP_SHARED_WORKSPACE_PLAN_JSON_NONFINITE')
    value = json.loads(raw, object_pairs_hook=unique, parse_constant=nonfinite)
    need(isinstance(value, dict), 'JSON_OBJECT_REQUIRED')
    return value


class Git:
    """Read existing objects with no hooks, attributes, credentials or lazy fetch."""
    def __init__(self, directory):
        self.directory = os.path.abspath(directory)

    def run(self, *args, limit=MAX_PLAN_BYTES):
        result = subprocess.run(['/usr/bin/git', '--git-dir=' + self.directory,
            '-c', 'credential.helper=', '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.attributesFile=/dev/null', *args], stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=180,
            env={'PATH': '/usr/bin:/bin', 'GIT_CONFIG_NOSYSTEM': '1',
                'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_SYSTEM': '/dev/null',
                'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_TERMINAL_PROMPT': '0',
                'GIT_NO_LAZY_FETCH': '1', 'GIT_OPTIONAL_LOCKS': '0'})
        need(result.returncode == 0 and len(result.stdout) <= limit, 'GIT_READ_FAILED')
        return result.stdout

    def commit(self, revision):
        need(isinstance(revision, str) and REV.fullmatch(revision)
            and revision != '0' * 40, 'COMMIT_PIN_REQUIRED')
        need(self.run('rev-parse', revision + '^{commit}', limit=100).strip().decode() == revision,
            'COMMIT_OBJECT_REQUIRED')

    def files(self, revision):
        rows = self.run('ls-tree', '-r', '-z', revision).split(b'\0')
        files = {}
        for row in filter(None, rows):
            facts, encoded = row.split(b'\t', 1)
            name = encoded.decode('utf-8')
            mode, kind, blob = facts.decode('ascii').split(' ')
            files[name] = (mode, kind, blob)
        return files

    def blob(self, blob):
        return self.run('cat-file', 'blob', blob, limit=MAX_FILE)


def path_scope(name):
    need(isinstance(name, str) and name and len(name) <= 300
        and all(32 < ord(char) < 127 for char in name)
        and not any(char in name for char in ('\\', ':'))
        and str(PurePosixPath(name)) == name and not name.startswith('/')
        and all(part not in ('.', '..') and (not part.startswith('.')
            or index == 0 and part == '.github') for index, part in enumerate(name.split('/'))),
        'SOURCE_PATH_INVALID')
    if name.startswith('database/'):
        need(name in MIGRATIONS, 'EXISTING_OR_UNREVIEWED_DATABASE_SOURCE_CHANGED')
        return 'production_migration'
    if name.startswith(('app/', 'config/', 'desktop/', 'public/', 'resources/', 'routes/')):
        return 'production_source'
    if name in ('artisan', 'bootstrap/app.php'):
        return 'production_source'
    if name.startswith(('desktop-pos/', '.github/workflows/', 'tests/', 'docs/', 'deployment/')):
        return 'release_only'
    raise Stop('GO_ERP_SHARED_WORKSPACE_PLAN_UNREVIEWED_RELEASE_PATH')


def source_manifest(git, baseline, release):
    """Generate draft pins from immutable commits, including absent-before files.

    This is a review input, not an allowlist. The final operator must embed the
    reviewed exact roster and hashes and never accept this plan from a request.
    """
    git.commit(baseline)
    git.commit(release)
    need(baseline != release, 'DISTINCT_RELEASE_REQUIRED')
    before, after = git.files(baseline), git.files(release)
    source, release_only, total = {}, [], 0
    for name in sorted(set(before) | set(after)):
        if before.get(name) == after.get(name):
            continue
        scope = path_scope(name)
        need(name in after, 'SOURCE_DELETION_REQUIRES_REVIEW')
        new = after[name]
        need(new[0] == '100644' and new[1] == 'blob', 'NONREGULAR_OR_EXECUTABLE_SOURCE')
        old = before.get(name)
        need(old is None or old[0] == '100644' and old[1] == 'blob', 'BASELINE_SOURCE_MODE_INVALID')
        if scope == 'release_only':
            release_only.append(name)
            continue
        old_raw = None if old is None else git.blob(old[2])
        new_raw = git.blob(new[2])
        need(0 < len(new_raw) <= MAX_FILE, 'SOURCE_SIZE_INVALID')
        total += len(new_raw)
        need(total <= MAX_PLAN_BYTES, 'SOURCE_TOTAL_LIMIT')
        if scope == 'production_migration':
            need(old is None, 'PRODUCTION_MIGRATION_MUST_BE_NEW')
            creates = re.findall(rb"Schema::create\(\s*['\"]([a-z_]+)['\"]", new_raw)
            need(tuple(item.decode() for item in creates) == MIGRATIONS[name]
                and not any(table.encode() in new_raw for table in PRIVATE_TABLES),
                'PRODUCTION_MIGRATION_TABLE_SCOPE_CHANGED')
        source[name] = {'operation': 'add' if old is None else 'replace',
            'before_sha256': None if old_raw is None else sha(old_raw),
            'after_sha256': sha(new_raw), 'bytes': len(new_raw), 'mode': '0644', 'scope': scope}
    need(set(name for name, row in source.items() if row['scope'] == 'production_migration') == set(MIGRATIONS),
        'EXACT_TWO_PRODUCTION_MIGRATIONS_REQUIRED')
    need('config/desktop_dashboard.php' in source, 'WORKSPACE_CONFIG_SOURCE_REQUIRED')
    config = git.blob(after['config/desktop_dashboard.php'][2])
    need(re.search(rb"['\"]workspace_enabled['\"]\s*=>\s*env\(\s*['\"]DESKTOP_DASHBOARD_WORKSPACE_ENABLED['\"]\s*,\s*false\s*\)", config),
        'WORKSPACE_FLAG_MUST_DEFAULT_FALSE')
    plan = {'format': 1, 'kind': 'go-erp-shared-workspace-source-draft',
        'baseline': baseline, 'release': release, 'protocol': 2, 'paths': source,
        'release_only_paths': release_only, 'production_table_additions': [table
            for tables in MIGRATIONS.values() for table in tables],
        'production_private_tables_allowed': False, 'financial_data_updates_allowed': False,
        'feature_activation_allowed': False, 'allocation_allowed': False,
        'publication_allowed': False, 'final_release_pins_pending': list(REQUIRED_RELEASE_PINS)}
    plan['manifest_sha256'] = sha(canonical(plan))
    return plan


def checkpoint_advice(state, publication, performance_job=None):
    """Classify snapshots only; never grant allocation or infer absent .60 state.

    The eventual root reader must prove path ownership, locks, raw snapshot
    hashes, branch lease, installer provenance and original acceptance receipts.
    This helper deliberately cannot establish those facts from JSON alone.
    """
    need(isinstance(state, dict) and type(state.get('format')) is int and state['format'] == 1
        and type(state.get('counter')) is int and state['counter'] in (59, 60),
        'CHECKPOINT_REQUIRES_REVIEW')
    desired, published = state.get('desired'), state.get('published')
    need(isinstance(desired, dict) and isinstance(published, dict)
        and isinstance(published.get('release'), dict) and publication == published['release']
        and isinstance(state.get('published_history'), list) and publication in state['published_history']
        and publication.get('source_revision') == published.get('commit')
        and isinstance(publication.get('sequence'), int) and type(publication['sequence']) is int
        and 1 <= publication['sequence'] <= SAFE,
        'PUBLICATION_BINDING_REQUIRES_REVIEW')
    counter = state['counter']
    need(desired.get('version') == '0.4.4-preview.' + str(counter)
        and isinstance(desired.get('commit'), str) and REV.fullmatch(desired['commit'])
        and isinstance(desired.get('inventorySha256'), str) and SHA.fullmatch(desired['inventorySha256'])
        and desired.get('pushed') is True and state.get('last_remote_sha') == desired['commit'],
        'DESIRED_BINDING_REQUIRES_REVIEW')
    if counter == 59:
        need(desired.get('status') == 'published' and desired['commit'] == published.get('commit') == INPUT_CANDIDATE
            and desired['inventorySha256'] == published.get('inventorySha256')
            and publication.get('version') == state.get('version_floor') == '0.4.4-preview.59'
            and publication['sequence'] == state.get('sequence_floor'), 'BASELINE_59_REQUIRES_REVIEW')
        if performance_job is None:
            need(state.get('base_revision') == OLD_SEED, 'MIGRATED_STATE_WITHOUT_JOB_REQUIRES_REVIEW')
            decision = 'observed_published_59_read_previous_acceptance_before_planning'
        else:
            need(state.get('base_revision') in (OLD_SEED, FAST_SEED), 'PERFORMANCE_SEED_REQUIRES_REVIEW')
            _performance_job(performance_job)
            need(performance_job.get('candidate') is None, 'PERFORMANCE_JOB_STATE_MISMATCH')
            decision = 'resume_performance_checkpoint_before_any_workspace_allocation'
    else:
        need(state.get('base_revision') == FAST_SEED and isinstance(performance_job, dict),
            'RESERVED_60_WITHOUT_PERFORMANCE_JOB_REQUIRES_REVIEW')
        _performance_job(performance_job)
        need(performance_job.get('prepare_started') is True
            and type(performance_job.get('baseline_counter')) is int and performance_job['baseline_counter'] == 59
            and desired.get('candidatePolicy') == FAST_POLICY
            and performance_job.get('inventory') == desired['inventorySha256']
            and (performance_job.get('candidate'), performance_job.get('version'))
                in ((None, None), (desired['commit'], desired['version']))
            and desired.get('status') in ('prepared', 'waiting-ci', 'retry-build', 'blocked', 'published'),
            'RESERVED_60_BINDING_REQUIRES_REVIEW')
        if desired['status'] == 'published':
            need(published.get('commit') == desired['commit']
                and published.get('inventorySha256') == desired['inventorySha256']
                and publication.get('version') == state.get('version_floor') == desired['version']
                and publication['sequence'] == state.get('sequence_floor'), 'PUBLISHED_60_REQUIRES_REVIEW')
            decision = 'verify_original_60_acceptance_then_pin_new_predecessor'
        else:
            need(publication.get('version') == '0.4.4-preview.59'
                and published.get('commit') == INPUT_CANDIDATE, 'PREVIOUS_59_PUBLICATION_REQUIRES_REVIEW')
            decision = 'resume_exact_reserved_performance_60_do_not_replace_or_reallocate'
    return {'format': 1, 'kind': 'go-erp-shared-workspace-checkpoint-advice',
        'observed_counter': counter, 'decision': decision,
        'allocation_allowed': False, 'publication_allowed': False,
        'feature_activation_allowed': False, 'previous_acceptance_verification_required': True}


def _performance_job(job):
    need(isinstance(job, dict) and type(job.get('format')) is int and job['format'] == 1
        and job.get('kind') == 'go-erp-warm-start-rollout' and job.get('core') == FAST_CORE
        and job.get('seed') == FAST_SEED and job.get('remote_lease') == INPUT_CANDIDATE
        and job.get('phase') in ('preparing', 'prepared', 'running', 'ready'),
        'PERFORMANCE_JOB_REQUIRES_REVIEW')


def workspace_env_bytes(raw, *, enabled):
    """Pure, secret-preserving exact-flag edit; callers must not log these bytes.

    No filesystem writes. The final coordinator must audit raw bytes privately,
    preserve metadata and compare inode identities before any atomic rename.
    An enable operation still requires all release and lifecycle gates first.
    """
    need(isinstance(raw, bytes) and len(raw) <= 1024**2 and b'\0' not in raw
        and not raw.startswith(b'\xef\xbb\xbf') and type(enabled) is bool,
        'ENV_BYTES_INVALID')
    lines = raw.splitlines(keepends=True)
    assignments = []
    valid = re.compile(rb'(?P<prefix>[ \t]*(?:export[ \t]+)?' + FLAG
        + rb'[ \t]*=[ \t]*)(?P<value>true|false|0|1|"true"|"false"|\'true\'|\'false\')'
        + rb'(?P<suffix>[ \t]*(?:#[^\r\n]*)?)(?P<ending>\r?\n)?\Z')
    for index, line in enumerate(lines):
        if line.lstrip().startswith(b'#'):
            continue
        if re.match(rb'[ \t]*(?:export[ \t]+)?' + FLAG + rb'(?:[ \t]*=|[ \t]+|$)', line):
            match = valid.fullmatch(line)
            need(match is not None, 'ENV_FLAG_ASSIGNMENT_UNREVIEWED')
            assignments.append((index, match))
    need(len(assignments) <= 1, 'ENV_FLAG_DUPLICATE')
    value = b'true' if enabled else b'false'
    if assignments:
        index, match = assignments[0]
        lines[index] = match['prefix'] + value + match['suffix'] + (match['ending'] or b'')
        return b''.join(lines)
    ending = b'\r\n' if raw.count(b'\r\n') and raw.count(b'\n') == raw.count(b'\r\n') else b'\n'
    return raw + (ending if raw and not raw.endswith(b'\n') else b'') + FLAG + b'=' + value + ending


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir', required=True)
    parser.add_argument('--baseline', required=True)
    parser.add_argument('--release', required=True)
    options = parser.parse_args()
    result = source_manifest(Git(options.git_dir), options.baseline, options.release)
    sys.stdout.buffer.write(canonical(result))


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        code = str(error) if isinstance(error, Stop) else 'GO_ERP_SHARED_WORKSPACE_PLAN_PRIVATE_FAILURE'
        print(code, file=sys.stderr)
        raise SystemExit(1)
