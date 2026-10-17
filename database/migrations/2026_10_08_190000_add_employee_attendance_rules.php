<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEmployeeAttendanceRules extends Migration
{
    public function up(): void
    {
        Schema::create('branch_attendance_rules', function (Blueprint $t) {
            $t->id(); $t->string('branch',30); $t->string('shift',20);
            $t->time('starts_at'); $t->time('ends_at');
            $t->bigInteger('late_half_hour_cents'); $t->bigInteger('early_half_hour_cents');
            $t->bigInteger('absence_cents'); $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('actor_id'); $t->timestamps();
            $t->unique(['branch','shift']);
        });
        Schema::table('branch_employee_days', function (Blueprint $t) {
            $t->timestamp('checked_in_at')->nullable(); $t->timestamp('checked_out_at')->nullable();
            $t->text('attendance_rule_snapshot')->nullable();
        });
        Schema::table('branch_employee_entries', function (Blueprint $t) {
            $t->string('source_key',40)->nullable();
            $t->unique(['employee_id','day','source_key'],'employee_auto_deduction_unique');
        });
    }

    public function down(): void
    {
        Schema::table('branch_employee_entries', function (Blueprint $t) {
            $t->dropUnique('employee_auto_deduction_unique'); $t->dropColumn('source_key');
        });
        Schema::table('branch_employee_days', function (Blueprint $t) {
            $t->dropColumn(['checked_in_at','checked_out_at','attendance_rule_snapshot']);
        });
        Schema::dropIfExists('branch_attendance_rules');
    }
}
