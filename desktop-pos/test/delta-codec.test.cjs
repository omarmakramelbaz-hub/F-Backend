'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const path=require('node:path');
const {spawnSync}=require('node:child_process');

for(const name of ['unchanged','changed-added-deleted','empty-table','duplicate-pivot-rows','row-and-key-order',
  'php-precision','pdo-numeric-variants','sql-float-storage-precision','sql-float-range-and-roundtrip','lossless-json-floats','large-integers','sql-zero','authorized-projection','disabled-password','schema-auto-counter',
  'schema-counter-in-default','malformed-index','malformed-delta','foreign-columns-and-numbers',
  'replacement-removed-column','replacement-new-column','replacement-empty-target','replacement-proof-and-target','trusted-projection-transition','disabled-password-sanitized']){
  test('incremental row codec: '+name,t=>{
    const php=process.env.DESKTOP_TEST_PHP||'php';
    const result=spawnSync(php,['-n',path.join(__dirname,'../../tests/desktop_dashboard_delta_codec_test.php'),name],{encoding:'utf8'});
    if(result.error?.code==='ENOENT'&&!process.env.DESKTOP_TEST_PHP){t.skip('PHP is not installed');return;}
    assert.equal(result.status,0,result.stdout+result.stderr);
    assert.equal(result.stdout.trim(),'PASS '+name);
  });
}
