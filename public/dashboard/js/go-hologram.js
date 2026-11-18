/* GO hologram v7: portrait particle assembly and a living textured facial surface. */
(function (window, document) {
    'use strict';
    if (window.GoHologram && window.GoHologram.version >= 7) return;

    var items = [], frame = 0, queued = false, TAU = Math.PI * 2;
    var ASSEMBLY_DURATION = 5.6, EMITTER = [0, -.32, .64];
    var media = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function ease(t) { t = clamp(t, 0, 1); return t * t * (3 - 2 * t); }
    function mix(a, b, t) { return a + (b - a) * t; }
    function active(item) { return !item.dead && !item.lost && item.visible && item.canvas.isConnected && !document.hidden && item.width > 1 && item.height > 1; }
    function moving(item) { return active(item) && !item.reduced; }
    function wake() {
        if (frame || queued || !items.some(moving)) return;
        queued = true;
        // The shared shell loop must be scheduled after DashboardSPA's PageScope exits.
        Promise.resolve().then(function () {
            queued = false;
            if (!frame && items.some(moving)) frame = window.requestAnimationFrame(tick);
        });
    }
    function tick(now) {
        frame = 0;
        items.slice().forEach(function (item) {
            if (!item.canvas.isConnected) { item.destroy(); return; }
            if (!moving(item)) { item.last = 0; return; }
            if (!item.last || now - item.last >= item.interval - 1) {
                // Keep the living energy tied to visible wall time even on a slow GPU.
                if (item.last) { item.adaptDensity(now - item.last); item.elapsed += Math.max(0, (now - item.last) / 1000); }
                item.last = now;
                item.render(item.elapsed);
            }
        });
        wake();
    }
    function visibility() {
        items.forEach(function (item) {
            item.last = 0;
            if (active(item)) item.render(item.reduced ? 6 : item.elapsed);
        });
        if (document.hidden && frame) { window.cancelAnimationFrame(frame); frame = 0; }
        wake();
    }
    document.addEventListener('visibilitychange', visibility);
    function randomGenerator(seed) { return function () { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; }; }

    // All meshes are constructed here from smooth mathematical surfaces. UVs describe
    // the physical surface; no raster assets are involved in construction or shading.
    function controlCurve(points) {
        var slopes = [], tangents = [];
        for (var i = 0; i < points.length - 1; i++) slopes[i] = (points[i + 1][1] - points[i][1]) / (points[i + 1][0] - points[i][0]);
        tangents[0] = slopes[0]; tangents[points.length - 1] = slopes[slopes.length - 1];
        for (i = 1; i < points.length - 1; i++) {
            if (slopes[i - 1] * slopes[i] <= 0) tangents[i] = 0;
            else { var h0 = points[i][0] - points[i - 1][0], h1 = points[i + 1][0] - points[i][0], w0 = 2 * h1 + h0, w1 = h1 + 2 * h0; tangents[i] = (w0 + w1) / (w0 / slopes[i - 1] + w1 / slopes[i]); }
        }
        return function (value) {
            value = clamp(value, 0, 1);
            for (var j = 0; j < points.length - 1; j++) if (value <= points[j + 1][0]) {
                var h = points[j + 1][0] - points[j][0], t = (value - points[j][0]) / h, t2 = t * t, t3 = t2 * t;
                return (2 * t3 - 3 * t2 + 1) * points[j][1] + (t3 - 2 * t2 + t) * h * tangents[j] + (-2 * t3 + 3 * t2) * points[j + 1][1] + (t3 - t2) * h * tangents[j + 1];
            }
            return points[points.length - 1][1];
        };
    }
    // Anatomical control curves: broad forehead, cheekbone break, defined jaw and chin.
    // Tangents are shared across knots, avoiding the scalloping of zero-slope segments.
    var headWidth = controlCurve([[0, 0], [.02, .17], [.065, .34], [.13, .48], [.24, .55], [.42, .565], [.56, .575], [.66, .535], [.76, .44], [.86, .34], [.94, .22], [.98, .12], [1, 0]]);
    var headDepth = controlCurve([[0, 0], [.06, .22], [.18, .37], [.45, .425], [.65, .38], [.80, .28], [.94, .14], [.98, .075], [1, 0]]);
    var shoulderWidth = controlCurve([[0, 0], [.085, .22], [.18, .285], [.33, .29], [.40, .34], [.45, .43], [.50, .68], [.55, 1.02], [.61, 1.25], [.71, 1.43], [.86, 1.51], [1, 1.55]]);
    function headPoint(v, phi) {
        var front = Math.max(0, Math.cos(phi));
        var x = headWidth(v) * .88 * Math.sin(phi), y = 1.80 - v * 1.35;
        var z = headDepth(v) * Math.cos(phi) + .14 * ease((v - .82) / .18);
        z += Math.pow(front, 8) * (.045 * Math.exp(-Math.pow((y - 1.03) / .14, 2)) + .040 * Math.exp(-Math.pow((y - .60) / .09, 2)));
        return [x, y, z];
    }
    function bodyPoint(v, phi) {
        var shoulder = ease((v - .38) / .38), taper = ease((v - .35) / .35);
        var radius = shoulderWidth(v) * (1 - .20 * taper);
        var depth = (.14 + .29 * ease((v - .30) / .64)) * ease(v / .10), c = Math.cos(phi);
        return [radius * Math.sin(phi), .90 - v * 1.40 - .14 * ease((v - .48) / .36) + .045 * c * shoulder, depth * c - .05 * (1 - shoulder)];
    }
    function earPoint(side) {
        return function (v, phi) {
            var theta = v * Math.PI, s = 1 - Math.abs(v * 2 - 1);
            return [side * (.474 + .042 * s), .945 + .083 * Math.cos(theta), -.025 + .025 * s * Math.cos(phi)];
        };
    }
    function buildMesh(compact) {
        var vertices = [], indices = [];
        function add(fn, part, rows, cols) {
            var offset = vertices.length / 9, epsilon = .00015;
            for (var row = 0; row <= rows; row++) {
                var v = row / rows;
                for (var col = 0; col <= cols; col++) {
                    var phi = col / cols * TAU, p = fn(v, phi);
                    var a = fn(clamp(v - epsilon, 0, 1), phi), b = fn(clamp(v + epsilon, 0, 1), phi);
                    var c = fn(v, phi - epsilon), d = fn(v, phi + epsilon);
                    var ax = b[0] - a[0], ay = b[1] - a[1], az = b[2] - a[2];
                    var bx = d[0] - c[0], by = d[1] - c[1], bz = d[2] - c[2];
                    var nx = ay * bz - az * by, ny = az * bx - ax * bz, nz = ax * by - ay * bx;
                    var length = Math.sqrt(nx * nx + ny * ny + nz * nz);
                    if (length < 1e-10) { nx = 0; ny = row === 0 ? 1 : -1; nz = 0; length = 1; }
                    vertices.push(p[0], p[1], p[2], nx / length, ny / length, nz / length, col / cols, v, part);
                }
            }
            for (var r = 0; r < rows; r++) for (var k = 0; k < cols; k++) {
                var i = offset + r * (cols + 1) + k;
                indices.push(i, i + cols + 1, i + 1, i + 1, i + cols + 1, i + cols + 2);
            }
        }
        add(headPoint, 1, compact ? 56 : 96, compact ? 80 : 144);
        add(bodyPoint, 0, compact ? 60 : 112, compact ? 80 : 144);
        add(earPoint(-1), 2, compact ? 20 : 32, compact ? 32 : 48);
        add(earPoint(1), 2, compact ? 20 : 32, compact ? 32 : 48);
        return {vertices: new Float32Array(vertices), indices: new Uint16Array(indices), vertexCount: vertices.length / 9};
    }
    function buildParticles(compact) {
        var random = randomGenerator(58219), count = compact ? 1100 : 14000, result = [];
        for (var i = 0; i < count; i++) {
            var head = i % 5 < 2, v = .025 + random() * .95, phi = random() * TAU;
            var fn = head ? headPoint : bodyPoint, p = fn(v, phi);
            var radial = head ? .013 : .018;
            var outward = .008 + random() * .032;
            if (head) { p[0] += p[0] / .575 * outward; p[1] += (p[1] - 1.125) / .675 * outward; p[2] += p[2] / .425 * outward; }
            else { p[0] += p[0] / 1.545 * outward; p[2] += (p[2] < 0 ? -1 : 1) * outward; }
            var dx = (random() - .5) * radial, dy = (random() - .5) * radial, dz = (random() - .5) * radial;
            result.push(p[0] + dx, p[1] + dy, p[2] + dz, 0, 0, 1, phi / TAU, v, head ? 1 : 0, random(), .55 + random() * 1.05);
        }
        result.push(EMITTER[0], EMITTER[1], EMITTER[2], 0, 0, 1, 0, 0, 3, 0, 18);
        return {vertices: new Float32Array(result), count: count + 1};
    }

    function arrivalFor(p, part) {
        if (part > .5) return 2.75 + ease((p[0] + .51) / 1.02) * 1.20 + ease((p[2] - .02) / .30) * .65 + clamp((p[1] - .45) / 1.35, 0, 1) * .15;
        var height = clamp((p[1] + .32) / 1.22, 0, 1), side = ease((p[0] + .14) / .34);
        var left = 1.50 + height * 1.25 + Math.min(1, Math.abs(p[0]) / 1.55) * .15;
        var right = 4.35 + height * .55 + Math.abs(p[0]) * .18;
        var central = 1 - ease((Math.abs(p[0]) - .06) / .14);
        return mix(mix(left, right, side), .95 + height * 2.20, central * .90);
    }
    var ARRIVAL_SHADER = [
        'float assemblyArrival(vec3 p,float part){',
        ' if(part>.5)return 2.75+smoothstep(-.51,.51,p.x)*1.20+smoothstep(.02,.32,p.z)*.65+clamp((p.y-.45)/1.35,0.,1.)*.15;',
        ' float height=clamp((p.y+.32)/1.22,0.,1.),side=smoothstep(-.14,.20,p.x);',
        ' float left=1.50+height*1.25+min(1.,abs(p.x)/1.55)*.15;',
        ' float right=4.35+height*.55+abs(p.x)*.18;',
        ' float central=1.-smoothstep(.06,.20,abs(p.x));',
        ' return mix(mix(left,right,side),.95+height*2.20,central*.90);',
        '}',
        'vec3 fountain(vec3 target,float part,float seed,float q){',
        ' vec3 emitter=vec3(0.,-.32,.64);',
        ' vec3 c1=emitter+vec3(.72+sin(seed*51.7)*.08,.18+cos(seed*67.3)*.07,sin(seed*31.1)*.08);',
        ' vec3 c2=part>.5?vec3(-1.75+cos(seed*23.4)*.10,1.98+sin(seed*42.2)*.12,-.08+cos(seed*17.4)*.09):vec3(target.x*1.25-.12,target.y+.68,target.z+sin(seed*27.1)*.12);',
        ' float u=1.-q;return emitter*u*u*u+3.*c1*u*u*q+3.*c2*u*q*q+target*q*q*q;',
        '}'
    ].join('\n');
    var VERTEX = [
        'precision highp float;',
        'attribute vec3 aPosition; attribute vec3 aNormal; attribute vec2 aUV; attribute float aPart; attribute float aPhase; attribute float aSize;',
        'uniform mat4 uProjection; uniform mat4 uView; uniform float uHeadYaw; uniform float uBodyYaw; uniform float uPitch;',
        'uniform float uTime; uniform float uVoice; uniform float uPointPass; uniform float uExpand; uniform float uDpr; uniform float uCompact; uniform float uAspect; uniform float uScatter; uniform float uReduced;',
        'varying vec3 vNormal; varying vec3 vPosition; varying vec3 vLocal; varying vec2 vUV; varying float vPart; varying float vAlpha; varying float vPhase;',
        ARRIVAL_SHADER,
        'mat3 yaw(float a){float c=cos(a),s=sin(a);return mat3(c,0.,-s,0.,1.,0.,s,0.,c);}',
        'mat3 pitch(float a){float c=cos(a),s=sin(a);return mat3(1.,0.,0.,0.,c,s,0.,-s,c);}',
        'void main(){',
        ' vec3 p=aPosition,n=aNormal; vLocal=aPosition; vUV=aUV; vPart=aPart; vPhase=aPhase;',
        ' float head=step(.5,aPart)*(1.-step(2.5,aPart));',
        // Real lower-face vertex displacement: jaw opening and flowing lips/waves.
        ' float mouth=exp(-pow((p.y-.85)/.135,2.))*exp(-pow(p.x/.27,4.))*smoothstep(.10,.31,p.z)*head;',
        ' float jaw=(1.-smoothstep(.69,.98,p.y))*smoothstep(-.08,.20,p.z)*head;',
        ' p.y-=uVoice*.095*jaw;',
        ' p.y+=mouth*sin(p.x*27.-uTime*7.)*uVoice*.024;',
        ' p.z+=mouth*(uVoice*.045+sin(p.x*24.-uTime*5.)*uVoice*.018);',
        ' p+=n*uExpand;',
        ' float neck=smoothstep(.08,.65,p.y)*(1.-head); float angle=mix(uBodyYaw,uHeadYaw,head+neck*.36);',
        ' vec3 pivot=mix(vec3(0.,.10,0.),vec3(0.,.53,-.035),head);',
        ' mat3 rotation=yaw(angle)*pitch(uPitch*mix(.12,1.,head));',
        ' p=rotation*(p-pivot)+pivot; n=rotation*n;',
        ' p.y+=sin(uTime*1.12)*.004*(1.-uReduced);',
        ' vAlpha=1.;',
        ' if(uPointPass>.5){',
        '   if(aPart>2.5){',
        '     p=vec3(0.,-.32,.64);',
        '     vAlpha=smoothstep(.035,.20,uTime)*((1.-smoothstep(5.6,7.2,uTime))*.94+.035);',
        '     if(uCompact>.5||uReduced>.5)vAlpha=.035;',
        '   }else{',
        '     float arrival=assemblyArrival(aPosition,aPart)+(aPhase-.5)*.25;',
        '     float flight=aPart>.5?2.65:1.45+abs(aPosition.x)*.15;',
        '     float birth=max(.48,arrival-flight);',
        '     float q=smoothstep(birth,arrival,uTime);vPhase=q;',
        '     p=fountain(p,aPart,aPhase,q);',
        '     float phase=fract(max(0.,uTime-5.6)/(10.+aPhase*10.)+aPhase);',
        '     float detach=smoothstep(.08,.30,phase)*(1.-smoothstep(.63,.96,phase));',
        '     detach*=step(.94,aPhase)*uScatter*smoothstep(5.7,7.0,uTime)*(1.-uReduced);',
        '     vec3 farPoint=vec3(sin(aPhase*173.1)*2.8*uAspect,cos(aPhase*137.2)*2.1+.52,sin(aPhase*71.9)*.9);',
        '     p=mix(p,farPoint,detach);',
        '     p+=vec3(sin(uTime*.8+aPhase*63.),cos(uTime*.6+aPhase*43.),sin(uTime*.7+aPhase*31.))*.009*(1.-uReduced)*q;',
        '     vAlpha=(.48+.32*sin(aPhase*67.+uTime*1.7))*mix(1.,.08,detach)*smoothstep(birth,birth+.07,uTime);',
        '   }',
        ' }',
        ' vPosition=p; vNormal=n;',
        ' gl_Position=uProjection*uView*vec4(p,1.);',
        ' gl_PointSize=aPart>2.5?mix(52.,7.,smoothstep(5.5,7.2,uTime))*uDpr:clamp(aSize*uDpr*(uCompact>.5?2.0:2.6),1.5,5.5);',
        ' if(aPart>2.5&&(uCompact>.5||uReduced>.5))gl_PointSize=7.*uDpr;',
        '}'
    ].join('\n');
    var FRAGMENT = [
        '#extension GL_OES_standard_derivatives : enable',
        'precision highp float;',
        'uniform float uTime; uniform float uVoice; uniform float uAlert; uniform float uGlowPass; uniform float uPointPass; uniform float uCompact; uniform float uReduced; uniform float uExpand;',
        'varying vec3 vNormal; varying vec3 vPosition; varying vec3 vLocal; varying vec2 vUV; varying float vPart; varying float vAlpha; varying float vPhase;',
        ARRIVAL_SHADER,
        'float contour(float value,float width){float d=abs(fract(value+.5)-.5);float pixel=max(fwidth(value),.0008);return exp(-pow(d/(pixel*width),2.));}',
        'void main(){',
        ' vec3 eye=normalize(vec3(0.,.52,6.2)-vPosition); vec3 normal=normalize(vNormal);',
        ' float facing=abs(dot(normal,eye)); float rim=pow(1.-facing,2.0);',
        ' float head=step(.5,vPart)*(1.-step(2.5,vPart)); float ear=step(1.5,vPart)*(1.-step(2.5,vPart));',
        ' float front=smoothstep(.06,.32,vLocal.z)*head;',
        ' float lower=exp(-pow((vLocal.y-.85)/.225,2.))*exp(-pow(vLocal.x/.36,4.))*front*(1.-ear);',
        ' vec3 cyan=vec3(.01,.73,1.0), ice=vec3(.42,.95,1.0);',
        ' float wave=(sin(vLocal.x*24.-uTime*2.7)+.40*sin(vLocal.x*43.+uTime*3.9+vLocal.y*9.)+.22*sin(vLocal.x*63.-uTime*5.1))*(.43+uVoice*.30)*lower;',
        ' float fluid=(sin(vLocal.x*9.+vLocal.y*6.+uTime*.38)*.28+sin(vLocal.x*19.-vLocal.y*8.-uTime*.27)*.13)*head;',
        ' float latitude=vUV.y*mix(48.,43.,head)+wave+fluid;',
        ' float chest=1.-smoothstep(.16,.34,vLocal.y);',
        ' float pectoral=length(vec2((sqrt(vLocal.x*vLocal.x+.0049)-.79)*1.12,(vLocal.y+1.05)*1.05))*9.0;',
        ' float stream=vUV.x*24.+sin(vUV.y*11.)*.25;',
        ' float longitude=mix(stream,vUV.x*58.,head);',
        ' float lat=contour(latitude,.58), latGlow=contour(latitude,2.0);',
        ' float lon=mix(contour(pectoral,.55)*chest,contour(longitude,.50),head);',
        ' float lonGlow=mix(contour(pectoral,1.6)*chest,contour(longitude,1.6),head);',
        ' float neural=exp(-pow(vLocal.x/.14,2.))*chest*(1.-head);',
        ' float neuralPhase=vLocal.x*27.+sin(vLocal.y*11.)*.14;',
        ' lon*=1.-neural*.85;',
        ' lonGlow*=1.-neural*.85;',
        ' float collar=contour((vLocal.y+.13+.18*pow(abs(vLocal.x)-.66,2.))*29.,.65)*(1.-smoothstep(.28,.45,vLocal.y))*smoothstep(-.42,-.24,vLocal.y);',
        ' float breakage=.57+.43*smoothstep(-.25,.65,sin(vLocal.x*16.+vLocal.y*12.-uTime*.36)+.40*sin(vLocal.x*31.-vLocal.y*13.+uTime*.22));',
        ' float lines=mix(lon*.39,lat*.44*breakage+lon*.018,head);',
        ' float lineGlow=mix(lonGlow*.075,latGlow*.12*breakage,head);',
        ' if(ear>.5){lines=contour(vUV.y*3.+vUV.x*2.,.65)*.07;lineGlow=lines*.20;rim*=.18;}',
        ' float intersection=lat*lon; float current=pow(.5+.5*sin(vUV.y*39.+vUV.x*76.-uTime*2.1),18.);',
        ' vec3 color=cyan*(lines*.8+lineGlow+rim*mix(3.8,2.35,head))+ice*(lines*.12+intersection*.20);',
        ' float softFill=.045+pow(max(0.,dot(normal,normalize(vec3(-.5,1.,1.)))),2.)*.035;',
        ' color+=cyan*softFill;',
        ' vec3 amber=vec3(1.0,.38,.008), yellow=vec3(1.,.91,.22);',
        ' float warmth=clamp(lower*1.65,0.,1.);',
        ' float core=exp(-pow((vLocal.y-.84)/.16,2.)-pow(vLocal.x/.26,2.))*front;',
        ' vec3 hot=amber*(.55+latGlow*.70+lat*.90)+yellow*(core*.52+lat*(.60+uVoice*.60));',
        ' color=mix(color,hot,warmth);',
        ' float trunk=(1.-head)*(1.-smoothstep(.48,.68,vLocal.y))*smoothstep(-.36,-.25,vLocal.y);',
        ' float neuralWidth=.035+.235*smoothstep(-.32,.52,vLocal.y);',
        ' float neuralField=vLocal.x/neuralWidth*4.5+sin(vLocal.y*18.+vLocal.x*15.)*.27+sin(vLocal.y*35.+vLocal.x*29.)*.12;',
        ' float veins=contour(neuralField,.70)*exp(-pow(vLocal.x/neuralWidth,4.))*trunk;',
        ' color+=vec3(1.,.57,.06)*veins*(.64+uVoice*.26);',
        ' float nucleus=exp(-dot((vLocal-vec3(0.,-.32,.43))*vec3(15.,20.,18.),(vLocal-vec3(0.,-.32,.43))*vec3(15.,20.,18.)))*(1.-head);',
        ' color+=vec3(.45,1.,1.)*nucleus*.18;',
        ' color+=ice*intersection*current*.20;',
        ' float alpha=clamp(lines*.80+lineGlow*.30+rim*.94+softFill+warmth*.72+veins*.56+nucleus+.08*head+.045*(1.-head),0.,.98);',
        ' if(uGlowPass>.5){alpha=pow(1.-facing,3.)*.18*exp(-uExpand*65.)*mix(1.5,1.,head)*(1.-ear*.82);color=vec3(.0,.72,1.);}',
        ' if(uPointPass>.5){',
        '   float d=length(gl_PointCoord-.5)*2.; float edge=exp(-d*d*4.)*(1.-smoothstep(.72,1.,d));',
        '   color=mix(vec3(.05,.85,1.),vec3(1.,.63,.08),lower*.8*smoothstep(.9,1.,vPhase)); color+=vec3(.28,.55,.58)*(1.-d);',
        '   alpha=edge*vAlpha*(mix(.40,.72,head)+uAlert*.20);',
        '   if(vPart>2.5){color=vec3(.03,.78,1.)+vec3(.97,.22,0.)*exp(-d*d*13.);alpha=edge*vAlpha;}',
        ' } else {',
        '   float arrival=assemblyArrival(vLocal,vPart);float reveal=smoothstep(arrival-.04,arrival+.62,uTime);',
        '   alpha*=uReduced>.5?1.:reveal;',
        ' }',
        ' float fade=smoothstep(-.68,-.18,vLocal.y);',
        ' if(uPointPass>.5)fade=mix(1.,fade,vPhase);',
        ' if(vPart<2.5)alpha*=mix(fade,1.,head);',
        ' if(alpha<.001)discard;',
        ' color*=1.+uAlert*.13;',
        ' gl_FragColor=vec4(color*alpha,alpha);',
        '}'
    ].join('\n');
    var POST_VERTEX = 'attribute vec2 aQuad;varying vec2 vUV;void main(){vUV=aQuad*.5+.5;gl_Position=vec4(aQuad,0.,1.);}';
    var BLUR_FRAGMENT = [
        'precision highp float;varying vec2 vUV;uniform sampler2D uScene;uniform vec2 uDirection;',
        'void main(){vec4 c=texture2D(uScene,vUV)*.227027;',
        ' c+=(texture2D(uScene,vUV+uDirection*1.384615)+texture2D(uScene,vUV-uDirection*1.384615))*.316216;',
        ' c+=(texture2D(uScene,vUV+uDirection*3.230769)+texture2D(uScene,vUV-uDirection*3.230769))*.070270;',
        ' gl_FragColor=c;}'
    ].join('\n');
    var POST_FRAGMENT = [
        'precision highp float;varying vec2 vUV;uniform sampler2D uScene;uniform sampler2D uBloom;uniform vec2 uTexel;',
        'void main(){vec4 base=texture2D(uScene,vUV),halo=texture2D(uBloom,vUV);',
        ' vec4 near=(texture2D(uScene,vUV+uTexel)+texture2D(uScene,vUV-uTexel)+texture2D(uScene,vUV+vec2(uTexel.x,-uTexel.y))+texture2D(uScene,vUV+vec2(-uTexel.x,uTexel.y)))*.25;',
        ' vec3 color=base.rgb+near.rgb*.18+halo.rgb*1.05;',
        ' gl_FragColor=vec4(color,max(base.a,halo.a*.90));}'
    ].join('\n');

    // A separate emissive volume pass. The approved construction particles above
    // are untouched; these coherent internal currents begin only after construction.
    function buildEnergy(compact) {
        var random = randomGenerator(71823), count = compact ? 900 : 5400, data = [];
        for (var i = 0; i < count; i++) data.push(random(), random(), i % 10, .65 + random() * .80);
        return {data: new Float32Array(data), count: count};
    }
    var ENERGY_VERTEX = [
        'precision highp float;attribute vec4 aEnergy;',
        'uniform mat4 uProjection;uniform mat4 uView;uniform float uFlowTime;uniform float uTrail;uniform float uHeadYaw;uniform float uBodyYaw;uniform float uPitch;uniform float uDpr;uniform float uFlowVoice;',
        'varying float vWarm;varying float vEnergyAlpha;',
        'mat3 yaw(float a){float c=cos(a),s=sin(a);return mat3(c,0.,-s,0.,1.,0.,s,0.,c);}',
        'mat3 pitch(float a){float c=cos(a),s=sin(a);return mat3(1.,0.,0.,0.,c,s,0.,-s,c);}',
        'void main(){',
        ' float seed=aEnergy.x,lane=aEnergy.y,group=aEnergy.z,t=max(0.,uFlowTime-uTrail);vec3 p;float weight=0.;float fade=1.;',
        ' if(group<4.){',
        '   float h=fract(seed+t*(.105+lane*.035));float radius=.44*pow(max(0.,sin(h*3.14159265)),.60)*(.35+.50*lane);',
        '   float a=group*1.5707963+h*7.8-t*1.2+lane*.9+sin(h*8.+t*.6)*.28;',
        '   p=vec3(cos(a)*radius,.50+1.25*h,sin(a)*radius*.68+.015);weight=1.;',
        '   fade=smoothstep(0.,.10,h)*(1.-smoothstep(.90,1.,h));vWarm=step(.88,seed);',
        ' }else if(group<6.){',
        '   float q=fract(seed+t*(.19+lane*.05));float branch=(floor(lane*12.)-5.5)/5.5;',
        '   p=vec3(branch*(.02+.20*pow(q,1.4))+.014*sin(q*13.+branch*4.+t*.6),-.28+.98*q,.045+.11*(1.-q)*sin(branch*4.+q*7.));',
        '   weight=smoothstep(.35,.70,p.y);fade=smoothstep(0.,.08,q)*(1.-smoothstep(.92,1.,q));vWarm=step(.40,seed);',
        ' }else if(group<8.){',
        '   float q=fract(seed+t*(.13+lane*.05)),u=1.-q,side=group<7.?-1.:1.;',
        '   p=vec3(side*1.056,-.18,.06)*u*u*u+3.*vec3(side*.944,.16,.12)*u*u*q+3.*vec3(side*.144,.20,.10)*u*q*q+vec3(0.,.36,.045)*q*q*q;',
        '   fade=smoothstep(0.,.08,q)*(1.-smoothstep(.92,1.,q));vWarm=step(.72,seed);',
        ' }else{',
        '   float a=seed*6.2831853+t*(1.4+lane*.7)+.25*sin(t*1.8+seed*10.);float radius=.27*sqrt(lane)*(1.+uFlowVoice*.10);',
        '   p=vec3(cos(a)*radius,.85+sin(a)*radius*.65+.018*sin(t*3.+seed*9.)-uFlowVoice*.035,.08+sin(a*.7+seed*10.)*.14);',
        '   weight=1.;vWarm=1.;',
        ' }',
        ' float angle=mix(uBodyYaw,uHeadYaw,weight);vec3 pivot=mix(vec3(0.,.10,0.),vec3(0.,.53,-.035),weight);',
        ' p=yaw(angle)*pitch(uPitch*mix(.12,1.,weight))*(p-pivot)+pivot;',
        ' gl_Position=uProjection*uView*vec4(p,1.);',
        ' gl_PointSize=clamp(aEnergy.w*uDpr*3.4,1.7,6.0);',
        ' if(group>=6.&&group<8.)fade*=.32+.18*pow(.5+.5*sin(seed*28.+t*3.),4.);',
        ' vEnergyAlpha=fade*(.30+.13*sin(seed*31.+t*.8))*exp(-uTrail*9.)*smoothstep(5.6,6.5,uFlowTime)*(1.+uFlowVoice*.20);',
        '}'
    ].join('\n');
    var ENERGY_FRAGMENT = [
        'precision highp float;varying float vWarm;varying float vEnergyAlpha;',
        'void main(){float d=length(gl_PointCoord-.5)*2.;float glow=exp(-d*d*4.5)*(1.-smoothstep(.74,1.,d));',
        ' vec3 blue=vec3(.025,.56,1.),orange=vec3(1.,.42,.025);vec3 color=mix(blue,orange,vWarm);',
        ' color+=mix(vec3(.18,.48,.55),vec3(.30,.38,.10),vWarm)*exp(-d*d*12.);',
        ' float alpha=glow*vEnergyAlpha;if(alpha<.001)discard;gl_FragColor=vec4(color*alpha,alpha);}'
    ].join('\n');

    function buildSwarm(compact) {
        var random = randomGenerator(92531), count = compact ? 2048 : 65536, data = new Float32Array(count * 4);
        for (var i = 0; i < data.length; i++) data[i] = random();
        var motes = compact ? 24 : 96;
        for (i = 0; i < count; i++) data[i * 4 + 2] = i < motes ? .99965 + data[i * 4 + 2] * .00035 : data[i * 4 + 2] * .9996;
        return {data: data, count: count, motes: motes};
    }
    var SWARM_VERTEX = [
        'precision highp float;attribute vec4 aSwarm;uniform mat4 uProjection;uniform mat4 uView;uniform float uSwarmTime;uniform float uDpr;uniform float uDensityGain;uniform float uArtwork;',
        'varying float vSwarmWarm;varying float vSwarmAlpha;',
        'void main(){float t=uSwarmTime,seed=aSwarm.x,lane=aSwarm.y,depth=aSwarm.z;vec3 p;',
        ' float spread=fract(seed*19.37+lane*43.13+aSwarm.w*11.7);float a=lane*6.2831853+t*(.12+depth*.14);',
        ' if(aSwarm.w<.45){',
        '   float h=fract(seed+t*(.018+depth*.016)),vertical=h*2.-1.;',
        '   float radius=(.43+.65*spread)*sqrt(max(0.,1.-vertical*vertical));',
        '   a+=sin(h*7.+t*.24)*.30;radius*=.96+.14*sin(a*3.+h*9.-t*.13)+.09*sin(h*17.+a*2.+t*.21);',
        '   p=vec3(cos(a)*radius,1.12+vertical*(.72+.30*spread)+.06*sin(a*2.1+h*17.+t*.37),sin(a)*radius*.72);',
        ' }else{',
        '   float q=fract(seed+t*(.025+depth*.018)),h=.60-q*1.26;',
        '   float radius=.27+1.12*smoothstep(.05,1.,q)+spread*.40;',
        '   a+=sin(q*9.-t*.20)*.35;',
        '   p=vec3(cos(a)*radius,h+sin(a*2.+t*.24)*.065,sin(a)*(.28+spread*.25));',
        ' }',
        ' if(uArtwork>.5){',
        '   float h=fract(seed+t*(.014+depth*.010)),v=h*2.-1.;',
        '   if(aSwarm.w<.53){float r=.53*sqrt(max(0.,1.-v*v));p=vec3(cos(a)*r*1.15,1.13+v*.72,sin(a)*r*.78);}',
        '   else{float q=h,r=.27+1.0*smoothstep(.04,1.,q);p=vec3(cos(a)*r,.57-q*1.15,sin(a)*(.20+q*.18));}',
        '   p.x+=sign(p.x)*(.028+.12*spread);',
        ' }',
        ' float medium=step(.986,depth),bright=step(.99965,depth);vSwarmWarm=step(.55,fract(lane*7.13+seed*3.71));',
        ' float pulse=.64+.36*sin(seed*38.+t*(1.0+lane));',
        ' vSwarmAlpha=mix(mix(.010,.12,medium),1.1,bright)*pulse*mix(uDensityGain,1.,bright)*smoothstep(5.6,7.4,t)*(1.-smoothstep(.68,1.,spread)*mix(.90,.25,bright));',
        ' vSwarmAlpha*=mix(1.,.24,uArtwork);vSwarmWarm*=mix(1.,step(.84,seed),uArtwork);',
        ' gl_PointSize=(mix(1.05,1.75,medium)+bright*(2.4+seed*1.8))*uDpr*mix(1.,.65,uArtwork);',
        ' gl_Position=uProjection*uView*vec4(p,1.);',
        '}'
    ].join('\n');
    var SWARM_FRAGMENT = [
        'precision highp float;varying float vSwarmWarm;varying float vSwarmAlpha;',
        'void main(){float d=length(gl_PointCoord-.5)*2.;float glow=exp(-d*d*3.8)*(1.-smoothstep(.78,1.,d));',
        ' vec3 color=mix(vec3(.015,.55,1.),vec3(1.,.25,.025),vSwarmWarm);',
        ' float alpha=glow*vSwarmAlpha;if(alpha<.0001)discard;gl_FragColor=vec4(color*alpha,alpha);}'
    ].join('\n');

    function buildProjectionRing() {
        var points = [];
        for (var ring = 0; ring < 3; ring++) for (var i = 0; i <= 192; i++) {
            var a = i / 192 * TAU, radius = 1.22 + ring * .085;
            points.push(Math.cos(a) * radius, -.46 - ring * .012, Math.sin(a) * radius * .30, i / 192, ring);
        }
        return new Float32Array(points);
    }
    var RING_VERTEX = [
        'precision highp float;attribute vec3 aRingPosition;attribute vec2 aRingUV;',
        'uniform mat4 uProjection;uniform mat4 uView;varying vec2 vRingUV;',
        'void main(){vRingUV=aRingUV;gl_Position=uProjection*uView*vec4(aRingPosition,1.);}'
    ].join('\n');
    var RING_FRAGMENT = [
        'precision highp float;varying vec2 vRingUV;uniform float uRingTime;uniform float uRingVoice;uniform float uRingReduced;',
        'void main(){float angle=vRingUV.x*6.2831853;',
        ' float cursor=pow(.5+.5*cos(angle-uRingTime*.24*(1.-uRingReduced)),14.);',
        ' float alpha=(.14+cursor*.38+uRingVoice*.10)*smoothstep(4.3,5.6,uRingTime);',
        ' vec3 color=vec3(.03,.62,.84)+vec3(.13,.22,.20)*cursor;',
        ' gl_FragColor=vec4(color*alpha,alpha);}'
    ].join('\n');

    function shader(gl, type, source) {
        var result = gl.createShader(type); gl.shaderSource(result, source); gl.compileShader(result);
        if (!gl.getShaderParameter(result, gl.COMPILE_STATUS)) { var message = gl.getShaderInfoLog(result); gl.deleteShader(result); throw new Error(message || 'GO hologram shader compilation failed'); }
        return result;
    }
    function makeProgram(gl, vertexSource, fragmentSource) {
        var vertex = shader(gl, gl.VERTEX_SHADER, vertexSource || VERTEX), fragment = shader(gl, gl.FRAGMENT_SHADER, fragmentSource || FRAGMENT);
        var result = gl.createProgram(); gl.attachShader(result, vertex); gl.attachShader(result, fragment); gl.linkProgram(result);
        gl.deleteShader(vertex); gl.deleteShader(fragment);
        if (!gl.getProgramParameter(result, gl.LINK_STATUS)) { var message = gl.getProgramInfoLog(result); gl.deleteProgram(result); throw new Error(message || 'GO hologram linking failed'); }
        return result;
    }
    function projection(aspect) {
        var f = 1 / Math.tan(29 * Math.PI / 360) * Math.min(1, aspect / 1.18), near = .1, far = 40, matrix = new Float32Array(16);
        matrix[0] = f / aspect; matrix[5] = f; matrix[10] = (far + near) / (near - far); matrix[11] = -1; matrix[14] = 2 * far * near / (near - far);
        return matrix;
    }
    function viewMatrix(fullViewport) {
        var matrix = new Float32Array(16); matrix[0] = matrix[5] = matrix[10] = matrix[15] = 1;
        matrix[13] = fullViewport ? -.52 : -.52; matrix[14] = -6.2;
        return matrix;
    }
    // The approved portrait is mapped onto a deformable grid, with separate
    // image-coloured assembly particles. UV landmarks are in the original asset.
    var PORTRAIT_VERTEX = [
        'precision highp float; attribute vec2 aPortraitUV;',
        'uniform vec4 uRect; uniform vec2 uViewport; uniform float uTime,uYaw,uPitch,uBlink,uVoice,uPoint,uDpr;',
        'varying vec2 vPortraitUV; varying float vArrival;',
        'float hash(vec2 p){return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453);}',
        'vec2 eyelid(vec2 p,vec2 eye,vec2 size){vec2 q=(p-eye)/size; float w=exp(-dot(q,q)*1.7);p.y+=(eye.y-p.y)*uBlink*.97*w;return p;}',
        'void main(){vec2 uv=aPortraitUV,p=uv;vPortraitUV=uv;',
        'p=eyelid(p,vec2(.503,.315),vec2(.050,.029));p=eyelid(p,vec2(.620,.324),vec2(.038,.025));',
        'vec2 lip=(p-vec2(.562,.467))/vec2(.057,.025);float lips=exp(-lip.x*lip.x*1.8-lip.y*lip.y*.8);',
        'p.y+=uVoice*.009*(lip.y/(.33+abs(lip.y)))*lips;',
        'float j=(p.x-.562)/.105;float jaw=exp(-j*j*j*j)*smoothstep(.455,.50,p.y)*(1.-smoothstep(.54,.59,p.y));p.y+=uVoice*.008*jaw;',
        'float head=(1.-smoothstep(.50,.68,uv.y))*smoothstep(.30,.39,uv.x)*(1.-smoothstep(.69,.75,uv.x));',
        'vec2 h=p-vec2(.56,.58),depthUV=(p-vec2(.545,.30))/vec2(.17,.32);float depth=sqrt(max(0.,1.-dot(depthUV,depthUV)))*.10;',
        'p.x+=head*(sin(uYaw)*depth+h.y*uPitch*.35);p.y+=head*(-sin(uPitch)*depth+h.x*uYaw*.12);',
        'float seed=hash(uv),delay=.18+(1.-uv.y)*1.10+seed*.75;',
        'float travel=smoothstep(delay,delay+2.85,uTime);vArrival=smoothstep(delay-.09,delay+.18,uTime);',
        'if(uPoint>.5){vec2 emitter=vec2(.56,.78),delta=p-emitter;float spiral=sin(travel*9.42478+seed*6.28318);',
        'p=mix(emitter,p,travel)+vec2(spiral,cos(seed*6.28318+travel*8.))*sin(travel*3.14159)*(.055+seed*.11)*(1.-travel*.65);}',
        'vec2 pixel=uRect.xy+p*uRect.zw;gl_Position=vec4(pixel.x/uViewport.x*2.-1.,1.-pixel.y/uViewport.y*2.,0.,1.);',
        'gl_PointSize=clamp((1.7+seed*1.3)*uDpr,1.,4.5);}'
    ].join('\n');
    var PORTRAIT_FRAGMENT = [
        'precision highp float; uniform sampler2D uPortrait;uniform float uTime,uPoint,uVoice;',
        'varying vec2 vPortraitUV;varying float vArrival;',
        'void main(){vec4 c=texture2D(uPortrait,vPortraitUV);if(c.a<.025)discard;',
        'float reveal=smoothstep(4.35,5.6,uTime);float alpha=c.a;',
        'if(uPoint>.5){float r=length(gl_PointCoord-.5)*2.;alpha*=exp(-r*r*2.8)*vArrival*(1.-reveal);}',
        'else{alpha*=reveal;vec2 m=(vPortraitUV-vec2(.562,.467))/vec2(.043,.008);float cavity=exp(-m.x*m.x*m.x*m.x-m.y*m.y*3.)*uVoice*.80;c.rgb=mix(c.rgb,vec3(.002,.015,.025),cavity);}',
        'c.rgb*=1.+uVoice*.055;gl_FragColor=vec4(c.rgb*alpha,alpha);}'
    ].join('\n');
    var SEED_VERTEX = 'attribute vec2 aQuad;uniform vec4 uRect;uniform vec2 uViewport;uniform float uDpr;void main(){vec2 p=uRect.xy+vec2(.56,.78)*uRect.zw;gl_Position=vec4(p.x/uViewport.x*2.-1.,1.-p.y/uViewport.y*2.,0.,1.);gl_PointSize=48.*uDpr;}';
    var SEED_FRAGMENT = 'precision mediump float;uniform float uTime;void main(){float r=length(gl_PointCoord-.5)*2.;float core=exp(-r*r*70.),halo=exp(-r*r*6.);float a=(core+halo*.55)*(1.-smoothstep(1.2,4.0,uTime));gl_FragColor=vec4(vec3(1.,.55,.12)*a,a);}';
    function portraitGrid(columns, rows, points) {
        var uv=[], indices=[], rng=randomGenerator(91723);
        for(var y=0;y<=rows;y++)for(var x=0;x<=columns;x++)uv.push(clamp((x+(points?rng()*.8:0))/columns,0,1),clamp((y+(points?rng()*.8:0))/rows,0,1));
        if(!points)for(y=0;y<rows;y++)for(x=0;x<columns;x++){var a=y*(columns+1)+x,b=a+columns+1;indices.push(a,b,a+1,a+1,b,b+1);}
        return {uv:new Float32Array(uv),indices:new Uint16Array(indices),count:uv.length/2};
    }
    function Hologram(canvas, options) {
        this.canvas = canvas; this.options = options; this.compact = !!options.compact; this.artwork = typeof options.artwork === 'boolean' ? options.artwork : !!(canvas.parentElement && canvas.parentElement.querySelector('[data-hologram-artwork]'));
        this.fullViewport = !!options.fullViewport || (!!options.background && !this.compact);
        this.interval = 1000 / (this.compact ? 12 : this.artwork ? 24 : 30);
        this.visible = true; this.dead = false; this.lost = false; this.last = 0; this.elapsed = 0;
        this.width = 0; this.height = 0; this.speaking = false; this.alert = false;
        this.voiceLevel = 0; this.explicitVoice = false; this.mouthOpen = 0; this.gazeX = 0; this.gazeY = 0;
        this.headYaw = 0; this.bodyYaw = 0; this.pitch = 0; this.px = 0; this.py = 0;
        this.reduced = typeof options.reducedMotion === 'boolean' ? options.reducedMotion : !!(media && media.matches);
        this.gl = canvas.getContext('webgl', {alpha: true, antialias: true, premultipliedAlpha: true, depth: true, powerPreference: this.compact ? 'low-power' : 'high-performance'});
        if (!this.gl) throw new Error('GO hologram requires WebGL');
        this.mesh = buildMesh(this.compact); this.particles = buildParticles(this.compact); this.energyParticles = buildEnergy(this.compact); this.swarmParticles = buildSwarm(this.compact);
        this.swarmCount = 0; this.swarmLimit = 0; this.swarmSlow = 0; this.swarmFast = 0; this.lastSwarmDrawCount = 0;
        this.prepare();
        var self = this;
        this.onResize = function () { self.resize(); };
        this.onMotion = function () {
            if (typeof options.reducedMotion !== 'boolean') self.reduced = !!(media && media.matches);
            self.last = 0; if (active(self)) self.render(self.reduced ? 6 : self.elapsed); wake();
        };
        this.onLost = function (event) { event.preventDefault(); self.lost = true; self.releasePortrait(); canvas.dataset.goHologramState = 'recovering'; };
        this.onRestored = function () { if (self.dead) return; self.lost = false; self.prepare(); self.resize(); if(self.artwork)self.loadPortrait(); wake(); };
        canvas.addEventListener('webglcontextlost', this.onLost);
        canvas.addEventListener('webglcontextrestored', this.onRestored);
        if (window.ResizeObserver) { this.resizeObserver = new window.ResizeObserver(this.onResize); this.resizeObserver.observe(canvas); } else window.addEventListener('resize', this.onResize);
        if (window.IntersectionObserver) {
            this.intersectionObserver = new window.IntersectionObserver(function (entries) { self.visible = entries[0].isIntersecting; self.last = 0; if (active(self)) self.render(self.reduced ? 6 : self.elapsed); wake(); }, {threshold: 0});
            this.intersectionObserver.observe(canvas);
        }
        if (media) { if (media.addEventListener) media.addEventListener('change', this.onMotion); else if (media.addListener) media.addListener(this.onMotion); }
        canvas.setAttribute('aria-hidden', 'true'); canvas.dataset.goHologramRenderer = this.artwork ? 'webgl-depth-artwork-v6' : 'webgl-geometry-v6'; this.resize();
        this.artworkImage = canvas.parentElement ? canvas.parentElement.querySelector('[data-hologram-artwork]') : null;
        if (this.artworkImage) {
            this.onArtworkError = function () {
                if (self.dead) return;
                self.artworkImage.hidden = true;
                self.releasePortrait();
                if (canvas.parentElement) canvas.parentElement.dataset.artworkFailed = 'true';
                self.setArtwork(false);
            };
            this.onArtworkLoad = function () {
                if (self.dead || !self.artworkImage.naturalWidth) return;
                self.artworkImage.hidden = false;
                if (canvas.parentElement) delete canvas.parentElement.dataset.artworkFailed;
                self.setArtwork(true);
                self.loadPortrait();
            };
            this.artworkImage.addEventListener('error', this.onArtworkError);
            this.artworkImage.addEventListener('load', this.onArtworkLoad);
            if (this.artworkImage.complete && (this.artworkImage.currentSrc || this.artworkImage.getAttribute('src'))) {
                if (this.artworkImage.naturalWidth) this.onArtworkLoad(); else this.onArtworkError();
            }
        }
    }
    Hologram.prototype.setArtwork = function (value) {
        if (this.dead) return;
        var next = !!value;
        if (next === this.artwork) return;
        this.artwork = next; this.interval = 1000 / (this.compact ? 12 : this.artwork ? 24 : 30);
        this.canvas.dataset.goHologramRenderer = this.artwork ? 'webgl-depth-artwork-v6' : 'webgl-geometry-v6';
        this.resize(); wake();
    };
    Hologram.prototype.loadPortrait = function () {
        if(this.dead || this.lost || !this.artworkImage || !this.artworkImage.naturalWidth)return;
        var gl=this.gl;
        try {
            if(!this.portraitProgram){
                this.portraitProgram=makeProgram(gl,PORTRAIT_VERTEX,PORTRAIT_FRAGMENT);this.portraitUV=gl.getAttribLocation(this.portraitProgram,'aPortraitUV');this.portraitUniforms={};
                ['uRect','uViewport','uTime','uYaw','uPitch','uBlink','uVoice','uPoint','uDpr','uPortrait'].forEach(function(n){this.portraitUniforms[n]=gl.getUniformLocation(this.portraitProgram,n);},this);
                var grid=portraitGrid(this.compact?64:160,this.compact?48:120,false),dots=portraitGrid(this.compact?63:255,this.compact?63:191,true);
                this.portraitMesh=gl.createBuffer();gl.bindBuffer(gl.ARRAY_BUFFER,this.portraitMesh);gl.bufferData(gl.ARRAY_BUFFER,grid.uv,gl.STATIC_DRAW);
                this.portraitIndex=gl.createBuffer();gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER,this.portraitIndex);gl.bufferData(gl.ELEMENT_ARRAY_BUFFER,grid.indices,gl.STATIC_DRAW);this.portraitIndexCount=grid.indices.length;
                this.portraitDots=gl.createBuffer();gl.bindBuffer(gl.ARRAY_BUFFER,this.portraitDots);gl.bufferData(gl.ARRAY_BUFFER,dots.uv,gl.STATIC_DRAW);this.portraitParticles=dots.count;
                this.seedProgram=makeProgram(gl,SEED_VERTEX,SEED_FRAGMENT);this.seedAttribute=gl.getAttribLocation(this.seedProgram,'aQuad');this.seedUniforms={};
                ['uRect','uViewport','uDpr','uTime'].forEach(function(n){this.seedUniforms[n]=gl.getUniformLocation(this.seedProgram,n);},this);
                this.portraitTexture=gl.createTexture();
            }
            gl.bindTexture(gl.TEXTURE_2D,this.portraitTexture);gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL,false);gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL,false);
            gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,gl.RGBA,gl.UNSIGNED_BYTE,this.artworkImage);
            gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.LINEAR);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.LINEAR);
            gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_S,gl.CLAMP_TO_EDGE);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_T,gl.CLAMP_TO_EDGE);
            this.portraitReady=true;this.canvas.parentElement.dataset.portraitGpu='true';this.updatePortraitRect();this.render(this.reduced?6:this.elapsed);wake();
        } catch(_){this.portraitReady=false;delete this.canvas.parentElement.dataset.portraitGpu;}
    };
    Hologram.prototype.updatePortraitRect = function () {
        if(!this.artworkImage)return;
        var cb=this.canvas.getBoundingClientRect(),ib=typeof this.artworkImage.getBoundingClientRect==='function'?this.artworkImage.getBoundingClientRect():cb;
        var aspect=this.artworkImage.naturalWidth/(this.artworkImage.naturalHeight||1082),w=Math.min(ib.width,ib.height*aspect),h=w/aspect;
        this.portraitRect=[(ib.left||0)-(cb.left||0)+(ib.width-w)/2,(ib.top||0)-(cb.top||0)+(ib.height-h)/2,w,h];
    };
    Hologram.prototype.releasePortrait = function () {
        var gl=this.gl;
        if(!this.lost){[this.portraitMesh,this.portraitIndex,this.portraitDots].forEach(function(b){if(b)gl.deleteBuffer(b);});if(this.portraitProgram)gl.deleteProgram(this.portraitProgram);if(this.seedProgram)gl.deleteProgram(this.seedProgram);if(this.portraitTexture)gl.deleteTexture(this.portraitTexture);}
        this.portraitProgram=null;this.seedProgram=null;this.portraitTexture=null;this.portraitReady=false;
        if(this.canvas.parentElement)delete this.canvas.parentElement.dataset.portraitGpu;
    };
    Hologram.prototype.renderPortrait = function(time) {
        if(!this.portraitReady || !this.portraitRect)return;
        var gl=this.gl,u=this.portraitUniforms,r=this.portraitRect,t=this.compact||this.reduced?6:Math.max(0,time);
        this.assemblyProgress=clamp(t/ASSEMBLY_DURATION,0,1);
        gl.disable(gl.DEPTH_TEST);gl.disable(gl.CULL_FACE);gl.enable(gl.BLEND);gl.blendFunc(gl.ONE,gl.ONE_MINUS_SRC_ALPHA);
        gl.useProgram(this.portraitProgram);gl.uniform4f(u.uRect,r[0],r[1],r[2],r[3]);gl.uniform2f(u.uViewport,this.width,this.height);
        gl.uniform1f(u.uTime,t);gl.uniform1f(u.uYaw,this.headYaw);gl.uniform1f(u.uPitch,this.pitch);gl.uniform1f(u.uBlink,this.blink);gl.uniform1f(u.uVoice,this.level);gl.uniform1f(u.uDpr,this.ratio);
        gl.activeTexture(gl.TEXTURE0);gl.bindTexture(gl.TEXTURE_2D,this.portraitTexture);gl.uniform1i(u.uPortrait,0);
        if(t>4.35){gl.bindBuffer(gl.ARRAY_BUFFER,this.portraitMesh);gl.enableVertexAttribArray(this.portraitUV);gl.vertexAttribPointer(this.portraitUV,2,gl.FLOAT,false,0,0);gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER,this.portraitIndex);gl.uniform1f(u.uPoint,0);gl.drawElements(gl.TRIANGLES,this.portraitIndexCount,gl.UNSIGNED_SHORT,0);}
        if(t<ASSEMBLY_DURATION){gl.bindBuffer(gl.ARRAY_BUFFER,this.portraitDots);gl.enableVertexAttribArray(this.portraitUV);gl.vertexAttribPointer(this.portraitUV,2,gl.FLOAT,false,0,0);gl.uniform1f(u.uPoint,1);gl.drawArrays(gl.POINTS,0,this.portraitParticles);
            u=this.seedUniforms;gl.useProgram(this.seedProgram);gl.uniform4f(u.uRect,r[0],r[1],r[2],r[3]);gl.uniform2f(u.uViewport,this.width,this.height);gl.uniform1f(u.uDpr,this.ratio);gl.uniform1f(u.uTime,t);if(this.seedAttribute>=0){gl.bindBuffer(gl.ARRAY_BUFFER,this.quadBuffer);gl.enableVertexAttribArray(this.seedAttribute);gl.vertexAttribPointer(this.seedAttribute,2,gl.FLOAT,false,0,0);}gl.drawArrays(gl.POINTS,0,1);}
    };
    Hologram.prototype.prepare = function () {
        var gl = this.gl; gl.getExtension('OES_standard_derivatives'); this.program = makeProgram(gl); gl.useProgram(this.program);
        var names = ['aPosition', 'aNormal', 'aUV', 'aPart', 'aPhase', 'aSize']; this.attributes = {};
        for (var i = 0; i < names.length; i++) this.attributes[names[i]] = gl.getAttribLocation(this.program, names[i]);
        names = ['uProjection', 'uView', 'uHeadYaw', 'uBodyYaw', 'uPitch', 'uTime', 'uVoice', 'uPointPass', 'uExpand', 'uDpr', 'uCompact', 'uAspect', 'uScatter', 'uReduced', 'uAlert', 'uGlowPass']; this.uniforms = {};
        for (i = 0; i < names.length; i++) this.uniforms[names[i]] = gl.getUniformLocation(this.program, names[i]);
        this.vertexBuffer = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, this.vertexBuffer); gl.bufferData(gl.ARRAY_BUFFER, this.mesh.vertices, gl.STATIC_DRAW);
        this.indexBuffer = gl.createBuffer(); gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this.indexBuffer); gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, this.mesh.indices, gl.STATIC_DRAW);
        this.particleBuffer = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, this.particleBuffer); gl.bufferData(gl.ARRAY_BUFFER, this.particles.vertices, gl.STATIC_DRAW);
        this.postProgram = makeProgram(gl, POST_VERTEX, POST_FRAGMENT);
        this.quadAttribute = gl.getAttribLocation(this.postProgram, 'aQuad');
        this.sceneUniform = gl.getUniformLocation(this.postProgram, 'uScene');
        this.texelUniform = gl.getUniformLocation(this.postProgram, 'uTexel');
        this.bloomUniform = gl.getUniformLocation(this.postProgram, 'uBloom');
        this.blurProgram = makeProgram(gl, POST_VERTEX, BLUR_FRAGMENT);
        this.blurAttribute = gl.getAttribLocation(this.blurProgram, 'aQuad');
        this.blurSceneUniform = gl.getUniformLocation(this.blurProgram, 'uScene');
        this.blurDirectionUniform = gl.getUniformLocation(this.blurProgram, 'uDirection');
        this.quadBuffer = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
        this.energyProgram = makeProgram(gl, ENERGY_VERTEX, ENERGY_FRAGMENT);
        this.energyAttribute = gl.getAttribLocation(this.energyProgram, 'aEnergy');
        this.energyUniforms = {};
        names = ['uProjection', 'uView', 'uFlowTime', 'uTrail', 'uHeadYaw', 'uBodyYaw', 'uPitch', 'uDpr', 'uFlowVoice'];
        for (i = 0; i < names.length; i++) this.energyUniforms[names[i]] = gl.getUniformLocation(this.energyProgram, names[i]);
        this.energyBuffer = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, this.energyBuffer);
        gl.bufferData(gl.ARRAY_BUFFER, this.energyParticles.data, gl.STATIC_DRAW);
        this.swarmProgram = makeProgram(gl, SWARM_VERTEX, SWARM_FRAGMENT);
        this.swarmAttribute = gl.getAttribLocation(this.swarmProgram, 'aSwarm'); this.swarmUniforms = {};
        names = ['uProjection', 'uView', 'uSwarmTime', 'uDpr', 'uDensityGain', 'uArtwork'];
        for (i = 0; i < names.length; i++) this.swarmUniforms[names[i]] = gl.getUniformLocation(this.swarmProgram, names[i]);
        this.swarmBuffer = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, this.swarmBuffer); gl.bufferData(gl.ARRAY_BUFFER, this.swarmParticles.data, gl.STATIC_DRAW);
        this.ringProgram = makeProgram(gl, RING_VERTEX, RING_FRAGMENT);
        this.ringPosition = gl.getAttribLocation(this.ringProgram, 'aRingPosition');
        this.ringUV = gl.getAttribLocation(this.ringProgram, 'aRingUV');
        this.ringUniforms = {};
        names = ['uProjection', 'uView', 'uRingTime', 'uRingVoice', 'uRingReduced'];
        for (i = 0; i < names.length; i++) this.ringUniforms[names[i]] = gl.getUniformLocation(this.ringProgram, names[i]);
        this.ringBuffer = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, this.ringBuffer);
        gl.bufferData(gl.ARRAY_BUFFER, buildProjectionRing(), gl.STATIC_DRAW);
        gl.getExtension('OES_standard_derivatives'); gl.enable(gl.DEPTH_TEST); gl.enable(gl.CULL_FACE); gl.cullFace(gl.BACK);
        gl.clearColor(0, 0, 0, 0); gl.clearDepth(1);
        this.canvas.dataset.goHologramState = 'ready';
    };
    Hologram.prototype.allocateTarget = function () {
        var gl = this.gl;
        if (this.bloomTargets) this.bloomTargets.forEach(function (target) { gl.deleteTexture(target.texture); gl.deleteFramebuffer(target.framebuffer); });
        if (this.sceneTexture) gl.deleteTexture(this.sceneTexture);
        if (this.sceneFramebuffer) gl.deleteFramebuffer(this.sceneFramebuffer);
        if (this.sceneDepth) gl.deleteRenderbuffer(this.sceneDepth);
        if (this.artwork) { this.bloomTargets = []; this.sceneTexture = this.sceneFramebuffer = this.sceneDepth = null; return; }
        // This texture contains only geometry rendered by this engine. Allocation
        // supplies null data; no photographs or face sprites are ever uploaded.
        this.sceneTexture = gl.createTexture(); gl.bindTexture(gl.TEXTURE_2D, this.sceneTexture);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, this.canvas.width, this.canvas.height, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
        this.sceneDepth = gl.createRenderbuffer(); gl.bindRenderbuffer(gl.RENDERBUFFER, this.sceneDepth);
        gl.renderbufferStorage(gl.RENDERBUFFER, gl.DEPTH_COMPONENT16, this.canvas.width, this.canvas.height);
        this.sceneFramebuffer = gl.createFramebuffer(); gl.bindFramebuffer(gl.FRAMEBUFFER, this.sceneFramebuffer);
        gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, this.sceneTexture, 0);
        gl.framebufferRenderbuffer(gl.FRAMEBUFFER, gl.DEPTH_ATTACHMENT, gl.RENDERBUFFER, this.sceneDepth);
        if (gl.checkFramebufferStatus(gl.FRAMEBUFFER) !== gl.FRAMEBUFFER_COMPLETE) throw new Error('GO hologram render target unavailable');
        this.bloomWidth = Math.max(1, Math.ceil(this.width / 4)); this.bloomHeight = Math.max(1, Math.ceil(this.height / 4));
        this.bloomTargets = [];
        for (var b = 0; b < 2; b++) {
            var texture = gl.createTexture(); gl.bindTexture(gl.TEXTURE_2D, texture);
            gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, this.bloomWidth, this.bloomHeight, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
            var framebuffer = gl.createFramebuffer(); gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer);
            gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0);
            if (gl.checkFramebufferStatus(gl.FRAMEBUFFER) !== gl.FRAMEBUFFER_COMPLETE) throw new Error('GO hologram bloom target unavailable');
            this.bloomTargets.push({texture: texture, framebuffer: framebuffer});
        }
        gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    };
    Hologram.prototype.resize = function () {
        if (this.dead || this.lost) return;
        var box = this.canvas.getBoundingClientRect(); this.width = Math.round(box.width); this.height = Math.round(box.height);
        if (this.width < 2 || this.height < 2) return;
        this.ratio = Math.max(.5, Math.min(this.artwork ? 1.5 : 2, window.devicePixelRatio || 1, Math.sqrt((this.artwork ? 2100000 : 4000000) / (this.width * this.height))));
        this.canvas.width = Math.round(this.width * this.ratio); this.canvas.height = Math.round(this.height * this.ratio);
        var density = this.artwork ? (this.compact ? 256 : this.width < 700 ? 2048 : 4096) : this.compact ? 2048 : this.width < 700 ? 16384 : 65536;
        if (typeof this.options.swarmCount === 'number') density = clamp(Math.floor(this.options.swarmCount), 1024, this.swarmParticles.count);
        if (density !== this.swarmLimit) { this.swarmLimit = density; this.swarmCount = density; this.swarmSlow = 0; this.swarmFast = 0; }
        this.allocateTarget();
        this.projection = projection(this.width / this.height); this.view = viewMatrix(this.fullViewport);
        this.updatePortraitRect();
        this.gl.viewport(0, 0, this.canvas.width, this.canvas.height); this.last = 0;
        this.render(this.reduced ? 6 : this.elapsed); wake();
    };
    Hologram.prototype.pose = function (time) {
        this.px += (this.gazeX-this.px)*.08;this.py += (this.gazeY-this.py)*.08;
        this.headYaw = this.reduced?0:.022*Math.sin(time*.47)+.009*Math.sin(time*.83)+this.px*.035;
        this.bodyYaw = 0;this.pitch = this.reduced?0:.012*Math.sin(time*.38)+this.py*.018;
        var blinkPhase=time%4.6;this.blink=this.reduced?0:Math.sin(Math.PI*clamp((blinkPhase-3.35)/.30,0,1));
        var level = this.explicitVoice ? this.voiceLevel : this.speaking ? .36 + .24 * Math.sin(time * 12.7) + .18 * Math.sin(time * 19.1) : 0;
        this.level = this.reduced ? 0 : clamp(level, 0, 1); this.mouthOpen = this.level * .095;
    };
    Hologram.prototype.bind = function (particles) {
        var gl = this.gl, attributes = this.attributes, stride = particles ? 44 : 36;
        gl.bindBuffer(gl.ARRAY_BUFFER, particles ? this.particleBuffer : this.vertexBuffer);
        [['aPosition', 3, 0], ['aNormal', 3, 12], ['aUV', 2, 24], ['aPart', 1, 32]].forEach(function (entry) {
            var index = attributes[entry[0]]; if (index < 0) return; gl.enableVertexAttribArray(index); gl.vertexAttribPointer(index, entry[1], gl.FLOAT, false, stride, entry[2]);
        });
        ['aPhase', 'aSize'].forEach(function (name, i) {
            var index = attributes[name]; if (index < 0) return;
            if (particles) { gl.enableVertexAttribArray(index); gl.vertexAttribPointer(index, 1, gl.FLOAT, false, stride, 36 + i * 4); }
            else { gl.disableVertexAttribArray(index); gl.vertexAttrib1f(index, i === 0 ? 0 : 1); }
        });
        if (!particles) gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this.indexBuffer);
    };
    Hologram.prototype.renderEnergy = function (time) {
        var flowTime = this.reduced ? 8 : this.compact ? time + 8 : time;
        if (flowTime <= ASSEMBLY_DURATION) return;
        var gl = this.gl, u = this.energyUniforms;
        gl.useProgram(this.energyProgram); gl.bindBuffer(gl.ARRAY_BUFFER, this.energyBuffer);
        gl.enableVertexAttribArray(this.energyAttribute); gl.vertexAttribPointer(this.energyAttribute, 4, gl.FLOAT, false, 16, 0);
        gl.uniformMatrix4fv(u.uProjection, false, this.projection); gl.uniformMatrix4fv(u.uView, false, this.view);
        gl.uniform1f(u.uFlowTime, flowTime); gl.uniform1f(u.uHeadYaw, this.headYaw); gl.uniform1f(u.uBodyYaw, this.bodyYaw);
        gl.uniform1f(u.uPitch, this.pitch); gl.uniform1f(u.uDpr, this.ratio); gl.uniform1f(u.uFlowVoice, this.level);
        // X-ray emission is deliberate: the currents are inside the bounded head,
        // neck and torso volume and must remain visible through a translucent shell.
        gl.disable(gl.DEPTH_TEST); gl.depthMask(false); gl.disable(gl.CULL_FACE); gl.enable(gl.BLEND); gl.blendFunc(gl.ONE, gl.ONE);
        var trails = [.18, .12, .06, 0];
        for (var i = 0; i < trails.length; i++) { gl.uniform1f(u.uTrail, trails[i]); gl.drawArrays(gl.POINTS, 0, this.energyParticles.count); }
    };
    Hologram.prototype.adaptDensity = function (frameMs) {
        if (this.options.adaptiveParticles === false || this.compact || !this.lastSwarmDrawCount) return;
        if (frameMs > 220 && this.swarmCount > 4096) { this.swarmCount = Math.max(4096, this.swarmCount / 4); this.swarmSlow = 0; this.swarmFast = 0; return; }
        if (frameMs > 78) { this.swarmSlow++; this.swarmFast = 0; }
        else if (frameMs < 40) { this.swarmFast++; this.swarmSlow = Math.max(0, this.swarmSlow - 1); }
        else { this.swarmSlow = Math.max(0, this.swarmSlow - 1); this.swarmFast = 0; }
        if (this.swarmSlow >= 8 && this.swarmCount > 4096) { this.swarmCount = Math.max(4096, this.swarmCount / 2); this.swarmSlow = 0; }
        if (this.swarmFast >= 120 && this.swarmCount < this.swarmLimit) { this.swarmCount = Math.min(this.swarmLimit, this.swarmCount * 2); this.swarmFast = 0; }
    };
    Hologram.prototype.renderSwarm = function (time) {
        var flowTime = this.reduced ? 8 : this.compact ? time + 8 : time;
        this.lastSwarmDrawCount = 0;
        if (flowTime <= ASSEMBLY_DURATION || !this.swarmCount) return;
        var gl = this.gl, u = this.swarmUniforms;
        gl.useProgram(this.swarmProgram); gl.bindBuffer(gl.ARRAY_BUFFER, this.swarmBuffer);
        gl.enableVertexAttribArray(this.swarmAttribute); gl.vertexAttribPointer(this.swarmAttribute, 4, gl.FLOAT, false, 16, 0);
        gl.uniformMatrix4fv(u.uProjection, false, this.projection); gl.uniformMatrix4fv(u.uView, false, this.view);
        gl.uniform1f(u.uSwarmTime, flowTime); gl.uniform1f(u.uDpr, this.ratio); gl.uniform1f(u.uDensityGain, Math.min(2.2, Math.sqrt(this.swarmLimit / this.swarmCount))); gl.uniform1f(u.uArtwork, this.artwork ? 1 : 0);
        gl.disable(gl.DEPTH_TEST); gl.depthMask(false); gl.disable(gl.CULL_FACE); gl.enable(gl.BLEND); gl.blendFunc(gl.ONE, gl.ONE);
        this.lastSwarmDrawCount = this.swarmCount; gl.drawArrays(gl.POINTS, 0, this.swarmCount);
    };
    Hologram.prototype.renderProjectionRing = function (time) {
        if (this.options.projectionRing === false || (this.artwork && this.options.projectionRing !== true)) return;
        var gl = this.gl, u = this.ringUniforms;
        gl.useProgram(this.ringProgram); gl.bindBuffer(gl.ARRAY_BUFFER, this.ringBuffer);
        gl.enableVertexAttribArray(this.ringPosition); gl.vertexAttribPointer(this.ringPosition, 3, gl.FLOAT, false, 20, 0);
        gl.enableVertexAttribArray(this.ringUV); gl.vertexAttribPointer(this.ringUV, 2, gl.FLOAT, false, 20, 12);
        gl.uniformMatrix4fv(u.uProjection, false, this.projection); gl.uniformMatrix4fv(u.uView, false, this.view);
        gl.uniform1f(u.uRingTime, this.reduced || this.compact ? Math.max(6, time) : time);
        gl.uniform1f(u.uRingVoice, this.level); gl.uniform1f(u.uRingReduced, this.reduced ? 1 : 0);
        gl.disable(gl.DEPTH_TEST); gl.disable(gl.CULL_FACE); gl.enable(gl.BLEND); gl.blendFunc(gl.ONE, gl.ONE);
        for (var ring = 0; ring < 3; ring++) gl.drawArrays(gl.LINE_STRIP, ring * 193, 193);
    };
    Hologram.prototype.render = function (time) {
        if (this.dead || this.lost || this.width < 2 || this.height < 2) return;
        this.pose(time);
        var gl = this.gl, u = this.uniforms;
        // Texture/grid and image-coloured particles share the approved portrait.
        if (this.artwork) {
            gl.bindFramebuffer(gl.FRAMEBUFFER, null); gl.viewport(0, 0, this.canvas.width, this.canvas.height);
            gl.colorMask(true, true, true, true); gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
            this.renderPortrait(time);
            this.renderSwarm(time); this.renderProjectionRing(time);
            gl.depthMask(true); gl.disable(gl.BLEND); return;
        }
        gl.useProgram(this.program);
        gl.bindFramebuffer(gl.FRAMEBUFFER, this.sceneFramebuffer);
        gl.uniformMatrix4fv(u.uProjection, false, this.projection); gl.uniformMatrix4fv(u.uView, false, this.view);
        gl.uniform1f(u.uTime, this.reduced ? 6 : this.compact ? Math.max(6, time + 6) : Math.max(0, time)); gl.uniform1f(u.uHeadYaw, this.headYaw); gl.uniform1f(u.uBodyYaw, this.bodyYaw); gl.uniform1f(u.uPitch, this.pitch);
        gl.uniform1f(u.uVoice, this.level); gl.uniform1f(u.uAlert, this.alert ? 1 : 0); gl.uniform1f(u.uCompact, this.compact ? 1 : 0); gl.uniform1f(u.uDpr, this.ratio);
        gl.uniform1f(u.uAspect, this.width / this.height); gl.uniform1f(u.uScatter, this.fullViewport && !this.compact ? 1 : 0); gl.uniform1f(u.uReduced, this.reduced ? 1 : 0);
        gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT); gl.enable(gl.DEPTH_TEST); gl.enable(gl.CULL_FACE); gl.cullFace(gl.BACK);
        this.bind(false); gl.uniform1f(u.uPointPass, 0); gl.uniform1f(u.uGlowPass, 0); gl.uniform1f(u.uExpand, 0);
        // Depth prepass: the hidden ear, back of the head and far shoulder really occlude.
        gl.disable(gl.BLEND); gl.colorMask(false, false, false, false); gl.depthMask(true); gl.depthFunc(gl.LEQUAL);
        gl.drawElements(gl.TRIANGLES, this.mesh.indices.length, gl.UNSIGNED_SHORT, 0);
        gl.colorMask(true, true, true, true); gl.depthMask(false); gl.enable(gl.BLEND); gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
        gl.drawElements(gl.TRIANGLES, this.mesh.indices.length, gl.UNSIGNED_SHORT, 0);
        // Expanded back-face shells create a soft physical rim without a low-resolution
        // post-processing raster or external resources. Contours stay native-resolution.
        gl.cullFace(gl.FRONT); gl.uniform1f(u.uGlowPass, 1); gl.blendFunc(gl.ONE, gl.ONE);
        var shells = this.compact ? [.006, .017] : [.003, .009, .018, .030];
        for (var i = 0; i < shells.length; i++) { gl.uniform1f(u.uExpand, shells[i]); gl.drawElements(gl.TRIANGLES, this.mesh.indices.length, gl.UNSIGNED_SHORT, 0); }
        gl.uniform1f(u.uExpand, 0); gl.uniform1f(u.uGlowPass, 0); gl.uniform1f(u.uPointPass, 1); gl.disable(gl.CULL_FACE);
        this.bind(true); gl.drawArrays(gl.POINTS, 0, this.particles.count);
        this.renderEnergy(time); this.renderSwarm(time); this.renderProjectionRing(time);
        gl.depthMask(true); gl.disable(gl.BLEND);
        // A broad, continuous energy envelope from a separable generated-light blur.
        gl.disable(gl.DEPTH_TEST); gl.disable(gl.CULL_FACE); gl.useProgram(this.blurProgram);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer); gl.enableVertexAttribArray(this.blurAttribute);
        gl.vertexAttribPointer(this.blurAttribute, 2, gl.FLOAT, false, 0, 0); gl.activeTexture(gl.TEXTURE0); gl.uniform1i(this.blurSceneUniform, 0);
        gl.viewport(0, 0, this.bloomWidth, this.bloomHeight);
        for (var blur = 0; blur < 2; blur++) {
            gl.bindFramebuffer(gl.FRAMEBUFFER, this.bloomTargets[blur].framebuffer);
            gl.bindTexture(gl.TEXTURE_2D, blur ? this.bloomTargets[0].texture : this.sceneTexture);
            gl.uniform2f(this.blurDirectionUniform, blur ? 0 : 1 / this.bloomWidth, blur ? 1 / this.bloomHeight : 0);
            gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
        }
        gl.viewport(0, 0, this.canvas.width, this.canvas.height);
        gl.bindFramebuffer(gl.FRAMEBUFFER, null); gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
        gl.disable(gl.DEPTH_TEST); gl.disable(gl.CULL_FACE); gl.useProgram(this.postProgram);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer); gl.enableVertexAttribArray(this.quadAttribute);
        gl.vertexAttribPointer(this.quadAttribute, 2, gl.FLOAT, false, 0, 0);
        gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, this.sceneTexture);
        gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, this.bloomTargets[1].texture); gl.uniform1i(this.bloomUniform, 1);
        gl.uniform1i(this.sceneUniform, 0); gl.uniform2f(this.texelUniform, this.ratio / this.canvas.width, this.ratio / this.canvas.height);
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
    };
    Hologram.prototype.project = function (x, y, z, group) {
        var yaw = group ? this.headYaw : this.bodyYaw, pivotY = group ? .53 : .10, pivotZ = group ? -.035 : 0;
        var cy = Math.cos(yaw), sy = Math.sin(yaw), pitch = this.pitch * (group ? 1 : .12), cp = Math.cos(pitch), sp = Math.sin(pitch);
        var yy = (y - pivotY) * cp - (z - pivotZ) * sp;
        var zz = (y - pivotY) * sp + (z - pivotZ) * cp;
        var xx = x * cy + zz * sy, depth = -x * sy + zz * cy + pivotZ;
        var f = 1 / Math.tan(29 * Math.PI / 360) * Math.min(1, this.width / this.height / 1.18), cameraY = this.fullViewport ? .52 : .52;
        this.px = this.width / 2 + xx / (6.2 - depth) * f * this.height / 2;
        this.py = this.height / 2 - (yy + pivotY - cameraY) / (6.2 - depth) * f * this.height / 2;
        return {x: this.px, y: this.py, z: depth};
    };
    Hologram.prototype.internalFlow = function (time) {
        time = Math.max(0, Number(time) || 0);
        var flowTime = this.reduced ? 8 : this.compact ? time + 8 : time;
        var saved = [this.headYaw, this.bodyYaw, this.pitch, this.level, this.mouthOpen]; this.pose(time);
        var self = this, f = 1 / Math.tan(29 * Math.PI / 360) * Math.min(1, this.width / this.height / 1.18);
        function fract(v) { return v - Math.floor(v); }
        function sample(id) {
            var offset = id * 4, data = self.energyParticles.data, seed = data[offset], lane = data[offset + 1], group = data[offset + 2], p, weight = 0, warm;
            if (group < 4) {
                var h = fract(seed + flowTime * (.105 + lane * .035)), radius = .44 * Math.pow(Math.max(0, Math.sin(h * Math.PI)), .60) * (.35 + .50 * lane);
                var a = group * 1.5707963 + h * 7.8 - flowTime * 1.2 + lane * .9 + Math.sin(h * 8 + flowTime * .6) * .28;
                p = [Math.cos(a) * radius, .50 + 1.25 * h, Math.sin(a) * radius * .68 + .015]; weight = 1; warm = seed >= .88;
            } else if (group < 6) {
                var q = fract(seed + flowTime * (.19 + lane * .05)), branch = (Math.floor(lane * 12) - 5.5) / 5.5;
                p = [branch * (.02 + .20 * Math.pow(q, 1.4)) + .014 * Math.sin(q * 13 + branch * 4 + flowTime * .6), -.28 + .98 * q, .045 + .11 * (1 - q) * Math.sin(branch * 4 + q * 7)];
                weight = ease((p[1] - .35) / .35); warm = seed >= .40;
            } else if (group < 8) {
                q = fract(seed + flowTime * (.13 + lane * .05)); var u = 1 - q, side = group < 7 ? -1 : 1;
                p = [side * 1.056 * u * u * u + 3 * side * .944 * u * u * q + 3 * side * .144 * u * q * q, -.18 * u * u * u + 3 * .16 * u * u * q + 3 * .20 * u * q * q + .36 * q * q * q, .06 * u * u * u + 3 * .12 * u * u * q + 3 * .10 * u * q * q + .045 * q * q * q];
                warm = seed >= .72;
            } else {
                a = seed * TAU + flowTime * (1.4 + lane * .7) + .25 * Math.sin(flowTime * 1.8 + seed * 10); radius = .27 * Math.sqrt(lane) * (1 + self.level * .10);
                p = [Math.cos(a) * radius, .85 + Math.sin(a) * radius * .65 + .018 * Math.sin(flowTime * 3 + seed * 9) - self.level * .035, .08 + Math.sin(a * .7 + seed * 10) * .14]; weight = 1; warm = true;
            }
            var local = p.slice(), angle = mix(self.bodyYaw, self.headYaw, weight), pitch = self.pitch * mix(.12, 1, weight), pivotY = mix(.10, .53, weight), pivotZ = mix(0, -.035, weight);
            var y = (p[1] - pivotY) * Math.cos(pitch) - (p[2] - pivotZ) * Math.sin(pitch), z = (p[1] - pivotY) * Math.sin(pitch) + (p[2] - pivotZ) * Math.cos(pitch);
            p = [p[0] * Math.cos(angle) + z * Math.sin(angle), y + pivotY, -p[0] * Math.sin(angle) + z * Math.cos(angle) + pivotZ];
            return {id: id, pass: 'internal-energy', group: group, color: warm ? 'orange' : 'blue', local_position: local, position: p, projected_position: {x: self.width / 2 + p[0] / (6.2 - p[2]) * f * self.height / 2, y: self.height / 2 - (p[1] - .52) / (6.2 - p[2]) * f * self.height / 2}};
        }
        var ids = [0, 1, 4, 5, 6, 7, 8, 9, 101, 205, 318, 429];
        var samples = ids.filter(function (id) { return id < self.energyParticles.count; }).map(sample);
        this.headYaw = saved[0]; this.bodyYaw = saved[1]; this.pitch = saved[2]; this.level = saved[3]; this.mouthOpen = saved[4];
        return {enabled: flowTime > 5.6, count: this.energyParticles.count, pass: 'internal-energy', trail_count: 4, samples: samples};
    };
    Hologram.prototype.swarm = function (time) {
        time = Math.max(0, Number(time) || 0); var t = this.reduced ? 8 : this.compact ? time + 8 : time, self = this;
        var f = 1 / Math.tan(29 * Math.PI / 360) * Math.min(1, this.width / this.height / 1.18);
        function fract(v) { return v - Math.floor(v); }
        function sample(id) {
            var d = self.swarmParticles.data, i = id * 4, seed = d[i], lane = d[i + 1], depth = d[i + 2], group = d[i + 3], spread = fract(seed * 19.37 + lane * 43.13 + group * 11.7), a = lane * 6.2831853 + t * (.12 + depth * .14), p;
            if (group < .45) {
                var h = fract(seed + t * (.018 + depth * .016)), vertical = h * 2 - 1, radius = (.43 + .65 * spread) * Math.sqrt(Math.max(0, 1 - vertical * vertical));
                a += Math.sin(h * 7 + t * .24) * .30; radius *= .96 + .14 * Math.sin(a * 3 + h * 9 - t * .13) + .09 * Math.sin(h * 17 + a * 2 + t * .21);
                p = [Math.cos(a) * radius, 1.12 + vertical * (.72 + .30 * spread) + .06 * Math.sin(a * 2.1 + h * 17 + t * .37), Math.sin(a) * radius * .72];
            } else {
                var q = fract(seed + t * (.025 + depth * .018)), height = .60 - q * 1.26;
                radius = .27 + 1.12 * ease((q - .05) / .95) + spread * .40; a += Math.sin(q * 9 - t * .20) * .35;
                p = [Math.cos(a) * radius, height + Math.sin(a * 2 + t * .24) * .065, Math.sin(a) * (.28 + spread * .25)];
            }
            return {id: id, pass: 'outer-swarm', position: p, projected_position: {x: self.width / 2 + p[0] / (6.2 - p[2]) * f * self.height / 2, y: self.height / 2 - (p[1] - .52) / (6.2 - p[2]) * f * self.height / 2}, color: fract(lane * 7.13 + seed * 3.71) >= .55 ? 'orange' : 'blue', bright: depth >= .99965, size: (depth >= .986 ? 1.75 : 1.05) + (depth >= .99965 ? 2.4 + seed * 1.8 : 0)};
        }
        var ids = [], limit = Math.min(this.swarmCount, 150000);
        for (var i = 0; i < limit && ids.length < 12; i++) if (this.swarmParticles.data[i * 4 + 2] >= .99965) ids.push(i);
        return {enabled: t > ASSEMBLY_DURATION, pass: 'outer-swarm', capacity: this.swarmParticles.count, count: this.swarmCount, visibleMotes: this.swarmParticles.motes, submitted: this.lastSwarmDrawCount, drawCalls: t > ASSEMBLY_DURATION ? 1 : 0, trails: 1, adaptive: this.options.adaptiveParticles !== false, samples: ids.map(sample)};
    };
    Hologram.prototype.assembly = function (time) {
        // Bounded inspection of the exact procedural path. This does not render,
        // change playback, or introduce a debug surface into the product UI.
        time = Math.max(0, Number(time) || 0);
        var shaderTime = this.reduced ? 6 : this.compact ? Math.max(6, time + 6) : time;
        var saved = [this.headYaw, this.bodyYaw, this.pitch, this.level, this.mouthOpen];
        this.pose(time);
        var item = this, cameraY = .52;
        var f = 1 / Math.tan(29 * Math.PI / 360) * Math.min(1, this.width / this.height / 1.18);
        function camera(p) { return {x: item.width / 2 + p[0] / (6.2 - p[2]) * f * item.height / 2, y: item.height / 2 - (p[1] - cameraY) / (6.2 - p[2]) * f * item.height / 2}; }
        function posePoint(local, part) {
            var p = local.slice(), head = part > .5 && part < 2.5 ? 1 : 0;
            var mouth = Math.exp(-Math.pow((p[1] - .85) / .135, 2)) * Math.exp(-Math.pow(p[0] / .27, 4)) * ease((p[2] - .10) / .21) * head;
            var jaw = (1 - ease((p[1] - .69) / .29)) * ease((p[2] + .08) / .28) * head;
            p[1] -= item.level * .095 * jaw;
            p[1] += mouth * Math.sin(p[0] * 27 - shaderTime * 7) * item.level * .024;
            p[2] += mouth * (item.level * .045 + Math.sin(p[0] * 24 - shaderTime * 5) * item.level * .018);
            var neck = ease((p[1] - .08) / .57) * (1 - head), yaw = mix(item.bodyYaw, item.headYaw, head + neck * .36);
            var pivotY = mix(.10, .53, head), pivotZ = mix(0, -.035, head), pitch = item.pitch * mix(.12, 1, head);
            var y = (p[1] - pivotY) * Math.cos(pitch) - (p[2] - pivotZ) * Math.sin(pitch);
            var z = (p[1] - pivotY) * Math.sin(pitch) + (p[2] - pivotZ) * Math.cos(pitch);
            var result = [p[0] * Math.cos(yaw) + z * Math.sin(yaw), y + pivotY, -p[0] * Math.sin(yaw) + z * Math.cos(yaw) + pivotZ];
            if (!item.reduced) result[1] += Math.sin(shaderTime * 1.12) * .004;
            return result;
        }
        function sample(id) {
            var offset = id * 11, data = item.particles.vertices;
            var local = [data[offset], data[offset + 1], data[offset + 2]], part = data[offset + 8], seed = data[offset + 9];
            var arrival = arrivalFor(local, part) + (seed - .5) * .25;
            var flight = part > .5 ? 2.65 : 1.45 + Math.abs(local[0]) * .15, birth = Math.max(.48, arrival - flight);
            var q = ease((shaderTime - birth) / (arrival - birth)), target = posePoint(local, part), u = 1 - q;
            var c1 = [EMITTER[0] + .72 + Math.sin(seed * 51.7) * .08, EMITTER[1] + .18 + Math.cos(seed * 67.3) * .07, EMITTER[2] + Math.sin(seed * 31.1) * .08];
            var c2 = part > .5 ? [-1.75 + Math.cos(seed * 23.4) * .10, 1.98 + Math.sin(seed * 42.2) * .12, -.08 + Math.cos(seed * 17.4) * .09] : [target[0] * 1.25 - .12, target[1] + .68, target[2] + Math.sin(seed * 27.1) * .12];
            var position = [0, 0, 0];
            for (var axis = 0; axis < 3; axis++) position[axis] = EMITTER[axis] * u * u * u + 3 * c1[axis] * u * u * q + 3 * c2[axis] * u * q * q + target[axis] * q * q * q;
            var phase = Math.max(0, shaderTime - 5.6) / (10 + seed * 10) + seed; phase -= Math.floor(phase);
            var detached = ease((phase - .08) / .22) * (1 - ease((phase - .63) / .33)) * (seed >= .94 ? 1 : 0) * (item.fullViewport && !item.compact ? 1 : 0) * ease((shaderTime - 5.7) / 1.3) * (item.reduced ? 0 : 1);
            var far = [Math.sin(seed * 173.1) * 2.8 * item.width / item.height, Math.cos(seed * 137.2) * 2.1 + .52, Math.sin(seed * 71.9) * .9];
            var noise = item.reduced ? [0, 0, 0] : [Math.sin(shaderTime * .8 + seed * 63) * .009, Math.cos(shaderTime * .6 + seed * 43) * .009, Math.sin(shaderTime * .7 + seed * 31) * .009];
            for (axis = 0; axis < 3; axis++) { position[axis] = mix(position[axis], far[axis], detached) + noise[axis] * q; target[axis] += noise[axis]; }
            return {id: id, birth: birth, arrival: arrival, local_target: local, target: target, position: position, projected_position: camera(position), active: shaderTime >= birth, arrived: shaderTime >= arrival && detached < .00001, detached: detached};
        }
        if (!this.surfaceArrival) {
            this.surfaceArrival = {headFirst: Infinity, headLast: 0, torsoFirst: Infinity, torsoLast: 0};
            for (var i = 0; i < this.mesh.vertices.length; i += 9) {
                var p = [this.mesh.vertices[i], this.mesh.vertices[i + 1], this.mesh.vertices[i + 2]], part = this.mesh.vertices[i + 8], value = arrivalFor(p, part), prefix = part > .5 ? 'head' : 'torso';
                this.surfaceArrival[prefix + 'First'] = Math.min(this.surfaceArrival[prefix + 'First'], value);
                this.surfaceArrival[prefix + 'Last'] = Math.max(this.surfaceArrival[prefix + 'Last'], value);
            }
        }
        var ids = [0, 1, 2, 3, 4, 21, 83, 199, 405, 717, 1133, 2003, 3001, 4209, 6059, 7999, 10999, 13999];
        var particles = ids.filter(function (id) { return id < item.particles.count - 1; }).map(sample);
        this.headYaw = saved[0]; this.bodyYaw = saved[1]; this.pitch = saved[2]; this.level = saved[3]; this.mouthOpen = saved[4];
        return {duration: ASSEMBLY_DURATION, emitter: EMITTER.slice(), emitter_projected: camera(EMITTER), particles: particles, surfaceArrival: this.surfaceArrival};
    };
    Hologram.prototype.destroy = function () {
        if (this.dead) return; this.dead = true;
        this.releasePortrait();
        if (this.artworkImage) {
            this.artworkImage.removeEventListener('error', this.onArtworkError);
            this.artworkImage.removeEventListener('load', this.onArtworkLoad);
        }
        if (this.resizeObserver) this.resizeObserver.disconnect(); else window.removeEventListener('resize', this.onResize);
        if (this.intersectionObserver) this.intersectionObserver.disconnect();
        this.canvas.removeEventListener('webglcontextlost', this.onLost); this.canvas.removeEventListener('webglcontextrestored', this.onRestored);
        if (media) { if (media.removeEventListener) media.removeEventListener('change', this.onMotion); else if (media.removeListener) media.removeListener(this.onMotion); }
        if (!this.lost) {
            var gl = this.gl; gl.deleteBuffer(this.vertexBuffer); gl.deleteBuffer(this.indexBuffer); gl.deleteBuffer(this.particleBuffer); gl.deleteBuffer(this.quadBuffer); gl.deleteBuffer(this.energyBuffer); gl.deleteBuffer(this.swarmBuffer); gl.deleteBuffer(this.ringBuffer);
            gl.deleteProgram(this.program); gl.deleteProgram(this.postProgram); gl.deleteProgram(this.blurProgram); gl.deleteProgram(this.energyProgram); gl.deleteProgram(this.swarmProgram); gl.deleteProgram(this.ringProgram); gl.deleteTexture(this.sceneTexture); gl.deleteFramebuffer(this.sceneFramebuffer); gl.deleteRenderbuffer(this.sceneDepth);
            if (this.bloomTargets) this.bloomTargets.forEach(function (target) { gl.deleteTexture(target.texture); gl.deleteFramebuffer(target.framebuffer); });
        }
        items = items.filter(function (item) { return item !== this; }, this);
        if (this.canvas.__goHologram === this) delete this.canvas.__goHologram;
        if (!items.some(moving) && frame) { window.cancelAnimationFrame(frame); frame = 0; }
    };
    window.GoHologram = {
        version: 7,
        mount: function (canvas, options) {
            if (!canvas || typeof canvas.getContext !== 'function') return null;
            if (canvas.__goHologram && !canvas.__goHologram.dead) return canvas.__goHologram.api;
            var item;
            try { item = new Hologram(canvas, options || {}); }
            catch (error) {
                canvas.dataset.goHologramState = 'unavailable';
                canvas.dispatchEvent(new window.CustomEvent('go:hologram-error', {detail: {message: String(error.message || error)}}));
                return null;
            }
            var api = {
                destroy: function () { item.destroy(); },
                setArtwork: function (value) { item.setArtwork(value); },
                setSpeaking: function (value) { item.speaking = !!value; if (canvas.parentElement) canvas.parentElement.dataset.speaking = item.speaking ? 'true' : 'false'; if (!value) { item.voiceLevel = 0; item.explicitVoice = false; } wake(); },
                setVoiceLevel: function (value) { item.voiceLevel = clamp(Number(value) || 0, 0, 1); item.explicitVoice = true; if (canvas.parentElement) canvas.parentElement.style.setProperty('--hologram-voice-level', String(item.voiceLevel)); wake(); },
                setAlert: function (value) { item.alert = !!value; if (canvas.parentElement) canvas.parentElement.dataset.alert = item.alert ? 'true' : 'false'; wake(); },
                setGaze: function (x, y) { item.gazeX = clamp(Number(x) || 0, -1, 1); item.gazeY = clamp(Number(y) || 0, -1, 1); },
                setVisible: function (value) { item.visible = !!value; item.last = 0; if (active(item)) item.render(item.reduced ? 6 : item.elapsed); wake(); },
                assembly: function (time) { return item.assembly(time); },
                energy: function (time) { return item.internalFlow(time); },
                swarm: function (time) { return item.swarm(time); },
                diagnostics: function () { return {version: 7, artwork: item.artwork, portraitAnimated:!!item.portraitReady,portraitReady:!!item.portraitReady,portraitParticles:item.portraitParticles||0,assemblyProgress:item.assemblyProgress||0,blink:item.blink||0,pitch:item.pitch, renderer: 'webgl', vertices: item.mesh.vertexCount, particles: item.particles.count, swarmParticles: item.swarmCount, swarmCapacity: item.swarmParticles.count, swarmVisibleMotes: item.swarmParticles.motes, swarmTier: item.compact ? 'compact' : item.swarmCount < item.swarmLimit ? 'adaptive' : item.artwork ? 'artwork-depth' : item.swarmLimit < 65536 ? 'mobile' : 'desktop', swarmSubmitted: item.lastSwarmDrawCount, width: item.canvas.width, height: item.canvas.height, yaw: item.headYaw, mouthOpen: item.mouthOpen}; }
            };
            item.api = api; canvas.__goHologram = item; items.push(item); wake(); return api;
        }
    };
})(window, document);
