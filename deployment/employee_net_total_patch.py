#!/usr/bin/env python3
"""Add the filtered monthly employee net total using the existing payroll ledger."""
import argparse
import json
from pathlib import Path
import sys

sys.path.append(str(Path(__file__).resolve().parent / 'launch_reset'))
from bonus_owner_patch import failure_details, install, preflight


def replace_once(source, old, new, code):
    if source.count(old) != 1:
        raise ValueError(code)
    return source.replace(old, new, 1)


def patch_payroll(source: str) -> str:
    marker = '// Filtered monthly net totals use the same period calculation as every displayed row.'
    begin = source.index('    public function listing(array $values,$actor,bool $export=false): array')
    end = source.index('\n    }\n', begin) + len('\n    }\n')
    method = source[begin:end]
    cache = "$statement=$netPageStatements[$employee->id]??$this->period($employee,$v['month']);"
    summary = "'net'=>Money::decimal($netTotalCents)"
    if marker in source:
        if source.count(marker) != 1 or method.count(marker) != 1 or method.count(cache) != 1 or method.count(summary) != 1:
            raise ValueError('PARTIAL_EMPLOYEE_NET_TOTAL_PAYROLL_PATCH')
        return source
    # Clone the already authorized query after search/job/shift filters. Chunked
    # reads avoid a nested query on an unbuffered PDO cursor and retain every row.
    page = "        $employees=(clone $q)->orderByDesc('active')->orderBy('name')->offset($export?0:($page-1)*30)->limit($export?1000:30)->get();\n"
    total = ("        " + marker + "\n"
             "        $netTotalCents=0;$netPageStatements=[];$netPageIds=array_fill_keys($employees->pluck('id')->all(),true);\n"
             "        (clone $q)->chunkById(100,function($netEmployees)use(&$netTotalCents,&$netPageStatements,$netPageIds,$v){\n"
             "            foreach($netEmployees as $netEmployee){\n"
             "                $netStatement=$this->period($netEmployee,$v['month']);\n"
             "                $netTotalCents+=(int)$netStatement['net_cents'];\n"
             "                if(isset($netPageIds[$netEmployee->id]))$netPageStatements[$netEmployee->id]=$netStatement;\n"
             "            }\n"
             "        });\n")
    method = replace_once(method, page, page + total, 'EMPLOYEE_NET_TOTAL_PAGE_QUERY_CHANGED')
    method = replace_once(method, "$statement=$this->period($employee,$v['month']);", cache, 'EMPLOYEE_NET_TOTAL_ROW_PERIOD_CHANGED')
    advance = "'advance'=>Money::decimal((int)($entrySums['advance']??0))"
    method = replace_once(method, advance, advance + ',' + summary, 'EMPLOYEE_NET_TOTAL_SUMMARY_CHANGED')
    return source[:begin] + method + source[end:]


def patch_javascript(source: str) -> str:
    marker = '/* filtered monthly employee net total */'
    if marker in source:
        if (source.count(marker) != 1 or source.count("net:'إجمالي صافي المستحقات'") != 1
                or source.count("netTotal.dataset.opNetTotal=''") != 1):
            raise ValueError('PARTIAL_EMPLOYEE_NET_TOTAL_UI_PATCH')
        return source
    source = replace_once(source, "advance:'إجمالي السلف'", "advance:'إجمالي السلف',net:'إجمالي صافي المستحقات'", 'EMPLOYEE_NET_TOTAL_CARD_LABEL_CHANGED')
    currency = "['bonus','deduction','advance'].includes(k)?'جنيه':'موظف'"
    context = "k==='net'?'جنيه · شهر '+data.filters.month+' · كل النتائج المطابقة':['bonus','deduction','advance'].includes(k)?'جنيه':'موظف'"
    source = replace_once(source, currency, context, 'EMPLOYEE_NET_TOTAL_CARD_CURRENCY_CHANGED')
    begin = source.index('function renderEmployees(target){')
    finish = source.index('function advanceDay(){', begin)
    employee_view = source[begin:finish]
    empty = "if(!data.items.length){target.appendChild(n('p','لا توجد سجلات مطابقة.','op-empty'));return;}"
    employee_view = replace_once(employee_view, empty,
                                 "if(!data.items.length)target.appendChild(n('p','لا توجد سجلات مطابقة.','op-empty'));",
                                 'EMPLOYEE_NET_TOTAL_EMPTY_STATE_CHANGED')
    source = source[:begin] + employee_view + source[finish:]
    footer = "var end=n('td','الإجماليات حسب اليوم والفلاتر المحددة');end.colSpan=4;tr.appendChild(end);"
    changed = (marker + "var end=n('td','الحركات حسب اليوم · الصافي حسب شهر المستحقات');end.colSpan=2;tr.appendChild(end);"
               "var netTotal=n('td',undefined,'op-net-total');netTotal.dataset.opNetTotal='';"
               "netTotal.appendChild(n('strong',(data.summary.net==null?'—':data.summary.net+' ج')));"
               "netTotal.appendChild(n('small','شهر '+data.filters.month));tr.appendChild(netTotal);"
               "var scope=n('td','كل النتائج المطابقة');scope.title='يشمل جميع الموظفين المطابقين للفلاتر عبر كل الصفحات';tr.appendChild(scope);")
    return replace_once(source, footer, changed, 'EMPLOYEE_NET_TOTAL_FOOTER_CHANGED')


def patch_css(source: str) -> str:
    marker = '/* employee monthly net total */'
    original = '.op-employees .op-cards{grid-template-columns:repeat(7,minmax(0,1fr));gap:12px}'
    changed = '.op-employees .op-cards{grid-template-columns:repeat(8,minmax(0,1fr));gap:12px}'
    if marker in source:
        if source.count(marker) != 1 or source.count(changed) != 1 or original in source:
            raise ValueError('PARTIAL_EMPLOYEE_NET_TOTAL_CSS_PATCH')
        return source
    source = replace_once(source, original, changed, 'EMPLOYEE_NET_TOTAL_DESKTOP_GRID_CHANGED')
    return source + ('\n' + marker + '\n'
                     '.op-employees .op-card-net{color:#175abb;background:#f1f6ff}'
                     '.op-daily-table tfoot td.op-net-total{color:#175abb;background:#f1f6ff;font-variant-numeric:tabular-nums}'
                     '.op-daily-table tfoot td.op-net-total strong{display:block;font-size:15px;white-space:nowrap}\n')


def specs():
    return [('app/Services/Dashboard/BranchPayroll.php', patch_payroll),
            ('public/dashboard/js/branch-operations.js', patch_javascript),
            ('public/dashboard/css/branch-operations.css', patch_css)]


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
            print('EMPLOYEE_NET_TOTAL_PREFLIGHT=' + json.dumps(preflight(options.root, options.php, specs())))
        else:
            print('EMPLOYEE_NET_TOTAL_PATCH=' + json.dumps(install(options.root, options.backup, options.php, specs())))
    except Exception as error:
        raise SystemExit('EMPLOYEE_NET_TOTAL_PATCH_FAILED ' + failure_details(error))
