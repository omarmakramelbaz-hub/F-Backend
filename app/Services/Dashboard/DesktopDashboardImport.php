<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Validator};

/** Import into a brand-new private staging database. It never replaces a working local ledger. */
class DesktopDashboardImport
{
    public function import(array $snapshot): array
    {
        abort_unless(config('desktop_dashboard.local')&&DB::connection()->getConfig('host')==='127.0.0.1'
            &&preg_match('/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D',DB::connection()->getDatabaseName()),403);
        Validator::make($snapshot,['format'=>'required|in:1','kind'=>'required|in:initial-dashboard-data',
            'snapshot_id'=>'required|uuid','device_id'=>'required|uuid','actor_id'=>'required|integer|min:1',
            'branches'=>'required|array|min:1','schema_hash'=>'required|regex:/^[a-f0-9]{64}$/D','tables'=>'required|array',
            'coverage'=>'required|array'])->validate();
        abort_unless($snapshot['device_id']===(string)config('desktop_dashboard.device_id'),403);
        abort_unless(DB::select('SHOW TABLES')===[],409,'قاعدة التجهيز ليست فارغة؛ بيانات الجهاز محفوظة.');
        $allowed=DesktopDashboardSchema::TABLES;
        $schema=[];$rowCount=0;
        foreach($snapshot['tables'] as $table=>$part){
            abort_unless(in_array($table,$allowed,true)&&is_array($part)&&isset($part['ddl'],$part['rows'],$part['sha256'])&&is_array($part['rows']),422);
            $ddl=$part['ddl'];abort_unless(is_string($ddl)&&strlen($ddl)<1024*1024&&str_starts_with($ddl,'CREATE TABLE `'.$table.'` ('),422);
            // Remove quoted SQL values/identifiers before checking for additional statements or unsafe DDL.
            $syntax=preg_replace('/\x27(?:[^\x27\\\\]|\\\\.|\x27\x27)*\x27|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`/s','_',$ddl);
            $syntax=preg_replace('/\bON\s+(?:DELETE|UPDATE)\s+(?:RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)\b/i','',$syntax??'');
            $syntax=preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\([0-9]*\))?/i','',$syntax);
            abort_unless($syntax!==null &&preg_match('/\bENGINE=InnoDB\b/i',$syntax)
                &&!preg_match('/;|--|#|\/\*|REFERENCES\s+_\s*\.|\b(?:SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|RENAME|TRUNCATE|GRANT|REVOKE|INTO|OUTFILE|INFILE|TABLESPACE|DIRECTORY|FEDERATED|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i',substr($syntax,strlen('CREATE TABLE'))),422,'مخطط قاعدة التجهيز غير مقبول.');
            preg_match_all('/REFERENCES\s+`([^`]+)`\s*\(/i',$ddl,$references);
            foreach($references[1] as $target)abort_unless(array_key_exists($target,$snapshot['tables']),409,'جدول مرتبط غير موجود في نسخة الحساب.');
            abort_unless(preg_match('/^[a-f0-9]{64}$/D',$part['sha256'])&&hash_equals($part['sha256'],hash('sha256',DesktopDashboardBootstrap::json($part['rows']))),422,'بيانات التجهيز غير مكتملة.');
            $schema[$table]=hash('sha256',$ddl);$rowCount+=count($part['rows']);abort_if($rowCount>500000,413);
        }
        ksort($schema);abort_unless(hash_equals($snapshot['schema_hash'],hash('sha256',DesktopDashboardBootstrap::json($schema))),422,'بصمة مخطط التجهيز مختلفة.');
        $users=$snapshot['tables']['users']['rows']??[];
        abort_unless(count(array_filter($users,fn($row)=>(int)($row['id']??0)===(int)$snapshot['actor_id']))===1,422);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try{
            foreach($snapshot['tables'] as $part)DB::unprepared($part['ddl']);
            // DDL is outside the transaction. Incomplete staging databases are never made active.
            DB::transaction(function()use($snapshot){
                foreach($snapshot['tables'] as $table=>$part){
                    foreach(array_chunk($part['rows'],100) as $rows)DB::table($table)->insert($rows);
                }
                $groups=[];
                foreach(DB::select('SELECT TABLE_NAME AS source_table,CONSTRAINT_NAME AS constraint_name,COLUMN_NAME AS source_column,REFERENCED_TABLE_NAME AS target_table,REFERENCED_COLUMN_NAME AS target_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION',[DB::connection()->getDatabaseName()]) as $reference){
                    $groups[$reference->source_table.':'.$reference->constraint_name][]=$reference;
                }
                foreach($groups as $columns){
                    $first=$columns[0];$joins=[];$notNull=[];
                    foreach($columns as $column){
                        foreach([$column->source_table,$column->source_column,$column->target_table,$column->target_column] as $name)abort_unless(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D',$name),422);
                        $joins[]='p.`'.$column->target_column.'`=c.`'.$column->source_column.'`';$notNull[]='c.`'.$column->source_column.'` IS NOT NULL';
                    }
                    $orphans=DB::selectOne('SELECT COUNT(*) AS total FROM `'.$first->source_table.'` c WHERE '.implode(' AND ',$notNull).' AND NOT EXISTS (SELECT 1 FROM `'.$first->target_table.'` p WHERE '.implode(' AND ',$joins).')');
                    abort_unless((int)$orphans->total===0,409,'نسخة الحساب تحتوي على مرجع ناقص؛ لم تُفعّل قاعدة التجهيز.');
                }
            });
        }finally{DB::statement('SET FOREIGN_KEY_CHECKS=1');}
        require_once database_path('migrations/2026_10_08_130000_create_desktop_dashboard_journal.php');
        (new \CreateDesktopDashboardJournal)->up();
        require_once database_path('migrations/2026_10_08_160000_create_desktop_dashboard_local_state.php');
        (new \CreateDesktopDashboardLocalState)->up();
        DB::table('desktop_dashboard_local_state')->insert([
            'device_id'=>$snapshot['device_id'],'actor_id'=>$snapshot['actor_id'],'snapshot_id'=>$snapshot['snapshot_id'],
            'schema_hash'=>$snapshot['schema_hash'],'branches'=>DesktopDashboardBootstrap::json($snapshot['branches']),
            'coverage'=>DesktopDashboardBootstrap::json($snapshot['coverage']),'state'=>'ready',
            'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ]);
        return ['format'=>1,'snapshot_id'=>$snapshot['snapshot_id'],'device_id'=>$snapshot['device_id'],'actor_id'=>$snapshot['actor_id'],
            'schema_hash'=>$snapshot['schema_hash'],'tables'=>count($schema),'rows'=>$rowCount,'coverage'=>$snapshot['coverage']];
    }
}
