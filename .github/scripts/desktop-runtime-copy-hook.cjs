'use strict';
// Copy into desktop-pos before packaging; preserve the complete verified runtime.
const fs=require('node:fs/promises'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
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
  const windowsNsis=nsis.replace(/\$\{PROJECT_DIR\}\/[^"\r\n]+/g,value=>value.replaceAll('/','\\'));
  if(!windowsNsis.includes('SetCompressor /SOLID lzma'))throw Error('The reviewed preview compression directive is missing.');
  // Use fast lossless preview compression within the pinned builder's compile timeout.
  const previewNsis=windowsNsis.replace('SetCompressor /SOLID lzma','SetCompressor /SOLID zlib');
  const extractionRoot='SetOutPath "$INSTDIR"';
  if(previewNsis.split(extractionRoot).length!==2)throw Error('The reviewed installation output root is missing or ambiguous.');
  // The Unicode NSIS runtime accepts the extended-length prefix without a
  // machine-wide long-path policy. Keep $INSTDIR normal for shortcuts/registry.
  const extendedNsis=previewNsis.replace(extractionRoot,'SetOutPath "\\\\?\\$INSTDIR"');
  const uninstallList=path.join(project,'dist/uninstall-files.nsh');
  // Use the Unicode filesystem APIs for every exact packaged path. Reject
  // quote delimiters rather than emitting an ambiguous System::Call argument.
  const removal=(await fs.readFile(uninstallList,'utf8')).replace(/^(Delete|RMDir) "(\$INSTDIR\\[^"'\r\n]+)"$/gm,
    (_,command,target)=>"System::Call 'kernel32::"+(command==='Delete'?'DeleteFileW':'RemoveDirectoryW')
      +'(w "\\\\?\\'+target+'") i .r0\'');
  if(/^(Delete|RMDir) /m.test(removal))throw Error('An unreviewed uninstaller path remains.');
  await fs.writeFile(uninstallList,removal);
  const fileDirective='File /r "${PROJECT_DIR}\\dist\\win-unpacked\\*"';
  if(!extendedNsis.includes(fileDirective))throw Error('The complete NSIS input directive is missing.');
  // makensis cannot open some original vendor files via the runner's long path.
  // Map only the already verified output to a free drive; preserve every byte.
  const drive='R:';
  try{await fs.access(drive+'\\');throw Error('The compiler input drive is already occupied.');}
  catch(error){if(error.code!=='ENOENT')throw error;}
  execFileSync('subst.exe',[drive,context.appOutDir],{windowsHide:true});
  await fs.writeFile(path.join(project,'dist/nsis-build-drive.json'),JSON.stringify({drive,directory:context.appOutDir})+'\n');
  await fs.writeFile(path.join(project,'installer-windows.nsi'),extendedNsis.replace(fileDirective,'File /r "'+drive+'\\*"'));
  await fs.access(path.join(project,'dist/installer-version.nsh'));
  process.stdout.write('Verified NSIS metadata and Windows path separators in '+project+'\n');
};
