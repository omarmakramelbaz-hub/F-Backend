'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path'),{spawnSync}=require('node:child_process');
for(const name of ['scoped-diff-only','device-actor-binding','source-binding','source-race','table-roster-upgrades','new-empty-table','structural-only-summary','changed-schema-server-rows',
  'reject-client-rows-and-sql','local-refusal','scoped-export-binding','total-row-limit','projected-eligibility',
  'http-private-response','http-auth-before-body','http-reject-json-media-type','http-length-limit','http-invalid-json','http-empty-body','http-actual-body-limit']){
  test('authenticated server delta: '+name,t=>{
    const php=process.env.DESKTOP_TEST_PHP||'php';
    const result=spawnSync(php,['-n',path.join(__dirname,'../../tests/desktop_dashboard_delta_server_test.php'),name],{encoding:'utf8'});
    if(result.error?.code==='ENOENT'&&!process.env.DESKTOP_TEST_PHP){t.skip('PHP is not installed');return;}
    assert.equal(result.status,0,result.stdout+result.stderr);assert.equal(result.stdout.trim(),'PASS '+name);
  });
}
