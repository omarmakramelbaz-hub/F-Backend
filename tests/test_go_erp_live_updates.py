"""Offline worker acceptance: synthetic GitHub records, real private Git/source/publisher.

These fixtures never contact GitHub or a live server and do not claim Windows
execution. Their CI records exercise the production verification boundaries.
"""
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import types
import unittest
import urllib.error
import zipfile
from unittest.mock import patch

DEPLOYMENT = Path(__file__).resolve().parents[1] / 'deployment'


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


worker = module('go_erp_worker_fixture', DEPLOYMENT / 'go_erp_live_updates.py')
source = module('go_erp_source_fixture', DEPLOYMENT / 'go_erp_live_source.py')
publisher = module('go_erp_publisher_fixture', DEPLOYMENT / 'publish_go_erp_update.py')


def sha(value):
    return hashlib.sha256(value).hexdigest()


class Response(io.BytesIO):
    def __init__(self, data, url):
        super().__init__(data)
        self.url = url
        self.status = 200

    def geturl(self):
        return self.url


class Opener:
    def __init__(self, steps):
        self.steps, self.requests = list(steps), []

    def open(self, request, timeout):
        self.requests.append((request, timeout))
        value = self.steps.pop(0)
        if isinstance(value, Exception):
            raise value
        return value


def run(workflow='desktop-dashboard-tests.yml', revision='a' * 40, run_id=500):
    return {'id': run_id, 'run_attempt': 1, 'event': 'push', 'head_branch': worker.BRANCH,
            'head_sha': revision, 'pull_requests': [], 'path': '.github/workflows/' + workflow,
            'repository': {'id': worker.REPOSITORY_ID, 'full_name': worker.REPOSITORY},
            'head_repository': {'id': worker.REPOSITORY_ID, 'full_name': worker.REPOSITORY},
            'status': 'completed', 'conclusion': 'success'}


class TransportTests(unittest.TestCase):
    def test_actual_cli_entry_preserves_success_and_reported_failure_and_redacts_private_errors(self):
        path = str(DEPLOYMENT / 'go_erp_live_updates.py')
        helper = subprocess.run([sys.executable, '-I', '-B', path, '--help'], capture_output=True)
        self.assertEqual(helper.returncode, 0)
        self.assertNotIn(b'NOT_CONFIGURED', helper.stderr)
        loader = '''import ast,sys
path,mode=sys.argv[1:]
tree=ast.parse(open(path).read(),filename=path)
bodies={'success':'return 0','reported':'print("GO_ERP_LIVE_REPORTED_FAILURE",file=sys.stderr)\\nreturn 1',
        'private':'raise RuntimeError("PRIVATE_PAT_MUST_NOT_APPEAR")',
        'public':'raise WatchError("GO_ERP_LIVE_CONFIG_INVALID")'}
replacement=ast.parse("def replacement():\\n"+"\\n".join("    "+line for line in bodies[mode].splitlines())).body[0].body
for node in tree.body:
    if isinstance(node,ast.FunctionDef) and node.name=='main':node.body=replacement
ast.fix_missing_locations(tree)
exec(compile(tree,path,'exec'),{'__name__':'__main__','__file__':path})
'''
        cases = [('success', 0, b''), ('reported', 1, b'GO_ERP_LIVE_REPORTED_FAILURE\n'),
                 ('private', 1, b'GO_ERP_LIVE_NOT_CONFIGURED_OR_UNAVAILABLE\n'),
                 ('public', 1, b'GO_ERP_LIVE_CONFIG_INVALID\n')]
        for mode, code, stderr in cases:
            result = subprocess.run([sys.executable, '-I', '-B', '-c', loader, path, mode], capture_output=True)
            with self.subTest(mode=mode):
                self.assertEqual(result.returncode, code, result.stderr)
                self.assertEqual(result.stdout, b'')
                self.assertEqual(result.stderr, stderr)
                self.assertNotIn(b'PRIVATE_PAT', result.stderr)

    def test_redirects_strip_token_and_only_allow_https_signed_artifact_hosts(self):
        url = 'https://api.github.com/repos/' + worker.REPOSITORY + '/actions/artifacts/1/zip'
        cloud = 'https://results.blob.core.windows.net/artifact?signature=private'
        redirect = urllib.error.HTTPError(url, 302, 'redirect', {'Location': cloud}, None)
        opener = Opener([redirect, Response(b'zip', cloud)])
        client = worker.GitHub('secret-pat-never-in-url', opener)
        with client.open(url, artifact=True) as response:
            self.assertEqual(response.read(), b'zip')
        first, second = opener.requests
        self.assertIn('Authorization', dict(first[0].header_items()))
        self.assertNotIn('Authorization', dict(second[0].header_items()))
        self.assertEqual([first[1], second[1]], [20, 20])
        for bad in ['http://api.github.com/x', 'https://evil.example/x', 'https://api.github.com@evil.example/x',
                    'https://results.blob.core.windows.net.evil.example/x', 'https://api.github.com:444/x']:
            with self.subTest(bad=bad), self.assertRaises(worker.WatchError):
                client.allowed(bad, artifact=True)

    def test_unexpected_implicit_redirect_and_error_bodies_cannot_leak_credentials(self):
        url = 'https://api.github.com/repos/' + worker.REPOSITORY + '/actions/artifacts/1/zip'
        for value in [Response(b'private', 'https://evil.example/'),
                      urllib.error.HTTPError(url, 401, 'PAT=must-not-print', {}, io.BytesIO(b'token=secret'))]:
            with self.assertRaises(worker.WatchError) as caught:
                worker.GitHub('secret', Opener([value])).open(url)
            self.assertNotIn('secret', str(caught.exception))
            self.assertNotIn('PAT', str(caught.exception))

    def test_exact_push_workflow_repository_branch_revision_and_attempt_are_required(self):
        good = run()
        self.assertEqual(worker.run_identity(good, 'desktop-dashboard-tests.yml', 'a' * 40), good)
        faults = [('event', 'pull_request'), ('head_branch', 'main'), ('head_sha', 'b' * 40),
                  ('pull_requests', [{'number': 1}]), ('path', '.github/workflows/deploy.yml'),
                  ('run_attempt', 0), ('head_repository', {'id': 1, 'full_name': 'foreign/repo'}),
                  ('repository', {'id': 1, 'full_name': worker.REPOSITORY})]
        for key, value in faults:
            altered = copy.deepcopy(good)
            altered[key] = value
            with self.subTest(key=key), self.assertRaises(worker.WatchError):
                worker.run_identity(altered, 'desktop-dashboard-tests.yml', 'a' * 40)

    def test_required_workflows_exclude_frozen_activation_baseline_and_include_contracts(self):
        self.assertEqual(worker.WORKFLOWS, {'desktop-dashboard-tests.yml': {'connected-native', 'original-runtime', 'windows-installer'},
                                         'go-erp-update-tests.yml': {'update-contracts'},
                                         'whatsapp-integrated-regressions.yml': {'ui', 'runtime'}})

    def test_latest_attempt_jobs_must_each_be_exact_success_from_this_source(self):
        value = run()
        jobs = [{'name': name, 'status': 'completed', 'conclusion': 'success', 'run_id': value['id'],
                 'head_sha': value['head_sha'], 'run_attempt': 1} for name in worker.WORKFLOWS['desktop-dashboard-tests.yml']]
        client = types.SimpleNamespace(pages=lambda path, key: [value] if key == 'workflow_runs' else jobs)
        self.assertEqual(worker.accepted_run(client, 'desktop-dashboard-tests.yml', 'a' * 40), value)
        for change in ['missing', 'duplicate', 'failed', 'old-attempt', 'old-source']:
            bad = copy.deepcopy(jobs)
            if change == 'missing':
                bad.pop()
            elif change == 'duplicate':
                bad.append(bad[0])
            elif change == 'failed':
                bad[0]['conclusion'] = 'failure'
            elif change == 'old-attempt':
                bad[0]['run_attempt'] = 2
            else:
                bad[0]['head_sha'] = 'b' * 40
            client.pages = lambda path, key, rows=bad: [value] if key == 'workflow_runs' else rows
            with self.subTest(change=change), self.assertRaises(worker.WatchError):
                worker.accepted_run(client, 'desktop-dashboard-tests.yml', 'a' * 40)

    def test_artifact_metadata_is_unique_unexpired_and_bound_to_this_repository_run_and_source(self):
        accepted = run()
        good = {'id': 19, 'name': worker.ARTIFACT, 'size_in_bytes': 1234, 'expired': False,
                'digest': 'sha256:' + 'b' * 64,
                'workflow_run': {'id': accepted['id'], 'repository_id': worker.REPOSITORY_ID,
                                 'head_repository_id': worker.REPOSITORY_ID, 'head_sha': 'a' * 40,
                                 'head_branch': worker.BRANCH}}
        client = types.SimpleNamespace(pages=lambda path, key: [good])
        self.assertEqual(worker.accepted_artifact(client, accepted, 'a' * 40), good)
        for field, wrong in [('id', 0), ('size_in_bytes', worker.MAX_ARCHIVE + 1), ('expired', True),
                             ('digest', 'sha256:' + 'g' * 64), ('workflow_run.id', 499),
                             ('workflow_run.repository_id', 1), ('workflow_run.head_repository_id', 1),
                             ('workflow_run.head_sha', 'c' * 40), ('workflow_run.head_branch', 'main')]:
            altered = copy.deepcopy(good)
            target, name = (altered['workflow_run'], field.split('.')[1]) if '.' in field else (altered, field)
            target[name] = wrong
            client.pages = lambda path, key, row=altered: [row]
            with self.subTest(field=field), self.assertRaises(worker.WatchError):
                worker.accepted_artifact(client, accepted, 'a' * 40)
        for rows in ([], [good, good]):
            client.pages = lambda path, key, rows=rows: rows
            with self.assertRaises(worker.WatchError):
                worker.accepted_artifact(client, accepted, 'a' * 40)


@unittest.skipUnless(os.geteuid() == 0, 'isolated root-owned source/publication contract')
class RootWorkerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-worker-test-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.work, self.live = self.base / 'work', self.base / 'live'
        self.work.mkdir(mode=0o700)
        self.live.mkdir(mode=0o700)

    def write(self, relative, data, root=None, mode=0o644):
        target = (root or self.live) / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data if isinstance(data, bytes) else worker.canonical(data))
        target.chmod(mode)
        return target

    def seed(self):
        seed = self.work / 'checkout'
        seed.mkdir(mode=0o700)
        shared = {'artisan': b'<?php // fixture\n', 'bootstrap/app.php': b'<?php // bootstrap\n',
                  'app/Http/Kernel.php': b'<?php // kernel\n', 'config/app.php': b'<?php // config\n',
                  'routes/web.php': b'<?php // routes\n', 'public/js/dashboard-new.js': b'const initial=1;\n',
                  'public/css/site.css': b'body {color: red;}\n',
                  'desktop/source-contract.json': worker.canonical({'format': 1, 'framework': '8.83.29', 'supportedRuntimeFrameworks': ['8.83.29', '12.70.0']})}
        for name, data in shared.items():
            self.write(name, data, seed)
            self.write(name, data)
        lock = {'packages': [{'name': 'laravel/framework', 'version': 'v8.83.29'}]}
        self.write('composer.lock', lock, seed)
        self.write('composer.lock', lock)
        self.write('composer.json', {'require': {'laravel/framework': '^8.0'}}, seed)
        self.write('composer.json', {'require': {'laravel/framework': '^8.0'}})
        self.write('vendor/composer/installed.json', lock)
        self.write('vendor/composer/installed.php', b'<?php /* dependency metadata, never executed */')
        package = {'name': 'go-erp', 'version': '0.4.4-preview.6', 'private': True, 'build': {'productName': 'GO ERP'}}
        self.write('desktop-pos/package.json', package, seed)
        self.write('desktop-pos/package-lock.json', {'name': 'go-erp', 'version': package['version'], 'lockfileVersion': 3,
                                                   'packages': {'': {'name': 'go-erp', 'version': package['version']}}}, seed)
        self.write('.gitattributes', b'* text=auto\n', seed)
        commands = [['init', '-q'], ['remote', 'add', 'origin', worker.REMOTE], ['add', '.'],
                    ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '-qm', 'trusted fixture']]
        for args in commands:
            subprocess.run(['git', '-C', str(seed), *args], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        revision = subprocess.check_output(['git', '-C', str(seed), 'rev-parse', 'HEAD']).decode().strip()
        (self.live / 'storage/app/desktop-updates').mkdir(parents=True)
        return seed, revision

    def make_worker(self, client=None):
        seed, revision = self.seed()
        config = {'format': 1, 'repository': worker.REPOSITORY, 'repository_id': worker.REPOSITORY_ID,
                  'branch': worker.BRANCH, 'base_revision': revision, 'token': 'secret-token-only-memory-123456'}
        client = client or types.SimpleNamespace(json=lambda path, missing=False: None, pages=lambda path, key: [])
        value = worker.Worker(config, source, publisher, client=client, work=self.work, live=self.live, web_uid=1001)
        self.pushes = []
        value.git.push = lambda checkout, commit, previous: self.pushes.append((checkout, commit, previous))
        return value

    def receipt(self, version='0.4.4-preview.7', sequence=500, revision='a' * 40, exe=b'MZsynthetic-fixture'):
        current = publisher.source_snapshot(self.live)
        return {'format': 1, 'kind': 'go-erp-desktop-release', 'version': version, 'sequence': sequence,
                'source_revision': revision, 'source': current['fingerprint'], 'gui': publisher.snapshot_gui(current),
                'installer': {'sha256': sha(exe), 'bytes': len(exe)},
                'acceptance': {'windows_installer': True, 'runtime': True, 'connected': True, 'source_revision': revision}}

    def archive(self, values=None, additions=()):
        values = values or {name: b'MZfixture' if name.endswith('.exe') else b'{}' for name in worker.FILES}
        target = self.base / ('archive-' + str(len(list(self.base.glob('archive-*')))) + '.zip')
        with zipfile.ZipFile(target, 'w') as output:
            for name, data in values.items():
                output.writestr(name, data)
            for name, data in additions:
                output.writestr(name, data)
        return target

    def proof_files(self, desired, sequence=500, attempt=1):
        exe = b'MZsynthetic accepted fixture'
        receipt = self.receipt(desired['version'], sequence, desired['commit'], exe)
        package_files = {'application/app/Http/Kernel.php': sha((self.live / 'app/Http/Kernel.php').read_bytes())}
        checks = {'sourceRevision': desired['commit'], 'version': desired['version'], 'installer': receipt['installer'],
                  **{name: True for name in ('installation', 'installedNativeExecutables', 'installedDashboardRecovery', 'packagedLedger', 'uninstallRetention')},
                  'automaticUpgrade': {name: True for name in ('waitedForExit', 'preservedJournal', 'preservedUnrelatedFiles', 'registrationRetained', 'restartedInstalledExecutable')}}
        native = {'format': 1, 'sourceRevision': desired['commit'], 'platform': 'win32'}
        values = {name: b'{}' for name in worker.FILES}
        values.update({'GO-ERP-Preview-Setup.exe': exe,
                       'go-erp-update-release.json': worker.canonical(receipt),
                       'runtime-package.json': worker.canonical({'sourceRevision': desired['commit'], 'version': desired['version'],
                                                                 'sourceFingerprint': receipt['source'], 'files': package_files}),
                       'installer-checks.json': worker.canonical(checks),
                       'connected-request-native.json': worker.canonical({**native, 'hardware': False, 'completed': True,
                            'actualElectronSessionFetch': True, 'actualWebRequest': True, 'actualDpapi': True}),
                       'outbox-review-native.json': worker.canonical({**native, 'completed': True, 'actualDpapiAudit': True,
                            'durableIntentBeforeRetry': True, 'unchangedCommandId': True}),
                       'offline-print-native.json': worker.canonical({**native, 'hardware': False, 'invocations': 7, 'accepted': 6,
                            'review': 1, 'realPreloadIpc': True, 'refreshFence': True, 'encryptedRestartRecovery': True})})
        proof = {'format': 1, 'kind': 'go-erp-live-source-acceptance', 'repository': worker.REPOSITORY,
                 'branch': worker.BRANCH, 'revision': desired['commit'], 'run_id': sequence, 'run_attempt': attempt,
                 'version': desired['version'], 'candidate_inventory_sha256': desired['inventorySha256'],
                 'full_package_sha256': worker.digest(package_files),
                 'update_receipt_sha256': sha(values['go-erp-update-release.json']), 'installer': receipt['installer']}
        values['go-erp-live-source-acceptance.json'] = worker.canonical(proof)
        return values

    def test_download_stream_checks_size_hash_deadline_and_live_heartbeat(self):
        payload = b'0123456789' * 10000
        url = 'https://api.github.com/repos/' + worker.REPOSITORY + '/actions/artifacts/1/zip'
        heartbeat = []
        target = self.base / 'download.zip'
        client = worker.GitHub('secret', Opener([Response(payload, url)]))
        client.download(1, target, sha(payload), len(payload), lambda: heartbeat.append(True))
        self.assertEqual(target.read_bytes(), payload)
        self.assertTrue(heartbeat)
        target.unlink()
        for expected_size, expected_sha in [(len(payload) - 1, sha(payload)), (len(payload), 'a' * 64)]:
            client = worker.GitHub('secret', Opener([Response(payload, url)]))
            with self.assertRaises(worker.WatchError):
                client.download(1, target, expected_sha, expected_size, lambda: None)
            self.assertFalse(target.exists())
        client = worker.GitHub('secret', Opener([Response(payload, url)]))
        with self.assertRaises(worker.Superseded):
            client.download(1, target, sha(payload), len(payload), lambda: (_ for _ in ()).throw(worker.Superseded()))
        self.assertFalse(target.exists())

    def test_zip_has_one_exact_bounded_roster_without_traversal_alias_or_links(self):
        archive = self.archive()
        destination = self.base / 'extracted'
        destination.mkdir()
        worker.extract_artifact(archive, destination)
        self.assertEqual({path.relative_to(destination).as_posix() for path in destination.rglob('*') if path.is_file()}, set(worker.FILES))
        for name in ['../escape', '/absolute', 'C:drive', 'GO-ERP-PREVIEW-SETUP.EXE', 'unexpected.json']:
            bad = self.archive(additions=[(name, b'invalid')])
            folder = self.base / ('bad-' + str(len(list(self.base.glob('bad-*')))))
            folder.mkdir()
            with self.subTest(name=name), self.assertRaises(worker.WatchError):
                worker.extract_artifact(bad, folder)
        bad = self.base / 'symlink.zip'
        with zipfile.ZipFile(bad, 'w') as output:
            entry = zipfile.ZipInfo('GO-ERP-Preview-Setup.exe')
            entry.create_system = 3
            entry.external_attr = (stat.S_IFLNK | 0o777) << 16
            output.writestr(entry, b'/private')
        folder = self.base / 'links'
        folder.mkdir()
        with self.assertRaises(worker.WatchError):
            worker.extract_artifact(bad, folder)

    def test_feed_invalidation_never_deletes_unknown_files_or_follows_links(self):
        directory = self.live / 'storage/app/desktop-updates'
        directory.mkdir(parents=True)
        unknown = self.write('storage/app/desktop-updates/operator-file.txt', b'keep')
        current = self.write('storage/app/desktop-updates/current.json', b'{}')
        worker.invalidate_feed(self.live)
        self.assertFalse(current.exists())
        self.assertEqual(unknown.read_bytes(), b'keep')
        private = self.write('private.txt', b'private')
        current.symlink_to(private)
        with self.assertRaises(worker.WatchError):
            worker.invalidate_feed(self.live)
        self.assertEqual(private.read_bytes(), b'private')

    def test_real_source_candidate_pipeline_and_version_floor_survive_feed_invalidation(self):
        value = self.make_worker()
        initial = self.receipt('0.4.4-preview.100', sequence=100)
        current = self.write('storage/app/desktop-updates/current.json', publisher.validate_receipt(initial))
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertFalse(current.exists())
        desired = value.state['desired']
        self.assertEqual(desired['version'], '0.4.4-preview.101')
        self.assertEqual(value.state['counter'], 101)
        self.assertEqual(value.state['version_floor'], '0.4.4-preview.100')
        self.assertEqual(value.state['sequence_floor'], 100)
        self.assertTrue(self.pushes)
        self.assertFalse(Path(desired['candidatePath']).exists(), 'pushed private source clone must be retired')
        self.assertEqual(source.scan_snapshot(self.live, value.checkout, 1001, value.state['observed'])['metadata']['inventorySha256'], desired['inventorySha256'])
        self.assertNotIn(value.config['token'], value.state_file.read_text())

    def test_different_numeric_prefix_or_stable_manual_release_blocks_candidate_downgrade(self):
        value = self.make_worker()
        for version in ['0.5.0-preview.3', '0.5.0']:
            release = publisher.validate_receipt(self.receipt(version, sequence=100))
            current = self.write('storage/app/desktop-updates/current.json', release)
            value.state['version_floor'] = None
            with self.subTest(version=version), self.assertRaisesRegex(worker.WatchError, 'TRUSTED_SEED_UPGRADE_REQUIRED'):
                value.tick()
            self.assertTrue(current.exists())
            self.assertEqual(value.state['version_floor'], version)
            self.assertFalse(self.pushes)

    def test_live_changes_coalesce_and_invalidate_the_old_candidate_before_any_publish(self):
        value = self.make_worker()
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        first = value.state['desired']['commit']
        self.write('public/css/site.css', b'body {color: green;}\n')
        value.client.json = lambda path, missing=False: {'object': {'sha': first}}
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertNotEqual(first, value.state['desired']['commit'])
        self.assertEqual(value.state['counter'], 8)
        self.assertEqual(len(self.pushes), 2)

    def test_unknown_remote_branch_update_is_not_overwritten(self):
        value = self.make_worker(types.SimpleNamespace(json=lambda path, missing=False: {'object': {'sha': 'f' * 40}}, pages=lambda path, key: []))
        with self.assertRaisesRegex(worker.WatchError, 'REMOTE_BRANCH_CHANGED_OUTSIDE_WORKER'):
            value.tick()
        self.assertFalse(self.pushes)

    def test_disk_space_guard_blocks_large_work_without_touching_application_source(self):
        value = self.make_worker()
        original = (self.live / 'app/Http/Kernel.php').read_bytes()
        with patch.object(worker.shutil, 'disk_usage', return_value=types.SimpleNamespace(free=0)):
            with self.assertRaisesRegex(worker.WatchError, 'STORAGE_BLOCKED'):
                value.tick()
        self.assertEqual((self.live / 'app/Http/Kernel.php').read_bytes(), original)
        self.assertFalse(self.pushes)

    def test_git_token_stays_out_of_argv_saved_script_and_durable_state(self):
        state = self.work / 'state'
        state.mkdir()
        secret = 'private-token-never-write'
        client = worker.Git(self.work, secret)
        captured = []
        process = types.SimpleNamespace(returncode=0, poll=lambda: 0)
        with patch.object(worker.subprocess, 'Popen', side_effect=lambda args, **kwargs: captured.append((args, kwargs)) or process):
            client.run(['status'], self.work)
        args, options = captured[0]
        self.assertNotIn(secret, repr(args))
        self.assertEqual(options['env']['GO_ERP_GIT_TOKEN'], secret)
        self.assertNotIn('HOME', options['env'])
        self.assertNotIn(secret, client.askpass.read_text())
        self.assertIn('core.hooksPath=/dev/null', args)
        self.assertEqual(options['env']['GIT_CONFIG_NOSYSTEM'], '1')

    def test_real_candidate_to_exact_proof_download_and_source_guarded_publisher_roundtrip(self):
        value = self.make_worker()
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        desired = value.state['desired']
        values = self.proof_files(desired)
        archive = self.archive(values)
        raw = archive.read_bytes()
        accepted = run(revision=desired['commit'])
        artifact = {'id': 12, 'digest': 'sha256:' + sha(raw), 'size_in_bytes': len(raw)}
        url = 'https://api.github.com/repos/' + worker.REPOSITORY + '/actions/artifacts/12/zip'
        value.client.download = worker.GitHub('never-saved-secret', Opener([Response(raw, url)])).download
        with patch.object(worker, 'accepted_run', side_effect=lambda client, workflow, revision: accepted), \
                patch.object(worker, 'accepted_artifact', return_value=artifact):
            self.assertEqual(value.fetch_accepted(desired), 'GO_ERP_LIVE_UPDATE_PUBLISHED')
        current = publisher.read_json(self.live / 'storage/app/desktop-updates/current.json', root_owned=True)
        self.assertEqual(current['source_revision'], desired['commit'])
        self.assertEqual(current['version'], '0.4.4-preview.7')
        self.assertEqual((self.live / 'storage/app/desktop-updates' / (current['installer']['sha256'] + '.exe')).read_bytes(), values['GO-ERP-Preview-Setup.exe'])
        self.assertEqual(list((self.work / 'staging').iterdir()), [])
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_CURRENT')

    def test_stale_attempt_run_candidate_receipt_and_package_proofs_cannot_publish(self):
        value = self.make_worker()
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        desired = value.state['desired']
        accepted = run(revision=desired['commit'])
        originals = self.proof_files(desired)
        for field, wrong in [('run_id', 499), ('run_attempt', 2), ('revision', 'f' * 40),
                             ('candidate_inventory_sha256', 'f' * 64), ('full_package_sha256', 'f' * 64),
                             ('update_receipt_sha256', 'f' * 64), ('branch', 'main')]:
            values = copy.deepcopy(originals)
            proof = json.loads(values['go-erp-live-source-acceptance.json'])
            proof[field] = wrong
            values['go-erp-live-source-acceptance.json'] = worker.canonical(proof)
            folder = self.base / ('proof-' + field)
            folder.mkdir()
            for name, data in values.items():
                target = folder / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
            with self.subTest(field=field), self.assertRaises(worker.WatchError):
                worker.verify_proof(folder, accepted, desired, publisher)
        self.assertFalse((self.live / 'storage/app/desktop-updates/current.json').exists())

    def test_superseded_download_cleans_staging_and_never_publishes_a_previous_source(self):
        value = self.make_worker()
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        desired = value.state['desired']
        values = self.proof_files(desired)
        archive = self.archive(values)
        raw = archive.read_bytes()
        accepted = run(revision=desired['commit'])
        artifact = {'id': 12, 'digest': 'sha256:' + sha(raw), 'size_in_bytes': len(raw)}
        def changed_download(artifact_id, target, expected_digest, expected_size, heartbeat):
            target.write_bytes(raw)
            self.write('public/css/site.css', b'body {color: changed-during-download;}\n')
            value.live_due = 0
            heartbeat()
        value.client.download = changed_download
        with patch.object(worker, 'accepted_run', return_value=accepted), patch.object(worker, 'accepted_artifact', return_value=artifact):
            with self.assertRaises(worker.Superseded):
                value.fetch_accepted(desired)
        self.assertFalse((self.live / 'storage/app/desktop-updates/current.json').exists())
        self.assertEqual(list((self.work / 'staging').iterdir()), [])

    def test_live_change_during_real_publisher_copy_aborts_without_reentering_its_directory_lock(self):
        value = self.make_worker()
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        desired = value.state['desired']
        archive = self.archive(self.proof_files(desired))
        raw = archive.read_bytes()
        artifact = {'id': 12, 'digest': 'sha256:' + sha(raw), 'size_in_bytes': len(raw)}
        def download(artifact_id, target, expected_digest, expected_size, heartbeat):
            target.write_bytes(raw)
        value.client.download = download
        original_copy = publisher.copy_installer
        def changed_copy(installer, directory_fd, expected):
            original_copy(installer, directory_fd, expected)
            self.write('public/css/site.css', b'body {color: changed-during-publication;}\n')
        with patch.object(worker, 'accepted_run', return_value=run(revision=desired['commit'])), \
                patch.object(worker, 'accepted_artifact', return_value=artifact), \
                patch.object(publisher, 'copy_installer', side_effect=changed_copy):
            with self.assertRaises(worker.Superseded):
                value.fetch_accepted(desired)
        self.assertFalse(value.publishing)
        self.assertFalse((self.live / 'storage/app/desktop-updates/current.json').exists())
        self.assertEqual(list((self.work / 'staging').iterdir()), [])

    def test_dependency_guard_keeps_only_four_hashes_and_refuses_manifest_changes(self):
        value = self.make_worker()
        value.check_dependencies()
        initial = copy.deepcopy(value.state['dependency_guard'])
        self.assertEqual(set(initial), set(worker.DEPENDENCIES))
        self.assertTrue(all(worker.SHA.fullmatch(sha_value) for sha_value in initial.values()))
        for relative in worker.DEPENDENCIES:
            path = self.live / relative
            before = path.read_bytes()
            current = self.write('storage/app/desktop-updates/current.json', publisher.validate_receipt(self.receipt()))
            path.write_bytes(before + b' changed dependency metadata')
            with self.subTest(relative=relative), self.assertRaisesRegex(worker.WatchError, 'DEPENDENCY_CHANGE_REQUIRES_REVIEWED_SEED'):
                value.check_dependencies()
            self.assertFalse(current.exists())
            self.assertEqual(value.state['dependency_guard'], initial)
            path.write_bytes(before)
        self.assertNotIn('dependency metadata', value.state_file.read_text())

    def test_dependency_guard_rejects_links_missing_manifests_and_hardlinks(self):
        value = self.make_worker()
        path = self.live / 'composer.json'
        old = path.read_bytes()
        path.unlink()
        with self.assertRaises(worker.WatchError):
            worker.dependency_snapshot(self.live, 1001)
        path.write_bytes(old)
        os.link(path, self.live / 'composer-alias.json')
        with self.assertRaises(worker.WatchError):
            worker.dependency_snapshot(self.live, 1001)
        (self.live / 'composer-alias.json').unlink()
        path.unlink()
        path.symlink_to(self.live / 'composer.lock')
        with self.assertRaises(OSError):
            worker.dependency_snapshot(self.live, 1001)

    def test_dependency_change_during_download_aborts_even_when_source_roster_is_unchanged(self):
        value = self.make_worker()
        self.assertEqual(value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        expected = value.state['desired']['inventorySha256']
        original = source.scan_snapshot(self.live, value.checkout, 1001, value.state['observed'])['metadata']['inventorySha256']
        self.write('vendor/composer/installed.php', b'<?php /* newly installed dependency */')
        self.assertEqual(source.scan_snapshot(self.live, value.checkout, 1001, value.state['observed'])['metadata']['inventorySha256'], original)
        with self.assertRaisesRegex(worker.WatchError, 'DEPENDENCY_CHANGE_REQUIRES_REVIEWED_SEED'):
            value.guard_live(expected, force=True)

    def test_pruning_removes_only_journal_known_old_installers_and_keeps_three_recent(self):
        value = self.make_worker()
        history = []
        for number in range(5):
            body = b'MZpublished installer ' + str(number).encode()
            accepted = publisher.validate_receipt(self.receipt('0.4.4-preview.' + str(number + 7), number + 500, exe=body))
            self.write('storage/app/desktop-updates/' + accepted['installer']['sha256'] + '.exe', body)
            history.append(accepted)
        unknown = self.write('storage/app/desktop-updates/' + 'f' * 64 + '.exe', b'operator file')
        self.write('storage/app/desktop-updates/current.json', history[-1])
        value.state['published_history'] = history
        value.prune_publications()
        self.assertEqual(len(value.state['published_history']), 3)
        for index, accepted in enumerate(history):
            path = self.live / 'storage/app/desktop-updates' / (accepted['installer']['sha256'] + '.exe')
            self.assertEqual(path.exists(), index >= 2)
        self.assertEqual(unknown.read_bytes(), b'operator file')


if __name__ == '__main__':
    unittest.main()
