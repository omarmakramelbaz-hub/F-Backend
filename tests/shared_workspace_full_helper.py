#!/usr/bin/env python3
"""Actual full production SQL-helper compatibility on a disposable CI database.

The isolated bootstrap provides real locked/resolved Laravel classes and PDO.
It is not a production web/profile acceptance claim. Never run on a user server.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import uuid

HERE=Path(__file__).resolve().parent
REPO=HERE.parent
CORE='052e69008da7e330815b376824cd8afc18cf04c1'
APP=Path('/home/fasakha/public_html')
ORIGINAL_SHA='cc1a130148fd1079b2de9a7dcade91fd7d130ade27bdf77435a3318f43ca3aa2'
COMPAT_SHA='d30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be'
MIGRATIONS=(
    'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php',
    'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php',
)
POLICY='historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'

def need(value,code):
    if not value:raise RuntimeError('FULL_HELPER_'+code)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False,allow_nan=False).encode()+b'\n'
def git_file(name,git_dir=None):
    args=['git']+(['--git-dir='+str(git_dir)] if git_dir else [])
    return subprocess.check_output(args+['show',CORE+':'+name])

def prepare(args):
    target=args.runtime;need(not target.exists(),'RUNTIME_ALREADY_EXISTS');target.mkdir(parents=True)
    if args.framework=='8.83.29':
        value={'name':'goerp/disposable-full-helper-laravel8','require':{'php':'^8.2','laravel/framework':'8.83.29'},
            'config':{'audit':{'block-insecure':False},'preferred-install':'dist','sort-packages':True}}
        (target/'composer.json').write_bytes(canonical(value))
    else:
        for name in ('composer.json','composer.lock'):(target/name).write_bytes(git_file('desktop-pos/runtime/'+name,args.git_dir))
        # These application autoload files are intentionally empty fixture files;
        # no customer/financial application helpers run in this metadata test.
        (target/'app/Helpers').mkdir(parents=True)
        (target/'app/Helpers/helper.php').write_text('<?php // Isolated SQL-helper fixture.\n')
        (target/'compatibility.php').write_text('<?php // Isolated SQL-helper fixture.\n')
    fixtures=target/'source'
    for name in MIGRATIONS:
        p=fixtures/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(git_file(name,args.git_dir))
    print('FULL_HELPER_FIXTURE_PREPARED '+args.framework)

ADMIN_CODE=r'''$db=getenv('GO_ERP_FIXTURE_DATABASE');
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||!preg_match('/^goerp_compat_[a-f0-9]{24}$/D',$db))throw new RuntimeException('FIXTURE_ONLY');
$pdo=new PDO('mysql:host=127.0.0.1;port='.getenv('GO_ERP_FIXTURE_PORT'),'root','test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$action=$argv[1];
if($action==='create'){
 if($pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$db'")->fetchColumn())throw new RuntimeException('DATABASE_ALREADY_EXISTS');
 $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
 $pdo->exec("CREATE TABLE `$db`.`migrations` (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,migration varchar(255) NOT NULL,batch int NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $pdo->exec("CREATE TABLE `$db`.`fixture_financial_sentinel` (id int NOT NULL PRIMARY KEY,amount int NOT NULL) ENGINE=InnoDB");
 $pdo->exec("INSERT INTO `$db`.`fixture_financial_sentinel` VALUES (1,12345),(2,-210)");
}elseif($action==='inspect'){
 $rows=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
 $sentinel=$pdo->query("SELECT id,amount FROM `$db`.`fixture_financial_sentinel` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
 echo json_encode(['tables'=>$rows,'sentinel'=>$sentinel,'server_version'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)],JSON_THROW_ON_ERROR);
}elseif($action==='drop'){$pdo->exec("DROP DATABASE `$db`");}else throw new RuntimeException('BAD_FIXTURE_ACTION');'''

def admin(action,env):
    result=subprocess.run(['php','-r',ADMIN_CODE,action],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=40)
    need(result.returncode==0,'ADMIN_'+action.upper()+'_'+result.stderr.decode(errors='replace')[:500])
    return json.loads(result.stdout) if result.stdout else None

def materialize(raw,gate):
    anchor=b'const GO_ERP_WORKSPACE_MIGRATION_GATE = null;';need(raw.count(anchor)==1,'GATE_ANCHOR')
    return raw.replace(anchor,("const GO_ERP_WORKSPACE_MIGRATION_GATE = '"+sha(canonical(gate))+"';").encode())

def run_php(program,spec,env,*,expect_success=True):
    need(program.startswith(b'<?php') and b'?>' not in program,'CLI_PROGRAM')
    result=subprocess.run(['setpriv','--reuid=1001','--regid=1003','--clear-groups','php','-d','display_errors=0','-r',program[5:].decode()],
        input=canonical(spec),env=env,cwd=APP,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=90)
    need(len(result.stdout)+len(result.stderr)<=262144,'OUTPUT_BOUND')
    if expect_success:need(result.returncode==0,'PHP_EFFECT_'+result.stderr.decode(errors='replace')[:1000])
    return result

def diagnostic_catch(raw,*,fault=False):
    marker=b'} catch (Throwable $error) {';need(raw.count(marker)==1,'CATCH_ANCHOR')
    begin=raw.index(marker)
    catch=br'''} catch (Throwable $error) {
    $manager=\Illuminate\Support\Facades\DB::getFacadeRoot();
    $schema=\Illuminate\Support\Facades\Schema::getFacadeRoot();
    $result=['class'=>get_class($error),'illegal_offset'=>str_contains($error->getMessage(),'Illegal offset type'),
        'fixture_expected'=>$error->getMessage()==='FIXTURE_EXPECTED_MIGRATION_FAILURE',
        'manager_restored'=>$manager instanceof \Illuminate\Database\DatabaseManager,
        'schema_restored'=>$schema instanceof \Illuminate\Database\Schema\Builder&&$schema->getConnection()===$db];
    fwrite(STDERR,json_encode($result,JSON_THROW_ON_ERROR)."\n");exit(1);
}
'''
    raw=raw[:begin]+catch
    if fault:
        anchor=b"$class=$spec['class']; (new $class())->up();";need(raw.count(anchor)==1,'FAULT_ANCHOR')
        raw=raw.replace(anchor,b"throw new RuntimeException('FIXTURE_EXPECTED_MIGRATION_FAILURE');")
    return raw

def setup_app(runtime):
    need(os.geteuid()==0 and not APP.exists(),'FIXTURE_ROOT_NOT_FRESH')
    APP.mkdir(parents=True,mode=0o755);(APP/'bootstrap').mkdir(mode=0o755)
    (APP/'bootstrap/app.php').write_bytes((HERE/'fixtures/shared_workspace_bootstrap.php').read_bytes())
    (APP/'vendor').symlink_to((runtime/'vendor').resolve(),target_is_directory=True)
    for name in MIGRATIONS:
        p=APP/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((runtime/'source'/name).read_bytes())
    for p in [APP,*APP.rglob('*')]:
        if p.is_symlink():continue
        os.chown(p,1001,1003);p.chmod(0o755 if p.is_dir() else 0o644)

def execute(args):
    need(os.geteuid()==0 and os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('APP_ENV')=='testing','CI_ONLY')
    need(args.framework in ('8.83.29','12.70.0') and os.environ.get('GO_ERP_FIXTURE_PORT')=='3306','MATRIX_SCOPE')
    original=(REPO/'deployment/go_erp_shared_workspace_migration.php').read_bytes()
    compat=(REPO/'deployment/go_erp_shared_workspace_migration_compat.php').read_bytes()
    need(sha(original)==ORIGINAL_SHA and sha(compat)==COMPAT_SHA,'HELPER_SOURCE_CHANGED')
    accepted=json.loads((HERE/'fixtures/shared_workspace_schema_accepted_r13.json').read_bytes())
    need(all(sha((args.runtime/'source'/p).read_bytes())==accepted['migration_hashes'][p] for p in MIGRATIONS),'MIGRATIONS_CHANGED')
    setup_app(args.runtime)
    gate={'policy':POLICY,'release':CORE,'source_manifest_sha256':sha(b'isolated-full-helper-fixture'),
        'migration_hashes':accepted['migration_hashes'],'schema_hashes':{t:sha(canonical(s)) for t,s in accepted['schemas'].items()}}
    program=materialize(compat,gate)
    # Execute the entire helper unchanged, with a postlude that only verifies
    # restored facade wiring after its normal successful output.
    positive=program+br'''
if($input['mode']==='create-table'){
 if(!($app['db'] instanceof \Illuminate\Database\DatabaseManager))throw new RuntimeException('FIXTURE_MANAGER_NOT_RESTORED');
 $restoredSchema=\Illuminate\Support\Facades\Schema::getFacadeRoot();
 if(!($restoredSchema instanceof \Illuminate\Database\Schema\Builder)||$restoredSchema->getConnection()!==$db)throw new RuntimeException('FIXTURE_SCHEMA_NOT_RESTORED');
}
'''
    profiles=[]
    for profile in (0,1):
        env={**os.environ,'GO_ERP_FIXTURE_DATABASE':'goerp_compat_'+uuid.uuid4().hex[:24],
            'GO_ERP_FIXTURE_TIMESTAMP':str(profile)};owned=False
        try:
            admin('create',env);owned=True;before=admin('inspect',env)
            spec={'database_id_sha256':None,'gate':gate,'migration_path':None,'mode':'identity','table':None}
            identity=json.loads(run_php(positive,spec,env).stdout);spec['database_id_sha256']=identity['database_id_sha256'];spec['mode']='observe'
            empty=json.loads(run_php(positive,spec,env).stdout)
            need(all(v is None for v in empty['tables'].values()) and all(v is None for v in empty['ledger'].values()),'ALL_ABSENT_INITIAL')
            first=MIGRATIONS[0];first_table='desktop_dashboard_workspaces'
            probe={**spec,'mode':'create-table','migration_path':first,'table':first_table}
            original_failure=None
            if args.framework=='8.83.29':
                failed=run_php(materialize(diagnostic_catch(original),gate),probe,env,expect_success=False)
                error=json.loads(failed.stderr);need(failed.returncode!=0 and error['class']=='TypeError' and error['illegal_offset'] is True,'ORIGINAL_BUG_NOT_REPRODUCED')
                original_failure=error
                observed=json.loads(run_php(positive,spec,env).stdout);need(all(v is None for v in observed['tables'].values()),'ORIGINAL_FAILURE_CREATED_TABLE')
            fault=run_php(materialize(diagnostic_catch(compat,fault=True),gate),probe,env,expect_success=False)
            fault_error=json.loads(fault.stderr)
            need(fault.returncode!=0 and fault_error['fixture_expected'] is True and fault_error['manager_restored'] is True
                and fault_error['schema_restored'] is True,'EXCEPTION_RESTORE_FAILED')
            observed=json.loads(run_php(positive,spec,env).stdout);need(all(v is None for v in observed['tables'].values()),'FAULT_CREATED_TABLE')
            calls=[];created=set()
            for path in MIGRATIONS:
                targets=(['desktop_dashboard_workspaces','desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests']
                    if path==MIGRATIONS[0] else ['desktop_dashboard_ws_command_auth'])
                for table in targets:
                    call={**spec,'mode':'create-table','migration_path':path,'table':table}
                    actual=json.loads(run_php(positive,call,env).stdout);created.add(table);calls.append({'mode':'create-table','table':table})
                    need({t for t,v in actual['tables'].items() if v is not None}==created,'ONE_TABLE_EFFECT_SCOPE')
                    need(all(actual['tables'][t]==accepted['schemas'][t] for t in created),'ACTUAL_SCHEMA_DIFFERS')
                    need(actual['workspace_enabled'] is False and actual['local'] is False and actual['financial_rows_changed'] is False
                        and actual['dropped_tables']==[],'EFFECT_FLAGS')
                ledger={**spec,'mode':'record-ledger','migration_path':path,'table':None}
                actual=json.loads(run_php(positive,ledger,env).stdout);calls.append({'mode':'record-ledger','migration':path})
                need(actual['ledger'][path] is not None,'LEDGER_NOT_RECORDED')
            after=admin('inspect',env);need(after['sentinel']==before['sentinel'],'FINANCIAL_SENTINEL_CHANGED')
            need(set(after['tables'])==set(accepted['schemas'])|{'migrations','fixture_financial_sentinel'},'UNREVIEWED_TABLE_EFFECT')
            final=json.loads(run_php(positive,spec,env).stdout)
            need(all(final['ledger'][p] is not None for p in MIGRATIONS),'FINAL_LEDGER')
            need(all(final['tables'][t]==schema for t,schema in accepted['schemas'].items()),'FINAL_SCHEMAS')
            need(all(sha((APP/p).read_bytes())==accepted['migration_hashes'][p] for p in MIGRATIONS),'SOURCE_MIGRATIONS_CHANGED')
            engine='mariadb' if 'mariadb' in after['server_version'].lower() else 'mysql'
            profiles.append({'framework':args.framework,'engine':engine,'initial_timestamp':profile,'server_version':after['server_version'],'calls':calls,
                'original_laravel8_defect':original_failure,'exception_restore':fault_error,'final_schemas_exact':True,
                'sentinel_unchanged':True,'private_tables_absent':True,'actual_pdo':True,'full_helper_body':True,
                'created_tables':4,'recorded_migrations':2,'all_schemas_match':True,'source_migrations_unchanged':True,
                'compat_template_sha256':sha(compat),'original_swap_failure_reproduced':args.framework=='8.83.29'})
        finally:
            if owned:admin('drop',env)
    version_code="require $argv[1].'/vendor/autoload.php';echo json_encode(['framework'=>Illuminate\\Foundation\\Application::VERSION,'php'=>PHP_VERSION,'schema_sha256'=>hash_file('sha256',$argv[1].'/vendor/laravel/framework/src/Illuminate/Support/Facades/Schema.php'),'facade_sha256'=>hash_file('sha256',$argv[1].'/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php')],JSON_THROW_ON_ERROR);"
    framework=json.loads(subprocess.check_output(['php','-r',version_code,str(args.runtime)]));need(framework['framework']==args.framework,'FRAMEWORK_VERSION_CHANGED')
    receipt={'format':1,'kind':'go-erp-full-sql-helper-compatibility','core':CORE,'operator_revision':os.environ.get('GITHUB_SHA'),
        'original_helper_sha256':sha(original),'compat_helper_sha256':sha(compat),'actual_program_sha256':sha(positive),
        'framework':framework,'resolved_composer_lock_sha256':sha((args.runtime/'composer.lock').read_bytes()),
        'migration_hashes':accepted['migration_hashes'],'profiles':profiles,
        'scope':{'full_helper_body':True,'actual_framework_facades':True,'actual_pdo_database':True,
            'isolated_bootstrap':True,'production_web_application':False,'production_database':False}}
    args.output.parent.mkdir(parents=True,exist_ok=True);args.output.write_bytes(canonical(receipt));args.output.chmod(0o644)
    shutil.copyfile(args.runtime/'composer.lock',args.output.with_name('resolved-composer.lock'));args.output.with_name('resolved-composer.lock').chmod(0o644)
    print('GO_ERP_FULL_SQL_HELPER_COMPAT_COMPLETE '+json.dumps({'framework':args.framework,'profiles':2,'helper_sha256':sha(compat)},separators=(',',':')))

def main():
    p=argparse.ArgumentParser();p.add_argument('mode',choices=('prepare','execute'));p.add_argument('--runtime',type=Path,required=True)
    p.add_argument('--framework',choices=('8.83.29','12.70.0'),required=True);p.add_argument('--git-dir',type=Path)
    p.add_argument('--output',type=Path);a=p.parse_args();a.runtime=a.runtime.resolve()
    if a.mode=='prepare':prepare(a)
    else:need(a.output is not None,'OUTPUT_REQUIRED');execute(a)

if __name__=='__main__':main()
