import importlib.util
import json
from pathlib import Path
import os
import re
import stat
import tempfile
import unittest
from unittest.mock import patch
from types import SimpleNamespace


PACKAGE = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('paymob_checkout_publish', PACKAGE / 'publish.py')
fix = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(fix)
TEMPLATE = (PACKAGE / 'checking_out.php.template').read_bytes()
CONTROLLER = b'''<?php
class Example {
    public function checkingOut($payment_method, $integration_id, $order_id, $iframe_id_or_wallet_number)
    {
        $json['token'] = 'Token synthetic_secret_only';
        $link = 'https://accept.paymob.com/unifiedcheckout/?publicKey=synthetic_public_only&clientSecret=';
    }
    // public function checkingOut($payment_method, $integration_id, $order_id, $iframe_id_or_wallet_number)
    public function callback() { return 'signed-callback-unchanged'; }
}
'''
WALLET = b'''<?php
class WalletController {
    public function charging_wallet($request) {
        if($user->email==null ){
            return $this->successResponse($user->balance,__('api.please update your info and enter email first'));
        }
        $wallet=Wallet::create([]);
    }
    public function transfer_wallet() { return 'unchanged'; }
}
'''


class PublisherTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name) / 'app'
        self.root.mkdir()
        self.backup = Path(self.temporary.name) / 'backup'
        self.originals = {fix.CONTROLLER: CONTROLLER, fix.WALLET: WALLET}
        self.specs = {}
        for name, data in self.originals.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
            path.chmod(0o640)
            desired = fix.controller_target(data, TEMPLATE) if name == fix.CONTROLLER else fix.wallet_target(data)
            self.specs[name] = {'before': fix.digest(data), 'after': fix.digest(desired),
                'attributes': [os.geteuid(), os.getegid(), 0o640]}

    def tearDown(self):
        self.temporary.cleanup()

    def plan(self):
        return fix.plan(self.root, self.specs, TEMPLATE)

    def test_scope_and_callback_are_unchanged(self):
        target = fix.controller_target(CONTROLLER, TEMPLATE)
        before_start, before_end = fix.method_span(CONTROLLER.decode())
        after_start, after_end = fix.method_span(target.decode())
        self.assertEqual(CONTROLLER.decode()[:before_start], target.decode()[:after_start])
        self.assertEqual(CONTROLLER.decode()[before_end:], target.decode()[after_end:])
        self.assertIn(b"'Token synthetic_secret_only'", target)
        self.assertIn(b"'synthetic_public_only'", target)
        self.assertNotIn(b'__PAYMOB_', target)
        self.assertEqual(1, target.count(b"->post('https://accept.paymob.com/v1/intention/'"))
        self.assertNotIn(b'/api/auth/tokens', target)
        self.assertNotIn(b'/api/ecommerce/orders', target)
        self.assertNotIn(b'->retry(', target)

    def test_wallet_rejects_email_before_records_and_preserves_transfers(self):
        target = fix.wallet_target(WALLET)
        self.assertIn(b'FILTER_VALIDATE_EMAIL', target)
        self.assertLess(target.index(b'errorResponse'), target.index(b'$wallet=Wallet::create('))
        self.assertIn(b"public function transfer_wallet() { return 'unchanged'; }", target)
        self.assertIn(b", 422);", target)

    def test_exact_publish_backup_metadata_permissions_and_repeat(self):
        changes = self.plan()
        fix.publish(self.root, changes, self.backup)
        for name, original in self.originals.items():
            self.assertEqual(self.specs[name]['after'], fix.digest((self.root / name).read_bytes()))
            self.assertEqual(original, (self.backup / name).read_bytes())
            self.assertEqual(0o640, stat.S_IMODE((self.root / name).stat().st_mode))
            self.assertEqual(0o600, stat.S_IMODE((self.backup / name).stat().st_mode))
        self.assertEqual(0o700, stat.S_IMODE(self.backup.stat().st_mode))
        self.assertEqual(0o600, stat.S_IMODE((self.backup / 'metadata.json').stat().st_mode))
        self.assertNotIn('synthetic_secret', (self.backup / 'metadata.json').read_text())
        self.assertEqual([], self.plan())

    def test_foreign_baseline_rejected_before_any_publication(self):
        path = self.root / fix.CONTROLLER
        path.write_bytes(CONTROLLER + b'// concurrent modification\n')
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_MISMATCH'):
            self.plan()
        self.assertFalse(self.backup.exists())
        self.assertEqual(WALLET, (self.root / fix.WALLET).read_bytes())

    def test_unexpected_target_hash_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'TARGET_HASH_MISMATCH'):
            fix.plan(self.root, self.specs, TEMPLATE + b'changed')

    def test_reviewed_live_digests_and_distinct_attributes_are_pinned(self):
        self.assertEqual({
            fix.CONTROLLER: {
                'before': 'c679ab86629ccd103f2cbe8e96c96c274fba6ff84d726d912cf49ed8c4ec4a48',
                'after': 'de966706defacd95d07ffe34938f65f6f69819dab7a73bc1edbf021792b4d634',
                'attributes': [1001, 1003, 0o755],
            },
            fix.WALLET: {
                'before': 'c8f7dec64024046eb1d59c480f8c731ab00c64d53147c8628abeb919251203aa',
                'after': 'a0b48a59a5ed92f31da049fd2962aa32148aa309007ecf05935509689ff4c023',
                'attributes': [0, 0, 0o644],
            },
        }, fix.SPECS)
        self.assertEqual('52aca681dfad03927021f168b8878391fc27f4679957b5360c2c68b4c55492ac', fix.digest(TEMPLATE))

    def test_mode_owner_and_group_drift_are_rejected_even_if_content_matches(self):
        real_source = fix.source
        for field in ('st_uid', 'st_gid', 'st_mode'):
            def changed_source(root, name):
                path, info = real_source(root, name)
                values = {'st_uid': info.st_uid, 'st_gid': info.st_gid, 'st_mode': info.st_mode}
                values[field] += 1
                return path, SimpleNamespace(**values)
            with patch.object(fix, 'source', side_effect=changed_source):
                with self.assertRaisesRegex(RuntimeError, 'SOURCE_ATTRIBUTES_MISMATCH'):
                    self.plan()
        (self.root / fix.WALLET).chmod(0o600)
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_ATTRIBUTES_MISMATCH'):
            self.plan()
        self.assertFalse(self.backup.exists())

    def test_mixed_live_attributes_are_preserved_in_publication_and_rollback(self):
        specs = {name: {**entry, 'attributes': fix.SPECS[name]['attributes']} for name, entry in self.specs.items()}
        real_source = fix.source
        writes = []

        def mixed_source(root, name):
            path, info = real_source(root, name)
            if root == self.root:
                uid, gid, mode = specs[name]['attributes']
                info = SimpleNamespace(st_uid=uid, st_gid=gid, st_mode=stat.S_IFREG | mode)
            return path, info

        def capture_replace(path, content, attributes):
            writes.append((path.relative_to(self.root).as_posix(), attributes))
            path.write_bytes(content)

        with patch.object(fix, 'source', side_effect=mixed_source), patch.object(fix, 'replace', side_effect=capture_replace):
            changes = fix.plan(self.root, specs, TEMPLATE)
            self.assertEqual([(1001, 1003, 0o755), (0, 0, 0o644)], [change[3] for change in changes])
            fix.publish(self.root, changes, self.backup)
            metadata = json.loads((self.backup / 'metadata.json').read_text())
            self.assertEqual([0, 0, 0o644], metadata[fix.WALLET]['attributes'])
            with patch.object(fix, 'SPECS', specs):
                rollback = fix.rollback_plan(self.root, self.backup)
                fix.publish(self.root, rollback, self.backup.with_name('mixed-rollback'))
        expected = [(fix.CONTROLLER, (1001, 1003, 0o755)), (fix.WALLET, (0, 0, 0o644))]
        self.assertEqual(expected, writes[:2])
        self.assertCountEqual(expected, writes[2:])
        for name, original in self.originals.items():
            self.assertEqual(original, (self.root / name).read_bytes())

    def test_fasakha_account_identity_is_exactly_the_observed_uid(self):
        with patch.object(fix.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=1001)):
            fix.require_account_identity()
        with patch.object(fix.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=0)):
            with self.assertRaisesRegex(RuntimeError, 'ACCOUNT_IDENTITY_MISMATCH'):
                fix.require_account_identity()
        with patch.object(fix.pwd, 'getpwnam', side_effect=KeyError('missing')):
            with self.assertRaisesRegex(RuntimeError, 'ACCOUNT_IDENTITY_MISMATCH'):
                fix.require_account_identity()

    def test_both_pinned_runners_match_every_actual_payload_digest(self):
        diagnostic = PACKAGE.parent / 'paymob_checkout_diagnostics_20261112'
        payloads = {
            'publish.py': PACKAGE / 'publish.py',
            'checking_out.php.template': PACKAGE / 'checking_out.php.template',
            'diagnostics.py': diagnostic / 'diagnostics.py',
        }
        for runner, variable, expected_names in [
            (PACKAGE / 'run_root.sh', 'checkout_run', {'publish.py', 'checking_out.php.template'}),
            (diagnostic / 'run_root.sh', 'diagnostic_run', set(payloads)),
        ]:
            entries = re.findall(r'([a-f0-9]{64}) "\$' + variable + r'/([^"\n]+)"', runner.read_text())
            self.assertEqual(expected_names, {name for _, name in entries})
            self.assertEqual(len(expected_names), len(entries))
            for expected, name in entries:
                self.assertEqual(expected, fix.digest(payloads[name].read_bytes()), name)
    def test_symlink_and_hardlink_rejected(self):
        path = self.root / fix.CONTROLLER
        other = path.with_suffix('.other')
        path.rename(other)
        path.symlink_to(other)
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_TYPE_INVALID'):
            self.plan()
        path.unlink()
        os.link(other, path)
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_TYPE_INVALID'):
            self.plan()

    def test_changed_source_after_plan_cannot_be_overwritten(self):
        changes = self.plan()
        path = self.root / fix.CONTROLLER
        concurrent = CONTROLLER + b'// kept\n'
        path.write_bytes(concurrent)
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED'):
            fix.publish(self.root, changes, self.backup)
        self.assertEqual(concurrent, path.read_bytes())

    def test_write_failure_rolls_back_only_owned_changes(self):
        changes = self.plan()
        real_replace = fix.replace
        calls = []

        def fail_second(path, content, attrs):
            calls.append(path)
            if len(calls) == 2:
                raise OSError('synthetic failure')
            real_replace(path, content, attrs)

        with patch.object(fix, 'replace', side_effect=fail_second):
            with self.assertRaises(OSError):
                fix.publish(self.root, changes, self.backup)
        for name, original in self.originals.items():
            self.assertEqual(original, (self.root / name).read_bytes())

    def test_php_diagnostics_are_not_exposed(self):
        class Result:
            returncode = 255
            stderr = b'synthetic secret must not appear'
        with patch.object(fix.subprocess, 'run', return_value=Result()):
            with self.assertRaisesRegex(RuntimeError, '^PHP_LINT_FAILED$'):
                fix.lint_php(self.plan(), 'php')

    def test_missing_shared_money_utility_is_unneeded_and_not_created(self):
        path = self.root / 'app/Services/GoServices/Money.php'
        self.assertFalse(path.exists())
        fix.publish(self.root, self.plan(), self.backup)
        self.assertFalse(path.exists())
        self.assertNotIn(b'GoServices', TEMPLATE)

    def test_foreign_shared_money_utility_is_neither_read_nor_changed(self):
        path = self.root / 'app/Services/GoServices/Money.php'
        path.parent.mkdir(parents=True)
        foreign = b'<?php // unrelated runtime utility must stay unchanged\n'
        path.write_bytes(foreign)
        read_bytes = Path.read_bytes

        def guarded_read(source):
            self.assertNotEqual(path, source, 'publisher must not read the shared utility')
            return read_bytes(source)

        with patch.object(Path, 'read_bytes', guarded_read):
            self.assertEqual({fix.CONTROLLER, fix.WALLET}, {change[0] for change in self.plan()})
            fix.publish(self.root, self.plan(), self.backup)
        self.assertEqual(foreign, path.read_bytes())
        self.assertFalse((self.backup / 'app/Services').exists())

    def test_explicit_rollback_restores_exact_sources_and_can_repeat(self):
        fix.publish(self.root, self.plan(), self.backup)
        with patch.object(fix, 'SPECS', self.specs):
            changes = fix.rollback_plan(self.root, self.backup)
            fix.publish(self.root, changes, self.backup.with_name('rollback-copy'))
            self.assertEqual([], fix.rollback_plan(self.root, self.backup))
        for name, original in self.originals.items():
            self.assertEqual(original, (self.root / name).read_bytes())

    def test_explicit_rollback_refuses_later_edits_and_corrupt_backup(self):
        fix.publish(self.root, self.plan(), self.backup)
        path = self.root / fix.CONTROLLER
        published = path.read_bytes()
        path.write_bytes(published + b'// concurrent edit\n')
        with patch.object(fix, 'SPECS', self.specs):
            with self.assertRaisesRegex(RuntimeError, 'ROLLBACK_SOURCE_CHANGED'):
                fix.rollback_plan(self.root, self.backup)
            path.write_bytes(published)
            (self.backup / fix.CONTROLLER).write_bytes(b'corrupt source')
            with self.assertRaisesRegex(RuntimeError, 'BACKUP_SOURCE_INVALID'):
                fix.rollback_plan(self.root, self.backup)

    def test_rollback_refuses_attribute_drift_and_forged_backup_attributes(self):
        fix.publish(self.root, self.plan(), self.backup)
        (self.root / fix.WALLET).chmod(0o600)
        with patch.object(fix, 'SPECS', self.specs):
            with self.assertRaisesRegex(RuntimeError, 'BACKUP_SOURCE_INVALID'):
                fix.rollback_plan(self.root, self.backup)
            (self.root / fix.WALLET).chmod(0o640)
            audit = self.backup / 'metadata.json'
            metadata = json.loads(audit.read_text())
            metadata[fix.WALLET]['attributes'][0] += 1
            audit.write_text(json.dumps(metadata))
            with self.assertRaisesRegex(RuntimeError, 'BACKUP_METADATA_INVALID'):
                fix.rollback_plan(self.root, self.backup)

    def test_automatic_rollback_preserves_a_concurrent_edit(self):
        changes = self.plan()
        real_replace = fix.replace
        calls = []
        concurrent = b'<?php // concurrent edit must survive\n'

        def fail_second(path, content, attrs):
            calls.append(path)
            if len(calls) == 2:
                (self.root / fix.CONTROLLER).write_bytes(concurrent)
                raise OSError('synthetic failure')
            real_replace(path, content, attrs)

        with patch.object(fix, 'replace', side_effect=fail_second):
            with self.assertRaises(OSError):
                fix.publish(self.root, changes, self.backup)
        self.assertEqual(concurrent, (self.root / fix.CONTROLLER).read_bytes())
        self.assertEqual(WALLET, (self.root / fix.WALLET).read_bytes())


if __name__ == '__main__':
    unittest.main()
