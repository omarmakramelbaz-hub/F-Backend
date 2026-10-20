'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {registryString,powerShellJson}=require('./helpers/windows-registry.cjs');
const encoded=value=>Buffer.from(JSON.stringify(value),'utf16le').toString('base64');
test('native registration proof preserves the exact quoted Arabic REG_SZ through ASCII base64',()=>{
  const registration='HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\FasakhanstaDashboardPreview';
  const expected='"C:\\Users\\Runner\\تجربة البرنامج\\Uninstall.exe"';let invocation;
  const value=registryString('powershell.exe',registration,'UninstallString',(...args)=>{invocation=args;return encoded(expected);});
  assert.equal(value,expected);assert.notEqual(value,expected.replace(/[^\x00-\x7f]/g,'?'));
  const [executable,args,options]=invocation;assert.equal(executable,'powershell.exe');
  assert.deepEqual(args.slice(0,3),['-NoProfile','-NonInteractive','-EncodedCommand']);
  const script=Buffer.from(args[3],'base64').toString('utf16le');
  assert.match(script,/Get-ItemProperty -LiteralPath \$env:GO_ERP_REGISTRY_PATH -Name \$env:GO_ERP_REGISTRY_FIELD/);
  assert.match(script,/GetValueKind\(\$env:GO_ERP_REGISTRY_FIELD\).*RegistryValueKind\]::String/);
  assert.match(script,/\[Text.Encoding\]::Unicode.GetBytes\(\$json\)/);
  assert.equal(script.includes(registration),false);assert.equal(script.includes('UninstallString'),false);
  assert.equal(options.encoding,'ascii');assert.equal(options.windowsHide,true);assert.equal(options.shell,undefined);
  assert.equal(options.env.GO_ERP_REGISTRY_PATH,'Registry::HKEY_CURRENT_USER\\'+registration.slice(5));
  assert.equal(options.env.GO_ERP_REGISTRY_FIELD,'UninstallString');
});
test('native folder paths preserve Unicode and quotes using the same console-independent transport',()=>{
  const expected={desktop:'C:\\مستخدم "اختبار"\\Desktop',programs:'C:\\مستخدم "اختبار"\\Start Menu'};
  assert.deepEqual(powerShellJson('powershell.exe','$result=$null',{},()=>encoded(expected)),expected);
});
test('native registry proof propagates read failures and rejects malformed transport or nonstring values',()=>{
  const read=run=>registryString('powershell.exe','HKCU\\Software\\GO ERP','UninstallString',run);
  const failure=Error('Registry property absent');assert.throws(()=>read(()=>{throw failure;}),error=>error===failure);
  for(const output of ['','not base64!',Buffer.from('x').toString('base64'),encoded({unexpected:true})])
    assert.throws(()=>read(()=>output));
  assert.equal(read(()=>encoded('GO ERP')),'GO ERP');
});
