// Bloop, the ai's pixel water blob. every frame is a body shape + a face (+ an optional scene) drawn
// from tiny pixel rules on a 48x60 grid, so squash, hops, blinks and the jiggle are frame swaps and row shifts.
// loaded once in the head; pages mount canvases with Bloop.mount(canvas, options) or <canvas data-bloop="anim">
(function(){
if(window.Bloop){
    return;
}

const C = 48, R = 60, GY = 53, CX = 24;
const PAL = {o:"#0b2e6b",b:"#3db8ff",s:"#1f8be6",h:"#a8e8ff",w:"#ffffff",e:"#0a1633",m:"#0a1633",p:"#ff8fb8",r:"#ff5f86",g:"#c9d5ea",k:"#5d6f94",n:"#fff6c2",y:"#ffb81f",Y:"#ffe27a",q:"#d98a00",W:"#f1c58b",f:"#aab6cc"};
const SKIN = {
    deep: {b:"#3db8ff",s:"#1f8be6",h:"#a8e8ff"},
    abyss: {b:"#8b7bff",s:"#5f4fd9",h:"#cfc7ff"},
    reef: {b:"#2fd6b4",s:"#16a88e",h:"#a6f5e3"},
    foam: {b:"#d9f4ff",s:"#9fd3ec",h:"#ffffff"}
};

// body shapes: H rows, rx/ry ellipse radii, tip = rows of the swooping top, lean = curl of the tip
const POSE = {
    base: {H:38,rx:14,ry:14.5,tip:13,lean:3,sp:0},
    sink: {H:36,rx:15,ry:13.5,tip:12,lean:3,sp:1},
    squash: {H:31,rx:17,ry:12,tip:9,lean:2,sp:3},
    stretch: {H:44,rx:12.5,ry:16,tip:17,lean:4,sp:-1}
};

// which part of the 48x60 grid a canvas shows: x, y, width, height in grid cells
const CROPS = {
    full: [0, 0, 48, 60],
    stage: [0, 4, 48, 52],
    head: [6, 12, 36, 36]
};

const shapes = {};
function shape(name){
    if(shapes[name]){
        return shapes[name];
    }
    const P = POSE[name], yc = P.H - P.ry, rows = [];
    for(let y = 0; y < P.H; y++){
        const d = y + .5 - yc;
        let hw = d > 0 ? P.rx * Math.pow(Math.max(0, 1 - Math.pow(d / P.ry, 4)), .25) : P.rx * Math.sqrt(Math.max(0, 1 - Math.pow(d / P.ry, 2)));
        if(y < P.tip){
            const t = y / P.tip;
            hw = Math.max(hw, 2 + (P.rx * .62 - 2) * Math.pow(t, 1.7));
        }
        const sh = y < P.tip ? Math.round(P.lean * Math.pow(1 - y / P.tip, 2)) : 0;
        rows.push([Math.round(CX + sh - hw), Math.round(CX + sh + hw) - 1]);
    }
    return shapes[name] = rows;
}

const FIST = [".ooooo.","obbbbbo","obhbbbo","obbbbso","obsssso",".ooooo."];

function build(sp){
    const P = POSE[sp.p], ox = sp.ox || 0, fx = sp.fx || 0, H = P.H, T = GY - H + 1, yc = H - P.ry;
    const g = Array.from({length: R}, () => Array(C).fill("."));
    const Q = (x, y, ch) => { if(y >= 0 && y < R && x >= 0 && x < C) g[y][x] = ch; };
    const put = (x, y, ch) => { const v = g[y] && g[y][x]; if(v && v != "." && v != "o") g[y][x] = ch; };
    const blit = (x, y, rows, f) => rows.forEach((row, j) => [...row].forEach((ch, i) => { if(ch != ".") (f || Q)(x + i, y + j, ch); }));
    const rows = shape(sp.p);

    rows.forEach((a, i) => { for(let x = a[0]; x <= a[1]; x++) Q(x + ox, T + i, (x == a[0] || x == a[1] || i == 0 || i == H - 1) ? "o" : "b"); });
    const ed = [];
    for(let y = 1; y < R - 1; y++) for(let x = 0; x < C; x++) if(g[y][x] == "b" && (g[y - 1][x] == "." || g[y + 1][x] == ".")) ed.push([x, y]);
    ed.forEach(a => g[a[1]][a[0]] = "o");
    for(let ps = 0; ps < 2; ps++){
        const sh = [];
        for(let y = 0; y < R - 1; y++) for(let x = 0; x < C - 1; x++){
            const r = g[y][x + 1], d = g[y + 1][x];
            if(g[y][x] == "b" && (r == "o" || d == "o" || r == "s" || d == "s")) sh.push([x, y]);
        }
        sh.forEach(a => g[a[1]][a[0]] = "s");
    }
    const ys = Math.round(yc - 9);
    [[0,4,"h"],[1,4,"h"],[2,3,"w"],[2,4,"h"],[3,3,"w"],[3,4,"h"],[4,3,"h"],[5,3,"h"]].forEach(a => {
        const i = ys + a[0];
        if(i < 0 || i >= H) return;
        const x = rows[i][0] + a[1] + ox, y = T + i;
        if(g[y][x] == "b" || g[y][x] == "s") g[y][x] = a[2];
    });

    // face
    const fy = Math.round(yc - 4.5), y0 = T + fy, k = P.sp, fc = CX + ox + fx, ex = sp.ex || 0, side = fx != 0;
    [fc - 8 - k + ex, fc + 4 + k + ex].forEach(x => {
        const E = sp.eyes;
        if(E == "open"){ blit(x, y0, [".ee.","eeee","eeee","eeee",".ee."], put); put(x, y0 + 1, "w"); put(x + 1, y0 + 1, "w"); put(x, y0 + 2, "w"); }
        else if(E == "down"){ blit(x, y0 + 1, [".ee.","eeee","eeee",".ee."], put); put(x, y0 + 2, "w"); }
        else if(E == "up"){ blit(x, y0 - 1, [".ee.","eeee","eeee",".ee."], put); put(x + 2, y0, "w"); }
        else if(E == "blink"){ for(let j = 0; j < 4; j++){ put(x + j, y0 + 2, "e"); put(x + j, y0 + 3, "e"); } }
        else if(E == "happy"){ [[1,1],[2,1],[0,2],[3,2],[0,3],[3,3]].forEach(a => put(x + a[0], y0 + a[1], "e")); }
        else { for(let j = -1; j < 5; j++){ put(x + j, y0 + 3, "e"); put(x + j, y0 + 4, "e"); } }
    });
    (side ? [fc - 8, fc + 8] : [fc - 12 - k, fc + 8 + k]).forEach(x => { for(let j = 0; j < 4; j++){ put(x + j, y0 + 7, "p"); put(x + j, y0 + 8, "p"); } });
    const my = y0 + 7, M = sp.mouth;
    if(M == "smile") [[-3,0],[2,0],[-2,1],[-1,1],[0,1],[1,1]].forEach(a => put(fc + a[0], my + a[1], "m"));
    else if(M == "o") blit(fc - 2, my, [".mm.","mmmm",".mm."], put);
    else if(M == "big") blit(fc - 3, my, ["mmmmmm","mrrrrm",".mrrm.","..mm.."], put);
    else for(let j = -2; j < 2; j++) put(fc + j, my + 1, "m");

    // scenes
    if(sp.lap){
        const L = sp.lap;
        for(let y = 42; y <= 52; y++) for(let x = CX - 14; x <= CX + 13; x++) Q(x, y, (y == 42 || y == 52 || x == CX - 14 || x == CX + 13) ? "o" : "g");
        for(let x = CX - 16; x <= CX + 15; x++) Q(x, 53, "o");
        blit(CX - 3, 45, ["..bb..",".bbbb.","bbbbbb","bbhbbb",".bbbb."]);
        blit(CX - 18, 41 + 3 * L.l, FIST);
        blit(CX + 11, 41 + 3 * L.r, FIST);
    }
    const arm = (x, y) => { for(let j = 0; j < 4; j++){ const ch = (j == 0 || j == 3) ? "o" : "b"; for(let xx = x; xx < C && g[y + j] && g[y + j][xx] == "."; xx++) Q(xx, y + j, ch); } };
    if(sp.scn == "lap2"){
        const D = sp.lp;
        for(let x = 6; x <= 21; x++) Q(x, 53, "k");
        for(let x = 9; x <= 19; x += 3){ Q(x, 52, "g"); Q(x + 1, 52, "g"); }
        for(let i = 0; i < 17; i++){
            const x = 6 - Math.floor(i * .4), y = 52 - i, lit = (i + D.s * 2) % 4 < 2;
            Q(x, y, "k"); Q(x + 1, y, "k"); Q(x + 2, y, lit ? "b" : "h");
        }
        arm(20, D.y + 1);
        blit(14, D.y, FIST);
    }
    if(sp.scn == "write"){
        const D = sp.wr, x0 = y => 6 - Math.floor((53 - y) * .2), done = D.l >= 4, li = Math.min(D.l, 3);
        for(let y = 40; y <= 53; y++){
            const a = x0(y);
            if(y == 40){ for(let j = 0; j < 5; j++) Q(a + j, y, "o"); }
            else { Q(a, y, "o"); Q(a + 1, y, "k"); Q(a + 2, y, "n"); Q(a + 3, y, "n"); Q(a + 4, y, "o"); }
        }
        blit(x0(40) + 1, 37, [".ff.","f..f",".ff."]);
        for(let l = 0; l < Math.min(D.l, 4); l++){ const ym = 46 + 2 * l; Q(x0(ym) + 3, ym, "e"); Q(x0(ym) + 2, ym, "e"); }
        const yt = 46 + 2 * li, xt = x0(yt) + 5 + (done ? 5 : D.j), ty = done ? yt - 5 : yt;
        for(let i = 0; i < 12; i++){
            const x = xt + i, y = ty - Math.floor(i * .35);
            if(i == 0) Q(x, y, "e");
            else if(i == 1){ Q(x, y, "W"); Q(x, y - 1, "W"); }
            else if(i == 2){ Q(x, y, "W"); Q(x, y - 1, "W"); Q(x, y - 2, "W"); }
            else { Q(x, y - 2, "Y"); Q(x, y - 1, "y"); Q(x, y, "q"); }
        }
        blit(xt + 6, ty - 6, FIST);
        for(let j = 0; j < 2; j++){ Q(xt + 8, ty - 8 + j, "r"); Q(xt + 9, ty - 8 + j, "r"); }
    }
    return g;
}

// the page's text color, so thought dots, z's and the shadow show up on every theme
let ink = "#F5F1ED";
function readTheme(){
    const id = document.documentElement.dataset.theme;
    Object.assign(PAL, SKIN[id] || SKIN.deep);
    ink = getComputedStyle(document.body || document.documentElement).getPropertyValue("--text").trim() || ink;
}

function draw(b, f){
    const ctx = b.ctx, cs = b.cs, [cx0, cy0] = b.crop, sp = f.sp, dy = f.dy || 0;
    const g = build(sp);
    ctx.clearRect(0, 0, b.canvas.width, b.canvas.height);

    const sw = dy > 8 ? 16 : dy > 2 ? 20 : 24;
    ctx.globalAlpha = .18;
    ctx.fillStyle = ink;
    ctx.fillRect((CX + (sp.ox || 0) - sw / 2 - cx0) * cs, (GY + 1 - cy0) * cs, sw * cs, 2 * cs);
    ctx.globalAlpha = 1;

    for(let y = 0; y < R; y++){
        const off = f.j ? Math.round(f.j * Math.sin(f.t * 9 - y * .3)) : 0;
        for(let x = 0; x < C; x++){
            const ch = g[y][x];
            if(ch == ".") continue;
            ctx.fillStyle = PAL[ch];
            ctx.fillRect((x + off - cx0) * cs, (y - dy - cy0) * cs, cs, cs);
        }
    }

    // particles live on a 2-cell grid
    b.parts.forEach(p => {
        const d = PAT[p.type];
        ctx.globalAlpha = Math.min(1, p.life / .4);
        ctx.fillStyle = d.c || ink;
        d.m.forEach(m => ctx.fillRect(((Math.floor(p.x) + m[0]) * 2 - cx0) * cs, ((Math.floor(p.y) + m[1]) * 2 - cy0) * cs, 2 * cs, 2 * cs));
    });
    ctx.globalAlpha = 1;

    if(f.dots){
        ctx.fillStyle = ink;
        for(let i = 0; i < f.dots; i++) ctx.fillRect((36 + i * 4 - cx0) * cs, (10 - cy0) * cs, 2 * cs, 2 * cs);
    }
}

const PAT = {
    bubble: {c:"#37a4ee", m:[[1,0],[0,1],[2,1],[1,2]]},
    spark: {c:"#ffb81f", m:[[1,0],[0,1],[1,1],[2,1],[1,2]]},
    z: {c:null, m:[[0,0],[1,0],[2,0],[3,0],[2,1],[1,2],[0,3],[1,3],[2,3],[3,3]]}
};

const RM = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
const THINKS = ["think", "think2"];
const ONCE = { hop: .95, jiggle: 2.2, happy: 1.6 };

function spawn(b, type, n){
    if(RM){
        return;
    }
    for(let i = 0; i < (n || 1); i++){
        const z = type == "z";
        b.parts.push({
            type: type,
            x: z ? 17 : 12 + Math.random() * 10 - 5,
            y: z ? 8 : 6 + Math.random() * 4,
            vx: z ? 2.5 : (Math.random() - .5) * 6,
            vy: z ? -3.5 : -(4 + Math.random() * 5),
            life: z ? 1.6 : .9 + Math.random() * .6
        });
    }
}

function pose(b, now){
    let t = RM ? 0 : Math.max(0, now - b.t0) / 1000;
    if(ONCE[b.anim] && t > ONCE[b.anim]){
        b.set(b.after);
        t = 0;
    }
    const anim = b.anim, sp = {p:"base", eyes:"open", mouth:"smile"};
    let dy = 0, j = 0, dots = 0;

    if(anim == "idle"){
        sp.p = Math.floor(t / .6) % 2 ? "sink" : "base";
    } else if(anim == "watch"){
        // reading what you type
        sp.p = Math.floor(t / .6) % 2 ? "sink" : "base";
        sp.eyes = "down";
        sp.mouth = "o";
    } else if(anim == "talk"){
        sp.p = Math.floor(t / .24) % 2 ? "sink" : "base";
        sp.mouth = ["smile","o","big","o","smile","big","o"][Math.floor(t / .11) % 7];
    } else if(anim == "happy"){
        const k = Math.abs(Math.sin(t * 6.5));
        dy = Math.round(k * (b.small ? 3 : 6));
        sp.p = k < .2 ? "squash" : k > .7 ? "stretch" : "base";
        sp.eyes = "happy";
        sp.mouth = "big";
        if(t - b.lastSp > .5){ b.lastSp = t; spawn(b, "spark", 2); }
    } else if(anim == "sleep"){
        sp.p = Math.floor(t / 1.3) % 2 ? "sink" : "base";
        sp.eyes = "sleep";
        sp.mouth = "flat";
        if(!b.lastSp || t - b.lastSp > 1.6){ b.lastSp = t || .001; spawn(b, "z"); }
    } else if(anim == "jiggle"){
        j = 2.4;
        sp.mouth = "o";
    } else if(anim == "ponder"){
        // eyes up, mouth flat: the "hmm" before anything's come back
        sp.p = Math.floor(t / .9) % 2 ? "sink" : "base";
        sp.eyes = "up";
        sp.ex = [0, 2, 2, 0, -2, -2][Math.floor(t / .7) % 6];
        sp.mouth = "flat";
        dots = 1 + Math.floor(t / .35) % 3;
    } else if(anim == "think"){
        const typing = (t % 3.4) < 2.5, i = Math.floor(t / .09) % 8;
        sp.eyes = typing ? "down" : "open";
        sp.ex = typing ? [0, 2, 0, -2][Math.floor(t / .5) % 4] : 2;
        sp.lap = typing ? {l:[0,1,0,0,1,1,0,1][i], r:[1,0,0,1,0,1,1,0][i]} : {l:0, r:0};
        dots = typing ? 1 + Math.floor(t / .35) % 3 : 3;
    } else if(anim == "think2"){
        const typing = (t % 3.4) < 2.5, i = Math.floor(t / .1) % 8;
        sp.ox = 8; sp.fx = -4; sp.scn = "lap2";
        sp.eyes = typing ? "down" : "open";
        sp.ex = typing ? [0, 2, 0, 2][Math.floor(t / .5) % 4] : 2;
        sp.lp = {y: typing ? [48,45,48,48,45,45,48,45][i] : 48, s: Math.floor(t / .2) % 2};
        dots = typing ? 1 + Math.floor(t / .35) % 3 : 3;
    } else if(anim == "write"){
        const T = 3.8, ph = t % T, cyc = Math.floor(t / T), wr = ph < 2.8, l = wr ? Math.min(3, Math.floor(ph / .7)) : 4;
        sp.ox = 8; sp.fx = -4; sp.scn = "write";
        sp.eyes = wr ? "down" : "happy";
        sp.wr = {l: l, j: wr ? Math.floor(t / .09) % 2 : 0};
        if(!wr && !b.fired["c" + cyc]){ b.fired["c" + cyc] = 1; spawn(b, "spark", 2); }
    } else if(anim == "hop"){
        const s = b.small ? .4 : 1;
        if(t < .16) sp.p = "squash";
        else if(t < .24){ sp.p = "stretch"; dy = 4 * s; }
        else if(t < .64){
            const u = (t - .24) / .4;
            dy = Math.round((40 * u * (1 - u) + 4) * s);
            sp.p = (u < .25 || u > .75) ? "stretch" : "base";
            if(u > .4 && !b.fired.a){ b.fired.a = 1; spawn(b, "bubble", 3); }
        }
        else if(t < .74){ sp.p = "stretch"; dy = 2 * s; }
        else if(t < .9){ sp.p = "squash"; if(!b.fired.b){ b.fired.b = 1; spawn(b, "bubble", 4); } }
        else sp.p = "sink";
        if(dy > 6 * s) sp.eyes = "happy";
    }

    if(!b.dots){
        dots = 0;
    }
    if(sp.eyes == "open" || sp.eyes == "down" || sp.eyes == "up"){
        if(now >= b.nextBlink){ b.blinkEnd = now + 140; b.nextBlink = now + 2200 + Math.random() * 2800; }
        if(now < b.blinkEnd) sp.eyes = "blink";
    }
    return {sp: sp, dy: dy, j: j, t: t, dots: dots};
}

const live = new Set();
let last = 0, running = false;

function loop(now){
    if(!live.size){
        running = false;
        return;
    }
    requestAnimationFrame(loop);
    if(now - last < 33 || document.hidden){
        return;
    }
    const dt = Math.min((now - last) / 1000, .1);
    last = now;

    live.forEach(function(b) {
        if(!b.canvas.isConnected){
            live.delete(b);
            return;
        }
        // hidden (display:none somewhere up the tree) costs nothing
        if(!b.canvas.offsetParent && getComputedStyle(b.canvas).position !== "fixed"){
            return;
        }
        for(let i = b.parts.length - 1; i >= 0; i--){
            const p = b.parts[i];
            p.x += p.vx * dt; p.y += p.vy * dt; p.life -= dt;
            if(p.life <= 0) b.parts.splice(i, 1);
        }
        draw(b, pose(b, now));
        if(b.still && !b.parts.length && !ONCE[b.anim]){
            live.delete(b);
        }
    });
}

function wake(b){
    live.add(b);
    if(!running){
        running = true;
        requestAnimationFrame(loop);
    }
}

// options: anim, crop (full | stage | head), cs (pixels per grid cell), dots (show thought dots),
// hop (tap to hop), still (draw one frame, only animate for one-shot moves like hop)
function mount(canvas, options){
    options = options || {};
    if(canvas.bloop){
        return canvas.bloop;
    }
    const crop = CROPS[options.crop || "full"];
    const cs = options.cs || 2;
    canvas.width = crop[2] * cs;
    canvas.height = crop[3] * cs;

    const b = {
        canvas: canvas,
        ctx: canvas.getContext("2d"),
        crop: crop,
        cs: cs,
        small: options.crop === "head",
        dots: options.dots !== false && options.crop !== "head",
        still: !!options.still,
        parts: [],
        anim: null,
        after: "idle",
        t0: performance.now(),
        fired: {},
        lastSp: 0,
        nextBlink: performance.now() + 1000 + Math.random() * 3000,
        blinkEnd: 0,
        // then: what to go back to after a one-shot move (hop, jiggle, happy)
        set: function(anim, then){
            if(anim === "thinking"){
                anim = THINKS[Math.floor(Math.random() * THINKS.length)];
            }
            if(ONCE[anim]){
                b.after = then || (ONCE[b.anim] ? b.after : b.anim) || "idle";
            } else if(anim === b.anim){
                return b;
            }
            b.anim = anim;
            b.t0 = performance.now();
            b.fired = {};
            b.lastSp = 0;
            if(b.still && !ONCE[anim]){
                draw(b, pose(b, performance.now()));
            } else {
                wake(b);
            }
            return b;
        },
        poke: function(){
            if(!ONCE[b.anim] && ["idle", "watch", "sleep"].includes(b.anim)){
                b.set(Math.random() < .25 ? "jiggle" : "hop", b.anim === "sleep" ? "idle" : b.anim);
            }
        }
    };
    canvas.bloop = b;
    canvas.style.imageRendering = "pixelated";

    if(options.hop){
        canvas.style.cursor = "pointer";
        canvas.tabIndex = 0;
        canvas.addEventListener("click", b.poke);
        canvas.addEventListener("keydown", function(e) {
            if(e.key === "Enter" || e.key === " "){
                e.preventDefault();
                b.poke();
            }
        });
    }

    b.set(options.anim || "idle");
    if(b.still){
        draw(b, pose(b, performance.now()));
    }
    all.add(b);
    return b;
}

// every mounted bloop, so a theme change can repaint the still ones
const all = new Set();

function scan(root){
    (root || document).querySelectorAll("canvas[data-bloop]").forEach(function(canvas) {
        mount(canvas, {
            anim: canvas.dataset.bloop,
            crop: canvas.dataset.crop,
            cs: +canvas.dataset.cs || undefined,
            hop: canvas.hasAttribute("data-hop"),
            still: canvas.hasAttribute("data-still")
        });
    });
}

readTheme();
new MutationObserver(function() {
    readTheme();
    all.forEach(function(b) {
        if(!b.canvas.isConnected){
            all.delete(b);
            return;
        }
        draw(b, pose(b, performance.now()));
        spawn(b, "bubble", 3);
        wake(b);
    });
}).observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });

window.Bloop = { mount: mount, scan: scan };
})();
