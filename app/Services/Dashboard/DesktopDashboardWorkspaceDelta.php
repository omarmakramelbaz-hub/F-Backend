<?php
namespace App\Services\Dashboard;

/** Separate group delta namespace: an actor-scoped index can never delete another member's rows. */
final class DesktopDashboardWorkspaceDelta
{
    public function __construct(private DesktopDashboardWorkspaceBootstrap $bootstrap,private DesktopDashboardWorkspaceAuthority $authority) {}
    public function export(object $root,array $index): array
    {
        abort_if(config('desktop_dashboard.local')===true,403);$this->authority->ready();
        self::need(self::exact($index,['format','kind','device_id','actor_id','workspace','source','tables'])
            &&$index['format']===2&&$index['kind']==='desktop-workspace-delta-index'
            &&$index['device_id']===(string)$root->id&&$index['actor_id']===(int)$root->custodian_actor_id
            &&is_array($index['workspace'])&&self::exact($index['workspace'],['workspace_id','root_fingerprint','revision','roster_sha256'])
            &&$index['workspace']['workspace_id']===(string)$root->id
            &&$index['workspace']['root_fingerprint']===$this->authority->rootFingerprint($root)
            &&is_int($index['workspace']['revision'])&&$index['workspace']['revision']>0
            &&is_string($index['workspace']['roster_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$index['workspace']['roster_sha256'])
            &&is_array($index['source'])&&is_array($index['tables'])&&count($index['tables'])<=DesktopDashboardDeltaCodec::MAX_TABLES);
        abort_unless(app(DesktopDashboardSource::class)->matches($index['source']),409,'نسخة برنامج الجهاز لا تطابق مصدر بيانات المزامنة.');
        // Load the predecessor from authenticated server history. A client
        // cannot nominate an arbitrary old roster or replace its signature.
        $beforeManifest=$this->authority->historicalManifest($root,$index['workspace']['revision']);
        $beforeCodec=new DesktopDashboardWorkspaceDeltaCodec($beforeManifest);
        self::need(DesktopDashboardWorkspaceAuthority::canonical($beforeCodec->context())
            ===DesktopDashboardWorkspaceAuthority::canonical($index['workspace']));$total=0;
        foreach($index['tables'] as $table=>$part){
            self::need(is_string($table)&&in_array($table,DesktopDashboardSchema::TABLES,true)&&is_array($part));
            $beforeCodec->validateIndex($table,$part);$total+=$part['count'];
            abort_if($total>DesktopDashboardDeltaCodec::MAX_ROWS,413,'فهرس مساحة عمل الجهاز يحتاج مزامنة على دفعات.');
        }
        $snapshot=$this->bootstrap->export($root);$codec=new DesktopDashboardWorkspaceDeltaCodec($snapshot['workspace_manifest']);
        self::need($snapshot['device_id']===$index['device_id']&&$snapshot['actor_id']===$index['actor_id']);
        abort_unless(app(DesktopDashboardSource::class)->matches($index['source']),409,'مصدر البرنامج تغير أثناء مزامنة مساحة العمل.');
        $previous=$codec->context()===$beforeCodec->context()?null:$beforeCodec;
        $retired=array_diff(array_keys($index['tables']),array_keys($snapshot['tables']));
        $summary=['changed_tables'=>count($retired),'added_rows'=>0,'removed_rows'=>0];
        foreach($snapshot['tables'] as $table=>&$part){
            $projection=DesktopDashboardDeltaCodec::projection($table,$snapshot['coverage']);
            $new=!array_key_exists($table,$index['tables']);
            $before=$index['tables'][$table]??$beforeCodec->index($table,[],$part['ddl'],$projection);
            $delta=$codec->delta($table,$part['rows'],$part['ddl'],$before,$projection,$previous);
            if($new||($delta['replace']??false)===true||$delta['add']!==[]||$delta['remove']!==[])$summary['changed_tables']++;
            $summary['added_rows']+=count($delta['add']);$summary['removed_rows']+=array_sum($delta['remove']);
            $part=['ddl'=>$part['ddl'],'delta'=>$delta];
        }
        unset($part);$snapshot['kind']='workspace-data-delta';$snapshot['delta_summary']=$summary;
        return $snapshot;
    }
    private static function exact(array $value,array $keys): bool {return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];}
    private static function need(bool $condition): void {abort_unless($condition,422,'فهرس مزامنة مساحة عمل الجهاز غير صالح؛ البيانات الحالية محفوظة.');}
}
