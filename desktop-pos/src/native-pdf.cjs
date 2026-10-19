'use strict';
const fs=require('node:fs/promises'),path=require('node:path'),crypto=require('node:crypto');
const policy=require('./wkhtmltox-windows.json');
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const provenance=JSON.stringify(policy,null,2)+'\n';
const sorted=value=>JSON.stringify(Object.entries(value).sort(([a],[b])=>a.localeCompare(b)));

function packageFiles(){
  const files={};
  for(const [name,digest] of Object.entries(policy.files))files['wkhtmltox/'+name]=digest;
  for(const [name,item] of Object.entries(policy.licenses))files['wkhtmltox/licenses/'+name]=item.sha256;
  files['wkhtmltox/provenance.json']=sha(provenance);
  return files;
}
function declaration(){
  return {format:1,name:policy.name,version:policy.version,platform:policy.platform,
    archiveSha256:policy.archive.sha256,policySha256:sha(provenance),files:packageFiles()};
}
async function inventory(directory){
  const files={};
  const walk=async relative=>{
    for(const entry of await fs.readdir(path.join(directory,relative),{withFileTypes:true})){
      const name=relative?relative+'/'+entry.name:entry.name;
      if(entry.isSymbolicLink()||/[\\:\x00-\x1f]/.test(name))throw Error('Unsafe Windows PDF component path: '+name);
      if(entry.isDirectory())await walk(name);
      else if(entry.isFile())files[name]=sha(await fs.readFile(path.join(directory,name)));
      else throw Error('Invalid Windows PDF component entry: '+name);
    }
  };
  await walk('');return files;
}
async function verifyExtracted(directory){
  const actual=await inventory(directory);
  if(sorted(actual)!==sorted(policy.files))throw Error('The Windows PDF files differ from the pinned official release.');
  return actual;
}
async function packageInto(sourceDirectory,bundle,repository){
  await verifyExtracted(sourceDirectory);
  const target=path.join(bundle,'wkhtmltox');
  await fs.cp(sourceDirectory,target,{recursive:true,errorOnExist:true,force:false});
  await fs.mkdir(path.join(target,'licenses'),{recursive:true});
  for(const [name,item] of Object.entries(policy.licenses)){
    const bytes=await fs.readFile(path.join(repository,'desktop-pos/licenses/wkhtmltox',name));
    if(sha(bytes)!==item.sha256)throw Error('The Windows PDF license or source notice changed: '+name);
    await fs.writeFile(path.join(target,'licenses',name),bytes,{flag:'wx'});
  }
  await fs.writeFile(path.join(target,'provenance.json'),provenance,{flag:'wx'});
  const result=declaration();await verify(bundle,result);return result;
}
async function verify(bundle,claimed,knownFiles){
  const expected=declaration();
  if(!claimed||['format','name','version','platform','archiveSha256','policySha256'].some(key=>claimed[key]!==expected[key])
      ||!claimed.files||Array.isArray(claimed.files)||sorted(claimed.files)!==sorted(expected.files))
    throw Error('The Windows PDF runtime has no matching pinned source declaration.');
  const actual=knownFiles?Object.fromEntries(Object.entries(knownFiles).filter(([name])=>name.startsWith('wkhtmltox/')))
    :Object.fromEntries(Object.entries(await inventory(path.join(bundle,'wkhtmltox'))).map(([name,digest])=>['wkhtmltox/'+name,digest]));
  if(sorted(actual)!==sorted(expected.files))throw Error('The packaged Windows PDF runtime differs from the pinned official files.');
  return expected;
}
module.exports={policy,declaration,packageFiles,verifyExtracted,packageInto,verify};
