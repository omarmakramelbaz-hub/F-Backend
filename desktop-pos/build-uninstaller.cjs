const fs=require('node:fs/promises'),path=require('node:path');
// Remove only files packaged by this application, even if a custom install directory has other files.
module.exports=async context=>{
  await require('./installer-runtime.cjs').afterPack(context);
  const files=[],dirs=[];
  async function walk(dir,prefix=''){for(const e of await fs.readdir(dir,{withFileTypes:true})){const r=prefix?prefix+'/'+e.name:e.name;if(e.isDirectory()){dirs.push(r);await walk(path.join(dir,e.name),r);}else files.push(r);}}
  await walk(context.appOutDir);
  const safe=r=>r.replaceAll('/','\\').replaceAll('$','$$').replaceAll('"','$\\"');
  const lines=files.map(r=>'Delete "$INSTDIR\\'+safe(r)+'"');
  for(const r of dirs.sort((a,b)=>b.split('/').length-a.split('/').length))lines.push('RMDir "$INSTDIR\\'+safe(r)+'"');
  await fs.writeFile(path.join(__dirname,'dist','uninstall-files.nsh'),lines.join('\n')+'\n');
};
