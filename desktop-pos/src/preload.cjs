const {contextBridge,ipcRenderer}=require('electron');
const api={};
for(const name of ['state','new','discard','order','update','action','print','sync','pair','printers','printer','dashboard','backup'])api[name]=async(...args)=>{const r=await ipcRenderer.invoke('pos:'+name,...args);if(!r.ok)throw Error(r.error);return r.value;};
api.onState=callback=>{const fn=(_e,value)=>callback(value);ipcRenderer.on('pos:state',fn);return()=>ipcRenderer.removeListener('pos:state',fn);};
contextBridge.exposeInMainWorld('POS',api);
