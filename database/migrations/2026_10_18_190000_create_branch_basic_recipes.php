<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBranchBasicRecipes extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_basic_recipes')) return;
        Schema::create('branch_basic_recipes', function (Blueprint $table) {
            // A single immutable, deployment-captured baseline. Branch recipes remain independent.
            $table->unsignedBigInteger('id')->primary();
            $table->string('source_branch', 30);
            $table->string('source_name');
            $table->longText('snapshot');
            $table->char('snapshot_hash', 64);
            $table->timestamp('frozen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // The captured recipe is business data; rollback must not discard it.
    }
}
