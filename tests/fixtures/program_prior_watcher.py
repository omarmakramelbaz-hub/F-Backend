"""State/payload/lock guards for the adapter; no server or account is accessed."""
import copy
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import types
import unittest

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('wire_hash_upgrade', ROOT / 'deployment/upgrade_go_erp_wire_hash_watcher_root.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class Base:
    @staticmethod
    def checked_state(value, seed):
        M.need(value['base_revision'] == seed and type(value['counter']) is int
               and value['counter'] >= 0, 'STATE_IDENTITY_INVALID')


class Guard:
    revision = 'a' * 40
    number = 35
    @staticmethod
    def candidate(checkout, revision):
        M.need(revision in ('a' * 40, 'b' * 40), 'REMOTE_REVISION_INVALID')
        return ('0.4.4-preview.35', 35)
    @staticmethod
    def validate_release(release):
        M.need(isinstance(release, dict), 'PUBLIC_RELEASE_UNREVIEWED')
        return release


def state():
    return {'format': 1, 'base_revision': M.OLD_SEED, 'counter': 35,
        'version_floor': '0.4.4-preview.35', 'sequence_floor': 900,
        'last_remote_sha': Guard.revision,
        'observed': {'seedRevision': M.OLD_SEED, 'inventorySha256': M.OLD_INVENTORY},
        'desired': {'commit': Guard.revision, 'inventorySha256': M.OLD_INVENTORY},
        'published': {'commit': 'b' * 40, 'inventorySha256': M.OLD_INVENTORY,
            'release': {'version': '0.4.4-preview.35', 'source_revision': 'b' * 40,
                        'source': M.OLD_SOURCE, 'sequence': 800}},
        'published_history': [{'unchanged': 'historical release fixture'}],
        'dependency_guard': {'unchanged': 'dependency fixture'}}


def migrate(value, publication=None):
    return M.migrated_state(Base, Guard(), Path('/private/checkout'), value, M.OLD_SEED, M.NEW_SEED, publication)


class StateTests(unittest.TestCase):
    def test_preserves_counter_sequence_history_and_dependency_guard(self):
        value = state(); original = copy.deepcopy(value)
        result = migrate(value)
        self.assertEqual(value, original)
        self.assertEqual(result['counter'], 35)
        self.assertEqual(result['version_floor'], '0.4.4-preview.35')
        self.assertEqual(result['sequence_floor'], 900)
        self.assertEqual(result['published_history'], original['published_history'])
        self.assertEqual(result['dependency_guard'], original['dependency_guard'])
        self.assertTrue(all(result[key] is None for key in ('desired', 'published', 'observed')))
        self.assertEqual(result['base_revision'], M.NEW_SEED)

    def test_keeps_consumed_higher_counter_and_public_sequence(self):
        value = state(); value.update(counter=40, version_floor='0.4.4-preview.39')
        result = migrate(value, {'version': '0.4.4-preview.35', 'source_revision': Guard.revision,
            'source': M.OLD_SOURCE, 'sequence': 1200})
        self.assertEqual((result['counter'], result['version_floor'], result['sequence_floor']),
                         (40, '0.4.4-preview.40', 1200))

    def test_cleared_candidates_keep_the_old_high_water_and_dependency_guard(self):
        value = state()
        value.update(observed=None, desired=None, published=None)
        original = copy.deepcopy(value)
        result = migrate(value)
        self.assertEqual(value, original)
        self.assertEqual(result['counter'], 35)
        self.assertEqual(result['version_floor'], '0.4.4-preview.35')
        self.assertEqual(result['dependency_guard'], original['dependency_guard'])
        self.assertEqual(result['published_history'], original['published_history'])

    def test_older_public_sequence_does_not_lower_the_existing_floor(self):
        result = migrate(state(), {'version': '0.4.4-preview.35',
            'source_revision': Guard.revision, 'source': M.OLD_SOURCE, 'sequence': 700})
        self.assertEqual(result['sequence_floor'], 900)

    def test_rejects_unknown_or_uncertain_candidates(self):
        mutations = [lambda x: x.update(last_remote_sha='c' * 40),
            lambda x: x['desired'].update(commit='c' * 40),
            lambda x: x['desired'].update(inventorySha256=M.NEW_INVENTORY),
            lambda x: x['published'].update(commit='c' * 40),
            lambda x: x['published']['release'].update(source=M.NEW_SOURCE),
            lambda x: x['published']['release'].update(version='0.4.4-preview.34'),
            lambda x: x['observed'].update(inventorySha256=M.NEW_INVENTORY),
            lambda x: x['observed'].update(seedRevision='c' * 40),
            lambda x: x.update(counter=34),
            lambda x: x.update(version_floor='0.4.4-preview.36')]
        for mutation in mutations:
            value = state(); mutation(value)
            with self.subTest(value=value), self.assertRaises(M.Stop):
                migrate(value)

    def test_rejects_public_release_with_unknown_revision_or_source(self):
        for publication in [
            {'version': '0.4.4-preview.35', 'source_revision': 'c' * 40, 'source': M.OLD_SOURCE, 'sequence': 1000},
            {'version': '0.4.4-preview.35', 'source_revision': Guard.revision, 'source': M.NEW_SOURCE, 'sequence': 1000}]:
            with self.subTest(publication=publication), self.assertRaises(M.Stop):
                migrate(state(), publication)


def prior(name):
    candidate = ROOT / 'deployment/wire-hash-prior/payload' / name
    return candidate.read_bytes()


class PayloadTests(unittest.TestCase):
    def test_only_two_payloads_change_and_exact_old_pins_are_required(self):
        original = {name: prior(name) for name in M.OLD_PINS}
        adapted = M.adapt_payloads(original)
        self.assertEqual([name for name in sorted(original) if original[name] != adapted[name]],
                         ['go_erp_live_source.py', 'go_erp_live_updates.py'])
        for name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
            self.assertIn(M.NEW_SEED.encode(), adapted[name])
            self.assertNotIn(M.OLD_SEED.encode(), adapted[name])
            self.assertIn(b"'-native-import-wire-hash-seed-v1'", adapted[name])
        changed = dict(original); changed['go_erp_live_source.py'] += b'\n# unreviewed\n'
        with self.assertRaisesRegex(M.Stop, '^PAYLOAD_PIN_INVALID$'):
            M.adapt_payloads(changed)

    def test_actual_pinned_publisher_reads_source_without_live_deployment_manifest(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            readers = root / 'readers'; readers.mkdir()
            for name in ('publish_go_erp_update.py', 'desktop_dashboard_server_preflight.py',
                         'diagnose_desktop_dashboard_current_source.py'):
                path = readers / name; path.write_bytes(prior(name)); path.chmod(0o644)
            application = root / 'application'; application.mkdir()
            (application / 'app').mkdir(); (application / 'desktop').mkdir()
            (application / 'app/Example.php').write_text('<?php // synthetic source only\n')
            (application / 'desktop/source-contract.json').write_text(json.dumps({'format': 1,
                'framework': '8.83.29', 'supportedRuntimeFrameworks': ['8.83.29']}))
            (application / 'composer.lock').write_text(json.dumps({'packages': [
                {'name': 'laravel/framework', 'version': 'v8.83.29'}]}))
            path = readers / 'publish_go_erp_update.py'
            publisher = types.ModuleType('fixture_pinned_publisher'); publisher.__file__ = str(path)
            sys.modules[publisher.__name__] = publisher
            exec(compile(path.read_bytes(), str(path), 'exec'), publisher.__dict__)
            absent = application / 'deployment/desktop_dashboard_server_manifest.json'
            self.assertFalse(absent.exists())
            result = publisher.source_snapshot(application)
            self.assertEqual(result['fingerprint']['framework'], '8.83.29')
            self.assertEqual(result['fingerprint']['files'], 2)
            self.assertFalse(absent.exists())


class FakeGitBase:
    REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
    def __init__(self):
        self.revision = 'a' * 40
        self.changed_names = []
        self.current_extra = False
        self.number = 36
        self.parents = [M.OLD_SEED]
    @staticmethod
    def parse(data):
        return json.loads(data)
    def git(self, root, *args, **options):
        if args[0] == 'fetch': return b''
        if args[0] == 'ls-remote':
            return (self.revision + '\trefs/heads/codex/go-erp-live-source-20261021\n').encode()
        if args[0] == 'cat-file':
            return ('tree ' + 'f' * 40 + '\n' + ''.join('parent ' + parent + '\n' for parent in self.parents) + '\nmessage\n').encode()
        if args[0] == 'diff': return b'\0'.join(name.encode() for name in self.changed_names) + b'\0'
        revision, name = args[1].split(':', 1)
        number = 35 if revision == M.BASELINE else self.number
        version = '0.4.4-preview.' + str(number)
        if name == M.VERSIONS[2]:
            value = {'version': version, 'versionSequence': number, 'baseRevision': M.OLD_SEED,
                'inventorySha256': M.OLD_INVENTORY, 'fixture': 'unchanged'}
            if revision != M.BASELINE and self.current_extra: value['unreviewed'] = True
        else:
            value = {'version': version, 'fixture': 'unchanged'}
            if name.endswith('package-lock.json'): value['packages'] = {'': {'version': version}}
        return json.dumps(value).encode()


class ReleaseGuardTests(unittest.TestCase):
    def test_accepts_higher_version_only_sibling_and_freezes_remote(self):
        base = FakeGitBase(); guard = M.ReleaseGuard(base)
        guard(Path('/private/checkout'))
        self.assertEqual(guard.number, 36)
        base.revision = 'b' * 40
        with self.assertRaisesRegex(M.Stop, '^REMOTE_BRANCH_CHANGED$'):
            guard(Path('/private/checkout'))

    def test_accepts_the_exact_current_preview35_baseline(self):
        base = FakeGitBase(); base.number = 35
        guard = M.ReleaseGuard(base)
        guard(Path('/private/checkout'))
        self.assertEqual((guard.number, guard.version), (35, '0.4.4-preview.35'))

    def test_refuses_an_older_version_or_an_unreviewed_parent(self):
        for kind in ('older-version', 'other-parent', 'merge-parent'):
            base = FakeGitBase()
            if kind == 'older-version': base.number = 34
            elif kind == 'other-parent': base.parents = ['c' * 40]
            else: base.parents.append('c' * 40)
            with self.subTest(kind=kind), self.assertRaises(M.Stop):
                M.ReleaseGuard(base)(Path('/private/checkout'))

    def test_refuses_code_or_inventory_changes(self):
        for kind in ('code', 'metadata'):
            base = FakeGitBase()
            if kind == 'code': base.changed_names = ['desktop-pos/src/local-runtime.cjs']
            else: base.current_extra = True
            with self.subTest(kind=kind), self.assertRaises(M.Stop):
                M.ReleaseGuard(base)(Path('/private/checkout'))


@unittest.skipUnless(os.geteuid() == 0, 'actual root-owned inode/lock fixture')
class LockTests(unittest.TestCase):
    def utility(self):
        candidate = ROOT / 'deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py'
        raw = candidate.read_bytes() if candidate.is_file() else subprocess.check_output(['git', '-C', str(ROOT), 'show',
            '8554b9f3a1c528e0d8970f0664022a74dab3ac59:deployment/upgrade_go_erp_capacity_watcher_root.py'])
        module = types.ModuleType('fixture_reviewed_utility'); module.__file__ = str(candidate)
        sys.modules[module.__name__] = module
        exec(compile(raw, str(candidate), 'exec'), module.__dict__)
        return module

    def test_reused_inner_context_never_releases_outer_real_lock(self):
        utility = self.utility()
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory); os.chmod(work, 0o700)
            lock = work / 'watcher.lock'; lock.write_bytes(b''); os.chmod(lock, 0o600)
            other = os.open(lock, os.O_RDONLY)
            try:
                with M.held_lock(utility, work) as (fd, verify):
                    with M.LockedUtility(utility, work, verify).watcher_lock(work):
                        verify()
                    with self.assertRaises(BlockingIOError):
                        fcntl.flock(other, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    self.assertEqual(os.fstat(fd).st_ino, lock.stat().st_ino)
                fcntl.flock(other, fcntl.LOCK_EX | fcntl.LOCK_NB)
            finally: os.close(other)

    def test_named_lock_replacement_is_detected(self):
        utility = self.utility()
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory); os.chmod(work, 0o700)
            lock = work / 'watcher.lock'; lock.write_bytes(b''); os.chmod(lock, 0o600)
            with self.assertRaisesRegex(M.Stop, '^LOCK_CHANGED$'):
                with M.held_lock(utility, work) as (_, verify):
                    lock.rename(work / 'old.lock')
                    lock.write_bytes(b''); os.chmod(lock, 0o600)
                    verify()

    def test_pending_application_or_watcher_audit_blocks_before_preliminary_config(self):
        utility = self.utility()
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory); os.chmod(work, 0o700)
            original_prefix = 'fixture-prefix-kept-'
            base = types.SimpleNamespace(PREFIX=original_prefix,
                pending_audit=lambda utility, directory: None)
            M.require_no_pending(base, utility, work)
            self.assertEqual(base.PREFIX, original_prefix)
            for index, prefix in enumerate(('import422-application-', 'wire-hash-application-')):
                audit = work / (prefix + str(index) * 32); audit.mkdir(mode=0o700)
                (audit / 'application-intent.json').write_text('{}')
                with self.subTest(prefix=prefix), self.assertRaisesRegex(M.Stop,
                        '^PRIOR_MIGRATION_REQUIRES_REVIEW$'):
                    M.require_no_pending(base, utility, work)
                self.assertEqual(base.PREFIX, original_prefix)
                final = 'application-rolled-back.json' if index == 0 else 'application-committed.json'
                (audit / final).write_text('{}')
                M.require_no_pending(base, utility, work)
            for prefix in ('import422-seed-upgrade-', 'wire-hash-seed-upgrade-'):
                observed = []
                def pending(_utility, _directory):
                    observed.append(base.PREFIX)
                    return prefix + 'pending' if base.PREFIX == prefix else None
                base.pending_audit = pending
                with self.subTest(prefix=prefix), self.assertRaisesRegex(M.Stop,
                        '^PRIOR_MIGRATION_REQUIRES_REVIEW$'):
                    M.require_no_pending(base, utility, work)
                self.assertIn(prefix, observed)
                self.assertEqual(base.PREFIX, original_prefix)

    def test_watcher_audit_read_failure_restores_the_original_prefix(self):
        utility = self.utility()
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory); os.chmod(work, 0o700)
            def unreadable(_utility, _directory):
                raise RuntimeError('synthetic private audit read failure')
            base = types.SimpleNamespace(PREFIX='fixture-prefix-kept-', pending_audit=unreadable)
            with self.assertRaisesRegex(RuntimeError, '^synthetic private audit read failure$'):
                M.require_no_pending(base, utility, work)
            self.assertEqual(base.PREFIX, 'fixture-prefix-kept-')

    def transaction(self, result, *, confirm=True, current=False, commit_fails=False):
        utility = self.utility()
        events = []
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory); os.chmod(work, 0o700)
            lock = work / 'watcher.lock'; lock.write_bytes(b''); os.chmod(lock, 0o600)
            observer = os.open(lock, os.O_RDONLY)
            old_work = M.WORK; M.WORK = work
            def event(name):
                with self.assertRaises(BlockingIOError):
                    fcntl.flock(observer, fcntl.LOCK_EX | fcntl.LOCK_NB)
                events.append(name)
            class Patch:
                def __init__(self, *args): event('construct')
                def prepare(self): event('prepare')
                def apply(self): event('apply')
                def verify(self): event('verify-app')
                def rollback(self): event('rollback-app')
                def commit(self):
                    event('commit-app')
                    if commit_fails: raise RuntimeError('cleanup failed')
            application = types.SimpleNamespace(ApplicationPatch=Patch,
                verify_current=lambda *args: event('verify-current'))
            def upgrade(*args, **kwargs):
                with kwargs['utility'].watcher_lock(work): event('watcher')
                event('watcher-inner-closed')
                return copy.deepcopy(result)
            base = types.SimpleNamespace(upgrade=upgrade)
            owner = types.SimpleNamespace(pw_uid=0, pw_gid=0)
            def confirm_rollback():
                event('confirm-old-watcher')
                if not confirm: raise RuntimeError('new private slots remain')
            try:
                with M.held_lock(utility, work) as (fd, verify):
                    if current:
                        output = M.run_current(base, utility, application, work, owner, fd, verify, {}, {}, None)
                    else:
                        output = M.run_transaction(base, utility, application, work, owner, fd, verify,
                            {}, {}, None, confirm_rollback)
                fcntl.flock(observer, fcntl.LOCK_EX | fcntl.LOCK_NB)
                return output, events
            except BaseException as error:
                error.fixture_events = events
                raise
            finally:
                M.WORK = old_work; os.close(observer)

    def test_success_commits_application_only_after_watcher_with_one_held_lock(self):
        result, events = self.transaction({'ok': True, 'counter': 35, 'rollback_complete': True})
        self.assertTrue(result['application_contents_changed'])
        self.assertEqual(result['next_version'], '0.4.4-preview.36')
        self.assertLess(events.index('watcher-inner-closed'), events.index('commit-app'))
        self.assertNotIn('rollback-app', events)

    def test_success_uses_a_consumed_higher_counter_for_the_next_version(self):
        result, events = self.transaction({'ok': True, 'counter': 43, 'rollback_complete': True})
        self.assertTrue(result['application_contents_changed'])
        self.assertEqual(result['next_version'], '0.4.4-preview.44')
        self.assertLess(events.index('watcher-inner-closed'), events.index('commit-app'))

    def test_complete_watcher_rollback_is_recognized_before_application_rollback(self):
        result, events = self.transaction({'ok': False, 'rollback_complete': True, 'state_changed': True})
        self.assertFalse(result['application_contents_changed'])
        self.assertLess(events.index('confirm-old-watcher'), events.index('rollback-app'))
        self.assertEqual(events.count('rollback-app'), 1)
        self.assertNotIn('commit-app', events)

    def test_uncertain_watcher_rollback_keeps_new_application_and_original_backups(self):
        result, events = self.transaction({'ok': False, 'rollback_complete': False, 'recovery_required': True})
        self.assertFalse(result['rollback_complete'])
        self.assertNotIn('rollback-app', events)
        self.assertNotIn('commit-app', events)
        with self.assertRaisesRegex(M.Stop, '^WATCHER_ROLLBACK_REQUIRES_REVIEW$') as error:
            self.transaction({'ok': False, 'rollback_complete': True}, confirm=False)
        self.assertNotIn('rollback-app', error.exception.fixture_events)

    def test_application_finalize_failure_never_rolls_back_matching_new_watcher(self):
        with self.assertRaisesRegex(M.Stop, '^APPLICATION_COMMIT_REQUIRES_REVIEW$') as error:
            self.transaction({'ok': True, 'counter': 35, 'rollback_complete': True}, commit_fails=True)
        self.assertNotIn('rollback-app', error.exception.fixture_events)

    def test_already_current_verifies_without_constructing_or_applying_patch(self):
        result, events = self.transaction({'ok': True, 'already_current': True, 'counter': 35,
            'rollback_complete': True}, current=True)
        self.assertTrue(result['already_current'])
        self.assertFalse(result['application_contents_changed'])
        self.assertEqual(events, ['verify-current', 'watcher', 'watcher-inner-closed', 'verify-current'])


if __name__ == '__main__':
    unittest.main()
