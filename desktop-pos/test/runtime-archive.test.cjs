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
test('source corruption and symlinks cannot acquire an encrypted runtime receipt',async t=>{
  const f=await fixture(t),source=await f.make('a'.repeat(40),'original');await fs.writeFile(path.join(source,'application/source.php'),'changed');
  await assert.rejects(f.archive.retain(source));assert.equal(f.records.size,0);
  if(process.platform!=='win32'){
    const safe=await f.make('b'.repeat(40),'safe');await fs.symlink(path.join(source,'php.exe'),path.join(safe,'external'));
    await assert.rejects(f.archive.retain(safe));assert.equal(f.records.size,0);
  }
});
