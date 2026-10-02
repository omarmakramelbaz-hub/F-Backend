<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateErpOperations extends Migration
{
    public function up()
    {
        Schema::create('erp_suppliers', function (Blueprint $t) {
            $t->id(); $t->string('name',120); $t->string('phone',32)->nullable();
            $t->string('address',300)->nullable(); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('erp_purchases', function (Blueprint $t) {
            $t->id(); $t->foreignId('supplier_id')->constrained('erp_suppliers');
            $t->foreignId('warehouse_id')->constrained('erp_warehouses');
            $t->string('invoice_number',80); $t->date('invoice_date'); $t->unsignedBigInteger('total_minor');
            $t->string('request_key',64)->unique(); $t->string('payload_hash',64); $t->string('actor_key',40);
            $t->string('notes',500); $t->timestamp('created_at'); $t->unique(['supplier_id','invoice_number']);
        });
        Schema::create('erp_purchase_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('purchase_id')->constrained('erp_purchases');
            $t->foreignId('item_id')->constrained('erp_items'); $t->foreignId('stock_document_id')->constrained('erp_stock_documents');
            $t->unsignedBigInteger('quantity_milli'); $t->unsignedBigInteger('unit_cost_minor'); $t->unsignedBigInteger('total_minor');
            $t->unique(['purchase_id','item_id']);
        });
        Schema::create('erp_recipes', function (Blueprint $t) {
            $t->id(); $t->string('name',120); $t->foreignId('output_item_id')->constrained('erp_items');
            $t->unsignedBigInteger('output_milli'); $t->string('notes',500)->nullable(); $t->string('actor_key',40); $t->timestamp('created_at');
        });
        Schema::create('erp_recipe_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('recipe_id')->constrained('erp_recipes'); $t->foreignId('item_id')->constrained('erp_items');
            $t->unsignedBigInteger('quantity_milli'); $t->unique(['recipe_id','item_id']);
        });
        Schema::create('erp_productions', function (Blueprint $t) {
            $t->id(); $t->foreignId('recipe_id')->constrained('erp_recipes'); $t->foreignId('warehouse_id')->constrained('erp_warehouses');
            $t->unsignedBigInteger('factor_milli'); $t->unsignedBigInteger('expected_output_milli'); $t->unsignedBigInteger('actual_output_milli');
            $t->unsignedBigInteger('total_minor'); $t->string('request_key',64)->unique(); $t->string('payload_hash',64);
            $t->string('actor_key',40); $t->string('notes',500); $t->timestamp('created_at');
        });
        Schema::create('erp_accounts', function (Blueprint $t) {
            $t->string('code',12)->primary(); $t->string('name',120); $t->string('type',16);
        });
        $accounts = [
            ['1100','الخزينة المركزية','asset'], ['1200','مخزون الخامات','asset'], ['1210','مخزون المنتج الجاهز','asset'],
            ['1220','مخزون التغليف','asset'], ['2100','الموردون','liability'], ['2190','استلامات تحتاج تسوية مالية','liability'],
            ['2195','استقطاعات سلف تحتاج مطابقة','liability'], ['2200','مرتبات مستحقة','liability'], ['3100','تسوية الأرصدة الافتتاحية','equity'],
            ['3200','تمويل المالك','equity'], ['4900','فروق زيادة الجرد','income'], ['5100','هالك المخزون','expense'],
            ['5200','مصروف المرتبات','expense'], ['5300','فروق عجز الجرد','expense'], ['5400','مصروفات تشغيلية','expense'],
        ];
        foreach ($accounts as [$code,$name,$type]) { DB::table('erp_accounts')->insert(compact('code','name','type')); }
        Schema::create('erp_ledger_state', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); $t->timestamp('initialized_at')->nullable();
        });
        DB::table('erp_ledger_state')->insert(['id'=>1]);
        Schema::create('erp_journals', function (Blueprint $t) {
            $t->id(); $t->string('source_key',100)->unique(); $t->string('payload_hash',64); $t->string('description',500);
            $t->date('entry_date'); $t->string('actor_key',40); $t->timestamp('created_at');
        });
        Schema::create('erp_journal_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('journal_id')->constrained('erp_journals'); $t->string('account_code',12);
            $t->foreign('account_code')->references('code')->on('erp_accounts');
            $t->foreignId('branch_id')->nullable()->constrained('erp_branches');
            $t->foreignId('supplier_id')->nullable()->constrained('erp_suppliers');
            $t->unsignedBigInteger('debit_minor'); $t->unsignedBigInteger('credit_minor');
            $t->index(['account_code','branch_id']); $t->index(['supplier_id','account_code']);
        });
        Schema::create('erp_cash_documents', function (Blueprint $t) {
            $t->id(); $t->string('type',24); $t->foreignId('supplier_id')->nullable()->constrained('erp_suppliers');
            $t->foreignId('payroll_id')->nullable()->unique()->constrained('erp_payrolls');
            $t->foreignId('branch_id')->nullable()->constrained('erp_branches');
            $t->unsignedBigInteger('amount_minor'); $t->string('request_key',64)->unique(); $t->string('payload_hash',64);
            $t->string('actor_key',40); $t->string('reason',500); $t->timestamp('created_at');
        });
    }

    public function down()
    {
        if (DB::table('erp_ledger_state')->whereNotNull('initialized_at')->exists()
            || DB::table('erp_purchases')->exists() || DB::table('erp_productions')->exists()
            || DB::table('erp_recipes')->exists() || DB::table('erp_suppliers')->exists()) {
            throw new \RuntimeException('ERP operations contain business data; use a reviewed recovery procedure.');
        }
        foreach (['erp_cash_documents','erp_journal_lines','erp_journals','erp_ledger_state','erp_accounts','erp_productions','erp_recipe_lines','erp_recipes','erp_purchase_lines','erp_purchases','erp_suppliers'] as $table) { Schema::dropIfExists($table); }
    }
}
