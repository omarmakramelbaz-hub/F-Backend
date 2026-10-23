'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path'),{spawnSync}=require('node:child_process');
test('simultaneous heavy delta scans are bounded without queuing or business row locks',t=>{
  const result=spawnSync(process.env.DESKTOP_TEST_PHP||'php',['-n',path.resolve(__dirname,'../../tests/desktop_dashboard_delta_capacity_test.php')],{encoding:'utf8',timeout:15000});
  if(!process.env.DESKTOP_TEST_PHP&&!process.env.CI&&result.error?.code==='ENOENT'){t.skip('PHP unavailable');return;}
  assert.ifError(result.error);assert.equal(result.status,0,result.stdout+result.stderr);
  assert.equal(result.stdout.trim(),'PASS isolated delta capacity checks');
});
