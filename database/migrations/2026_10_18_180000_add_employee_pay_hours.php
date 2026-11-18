<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use App\Services\Dashboard\{EmployeePayHours,OperatingDay};

class AddEmployeePayHours extends Migration
{
    public function up(): void
    {
        if(!Schema::hasTable('branch_work_hours'))Schema::create('branch_work_hours',function(Blueprint $table){
            $table->bigIncrements('id');$table->string('branch',30);$table->string('effective_month',7);
            $table->unsignedSmallInteger('full_time_minutes');$table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('actor_id')->nullable();$table->timestamps();$table->unique(['branch','effective_month']);
        });
        if(!Schema::hasTable('branch_employee_pay_hours'))Schema::create('branch_employee_pay_hours',function(Blueprint $table){
            $table->bigIncrements('id');$table->string('branch',30);$table->unsignedBigInteger('employee_id');$table->string('effective_month',7);
            $table->unsignedSmallInteger('scheduled_minutes')->nullable();$table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('actor_id')->nullable();$table->timestamps();$table->unique(['employee_id','effective_month']);$table->index(['branch','effective_month']);
        });
        $this->seedCurrentContracts();
    }

    public function seedCurrentContracts(): void
    {
        // Existing unknown historical contracts retain full pay. Current contracts begin this operating month.
        $month=substr(OperatingDay::date(),0,7);
        DB::transaction(function()use($month){
        DB::table('branch_employees')->orderBy('id')->chunkById(500,function($employees)use($month){
            foreach($employees as $employee){
                // Re-running the migration must never add a later baseline over existing history.
                if(DB::table('branch_employee_pay_hours')->where('employee_id',$employee->id)->exists())continue;
                DB::table('branch_employee_pay_hours')->insertOrIgnore(['branch'=>$employee->branch,'employee_id'=>$employee->id,
                    'effective_month'=>$month,'scheduled_minutes'=>EmployeePayHours::scheduledMinutes($employee),
                    'revision'=>1,'actor_id'=>$employee->actor_id??null,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            }
        });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_employee_pay_hours');Schema::dropIfExists('branch_work_hours');
    }
}
