/* GO's decorative hologram. Local Canvas 2D geometry; no network, video or WebGL dependency. */
(function (window, document) {
    'use strict';
    if (window.GoHologram && window.GoHologram.version === 1) return;

    var instances = [], frame = 0, queued = false, imageCache = Object.create(null);
    var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var TAU = Math.PI * 2;

    function smooth(v) { v = Math.max(0, Math.min(1, v)); return v * v * (3 - 2 * v); }
    function mix(a, b, t) { return a + (b - a) * t; }
    function eligible(item) {
        return !item.dead && item.visible && item.canvas.isConnected && !document.hidden && item.width > 1 && item.height > 1;
    }
    function animate(item) { return eligible(item) && !item.reduced; }
    function wake() {
        if (frame || queued || !instances.some(animate)) return;
        queued = true;
        // DashboardSPA scopes page callbacks. A microtask schedules this shared shell loop
        // after that scope exits, so leaving the home page cannot stop the sidebar robot.
        Promise.resolve().then(function () {
            queued = false;
            if (!frame && instances.some(animate)) frame = window.requestAnimationFrame(tick);
        });
    }
    function tick(now) {
        frame = 0;
        instances.slice().forEach(function (item) {
            if (!item.canvas.isConnected) { item.destroy(); return; }
            if (!animate(item)) { item.last = 0; return; }
            if (!item.last || now - item.last >= item.interval - 1) {
                var dt = item.last ? Math.min(.1, (now - item.last) / 1000) : 0;
                item.last = now;
                item.elapsed += dt;
                item.speech = mix(item.speech, item.speaking ? 1 : 0, Math.min(1, dt * 8));
                item.alarm = mix(item.alarm, item.alert ? 1 : 0, Math.min(1, dt * 5));
                item.render(item.elapsed);
            }
        });
        wake();
    }
    function refreshVisibility() {
        instances.forEach(function (item) {
            item.last = 0;
            if (eligible(item)) item.render(item.reduced ? 0 : item.elapsed);
        });
        if (document.hidden && frame) { window.cancelAnimationFrame(frame); frame = 0; }
        wake();
    }
    document.addEventListener('visibilitychange', refreshVisibility);

    function seeded(seed) {
        return function () { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; };
    }
    function loadArtwork(source, notify) {
        var state = imageCache[source];
        if (!state) {
            state = {image: new window.Image(), ready: false, failed: false, listeners: []};
            imageCache[source] = state;
            state.image.decoding = 'async';
            state.image.onload = function () {
                state.ready = state.image.naturalWidth > 0;
                state.failed = !state.ready;
                state.listeners.splice(0).forEach(function (listener) { listener(state); });
            };
            state.image.onerror = function () {
                state.failed = true;
                state.listeners.splice(0).forEach(function (listener) { listener(state); });
            };
            state.image.src = source;
        }
        if (state.ready || state.failed) notify(state); else state.listeners.push(notify);
        return state;
    }
    function profile(v) {
        var stops = [[-1, 0], [-.95, .39], [-.8, .75], [-.6, .94], [-.36, 1], [-.08, .99], [.2, .94], [.45, .83], [.7, .65], [.9, .38], [1, .09]];
        for (var i = 1; i < stops.length; i++) {
            if (v <= stops[i][0]) return mix(stops[i - 1][1], stops[i][1], smooth((v - stops[i - 1][0]) / (stops[i][0] - stops[i - 1][0])));
        }
        return .09;
    }
    function line(points, group) { return {points: new Float32Array(points), group: group || 0}; }
    function geometry(compact) {
        var head = [], torso = [], outline = [], ears = [], sparks = [], nerves = [], random = seeded(4127);
        var rows = compact ? 27 : 43, steps = compact ? 34 : 56;
        for (var row = 0; row <= rows; row++) {
            var v = -.985 + row / rows * 1.97, width = profile(v) * 132, points = [];
            for (var col = 0; col <= steps; col++) {
                var angle = col / steps * Math.PI, front = Math.sin(angle);
                points.push(Math.cos(angle) * width, -178 + v * 174 - front * 9, front * width * .73);
            }
            head.push(line(points, 1));
        }
        // The silhouette follows the cheek and narrow chin, rather than a mechanical mask.
        [-1, 1].forEach(function (side) {
            var points = [];
            for (var i = 0; i <= 70; i++) {
                var v = -1 + i / 70 * 2;
                points.push(side * profile(v) * 132, -178 + v * 174, 0);
            }
            outline.push(line(points, 1));
            var ear = [];
            for (i = 0; i <= 30; i++) {
                var angle = -Math.PI / 2 + i / 30 * Math.PI;
                ear.push(side * (125 + Math.cos(angle) * 18), -119 + Math.sin(angle) * 34, 5 + Math.cos(angle) * 12);
            }
            ears.push(line(ear, 1));
            var shoulderCount = compact ? 15 : 27;
            for (i = 0; i < shoulderCount; i++) {
                var curve = [], q = i / (shoulderCount - 1);
                // Curves follow the throat into the trapezius and the rounded shoulder cap.
                var anchors = [
                    [side * (26 + q * 36), -12 + q * 9, 37],
                    [side * (28 + q * 35), 64 + q * 29, 43],
                    [side * (53 + q * 38), 125 + q * 20, 55],
                    [side * (139 + q * 48), 157 + q * 27, 65],
                    [side * (256 + q * 45), 176 + q * 55, 48],
                    [side * (340 + q * 36), 246 + q * 64, 15],
                    [side * (376 + q * 48), 378 + q * 26, -18]
                ];
                for (var a = 0; a < anchors.length - 1; a++) {
                    var p0 = anchors[Math.max(0, a - 1)], p1 = anchors[a], p2 = anchors[a + 1], p3 = anchors[Math.min(anchors.length - 1, a + 2)];
                    for (var k = 0; k < 10; k++) {
                        var u = k / 10, u2 = u * u, u3 = u2 * u;
                        for (var axis = 0; axis < 3; axis++) curve.push(.5 * ((2 * p1[axis]) + (-p0[axis] + p2[axis]) * u + (2 * p0[axis] - 5 * p1[axis] + 4 * p2[axis] - p3[axis]) * u2 + (-p0[axis] + 3 * p1[axis] - 3 * p2[axis] + p3[axis]) * u3));
                    }
                }
                torso.push(line(curve, 0));
                if (i === shoulderCount - 1) outline.push(line(curve, 0));
            }
            // Chest strands converge on the central energy point, with no platform or orbit rings.
            for (i = 0; i < (compact ? 9 : 16); i++) {
                var chest = [], portion = i / 16;
                for (k = 0; k <= 36; k++) {
                    var t = k / 36;
                    chest.push(side * (10 + i * 15) * (1 - .48 * t), 171 + i * 7 + 223 * t, 46 + Math.sin(t * Math.PI) * 21 - portion * 25);
                }
                torso.push(line(chest));
            }
        });
        for (var n = 0; n < (compact ? 8 : 16); n++) {
            var side = n % 2 ? -1 : 1, points = [], startX = side * (9 + random() * 40);
            for (var j = 0; j <= 21; j++) {
                var u = j / 21, taper = Math.pow(1 - u, 1.3);
                points.push(startX * taper + Math.sin(u * 15 + n * 1.6) * (3 + 4 * Math.sin(u * Math.PI)), -7 + u * 300, 54 + Math.sin(u * Math.PI) * 12);
            }
            nerves.push(line(points));
        }
        var all = head.concat(torso);
        for (var s = 0; s < (compact ? 190 : 640); s++) {
            var curve = all[Math.floor(random() * all.length)], idx = Math.floor(random() * curve.points.length / 3) * 3;
            sparks.push({x: curve.points[idx] + (random() - .5) * 30, y: curve.points[idx + 1] + (random() - .5) * 28,
                z: curve.points[idx + 2] + (random() - .5) * 25, group: curve.group, phase: random() * TAU, r: .4 + random() * .95, warm: random() > .92});
        }
        return {head: head, torso: torso, outline: outline, ears: ears, sparks: sparks, nerves: nerves};
    }

    function Hologram(canvas, options) {
        this.canvas = canvas;
        this.options = options;
        this.ctx = canvas.getContext('2d', {alpha: true});
        this.compact = !!options.compact;
        this.interval = this.compact ? 1000 / 15 : 1000 / 30;
        this.reduced = typeof options.reducedMotion === 'boolean' ? options.reducedMotion : !!(motionQuery && motionQuery.matches);
        this.visible = true; this.dead = false; this.speaking = false; this.alert = false;
        this.speech = 0; this.alarm = 0; this.last = 0; this.elapsed = 0;
        this.width = 0; this.height = 0; this.scale = 1; this.originY = 0;
        this.mesh = geometry(this.compact);
        this.headYaw = 0; this.bodyYaw = 0; this.pitch = 0; this.breath = 0;
        this.px = 0; this.py = 0;
        var self = this;
        if (options.imageSrc) {
            this.artwork = loadArtwork(options.imageSrc, function (state) {
                if (self.dead) return;
                self.artwork = state;
                if (eligible(self)) self.render(self.reduced ? 0 : self.elapsed);
                wake();
            });
        }
        this.onResize = function () { self.resize(); };
        this.onMotion = function () {
            if (typeof self.options.reducedMotion !== 'boolean') self.reduced = !!(motionQuery && motionQuery.matches);
            self.last = 0;
            if (eligible(self)) self.render(self.reduced ? 0 : self.elapsed);
            wake();
        };
        canvas.setAttribute('aria-hidden', 'true');
        if (window.ResizeObserver) { this.resizeObserver = new window.ResizeObserver(this.onResize); this.resizeObserver.observe(canvas); }
        else window.addEventListener('resize', this.onResize);
        if (window.IntersectionObserver) {
            this.intersectionObserver = new window.IntersectionObserver(function (entries) {
                self.visible = entries[0].isIntersecting;
                self.last = 0;
                if (eligible(self)) self.render(self.reduced ? 0 : self.elapsed);
                wake();
            }, {threshold: 0});
            this.intersectionObserver.observe(canvas);
        }
        if (motionQuery) {
            if (motionQuery.addEventListener) motionQuery.addEventListener('change', this.onMotion);
            else if (motionQuery.addListener) motionQuery.addListener(this.onMotion);
        }
        this.resize();
    }
    Hologram.prototype.resize = function () {
        if (this.dead) return;
        var box = this.canvas.getBoundingClientRect(), width = Math.round(box.width), height = Math.round(box.height);
        if (width < 2 || height < 2) { this.width = width; this.height = height; return; }
        var ratio = Math.min(1.5, window.devicePixelRatio || 1);
        this.width = width; this.height = height;
        if (this.canvas.width !== Math.round(width * ratio)) this.canvas.width = Math.round(width * ratio);
        if (this.canvas.height !== Math.round(height * ratio)) this.canvas.height = Math.round(height * ratio);
        this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        this.scale = Math.min(width * .94 / 900, height * .94 / 800) * (this.options.scale || 1);
        this.originY = (height - 800 * this.scale) / 2 + 352 * this.scale + (this.options.offsetY || 0);
        this.render(this.reduced ? 0 : this.elapsed);
        this.last = 0; wake();
    };
    Hologram.prototype.project = function (x, y, z, group) {
        var yaw = group ? this.headYaw : this.bodyYaw, c = Math.cos(yaw), s = Math.sin(yaw);
        var rx = x * c + z * s, rz = -x * s + z * c, pivot = group ? -3 : 135;
        var pitch = group ? this.pitch : this.pitch * .15;
        var ry = (y - pivot) * Math.cos(pitch) - rz * Math.sin(pitch) + pivot;
        var perspective = 1600 / (1600 - rz);
        this.px = rx * perspective + (group ? this.headYaw * 10 : 0);
        this.py = ry + this.breath * (group ? 1 : .65);
    };
    Hologram.prototype.path = function (curve) {
        var p = curve.points, ctx = this.ctx;
        ctx.beginPath();
        for (var i = 0; i < p.length; i += 3) {
            this.project(p[i], p[i + 1], p[i + 2], curve.group);
            if (i === 0) ctx.moveTo(this.px, this.py); else ctx.lineTo(this.px, this.py);
        }
    };
    Hologram.prototype.curves = function (curves, color, width, alpha) {
        var ctx = this.ctx;
        ctx.strokeStyle = color; ctx.lineWidth = width; ctx.globalAlpha = alpha;
        for (var i = 0; i < curves.length; i++) { this.path(curves[i]); ctx.stroke(); }
        ctx.globalAlpha = 1;
    };
    Hologram.prototype.glow = function (x, y, radius, color, strength, squash) {
        var ctx = this.ctx;
        ctx.save(); ctx.translate(x, y); if (squash) ctx.scale(1, squash);
        var gradient = ctx.createRadialGradient(0, 0, 0, 0, 0, radius);
        gradient.addColorStop(0, 'rgba(' + color + ',' + strength + ')');
        gradient.addColorStop(.38, 'rgba(' + color + ',' + strength * .34 + ')');
        gradient.addColorStop(1, 'rgba(' + color + ',0)');
        ctx.fillStyle = gradient; ctx.fillRect(-radius, -radius, radius * 2, radius * 2); ctx.restore();
    };
    Hologram.prototype.pose = function (time) {
        var look = 0, phase = time % 60;
        // Every minute: turn left, look across to the right, settle naturally at the centre.
        if (time >= 60 && phase < 9) {
            if (phase < 2.5) look = -.40 * smooth(phase / 2.5);
            else if (phase < 6) look = mix(-.40, .40, smooth((phase - 2.5) / 3.5));
            else look = .40 * (1 - smooth((phase - 6) / 3));
        }
        this.headYaw = look + (this.reduced ? 0 : Math.sin(time * .39) * .016);
        this.bodyYaw = look * .18 + (this.reduced ? 0 : Math.sin(time * .25) * .007);
        this.pitch = this.reduced ? 0 : Math.sin(time * .55) * .012 + Math.abs(look) * .026;
        this.breath = this.reduced ? 0 : Math.sin(time * 1.12) * 1.4;
    };
    Hologram.prototype.background = function (time) {
        if (!this.options.background || this.compact) return;
        var ctx = this.ctx, width = this.width / this.scale, height = this.height / this.scale;
        ctx.lineWidth = .7;
        for (var i = 0; i < 12; i++) {
            var side = i % 2 ? 1 : -1, y = -height / 2 + (i / 12) * height, drift = Math.sin(time * .16 + i) * 13;
            ctx.strokeStyle = 'rgba(28,171,216,' + (.045 + .012 * Math.sin(time * .3 + i)) + ')';
            ctx.beginPath(); ctx.moveTo(side * width * .58, y + drift);
            ctx.bezierCurveTo(side * width * .32, y + 80, side * 240, 260 + drift, side * 22, 280); ctx.stroke();
        }
    };
    Hologram.prototype.face = function (time) {
        var ctx = this.ctx, energy = .68 + this.speech * .32 + this.alarm * .19;
        var beat = this.reduced ? .8 : .8 + .12 * Math.sin(time * 2.7) + this.speech * .12 * Math.sin(time * 17);
        this.project(0, -132, 91, 1);
        this.glow(this.px, this.py, 131, '255,137,0', .40 * energy, .82);
        this.glow(this.px, this.py, 86, '255,205,28', .60 * energy, .66);
        var rows = this.compact ? 12 : 21;
        ctx.lineCap = 'round';
        for (var i = 0; i < rows; i++) {
            var v = i / (rows - 1), y = -220 + v * 170;
            var halfWidth = 100 * Math.pow(Math.sin(Math.PI * (v * .84 + .08)), .72);
            var fade = Math.pow(Math.sin(Math.PI * (v * .86 + .07)), .75);
            for (var pass = 0; pass < 2; pass++) {
                ctx.beginPath();
                for (var j = 0; j <= 38; j++) {
                    var x = -halfWidth + 2 * halfWidth * j / 38, edge = Math.sin(j / 38 * Math.PI);
                    var wave = this.reduced ? 0 : Math.sin(x * .067 - time * (2.4 + this.speech * 5) + i * .31) * (2 + this.speech * 4) * edge;
                    this.project(x, y - edge * 6 + wave, 70 + edge * 26, 1);
                    if (j === 0) ctx.moveTo(this.px, this.py); else ctx.lineTo(this.px, this.py);
                }
                ctx.lineWidth = pass ? 1.25 : 5.5;
                ctx.strokeStyle = pass ? 'rgba(255,234,123,' + Math.min(.98, fade * beat * energy).toFixed(3) + ')' : 'rgba(255,133,5,' + (fade * .22 * energy).toFixed(3) + ')';
                ctx.stroke();
            }
        }
    };
    Hologram.prototype.renderArtwork = function (time) {
        var ctx = this.ctx, image = this.artwork.image;
        var sourceWidth = image.naturalWidth, sourceHeight = image.naturalHeight;
        var fit = Math.min(this.width * .98 / sourceWidth, this.height * .98 / sourceHeight) * (this.options.scale || 1);
        var breath = this.reduced ? 0 : Math.sin(time * 1.12);
        var drawWidth = sourceWidth * fit * (1 + breath * .0016);
        var drawHeight = sourceHeight * fit * (1 + breath * .0025);
        var left = (this.width - drawWidth) / 2;
        var top = (this.height - drawHeight) / 2 + (this.options.offsetY || 0) - breath * .6;
        this.pose(time);
        ctx.save();
        ctx.imageSmoothingEnabled = true;
        if ('imageSmoothingQuality' in ctx) ctx.imageSmoothingQuality = 'high';
        var turning = !this.reduced && time >= 60 && time % 60 < 9;
        if (!turning) {
            // The approved artwork is drawn unchanged while idle; there is no second
            // procedural face or body layered over it.
            ctx.drawImage(image, left, top, drawWidth, drawHeight);
        } else {
            // A continuous perspective warp around the throat lets the featureless
            // holographic head look left/right while the shoulders follow gently.
            // Only the nine-second glance uses bands; idle costs a single image draw.
            var bands = this.compact ? 45 : 112;
            for (var row = 0; row < bands; row++) {
                var v = (row + .5) / bands;
                var headWeight = 1 - smooth((v - .48) / .19);
                var yaw = this.headYaw * headWeight + this.bodyYaw * (1 - headWeight);
                var squash = 1 - .09 * Math.abs(yaw / .4);
                var width = drawWidth * squash, shift = Math.sin(yaw) * drawWidth * .085;
                var x = (this.width - width) / 2 + shift;
                var sy = row / bands * sourceHeight, sh = Math.min(sourceHeight - sy, sourceHeight / bands);
                var dy = top + row / bands * drawHeight, dh = sh / sourceHeight * drawHeight;
                // Whole-width bands preserve contour continuity: splitting down the
                // face would leave a bright seam in this translucent artwork.
                ctx.drawImage(image, 0, sy, sourceWidth, sh, x, dy, width, dh + .05);
            }
        }
        ctx.globalCompositeOperation = 'lighter';
        var faceX = this.width / 2 + Math.sin(this.headYaw) * drawWidth * .085;
        var faceY = top + drawHeight * .30;
        if (this.speech > .01 || this.alarm > .01) {
            var amplitude = .07 * this.speech * (.6 + .4 * Math.sin(time * 17)) + .035 * this.alarm;
            this.glow(faceX, faceY, drawWidth * .15, '255,177,17', amplitude, .68);
            this.glow(this.width / 2, top + drawHeight * .84, drawWidth * .065, '40,235,255', amplitude * .7);
        }
        // Small travelling energy specks make the space around the figure live,
        // while preserving all the detailed contours in the approved artwork.
        if (!this.reduced) {
            var count = this.compact ? 16 : 62;
            for (var i = 0; i < count; i++) {
                var seed = this.mesh.sparks[i];
                var phase = seed.phase, orbit = .20 + (i % 7) * .045;
                var vertical = (time * (.022 + i % 3 * .003) + phase / TAU) % 1;
                var particleX = this.width / 2 + Math.sin(phase) * drawWidth * orbit + Math.sin(time * .28 + phase) * 3;
                var particleY = top + drawHeight * (.92 - vertical * .85);
                var alpha = Math.sin(vertical * Math.PI) * (.18 + .13 * Math.sin(time * 1.4 + phase));
                ctx.fillStyle = 'rgba(30,220,255,' + Math.max(0, alpha).toFixed(3) + ')';
                ctx.beginPath(); ctx.arc(particleX, particleY, this.compact ? .5 : .65, 0, TAU); ctx.fill();
            }
        }
        ctx.restore();
    };
    Hologram.prototype.render = function (time) {
        if (this.dead || this.width < 2 || this.height < 2) return;
        var ctx = this.ctx, mesh = this.mesh, scale = this.scale;
        ctx.clearRect(0, 0, this.width, this.height);
        if (this.artwork && this.artwork.ready) { this.renderArtwork(time); return; }
        // Do not flash a differently proportioned procedural robot while the local
        // artwork decodes. Its stable aspect-fit box is ready before the first paint.
        if (this.artwork && !this.artwork.failed) return;
        ctx.save(); ctx.translate(this.width / 2, this.originY); ctx.scale(scale, scale);
        ctx.lineJoin = 'round'; ctx.lineCap = 'round';
        this.pose(time);
        this.background(time);
        ctx.globalCompositeOperation = 'lighter';
        this.glow(0, -160, 255, '0,154,221', .055);
        this.glow(0, 180, 340, '0,167,221', .022);
        this.curves(mesh.torso, '#00a8e7', 5, .045);
        this.curves(mesh.torso, '#07c9f3', 1.1, .28);
        this.curves(mesh.torso, '#8aecff', .42, .46);
        this.curves(mesh.head, '#00c9ff', 7, .08);
        this.curves(mesh.head, '#00bded', 2.2, .29);
        this.curves(mesh.head, '#9af0ff', .65, .70);
        this.curves(mesh.outline, '#00aaff', 12, .11);
        this.curves(mesh.outline, '#03ddff', 4.0, .65);
        this.curves(mesh.outline, '#c5f9ff', 1.0, .93);
        this.curves(mesh.ears, '#03cfff', 7, .20);
        this.curves(mesh.ears, '#a7f2ff', 1.1, .86);
        var sparks = mesh.sparks;
        for (var i = 0; i < sparks.length; i++) {
            var spark = sparks[i], drift = this.reduced ? 0 : Math.sin(time * .55 + spark.phase) * 3;
            this.project(spark.x + drift, spark.y + drift * .8, spark.z, spark.group);
            var alpha = this.reduced ? .6 : .28 + .39 * (.5 + .5 * Math.sin(time * 1.15 + spark.phase));
            ctx.fillStyle = spark.warm ? 'rgba(255,175,47,' + alpha.toFixed(3) + ')' : 'rgba(43,220,255,' + alpha.toFixed(3) + ')';
            ctx.beginPath(); ctx.arc(this.px, this.py, spark.r * (1 + this.alarm * .35), 0, TAU); ctx.fill();
        }
        this.face(time);
        this.curves(mesh.nerves, '#fa9b00', 4.7, .12);
        this.curves(mesh.nerves, '#ffc13c', .8, .62 + this.speech * .22);
        this.project(0, 288, 63, 0);
        var chestX = this.px, chestY = this.py;
        var pulse = this.reduced ? 1 : 1 + .1 * Math.sin(time * 2.2);
        this.glow(chestX, chestY, 56 * pulse, '0,219,255', .40);
        this.glow(chestX, chestY, 19 * pulse, '78,245,255', .96);
        ctx.fillStyle = '#e7ffff'; ctx.beginPath(); ctx.arc(chestX, chestY, 3.2, 0, TAU); ctx.fill();
        ctx.globalCompositeOperation = 'source-over';
        // A gradual lower-body fade avoids the appearance of a solid cut-out sprite.
        ctx.globalCompositeOperation = 'destination-out';
        var fade = ctx.createLinearGradient(0, 302, 0, 430);
        fade.addColorStop(0, 'rgba(0,0,0,0)'); fade.addColorStop(1, 'rgba(0,0,0,1)');
        ctx.fillStyle = fade; ctx.fillRect(-650, 302, 1300, 150);
        ctx.restore();
    };
    Hologram.prototype.destroy = function () {
        if (this.dead) return;
        this.dead = true;
        if (this.resizeObserver) this.resizeObserver.disconnect(); else window.removeEventListener('resize', this.onResize);
        if (this.intersectionObserver) this.intersectionObserver.disconnect();
        if (motionQuery) {
            if (motionQuery.removeEventListener) motionQuery.removeEventListener('change', this.onMotion);
            else if (motionQuery.removeListener) motionQuery.removeListener(this.onMotion);
        }
        instances = instances.filter(function (item) { return item !== this; }, this);
        if (this.canvas.__goHologram === this) delete this.canvas.__goHologram;
        if (!instances.some(animate) && frame) { window.cancelAnimationFrame(frame); frame = 0; }
    };
    window.GoHologram = {
        version: 1,
        mount: function (canvas, options) {
            if (!canvas || typeof canvas.getContext !== 'function') return null;
            if (!canvas.getContext('2d')) return null;
            if (canvas.__goHologram && !canvas.__goHologram.dead) return canvas.__goHologram.api;
            var item = new Hologram(canvas, options || {});
            if (!item.ctx) return null;
            var api = {
                destroy: function () { item.destroy(); },
                setSpeaking: function (value) { item.speaking = !!value; wake(); },
                setAlert: function (value) { item.alert = !!value; wake(); },
                setVisible: function (value) { item.visible = !!value; item.last = 0; if (eligible(item)) item.render(item.reduced ? 0 : item.elapsed); wake(); }
            };
            item.api = api; canvas.__goHologram = item; instances.push(item); wake();
            return api;
        }
    };
})(window, document);
