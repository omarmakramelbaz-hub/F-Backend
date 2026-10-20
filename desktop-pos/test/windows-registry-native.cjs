'use strict';
// Only an owned HKCU fixture is changed. This is an OS transport proof, not an
// installer acceptance receipt or a substitute for the full Windows workflow.
const assert=require('node:assert/strict'),path=require('node:path'),crypto=require('node:crypto'),fs=require('node:fs');
const {execFileSync}=require('node:child_process');
assert.equal(process.platform,'win32','This proof requires actual Windows.');
const release='8ad022856b9feacdc96a3449ff6d075c29bb2ae4',helperBlob='27451e1c6ee19a8ed2e48e04973aa90bb709a19d';
const repository=path.resolve(__dirname,'../..'),helperName='desktop-pos/test/helpers/windows-registry.cjs';
const git=(...args)=>execFileSync('git',args,{cwd:repository,encoding:'ascii',windowsHide:true,timeout:30000}).trim();
assert.equal(git('rev-parse','HEAD:'+helperName),helperBlob,'The proof branch changed the actual release helper.');
const helperBytes=fs.readFileSync(path.join(repository,helperName));
assert.equal(crypto.createHash('sha1').update(Buffer.from('blob '+helperBytes.length+'\0')).update(helperBytes).digest('hex'),helperBlob,
  'The helper executed by the proof must retain its exact reviewed release bytes.');
const {powerShellJson,registryString}=require('./helpers/windows-registry.cjs');
const powershell=path.join(process.env.SystemRoot,'System32/WindowsPowerShell/v1.0/powershell.exe');
const version=powerShellJson(powershell,'$result=[pscustomobject]@{major=$PSVersionTable.PSVersion.Major;minor=$PSVersionTable.PSVersion.Minor;edition=$PSVersionTable.PSEdition}');
assert.deepEqual(version,{major:5,minor:1,edition:'Desktop'});
const owner=crypto.randomUUID(),keyName='GOERPUnicodeProof_'+owner.replaceAll('-','');
const registration='HKCU\\Software\\'+keyName;
const environment={GO_ERP_PROOF_KEY:keyName,GO_ERP_PROOF_OWNER:owner};
try{
  const created=powerShellJson(powershell,
    '$software=[Microsoft.Win32.Registry]::CurrentUser.OpenSubKey("Software",$true); '
    +'try{$existing=$software.OpenSubKey($env:GO_ERP_PROOF_KEY); '
    +'if($null -ne $existing){$existing.Dispose();throw "Private fixture already exists"}; '
    +'$key=$software.CreateSubKey($env:GO_ERP_PROOF_KEY); '
    +'try{$key.SetValue("FixtureOwner",$env:GO_ERP_PROOF_OWNER,[Microsoft.Win32.RegistryValueKind]::String)}finally{$key.Dispose()}; '
    +'$result=$true}finally{$software.Dispose()}',environment);
  assert.equal(created,true);
  const cases=[
    ['DisplayName','GO ERP — تجربة البرنامج'],
    ['UninstallString','"C:\\Users\\Runner\\تجربة البرنامج\\Uninstall.exe"'],
    ['UninstallString','"C:\\اختبار $البرنامج`\\Uninstall.exe"'],
    ['MetaCharacters','"C:\\تجربة "اسم" و $value `علامة`\\Uninstall.exe"'],
  ];
  for(const [field,expected]of cases){
    const written=powerShellJson(powershell,
      '$software=[Microsoft.Win32.Registry]::CurrentUser.OpenSubKey("Software",$true); '
      +'try{$key=$software.OpenSubKey($env:GO_ERP_PROOF_KEY,$true); '
      +'try{if($key.GetValue("FixtureOwner") -cne $env:GO_ERP_PROOF_OWNER){throw "Fixture ownership changed"}; '
      +'$key.SetValue($env:GO_ERP_PROOF_FIELD,$env:GO_ERP_PROOF_VALUE,[Microsoft.Win32.RegistryValueKind]::String);$result=$true} '
      +'finally{$key.Dispose()}}finally{$software.Dispose()}',
      {...environment,GO_ERP_PROOF_FIELD:field,GO_ERP_PROOF_VALUE:expected});
    assert.equal(written,true);assert.equal(registryString(powershell,registration,field),expected);
  }
  assert.throws(()=>registryString(powershell,registration,'MissingValue'));
  powerShellJson(powershell,
    '$software=[Microsoft.Win32.Registry]::CurrentUser.OpenSubKey("Software",$true); '
    +'try{$key=$software.OpenSubKey($env:GO_ERP_PROOF_KEY,$true); '
    +'try{if($key.GetValue("FixtureOwner") -cne $env:GO_ERP_PROOF_OWNER){throw "Fixture ownership changed"}; '
    +'$key.SetValue("WrongKind",42,[Microsoft.Win32.RegistryValueKind]::DWord);$result=$true} '
    +'finally{$key.Dispose()}}finally{$software.Dispose()}',environment);
  assert.throws(()=>registryString(powershell,registration,'WrongKind'));
  const desktop='C:\\مستخدم "اختبار"\\سطح المكتب $desktop `حرف`';
  const programs='C:\\تجربة البرنامج\\قائمة "ابدأ" $programs `علامة`';
  const folders=powerShellJson(powershell,
    '$result=[pscustomobject]@{desktop=$env:GO_ERP_PROOF_DESKTOP;programs=$env:GO_ERP_PROOF_PROGRAMS;actualDesktop=[Environment]::GetFolderPath("DesktopDirectory")}',
    {GO_ERP_PROOF_DESKTOP:desktop,GO_ERP_PROOF_PROGRAMS:programs});
  assert.equal(folders.desktop,desktop);assert.equal(folders.programs,programs);assert.equal(typeof folders.actualDesktop,'string');
}finally{
  const removed=powerShellJson(powershell,
    '$software=[Microsoft.Win32.Registry]::CurrentUser.OpenSubKey("Software",$true); '
    +'try{$key=$software.OpenSubKey($env:GO_ERP_PROOF_KEY); '
    +'if($null -eq $key){$result=$true}else{ '
    +'try{$owned=$key.GetValue("FixtureOwner") -ceq $env:GO_ERP_PROOF_OWNER}finally{$key.Dispose()}; '
    +'if(-not $owned){throw "Refusing to remove an unowned fixture"}; '
    +'$software.DeleteSubKey($env:GO_ERP_PROOF_KEY,$true);$result=$null -eq $software.OpenSubKey($env:GO_ERP_PROOF_KEY)}} '
    +'finally{$software.Dispose()}',environment);
  assert.equal(removed,true,'The exact owned registry fixture was not removed.');
}
process.stdout.write('PASS actual Windows PowerShell 5.1 preserves exact Arabic REG_SZ, quotes, dollar signs, backticks and Unicode folder JSON; private HKCU fixture removed\n');
process.stdout.write('GO_ERP_WINDOWS_UNICODE_HELPER_PROOF '+JSON.stringify({releaseRevision:release,helperBlob,proofRevision:git('rev-parse','HEAD'),powershell:version})+'\n');
