'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');

function originalBulkHelper(page,roleBulkButton){
  const source=fs.readFileSync(path.join(__dirname,'../../tests/desktop_dashboard_runtime/browser.cjs'),'utf8');
  const start=source.indexOf('    const clickRoleBulk=async outcome=>{');
  const end=source.indexOf('\n    await clickRoleBulk(',start);
  assert.ok(start>=0&&end>start,'Exercise the actual original-browser bulk helper.');
  return vm.runInNewContext(source.slice(start,end)+'\nclickRoleBulk;',{page,roleBulkButton,assert,Promise});
}

for(const deferred of [true,false])test('original bulk helper handles the native alert before '+(deferred?'deferred failure delivery':'finishing an already delivered response'),async()=>{
  const events=[];let dialogs=0,showAlert,finishClick,finishOutcome,accepted=false;
  const result={kind:deferred?'requestfailed':'response',status:deferred?null:200};
  // This outcome listener is armed before the original button click, as in the real fixture.
  const outcome=deferred?new Promise(resolve=>{finishOutcome=resolve;}):Promise.resolve(result);
  const clicked=new Promise(resolve=>{finishClick=resolve;});
  const alertPromise=new Promise(resolve=>{showAlert=resolve;});
  const alert={type:()=> 'alert',message:()=>deferred?'':'Both roles deleted',accept:async()=>{
    events.push('alert accepted');accepted=true;finishOutcome?.(result);finishClick();
  }};
  const confirmation={type:()=> 'confirm',message:()=> 'Are you sure you want to delete this row?',accept:async()=>{
    events.push('confirmation accepted');showAlert(alert);
  }};
  const page={waitForEvent(name){assert.equal(name,'dialog');events.push('dialog listener '+(++dialogs));
    return dialogs===1?Promise.resolve(confirmation):alertPromise;}};
  const roleBulkButton={click(){events.push('original button clicked');return clicked;}};
  const run=originalBulkHelper(page,roleBulkButton)(outcome);
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(accepted,true,'The available alert must be dismissed without waiting for the transport or click completion that it blocks.');
  const returned=await run;
  assert.equal(returned.result,result);assert.equal(returned.message,alert.message());
  assert.deepEqual(events,['dialog listener 1','original button clicked','dialog listener 2','confirmation accepted','alert accepted']);
});
