import importlib.util
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest

SPEC = importlib.util.spec_from_file_location('website_publish', Path(__file__).resolve().parents[1] / 'publish.py')
publish = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(publish)


class FakeRunner:
    def __init__(self):
        self.calls = []
        self.fail_lint = False
        self.fail_migrate = False
        self.ready = dict(enabled=True, table_present=True, token_configured=True,
                          test_event_code_configured=False,
                          dataset_id='1183070883497428', pending_count=0, sent_count=0)

    def __call__(self, args, **kwargs):
        if not isinstance(args, list) or 'shell' in kwargs:
            raise AssertionError('Commands must use argv without a shell')
        self.calls.append(args)
        failed = (self.fail_lint and '-l' in args) or (self.fail_migrate and 'migrate' in args)
        output = json.dumps(self.ready) if '--check' in args or '-r' in args else ''
        return SimpleNamespace(returncode=int(failed), stdout=output, stderr='private diagnostic omitted')


class PublicationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.package, self.app = self.root / 'package', self.root / 'app'
        self.backups, self.cron = self.root / 'backups', self.root / 'cron.d' / 'website'
        self.log = self.root / 'logs' / 'website.log'
        self.website = 'app/Services/Site/WebStorefront.php'
        self.old = b'<?php // original checkout\n'
        self.after, self.manifest = {}, {}
        for name in sorted(publish.PATHS):
            data = ('<?php // release ' + name + '\n').encode()
            self.after[name] = data
            path = self.package / 'payload' / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
            self.manifest[name] = dict(before=publish.digest(self.old) if name == self.website else None,
                                       after=publish.digest(data))
        original = self.app / self.website
        original.parent.mkdir(parents=True)
        original.write_bytes(self.old)
        original.chmod(0o640)
        self.write_manifest()
        self.runner = FakeRunner()

    def write_manifest(self):
        (self.package / 'manifest.json').write_text(json.dumps(self.manifest))

    def publisher(self):
        return publish.Publisher(self.package, app=self.app, backups=self.backups,
            cron=self.cron, log=self.log, runner=self.runner,
            identity=SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid()),
            cron_owner=(os.getuid(), os.getgid()))

    def assert_original(self):
        self.assertEqual((self.app / self.website).read_bytes(), self.old)
        for name in publish.PATHS - {self.website}:
            self.assertFalse((self.app / name).exists())

    def test_check_is_read_only_and_lints_every_file(self):
        states, cron = self.publisher().preflight()
        self.assertEqual(len(states), 4)
        self.assertIsNone(cron)
        self.assertEqual(sum('-l' in c for c in self.runner.calls), 4)
        self.assertEqual(sum('-r' in c for c in self.runner.calls), 1)
        self.assertFalse(self.backups.exists())
        self.assertFalse(self.cron.exists())
        self.assert_original()

    def test_changed_checkout_blocks_before_mutation(self):
        target = self.app / self.website
        target.write_bytes(b'<?php // newer real checkout\n')
        with self.assertRaisesRegex(publish.PublishError, 'TARGET_CHANGED'):
            self.publisher().apply()
        self.assertEqual(target.read_bytes(), b'<?php // newer real checkout\n')
        self.assertEqual(self.runner.calls, [])
        self.assertFalse(self.backups.exists())

    def test_tampered_payload_blocks_before_mutation(self):
        (self.package / 'payload' / self.website).write_bytes(b'tampered')
        with self.assertRaisesRegex(publish.PublishError, 'PAYLOAD_HASH_MISMATCH'):
            self.publisher().apply()
        self.assert_original()
        self.assertFalse(self.backups.exists())

    def test_traversal_manifest_is_refused(self):
        self.manifest['../outside.php'] = self.manifest.pop(self.website)
        self.write_manifest()
        with self.assertRaisesRegex(publish.PublishError, 'INVALID_MANIFEST_PATHS'):
            self.publisher()
        self.assert_original()

    def test_existing_unrelated_new_service_is_not_overwritten(self):
        target = self.app / 'app/Services/Site/WebsiteMetaPurchase.php'
        target.write_bytes(b'<?php // unrelated existing service\n')
        with self.assertRaisesRegex(publish.PublishError, 'TARGET_CHANGED'):
            self.publisher().apply()
        self.assertIn(b'unrelated', target.read_bytes())
        self.assertEqual((self.app / self.website).read_bytes(), self.old)

    def test_symlink_payload_is_refused(self):
        source = self.package / 'payload' / self.website
        real = self.root / 'external.php'
        real.write_bytes(source.read_bytes())
        source.unlink()
        source.symlink_to(real)
        with self.assertRaisesRegex(publish.PublishError, 'SYMLINK_REFUSED'):
            self.publisher().apply()
        self.assert_original()

    def test_symlink_destination_parent_is_refused(self):
        target = self.app / 'app/Console'
        external = self.root / 'external'
        external.mkdir()
        target.symlink_to(external, target_is_directory=True)
        with self.assertRaisesRegex(publish.PublishError, 'SYMLINK_REFUSED'):
            self.publisher().apply()
        self.assertFalse(list(external.iterdir()))
        self.assert_original()

    def test_lint_failure_happens_before_backups_or_target_writes(self):
        self.runner.fail_lint = True
        with self.assertRaisesRegex(publish.PublishError, 'COMMAND_FAILED'):
            self.publisher().apply()
        self.assert_original()
        self.assertFalse(self.backups.exists())
        self.assertFalse(self.cron.exists())

    def test_wrong_dataset_halts_preflight(self):
        self.runner.ready['dataset_id'] = '951321707460227'
        with self.assertRaisesRegex(publish.PublishError, 'TRACKING_NOT_READY'):
            self.publisher().apply()
        self.assert_original()
        self.assertFalse(self.backups.exists())

    def test_test_event_code_configuration_halts_before_mutation(self):
        self.runner.ready['test_event_code_configured'] = True
        with self.assertRaisesRegex(publish.PublishError, 'TRACKING_NOT_READY'):
            self.publisher().apply()
        self.assert_original()
        self.assertFalse(self.backups.exists())
        self.assertFalse(self.cron.exists())

    def test_unexpected_cron_is_preserved(self):
        self.cron.parent.mkdir()
        self.cron.write_bytes(b'existing unexpected cron\n')
        with self.assertRaisesRegex(publish.PublishError, 'UNEXPECTED_CRON'):
            self.publisher().apply()
        self.assertEqual(self.cron.read_bytes(), b'existing unexpected cron\n')
        self.assert_original()

    def test_apply_is_incremental_and_idempotent_without_sending_events(self):
        result = self.publisher().apply()
        self.assertEqual(result['status'], 'APPLIED')
        for name, after in self.after.items():
            self.assertEqual((self.app / name).read_bytes(), after)
        self.assertEqual((self.app / self.website).stat().st_mode & 0o777, 0o640)
        self.assertEqual(self.cron.read_bytes(), publish.CRON_DATA)
        self.assertEqual(self.cron.stat().st_mode & 0o777, 0o644)
        self.assertEqual(self.log.stat().st_mode & 0o777, 0o600)
        backup = Path(result['backup'])
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual(self.publisher().apply()['status'], 'ALREADY_APPLIED')
        self.assertEqual(len(list(self.backups.iterdir())), 1)
        migrations = [c for c in self.runner.calls if 'migrate' in c]
        self.assertEqual(len(migrations), 1)
        self.assertIn('--path=' + publish.MIGRATION, migrations[0])
        for command in self.runner.calls:
            if 'meta:website-purchases' in command:
                self.assertIn('--check', command)

    def test_checkout_hook_is_installed_only_after_migration_and_readiness(self):
        runner = self.runner
        observed = []

        def inspect_order(args, **kwargs):
            if 'migrate' in args or '--check' in args:
                self.assertEqual((self.app / self.website).read_bytes(), self.old)
                for name in publish.PATHS - {self.website}:
                    self.assertEqual((self.app / name).read_bytes(), self.after[name])
                observed.append('migrate' if 'migrate' in args else 'readiness')
            return runner(args, **kwargs)

        publisher = self.publisher()
        publisher.runner = inspect_order
        publisher.apply()
        self.assertEqual(observed, ['migrate', 'readiness'])
        self.assertEqual((self.app / self.website).read_bytes(), self.after[self.website])

    def test_migration_failure_restores_only_code_and_keeps_private_backup(self):
        self.runner.fail_migrate = True
        with self.assertRaisesRegex(publish.PublishError, 'APPLY_FAILED_CODE_RESTORED'):
            self.publisher().apply()
        self.assert_original()
        self.assertFalse(self.cron.exists())
        self.assertEqual(len(list(self.backups.iterdir())), 1)

    def test_postinstall_readiness_failure_restores_code(self):
        # Config is ready, but the post-migration table check fails.
        self.runner.ready['table_present'] = False
        with self.assertRaisesRegex(publish.PublishError, 'APPLY_FAILED_CODE_RESTORED'):
            self.publisher().apply()
        self.assert_original()
        self.assertFalse(self.cron.exists())

    def test_rollback_restores_checkout_but_does_not_run_down_migration(self):
        result = self.publisher().apply()
        call_count = len(self.runner.calls)
        rolled = self.publisher().rollback(result['backup'])
        self.assertTrue(rolled['schema_retained'])
        self.assert_original()
        self.assertEqual((self.app / self.website).stat().st_mode & 0o777, 0o640)
        self.assertFalse(self.cron.exists())
        self.assertEqual(len(self.runner.calls), call_count)
        self.assertTrue(self.log.exists())

    def test_rollback_refuses_to_clobber_newer_edits_before_any_restore(self):
        result = self.publisher().apply()
        target = self.app / self.website
        target.write_bytes(b'<?php // newer approved production edit\n')
        with self.assertRaisesRegex(publish.PublishError, 'ROLLBACK_TARGET_CHANGED'):
            self.publisher().rollback(result['backup'])
        self.assertIn(b'newer approved', target.read_bytes())
        self.assertEqual(self.cron.read_bytes(), publish.CRON_DATA)
        self.assertTrue((self.app / 'app/Services/Site/WebsiteMetaPurchase.php').exists())

    def test_rollback_refuses_corrupt_backup(self):
        result = self.publisher().apply()
        backup = Path(result['backup'])
        (backup / 'files' / self.website).write_bytes(b'corrupt')
        with self.assertRaisesRegex(publish.PublishError, 'BACKUP_HASH_MISMATCH'):
            self.publisher().rollback(backup)
        self.assertEqual((self.app / self.website).read_bytes(), self.after[self.website])
        self.assertEqual(self.cron.read_bytes(), publish.CRON_DATA)


if __name__ == '__main__':
    unittest.main()
