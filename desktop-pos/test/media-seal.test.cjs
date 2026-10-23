'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path');
const {spawnSync}=require('node:child_process');
test('full media verification precedes the short native archive fence and rejects changed files',t=>{
  const php=process.env.DESKTOP_TEST_PHP||'php';
  const result=spawnSync(php,['-n',path.resolve(__dirname,'../../tests/desktop_dashboard_media_seal_test.php')],{encoding:'utf8'});
  if(result.error?.code==='ENOENT'&&!process.env.DESKTOP_TEST_PHP){t.skip('PHP not installed');return;}
  assert.equal(result.status,0,result.stdout+result.stderr);assert.match(result.stdout,/PASS (?:9|10) native media handoff checks/);
});
