#!/usr/bin/env python3
import os
import pathlib
import stat
import tempfile
import unittest
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deployment"))

from allow_whatsapp_account import ACCOUNT_ID, SafeError, atomic_replace, proposal, read_env, update

TEST_ID = "1636131124838697"


class ParsingTests(unittest.TestCase):
    def test_preserves_unrelated_bytes_crlf_comments_and_secrets(self):
        before = '# مثال\r\nOTHER_SECRET="secret#literal"\r\nWHATSAPP_ALLOWED_ACCOUNT_IDS="' + TEST_ID + '"\r\nLAST=value'
        after, ids = proposal(before)
        self.assertEqual(after, before.replace('"' + TEST_ID + '"', '"' + TEST_ID + ',' + ACCOUNT_ID + '"'))
        self.assertEqual(ids, [TEST_ID, ACCOUNT_ID])

    def test_export_single_quotes_and_inline_comment(self):
        before = "export WHATSAPP_ALLOWED_ACCOUNT_IDS = '11, 22,11' # private comment\nAPP_SECRET=untouched\n"
        after, ids = proposal(before)
        self.assertEqual(ids, ["11", "22", ACCOUNT_ID])
        self.assertTrue(after.endswith("APP_SECRET=untouched\n"))

    def test_unquoted_ids_comment_and_missing_key(self):
        after, ids = proposal("WHATSAPP_ALLOWED_ACCOUNT_IDS=11,22 # comment\n")
        self.assertEqual(ids, ["11", "22", ACCOUNT_ID])
        self.assertEqual(proposal("OTHER=ok")[0], 'OTHER=ok\nWHATSAPP_ALLOWED_ACCOUNT_IDS="' + ACCOUNT_ID + '"\n')
        self.assertEqual(proposal("")[1], [ACCOUNT_ID])

    def test_idempotent_existing_value_is_byte_unchanged(self):
        before = 'export WHATSAPP_ALLOWED_ACCOUNT_IDS = "' + TEST_ID + ',' + ACCOUNT_ID + '" # Keep comment\n'
        self.assertEqual(proposal(before)[0], before)
        self.assertEqual(proposal(proposal('WHATSAPP_ALLOWED_ACCOUNT_IDS="11"\n')[0])[0], proposal('WHATSAPP_ALLOWED_ACCOUNT_IDS="11"\n')[0])

    def test_comment_and_key_inside_multiline_other_value_not_active(self):
        before = '# WHATSAPP_ALLOWED_ACCOUNT_IDS="comment"\nOTHER="line one\nWHATSAPP_ALLOWED_ACCOUNT_IDS=999\nline three"\nWHATSAPP_ALLOWED_ACCOUNT_IDS="11"\n'
        after, ids = proposal(before)
        self.assertEqual(ids, ["11", ACCOUNT_ID])
        self.assertIn("WHATSAPP_ALLOWED_ACCOUNT_IDS=999\n", after)

    def test_duplicate_active_definitions_rejected_without_values_in_error(self):
        with self.assertRaisesRegex(SafeError, "^DUPLICATE_ALLOWLIST_SETTING$"):
            proposal('WHATSAPP_ALLOWED_ACCOUNT_IDS="11"\nexport WHATSAPP_ALLOWED_ACCOUNT_IDS=22\n')

    def test_ambiguous_values_invalid_ids_multiline_and_bom_rejected(self):
        for rhs in ['"11,secret-value"', '${OTHER}', '11;22', '"11\n22"', '"11" trailing', '"11']:
            with self.subTest(rhs=rhs), self.assertRaises(SafeError):
                proposal("WHATSAPP_ALLOWED_ACCOUNT_IDS=" + rhs + "\n")
        with self.assertRaises(SafeError):
            proposal('\ufeffWHATSAPP_ALLOWED_ACCOUNT_IDS="11"\n')


class TransactionTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temporary.name) / "public_html"
        (self.root / "deployment").mkdir(parents=True)
        (self.root / "deployment/whatsapp_webhook_setup.php").write_text("fixture")
        self.path = self.root / ".env"
        self.before = ('APP_SECRET="untouched-fixture"\nWHATSAPP_ALLOWED_ACCOUNT_IDS="' + TEST_ID + '"\n').encode()
        self.path.write_bytes(self.before)
        self.path.chmod(0o640)
        self.uid = os.geteuid()
        self.metadata = self.path.stat()

    def tearDown(self):
        self.temporary.cleanup()

    def test_success_backup_metadata_effective_check_smoke_and_idempotency(self):
        calls = []
        def run(root, arguments):
            calls.append(arguments)
            return True
        status, backup = update(self.root, self.uid, run)
        self.assertEqual(status, "ALLOWLIST_READY")
        self.assertEqual((backup / "env.before").read_bytes(), self.before)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((backup / "env.before").stat().st_mode), 0o600)
        self.assertEqual((self.path.stat().st_uid, self.path.stat().st_gid, stat.S_IMODE(self.path.stat().st_mode)),
                         (self.metadata.st_uid, self.metadata.st_gid, 0o640))
        self.assertEqual(calls[0], ["php", "artisan", "config:clear"])
        self.assertEqual(calls[1][-1], TEST_ID + "," + ACCOUNT_ID)
        self.assertEqual(calls[2][-1], "smoke")
        current = self.path.read_bytes()
        update(self.root, self.uid, run)
        self.assertEqual(self.path.read_bytes(), current)
        self.assertEqual(list(self.root.glob(".env-whatsapp-*")), [])

    def test_clear_effective_check_and_smoke_failures_restore_original(self):
        for fail_at in (1, 2, 3):
            with self.subTest(fail_at=fail_at):
                self.path.write_bytes(self.before)
                count = 0
                def run(root, arguments):
                    nonlocal count
                    count += 1
                    return count != fail_at
                with self.assertRaisesRegex(SafeError, "^ORIGINAL_ENV_RESTORED;"):
                    update(self.root, self.uid, run)
                self.assertEqual(self.path.read_bytes(), self.before)
                self.assertEqual(stat.S_IMODE(self.path.stat().st_mode), 0o640)

    def test_concurrent_edit_is_never_overwritten_during_rollback(self):
        concurrent = b"OTHER=concurrent-data\n"
        def run(root, arguments):
            if arguments[-1] == "smoke":
                self.path.write_bytes(concurrent)
                return False
            return True
        with self.assertRaisesRegex(SafeError, "^ROLLBACK_STOPPED_ENV_CHANGED_OR_WRITE_FAILED;"):
            update(self.root, self.uid, run)
        self.assertEqual(self.path.read_bytes(), concurrent)

    def test_concurrent_edit_after_verification_does_not_report_success(self):
        def run(root, arguments):
            if arguments[-1] == "smoke":
                self.path.write_bytes(b"CHANGED_AFTER_CHECK=1\n")
            return True
        with self.assertRaisesRegex(SafeError, "^ROLLBACK_STOPPED_ENV_CHANGED_OR_WRITE_FAILED;"):
            update(self.root, self.uid, run)

    def test_atomic_snapshot_guard_and_symlink_rejection(self):
        original, metadata = read_env(self.path, self.uid)
        self.path.write_bytes(b"concurrent\n")
        with self.assertRaisesRegex(SafeError, "^ENV_CHANGED_CONCURRENTLY$"):
            atomic_replace(self.path, b"new\n", original, metadata, self.uid)
        self.assertEqual(self.path.read_bytes(), b"concurrent\n")
        self.path.unlink()
        self.path.symlink_to(self.root / "deployment/whatsapp_webhook_setup.php")
        with self.assertRaises(OSError):
            read_env(self.path, self.uid)
        self.assertEqual(list(self.root.glob(".env-whatsapp-*")), [])

    def test_concurrent_permissions_change_is_not_overwritten(self):
        def run(root, arguments):
            if arguments[-1] == "smoke":
                self.path.chmod(0o600)
            return True
        with self.assertRaisesRegex(SafeError, "^ROLLBACK_STOPPED_ENV_CHANGED_OR_WRITE_FAILED;"):
            update(self.root, self.uid, run)
        self.assertEqual(stat.S_IMODE(self.path.stat().st_mode), 0o600)


if __name__ == "__main__":
    unittest.main()
