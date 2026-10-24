<?php
declare(strict_types=1);
require __DIR__.'/../deployment/launch_reset_20261025/payroll_inspect.php';

final class InspectionPdo extends PDO
{
    public array $inspectedSql = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (!preg_match('/^SELECT /D', $query)) throw new RuntimeException('Inspector attempted a non-SELECT query');
        $this->inspectedSql[] = $query;
        return parent::prepare($query, $options);
    }
}
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function fixture(): array
{
    $pdo = new InspectionPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $definitions = [
        'branch_employees' => 'id INTEGER PRIMARY KEY,branch TEXT,active INTEGER,hired_on TEXT,left_on TEXT,schedule_type TEXT,name TEXT,phone TEXT',
        'branch_employee_salaries' => 'id INTEGER PRIMARY KEY,employee_id INTEGER,branch TEXT,effective_month TEXT,amount_cents INTEGER',
        'branch_employee_entries' => 'id INTEGER PRIMARY KEY,employee_id INTEGER,branch TEXT,day TEXT,kind TEXT,source_key TEXT,amount_cents INTEGER,voided_at TEXT',
        'branch_employee_days' => 'id INTEGER PRIMARY KEY,employee_id INTEGER,branch TEXT,day TEXT,status TEXT',
        'branch_payrolls' => 'id INTEGER PRIMARY KEY,employee_id INTEGER,branch TEXT,month TEXT,status TEXT,net_cents INTEGER',
        'branch_employee_pay_hours' => 'id INTEGER PRIMARY KEY,employee_id INTEGER,branch TEXT,effective_month TEXT,scheduled_minutes INTEGER',
        'branch_work_hours' => 'id INTEGER PRIMARY KEY,branch TEXT,effective_month TEXT,full_time_minutes INTEGER',
    ];
    $schema = [];
    foreach ($definitions as $table => $definition) {
        $pdo->exec('CREATE TABLE '.$table.' ('.$definition.')');
        foreach ($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC) as $column) $schema[$table]['columns'][$column['name']] = ['type' => $column['type']];
    }
    $pdo->exec("INSERT INTO branch_employees VALUES
        (1,'f:1',1,'2026-10-19',NULL,'branch','PRIVATE_EMPLOYEE_NAME','PRIVATE_PHONE'),
        (2,'f:1',1,'2026-10-01',NULL,'part_time','PRIVATE_NAME_TWO','PRIVATE_PHONE_TWO'),
        (3,'f:2',0,'2026-09-01','2026-10-10','branch','PRIVATE_LEAVER','PRIVATE_PHONE_THREE'),
        (4,'r:99',1,'2026-10-01',NULL,'branch','OTHER_TENANT','OTHER_PHONE')");
    $pdo->exec("INSERT INTO branch_employee_salaries VALUES
        (1,1,'f:1','2026-09',10000),(2,1,'f:1','2026-10',660000),
        (3,2,'f:1','2026-10',710000),(4,3,'f:2','2026-10',800000),
        (5,1,'f:1','2026-11',990000),(6,4,'r:99','2026-10',999999999)");
    $pdo->exec("INSERT INTO branch_employee_pay_hours VALUES
        (1,1,'f:1','2026-10',NULL),(2,2,'f:1','2026-10',480),(3,3,'f:2','2026-10',NULL)");
    $pdo->exec("INSERT INTO branch_work_hours VALUES (1,'f:1','2026-10',600),(2,'f:2','2026-10',600)");
    $pdo->exec("INSERT INTO branch_employee_entries VALUES
        (1,1,'f:1','2026-10-01','bonus',NULL,1000,NULL),
        (2,1,'f:1','2026-10-02','advance',NULL,5000,NULL),
        (3,1,'f:1','2026-10-02','deduction','attendance.late',10000,NULL),
        (4,1,'f:1','2026-10-02','deduction','attendance.absence',999,'2026-10-03'),
        (5,2,'f:1','2026-10-03','deduction','attendance.leave',22903,NULL),
        (6,2,'f:old','2026-10-04','deduction','PRIVATE_SOURCE',1000,NULL),
        (7,1,'f:1','2026-10-26','deduction',NULL,888888,NULL),
        (8,1,'f:1','2026-09-30','deduction',NULL,888888,NULL),
        (9,4,'r:99','2026-10-02','deduction',NULL,888888,NULL)");
    $pdo->exec("INSERT INTO branch_employee_days VALUES (1,1,'f:1','2026-10-01','morning'),(2,2,'f:1','2026-10-03','preapproved_leave'),(3,4,'r:99','2026-10-01','unauthorized_absence')");
    $pdo->exec("INSERT INTO branch_payrolls VALUES (1,1,'f:1','2026-10','paid',200000),(2,1,'f:1','2026-09','closed',200000),(3,4,'r:99','2026-10','paid',200000)");
    return [$pdo, $schema];
}
function snapshot(PDO $pdo, array $schema): string
{
    $all = [];
    foreach ($schema as $table => $_) $all[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256', json_encode($all, JSON_THROW_ON_ERROR));
}

check(LaunchPayrollInspection::calculateTerms(660000,25,31,600,null,0) === ['earned_salary_cents'=>532258,'hours_deduction_cents'=>0], 'October25 uses31 not30');
check(LaunchPayrollInspection::calculateTerms(710000,25,31,600,480,1) === ['earned_salary_cents'=>572581,'hours_deduction_cents'=>109935], 'Parttime contract hours and leave exclusion exactly match service');
check(LaunchPayrollInspection::calculateTerms(1,15,31,600,null,0)['earned_salary_cents'] === 0, 'Below half cent rounds down');
check(LaunchPayrollInspection::calculateTerms(1,16,31,600,null,0)['earned_salary_cents'] === 1, 'Above half cent rounds up');
check(LaunchPayrollInspection::calculateTerms(660000,25,31,600,720,0)['hours_deduction_cents'] === 0, 'More hours do not invent overtime or a negative deduction');
check(LaunchPayrollInspection::calculateTerms(660000,25,31,600,480,99)['hours_deduction_cents'] === 0, 'Leave days clamp to eligible days');
foreach ([[-1,25,31,600,null,0],[660000,32,31,600,null,0],[660000,25,31,0,null,0],[660000,25,31,600,0,0]] as $args) {
    try { LaunchPayrollInspection::calculateTerms(...$args); check(false, 'Invalid terms must fail'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'PAYROLL_INSPECTION_INVALID_TERMS', 'Invalid terms are bounded failures'); }
}
[$pdo,$schema] = fixture();
$before = snapshot($pdo,$schema);
$result = LaunchPayrollInspection::inspect($pdo,$schema,new DateTimeImmutable('2026-10-25T05:59:59+03:00'));
check($result['ready'] === true, 'Complete schema ready');
check($result['employee_ids'] === [1,2,3], 'All registered f employees include inactive, exclude other tenant');
check($result['cohort_flags']['active_count'] === 2 && $result['cohort_flags']['inactive_count'] === 1, 'Active status flags');
check($result['cohort_flags']['left_before_requested_through_count'] === 1, 'Leaver decision surfaced');
check($result['cohort_flags']['hired_after_requested_start_count'] === 1, 'Newer hire date surfaced');
check($result['clock']['operating_date'] === '2026-10-24' && $result['clock']['operating_day_before_requested_through'] === true, 'Cairo before6AM operating day is yesterday');
check($result['projection']['complete'] === true && $result['projection']['existing_leavers_require_decision'] === true, 'Known hypothetical terms and leaver uncertainty are distinct');
check($result['projection']['totals'] === ['employees_with_known_salary_and_hours'=>3,'monthly_base_cents'=>2170000,'full_period_gross_cents'=>1750000,'contract_hours_deduction_cents'=>109935,'retained_recorded_deductions_cents'=>33903,'post_requested_bonus_advance_reset_net_cents'=>1606162], 'Post-request net preserves all active deductions and contract hours, removes only bonuses/advances');
check($result['october_entries']['active_totals_cents'] === ['bonus'=>1000,'deduction'=>33903,'advance'=>5000], 'Voided/future/old/other-tenant entries excluded');
check($result['cohort_flags']['cross_branch_ledger_rows_count'] === 1, 'Existing employee ledger queries follow employee IDs and flag branch changes');
check($result['october_attendance_counts'] === ['morning'=>1,'preapproved_leave'=>1], 'Attendance counted without manufacturing punches');
check($result['october_saved_payrolls']['counts_by_status'] === ['paid'=>1] && $result['october_saved_payrolls']['blocks_recalculation'] === true, 'Saved October payroll blocks normal recalculation');
check($result['cohort_flags']['future_salary_history_rows_count'] === 1, 'Future salary not silently applied');
check(snapshot($pdo,$schema) === $before && $result['database_changed'] === false, 'Every database row preserved');
$text = json_encode($result, JSON_THROW_ON_ERROR);
check(!str_contains($text,'PRIVATE_') && !str_contains($text,'OTHER_TENANT') && !str_contains($text,'OTHER_PHONE'), 'No staff PII or arbitrary source keys in output');
check(count($pdo->inspectedSql) >= 7, 'Inspector exercises real read-only SQL paths');
$result = LaunchPayrollInspection::inspect($pdo,$schema,new DateTimeImmutable('2026-10-25T06:00:00+03:00'));
check($result['clock']['operating_date'] === '2026-10-25' && $result['clock']['operating_day_before_requested_through'] === false, '6AM inclusive launches day25');

$missing=$schema;unset($missing['branch_employee_salaries']['columns']['amount_cents']);
$queryCount=count($pdo->inspectedSql);$result=LaunchPayrollInspection::inspect($pdo,$missing);
check(!$result['ready'] && in_array('branch_employee_salaries.amount_cents',$result['missing_schema'],true), 'Missing salary schema reported before data queries');
check(count($pdo->inspectedSql)===$queryCount, 'No data query after missing schema');

$fallback=$schema;unset($fallback['branch_employee_pay_hours'],$fallback['branch_work_hours']);
$result=LaunchPayrollInspection::inspect($pdo,$fallback);
check(!$result['contract_tables']['employee_pay_hours_present'] && !$result['contract_tables']['branch_work_hours_present'], 'Missing optional contract tables visible');
check($result['cohort_flags']['part_time_without_employee_hours_record_count']===1 && $result['cohort_flags']['employees_using_default_branch_hours_count']===3, 'Default legacy terms and missing parttime history explicitly flagged');
check($result['projection']['totals']['contract_hours_deduction_cents']===0, 'Missing historical contract hours match actual full-time fallback');

$pdo->exec("UPDATE branch_employee_salaries SET amount_cents='corrupt' WHERE id=2");
$result=LaunchPayrollInspection::inspect($pdo,$schema);
check(!$result['projection']['complete'] && $result['cohort_flags']['missing_october_salary_count']===1, 'Invalid latest salary does not fall back to a prior salary');
check($result['projection']['totals']['monthly_base_cents']===1510000, 'Partial known totals do not invent unknown salary');
check($result['cohort_flags']['invalid_salary_history_rows_count']===1, 'Invalid salary explicitly reported');

echo 'PASS '.$checks." payroll launch inspection checks\n";
