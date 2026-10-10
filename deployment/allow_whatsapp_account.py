#!/usr/bin/env python3
"""Add the confirmed production WABA without printing or changing secrets."""
import fcntl
import os
import pathlib
import pwd
import re
import signal
import stat
import subprocess
import sys
import tempfile

ACCOUNT_ID = "468336579702269"
APP_ROOT = pathlib.Path("/home/fasakha/public_html")
KEY = "WHATSAPP_ALLOWED_ACCOUNT_IDS"
ASSIGNMENT = re.compile(r"^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=(.*)$")


class SafeError(Exception):
    pass


def proposal(content):
    """Accept literal numeric IDs only; never rewrite unrelated dotenv content."""
    if content.startswith("\ufeff"):
        raise SafeError("ENV_ENCODING_NEEDS_REVIEW")
    lines = content.splitlines(keepends=True)
    matches = []
    quote = None
    for index, line in enumerate(lines):
        body = line.rstrip("\r\n")
        if quote is None:
            assignment = ASSIGNMENT.match(body)
            if assignment is None:
                continue
            name, rhs = assignment.groups()
            if name == KEY:
                matches.append((index, rhs))
            value = rhs.lstrip(" \t")
            if value[:1] in ("'", '"'):
                quote = value[0]
                scan = value[1:]
            else:
                continue
        else:
            scan = body
        # Track quoted multiline values so a key-like line inside another value
        # cannot be mistaken for an active setting. Ambiguity fails closed.
        escaped = False
        for char in scan:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
                break
    if quote is not None:
        raise SafeError("ENV_QUOTE_NEEDS_REVIEW")
    if len(matches) > 1:
        raise SafeError("DUPLICATE_ALLOWLIST_SETTING")
    if matches:
        index, rhs = matches[0]
        value = rhs.strip(" \t")
        if value.startswith(("'", '"')):
            parsed = re.fullmatch(r"(['\"])([0-9, \t]*)\1[ \t]*(?:#.*)?", value)
            if parsed is None:
                raise SafeError("ALLOWLIST_VALUE_NEEDS_REVIEW")
            value = parsed.group(2)
        else:
            parsed = re.fullmatch(r"([0-9, \t]*?)(?:[ \t]+#.*)?", value)
            if parsed is None:
                raise SafeError("ALLOWLIST_VALUE_NEEDS_REVIEW")
            value = parsed.group(1)
        existing = [part.strip(" \t") for part in value.split(",") if part.strip(" \t")]
    else:
        index, existing = None, []
    ids = list(dict.fromkeys(existing + [ACCOUNT_ID]))
    if ACCOUNT_ID in existing and len(existing) == len(set(existing)):
        return content, ids
    newline = "\r\n" if "\r\n" in content else "\n"
    replacement = KEY + '="' + ",".join(ids) + '"'
    if index is None:
        content += ("" if not content or content.endswith(("\n", "\r")) else newline)
        return content + replacement + newline, ids
    ending = "\r\n" if lines[index].endswith("\r\n") else "\n" if lines[index].endswith("\n") else ""
    lines[index] = replacement + ending
    return "".join(lines), ids


def read_env(path, uid):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != uid:
            raise SafeError("ENV_OWNERSHIP_NEEDS_REVIEW")
        with os.fdopen(descriptor, "rb", closefd=False) as stream:
            content = stream.read()
        return content, metadata
    finally:
        os.close(descriptor)


def atomic_replace(path, content, expected, metadata, uid):
    descriptor, temporary = tempfile.mkstemp(prefix=".env-whatsapp-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(content)
            stream.flush()
            os.fchown(stream.fileno(), metadata.st_uid, metadata.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(metadata.st_mode))
            os.fsync(stream.fileno())
        current, current_metadata = read_env(path, uid)
        if current != expected or (current_metadata.st_uid, current_metadata.st_gid, stat.S_IMODE(current_metadata.st_mode)) != (metadata.st_uid, metadata.st_gid, stat.S_IMODE(metadata.st_mode)):
            raise SafeError("ENV_CHANGED_CONCURRENTLY")
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


VERIFY_PHP = r"""
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $expected = explode(',', $argv[1]);
    $actual = config('whatsapp.allowed_account_ids', []);
    exit($actual === $expected ? 0 : 1);
} catch (\Throwable $error) { exit(1); }
"""


def command(root, arguments):
    try:
        return subprocess.run(arguments, cwd=root, stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL, timeout=40, check=False).returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return False


def private_parent(path, uid):
    path.mkdir(mode=0o700, exist_ok=True)
    metadata = path.lstat()
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != uid:
        raise SafeError("BACKUP_DIRECTORY_NEEDS_REVIEW")
    path.chmod(0o700)


def update(root, uid, runner=command):
    path = root / ".env"
    parent = root.parent / "whatsapp-release-backups"
    private_parent(parent, uid)
    lock_descriptor = os.open(parent / "allowlist-update.lock", os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        lock_metadata = os.fstat(lock_descriptor)
        if not stat.S_ISREG(lock_metadata.st_mode) or lock_metadata.st_uid != uid:
            raise SafeError("UPDATE_LOCK_NEEDS_REVIEW")
        try:
            fcntl.flock(lock_descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError("ANOTHER_ALLOWLIST_UPDATE_IS_RUNNING")
        before, metadata = read_env(path, uid)
        after_text, ids = proposal(before.decode("utf-8"))
        after = after_text.encode("utf-8")
        if not (root / "deployment/whatsapp_webhook_setup.php").is_file():
            raise SafeError("WEBHOOK_SETUP_SCRIPT_MISSING")
        backup = pathlib.Path(tempfile.mkdtemp(prefix="allowlist-", dir=parent))
        backup.chmod(0o700)
        backup_file = backup / "env.before"
        fd = os.open(backup_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as stream:
            stream.write(before)
            stream.flush()
            os.fsync(stream.fileno())
        changed = False
        try:
            if after != before:
                changed = True
                atomic_replace(path, after, before, metadata, uid)
            if not runner(root, ["php", "artisan", "config:clear"]):
                raise SafeError("CONFIG_CLEAR_FAILED")
            if not runner(root, ["php", "-r", VERIFY_PHP, ",".join(ids)]):
                raise SafeError("EFFECTIVE_ALLOWLIST_CHECK_FAILED")
            if not runner(root, ["php", "deployment/whatsapp_webhook_setup.php", "smoke"]):
                raise SafeError("WEBHOOK_SMOKE_FAILED")
            current, current_metadata = read_env(path, uid)
            if current != after or (current_metadata.st_gid, stat.S_IMODE(current_metadata.st_mode)) != (metadata.st_gid, stat.S_IMODE(metadata.st_mode)):
                raise SafeError("ENV_CHANGED_CONCURRENTLY")
            return "ALLOWLIST_READY", backup
        except BaseException as error:
            if changed:
                try:
                    current, _ = read_env(path, uid)
                    if current != before:
                        atomic_replace(path, before, after, metadata, uid)
                except BaseException:
                    raise SafeError("ROLLBACK_STOPPED_ENV_CHANGED_OR_WRITE_FAILED; BACKUP=" + str(backup)) from None
                restored_clear = runner(root, ["php", "artisan", "config:clear"])
                restored_smoke = runner(root, ["php", "deployment/whatsapp_webhook_setup.php", "smoke"]) if restored_clear else False
                result = "ORIGINAL_ENV_RESTORED" if restored_smoke else "ORIGINAL_ENV_RESTORED_RECHECK_FAILED"
                raise SafeError(result + "; BACKUP=" + str(backup)) from None
            if isinstance(error, SafeError):
                raise error
            raise SafeError("UPDATE_STOPPED_WITHOUT_ENV_CHANGE") from None
    finally:
        os.close(lock_descriptor)


def main():
    # Unexpected warning/exception text can contain environment values.
    # Only our fixed status strings and a private backup path are printed.
    try:
        if len(sys.argv) != 1 or os.geteuid() != pwd.getpwnam("fasakha").pw_uid:
            raise SafeError("RUN_AS_FASAKHA_WITHOUT_ARGUMENTS")
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError("UPDATE_INTERRUPTED")))
        result, backup = update(APP_ROOT, os.geteuid())
        print(result)
        print("BACKUP=" + str(backup))
    except SafeError as error:
        print(str(error), file=sys.stderr)
        return 1
    except BaseException:
        print("ALLOWLIST_UPDATE_FAILED_WITHOUT_SECRET_OUTPUT", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
