'use strict';
const assert=require('node:assert/strict');
const path=require('node:path');
const {spawnSync}=require('node:child_process');
const {test}=require('node:test');
test('actual staged verification hashes media after releasing the cashier print scope',t=>{
  const php=process.env.DESKTOP_TEST_PHP||'php';
  const result=spawnSync(php,['-n',path.resolve(__dirname,'../../tests/desktop_dashboard_media_verify_scope_test.php')],{encoding:'utf8'});
  if(result.error?.code==='ENOENT'&&!process.env.DESKTOP_TEST_PHP){t.skip('PHP not installed');return;}
  assert.equal(result.status,0,result.stdout+result.stderr);
  assert.match(result.stdout,/PASS actual Import\.verify releases print scope/);
});
