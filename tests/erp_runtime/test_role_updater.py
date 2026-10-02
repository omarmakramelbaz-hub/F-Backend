#!/usr/bin/env python3
"""No-network, no-PHP, non-root integration tests for the five-file updater.

Only the two commit literals are substituted in a disposable script copy. Every
production content hash remains pinned and is exercised against the real blobs.
Requires both reviewed commits locally (CI checkout uses fetch-depth: 0).
"""
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]
BASE = "20ea8dde5ab8c934a4e0b87b92841f9ee054c340"
TARGET = "02bcc205fb86402b2f10510bb526a33c7ae1b3e7"
FILES = [
    "app/Http/Controllers/Erp/AuthController.php",
    "app/Services/Erp/Actor.php",
    "resources/views/erp/account-form.blade.php",
    "resources/views/erp/accounts.blade.php",
    "resources/views/erp/layout.blade.php",
]


def command(*args, cwd=None, env=None):
    return subprocess.check_output(args, cwd=cwd, env=env, stderr=subprocess.STDOUT)


def fingerprint(path):
    s = path.stat()
    return (path.read_bytes(), s.st_uid, s.st_gid, stat.S_IMODE(s.st_mode), s.st_mtime_ns)


class RoleUpdaterTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() == 0:
            raise unittest.SkipTest("Run fixtures as an ordinary non-root user; production root branch is statically checked.")
        cls.blobs = {
            revision: {
                path: command("git", "show", f"{revision}:{path}", cwd=REPO)
                for path in FILES
            }
            for revision in (BASE, TARGET)
        }

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="erp-role-fixture-")
        self.addCleanup(self.directory.cleanup)
        self.work = Path(self.directory.name)
        self.root = self.work / "public_html"
        self.root.mkdir()
        for folder in ("vendor", "storage/framework/views", "bootstrap/cache"):
            (self.root / folder).mkdir(parents=True)
        (self.root / ".gitignore").write_text(".env\nvendor/\nstorage/framework/down\nstorage/framework/views/\n")
        (self.root / ".env").write_text("APP_KEY=fixture-private-value\nERP_ENABLED=true\n")
        (self.root / ".env").chmod(0o640)
        (self.root / "vendor/autoload.php").write_text("<?php // fixture\n")
        (self.root / "artisan").write_text("<?php // fixture\n")
        (self.root / "legacy.txt").write_text("Unrelated tracked legacy code.\n")
        self.git("init", "-q")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("config", "user.name", "Local fixture")
        for path, content in self.blobs[BASE].items():
            f = self.root / path
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_bytes(content)
        self.git("add", ".")
        self.git("commit", "-qm", "Fixture baseline")
        self.base = self.git("rev-parse", "HEAD").strip().decode()
        for path, content in self.blobs[TARGET].items():
            (self.root / path).write_bytes(content)
        self.git("add", ".")
        self.git("commit", "-qm", "Fixture reviewed target")
        self.target = self.git("rev-parse", "HEAD").strip().decode()
        self.git("checkout", "--detach", "-q", self.base)
        # Metadata preservation includes non-default modes and subsecond mtimes.
        for i, path in enumerate(FILES):
            f = self.root / path
            f.chmod(0o640 if i % 2 else 0o644)
            os.utime(f, ns=(1_600_000_000_123456789 + i, 1_600_000_000_123456789 + i))
        self.untracked = self.root / "private-backup.sql"
        self.untracked.write_text("Unrelated sensitive legacy data; do not touch.\n")
        self.untracked.chmod(0o600)
        (self.root / "old-untracked").mkdir()
        (self.root / "old-untracked/report.txt").write_text("preserve me")
        self.before = {p: fingerprint(self.root / p) for p in FILES}
        self.env_before = fingerprint(self.root / ".env")
        self.untracked_before = fingerprint(self.untracked)
        self.index_before = fingerprint(self.root / ".git/index")
        self.head_before = fingerprint(self.root / ".git/HEAD")
        script = (REPO / "deployment/erp_update_roles.sh").read_text()
        self.assertEqual(script.count(BASE), 1)
        self.assertEqual(script.count(TARGET), 1)
        self.script = self.work / "erp_update_roles.sh"
        self.script.write_text(script.replace(BASE, self.base).replace(TARGET, self.target))
        self.bin = self.work / "bin"
        self.bin.mkdir()
        self.calls = self.work / "artisan.calls"
        self.stub("php", r'''#!/usr/bin/env python3
import os, pathlib, sys
args = sys.argv[1:]
work = pathlib.Path(os.environ["FIXTURE_WORK"])
with (work / "artisan.calls").open("a") as out:
    out.write(str(os.geteuid()) + " " + " ".join(args) + "\n")
if args[0] == "-l":
    sys.exit(41 if os.environ.get("FAIL_LINT") else 0)
assert args[0] == "artisan", args
assert args[1] in ("down", "up", "view:clear"), args
marker = pathlib.Path("storage/framework/down")
if args[1] == "down":
    marker.write_text('{"time": 1, "retry": 60}')
    if os.environ.get("EDIT_UNRELATED_ON_DOWN"):
        pathlib.Path("legacy.txt").write_text("concurrent operator edit")
    if os.environ.get("CHMOD_TARGET_ON_DOWN"):
        pathlib.Path("app/Http/Controllers/Erp/AuthController.php").chmod(0o600)
    sys.exit(42 if os.environ.get("FAIL_DOWN_AFTER_MARKER") else 0)
if args[1] == "up":
    marker.unlink(missing_ok=True)
    if os.environ.get("FAIL_UP_AFTER_REMOVE") and not (work / "failed-up").exists():
        (work / "failed-up").touch()
        sys.exit(43)
if args[1] == "view:clear":
    if os.environ.get("EDIT_TARGET_ON_CACHE"):
        pathlib.Path("app/Http/Controllers/Erp/AuthController.php").write_text("concurrent target edit")
    if os.environ.get("FAIL_CACHE_ALWAYS"):
        sys.exit(44)
    if os.environ.get("FAIL_CACHE_ONCE") and not (work / "failed-cache").exists():
        (work / "failed-cache").touch()
        sys.exit(45)
sys.exit(0)
''')
        real_cp = shutil.which("cp")
        self.stub("cp", f'''#!/usr/bin/env python3
import os, pathlib, sys
args = sys.argv[1:]
work = pathlib.Path(os.environ["FIXTURE_WORK"])
source, destination = args[-2:]
live_write = "/public_html/" in destination and ".erp-role-update-" in destination
if live_write and os.environ.get("FAIL_COPY_ONCE") and "/target/1" in source and not (work / "failed-copy").exists():
    (work / "failed-copy").touch()
    sys.exit(46)
if live_write and "/original/" in source and not pathlib.Path("storage/framework/down").is_file():
    (work / "unsafe-live-restore").touch()
    sys.exit(49)
if live_write and os.environ.get("FAIL_RESTORE") and "/original/" in source:
    sys.exit(47)
os.execv({real_cp!r}, [{real_cp!r}] + args)
''')
        self.environment = dict(os.environ, PATH=f"{self.bin}:{os.environ['PATH']}", FIXTURE_WORK=str(self.work))

    def git(self, *args):
        return command("git", *args, cwd=self.root)

    def stub(self, name, text):
        p = self.bin / name
        p.write_text(text)
        p.chmod(0o755)

    def run_script(self, action="apply", receipt=None, root=None, **failures):
        args = ["bash", str(self.script), action, str(root or self.root)]
        if receipt is not None:
            args.append(str(receipt))
        result = subprocess.run(args, cwd=self.work, env=dict(self.environment, **failures), text=True,
                                stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        self.last_output = result.stdout
        return result

    def receipt(self):
        runs = list((self.work / "erp-role-backups").glob("roles-*"))
        self.assertEqual(len(runs), 1, self.last_output)
        return runs[0]

    def assert_untouched(self):
        self.assertEqual(fingerprint(self.root / ".env"), self.env_before)
        self.assertEqual(fingerprint(self.untracked), self.untracked_before)
        self.assertEqual((self.root / "old-untracked/report.txt").read_text(), "preserve me")
        self.assertEqual(fingerprint(self.root / ".git/index"), self.index_before)
        self.assertEqual(fingerprint(self.root / ".git/HEAD"), self.head_before)
        self.assertEqual((self.root / "legacy.txt").read_text(), "Unrelated tracked legacy code.\n")
        self.assertEqual(list(self.root.rglob(".erp-role-update-*")), [])
        self.assertFalse((self.work / "unsafe-live-restore").exists())

    def assert_original(self):
        for path in FILES:
            self.assertEqual(fingerprint(self.root / path), self.before[path], path)

    def assert_rejected(self, **args):
        result = self.run_script(**args)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertFalse(self.calls.exists(), result.stdout)
        return result

    def test_success_and_explicit_rollback_preserve_everything_else(self):
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stdout)
        for path in FILES:
            self.assertEqual((self.root / path).read_bytes(), self.blobs[TARGET][path])
            self.assertEqual(fingerprint(self.root / path)[1:4], self.before[path][1:4])
        self.assert_untouched()
        receipt = self.receipt()
        self.assertEqual((receipt / "state").read_text(), "applied\n")
        for directory in (receipt.parent, receipt, receipt / "original", receipt / "target"):
            self.assertEqual(stat.S_IMODE(directory.stat().st_mode), 0o700)
            self.assertEqual(directory.stat().st_uid, os.geteuid())
        for i, path in enumerate(FILES):
            self.assertEqual(fingerprint(receipt / f"original/{i}"), self.before[path])
        lines = self.calls.read_text().splitlines()
        self.assertEqual(sum(" -l " in line for line in lines), 5)
        self.assertTrue(all(line.startswith(str(os.geteuid()) + " ") for line in lines))
        self.assertEqual([line.split()[2] for line in lines if " artisan " in line], ["down", "view:clear", "up"])
        self.assertFalse((self.root / "storage/framework/down").exists())
        result = self.run_script("rollback", receipt)
        self.assertEqual(result.returncode, 0, result.stdout)
        self.assertEqual((receipt / "state").read_text(), "rolled-back\n")
        self.assert_original()
        self.assert_untouched()
        result = self.run_script("rollback", receipt)
        self.assertNotEqual(result.returncode, 0, "Repeated rollback must refuse stale receipt")

    def test_one_copy_failure_restores_code_and_serving_state(self):
        result = self.run_script(FAIL_COPY_ONCE="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("ORIGINAL_FIVE_FILES", result.stdout)
        self.assert_original()
        self.assert_untouched()
        self.assertFalse((self.root / "storage/framework/down").exists())

    def test_one_cache_failure_restores_code_and_serving_state(self):
        result = self.run_script(FAIL_CACHE_ONCE="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assert_original()
        self.assert_untouched()
        self.assertFalse((self.root / "storage/framework/down").exists())

    def test_persistent_cache_failure_keeps_maintenance(self):
        result = self.run_script(FAIL_CACHE_ALWAYS="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("MANUAL_INTERVENTION_REQUIRED", result.stdout)
        self.assertTrue((self.root / "storage/framework/down").is_file())
        self.assertEqual((self.receipt() / "state").read_text(), "manual-intervention\n")
        self.assert_original()
        self.assert_untouched()

    def test_restore_failure_keeps_maintenance(self):
        result = self.run_script(FAIL_COPY_ONCE="1", FAIL_RESTORE="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("MANUAL_INTERVENTION_REQUIRED", result.stdout)
        self.assertTrue((self.root / "storage/framework/down").is_file())
        self.assert_untouched()

    def test_down_failure_after_marker_removes_only_our_maintenance(self):
        result = self.run_script(FAIL_DOWN_AFTER_MARKER="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assert_original()
        self.assert_untouched()
        self.assertFalse((self.root / "storage/framework/down").exists())

    def test_up_failure_after_removal_restores_original_code(self):
        result = self.run_script(FAIL_UP_AFTER_REMOVE="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assert_original()
        self.assert_untouched()
        self.assertFalse((self.root / "storage/framework/down").exists())

    def test_lint_failure_never_enters_maintenance(self):
        result = self.run_script(FAIL_LINT="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertNotIn(" artisan ", self.calls.read_text())
        self.assert_original()
        self.assert_untouched()

    def test_wrong_head_is_rejected_before_writes(self):
        self.git("checkout", "--detach", "-q", self.target)
        self.assert_rejected()
        self.assertFalse((self.work / "erp-role-backups").exists())

    def test_dirty_target_is_rejected_before_writes(self):
        (self.root / FILES[0]).write_text("<?php // operator edit\n")
        self.assert_rejected()
        self.assertFalse((self.work / "erp-role-backups").exists())

    def test_dirty_unrelated_file_is_rejected(self):
        (self.root / "legacy.txt").write_text("operator edit")
        self.assert_rejected()

    def test_hidden_index_flag_is_rejected(self):
        self.git("update-index", "--assume-unchanged", "legacy.txt")
        self.assert_rejected()

    def test_target_symlink_is_rejected(self):
        p = self.root / FILES[0]
        saved = self.work / "saved-controller"
        p.rename(saved)
        p.symlink_to(saved)
        self.assert_rejected()

    def test_parent_symlink_is_rejected(self):
        p = self.root / "app/Services/Erp"
        saved = self.work / "saved-service-directory"
        p.rename(saved)
        p.symlink_to(saved, target_is_directory=True)
        self.assert_rejected()

    def test_root_symlink_is_rejected(self):
        p = self.work / "linked-root"
        p.symlink_to(self.root, target_is_directory=True)
        self.assert_rejected(root=p)

    def test_environment_symlink_is_rejected(self):
        p = self.root / ".env"
        saved = self.work / "saved-environment"
        p.rename(saved)
        p.symlink_to(saved)
        self.assert_rejected()

    def test_existing_maintenance_is_preserved(self):
        marker = self.root / "storage/framework/down"
        marker.write_text("preexisting maintenance")
        self.assert_rejected()
        self.assertEqual(marker.read_text(), "preexisting maintenance")

    def test_backup_symlink_is_rejected(self):
        p = self.work / "erp-role-backups"
        elsewhere = self.work / "elsewhere"
        elsewhere.mkdir()
        p.symlink_to(elsewhere, target_is_directory=True)
        self.assert_rejected()
        self.assertEqual(list(elsewhere.iterdir()), [])

    def test_explicit_rollback_refuses_intervening_content(self):
        self.assertEqual(self.run_script().returncode, 0, self.last_output)
        p = self.root / FILES[0]
        p.write_text("<?php // new operator edit\n")
        result = self.run_script("rollback", self.receipt())
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertEqual(p.read_text(), "<?php // new operator edit\n")
        self.assertFalse((self.root / "storage/framework/down").exists())

    def test_explicit_rollback_refuses_intervening_metadata(self):
        self.assertEqual(self.run_script().returncode, 0, self.last_output)
        p = self.root / FILES[0]
        p.chmod(0o600)
        result = self.run_script("rollback", self.receipt())
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("metadata", result.stdout)
        self.assertEqual(stat.S_IMODE(p.stat().st_mode), 0o600)
        self.assertFalse((self.root / "storage/framework/down").exists())

    def test_explicit_rollback_refuses_changed_environment(self):
        self.assertEqual(self.run_script().returncode, 0, self.last_output)
        (self.root / ".env").write_text("APP_KEY=intervening-operator-change\n")
        result = self.run_script("rollback", self.receipt())
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("environment", result.stdout)
        self.assertFalse((self.root / "storage/framework/down").exists())

    def test_dangling_lock_symlink_is_rejected(self):
        backups = self.work / "erp-role-backups"
        backups.mkdir(mode=0o700)
        destination = self.work / "must-not-create"
        (backups / "deployment.lock").symlink_to(destination)
        self.assert_rejected()
        self.assertFalse(destination.exists())

    def test_concurrent_unrelated_edit_stops_before_copy(self):
        result = self.run_script(EDIT_UNRELATED_ON_DOWN="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("MANUAL_INTERVENTION_REQUIRED", result.stdout)
        self.assert_original()
        self.assertEqual((self.root / "legacy.txt").read_text(), "concurrent operator edit")
        self.assertTrue((self.root / "storage/framework/down").exists())

    def test_explicit_rollback_refuses_changed_head_reference(self):
        self.assertEqual(self.run_script().returncode, 0, self.last_output)
        self.git("branch", "other-ref", self.base)
        (self.root / ".git/HEAD").write_text("ref: refs/heads/other-ref\n")
        result = self.run_script("rollback", self.receipt())
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertFalse((self.root / "storage/framework/down").exists())

    def test_target_metadata_drift_during_down_stops_before_copy(self):
        result = self.run_script(CHMOD_TARGET_ON_DOWN="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        for path in FILES:
            self.assertEqual((self.root / path).read_bytes(), self.blobs[BASE][path])
        self.assertEqual(stat.S_IMODE((self.root / FILES[0]).stat().st_mode), 0o600)

    def test_target_edit_during_cache_clear_is_not_reported_applied(self):
        result = self.run_script(EDIT_TARGET_ON_CACHE="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("MANUAL_INTERVENTION_REQUIRED", result.stdout)
        self.assertNotIn("OVERLAY_APPLIED", result.stdout)
        self.assertEqual((self.root / FILES[0]).read_text(), "concurrent target edit")
        self.assertTrue((self.root / "storage/framework/down").exists())

    def test_target_edit_during_recovery_cache_clear_retains_maintenance(self):
        result = self.run_script(FAIL_COPY_ONCE="1", EDIT_TARGET_ON_CACHE="1")
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("MANUAL_INTERVENTION_REQUIRED", result.stdout)
        self.assertNotIn("ORIGINAL_FIVE_FILES_RESTORED", result.stdout)
        self.assertEqual((self.receipt() / "state").read_text(), "manual-intervention\n")
        self.assertEqual((self.root / FILES[0]).read_text(), "concurrent target edit")
        self.assertTrue((self.root / "storage/framework/down").exists())
        self.assertNotIn(" artisan up", self.calls.read_text())
        self.assert_untouched()

    def test_production_script_has_no_pin_overrides_or_privileged_artisan(self):
        source = (REPO / "deployment/erp_update_roles.sh").read_text()
        self.assertIn(f"readonly BASE={BASE}", source)
        self.assertIn(f"readonly TARGET={TARGET}", source)
        self.assertIn('runuser -u "$app_user" -- php artisan "$@"', source)
        self.assertNotIn("setfacl", source)
        self.assertNotIn("optimize:clear", source)
        self.assertNotIn("migrate", source.split("set -Eeuo pipefail", 1)[1])
        self.assertNotIn("reset --", source)
        self.assertNotIn("git clean", source)
        command("bash", "-n", str(REPO / "deployment/erp_update_roles.sh"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
