// ============================================================
// NÚCLEO - MarcelCraft
// ============================================================

// Canvas principal
const canvas = document.getElementById('c');
const ctx = canvas.getContext('2d');

// Estado de cámara
let camX = 0, camY = 0, zoom = 1;
let dragging = false, dragO = { x: 0, y: 0, cx: 0, cy: 0 };
let hovR = null, hovC = null;
let selR = null, selC = null;

// Estado de animación
let frame = 0, lastTs = 0, fps = 0, fpsN = 0, fpsT = 0;
const keys = {};

// Ciclo día/noche
let dayAngle = 0.92;
const DAY_SPEED = 0.00042;
let _sunH = 0;
let dayNightEnabled = true;

// ============================================================
// CLASE DE RUIDO
// ============================================================
class VNoise {
    constructor(seed) { this.s = seed >>> 0; }

    _h(ix, iy) {
        let n = (ix * 374761393 + iy * 668265263 + this.s * 2246822519) >>> 0;
        n = Math.imul(n ^ (n >>> 13), 1274126177) >>> 0;
        return (n ^ (n >>> 16)) / 0xffffffff;
    }

    at(x, y) {
        const ix = Math.floor(x), iy = Math.floor(y);
        const fx = x - ix, fy = y - iy;
        const ux = fx * fx * (3 - 2 * fx), uy = fy * fy * (3 - 2 * fy);
        const v00 = this._h(ix, iy), v10 = this._h(ix + 1, iy);
        const v01 = this._h(ix, iy + 1), v11 = this._h(ix + 1, iy + 1);
        return v00 + (v10 - v00) * ux + (v01 - v00) * uy + (v00 - v10 - v01 + v11) * ux * uy;
    }

    oct(x, y, n, p) {
        let v = 0, a = 1, f = 1, m = 0;
        for (let i = 0; i < n; i++) { v += this.at(x * f, y * f) * a; m += a; a *= p; f *= 2; }
        return v / m;
    }
}

// ============================================================
// GENERACIÓN DEL MAPA
// ============================================================
function buildMap() {
    const elev = new VNoise(SEED);
    const elev2 = new VNoise(SEED ^ 0x9f8e7d6c);
    const mois = new VNoise(SEED ^ 0xdeadbeef);
    const deco = new VNoise(SEED ^ 0x12345678);
    const map = [];

    for (let r = 0; r < MH; r++) {
        map[r] = [];
        for (let c = 0; c < MW; c++) {
            const nx = c / MW, ny = r / MH;

            const e1 = elev.oct(nx * 4.5, ny * 4.5, 7, 0.52);
            const e2 = elev2.oct(nx * 2.8, ny * 2.8, 5, 0.58);
            let e = Math.max(e1 * 0.90, e2 * 0.80);

            const dx = nx * 2 - 1, dy = ny * 2 - 1;
            const d = Math.sqrt(dx * dx + dy * dy);
            e -= Math.pow(Math.max(0, d - 0.88), 2) * 2.2;
            e = Math.max(0, Math.min(1, e + 0.10));

            const m = mois.oct(nx * 5 + 7, ny * 5 + 7, 4, 0.5);
            const dv = deco.at(c * 0.8, r * 0.8);

            let type, height;
            if (e < 0.13) { type = T.DEEP; height = 0; }
            else if (e < 0.23) { type = T.WATER; height = 0; }
            else if (e < 0.29) { type = T.SAND; height = 1; }
            else if (e < 0.64) { type = m > 0.50 ? T.JUNGLE : T.GRASS; height = 1; }
            else if (e < 0.78) { type = T.ROCK; height = 2; }
            else if (e < 0.90) { type = T.MOUNTAIN; height = 3; }
            else { type = T.SNOW; height = 4; }

            let dec = null;
            if (type === T.JUNGLE && dv > 0.20) dec = dv > 0.55 ? 'palm' : 'tree';
            else if (type === T.GRASS && dv > 0.55) dec = 'flower';
            else if (type === T.ROCK && dv > 0.30) dec = 'boulder';
            else if (type === T.MOUNTAIN && dv > 0.25) dec = 'rock';
            else if (type === T.WATER && dv > 0.65) dec = 'lily';
            else if (type === T.SNOW && dv > 0.40) dec = 'snowrock';

            map[r][c] = { type, height, e, dec };
        }
    }
    return map;
}

// Mapa global
let map = buildMap();

// ============================================================
// FUNCIONES DE COORDENADAS
// ============================================================
function t2s(r, c, h = 0) {
    return {
        x: (c - r) * (TW / 2) * zoom + camX,
        y: (c + r) * (TH / 2) * zoom + camY - h * WH * zoom
    };
}

function s2t(mx, my) {
    const rx = (mx - camX) / zoom, ry = (my - camY) / zoom;
    const X = rx / (TW / 2), Y = ry / (TH / 2);
    return { r: Math.round((Y - X) / 2), c: Math.round((Y + X) / 2) };
}

function resetCam() {
    camX = canvas.width / 2 - (MW / 2 - MH / 2) * (TW / 2) * zoom;
    camY = canvas.height / 3 - (MW / 2 + MH / 2) * (TH / 2) * zoom;
}

// ============================================================
// VIEWPORT CULLING
// ============================================================
function getVisibleTileRange() {
    const corners = [
        s2t(0, 0),
        s2t(canvas.width, 0),
        s2t(canvas.width, canvas.height),
        s2t(0, canvas.height)
    ];

    let minR = MH, maxR = 0, minC = MW, maxC = 0;

    corners.forEach(t => {
        minR = Math.min(minR, Math.max(0, t.r - 2));
        maxR = Math.max(maxR, Math.min(MH - 1, t.r + 2));
        minC = Math.min(minC, Math.max(0, t.c - 2));
        maxC = Math.max(maxC, Math.min(MW - 1, t.c + 2));
    });

    const margin = 4;
    minR = Math.max(0, minR - margin);
    maxR = Math.min(MH - 1, maxR + margin);
    minC = Math.max(0, minC - margin);
    maxC = Math.min(MW - 1, maxC + margin);

    return { minR, maxR, minC, maxC };
}

// ============================================================
// FUNCIONES DE TIEMPO
// ============================================================
function getSunState() {
    const sh = Math.sin(dayAngle);
    const ch = Math.cos(dayAngle);
    const horizY = canvas.height * 0.28;
    const arcR = canvas.width * 0.46;
    return {
        sunX: canvas.width / 2 - ch * arcR,
        sunY: horizY - sh * canvas.height * 0.30,
        moonX: canvas.width / 2 + ch * arcR,
        moonY: horizY + sh * canvas.height * 0.30,
        sh, ch
    };
}

function timeOfDayLabel(sh) {
    if (sh > 0.82) return '☀️ Mediodía';
    if (sh > 0.45) return '🌤️ Día';
    if (sh > 0.10) return Math.cos(dayAngle) < 0 ? '🌅 Tarde' : '🌄 Mañana';
    if (sh > -0.06) return Math.cos(dayAngle) < 0 ? '🌇 Atardecer' : '🌅 Amanecer';
    if (sh > -0.35) return '🌆 Crepúsculo';
    return '🌙 Noche';
}

function tileHash(r, c, i = 0) {
    let n = ((r * 374761 + c * 668265 + i * 1234567) ^ (SEED * 89101)) >>> 0;
    n = Math.imul(n ^ (n >>> 13), 1274126177) >>> 0;
    return (n ^ (n >>> 16)) / 0xffffffff;
}

// ============================================================
// MOVIMIENTO DE CÁMARA
// ============================================================
function moveCam() {
    const spd = 7 / zoom;
    if (keys['w'] || keys['arrowup']) camY += spd;
    if (keys['s'] || keys['arrowdown']) camY -= spd;
    if (keys['a'] || keys['arrowleft']) camX += spd;
    if (keys['d'] || keys['arrowright']) camX -= spd;
}

// ============================================================
// RESIZE
// ============================================================
let _firstResize = true;
function resize() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    if (_firstResize) {
        _firstResize = false;
        const isMobile = window.innerWidth < 768 || ('ontouchstart' in window);
        if (isMobile) {
            const fw = canvas.width / ((MW + MH) * (TW / 2));
            const fh = canvas.height / ((MW + MH) * (TH / 2));
            zoom = Math.max(0.30, Math.min(0.80, Math.min(fw, fh) * 1.6));
        }
    }
    resetCam();
}

// ============================================================
// EVENTOS GLOBALES
// ============================================================
window.addEventListener('resize', resize);

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
        e.preventDefault(); undo(); return;
    }
    if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))) {
        e.preventDefault(); redo(); return;
    }
    keys[e.key.toLowerCase()] = true;
    if (e.key.toLowerCase() === 'r' && !editorMode) {
        const ns = Math.floor(Math.random() * 999999);
        location.href = `?seed=${ns}&w=${MW}&h=${MH}`;
    }
});

document.addEventListener('keyup', e => { keys[e.key.toLowerCase()] = false; });

// ============================================================
// TOAST
// ============================================================
function showToast(msg) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
}

