#!/usr/bin/env python3
"""Net summaries use real filtered monthly periods, independent of pagination."""
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
from employee_wallet_branch_patch import specs as wallet_specs
from employee_net_total_patch import specs as net_specs

PHP = os.environ.get('LAUNCH_TEST_PHP', '/tmp/current-customer-consent-runtime/php')
AUTOLOAD = os.environ.get('LAUNCH_TEST_AUTOLOAD', '/tmp/current-customer-consent-runtime/vendor/autoload.php')


def test_ui(javascript):
    table = javascript[javascript.index('function table(headings,rows){'):javascript.index('function labelNode(')]
    render = javascript[javascript.index('function render(){'):javascript.index('var moneyLabels=')]
    employees = javascript[javascript.index('function renderEmployees(target){'):javascript.index('function advanceDay(){')]
    program = r'''const assert=require('node:assert/strict');
class Element{constructor(tag,text,cls){this.tag=tag;this.text=text;this.cls=cls||'';this.children=[];this.dataset={};this.value='';this.handlers={};this.classList={add:(v)=>{this.cls+=' '+v},contains:(v)=>this.cls.split(' ').includes(v)}}
appendChild(x){this.children.push(x);return x}replaceChildren(){this.children=[]}setAttribute(k,v){(this.attributes||(this.attributes={}))[k]=v}addEventListener(k,v){this.handlers[k]=v}
querySelector(s){return this.querySelectorAll(s)[0]||null}querySelectorAll(s){let parts=s.split(' '),found=[];function match(e,p){return p.startsWith('.')?e.cls.split(' ').includes(p.slice(1)):e.tag===p}function walk(e,anc){if(match(e,parts.at(-1))&&(parts.length===1||anc.some(a=>match(a,parts[0]))))found.push(e);for(let c of e.children)walk(c,anc.concat(e))}for(let e of this.children)walk(e,[]);return found}}
global.Node=Element;function n(tag,text,cls){return new Element(tag,text,cls)}function btn(text,fn,cls){let b=n('button',text,cls);b.action=fn;return b}
function fixedDisabled(e,v){e.disabled=v}function actions(){return n('div')}function labelNode(a,b){return n('label',a+' '+b)}
function branchName(x){return x}function attendanceCell(){return n('attendance')}function dayKey(x){return x.id}function moneyCell(x,k){return n(k)}function statement(){}function message(){}
let module='employees',page=1,rowDrafts={},walletDrafts={},window={},filters={job_title:n('select'),shift:n('select')},moneyLabels={deduction:'الخصومات',bonus:'المكافآت',advance:'السلف'},names={draft:'draft'},writes=[],urls={wallet:'/wallet'};
function write(url,payload,callback){writes.push({url,payload});if(callback)callback()}
let controls={'[data-op-add]':n('button'),'[data-op-pages]':n('span'),'[data-op-prev]':n('button'),'[data-op-next]':n('button'),'[data-op-cards]':n('div'),'[data-op-table]':n('div')};
let root={querySelector(k){assert.ok(controls[k],'Unexpected selector '+k);return controls[k]}};
let employee={id:31,revision:7,name:'Page two employee',branch:'f:94',wallet_phone:'01000000001',job_title:'Cashier',shift:'Morning',statement:{net:'-12.34',month:'2026-09',eligible_days:30,status:'draft'}};
let data={can_manage_employees:false,can_award_bonuses:false,items:[employee],pagination:{page:2,last_page:2,total:37},summary:{employees:37,present:0,absent:0,leave:0,bonus:'0.00',deduction:'0.00',advance:'0.00',net:'1234567.89'},filters:{day:'2026-10-19',month:'2026-09'}};
function walk(x){return [x].concat(...(x.children||[]).map(walk))}function textOf(x){return [x.text,x.textContent].filter(x=>x!=null).join(' ')+(x.children||[]).map(textOf).join(' ')}
''' + table + render + employees + r'''
function verifyTotal(expected){
let cards=controls['[data-op-cards]'].children;assert.equal(cards.length,8,'Expected eight summary cards');
let netCard=cards.find(x=>x.cls.split(' ').includes('op-card-net'));assert.ok(netCard,'Net summary card missing');
assert.ok(textOf(netCard).includes('إجمالي صافي المستحقات'));assert.equal(netCard.querySelector('strong').text,expected);assert.ok(netCard.querySelector('small').text.startsWith('جنيه'),'Net card displayed employee unit');assert.ok(textOf(netCard).includes(data.filters.month),'Card month not explicit');
let grid=controls['[data-op-table]'].querySelector('table');assert.ok(grid,'Employee totals table absent');
let headings=grid.querySelectorAll('th').map(x=>textOf(x));let netColumn=headings.indexOf('صافي المستحقات');assert.ok(netColumn>=0,'Net heading missing');
let footer=grid.querySelector('tfoot');assert.ok(footer,'Net total footer absent');
let cells=footer.querySelector('tr').children,index=0,netCells=[];
for(let cell of cells){if(cell.cls.split(' ').includes('op-net-total'))netCells.push({cell,index});index+=cell.colSpan||1}
assert.equal(index,headings.length,'Footer span no longer matches employee columns');assert.equal(netCells.length,1);assert.equal(netCells[0].index,netColumn,'Total is not aligned under net column');
assert.ok(textOf(netCells[0].cell).includes(expected),'Footer used a page/row sum or lost exact digits');
assert.ok(textOf(netCells[0].cell).includes(data.filters.month),'Footer month not explicit');
}
render();verifyTotal('1234567.89');assert.equal(controls['[data-op-add]'].hidden,true,'Branch add employee control visible');
let grid=controls['[data-op-table]'].querySelector('table');assert.ok(!textOf(grid).includes('تعديل الموظف'),'Branch profile editor exposed');
let wallet=grid.querySelectorAll('input').find(x=>Object.hasOwn(x.dataset,'opWallet'));assert.ok(wallet&&!wallet.disabled,'Branch wallet field changed');
wallet.value='01099999999';wallet.handlers.input();wallet.handlers.change();assert.deepEqual(writes,[{url:'/wallet',payload:{branch:'f:94',employee_id:31,expected_revision:7,wallet_phone:'01099999999'}}]);
data.can_manage_employees=true;render();verifyTotal('1234567.89');assert.equal(controls['[data-op-add]'].hidden,false);assert.ok(textOf(controls['[data-op-table]']).includes('تعديل الموظف'),'Central editor missing');
data.items=[];data.pagination={page:1,last_page:1,total:0};data.summary={employees:0,present:0,absent:0,leave:0,bonus:'0.00',deduction:'0.00',advance:'0.00',net:'0.00'};render();verifyTotal('0.00');
console.log('PASS: actual eight-card render, exact currency total aligned under net on page two and empty results; branch wallet/profile controls retained');
'''
    subprocess.run(['node', '-e', program], check=True)


with tempfile.TemporaryDirectory(prefix='employee-net-total-tests-') as folder:
    stage = Path(folder)
    for relative, transform in profile_specs():
        path = stage / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        source = (ROOT / relative).read_text()
        if relative.endswith('BranchPayroll.php'):
            source = bonus_payroll(source)
        elif relative.endswith('branch-operations.js'):
            source = bonus_javascript(source)
        path.write_text(transform(source))
    for relative, transform in wallet_specs():
        path = stage / relative
        path.write_text(transform(path.read_text()))
    for relative, transform in net_specs():
        path = stage / relative
        if not path.exists():
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text((ROOT / relative).read_text())
    before = {relative: (stage / relative).read_text() for relative, _ in net_specs()}
    preflight(stage, PHP, net_specs())
    assert all((stage / relative).read_text() == source for relative, source in before.items()), 'Net preflight edited source'
    install(stage, stage / 'net-source-backup', PHP, net_specs())
    after = {relative: (stage / relative).read_text() for relative, _ in net_specs()}
    for relative, transform in net_specs():
        assert (stage / 'net-source-backup' / relative).read_text() == before[relative], 'Source backup not exact'
        assert transform(after[relative]) == after[relative], 'Net patch not idempotent: ' + relative
    payroll_path = 'app/Services/Dashboard/BranchPayroll.php'
    listing = '    public function listing(array $values,$actor,bool $export=false): array'
    assert before[payroll_path].split(listing)[0] == after[payroll_path].split(listing)[0], 'Net patch changed period, wallet/profile or ledger methods'
    js_path = 'public/dashboard/js/branch-operations.js'
    for start, end in [('function edit(item){', 'function attendanceCell(item){'), ('function moneyCell(item,kind){', 'function renderEmployees(target){')]:
        if end not in before[js_path][before[js_path].index(start):]:
            end = 'function employeeSchedule(item){'
        assert before[js_path][before[js_path].index(start):before[js_path].index(end, before[js_path].index(start))] == after[js_path][after[js_path].index(start):after[js_path].index(end, after[js_path].index(start))], 'Net patch changed employee editor or owner-only bonus controls'
    subprocess.run([PHP, str(ROOT / 'tests/employee_net_total_test.php'), AUTOLOAD, str(stage)], check=True)
    subprocess.run(['node', '--check', str(stage / js_path)], check=True)
    test_ui(after[js_path])
    print('PASS: composed wallet/profile/owner-bonus policies and period calculation preserved; exact backup, read-only preflight and idempotent net patch')
