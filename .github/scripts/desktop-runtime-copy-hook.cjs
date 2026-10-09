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
  if(process.env.ELECTRON_BUILDER_7ZIP_PATH)throw Error('An unverified extraction-tool override is not allowed.');
  const sevenZip=await require('app-builder-lib/out/toolsets/7zip.js').getPath7za();
  await fs.copyFile(sevenZip,path.join(project,'dist/installer-7za.exe'));
  await fs.mkdir(path.join(context.appOutDir,'resources/third-party'),{recursive:true});
  await fs.writeFile(path.join(context.appOutDir,'resources/third-party/7zip-notice.txt'),
    'This installer uses the 7-Zip standalone program for archive extraction.\n'
    +'7-Zip is licensed under the GNU LGPL. Source code and license information: https://www.7-zip.org/\n');
  await fs.writeFile(path.join(project,'dist/installer-tools.json'),JSON.stringify({format:1,
    provider:'electron-builder@26.15.3',toolsetArchiveSha256:'be071f15bd6da2f78fe81c6ddef2009b0c4d8a51f36b780cb806c7e6df95e1b3',
    extractionExecutableSha256:hash(await fs.readFile(sevenZip))},null,2)+'\n');
  await require('./build-uninstaller.cjs')(context);
  // Call Unicode filesystem APIs directly for each exact packaged path;
  // legacy NSIS path handling can shorten long destination filenames.
  const uninstallFile=path.join(project,'dist/uninstall-files.nsh');
  const deletion=(await fs.readFile(uninstallFile,'utf8')).replace(/^(Delete|RMDir) "(\$INSTDIR\\[^"\r\n]+)"$/gm,
    (_,command,target)=>"System::Call 'kernel32::"+(command==='Delete'?'DeleteFileW':'RemoveDirectoryW')
      +'(w "\\\\?\\'+target+'") i .r0\'');
  if(/^(Delete|RMDir) /m.test(deletion))throw Error('An unreviewed uninstaller path remains.');
  await fs.writeFile(uninstallFile,deletion);
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
  const extraction=[
    'InitPluginsDir',
    'SetOutPath "$PLUGINSDIR"',
    'File /oname=dashboard.7z "${APP_64}"',
    'File /oname=7za.exe "${PROJECT_DIR}\\dist\\installer-7za.exe"',
    'nsExec::ExecToLog /TIMEOUT=900000 \'"$PLUGINSDIR\\7za.exe" x "$PLUGINSDIR\\dashboard.7z" "-o$INSTDIR" -y -bd\'',
    'Pop $0',
    'StrCmp $0 "0" dashboardExtracted',
    'SetErrorLevel 1',
    'MessageBox MB_OK|MB_ICONSTOP "تعذّر تثبيت ملفات البرنامج. أعد المحاولة." /SD IDOK',
    'Quit',
    'dashboardExtracted:',
    'SetOutPath "$INSTDIR"'
  ].join('\n  ');
  windowsNsis=windowsNsis.replace(originalFile,extraction).replace('SetCompressor /SOLID lzma','SetCompressor /FINAL zlib');
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
