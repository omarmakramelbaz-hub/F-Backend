#!/usr/bin/env python3
"""Single-file workflow publication fixtures; no database, network or customer data."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import pathlib
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / 'deployment/upgrade_whatsapp_order_workflow.py'
SPEC = importlib.util.spec_from_file_location('whatsapp_order_workflow_upgrader', MODULE_PATH)
upgrader = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = upgrader
SPEC.loader.exec_module(upgrader)
base = upgrader.base
RELEASE = 'a' * 40
OLD = b'<?php namespace App\\Services\\Dashboard; class WhatsAppOrderWorkflow { /* fixture installed b119 workflow */ }\n'
TARGET = b'<?php namespace App\\Services\\Dashboard; class WhatsAppOrderWorkflow { /* fixture reviewed f7ea workflow */ }\n'
IMMUTABLES = (
    'routes/admin.php', 'resources/views/admin/layouts/menu.blade.php',
    'app/Services/Dashboard/PhoneDelivery.php', 'app/Services/Dashboard/PosServiceTicket.php',
    'app/Console/Kernel.php', 'config/whatsapp_orders.php', 'config/whatsapp_replies.php',
    'database/migrations/fixture.php',
)


def metadata(path):
    info = path.lstat()
    return (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_dev, info.st_ino,
            info.st_size, info.st_mtime_ns, info.st_ctime_ns, info.st_nlink)


class Fixture:
    def __init__(self, directory, cached=True, current=OLD):
        self.uid = os.geteuid()
        self.root = pathlib.Path(directory) / 'project'
        self.root.mkdir(mode=0o700)
        (self.root / '.git').mkdir(mode=0o700)
        self.write(upgrader.WORKFLOW_PATH, current, 0o640)
        self.env = b'APP_KEY=fixture-only\nWHATSAPP_ORDERS_MODE=review\nWHATSAPP_ORDERS_MODEL=fixture\n'
        self.write('.env', self.env, 0o600)
        self.cache = b"<?php return ['whatsapp_orders'=>['enabled'=>true,'mode'=>'review','model'=>'fixture','api_key'=>'sk-fixture-only'],'whatsapp_replies'=>['enabled'=>true,'access_token'=>'fixture-only'],'legacy'=>['enabled'=>true,'nested'=>['number'=>7,'nil'=>null]]];\n"
        if cached:
            self.write(upgrader.CACHE_PATH, self.cache, 0o600)
        else:
            (self.root / upgrader.CACHE_PATH).parent.mkdir(parents=True, exist_ok=True)
        for index, relative in enumerate(IMMUTABLES):
            self.write(relative, b'<?php // unrelated original ' + str(index).encode() + b'\n', 0o640)
        self.originals = {p: (self.path(p).read_bytes(), metadata(self.path(p)))
                          for p in ('.env', *IMMUTABLES)}
        self.cache_before = ((self.cache, metadata(self.path(upgrader.CACHE_PATH))) if cached else None)
        self.calls = []
        self.hook = None
        self.fail = None
        self.tree_mode = b'100644'
        self.tree_blob = base.blob_hash(TARGET).encode()
        self.git_content = TARGET
        self.git_kind = b'commit\n'
        self.review = True
        self.fingerprint = hashlib.sha256(b'fixture-effective-orders-and-replies').hexdigest()

    def path(self, relative):
        return self.root / relative

    def write(self, relative, content, mode=0o644):
        path = self.path(relative)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)

    def initial_install_config_hardlinks(self):
        """Keep the exact private publication links created by the prior installer."""
        backups = self.root.parent / 'whatsapp-release-backups'
        backups.mkdir(mode=0o700, exist_ok=True)
        private = pathlib.Path(tempfile.mkdtemp(prefix='initial-install-', dir=backups))
        journal = {}
        aliases = {}
        for relative in upgrader.CONFIG_PATHS:
            path = self.path(relative)
            content = path.read_bytes()
            path.unlink()
            base.replace_file(path, content, None, self.uid, self.root, {},
                              journal=journal, relative=relative, private_directory=private)
            aliases[relative] = journal[relative].temporary
            self.originals[relative] = (content, metadata(path))
        return aliases

    def runner(self, directory, args, capture=False, timeout=60):
        self.calls.append((pathlib.Path(directory), tuple(args), capture))
        if self.hook:
            self.hook(directory, args)
        if self.fail and self.fail(args):
            return 1, b'PRIVATE_SUBPROCESS_MESSAGE_MUST_NOT_ESCAPE'
        if args[:3] == ['git', 'cat-file', '-t']:
            return 0, self.git_kind
        if args[:3] == ['git', 'ls-tree', '-z']:
            return 0, self.tree_mode + b' blob ' + self.tree_blob + b'\t' + upgrader.WORKFLOW_PATH.encode() + b'\0'
        if args[:2] == ['git', 'show']:
            return 0, self.git_content
        if args[:2] == ['php', '-l']:
            return 0, b''
        if args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] in ('source', 'target'):
            return 0, json.dumps({'review': self.review, 'fingerprint': self.fingerprint}).encode()
        raise AssertionError('Unexpected command in read-only single-file upgrade: ' + repr(args[:2]))

    def upgrade(self):
        # Only fixtures inject hashes; the production CLI retains its exact release/blob defaults.
        return upgrader.upgrade(self.root, self.uid, self.runner, RELEASE,
                                base.blob_hash(TARGET), base.blob_hash(OLD))

    def assert_unrelated_unchanged(self, case):
        for relative, (content, before) in self.originals.items():
            case.assertEqual(self.path(relative).read_bytes(), content, relative)
            case.assertEqual(metadata(self.path(relative)), before, relative)
        if self.cache_before is None:
            case.assertFalse(self.path(upgrader.CACHE_PATH).exists())
        else:
            content, before = self.cache_before
            case.assertEqual(self.path(upgrader.CACHE_PATH).read_bytes(), content)
            case.assertEqual(metadata(self.path(upgrader.CACHE_PATH)), before)

    def assert_workflow(self, case, wanted):
        case.assertEqual(self.path(upgrader.WORKFLOW_PATH).read_bytes(), wanted)
        case.assertEqual(stat.S_IMODE(self.path(upgrader.WORKFLOW_PATH).stat().st_mode), 0o640)


class WorkflowUpgradeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='whatsapp-workflow-upgrade-test-')
        self.fixture = Fixture(self.directory.name)

    def tearDown(self):
        self.directory.cleanup()

    def test_pinned_old_to_target_publishes_only_workflow_preserving_every_other_byte_and_metadata(self):
        f = self.fixture
        before = base.snapshot(f.path(upgrader.WORKFLOW_PATH), f.uid, f.root)
        status, backup = f.upgrade()
        self.assertIn('UPGRADED', status)
        f.assert_workflow(self, TARGET)
        f.assert_unrelated_unchanged(self)
        after = base.snapshot(f.path(upgrader.WORKFLOW_PATH), f.uid, f.root)
        self.assertEqual((after.uid, after.gid, after.mode), (before.uid, before.gid, before.mode))
        self.assertNotEqual(after.inode, before.inode)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        self.assertTrue(any(p.read_bytes() == OLD for p in backup.rglob('*') if p.is_file()))
        self.assertFalse(any(p.read_bytes() in (f.env, f.cache) for p in backup.rglob('*') if p.is_file()))
        phases = [args[-1] for _, args, _ in f.calls if args[:3] == ('php', '-r', upgrader.READINESS_PHP)]
        self.assertEqual(phases, ['source', 'target'])
        self.assertTrue(all(args[0] in ('git', 'php') for _, args, _ in f.calls))
        self.assertFalse(any('artisan' in args or any(word in args for word in
            ('migrate', 'config:cache', 'config:clear', 'route:clear', 'optimize:clear',
             'whatsapp:process-orders', 'whatsapp:consume-inbox', 'pull', 'reset', 'checkout'))
            for _, args, _ in f.calls))
        for _, args, _ in f.calls:
            if args[:2] == ('php', '-r'):
                self.assertEqual(args[2], upgrader.READINESS_PHP)
            elif args[:2] == ('php', '-l'):
                self.assertNotIn('.env', args[-1])
                self.assertNotIn(upgrader.CACHE_PATH, args[-1])

    def test_uncached_upgrade_preserves_cache_absence(self):
        with tempfile.TemporaryDirectory() as directory:
            f = Fixture(directory, cached=False)
            f.upgrade()
            f.assert_workflow(self, TARGET)
            f.assert_unrelated_unchanged(self)

    def test_prior_initial_installer_private_config_links_upgrade_without_metadata_change(self):
        f = self.fixture
        aliases = f.initial_install_config_hardlinks()
        before_aliases = {}
        for relative, alias in aliases.items():
            original = metadata(f.path(relative))
            self.assertEqual(original[3:5], metadata(alias)[3:5])
            self.assertEqual(original[-1], 2)
            before_aliases[relative] = (alias.read_bytes(), metadata(alias))
        status, _ = f.upgrade()
        self.assertIn('UPGRADED', status)
        f.assert_workflow(self, TARGET)
        f.assert_unrelated_unchanged(self)
        for relative, alias in aliases.items():
            self.assertEqual((alias.read_bytes(), metadata(alias)), before_aliases[relative])

    def test_identical_target_repeat_preserves_inode_mode_and_does_not_create_backup(self):
        f = self.fixture
        f.upgrade()
        before = metadata(f.path(upgrader.WORKFLOW_PATH))
        backups = list((f.root.parent / 'whatsapp-release-backups').glob('*'))
        status, backup = f.upgrade()
        self.assertIn('ALREADY_CURRENT', status)
        self.assertIsNone(backup)
        self.assertEqual(metadata(f.path(upgrader.WORKFLOW_PATH)), before)
        self.assertEqual(list((f.root.parent / 'whatsapp-release-backups').glob('*')), backups)
        f.assert_unrelated_unchanged(self)

    def test_unknown_source_rejects_before_php_or_publication(self):
        f = self.fixture
        external = OLD + b'// unreviewed change\n'
        f.write(upgrader.WORKFLOW_PATH, external, 0o640)
        with self.assertRaises(base.SafeError): f.upgrade()
        f.assert_workflow(self, external)
        f.assert_unrelated_unchanged(self)
        self.assertFalse(any(args[0] == 'php' for _, args, _ in f.calls))

    def test_missing_source_rejects(self):
        f = self.fixture
        f.path(upgrader.WORKFLOW_PATH).unlink()
        with self.assertRaises(base.SafeError): f.upgrade()
        self.assertFalse(f.path(upgrader.WORKFLOW_PATH).exists())
        f.assert_unrelated_unchanged(self)

    def test_invalid_target_git_mode_is_refused(self):
        for mode in (b'120000', b'040000', b'100664'):
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory);f.tree_mode = mode
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD);f.assert_unrelated_unchanged(self)

    def test_target_tree_blob_and_actual_bytes_both_must_match(self):
        for mismatch in ('tree', 'bytes', 'empty'):
            with self.subTest(mismatch=mismatch), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory)
                if mismatch == 'tree': f.tree_blob = b'b' * 40
                elif mismatch == 'bytes': f.git_content += b'// substituted content\n'
                else: f.git_content = b''
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD);f.assert_unrelated_unchanged(self)

    def test_pinned_release_must_be_a_commit_and_staged_php_must_lint(self):
        for failure in ('kind', 'lint'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory)
                if failure == 'kind': f.git_kind = b'tree\n'
                else: f.fail = lambda args: args[:2] == ['php', '-l']
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD);f.assert_unrelated_unchanged(self)

    def test_workflow_symlink_is_refused_without_touching_referent(self):
        f = self.fixture
        outside = pathlib.Path(self.directory.name) / 'outside.php'
        outside.write_bytes(OLD)
        f.path(upgrader.WORKFLOW_PATH).unlink()
        f.path(upgrader.WORKFLOW_PATH).symlink_to(outside)
        with self.assertRaises(base.SafeError): f.upgrade()
        self.assertTrue(f.path(upgrader.WORKFLOW_PATH).is_symlink())
        self.assertEqual(outside.read_bytes(), OLD)
        f.assert_unrelated_unchanged(self)

    def test_workflow_ancestor_symlink_is_refused(self):
        f = self.fixture
        ancestor = f.path('app/Services/Dashboard')
        outside = pathlib.Path(self.directory.name) / 'outside-directory'
        ancestor.rename(outside)
        ancestor.symlink_to(outside, target_is_directory=True)
        with self.assertRaises(base.SafeError): f.upgrade()
        self.assertEqual((outside / 'WhatsAppOrderWorkflow.php').read_bytes(), OLD)

    def test_wrong_file_owner_is_refused(self):
        for relative in (upgrader.WORKFLOW_PATH, '.env', upgrader.CACHE_PATH, *upgrader.CONFIG_PATHS):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory)
                if relative in upgrader.CONFIG_PATHS:
                    f.initial_install_config_hardlinks()
                # User namespaces may disallow chown even as uid0. Exercise the
                # actual owner guard with a changed fstat owner for one real inode.
                wanted = f.path(relative).stat()
                actual = os.fstat
                def wrong_owner(descriptor):
                    result = actual(descriptor)
                    if (result.st_dev, result.st_ino) == (wanted.st_dev, wanted.st_ino):
                        fields = list(result);fields[4] = f.uid + 1
                        return os.stat_result(fields)
                    return result
                with mock.patch.object(os, 'fstat', wrong_owner):
                    with self.assertRaises(base.SafeError): f.upgrade()
                self.assertEqual(f.path(upgrader.WORKFLOW_PATH).read_bytes(), OLD)

    def test_env_cache_and_source_config_symlinks_are_refused(self):
        for relative in ('.env', upgrader.CACHE_PATH, *upgrader.CONFIG_PATHS):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory);path = f.path(relative)
                outside = pathlib.Path(directory) / 'outside';outside.write_bytes(path.read_bytes())
                path.unlink();path.symlink_to(outside)
                before = metadata(outside)
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD)
                self.assertTrue(path.is_symlink());self.assertEqual(metadata(outside), before)

    def test_env_and_cache_hardlinks_remain_rejected(self):
        for relative in ('.env', upgrader.CACHE_PATH):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory)
                alias = pathlib.Path(directory) / 'secret-alias'
                os.link(f.path(relative), alias)
                before = (f.path(relative).read_bytes(), metadata(f.path(relative)))
                self.assertEqual(before[1][-1], 2)
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD)
                self.assertEqual((f.path(relative).read_bytes(), metadata(f.path(relative))), before)
                self.assertEqual((alias.read_bytes(), metadata(alias)), before)
                self.assertFalse(f.calls)

    def test_nonstandard_effective_cache_path_and_nonreview_mode_fail_precheck(self):
        for failure in ('cache_path', 'auto'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory)
                if failure == 'cache_path': f.fail = lambda args: args[:3] == ['php', '-r', upgrader.READINESS_PHP]
                else: f.review = False
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD);f.assert_unrelated_unchanged(self)

    def test_prepublication_workflow_race_retains_external_change(self):
        f = self.fixture;external = OLD + b'// concurrent author change\n';done = False
        def hook(directory, args):
            nonlocal done
            if not done and args[:2] == ['php', '-l']:
                done = True;f.write(upgrader.WORKFLOW_PATH, external, 0o640)
        f.hook = hook
        with self.assertRaises(base.SafeError): f.upgrade()
        self.assertTrue(done);f.assert_workflow(self, external);f.assert_unrelated_unchanged(self)

    def test_prepublication_env_or_cache_race_keeps_original_workflow(self):
        for relative in ('.env', upgrader.CACHE_PATH):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory);external = f.path(relative).read_bytes() + b'\n# concurrent setting\n';done = False
                def hook(directory, args):
                    nonlocal done
                    if not done and args[:2] == ['php', '-l']:
                        done = True;f.write(relative, external, 0o600)
                f.hook = hook
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD);self.assertEqual(f.path(relative).read_bytes(), external)

    def test_same_bytes_different_env_or_cache_inode_is_detected(self):
        for relative in ('.env', upgrader.CACHE_PATH):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory);before = metadata(f.path(relative));done = False
                def hook(directory, args):
                    nonlocal done
                    if not done and args[:2] == ['php', '-l']:
                        done = True;content = f.path(relative).read_bytes();f.path(relative).unlink();f.write(relative, content, 0o600)
                f.hook = hook
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD);self.assertNotEqual(metadata(f.path(relative)), before)

    def test_prepublication_feature_config_source_drift_aborts_without_workflow_change(self):
        for relative in ('config/whatsapp_orders.php', 'config/whatsapp_replies.php'):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory);external = b"<?php return ['mode'=>'auto'];\n";done = False
                def hook(directory, args):
                    nonlocal done
                    if not done and args[:2] == ['php', '-l']:
                        done = True;f.write(relative, external, 0o640)
                f.hook = hook
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, OLD);self.assertEqual(f.path(relative).read_bytes(), external)
                self.assertEqual(f.path('.env').read_bytes(), f.env)
                self.assertEqual(metadata(f.path('.env')), f.originals['.env'][1])
                self.assertEqual(metadata(f.path(upgrader.CACHE_PATH)), f.cache_before[1])

    def test_postpublication_feature_config_source_drift_retains_new_guard(self):
        for relative in ('config/whatsapp_orders.php', 'config/whatsapp_replies.php'):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory);external = b"<?php return ['mode'=>'auto'];\n"
                def hook(directory, args):
                    if args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target':
                        f.write(relative, external, 0o640)
                f.hook = hook
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, TARGET);self.assertEqual(f.path(relative).read_bytes(), external)
                self.assertEqual(metadata(f.path('.env')), f.originals['.env'][1])
                self.assertEqual(metadata(f.path(upgrader.CACHE_PATH)), f.cache_before[1])

    def test_private_config_alias_mutation_is_detected_before_and_after_publication(self):
        for phase in ('source', 'target'):
            for relative in upgrader.CONFIG_PATHS:
                with self.subTest(phase=phase, relative=relative), tempfile.TemporaryDirectory() as directory:
                    f = Fixture(directory)
                    aliases = f.initial_install_config_hardlinks()
                    before_inode = metadata(f.path(relative))[3:5]
                    external = b"<?php return ['mode'=>'auto','changed'=>'external'];\n"
                    done = False
                    def hook(directory, args):
                        nonlocal done
                        trigger = (args[:2] == ['php', '-l'] if phase == 'source' else
                                   args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target')
                        if trigger and not done:
                            done = True
                            aliases[relative].write_bytes(external)
                    f.hook = hook
                    with self.assertRaises(base.SafeError) as caught: f.upgrade()
                    self.assertTrue(done)
                    self.assertIn('CONFIGURATION_CHANGED', str(caught.exception))
                    f.assert_workflow(self, OLD if phase == 'source' else TARGET)
                    self.assertEqual(metadata(f.path(relative))[3:5], before_inode)
                    self.assertEqual(metadata(aliases[relative])[3:5], before_inode)
                    self.assertEqual(f.path(relative).read_bytes(), external)
                    self.assertEqual(aliases[relative].read_bytes(), external)
                    self.assertEqual(metadata(f.path(relative))[-1], 2)
                    for other, (content, before) in f.originals.items():
                        if other != relative:
                            self.assertEqual((f.path(other).read_bytes(), metadata(f.path(other))), (content, before))
                    self.assertEqual(metadata(f.path(upgrader.CACHE_PATH)), f.cache_before[1])

    def test_same_bytes_config_alias_rewrite_or_unlink_cannot_hide_metadata_drift(self):
        for mutation in ('rewrite_restore', 'unlink'):
            for phase in ('source', 'target'):
                with self.subTest(mutation=mutation, phase=phase), tempfile.TemporaryDirectory() as directory:
                    f = Fixture(directory)
                    relative = upgrader.CONFIG_PATHS[0]
                    alias = f.initial_install_config_hardlinks()[relative]
                    before = metadata(f.path(relative))
                    original = f.path(relative).read_bytes()
                    done = False
                    def hook(directory, args):
                        nonlocal done
                        trigger = (args[:2] == ['php', '-l'] if phase == 'source' else
                                   args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target')
                        if trigger and not done:
                            done = True
                            if mutation == 'rewrite_restore':
                                alias.write_bytes(original + b'// transient external edit\n')
                                alias.write_bytes(original)
                            else:
                                alias.unlink()
                    f.hook = hook
                    with self.assertRaises(base.SafeError) as caught: f.upgrade()
                    self.assertTrue(done)
                    self.assertIn('CONFIGURATION_CHANGED', str(caught.exception))
                    f.assert_workflow(self, OLD if phase == 'source' else TARGET)
                    self.assertEqual(f.path(relative).read_bytes(), original)
                    self.assertEqual(metadata(f.path(relative))[3:5], before[3:5])
                    self.assertNotEqual(metadata(f.path(relative)), before)
                    self.assertEqual(metadata(f.path(relative))[-1], 2 if mutation == 'rewrite_restore' else 1)
                    self.assertEqual(metadata(f.path('.env')), f.originals['.env'][1])
                    self.assertEqual(metadata(f.path(upgrader.CACHE_PATH)), f.cache_before[1])

    def test_postpublication_env_or_cache_change_retains_new_guard_and_external_config(self):
        for relative in ('.env', upgrader.CACHE_PATH):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory);external = f.path(relative).read_bytes() + b'\n# concurrent change\n'
                def hook(directory, args):
                    if args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target':
                        f.write(relative, external, 0o600)
                f.hook = hook
                with self.assertRaises(base.SafeError): f.upgrade()
                f.assert_workflow(self, TARGET);self.assertEqual(f.path(relative).read_bytes(), external)

    def test_postpublication_config_fingerprint_change_retains_new_guard(self):
        f = self.fixture
        def hook(directory, args):
            if args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target':
                f.fingerprint = hashlib.sha256(b'concurrent-effective-configuration').hexdigest()
        f.hook = hook
        with self.assertRaises(base.SafeError): f.upgrade()
        f.assert_workflow(self, TARGET);f.assert_unrelated_unchanged(self)

    def test_postpublication_nonreview_effective_mode_retains_new_guard(self):
        f = self.fixture
        def hook(directory, args):
            if args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target':
                f.review = False
        f.hook = hook
        with self.assertRaises(base.SafeError): f.upgrade()
        f.assert_workflow(self, TARGET);f.assert_unrelated_unchanged(self)

    def test_postpublication_cache_creation_is_retained_with_new_guard(self):
        with tempfile.TemporaryDirectory() as directory:
            f = Fixture(directory, cached=False)
            def hook(directory, args):
                if args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target':
                    f.write(upgrader.CACHE_PATH, f.cache, 0o600)
            f.hook = hook
            with self.assertRaises(base.SafeError): f.upgrade()
            f.assert_workflow(self, TARGET);self.assertEqual(f.path(upgrader.CACHE_PATH).read_bytes(), f.cache)

    def test_interruption_before_or_after_exchange_restores_only_own_workflow(self):
        for phase in ('before', 'after'):
            with self.subTest(phase=phase), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory);actual = base.atomic_exchange;interrupted = False
                def exchange(source, destination):
                    nonlocal interrupted
                    if pathlib.Path(destination) == f.path(upgrader.WORKFLOW_PATH) and not interrupted:
                        interrupted = True
                        if phase == 'after': actual(source, destination)
                        raise KeyboardInterrupt()
                    actual(source, destination)
                with mock.patch.object(base, 'atomic_exchange', exchange):
                    with self.assertRaises(base.SafeError): f.upgrade()
                self.assertTrue(interrupted);f.assert_workflow(self, OLD);f.assert_unrelated_unchanged(self)

    def test_smoke_failure_with_unchanged_configuration_restores_workflow_and_hides_output(self):
        f = self.fixture
        f.fail = lambda args: args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target'
        with self.assertRaises(base.SafeError) as caught: f.upgrade()
        self.assertNotIn('PRIVATE_SUBPROCESS', str(caught.exception))
        f.assert_workflow(self, OLD);f.assert_unrelated_unchanged(self)

    def test_invalid_smoke_report_is_private_and_restores_workflow(self):
        f = self.fixture;actual = f.runner
        def runner(directory, args, **kwargs):
            code, body = actual(directory, args, **kwargs)
            if args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target':
                return 0, b'{"private":"MUST_NOT_ESCAPE"}'
            return code, body
        f.runner = runner
        with self.assertRaises(base.SafeError) as caught: f.upgrade()
        self.assertNotIn('MUST_NOT_ESCAPE', str(caught.exception))
        f.assert_workflow(self, OLD);f.assert_unrelated_unchanged(self)

    def test_preexchange_concurrent_workflow_edit_survives_recovery(self):
        f = self.fixture;actual = base.atomic_exchange;external = OLD + b'// changed immediately before exchange\n';done = False
        def exchange(source, destination):
            nonlocal done
            if pathlib.Path(destination) == f.path(upgrader.WORKFLOW_PATH) and not done:
                done = True;f.write(upgrader.WORKFLOW_PATH, external, 0o640)
            actual(source, destination)
        with mock.patch.object(base, 'atomic_exchange', exchange):
            with self.assertRaises(base.SafeError): f.upgrade()
        self.assertTrue(done);f.assert_workflow(self, external);f.assert_unrelated_unchanged(self)

    def test_postexchange_concurrent_workflow_edit_is_never_overwritten_by_rollback(self):
        f = self.fixture;actual = base.atomic_exchange;external = TARGET + b'// newer external guard\n';done = False
        def exchange(source, destination):
            nonlocal done
            actual(source, destination)
            if pathlib.Path(destination) == f.path(upgrader.WORKFLOW_PATH) and not done:
                done = True;f.write(upgrader.WORKFLOW_PATH, external, 0o640);raise KeyboardInterrupt()
        with mock.patch.object(base, 'atomic_exchange', exchange):
            with self.assertRaises(base.SafeError): f.upgrade()
        self.assertTrue(done);f.assert_workflow(self, external);f.assert_unrelated_unchanged(self)

    def test_rollback_move_race_retains_external_workflow(self):
        f = self.fixture;actual = base.atomic_move_noreplace;external = TARGET + b'// changed during recovery\n';done = False
        f.fail = lambda args: args[:3] == ['php', '-r', upgrader.READINESS_PHP] and args[-1] == 'target'
        def move(source, destination):
            nonlocal done
            if pathlib.Path(source) == f.path(upgrader.WORKFLOW_PATH) and not done:
                done = True;f.write(upgrader.WORKFLOW_PATH, external, 0o640)
            actual(source, destination)
        with mock.patch.object(base, 'atomic_move_noreplace', move):
            with self.assertRaises(base.SafeError): f.upgrade()
        self.assertTrue(done);f.assert_workflow(self, external);f.assert_unrelated_unchanged(self)

    def test_production_cli_has_fixed_pins_and_rejects_injected_release_or_root(self):
        self.assertRegex(upgrader.TARGET_RELEASE, r'\A[0-9a-f]{40}\Z')
        self.assertRegex(upgrader.TARGET_BLOB, r'\A[0-9a-f]{40}\Z')
        self.assertRegex(upgrader.SOURCE_BLOB, r'\A[0-9a-f]{40}\Z')
        for arguments in (['--root', str(upgrader.APP_ROOT), '--release', RELEASE],
                          ['--root', str(self.fixture.root)], ['--target-blob', 'b' * 40]):
            with self.subTest(arguments=arguments), mock.patch.object(sys, 'argv', ['upgrader', *arguments]), \
                    mock.patch.object(upgrader, 'upgrade') as operation, contextlib.redirect_stderr(io.StringIO()), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(upgrader.main(), 1)
                operation.assert_not_called()

    def test_real_php_readiness_is_read_only_and_effective_feature_fingerprint_is_stable(self):
        php = os.environ.get('WHATSAPP_TEST_PHP') or shutil.which('php')
        if not php: self.skipTest('PHP unavailable; publication fixtures remain independent')
        f = self.fixture
        f.write(upgrader.WORKFLOW_PATH, b'''<?php
namespace App\\Services\\Dashboard;
class WhatsAppOrderWorkflow {
 function available() { return true; }
 private function activationBoundary($x) { return 0; }
 private function automaticFloor($x) { return 0; }
 function process() { throw new \\RuntimeException('FORBIDDEN_PROCESS'); }
 function analyze() { throw new \\RuntimeException('FORBIDDEN_ANALYSIS'); }
}
''', 0o640)
        f.write('vendor/autoload.php', b'''<?php
class FixtureKernel {
 function bootstrap() {}
 function all() { return ['whatsapp:process-orders'=>null]; }
}
class FixtureApplication {
 function make($class) { return new FixtureKernel(); }
 function getCachedConfigPath() { return getenv('FIXTURE_WRONG_CACHE') ?: getcwd().'/bootstrap/cache/config.php'; }
}
function app($class) { return new $class(); }
function config($name) { $all=require getcwd().'/bootstrap/cache/config.php';return $all[$name] ?? null; }
require getcwd().'/app/Services/Dashboard/WhatsAppOrderWorkflow.php';
''')
        f.write('bootstrap/app.php', b'<?php return new FixtureApplication();\n')
        paths = ('.env', upgrader.CACHE_PATH, upgrader.WORKFLOW_PATH, *IMMUTABLES,
                 'vendor/autoload.php', 'bootstrap/app.php')
        before = {p: (f.path(p).read_bytes(), metadata(f.path(p))) for p in paths}
        outputs = []
        for phase in ('source', 'target'):
            result = subprocess.run([php, '-r', upgrader.READINESS_PHP, phase], cwd=f.root,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
            self.assertEqual(result.returncode, 0, result.stderr.decode(errors='replace'))
            outputs.append(json.loads(result.stdout))
        self.assertEqual(outputs[0], outputs[1])
        self.assertEqual(outputs[0]['review'], True)
        self.assertRegex(outputs[0]['fingerprint'], r'\A[0-9a-f]{64}\Z')
        self.assertNotIn(b'sk-fixture-only', json.dumps(outputs).encode())
        expected = subprocess.run([php, '-r',
            "$c=require $argv[1];echo hash('sha256',serialize([$c['whatsapp_orders'],$c['whatsapp_replies']]));",
            str(f.path(upgrader.CACHE_PATH))], stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
        self.assertEqual(expected.returncode, 0)
        self.assertEqual(outputs[0]['fingerprint'], expected.stdout.decode())
        wrong_env = dict(os.environ, FIXTURE_WRONG_CACHE=str(f.root / 'custom-cache.php'))
        rejected = subprocess.run([php, '-r', upgrader.READINESS_PHP, 'target'], cwd=f.root,
                                  env=wrong_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
        self.assertNotEqual(rejected.returncode, 0)
        self.assertEqual(rejected.stdout, b'')
        self.assertEqual(before, {p: (f.path(p).read_bytes(), metadata(f.path(p))) for p in paths})


if __name__ == '__main__':
    unittest.main()
