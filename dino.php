<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jurassic RTS/RPG - Guerra de Colmenas V.27</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { margin: 0; overflow: hidden; background: #010409; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: white; }
        canvas#gameCanvas { display: block; cursor: crosshair; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 1; }
        
        #ui-layer { pointer-events: none; position: absolute; inset: 0; display: flex; color: white; z-index: 10; }
        .interactive { pointer-events: auto; }
        .glass { background: rgba(13, 17, 23, 0.9); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.1); }
        
        #minimap-container { width: 220px; height: 220px; background: #000; position: relative; overflow: hidden; border-radius: 12px; border: 2px solid rgba(255,255,255,0.2); box-shadow: 0 0 20px rgba(0,0,0,0.8); pointer-events: auto; }
        #minimap-canvas { width: 100%; height: 100%; cursor: pointer; image-rendering: pixelated; }

        .speed-btn { transition: all 0.2s; border: 1px solid rgba(255,255,255,0.1); font-size: 10px; color: white; padding: 4px; border-radius: 4px; }
        .speed-btn.active { background: #22c55e; color: black; border-color: #22c55e; font-weight: bold; }

        .btn-caste { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); transition: all 0.2s; text-align: center; padding: 8px; border-radius: 10px; color: white; width: 100%; cursor: pointer; }
        .btn-caste:hover { background: rgba(34, 197, 94, 0.2); border-color: #22c55e; transform: translateY(-2px); }
        .btn-caste:disabled { opacity: 0.3; cursor: not-allowed; }

        .cmd-btn { background: rgba(59, 130, 246, 0.2); border: 1px solid rgba(255, 255, 255, 0.2); font-size: 9px; padding: 6px 10px; border-radius: 6px; text-transform: uppercase; font-weight: bold; color: white; cursor: pointer; }
        .cmd-btn.active { background: #3b82f6; border-color: #60a5fa; box-shadow: 0 0 10px rgba(59, 130, 246, 0.4); }

        #gene-lab { position: fixed; right: -600px; top: 0; bottom: 0; width: 550px; transition: right 0.5s cubic-bezier(0.4, 0, 0.2, 1); z-index: 1000; overflow-y: auto; background: rgba(7, 15, 35, 0.98); border-left: 1px solid rgba(59, 130, 246, 0.3); box-shadow: -15px 0 50px rgba(0,0,0,0.9); pointer-events: auto; }
        #gene-lab.open { right: 0; }
        .tree-branch { margin-bottom: 30px; position: relative; padding-left: 20px; border-left: 2px solid rgba(59, 130, 246, 0.2); }
        .tree-branch-title { font-size: 11px; font-weight: 900; color: #3b82f6; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 10px; display: block; }
        .gene-node { background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 10px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; width: 220px; display: inline-block; margin-right: 10px; vertical-align: top; }
        .gene-node:hover { border-color: #22c55e; background: rgba(34, 197, 94, 0.1); }
        .gene-node.unlocked { border-color: #3b82f6; background: rgba(59, 130, 246, 0.1); pointer-events: none; opacity: 0.6; }
        .gene-node.locked { opacity: 0.2; filter: grayscale(1); cursor: not-allowed; }

        #start-screen, #game-over { position: fixed; inset: 0; background: #020617; z-index: 2000; display: flex; align-items: center; justify-content: center; color: white; }
        #game-over { background: rgba(220, 38, 38, 0.7); backdrop-filter: blur(15px); display: none; flex-direction: column; }
        
        #attack-alert { position: absolute; top: 20px; left: 50%; transform: translateX(-50%); padding: 12px 24px; background: rgba(220, 38, 38, 0.95); border: 2px solid #ef4444; border-radius: 99px; color: white; font-weight: 900; font-size: 14px; opacity: 0; pointer-events: none; transition: opacity 0.3s; z-index: 500; text-shadow: 0 0 10px rgba(0,0,0,0.5); }
        #attack-alert.visible { opacity: 1; animation: blink 0.5s infinite alternate; }
        @keyframes blink { from { opacity: 1; transform: translateX(-50%) scale(1); } to { opacity: 0.7; transform: translateX(-50%) scale(1.05); } }

        #selection-box { position: absolute; border: 2px solid #22c55e; background: rgba(34, 197, 94, 0.2); pointer-events: none; display: none; z-index: 100; }
        .evolution-tag { background: rgba(34, 197, 94, 0.2); border: 1px solid #22c55e; padding: 2px 6px; border-radius: 4px; font-size: 8px; display: inline-block; margin: 2px; text-transform: uppercase; color: #4ade80; }
    </style>
</head>
<body>

    <div id="selection-box"></div>

    <div id="start-screen">
        <div class="glass p-10 rounded-3xl w-full max-w-lg shadow-2xl text-center">
            <h1 class="text-5xl font-black text-green-500 italic mb-8 tracking-tighter uppercase text-white text-center">Guerra de Colmenas</h1>
            <div class="mb-6 text-left">
                <label class="text-[10px] uppercase font-bold text-white mb-2 block tracking-widest text-white">Nombre de tu Especie</label>
                <input type="text" id="species-name" placeholder="Ej: Xeno-Saur..." maxlength="20" class="bg-slate-900 border border-slate-700 p-3 rounded-lg w-full outline-none focus:border-green-500 text-center text-white font-bold">
            </div>
            <div class="mb-8 text-left">
                <label class="text-[10px] uppercase font-bold text-white mb-2 block tracking-widest text-white">Biología Inicial</label>
                <select id="species-diet" class="bg-slate-900 border border-slate-700 p-3 rounded-lg w-full outline-none focus:border-green-500 text-white font-bold">
                    <option value="carnivore">Carnívoro (Bonus de Ataque)</option>
                    <option value="herbivore">Herbívoro (Bonus de Defensa)</option>
                    <option value="omnivore" selected>Omnívoro (Híbrido)</option>
                </select>
            </div>
            <button onclick="startGame()" class="w-full bg-green-600 hover:bg-green-500 text-white font-black py-4 rounded-2xl transition-all shadow-xl text-lg uppercase tracking-widest">Fundar Colmena</button>
        </div>
    </div>

    <div id="game-over">
        <h1 class="text-7xl font-black text-white italic mb-4 uppercase tracking-tighter text-center">NEXO DESTRUIDO</h1>
        <p class="text-xl text-white mb-8 font-bold opacity-90 uppercase tracking-widest text-center">Tu Reina ha muerto. La especie se extingue.</p>
        <button onclick="location.reload()" class="bg-white text-black font-black px-12 py-4 rounded-2xl hover:bg-slate-200 transition-all uppercase tracking-widest">Reiniciar Génesis</button>
    </div>

    <div id="gene-lab" class="p-8 interactive">
        <div class="flex justify-between items-center mb-8 text-white">
            <h2 class="text-2xl font-black text-blue-400 italic uppercase">Estructura Genética</h2>
            <button onclick="toggleLab()" class="bg-blue-600/20 hover:bg-red-500 p-2 px-6 rounded-full text-white font-bold transition-all uppercase text-[10px] interactive border border-blue-500/50">Cerrar ✕</button>
        </div>
        
        <div id="tree-content">
            <div class="tree-branch"><span class="tree-branch-title">Rama de Combate</span><div id="branch-combat"></div></div>
            <div class="tree-branch"><span class="tree-branch-title">Rama de Supervivencia</span><div id="branch-survival"></div></div>
            <div class="tree-branch"><span class="tree-branch-title">Rama de Adaptación</span><div id="branch-utility"></div></div>
            <div class="tree-branch"><span class="tree-branch-title">Mutaciones Prohibidas</span><div id="branch-special"></div></div>
        </div>
    </div>

    <div id="attack-alert">⚠️ COLMENA BAJO ATAQUE ⚠️</div>

    <div id="ui-layer">
        <div class="flex-1 flex flex-col justify-between p-6">
            <div class="flex gap-4">
                <div class="glass px-6 py-3 rounded-2xl interactive flex gap-6 shadow-xl border-t border-white/10 text-xs font-bold items-center text-white">
                    <div class="flex items-center gap-2">
                        <span class="text-blue-400 uppercase font-black text-[10px]">ADN:</span>
                        <span id="res-dna" class="font-mono text-base text-white">0</span>
                    </div>
                    <div class="flex items-center gap-2 border-l border-white/20 pl-4">
                        <span class="text-red-500 uppercase text-[10px]">CARNE:</span>
                        <span id="res-meat" class="font-mono text-base text-white">0</span>
                    </div>
                    <div class="flex items-center gap-2 border-l border-white/20 pl-4">
                        <span class="text-green-500 uppercase text-[10px]">HIERBA:</span>
                        <span id="res-grass" class="font-mono text-base text-white">0</span>
                    </div>
                    <div class="flex items-center gap-2 border-l border-white/20 pl-4">
                        <span class="text-slate-400 uppercase text-[10px]">PIEDRA:</span>
                        <span id="res-stone" class="font-mono text-base text-white">0</span>
                    </div>
                    <div class="flex items-center gap-2 border-l border-white/20 pl-4">
                        <span class="text-yellow-400 uppercase text-[10px]">POB:</span>
                        <span id="res-pop" class="font-mono text-base text-white">0 / 6</span>
                    </div>
                    <button onclick="toggleLab()" class="ml-4 bg-blue-600 hover:bg-blue-500 px-4 py-2 rounded-xl text-white text-[10px] font-black uppercase shadow-lg animate-pulse interactive">🧬 Mutar Especie</button>
                </div>
            </div>

            <div id="action-panel" class="glass p-6 w-full max-w-6xl rounded-3xl flex gap-6 interactive opacity-0 transition-all transform translate-y-8 text-white">
                <div class="w-20 h-20 bg-slate-900/50 rounded-2xl border border-white/20 flex items-center justify-center text-5xl shadow-inner text-white" id="entity-icon">🦎</div>
                <div class="flex-1 flex flex-col justify-center text-white">
                    <div class="flex justify-between items-center mb-1">
                        <h2 id="entity-name" class="text-lg font-black uppercase tracking-tight text-green-400 text-white">---</h2>
                        <span id="ui-spec-name-panel" class="text-[10px] font-bold text-white/50 uppercase tracking-widest text-white">---</span>
                    </div>
                    <div id="entity-stats" class="space-y-2 text-white">
                        <div class="h-2.5 bg-slate-800 rounded-full overflow-hidden border border-white/5">
                            <div id="entity-hp-bar" class="h-full bg-gradient-to-r from-green-600 to-emerald-400 w-full transition-all"></div>
                        </div>
                        <div id="stat-grid" class="grid grid-cols-4 gap-4 text-[10px] font-black uppercase text-white text-white"></div>
                    </div>
                    <div id="queen-actions" class="hidden mt-4 grid grid-cols-6 gap-2 text-white">
                        <button onclick="spawnEgg('worker')" id="btn-spawn-worker" class="btn-caste"><div class="text-green-400 font-black text-[10px]">OBRERO</div><div class="text-[8px]">50C/50H</div></button>
                        <button onclick="spawnEgg('warrior')" id="btn-spawn-warrior" class="btn-caste"><div class="text-red-500 font-black text-[10px]">SOLDADO</div><div class="text-[8px]">180C</div></button>
                        <button onclick="spawnEgg('guardian')" id="btn-spawn-guardian" class="btn-caste"><div class="text-blue-400 font-black text-[10px]">GUARDIÁN</div><div class="text-[8px]">150C</div></button>
                        <button onclick="spawnEgg('explorer')" id="btn-spawn-explorer" class="btn-caste"><div class="text-orange-400 font-black text-[10px]">EXPLORADOR</div><div class="text-[8px]">120C</div></button>
                        <button onclick="buildStructure('shelter')" id="btn-build-shelter" class="btn-caste border-yellow-500/40 text-white"><div class="text-yellow-400 font-black text-[10px]">REFUGIO</div><div class="text-[8px]">150P</div></button>
                        <button onclick="buildStructure('tower')" id="btn-build-tower" class="btn-caste border-blue-500/40 text-white"><div class="text-blue-400 font-black text-[10px]">TORRE</div><div class="text-[8px]">200C/150P</div></button>
                    </div>
                    <div id="unit-commands" class="hidden mt-4 flex flex-wrap gap-2 text-white">
                        <button onclick="setBehavior('auto_collect')" class="cmd-btn" id="cmd-collect">Recolectar</button>
                        <button onclick="setBehavior('auto_attack')" class="cmd-btn" id="cmd-attack">Cazar</button>
                        <button onclick="setBehavior('manual')" class="cmd-btn" id="cmd-manual">Manual</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="w-72 glass h-full border-l border-white/10 flex flex-col interactive shadow-2xl text-white">
            <div class="p-4">
                <div class="text-[10px] font-black uppercase tracking-widest text-green-500 mb-3 flex justify-between text-white">
                    <span>Scanner Táctico</span>
                    <span class="opacity-20 text-white">V.27</span>
                </div>
                <div id="minimap-container">
                    <canvas id="minimap-canvas"></canvas>
                </div>
                <div class="mt-4 border-t border-white/10 pt-3 text-white">
                    <h3 class="text-[10px] font-black text-blue-400 uppercase mb-2">Mutaciones</h3>
                    <div id="evolution-tracker" class="flex flex-wrap h-20 overflow-y-auto content-start"></div>
                </div>
                <div id="wave-timer-ui" class="mt-3 text-[10px] text-center text-red-400 font-black uppercase tracking-widest text-white">Invasión: 02:00</div>
            </div>
            <div class="flex-1 p-4 overflow-y-auto space-y-3 border-t border-white/10 mt-2 text-white">
                <div id="log-container" class="space-y-2 text-[10px] font-mono text-white/80"></div>
            </div>
            <div class="p-6 bg-black/40 border-t border-white/10 text-center font-mono text-2xl font-bold text-white">
                <div id="game-time">00:00</div>
            </div>
        </div>
    </div>

    <canvas id="gameCanvas"></canvas>

    <script>
        const canvas = document.getElementById('gameCanvas');
        const ctx = canvas.getContext('2d');
        const minimapCanvas = document.getElementById('minimap-canvas');
        minimapCanvas.width = 220; minimapCanvas.height = 220;
        const mctx = minimapCanvas.getContext('2d');
        const selBox = document.getElementById('selection-box');

        const MAP_SIZE = 80, START_POS = { x: 10, y: 10 }, ENEMY_HIVE_POS = { x: 70, y: 70 };
        const TILE_WIDTH = 64, TILE_HEIGHT = 32;
        let CAMERA_SPEED = 22, HEAL_RADIUS = 8, PROTECTION_RADIUS = 15;

        const terrainTiles = [];
        const TILE_ROWS = 6, TILE_COLS = 16;
        let tilesLoaded = 0;

        const sprites = {
            units: {},
            enemies: {},
            fauna: {},
            resources: {},
            structures: {},
            misc: {}
        };

        function loadSprites() {
            const unitTypes = ['worker', 'warrior', 'guardian', 'explorer', 'boss', 'queen'];
            unitTypes.forEach(type => {
                const img = new Image();
                img.onerror = () => {};
                img.src = `assets/sprites/units/${type}.png`;
                sprites.units[type] = img;
                const imgE = new Image();
                imgE.onerror = () => {};
                imgE.src = `assets/sprites/enemies/${type}.png`;
                sprites.enemies[type] = imgE;
            });

            const faunaTypes = ['small', 'medium', 'large'];
            faunaTypes.forEach(type => {
                const img = new Image();
                img.onerror = () => {};
                img.src = `assets/sprites/fauna/${type}.png`;
                sprites.fauna[type] = img;
            });

            const resTypes = ['meat', 'grass', 'stone', 'dna'];
            resTypes.forEach(type => {
                const img = new Image();
                img.onerror = () => {};
                img.src = `assets/sprites/resources/${type}.png`;
                sprites.resources[type] = img;
            });

            ['shelter', 'tower'].forEach(type => {
                const img = new Image();
                img.onerror = () => {};
                img.src = `assets/sprites/structures/${type}.png`;
                sprites.structures[type] = img;
            });

            ['egg', 'nexus', 'hive'].forEach(type => {
                const img = new Image();
                img.onerror = () => {};
                img.src = `assets/sprites/misc/${type}.png`;
                sprites.misc[type] = img;
            });
        }
        loadSprites();

        function loadTiles() {
            for (let i = 0; i < TILE_ROWS; i++) {
                for (let j = 0; j < TILE_COLS; j++) {
                    const img = new Image();
                    const r = i.toString().padStart(2, '0');
                    const c = j.toString().padStart(2, '0');
                    img.src = `assets/tiles/tile_${r}_${c}.png`;
                    img.onload = () => tilesLoaded++;
                    terrainTiles.push(img);
                }
            }
        }
        loadTiles();

        let gTimer = 0, waveTimer = 120, waveCount = 0, isGameStarted = false, frameCount = 0, gameSpeed = 1.0, zoom = 1.0;
        
        // --- CANTIDADES INICIALES AJUSTADAS (V.27) ---
        let res = { dna: 50, meat: 200, grass: 100, stone: 100 }; 
        let speciesConfig = { name: "Xeno-Saur", diet: "omnivore", color: "#22c55e" };
        
        let maxPopulation = 6, sheltersCount = 0, structures = [];
        let particles = [];
        let activeMutations = [];
        let camera = { x: 0, y: 0 }, keys = {};
        let map = [], eggs = [], units = [], npcs = [], items = [];
        let selection = { type: null, data: [] }; 
        let dragStart = null;
        let queen = { hp: 5000, maxHp: 5000, x: START_POS.x, y: START_POS.y, lastHitTime: 0 };

        let audioCtx = null;
        function playSfx(freq, type = 'sine', duration = 0.1, vol = 0.05) {
            try {
                if(!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain();
                osc.type = type; osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
                gain.gain.setValueAtTime(vol, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + duration);
                osc.connect(gain); gain.connect(audioCtx.destination);
                osc.start(); osc.stop(audioCtx.currentTime + duration);
            } catch(e) {}
        }

        const CASTE_TYPES = {
            worker: { name: 'Obrero', hp: 120, atk: 12, spd: 0.18, cap: 150, size: 4, cost: {m:50, g:50} },
            warrior: { name: 'Soldado', hp: 400, atk: 75, spd: 0.14, cap: 30, size: 7, cost: {m:180, g:0} },
            guardian: { name: 'Guardián', hp: 1500, atk: 55, spd: 0.08, cap: 20, size: 10, cost: {m:150, g:0} },
            explorer: { name: 'Explorador', hp: 100, atk: 10, spd: 0.45, cap: 15, size: 4, cost: {m:120, g:0} }
        };

        const GENE_TREE_BRANCHES = {
            combat: [
                { id: 'combat_1', name: 'Escamas I', cost: 100, eff: {hp: 1.2}, desc: '+20% Vida', req: null },
                { id: 'combat_2', name: 'Fauces I', cost: 150, eff: {atk: 1.2}, desc: '+20% Atk', req: 'combat_1' },
                { id: 'combat_3', name: 'Escamas II', cost: 250, eff: {hp: 1.4}, desc: '+40% Vida', req: 'combat_2' }
            ],
            survival: [
                { id: 'survival_1', name: 'Pulmones', cost: 100, eff: {spd: 1.1}, desc: '+10% Vel', req: null },
                { id: 'survival_2', name: 'Reflejos', cost: 200, eff: {spd: 1.2}, desc: '+20% Vel', req: 'survival_1' }
            ],
            utility: [
                { id: 'utility_1', name: 'Carga I', cost: 120, eff: {cap: 1.5}, desc: '+50% Carga', req: null },
                { id: 'utility_2', name: 'Olfato', cost: 200, eff: {harvest: 1.5}, desc: '+50% Efic.', req: 'utility_1' }
            ],
            special: [
                { id: 'vamp', name: 'Vampirismo', cost: 600, desc: 'Sana al atacar', req: 'combat_2' },
                { id: 'wings', name: 'Alas', cost: 800, desc: 'Velocidad aérea', req: 'survival_2' }
            ]
        };

        function isoToScreen(gx, gy) { return { x: (gx - gy) * (TILE_WIDTH / 2) * zoom + camera.x, y: (gx + gy) * (TILE_HEIGHT / 2) * zoom + camera.y }; }
        function screenToIso(sx, sy) {
            let rx = (sx - camera.x) / zoom, ry = (sy - camera.y) / zoom;
            return { x: (rx / (TILE_WIDTH / 2) + ry / (TILE_HEIGHT / 2)) / 2, y: (ry / (TILE_HEIGHT / 2) - rx / (TILE_WIDTH / 2)) / 2 };
        }
        function centerOn(gx, gy) { camera.x = (window.innerWidth / 2 - (gx - gy) * (TILE_WIDTH / 2) * zoom); camera.y = (window.innerHeight / 2 - (gx + gy) * (TILE_HEIGHT / 2) * zoom); }

        function createUnit(cKey, x, y, isEnemy = false) {
            const c = CASTE_TYPES[cKey] || { hp: 5000, atk: 200, spd: 0.1, cap: 0, size: 20 };
            const behavior = (cKey === 'worker' || cKey === 'explorer') ? 'manual' : 'auto_attack';
            const u = { id: Date.now()+Math.random(), caste: cKey, name: c.name || 'Jefe', color: isEnemy ? "#facc15" : speciesConfig.color, x, y, targetX: x, targetY: y, hp: c.hp, maxHp: c.hp, atk: c.atk, spd: c.spd, capacity: c.cap || 0, size: c.size, cargo: {amount:0, type: 'none'}, behavior, lastHitTime: 0, targetEnemy: null, isHiveEnemy: isEnemy, jobNode: null, animTimer: 0 };
            if(isEnemy) { u.hp *= 2.0; u.atk *= 2.2; u.targetX = START_POS.x; u.targetY = START_POS.y; }
            units.push(u);
            if(!isEnemy) playSfx(400, 'triangle', 0.2);
        }

        function spawnInitialColony() { 
            createUnit('worker', START_POS.x+2, START_POS.y); 
            createUnit('warrior', START_POS.x, START_POS.y+2); 
        }

        function spawnWave() {
            waveCount++;
            if(waveCount === 1) {
                // --- OLA 1: SOLO 3 ENEMIGOS ---
                addLog("⚠️ INCURSIÓN DE RECONOCIMIENTO (Ola 1) ⚠️");
                for(let i=0; i<3; i++) createUnit('warrior', ENEMY_HIVE_POS.x + Math.random()*2, ENEMY_HIVE_POS.y + Math.random()*2, true);
            } else {
                addLog(`⚠️ INVASIÓN NIVEL ${waveCount} DETECTADA ⚠️`);
                const q = 4 + waveCount;
                for(let i=0; i<q; i++) createUnit('warrior', ENEMY_HIVE_POS.x + Math.random()*5, ENEMY_HIVE_POS.y + Math.random()*5, true);
                const bossScale = 1 + (waveCount * 0.1);
                units.push({ id: Date.now(), caste: 'boss', name: 'Devorador', color: '#000', x: ENEMY_HIVE_POS.x, y: ENEMY_HIVE_POS.y, targetX: START_POS.x, targetY: START_POS.y, hp: 5000*bossScale, maxHp: 5000*bossScale, atk: 300*bossScale, spd: 0.08, size: 24*bossScale, cargo: null, behavior: 'auto_attack', isHiveEnemy: true, lastHitTime: 0, animTimer: 0 });
            }
            waveTimer = 120; playSfx(60, 'sawtooth', 1.0, 0.2);
        }

        function buildStructure(type) {
            if(type === 'shelter' && res.stone >= 150 && sheltersCount < 5) {
                res.stone -= 150; sheltersCount++; maxPopulation += 5;
                structures.push({ type: 'shelter', x: START_POS.x + Math.cos(Math.random()*7)*5, y: START_POS.y + Math.sin(Math.random()*7)*5 });
                addLog(`Refugio construido.`); playSfx(150, 'square', 0.2);
            } else if(type === 'tower' && res.meat >= 200 && res.stone >= 150) {
                res.meat -= 200; res.stone -= 150;
                structures.push({ type: 'tower', x: START_POS.x + Math.cos(Math.random()*7)*7, y: START_POS.y + Math.sin(Math.random()*7)*7, lastShot: 0, atk: 80, range: 14, animTimer: 0 });
                addLog(`Torre de defensa alzada.`); playSfx(200, 'square', 0.3);
            }
        }

        function setBehavior(b) {
            if (selection.type === 'unit' && selection.data.length > 0) {
                selection.data.forEach(u => { u.behavior = b; u.targetEnemy = null; u.jobNode = null; if (b === 'manual') { u.targetX = u.x; u.targetY = u.y; } });
                playSfx(600, 'sine', 0.05);
            }
        }

        function spawnBlood(gx, gy) {
            for(let i=0; i<10; i++) particles.push({ x: gx, y: gy, vx: (Math.random()-0.5)*0.25, vy: (Math.random()-0.5)*0.25, life: 60, size: 2 + Math.random()*2 });
        }

        function drawDino(u, s) {
            let img = sprites.units[u.caste];
            if (u.isHiveEnemy) {
                img = sprites.enemies[u.caste] || sprites.enemies['boss'];
            } else if (u.type) { // Es Fauna Neutral
                img = sprites.fauna[u.type];
            }

            const isMov = Math.hypot(u.targetX - u.x, u.targetY - u.y) > 0.2;
            const actionAnim = Math.sin(u.animTimer * 0.5) * 6; 
            
            ctx.save();
            ctx.translate(s.x, s.y + 12*zoom);
            
            // Sombra
            ctx.fillStyle = 'rgba(0,0,0,0.3)';
            ctx.beginPath();
            ctx.ellipse(0, 0, u.size * 2 * zoom, u.size * zoom, 0, 0, Math.PI * 2);
            ctx.fill();

            if(u.targetX < u.x) ctx.scale(-1, 1);

            if(img && img.complete && img.naturalWidth > 1) {
                const dw = u.size * 10 * zoom;
                const dh = u.size * 10 * zoom;
                ctx.drawImage(img, -dw/2, -dh + (actionAnim * zoom), dw, dh);
            } else {
                // Fallback mejorado
                const legAnim = isMov ? Math.sin(frameCount * 0.2) * 8 : 0;
                ctx.fillStyle = u.color || '#fff';
                ctx.beginPath(); 
                ctx.ellipse(0, -u.size*zoom, u.size*2*zoom, u.size*1.2*zoom, 0, 0, Math.PI*2); 
                ctx.fill();
            }
            ctx.restore();

            if(u.selected) { ctx.beginPath(); ctx.ellipse(s.x, s.y+12*zoom, 25*zoom, 12*zoom, 0, 0, Math.PI*2); ctx.strokeStyle = '#22c55e'; ctx.lineWidth = 3; ctx.stroke(); }
            if(u.targetEnemy || Date.now() - u.lastHitTime < 1000) {
                const r = 6*zoom; ctx.strokeStyle = '#ef4444'; ctx.lineWidth = 2*zoom;
                ctx.beginPath(); ctx.moveTo(s.x-r, s.y-35*zoom-r); ctx.lineTo(s.x+r, s.y-35*zoom+r);
                ctx.moveTo(s.x+r, s.y-35*zoom-r); ctx.lineTo(s.x-r, s.y-35*zoom+r); ctx.stroke();
            }
            ctx.fillStyle = '#000'; ctx.fillRect(s.x-15*zoom, s.y-25*zoom, 30*zoom, 4*zoom);
            ctx.fillStyle = u.isHiveEnemy ? '#facc15' : (u.loot ? '#4ade80' : '#22c55e');
            if(u.hp < u.maxHp * 0.35) ctx.fillStyle = '#ef4444';
            ctx.fillRect(s.x-15*zoom, s.y-25*zoom, Math.max(0, (u.hp/u.maxHp)*30*zoom), 4*zoom);
        }

        function startGame() {
            const n = document.getElementById('species-name').value;
            if(n) speciesConfig.name = n;
            speciesConfig.diet = document.getElementById('species-diet').value;
            speciesConfig.color = speciesConfig.diet === 'carnivore' ? "#ef4444" : speciesConfig.diet === 'herbivore' ? "#10b981" : "#8b5cf6";
            canvas.width = window.innerWidth; canvas.height = window.innerHeight;
            document.getElementById('start-screen').style.display = 'none';
            document.getElementById('ui-spec-name-panel').innerText = speciesConfig.name;
            initMap(); spawnInitialColony(); centerOn(START_POS.x, START_POS.y); isGameStarted = true; animate();
            playSfx(100, 'sawtooth', 0.5);
        }

        function initMap() {
            for (let i = 0; i < MAP_SIZE; i++) {
                map[i] = [];
                for (let j = 0; j < MAP_SIZE; j++) {
                    const n = Math.sin(i * 0.1) * Math.cos(j * 0.1);
                    // Seleccionar fila de tiles basada en "bioma" (ruido)
                    let row = 0; // Verde
                    if (n > 0.4) row = 2; // Nieve
                    else if (n < -0.3) row = 1; // Seco
                    
                    // Seleccionar una de las 16 variaciones de la fila aleatoriamente
                    const col = Math.floor(Math.random() * 16);
                    map[i][j] = { tileIdx: row * 16 + col };
                }
            }
            const clusters = 12;
            for(let k=0; k<clusters; k++) {
                const cx = Math.random() * MAP_SIZE, cy = Math.random() * MAP_SIZE;
                const type = Math.random() > 0.5 ? 'grass_node' : 'meat_node';
                for(let m=0; m<8; m++) {
                    const rx = cx + Math.random()*10-5, ry = cy + Math.random()*10-5;
                    if(rx > 0 && rx < MAP_SIZE && ry > 0 && ry < MAP_SIZE) items.push({ type, x: rx, y: ry, amount: 800 + Math.random()*800 });
                }
            }
            // Nodos de piedra (recurso no renovable para estructuras)
            for(let k=0; k<6; k++) {
                const cx = Math.random() * MAP_SIZE, cy = Math.random() * MAP_SIZE;
                for(let m=0; m<5; m++) {
                    const rx = cx + Math.random()*8-4, ry = cy + Math.random()*8-4;
                    if(rx > 0 && rx < MAP_SIZE && ry > 0 && ry < MAP_SIZE) items.push({ type: 'stone_node', x: rx, y: ry, amount: 600 + Math.random()*600 });
                }
            }
            spawnNPCs(40);
        }

        function spawnNPCs(count) {
            for(let k=0; k<count; k++) {
                const rx = Math.floor(Math.random()*MAP_SIZE), ry = Math.floor(Math.random()*MAP_SIZE);
                if (map[rx] && map[rx][ry] && Math.hypot(rx-START_POS.x, ry-START_POS.y) > 12) {
                    const r = Math.random();
                    if(r < 0.5) npcs.push({ type: 'small', name: 'Fugaz', hp: 120, maxHp: 120, atk: 0, spd: 0.15, loot: { dna: 40, meat: 80 }, color: '#4ade80', size: 3.5, x: rx, y: ry, targetX: rx, targetY: ry, animTimer: 0 });
                    else if(r < 0.8) npcs.push({ type: 'medium', name: 'Stomper', hp: 600, maxHp: 600, atk: 30, spd: 0.08, loot: { dna: 120, meat: 200 }, color: '#3b82f6', size: 5.5, x: rx, y: ry, targetX: rx, targetY: ry, aggroed: false, animTimer: 0 });
                    else npcs.push({ type: 'large', name: 'Behemoth', hp: 2000, maxHp: 2000, atk: 100, spd: 0.1, loot: { dna: 400, meat: 800 }, color: '#f87171', size: 10, x: rx, y: ry, targetX: rx, targetY: ry, territorial: true, animTimer: 0 });
                }
            }
        }

        function animate() { update(); render(); requestAnimationFrame(animate); }

        function update() {
            if(!isGameStarted) return;
            if(queen.hp <= 0) { document.getElementById('game-over').style.display = 'flex'; isGameStarted = false; return; }
            frameCount++;
            if(frameCount % 60 === 0) {
                waveTimer--;
                const wm = Math.floor(Math.max(0, waveTimer) / 60), ws = Math.max(0, waveTimer) % 60;
                const wt = document.getElementById('wave-timer-ui');
                if(wt) wt.innerText = `Invasión: ${String(wm).padStart(2,'0')}:${String(ws).padStart(2,'0')}`;
            }
            if(waveTimer <= 0) spawnWave();
            const scr = 15 * gameSpeed;
            if(keys['w']) camera.y += scr; if(keys['s']) camera.y -= scr;
            if(keys['a']) camera.x += scr; if(keys['d']) camera.x -= scr;
            let atkM=1, hpM=1, spdM=1, capM=1;
            activeMutations.forEach(id => {
                const searchNode = (branch) => GENE_TREE_BRANCHES[branch].find(n => n.id === id);
                const m = searchNode('combat') || searchNode('survival') || searchNode('utility') || searchNode('special');
                if(m && m.eff) { if(m.eff.atk) atkM*=m.eff.atk; if(m.eff.hp) hpM*=m.eff.hp; if(m.eff.spd) spdM*=m.eff.spd; if(m.eff.cap) capM*=m.eff.cap; }
            });

            // FÍSICA DE SEPARACIÓN
            const allEntities = [...units, ...npcs];
            for(let i=0; i<allEntities.length; i++){
                for(let j=i+1; j<allEntities.length; j++){
                    const u1 = allEntities[i], u2 = allEntities[j];
                    const dx = u1.x - u2.x, dy = u1.y - u2.y;
                    const d = Math.hypot(dx, dy);
                    const minD = (u1.size + u2.size) / 12;
                    if(d < minD && d > 0.01) { const push = (minD - d) * 0.08; u1.x += (dx/d)*push; u1.y += (dy/d)*push; u2.x -= (dx/d)*push; u2.y -= (dy/d)*push; }
                }
            }

            structures.forEach(st => {
                if(st.type === 'tower') {
                    if(frameCount - st.lastShot > 45) {
                        const target = units.find(u => u.isHiveEnemy && Math.hypot(u.x - st.x, u.y - st.y) < st.range);
                        if(target) { target.hp -= st.atk; st.lastShot = frameCount; st.animTimer = 10; spawnBlood(target.x, target.y); playSfx(300, 'sawtooth', 0.1); }
                    }
                    if(st.animTimer > 0) st.animTimer--;
                }
            });

            // Lógica de Unidades y BOTÍN
            for (let i = units.length - 1; i >= 0; i--) {
                const u = units[i];
                const dist = Math.hypot(u.targetX - u.x, u.targetY - u.y);
                let speed = u.spd * gameSpeed * (u.isHiveEnemy ? 1 : spdM);
                if(dist > 0.1) { u.x += ((u.targetX - u.x)/dist)*speed; u.y += ((u.targetY - u.y)/dist)*speed; }
                
                if(u.x <= 1 || u.x >= MAP_SIZE-1 || u.y <= 1 || u.y >= MAP_SIZE-1) {
                    u.x = Math.max(1.1, Math.min(MAP_SIZE - 1.1, u.x)); u.y = Math.max(1.1, Math.min(MAP_SIZE - 1.1, u.y));
                    u.targetX = MAP_SIZE/2; u.targetY = MAP_SIZE/2;
                }

                const dToQ = Math.hypot(u.x - START_POS.x, u.y - START_POS.y);
                if(!u.isHiveEnemy && dToQ < HEAL_RADIUS && u.hp < u.maxHp * hpM) u.hp = Math.min(u.maxHp * hpM, u.hp + (0.4 * gameSpeed));

                if(u.isHiveEnemy) {
                    const target = units.find(f => !f.isHiveEnemy && Math.hypot(f.x-u.x, f.y-u.y) < 15);
                    if(Math.hypot(u.x - START_POS.x, u.y - START_POS.y) < 3) { if(frameCount % 40 === 0) { queen.hp -= u.atk; queen.lastHitTime = Date.now(); spawnBlood(queen.x, queen.y); playSfx(80, 'sawtooth', 0.2); } } 
                    else if(target) { u.targetX = target.x; u.targetY = target.y; if(Math.hypot(u.x-target.x, u.y-target.y) < 2.2 && frameCount % 35 === 0) { target.hp -= u.atk; target.lastHitTime = Date.now(); u.animTimer = 20; spawnBlood(target.x, target.y); playSfx(120, 'square', 0.1); } }
                } else if(u.targetEnemy) {
                    if(u.targetEnemy.hp <= 0) { u.targetEnemy = null; u.behavior = 'auto_attack'; }
                    else {
                        u.targetX = u.targetEnemy.x; u.targetY = u.targetEnemy.y;
                        if(Math.hypot(u.x - u.targetEnemy.x, u.y - u.targetEnemy.y) < 2.5 && frameCount % 25 === 0) { u.targetEnemy.hp -= u.atk * atkM; u.animTimer = 20; spawnBlood(u.targetEnemy.x, u.targetEnemy.y); if(u.targetEnemy.loot) u.targetEnemy.aggroed = true; playSfx(150, 'sawtooth', 0.1); }
                    }
                } else if(u.behavior === 'auto_collect' && u.cargo) {
                    if(u.cargo.amount < u.capacity * capM) {
                        if(!u.jobNode) u.jobNode = items.find(it => true);
                        if(u.jobNode) {
                            u.targetX = u.jobNode.x; u.targetY = u.jobNode.y;
                            if(Math.hypot(u.x-u.jobNode.x, u.y-u.jobNode.y) < 1.2) { 
                                const r = 1.0 * gameSpeed; u.jobNode.amount -= r; u.cargo.amount += r; u.cargo.type = u.jobNode.type.split('_')[0]; 
                                u.animTimer = 15; if(frameCount%20===0) playSfx(220, 'sine', 0.05);
                                if(u.jobNode.amount <= 0) { items.splice(items.indexOf(u.jobNode), 1); u.jobNode = null; } 
                            }
                        }
                    } else { u.targetX = START_POS.x; u.targetY = START_POS.y; if(dToQ < 2) { res[u.cargo.type] = Math.min(res[u.cargo.type] + Math.floor(u.cargo.amount), 99999); u.cargo.amount = 0; playSfx(440, 'sine', 0.1); } }
                } else if(u.behavior === 'auto_attack') {
                    const e = units.find(e => e.isHiveEnemy && Math.hypot(e.x-u.x, e.y-u.y) < 15) || npcs.find(n => Math.hypot(n.x-u.x, n.y-u.y) < 10 && (n.aggroed || n.territorial));
                    if(e) { u.targetX = e.x; u.targetY = e.y; if(Math.hypot(u.x-e.x, u.y-e.y) < 2.2 && frameCount % 30 === 0) { e.hp -= u.atk * atkM; u.animTimer = 20; spawnBlood(e.x, e.y); playSfx(140, 'sawtooth', 0.1); } }
                }
                if(u.animTimer > 0) u.animTimer--;

                // MUERTE DE UNIDADES
                if(u.hp <= 0) {
                    if(u.isHiveEnemy) {
                        const dnaBonus = u.caste === 'boss' ? 800 : 150;
                        addLog(`Invasor eliminado: +${dnaBonus} ADN.`); res.dna += dnaBonus;
                        items.push({ type: 'meat_node', x: u.x, y: u.y, amount: 600 });
                    } else {
                        addLog(`Unidad caída: ${u.name || u.caste}`);
                        spawnBlood(u.x, u.y);
                    }
                    units.splice(i, 1);
                }
            }

            // NPCs IA y MUERTE (BOTÍN)
            for (let i = npcs.length - 1; i >= 0; i--) {
                const n = npcs[i];
                const dist = Math.hypot(n.targetX-n.x, n.targetY-n.y);
                if(dist > 0.1) { n.x += ((n.targetX-n.x)/dist)*n.spd*gameSpeed; n.y += ((n.targetY-n.y)/dist)*n.spd*gameSpeed; }
                else if(Math.random() > 0.99) { n.targetX = n.x + Math.random()*20-10; n.targetY = n.y + Math.random()*20-10; }
                if(n.x <= 1 || n.x >= MAP_SIZE-1 || n.y <= 1 || n.y >= MAP_SIZE-1) { n.targetX = MAP_SIZE/2; n.targetY = MAP_SIZE/2; }
                if(n.hp <= 0) {
                    addLog(`Fauna eliminada: +${n.loot.dna} ADN.`); res.dna += n.loot.dna;
                    items.push({ type: 'meat_node', x: n.x, y: n.y, amount: n.loot.meat * 2.5 });
                    npcs.splice(i, 1); playSfx(60, 'sawtooth', 0.4);
                }
            }

            for(let i = particles.length - 1; i >= 0; i--) { const p = particles[i]; p.x += p.vx; p.y += p.vy; p.life--; if(p.life <= 0) particles.splice(i, 1); }
            const uAtt = units.some(u => Date.now() - u.lastHitTime < 1500) || (Date.now() - queen.lastHitTime < 1500);
            document.getElementById('attack-alert').classList.toggle('visible', uAtt);
            for(let i = eggs.length - 1; i >= 0; i--) { const e = eggs[i]; e.progress += gameSpeed; if(e.progress >= 500) { createUnit(e.caste, e.x, e.y); eggs.splice(i, 1); } }
            updateUI();
        }

                function render() {
                    if(!isGameStarted) return;
                    ctx.clearRect(0,0,canvas.width, canvas.height);
                    const w = TILE_WIDTH * zoom, h = TILE_HEIGHT * zoom;
                    const imgW = 64 * zoom, imgH = 128 * zoom;
                    for(let i=0; i<MAP_SIZE; i++) {
                        for(let j=0; j<MAP_SIZE; j++) {
                            const s = isoToScreen(i, j);
                            if(s.x < -imgW || s.x > canvas.width+imgW || s.y < -imgH || s.y > canvas.height+imgH) continue;
                            
                            const tile = terrainTiles[map[i][j].tileIdx];
                            if(tile && tile.complete) {
                                // El diamante de la base del tile (64x32) está al fondo de la imagen de 128px de alto.
                                // Ajustamos la posición para que el diamante coincida con las coordenadas isométricas.
                                ctx.drawImage(tile, s.x - imgW/2, s.y - (imgH - h), imgW, imgH);
                            }
                        }
                    }
                    particles.forEach(p => {
         const s = isoToScreen(p.x, p.y); ctx.fillStyle = `rgba(220, 0, 0, ${p.life/60})`; ctx.beginPath(); ctx.arc(s.x, s.y + 12*zoom, p.size*zoom, 0, Math.PI*2); ctx.fill(); });
                        structures.forEach(st => {
                            const s = isoToScreen(st.x, st.y);
                            ctx.fillStyle = 'rgba(0,0,0,0.5)'; ctx.beginPath(); ctx.ellipse(s.x, s.y + 12*zoom, 20*zoom, 10*zoom, 0, 0, Math.PI*2); ctx.fill();
                            const stImg = sprites.structures[st.type];
                            const stSize = st.type === 'tower' ? 60*zoom : 50*zoom;
                            if(stImg && stImg.complete && stImg.naturalWidth > 0) {
                                ctx.drawImage(stImg, s.x - stSize/2, s.y - stSize + 10*zoom, stSize, stSize);
                            }
                            if(st.type === 'tower' && st.animTimer > 0) {
                                ctx.strokeStyle = '#ffd700'; ctx.lineWidth = 2*zoom;
                                ctx.beginPath(); ctx.moveTo(s.x, s.y - 20*zoom);
                                ctx.lineTo(s.x + (Math.random()-0.5)*40*zoom, s.y - (20+Math.random()*30)*zoom); ctx.stroke();
                            }
                        });
                        items.forEach(it => { 
                            const s = isoToScreen(it.x, it.y); 
                            const type = it.type.split('_')[0]; // meat, grass, stone
                            const img = sprites.resources[type];
                            const sizeMult = (it.amount / 2000) + 0.5;
                            const dim = 24 * zoom * sizeMult;
                            
                            if(img && img.complete && img.naturalWidth > 0) {
                                ctx.drawImage(img, s.x - dim/2, s.y - dim/2, dim, dim);
                            } else {
                                ctx.font = `${10 * zoom * sizeMult}px Arial`; 
                                ctx.fillText(it.type === 'meat_node' ? '🍖' : (it.type === 'stone_node' ? '🪨' : '🌿'), s.x-5*zoom, s.y+10*zoom); 
                            }
                        });
                        eggs.forEach(e => {
                            const s = isoToScreen(e.x, e.y);
                            const eggImg = sprites.misc['egg'];
                            const eSize = 22*zoom;
                            if(eggImg && eggImg.complete && eggImg.naturalWidth > 0) {
                                ctx.drawImage(eggImg, s.x - eSize/2, s.y - eSize/2, eSize, eSize);
                            } else {
                                ctx.fillStyle = '#fffdf5'; ctx.beginPath(); ctx.ellipse(s.x, s.y, 8*zoom, 10*zoom, 0, 0, Math.PI*2); ctx.fill();
                            }
                            ctx.fillStyle = '#000'; ctx.fillRect(s.x-10*zoom, s.y+12*zoom, 20*zoom, 2*zoom);
                            ctx.fillStyle = '#3b82f6'; ctx.fillRect(s.x-10*zoom, s.y+12*zoom, (e.progress/500)*20*zoom, 2*zoom);
                        });
            const q = isoToScreen(START_POS.x, START_POS.y);
            const nexusImg = sprites.misc['nexus']; const nexusSize = 70*zoom;
            if(nexusImg && nexusImg.complete && nexusImg.naturalWidth > 0) {
                ctx.drawImage(nexusImg, q.x - nexusSize/2, q.y - nexusSize + 15*zoom, nexusSize, nexusSize);
            }
            ctx.fillStyle = '#000'; ctx.fillRect(q.x-20*zoom, q.y-35*zoom, 40*zoom, 6*zoom);
            ctx.fillStyle = '#22c55e'; ctx.fillRect(q.x-20*zoom, q.y-35*zoom, Math.max(0, (queen.hp/queen.maxHp)*40*zoom), 6*zoom);
            const eq = isoToScreen(ENEMY_HIVE_POS.x, ENEMY_HIVE_POS.y);
            const hiveImg = sprites.misc['hive']; const hiveSize = 70*zoom;
            if(hiveImg && hiveImg.complete && hiveImg.naturalWidth > 0) {
                ctx.drawImage(hiveImg, eq.x - hiveSize/2, eq.y - hiveSize + 15*zoom, hiveSize, hiveSize);
            }
            [...units, ...npcs].forEach(u => { const s = isoToScreen(u.x, u.y); if(s.x < -100 || s.x > canvas.width+100) return; drawDino(u, s); });
            renderMinimap();
        }

        function updateUI() {
            document.getElementById('res-dna').innerText = Math.floor(res.dna);
            document.getElementById('res-meat').innerText = Math.floor(res.meat);
            document.getElementById('res-grass').innerText = Math.floor(res.grass);
            document.getElementById('res-stone').innerText = Math.floor(res.stone);
            const currentPop = units.filter(u => !u.isHiveEnemy).length + eggs.length;
            const popUI = document.getElementById('res-pop'); if(popUI) popUI.innerText = `${currentPop} / ${maxPopulation}`;
            const tracker = document.getElementById('evolution-tracker');
            if(tracker && activeMutations.length > 0) tracker.innerHTML = activeMutations.map(id => `<span class="evolution-tag">${id.replace('_', ' ')}</span>`).join('');
            const panel = document.getElementById('action-panel');
            if(panel && selection.type) {
                panel.style.opacity = "1"; panel.style.transform = "translateY(0)";
                const qa = document.getElementById('queen-actions'), uc = document.getElementById('unit-commands'), sg = document.getElementById('stat-grid'), en = document.getElementById('entity-name');
                if(selection.type === 'queen') { en.innerText = "Reina Madre"; if(qa) qa.classList.remove('hidden'); if(uc) uc.classList.add('hidden'); sg.innerHTML = `<div>HP: ${Math.floor(queen.hp)}</div><div>POB: ${currentPop}/${maxPopulation}</div>`; }
                else {
                    // Filtrar unidades muertas que ya no están en el array
                    selection.data = selection.data.filter(u => units.includes(u));
                    if(selection.data.length === 0) { selection = {type:null, data:[]}; if(panel) panel.style.opacity = "0"; return; }
                    const u = selection.data[0]; en.innerText = `${selection.data.length > 1 ? 'GRUPO' : (u.name || u.caste)}`; if(qa) qa.classList.add('hidden'); if(uc) uc.classList.remove('hidden'); sg.innerHTML = `<div>HP PROM: ${Math.floor(u.hp)}</div><div>UNDS: ${selection.data.length}</div>`;
                }
            } else if(panel) panel.style.opacity = "0";
        }

        function renderGeneTree() {
            Object.keys(GENE_TREE_BRANCHES).forEach(branchKey => {
                const container = document.getElementById(`branch-${branchKey}`);
                if(!container) return; container.innerHTML = "";
                GENE_TREE_BRANCHES[branchKey].forEach(node => {
                    const div = document.createElement('div');
                    const unlocked = activeMutations.includes(node.id); const canUnlock = !node.req || activeMutations.includes(node.req);
                    div.className = `gene-node ${unlocked ? 'unlocked' : (canUnlock ? 'can-unlock' : 'locked')}`;
                    div.innerHTML = `<div class="flex justify-between font-black text-[10px]"><span>${node.name}</span><span class="text-blue-400">${node.cost}</span></div><p class="text-[8px] opacity-60">${node.desc}</p>`;
                    div.onclick = (e) => { e.stopPropagation(); if(res.dna >= node.cost && !unlocked && canUnlock) { res.dna -= node.cost; activeMutations.push(node.id); renderGeneTree(); updateUI(); playSfx(800, 'sine', 0.2); } };
                    container.appendChild(div);
                });
            });
        }

        function toggleLab() { const lab = document.getElementById('gene-lab'); if(lab) { lab.classList.toggle('open'); if(lab.classList.contains('open')) renderGeneTree(); } }
        function spawnEgg(cKey) { const c = CASTE_TYPES[cKey]; const currentPop = units.filter(u => !u.isHiveEnemy).length + eggs.length; if(currentPop < maxPopulation && res.meat >= c.cost.m && res.grass >= c.cost.g) { res.meat -= c.cost.m; res.grass -= c.cost.g; eggs.push({ x: START_POS.x + Math.random()*3, y: START_POS.y + Math.random()*3, progress: 0, caste: cKey }); } }
        function addLog(m) { const l = document.getElementById('log-container'); if(!l) return; const e = document.createElement('div'); e.innerText = `> ${m}`; l.prepend(e); if(l.childNodes.length > 8) l.removeChild(l.lastChild); }
        function renderMinimap() { 
            mctx.fillStyle = '#000'; mctx.fillRect(0,0,220,220);
            const tl = screenToIso(0, 0), br = screenToIso(canvas.width, canvas.height);
            mctx.strokeStyle = '#fff'; mctx.strokeRect((tl.x/MAP_SIZE)*220, (tl.y/MAP_SIZE)*220, ((br.x-tl.x)/MAP_SIZE)*220, ((br.y-tl.y)/MAP_SIZE)*220);
            units.forEach(u => { mctx.fillStyle = u.isHiveEnemy ? '#facc15' : (u.selected ? '#0f0' : '#fff'); mctx.fillRect((u.x/MAP_SIZE)*220-2, (u.y/MAP_SIZE)*220-2, 4, 4); });
            npcs.forEach(n => { mctx.fillStyle = n.aggressive || n.territorial ? '#f87171' : '#4ade80'; mctx.fillRect((n.x/MAP_SIZE)*220-1, (n.y/MAP_SIZE)*220-1, 2, 2); });
        }
        minimapCanvas.addEventListener('mousedown', e => { const rect = minimapCanvas.getBoundingClientRect(); centerOn((e.clientX-rect.left)/rect.width*MAP_SIZE, (e.clientY-rect.top)/rect.height*MAP_SIZE); });
        window.addEventListener('wheel', e => { zoom = Math.min(Math.max(zoom * (e.deltaY > 0 ? 0.9 : 1.1), 0.4), 2.5); }, { passive: false });
        window.addEventListener('keydown', e => keys[e.key.toLowerCase()] = true);
        window.addEventListener('keyup', e => keys[e.key.toLowerCase()] = false);
        window.addEventListener('mousedown', e => {
            if(!isGameStarted || e.clientX > window.innerWidth - 300 || e.clientY < 60) return;
            if(e.button === 0) { dragStart = { x: e.clientX, y: e.clientY }; selBox.style.display = 'block'; selBox.style.left = e.clientX + 'px'; selBox.style.top = e.clientY + 'px'; selBox.style.width = '0px'; selBox.style.height = '0px';
            } else if(e.button === 2 && selection.data.length > 0) {
                const g = screenToIso(e.clientX, e.clientY);
                const target = units.find(u => u.isHiveEnemy && Math.hypot(u.x - g.x, u.y - g.y) < 2.5) || npcs.find(n => Math.hypot(n.x - g.x, n.y - g.y) < 2.5);
                selection.data.forEach(u => {
                    if(target) { u.targetEnemy = target; u.behavior = 'manual'; }
                    else { u.targetX = g.x; u.targetY = g.y; u.behavior = 'manual'; u.targetEnemy = null; u.jobNode = items.find(it => Math.hypot(it.x-g.x, it.y-g.y) < 2.0); if(u.jobNode) u.behavior = 'auto_collect'; }
                });
                playSfx(500, 'sine', 0.05);
            }
        });
        window.addEventListener('mousemove', e => { if(dragStart) { selBox.style.width = Math.abs(e.clientX - dragStart.x) + 'px'; selBox.style.height = Math.abs(e.clientY - dragStart.y) + 'px'; selBox.style.left = Math.min(e.clientX, dragStart.x) + 'px'; selBox.style.top = Math.min(e.clientY, dragStart.y) + 'px'; } });
        window.addEventListener('mouseup', e => {
            if(dragStart) {
                const rect = selBox.getBoundingClientRect(); const d = Math.hypot(e.clientX - dragStart.x, e.clientY - dragStart.y);
                if(d < 10) {
                    const g = screenToIso(e.clientX, e.clientY); selection.data = [];
                    units.forEach(u => { u.selected = !u.isHiveEnemy && Math.hypot(u.x-g.x, u.y-g.y) < 2.5; if(u.selected) selection.data.push(u); });
                    if(selection.data.length === 0 && Math.hypot(g.x-START_POS.x, g.y-START_POS.y) < 4) selection = {type:'queen', data:[]};
                    else if(selection.data.length === 0) selection = {type:null, data:[]};
                    else selection.type = 'unit';
                } else {
                    selection.data = []; units.forEach(u => { const s = isoToScreen(u.x, u.y); u.selected = !u.isHiveEnemy && s.x > rect.left && s.x < rect.right && s.y > rect.top && s.y < rect.bottom; if(u.selected) selection.data.push(u); });
                    if(selection.data.length > 0) selection.type = 'unit';
                    else { selection.type = null; }
                }
                dragStart = null; selBox.style.display = 'none';
            }
        });
        window.addEventListener('contextmenu', e => e.preventDefault());
        window.addEventListener('resize', () => { canvas.width = window.innerWidth; canvas.height = window.innerHeight; });
        setInterval(() => {
            if(isGameStarted) {
                gTimer++;
                res.dna += 0.3 * gameSpeed;
                const gm = Math.floor(gTimer / 60), gs = gTimer % 60;
                const gt = document.getElementById('game-time');
                if(gt) gt.innerText = `${String(gm).padStart(2,'0')}:${String(gs).padStart(2,'0')}`;
            }
        }, 1000);
    </script>
</body>
</html>

