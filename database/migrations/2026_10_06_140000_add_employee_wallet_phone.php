<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class AddEmployeeWalletPhone extends Migration
{
    public function up(): void {Schema::table('branch_employees',function(Blueprint $t){$t->string('wallet_phone',11)->nullable();});}
    public function down(): void {Schema::table('branch_employees',function(Blueprint $t){$t->dropColumn('wallet_phone');});}
}
