<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$reference = is_file($root.'/app/Services/Dashboard/OperatingDay.php')
    ? $root.'/app/Services/Dashboard' : $root.'/reference';
require $reference.'/OperatingDay.php';
require $reference.'/EmployeeAttendanceRules.php';
require $root.'/deployment/employee_attendance_nov05/repair.php';

use App\Services\Dashboard\EmployeeAttendanceRules;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$checks = 0;
$temporary = [];
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('TEST_FAILED: '.$message);
    $checks++;
}
function same($expected, $actual, string $message): void
{
    check($expected === $actual, $message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}
function backupDirectory(): string
{
    global $temporary;
    $path = sys_get_temp_dir().'/hr-nov05-test-'.bin2hex(random_bytes(8));
    if (!mkdir($path, 0700)) throw new RuntimeException('Temporary directory failed');
    $temporary[] = $path;
    return $path;
}
function rows($db, string $table): array
{
    return array_map(static fn($row) => (array)$row, $db->table($table)->orderBy('id')->get()->all());
}
function state($db): array
{
    $result = [];
    foreach (['resturants','branch_employees','branch_attendance_rules','branch_employee_days','branch_employee_entries','branch_payrolls'] as $table) {
        $result[$table] = rows($db, $table);
    }
    return $result;
}
function fixture()
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
    $db = $capsule->getConnection();
    $schema = $db->getSchemaBuilder();
    $schema->create('resturants', static function (Blueprint $t) { $t->integer('id')->primary(); });
    $schema->create('branch_employees', static function (Blueprint $t) {
        $t->integer('id')->primary(); $t->string('branch'); $t->boolean('active');
        $t->string('hired_on'); $t->string('left_on')->nullable(); $t->string('shift')->nullable();
        $t->string('schedule_type'); $t->string('attendance_start')->nullable(); $t->string('attendance_end')->nullable();
        $t->string('attendance_shift')->nullable(); $t->integer('revision');
    });
    $schema->create('branch_attendance_rules', static function (Blueprint $t) {
        $t->integer('id')->primary(); $t->string('branch'); $t->string('shift');
        $t->string('starts_at'); $t->string('ends_at'); $t->integer('late_half_hour_cents');
        $t->integer('early_half_hour_cents'); $t->integer('absence_cents'); $t->integer('revision');
        $t->unique(['branch','shift']);
    });
    $schema->create('branch_employee_days', static function (Blueprint $t) {
        $t->increments('id'); $t->string('branch'); $t->integer('employee_id'); $t->string('day'); $t->string('status');
        foreach (['check_in','check_out','checked_in_at','checked_out_at','attendance_rule_snapshot','notes'] as $column) $t->text($column)->nullable();
        $t->integer('revision'); $t->integer('actor_id'); $t->string('created_at'); $t->string('updated_at');
        $t->unique(['employee_id','day']);
    });
    $schema->create('branch_employee_entries', static function (Blueprint $t) {
        $t->increments('id'); $t->string('branch'); $t->integer('employee_id'); $t->string('day'); $t->string('kind');
        $t->integer('amount_cents'); $t->string('reason'); $t->text('notes')->nullable(); $t->string('source_key')->nullable();
        $t->string('voided_at')->nullable(); $t->integer('voided_by')->nullable(); $t->string('void_reason')->nullable();
        $t->integer('revision'); $t->integer('actor_id'); $t->string('created_at'); $t->string('updated_at');
    });
    $schema->create('branch_payrolls', static function (Blueprint $t) {
        $t->increments('id'); $t->integer('employee_id'); $t->string('month'); $t->string('status');
    });
    foreach ([1,2,3] as $id) $db->table('resturants')->insert(['id'=>$id]);
    $employee = ['branch'=>'f:1','active'=>1,'hired_on'=>'2026-10-01','left_on'=>null,'shift'=>'morning',
        'schedule_type'=>'branch','attendance_start'=>null,'attendance_end'=>null,'attendance_shift'=>null,'revision'=>2];
    $employees = [
        ['id'=>1], ['id'=>2,'shift'=>'مسائي'],
        ['id'=>3,'branch'=>'f:2','schedule_type'=>'part_time','attendance_start'=>'01:00:00','attendance_end'=>'04:00:00','attendance_shift'=>'evening'],
        ['id'=>4,'branch'=>'f:2','hired_on'=>'2026-11-03'],
        ['id'=>5,'branch'=>'f:2','active'=>0,'left_on'=>'2026-11-03'],
        ['id'=>6,'branch'=>'f:2','hired_on'=>'2026-11-06'],
        ['id'=>7,'branch'=>'f:2','active'=>0],
        ['id'=>8,'branch'=>'gs:9'], ['id'=>9,'branch'=>'f:3'],
    ];
    foreach ($employees as $item) $db->table('branch_employees')->insert(array_replace($employee, $item));
    $rule = ['late_half_hour_cents'=>100,'early_half_hour_cents'=>200,'absence_cents'=>1000,'revision'=>3];
    foreach ([
        ['id'=>1,'branch'=>'f:1','shift'=>'morning','starts_at'=>'09:00:00','ends_at'=>'18:00:00'],
        ['id'=>2,'branch'=>'f:1','shift'=>'evening','starts_at'=>'20:00:00','ends_at'=>'03:00:00'],
        ['id'=>3,'branch'=>'f:2','shift'=>'morning','starts_at'=>'10:00:00','ends_at'=>'18:00:00'],
        ['id'=>4,'branch'=>'f:2','shift'=>'evening','starts_at'=>'20:00:00','ends_at'=>'03:00:00'],
    ] as $item) $db->table('branch_attendance_rules')->insert($item+$rule);
    $day = ['branch'=>'f:1','employee_id'=>1,'status'=>'unauthorized_absence','check_in'=>null,'check_out'=>null,
        'checked_in_at'=>null,'checked_out_at'=>null,'attendance_rule_snapshot'=>null,'notes'=>'original note',
        'revision'=>4,'actor_id'=>1,'created_at'=>'2026-11-01 08:00:00','updated_at'=>'2026-11-01 08:00:00'];
    $historicalRule = (object)(['shift'=>'morning','starts_at'=>'08:00:00','ends_at'=>'17:00:00']+$rule);
    $snapshot = EmployeeAttendanceRules::snapshot($historicalRule, '2026-11-02');
    foreach ([
        ['day'=>'2026-11-01'],
        ['day'=>'2026-11-02','status'=>'morning','attendance_rule_snapshot'=>json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'check_in'=>'10:00:00','checked_in_at'=>'2026-11-02 08:00:00'],
        ['day'=>'2026-10-31'], ['day'=>'2026-11-06'],
        ['day'=>'2026-11-01','employee_id'=>8,'branch'=>'gs:9'],
        ['day'=>'2026-11-01','employee_id'=>9,'branch'=>'f:3'],
    ] as $item) $db->table('branch_employee_days')->insert(array_replace($day,$item));
    $entry = ['branch'=>'f:1','employee_id'=>1,'kind'=>'deduction','amount_cents'=>1000,'reason'=>'original reason','notes'=>'original notes',
        'source_key'=>null,'voided_at'=>null,'voided_by'=>null,'void_reason'=>null,'revision'=>5,'actor_id'=>1,
        'created_at'=>'2026-11-01 08:00:00','updated_at'=>'2026-11-01 08:00:00'];
    foreach ([
        ['day'=>'2026-11-01'],
        ['day'=>'2026-11-02','source_key'=>'attendance.late','amount_cents'=>2000],
        ['day'=>'2026-11-03','employee_id'=>2,'source_key'=>'attendance.absence','amount_cents'=>3000],
        ['day'=>'2026-11-03','kind'=>'bonus','amount_cents'=>400],
        ['day'=>'2026-11-04','kind'=>'advance','amount_cents'=>500],
        ['day'=>'2026-10-31'], ['day'=>'2026-11-06'],
        ['day'=>'2026-11-01','branch'=>'gs:9','employee_id'=>8],
        ['day'=>'2026-11-01','branch'=>'f:3','employee_id'=>9],
        ['day'=>'2026-11-04','voided_at'=>'2026-11-04 09:00:00','voided_by'=>1,'void_reason'=>'previous cancellation'],
    ] as $item) $db->table('branch_employee_entries')->insert(array_replace($entry,$item));
    return $db;
}
function expectAtomicFailure(callable $prepare, string $expectedCode): void
{
    $db = fixture(); $prepare($db); $before = state($db); $caught = null;
    try { goHrNov05Repair($db, ['f:1','f:2'], backupDirectory()); }
    catch (Throwable $error) { $caught = $error; }
    check($caught !== null, 'failure was required: '.$expectedCode);
    if ($expectedCode !== 'DATABASE_TRIGGER') same($expectedCode, $caught->getMessage(), 'exact failure marker');
    same($before, state($db), 'failure leaves every table unchanged: '.$expectedCode);
}

try {
    $db = fixture(); $before = state($db); $directory = backupDirectory();
    $result = goHrNov05Repair($db, ['f:2','f:1'], $directory);
    same('GO_HR_NOV05_APPLIED',$result['status'],'success marker');
    same('2026-11-01',$result['from'],'fixed start'); same('2026-11-05',$result['through'],'inclusive fifth');
    same(21,$result['attendance_verified'],'eligible attendance includes all five days and employment bounds');
    same(3,$result['deductions_cancelled'],'manual and automatic deductions cancelled');
    same(0,$result['remaining_deductions'],'no current-period selected-branch deductions remain');
    foreach ([1=>5,2=>5,3=>5,4=>3,5=>3,6=>0,7=>0] as $id=>$count) {
        same($count,$db->table('branch_employee_days')->where('employee_id',$id)->whereBetween('day',['2026-11-01','2026-11-05'])->count(),'employee eligibility '.$id);
    }
    $morning = $db->table('branch_employee_days')->where('employee_id',1)->where('day','2026-11-01')->first();
    same('morning',$morning->status,'absence becomes present'); same('09:00:00',$morning->check_in,'morning scheduled check-in');
    same('18:00:00',$morning->check_out,'morning scheduled checkout'); check(str_contains($morning->notes,'original note'),'old notes retained');
    $historical = $db->table('branch_employee_days')->where('employee_id',1)->where('day','2026-11-02')->first();
    same('08:00:00',$historical->check_in,'historic schedule retained over later rule'); same('17:00:00',$historical->check_out,'historic scheduled end retained');
    foreach ([2=>['evening','2026-11-05 20:00:00','2026-11-06 03:00:00'],3=>['part_time','2026-11-06 01:00:00','2026-11-06 04:00:00']] as $id=>$expected) {
        $row = $db->table('branch_employee_days')->where('employee_id',$id)->where('day','2026-11-05')->first();
        same($expected[0],$row->status,'shift status '.$id);
        same(Carbon::parse($expected[1],'Africa/Cairo')->utc()->toDateTimeString(),$row->checked_in_at,'UTC overnight scheduled start '.$id);
        same(Carbon::parse($expected[2],'Africa/Cairo')->utc()->toDateTimeString(),$row->checked_out_at,'UTC overnight scheduled end '.$id);
    }
    foreach ([1,2,3] as $id) {
        $entry=(array)$db->table('branch_employee_entries')->where('id',$id)->first();
        check($entry['voided_at'] !== null,'deduction void date '.$id); same(1,$entry['voided_by'],'owner cancellation '.$id);
        check(str_contains($entry['void_reason'],'1 إلى 5 نوفمبر'),'explicit administrative reason '.$id);
        same(6,$entry['revision'],'ledger revision increment '.$id);
        foreach (['amount_cents','reason','notes','source_key','actor_id','created_at'] as $key) same($before['branch_employee_entries'][$id-1][$key],$entry[$key],'original ledger retained '.$id.'/'.$key);
    }
    foreach (['resturants','branch_employees','branch_attendance_rules','branch_payrolls'] as $table) same($before[$table],rows($db,$table),'unrelated whole table retained '.$table);
    foreach (array_slice($before['branch_employee_entries'],3) as $old) same($old,(array)$db->table('branch_employee_entries')->where('id',$old['id'])->first(),'bonuses advances old/future/merchant/void entries untouched '.$old['id']);
    foreach (array_slice($before['branch_employee_days'],2) as $old) same($old,(array)$db->table('branch_employee_days')->where('id',$old['id'])->first(),'old/future/other branch attendance untouched '.$old['id']);
    check(is_file($result['backup']),'backup created'); same(0600,fileperms($result['backup']) & 0777,'backup is private');
    $backup=json_decode(file_get_contents($result['backup']),true,512,JSON_THROW_ON_ERROR);
    same(array_slice($before['branch_employee_days'],0,2),$backup['attendance_before'],'backup contains original attendance');
    same(array_slice($before['branch_employee_entries'],0,3),$backup['deductions_before'],'backup contains original deductions');
    same(21,count($backup['attendance_keys']),'backup identifies inserted attendance keys too');
    $after = state($db); $again = goHrNov05Repair($db,['f:1','f:2'],$directory);
    same(0,$again['attendance_changed'],'repeat makes no attendance writes'); same(0,$again['deductions_cancelled'],'repeat makes no ledger writes');
    same($after,state($db),'entire database stable on repeated invocation');
    expectAtomicFailure(static fn($db) => $db->table('branch_attendance_rules')->where('id',4)->delete(),'GO_HR_MISSING_SCHEDULE_EMPLOYEE_3');
    expectAtomicFailure(static fn($db) => $db->table('branch_payrolls')->insert(['employee_id'=>2,'month'=>'2026-11','status'=>'closed']),'GO_HR_NOVEMBER_PAYROLL_ALREADY_CLOSED_NO_CHANGES');
    expectAtomicFailure(static fn($db) => $db->table('branch_attendance_rules')->where('id',3)->update(['starts_at'=>'18:00:00','ends_at'=>'18:00:00']),'GO_HR_INVALID_SCHEDULE_EMPLOYEE_4');
    // Fail after attendance writes and at least one ledger cancellation: all must roll back.
    expectAtomicFailure(static fn($db) => $db->unprepared("CREATE TRIGGER deny_second_void BEFORE UPDATE ON branch_employee_entries WHEN OLD.id = 2 BEGIN SELECT RAISE(ABORT, 'injected failure'); END"),'DATABASE_TRIGGER');
    echo 'PASS '.$checks." runtime assertions\n";
} finally {
    foreach ($temporary as $directory) {
        foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
        rmdir($directory);
    }
}
