#!/usr/bin/env python3
"""Restrict employee profile creation/editing while preserving daily branch work."""
import argparse
import json
from pathlib import Path
import re

from bonus_owner_patch import failure_details, install, preflight


def replace_once(source, old, new, code):
    if source.count(old) != 1:
        raise ValueError(code)
    return source.replace(old, new, 1)


def patch_payroll(source: str) -> str:
    marker = "// Central owner/admin employee profiles; branch daily work stays available."
    capability = "'can_manage_employees'=>$this->canManageEmployees($actor)"
    guard = "abort_unless($this->canManageEmployees($actor),403,'إضافة الموظفين وتعديل بياناتهم متاحان للأونر والأدمن فقط.');"
    if marker in source:
        if source.count(marker) != 1 or source.count(capability) != 1 or source.count(guard) != 4:
            raise ValueError("PARTIAL_EMPLOYEE_PROFILE_PATCH")
        return source
    anchor = "    public function employeeSave(array $values,$actor): array\n    {\n"
    helper = ("    // Central owner/admin employee profiles; branch daily work stays available.\n"
              "    public function canManageEmployees($actor): bool\n"
              "    {\n"
              "        $fresh=$this->ops->access->actor($actor);\n"
              "        return $fresh->account_type==='admin'&&empty($fresh->owner_resturant_id);\n"
              "    }\n")
    source = replace_once(source, anchor, helper + anchor + "        " + guard + "\n", "EMPLOYEE_SAVE_ANCHOR_CHANGED")
    source = replace_once(source, "    public function wallet(array $values,$actor): array\n    {\n",
                          "    public function wallet(array $values,$actor): array\n    {\n        " + guard + "\n", "EMPLOYEE_WALLET_ANCHOR_CHANGED")
    # Guards before the common writer reject cached results after role changes; guards
    # in its closure also re-read the actor at the point of the profile mutation.
    for kind in ["save", "wallet"]:
        pattern = re.compile(r"(return \$this->ops->write\('employee\." + kind + r"',\$v,\$actor,function\(\$branch,\$actor\)use\([^\n]*\)\{\n)")
        if len(pattern.findall(source)) != 1:
            raise ValueError("EMPLOYEE_" + kind.upper() + "_TRANSACTION_ANCHOR_CHANGED")
        source = pattern.sub(lambda match: match.group(1) + "            " + guard + "\n", source, count=1)
    return replace_once(source, "'can_manage_attendance'=>$this->canManageAttendance($actor)",
                        capability + ",'can_manage_attendance'=>$this->canManageAttendance($actor)", "EMPLOYEE_LISTING_ANCHOR_CHANGED")


def patch_controller(source: str) -> str:
    marker = "/* Central employee profile routes, including stored remote replies. */"
    if marker in source:
        if source.count(marker) != 1 or "->canManageEmployees(auth('admin')->user())" not in source:
            raise ValueError("PARTIAL_EMPLOYEE_CONTROLLER_PATCH")
        return source
    # Desktop remote-attempt recovery executes controller middleware before replaying
    # a reply; checking here covers that path as well as ordinary HTTP submissions.
    guard = (marker + "if(in_array($request->route()?->getName(),['employees.save','employees.wallet'],true))"
             "abort_unless(app(BranchPayroll::class)->canManageEmployees(auth('admin')->user()),403,'إضافة الموظفين وتعديل بياناتهم متاحان للأونر والأدمن فقط.');")
    return replace_once(source, "return $next($request);", guard + "return $next($request);", "EMPLOYEE_CONTROLLER_MIDDLEWARE_ANCHOR_CHANGED")


def patch_javascript(source: str) -> str:
    marker = "/* central employee profile controls */"
    if marker in source:
        if source.count(marker) != 1 or "if(module==='employees'&&data.can_manage_employees!==true)return;" not in source:
            raise ValueError("PARTIAL_EMPLOYEE_UI_PATCH")
        return source
    source = replace_once(source, "function edit(item){",
                          "function edit(item){" + marker + "if(module==='employees'&&data.can_manage_employees!==true)return;", "EMPLOYEE_EDITOR_ANCHOR_CHANGED")
    source = replace_once(source, "menu.appendChild(btn('تعديل الموظف',function(){more.open=false;edit(item);}));",
                          "if(data.can_manage_employees===true)menu.appendChild(btn('تعديل الموظف',function(){more.open=false;edit(item);}));", "EMPLOYEE_EDIT_BUTTON_ANCHOR_CHANGED")
    source = replace_once(source, "root.querySelector('[data-op-add]').onclick=function(){edit();};",
                          "var addButton=root.querySelector('[data-op-add]');if(addButton)addButton.onclick=function(){edit();};", "EMPLOYEE_ADD_BINDING_ANCHOR_CHANGED")
    source = replace_once(source, "function render(){",
                          "function render(){var addButton=root.querySelector('[data-op-add]');if(addButton)addButton.hidden=module==='employees'&&data.can_manage_employees!==true;", "EMPLOYEE_RENDER_ANCHOR_CHANGED")
    start = source.index("var walletBox=n('div',undefined,'op-wallet-cell'),wallet=n('input');")
    finish_anchor = "walletBox.appendChild(n('small','حفظ تلقائي'));"
    finish = source.index(finish_anchor, start) + len(finish_anchor)
    original = source[start:finish]
    original = original.replace("var walletBox=n('div',undefined,'op-wallet-cell'),wallet=n('input');",
                                "var walletBox=n('div',undefined,'op-wallet-cell');if(data.can_manage_employees===true){var wallet=n('input');", 1)
    original += "}else{walletBox.appendChild(n('strong',item.wallet_phone||'—'));walletBox.appendChild(n('small','للعرض فقط'));}"
    return source[:start] + original + source[finish:]


def patch_view(source: str) -> str:
    marker = "{{-- Central employee profile creation --}}"
    if marker in source:
        if source.count(marker) != 1:
            raise ValueError("PARTIAL_EMPLOYEE_VIEW_PATCH")
        return source
    pattern = re.compile(r"<button data-op-add\b[^>]*>.*?</button>", re.DOTALL)
    if len(pattern.findall(source)) != 1:
        raise ValueError("EMPLOYEE_ADD_VIEW_ANCHOR_CHANGED")
    return pattern.sub(lambda match: marker + "@if($boot['module']!=='employees'||($boot['initial']['can_manage_employees']??false))" + match.group(0) + "@endif", source, count=1)


def specs():
    return [("app/Services/Dashboard/BranchPayroll.php", patch_payroll),
            ("app/Http/Controllers/Dashboard/BranchOperationsController.php", patch_controller),
            ("public/dashboard/js/branch-operations.js", patch_javascript),
            ("resources/views/admin/branch_operations/index.blade.php", patch_view)]


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
            print("EMPLOYEE_PROFILE_PREFLIGHT=" + json.dumps(preflight(options.root, options.php, specs())))
        else:
            print("EMPLOYEE_PROFILE_PATCH=" + json.dumps(install(options.root, options.backup, options.php, specs())))
    except Exception as error:
        raise SystemExit("EMPLOYEE_PROFILE_PATCH_FAILED " + failure_details(error))
