const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto');
const RuntimeArchive=require('../src/runtime-archive.cjs');
async function fixture(t){
  const profile=await fs.mkdtemp(path.join(os.tmpdir(),'runtime-archive-'));t.after(()=>fs.rm(profile,{recursive:true,force:true}));
  const records=new Map(),metadata={read:async key=>records.get(key)||null,write:async(key,value)=>records.set(key,structuredClone(value))};
  const make=async(id,text)=>{
    const bundle=path.join(profile,'source-'+id);await fs.mkdir(path.join(bundle,'application'),{recursive:true});
    await fs.writeFile(path.join(bundle,'application/source.php'),text);await fs.writeFile(path.join(bundle,'php.exe'),'native-'+text);
    await fs.writeFile(path.join(bundle,'manifest.json'),JSON.stringify({format:1,platform:'win32-x64',sourceRevision:id,sourceHashes:{'source.php':crypto.createHash('sha256').update(text).digest('hex')}}));return bundle;
  };
  return {profile,records,metadata,make,archive:new RuntimeArchive(path.join(profile,'dashboard'),metadata)};
}
test('an application update reopens the exact retained old PHP/MariaDB bundle and never selects new native binaries',async t=>{
  const f=await fixture(t),old='a'.repeat(40),next='b'.repeat(40),source=await f.make(old,'original');
  const retained=await f.archive.select(source,null);await fs.rm(source,{recursive:true});
  const installed=await f.make(next,'upgraded');
  assert.equal(await f.archive.select(installed,{sourceRevision:old}),retained);
  assert.equal(await fs.readFile(path.join(retained,'application/source.php'),'utf8'),'original');
});
test('a missing or modified retained executable fails closed without deleting the archive or journal metadata',async t=>{
  const f=await fixture(t),old='a'.repeat(40),retained=await f.archive.retain(await f.make(old,'old'));
  f.records.set('prepared',{sourceRevision:old,database:'preserved-business-db'});
  await fs.writeFile(path.join(retained,'php.exe'),'altered');
  await assert.rejects(f.archive.select(await f.make('b'.repeat(40),'new'),{sourceRevision:old}));
  assert.equal(f.records.get('prepared').database,'preserved-business-db');assert.equal(await fs.readFile(path.join(retained,'php.exe'),'utf8'),'altered');
});
test('a missing old archive cannot be silently replaced by a new source revision',async t=>{
  const f=await fixture(t);await assert.rejects(f.archive.select(await f.make('b'.repeat(40),'new'),{sourceRevision:'a'.repeat(40)}));assert.equal(f.records.size,0);
});
test('an authenticated old PDF runtime remains recoverable after the installed PDF policy changes',async t=>{
  const f=await fixture(t),old='a'.repeat(40),bundle=await f.make(old,'old PDF source');
  const manifest=JSON.parse(await fs.readFile(path.join(bundle,'manifest.json'),'utf8'));
  manifest.pdfRuntime={format:1,name:'wkhtmltox',version:'prior-retained-version'};
  await fs.mkdir(path.join(bundle,'application/config'),{recursive:true});
  await fs.writeFile(path.join(bundle,'application/config/snappy.php'),'<?php /* prior PDF configuration */');
  await fs.writeFile(path.join(bundle,'manifest.json'),JSON.stringify(manifest));
  await fs.mkdir(path.join(bundle,'wkhtmltox/bin'),{recursive:true});
  await fs.writeFile(path.join(bundle,'wkhtmltox/bin/wkhtmltopdf.exe'),'exact older native bytes');
  const destination=path.join(f.archive.root,old);await fs.mkdir(f.archive.root,{recursive:true});
  await fs.cp(bundle,destination,{recursive:true});
  f.records.set('runtimes/'+old,{format:1,revision:old,files:await f.archive.inventory(destination)});
  assert.equal(await f.archive.select(await f.make('b'.repeat(40),'new'),{sourceRevision:old}),destination);
  await fs.writeFile(path.join(destination,'wkhtmltox/bin/wkhtmltopdf.exe'),'changed older bytes');
  await assert.rejects(f.archive.select(await f.make('c'.repeat(40),'another'),{sourceRevision:old}),/غير مكتملة/);
});
test('a new Snappy bundle cannot omit its PDF declaration and acquire an authenticated receipt',async t=>{
  const f=await fixture(t),bundle=await f.make('a'.repeat(40),'source'),configuration='<?php return [];';
  await fs.mkdir(path.join(bundle,'application/config'),{recursive:true});
  await fs.writeFile(path.join(bundle,'application/config/snappy.php'),configuration);
  const manifest=JSON.parse(await fs.readFile(path.join(bundle,'manifest.json'),'utf8'));
  manifest.sourceHashes['config/snappy.php']=crypto.createHash('sha256').update(configuration).digest('hex');
  await fs.writeFile(path.join(bundle,'manifest.json'),JSON.stringify(manifest));
  await assert.rejects(f.archive.retain(bundle),/غير مكتملة لتشغيل ملفات PDF/);
  assert.equal(f.records.size,0);
});
test('an already authenticated legacy Snappy runtime without PDF metadata still reopens its data',async t=>{
  const f=await fixture(t),old='a'.repeat(40),bundle=await f.make(old,'legacy source');
  await fs.mkdir(path.join(bundle,'application/config'),{recursive:true});
  await fs.writeFile(path.join(bundle,'application/config/snappy.php'),'<?php /* legacy Linux binary path */');
  const destination=path.join(f.archive.root,old);await fs.mkdir(f.archive.root,{recursive:true});
  await fs.cp(bundle,destination,{recursive:true});
  f.records.set('runtimes/'+old,{format:1,revision:old,files:await f.archive.inventory(destination)});
  assert.equal(await f.archive.select(await f.make('b'.repeat(40),'current'),{sourceRevision:old}),destination);
});
test('source corruption and symlinks cannot acquire an encrypted runtime receipt',async t=>{
  const f=await fixture(t),source=await f.make('a'.repeat(40),'original');await fs.writeFile(path.join(source,'application/source.php'),'changed');
  await assert.rejects(f.archive.retain(source));assert.equal(f.records.size,0);
  if(process.platform!=='win32'){
    const safe=await f.make('b'.repeat(40),'safe');await fs.symlink(path.join(source,'php.exe'),path.join(safe,'external'));
    await assert.rejects(f.archive.retain(safe));assert.equal(f.records.size,0);
  }
});

test('warm launch reuses source fingerprint only after every retained byte matches the authenticated receipt',async t=>{
  const sourceCode=require('../src/dashboard-source.cjs'),f=await fixture(t),id='d'.repeat(40),bundle=await f.make(id,'unchanged extra source');
  await fs.mkdir(path.join(bundle,'application/app'),{recursive:true});
  const order='<?php /* original durable order implementation */';
  const lock=JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]});
  await fs.writeFile(path.join(bundle,'application/app/Order.php'),order);await fs.writeFile(path.join(bundle,'application/composer.lock'),lock);
  const manifest=JSON.parse(await fs.readFile(path.join(bundle,'manifest.json'),'utf8'));
  for(const [name,bytes] of [['app/Order.php',order],['composer.lock',lock]])manifest.sourceHashes[name]=crypto.createHash('sha256').update(bytes).digest('hex');
  manifest.sourceFingerprint=await sourceCode.fingerprint(path.join(bundle,'application'));
  await fs.writeFile(path.join(bundle,'manifest.json'),JSON.stringify(manifest));
  const fingerprint=sourceCode.fingerprint;let walks=0;
  sourceCode.fingerprint=async(...args)=>{walks++;return fingerprint(...args);};
  t.after(()=>{sourceCode.fingerprint=fingerprint;});
  const directory=await f.archive.select(bundle,null);
  assert.deepEqual(await f.archive.sourceFingerprint(directory),manifest.sourceFingerprint);assert.equal(walks,1);
  assert.deepEqual(f.records.get('runtimes/'+id).sourceFingerprint,manifest.sourceFingerprint);
  const restarted=new RuntimeArchive(path.join(f.profile,'dashboard'),f.metadata);
  assert.equal(await restarted.select(bundle,{sourceRevision:id}),directory);
  assert.deepEqual(await restarted.sourceFingerprint(directory),manifest.sourceFingerprint);
  assert.equal(walks,1,'the second launch performs no duplicate application fingerprint walk');
  // Metadata caching never becomes a stat-only integrity check. Replacing bytes
  // at the same path/length/mtime must still fail, leaving business state alone.
  f.records.set('prepared',{sourceRevision:id,database:'preserved-business-db'});
  const executable=path.join(directory,'php.exe'),stat=await fs.stat(executable),original=await fs.readFile(executable);
  await fs.writeFile(executable,Buffer.alloc(original.length,0x78));await fs.utimes(executable,stat.atime,stat.mtime);
  const corrupted=new RuntimeArchive(path.join(f.profile,'dashboard'),f.metadata);
  await assert.rejects(corrupted.select(bundle,{sourceRevision:id}),/غير مكتملة/);
  await assert.rejects(corrupted.sourceFingerprint(directory),/يلزم التحقق/);
  await assert.rejects(restarted.select(bundle,{sourceRevision:id}),/غير مكتملة/);
  await assert.rejects(restarted.sourceFingerprint(directory),/يلزم التحقق/,'a failed recheck invalidates prior in-process authority too');
  assert.equal(walks,1);assert.equal(f.records.get('prepared').database,'preserved-business-db');
});

test('a startup cancellation settles pending verification without writing an archive receipt or changing active data',async t=>{
  const f=await fixture(t),id='e'.repeat(40),bundle=await f.make(id,'safe source');
  const binary=await fs.open(path.join(bundle,'application','large.bin'),'wx');
  try { await binary.write(crypto.randomBytes(1024*1024));await binary.truncate(512*1024*1024); } finally { await binary.close(); }
  f.records.set('prepared',{sourceRevision:'a'.repeat(40),database:'existing-data'});
  let stopping=false;const cancelled=new RuntimeArchive(path.join(f.profile,'dashboard'),f.metadata,{stopped:()=>stopping});
  const work=cancelled.retain(bundle),timer=setTimeout(()=>{stopping=true;},50);
  try { await assert.rejects(work,/البرنامج يُغلق/); } finally { clearTimeout(timer); }
  assert.equal(f.records.size,1);assert.equal(f.records.get('prepared').database,'existing-data');
  assert.equal((await fs.readdir(path.join(f.profile,'dashboard')).catch(error=>error.code==='ENOENT'?[]:Promise.reject(error))).length,0);
});

test('a warm launch rejects both missing and extra retained files without replacing its receipt or active data',async t=>{
  const f=await fixture(t),id='f'.repeat(40),bundle=await f.make(id,'preserved source'),retained=await f.archive.retain(bundle);
  const receipt=structuredClone(f.records.get('runtimes/'+id));
  f.records.set('prepared',{sourceRevision:id,database:'preserved-business-db'});
  const executable=path.join(retained,'php.exe'),bytes=await fs.readFile(executable);
  await fs.rm(executable);
  await assert.rejects(new RuntimeArchive(path.join(f.profile,'dashboard'),f.metadata).select(bundle,{sourceRevision:id}),/غير مكتملة/);
  await fs.writeFile(executable,bytes);await fs.writeFile(path.join(retained,'unexpected.php'),'<?php unexpected();');
  await assert.rejects(new RuntimeArchive(path.join(f.profile,'dashboard'),f.metadata).select(bundle,{sourceRevision:id}),/غير مكتملة/);
  assert.deepEqual(f.records.get('runtimes/'+id),receipt);
  assert.equal(f.records.get('prepared').database,'preserved-business-db');
});

test('cancellation during retained-file copying stops at the next entry and removes only its incomplete stage',async t=>{
  const f=await fixture(t),id='9'.repeat(40),bundle=await f.make(id,'preserved source');
  f.records.set('prepared',{sourceRevision:'a'.repeat(40),database:'preserved-business-db'});
  let stopping=false,entries=0;
  const archive=new RuntimeArchive(path.join(f.profile,'dashboard'),f.metadata,{stopped:()=>stopping});
  const cp=fs.cp;
  fs.cp=async(source,target,options)=>{
    assert.equal(typeof options.filter,'function','a large retained copy must have a per-entry cancellation boundary');
    return cp(source,target,{...options,filter:(from,to)=>{
      const accepted=options.filter(from,to);
      if(++entries===2)stopping=true;
      return accepted;
    }});
  };
  try{await assert.rejects(archive.retain(bundle),/البرنامج يُغلق/);}
  finally{fs.cp=cp;}
  assert.ok(entries>=2);assert.equal(f.records.size,1);
  assert.equal(f.records.get('prepared').database,'preserved-business-db');
  assert.deepEqual(await fs.readdir(archive.root),[],'only this interrupted copy stage is removed');
  assert.equal(await fs.readFile(path.join(bundle,'application/source.php'),'utf8'),'preserved source');
});
