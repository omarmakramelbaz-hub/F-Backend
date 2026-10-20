'use strict';
// PowerShell does not reliably wait for Windows GUI executables. The Node
// parent waits for the actual pinned Electron process and propagates its result.
const {spawnSync}=require('node:child_process'),path=require('node:path'),fs=require('node:fs');
if(process.platform!=='win32')throw Error('Native Electron acceptance requires actual Windows.');
const executable=path.resolve(__dirname,'../node_modules/electron/dist/electron.exe');
if(!fs.existsSync(executable)||process.argv.length<3)throw Error('The pinned Electron executable and native fixture are required.');
const child=spawnSync(executable,process.argv.slice(2),{stdio:'inherit',shell:false,windowsHide:true,timeout:180000});
if(child.error){process.stderr.write(child.error.stack+'\n');process.exit(1);}
process.exit(Number.isInteger(child.status)?child.status:1);
