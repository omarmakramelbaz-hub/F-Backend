#!/usr/bin/env python3
"""Preserve live payroll/attendance changes while making bonus creation owner-only."""
import argparse
import hashlib
import json
import os
import re
import shutil
from pathlib import Path
import subprocess
import tempfile


def patch_payroll(source: str) -> str:
    if "return (int)$owner->id===1&&$owner->account_type==='admin'&&empty($owner->owner_resturant_id);" not in source:
        raise ValueError("PRIMARY_OWNER_POLICY_CHANGED")
    marker = "// Owner-only bonus creation, including idempotent retries."
    if marker in source:
        if source.count(marker) != 1 or "'can_award_bonuses'=>$this->canVoidEntries($actor)" not in source:
            raise ValueError("PARTIAL_BONUS_PATCH")
        return source
    start = source.index("    public function entry(array $values,$actor): array")
    end = source.index("    private function canVoidEntries($actor): bool", start)
    method = source[start:end]
    opening = "    public function entry(array $values,$actor): array\n    {\n"
    if method.count(opening) != 1:
        raise ValueError("ENTRY_ANCHOR_CHANGED")
    guard = ("        // Owner-only bonus creation, including idempotent retries.\n"
             "        if(($values['kind']??null)==='bonus')abort_unless($this->canVoidEntries($actor),403,'إضافة المكافآت متاحة للأونر فقط.');\n")
    method = method.replace(opening, opening + guard, 1)
    closure = "return $this->ops->write('employee.entry',$v,$actor,function($branch,$actor)use($v,$amount){\n"
    if method.count(closure) != 1:
        raise ValueError("ENTRY_TRANSACTION_ANCHOR_CHANGED")
    method = method.replace(closure, closure + "            if($v['kind']==='bonus')abort_unless($this->canVoidEntries($actor),403,'إضافة المكافآت متاحة للأونر فقط.');\n", 1)
    source = source[:start] + method + source[end:]
    listing = "'can_manage_attendance'=>$this->canManageAttendance($actor)"
    if source.count(listing) != 1:
        raise ValueError("LISTING_ANCHOR_CHANGED")
    # Reuse the persisted primary-owner check; do not broaden copied admin roles.
    return source.replace(listing, "'can_award_bonuses'=>$this->canVoidEntries($actor)," + listing, 1)


def patch_javascript(source: str) -> str:
    marker = "/* owner-only bonus creation */"
    if marker in source:
        if source.count(marker) != 2:
            raise ValueError("PARTIAL_BONUS_UI_PATCH")
        return source
    begin = source.index("function moneyCell(item,kind){")
    end = source.index("function renderEmployees(target){", begin)
    cell = source[begin:end]
    anchor = "var add=btn('+ إضافة '+names[kind]"
    if cell.count(anchor) != 1:
        raise ValueError("BONUS_BUTTON_ANCHOR_CHANGED")
    cell = cell.replace(anchor, "/* owner-only bonus creation */if(kind==='bonus'&&data.can_award_bonuses!==true)return box;" + anchor, 1)
    source = source[:begin] + cell + source[end:]
    anchor = "function entry(item,kind){"
    if source.count(anchor) != 1:
        raise ValueError("BONUS_DIALOG_ANCHOR_CHANGED")
    return source.replace(anchor, anchor + "/* owner-only bonus creation */if(kind==='bonus'&&data.can_award_bonuses!==true)return;", 1)


class SourcePatchError(RuntimeError):
    """A source compatibility failure whose public details contain no source content."""
    def __init__(self, relative, phase, error, source_sha256=None):
        code = "SOURCE_OPERATION_FAILED"
        if isinstance(error, UnicodeDecodeError):
            code = "SOURCE_NOT_UTF8"
        elif isinstance(error, ValueError):
            message = str(error)
            if re.fullmatch(r"[A-Z][A-Z0-9_]{0,99}", message):
                code = message
            elif message == "substring not found":
                code = "ANCHOR_NOT_FOUND"
            else:
                code = "SOURCE_TRANSFORM_FAILED"
        elif isinstance(error, subprocess.CalledProcessError):
            code = "SOURCE_SYNTAX_CHECK_FAILED"
        elif isinstance(error, OSError):
            code = "SOURCE_IO_FAILED"
        self.details = {"path": relative, "phase": phase, "code": code}
        if source_sha256 is not None:
            self.details["source_sha256"] = source_sha256
        super().__init__(code)


def failure_details(error):
    if isinstance(error, SourcePatchError):
        return json.dumps(error.details, sort_keys=True)
    # Unknown exception messages may include source text or local values.
    return json.dumps({"code": "SOURCE_PATCH_FAILED"}, sort_keys=True)


def default_specs():
    return [("app/Services/Dashboard/BranchPayroll.php", patch_payroll),
            ("public/dashboard/js/branch-operations.js", patch_javascript)]


def prepare_files(root: Path, specs=None):
    if specs is None:
        specs = default_specs()
    prepared = []
    for relative, transform in specs:
        path = root / relative
        old = None
        phase = "read"
        try:
            if path.is_symlink() or not path.is_file():
                raise ValueError("UNSAFE_SOURCE_PATH")
            old = path.read_bytes()
            phase = "transform"
            new = transform(old.decode("utf-8")).encode("utf-8")
            prepared.append((relative, path, old, new, path.stat()))
        except Exception as error:
            digest = hashlib.sha256(old).hexdigest() if old is not None else None
            raise SourcePatchError(relative, phase, error, digest) from None
    return prepared


def report_files(prepared):
    return {relative: {"before": hashlib.sha256(old).hexdigest(), "after": hashlib.sha256(new).hexdigest()}
            for relative, path, old, new, stat in prepared}


def check_prepared(prepared, php):
    # Check copies outside the application; --check never creates backups or edits live files.
    with tempfile.TemporaryDirectory(prefix="launch-source-check-") as folder:
        node = shutil.which("node")
        for index, (relative, path, old, new, stat) in enumerate(prepared):
            suffix = Path(relative).suffix
            if suffix != ".php" and not (suffix == ".js" and node):
                continue
            staged = Path(folder) / (str(index) + suffix)
            try:
                staged.write_bytes(new)
                command = [php, "-l", str(staged)] if suffix == ".php" else [node, "--check", str(staged)]
                subprocess.run(command, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            except Exception as error:
                raise SourcePatchError(relative, "syntax", error, hashlib.sha256(old).hexdigest()) from None


def preflight(root: Path, php: str, specs=None) -> dict:
    prepared = prepare_files(root, specs)
    check_prepared(prepared, php)
    # Reject concurrent changes before declaring the source check successful.
    for relative, path, old, new, stat in prepared:
        if path.read_bytes() != old:
            raise SourcePatchError(relative, "verify", ValueError("CONCURRENT_SOURCE_CHANGE"), hashlib.sha256(old).hexdigest())
    return report_files(prepared)


def install(root: Path, backup: Path, php: str, specs=None) -> dict:
    prepared = prepare_files(root, specs)
    check_prepared(prepared, php)
    backup.mkdir(mode=0o700, parents=True, exist_ok=False)
    report = report_files(prepared)
    for relative, path, old, new, stat in prepared:
        target = backup / relative
        target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        target.write_bytes(old)
        os.chmod(target, 0o600)
    (backup / "hashes.json").write_text(json.dumps(report, indent=2))
    os.chmod(backup / "hashes.json", 0o600)
    temporaries = []
    replaced = []
    try:
        for relative, path, old, new, stat in prepared:
            descriptor, temporary = tempfile.mkstemp(prefix=".owner-bonus-", dir=path.parent)
            temporary = Path(temporary)
            temporaries.append(temporary)
            with os.fdopen(descriptor, "wb") as out:
                out.write(new)
                out.flush()
                os.fsync(out.fileno())
            os.chmod(temporary, stat.st_mode & 0o777)
            if relative.endswith(".php"):
                subprocess.run([php, "-l", str(temporary)], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        # Lease both originals immediately before publishing; no blind replacement of concurrent edits.
        for relative, path, old, new, stat in prepared:
            if path.read_bytes() != old:
                raise ValueError("CONCURRENT_SOURCE_CHANGE")
        for spec, temporary in zip(prepared, temporaries):
            relative, path, old, new, stat = spec
            os.replace(temporary, path)
            replaced.append(spec)
        for relative, path, old, new, stat in prepared:
            if path.read_bytes() != new:
                raise ValueError("PUBLISHED_SOURCE_MISMATCH")
        return report
    except BaseException:
        for relative, path, old, new, stat in reversed(replaced):
            if path.read_bytes() == new:
                path.write_bytes(old)
                os.chmod(path, stat.st_mode & 0o777)
        raise
    finally:
        for temporary in temporaries:
            temporary.unlink(missing_ok=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", required=True, type=Path)
    parser.add_argument("--backup", type=Path)
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--php", required=True)
    options = parser.parse_args()
    if not options.check and options.backup is None:
        parser.error("--backup is required unless --check is used")
    if str(options.root) != "/home/fasakha/public_html" or options.root.resolve() != options.root:
        raise SystemExit("UNEXPECTED_APPLICATION_ROOT")
    try:
        if options.check:
            print("OWNER_ONLY_BONUS_PREFLIGHT=" + json.dumps(preflight(options.root, options.php)))
        else:
            print("OWNER_ONLY_BONUS_PATCH=" + json.dumps(install(options.root, options.backup, options.php)))
    except Exception as error:
        raise SystemExit("BONUS_PATCH_FAILED " + failure_details(error))
