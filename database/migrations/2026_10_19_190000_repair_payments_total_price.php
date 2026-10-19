<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairPaymentsTotalPrice extends Migration
{
    public function up()
    {
        // A fresh original table already has its amount. Gateway-attempt columns
        // belong to other deployment checkpoints and are not needed for this no-op.
        if (Schema::hasColumn('payments', 'total_price')) return;
        require_once __DIR__ . '/../../deployment/payment_schema/PaymentSchemaRepair.php';
        $connection = DB::connection();
        (new \PaymentSchemaRepair($connection->getPdo(), $connection->getTablePrefix()))->apply();
    }

    public function down()
    {
        // Amounts written after repair are financial history; rollback retains them.
    }
}
