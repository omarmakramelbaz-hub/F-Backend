'use strict';
// Temporary CI build-tool deadline for the complete preview; no runtime/test gate is changed.
const fs=require('node:fs/promises'),path=require('node:path'),crypto=require('node:crypto');
(async()=>{
  const project=path.resolve(process.argv[2]||'desktop-pos');
  const pinned=JSON.parse(await fs.readFile(path.join(project,'package-lock.json'),'utf8')).packages['node_modules/builder-util'].version;
  const file=require.resolve('builder-util/out/util.js',{paths:[project]});
  const metadata=JSON.parse(await fs.readFile(path.resolve(path.dirname(file),'../package.json'),'utf8'));
  if(pinned!=='26.15.3'||metadata.version!==pinned)throw Error('Unreviewed installer build utility.');
  const original=await fs.readFile(file,'utf8'),marker='function spawnAndWriteWithOutput(';
  if(original.split(marker).length!==2)throw Error('Unexpected compiler process wrapper.');
  const start=original.indexOf(marker),end=original.indexOf('\n}',start)+2;
  if(end<=start)throw Error('Compiler process boundary is missing.');
  const body=original.slice(start,end),timer='4 * 60 * 1000',message='timed out after 4 minutes';
  if(body.split(timer).length!==2||body.split(message).length!==2)throw Error('The pinned compiler deadline changed.');
  const changed=body.replace(timer,'12 * 60 * 1000').replace(message,'timed out after 12 minutes');
  const adjusted=original.slice(0,start)+changed+original.slice(end);
  await fs.writeFile(file,adjusted);
  const sha=x=>crypto.createHash('sha256').update(x).digest('hex');
  process.stdout.write(JSON.stringify({buildUtility:pinned,originalSha256:sha(original),adjustedSha256:sha(adjusted),compileDeadlineSeconds:720})+'\n');
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;});
