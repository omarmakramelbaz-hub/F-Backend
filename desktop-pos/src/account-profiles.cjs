'use strict';
const fs=require('node:fs/promises'),path=require('node:path'),crypto=require('node:crypto');
const {GenerationStore}=require('./dashboard-generation.cjs');

const validId=id=>id==='default'||typeof id==='string'&&/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/.test(id);
const exact=(value,keys)=>value&&typeof value==='object'&&!Array.isArray(value)
  &&Object.keys(value).sort().join(',')===keys.slice().sort().join(',');
const invalid=()=>Error('تعذر التحقق من ملفات الحسابات؛ بيانات الحساب الحالي محفوظة.');
const label=value=>typeof value==='string'&&value===value.trim()&&value.length>0&&value.length<=120&&!/[\x00-\x1f\x7f]/.test(value);
function binding(value){
  if(!value||!Number.isSafeInteger(value.actorId)||value.actorId<1||typeof value.serverOrigin!=='string')throw invalid();
  let origin;try{origin=new URL(value.serverOrigin);}catch{throw invalid();}
  if(origin.protocol!=='https:'||origin.username||origin.password||origin.origin!==value.serverOrigin)throw invalid();
  return {actorId:value.actorId,serverOrigin:value.serverOrigin};
}
const initial=()=>({format:1,selected:'default',profiles:[{id:'default',label:'الحساب الحالي',actorId:null,serverOrigin:null}]});
const normalized=value=>process.platform==='win32'?path.normalize(value).toLowerCase():path.normalize(value);
const storedLogin=value=>typeof value==='string'&&value.length>0&&value.length<=320
  &&value===value.trim().toLowerCase()&&!/[\x00-\x1f\x7f]/.test(value);
const loginName=value=>{
  if(typeof value!=='string'||!value.trim()||value.length>320||/[\x00-\x1f\x7f]/.test(value))throw invalid();
  let name=value.trim().toLowerCase();if(name.startsWith('0'))name=name.slice(1);
  if(!name)throw invalid();return name;
};

/** Account selection is metadata only. No runtime, cookie, credential or journal is copied or deleted. */
class AccountProfiles{
  constructor({root,safeStorage}){
    if(typeof root!=='string'||!path.isAbsolute(root)||path.resolve(root)!==root||root===path.parse(root).root)throw invalid();
    this.root=root;this.metadata=new GenerationStore(root,safeStorage);this.tail=Promise.resolve();
  }
  serialize(work){const task=this.tail.then(work);this.tail=task.catch(()=>{});return task;}
  async directory(directory,create=false){
    if(directory!==this.root&&!directory.startsWith(this.root+path.sep))throw invalid();
    let cursor=path.parse(directory).root;
    for(const part of directory.slice(cursor.length).split(path.sep).filter(Boolean)){
      cursor=path.join(cursor,part);
      let stat;try{stat=await fs.lstat(cursor);}catch(error){
        if(error.code!=='ENOENT'||!create||cursor===this.root||!cursor.startsWith(this.root+path.sep))throw invalid();
        try{await fs.mkdir(cursor,{mode:0o700});}catch(error){if(error.code!=='EEXIST')throw error;}
        stat=await fs.lstat(cursor);
      }
      if(!stat.isDirectory()||stat.isSymbolicLink())throw invalid();
    }
    if(normalized(await fs.realpath(directory))!==normalized(directory))throw invalid();
    return directory;
  }
  async profile(id,create=false){
    if(!validId(id))throw invalid();
    return this.directory(id==='default'?this.root:path.join(this.root,'accounts',id),create);
  }
  async indexFile(){
    await this.directory(this.root);
    try{
      const stat=await fs.lstat(path.join(this.root,'account-profiles.enc'));
      if(!stat.isFile()||stat.isSymbolicLink()||stat.size<1||stat.size>65536)throw invalid();
      return stat;
    }catch(error){if(error.code==='ENOENT')return null;throw error;}
  }
  validate(value){
    if(!exact(value,['format','selected','profiles'])||value.format!==1||!validId(value.selected)
      ||!Array.isArray(value.profiles)||value.profiles.length<1||value.profiles.length>64)throw invalid();
    const ids=new Set();
    for(const row of value.profiles){
      if(!exact(row,['id','label','actorId','serverOrigin'])||!validId(row.id)||ids.has(row.id)||!label(row.label))throw invalid();
      ids.add(row.id);
      if(row.actorId!==null||row.serverOrigin!==null)binding(row);
    }
    if(!ids.has('default')||!ids.has(value.selected))throw invalid();
    return value;
  }
  async read(){
    const before=await this.indexFile();if(!before)return initial();
    let value;try{value=this.validate(await this.metadata.read('account-profiles'));}catch{throw invalid();}
    const after=await this.indexFile();
    if(!after||before.dev!==after.dev||before.ino!==after.ino||before.size!==after.size||before.mtimeMs!==after.mtimeMs)throw invalid();
    for(const row of value.profiles)await this.profile(row.id);
    return value;
  }
  async commit(previous,next){
    this.validate(next);await this.indexFile();
    try{await this.metadata.write('account-profiles',next);await this.indexFile();}
    catch(error){
      // A metadata write can report a directory-flush failure after rename.
      // Restore the previous selection if those exact new bytes committed.
      try{
        const current=await this.read();
        if(JSON.stringify(current)===JSON.stringify(next))await this.metadata.write('account-profiles',previous);
        else if(JSON.stringify(current)!==JSON.stringify(previous))throw invalid();
      }catch(rollback){throw new AggregateError([error,rollback],'تعذر تأكيد اختيار الحساب؛ أعد فتح البرنامج دون تغيير ملفاته.');}
      throw error;
    }
  }
  async descriptor(row){
    return {id:row.id,label:row.label,profile:await this.profile(row.id),partition:'persist:fasakhansta-dashboard'+(row.id==='default'?'':'-'+row.id)};
  }
  selected(){return this.serialize(async()=>{const value=await this.read();return this.descriptor(value.profiles.find(row=>row.id===value.selected));});}
  account(id){return this.serialize(async()=>{const value=await this.read(),row=value.profiles.find(item=>item.id===id);if(!row)throw invalid();return {...await this.descriptor(row),actorId:row.actorId,serverOrigin:row.serverOrigin};});}
  forActor(actorId,serverOrigin){return this.serialize(async()=>{
    const wanted=binding({actorId,serverOrigin}),value=await this.read();
    const rows=value.profiles.filter(row=>row.actorId===wanted.actorId&&row.serverOrigin===wanted.serverOrigin);
    if(rows.length>1)throw Error('يوجد أكثر من ملف للحساب نفسه؛ بياناته محفوظة وتحتاج مراجعة.');
    return rows.length?{...await this.descriptor(rows[0]),actorId:rows[0].actorId,serverOrigin:rows[0].serverOrigin}:null;
  });}
  async loginIndex(value){
    const file=path.join(this.root,'account-logins.enc');
    let before;
    try{before=await fs.lstat(file);if(!before.isFile()||before.isSymbolicLink()||before.size<1||before.size>65536)throw invalid();}
    catch(error){if(error.code==='ENOENT')return {format:1,entries:[]};throw error;}
    const record=await this.metadata.read('account-logins');
    if(!exact(record,['format','entries'])||record.format!==1||!Array.isArray(record.entries)||record.entries.length>256)throw invalid();
    const seen=new Set();
    for(const item of record.entries){
      // The original controller strips one zero from submitted identifiers.
      // Stored aliases already underwent that transform; applying it again
      // corrupts legitimate identifiers beginning with two or more zeroes.
      if(!exact(item,['login','id','actorId','serverOrigin'])||!storedLogin(item.login)||!validId(item.id))throw invalid();
      const trusted=binding(item),row=value.profiles.find(profile=>profile.id===item.id);
      if(!row||row.actorId!==trusted.actorId||row.serverOrigin!==trusted.serverOrigin)throw invalid();
      const key=JSON.stringify([item.serverOrigin,item.login]);if(seen.has(key))throw invalid();seen.add(key);
    }
    const after=await fs.lstat(file);
    if(!after.isFile()||after.isSymbolicLink()||before.dev!==after.dev||before.ino!==after.ino||before.size!==after.size||before.mtimeMs!==after.mtimeMs)throw invalid();
    return record;
  }
  findLogin(input,serverOrigin){return this.serialize(async()=>{
    const name=loginName(input);binding({actorId:1,serverOrigin});const value=await this.read(),index=await this.loginIndex(value);
    const item=index.entries.find(row=>row.login===name&&row.serverOrigin===serverOrigin);if(!item)return null;
    const row=value.profiles.find(profile=>profile.id===item.id);return {...await this.descriptor(row),actorId:row.actorId,serverOrigin:row.serverOrigin};
  });}
  rememberLogin(id,credential,input){return this.serialize(async()=>{
    const trusted=binding(credential),name=loginName(input),value=await this.read(),row=value.profiles.find(item=>item.id===id);
    if(!row||row.actorId!==trusted.actorId||row.serverOrigin!==trusted.serverOrigin)throw invalid();
    const previous=await this.loginIndex(value),existing=previous.entries.find(item=>item.login===name&&item.serverOrigin===trusted.serverOrigin);
    if(existing&&(existing.id!==id||existing.actorId!==trusted.actorId))throw Error('اسم الدخول مرتبط بملف حساب آخر؛ بيانات الحسابين محفوظة.');
    if(!existing){
      const next={format:1,entries:[...previous.entries,{login:name,id,...trusted}]};
      if(previous.entries.length>=256||Buffer.byteLength(JSON.stringify(next),'utf8')>48*1024)throw Error('وصل عدد أسماء الحسابات المحفوظة إلى الحد المسموح.');
      await this.metadata.write('account-logins',next);
    }
    return true;
  });}
  list(){return this.serialize(async()=>{const value=await this.read();return value.profiles.map(row=>({id:row.id,label:row.label,selected:row.id===value.selected}));});}
  create(){return this.serialize(async()=>{
    const previous=await this.read();if(previous.profiles.length>=64)throw Error('وصل عدد الحسابات المحفوظة إلى الحد المسموح.');
    const id=crypto.randomUUID();if(previous.profiles.some(row=>row.id===id))throw invalid();
    await this.directory(path.join(this.root,'accounts'),true);
    // Never adopt an existing unregistered directory, including a remnant from
    // an interrupted create. It may contain another account's retained data.
    await fs.mkdir(path.join(this.root,'accounts',id),{mode:0o700});await this.profile(id);
    const row={id,label:'حساب جديد',actorId:null,serverOrigin:null},next={...previous,profiles:[...previous.profiles,row]};
    await this.commit(previous,next);return this.descriptor(row);
  });}
  select(id){return this.serialize(async()=>{
    if(!validId(id))throw invalid();const previous=await this.read(),row=previous.profiles.find(row=>row.id===id);if(!row)throw invalid();
    await this.profile(id);if(previous.selected!==id)await this.commit(previous,{...previous,selected:id});
    return this.descriptor(row);
  });}
  bind(id,credential){return this.serialize(()=>this.bindRecord(id,credential));}
  assertBinding(id,credential){return this.serialize(()=>this.bindRecord(id,credential));}
  async bindRecord(id,credential){
    if(!validId(id))throw invalid();const trusted=binding(credential),previous=await this.read(),row=previous.profiles.find(row=>row.id===id);if(!row)throw invalid();
    if(row.actorId!==null&&(row.actorId!==trusted.actorId||row.serverOrigin!==trusted.serverOrigin))throw Error('الحساب لا يطابق ملف بيانات الجهاز المختار؛ اختر ملفه الصحيح.');
    if(credential.label!==undefined&&!label(credential.label))throw invalid();
    const updated={...row,...trusted,...(credential.label===undefined?{}:{label:credential.label})};
    if(JSON.stringify(updated)!==JSON.stringify(row))await this.commit(previous,{...previous,profiles:previous.profiles.map(item=>item.id===id?updated:item)});
    return this.descriptor(updated);
  }
}
module.exports=AccountProfiles;
