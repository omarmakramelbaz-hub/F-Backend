<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Validator};

/** Import into a brand-new private staging database. It never replaces a working local ledger. */
class DesktopDashboardImport
{
    public function import(array $snapshot,?array $rowHashes=null): array
    {
        abort_unless(config('desktop_dashboard.local')&&DB::connection()->getConfig('host')==='127.0.0.1'
            &&preg_match('/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D',DB::connection()->getDatabaseName()),403);
        $this->validateImportMetadata($snapshot);
        abort_unless($snapshot['device_id']===(string)config('desktop_dashboard.device_id'),403);
        abort_unless(app(DesktopDashboardSource::class)->matches($snapshot['source']??null),409,'نسخة برنامج الجهاز لا تطابق بيانات السيرفر؛ النسخة الحالية وسجلاتها محفوظة.');
        abort_unless(DB::select('SHOW TABLES')===[],409,'قاعدة التجهيز ليست فارغة؛ بيانات الجهاز محفوظة.');
        // Only the native entry point derives these hashes from the exact JSON
        // it decoded. Public snapshot fields cannot override row verification.
        if($rowHashes!==null){
            abort_unless(array_keys($rowHashes)===array_keys($snapshot['tables']),422,'بيانات التجهيز غير مكتملة.');
            foreach($rowHashes as $digest)abort_unless(is_string($digest)&&preg_match('/^[a-f0-9]{64}$/D',$digest),422,'بيانات التجهيز غير مكتملة.');
        }
        abort_unless(!array_key_exists('catalog_scopes',$snapshot['coverage'])||in_array($snapshot['coverage']['catalog_scopes'],[0,1],true),422,'وصف تغطية نطاقات الكتالوج غير صالح.');
        $catalog=($snapshot['coverage']['catalog_scopes']??null)===1;
        $base=$snapshot['enrollment']['base_branches']??$snapshot['branches'];
        $this->validateBaseBranches($base);
        if($catalog){
            abort_unless(($snapshot['coverage']['print_protocol']??null)===1&&isset($snapshot['enrollment']['base_branches'])&&is_array($snapshot['created_scopes']??null)
                &&hash_equals($snapshot['coverage']['catalog_grants_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json($snapshot['created_scopes'])))
                &&hash_equals($snapshot['coverage']['base_branches_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json($base)))
                &&hash_equals($snapshot['coverage']['catalog_branches_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json($snapshot['branches']))),422,'بيانات نطاقات الكتالوج أو فروع الجهاز غير متطابقة.');
        }else abort_unless($base===$snapshot['branches']&&empty($snapshot['created_scopes']),422,'فروع نسخة التجهيز أو نطاقاتها غير متطابقة.');
        $media=DesktopDashboardMedia::receipt($snapshot['media']??[]);
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
            abort_unless(is_string($part['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$part['sha256'])
                &&hash_equals($part['sha256'],$rowHashes===null?hash('sha256',DesktopDashboardBootstrap::json($part['rows'])):$rowHashes[$table]),422,'بيانات التجهيز غير مكتملة.');
            $schema[$table]=hash('sha256',$ddl);$rowCount+=count($part['rows']);abort_if($rowCount>500000,413);
        }
        ksort($schema);abort_unless(hash_equals($snapshot['schema_hash'],hash('sha256',DesktopDashboardBootstrap::json($schema))),422,'بصمة مخطط التجهيز مختلفة.');
        $users=$snapshot['tables']['users']['rows']??[];
        abort_unless(count(array_filter($users,fn($row)=>(int)($row['id']??0)===(int)$snapshot['actor_id']))===1,422,'بيانات حساب مستخدم الجهاز مفقودة أو مكررة.');
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
        require_once database_path('migrations/2026_10_08_210000_create_desktop_dashboard_archived_commands.php');
        (new \CreateDesktopDashboardArchivedCommands)->up();
        require_once database_path('migrations/2026_10_08_220000_create_desktop_dashboard_media_manifest.php');
        (new \CreateDesktopDashboardMediaManifest)->up();
        require_once database_path('migrations/2026_10_08_230000_create_desktop_dashboard_source_manifest.php');
        (new \CreateDesktopDashboardSourceManifest)->up();
        if(($snapshot['coverage']['print_protocol']??null)===1){
            Validator::make(self::validationMetadata($snapshot,['enrollment']),['enrollment.enrollment_hash'=>'required|regex:/^[a-f0-9]{64}$/D','enrollment.enrolled_at'=>'required|date'])->validate();
            DB::table('desktop_dashboard_devices')->insert(['id'=>$snapshot['device_id'],'actor_id'=>$snapshot['actor_id'],'name'=>'Local dashboard',
                'enrollment_hash'=>$snapshot['enrollment']['enrollment_hash'],'enrolled_at'=>$snapshot['enrollment']['enrolled_at'],
                // This is not a server bearer credential and is never used for server authentication.
                'token_hash'=>hash('sha256',random_bytes(32)),'branches'=>DesktopDashboardBootstrap::json($base),
                'enabled'=>true,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            app(DesktopDashboardPrintLedgerSchema::class)->install();
            app(DesktopDashboardPrintLedger::class)->bind($snapshot['device_id']);
        }
        if($catalog){require_once database_path('migrations/2026_10_20_000002_create_desktop_dashboard_created_scopes.php');(new \CreateDesktopDashboardCreatedScopes)->up();}
        $sourceJson=DesktopDashboardBootstrap::json($snapshot['source']);
        DB::table('desktop_dashboard_source_manifest')->insert(['device_id'=>$snapshot['device_id'],'snapshot_id'=>$snapshot['snapshot_id'],
            'sha256'=>hash('sha256',$sourceJson),'source'=>$sourceJson]);
        $mediaJson=DesktopDashboardBootstrap::json($media);
        DB::table('desktop_dashboard_media_manifest')->insert(['device_id'=>$snapshot['device_id'],'snapshot_id'=>$snapshot['snapshot_id'],
            'sha256'=>hash('sha256',$mediaJson),'files'=>$mediaJson]);
        DB::table('desktop_dashboard_local_state')->insert([
            'device_id'=>$snapshot['device_id'],'actor_id'=>$snapshot['actor_id'],'snapshot_id'=>$snapshot['snapshot_id'],
            'schema_hash'=>$snapshot['schema_hash'],'branches'=>DesktopDashboardBootstrap::json($snapshot['branches']),
            'coverage'=>DesktopDashboardBootstrap::json($snapshot['coverage']),'state'=>'ready',
            'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ]);
        if($catalog)DB::transaction(fn()=>app(DesktopDashboardCreatedScopes::class)->restore($snapshot));
        return ['format'=>1,'snapshot_id'=>$snapshot['snapshot_id'],'device_id'=>$snapshot['device_id'],'actor_id'=>$snapshot['actor_id'],
            'schema_hash'=>$snapshot['schema_hash'],'tables'=>count($schema),'rows'=>$rowCount,'coverage'=>$snapshot['coverage'],
            'source'=>$snapshot['source'],
            'branches'=>$snapshot['branches'],'table_rows'=>array_map(fn($part)=>count($part['rows']),$snapshot['tables']),'media'=>$media];
    }

    protected function validateImportMetadata(array $snapshot): void
    {
        Validator::make(self::validationMetadata($snapshot,
            ['format','kind','snapshot_id','device_id','actor_id','branches','schema_hash','tables','coverage'],['tables','coverage']),
            ['format'=>'required|in:1','kind'=>'required|in:initial-dashboard-data',
            'snapshot_id'=>'required|uuid','device_id'=>'required|uuid','actor_id'=>'required|integer|min:1',
            'branches'=>'required|array|min:1','branches.*'=>['required','distinct','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'schema_hash'=>'required|regex:/^[a-f0-9]{64}$/D','tables'=>'required|array',
            'coverage'=>'required|array'])->validate();
    }
    protected function validateBaseBranches(array $base): void
    {
        Validator::make(['base'=>$base],['base'=>'required|array|min:1','base.*'=>['required','distinct','regex:/^(f|gs):[1-9][0-9]{0,18}$/D']])->validate();
    }
    protected function validateVerifyMetadata(array $receipt): void
    {
        Validator::make($receipt,['format'=>'required|in:1','snapshot_id'=>'required|uuid','device_id'=>'required|uuid',
            'actor_id'=>'required|integer|min:1','schema_hash'=>'required|regex:/^[a-f0-9]{64}$/D',
            'branches'=>'required|array|min:1','coverage'=>'required|array','table_rows'=>'required|array|min:1',
            'tables'=>'required|integer|min:1','rows'=>'required|integer|min:0'])->validate();
    }
    protected function assertReplayHistory(array $receipt): void
    {
        abort_unless(DB::table('desktop_dashboard_commands')->count()===0
            &&DB::table('users')->where('id',$receipt['actor_id'])->exists()
            &&!DB::table('desktop_dashboard_archived_commands')->where(function($q)use($receipt){
                $q->where('device_id','<>',$receipt['device_id'])->orWhere('actor_id','<>',$receipt['actor_id']);
            })->exists(),409);
    }

    protected static function validationMetadata(array $snapshot,array $keys,array $containers=[]): array
    {
        $data=array_intersect_key($snapshot,array_fill_keys($keys,true));
        // Laravel recursively copies validation data and its returned result.
        // These containers have only required|array rules; retain their exact
        // empty/nonempty and type semantics without copying every business row.
        foreach($containers as $key)if(isset($data[$key])&&is_array($data[$key]))$data[$key]=$data[$key]===[]?[]:[true];
        return $data;
    }

    /** Reopen and inspect the staging database in an independent native PHP process. */
    public function verify(array $receipt,?array $mediaSeal=null): array
    {
        abort_unless(config('desktop_dashboard.local')&&DB::connection()->getConfig('host')==='127.0.0.1'
            &&preg_match('/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D',DB::connection()->getDatabaseName()),403);
        $this->validateVerifyMetadata($receipt);
        abort_unless($receipt['device_id']===(string)config('desktop_dashboard.device_id'),403);
        abort_unless(app(DesktopDashboardSource::class)->matches($receipt['source']??null),409,'مصدر برنامج التجهيز لا يطابق النسخة المعتمدة.');
        $result=DB::transaction(function()use($receipt){
            app(DesktopDashboardPrintLedger::class)->fence($receipt['device_id']);
            $state=DB::table('desktop_dashboard_local_state')->where('device_id',$receipt['device_id'])->lockForUpdate()->first();
            abort_unless($state&&$state->state==='ready'&&$state->snapshot_id===$receipt['snapshot_id']
                &&(int)$state->actor_id===(int)$receipt['actor_id']&&$state->schema_hash===$receipt['schema_hash']
                &&json_decode($state->branches,true)===$receipt['branches']
                &&json_decode($state->coverage,true)===$receipt['coverage'],409,'قاعدة التجهيز لا تطابق النسخة المعتمدة.');
            if(($receipt['coverage']['print_protocol']??null)===1)app(DesktopDashboardPrintLedger::class)->verifyEnrollment($receipt['device_id']);
            if(($receipt['coverage']['catalog_scopes']??null)===1){
                $device=DB::table('desktop_dashboard_devices')->where('id',$receipt['device_id'])->lockForUpdate()->first();$actor=\App\Models\User::withoutGlobalScopes()->whereKey($receipt['actor_id'])->lockForUpdate()->firstOrFail();
                $grants=app(DesktopDashboardCreatedScopes::class)->export($device,$actor);
                abort_unless(hash_equals($receipt['coverage']['catalog_grants_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json($grants)))
                    &&hash_equals($receipt['coverage']['base_branches_sha256']??'',hash('sha256',DesktopDashboardBootstrap::json(json_decode($device->branches,true))))
                    &&app(DesktopDashboardCreatedScopes::class)->effective($device,$actor)===$receipt['branches'],409);
            }
            $source=DB::table('desktop_dashboard_source_manifest')->where('device_id',$receipt['device_id'])->first();
            abort_unless($source&&$source->snapshot_id===$receipt['snapshot_id']
                &&hash_equals($source->sha256,hash('sha256',DesktopDashboardBootstrap::json($receipt['source'])))
                &&json_decode($source->source,true)===$receipt['source'],409,'مصدر بيانات التجهيز لا يطابق النسخة المعتمدة.');
            $this->assertReplayHistory($receipt);
            $rows=0;
            foreach($receipt['table_rows'] as $table=>$count){
                abort_unless(in_array($table,DesktopDashboardSchema::TABLES,true)&&is_int($count)&&$count>=0,422);
                abort_unless(DB::table($table)->count()===$count,409,'عدد سجلات التجهيز غير متطابق.');$rows+=$count;
            }
            abort_unless(count($receipt['table_rows'])===$receipt['tables']&&$rows===$receipt['rows'],409);
            $media=DesktopDashboardMedia::receipt($receipt['media']??[]);
            $manifest=DB::table('desktop_dashboard_media_manifest')->where('device_id',$receipt['device_id'])->first();
            abort_unless($manifest&&$manifest->snapshot_id===$receipt['snapshot_id']
                &&hash_equals($manifest->sha256,hash('sha256',DesktopDashboardBootstrap::json($media))),409,'قائمة صور التجهيز لا تطابق النسخة المعتمدة.');
            return array_merge($receipt,['verified'=>true]);
        });
        // The inactive generation is private to the native staging process.
        // Do not retain the shared print-ledger scope lock while hashing a
        // gigabyte of attachments: the active cashier uses that same scope.
        if($mediaSeal===null)DesktopDashboardMedia::verifyFiles($result['media']??[]);
        else DesktopDashboardMediaSeal::verify($result['media']??[],$mediaSeal);
        return $result;
    }
}
