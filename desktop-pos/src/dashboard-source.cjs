'use strict';
const fs = require('node:fs/promises'), path = require('node:path'), crypto = require('node:crypto');
const roots = ['app','bootstrap','config','database','desktop','resources','routes','public/dashboard/js','public/dashboard/css','public/dashboard/vendor/desktop-external'];
const excluded = /(?:^|\/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$/i;
const digest = bytes => crypto.createHash('sha256').update(Buffer.from(bytes.toString('latin1').replace(/\r\n/g, '\n'), 'latin1')).digest('hex');
function valid(value) {
  return value?.format===1&&Number.isSafeInteger(value.files)&&value.files>0&&value.files<=20000
    &&/^[a-f0-9]{64}$/.test(value.sha256||'')&&typeof value.framework==='string'&&value.framework.length>0&&value.framework.length<=100
    &&Object.keys(value).every(key=>['format','files','sha256','framework'].includes(key));
}
async function fingerprint(application) {
  const files=new Map(),cases=new Set();let total=0;
  const add=async name=>{
    const file=path.join(application,...name.split('/')),stat=await fs.lstat(file);
    if(!stat.isFile()||stat.isSymbolicLink()||!/^[a-zA-Z0-9_.@/-]+$/.test(name)||cases.has(name.toLowerCase()))throw Error('مصدر البرنامج يحتوي مسارًا غير متوافق.');
    const bytes=await fs.readFile(file);total+=bytes.length;
    if(total>64*1024**2||files.size>=20000)throw Error('مصدر البرنامج تجاوز حد التحقق.');
    cases.add(name.toLowerCase());files.set(name,digest(bytes));
  };
  const walk=async relative=>{
    if(relative==='bootstrap/cache'||excluded.test(relative))return;
    const entries=await fs.readdir(path.join(application,...relative.split('/')),{withFileTypes:true});
    for(const entry of entries){
      const name=relative+'/'+entry.name;
      if(name.startsWith('bootstrap/cache/')||excluded.test(name))continue;
      if(entry.isSymbolicLink())throw Error('مصدر البرنامج يحتوي رابطًا غير متوافق.');
      if(entry.isDirectory())await walk(name);
      else if(/\.(?:php|js|css|json|html|scss|vue)$/i.test(name))await add(name);
    }
  };
  for(const root of roots){
    let stat;try{stat=await fs.lstat(path.join(application,...root.split('/')));}catch(error){if(error.code==='ENOENT')continue;throw error;}
    if(!stat.isDirectory()||stat.isSymbolicLink())throw Error('مصدر البرنامج يحتاج التحقق قبل تجهيز الجهاز.');
    await walk(root);
  }
  try{await fs.access(path.join(application,'artisan'));await add('artisan');}catch(error){if(error.code!=='ENOENT')throw error;}
  const lock=JSON.parse(await fs.readFile(path.join(application,'composer.lock'),'utf8'));
  const framework=(lock.packages||[]).find(item=>item.name==='laravel/framework')?.version?.replace(/^v/,'');
  const hash=crypto.createHash('sha256').update('desktop-dashboard-source:1\n');
  for(const name of [...files.keys()].sort((a,b)=>Buffer.compare(Buffer.from(a),Buffer.from(b))))hash.update(name+'\0'+files.get(name)+'\n');
  const value={format:1,files:files.size,sha256:hash.digest('hex'),framework};
  if(!valid(value))throw Error('نسخة إطار البرنامج غير معروفة.');return value;
}
function same(left,right) {
  return valid(left)&&valid(right)&&left.files===right.files&&left.sha256===right.sha256&&left.framework===right.framework;
}
module.exports={fingerprint,valid,same};
