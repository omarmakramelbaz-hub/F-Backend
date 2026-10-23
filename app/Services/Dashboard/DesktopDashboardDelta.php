<?php
namespace App\Services\Dashboard;

/** Stateless scoped differences. No server business row, cursor or device state is changed. */
final class DesktopDashboardDelta
{
    public function __construct(private DesktopDashboardBootstrap $bootstrap) {}

    private static function exact(array $value,array $keys): bool
    {
        return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];
    }
    private static function need(bool $value): void
    {
        abort_unless($value,422,'وصف مزامنة التعديلات غير صالح؛ بيانات الجهاز محفوظة.');
    }
    public static function requestBudget(): void
    {
        $parse=static function($value): ?float {
            $value=trim((string)$value);if($value==='-1')return INF;
            if(!preg_match('/^([0-9]+)\s*([KMG]?)$/iD',$value,$match))return null;
            return (float)$match[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($match[2])]));
        };
        $limit=512*1024*1024;$before=$parse(ini_get('memory_limit'));
        $message='ذاكرة طلب مزامنة التعديلات غير كافية؛ بيانات الجهاز محفوظة.';
        abort_unless($before!==null&&memory_get_usage(true)<$limit,503,$message);if($before===(float)$limit)return;
        abort_unless(@ini_set('memory_limit','512M')!==false&&($parse(ini_get('memory_limit'))??0)===(float)$limit,503,$message);
    }
    public function export(object $device,array $index): array
    {
        abort_if(config('desktop_dashboard.local')===true,403);
        self::need(self::exact($index,['format','kind','device_id','actor_id','source','tables'])
            &&$index['format']===1&&$index['kind']==='dashboard-delta-index'
            &&is_string($index['device_id'])&&$index['device_id']===(string)$device->id
            &&is_int($index['actor_id'])&&$index['actor_id']===(int)$device->actor_id
            &&is_array($index['source'])&&is_array($index['tables'])
            &&count($index['tables'])<=DesktopDashboardDeltaCodec::MAX_TABLES);
        abort_unless(app(DesktopDashboardSource::class)->matches($index['source']),409,
            'نسخة البرنامج لا تطابق بيانات السيرفر؛ التحديث التلقائي يحتفظ ببيانات الجهاز.');
        $count=0;
        foreach($index['tables'] as $table=>$part){
            self::need(is_string($table)&&in_array($table,DesktopDashboardSchema::TABLES,true)&&is_array($part));
            DesktopDashboardDeltaCodec::validateIndex($part);$count+=$part['count'];
            abort_if($count>DesktopDashboardDeltaCodec::MAX_ROWS,413,'وصف التعديلات يتجاوز حد المزامنة؛ بيانات الجهاز محفوظة.');
        }
        // Reuse the same authenticated repeatable-read scopes, redaction,
        // enrollment checks, parent closure and media capability checks as setup.
        // The complete rows are scanned on the server but never sent unchanged.
        $snapshot=$this->bootstrap->export($device);
        self::need(($snapshot['device_id']??null)===$index['device_id']&&($snapshot['actor_id']??null)===$index['actor_id']);
        // New code may introduce a table absent from the retained old runtime.
        // Retired allowed tables are not carried into the fresh target snapshot.
        // The client never supplies a DDL statement or expands the server scope.
        // A source update during the authenticated export must not be matched
        // against a previously valid index.
        abort_unless(app(DesktopDashboardSource::class)->matches($index['source']),409,
            'مصدر البرنامج تغير أثناء المزامنة؛ سيُعاد الفحص تلقائيًا.');
        $retired=array_diff(array_keys($index['tables']),array_keys($snapshot['tables']));
        $summary=['changed_tables'=>count($retired),'added_rows'=>0,'removed_rows'=>0];
        foreach($snapshot['tables'] as $table=>&$part){
            $projection=DesktopDashboardDeltaCodec::projection($table,$snapshot['coverage']);
            $new=!array_key_exists($table,$index['tables']);
            $before=$index['tables'][$table]??['schema_sha256'=>DesktopDashboardDeltaCodec::schemaHash($part['ddl']),'count'=>0,'hashes'=>[]];
            $delta=DesktopDashboardDeltaCodec::delta($table,$part['rows'],$snapshot['actor_id'],$part['ddl'],$before,$projection);
            if($new||($delta['replace']??false)===true||$delta['add']!==[]||$delta['remove']!==[])$summary['changed_tables']++;
            $summary['added_rows']+=count($delta['add']);$summary['removed_rows']+=array_sum($delta['remove']);
            // No client SQL, row values or redaction instructions are accepted.
            // Initial table checksums hash the wire array and are not a delta identity.
            $part=['ddl'=>$part['ddl'],'delta'=>$delta];
        }
        unset($part);$snapshot['kind']='dashboard-data-delta';$snapshot['delta_summary']=$summary;
        return $snapshot;
    }
}
