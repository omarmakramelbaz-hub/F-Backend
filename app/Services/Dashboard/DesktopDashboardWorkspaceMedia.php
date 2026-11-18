<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{Crypt,DB};

/** Native-only media transport for one authority-issued physical workspace audience. */
final class DesktopDashboardWorkspaceMedia extends DesktopDashboardMedia
{
    private const TICKET_SECONDS=3600;
    private const SAFE=9007199254740991;

    public function __construct(DesktopDashboardDevices $devices,DesktopDashboardData $legacyData,
        private DesktopDashboardWorkspaceAuthority $authority,private DesktopDashboardWorkspaceData $workspaceData)
    {
        parent::__construct($devices,$legacyData);
    }

    /** Dataset and file authority must share the exact authenticated snapshot audience. */
    public function manifestForAudience(array $dataset,DesktopDashboardWorkspaceSnapshotAudience $audience,string $snapshot): array
    {
        self::need(self::uuid($snapshot),422);
        $this->authority->assertSnapshotAudience($audience);
        $context=$audience->context();
        $queries=$this->workspaceData->queries($audience);
        return $this->buildManifest($dataset,$queries,function(string $path,string $sha,int $bytes,string $mime,array $proof,string $area)use($context,$snapshot): string {
            $value=['format'=>2,'kind'=>'desktop-workspace-media-ticket']+$context+[
                'snapshot_id'=>$snapshot,'path'=>$path,'sha256'=>$sha,'bytes'=>$bytes,'mime'=>$mime,
                'proof'=>$proof,'expires_at'=>time()+self::TICKET_SECONDS];
            if($area==='private')$value['area']='private';
            return Crypt::encryptString(DesktopDashboardWorkspaceAuthority::canonical($value));
        });
    }

    /** A ticket cannot retain the scope of a stale roster or a replaced physical enrollment. */
    public function downloadWorkspace(object $root,string $ticket): array
    {
        self::need(strlen($ticket)>0&&strlen($ticket)<=8192,422);
        try{$value=json_decode(Crypt::decryptString($ticket),true,32,JSON_THROW_ON_ERROR);}
        catch(\Throwable $error){abort(422);}
        $this->validateTicket($value);
        return DB::transaction(function()use($root,$value): array {
            $fresh=$this->authority->lockAuthenticated($root);
            $audience=$this->authority->snapshotAudience($fresh);
            foreach($audience->context() as $key=>$expected)
                self::need(array_key_exists($key,$value)&&$value[$key]===$expected,403);
            $queries=$this->workspaceData->queries($audience);
            return $this->downloadAuthorized($value,$queries);
        });
    }

    private function validateTicket($value): void
    {
        self::need(is_array($value),422);
        $keys=['format','kind','workspace_id','revision','root_fingerprint','roster_sha256',
            'snapshot_id','path','sha256','bytes','mime','proof','expires_at'];
        if(array_key_exists('area',$value))$keys[]='area';
        self::need(self::exact($value,$keys)&&$value['format']===2&&$value['kind']==='desktop-workspace-media-ticket'
            &&self::uuid($value['workspace_id'])&&self::positive($value['revision'])
            &&self::hash($value['root_fingerprint'])&&self::hash($value['roster_sha256'])
            &&self::uuid($value['snapshot_id'])&&is_int($value['expires_at'])
            &&$value['expires_at']>=time()&&$value['expires_at']<=time()+self::TICKET_SECONDS,403);
        // Reuse the exact importer path/MIME/size contract before resolving any file.
        self::receipt([array_intersect_key($value,array_flip(['path','sha256','bytes','mime','area']))]);
        $proof=$value['proof'];self::need(is_array($proof)&&is_string($proof['kind']??null),403);
        switch($proof['kind']){
            case 'media':
                self::need(self::exact($proof,['kind','id','disk','relative'])&&self::proofId($proof['id'])
                    &&is_string($proof['disk'])&&$proof['disk']!==''&&strlen($proof['disk'])<=100
                    &&self::safePath($proof['relative']),403);break;
            case 'store-product':case 'expense':
                self::need(self::exact($proof,['kind','id'])&&self::proofId($proof['id']),403);break;
            case 'employee-id':
                self::need(self::exact($proof,['kind','id','side'])&&self::proofId($proof['id'])
                    &&in_array($proof['side'],EmployeeIdentity::SIDES,true),403);break;
            case 'setting':
                self::need(self::exact($proof,['kind','name','source_path'])&&is_string($proof['name'])
                    &&is_string($proof['source_path'])&&strlen($proof['source_path'])<=500,403);break;
            default:abort(403);
        }
    }
    private static function exact(array $value,array $keys): bool
    {
        return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];
    }
    private static function uuid($value): bool
    {
        return is_string($value)&&(bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value);
    }
    private static function hash($value): bool {return is_string($value)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$value);}
    private static function positive($value): bool {return is_int($value)&&$value>0&&$value<=self::SAFE;}
    /** Proof IDs remain encrypted server-only values and retain the original PHP integer range. */
    private static function proofId($value): bool {return is_int($value)&&$value>0;}
    private static function need(bool $value,int $status): void
    {
        abort_unless($value,$status,'رابط مرفق مساحة عمل الجهاز غير صالح؛ يلزم تحديث التجهيز.');
    }
}
