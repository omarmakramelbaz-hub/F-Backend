'use strict';
// Copy into desktop-pos before packaging; preserve the complete verified runtime.
const fs=require('node:fs/promises'),path=require('node:path'),crypto=require('node:crypto');
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
module.exports=async context=>{
  const project=context.packager.projectDir;
  const receipt=JSON.parse(await fs.readFile(path.join(project,'dist/runtime-package.json'),'utf8'));
  const source=path.join(project,'runtime-bundle'),target=path.join(context.appOutDir,'resources/dashboard-runtime');
  const verified=await require('./installer-runtime.cjs').verifyBundle(source);
  if(JSON.stringify(Object.keys(verified.files).sort())!==JSON.stringify(Object.keys(receipt.files).sort())
      ||Object.keys(receipt.files).some(name=>verified.files[name]!==receipt.files[name]))throw Error('Runtime changed after its source review.');
  let restored=0;
  for(const [name,expected]of Object.entries(receipt.files)){
    const output=path.join(target,name);let current;
    try{current=hash(await fs.readFile(output));}catch(error){if(error.code!=='ENOENT')throw error;}
    if(current===expected)continue;
    const input=path.join(source,name),bytes=await fs.readFile(input);
    if(hash(bytes)!==expected)throw Error('Runtime receipt mismatch: '+name);
    await fs.mkdir(path.dirname(output),{recursive:true});await fs.copyFile(input,output);restored++;
  }
  process.stdout.write('Restored '+restored+' exact runtime files before complete installed-byte verification.\n');
  await require('./build-uninstaller.cjs')(context);
  // Recreate the generated NSIS header after the output directory has
  // been prepared. Values must agree with the verified build receipt.
  const info=JSON.parse(await fs.readFile(path.join(project,'package.json'),'utf8'));
  const product=context.packager.appInfo.productName;
  if(info.version!==receipt.version||receipt.channel!=='preview'
      ||!/^[0-9]+\.[0-9]+\.[0-9]+-preview\.[1-9][0-9]*$/.test(info.version)
      ||!/^[A-Za-z0-9 ._-]{1,80}$/.test(product))throw Error('Invalid verified installer metadata.');
  await fs.writeFile(path.join(project,'dist/installer-version.nsh'),
    '!define FASAKHANSTA_VERSION "'+info.version+'"\n!define FASAKHANSTA_PRODUCT_NAME "'+product+'"\n'
    +'!define FASAKHANSTA_UNINSTALL_KEY "FasakhanstaDashboardPreview"\n');
  const nsis=await fs.readFile(path.join(project,'installer.nsi'),'utf8');
  let windowsNsis=nsis.replace(/\$\{PROJECT_DIR\}\/[^"\r\n]+/g,value=>value.replaceAll('/','\\'));
  const originalFile='File /r "${PROJECT_DIR}\\dist\\win-unpacked\\*"';
  if(windowsNsis.split(originalFile).length!==2)throw Error('Unexpected original NSIS file command.');
  for(const name of Object.keys(receipt.files))if(('S:\\resources\\dashboard-runtime\\'+name).length>=260)
    throw Error('Runtime source path still exceeds the native compiler limit: '+name);
  try{await fs.access('S:\\');throw Error('The installer source drive is already in use.');}
  catch(error){if(error.code!=='ENOENT')throw error;}
  require('node:child_process').execFileSync('subst',['S:',path.resolve(context.appOutDir)],{windowsHide:true});
  await fs.writeFile(path.join(project,'dist/nsis-source-drive.txt'),'S:\n');
  windowsNsis=windowsNsis.replace(originalFile,'File /r "S:\\*"');
  await fs.writeFile(path.join(project,'installer-windows.nsi'),windowsNsis);
  await fs.access(path.join(project,'dist/installer-version.nsh'));
  process.stdout.write('Verified NSIS metadata and Windows path separators in '+project+'\n');
  // The pinned builder uses a hardcoded four-minute stdin compiler
  // deadline. Extend only makensis, retaining its output validation.
  const executor=require('builder-util/out/util.js'),normal=executor.spawnAndWriteWithOutput;
  executor.spawnAndWriteWithOutput=(command,args,data,options)=>{
    if(path.basename(command).toLowerCase()!=='makensis.exe')return normal(command,args,data,options);
    process.stdout.write('Compiling the complete runtime with a bounded 15-minute NSIS deadline.\n');
    return new Promise((resolve,reject)=>{
      const child=require('node:child_process').spawn(command,args,{...options,windowsHide:true,shell:false,stdio:['pipe','pipe','pipe']});
      let stdout='',stderr='',timedOut=false,logExceeded=false;
      const timer=setTimeout(()=>{timedOut=true;child.kill();},15*60*1000);
      child.once('error',error=>{clearTimeout(timer);reject(error);});
      const collect=(bytes,isError)=>{
        if(logExceeded)return;
        if(isError)stderr+=bytes.toString();else stdout+=bytes.toString();
        if(stdout.length+stderr.length>32*1024*1024){logExceeded=true;child.kill();}
      };
      child.stdout.on('data',bytes=>collect(bytes,false));child.stderr.on('data',bytes=>collect(bytes,true));
      child.stdin.on('error',()=>{});
      child.once('close',code=>{
        clearTimeout(timer);
        if(timedOut)reject(Error('NSIS compilation exceeded its 15-minute limit.'));
        else if(logExceeded)reject(Error('NSIS compilation output exceeded its 32 MiB limit.'));
        else if(code!==0)reject(Error('NSIS compilation failed ('+code+'): '+stdout+'\n'+stderr));
        else resolve({stdout,stderr});
      });
      child.stdin.end(data);
    });
  };
};
