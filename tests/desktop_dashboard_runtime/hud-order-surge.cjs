const {readFileSync}=require('node:fs');
const vm=require('node:vm'); const assert=require('node:assert/strict');
const source=readFileSync(process.argv[2],'utf8');
const handler=source.slice(source.indexOf('    var surgeUntil'),source.indexOf('    var BEAT'));
const context={Set,Array,performance:{now:()=>1000},reduce:false,leaving:false};vm.createContext(context);vm.runInContext(handler,context);
function event(keys){context.keys=keys;vm.runInContext('onOrderAlert({detail:{pending:keys}})',context);}
function until(){return vm.runInContext('surgeUntil',context);}
event(['store:12']);assert.equal(until(),13000);
vm.runInContext('surgeUntil=0',context);event(['store:12']);assert.equal(until(),0);
event(['phone:12','bad']);assert.equal(until(),0);
event(['legacy:13']);assert.equal(until(),13000);
vm.runInContext('surgeUntil=0;reduce=true',context);event(['store:14']);assert.equal(until(),0);
console.log('Solar order surge: new app arrivals, repeat polling, invalid keys and reduced motion passed');
