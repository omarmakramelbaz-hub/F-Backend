#!/usr/bin/env python3
"""Restore the branch wallet field while preserving profile/accounting restrictions."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/launch_reset'))
sys.path.insert(0, str(ROOT / 'deployment'))
from bonus_owner_patch import install, preflight, patch_payroll as bonus_payroll, patch_javascript as bonus_javascript
from employee_profile_patch import specs as profile_specs
from employee_wallet_branch_patch import specs as wallet_specs, patch_payroll, patch_controller, patch_javascript

PHP = os.environ.get('LAUNCH_TEST_PHP', '/tmp/current-customer-consent-runtime/php')
AUTOLOAD = os.environ.get('LAUNCH_TEST_AUTOLOAD', '/tmp/current-customer-consent-runtime/vendor/autoload.php')


def test_ui(javascript):
    render = javascript[javascript.index('function render(){'):javascript.index('var moneyLabels=')]
    employees = javascript[javascript.index('function renderEmployees(target){'):javascript.index('function advanceDay(){')]
    start = javascript.index('function edit(item){')
    ends = [javascript.find(anchor, start) for anchor in ('function attendanceCell(item){', 'function employeeSchedule(item){')]
    editor = javascript[start:min(end for end in ends if end >= 0)]
    script = r'''const assert=require('node:assert/strict');
class Element{constructor(tag,text,cls){this.tag=tag;this.text=text;this.cls=cls;this.children=[];this.dataset={};this.value='';this.handlers={};this.classList={add(){},contains(){return false}}}
appendChild(x){this.children.push(x);return x}replaceChildren(){this.children=[]}setAttribute(){}addEventListener(k,v){this.handlers[k]=v}querySelector(){return null}querySelectorAll(){return []}}
function n(tag,text,cls){return new Element(tag,text,cls)}function btn(text,fn,cls){let b=n('button',text,cls);b.action=fn;return b}
function fixedDisabled(e,v){e.disabled=v}function actions(){return n('div')}function labelNode(a,b){return n('label',a+' '+b)}
function branchName(x){return x}function attendanceCell(){return n('attendance')}function dayKey(x){return x.id}function moneyCell(x,k){return n(k)}function statement(){}function employeeSchedule(x){return x.shift}function message(){}
let opened=0;function open(){opened++;return false}let module='employees',page=1,rowDrafts={},walletDrafts={},window={};
let filters={job_title:n('select'),shift:n('select')},moneyLabels={},names={draft:'draft'},tableRows,writes=[],urls={wallet:'/employees/wallet'};
function write(url,payload,callback){writes.push({url,payload});if(callback)callback()}
function table(heads,rows){tableRows=rows;let box=n('div'),t=n('table');box.appendChild(t);box.querySelector=()=>t;return box}
let controls={'[data-op-add]':n('button'),'[data-op-pages]':n('span'),'[data-op-prev]':n('button'),'[data-op-next]':n('button'),'[data-op-cards]':n('div'),'[data-op-table]':n('div')};
let root={querySelector(k){assert.ok(controls[k],'Unexpected selector '+k);return controls[k]}};
const employee={id:1,revision:7,name:'Test employee',branch:'f:94',wallet_phone:'01000000001',job_title:'Cashier',shift:'Morning',statement:{net:'10.00',month:'2026-10',eligible_days:19,status:'draft'}};
let data={can_manage_employees:false,can_award_bonuses:false,items:[employee],pagination:{page:1,last_page:1,total:1},summary:{employees:1},filters:{day:'2026-10-19'}};
function walk(x){return [x].concat(...(x.children||[]).map(walk))}
''' + render + employees + editor + r'''
edit(employee);edit();assert.equal(opened,0,'Branch opened profile/add dialog');
render();assert.equal(controls['[data-op-add]'].hidden,true,'Branch add control visible');
assert.equal(walk(tableRows[0][10]).filter(x=>x.text==='تعديل الموظف').length,0,'Branch profile edit button visible');
let walletFields=walk(tableRows[0][7]).filter(x=>x.tag==='input');assert.equal(walletFields.length,1,'Branch wallet input absent');
assert.equal(walletFields[0].value,employee.wallet_phone);assert.ok(!walletFields[0].disabled,'Branch wallet input disabled');
walletFields[0].value='01099999999';walletFields[0].handlers.input();walletFields[0].handlers.change();
assert.deepEqual(writes,[{url:'/employees/wallet',payload:{branch:'f:94',employee_id:1,expected_revision:7,wallet_phone:'01099999999'}}],'Wallet field submitted profile/accounting data or wrong route');
assert.deepEqual(walletDrafts,{},'Successful wallet save did not clear draft');
assert.ok(walk(tableRows[0][10]).some(x=>x.text==='كشف الشهر'),'Daily statement action disappeared');
data.can_manage_employees=true;data.can_award_bonuses=true;render();assert.equal(controls['[data-op-add]'].hidden,false);
let editButtons=walk(tableRows[0][10]).filter(x=>x.text==='تعديل الموظف');assert.equal(editButtons.length,1,'Owner profile editor absent');
editButtons[0].action();edit();assert.equal(opened,2,'Central profile/add dialog denied');
assert.equal(walk(tableRows[0][7]).filter(x=>x.tag==='input').length,1,'Owner wallet input absent');
console.log('PASS: actual branch wallet input saves only wallet data; employee add/edit stays hidden; central editor and daily statements remain');
'''
    subprocess.run(['node', '-e', script], check=True)


def scoped_preservation(before, after):
    old = before['app/Services/Dashboard/BranchPayroll.php']
    new = after['app/Services/Dashboard/BranchPayroll.php']
    opening, closing = '    public function wallet(array $values,$actor): array', '    public function dailyNotes('
    a, b = old.index(opening), old.index(closing, old.index(opening))
    c, d = new.index(opening), new.index(closing, new.index(opening))
    assert old[:a] == new[:c] and old[b:] == new[d:], 'Wallet fix changed another payroll method'
    old = before['public/dashboard/js/branch-operations.js']
    new = after['public/dashboard/js/branch-operations.js']
    for opening, closing in [('function edit(item){', 'function attendanceCell(item){'),
                             ('function moneyCell(item,kind){', 'function renderEmployees(target){')]:
        if closing not in old[old.index(opening):]:
            closing = 'function employeeSchedule(item){'
        assert old[old.index(opening):old.index(closing, old.index(opening))] == new[new.index(opening):new.index(closing, new.index(opening))], 'Wallet fix changed editor/bonus behavior'


with tempfile.TemporaryDirectory(prefix='employee-wallet-branch-tests-') as folder:
    stage = Path(folder)
    for relative, transform in profile_specs():
        path = stage / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        original = (ROOT / relative).read_text()
        if relative.endswith('BranchPayroll.php'):
            original = bonus_payroll(original)
        elif relative.endswith('branch-operations.js'):
            original = bonus_javascript(original)
        path.write_text(transform(original))
    before = {relative: (stage / relative).read_text() for relative, transform in wallet_specs()}
    for relative, transform in wallet_specs():
        changed = transform(before[relative])
        assert transform(changed) == changed, relative + ' is not idempotent'
    preflight(stage, PHP, wallet_specs())
    assert all((stage / relative).read_text() == text for relative, text in before.items()), 'Wallet preflight edited source'
    install(stage, stage / 'wallet-source-backup', PHP, wallet_specs())
    after = {relative: (stage / relative).read_text() for relative, transform in wallet_specs()}
    scoped_preservation(before, after)
    for relative, transform in wallet_specs():
        assert (stage / 'wallet-source-backup' / relative).read_text() == before[relative], 'Source backup not exact'
        assert transform(after[relative]) == after[relative], 'Installed wallet patch not idempotent'
    assert bonus_payroll(after['app/Services/Dashboard/BranchPayroll.php']) == after['app/Services/Dashboard/BranchPayroll.php']
    assert bonus_javascript(after['public/dashboard/js/branch-operations.js']) == after['public/dashboard/js/branch-operations.js']
    subprocess.run([PHP, str(ROOT / 'tests/employee_wallet_branch_test.php'), AUTOLOAD, str(stage)], check=True)
    subprocess.run(['node', '--check', str(stage / 'public/dashboard/js/branch-operations.js')], check=True)
    test_ui(after['public/dashboard/js/branch-operations.js'])
    print('PASS: source-preserving wallet-only patch, exact backup, read-only preflight and repeat installation')
