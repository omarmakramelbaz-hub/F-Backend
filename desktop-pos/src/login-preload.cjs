'use strict';
const {contextBridge,ipcRenderer}=require('electron');
const argument=process.argv.find(value=>value.startsWith('--go-erp-login-token='));
const token=argument?.slice('--go-erp-login-token='.length);
if(!/^[a-f0-9]{64}$/.test(token||''))throw Error('Login surface unavailable.');
contextBridge.exposeInMainWorld('GOERPLogin',Object.freeze({
  authenticate:input=>ipcRenderer.invoke('go-erp-login:'+token,input),
  onState:callback=>{if(typeof callback!=='function')return;ipcRenderer.on('go-erp-login-state',(_event,value)=>callback(value));}
}));
