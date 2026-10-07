<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddCannedHerringStock extends Migration
{
    public function up(): void
    {
        foreach(['علبة رنجة صغيرة','علبة رنجة كبيرة'] as $name){
            if(DB::table('stock_ingredients')->where('name',$name)->where('unit','piece')->exists())continue;
            DB::table('stock_ingredients')->insert(['name'=>$name,'unit'=>'piece','position'=>(int)DB::table('stock_ingredients')->max('position')+1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        }
    }
    public function down(): void
    {
        // Stock rows can be referenced by receipts and sale snapshots; retain them on rollback.
    }
}
