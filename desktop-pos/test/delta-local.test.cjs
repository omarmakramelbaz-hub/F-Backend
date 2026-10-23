'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const path=require('node:path');const fs=require('node:fs'),os=require('node:os');const {spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),options={encoding:'utf8',timeout:20000,maxBuffer:2*1024*1024,windowsHide:true};
function php(t){
  const command=process.env.DESKTOP_TEST_PHP||'php',probe=spawnSync(command,['-n','-r','echo PHP_VERSION_ID;'],options);
  if(!process.env.DESKTOP_TEST_PHP&&!process.env.CI&&probe.error?.code==='ENOENT'){t.skip('PHP unavailable; set DESKTOP_TEST_PHP.');return null;}
  assert.ifError(probe.error);assert.equal(probe.status,0,probe.stderr);assert.ok(Number(probe.stdout)>=80000);return command;
}
test('private PHP capture/reconstruct enforces snapshot, binding, no-active-write and owned-cleanup invariants',t=>{
  const command=php(t);if(!command)return;
  const codec=process.env.DESKTOP_DELTA_CODEC_PATH||path.join(root,'app/Services/Dashboard/DesktopDashboardDeltaCodec.php');
  const result=spawnSync(command,['-n',path.join(root,'tests/desktop_delta/local.php'),root,codec],options);
  assert.ifError(result.error);assert.equal(result.status,0,result.stderr);assert.match(result.stdout,/"passed":12/);
  assert.doesNotMatch(result.stdout+result.stderr,/PRIVATE ACCOUNT VALUE|PRIVATE HASH|PRIVATE SETTING|PRIVATE NEW VALUE/);
});
test('one-shot CLI rejects nonlocal use and malformed private input without account values or full paths',t=>{
  const command=php(t);if(!command)return;
  for(const [local,input,reason] of [['false','{"account":"PRIVATE ACCOUNT VALUE"}','DESKTOP_DELTA_CLI_REQUIRED'],['true','{"account":"PRIVATE ACCOUNT VALUE"',null]]){
    const result=spawnSync(command,['-n',path.join(root,'desktop/delta.php')],{...options,input,env:{...process.env,DESKTOP_DASHBOARD_LOCAL:local}});
    assert.ifError(result.error);assert.equal(result.status,1);assert.equal(result.stdout,'');
    assert.match(result.stderr,/DESKTOP_DELTA_DIAGNOSTIC /);assert.doesNotMatch(result.stderr,/PRIVATE ACCOUNT VALUE/);
    assert.ok(!result.stderr.includes(root));
    const report=JSON.parse(result.stderr.split('DESKTOP_DELTA_DIAGNOSTIC ')[1]);
    assert.match(report.file,/^[A-Za-z0-9_.-]+$/);assert.ok(Number.isSafeInteger(report.line)&&report.line>0);
    if(reason)assert.equal(report.reason,reason);else assert.equal(report.phase,'json');
  }
});

test('database stage-status only reads exact fresh staging existence before app grants',t=>{
  const command=php(t);if(!command)return;
  const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'desktop-stage-status-'));
  try{
    const actual=fs.readFileSync(path.join(root,'desktop/database.php'),'utf8');
    assert.equal(actual.split('new PDO(').length,2,'one audited PDO construction seam');
    const fixture=path.join(temporary,'database.php');
    fs.writeFileSync(fixture,actual.replace('new PDO(','new DesktopStatusPdo('));
    const dependency=path.join(temporary,'pdo.php');
    fs.writeFileSync(dependency,String.raw`<?php
      if(!class_exists('PDO')){class PDO{public const ATTR_ERRMODE=3;public const ERRMODE_EXCEPTION=2;public const ATTR_TIMEOUT=2;}}
      final class DesktopStatusPdo{
        public function __construct(...$args){}
        public function exec($sql){throw new RuntimeException('Unexpected mutation');}
        public function prepare($sql){
          if($sql!=='SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?')throw new RuntimeException('Unexpected query');
          return new class{
            public function execute($values){if($values!==[getenv('DB_DATABASE')])throw new RuntimeException('Wrong bound database');}
            public function fetchColumn(){return getenv('DESKTOP_TEST_DB_EXISTS')==='true'?getenv('DB_DATABASE'):false;}
          };
        }
      }
    `);
    for(const exists of [false,true]){
      const database='fasakhansta_dashboard_stage_0123456789abcdef';
      const result=spawnSync(command,['-n','-d','auto_prepend_file='+dependency,fixture,'stage-status'],{...options,
        input:JSON.stringify({password:'PRIVATE ROOT PASSWORD'}),env:{...process.env,DESKTOP_DASHBOARD_LOCAL:'true',DB_PORT:'13376',DB_DATABASE:database,DESKTOP_TEST_DB_EXISTS:String(exists)}});
      assert.ifError(result.error);assert.equal(result.status,0,result.stderr);
      assert.deepEqual(JSON.parse(result.stdout),{format:1,database,exists});
      assert.equal(result.stderr,'');assert.doesNotMatch(result.stdout,/PRIVATE ROOT PASSWORD/);
    }
    const invalid=spawnSync(command,['-n','-d','auto_prepend_file='+dependency,fixture,'stage-status'],{...options,
      input:JSON.stringify({password:'PRIVATE ROOT PASSWORD'}),env:{...process.env,DESKTOP_DASHBOARD_LOCAL:'true',DB_PORT:'13376',DB_DATABASE:'fasakhansta_dashboard'}});
    assert.equal(invalid.status,1);assert.equal(invalid.stdout,'');assert.doesNotMatch(invalid.stderr,/PRIVATE ROOT PASSWORD/);
  }finally{fs.rmSync(temporary,{recursive:true,force:true});}
});
