<?php
// Real local journal rollback, immutable file retry and mapped server reconciliation.
use Illuminate\Http\{Request,UploadedFile};
use Illuminate\Support\Facades\{DB,Storage};
use App\Models\User;
use App\Services\Dashboard\{BranchExpenses,DesktopDashboardExpenseAttachments,DesktopDashboardJournal,DesktopDashboardReconciliation};

config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.device_id'=>$phoneDevice,'desktop_dashboard.local'=>true]);DB::purge();
$expenseActor=User::withoutGlobalScopes()->findOrFail(1);
$attachmentBytes="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$attachmentSource=$profile.'/private/attachment-fixture.pdf';file_put_contents($attachmentSource,$attachmentBytes);
$expenseInput=['branch'=>'f:100','idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),'occurred_on'=>\App\Services\Dashboard\OperatingDay::date(),
    'category'=>'purchases','description'=>'مصروف ومرفق أثناء الانقطاع','amount'=>'9.00','payment_method'=>'cash','approve'=>true];
$upload=new UploadedFile($attachmentSource,'فاتورة مشتريات.pdf','application/pdf',null,true);
$attachments=app(DesktopDashboardExpenseAttachments::class);
$request=Request::create('/admin/branch-expenses/save','POST',$expenseInput,[],['attachment'=>$upload]);
$expenseFiles=$attachments->files($request,'branch-expenses.save');
$expensePayload=['values'=>$expenseInput,'parameters'=>[],'files'=>$expenseFiles];
$saveAttachment=fn()=>app(DesktopDashboardJournal::class)->execute($phoneDevice,$expenseInput['idempotency_key'],1,'branch-expenses.save',$expensePayload,[],
    fn()=>app(BranchExpenses::class)->save($expenseInput,$expenseActor,$upload));
$expenseCount=DB::table('branch_expenses')->count();$expenseCash=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
DB::statement("CREATE TRIGGER reject_attachment_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.route_name='branch-expenses.save' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated attachment journal failure'; END IF; END");
try{
    try{$saveAttachment();throw new RuntimeException('Expected outer journal persistence failure.');}
    catch(\Illuminate\Database\QueryException $error){check(str_contains($error->getMessage(),'simulated attachment journal failure'),'a real journal failure rolls back after the original attachment service has returned');}
}finally{DB::statement('DROP TRIGGER reject_attachment_journal');}
check(DB::table('branch_expenses')->count()===$expenseCount&&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$expenseCash
    &&!DB::table('desktop_dashboard_commands')->where('command_id',$expenseInput['idempotency_key'])->exists(),
    'failed attachment journaling commits neither expense nor drawer deduction nor a success command');
$filesAfterFailure=Storage::disk('local')->allFiles('branch-expenses/desktop');
check(count($filesAfterFailure)===1&&Storage::disk('local')->get($filesAfterFailure[0])===$attachmentBytes,
    'a rolled-back outer transaction retains one immutable private file for retry');
$attachmentResult=$saveAttachment();$path=DB::table('branch_expenses')->where('id',$attachmentResult['expense']['id'])->value('attachment_path');
check(Storage::disk('local')->get($path)===$attachmentBytes&&count(Storage::disk('local')->allFiles('branch-expenses/desktop'))===1
    &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$expenseCash-900,
    'retry reuses the complete file and commits one original expense and cash deduction');
check($saveAttachment()===$attachmentResult&&DB::table('branch_expenses')->count()===$expenseCount+1,
    'losing the local expense reply preserves its exact result and cannot duplicate an attachment expense');
$changed=$expensePayload;$changed['files']['attachment']['base64']=base64_encode($attachmentBytes.'changed');
denied(fn()=>app(DesktopDashboardJournal::class)->execute($phoneDevice,$expenseInput['idempotency_key'],1,'branch-expenses.save',$changed,[],fn()=>[]),409,
    'changed attachment bytes cannot reuse a committed local expense UUID');
$diskPath=Storage::disk('local')->path($path);unlink($diskPath);
denied($saveAttachment,503,'a saved local reply cannot claim success with a missing private attachment');
file_put_contents($diskPath,$attachmentBytes);
$envelope=app(DesktopDashboardJournal::class)->pending($phoneDevice)[0];
check($envelope['command_id']===$expenseInput['idempotency_key']&&$envelope['payload']['files']===$expenseFiles,
    'the encrypted outbox retains complete attachment bytes and the original Arabic filename');
config(['database.connections.mysql.database'=>$phoneServer,'desktop_dashboard.local'=>false]);DB::purge();
// This fixture shares a physical storage root; remove the local copy to prove
// the server reconstructs the attachment from the encrypted wire payload.
unlink($diskPath);
$serverExpenseCount=DB::table('branch_expenses')->count();$serverCash=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
$invalidFile=$envelope;$invalidFile['command_id']=(string)\Illuminate\Support\Str::uuid();$invalidFile['payload']['values']['idempotency_key']=$invalidFile['command_id'];
$invalidFile['payload']['files']['attachment']['mime']='image/png';
denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$invalidFile),422,'reconciliation checks the actual file type rather than trusting the uploaded MIME declaration');
$badHash=$invalidFile;$badHash['payload']['files']['attachment']['sha256']=str_repeat('0',64);
denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$badHash),422,'altered base64 content fails its original attachment digest before creating a server expense');
$unknownFile=$envelope;$unknownFile['command_id']=(string)\Illuminate\Support\Str::uuid();$unknownFile['payload']['values']['idempotency_key']=$unknownFile['command_id'];$unknownFile['payload']['files']['other']=$expenseFiles['attachment'];
denied(fn()=>app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$unknownFile),422,'extra unreviewed attachment fields cannot be silently discarded during expense reconciliation');
check(!is_file($diskPath)&&DB::table('branch_expenses')->count()===$serverExpenseCount,
    'rejected uploads leave no server attachment or expense before valid wire reconstruction');
$attachmentReceipt=app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$envelope);
check($attachmentReceipt===app(DesktopDashboardReconciliation::class)->ingest($phoneRemote,$envelope)
    &&DB::table('branch_expenses')->count()===$serverExpenseCount+1
    &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$serverCash-900,
    'lost server replies reconcile one expense and one cash movement with an identical receipt');
$serverAttachment=DB::table('branch_expenses')->where('id',$attachmentReceipt['result']['expense']['id'])->first();
check(Storage::disk('local')->get($serverAttachment->attachment_path)===$attachmentBytes
    &&$serverAttachment->attachment_name==='فاتورة مشتريات.pdf'&&!is_file(storage_path('app/public/'.$serverAttachment->attachment_path)),
    'the reconciled Arabic PDF retains its private bytes and filename without a public storage copy');
config(['database.connections.mysql.database'=>$phoneLocal,'desktop_dashboard.local'=>true]);DB::purge();
app(DesktopDashboardJournal::class)->acknowledge($phoneDevice,$envelope['command_id'],$attachmentReceipt);
check(app(DesktopDashboardJournal::class)->counts($phoneDevice)['pending']===0,'a committed attachment receipt confirms the local command without deleting its file');
config(['database.connections.mysql.database'=>$remoteDatabase,'desktop_dashboard.device_id'=>$device,'desktop_dashboard.local'=>false]);DB::purge();
