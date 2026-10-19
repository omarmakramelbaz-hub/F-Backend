#!/usr/bin/env python3
"""Restore branch entry of employee wallet phones without changing profile rights."""
import argparse
import json
from pathlib import Path
import sys

# Repository imports and the pinned deployment staging directory both work.
sys.path.append(str(Path(__file__).resolve().parent / 'launch_reset'))
from bonus_owner_patch import failure_details, install, preflight


PROFILE_GUARD = "abort_unless($this->canManageEmployees($actor),403,'إضافة الموظفين وتعديل بياناتهم متاحان للأونر والأدمن فقط.');"


def replace_once(source, old, new, code):
    if source.count(old) != 1:
        raise ValueError(code)
    return source.replace(old, new, 1)


def profile_policy(source):
    start = source.index('    public function employeeSave(array $values,$actor): array')
    end = source.index('    public function canManageAttendance(', start)
    if source[start:end].count(PROFILE_GUARD) != 2:
        raise ValueError('EMPLOYEE_PROFILE_SAVE_POLICY_CHANGED')
    if (source.count('// Owner-only bonus creation, including idempotent retries.') != 1
            or "'can_award_bonuses'=>$this->canVoidEntries($actor)" not in source
            or "return (int)$owner->id===1&&$owner->account_type==='admin'&&empty($owner->owner_resturant_id);" not in source):
        raise ValueError('OWNER_ONLY_BONUS_POLICY_CHANGED')


def patch_payroll(source: str) -> str:
    profile_policy(source)
    marker = '// Branch wallet phone entry keeps the shared branch-scoped writer.'
    opening = '    public function wallet(array $values,$actor): array\n    {\n'
    start = source.index(opening)
    end = source.index('    public function dailyNotes(', start)
    method = source[start:end]
    # These existing checks must survive: the writer refreshes the actor, checks
    # checkout permission and authorizes branch; employee lookup is branch-scoped.
    anchors = [
        "return $this->ops->write('employee.wallet',$v,$actor,function($branch,$actor)use($v,$number){\n",
        "$employee=$this->employee($v['employee_id'],$branch['value'],true); $this->ops->revision($employee,$v);",
        "$number=$this->walletNumber($v['wallet_phone']??'');",
        "DB::table('branch_employees')->where('id',$employee->id)->update(['wallet_phone'=>$number?:null,'revision'=>(int)$employee->revision+1,'actor_id'=>$actor->id,'updated_at'=>now('UTC')]);",
    ]
    if any(method.count(anchor) != 1 for anchor in anchors):
        raise ValueError('EMPLOYEE_WALLET_BRANCH_SCOPE_CHANGED')
    if marker in source:
        if source.count(marker) != 1 or method.count(marker) != 1 or 'canManageEmployees' in method:
            raise ValueError('PARTIAL_BRANCH_WALLET_PAYROLL_PATCH')
        return source
    if method.count(PROFILE_GUARD) != 2:
        raise ValueError('EMPLOYEE_WALLET_PROFILE_GUARDS_CHANGED')
    method = replace_once(method, opening + '        ' + PROFILE_GUARD + '\n',
                          opening + '        ' + marker + '\n', 'EMPLOYEE_WALLET_PREVALIDATION_GUARD_CHANGED')
    transaction = anchors[0]
    method = replace_once(method, transaction + '            ' + PROFILE_GUARD + '\n',
                          transaction, 'EMPLOYEE_WALLET_TRANSACTION_GUARD_CHANGED')
    if 'canManageEmployees' in method:
        raise ValueError('EMPLOYEE_WALLET_EXTRA_PROFILE_GUARD')
    return source[:start] + method + source[end:]


def patch_controller(source: str) -> str:
    marker = '/* Branch wallet phone entry retains branch-scoped write authorization. */'
    original = "if(in_array($request->route()?->getName(),['employees.save','employees.wallet'],true))"
    changed = "if(in_array($request->route()?->getName(),['employees.save'],true))"
    protected_save = changed + "abort_unless(app(BranchPayroll::class)->canManageEmployees(auth('admin')->user()),403,"
    if (source.count('/* Central employee profile routes, including stored remote replies. */') != 1
            or source.count('public function wallet(Request $r,BranchPayroll $s)') != 1):
        raise ValueError('EMPLOYEE_CONTROLLER_PROFILE_POLICY_CHANGED')
    if marker in source:
        if source.count(marker) != 1 or source.count(protected_save) != 1 or original in source:
            raise ValueError('PARTIAL_BRANCH_WALLET_CONTROLLER_PATCH')
        return source
    result = replace_once(source, original, marker + changed, 'EMPLOYEE_WALLET_MIDDLEWARE_ANCHOR_CHANGED')
    if result.count(protected_save) != 1:
        raise ValueError('EMPLOYEE_PROFILE_MIDDLEWARE_POLICY_CHANGED')
    return result


def patch_javascript(source: str) -> str:
    marker = '/* branch wallet phone entry */'
    policies = [
        "function edit(item){/* central employee profile controls */if(module==='employees'&&data.can_manage_employees!==true)return;",
        "if(data.can_manage_employees===true)menu.appendChild(btn('تعديل الموظف',function(){more.open=false;edit(item);}));",
        "addButton.hidden=module==='employees'&&data.can_manage_employees!==true;",
        "/* owner-only bonus creation */if(kind==='bonus'&&data.can_award_bonuses!==true)return box;",
        "function entry(item,kind){/* owner-only bonus creation */if(kind==='bonus'&&data.can_award_bonuses!==true)return;",
    ]
    if any(source.count(policy) != 1 for policy in policies):
        raise ValueError('EMPLOYEE_PROFILE_OR_BONUS_UI_POLICY_CHANGED')
    guarded = "var walletBox=n('div',undefined,'op-wallet-cell');if(data.can_manage_employees===true){var wallet=n('input');"
    restored = "var walletBox=n('div',undefined,'op-wallet-cell'),wallet=n('input');" + marker
    ending = "walletBox.appendChild(n('small','حفظ تلقائي'));}else{walletBox.appendChild(n('strong',item.wallet_phone||'—'));walletBox.appendChild(n('small','للعرض فقط'));}"
    write = "write(urls.wallet,{branch:item.branch,employee_id:item.id,expected_revision:item.revision,wallet_phone:wallet.value},function(){delete walletDrafts[item.id];});"
    if source.count(write) != 1:
        raise ValueError('EMPLOYEE_WALLET_INLINE_WRITE_CHANGED')
    if marker in source:
        if source.count(marker) != 1 or source.count(restored) != 1 or guarded in source or ending in source:
            raise ValueError('PARTIAL_BRANCH_WALLET_UI_PATCH')
        return source
    start = source.index(guarded)
    end = source.index(ending, start) + len(ending)
    method = source[start:end]
    if method.count(write) != 1 or method.count('can_manage_employees') != 1:
        raise ValueError('EMPLOYEE_WALLET_INLINE_SCOPE_CHANGED')
    method = replace_once(method, guarded, restored, 'EMPLOYEE_WALLET_INLINE_OPENING_CHANGED')
    method = replace_once(method, ending, "walletBox.appendChild(n('small','حفظ تلقائي'));", 'EMPLOYEE_WALLET_READONLY_ENDING_CHANGED')
    return source[:start] + method + source[end:]


def specs():
    return [('app/Services/Dashboard/BranchPayroll.php', patch_payroll),
            ('app/Http/Controllers/Dashboard/BranchOperationsController.php', patch_controller),
            ('public/dashboard/js/branch-operations.js', patch_javascript)]


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, required=True)
    parser.add_argument('--php', required=True)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--backup', type=Path)
    options = parser.parse_args()
    if not options.check and options.backup is None:
        parser.error('--backup is required unless --check is used')
    if str(options.root) != '/home/fasakha/public_html' or options.root.resolve() != options.root:
        raise SystemExit('UNEXPECTED_APPLICATION_ROOT')
    try:
        if options.check:
            print('EMPLOYEE_WALLET_BRANCH_PREFLIGHT=' + json.dumps(preflight(options.root, options.php, specs())))
        else:
            print('EMPLOYEE_WALLET_BRANCH_PATCH=' + json.dumps(install(options.root, options.backup, options.php, specs())))
    except Exception as error:
        raise SystemExit('EMPLOYEE_WALLET_BRANCH_PATCH_FAILED ' + failure_details(error))
