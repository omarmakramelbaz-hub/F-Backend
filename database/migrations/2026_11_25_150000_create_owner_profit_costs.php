<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's figures for net profit, kept on the goods page: each branch's monthly rent, the packaging of one order,
 * and which expense items count against profit.
 */
class CreateOwnerProfitCosts extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_fixed_costs')) Schema::create('branch_fixed_costs', function (Blueprint $t) {
            $t->id();
            $t->string('branch', 30)->unique();
            $t->unsignedBigInteger('rent_monthly_cents')->default(0);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamps();
        });
        if (!Schema::hasTable('owner_profit_settings')) Schema::create('owner_profit_settings', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->text('payload');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['owner_profit_settings', 'branch_fixed_costs'] as $table) Schema::dropIfExists($table);
    }
}
