<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBranchOperations extends Migration
{
    public function up(): void
    {
        Schema::create('branch_operation_commands', function (Blueprint $t) {
            $t->id(); $t->string('branch', 30); $t->unsignedBigInteger('actor_id'); $t->uuid('request_key');
            $t->string('request_hash', 64); $t->string('kind', 40); $t->longText('result'); $t->timestamps();
            $t->unique(['branch','actor_id','request_key'], 'branch_operation_request_unique');
        });
        Schema::create('branch_customers', function (Blueprint $t) {
            $t->id(); $t->string('branch',30); $t->string('phone_key',30); $t->string('phone',30); $t->string('name',100);
            $t->string('address',500); $t->string('area',150)->nullable(); $t->string('delivery_notes',500)->nullable();
            $t->decimal('latitude',10,7)->nullable(); $t->decimal('longitude',10,7)->nullable();
            $t->unsignedBigInteger('actor_id'); $t->unsignedInteger('revision')->default(1); $t->timestamps();
            $t->unique(['branch','phone_key']); $t->index(['branch','name']);
        });
        Schema::create('branch_delivery_companies', function (Blueprint $t) {
            $t->id(); $t->string('branch',30); $t->string('name',150); $t->string('phone',30); $t->string('contact_name',100)->nullable();
            $t->string('address',500)->nullable(); $t->text('notes')->nullable(); $t->boolean('active')->default(true);
            $t->unsignedInteger('revision')->default(1); $t->unsignedBigInteger('actor_id'); $t->timestamps(); $t->index(['branch','active']);
        });
        Schema::table('pos_service_tickets', function (Blueprint $t) {
            $t->unsignedBigInteger('customer_id')->nullable(); $t->unsignedBigInteger('delivery_company_id')->nullable();
            $t->text('delivery_company_snapshot')->nullable(); $t->text('delivery_snapshot')->nullable();
            $t->index(['branch','delivery_company_id'], 'pos_delivery_company_index');
        });
        Schema::create('branch_employees', function (Blueprint $t) {
            $t->id(); $t->string('branch',30); $t->string('name',100); $t->string('phone',30)->nullable();
            $t->string('job_title',100); $t->string('shift',100)->nullable(); $t->date('hired_on'); $t->date('left_on')->nullable();
            $t->text('notes')->nullable(); $t->boolean('active')->default(true); $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('actor_id'); $t->timestamps(); $t->index(['branch','active']);
        });
        Schema::create('branch_employee_salaries', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('employee_id'); $t->string('branch',30); $t->string('effective_month',7);
            $t->bigInteger('amount_cents'); $t->unsignedBigInteger('actor_id'); $t->timestamps(); $t->unique(['employee_id','effective_month'],'employee_salary_month_unique');
        });
        Schema::create('branch_employee_days', function (Blueprint $t) {
            $t->id(); $t->string('branch',30); $t->unsignedBigInteger('employee_id'); $t->date('day'); $t->string('status',30);
            $t->time('check_in')->nullable(); $t->time('check_out')->nullable(); $t->text('notes')->nullable();
            $t->unsignedInteger('revision')->default(1); $t->unsignedBigInteger('actor_id'); $t->timestamps();
            $t->unique(['employee_id','day']); $t->index(['branch','day']);
        });
        Schema::create('branch_employee_entries', function (Blueprint $t) {
            $t->id(); $t->string('branch',30); $t->unsignedBigInteger('employee_id'); $t->date('day'); $t->string('kind',20);
            $t->bigInteger('amount_cents'); $t->string('reason',500); $t->text('notes')->nullable();
            $t->timestamp('voided_at')->nullable(); $t->unsignedBigInteger('voided_by')->nullable(); $t->string('void_reason',500)->nullable();
            $t->unsignedInteger('revision')->default(1); $t->unsignedBigInteger('actor_id'); $t->timestamps(); $t->index(['branch','employee_id','day'],'employee_entries_period_index');
        });
        Schema::create('branch_payrolls', function (Blueprint $t) {
            $t->id(); $t->string('branch',30); $t->unsignedBigInteger('employee_id'); $t->string('month',7);
            $t->bigInteger('net_cents'); $t->longText('snapshot'); $t->string('status',20)->default('closed');
            $t->unsignedInteger('revision')->default(1); $t->unsignedBigInteger('actor_id');
            $t->timestamp('paid_at')->nullable(); $t->unsignedBigInteger('paid_by')->nullable();
            $t->string('payment_method',30)->nullable(); $t->string('payment_reference',150)->nullable();
            $t->timestamps(); $t->unique(['employee_id','month']); $t->index(['branch','month']);
        });
    }
    public function down(): void
    {
        foreach (['branch_payrolls','branch_employee_entries','branch_employee_days','branch_employee_salaries','branch_employees','branch_delivery_companies','branch_customers','branch_operation_commands'] as $table) Schema::dropIfExists($table);
        Schema::table('pos_service_tickets', function (Blueprint $t) {
            $t->dropIndex('pos_delivery_company_index'); $t->dropColumn(['customer_id','delivery_company_id','delivery_company_snapshot','delivery_snapshot']);
        });
    }
}
