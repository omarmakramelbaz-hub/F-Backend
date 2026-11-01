<?php
// The shift page's tables for the last part of the end-to-end check (after every other part): the closings, and the
// app orders and carts the shift report reads. The orders table has only the columns the shift page reads, so the other
// pages keep treating app orders as not installed. Expenses waiting for review are approved: they would stop a closing.
require __DIR__.'/env.php';
$app = require GOERP_ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
goerp_settings($app);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once database_path('migrations/2026_10_04_100000_create_branch_shift_closings.php'); (new \CreateBranchShiftClosings)->up();
Schema::create('orders', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('resturant_id'); $t->string('type')->default('current'); $t->string('status')->nullable();
    $t->string('payment_type')->nullable(); $t->string('transfer_price_by')->nullable(); $t->decimal('total_price', 14, 2)->default(0);
    $t->decimal('delivery_price', 14, 2)->default(0); $t->decimal('user_tax', 14, 2)->default(0); $t->timestamp('updated_at')->nullable();
});
Schema::create('carts', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('order_id'); $t->decimal('price', 14, 2)->default(0); $t->string('qty')->default('1');
    $t->decimal('updated_total', 14, 2)->nullable();
});
DB::table('branch_expenses')->where('status', 'pending')->update(['status' => 'approved']);
echo "shift tables\n";
