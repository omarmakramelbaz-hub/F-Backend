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
        if(Schema::hasColumn('branch_employees','id_front_path'))return;
        Schema::table('branch_employees',function(Blueprint $t){
            foreach(['front','back'] as $side){
                $t->string('id_'.$side.'_path',300)->nullable();
                $t->char('id_'.$side.'_hash',64)->nullable();
                $t->string('id_'.$side.'_mime',20)->nullable();
            }
            $t->string('regulation_version',20)->nullable();
            $t->timestamp('regulation_accepted_at')->nullable();
            $t->unsignedBigInteger('regulation_accepted_by')->nullable();
        });
    }

    public function down(): void
    {
        if(!Schema::hasColumn('branch_employees','id_front_path'))return;
        Schema::table('branch_employees',function(Blueprint $t){$t->dropColumn(self::COLUMNS);});
    }
}
