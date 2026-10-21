<?php
/** Real shift service and existing SQLite accounting fixture; no live DB or printer. */
require __DIR__.'/shift_pending_invoices_test.php';
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
Carbon::setTestNow(Carbon::parse('2026-10-20T13:00:00Z'));
function expenseFixture($id,$branch='f:100',$status='pending'){
 DB::table('branch_expenses')->insert(['id'=>$id,'branch'=>$branch,'actor_id'=>10,'occurred_on'=>'2026-09-01','category'=>'other','description'=>'مصروف اختبار '.$id,'amount_cents'=>2500,'payment_method'=>'cash','status'=>$status,'revision'=>1,'created_at'=>'2026-09-01 10:00:00','updated_at'=>'2026-09-01 10:00:00']);
}
$expenseMessage='لا يمكن إغلاق الوردية قبل تسوية المصروفات المعلقة باعتمادها أو رفضها.';
$clean=closePayload($service,200);
expenseFixture(900);expenseFixture(901,'f:101');expenseFixture(902,'f:100','approved');expenseFixture(903,'f:100','rejected');expenseFixture(904,'f:100','voided');
$data=$service->data(['branch'=>'f:100'],actor());$report=$data['report'];
check($report['pending_expenses']===1&&count($report['pending_expense_items'])===1,'selected branch shows only pending expenses, including previous-month unresolved expenses');
check($report['pending_expense_items'][0]['number']==='EXP-000900'&&$report['pending_expense_items'][0]['occurred_on']==='2026-09-01'&&$report['pending_expense_items'][0]['branch_name']==='الفرع الأول','pending expense carries actionable identifiers, original date and branch');
check(str_contains($report['pending_expenses_url'],'branch-expenses.index')&&str_contains($report['pending_expenses_url'],'status=pending')&&str_contains($report['pending_expenses_url'],'branch=f%3A100'),'expense action uses existing pending expense workflow for the selected branch');
check(privateFieldsAbsent($report)&&!isset($report['pending_expense_items'][0]['amount_cents']),'pending preview retains blind cash policy and omits expense monetary totals');
$before=financialState();denied(fn()=>$service->close($clean,actor()),409,$expenseMessage);
check(financialState()===$before,'a new pending expense after clean review blocks direct API close without report, source, till or printer writes');
$all=$service->data(['branch'=>'all'],actor());
check($all['report']['pending_expenses']===2&&count($all['report']['pending_expense_items'])===2,'aggregate preview combines pending expenses from each accessible branch once');
check(!isset($all['review_token'])&&privateFieldsAbsent($all['report']),'aggregate expense preview remains read only and blind');
DB::table('branch_expenses')->where('id',900)->update(['status'=>'approved']);
$resolved=closePayload($service,201);$result=$service->close($resolved,actor());
check(!$result['replayed'],'approved, rejected and voided expenses do not block an otherwise clear selected branch');
check(DB::table('branch_expenses')->where('id',901)->value('status')==='pending','another branch pending expense is neither settled nor changed by closure');
expenseFixture(905);$afterNewPending=financialState();$replay=$service->close($resolved,actor());
check($replay['replayed']&&financialState()===$afterNewPending,'successful-key retries replay safely even after a later pending expense appears');
DB::table('branch_expenses')->where('id',905)->update(['status'=>'rejected']);
$rejected=closePayload($service,202);$result=$service->close($rejected,actor());
check(!$result['replayed']&&$service->data(['branch'=>'f:100'],actor())['report']['pending_expenses']===0,'rejecting the last pending expense releases the server close guard');
$source=file_get_contents(dirname(__DIR__).'/app/Services/Dashboard/BranchShiftClosing.php');
check(strpos($source,"\$this->pendingExpenses(\$branch,true)")<strpos($source,"DB::table('takeaway_tills')->insertOrIgnore"),'expense guard is a locking read before any closing till mutation');
$expensesSource=file_get_contents(dirname(__DIR__).'/app/Services/Dashboard/BranchExpenses.php');
check(substr_count($expensesSource,"\$this->access->branch(\$v['branch'],\$actor,true)")>=2,'expense creation and approval/rejection retain the same branch writer lock used by close');
// Render the real content section with only its existing if/foreach/escaped-value directives.
$boot=['can_all'=>true,'selected_branch'=>'f:100','branches'=>[['value'=>'f:100','name'=>'الفرع الأول']]];
$blade=file_get_contents(dirname(__DIR__).'/resources/views/admin/branch_shifts/index.blade.php');
$blade=explode("@section('content')",$blade,2)[1];$blade=explode('@endsection',$blade,2)[0];
foreach(['foreach','if','elseif'] as $directive)$blade=preg_replace_callback('/@'.$directive.'(\((?:[^()]++|(?1))*\))/s',fn($m)=>'<?php '.$directive.' '.$m[1].': ?>',$blade);
$blade=str_replace(['@endforeach','@endif','@else'],['<?php endforeach; ?>','<?php endif; ?>','<?php else: ?>'],$blade);
$blade=preg_replace('/{{\s*(.*?)\s*}}/s','<?= htmlspecialchars((string)($1), ENT_QUOTES, "UTF-8") ?>',$blade);
ob_start();eval('?>'.$blade);$html=ob_get_clean();
check(str_contains($html,'الفواتير المعلقة')&&str_contains($html,'المصروفات المعلقة')&&str_contains($html,'data-sh-expense-total'),'real shift template renders both explicit pending headings');
check(str_contains($html,'<details data-sh-expense-details hidden>')&&str_contains($html,'data-sh-form')&&substr_count($html,'data-sh-expense-items')===1,'compact expandable pending expense details preserve the blind cash closing form');
Carbon::setTestNow();echo 'SHIFT_PENDING_EXPENSES_OK checks='.$checks.PHP_EOL;
