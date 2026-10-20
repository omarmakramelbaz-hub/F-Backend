'use strict';
const assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
function powerShellJson(powershell,body,environment={},run=execFileSync){
  // Windows PowerShell 5.1 and reg.exe use console code pages for redirected
  // text. Transport the exact .NET UTF-16 JSON as ASCII base64 instead.
  const script='$ErrorActionPreference="Stop"; '+body+'; '
    +'$json=ConvertTo-Json -InputObject $result -Compress -Depth 8; '
    +'[Console]::Write([Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($json)))';
  const output=run(powershell,['-NoProfile','-NonInteractive','-EncodedCommand',Buffer.from(script,'utf16le').toString('base64')],
    {encoding:'ascii',windowsHide:true,timeout:30000,maxBuffer:128*1024,env:{...process.env,...environment}}).trim();
  assert.match(output,/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/,'PowerShell did not return ASCII base64 JSON.');
  const bytes=Buffer.from(output,'base64');
  assert.ok(bytes.length>0&&bytes.length%2===0&&bytes.toString('base64')===output,'PowerShell JSON transport was truncated.');
  return JSON.parse(bytes.toString('utf16le'));
}
function registryString(powershell,registrationPath,field,run=execFileSync){
  assert.match(registrationPath,/^HKCU\\.+/);assert.equal(typeof field,'string');assert.ok(field.length>0);
  const body='$key=Get-Item -LiteralPath $env:GO_ERP_REGISTRY_PATH; '
    +'if($key.GetValueKind($env:GO_ERP_REGISTRY_FIELD) -ne [Microsoft.Win32.RegistryValueKind]::String){throw "Expected REG_SZ"}; '
    +'$properties=Get-ItemProperty -LiteralPath $env:GO_ERP_REGISTRY_PATH -Name $env:GO_ERP_REGISTRY_FIELD; '
    +'$result=$properties.PSObject.Properties[$env:GO_ERP_REGISTRY_FIELD].Value; '
    +'if($result -isnot [string]){throw "Expected a registry string"}';
  const value=powerShellJson(powershell,body,{GO_ERP_REGISTRY_PATH:'Registry::HKEY_CURRENT_USER\\'+registrationPath.slice(5),GO_ERP_REGISTRY_FIELD:field},run);
  assert.equal(typeof value,'string');return value;
}
module.exports={powerShellJson,registryString};
