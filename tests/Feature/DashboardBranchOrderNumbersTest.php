<?php
namespace Tests\Feature;

use App\Services\Dashboard\BranchOrderSequence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Order numbers per branch section: they continue across shifts and restart from 1 every 10 closed shifts. */
class DashboardBranchOrderNumbersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('n',32)),'database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);
        DB::purge('sqlite');Schema::clearResolvedInstance('db.schema');
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->string('name');});
        Schema::create('branch_shift_closings',function(Blueprint $t){$t->id();$t->string('branch',40);$t->unsignedInteger('sequence');});
        foreach(['orders','takeaway_orders','pos_service_tickets'] as $table)Schema::create($table,function(Blueprint $t){$t->id();});
        require_once database_path('migrations/2026_10_18_210000_create_shift_report_printing_and_numbers.php');(new \CreateShiftReportPrintingAndNumbers)->up();
        DB::table('resturants')->insert([['id'=>100,'name'=>'Main'],['id'=>101,'name'=>'Other']]);
    }

    public function test_a_branch_that_never_closed_a_shift_keeps_its_old_numbers(): void
    {
        $this->assertSame([],app(BranchOrderSequence::class)->next('f:100','takeaway'));
    }

    private function close(string $branch='f:100'): void
    {
        $last=(int)DB::table('branch_shift_closings')->where('branch',$branch)->max('sequence');
        DB::table('branch_shift_closings')->insert(['branch'=>$branch,'sequence'=>$last+1]);
    }

    private function number(string $channel,string $branch='f:100'): int
    {
        return app(BranchOrderSequence::class)->next($branch,$channel)['shift_order_number'];
    }

    public function test_cycle_start_groups_shifts_in_tens(): void
    {
        foreach([1=>1,2=>1,10=>1,11=>11,15=>11,20=>11,21=>21] as $shift=>$start)$this->assertSame($start,BranchOrderSequence::cycleStart($shift));
    }

    public function test_each_section_counts_on_its_own_and_continues_across_shift_closings(): void
    {
        $this->close(); // Shift 2 is open.
        $this->close('f:101'); // Numbering starts at a branch's first close.
        $this->assertSame([1,2,3],[$this->number('takeaway'),$this->number('takeaway'),$this->number('takeaway')]);
        $this->assertSame([1,2],[$this->number('dine'),$this->number('dine')]);
        $this->assertSame(1,$this->number('phone'));
        $this->assertSame(1,$this->number('app'));
        $this->assertSame(1,$this->number('takeaway','f:101'),'another branch has its own numbers');

        $this->close(); // Shift 3: numbers keep going.
        $this->assertSame(4,$this->number('takeaway'));
        $this->assertSame(3,$this->number('dine'));
        $this->assertSame(2,$this->number('phone'));
        $this->assertSame(2,$this->number('app'));
        $numbers=app(BranchOrderSequence::class)->next('f:100','takeaway');
        $this->assertSame(['shift_sequence'=>3,'shift_order_number'=>5],$numbers,'each order still records its own shift');
    }

    public function test_numbers_restart_from_one_after_ten_closed_shifts(): void
    {
        $this->close(); // Shift 2.
        foreach(range(1,5) as $n)$this->assertSame($n,$this->number('takeaway'));
        while((int)DB::table('branch_shift_closings')->max('sequence')<9)$this->close();
        $this->close(); // The 10th close opens shift 11: a new run of numbers.
        $this->assertSame(1,$this->number('takeaway'));
        $this->assertSame(1,$this->number('dine'));
        $this->assertSame(2,$this->number('takeaway'));
        $this->close(); // Shift 12 continues the run.
        $this->assertSame(3,$this->number('takeaway'));
    }

    public function test_a_shift_numbered_before_the_change_does_not_repeat_numbers(): void
    {
        while((int)DB::table('branch_shift_closings')->max('sequence')<4)$this->close(); // Shift 5, in the run that starts at 1.
        DB::table('branch_order_sequences')->insert(['branch'=>'f:100','channel'=>'takeaway','shift_sequence'=>5,'last_number'=>7]);
        $this->assertSame(8,$this->number('takeaway'));
        $this->assertSame(9,$this->number('takeaway'));
    }
}
