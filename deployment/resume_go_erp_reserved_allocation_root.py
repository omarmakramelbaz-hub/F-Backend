#!/usr/bin/env python3
"""Resume one reviewed failed .54 allocation through the original .55 proof gates."""
import argparse
import copy
import hashlib
import json
import math
import os
from pathlib import Path
import re
import socket
import sys
import types
import uuid

REPAIR_RELEASE = '0adf41c64aa587b495a55b7ec058bf5cc54d1e2a'
REPAIR_SHA = '946dffbed1ecbe64822f5c3a4a4c59c13d8f0aabe7556142594cc84f488061ed'
CORE = 'a237b4c048887c6af1b0fb53fe02c414c1d17cd6'
SEED = '1556e002f18d00b9e8f9ba81590843e0d8697318'
FAILED = '5563faf5aa1e2e62157c4b3b9638592a02854c59'
PUBLISHED = 'afb333bbddd9dbc908e3c3674d99c766ed5c4053'
INVENTORY = '525a481eb2d13dc47879b48f4a6110cbd702f2c0d5fdad5e49d663dcafe75d21'
RUNTIME = 'policy-c5fca0a576be84564425d7de160a95d0825a20f5545d52ef911b4349778ebbe6'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-warm-start-accounts-v2'
OLD_POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-warm-start-accounts-v1'
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
SAFE = 9007199254740991


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def checked_reserved_state(state, publication):
    desired = state.get('desired') or {}
    published = state.get('published') or {}
    release = published.get('release') or {}
    need(type(state.get('format')) is int and state['format'] == 1
        and state.get('base_revision') == SEED and type(state.get('counter')) is int
        and state['counter'] == 54 and state.get('last_remote_sha') == FAILED
        and desired.get('commit') == FAILED and desired.get('version') == '0.4.4-preview.53'
        and desired.get('candidatePolicy') == OLD_POLICY and desired.get('inventorySha256') == INVENTORY
        and desired.get('pushed') is True and desired.get('status') == 'retry-build'
        and type(desired.get('failures')) is int and desired['failures'] == 3
        and type(desired.get('retry_at')) in (int, float) and math.isfinite(desired['retry_at'])
        and 0 <= desired['retry_at'] <= SAFE
        and published.get('commit') == PUBLISHED and release.get('source_revision') == PUBLISHED
        and release.get('version') == state.get('version_floor') == '0.4.4-preview.52'
        and type(state.get('sequence_floor')) is int and state['sequence_floor'] == 40230867670
        and isinstance(state.get('published_history'), list) and release in state['published_history'],
        'EXACT_RESERVED_ALLOCATION_REQUIRED')
    need(publication is None or publication == release, 'PUBLIC_FEED_NOT_CURRENT')
    return desired


def checked_original_job(job, runtime_name=RUNTIME):
    need(type(job.get('format')) is int and job['format'] == 1
        and job.get('kind') == 'go-erp-warm-start-repair-rollout'
        and job.get('core') == CORE and job.get('seed') == SEED and job.get('runtime') == runtime_name
        and job.get('phase') == 'running' and job.get('prepare_started') is True
        and job.get('baseline_candidate') == job.get('baseline_remote') == FAILED
        and type(job.get('baseline_counter')) is int and job['baseline_counter'] == 53
        and job.get('candidate') is None and job.get('version') is None
        and type(job.get('transient_failures')) is int and job['transient_failures'] == 0
        and job.get('inventory') == INVENTORY and 'reserved_allocation' not in job,
        'EXACT_RESERVED_JOB_REQUIRED')
    return job


def make_context(repair):
    """Reuse the frozen rollout, changing only its explicit allocation authorization."""
    original_check = repair.checked_failed_state
    active = []

    def checked_failed_state(state, publication):
        # The legacy checker is pure. Its validation-only copy never reaches a
        # Worker, state file, audit, version allocator or publication function.
        need(len(active) == 1 and state.get('base_revision') == repair.OLD_SEED,
            'RESERVED_CHECK_CONTEXT_REQUIRED')
        reviewed = copy.deepcopy(state)
        reviewed['base_revision'] = SEED
        active[0].verify_checkpoint()
        need(reviewed == active[0].reserved_state, 'RESERVED_STATE_CHANGED')
        checked_reserved_state(reviewed, publication)
        legacy = copy.deepcopy(state)
        legacy['counter'] = 53
        return original_check(legacy, publication)

    class ReservedContext(repair.Context):
        def job_record(self):
            need(self.e.read_file(self.job_path, 16 * 1024**2, uid=0, modes=(0o600,), optional=True)
                is not None, 'EXISTING_REPAIR_JOB_REQUIRED')
            super().job_record()
            need(self.runtime.name == RUNTIME, 'RESERVED_RUNTIME_CHANGED')
            if 'reserved_allocation' in self.job:
                self.verify_checkpoint()
            else:
                checked_original_job(self.job, self.runtime.name)
            active[:] = [self]

        def manual(self):
            # R2 is already installed. Validate it without rewriting its receipt
            # or replacing any service/helper bytes for this one-shot resume.
            e = self.e
            raw = e.read_file(repair.CONTROL / 'private/installation.json', 8192, uid=0, modes=(0o600,))[0]
            receipt = self.base.parse(raw)
            name = e.sha(e.canonical(dict(sorted(repair.MANUAL_PINS.items()))))
            runtime = repair.MANUAL_RELEASES / name
            e.directory(runtime, 0, 0, 0o555, False)
            need({p.name for p in runtime.iterdir()} == set(repair.MANUAL_PINS), 'MANUAL_RUNTIME_ROSTER_INVALID')
            for helper, expected in repair.MANUAL_PINS.items():
                need(digest(e.read_file(runtime / helper, uid=0, modes=(0o444,))[0]) == expected,
                    'MANUAL_RUNTIME_CHANGED')
            units = e.units(runtime)
            need(receipt == {'format': 1, 'kind': 'go-erp-manual-installation', 'release': REPAIR_RELEASE,
                'runtime': name, 'units': {key: e.sha(value) for key, value in units.items()}},
                'MANUAL_RECEIPT_UNREVIEWED')
            for unit, expected in units.items():
                need(e.read_file(repair.SYSTEMD / unit, 65536, uid=0, modes=(0o644,))[0] == expected,
                    'MANUAL_UNIT_CHANGED')
            self.manual_runtime = runtime

        def remote(self, expected):
            choices = (expected,) if isinstance(expected, str) else expected
            actual = self.base.git(repair.WORK / 'checkout', 'ls-remote', '--exit-code', self.base.REMOTE,
                'refs/heads/codex/go-erp-live-source-20261021', maximum=4096)
            need(actual in tuple((revision + '\trefs/heads/codex/go-erp-live-source-20261021\n').encode()
                for revision in choices),
                'RESERVED_REMOTE_LEASE_CHANGED')

        def read_reserved_file(self, path):
            return self.e.read_file(path, 16 * 1024**2, uid=0, modes=(0o600,))[0]

        def verify_checkpoint(self):
            binding = self.job.get('reserved_allocation')
            need(isinstance(binding, dict) and set(binding) == {'format', 'kind', 'audit',
                'manifest_sha256', 'attempt_started'} and type(binding['format']) is int
                and binding['format'] == 1 and binding['kind'] == 'go-erp-reserved-allocation'
                and type(binding['attempt_started']) is bool and isinstance(binding['audit'], str)
                and Path(binding['audit']).parent == repair.CONTROL / 'private/audits'
                and re.fullmatch(r'reserved-allocation-54-[a-f0-9]{32}', Path(binding['audit']).name)
                and isinstance(binding['manifest_sha256'], str) and SHA.fullmatch(binding['manifest_sha256']),
                'RESERVED_CHECKPOINT_UNREVIEWED')
            audit = Path(binding['audit']); self.e.directory(audit, 0, 0, 0o700, False)
            manifest_raw = self.read_reserved_file(audit / 'manifest.json')
            need(digest(manifest_raw) == binding['manifest_sha256'], 'RESERVED_MANIFEST_CHANGED')
            manifest = self.base.parse(manifest_raw)
            need(set(manifest) == {'format', 'kind', 'release', 'reserved_counter', 'next_counter', 'files'}
                and type(manifest['format']) is int and manifest['format'] == 1
                and manifest['kind'] == 'go-erp-reserved-allocation-audit'
                and isinstance(manifest['release'], str) and REV.fullmatch(manifest['release'])
                and manifest['release'] == self.release.revision
                and type(manifest['reserved_counter']) is int and manifest['reserved_counter'] == 54
                and type(manifest['next_counter']) is int and manifest['next_counter'] == 55
                and isinstance(manifest['files'], dict)
                and set(manifest['files']) == {'state.json', 'job.json', 'hold.json'},
                'RESERVED_MANIFEST_UNREVIEWED')
            files = {name: self.read_reserved_file(audit / name) for name in manifest['files']}
            need({name: digest(raw) for name, raw in files.items()} == manifest['files'], 'RESERVED_AUDIT_CHANGED')
            state, original_job, hold = (self.base.parse(files[name]) for name in ('state.json', 'job.json', 'hold.json'))
            checked_reserved_state(state, None); checked_original_job(original_job, self.runtime.name)
            expected_hold = {'format': 1, 'kind': 'go-erp-program-rollout-hold', **self.expected, 'authorization': None}
            need(hold == expected_hold, 'RESERVED_AUDIT_HOLD_CHANGED')
            immutable = set(original_job) - {'candidate', 'version', 'phase', 'prepare_started', 'transient_failures', 'baseline_counter'}
            need(set(self.job) == set(original_job) | {'reserved_allocation'}
                and all(self.job.get(key) == original_job[key] for key in immutable)
                and type(self.job.get('baseline_counter')) is int and self.job['baseline_counter'] == 54
                and self.job.get('phase') in ('running', 'ready') and type(self.job.get('prepare_started')) is bool
                and type(self.job.get('transient_failures')) is int and 0 <= self.job['transient_failures'] <= 3
                and ((self.job.get('candidate') is None and self.job.get('version') is None)
                    or (isinstance(self.job.get('candidate'), str) and REV.fullmatch(self.job['candidate'])
                        and self.job['candidate'] != FAILED and self.job.get('version') == '0.4.4-preview.55')),
                'RESERVED_JOB_CHANGED')
            self.reserved_state = state
            return binding

        def prepare(self, locked_utility):
            print('GO_ERP_RESERVED_ALLOCATION_REVIEW', flush=True)
            state = self.state(); snapshot = self.live(state)
            need(state.get('observed') == snapshot['metadata']
                and state.get('dependency_guard') == self.worker.dependency_snapshot(repair.ROOT, self.owner.pw_uid),
                'RESERVED_SNAPSHOT_CHANGED')
            if 'reserved_allocation' in self.job:
                binding = self.verify_checkpoint()
                if state.get('counter') == 54:
                    need(state == self.reserved_state and not binding['attempt_started'],
                        'RESERVED_ATTEMPT_REQUIRES_REVIEW')
                else:
                    need(binding['attempt_started'] and type(state.get('counter')) is int and state['counter'] == 55,
                        'RESERVED_ALLOCATION_REQUIRES_REVIEW')
                    desired = state.get('desired') or {}
                    need(desired.get('candidatePolicy') == POLICY and desired.get('inventorySha256') == INVENTORY
                        and desired.get('version') == '0.4.4-preview.55' and isinstance(desired.get('commit'), str)
                        and REV.fullmatch(desired['commit']) and desired['commit'] != FAILED
                        and desired.get('status') in ('prepared', 'waiting-ci', 'published')
                        and type(desired.get('pushed')) is bool
                        and (self.job['candidate'] is None or self.job['candidate'] == desired['commit']),
                        'RESERVED_CANDIDATE_REQUIRES_REVIEW')
                    expected = desired['commit'] if desired['pushed'] else FAILED
                    need(state.get('last_remote_sha') == expected, 'RESERVED_REMOTE_STATE_CHANGED')
                    if not desired['pushed']:
                        candidate = repair.WORK / 'staging' / ('candidate-55-' + INVENTORY[:16])
                        need(desired.get('candidatePath') == str(candidate), 'RESERVED_CANDIDATE_PATH_CHANGED')
                        self.worker.owned_directory(candidate)
                        need(self.base.git(candidate, 'rev-parse', 'HEAD').decode().strip() == desired['commit'],
                            'RESERVED_CANDIDATE_HEAD_CHANGED')
                    # A process can stop after the original leased push but
                    # before its local save. That same commit may resume; a
                    # different remote head cannot enter the original worker.
                    self.remote(expected if desired['pushed'] else (FAILED, desired['commit']))
                    if not self.ready(state):
                        for key in ('published', 'published_history', 'version_floor', 'sequence_floor'):
                            need(state.get(key) == self.reserved_state.get(key), 'RESERVED_PUBLICATION_CHANGED')
                    print('GO_ERP_RESERVED_ALLOCATION_RESUMING_CANDIDATE_55', flush=True)
                    return
            else:
                checked_reserved_state(state, self.publication()); checked_original_job(self.job, self.runtime.name)
            self.verify_published_archive(state); self.quiet_hold()
            hold_raw, hold = self.prior_hold()
            need(hold == {'format': 1, 'kind': 'go-erp-program-rollout-hold', **self.expected, 'authorization': None},
                'RESERVED_HOLD_REQUIRES_REVIEW')
            need(self.e.read_file(repair.WORK / ('program-rollout-completed-' + CORE + '.json'), 2 * 1024**2,
                uid=0, modes=(0o600,), optional=True) is None, 'RESERVED_ACCEPTANCE_REQUIRES_REVIEW')
            self.remote(FAILED)
            self.worker.require_space(repair.WORK,
                sum(row.get('bytes', 0) for row in snapshot['metadata']['inventory'].values()) * 3 + 128 * 1024**2)
            self.worker.owned_directory(repair.WORK / 'staging')
            destination = repair.WORK / 'staging' / ('candidate-55-' + INVENTORY[:16])
            need(not destination.exists() and not destination.is_symlink(), 'RESERVED_STAGING_REQUIRES_REVIEW')
            if 'reserved_allocation' in self.job:
                return
            state_raw = self.read_reserved_file(repair.WORK / 'state/current.json')
            job_raw = self.read_reserved_file(self.job_path)
            need(self.base.parse(state_raw) == state and self.base.parse(job_raw) == self.job
                and self.prior_hold()[0] == hold_raw, 'RESERVED_INPUTS_CHANGED')
            audit = repair.CONTROL / 'private/audits' / ('reserved-allocation-54-' + uuid.uuid4().hex)
            audit.mkdir(mode=0o700)
            directory = os.open(audit.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
            files = {'state.json': state_raw, 'job.json': job_raw, 'hold.json': hold_raw}
            for name, raw in files.items():
                self.e.atomic_write(audit / name, raw, 0, 0, 0o600)
            manifest = {'format': 1, 'kind': 'go-erp-reserved-allocation-audit', 'release': self.release.revision,
                'reserved_counter': 54, 'next_counter': 55, 'files': {name: digest(raw) for name, raw in files.items()}}
            manifest_raw = self.e.canonical(manifest)
            self.e.atomic_write(audit / 'manifest.json', manifest_raw, 0, 0, 0o600)
            need(self.read_reserved_file(self.job_path) == job_raw
                and self.read_reserved_file(repair.WORK / 'state/current.json') == state_raw
                and self.prior_hold()[0] == hold_raw, 'RESERVED_INPUTS_CHANGED')
            self.job['baseline_counter'] = 54
            self.job['reserved_allocation'] = {'format': 1, 'kind': 'go-erp-reserved-allocation', 'audit': str(audit),
                'manifest_sha256': digest(manifest_raw), 'attempt_started': False}
            self.save(); self.verify_checkpoint()
            print('GO_ERP_RESERVED_ALLOCATION_CHECKPOINT_SAVED', flush=True)

        def tick(self, allow, budget=2700):
            binding = self.verify_checkpoint()
            if allow:
                need(not binding['attempt_started'] and self.job.get('candidate') is None
                    and self.state() == self.reserved_state, 'RESERVED_ATTEMPT_REQUIRES_REVIEW')
                binding['attempt_started'] = True
                self.save()
                print('GO_ERP_RESERVED_ALLOCATION_STARTING_CANDIDATE_55', flush=True)
            else:
                need(binding['attempt_started'] and self.job.get('candidate') is not None,
                    'RESERVED_CANDIDATE_REQUIRED')
            return super().tick(allow, budget)

    repair.checked_failed_state = checked_failed_state
    return ReservedContext


def load_repair(raw):
    need(digest(raw) == REPAIR_SHA, 'ORIGINAL_REPAIR_PIN_INVALID')
    module = types.ModuleType('go_erp_reserved_original_repair')
    module.__file__ = '<pinned-go-erp-original-repair>'
    sys.modules[module.__name__] = module
    exec(compile(raw, module.__file__, 'exec'), module.__dict__)
    module.Stop = Stop
    return module


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir', required=True); parser.add_argument('--release', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--apply', action='store_true'); mode.add_argument('--complete', action='store_true')
    args = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524' and REV.fullmatch(args.release),
        'ROOT_HOST_RELEASE_REQUIRED')
    os.umask(0o077)
    # Read the immutable original through the same bounded git reader as R2.
    import subprocess
    result = subprocess.run(['/usr/bin/git', '--git-dir=' + args.git_dir, '-c', 'credential.helper=',
        '-c', 'core.hooksPath=/dev/null', 'show', REPAIR_RELEASE + ':deployment/upgrade_go_erp_warm_start_repair_root.py'],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=180,
        env={'PATH': '/usr/bin:/bin', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
            'GIT_CONFIG_SYSTEM': '/dev/null', 'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_TERMINAL_PROMPT': '0',
            'GIT_OPTIONAL_LOCKS': '0'})
    need(result.returncode == 0 and len(result.stdout) <= 2 * 1024**2, 'ORIGINAL_REPAIR_READ_FAILED')
    repair = load_repair(result.stdout)
    repair.Context = make_context(repair)
    release = repair.Release(args.git_dir, args.release)
    engine = repair.module(release.file('deployment/apply_go_erp_manual_updates_root.py', repair.BOOTSTRAP),
        'reserved_allocation_file_engine', repair.ENGINE_SHA)
    engine.Stop = Stop
    repository = release.repository.absolute(); engine.parents(repository)
    need(repository.resolve() == repository and repository.stat().st_uid == 0 and not repository.stat().st_mode & 0o022,
        'REPOSITORY_UNSAFE')
    need(release.git('rev-parse', args.release + '^{commit}').decode().strip() == args.release, 'RELEASE_INVALID')
    result = repair.execute(release, engine, args.complete)
    print(json.dumps({'format': 1, 'kind': 'go-erp-reserved-allocation-resume', **result,
        'database_changed': False, 'credentials_disclosed': False, 'automatic_updates_enabled': False}), flush=True)
    print('GO_ERP_RESERVED_ALLOCATION_PUBLISHED_MANUAL_UPDATES_ONLY' if result['published']
        else 'GO_ERP_RESERVED_ALLOCATION_PREPARED_PUBLICATION_HELD')


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        code = str(error) if isinstance(error, Stop) and re.fullmatch(r'(?:GO_ERP_)?[A-Z0-9_]{1,150}', str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'format': 1, 'kind': 'go-erp-reserved-allocation-resume', 'ok': False,
            'code': 'GO_ERP_RESERVED_ALLOCATION_' + code, 'published': False, 'database_changed': False,
            'credentials_disclosed': False, 'automatic_updates_enabled': False}), flush=True)
        raise SystemExit(1)
