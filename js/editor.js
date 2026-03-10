// ============================================================
// EDITOR Y UI - MarcelCraft
// ============================================================

// Estado del editor
let editorMode = false;
let activeTool = 'paint';
let paintType = T.GRASS;
let isPainting = false;
let paintStartX = 0, paintStartY = 0;
let heightDirection = +1;
let _pendingSnapshot = false;
let _edMouseDown = false;
let mapDirty = false;

// Historial
const undoStack = [];
const redoStack = [];
const MAX_UNDO = 20;

// Opciones
let waterAnimEnabled = true;

// ============================================================
// FUNCIONES DEL EDITOR
// ============================================================
function toggleEditor() {
    editorMode = !editorMode;
    const btn = document.getElementById('editor-toggle');
    const panel = document.getElementById('editor-panel');
    btn.textContent = editorMode ? '✎ EDITOR ON' : '⚙ EDITOR';
    btn.classList.toggle('active', editorMode);
    panel.classList.toggle('visible', editorMode);
    canvas.classList.toggle('edit', editorMode);
}

function setTool(tool) {
    activeTool = tool;
    ['paint', 'height', 'deco', 'erase'].forEach(t => {
        document.getElementById('et-' + t).classList.toggle('active', t === tool);
    });
    document.getElementById('ed-height-dir').style.display = tool === 'height' ? 'block' : 'none';
}

function applyEditAt(r, c, rightClick = false) {
    if (r < 0 || r >= MH || c < 0 || c >= MW) return;
    const tile = map[r][c];
    switch (activeTool) {
        case 'paint':
            tile.type = paintType;
            tile.height = DEFAULT_HEIGHT[paintType];
            tile.dec = VALID_DECO[paintType].includes(tile.dec) ? tile.dec : null;
            tile.e = tile.height / 4;
            break;
        case 'height':
            tile.height = Math.max(0, Math.min(4, tile.height + (rightClick ? -1 : heightDirection)));
            break;
        case 'deco': {
            const opts = VALID_DECO[tile.type];
            const cur = opts.indexOf(tile.dec);
            tile.dec = opts[(cur + 1) % opts.length];
            break;
        }
        case 'erase':
            tile.dec = null;
            break;
    }
    updateMinimapTile(r, c);
}

// ============================================================
// HISTORIAL
// ============================================================
function snapshotMap() {
    return map.map(row => row.map(cell => ({ t: cell.type, h: cell.height, e: cell.e, d: cell.dec })));
}

function restoreSnapshot(snap) {
    for (let r = 0; r < MH; r++) {
        for (let c = 0; c < MW; c++) {
            map[r][c].type = snap[r][c].t;
            map[r][c].height = snap[r][c].h;
            map[r][c].e = snap[r][c].e;
            map[r][c].dec = snap[r][c].d;
        }
    }
}

function pushUndo() {
    undoStack.push(snapshotMap());
    if (undoStack.length > MAX_UNDO) undoStack.shift();
    redoStack.length = 0;
    updateUndoButtons();
}

function undo() {
    if (!undoStack.length) return;
    redoStack.push(snapshotMap());
    restoreSnapshot(undoStack.pop());
    rebuildMinimap();
    updateUndoButtons();
    mapDirty = true;
    updateSaveButton();
    showToast('↩ Deshecho');
}

function redo() {
    if (!redoStack.length) return;
    undoStack.push(snapshotMap());
    restoreSnapshot(redoStack.pop());
    rebuildMinimap();
    updateUndoButtons();
    mapDirty = true;
    updateSaveButton();
    showToast('↪ Rehecho');
}

function updateUndoButtons() {
    document.getElementById('ed-undo').disabled = !undoStack.length;
    document.getElementById('ed-redo').disabled = !redoStack.length;
}

function updateSaveButton() {
    const btn = document.getElementById('ed-save');
    if (btn) btn.textContent = mapDirty ? '💾 Guardar*' : '💾 Guardar';
}

// ============================================================
// EVENTOS DE RATÓN Y TÁCTIL
// ============================================================
canvas.addEventListener('mousedown', e => {
    if (editorMode) {
        _edMouseDown = true;
        paintStartX = e.clientX;
        paintStartY = e.clientY;
        isPainting = false;
        _pendingSnapshot = true;
        return;
    }
    dragging = true;
    canvas.classList.add('drag');
    dragO = { x: e.clientX, y: e.clientY, cx: camX, cy: camY };
});

canvas.addEventListener('mousemove', e => {
    if (editorMode && _edMouseDown) {
        const dx = e.clientX - paintStartX, dy = e.clientY - paintStartY;
        if (!isPainting && (Math.abs(dx) > 3 || Math.abs(dy) > 3)) {
            isPainting = true;
            if (_pendingSnapshot) { pushUndo(); _pendingSnapshot = false; }
        }
        if (isPainting) {
            const { r, c } = s2t(e.clientX, e.clientY);
            applyEditAt(r, c, e.buttons === 2);
        }
    } else if (!editorMode && dragging) {
        camX = dragO.cx + (e.clientX - dragO.x);
        camY = dragO.cy + (e.clientY - dragO.y);
    }
    const { r, c } = s2t(e.clientX, e.clientY);
    hovR = r;
    hovC = c;
});

canvas.addEventListener('mouseup', e => {
    if (editorMode) {
        const dx = e.clientX - paintStartX, dy = e.clientY - paintStartY;
        const moved = Math.abs(dx) > 3 || Math.abs(dy) > 3;
        if (!moved && _pendingSnapshot) {
            const { r, c } = s2t(e.clientX, e.clientY);
            pushUndo();
            applyEditAt(r, c, e.button === 2);
        }
        if (isPainting || (!moved && _pendingSnapshot)) {
            rebuildMinimap();
            mapDirty = true;
            updateSaveButton();
        }
        isPainting = false;
        _edMouseDown = false;
        _pendingSnapshot = false;
        return;
    }
    const moved = Math.abs(e.clientX - dragO.x) > 5 || Math.abs(e.clientY - dragO.y) > 5;
    if (!moved) {
        const { r, c } = s2t(e.clientX, e.clientY);
        if (r >= 0 && r < MH && c >= 0 && c < MW) {
            selR = r;
            selC = c;
            const tile = map[r][c];
            const ti = TINFO[tile.type];
            document.getElementById('tile-name').textContent = `${ti.emoji} ${ti.name}`;
            document.getElementById('tile-x').textContent = c;
            document.getElementById('tile-y').textContent = r;
            document.getElementById('tile-icon').textContent = ti.icon;
            document.getElementById('tile-elev').textContent = `Altura ${tile.height}`;
            document.getElementById('tile-deco').textContent = tile.dec || 'Sin deco';
        }
    }
    dragging = false;
    canvas.classList.remove('drag');
});

canvas.addEventListener('wheel', e => {
    e.preventDefault();
    const f = e.deltaY > 0 ? 0.88 : 1.14;
    const nz = Math.max(.25, Math.min(3.5, zoom * f));
    camX = e.clientX + (camX - e.clientX) * (nz / zoom);
    camY = e.clientY + (camY - e.clientY) * (nz / zoom);
    zoom = nz;
}, { passive: false });

canvas.addEventListener('mouseleave', () => {
    dragging = false;
    canvas.classList.remove('drag');
    hovR = null;
    hovC = null;
    if (editorMode) {
        isPainting = false;
        _edMouseDown = false;
        _pendingSnapshot = false;
    }
});

canvas.addEventListener('contextmenu', e => { if (editorMode) e.preventDefault(); });

// ============================================================
// EVENTOS TÁCTILES
// ============================================================
let touchBase = { dist: 0, zoom: 1, cx: 0, cy: 0, ox: 0, oy: 0 };

function touchDist(t1, t2) {
    const dx = t1.clientX - t2.clientX, dy = t1.clientY - t2.clientY;
    return Math.sqrt(dx * dx + dy * dy);
}
function touchMid(t1, t2) {
    return { x: (t1.clientX + t2.clientX) / 2, y: (t1.clientY + t2.clientY) / 2 };
}

canvas.addEventListener('touchstart', e => {
    e.preventDefault();
    const t = e.touches;
    if (t.length === 1) {
        if (editorMode) {
            _edMouseDown = true;
            paintStartX = t[0].clientX;
            paintStartY = t[0].clientY;
            isPainting = false;
            _pendingSnapshot = true;
        } else {
            dragging = true;
            canvas.classList.add('drag');
            dragO = { x: t[0].clientX, y: t[0].clientY, cx: camX, cy: camY };
        }
    } else if (t.length === 2) {
        isPainting = false;
        _edMouseDown = false;
        _pendingSnapshot = false;
        dragging = false;
        canvas.classList.remove('drag');
        touchBase.dist = touchDist(t[0], t[1]);
        touchBase.zoom = zoom;
        const mid = touchMid(t[0], t[1]);
        touchBase.cx = camX;
        touchBase.cy = camY;
        touchBase.ox = mid.x;
        touchBase.oy = mid.y;
    }
}, { passive: false });

canvas.addEventListener('touchmove', e => {
    e.preventDefault();
    const t = e.touches;
    if (t.length === 1) {
        if (editorMode && _edMouseDown) {
            const dx = t[0].clientX - paintStartX, dy = t[0].clientY - paintStartY;
            if (!isPainting && (Math.abs(dx) > 3 || Math.abs(dy) > 3)) {
                isPainting = true;
                if (_pendingSnapshot) { pushUndo(); _pendingSnapshot = false; }
            }
            if (isPainting) {
                const { r, c } = s2t(t[0].clientX, t[0].clientY);
                applyEditAt(r, c);
            }
        } else if (!editorMode && dragging) {
            camX = dragO.cx + (t[0].clientX - dragO.x);
            camY = dragO.cy + (t[0].clientY - dragO.y);
        }
        const { r, c } = s2t(t[0].clientX, t[0].clientY);
        hovR = r;
        hovC = c;
    } else if (t.length === 2) {
        const d = touchDist(t[0], t[1]);
        const mid = touchMid(t[0], t[1]);
        const nz = Math.max(0.25, Math.min(3.5, touchBase.zoom * d / touchBase.dist));
        const scale = nz / touchBase.zoom;
        camX = mid.x + (touchBase.cx - touchBase.ox) * scale;
        camY = mid.y + (touchBase.cy - touchBase.oy) * scale;
        touchBase.ox = mid.x;
        touchBase.oy = mid.y;
        touchBase.cx = camX;
        touchBase.cy = camY;
        touchBase.dist = d;
        touchBase.zoom = nz;
        zoom = nz;
    }
}, { passive: false });

canvas.addEventListener('touchend', e => {
    e.preventDefault();
    if (e.touches.length === 0) {
        if (editorMode && _edMouseDown) {
            const t = e.changedTouches[0];
            const dx = t.clientX - paintStartX, dy = t.clientY - paintStartY;
            const moved = Math.abs(dx) > 3 || Math.abs(dy) > 3;
            if (!moved && _pendingSnapshot) {
                const { r, c } = s2t(t.clientX, t.clientY);
                pushUndo();
                applyEditAt(r, c);
            }
            if (isPainting || (!moved && _pendingSnapshot)) {
                rebuildMinimap();
                mapDirty = true;
                updateSaveButton();
            }
            isPainting = false;
            _edMouseDown = false;
            _pendingSnapshot = false;
        } else if (dragging) {
            const t = e.changedTouches[0];
            const moved = Math.abs(t.clientX - dragO.x) > 12 || Math.abs(t.clientY - dragO.y) > 12;
            if (!moved) {
                const { r, c } = s2t(t.clientX, t.clientY);
                if (r >= 0 && r < MH && c >= 0 && c < MW) {
                    selR = r;
                    selC = c;
                    const tile = map[r][c];
                    const ti = TINFO[tile.type];
                    document.getElementById('tile-name').textContent = `${ti.emoji} ${ti.name}`;
                    document.getElementById('tile-x').textContent = c;
                    document.getElementById('tile-y').textContent = r;
                    document.getElementById('tile-icon').textContent = ti.icon;
                    document.getElementById('tile-elev').textContent = `Altura ${tile.height}`;
                    document.getElementById('tile-deco').textContent = tile.dec || 'Sin deco';
                }
            }
        }
        dragging = false;
        canvas.classList.remove('drag');
        hovR = null;
        hovC = null;
    }
}, { passive: false });

// ============================================================
// D-PAD TÁCTIL
// ============================================================
const dpMap = { 'dp-u': 'w', 'dp-d': 's', 'dp-l': 'a', 'dp-r': 'd' };
Object.entries(dpMap).forEach(([id, key]) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('touchstart', e => { e.preventDefault(); keys[key] = true; }, { passive: false });
    el.addEventListener('touchend', e => { e.preventDefault(); keys[key] = false; }, { passive: false });
});

// ============================================================
// SERIALIZACIÓN
// ============================================================
function serializeMap() {
    return {
        title: document.getElementById('ed-title').value || 'Sin título',
        seed: SEED,
        w: MW,
        h: MH,
        map: map.flat().map(cell => [cell.type, cell.height, +cell.e.toFixed(4), cell.dec])
    };
}

function applyLoadedMap(data) {
    const newW = data.w || data.width || MW;
    const newH = data.h || data.height || MH;
    MW = newW;
    MH = newH;

    document.getElementById('ed-title').value = data.title || '';
    document.getElementById('h-size').textContent = MW + '×' + MH;

    map = [];
    for (let r = 0; r < MH; r++) {
        map[r] = [];
        for (let c = 0; c < MW; c++) {
            const idx = r * MW + c;
            const cell = data.map[idx];
            if (Array.isArray(cell)) {
                map[r][c] = { type: cell[0], height: cell[1], e: cell[2], dec: cell[3] || null };
            } else {
                map[r][c] = { type: T.GRASS, height: 1, e: 0.25, dec: null };
            }
        }
    }

    MM_PX = Math.max(2, Math.floor(160 / Math.max(MW, MH)));
    mmOff.width = MW * MM_PX;
    mmOff.height = MH * MM_PX;
    mmCanvas.width = MW * MM_PX;
    mmCanvas.height = MH * MM_PX;

    rebuildMinimap();
    resetCam();

    undoStack.length = 0;
    redoStack.length = 0;
    updateUndoButtons();
    mapDirty = false;
    updateSaveButton();

    showToast('✓ Mapa cargado');
}

// ============================================================
// API Y ALMACENAMIENTO
// ============================================================
async function saveMap() {
    const title = document.getElementById('ed-title').value.trim() || 'Sin título';
    const data = serializeMap();
    data.title = title;
    try {
        const res = await fetch('isocraft_api.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const json = await res.json();
        if (json.ok) {
            mapDirty = false;
            updateSaveButton();
            showToast('✓ Mapa guardado');
        } else {
            showToast('✗ Error: ' + (json.error || 'desconocido'));
        }
    } catch (err) {
        showToast('✗ Error de red');
    }
}

async function openLoadModal() {
    document.getElementById('load-modal').style.display = 'flex';
    const body = document.getElementById('load-modal-body');
    body.innerHTML = '<div style="color:#8ab; text-align:center;">Cargando...</div>';
    try {
        const res = await fetch('isocraft_api.php?action=list');
        const maps = await res.json();
        if (!maps.length) {
            body.innerHTML = '<div style="color:#8ab; text-align:center;">No hay mapas guardados</div>';
            return;
        }
        body.innerHTML = maps.map(m => `
            <div style="background:#1a2a40; border-radius:10px; padding:12px; display:flex; align-items:center; gap:10px;">
                <div style="flex:1;">
                    <div style="color:#fff; font-weight:bold;">${m.title}</div>
                    <div style="color:#8ab; font-size:11px;">${m.w}×${m.h} · ${m.saved}</div>
                </div>
                <div style="display:flex; gap:5px;">
                    <button onclick="loadMapFromServer('${m.file}')" style="background:#2a3a50; border:none; color:#fff; padding:5px 10px; border-radius:5px; cursor:pointer;">📂</button>
                    <button onclick="deleteMapFromServer('${m.file}',this)" style="background:#4a2a2a; border:none; color:#ff6b6b; padding:5px 10px; border-radius:5px; cursor:pointer;">🗑</button>
                </div>
            </div>`).join('');
    } catch (err) {
        body.innerHTML = '<div style="color:#ff6b6b; text-align:center;">Error cargando lista</div>';
    }
}

function closeLoadModal() {
    document.getElementById('load-modal').style.display = 'none';
}

async function loadMapFromServer(file) {
    try {
        const res = await fetch('isocraft_api.php?action=load&file=' + encodeURIComponent(file));
        const data = await res.json();
        if (data.error) { showToast('✗ ' + data.error); return; }
        applyLoadedMap(data);
        closeLoadModal();
    } catch (err) {
        showToast('✗ Error cargando mapa');
    }
}

async function deleteMapFromServer(file, btn) {
    if (!confirm('¿Eliminar este mapa?')) return;
    try {
        const res = await fetch('isocraft_api.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ file })
        });
        const json = await res.json();
        if (json.ok) {
            btn.closest('div').closest('div').remove();
            showToast('✓ Mapa eliminado');
        }
    } catch (err) {
        showToast('✗ Error');
    }
}

function exportMap() {
    const data = serializeMap();
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (data.title || 'mapa').replace(/\s+/g, '_') + '.json';
    a.click();
    URL.revokeObjectURL(url);
    showToast('⬇ Mapa exportado');
}

function importMap() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json';
    input.onchange = e => {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = ev => {
            try {
                applyLoadedMap(JSON.parse(ev.target.result));
                showToast('✓ Mapa importado');
            } catch (err) {
                showToast('✗ JSON inválido');
            }
        };
        reader.readAsText(file);
    };
    input.click();
}

function newMap() {
    const title = prompt('Título del nuevo mapa:', 'Nuevo Mapa');
    if (title === null) return;
    const wStr = prompt('Ancho (20-60):', '40');
    if (wStr === null) return;
    const hStr = prompt('Alto (20-60):', '40');
    if (hStr === null) return;
    const w = Math.min(60, Math.max(20, parseInt(wStr) || 40));
    const h = Math.min(60, Math.max(20, parseInt(hStr) || 40));
    const seed = Math.floor(Math.random() * 999999);
    location.href = `?seed=${seed}&w=${w}&h=${h}&title=${encodeURIComponent(title.substring(0, 64))}`;
}

// ============================================================
// UI Y TOGGLES
// ============================================================
function toggleBottomMenu() {
    const menu = document.getElementById('bottom-menu');
    const btn = document.getElementById('menu-btn');
    const open = menu.classList.toggle('open');
    btn.classList.toggle('open', open);
    btn.innerHTML = open ? '<span>⚙</span> CERRAR <span>▲</span>' : '<span>⚙</span> OPCIONES <span>▼</span>';
}

document.addEventListener('click', e => {
    const bar = document.getElementById('panel-right');
    if (bar && !bar.contains(e.target)) {
        document.getElementById('bottom-menu').classList.remove('open');
        const btn = document.getElementById('menu-btn');
        if (btn) btn.innerHTML = '<span>⚙</span> OPCIONES <span>▼</span>';
    }
});

function toggleWaterAnim() {
    waterAnimEnabled = !waterAnimEnabled;
    const btn = document.getElementById('opt-water');
    btn.textContent = waterAnimEnabled ? 'ON' : 'OFF';
    btn.classList.toggle('on', waterAnimEnabled);
    showToast(waterAnimEnabled ? '💧 Agua animada ON' : '💧 Agua animada OFF');
}

function toggleDayNight() {
    dayNightEnabled = !dayNightEnabled;
    const btn = document.getElementById('opt-day');
    btn.textContent = dayNightEnabled ? 'ON' : 'OFF';
    btn.classList.toggle('on', dayNightEnabled);
    showToast(dayNightEnabled ? '🌓 Ciclo día/noche ON' : '🌓 Ciclo día/noche OFF');
}

// ============================================================
// INICIALIZACIÓN UI
// ============================================================
(function initEditorUI() {
    const swatchData = [
        { type: T.DEEP, color: '#0e2244', label: 'Agua Profunda' },
        { type: T.WATER, color: '#1a4a8a', label: 'Agua' },
        { type: T.SAND, color: '#c8a96e', label: 'Arena' },
        { type: T.GRASS, color: '#5a8a3c', label: 'Césped' },
        { type: T.JUNGLE, color: '#1a5018', label: 'Jungla' },
        { type: T.ROCK, color: '#6a5a4a', label: 'Roca' },
        { type: T.MOUNTAIN, color: '#9a9080', label: 'Montaña' },
        { type: T.SNOW, color: '#dce8f8', label: 'Nieve' },
    ];
    const row = document.getElementById('ed-swatches');
    swatchData.forEach(({ type, color, label }) => {
        const el = document.createElement('div');
        el.className = 'ed-swatch';
        el.style.background = color;
        el.style.padding = '10px';
        el.style.borderRadius = '8px';
        el.style.border = type === paintType ? '3px solid #fff' : '1px solid #4a5a70';
        el.style.cursor = 'pointer';
        el.title = label;
        el.onclick = () => {
            paintType = type;
            setTool('paint');
            document.querySelectorAll('.ed-swatch').forEach(s => s.style.border = '1px solid #4a5a70');
            el.style.border = '3px solid #fff';
        };
        row.appendChild(el);
    });

    document.getElementById('ed-title').value = mapTitle;
})();

