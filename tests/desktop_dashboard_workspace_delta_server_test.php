<?php
// Synthetic server boundary with the production group Delta and both SQL codecs.
namespace App\Services\Dashboard {
    class DesktopDashboardSchema {public const TABLES=['users','products','pending_vendors'];}
    class DesktopDashboardSource {public bool $changed=false;private int $calls=0;public function matches($source):bool{return $source===\source()&&!($this->changed&&++$this->calls>1);}}
    class DesktopDashboardWorkspaceBootstrap {public int $calls=0;public array $snapshot;public function export($root):array{$this->calls++;return $this->snapshot;}}
    class DesktopDashboardWorkspaceAuthority {
        public array $history=[];public function ready():void{}public function rootFingerprint($root):string{return str_repeat('a',64);}
        public function historicalManifest($root,$revision):array{\abort_unless(isset($this->history[$revision]),409);return $this->history[$revision];}
        public static function canonical($value):string{return \canonical($value);}
    }
}
namespace {
    function abort_unless($condition,$status,$message=''){if(!$condition)throw new RuntimeException('FIXED_'.$status);}
    function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
    function config($key){return $GLOBALS['local'];}
    function app($class){if($class===\App\Services\Dashboard\DesktopDashboardSource::class)return $GLOBALS['source'];throw new RuntimeException('Unexpected synthetic service.');}
    function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
    function refused(callable $work){try{$work();}catch(Throwable $error){return;}throw new RuntimeException('Invalid group request was accepted.');}
    function canonical($value):string{
        $walk=function($item)use(&$walk){if(is_array($item)){if($item!==[]&&!array_is_list($item))ksort($item,SORT_STRING);foreach($item as &$child)$child=$walk($child);unset($child);}return $item;};
        return json_encode($walk($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR);
    }
    function source():array{return ['format'=>1,'files'=>1,'framework'=>'8.83.29','sha256'=>str_repeat('f',64)];}
    function ddl(string $table):string{return $table==='users'?"CREATE TABLE `users` (\n `id` bigint unsigned NOT NULL,\n `password` varchar(255) NOT NULL\n) ENGINE=InnoDB": "CREATE TABLE `$table` (\n `id` bigint unsigned NOT NULL,\n `name` varchar(255) NOT NULL\n) ENGINE=InnoDB";}
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardDeltaCodec.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceDeltaCodec.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceDelta.php';
    use App\Services\Dashboard\{DesktopDashboardWorkspaceDeltaCodec as Codec,DesktopDashboardWorkspaceDelta as Delta,
        DesktopDashboardWorkspaceBootstrap as Bootstrap,DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardSource as Source};
    function environment():array {
        $GLOBALS['local']=false;$GLOBALS['source']=new Source;$manifest=json_decode(file_get_contents(__DIR__.'/fixtures/desktop-workspace-delta-codec-v2.json'),true,64,JSON_THROW_ON_ERROR)['manifest'];
        $hashA=password_hash('synthetic-A',PASSWORD_BCRYPT,['cost'=>4]);$hashB=password_hash('synthetic-B',PASSWORD_BCRYPT,['cost'=>4]);
        $manifest['members'][0]['password_binding']=hash('sha256',$hashA);$manifest['members'][1]['password_binding']=hash('sha256',$hashB);
        $manifest['roster_sha256']=hash('sha256',canonical($manifest['members']));$codec=new Codec($manifest);
        $bootstrap=new Bootstrap;$bootstrap->snapshot=['format'=>2,'kind'=>'initial-workspace-data','device_id'=>$manifest['workspace_id'],'actor_id'=>202,
            'snapshot_id'=>'22222222-2222-4222-8222-222222222222','source'=>source(),'coverage'=>[], 'workspace_manifest'=>$manifest,
            'tables'=>['users'=>['ddl'=>ddl('users'),'rows'=>[['id'=>101,'password'=>$hashA],['id'=>202,'password'=>$hashB],['id'=>303,'password'=>'!desktop-disabled-old']], 'sha256'=>'wire-only'],
                'products'=>['ddl'=>ddl('products'),'rows'=>[['id'=>1,'name'=>'A order'],['id'=>2,'name'=>'B order']],'sha256'=>'wire-only']]];
        $authority=new Authority;$authority->history=[1=>$manifest];$root=(object)['id'=>$manifest['workspace_id'],'custodian_actor_id'=>202];
        // Reverse context key order intentionally: JSON object order is not authority.
        $index=['format'=>2,'kind'=>'desktop-workspace-delta-index','device_id'=>$root->id,'actor_id'=>202,
            'workspace'=>array_reverse($codec->context(),true),'source'=>source(),'tables'=>[]];
        foreach($bootstrap->snapshot['tables'] as $table=>$part)$index['tables'][$table]=$codec->index($table,$part['rows'],$part['ddl']);
        return [$root,$bootstrap,$authority,new Delta($bootstrap,$authority),$index,$codec];
    }
    $cases=[];
    $cases['unchanged-union-is-empty-delta']=function(){[$root,$bootstrap,$authority,$delta,$index]=environment();$result=$delta->export($root,$index);
        check($result['kind']==='workspace-data-delta'&&$bootstrap->calls===1,'Group export engine was bypassed.');
        foreach($result['tables'] as $part)check($part['delta']['add']===[]&&$part['delta']['remove']===[],'Unchanged B rows were treated as foreign actor data.');
        check($result['delta_summary']===['changed_tables'=>0,'added_rows'=>0,'removed_rows'=>0],'Unchanged union appeared changed.');};
    $cases['ordinary-A-change-keeps-B-row']=function(){[$root,$bootstrap,$authority,$delta,$index,$codec]=environment();$old=$bootstrap->snapshot['tables']['products']['rows'];$bootstrap->snapshot['tables']['products']['rows'][0]['name']='A updated';
        $result=$delta->export($root,$index);$change=$result['tables']['products']['delta'];$actual=$codec->apply('products',$old,ddl('products'),$change);
        check(in_array(['id'=>2,'name'=>'B order'],$actual,true),'A change removed B shared row.');};
    $cases['B-password-change-requires-server-authenticated-forward-roster']=function(){[$root,$bootstrap,$authority,$delta,$index,$before]=environment();$manifest=$bootstrap->snapshot['workspace_manifest'];$manifest['revision']=2;
        $hash=password_hash('synthetic-B-rotated',PASSWORD_BCRYPT,['cost'=>4]);$manifest['members'][1]['password_binding']=hash('sha256',$hash);
        $manifest['roster_sha256']=hash('sha256',canonical($manifest['members']));$bootstrap->snapshot['workspace_manifest']=$manifest;$bootstrap->snapshot['tables']['users']['rows'][1]['password']=$hash;
        $result=$delta->export($root,$index);$change=$result['tables']['users']['delta'];check(($change['replace']??false)===true,'Forward roster transition did not replace safely.');
        check(in_array($hash,array_column($change['add'],'password'),true),'B rotated verifier was dropped from transport.');
        $after=new Codec($manifest);$rebuilt=$after->applyReplacement('users',$change['add'],ddl('users'),$change,$index['tables']['users'],null,$before);
        check(count($rebuilt)===3,'Forward roster union replacement lost a user.');};
    $cases['legacy-and-cross-physical-indices-refused-before-export']=function(){foreach(['format','kind','device','actor','workspace','root','roster'] as $field){[$root,$bootstrap,$authority,$delta,$index]=environment();
        if($field==='format')$index['format']=1;elseif($field==='kind')$index['kind']='dashboard-delta-index';elseif($field==='device')$index['device_id']='33333333-3333-4333-8333-333333333333';elseif($field==='actor')$index['actor_id']=101;
        elseif($field==='workspace')$index['workspace']['workspace_id']='33333333-3333-4333-8333-333333333333';elseif($field==='root')$index['workspace']['root_fingerprint']=str_repeat('b',64);else $index['workspace']['roster_sha256']=str_repeat('b',64);
        refused(fn()=>$delta->export($root,$index));check($bootstrap->calls===0,'Foreign group index reached snapshot export.');}};
    $cases['unknown-historical-revision-and-table-context-refused']=function(){[$root,$bootstrap,$authority,$delta,$index]=environment();$index['workspace']['revision']=99;refused(fn()=>$delta->export($root,$index));
        [$root,$bootstrap,$authority,$delta,$index]=environment();$index['tables']['users']['workspace_id']='33333333-3333-4333-8333-333333333333';refused(fn()=>$delta->export($root,$index));check($bootstrap->calls===0,'Wrong table context reached export.');};
    $cases['source-update-before-or-during-export-refused']=function(){[$root,$bootstrap,$authority,$delta,$index]=environment();$index['source']['sha256']=str_repeat('b',64);refused(fn()=>$delta->export($root,$index));check($bootstrap->calls===0,'Wrong source reached export.');
        [$root,$bootstrap,$authority,$delta,$index]=environment();$GLOBALS['source']->changed=true;refused(fn()=>$delta->export($root,$index));check($bootstrap->calls===1,'Source race was not checked after export.');};
    $cases['new-empty-table-and-retired-table-remain-structural-changes']=function(){[$root,$bootstrap,$authority,$delta,$index]=environment();$bootstrap->snapshot['tables']['pending_vendors']=['ddl'=>ddl('pending_vendors'),'rows'=>[],'sha256'=>'wire'];
        $result=$delta->export($root,$index);check($result['delta_summary']['changed_tables']===1&&$result['tables']['pending_vendors']['delta']['count']===0,'New empty table disappeared.');
        [$root,$bootstrap,$authority,$delta,$index,$codec]=environment();$index['tables']['pending_vendors']=$codec->index('pending_vendors',[],ddl('pending_vendors'));$result=$delta->export($root,$index);check($result['delta_summary']['changed_tables']===1&&!isset($result['tables']['pending_vendors']),'Retired table was retained.');};
    foreach($cases as $name=>$work){$work();echo 'PASS workspace-delta-server '.$name.PHP_EOL;}
    echo 'Workspace delta server: '.count($cases).' synthetic cases passed; no database/network used.'.PHP_EOL;
}
