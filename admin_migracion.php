<?php
// ARCHIVO: admin_migracion.php
// Migración Espejo - Rediseño Premium Inventory-Suite

session_start();
require_once 'config_loader.php';
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Migración Espejo Premium | <?= htmlspecialchars(config_loader_system_name()) ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .inventory-hero {
            background: linear-gradient(135deg, <?php echo $config['hero_color_1'] ?? '#0f172a'; ?>ee, <?php echo $config['hero_color_2'] ?? '#1e293b'; ?>c6) !important;
        }
        .ring-premium {
            width: 100px; height: 100px; border-radius: 50%;
            background: conic-gradient(var(--pw-accent) calc(var(--p) * 1%), rgba(255,255,255,0.1) 0);
            display: grid; place-items: center;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            position: relative;
        }
        .ring-premium::after {
            content: attr(data-text);
            width: 80px; height: 80px; border-radius: 50%;
            background: rgba(255,255,255,0.05); backdrop-filter: blur(10px);
            display: grid; place-items: center;
            font-weight: 800; color: #fff; font-size: 1.2rem;
        }
        .log-box-premium {
            background: #0f1720; color: #d3e8ff; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1);
            min-height: 300px; max-height: 500px; overflow: auto; padding: 1.5rem;
            font-family: 'Fira Code', 'Cascadia Code', monospace; font-size: 0.85rem;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.5);
        }
        .step-card-premium {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255,255,255,0.05);
        }
        .step-card-premium:hover { transform: translateY(-3px); background: rgba(255,255,255,0.05); }
        .progress-premium { height: 10px; border-radius: 10px; background: rgba(0,0,0,0.1); overflow: hidden; }
        .progress-bar-premium { height: 100%; background: var(--pw-accent); transition: width 0.5s ease; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">
    
    <!-- Hero Section -->
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-center">
            <div class="text-center text-lg-start">
                <div class="section-title text-white-50 mb-2">Infraestructura / DevOps</div>
                <h1 class="h2 fw-bold mb-2 text-white"><i class="fas fa-server me-2"></i>Migración Espejo Premium</h1>
                <p class="mb-3 text-white-50">Sincronización visual de alta fidelidad entre servidores mediante rsync y túneles SSH seguros.</p>
                <div class="d-flex flex-wrap justify-content-center justify-content-lg-start gap-2">
                    <span class="kpi-chip"><i class="fas fa-microchip me-1"></i>Modo: <span id="kpi-mode">Dry Run</span></span>
                    <span class="kpi-chip"><i class="fas fa-network-wired me-1"></i>Puerto: <span id="kpi-port">22</span></span>
                    <span class="kpi-chip"><i class="fas fa-terminal me-1"></i>Status: <span id="statusGlobalText">En espera</span></span>
                </div>
            </div>
            <div class="ring-premium" id="ring" style="--p:0;" data-text="0%"></div>
        </div>
    </section>

    <div class="row g-4 align-items-stretch">
        <!-- Panel de Control (Izquierda) -->
        <div class="col-12 col-xl-4">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="section-title">Configuración</div>
                <h2 class="h5 fw-bold mb-4">Parámetros de Destino</h2>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Destino SSH (user@host)</label>
                    <input id="dest" class="form-control form-control-lg" placeholder="root@203.0.113.10" oninput="updateKpi()">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Puerto SSH</label>
                        <input id="ssh_port" type="number" class="form-control" value="22" oninput="updateKpi()">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Modo Ejecución</label>
                        <select id="mode" class="form-select" onchange="updateKpi()">
                            <option value="dry-run">Simulación (Dry Run)</option>
                            <option value="run">Ejecución Real (Run)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Sincronizar Usuarios/Grupos</label>
                    <select id="sync_users" class="form-select">
                        <option value="yes">Sí, mantener UID/GID</option>
                        <option value="no">No, solo archivos</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold">Contraseña SSH</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fas fa-key text-muted"></i></span>
                        <input id="ssh_password" type="password" class="form-control" placeholder="Opcional si usa llave pública" autocomplete="current-password">
                    </div>
                    <div class="form-text tiny mt-2">Se cifra localmente con su clave maestra de sesión.</div>
                </div>

                <div class="mb-4 pt-3 border-top">
                    <label class="form-label small fw-bold">Clave Maestra Local</label>
                    <div class="input-group mb-2">
                        <input id="master_key" type="password" class="form-control" placeholder="Clave para desbloqueo local" autocomplete="new-password">
                        <button id="btnUnlockPwd" class="btn btn-outline-primary" type="button"><i class="fas fa-unlock-alt"></i></button>
                    </div>
                    <div class="tiny text-muted">Protege el password SSH en la memoria del navegador.</div>
                </div>

                <div class="d-grid gap-2">
                    <button id="btnRunAll" class="btn btn-primary btn-lg fw-bold shadow-sm py-3"><i class="fas fa-play-circle me-2"></i>INICIAR MIGRACIÓN</button>
                    <div class="row g-2">
                        <div class="col-6"><button id="btnCheck" class="btn btn-outline-secondary w-100"><i class="fas fa-vial me-1"></i>Check</button></div>
                        <div class="col-6"><button id="btnReset" class="btn btn-outline-danger w-100"><i class="fas fa-undo me-1"></i>Reset</button></div>
                    </div>
                    <button id="btnClearSaved" class="btn btn-link btn-sm text-muted mt-2">Borrar configuración guardada</button>
                </div>
            </div>
        </div>

        <!-- Panel de Pasos (Derecha) -->
        <div class="col-12 col-xl-8">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <div class="section-title">Progreso del Pipeline</div>
                        <h2 class="h5 fw-bold mb-0">Flujo de Trabajo</h2>
                    </div>
                    <div class="text-end">
                        <span id="progressText" class="fw-bold text-primary">0/7 Pasos</span>
                        <div class="progress-premium mt-2" style="width: 150px;"><div class="progress-bar-premium" id="progressBar" style="width: 0%;"></div></div>
                    </div>
                </div>

                <div class="row g-3" id="stepGrid">
                    <!-- Se genera dinámicamente -->
                </div>

                <!-- Consola -->
                <div class="mt-4 pt-4 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="section-title mb-0">Salida de Consola (Log)</div>
                        <div class="d-flex gap-2">
                            <button id="btnCancelRun" class="btn btn-sm btn-outline-danger" disabled><i class="fas fa-stop me-1"></i>Cancelar</button>
                            <button id="btnClearLog" class="btn btn-sm btn-light border"><i class="fas fa-eraser"></i></button>
                        </div>
                    </div>
                    <div class="log-box-premium" id="logBox"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once 'menu_master.php'; ?>

<script>
// Manteniendo la lógica exacta del original pero vinculada a la nueva UI
const API = 'admin_migracion_api.php';
const STORAGE_KEY = 'palweb_migracion_cfg_v1';
const MASTER_KEY_SESSION = 'palweb_migracion_master_key_v1';

const steps = [
  {id:1, name:'Preparar conexión remota', icon:'fa-link'},
  {id:2, name:'Sincronizar /var/www', icon:'fa-folder-open'},
  {id:3, name:'Sincronizar configuración', icon:'fa-cogs'},
  {id:4, name:'Sincronizar systemd/cron', icon:'fa-clock'},
  {id:5, name:'Sincronizar usuarios/grupos', icon:'fa-users-cog'},
  {id:6, name:'Dump + restore MySQL', icon:'fa-database'},
  {id:7, name:'Permisos y servicios', icon:'fa-tools'}
];

const state = { running:false, cancelRequested:false, statuses:{} };

const el = {
  stepGrid: document.getElementById('stepGrid'),
  progressBar: document.getElementById('progressBar'),
  progressText: document.getElementById('progressText'),
  ring: document.getElementById('ring'),
  logBox: document.getElementById('logBox'),
  statusGlobal: document.getElementById('statusGlobalText'),
  btnCheck: document.getElementById('btnCheck'),
  btnRunAll: document.getElementById('btnRunAll'),
  btnReset: document.getElementById('btnReset'),
  btnClearSaved: document.getElementById('btnClearSaved'),
  btnClearLog: document.getElementById('btnClearLog'),
  btnCancelRun: document.getElementById('btnCancelRun'),
  dest: document.getElementById('dest'),
  sshPort: document.getElementById('ssh_port'),
  mode: document.getElementById('mode'),
  syncUsers: document.getElementById('sync_users'),
  sshPassword: document.getElementById('ssh_password'),
  masterKey: document.getElementById('master_key'),
  btnUnlockPwd: document.getElementById('btnUnlockPwd'),
  kpiMode: document.getElementById('kpi-mode'),
  kpiPort: document.getElementById('kpi-port')
};

function updateKpi() {
    el.kpiMode.textContent = el.mode.options[el.mode.selectedIndex].text;
    el.kpiPort.textContent = el.sshPort.value || '22';
}

function renderSteps() {
  el.stepGrid.innerHTML = steps.map(s => `
    <div class="col-md-6">
      <div class="glass-card p-3 step-card-premium" id="step-card-${s.id}">
        <div class="d-flex justify-content-between align-items-center">
          <div class="fw-bold small"><i class="fas ${s.icon} me-2 text-primary"></i>Paso ${s.id}</div>
          <span class="soft-pill" id="step-badge-${s.id}">Pendiente</span>
        </div>
        <div class="mt-2 small text-muted text-truncate">${s.name}</div>
      </div>
    </div>
  `).join('');
  steps.forEach(s => state.statuses[s.id] = 'pending');
  updateProgress();
}

function setStep(id, st, label) {
  state.statuses[id] = st;
  const b = document.getElementById(`step-badge-${id}`);
  const c = document.getElementById(`step-card-${id}`);
  if (!b) return;
  
  b.className = `soft-pill ${st==='running'?'bg-primary text-white':st==='done'?'bg-success text-white':st==='error'?'bg-danger text-white':'bg-light text-muted'}`;
  b.textContent = label;
  
  if(st === 'running') c.classList.add('border-primary');
  else c.classList.remove('border-primary');
  
  updateProgress();
}

function updateProgress() {
  const done = Object.values(state.statuses).filter(v => v === 'done').length;
  const percent = Math.round((done / steps.length) * 100);
  el.progressBar.style.width = `${percent}%`;
  el.progressText.textContent = `${done}/${steps.length} Pasos`;
  el.ring.style.setProperty('--p', percent);
  el.ring.setAttribute('data-text', `${percent}%`);
}

function log(msg, type='info') {
  const colorMap = {
    'error': '#fca5a5',
    'ok': '#86efac',
    'info': '#d3e8ff'
  };
  const line = document.createElement('div');
  line.style.marginBottom = '4px';
  line.innerHTML = `<span style="color:${colorMap[type] || colorMap.info}">[${new Date().toLocaleTimeString()}]</span> ${msg}`;
  el.logBox.appendChild(line);
  el.logBox.scrollTop = el.logBox.scrollHeight;
}

// Criptografía y Persistencia (Lógica original preservada)
function bytesToB64(bytes) {
  let bin = '';
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) bin += String.fromCharCode(...bytes.subarray(i, i + chunk));
  return btoa(bin);
}

function b64ToBytes(b64) {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

async function deriveAesKey(masterKey, saltBytes) {
  const enc = new TextEncoder();
  const baseKey = await crypto.subtle.importKey('raw', enc.encode(masterKey), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt: saltBytes, iterations: 180000, hash: 'SHA-256' },
    baseKey, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']
  );
}

async function encryptSecret(plainText, masterKey) {
  const enc = new TextEncoder();
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const key = await deriveAesKey(masterKey, salt);
  const cipherBuf = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, enc.encode(plainText));
  return { alg: 'AES-GCM-PBKDF2', salt_b64: bytesToB64(salt), iv_b64: bytesToB64(iv), ct_b64: bytesToB64(new Uint8Array(cipherBuf)) };
}

async function decryptSecret(payload, masterKey) {
  const dec = new TextDecoder();
  const salt = b64ToBytes(payload.salt_b64);
  const iv = b64ToBytes(payload.iv_b64);
  const ct = b64ToBytes(payload.ct_b64);
  const key = await deriveAesKey(masterKey, salt);
  const plainBuf = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
  return dec.decode(plainBuf);
}

function getMasterKey() {
  const v = el.masterKey.value || sessionStorage.getItem(MASTER_KEY_SESSION) || '';
  if (v) sessionStorage.setItem(MASTER_KEY_SESSION, v);
  return v;
}

async function saveFormLocal() {
  let existing = {};
  try { const raw = localStorage.getItem(STORAGE_KEY); if (raw) existing = JSON.parse(raw) || {}; } catch (_) {}
  const data = { dest: el.dest.value.trim(), ssh_port: el.sshPort.value.trim(), mode: el.mode.value, sync_users: el.syncUsers.value };
  const pwd = el.sshPassword.value;
  const master = getMasterKey();
  if (pwd && master && window.crypto?.subtle) {
    try { data.ssh_password_enc = await encryptSecret(pwd, master); } catch (_) { log('Error de cifrado local', 'error'); }
  } else if (!pwd && existing.ssh_password_enc) data.ssh_password_enc = existing.ssh_password_enc;
  localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
}

function loadFormLocal() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const data = JSON.parse(raw);
    if (data.dest) el.dest.value = data.dest;
    if (data.ssh_port) el.sshPort.value = data.ssh_port;
    if (data.mode) el.mode.value = data.mode;
    if (data.sync_users) el.syncUsers.value = data.sync_users;
    if (data.ssh_password_enc) { el.sshPassword.value = ''; el.sshPassword.placeholder = '●●●●● (Cifrado)'; }
    updateKpi();
  } catch (_) {}
}

async function unlockSavedPassword() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const data = JSON.parse(raw);
    if (!data.ssh_password_enc) { log('No hay datos cifrados'); return; }
    const master = getMasterKey();
    if (!master) { log('Escriba clave maestra', 'error'); return; }
    const plain = await decryptSecret(data.ssh_password_enc, master);
    el.sshPassword.value = plain;
    log('Password SSH desbloqueado', 'ok');
  } catch (_) { log('Clave maestra inválida', 'error'); }
}

async function runStep(stepId) {
  setStep(stepId, 'running', 'EJECUTANDO');
  log(`Iniciando Paso ${stepId}...`);

  const p = new URLSearchParams({
    action: 'run_step', step: String(stepId), dest: el.dest.value.trim(),
    ssh_port: el.sshPort.value.trim(), mode: el.mode.value, sync_users: el.syncUsers.value,
    ssh_password: el.sshPassword.value
  });

  const res = await fetch(API, { method: 'POST', body: p });
  const d = await res.json();

  if (!d.ok) {
    setStep(stepId, 'error', 'ERROR');
    log(`Paso ${stepId} falló: ${d.msg || 'Error desconocido'}`, 'error');
    if (d.output) log(d.output.replace(/\n/g, '<br>'), 'error');
    throw new Error('Fallo en el pipeline');
  }

  setStep(stepId, 'done', 'OK');
  log(`Paso ${stepId} completado correctamente`, 'ok');
  if (d.output) log(d.output.replace(/\n/g, '<br>'));
}

async function runAll() {
  if (state.running) return;
  if (!el.dest.value.trim()) { alert('Destino SSH requerido'); return; }
  
  state.running = true;
  state.cancelRequested = false;
  el.statusGlobal.textContent = 'En ejecución...';
  el.btnRunAll.disabled = true;
  el.btnCancelRun.disabled = false;

  try {
    for (const s of steps) {
      await runStep(s.id);
      if (state.cancelRequested) {
        el.statusGlobal.textContent = 'Cancelado';
        log('Operación detenida por el usuario', 'error');
        return;
      }
    }
    el.statusGlobal.textContent = 'Completado';
    log('Pipeline finalizado con éxito', 'ok');
  } catch (e) {
    el.statusGlobal.textContent = 'Error';
    log(e.message, 'error');
  } finally {
    state.running = false;
    el.btnRunAll.disabled = false;
    el.btnCancelRun.disabled = true;
  }
}

// Event Listeners
el.btnRunAll.addEventListener('click', runAll);
el.btnCheck.addEventListener('click', async () => {
  const res = await fetch(API + '?action=check');
  const d = await res.json();
  log(`Entorno: Script ${d.script_ready?'OK':'NO'}, sshpass ${d.sshpass_available?'OK':'NO'}`);
});
el.btnReset.addEventListener('click', () => { el.logBox.innerHTML = ''; renderSteps(); });
el.btnClearSaved.addEventListener('click', () => { localStorage.removeItem(STORAGE_KEY); location.reload(); });
el.btnUnlockPwd.addEventListener('click', unlockSavedPassword);
el.btnClearLog.addEventListener('click', () => el.logBox.innerHTML = '');
el.btnCancelRun.addEventListener('click', () => { state.cancelRequested = true; log('Solicitud de cancelación enviada...', 'error'); });

renderSteps();
loadFormLocal();
</script>
</body>
</html>
