'use strict';
// Restore the exact official Windows payload; the archive is verified before extraction.
const fs=require('node:fs/promises'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const pdf=require('../../desktop-pos/src/native-pdf.cjs');
async function prepare(destination){
  if(process.platform!=='win32')throw Error('The Windows PDF payload is prepared on Windows.');
  destination=path.resolve(destination);
  try{await fs.lstat(destination);throw Error('The PDF payload destination must be new.');}
  catch(error){if(error.code!=='ENOENT')throw error;}
  await fs.mkdir(path.dirname(destination),{recursive:true});
  const staging=await fs.mkdtemp(path.join(path.dirname(destination),'wkhtmltox-download-'));
  try{
    const response=await fetch(pdf.policy.archive.url,{signal:AbortSignal.timeout(180000)});
    if(!response.ok||!response.body)throw Error('The pinned official PDF archive is unavailable.');
    const digest=crypto.createHash('sha256'),archive=path.join(staging,'official.7z');let bytes=0;
    const file=await fs.open(archive,'wx');
    try{for await(const chunk of response.body){
      bytes+=chunk.length;if(bytes>pdf.policy.archive.bytes)throw Error('The official PDF archive exceeds its pinned size.');
      digest.update(chunk);await file.writeFile(chunk);
    }}finally{await file.close();}
    if(bytes!==pdf.policy.archive.bytes||digest.digest('hex')!==pdf.policy.archive.sha256)
      throw Error('The official Windows PDF archive digest differs.');
    const contents=path.join(staging,'contents');await fs.mkdir(contents);
    execFileSync(process.env.DESKTOP_7ZIP||'7z.exe',['x','-y','-o'+contents,archive],{stdio:'pipe',windowsHide:true,timeout:180000});
    if(JSON.stringify(await fs.readdir(contents))!==JSON.stringify(['wkhtmltox']))throw Error('The official PDF archive has unexpected roots.');
    await pdf.verifyExtracted(path.join(contents,'wkhtmltox'));
    await fs.rename(contents,destination);
    process.stdout.write(JSON.stringify({format:1,version:pdf.policy.version,archiveSha256:pdf.policy.archive.sha256,
      payload:path.join(destination,'wkhtmltox')})+'\n');
  }finally{await fs.rm(staging,{recursive:true,force:true});}
}
module.exports=prepare;
if(require.main===module){
  if(process.argv.length!==3)throw Error('Usage: node desktop-wkhtmltox.cjs new-destination');
  prepare(process.argv[2]).catch(error=>{console.error(error.message);process.exitCode=1;});
}
