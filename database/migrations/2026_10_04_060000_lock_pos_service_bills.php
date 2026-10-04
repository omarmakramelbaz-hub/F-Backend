<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class LockPosServiceBills extends Migration
{
    public function up(): void
    {
        Schema::table('pos_service_tickets', function (Blueprint $table) {
            $table->timestamp('bill_issued_at')->nullable();
            $table->unsignedBigInteger('bill_issued_by')->nullable();
            $table->unsignedInteger('bill_issued_revision')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('pos_service_tickets', function (Blueprint $table) {
            $table->dropColumn(['bill_issued_at', 'bill_issued_by', 'bill_issued_revision']);
        });
    }
}
