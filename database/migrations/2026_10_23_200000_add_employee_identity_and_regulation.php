<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** National ID photos (private disk paths) and the employee's acceptance of the work regulation. */
class AddEmployeeIdentityAndRegulation extends Migration
{
    private const COLUMNS=['id_front_path','id_front_hash','id_front_mime','id_back_path','id_back_hash','id_back_mime',
        'regulation_version','regulation_accepted_at','regulation_accepted_by'];

    public function up(): void
    {
        $missing=array_values(array_filter(self::COLUMNS,fn($column)=>!Schema::hasColumn('branch_employees',$column)));
        if(!$missing)return;
        Schema::table('branch_employees',function(Blueprint $t)use($missing){
            foreach(['front','back'] as $side){
                if(in_array('id_'.$side.'_path',$missing,true))$t->string('id_'.$side.'_path',300)->nullable();
                if(in_array('id_'.$side.'_hash',$missing,true))$t->char('id_'.$side.'_hash',64)->nullable();
                if(in_array('id_'.$side.'_mime',$missing,true))$t->string('id_'.$side.'_mime',20)->nullable();
            }
            if(in_array('regulation_version',$missing,true))$t->string('regulation_version',20)->nullable();
            if(in_array('regulation_accepted_at',$missing,true))$t->timestamp('regulation_accepted_at')->nullable();
            if(in_array('regulation_accepted_by',$missing,true))$t->unsignedBigInteger('regulation_accepted_by')->nullable();
        });
    }

    public function down(): void
    {
        $present=array_values(array_filter(self::COLUMNS,fn($column)=>Schema::hasColumn('branch_employees',$column)));
        if(!$present)return;
        Schema::table('branch_employees',function(Blueprint $t)use($present){$t->dropColumn($present);});
    }
}
