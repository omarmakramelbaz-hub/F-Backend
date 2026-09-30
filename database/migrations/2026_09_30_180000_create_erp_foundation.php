<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateErpFoundation extends Migration
{
    public function up()
    {
        Schema::create('erp_branches', function (Blueprint $t) {
            $t->id();
            // Explicit enrollment: external restaurants/GO stores never join implicitly.
            $t->unsignedBigInteger('restaurant_id')->unique();
            $t->string('name', 120);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('erp_users', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('email', 190)->unique();
            $t->string('password');
            $t->string('role', 32);
            $t->foreignId('branch_id')->nullable()->constrained('erp_branches');
            $t->json('permissions');
            $t->boolean('active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('erp_warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->nullable()->constrained('erp_branches');
            $t->string('name', 120);
            $t->timestamps();
        });
        Schema::create('erp_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 64)->unique();
            $t->string('name', 160);
            $t->string('unit', 12); // kg (3 decimals) or piece (integer)
            $t->string('category', 24); // raw, finished, packaging
            $t->unsignedBigInteger('minimum_milli')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('erp_stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained('erp_warehouses');
            $t->foreignId('item_id')->constrained('erp_items');
            $t->unsignedBigInteger('quantity_milli')->default(0);
            $t->unsignedBigInteger('value_minor')->default(0);
            $t->unique(['warehouse_id', 'item_id']);
        });
        Schema::create('erp_stock_documents', function (Blueprint $t) {
            $t->id();
            $t->string('request_key', 64)->unique();
            $t->string('payload_hash', 64);
            $t->string('actor_key', 40);
            $t->string('type', 20);
            $t->foreignId('item_id')->constrained('erp_items');
            $t->foreignId('warehouse_id')->constrained('erp_warehouses');
            $t->foreignId('destination_id')->nullable()->constrained('erp_warehouses');
            $t->unsignedBigInteger('quantity_milli');
            $t->unsignedBigInteger('value_minor');
            $t->string('reference', 160)->nullable();
            $t->string('reason', 500);
            $t->timestamp('created_at');
        });
        Schema::create('erp_stock_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('erp_stock_documents');
            $t->foreignId('warehouse_id')->constrained('erp_warehouses');
            $t->foreignId('item_id')->constrained('erp_items');
            $t->bigInteger('quantity_milli');
            $t->bigInteger('value_minor');
            $t->timestamp('created_at');
            $t->unique(['document_id', 'warehouse_id']);
        });
        Schema::create('erp_employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained('erp_branches');
            $t->string('name', 120);
            $t->string('phone', 32)->nullable();
            $t->string('job_title', 100);
            $t->date('hired_on');
            $t->unsignedBigInteger('salary_minor');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('erp_salary_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('erp_employees');
            $t->string('effective_month', 7);
            $t->unsignedBigInteger('salary_minor');
            $t->unique(['employee_id', 'effective_month']);
        });
        Schema::create('erp_attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('erp_employees');
            $t->date('day');
            $t->string('status', 24);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'day']);
        });
        Schema::create('erp_payroll_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('erp_employees');
            $t->string('month', 7);
            $t->string('type', 24); // bonus, deduction, advance_repayment
            $t->unsignedBigInteger('amount_minor');
            $t->string('reason', 500);
            $t->string('request_key', 64)->unique();
            $t->string('actor_key', 40);
            $t->timestamp('created_at');
        });
        Schema::create('erp_payrolls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('erp_employees');
            $t->foreignId('branch_id')->constrained('erp_branches');
            $t->string('month', 7);
            $t->unsignedBigInteger('base_minor');
            $t->unsignedBigInteger('bonus_minor');
            $t->unsignedBigInteger('deduction_minor');
            $t->unsignedBigInteger('advance_minor');
            $t->bigInteger('net_minor');
            $t->string('actor_key', 40);
            $t->timestamp('created_at');
            $t->unique(['employee_id', 'month']);
        });
        Schema::create('erp_audit', function (Blueprint $t) {
            $t->id();
            $t->string('actor_key', 40);
            $t->string('actor_name', 120);
            $t->string('action', 60);
            $t->string('entity', 50);
            $t->unsignedBigInteger('entity_id');
            $t->unsignedBigInteger('branch_id')->nullable()->index();
            $t->json('details');
            $t->timestamp('created_at')->index();
        });
    }

    public function down()
    {
        // A rollback must not erase posted stock or payroll history.
        foreach (['erp_stock_documents', 'erp_payrolls', 'erp_audit'] as $table) {
            if (Schema::hasTable($table) && \Illuminate\Support\Facades\DB::table($table)->exists()) {
                throw new \RuntimeException('ERP contains business history. Restore a reviewed backup instead of dropping its tables.');
            }
        }
        foreach (['erp_audit','erp_payrolls','erp_payroll_adjustments','erp_attendance','erp_salary_rates','erp_employees','erp_stock_entries','erp_stock_documents','erp_stock_balances','erp_items','erp_warehouses','erp_users','erp_branches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
