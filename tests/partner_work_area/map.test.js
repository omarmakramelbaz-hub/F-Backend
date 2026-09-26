'use strict';
const assert = require('node:assert/strict');
const api = require('../../public/dashboard/js/partner-work-area.js');
function element(value = '') {
    return {value, hidden:true, textContent:'', listeners:{},
        addEventListener(n,f) {this.listeners[n]=f;}, setCustomValidity(v) {this.validity=v;},
        focus() {this.focused=true;}};
}
function fixture(lat='', lng='', radius='') {
    const ids={};
    for (const name of ['lat','lng','radius','summary','validation','map']) ids['partner-work-'+name]=element();
    ids['partner-work-lat'].value=lat;ids['partner-work-lng'].value=lng;ids['partner-work-radius'].value=radius;
    const form=element(), el={ownerDocument:{getElementById:n=>ids[n]}, closest:()=>form};
    const state={markers:[], circles:[], fit:0};
    class Map {constructor(canvas,options){this.options=options;this.listeners={};state.map=this;}
        addListener(n,f){this.listeners[n]=f;} fitBounds(){state.fit++;}}
    class Marker {constructor(options){this.options=options;this.listeners={};state.markers.push(this);}
        addListener(n,f){this.listeners[n]=f;} setPosition(p){this.position=p;}}
    class Circle {constructor(options){this.options=options;state.circles.push(this);}
        setRadius(r){this.radius=r;} setCenter(p){this.center=p;}getBounds(){return {};}
        setMap(m){this.removed=m===null;}}
    api.init(el,{Map, Marker, Circle});
    return {state, ids, el, form, maps:{Map, Marker, Circle},
        click:(x,y)=>state.map.listeners.click({latLng:{lat:()=>x,lng:()=>y}}),
        input:v=>{ids['partner-work-radius'].value=v;ids['partner-work-radius'].listeners.input();}};
}
assert.equal(api.radius('١٠'),10);assert.equal(api.radius('۷'),7);
for(const x of ['',0,-1,256,'1.5','1e1','10km','NaN']) assert.equal(api.radius(x),null);
assert.equal(api.point('', ''),null);assert.equal(api.point(91,1),null);
assert.deepEqual(api.point(0,0),{lat:0,lng:0});
let f=fixture();assert.equal(f.state.markers.length,0);assert.equal(f.state.circles.length,0);
assert.equal(f.ids['partner-work-lat'].value,'');
f.input('١٠');assert.equal(f.state.circles.length,0); // number never chooses a default pin
let prevented=false;f.form.listeners.submit({preventDefault(){prevented=true;}});
assert.equal(prevented,true);assert.equal(f.ids['partner-work-validation'].hidden,false);
f.click(31.04,31.38);assert.equal(f.state.markers.length,1);
assert.equal(f.state.circles.at(-1).radius,10000);
assert.equal(f.ids['partner-work-lat'].value,'31.0400000');
f.input('7');assert.equal(f.state.circles.at(-1).radius,7000);assert.ok(f.state.fit>0);
f.state.markers[0].listeners.drag({latLng:{lat:()=>30,lng:()=>31}});
assert.deepEqual(f.state.circles.at(-1).center,{lat:30,lng:31});
assert.equal(f.ids['partner-work-lng'].value,'31.0000000');
f.input('');assert.equal(f.state.circles.at(-1).removed,true);
f.input('255');assert.equal(f.state.circles.at(-1).radius,255000);
f.input('0');assert.equal(f.state.circles.at(-1).removed,true);
f=fixture('31.0400000','31.3800000','15');assert.equal(f.state.circles[0].radius,15000);
api.init(f.el,f.maps);assert.equal(f.state.markers.length,1); // idempotent loader
f.input('20');prevented=false;f.form.listeners.submit({preventDefault(){prevented=true;}});assert.equal(prevented,false);
console.log('PASS: map selection, live km/metre radius, drag, edit restore, invalid values and submit protection');
