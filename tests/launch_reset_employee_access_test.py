#!/usr/bin/env python3
"""Employee profile access regression tests on the base and optional deployed sources."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/launch_reset'))
from employee_profile_patch import specs, patch_javascript, patch_payroll
from bonus_owner_patch import install, preflight, patch_payroll as bonus_payroll, patch_javascript as bonus_javascript

php = os.environ['LAUNCH_TEST_PHP']
autoload = os.environ['LAUNCH_TEST_AUTOLOAD']


def test_ui(javascript):
    render = javascript[javascript.index('function render(){'):javascript.index('var moneyLabels=')]
    employees = javascript[javascript.index('function renderEmployees(target){'):javascript.index('function advanceDay(){')]
    start = javascript.index('function edit(item){')
    ends = [javascript.find(anchor, start) for anchor in ['function attendanceCell(item){', 'function employeeSchedule(item){']]
    editor = javascript[start:min(end for end in ends if end >= 0)]
    script = '''const assert=require('node:assert/strict');
class Element{constructor(tag,text,cls){this.tag=tag;this.text=text;this.cls=cls;this.children=[];this.dataset={};this.value='';this.handlers={};this.classList={add(){},contains(){return false}}}
appendChild(x){this.children.push(x);return x} replaceChildren(){this.children=[]} setAttribute(){} addEventListener(k,v){this.handlers[k]=v} querySelector(){return null} querySelectorAll(){return []}}
function n(tag,text,cls){return new Element(tag,text,cls)}function btn(text,fn,cls){let b=n('button',text,cls);b.action=fn;return b}
function fixedDisabled(e,v){e.disabled=v}function actions(){return n('div')}function labelNode(a,b){return n('label',a+' '+b)}
function branchName(x){return x}function attendanceCell(){return n('attendance')}function dayKey(x){return x.id}function moneyCell(x,k){return n(k)}function statement(){}function employeeSchedule(x){return x.shift}
let opened=0;function open(){opened++;return false}let module='employees',page=1,rowDrafts={},walletDrafts={},window={};
let filters={job_title:n('select'),shift:n('select')},moneyLabels={},names={draft:'draft'},tableRows;
function table(heads,rows){tableRows=rows;let box=n('div'),t=n('table');box.appendChild(t);box.querySelector=()=>t;return box}
let controls={'[data-op-add]':n('button'),'[data-op-pages]':n('span'),'[data-op-prev]':n('button'),'[data-op-next]':n('button'),'[data-op-cards]':n('div'),'[data-op-table]':n('div')};
let root={querySelector(k){assert.ok(controls[k],'Unexpected selector '+k);return controls[k]}};
const employee={id:1,name:'Test employee',branch:'f:94',wallet_phone:'01000000001',job_title:'Cashier',shift:'Morning',statement:{net:'10.00',month:'2026-10',eligible_days:19,status:'draft'}};
let data={can_manage_employees:false,items:[employee],pagination:{page:1,last_page:1,total:1},summary:{employees:1},filters:{day:'2026-10-19'}};
function walk(x){return [x].concat(...(x.children||[]).map(walk))}
''' + render + employees + editor + '''
edit(employee);edit();assert.equal(opened,0,'Branch opened employee profile/add dialog');
render();assert.equal(controls['[data-op-add]'].hidden,true,'Branch add button visible');
assert.equal(walk(tableRows[0][10]).filter(x=>x.text==='تعديل الموظف').length,0,'Branch edit action visible');
assert.equal(walk(tableRows[0][7]).filter(x=>x.tag==='input').length,0,'Branch wallet editable');
assert.ok(walk(tableRows[0][7]).some(x=>x.text===employee.wallet_phone),'Read-only wallet disappeared');
assert.ok(walk(tableRows[0][10]).some(x=>x.text==='كشف الشهر'),'Daily statement action disappeared');
data.can_manage_employees=true;render();assert.equal(controls['[data-op-add]'].hidden,false);
let editButtons=walk(tableRows[0][10]).filter(x=>x.text==='تعديل الموظف');assert.equal(editButtons.length,1);
assert.equal(walk(tableRows[0][7]).filter(x=>x.tag==='input').length,1,'Central wallet control absent');
editButtons[0].action();edit();assert.equal(opened,2,'Central profile/add dialog denied');
console.log('PASS: branch profile/add dialog blocked, add/edit controls hidden, wallet read-only; central profile controls and daily statements retained');
'''
    subprocess.run(['node', '-e', script], check=True)


with tempfile.TemporaryDirectory(prefix='employee-profile-tests-') as folder:
    stage = Path(folder)
    originals = {}
    for relative, transform in specs():
        originals[relative] = (ROOT / relative).read_bytes()
        path = stage / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(originals[relative])
        changed = transform(path.read_text())
        assert transform(changed) == changed, relative + ' is not idempotent'
    checked = preflight(stage, php, specs())
    assert all((stage / relative).read_bytes() == old for relative, old in originals.items()), 'Preflight wrote source files'
    # Production installs the bonus policy first, then the employee profile policy.
    bonus_specs = [('app/Services/Dashboard/BranchPayroll.php', bonus_payroll), ('public/dashboard/js/branch-operations.js', bonus_javascript)]
    install(stage, stage / 'bonus-backup', php, bonus_specs)
    before_employee = {relative: (stage / relative).read_bytes() for relative, transform in specs()}
    install(stage, stage / 'employee-backup', php, specs())
    for relative, transform in specs():
        assert (stage / 'employee-backup' / relative).read_bytes() == before_employee[relative]
        assert transform((stage / relative).read_text()) == (stage / relative).read_text()
    for relative, transform in bonus_specs:
        assert transform((stage / relative).read_text()) == (stage / relative).read_text(), 'Employee patch disturbed bonus policy'
    subprocess.run([php, str(ROOT / 'tests/launch_reset_employee_access_test.php'), autoload, str(stage)], check=True)
    subprocess.run(['node', '--check', str(stage / 'public/dashboard/js/branch-operations.js')], check=True)
    test_ui((stage / 'public/dashboard/js/branch-operations.js').read_text())

    # Optional exact deployed sources are read-only fixtures. Their part-time form,
    # salary and attendance logic must survive the same incremental transforms.
    payroll_variant = os.environ.get('LAUNCH_TEST_EMPLOYEE_PAYROLL_VARIANT')
    javascript_variant = os.environ.get('LAUNCH_TEST_EMPLOYEE_JS_VARIANT')
    if payroll_variant and javascript_variant:
        live_payroll = Path(payroll_variant).read_text()
        live_javascript = Path(javascript_variant).read_text()
        changed_payroll = patch_payroll(bonus_payroll(live_payroll))
        changed_javascript = patch_javascript(bonus_javascript(live_javascript))
        assert patch_payroll(changed_payroll) == changed_payroll
        assert patch_javascript(changed_javascript) == changed_javascript
        assert bonus_payroll(changed_payroll) == changed_payroll
        assert bonus_javascript(changed_javascript) == changed_javascript
        for anchor in ['schedule_type', 'attendance_start', 'attendance_end', 'EmployeePayHours::scheduledMinutes']:
            assert changed_payroll.count(anchor) == live_payroll.count(anchor), 'Part-time employee policy changed'
        (stage / 'app/Services/Dashboard/BranchPayroll.php').write_text(changed_payroll)
        (stage / 'public/dashboard/js/branch-operations.js').write_text(changed_javascript)
        subprocess.run([php, '-l', str(stage / 'app/Services/Dashboard/BranchPayroll.php')], check=True)
        subprocess.run(['node', '--check', str(stage / 'public/dashboard/js/branch-operations.js')], check=True)
        test_ui(changed_javascript)
        print('PASS: latest deployed part-time salary variant preserves schedule/payroll code and composes with the owner-only bonus policy')
    print('PASS: employee source preflight, atomic backups, idempotence and sequential installation')
