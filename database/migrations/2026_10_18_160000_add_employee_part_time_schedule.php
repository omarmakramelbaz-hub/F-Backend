<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEmployeePartTimeSchedule extends Migration
{
    public function up(): void
    {
        // Existing employees continue to use their branch's morning/evening rules.
        foreach (['schedule_type','attendance_start','attendance_end','attendance_shift'] as $column) {
            if (Schema::hasColumn('branch_employees', $column)) continue;
            Schema::table('branch_employees', function (Blueprint $table) use ($column) {
                if ($column === 'schedule_type') $table->string($column,20)->default('branch');
                elseif ($column === 'attendance_shift') $table->string($column,20)->nullable();
                else $table->time($column)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('branch_employees', function (Blueprint $table) {
            $table->dropColumn(['schedule_type','attendance_start','attendance_end','attendance_shift']);
        });
    }
}
