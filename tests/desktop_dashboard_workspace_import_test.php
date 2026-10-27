<?php
// Pure trust-boundary tests. SQL/FK/media execution is covered by the runtime fixture, not these stubs.
namespace Illuminate\Support\Facades {
    class DB {
        public static int $level=0;public static function transactionLevel(){return self::$level;}
        public static function transaction($work){self::$level++;try{return $work();}finally{self::$level--;}}
        public static function connection(){return new class {function getConfig($key){return '127.0.0.1';}function getDatabaseName(){return 'fasakhansta_dashboard_stage_0123456789abcdef';}};}
        public static function table($name){return new class($name){function __construct(private string $name){}function where(...$args){return $this;}function count(){return 0;}function exists(){return $this->name==='users';}};}
    }
    class Validator {public static function make($data,$rules){return new class {function validate(){return [];}};}}
}
namespace App\Services\Dashboard {
    class DesktopDashboardBootstrap {public static function json($value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}}
    class DesktopDashboardImport {
        public static int $imports=0;
        public function import(array $snapshot,?array $hashes=null):array{
            $this->validateImportMetadata($snapshot);$this->validateBaseBranches($snapshot['branches']);$this->assertPrincipalRows($snapshot['tables']['users']['rows'],$snapshot);$this->validateCatalogEnvelope($snapshot,$snapshot['branches']);
            \abort_unless($snapshot['source']===\source(),409);$schema=[];
            foreach($snapshot['tables'] as $table=>$part){\abort_unless($part['sha256']===($hashes[$table]??hash('sha256',DesktopDashboardBootstrap::json($part['rows']))),422);$schema[$table]=hash('sha256',$part['ddl']);}
            ksort($schema);\abort_unless($snapshot['schema_hash']===hash('sha256',DesktopDashboardBootstrap::json($schema)),422);self::$imports++;
            if($snapshot['coverage']['catalog_scopes']===1)$this->restoreCatalogSnapshot($snapshot);
            return array_intersect_key($snapshot,array_flip(['snapshot_id','device_id','actor_id','branches','source','schema_hash','coverage']))+['format'=>1,'tables'=>count($snapshot['tables']),'rows'=>count($snapshot['tables']['users']['rows']),'table_rows'=>['users'=>count($snapshot['tables']['users']['rows'])],'media'=>[]];
        }
        public function verify(array $receipt,?array $seal=null):array{$this->validateVerifyMetadata($receipt);if($receipt['coverage']['catalog_scopes']===1)$this->verifyCatalogReceipt($receipt);$this->assertReplayHistory($receipt);return $receipt+['verified'=>true];}
        protected function validateCatalogEnvelope(array $snapshot,array $base):void{\abort_unless($snapshot['created_scopes']===[]&&$base===$snapshot['branches'],422);}
        protected static function validationMetadata(array $snapshot,array $keys,array $containers=[]):array{return array_intersect_key($snapshot,array_flip($keys));}
    }
    class DesktopDashboardWorkspaceLocalAuthority {
        public array $manifest=[];
        public function install(DesktopDashboardWorkspaceImportProvenance $proof):array{$proof->assertVerified();$context=$proof->context();$this->manifest=$context['manifest'];$GLOBALS['installed_context']=$context;
            return ['workspace_id'=>$this->manifest['workspace_id'],'root_fingerprint'=>$this->manifest['root_fingerprint'],'manifest_revision'=>$this->manifest['revision'],'roster_sha256'=>$this->manifest['roster_sha256'],'manifest_sha256'=>hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($this->manifest))];}
        public function verifyImport($receipt,$expected):array{\abort_unless($receipt['manifest_sha256']===hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($this->manifest))&&$expected['revision']===$this->manifest['revision'],422);return $this->manifest;}
    }
    class DesktopDashboardPrintLedger {public function bind($device):void{\check(isset($GLOBALS['installed_context'])&&$GLOBALS['installed_context']['snapshot']['workspace_id']===$device,'Print binding preceded verified local authority.');}}
    class DesktopDashboardWorkspaceCreatedScopes {
        public array $formats=[];public bool $restored=false;
        public function validateSnapshotCatalog($snapshot,$manifest):void{$this->formats[]=$snapshot['format'];\abort_unless($snapshot['format']===2&&$snapshot['kind']==='initial-workspace-data'&&$snapshot['device_id']===$manifest['workspace_id'],422);}
        public function restoreWorkspace($snapshot,DesktopDashboardWorkspaceImportProvenance $proof):void{$proof->assertVerified();\check(\Illuminate\Support\Facades\DB::transactionLevel()>0&&isset($GLOBALS['installed_context'])&&$snapshot['format']===2,'Catalog restore ran before verified local authority or used internal v1 header.');$this->restored=true;}
        public function verifyWorkspaceCatalog($receipt,$root):void{\check($this->restored&&$receipt['workspace']['root_fingerprint']===$root,'Catalog receipt verification lost the physical root.');}
    }
}
namespace {
    function abort_unless($condition,$status,$message=''){if(!$condition)throw new RuntimeException('FIXED_'.$status);}
    function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
    function config($key){return $key==='desktop_dashboard.device_id'?'11111111-1111-4111-8111-111111111111':true;}
    function app($class){if($class===\App\Services\Dashboard\DesktopDashboardWorkspaceAuthority::class)return $GLOBALS['authority'];if($class===\App\Services\Dashboard\DesktopDashboardWorkspaceLocalAuthority::class)return $GLOBALS['localAuthority'];if($class===\App\Services\Dashboard\DesktopDashboardWorkspaceCreatedScopes::class)return $GLOBALS['catalog'];if($class===\App\Services\Dashboard\DesktopDashboardPrintLedger::class)return new $class;throw new RuntimeException('Unexpected synthetic service.');}
    function source():array{return ['format'=>1,'files'=>1,'framework'=>'8.83.29','sha256'=>str_repeat('f',64)];}
    function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
    function refused(callable $work):void{try{$work();}catch(Throwable $error){return;}throw new RuntimeException('Invalid workspace import was accepted.');}
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceAuthority.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardDeltaCodec.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceDeltaCodec.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceImportProvenance.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceImport.php';
    use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardWorkspaceImport as Import,DesktopDashboardImport as Legacy};
    function environment():array{
        putenv('DESKTOP_DASHBOARD_LOCAL=true');putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');$GLOBALS['authority']=new Authority;$GLOBALS['localAuthority']=new \App\Services\Dashboard\DesktopDashboardWorkspaceLocalAuthority;$GLOBALS['catalog']=new \App\Services\Dashboard\DesktopDashboardWorkspaceCreatedScopes;
        $manifest=json_decode(file_get_contents(__DIR__.'/fixtures/desktop-workspace-delta-codec-v2.json'),true,64,JSON_THROW_ON_ERROR)['manifest'];
        $a=password_hash('synthetic-A',PASSWORD_BCRYPT,['cost'=>4]);$b=password_hash('synthetic-B',PASSWORD_BCRYPT,['cost'=>4]);
        $manifest['members'][0]['password_binding']=hash('sha256',$a);$manifest['members'][1]['password_binding']=hash('sha256',$b);
        $enrollment=['format'=>2,'workspace_id'=>$manifest['workspace_id'],'custodian_actor_id'=>202,'enrollment_hash'=>str_repeat('c',64),'token_hash'=>str_repeat('d',64),'enrolled_at'=>'2026-10-27 12:00:00'];
        $root=(object)$enrollment;$root->id=$enrollment['workspace_id'];$manifest['root_fingerprint']=$GLOBALS['authority']->rootFingerprint($root);$manifest['roster_sha256']=hash('sha256',Authority::canonical($manifest['members']));
        $rows=[['id'=>101,'account_type'=>'admin','status'=>'accepted','password'=>$a],['id'=>202,'account_type'=>'vendor','status'=>'accepted','password'=>$b],['id'=>303,'account_type'=>'user','status'=>'accepted','password'=>'!desktop-disabled']];
        $ddl="CREATE TABLE `users` (\n `id` bigint unsigned NOT NULL,\n `account_type` varchar(30) NOT NULL,\n `status` varchar(30) NOT NULL,\n `password` varchar(255) NOT NULL\n) ENGINE=InnoDB";
        $schema=['users'=>hash('sha256',$ddl)];$snapshot=['format'=>2,'kind'=>'initial-workspace-data','snapshot_id'=>'22222222-2222-4222-8222-222222222222','device_id'=>$manifest['workspace_id'],'actor_id'=>202,
            'branches'=>['f:100'],'source'=>source(),'schema_hash'=>hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($schema)),
            'enrollment'=>['enrollment_hash'=>$enrollment['enrollment_hash'],'enrolled_at'=>$enrollment['enrolled_at'],'base_branches'=>['f:100']],'created_scopes'=>[],
            'coverage'=>['workspace_protocol'=>2,'manifest_revision'=>1,'root_fingerprint'=>$manifest['root_fingerprint'],'roster_sha256'=>$manifest['roster_sha256'],'print_protocol'=>1,'catalog_scopes'=>0,'full_dashboard'=>false],
            'tables'=>['users'=>['ddl'=>$ddl,'rows'=>$rows,'sha256'=>hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($rows))]],'workspace_manifest'=>$manifest,'workspace_enrollment'=>$enrollment];
        $expected=['workspace_id'=>$manifest['workspace_id'],'root_fingerprint'=>$manifest['root_fingerprint'],'revision'=>1,'roster_sha256'=>$manifest['roster_sha256'],'manifest_sha256'=>hash('sha256',Authority::canonical($manifest)),'token_hash'=>$enrollment['token_hash']];
        Legacy::$imports=0;return [new Import,$snapshot,$expected];
    }
    $cases=[];
    $cases['private-binding-and-member-verifiers-install-typed-provenance']=function(){[$import,$snapshot,$expected]=environment();$receipt=$import->importWorkspace($snapshot,$expected);
        check($receipt['format']===2&&$receipt['kind']==='desktop-workspace-import-receipt'&&Legacy::$imports===1,'Strict preflight did not reach shared importer once.');
        check($GLOBALS['installed_context']['expected_binding_digest']===hash('sha256',Authority::canonical($expected)),'Private native binding was not sealed into provenance.');
        check($receipt['workspace']['manifest_sha256']===$expected['manifest_sha256'],'Receipt lost exact manifest binding.');
        check($import->verifyWorkspace($receipt,$expected)['verified']===true,'Independent workspace receipt verification failed.');};
    $cases['native-expectations-cannot-be-replaced-by-snapshot-fields']=function(){foreach(['workspace_id','root_fingerprint','revision','roster_sha256','manifest_sha256','token_hash'] as $key){[$import,$snapshot,$expected]=environment();
        $expected[$key]=$key==='revision'?2:($key==='workspace_id'?'33333333-3333-4333-8333-333333333333':str_repeat('b',64));refused(fn()=>$import->importWorkspace($snapshot,$expected));check(Legacy::$imports===0,'Mismatched native expectation reached SQL.');}};
    $cases['catalog-validation-retains-v2-envelope-through-internal-v1-SQL-engine']=function(){[$import,$snapshot,$expected]=environment();
        $snapshot['coverage']['catalog_scopes']=1;$snapshot['coverage']['catalog_grants_sha256']=hash('sha256','[]');
        $snapshot['coverage']['catalog_structural_branches']=$snapshot['branches'];
        foreach(['base_branches_sha256','catalog_branches_sha256'] as $key)$snapshot['coverage'][$key]=hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($snapshot['branches']));
        $receipt=$import->importWorkspace($snapshot,$expected);
        check($GLOBALS['catalog']->formats===[2,2]&&$GLOBALS['catalog']->restored,'Strict catalog validator received the converted legacy header.');
        check($import->verifyWorkspace($receipt,$expected)['verified']===true,'Catalog protocol-two receipt failed separate verification.');};
    $cases['absent-nonmember-custodian-is-audit-only-with-no-synthesized-user']=function(){[$import,$snapshot,$expected]=environment();
        $snapshot['actor_id']=404;$snapshot['workspace_enrollment']['custodian_actor_id']=404;
        $root=(object)$snapshot['workspace_enrollment'];$root->id=$root->workspace_id;
        $fingerprint=$GLOBALS['authority']->rootFingerprint($root);$snapshot['workspace_manifest']['root_fingerprint']=$fingerprint;
        $snapshot['coverage']['root_fingerprint']=$fingerprint;$expected['root_fingerprint']=$fingerprint;
        $expected['manifest_sha256']=hash('sha256',Authority::canonical($snapshot['workspace_manifest']));
        $before=$snapshot['tables']['users']['rows'];$receipt=$import->importWorkspace($snapshot,$expected);
        check($receipt['actor_id']===404&&$import->verifyWorkspace($receipt,$expected)['verified']===true,'Deleted custodian prevented valid members from importing.');
        check($before===$snapshot['tables']['users']['rows']&&!in_array(404,array_column($before,'id'),true),'Deleted custody user was fabricated or rebound.');};
    $cases['compound-root-custody-date-and-nonce-are-bound']=function(){foreach(['custodian_actor_id','enrollment_hash','enrolled_at'] as $key){[$import,$snapshot,$expected]=environment();$snapshot['workspace_enrollment'][$key]=$key==='custodian_actor_id'?101:($key==='enrolled_at'?'2026-10-28 12:00:00':str_repeat('e',64));refused(fn()=>$import->importWorkspace($snapshot,$expected));check(Legacy::$imports===0,'Changed physical custody root reached SQL.');}};
    $cases['member-B-hash-plaintext-role-removal-and-nonmember-verifier-refused']=function(){foreach(['hash','plaintext','role','missing','foreign'] as $change){[$import,$snapshot,$expected]=environment();$rows=&$snapshot['tables']['users']['rows'];
        if($change==='hash')$rows[1]['password']=password_hash('wrong-B',PASSWORD_BCRYPT,['cost'=>4]);elseif($change==='plaintext')$rows[1]['password']='PRIVATE password';elseif($change==='role')$rows[1]['account_type']='admin';elseif($change==='missing')array_splice($rows,1,1);else $rows[2]['password']=password_hash('foreign',PASSWORD_BCRYPT,['cost'=>4]);
        refused(fn()=>$import->importWorkspace($snapshot,$expected));check(Legacy::$imports===0,'Untrusted verifier/member row reached SQL.');}};
    $cases['branch-and-coverage-widening-and-legacy-namespace-refused']=function(){foreach(['branch','coverage','legacy'] as $change){[$import,$snapshot,$expected]=environment();if($change==='branch')$snapshot['branches'][]='f:999';elseif($change==='coverage')$snapshot['coverage']['full_dashboard']=true;else{$snapshot['format']=1;$snapshot['kind']='initial-dashboard-data';}
        refused(fn()=>$import->importWorkspace($snapshot,$expected));check(Legacy::$imports===0,'Wrong scope reached SQL.');}};
    $cases['ordinary-engine-row-schema-and-source-failures-prevent-local-authority']=function(){foreach(['row','schema','source'] as $change){[$import,$snapshot,$expected]=environment();if($change==='row')$snapshot['tables']['users']['sha256']=str_repeat('e',64);elseif($change==='schema')$snapshot['schema_hash']=str_repeat('e',64);else $snapshot['source']['sha256']=str_repeat('e',64);
        unset($GLOBALS['installed_context']);refused(fn()=>$import->importWorkspace($snapshot,$expected));check(!isset($GLOBALS['installed_context']),'Failed original validation issued local authority.');}};
    $cases['public-fields-and-direct-parent-entry-cannot-issue-provenance']=function(){[$import,$snapshot,$expected]=environment();refused(fn()=>\App\Services\Dashboard\DesktopDashboardWorkspaceImportProvenance::issue($import,new stdClass,[]));
        refused(fn()=>$import->import($snapshot));check(Legacy::$imports===0,'Inherited import bypassed typed preflight.');};
    foreach($cases as $name=>$work){$work();echo 'PASS workspace-import '.$name.PHP_EOL;}echo 'Workspace import: '.count($cases).' pure preflight cases passed; no SQL execution claimed.'.PHP_EOL;
}
