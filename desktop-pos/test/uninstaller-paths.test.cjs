'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),vm=require('node:vm');
const {createRequire}=require('node:module');
const {DatabaseSync}=require('node:sqlite');
const generatorFile=path.resolve(__dirname,'../build-uninstaller.cjs');
const hookFile=path.resolve(__dirname,'../../.github/scripts/desktop-runtime-copy-hook.cjs');

// Exercise the actual generator and hook. Native bundle verification already has
// its own tests; mock only that boundary and the Windows drive mapping here.
async function load(file,overrides,dirname=path.dirname(file)){
  const localRequire=createRequire(file),module={exports:{}};
  const wrapper=vm.runInNewContext('(function(require,module,exports,__dirname){\n'
    +await fs.readFile(file,'utf8')+'\n})',{process:{stdout:{write(){}}}},{filename:file});
  wrapper(name=>Object.hasOwn(overrides,name)?overrides[name]:localRequire(name),module,module.exports,dirname);
  return module.exports;
}
async function fixture(t,{syntheticName,extraCommand}={}){
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'dashboard-uninstall-'));
  t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const project=path.join(root,'desktop-pos'),out=path.join(root,'installed'),state={afterPackCalls:0};
  const long='resources/dashboard-runtime/application/vendor/'+['a'.repeat(90),'b'.repeat(90),'c'.repeat(90)].join('/')+'/ملف.txt';
  const files=['app.exe','resources/app.asar','موارد عربية/صور/فاتورة $literal $INSTDIR.png',long];
  for(const name of files){const file=path.join(out,...name.split('/'));await fs.mkdir(path.dirname(file),{recursive:true});await fs.writeFile(file,name);}
  await fs.mkdir(path.join(out,'empty','child','grandchild'),{recursive:true});
  await fs.mkdir(path.join(project,'dist'),{recursive:true});
  await fs.mkdir(path.join(project,'runtime-bundle'),{recursive:true});
  await fs.writeFile(path.join(project,'dist/runtime-package.json'),JSON.stringify({version:'0.3.0-preview.1',channel:'preview',files:{}}));
  await fs.writeFile(path.join(project,'package.json'),JSON.stringify({version:'0.3.0-preview.1'}));
  await fs.copyFile(path.resolve(__dirname,'../installer.nsi'),path.join(project,'installer.nsi'));
  const generatorFs={...fs,readdir:async(dir,options)=>{
    const entries=await fs.readdir(dir,options);
    if(syntheticName&&dir===out)entries.push({name:syntheticName,isDirectory:()=>false});
    return entries;
  }};
  const generate=await load(generatorFile,{'node:fs/promises':generatorFs,
    './installer-runtime.cjs':{afterPack:async()=>{state.afterPackCalls++;}}},project);
  const hook=await load(hookFile,{
    'node:fs/promises':{...fs,access:async file=>{
      if(file==='R:\\'){const error=Error('Unused test drive');error.code='ENOENT';throw error;}
      return fs.access(file);
    }},
    'node:child_process':{execFileSync(command,args){assert.equal(command,'subst.exe');assert.deepEqual(Array.from(args),['R:',out]);}},
    './installer-runtime.cjs':{verifyBundle:async()=>({files:{}})},
    './build-uninstaller.cjs':async context=>{
      await generate(context);
      const file=path.join(project,'dist/uninstall-files.nsh');
      state.original=await fs.readFile(file,'utf8');
      if(extraCommand){state.original+=extraCommand+'\n';await fs.writeFile(file,state.original);}
    }
  });
  return {root,project,out,state,files,hook,context:{packager:{projectDir:project,appInfo:{productName:'Fasakhansta Dashboard Preview'}},appOutDir:out}};
}
function parseCalls(text){
  const start="System::Call 'kernel32::",end='") i .r0\'';
  return text.trimEnd().split('\n').map(line=>{
    assert.ok(line.startsWith(start)&&line.endsWith(end),line);
    const separator=line.indexOf('(w "'),method=line.slice(start.length,separator);
    assert.ok(['DeleteFileW','RemoveDirectoryW'].includes(method));
    const extended=line.slice(separator+4,-end.length);
    assert.ok(extended.startsWith('\\\\?\\$INSTDIR\\'),extended);
    return {method,target:extended.slice(4)};
  });
}
const relative=target=>target.slice('$INSTDIR\\'.length).replaceAll('$$','$').split('\\');
test('Unicode uninstall preserves the generated exact inventory, dollar escaping and deepest-first empty directories',async t=>{
  const f=await fixture(t);await f.hook(f.context);
  assert.equal(f.state.afterPackCalls,1);
  const converted=await fs.readFile(path.join(f.project,'dist/uninstall-files.nsh'),'utf8');
  const calls=parseCalls(converted),original=f.state.original.trimEnd().split('\n').map(line=>{
    const match=/^(Delete|RMDir) "(.*)"$/.exec(line);assert.ok(match,line);
    return {method:match[1]==='Delete'?'DeleteFileW':'RemoveDirectoryW',target:match[2]};
  });
  assert.deepEqual(calls,original);assert.equal(new Set(calls.map(call=>call.target)).size,calls.length);
  assert.doesNotMatch(converted,/^(Delete|RMDir) |\/r\b/m);
  for(const call of calls)assert.doesNotMatch(call.target,/[*?]/);
  assert.deepEqual(calls.filter(call=>call.method==='DeleteFileW').map(call=>relative(call.target).join('/')).sort(),f.files.slice().sort());
  assert.ok(calls.some(call=>call.target.endsWith('فاتورة $$literal $$INSTDIR.png')));
  assert.ok(calls.some(call=>call.target.length>260));
  const directories=calls.filter(call=>call.method==='RemoveDirectoryW');
  for(let i=1;i<directories.length;i++)assert.ok(relative(directories[i-1].target).length>=relative(directories[i].target).length);
  assert.ok(directories.some(call=>relative(call.target).join('/')==='empty/child/grandchild'));
  assert.ok(calls.slice(0,f.files.length).every(call=>call.method==='DeleteFileW'));
  // Add unrelated data after packaging. The exact API inventory never names it;
  // selective rmdir semantics preserve nonempty directories containing it.
  const unknown=path.join(f.out,'empty','child','grandchild','unrelated.txt'),ledger=path.join(f.root,'userData','unsynced.sqlite');
  await fs.writeFile(unknown,'keep unrelated file');await fs.mkdir(path.dirname(ledger));await fs.writeFile(ledger,'paid unsynced order');
  for(const call of calls){
    const target=path.join(f.out,...relative(call.target));
    if(call.method==='DeleteFileW')await fs.unlink(target);
    else try{await fs.rmdir(target);}catch(error){if(!['ENOTEMPTY','EEXIST'].includes(error.code))throw error;}
  }
  for(const name of f.files)await assert.rejects(fs.access(path.join(f.out,...name.split('/'))),{code:'ENOENT'});
  assert.equal(await fs.readFile(unknown,'utf8'),'keep unrelated file');
  assert.equal(await fs.readFile(ledger,'utf8'),'paid unsynced order');
});
test('unsupported quote delimiters and broad NSIS removal directives fail before replacing the generated inventory',async t=>{
  for(const options of [{syntheticName:'quoted"name.txt'},{syntheticName:"apostrophe'name.txt"},
    {extraCommand:'RMDir /r "$INSTDIR\\resources"'},{extraCommand:'Delete /REBOOTOK "$INSTDIR\\*"'}]){
    const f=await fixture(t,options);
    await assert.rejects(f.hook(f.context),/An unreviewed uninstaller path remains/);
    const retained=await fs.readFile(path.join(f.project,'dist/uninstall-files.nsh'),'utf8');
    assert.equal(retained,f.state.original);assert.doesNotMatch(retained,/System::Call/);
    if(options.syntheticName?.includes('"'))assert.ok(retained.includes('quoted$\\"name.txt'));
  }
});

test('the later NSIS elevation helper is removed by its exact name while unknown resource files and real SQLite data survive',async t=>{
  const f=await fixture(t);
  f.context.electronPlatformName='win32';
  f.context.packager.info={framework:{isCopyElevateHelper:true}};
  f.context.targets=[{name:'nsis',options:{perMachine:false}}];
  await f.hook(f.context);
  // Reproduce the builder order: this file is absent when afterPack walks,
  // and copied by NSIS AppPackageHelper only after the hook has returned.
  const elevate=path.join(f.out,'resources','elevate.exe');
  await assert.rejects(fs.access(elevate),{code:'ENOENT'});
  await fs.writeFile(elevate,'the later packaged NSIS helper');
  const keep=path.join(f.out,'resources','KEEP-MY-FILE.txt');
  await fs.writeFile(keep,'unrelated resource file');
  const ledger=path.join(f.root,'userData','unsynced.sqlite');
  await fs.mkdir(path.dirname(ledger));
  let db=new DatabaseSync(ledger);
  db.exec('CREATE TABLE outbox(id TEXT PRIMARY KEY, acked INTEGER, payload TEXT)');
  db.prepare('INSERT INTO outbox VALUES(?,?,?)').run('paid-sale',0,'paid unsynced order');db.close();
  const calls=parseCalls(await fs.readFile(path.join(f.project,'dist/uninstall-files.nsh'),'utf8'));
  assert.deepEqual(calls.filter(call=>call.method==='DeleteFileW').map(call=>relative(call.target).join('/')).sort(),
    [...f.files,'resources/elevate.exe'].sort());
  assert.equal(calls.filter(call=>relative(call.target).join('/')==='resources/elevate.exe').length,1);
  for(const call of calls){
    assert.doesNotMatch(call.target,/[*?]/);
    const target=path.join(f.out,...relative(call.target));
    if(call.method==='DeleteFileW')await fs.unlink(target);
    else try{await fs.rmdir(target);}catch(error){if(!['ENOTEMPTY','EEXIST'].includes(error.code))throw error;}
  }
  await assert.rejects(fs.access(elevate),{code:'ENOENT'});
  assert.equal(await fs.readFile(keep,'utf8'),'unrelated resource file');
  db=new DatabaseSync(ledger,{readOnly:true});
  try{assert.deepEqual({...db.prepare('SELECT * FROM outbox').get()},{id:'paid-sale',acked:0,payload:'paid unsynced order'});}finally{db.close();}
});

test('an elevation filename is anticipated only when the pinned NSIS target will actually package it, without duplicate deletes',async t=>{
  for(const [name,platform,framework,target,options,expected]of [
    ['default helper','win32',true,'nsis',{},true],
    ['helper disabled','win32',true,'nsis',{packElevateHelper:false,perMachine:false},false],
    ['per-machine forces helper','win32',true,'nsis',{packElevateHelper:false,perMachine:true},true],
    ['another target','win32',true,'zip',{},false],
    ['another framework','win32',false,'nsis',{},false],
    ['another platform','linux',true,'nsis',{},false],
  ]){
    const f=await fixture(t);
    f.context.electronPlatformName=platform;f.context.packager.info={framework:{isCopyElevateHelper:framework}};
    f.context.targets=[{name:target,options}];await f.hook(f.context);
    const calls=parseCalls(await fs.readFile(path.join(f.project,'dist/uninstall-files.nsh'),'utf8'));
    assert.equal(calls.some(call=>relative(call.target).join('/')==='resources/elevate.exe'),expected,name);
    if(!expected){
      // A user's same-name file introduced after packaging has no removal entry.
      const unknown=path.join(f.out,'resources','elevate.exe');await fs.writeFile(unknown,'keep unpackaged file');
      for(const call of calls){const file=path.join(f.out,...relative(call.target));
        if(call.method==='DeleteFileW')await fs.unlink(file);
        else try{await fs.rmdir(file);}catch(error){if(!['ENOTEMPTY','EEXIST'].includes(error.code))throw error;}}
      assert.equal(await fs.readFile(unknown,'utf8'),'keep unpackaged file');
    }
  }
  const f=await fixture(t);await fs.writeFile(path.join(f.out,'resources','elevate.exe'),'already packaged helper');
  f.context.electronPlatformName='win32';f.context.packager.info={framework:{isCopyElevateHelper:true}};
  f.context.targets=[{name:'nsis',options:{}}];await f.hook(f.context);
  const calls=parseCalls(await fs.readFile(path.join(f.project,'dist/uninstall-files.nsh'),'utf8'));
  assert.equal(calls.filter(call=>relative(call.target).join('/')==='resources/elevate.exe').length,1);
});
