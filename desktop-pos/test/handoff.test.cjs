'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path'),{spawnSync,spawn}=require('node:child_process'),net=require('node:net');
test('private preverified archive authenticates cutover without rescanning source, rows or replay history',t=>{
  const php=process.env.DESKTOP_TEST_PHP||'php';
  const result=spawnSync(php,['-n',path.resolve(__dirname,'../../tests/desktop_dashboard_handoff_test.php')],{encoding:'utf8'});
  if(result.error?.code==='ENOENT'&&!process.env.DESKTOP_TEST_PHP){t.skip('PHP not installed');return;}
  assert.equal(result.status,0,result.stdout+result.stderr);assert.match(result.stdout,/PASS [0-9]+ private preverified archive checks/);
});
test('the same signed-handoff issuer cannot be invoked through a local HTTP PHP process',async t=>{
  const php=process.env.DESKTOP_TEST_PHP||'php',probe=spawnSync(php,['-n','-v'],{encoding:'utf8'});
  if(probe.error?.code==='ENOENT'&&!process.env.DESKTOP_TEST_PHP){t.skip('PHP not installed');return;}
  assert.equal(probe.status,0,probe.stderr);
  const port=await new Promise((resolve,reject)=>{const server=net.createServer();server.once('error',reject);server.listen(0,'127.0.0.1',()=>{const p=server.address().port;server.close(error=>error?reject(error):resolve(p));});});
  const child=spawn(php,['-n','-S',`127.0.0.1:${port}`,'-t',path.resolve(__dirname,'../../tests')],{stdio:['ignore','ignore','pipe']});
  let errors='';child.stderr.on('data',value=>{errors+=value;});t.after(()=>child.kill());
  const deadline=Date.now()+5000;let response;
  while(Date.now()<deadline){
    try{response=await fetch(`http://127.0.0.1:${port}/desktop_dashboard_handoff_test.php`);break;}
    catch{if(child.exitCode!==null)throw Error(errors);await new Promise(resolve=>setTimeout(resolve,20));}
  }
  assert.ok(response,errors);assert.equal(response.status,200);assert.equal((await response.text()).trim(),'PASS HTTP private handoff refused');
});
