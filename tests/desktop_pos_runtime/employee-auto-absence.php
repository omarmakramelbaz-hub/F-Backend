<?php
// Disposable SQLite only. Exercise the real scheduled service after all payroll regressions.
use App\Services\Dashboard\{BranchOperations,EmployeeAutomaticAbsence};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/employee-pay-hours.php';

foreach([[14,104],[15,105]] as [$userId,$branchId]){
    DB::table('users')->insert(['id'=>$userId,'name'=>'Automatic absence actor '.$userId,'account_type'=>'vendor']);
    DB::table('resturants')->insert(['id'=>$branchId,'user_id'=>$userId,'name'=>'Automatic absence branch '.$branchId]);
}
// Completed regression fixtures must not become unrelated candidates as the test clock moves.
DB::table('branch_employees')->update(['active'=>false]);
clock('2026-11-15 18:00:00');
foreach(['f:104','f:105'] as $branch){
    $s->saveAttendanceRules(['branch'=>$branch,'morning_start'=>'09:00','morning_end'=>'19:00',
        'morning_late'=>'10.00','morning_early'=>'5.00','evening_start'=>'15:00','evening_end'=>'01:00',
        'evening_late'=>'10.00','evening_early'=>'5.00','absence'=>'300.00','idempotency_key'=>attendanceKey()],$owner);
}
function autoEmployee(string $name,array $changes=[]): int {
    return payEmployee($name,array_replace(['branch'=>'f:104','schedule_type'=>'branch','shift'=>'صباحي',
        'effective_month'=>'2026-11','hired_on'=>'2026-11-01'],$changes));
}
function autoOnly(array $ids): void {
    DB::table('branch_employees')->whereIn('branch',['f:104','f:105'])->update(['active'=>false]);
    if($ids)DB::table('branch_employees')->whereIn('id',$ids)->update(['active'=>true]);
}
function autoDay(int $id,string $day='2026-11-15'): ?object {
    return DB::table('branch_employee_days')->where('employee_id',$id)->where('day',$day)->first();
}
function autoEntries(int $id): array {
    return DB::table('branch_employee_entries')->where('employee_id',$id)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
}
function autoDatabaseState(): array {
    $result=[];
    foreach(DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name") as $table){
        $result[$table->name]=DB::table($table->name)->orderBy('rowid')->get()->map(fn($r)=>(array)$r)->all();
    }
    return $result;
}
function autoRecorded(int $id,string $status,string $day='2026-11-15',array $values=[]): void {
    $branch=DB::table('branch_employees')->where('id',$id)->value('branch');
    DB::table('branch_employee_days')->insert(array_replace(['branch'=>$branch,'employee_id'=>$id,
        'day'=>$day,'status'=>$status,'notes'=>'Previously recorded '.$status,'revision'=>3,'actor_id'=>1,
        'created_at'=>now('UTC'),'updated_at'=>now('UTC')],$values));
}

$activationDirectory=sys_get_temp_dir().'/employee-auto-absence-test-'.bin2hex(random_bytes(8));
if(!mkdir($activationDirectory,0700))throw new RuntimeException('Cannot create private activation fixture.');
$activationPath=$activationDirectory.'/activation.json';
$installLockPath=$activationDirectory.'/install.lock';
file_put_contents($installLockPath,'');chmod($installLockPath,0600);
file_put_contents($activationPath,json_encode(['starts_on'=>'2026-11-15']));chmod($activationPath,0600);
register_shutdown_function(function()use($activationDirectory){
    foreach(glob($activationDirectory.'/*')?:[] as $path)unlink($path);
    rmdir($activationDirectory);
});
$operations=app(BranchOperations::class);
$automatic=new EmployeeAutomaticAbsence($operations,$activationPath);
check($automatic->ready(),'valid private activation date and installed payroll schema make the service ready');
$activationLate=autoEmployee('Automatic activation prior late shift',['schedule_type'=>'part_time','attendance_start'=>'23:00','attendance_end'=>'08:00','attendance_shift'=>'evening']);
autoOnly([$activationLate]);clock('2026-11-15 08:00:00');$beforeActivation=autoDatabaseState();$activationRun=$automatic->run();
check($activationRun['recorded']===0&&autoDatabaseState()===$beforeActivation&&!autoDay($activationLate,'2026-11-14'),'activation never manufactures absence for a late prior-day shift before its installation operating day');
clock('2026-11-15 18:00:00');
$missing=autoEmployee('Automatic missing attendance');
$notes=autoEmployee('Automatic notes only');
$s->dailyNotes(['branch'=>'f:104','employee_id'=>$notes,'day'=>'2026-11-15','notes'=>'ملاحظة محفوظة قبل اختيار الحالة','idempotency_key'=>attendanceKey()],$owner);
$notesBefore=(array)autoDay($notes);
autoOnly([$missing,$notes]);
clock('2026-11-15 21:59:59');
$beforeDeadline=autoDatabaseState();$early=$automatic->run();
check($early['ready']&&$early['recorded']===0&&autoDatabaseState()===$beforeDeadline,'21:59:59 Cairo cannot create absence or any audit mutation');
clock('2026-11-15 22:00:00');
$beforeDryRun=autoDatabaseState();$dryRun=$automatic->run(true);
check($dryRun['ready']&&$dryRun['considered']>=2&&$dryRun['recorded']===2&&autoDatabaseState()===$beforeDryRun,'dry run identifies due employees while every database table and sequence remains unchanged');
$firstRun=$automatic->run();$missingDay=autoDay($missing);$notesDay=autoDay($notes);
check($firstRun['ready']&&$firstRun['recorded']===2&&$firstRun['errors']===0,'22:00 Cairo records the two eligible employees without opening a dashboard page');
check($missingDay&&$missingDay->status==='unauthorized_absence'&&$missingDay->day==='2026-11-15','unrecorded employee receives unauthorized absence for the current operating day');
check($missingDay->checked_in_at===null&&$missingDay->checked_out_at===null&&$missingDay->check_in===null&&$missingDay->check_out===null,'automatic absence never fabricates attendance punches');
check($notesDay->status==='unauthorized_absence'&&$notesDay->notes===$notesBefore['notes']&&(int)$notesDay->id===$notesBefore['id']&&(int)$notesDay->revision===$notesBefore['revision']+1,'notes-only attendance converts once while preserving its identity and notes');
$absenceEntries=autoEntries($missing);$automaticSnapshot=json_decode($missingDay->attendance_rule_snapshot,true);
check(count($absenceEntries)===1&&$absenceEntries[0]['source_key']==='attendance.absence'&&(int)$absenceEntries[0]['amount_cents']===30000&&(int)$absenceEntries[0]['actor_id']===1&&str_contains($absenceEntries[0]['notes'],'تسجيل تلقائي'),'automatic absence creates one Owner-attributed deduction using the configured 300 pound amount');
check((int)$missingDay->actor_id===1&&($automaticSnapshot['absence_cents']??null)===30000,'absence keeps the applied branch rule snapshot and persisted Owner actor');
$missingStatement=payStatement($missing,'2026-11');
check($missingStatement['deduction']==='300.00'&&$missingStatement['net']==='1250.00','automatic absence flows once into accrued payroll using the month daily wage');
$onceState=autoDatabaseState();$repeated=(new EmployeeAutomaticAbsence($operations,$activationPath))->run();
check($repeated['recorded']===0&&autoDatabaseState()===$onceState,'another worker replay preserves attendance revisions all ledger rows and command receipts');
$entry=(object)$absenceEntries[0];
$s->voidEntry(['branch'=>'f:104','entry_id'=>$entry->id,'expected_revision'=>$entry->revision,'reason'=>'إلغاء خصم الغياب بمعرفة الأونر','idempotency_key'=>attendanceKey()],$owner);
$voidState=autoDatabaseState();$automatic->run();
check(autoDatabaseState()===$voidState&&total($missing)===0,'automatic rerun never revives an absence penalty explicitly cancelled by the Owner');

// Any saved status is authoritative, including older attendance vocabulary.
$recordedIds=[];
foreach(['morning','evening','part_time','preapproved_leave','unauthorized_absence','present','absent','paid_leave','unpaid_leave','off'] as $status){
    $id=autoEmployee('Automatic preserve '.$status);$recordedIds[]=$id;autoRecorded($id,$status);
}
autoOnly($recordedIds);$recordedState=autoDatabaseState();$preserved=$automatic->run();
check($preserved['recorded']===0&&autoDatabaseState()===$recordedState,'morning evening part-time leave absence and all legacy recorded statuses remain byte-for-byte unchanged');

// A preview does not reserve attendance against a later manual action or employment edit.
$racedStatus=autoEmployee('Automatic manual status after preview');
$racedActive=autoEmployee('Automatic deactivated after preview');
autoOnly([$racedStatus,$racedActive]);$racePreview=$automatic->run(true);
check($racePreview['recorded']===2,'a dry run can preview two current candidates without reserving their status');
payMark($racedStatus,'morning','check_in','2026-11-15');
DB::table('branch_employees')->where('id',$racedActive)->update(['active'=>false]);
$afterManualState=autoDatabaseState();$afterManualRun=$automatic->run();
check($afterManualRun['recorded']===0&&autoDatabaseState()===$afterManualState&&autoDay($racedStatus)->status==='morning'&&!autoDay($racedActive),'manual attendance and deactivation after a preview are rechecked before any automatic write');
$punchProtected=[];
foreach(['check_in'=>'09:00:00','check_out'=>'19:00:00','checked_in_at'=>'2026-11-15 07:00:00','checked_out_at'=>'2026-11-15 17:00:00'] as $field=>$value){
    $id=autoEmployee('Automatic preserve unrecorded '.$field);$punchProtected[]=$id;autoRecorded($id,'unrecorded','2026-11-15',[$field=>$value]);
}
autoOnly($punchProtected);$punchState=autoDatabaseState();$punchRun=$automatic->run();
check($punchRun['recorded']===0&&autoDatabaseState()===$punchState,'an inconsistent unrecorded row with any saved local or UTC punch is preserved for review');
$partSalary=autoEmployee('Automatic part-time payroll',['schedule_type'=>'part_time','attendance_start'=>'18:00','attendance_end'=>'23:00','attendance_shift'=>'evening']);
autoOnly([$partSalary]);$partRun=$automatic->run();$partSalaryStatement=payStatement($partSalary,'2026-11');
check($partRun['recorded']===1&&$partSalaryStatement['hours_deduction']==='775.00'&&$partSalaryStatement['deduction']==='300.00'&&$partSalaryStatement['net']==='475.00','five-hour payroll keeps the planned-hours reduction and one fixed Owner absence deduction separate');

// Candidate filtering is based on active employment dates and each employee closed period.
$inactive=autoEmployee('Automatic inactive',['active'=>false]);
$futureHire=autoEmployee('Automatic future hire',['hired_on'=>'2026-11-16']);
$departed=autoEmployee('Automatic departed',['left_on'=>'2026-11-14']);
$leavesToday=autoEmployee('Automatic departure today',['left_on'=>'2026-11-15']);
$closed=autoEmployee('Automatic closed payroll');
$closedPreview=payStatement($closed,'2026-11');
$s->close(['branch'=>'f:104','employee_id'=>$closed,'month'=>'2026-11','preview_hash'=>$closedPreview['preview_hash'],'idempotency_key'=>attendanceKey()],$owner);
$closedRow=(array)DB::table('branch_payrolls')->where('employee_id',$closed)->first();
DB::table('branch_attendance_rules')->where('branch','f:105')->update(['absence_cents'=>12500]);
$otherBranch=autoEmployee('Automatic other branch',['branch'=>'f:105']);
autoOnly([$futureHire,$departed,$leavesToday,$closed,$otherBranch]);
$employmentRun=$automatic->run();
check($employmentRun['recorded']===2&&autoDay($leavesToday)&&autoDay($otherBranch),'the final employment day and an independent configured branch both receive absence');
check(!autoDay($inactive)&&!autoDay($futureHire)&&!autoDay($departed)&&!autoDay($closed),'inactive future-hired departed and payroll-closed employees are excluded');
check(total($otherBranch)===12500&&total($leavesToday)===30000,'each branch uses its own Owner-configured absence amount during the same scheduled run');
DB::table('branch_attendance_rules')->where('branch','f:105')->update(['absence_cents'=>30000]);
check((array)DB::table('branch_payrolls')->where('employee_id',$closed)->first()===$closedRow&&autoEntries($closed)===[],'automatic absence never modifies a closed payroll snapshot or its ledger');

$noRules=autoEmployee('Automatic no branch rules',['branch'=>'f:105']);
autoOnly([$noRules]);$savedRules=DB::table('branch_attendance_rules')->where('branch','f:105')->get()->map(fn($r)=>(array)$r)->all();
DB::table('branch_attendance_rules')->where('branch','f:105')->delete();
$withoutRules=autoDatabaseState();$noRulesRun=$automatic->run();
check($noRulesRun['errors']>0&&!autoDay($noRules)&&autoEntries($noRules)===[]&&autoDatabaseState()===$withoutRules,'a branch without Owner attendance rules is reported and cannot create partial absence or deduction');
DB::table('branch_attendance_rules')->insert($savedRules);
DB::table('branch_attendance_rules')->where('branch','f:105')->update(['absence_cents'=>0]);
$zeroPenalty=autoEmployee('Automatic zero penalty',['branch'=>'f:105']);autoOnly([$zeroPenalty]);
$zeroRun=$automatic->run();
check($zeroRun['recorded']===1&&autoDay($zeroPenalty)->status==='unauthorized_absence'&&autoEntries($zeroPenalty)===[],'a zero Owner absence amount still records absence without creating a zero-value ledger entry');
DB::table('branch_attendance_rules')->where('branch','f:105')->update(['absence_cents'=>30000]);

$invalidLedger=autoEmployee('Automatic invalid preexisting source');autoOnly([$invalidLedger]);
DB::table('branch_employee_entries')->insert(['branch'=>'f:104','employee_id'=>$invalidLedger,'day'=>'2026-11-15',
    'kind'=>'bonus','source_key'=>'attendance.absence','amount_cents'=>30000,'reason'=>'Invalid old ledger source',
    'notes'=>'Must not be overwritten by scheduler','revision'=>1,'actor_id'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$invalidLedgerState=autoDatabaseState();$invalidLedgerRun=$automatic->run();
check($invalidLedgerRun['recorded']===0&&$invalidLedgerRun['errors']>0&&!autoDay($invalidLedger)&&autoDatabaseState()===$invalidLedgerState,'a conflicting preexisting ledger source rolls back attendance and operation receipt atomically');

// A late custom shift gets its real scheduled end, including the next operating day.
$atTen=autoEmployee('Automatic 22 to 02',['schedule_type'=>'part_time','attendance_start'=>'22:00','attendance_end'=>'02:00','attendance_shift'=>'evening']);
$lateNight=autoEmployee('Automatic 23 to 03',['schedule_type'=>'part_time','attendance_start'=>'23:00','attendance_end'=>'03:00','attendance_shift'=>'evening']);
$afterMidnight=autoEmployee('Automatic 01 to 05',['schedule_type'=>'part_time','attendance_start'=>'01:00','attendance_end'=>'05:00','attendance_shift'=>'evening']);
$pastSix=autoEmployee('Automatic 23 to 08',['schedule_type'=>'part_time','attendance_start'=>'23:00','attendance_end'=>'08:00','attendance_shift'=>'evening']);
autoOnly([$atTen,$lateNight,$afterMidnight,$pastSix]);$nightBefore=autoDatabaseState();$automatic->run();
check(!autoDay($atTen)&&!autoDay($lateNight)&&!autoDay($afterMidnight)&&!autoDay($pastSix)&&autoDatabaseState()===$nightBefore,'22:00 does not mark custom shifts starting at 22:00 or later absent before their scheduled end');
clock('2026-11-16 02:00:00');$two=$automatic->run();
check($two['recorded']===1&&autoDay($atTen)->day==='2026-11-15'&&!autoDay($lateNight),'a shift starting exactly at the standard deadline waits until its own two AM end');
clock('2026-11-16 02:59:59');$automatic->run();
check(!autoDay($lateNight)&&!autoDay($afterMidnight)&&!autoDay($pastSix),'late custom shifts remain unrecorded one second before the first scheduled end');
clock('2026-11-16 03:00:00');$three=$automatic->run();
check($three['recorded']===1&&autoDay($lateNight)->status==='unauthorized_absence'&&!autoDay($afterMidnight)&&!autoDay($pastSix),'23:00 to 03:00 becomes absent at its exact end on the preceding operating day');
clock('2026-11-16 05:00:00');$five=$automatic->run();
check($five['recorded']===1&&autoDay($afterMidnight)->day==='2026-11-15'&&!autoDay($pastSix),'01:00 to 05:00 becomes absent at its end and keeps the previous operating-day attribution');
$previousNormal=autoEmployee('Automatic previous normal no backfill');
autoOnly([$pastSix,$previousNormal]);
clock('2026-11-16 06:00:00');$six=$automatic->run();
check($six['recorded']===0&&!autoDay($pastSix)&&!autoDay($previousNormal,'2026-11-15')&&!autoDay($previousNormal,'2026-11-16'),'six AM rollover never backfills normal missing attendance or ends a custom shift still running');
clock('2026-11-16 07:59:59');$automatic->run();check(!autoDay($pastSix),'a custom shift ending after six is not absent one second before its scheduled end');
clock('2026-11-16 08:00:00');$eight=$automatic->run();
check($eight['recorded']===1&&autoDay($pastSix)->day==='2026-11-15'&&!autoDay($pastSix,'2026-11-16'),'a 23:00 to 08:00 shift becomes absent at eight against its own prior operating day only');
check(!autoDay($previousNormal,'2026-11-15')&&!autoDay($previousNormal,'2026-11-16'),'the late-shift recovery path cannot backfill an unrelated normal employee');

// UTC clocks and month boundaries are always interpreted using Cairo wall time.
$monthEnd=autoEmployee('Automatic month end',['effective_month'=>'2026-12','hired_on'=>'2026-12-31']);autoOnly([$monthEnd]);
Carbon::setTestNow(Carbon::parse('2026-12-31 19:59:59','UTC'));$automatic->run();check(!autoDay($monthEnd,'2026-12-31'),'winter 19:59:59 UTC is still before the Cairo ten PM deadline');
Carbon::setTestNow(Carbon::parse('2026-12-31 20:00:00','UTC'));$monthEndRun=$automatic->run();
check($monthEndRun['recorded']===1&&autoDay($monthEnd,'2026-12-31')->day==='2026-12-31','winter 20:00 UTC triggers Cairo ten PM for the December operating day');
clock('2027-01-01 00:01:00');$midnightState=autoDatabaseState();$automatic->run();
check(autoDatabaseState()===$midnightState&&!autoDay($monthEnd,'2027-01-01'),'calendar midnight cannot duplicate month-end absence into a new operating day');
$midnightMissing=autoEmployee('Automatic normal midnight catchup',['effective_month'=>'2026-12','hired_on'=>'2026-12-31']);autoOnly([$monthEnd,$midnightMissing]);
$midnightCatchup=$automatic->run();
check($midnightCatchup['recorded']===1&&autoDay($midnightMissing,'2026-12-31')->day==='2026-12-31'&&!autoDay($midnightMissing,'2027-01-01'),'an overdue normal employee caught after midnight belongs to the still-current December operating day');
clock('2027-01-01 05:59:59');$automatic->run();check(!autoDay($monthEnd,'2027-01-01'),'the December absence remains on December through 05:59:59 in January');
clock('2027-01-01 06:00:00');$automatic->run();check(!autoDay($monthEnd,'2027-01-01'),'the new month at six cannot record absence before its own deadline');
$summer=autoEmployee('Automatic summer UTC',['effective_month'=>'2027-07','hired_on'=>'2027-07-01']);autoOnly([$summer]);
Carbon::setTestNow(Carbon::parse('2027-07-01 18:59:59','UTC'));$automatic->run();check(!autoDay($summer,'2027-07-01'),'summer 18:59:59 UTC is one second before ten PM in Cairo');
Carbon::setTestNow(Carbon::parse('2027-07-01 19:00:00','UTC'));$summerRun=$automatic->run();
check($summerRun['recorded']===1&&autoDay($summer,'2027-07-01')->day==='2027-07-01','summer daylight saving uses Cairo ten PM rather than a fixed UTC hour');

// Readiness failure must be global and side-effect free, even with otherwise due candidates.
$invalidActor=autoEmployee('Automatic invalid Owner',['effective_month'=>'2027-07','hired_on'=>'2027-07-01']);autoOnly([$invalidActor]);
DB::table('users')->where('id',1)->update(['account_type'=>'vendor']);$invalidActorState=autoDatabaseState();
$notReady=$automatic->run();
check($notReady['errors']===1&&$notReady['recorded']===0&&autoDatabaseState()===$invalidActorState,'an invalid persisted Owner reports an error without mutating any table');
DB::table('users')->where('id',1)->update(['account_type'=>'admin']);
// The installer holds an exclusive lock from replacement through verification and rollback.
$installerLock=fopen($installLockPath,'r+');
if(!$installerLock||!flock($installerLock,LOCK_EX|LOCK_NB))throw new RuntimeException('Cannot hold installer lock fixture.');
try{
    $beforeInstallerLock=autoDatabaseState();$lockStarted=microtime(true);$lockedJob=$automatic->run();
    check($lockedJob['ready']&&$lockedJob['considered']===0&&$lockedJob['recorded']===0&&$lockedJob['skipped']===1&&$lockedJob['errors']===0&&microtime(true)-$lockStarted<2&&autoDatabaseState()===$beforeInstallerLock,'a real worker exits promptly without any mutation while the installer holds its exclusive lock');
    $lockedPreview=$automatic->run(true);
    check($lockedPreview['ready']&&$lockedPreview['recorded']===1&&autoDatabaseState()===$beforeInstallerLock,'installer verification can preview due absence while the exclusive lock still prevents real workers');
}finally{
    flock($installerLock,LOCK_UN);fclose($installerLock);
}
$afterInstaller=$automatic->run();
check($afterInstaller['recorded']===1&&autoDay($invalidActor,'2027-07-01')->status==='unauthorized_absence','the real worker records due absence after the installer releases its exclusive lock');
$missingLockEmployee=autoEmployee('Automatic missing installer lock',['effective_month'=>'2027-07','hired_on'=>'2027-07-01']);autoOnly([$missingLockEmployee]);
$missingLockState=autoDatabaseState();unlink($installLockPath);$missingLock=$automatic->run();
check(!$missingLock['ready']&&$missingLock['errors']===1&&$missingLock['recorded']===0&&!file_exists($installLockPath)&&autoDatabaseState()===$missingLockState,'a missing private installer lock fails closed without creating a lock file or any database writes');
symlink($activationPath,$installLockPath);$linkedLock=$automatic->run();
check(!$linkedLock['ready']&&$linkedLock['errors']===1&&$linkedLock['recorded']===0&&is_link($installLockPath)&&autoDatabaseState()===$missingLockState,'a symlink installer lock fails closed without following its target or changing attendance');
unlink($installLockPath);file_put_contents($installLockPath,'');chmod($installLockPath,0644);$publicLock=$automatic->run();
check(!$publicLock['ready']&&$publicLock['errors']===1&&$publicLock['recorded']===0&&autoDatabaseState()===$missingLockState,'a nonprivate installer lock fails closed without authorizing a worker');
chmod($installLockPath,0600);
$activationState=autoDatabaseState();
foreach(['missing','malformed','impossible_date','oversized','symlink'] as $kind){
    $invalidPath=$activationDirectory.'/'.$kind.'.json';
    if($kind==='malformed')file_put_contents($invalidPath,'{not json');
    if($kind==='impossible_date')file_put_contents($invalidPath,'{"starts_on":"2026-02-30"}');
    if($kind==='oversized')file_put_contents($invalidPath,str_repeat(' ',300).'{"starts_on":"2026-11-15"}');
    if($kind==='symlink')symlink($activationPath,$invalidPath);
    $unactivated=new EmployeeAutomaticAbsence($operations,$invalidPath);$unactivatedRun=$unactivated->run();
    check(!$unactivated->ready()&&!$unactivatedRun['ready']&&$unactivatedRun['recorded']===0&&autoDatabaseState()===$activationState,'a '.$kind.' activation marker cannot authorize any attendance or ledger writes');
}
file_put_contents($activationPath,json_encode(['starts_on'=>'2027-07-02']));
$futureActivation=$automatic->run();
check($futureActivation['recorded']===0&&autoDatabaseState()===$activationState,'a future activation date cannot authorize absences for earlier operating days');
Carbon::setTestNow();
echo $count.' attendance, pay-hours and automatic absence checks passed'.PHP_EOL;
