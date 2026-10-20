'use strict';
// Compile the actual reviewed installer with a tampered prerequisite and a tiny
// application fixture. A failed prerequisite must precede every install effect.
const fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {execFileSync,spawnSync}=require('node:child_process');
module.exports=async project=>{
  assert.equal(process.platform,'win32');
  // Use the same pinned resolver as NsisTarget: cache folders include the release
  // and URL hash, and may be redirected through the supported environment.
  const lock=JSON.parse(await fs.readFile(path.join(project,'package-lock.json'),'utf8'));
  const pinned=lock.packages['node_modules/app-builder-lib'].version;
  const resolver=require.resolve('app-builder-lib/out/toolsets/windows.js',{paths:[project]});
  const installed=JSON.parse(await fs.readFile(path.resolve(path.dirname(resolver),'../../package.json'),'utf8'));
  assert.equal(pinned,'26.15.3','Use the reviewed NSIS resolver.');
  assert.equal(installed.version,pinned,'Use the exact NSIS resolver verified by the pinned build.');
  const build=JSON.parse(await fs.readFile(path.join(project,'package.json'),'utf8')).build||{};
  const compiler=await require(resolver).getMakeNsisPath(build.toolsets?.nsis,build.nsis?.customNsisBinary);
  assert.ok((await fs.stat(compiler.path)).isFile(),'The pinned NSIS resolver must return its actual compiler.');
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'fasakhansta-prerequisite-'));
  const fixture=path.join(root,'fixture'),target=path.join(root,'installed'),setup=path.join(root,'failure.exe');
  const key='FasakhanstaPrerequisiteFixture-'+path.basename(root),product='Fasakhansta Prerequisite Fixture '+path.basename(root);
  const registrationPath='HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\'+key;
  const folders=JSON.parse(execFileSync(path.join(process.env.SystemRoot,'System32/WindowsPowerShell/v1.0/powershell.exe'),
    ['-NoProfile','-NonInteractive','-Command',
      '[pscustomobject]@{ desktop = [Environment]::GetFolderPath("DesktopDirectory"); programs = [Environment]::GetFolderPath("Programs") } | ConvertTo-Json -Compress'],
    {windowsHide:true,encoding:'utf8',timeout:30000}));
  assert.ok(folders.desktop&&folders.programs);
  const shortcut=path.join(folders.desktop,product+'.lnk'),menu=path.join(folders.programs,product);
  const safe=value=>value.replaceAll('$',()=> '$$').replaceAll('"','$\\"');
  try{
    await fs.mkdir(path.join(fixture,'dist/win-unpacked'),{recursive:true});
    await fs.mkdir(path.join(fixture,'dist/prerequisites'),{recursive:true});
    await fs.mkdir(path.join(fixture,'src/assets'),{recursive:true});
    await fs.mkdir(target);await fs.writeFile(path.join(target,'KEEP.txt'),'unrelated original file');
    await fs.copyFile(path.join(project,'src/assets/app.ico'),path.join(fixture,'src/assets/app.ico'));
    for(const name of ['windows-vcredist.json','windows-vcredist.ps1'])await fs.copyFile(path.join(project,name),path.join(fixture,name));
    const bytes=await fs.readFile(path.join(project,'dist/prerequisites/vc_redist.x64.exe'));bytes[1024]^=1;
    await fs.writeFile(path.join(fixture,'dist/prerequisites/vc_redist.x64.exe'),bytes);
    await fs.writeFile(path.join(fixture,'dist/win-unpacked/SHOULD-NOT-BE-INSTALLED.txt'),'must never be extracted');
    await fs.writeFile(path.join(fixture,'dist/installer-version.nsh'),
      '!define FASAKHANSTA_VERSION "0.0.0"\n!define FASAKHANSTA_PRODUCT_NAME "'+product+'"\n'
      +'!define FASAKHANSTA_UNINSTALL_KEY "'+key+'"\n');
    await fs.writeFile(path.join(fixture,'dist/uninstall-files.nsh'),'Delete "$INSTDIR\\SHOULD-NOT-BE-INSTALLED.txt"\n');
    // Apply the same separator/compression adaptation used by the Windows
    // packaging hook; prerequisite logic and every file remain unchanged.
    const reviewed=(await fs.readFile(path.join(project,'installer.nsi'),'utf8'))
      .replace(/\$\{PROJECT_DIR\}\/[^"\r\n]+/g,value=>value.replaceAll('/','\\'))
      .replace('SetCompressor /SOLID lzma','SetCompressor /SOLID zlib');
    await fs.writeFile(path.join(fixture,'installer-reviewed-windows.nsi'),reviewed);
    const wrapper=path.join(root,'failure.nsi');
    // Select the reviewed Unicode target before OutFile creates the NSIS header.
    await fs.writeFile(wrapper,'Unicode true\nOutFile "'+safe(setup)+'"\n!define PROJECT_DIR "'+safe(fixture)+'"\n'
      +'!macro addLangs\n!insertmacro MUI_LANGUAGE "English"\n!macroend\n!include "'+safe(path.join(fixture,'installer-reviewed-windows.nsi'))+'"\n');
    execFileSync(compiler.path,['/V2',wrapper],{windowsHide:true,timeout:300000,env:{...process.env,...(compiler.env||{})}});
    const failure=spawnSync(setup,['/S','/D='+target],{windowsHide:true,windowsVerbatimArguments:true,timeout:120000});
    if(failure.error)throw failure.error;
    assert.notEqual(failure.status,0,'A tampered prerequisite was accepted.');assert.notEqual(failure.status,3010);
    assert.deepEqual((await fs.readdir(target)).sort(),['KEEP.txt']);
    assert.equal(await fs.readFile(path.join(target,'KEEP.txt'),'utf8'),'unrelated original file');
    const registration=spawnSync('reg.exe',['query',registrationPath],{windowsHide:true});
    assert.equal(registration.status,1,'Failed prerequisite created an app uninstall registration.');
    await assert.rejects(fs.access(shortcut),{code:'ENOENT'});await assert.rejects(fs.access(menu),{code:'ENOENT'});
    process.stdout.write('PASS the actual compiled NSIS installer rejects a tampered prerequisite before application extraction, shortcuts or registration\n');
    return true;
  }finally{
    // These unique test-only names also clean up if a regression creates them.
    spawnSync('reg.exe',['delete',registrationPath,'/f'],{windowsHide:true});
    await fs.rm(shortcut,{force:true});await fs.rm(menu,{recursive:true,force:true});
    await fs.rm(root,{recursive:true,force:true});
  }
};
