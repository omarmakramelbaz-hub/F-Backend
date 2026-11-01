/* GO hologram v2: native three-dimensional surfaces, contour shaders and a moving point cloud. */
(function (window, document) {
    'use strict';
    if (window.GoHologram && window.GoHologram.version >= 2) return;

    var items = [], frame = 0, queued = false, TAU = Math.PI * 2;
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
                // Keep the sixty-second glance tied to visible wall time even on a slow GPU.
                if (item.last) item.elapsed += Math.max(0, (now - item.last) / 1000);
                item.last = now;
                item.render(item.elapsed);
            }
        });
        wake();
    }
    function visibility() {
        items.forEach(function (item) {
            item.last = 0;
            if (active(item)) item.render(item.reduced ? 3 : item.elapsed);
        });
        if (document.hidden && frame) { window.cancelAnimationFrame(frame); frame = 0; }
        wake();
    }
    document.addEventListener('visibilitychange', visibility);
    function randomGenerator(seed) { return function () { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; }; }

    // All meshes are constructed here from smooth mathematical surfaces. UVs describe
    // the physical surface; no raster assets are involved in construction or shading.
    function headPoint(v, phi) {
        var theta = v * Math.PI, c = Math.cos(theta), s = Math.sin(theta);
        var jaw = 1 - .27 * ease((-c - .05) / .95), front = Math.max(0, Math.cos(phi));
        var x = .465 * s * jaw * Math.sin(phi);
        var y = 1.18 + .675 * c;
        var z = .392 * s * Math.cos(phi) * (1 - .045 * Math.max(0, -c));
        // A continuous forehead/cheek/chin profile with no abrupt profile knots.
        z += Math.pow(front, 8) * (.024 * Math.exp(-Math.pow((y - 1.08) / .17, 2)) + .030 * Math.exp(-Math.pow((y - .72) / .12, 2)));
        return [x, y, z];
    }
    function bodyPoint(v, phi) {
        var baseY = .64 - v * 1.86;
        var shoulder = 1 / (1 + Math.exp((baseY + .045) / .125)), radius = .202 + 1.29 * shoulder;
        var depth = .185 + .30 * ease((v - .19) / .64);
        var c = Math.cos(phi), y = baseY + .10 * c * shoulder;
        return [radius * Math.sin(phi), y, depth * c];
    }
    function earPoint(side) {
        return function (v, phi) {
            var theta = v * Math.PI, s = Math.sin(theta), c = Math.cos(phi);
            return [side * .440 + .045 * s * Math.sin(phi), 1.01 + .105 * Math.cos(theta), -.018 + .065 * s * c];
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
            if (head) { p[0] += p[0] / .465 * outward; p[1] += (p[1] - 1.18) / .675 * outward; p[2] += p[2] / .392 * outward; }
            else { p[0] += p[0] / 1.545 * outward; p[2] += (p[2] < 0 ? -1 : 1) * outward; }
            var dx = (random() - .5) * radial, dy = (random() - .5) * radial, dz = (random() - .5) * radial;
            result.push(p[0] + dx, p[1] + dy, p[2] + dz, 0, 0, 1, phi / TAU, v, head ? 1 : 0, random(), .55 + random() * 1.05);
        }
        return {vertices: new Float32Array(result), count: count};
    }

    var VERTEX = [
        'precision highp float;',
        'attribute vec3 aPosition; attribute vec3 aNormal; attribute vec2 aUV; attribute float aPart; attribute float aPhase; attribute float aSize;',
        'uniform mat4 uProjection; uniform mat4 uView; uniform float uHeadYaw; uniform float uBodyYaw; uniform float uPitch;',
        'uniform float uTime; uniform float uVoice; uniform float uPointPass; uniform float uExpand; uniform float uDpr; uniform float uCompact; uniform float uAspect; uniform float uScatter; uniform float uReduced;',
        'varying vec3 vNormal; varying vec3 vPosition; varying vec3 vLocal; varying vec2 vUV; varying float vPart; varying float vAlpha; varying float vPhase;',
        'mat3 yaw(float a){float c=cos(a),s=sin(a);return mat3(c,0.,-s,0.,1.,0.,s,0.,c);}',
        'mat3 pitch(float a){float c=cos(a),s=sin(a);return mat3(1.,0.,0.,0.,c,s,0.,-s,c);}',
        'void main(){',
        ' vec3 p=aPosition,n=aNormal; vLocal=aPosition; vUV=aUV; vPart=aPart; vPhase=aPhase;',
        ' float head=step(.5,aPart);',
        // Real lower-face vertex displacement: jaw opening and flowing lips/waves.
        ' float mouth=exp(-pow((p.y-.965)/.135,2.))*exp(-pow(p.x/.27,4.))*smoothstep(.10,.31,p.z)*head;',
        ' float jaw=(1.-smoothstep(.82,1.08,p.y))*smoothstep(-.08,.20,p.z)*head;',
        ' p.y-=uVoice*.095*jaw;',
        ' p.y+=mouth*sin(p.x*27.-uTime*7.)*uVoice*.024;',
        ' p.z+=mouth*(uVoice*.045+sin(p.x*24.-uTime*5.)*uVoice*.018);',
        ' p+=n*uExpand;',
        ' float neck=smoothstep(.08,.65,p.y)*(1.-head); float angle=mix(uBodyYaw,uHeadYaw,head+neck*.36);',
        ' vec3 pivot=mix(vec3(0.,.10,0.),vec3(0.,.53,-.035),head);',
        ' mat3 rotation=yaw(angle)*pitch(uPitch*mix(.12,1.,head));',
        ' p=rotation*(p-pivot)+pivot; n=rotation*n;',
        ' p.y+=sin(uTime*1.12)*.004*(1.-uReduced);',
        ' float intro=smoothstep(0.,2.2,uTime); vAlpha=1.;',
        ' if(uPointPass>.5){',
        '   float delay=aPhase*.72; float assemble=smoothstep(delay,delay+1.4,uTime);',
        '   float phase=fract(uTime/(10.+aPhase*10.)+aPhase);',
        '   float detach=smoothstep(.08,.30,phase)*(1.-smoothstep(.63,.96,phase));',
        '   detach*=step(.85,aPhase)*uScatter*intro*(1.-uReduced);',
        '   float spread=max(1.-assemble,detach);',
        '   vec3 farPoint=vec3(sin(aPhase*173.1)*2.8*uAspect,cos(aPhase*137.2)*2.1+.2,sin(aPhase*71.9)*.9);',
        '   if(uCompact>.5)farPoint=vec3(sin(aPhase*173.1)*.15,-.78+cos(aPhase*173.1)*.1,.52);',
        '   p=mix(p,farPoint,spread);',
        '   p+=vec3(sin(uTime*.8+aPhase*63.),cos(uTime*.6+aPhase*43.),sin(uTime*.7+aPhase*31.))*.013*(1.-uReduced);',
        '   vAlpha=.48+.32*sin(aPhase*67.+uTime*1.7); vAlpha*=mix(1.,.22,detach);',
        ' }',
        ' vPosition=p; vNormal=n;',
        ' gl_Position=uProjection*uView*vec4(p,1.);',
        ' gl_PointSize=clamp(aSize*uDpr*(uCompact>.5?2.0:2.6),1.5,5.5);',
        '}'
    ].join('\n');
    var FRAGMENT = [
        '#extension GL_OES_standard_derivatives : enable',
        'precision highp float;',
        'uniform float uTime; uniform float uVoice; uniform float uAlert; uniform float uGlowPass; uniform float uPointPass; uniform float uCompact; uniform float uReduced;',
        'varying vec3 vNormal; varying vec3 vPosition; varying vec3 vLocal; varying vec2 vUV; varying float vPart; varying float vAlpha; varying float vPhase;',
        'float contour(float value,float width){float d=abs(fract(value+.5)-.5);float pixel=max(fwidth(value),.0008);return exp(-pow(d/(pixel*width),2.));}',
        'void main(){',
        ' vec3 eye=normalize(vec3(0.,.20,6.2)-vPosition); vec3 normal=normalize(vNormal);',
        ' float facing=abs(dot(normal,eye)); float rim=pow(1.-facing,2.0);',
        ' float head=step(.5,vPart); float ear=step(1.5,vPart);',
        ' float front=smoothstep(.06,.32,vLocal.z)*head;',
        ' float lower=exp(-pow((vLocal.y-.98)/.25,2.))*exp(-pow(vLocal.x/.35,4.))*front*(1.-ear);',
        ' vec3 cyan=vec3(.01,.73,1.0), ice=vec3(.42,.95,1.0);',
        ' float wave=sin(vLocal.x*28.-uTime*(2.5+uVoice*5.))*.11*(.25+uVoice)*lower;',
        ' float latitude=vUV.y*mix(48.,43.,head)+wave;',
        ' float chest=1.-smoothstep(-.10,.23,vLocal.y);',
        ' float pectoral=length(vec2((sqrt(vLocal.x*vLocal.x+.0049)-.79)*1.12,(vLocal.y+1.30)*1.25))*27.;',
        ' float stream=vUV.x*71.+sin(vUV.y*11.)*.25;',
        ' float longitude=mix(stream,vUV.x*58.,head);',
        ' float lat=contour(latitude,.58), latGlow=contour(latitude,2.0);',
        ' float lon=mix(contour(longitude,.50),contour(pectoral,.55),chest*(1.-head));',
        ' float lonGlow=mix(contour(longitude,1.6),contour(pectoral,1.6),chest*(1.-head));',
        ' float neural=exp(-pow(vLocal.x/.14,2.))*chest*(1.-head);',
        ' float neuralPhase=vLocal.x*27.+sin(vLocal.y*11.)*.14;',
        ' lon=mix(lon,contour(neuralPhase,.45)*.50,neural*.85);',
        ' lonGlow=mix(lonGlow,contour(neuralPhase,1.5)*.15,neural*.85);',
        ' float collar=contour((vLocal.y+.13+.18*pow(abs(vLocal.x)-.66,2.))*29.,.65)*(1.-smoothstep(.28,.45,vLocal.y))*smoothstep(-.42,-.24,vLocal.y);',
        ' float lines=mix(lon*.70+lat*.035+collar*.28,lat*.95+lon*.06,head);',
        ' float lineGlow=mix(lonGlow*.15+latGlow*.035,latGlow*.18,head);',
        ' if(ear>.5){lines=contour(vUV.y*17.,.65)*.85;lineGlow=contour(vUV.y*17.,2.)*.18;}',
        ' float intersection=lat*lon; float current=pow(.5+.5*sin(vUV.y*39.+vUV.x*76.-uTime*2.1),18.);',
        ' vec3 color=cyan*(lines*.8+lineGlow+rim*.9)+ice*(lines*.17+intersection*.5);',
        ' float softFill=.018+pow(max(0.,dot(normal,normalize(vec3(-.5,1.,1.)))),2.)*.035;',
        ' color+=cyan*softFill;',
        ' vec3 amber=vec3(1.0,.38,.008), yellow=vec3(1.,.91,.22);',
        ' float warmth=clamp(lower*1.65,0.,1.);',
        ' float core=exp(-pow((vLocal.y-.97)/.18,2.)-pow(vLocal.x/.23,2.))*front;',
        ' vec3 hot=amber*(.55+latGlow*.70+lat*.90)+yellow*(core*.34+lat*(.55+uVoice*.60));',
        ' color=mix(color,hot,warmth);',
        ' float trunk=(1.-head)*(1.-smoothstep(.48,.68,vLocal.y))*smoothstep(-1.1,-.70,vLocal.y);',
        ' float veins=contour(vLocal.x*17.+sin(vLocal.y*13.)*.7, .8)*exp(-pow(vLocal.x/(.075+.07*max(0.,vLocal.y+.6)),2.))*trunk;',
        ' color+=vec3(1.,.57,.06)*veins*(.40+uVoice*.35);',
        ' float nucleus=exp(-dot((vLocal-vec3(0.,-.81,.48))*vec3(15.,20.,18.),(vLocal-vec3(0.,-.81,.48))*vec3(15.,20.,18.)))*(1.-head);',
        ' color+=vec3(.45,1.,1.)*nucleus*2.5;',
        ' color+=ice*intersection*current*.5;',
        ' float alpha=clamp(lines*.80+lineGlow*.30+rim*.70+softFill+warmth*.66+veins*.40+nucleus,0.,.98);',
        ' if(uGlowPass>.5){alpha=pow(1.-facing,3.)*.075;color=mix(vec3(.0,.42,1.),vec3(.0,.85,1.),rim);}',
        ' if(uPointPass>.5){',
        '   float d=length(gl_PointCoord-.5)*2.; float edge=exp(-d*d*4.)*(1.-smoothstep(.72,1.,d));',
        '   color=mix(vec3(.05,.85,1.),vec3(1.,.63,.08),lower*.8); color+=vec3(.28,.55,.58)*(1.-d);',
        '   alpha=edge*vAlpha*(.72+uAlert*.25);',
        ' } else {',
        '   float reveal=smoothstep(.10,1.8,uTime)*smoothstep(vLocal.y-1.,vLocal.y+.30,-1.5+uTime*2.2);',
        '   alpha*=uReduced>.5?1.:reveal;',
        ' }',
        ' float fade=smoothstep(-1.08,-.35,vLocal.y); alpha*=mix(fade,1.,head);',
        ' if(alpha<.001)discard;',
        ' color*=1.+uAlert*.13;',
        ' gl_FragColor=vec4(color*alpha,alpha);',
        '}'
    ].join('\n');
    var POST_VERTEX = 'attribute vec2 aQuad;varying vec2 vUV;void main(){vUV=aQuad*.5+.5;gl_Position=vec4(aQuad,0.,1.);}';
    var POST_FRAGMENT = [
        'precision highp float;varying vec2 vUV;uniform sampler2D uScene;uniform vec2 uTexel;',
        'void main(){',
        ' vec4 base=texture2D(uScene,vUV);vec4 bloom=base*.25;',
        ' vec2 d=uTexel;',
        ' bloom+=texture2D(uScene,vUV+vec2(d.x,0.))*.125;',
        ' bloom+=texture2D(uScene,vUV-vec2(d.x,0.))*.125;',
        ' bloom+=texture2D(uScene,vUV+vec2(0.,d.y))*.125;',
        ' bloom+=texture2D(uScene,vUV-vec2(0.,d.y))*.125;',
        ' bloom+=texture2D(uScene,vUV+d)*.0625;',
        ' bloom+=texture2D(uScene,vUV-d)*.0625;',
        ' bloom+=texture2D(uScene,vUV+vec2(d.x,-d.y))*.0625;',
        ' bloom+=texture2D(uScene,vUV+vec2(-d.x,d.y))*.0625;',
        ' vec3 color=base.rgb+bloom.rgb*.52;',
        ' gl_FragColor=vec4(color,max(base.a,bloom.a*.90));',
        '}'
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
        var f = 1 / Math.tan(35 * Math.PI / 360), near = .1, far = 40, matrix = new Float32Array(16);
        matrix[0] = f / aspect; matrix[5] = f; matrix[10] = (far + near) / (near - far); matrix[11] = -1; matrix[14] = 2 * far * near / (near - far);
        return matrix;
    }
    function viewMatrix(fullViewport) {
        var matrix = new Float32Array(16); matrix[0] = matrix[5] = matrix[10] = matrix[15] = 1;
        matrix[13] = fullViewport ? -.08 : -.20; matrix[14] = -6.2;
        return matrix;
    }
    function glance(time) {
        if (time < 60) return 0;
        var phase = time % 60;
        if (phase < 2.5) return -Math.PI / 2 * ease(phase / 2.5);
        if (phase < 6) return mix(-Math.PI / 2, Math.PI / 2, ease((phase - 2.5) / 3.5));
        if (phase < 9) return Math.PI / 2 * (1 - ease((phase - 6) / 3));
        return 0;
    }

    function Hologram(canvas, options) {
        this.canvas = canvas; this.options = options; this.compact = !!options.compact;
        this.fullViewport = !!options.fullViewport || (!!options.background && !this.compact);
        this.interval = 1000 / (this.compact ? 15 : 30);
        this.visible = true; this.dead = false; this.lost = false; this.last = 0; this.elapsed = 0;
        this.width = 0; this.height = 0; this.speaking = false; this.alert = false;
        this.voiceLevel = 0; this.explicitVoice = false; this.mouthOpen = 0; this.gazeX = 0; this.gazeY = 0;
        this.headYaw = 0; this.bodyYaw = 0; this.pitch = 0; this.px = 0; this.py = 0;
        this.reduced = typeof options.reducedMotion === 'boolean' ? options.reducedMotion : !!(media && media.matches);
        this.gl = canvas.getContext('webgl', {alpha: true, antialias: true, premultipliedAlpha: true, depth: true, powerPreference: this.compact ? 'low-power' : 'high-performance'});
        if (!this.gl) throw new Error('GO hologram requires WebGL');
        this.mesh = buildMesh(this.compact); this.particles = buildParticles(this.compact);
        this.prepare();
        var self = this;
        this.onResize = function () { self.resize(); };
        this.onMotion = function () {
            if (typeof options.reducedMotion !== 'boolean') self.reduced = !!(media && media.matches);
            self.last = 0; if (active(self)) self.render(self.reduced ? 3 : self.elapsed); wake();
        };
        this.onLost = function (event) { event.preventDefault(); self.lost = true; canvas.dataset.goHologramState = 'recovering'; };
        this.onRestored = function () { if (self.dead) return; self.lost = false; self.prepare(); self.resize(); wake(); };
        this.onPointer = function (event) {
            var box = canvas.getBoundingClientRect(); self.gazeX = clamp((event.clientX - box.left) / box.width * 2 - 1, -1, 1); self.gazeY = clamp((event.clientY - box.top) / box.height * 2 - 1, -1, 1);
        };
        canvas.addEventListener('webglcontextlost', this.onLost);
        canvas.addEventListener('webglcontextrestored', this.onRestored);
        if (options.interactive && !this.compact) document.addEventListener('pointermove', this.onPointer, {passive: true});
        if (window.ResizeObserver) { this.resizeObserver = new window.ResizeObserver(this.onResize); this.resizeObserver.observe(canvas); } else window.addEventListener('resize', this.onResize);
        if (window.IntersectionObserver) {
            this.intersectionObserver = new window.IntersectionObserver(function (entries) { self.visible = entries[0].isIntersecting; self.last = 0; if (active(self)) self.render(self.reduced ? 3 : self.elapsed); wake(); }, {threshold: 0});
            this.intersectionObserver.observe(canvas);
        }
        if (media) { if (media.addEventListener) media.addEventListener('change', this.onMotion); else if (media.addListener) media.addListener(this.onMotion); }
        canvas.setAttribute('aria-hidden', 'true'); canvas.dataset.goHologramRenderer = 'webgl-geometry-v2'; this.resize();
    }
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
        this.quadBuffer = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
        gl.getExtension('OES_standard_derivatives'); gl.enable(gl.DEPTH_TEST); gl.enable(gl.CULL_FACE); gl.cullFace(gl.BACK);
        gl.clearColor(0, 0, 0, 0); gl.clearDepth(1);
        this.canvas.dataset.goHologramState = 'ready';
    };
    Hologram.prototype.allocateTarget = function () {
        var gl = this.gl;
        if (this.sceneTexture) gl.deleteTexture(this.sceneTexture);
        if (this.sceneFramebuffer) gl.deleteFramebuffer(this.sceneFramebuffer);
        if (this.sceneDepth) gl.deleteRenderbuffer(this.sceneDepth);
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
        gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    };
    Hologram.prototype.resize = function () {
        if (this.dead || this.lost) return;
        var box = this.canvas.getBoundingClientRect(); this.width = Math.round(box.width); this.height = Math.round(box.height);
        if (this.width < 2 || this.height < 2) return;
        this.ratio = Math.max(1, Math.min(2, window.devicePixelRatio || 1, Math.sqrt(4000000 / (this.width * this.height))));
        this.canvas.width = Math.round(this.width * this.ratio); this.canvas.height = Math.round(this.height * this.ratio);
        this.allocateTarget();
        this.projection = projection(this.width / this.height); this.view = viewMatrix(this.fullViewport);
        this.gl.viewport(0, 0, this.canvas.width, this.canvas.height); this.last = 0;
        this.render(this.reduced ? 3 : this.elapsed); wake();
    };
    Hologram.prototype.pose = function (time) {
        var turn = this.reduced ? 0 : glance(time), looking = time >= 60 && time % 60 <= 9;
        var idle = this.reduced || looking ? 0 : Math.sin(time * .29) * .032 + this.gazeX * .10;
        this.headYaw = turn + idle;
        this.bodyYaw = turn * .08 + (this.reduced ? 0 : Math.sin(time * .22) * .009);
        this.pitch = this.reduced ? 0 : Math.sin(time * .42) * .014 + this.gazeY * .035 * (looking ? 0 : 1);
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
    Hologram.prototype.render = function (time) {
        if (this.dead || this.lost || this.width < 2 || this.height < 2) return;
        this.pose(time);
        var gl = this.gl, u = this.uniforms; gl.useProgram(this.program);
        gl.bindFramebuffer(gl.FRAMEBUFFER, this.sceneFramebuffer);
        gl.uniformMatrix4fv(u.uProjection, false, this.projection); gl.uniformMatrix4fv(u.uView, false, this.view);
        gl.uniform1f(u.uTime, this.reduced ? 3 : Math.max(0, time)); gl.uniform1f(u.uHeadYaw, this.headYaw); gl.uniform1f(u.uBodyYaw, this.bodyYaw); gl.uniform1f(u.uPitch, this.pitch);
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
        var shells = this.compact ? [.018] : [.012, .035];
        for (var i = 0; i < shells.length; i++) { gl.uniform1f(u.uExpand, shells[i]); gl.drawElements(gl.TRIANGLES, this.mesh.indices.length, gl.UNSIGNED_SHORT, 0); }
        gl.uniform1f(u.uExpand, 0); gl.uniform1f(u.uGlowPass, 0); gl.uniform1f(u.uPointPass, 1); gl.disable(gl.CULL_FACE);
        this.bind(true); gl.drawArrays(gl.POINTS, 0, this.particles.count);
        gl.depthMask(true); gl.disable(gl.BLEND);
        // A native-resolution crisp pass plus a small procedural light bloom.
        gl.bindFramebuffer(gl.FRAMEBUFFER, null); gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
        gl.disable(gl.DEPTH_TEST); gl.disable(gl.CULL_FACE); gl.useProgram(this.postProgram);
        gl.bindBuffer(gl.ARRAY_BUFFER, this.quadBuffer); gl.enableVertexAttribArray(this.quadAttribute);
        gl.vertexAttribPointer(this.quadAttribute, 2, gl.FLOAT, false, 0, 0);
        gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, this.sceneTexture);
        gl.uniform1i(this.sceneUniform, 0); gl.uniform2f(this.texelUniform, this.ratio / this.canvas.width, this.ratio / this.canvas.height);
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
    };
    Hologram.prototype.project = function (x, y, z, group) {
        var yaw = group ? this.headYaw : this.bodyYaw, pivotY = group ? .53 : .10, pivotZ = group ? -.035 : 0;
        var cy = Math.cos(yaw), sy = Math.sin(yaw), pitch = this.pitch * (group ? 1 : .12), cp = Math.cos(pitch), sp = Math.sin(pitch);
        var yy = (y - pivotY) * cp - (z - pivotZ) * sp;
        var zz = (y - pivotY) * sp + (z - pivotZ) * cp;
        var xx = x * cy + zz * sy, depth = -x * sy + zz * cy + pivotZ;
        var f = 1 / Math.tan(35 * Math.PI / 360), cameraY = this.fullViewport ? .08 : .20;
        this.px = this.width / 2 + xx / (6.2 - depth) * f * this.height / 2;
        this.py = this.height / 2 - (yy + pivotY - cameraY) / (6.2 - depth) * f * this.height / 2;
        return {x: this.px, y: this.py, z: depth};
    };
    Hologram.prototype.destroy = function () {
        if (this.dead) return; this.dead = true;
        if (this.resizeObserver) this.resizeObserver.disconnect(); else window.removeEventListener('resize', this.onResize);
        if (this.intersectionObserver) this.intersectionObserver.disconnect();
        this.canvas.removeEventListener('webglcontextlost', this.onLost); this.canvas.removeEventListener('webglcontextrestored', this.onRestored);
        if (this.options.interactive && !this.compact) document.removeEventListener('pointermove', this.onPointer);
        if (media) { if (media.removeEventListener) media.removeEventListener('change', this.onMotion); else if (media.removeListener) media.removeListener(this.onMotion); }
        if (!this.lost) {
            var gl = this.gl; gl.deleteBuffer(this.vertexBuffer); gl.deleteBuffer(this.indexBuffer); gl.deleteBuffer(this.particleBuffer); gl.deleteBuffer(this.quadBuffer);
            gl.deleteProgram(this.program); gl.deleteProgram(this.postProgram); gl.deleteTexture(this.sceneTexture); gl.deleteFramebuffer(this.sceneFramebuffer); gl.deleteRenderbuffer(this.sceneDepth);
        }
        items = items.filter(function (item) { return item !== this; }, this);
        if (this.canvas.__goHologram === this) delete this.canvas.__goHologram;
        if (!items.some(moving) && frame) { window.cancelAnimationFrame(frame); frame = 0; }
    };
    window.GoHologram = {
        version: 2,
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
                setSpeaking: function (value) { item.speaking = !!value; if (!value) { item.voiceLevel = 0; item.explicitVoice = false; } wake(); },
                setVoiceLevel: function (value) { item.voiceLevel = clamp(Number(value) || 0, 0, 1); item.explicitVoice = true; wake(); },
                setAlert: function (value) { item.alert = !!value; wake(); },
                setGaze: function (x, y) { item.gazeX = clamp(Number(x) || 0, -1, 1); item.gazeY = clamp(Number(y) || 0, -1, 1); },
                setVisible: function (value) { item.visible = !!value; item.last = 0; if (active(item)) item.render(item.reduced ? 3 : item.elapsed); wake(); },
                diagnostics: function () { return {version: 2, renderer: 'webgl', vertices: item.mesh.vertexCount, particles: item.particles.count, width: item.canvas.width, height: item.canvas.height, yaw: item.headYaw, mouthOpen: item.mouthOpen}; }
            };
            item.api = api; canvas.__goHologram = item; items.push(item); wake(); return api;
        }
    };
})(window, document);
