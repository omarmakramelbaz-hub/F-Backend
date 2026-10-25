'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');

// Only fixed native stages and allowlisted navigation classifications enter
// this file. Never accept an error message, URL, argument or account value.
const phases=new Set([
  'startup-ready','ledger-open','splash-loading','splash-loaded','prepared-check',
  'runtime-start','runtime-archive','runtime-source','runtime-database','runtime-media','runtime-web','runtime-ready','remote-state','central-selection','dashboard-create',
  'recovery-check','generation-recovery','local-fence-recovery','central-fence-recovery',
  'dashboard-navigation','dashboard-visible','dashboard-response','dashboard-interactive','dashboard-loaded','dashboard-user-navigation',
  'dashboard-navigation-failed','dashboard-navigation-timeout','dashboard-open-settled','startup-complete','startup-failed',
  'shutdown-requested','shutdown-wait-accepted','shutdown-accepted-settled',
  'shutdown-drain-updater','shutdown-stop-runtime','shutdown-runtime-stopped',
  'shutdown-close-ledger','shutdown-ledger-failed','shutdown-complete'
]);
const navigationPhases=new Set(['dashboard-navigation','dashboard-response','dashboard-interactive','dashboard-loaded','dashboard-user-navigation',
  'dashboard-navigation-failed','dashboard-navigation-timeout']);
const modes=new Set(['local','server']),paths=new Set(['/admin/dashboard','/admin/login','/admin/signin','/admin/other']);

class LifecycleTrace{
  constructor(profile,{now=Date.now,pid=process.pid}={}){
    this.file=path.join(profile,'lifecycle-trace.json');this.now=now;this.started=now();
    this.pid=Number.isSafeInteger(pid)&&pid>0?pid:0;this.events=[];
  }
  mark(phase,details={}){
    if(!phases.has(phase))return false;
    const classification={};
    if(navigationPhases.has(phase)&&details&&typeof details==='object'){
      if(modes.has(details.mode))classification.mode=details.mode;
      if(paths.has(details.path))classification.path=details.path;
      if(Number.isInteger(details.status)&&details.status>=100&&details.status<=599)classification.status=details.status;
    }
    const previous=this.events.at(-1);
    if(previous?.phase===phase&&['mode','path','status'].every(key=>previous[key]===classification[key]))return true;
    const timestamp=this.now(),event={phase,at:new Date(timestamp).toISOString(),elapsed_ms:Math.max(0,timestamp-this.started),...classification};
    this.events.push(event);if(this.events.length>64)this.events.shift();
    // Replace one bounded file atomically. A failed diagnostic write never
    // delays shutdown with a retry, starts a timer, or changes business data.
    const temporary=this.file+'.'+crypto.randomBytes(8).toString('hex')+'.tmp';
    try{
      fs.writeFileSync(temporary,JSON.stringify({format:1,pid:this.pid,started_at:new Date(this.started).toISOString(),events:this.events})+'\n',{flag:'wx',mode:0o600});
      fs.renameSync(temporary,this.file);return true;
    }catch{
      try{fs.unlinkSync(temporary);}catch{}
      return false;
    }
  }
}
module.exports=LifecycleTrace;
