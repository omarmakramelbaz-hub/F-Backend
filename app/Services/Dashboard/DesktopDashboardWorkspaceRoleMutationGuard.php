<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/** Changing an assigned role must not commit unsigned login authority into the shared offline database. */
final class DesktopDashboardWorkspaceRoleMutationGuard
{
    public function beforeLocalWrite(string $route,array $payload): void
    {
        if(config('desktop_dashboard.local')!==true||!app(DesktopDashboardWorkspaceLocalAuthority::class)->selected()
            ||!DesktopDashboardRoleFacts::handles($route)||$route==='roles.store')return;
        abort_unless(DB::transactionLevel()>0,503);
        $rows=$route==='roles.destroy-all'?($payload['facts']['catalog_rows']??null)
            :[['id'=>$payload['parameters']['role']??null,'state'=>$payload['facts']['catalog_before']??null]];
        abort_unless(is_array($rows)&&array_is_list($rows)&&count($rows)>0&&count($rows)<=200,422);
        $ids=[];$assigned=false;
        foreach($rows as$row){
            abort_unless(is_array($row)&&is_array($row['state']??null),422);$id=$row['id']??null;
            if(is_array($id)){
                $reference=$id['$desktop_ref']??null;
                abort_unless(is_array($reference)&&preg_match('/^catalog_role(?:\.[0-9]+)?$/D',$reference['entity']??''),422);
                $id=$reference['local_id']??null;
            }
            abort_unless((is_int($id)||is_string($id))&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$id),422);
            $hash=$row['state']['membership_sha256']??null;
            abort_unless(is_string($hash)&&preg_match('/^[a-f0-9]{64}$/D',$hash),501,'عضوية هذا الدور تحتاج مراجعة متصلة قبل تعديله.');
            $assigned=$assigned||!hash_equals(hash('sha256','[]'),$hash);$ids[]=$id;
        }
        $tables=config('permission.table_names');
        DB::table($tables['roles'])->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get(['id']);
        $current=DB::table($tables['model_has_roles'])->whereIn('role_id',$ids)->orderBy('role_id')->lockForUpdate()->first();
        abort_if($assigned||$current!==null,501,
            'هذا الدور مرتبط بحسابات؛ عدّله من الداشبورد المتصل ثم انتظر مزامنة الصلاحيات. لم يُنفّذ أي تعديل محلي.');
    }
}
