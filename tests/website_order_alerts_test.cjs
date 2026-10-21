'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const storage = () => {
    const values = new Map();
    return {getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)};
};
const document = {
    currentScript:{dataset:{soundActor:'1',soundUrl:'/test.wav'}},
    getElementById:()=>null,querySelector:()=>null,
    addEventListener:()=>{},dispatchEvent:()=>{},
};
const window = {
    Audio:class {play(){return Promise.resolve();} pause(){}},
    addEventListener:()=>{},
};
const context = vm.createContext({document,window,localStorage:storage(),sessionStorage:storage(),
    CustomEvent:class {},setTimeout:()=>1,clearTimeout:()=>{},setInterval:()=>1,clearInterval:()=>{}});
vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/dashboard/js/dashboard-order-alerts.js'),'utf8'),context);
const alerts = window.DashboardOrderAlerts;
const pending = () => Array.from(alerts.state().pending).sort();
alerts.reconcileApp(['website:42','website:0','unknown:42','legacy:5'],alerts.checkpoint());
assert.deepEqual(pending(),['legacy:5','website:42'],'authoritative polling includes website orders and excludes invalid identities');
const started = alerts.checkpoint();
alerts.acknowledge('website:42');
alerts.reconcileApp(['website:42','legacy:5'],started);
assert.deepEqual(pending(),['legacy:5'],'a stale feed cannot restart sound after website acceptance');
alerts.reconcileApp(['website:43'],alerts.checkpoint());
assert.deepEqual(pending(),['website:43'],'a new website order alerts while the old native order clears');
alerts.reconcileApp([],alerts.checkpoint());
assert.deepEqual(pending(),[],'website sound clears when no website orders remain pending');
console.log('PASS website order polling, acknowledgements, stale responses and alert clearing');
