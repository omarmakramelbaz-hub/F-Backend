#!/usr/bin/env python3
"""Preserve live payroll/attendance changes while making bonus creation owner-only."""
import argparse
import hashlib
import json
import os
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


def install(root: Path, backup: Path, php: str, specs=None) -> dict:
    if specs is None:
        specs = [("app/Services/Dashboard/BranchPayroll.php", patch_payroll),
                 ("public/dashboard/js/branch-operations.js", patch_javascript)]
    prepared = []
    for relative, transform in specs:
        path = root / relative
        if path.is_symlink() or not path.is_file():
            raise ValueError("UNSAFE_SOURCE_PATH")
        old = path.read_bytes()
        new = transform(old.decode("utf-8")).encode("utf-8")
        prepared.append((relative, path, old, new, path.stat()))
    backup.mkdir(mode=0o700, parents=True, exist_ok=False)
    report = {}
    for relative, path, old, new, stat in prepared:
        target = backup / relative
        target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        target.write_bytes(old)
        os.chmod(target, 0o600)
        report[relative] = {"before": hashlib.sha256(old).hexdigest(), "after": hashlib.sha256(new).hexdigest()}
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
    parser.add_argument("--backup", required=True, type=Path)
    parser.add_argument("--php", required=True)
    options = parser.parse_args()
    if str(options.root) != "/home/fasakha/public_html" or options.root.resolve() != options.root:
        raise SystemExit("UNEXPECTED_APPLICATION_ROOT")
    try:
        print("OWNER_ONLY_BONUS_PATCH=" + json.dumps(install(options.root, options.backup, options.php)))
    except Exception as error:
        raise SystemExit("BONUS_PATCH_FAILED " + type(error).__name__)
