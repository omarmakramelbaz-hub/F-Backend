#!/usr/bin/env python3
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/launch_reset'))
from bonus_owner_patch import install, patch_payroll, patch_javascript
from customer_global_patch import patch_customers, patch_phone, patch_customer_ui

php = os.environ['LAUNCH_TEST_PHP']
vendor = os.environ['LAUNCH_TEST_AUTOLOAD']
with tempfile.TemporaryDirectory(prefix='launch-reset-patch-tests-') as folder:
    stage = Path(folder)
    specs = [('app/Services/Dashboard/BranchPayroll.php', patch_payroll),
             ('public/dashboard/js/branch-operations.js', patch_javascript),
             ('app/Services/Dashboard/BranchCustomers.php', patch_customers),
             ('app/Services/Dashboard/PosServicePhone.php', patch_phone),
             ('public/dashboard/js/customer-crm.js', patch_customer_ui)]
    for relative, transform in specs:
        original = (ROOT / relative).read_text()
        changed = transform(original)
        assert transform(changed) == changed, relative + ' patch is not idempotent'
        path = stage / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(original)
    report = install(stage, stage / 'source-backup', php, specs)
    assert len(report) == 5
    for relative, transform in specs:
        assert (stage / 'source-backup' / relative).read_bytes() == (ROOT / relative).read_bytes()
        assert (stage / relative).read_text() == transform((ROOT / relative).read_text())
        if relative.endswith('.js'):
            subprocess.run(['node', '--check', str(stage / relative)], check=True)
    subprocess.run([php, str(ROOT / 'tests/launch_reset_permissions_customers_test.php'), vendor, str(stage)], check=True)
    # Check UI behavior using the actual patched moneyCell function and minimal DOM helpers.
    javascript = (stage / 'public/dashboard/js/branch-operations.js').read_text()
    cell = javascript[javascript.index('function moneyCell('):javascript.index('function renderEmployees(')]
    script = '''const assert=require('node:assert/strict');
function n(tag,text,cls){return {tag,text,cls,children:[],dataset:{},appendChild(x){this.children.push(x)},setAttribute(){}}}
function btn(text,action,cls){const x=n('button',text,cls);x.action=action;return x}
function fixedDisabled(x,v){x.disabled=v} function entryDetails(){} function entry(){}
var names={bonus:'مكافأة',advance:'سلفة',deduction:'خصم'},moneyLabels={bonus:'المكافآت'};var data={can_award_bonuses:false};
''' + cell + '''
const employee={name:'Test',daily:{bonus:{count:1,amount:'50.00'},advance:{count:0,amount:'0.00'}},day_closed:false};
let box=moneyCell(employee,'bonus');assert.equal(box.children.filter(x=>x.dataset.opEntry==='bonus').length,0);assert.equal(box.children.filter(x=>x.dataset.opTotal==='bonus').length,1);
assert.equal(moneyCell(employee,'advance').children.filter(x=>x.dataset.opEntry==='advance').length,1);
data.can_award_bonuses=true;assert.equal(moneyCell(employee,'bonus').children.filter(x=>x.dataset.opEntry==='bonus').length,1);
console.log('PASS: branch bonus creation hidden, recorded bonus totals readable, owner creation and other branch entry kinds retained');
'''
    subprocess.run(['node', '-e', script], check=True)
    # The deployed auto-branch lookup moved $scope before $keys and $add.
    # Keep its actual method as a regression fixture (Git blob 16648bce251ac6007647ffea6080b91c6c0e7566).
    relative = 'app/Services/Dashboard/PosServicePhone.php'
    phone_source = (ROOT / relative).read_text()
    start = phone_source.index('    public function customers(array $values,$actor): array')
    end = phone_source.index('    public static function normalizedColumn(', start)
    fixture = (ROOT / 'tests/fixtures/launch-reset/phone_customers_all_branches.php.txt').read_text()
    live_variant = phone_source[:start] + fixture + '\n\n' + phone_source[end:]
    changed = patch_phone(live_variant)
    assert patch_phone(changed) == changed, 'All-branch phone patch is not idempotent'
    assert changed.index('$scope=$all?') < changed.index('$keys=array_unique($keys)')
    assert changed.index('        };\n', changed.index('$add=function')) < changed.index('// Shared directory lookup precedes')
    (stage / relative).write_text(changed)
    subprocess.run([php, '-l', str(stage / relative)], check=True)
    subprocess.run([php, str(ROOT / 'tests/launch_reset_permissions_customers_test.php'), vendor, str(stage), 'all-branches'], check=True)
    print('PASS: deployed all-branch phone lookup variant, central global delivery search, and branch all-scope denial')
    print('PASS: source-preserving atomic patches, exact backups, idempotence and JavaScript syntax')
