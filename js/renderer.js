// ============================================================
// RENDERIZADOR - MarcelCraft
// ============================================================

// Caché de texturas
const textureCache = new Map();

// Minimapa
const mmCanvas = document.getElementById('mmCanvas');
const mmCtx = mmCanvas.getContext('2d');
let MM_PX = Math.max(2, Math.floor(160 / Math.max(MW, MH)));

let mmOff = document.createElement('canvas');
mmOff.width = MW * MM_PX;
mmOff.height = MH * MM_PX;
let mmOffCtx = mmOff.getContext('2d');

// ============================================================
// COLORES DE TILES
// ============================================================
function tileColors(type, f, sh) {
    const wa = Math.sin(f * 0.04) * 0.1 + 0.9;
    const wHue = Math.round(210 + sh * 18);
    const wLit = Math.round(36 * wa);
    switch (type) {
        case T.DEEP:
            return { top: `hsl(220,78%,${Math.round(20 * wa)}%)`, L: '#0e2a5a', R: '#0a1e42' };
        case T.WATER:
            return { top: `hsl(${wHue},72%,${wLit}%)`, L: '#1a4070', R: '#132e54' };
        case T.SAND:
            return { top: '#d4b47c', L: '#aa8c58', R: '#8a6e3e' };
        case T.GRASS:
            return { top: '#5e9040', L: '#426828', R: '#2e4e1a' };
        case T.JUNGLE:
            return { top: '#2a6e28', L: '#1c4e1c', R: '#143a14' };
        case T.ROCK:
            return { top: '#8a7a6a', L: '#665a4a', R: '#4e4234' };
        case T.MOUNTAIN:
            return { top: '#b8b0a0', L: '#8a8278', R: '#6e6860' };
        case T.SNOW:
            return { top: '#eaf0ff', L: '#c0cce0', R: '#a0aec8' };
        default:
            return { top: '#888', L: '#666', R: '#444' };
    }
}

// ============================================================
// GENERADOR DE TEXTURAS (CACHÉ)
// ============================================================
function generateTexturePattern(type, size = 64) {
    const cacheKey = `${type}_${size}`;
    if (textureCache.has(cacheKey)) {
        return textureCache.get(cacheKey);
    }

    const offCanvas = document.createElement('canvas');
    offCanvas.width = size;
    offCanvas.height = size;
    const offCtx = offCanvas.getContext('2d');

    switch (type) {
        case T.GRASS:
            offCtx.fillStyle = '#5a8a3c';
            offCtx.fillRect(0, 0, size, size);
            for (let i = 0; i < 20; i++) {
                const x = Math.random() * size;
                const y = Math.random() * size;
                offCtx.fillStyle = `rgba(0,45,0,${0.2 + Math.random() * 0.2})`;
                offCtx.beginPath();
                offCtx.arc(x, y, 2 + Math.random() * 4, 0, Math.PI * 2);
                offCtx.fill();
            }
            break;

        case T.JUNGLE:
            offCtx.fillStyle = '#1a5018';
            offCtx.fillRect(0, 0, size, size);
            for (let i = 0; i < 25; i++) {
                const x = Math.random() * size;
                const y = Math.random() * size;
                offCtx.fillStyle = `rgba(0,65,0,${0.3 + Math.random() * 0.3})`;
                offCtx.beginPath();
                offCtx.arc(x, y, 3 + Math.random() * 5, 0, Math.PI * 2);
                offCtx.fill();
            }
            break;

        case T.SAND:
            offCtx.fillStyle = '#c8a96e';
            offCtx.fillRect(0, 0, size, size);
            for (let i = 0; i < 40; i++) {
                const x = Math.random() * size;
                const y = Math.random() * size;
                offCtx.fillStyle = `rgba(158,120,62,${0.3 + Math.random() * 0.3})`;
                offCtx.beginPath();
                offCtx.arc(x, y, 1 + Math.random() * 2, 0, Math.PI * 2);
                offCtx.fill();
            }
            break;

        case T.ROCK:
            offCtx.fillStyle = '#6a5a4a';
            offCtx.fillRect(0, 0, size, size);
            for (let i = 0; i < 15; i++) {
                const x = Math.random() * size;
                const y = Math.random() * size;
                offCtx.fillStyle = `rgba(30,22,12,${0.3 + Math.random() * 0.2})`;
                offCtx.beginPath();
                offCtx.ellipse(x, y, 4 + Math.random() * 6, 2 + Math.random() * 4, 0, 0, Math.PI * 2);
                offCtx.fill();
            }
            break;

        case T.MOUNTAIN:
            offCtx.fillStyle = '#9a9080';
            offCtx.fillRect(0, 0, size, size);
            offCtx.fillStyle = 'rgba(235,245,255,0.3)';
            offCtx.beginPath();
            offCtx.moveTo(10, size-10);
            offCtx.lineTo(size/2, 5);
            offCtx.lineTo(size-10, size-10);
            offCtx.fill();
            break;

        case T.SNOW:
            offCtx.fillStyle = '#dce8f8';
            offCtx.fillRect(0, 0, size, size);
            for (let i = 0; i < 30; i++) {
                const x = Math.random() * size;
                const y = Math.random() * size;
                offCtx.fillStyle = `rgba(255,255,255,${0.5 + Math.random() * 0.5})`;
                offCtx.beginPath();
                offCtx.arc(x, y, 2 + Math.random() * 3, 0, Math.PI * 2);
                offCtx.fill();
            }
            break;

        default:
            offCtx.fillStyle = '#888';
            offCtx.fillRect(0, 0, size, size);
    }

    const pattern = ctx.createPattern(offCanvas, 'repeat');
    textureCache.set(cacheKey, pattern);
    return pattern;
}

// ============================================================
// DIBUJAR TILE (SIN LÍNEAS NEGRAS)
// ============================================================
function drawTile(r, c) {
    const tile = map[r][c];
    const p = t2s(r, c, tile.height);
    const w = TW * zoom, h = TH * zoom;
    const wh = tile.height * WH * zoom;

    const top = { x: p.x + w / 2, y: p.y };
    const rig = { x: p.x + w, y: p.y + h / 2 };
    const bot = { x: p.x + w / 2, y: p.y + h };
    const lft = { x: p.x, y: p.y + h / 2 };

    const col = tileColors(tile.type, frame, _sunH);
    const isH = hovR === r && hovC === c;
    const isS = selR === r && selC === c;

    // Dibujar caras laterales (altura) - SIN BORDES
    if (wh > 0 && tile.type !== T.DEEP && tile.type !== T.WATER) {
        ctx.beginPath();
        ctx.moveTo(lft.x, lft.y); ctx.lineTo(bot.x, bot.y);
        ctx.lineTo(bot.x, bot.y + wh); ctx.lineTo(lft.x, lft.y + wh);
        ctx.closePath();
        ctx.fillStyle = col.L;
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(rig.x, rig.y); ctx.lineTo(bot.x, bot.y);
        ctx.lineTo(bot.x, bot.y + wh); ctx.lineTo(rig.x, rig.y + wh);
        ctx.closePath();
        ctx.fillStyle = col.R;
        ctx.fill();
    }

    // Dibujar cara superior
    ctx.beginPath();
    ctx.moveTo(top.x, top.y);
    ctx.lineTo(rig.x, rig.y);
    ctx.lineTo(bot.x, bot.y);
    ctx.lineTo(lft.x, lft.y);
    ctx.closePath();

    // Relleno con textura o color sólido
    if (tile.type !== T.WATER && tile.type !== T.DEEP && zoom > 0.3) {
        const pattern = generateTexturePattern(tile.type);
        ctx.fillStyle = pattern;
    } else {
        ctx.fillStyle = col.top;
    }
    ctx.fill();

    // Efectos de agua animada - AHORA USA LA VARIABLE GLOBAL
    if (waterAnimEnabled && (tile.type === T.WATER || tile.type === T.DEEP)) {
        const sv = Math.sin(frame * 0.055 + r * 0.42 + c * 0.31) * 0.5 + 0.5;
        if (sv > 0.65) {
            ctx.save();
            ctx.beginPath();
            ctx.moveTo(top.x, top.y); ctx.lineTo(rig.x, rig.y);
            ctx.lineTo(bot.x, bot.y); ctx.lineTo(lft.x, lft.y);
            ctx.closePath();
            ctx.clip();
            const sr = _sunH > 0 ? Math.round(180 + _sunH * 52) : 165;
            const sg2 = _sunH > 0 ? Math.round(228 + _sunH * 14) : 205;
            ctx.fillStyle = `rgba(${sr},${sg2},255,${((sv - .65) * .36).toFixed(3)})`;
            ctx.fillRect(p.x, p.y, w, h);
            ctx.restore();
        }
    }

    // Resaltado de selección (SIN BORDES NEGROS)
    if (isS) {
        ctx.save();
        ctx.globalAlpha = 0.25;
        ctx.fillStyle = '#ffd700';
        ctx.fill();
        ctx.restore();
    } else if (isH) {
        if (editorMode) {
            const hintColor = activeTool === 'paint' ? 'rgba(100,200,255,0.25)'
                : activeTool === 'height' ? 'rgba(255,180,80,0.25)'
                    : activeTool === 'deco' ? 'rgba(100,255,130,0.25)'
                        : 'rgba(255,80,80,0.25)';
            ctx.save();
            ctx.fillStyle = hintColor;
            ctx.fill();
            ctx.restore();
        } else {
            ctx.save();
            ctx.globalAlpha = 0.15;
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.restore();
        }
    }

    // Decoraciones
    if (tile.dec && zoom > 0.45) {
        drawDeco(tile.dec, top.x, p.y + h * .35, zoom, frame, r, c);
    }
}

// ============================================================
// DECORACIONES SIMPLIFICADAS
// ============================================================
function drawDeco(deco, cx, cy, z, f, r, c) {
    ctx.save();
    switch (deco) {
        case 'tree':
            ctx.fillStyle = '#6b3a1f';
            ctx.fillRect(cx - 2 * z, cy - 14 * z, 4 * z, 14 * z);
            ctx.fillStyle = '#2a7a2a';
            ctx.beginPath();
            ctx.arc(cx, cy - 22 * z, 8 * z, 0, Math.PI * 2);
            ctx.fill();
            break;

        case 'palm':
            ctx.strokeStyle = '#8b5e3c';
            ctx.lineWidth = 4 * z;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(cx, cy - 30 * z);
            ctx.stroke();
            ctx.fillStyle = '#2e8b2e';
            ctx.beginPath();
            ctx.arc(cx - 2 * z, cy - 32 * z, 6 * z, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.arc(cx + 4 * z, cy - 28 * z, 5 * z, 0, Math.PI * 2);
            ctx.fill();
            break;

        case 'flower':
            ctx.fillStyle = '#ff6b6b';
            ctx.beginPath();
            ctx.arc(cx, cy - 5 * z, 3 * z, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#ffd93d';
            ctx.beginPath();
            ctx.arc(cx, cy - 5 * z, 1.5 * z, 0, Math.PI * 2);
            ctx.fill();
            break;

        case 'lily':
            ctx.fillStyle = '#2a7a2a';
            ctx.beginPath();
            ctx.ellipse(cx, cy - 5 * z, 6 * z, 3 * z, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#ff69b4';
            ctx.beginPath();
            ctx.arc(cx, cy - 8 * z, 2 * z, 0, Math.PI * 2);
            ctx.fill();
            break;

        case 'boulder':
            ctx.fillStyle = '#7a6a5a';
            ctx.beginPath();
            ctx.ellipse(cx, cy - 5 * z, 8 * z, 4 * z, 0, 0, Math.PI * 2);
            ctx.fill();
            break;

        case 'rock':
            ctx.fillStyle = '#8a7a6a';
            ctx.beginPath();
            ctx.moveTo(cx - 5 * z, cy - 5 * z);
            ctx.lineTo(cx, cy - 15 * z);
            ctx.lineTo(cx + 5 * z, cy - 5 * z);
            ctx.closePath();
            ctx.fill();
            break;

        case 'snowrock':
            ctx.fillStyle = '#a0aec8';
            ctx.beginPath();
            ctx.ellipse(cx, cy - 5 * z, 6 * z, 3 * z, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            ctx.arc(cx - 2 * z, cy - 8 * z, 2 * z, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.arc(cx + 3 * z, cy - 7 * z, 1.5 * z, 0, Math.PI * 2);
            ctx.fill();
            break;
    }
    ctx.restore();
}

// ============================================================
// FONDO
// ============================================================
function drawBg() {
    const ss = getSunState();
    const sh = ss.sh;

    const g = ctx.createLinearGradient(0, 0, 0, canvas.height);
    g.addColorStop(0, '#0a1a2a');
    g.addColorStop(0.5, '#1a3a5a');
    g.addColorStop(1, '#0a1525');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    if (sh > 0) {
        ctx.fillStyle = '#ffffaa';
        ctx.shadowColor = '#ffff00';
        ctx.shadowBlur = 30;
        ctx.beginPath();
        ctx.arc(ss.sunX, ss.sunY, 20, 0, Math.PI * 2);
        ctx.fill();
    } else {
        ctx.fillStyle = '#dddddd';
        ctx.shadowColor = '#ffffff';
        ctx.shadowBlur = 20;
        ctx.beginPath();
        ctx.arc(ss.moonX, ss.moonY, 15, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.shadowBlur = 0;
}

// ============================================================
// MINIMAPA
// ============================================================
function rebuildMinimap() {
    mmOffCtx.clearRect(0, 0, mmOff.width, mmOff.height);
    for (let r = 0; r < MH; r++) {
        for (let c = 0; c < MW; c++) {
            mmOffCtx.fillStyle = MINI_C[map[r][c].type];
            mmOffCtx.fillRect(c * MM_PX, r * MM_PX, MM_PX, MM_PX);
        }
    }
}

function updateMinimapTile(r, c) {
    mmOffCtx.fillStyle = MINI_C[map[r][c].type];
    mmOffCtx.fillRect(c * MM_PX, r * MM_PX, MM_PX, MM_PX);
}

function drawMinimap() {
    mmCtx.clearRect(0, 0, mmCanvas.width, mmCanvas.height);
    mmCtx.drawImage(mmOff, 0, 0);

    if (selR !== null && selR >= 0 && selR < MH && selC >= 0 && selC < MW) {
        mmCtx.fillStyle = '#ffd700';
        mmCtx.globalAlpha = 0.8;
        mmCtx.fillRect(selC * MM_PX, selR * MM_PX, MM_PX, MM_PX);
        mmCtx.globalAlpha = 1;
    }
    if (hovR !== null && hovR >= 0 && hovR < MH && hovC >= 0 && hovC < MW) {
        mmCtx.fillStyle = 'rgba(255,255,255,.65)';
        mmCtx.fillRect(hovC * MM_PX, hovR * MM_PX, MM_PX, MM_PX);
    }

    const corners = [
        s2t(0, 0),
        s2t(canvas.width, 0),
        s2t(canvas.width, canvas.height),
        s2t(0, canvas.height),
    ];
    mmCtx.beginPath();
    corners.forEach((t, i) => {
        const mx = t.c * MM_PX + MM_PX * 0.5;
        const my = t.r * MM_PX + MM_PX * 0.5;
        i === 0 ? mmCtx.moveTo(mx, my) : mmCtx.lineTo(mx, my);
    });
    mmCtx.closePath();
    mmCtx.fillStyle = 'rgba(79,195,247,.15)';
    mmCtx.strokeStyle = '#4fc3f7';
    mmCtx.lineWidth = 1.5;
    mmCtx.fill();
    mmCtx.stroke();
}

mmCanvas.addEventListener('click', e => {
    const rect = mmCanvas.getBoundingClientRect();
    const scaleX = mmCanvas.width / rect.width;
    const scaleY = mmCanvas.height / rect.height;
    const tc = ((e.clientX - rect.left) * scaleX) / MM_PX;
    const tr = ((e.clientY - rect.top) * scaleY) / MM_PX;
    const sx = (tc - tr) * (TW / 2) * zoom;
    const sy = (tc + tr) * (TH / 2) * zoom;
    camX = canvas.width / 2 - sx;
    camY = canvas.height / 2 - sy;
});

// ============================================================
// RENDER PRINCIPAL
// ============================================================
function render(ts) {
    frame++;
    fpsT += ts - lastTs;
    lastTs = ts;
    fpsN++;
    if (fpsT >= 500) {
        fps = Math.round(fpsN * 1000 / fpsT);
        document.getElementById('fps-value').textContent = fps;
        fpsN = 0;
        fpsT = 0;
    }

    const ss = getSunState();
    _sunH = ss.sh;

    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawBg();

    const visible = getVisibleTileRange();

    document.getElementById('zoom-value').textContent = Math.round(zoom * 100);
    document.getElementById('time-value').textContent = timeOfDayLabel(_sunH).split(' ')[1] || 'Día';

    for (let r = visible.minR; r <= visible.maxR; r++) {
        for (let c = visible.minC; c <= visible.maxC; c++) {
            drawTile(r, c);
        }
    }

    if (hovR !== null && hovR >= 0 && hovR < MH && hovC >= 0 && hovC < MW) {
        const ti = TINFO[map[hovR][hovC].type];
        document.getElementById('tile-name').textContent = `${ti.emoji} ${ti.name}`;
        document.getElementById('tile-x').textContent = hovC;
        document.getElementById('tile-y').textContent = hovR;
        document.getElementById('tile-icon').textContent = ti.icon;
        document.getElementById('tile-elev').textContent = `Altura ${map[hovR][hovC].height}`;
        document.getElementById('tile-deco').textContent = map[hovR][hovC].dec || 'Sin deco';
        document.getElementById('h-tile').textContent = `${ti.emoji} ${ti.name}`;
    }

    drawMinimap();
}

// ============================================================
// LOOP PRINCIPAL
// ============================================================
function loop(ts) {
    if (dayNightEnabled) dayAngle = (dayAngle + DAY_SPEED) % (Math.PI * 2);
    moveCam();
    render(ts);
    requestAnimationFrame(loop);
}

// Iniciar
rebuildMinimap();
resize();
requestAnimationFrame(loop);

