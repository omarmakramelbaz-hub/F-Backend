'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path'),{spawnSync}=require('node:child_process');
test('employee migration readiness validates all nine nullable columns without row values',t=>{
  const php=process.env.DESKTOP_TEST_PHP||'php';
  const result=spawnSync(php,['-n',path.join(__dirname,'../../tests/desktop_employee_schema_probe_test.php')],{encoding:'utf8'});
  if(result.error?.code==='ENOENT'&&!process.env.DESKTOP_TEST_PHP){t.skip('PHP is not installed');return;}
  assert.equal(result.status,0,result.stdout+result.stderr);
  assert.equal(result.stdout.trim(),'PASS metadata-only employee schema proof');
});
