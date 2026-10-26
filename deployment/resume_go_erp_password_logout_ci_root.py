#!/usr/bin/env python3
"""Review successful CI for the existing preview.58; never allocate a version."""
import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys
import types

OPERATOR = '56c8680ac8d3d5cb68eaebd623daeba897cc3a48'
OPERATOR_SHA256 = 'c18a97f131615436acef59e291f3d244a978585a47411ce77c849c8433b11d69'
CANDIDATE = '02411715ca112f3d4393f1ca1b83d377aa253786'
VERSION = '0.4.4-preview.58'
INVENTORY = '525a481eb2d13dc47879b48f4a6110cbd702f2c0d5fdad5e49d663dcafe75d21'
RUNTIME = 'policy-031fc1271850918331615d3e04d02f9df5ace35cdb2462215c0a2836ce948d3d'
RUNS = {'desktop-dashboard-tests.yml': 40369059440,
        'go-erp-update-tests.yml': 40369059792,
        'whatsapp-integrated-regressions.yml': 40369059473}


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def checked_consumed_state(operator, state, job, previous):
    """Validate both journals before any original coordinator operation can write."""
    desired = state.get('desired') if isinstance(state, dict) else None
    need(isinstance(state, dict) and type(state.get('format')) is int and state['format'] == 1
        and state.get('base_revision') == operator.SEED and type(state.get('counter')) is int
        and state['counter'] == 58 and state.get('last_remote_sha') == CANDIDATE
        and isinstance(desired, dict) and desired.get('commit') == CANDIDATE
        and desired.get('version') == VERSION and desired.get('candidatePolicy') == operator.POLICY
        and desired.get('inventorySha256') == INVENTORY and desired.get('pushed') is True
        and desired.get('status') in ('retry-build', 'blocked', 'prepared', 'waiting-ci', 'published')
        and type(desired.get('failures')) is int and 0 <= desired['failures'] <= 100,
        'EXACT_PASSWORD_LOGOUT_58_REQUIRED')
    need(isinstance(job, dict) and type(job.get('format')) is int and job['format'] == 1
        and job.get('kind') == 'go-erp-warm-start-rollout'
        and job.get('core') == operator.CORE and job.get('seed') == operator.SEED
        and job.get('runtime') == RUNTIME and job.get('phase') in ('running', 'ready')
        and job.get('prepare_started') is True and job.get('candidate') == CANDIDATE
        and job.get('version') == VERSION and job.get('inventory') == INVENTORY
        and job.get('remote_lease') == operator.INPUT_CANDIDATE
        and job.get('baseline_candidate') == job.get('baseline_remote') == operator.INPUT_CANDIDATE
        and type(job.get('baseline_counter')) is int and job['baseline_counter'] == 57,
        'EXACT_PASSWORD_LOGOUT_JOURNAL_REQUIRED')
    operator.checked_published_state(previous, previous.get('published', {}).get('release'))
    if desired['status'] == 'published':
        published = state.get('published') or {}
        release = published.get('release') or {}
        appended = previous['published_history'] + [release]
        seen, retained = set(), []
        for item in reversed(appended):
            identity = (item.get('installer') or {}).get('sha256')
            if identity not in seen:
                seen.add(identity); retained.append(item)
        pruned = list(reversed(retained[:3]))
        need(published.get('commit') == CANDIDATE and published.get('inventorySha256') == INVENTORY
            and release.get('source_revision') == CANDIDATE and release.get('version') == VERSION
            and release.get('sequence') == RUNS['desktop-dashboard-tests.yml']
            and state.get('version_floor') == VERSION
            and state.get('sequence_floor') == RUNS['desktop-dashboard-tests.yml']
            and state.get('published_history') in (appended, pruned),
            'PASSWORD_LOGOUT_PUBLICATION_CHANGED')
    else:
        need(all(state.get(key) == previous.get(key) for key in
            ('published', 'published_history', 'version_floor', 'sequence_floor')),
            'PASSWORD_LOGOUT_PREVIOUS_PUBLICATION_CHANGED')
    return desired


def reviewed_state(state):
    """Only the retry state changes; allocation, failure count and history stay exact."""
    result = copy.deepcopy(state)
    result['desired']['status'] = 'waiting-ci'
    result['desired']['retry_at'] = 0
    return result


def make_context(operator, template):
    original_context = template.Context

    class ResumeContext(original_context):
        def reviewed_inputs(self):
            existing = self.e.read_file(self.job_path, 16 * 1024**2, uid=0, modes=(0o600,), optional=True)
            need(existing is not None, 'EXISTING_PASSWORD_LOGOUT_JOURNAL_REQUIRED')
            job = self.base.parse(existing[0])
            audit = Path(job.get('audit', ''))
            need(audit.parent == template.CONTROL / 'private/audits'
                and re.fullmatch('warm-start-[a-f0-9]{32}', audit.name), 'PASSWORD_LOGOUT_AUDIT_INVALID')
            previous = self.base.parse(self.e.read_file(audit / 'previous-state.json',
                16 * 1024**2, uid=0, modes=(0o600,))[0])
            state = self.state()
            checked_consumed_state(operator, state, job, previous)
            return state, job

        def job_record(self):
            # The original initial-allocation path is never reachable.
            self.reviewed_inputs()
            return super().job_record()

        def manual(self):
            # Validate the already installed .58 helper cohort without replacing it.
            self.reviewed_inputs()
            name = self.e.sha(self.e.canonical(dict(sorted(template.MANUAL_PINS.items()))))
            runtime = template.MANUAL_RELEASES / name
            self.e.directory(runtime, 0, 0, 0o555, False)
            need({path.name for path in runtime.iterdir()} == set(template.MANUAL_PINS),
                'PASSWORD_LOGOUT_MANUAL_ROSTER_CHANGED')
            for name, expected in template.MANUAL_PINS.items():
                need(sha(self.e.read_file(runtime / name, uid=0, modes=(0o444,))[0]) == expected,
                    'PASSWORD_LOGOUT_MANUAL_HELPER_CHANGED')
            units = self.e.units(runtime)
            receipt = self.base.parse(self.e.read_file(template.CONTROL / 'private/installation.json',
                8192, uid=0, modes=(0o600,))[0])
            need(receipt == {'format': 1, 'kind': 'go-erp-manual-installation', 'release': OPERATOR,
                'runtime': runtime.name, 'units': {key: self.e.sha(value) for key, value in units.items()}},
                'PASSWORD_LOGOUT_MANUAL_RECEIPT_CHANGED')
            for unit, expected in units.items():
                need(self.e.read_file(template.SYSTEMD / unit, 65536, uid=0, modes=(0o644,))[0] == expected,
                    'PASSWORD_LOGOUT_MANUAL_UNIT_CHANGED')
            self.manual_runtime = runtime

        def prepare(self, locked_utility):
            # Preparation already consumed exactly preview.58. Do not call the
            # original migration, prepare(), version allocator or push path.
            locked_utility.verify()
            state, _ = self.reviewed_inputs()
            print('GO_ERP_PASSWORD_LOGOUT_REVIEW_EXISTING_58_SOURCE', flush=True)
            self.live(state)
            self.candidate(state)
            if not self.ready(state):
                value = self.base.parse(self.e.read_file(template.WORK / 'program-rollout-hold.json',
                    1024**2, uid=0, modes=(0o600,))[0])
                need(set(value) == {'format', 'kind', 'authorization', *self.expected}
                    and value.get('format') == 1 and value.get('kind') == 'go-erp-program-rollout-hold'
                    and all(value.get(key) == expected for key, expected in self.expected.items()),
                    'PASSWORD_LOGOUT_ORIGINAL_HOLD_CHANGED')
            locked_utility.verify()

        def complete(self):
            template.quiet(self.e)
            with self.utility.watcher_lock(template.WORK):
                state, _ = self.reviewed_inputs()
                self.live(state)
                self.candidate(state)
                if not self.ready(state) and state['desired']['status'] in ('retry-build', 'blocked'):
                    print('GO_ERP_PASSWORD_LOGOUT_REVIEW_ORIGINAL_CI', flush=True)
                    config = self.worker.read_config()
                    need(config['base_revision'] == operator.SEED, 'PASSWORD_LOGOUT_CONFIG_CHANGED')
                    client = self.worker.GitHub(config['token'])
                    runs = {}
                    for workflow, run_id in RUNS.items():
                        run = self.worker.accepted_run(client, workflow, CANDIDATE)
                        need(run is not None and run['id'] == run_id,
                            'PASSWORD_LOGOUT_SUCCESSFUL_ORIGINAL_CI_REQUIRED')
                        runs[workflow] = {'id': run['id'], 'attempt': run['run_attempt']}
                        print('GO_ERP_PASSWORD_LOGOUT_CI_REVIEWED_' + str(len(runs)), flush=True)
                    # Recheck source and exact state after network I/O while the
                    # watcher lock still excludes another production worker.
                    self.live(state)
                    self.candidate(state)
                    need(self.state() == state, 'PASSWORD_LOGOUT_STATE_CHANGED_DURING_REVIEW')
                    before = self.e.canonical(state)
                    record = {'format': 1, 'kind': 'go-erp-password-logout-ci-review',
                        'operator': OPERATOR, 'core': operator.CORE, 'seed': operator.SEED,
                        'candidate': CANDIDATE, 'version': VERSION, 'inventory': INVENTORY,
                        'before_state_sha256': sha(before), 'runs': runs}
                    audit = Path(self.job['audit'])
                    for name, raw in (('password-logout-ci-state-' + sha(before) + '.json', before),
                            ('password-logout-ci-review-' + sha(self.e.canonical(record)) + '.json',
                             self.e.canonical(record))):
                        existing = self.e.read_file(audit / name, 16 * 1024**2,
                            uid=0, modes=(0o600,), optional=True)
                        if existing is None:
                            self.e.atomic_write(audit / name, raw, 0, 0, 0o600)
                        else:
                            need(existing[0] == raw, 'PASSWORD_LOGOUT_CI_AUDIT_CHANGED')
                    self.e.atomic_write(template.WORK / 'state/current.json',
                        self.e.canonical(reviewed_state(state)), 0, 0, 0o600)
                    print('GO_ERP_PASSWORD_LOGOUT_SAME_58_CI_REVIEWED', flush=True)
            # Original acceptance, artifact, employee proof and publication gates
            # all run unchanged and remain bound to the existing candidate.
            return super().complete()

    return ResumeContext


def load_operator(repository):
    result = subprocess.run(['/usr/bin/git', '--git-dir=' + str(repository),
        '-c', 'credential.helper=', '-c', 'core.hooksPath=/dev/null', 'show',
        OPERATOR + ':deployment/upgrade_go_erp_password_logout_root.py'],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=180,
        env={'PATH': '/usr/bin:/bin', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
            'GIT_CONFIG_SYSTEM': '/dev/null', 'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_TERMINAL_PROMPT': '0',
            'GIT_OPTIONAL_LOCKS': '0', 'GIT_NO_LAZY_FETCH': '1'})
    need(result.returncode == 0 and sha(result.stdout) == OPERATOR_SHA256, 'PASSWORD_LOGOUT_OPERATOR_PIN_CHANGED')
    operator = types.ModuleType('go_erp_password_logout_pinned_operator')
    operator.__file__ = '<pinned-go-erp-password-logout-operator>'
    sys.modules[operator.__name__] = operator
    exec(compile(result.stdout, operator.__file__, 'exec'), operator.__dict__)
    operator.Stop = Stop
    return operator


def checked_repository(repository, package_parent=Path('/var/tmp')):
    """Trust the root-only retained package before invoking Git on its repository."""
    repository = Path(repository)
    package = repository.parent
    need(repository.is_absolute() and repository.name == 'release.git'
        and package.parent == package_parent
        and re.fullmatch(r'go-erp-password-logout\.[A-Za-z0-9]{8}', package.name),
        'PASSWORD_LOGOUT_RETAINED_PACKAGE_REQUIRED')
    for path in (*reversed(repository.parents), repository):
        info = path.lstat()
        need(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode),
            'PASSWORD_LOGOUT_RETAINED_PACKAGE_UNSAFE')
    need(repository.resolve() == repository, 'PASSWORD_LOGOUT_REPOSITORY_SYMLINK')
    for path in (package, repository):
        info = path.lstat()
        need(info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700,
            'PASSWORD_LOGOUT_RETAINED_PACKAGE_PERMISSIONS')
    fd = os.open(package / 'apply.py', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as source:
        info = os.fstat(source.fileno())
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
            and stat.S_IMODE(info.st_mode) == 0o600 and info.st_size <= 2 * 1024**2,
            'PASSWORD_LOGOUT_RETAINED_OPERATOR_UNSAFE')
        raw = source.read(2 * 1024**2 + 1)
        need(sha(raw) == OPERATOR_SHA256, 'PASSWORD_LOGOUT_RETAINED_OPERATOR_PIN_CHANGED')
    return repository


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--complete', action='store_true', required=True)
    args = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524'
        and args.release == OPERATOR, 'PASSWORD_LOGOUT_ROOT_HOST_OPERATOR_REQUIRED')
    repository = checked_repository(args.git_dir)
    operator = load_operator(repository)
    original_configure = operator.configure
    def configure(*values, **options):
        template = original_configure(*values, **options)
        template.Context = make_context(operator, template)
        return template
    operator.configure = configure
    operator.main()


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        code = str(error)
        if not ((isinstance(error, Stop) or code.startswith('GO_ERP_'))
                and re.fullmatch(r'(?:GO_ERP_)?[A-Z0-9_]{1,150}', code)):
            code = 'PRIVATE_FAILURE'
        print(json.dumps({'format': 1, 'kind': 'go-erp-password-logout-ci-resume', 'ok': False,
            'code': 'GO_ERP_PASSWORD_LOGOUT_' + code, 'published': False,
            'database_changed': False, 'credentials_disclosed': False, 'automatic_updates_enabled': False}), flush=True)
        raise SystemExit(1)
