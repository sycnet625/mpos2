<?php
session_start();
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
    <title>Migración Espejo | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root {
            --bg: #eef3f9;
            --card: #ffffff;
            --ink: #1d2a35;
            --muted: #5d6b79;
            --line: #d8e0ea;
            --accent: #1677ff;
            --good: #1f9d57;
            --warn: #d68a00;
            --bad: #c62828;
            --run: #0077b6;
        }
        body {
            background:
                radial-gradient(1200px 500px at -10% -20%, #d8e9ff 0%, transparent 60%),
                radial-gradient(900px 420px at 110% -10%, #d5f5ea 0%, transparent 60%),
                var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", system-ui, sans-serif;
        }
        .hero-card, .panel-card, .step-card { border: 1px solid var(--line); border-radius: 16px; background: var(--card); box-shadow: 0 8px 24px rgba(18, 42, 66, 0.08); }
        .hero-card { padding: 20px; }
        .panel-card { padding: 16px; }
        .kpi-value { font-size: 1.6rem; font-weight: 800; line-height: 1; }
        .kpi-label { color: var(--muted); font-size: .85rem; }
        .progress-wrap { background: #e8eef6; border-radius: 999px; height: 12px; overflow: hidden; }
        .progress-inner { height: 100%; width: 0%; background: linear-gradient(90deg, #0b6bcb, #00b4d8); transition: width .4s ease; }
        .ring {
            width: 86px; height: 86px; border-radius: 50%;
            background: conic-gradient(#159957 calc(var(--p) * 1%), #d8e0ea 0);
            display: grid; place-items: center;
        }
        .ring::after {
            content: attr(data-text);
            width: 68px; height: 68px; border-radius: 50%;
            background: white; display: grid; place-items: center;
            font-weight: 700; color: #1f2f40;
        }
        .step-card { padding: 14px; transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
        .step-card:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(15, 35, 55, 0.1); }
        .step-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .step-title { font-weight: 700; }
        .badge-state { font-size: .72rem; border-radius: 999px; padding: 4px 10px; }
        .state-pending { border-color: #cfd8e3; background: #eff3f8; color: #4f6172; }
        .state-running { border-color: #86cdf5; background: #dff4ff; color: #00689d; }
        .state-done { border-color: #9ad8b4; background: #e3f6eb; color: #1f7d44; }
        .state-error { border-color: #f1a8a8; background: #fdecec; color: #9b1f1f; }
        .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 8px; }
        .log-box {
            background: #0f1720; color: #d3e8ff; border-radius: 14px; border: 1px solid #273445;
            min-height: 260px; max-height: 420px; overflow: auto; padding: 12px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .83rem;
        }
        .btn-grad { background: linear-gradient(90deg, #1769ff, #00a9ce); color: #fff; border: none; }
        .btn-grad:hover { color: #fff; filter: brightness(0.96); }
    </style>
</head>
<body class="p-3 p-md-4">
<div class="container-fluid" style="max-width:1300px;">
    <div class="hero-card mb-3">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div>
                <h3 class="mb-1"><i class="fas fa-server text-primary"></i> Migración Espejo de Servidor</h3>
                <div class="text-muted">Ejecución visual por pasos de <code>/var/www/migrar_espejo.sh</code> con progreso, estados y log.</div>
            </div>
            <div class="ring" id="ring" style="--p:0;" data-text="0%"></div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="panel-card h-100">
                <h6 class="mb-3"><i class="fas fa-sliders-h"></i> Parámetros</h6>
                <div class="mb-2">
                    <label class="form-label mb-1">Destino SSH</label>
                    <input id="dest" class="form-control" placeholder="root@203.0.113.10">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label mb-1">Puerto</label>
                        <input id="ssh_port" class="form-control" value="22">
                    </div>
                    <div class="col-6">
                        <label class="form-label mb-1">Modo</label>
                        <select id="mode" class="form-select">
                            <option value="dry-run">Dry Run</option>
                            <option value="run">Run</option>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label mb-1">Sync usuarios/grupos</label>
                    <select id="sync_users" class="form-select">
                        <option value="yes">Sí</option>
                        <option value="no">No</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label mb-1">Password SSH (una vez)</label>
                    <input id="ssh_password" type="password" class="form-control" placeholder="Opcional si usas llave SSH" autocomplete="current-password">
                    <small class="text-muted">Se reutiliza automáticamente en cada paso y se guarda cifrado localmente.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label mb-1">Clave maestra local (para cifrar password)</label>
                    <div class="input-group">
                        <input id="master_key" type="password" class="form-control" placeholder="Define una clave maestra local" autocomplete="new-password">
                        <button id="btnUnlockPwd" class="btn btn-outline-primary" type="button"><i class="fas fa-unlock-alt"></i> Desbloquear</button>
                    </div>
                    <small class="text-muted">La clave maestra no se guarda en disco; solo en la sesión actual del navegador.</small>
                </div>

                <div class="d-grid gap-2">
                    <button id="btnCheck" class="btn btn-outline-secondary"><i class="fas fa-vial"></i> Verificar entorno</button>
                    <button id="btnRunAll" class="btn btn-grad"><i class="fas fa-play-circle"></i> Ejecutar migración completa</button>
                    <button id="btnReset" class="btn btn-outline-dark"><i class="fas fa-undo"></i> Reiniciar estados</button>
                    <button id="btnClearSaved" class="btn btn-outline-danger"><i class="fas fa-trash-alt"></i> Borrar datos guardados</button>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="panel-card h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="fas fa-tasks"></i> Progreso</h6>
                    <span id="progressText" class="kpi-label">0/7 pasos</span>
                </div>
                <div class="progress-wrap mb-3"><div class="progress-inner" id="progressBar"></div></div>

                <div class="row g-2" id="stepGrid"></div>
            </div>
        </div>
    </div>

    <div class="panel-card">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0"><i class="fas fa-terminal"></i> Consola de ejecución</h6>
            <div class="d-flex align-items-center gap-2">
                <button id="btnClearLog" class="btn btn-sm btn-outline-secondary"><i class="fas fa-eraser"></i> Borrar log</button>
                <button id="btnCancelRun" class="btn btn-sm btn-outline-danger" disabled><i class="fas fa-stop-circle"></i> Cancelar</button>
                <span id="statusGlobal" class="badge state-pending badge-state">En espera</span>
            </div>
        </div>
        <div class="log-box" id="logBox"></div>
    </div>
</div>

<?php include_once 'menu_master.php'; ?>
<script>
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
  statusGlobal: document.getElementById('statusGlobal'),
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
  btnUnlockPwd: document.getElementById('btnUnlockPwd')
};

function renderSteps() {
  el.stepGrid.innerHTML = steps.map(s => `
    <div class="col-md-6">
      <div class="step-card" id="step-card-${s.id}">
        <div class="step-head">
          <div class="step-title"><i class="fas ${s.icon} me-2"></i>Paso ${s.id}: ${s.name}</div>
          <span class="badge-state state-pending" id="step-badge-${s.id}">Pendiente</span>
        </div>
      </div>
    </div>
  `).join('');
  steps.forEach(s => state.statuses[s.id] = 'pending');
  updateProgress();
}

function setStep(id, st, label) {
  state.statuses[id] = st;
  const b = document.getElementById(`step-badge-${id}`);
  if (!b) return;
  b.className = `badge-state ${st==='running'?'state-running':st==='done'?'state-done':st==='error'?'state-error':'state-pending'}`;
  b.textContent = label;
  updateProgress();
}

function updateProgress() {
  const done = Object.values(state.statuses).filter(v => v === 'done').length;
  const percent = Math.round((done / steps.length) * 100);
  el.progressBar.style.width = `${percent}%`;
  el.progressText.textContent = `${done}/${steps.length} pasos`;
  el.ring.style.setProperty('--p', percent);
  el.ring.setAttribute('data-text', `${percent}%`);
}

function log(msg, type='info') {
  const color = type==='error' ? '#ff9aa2' : type==='ok' ? '#8dffb3' : '#d3e8ff';
  const line = document.createElement('div');
  line.innerHTML = `<span style="color:${color}">●</span> ${msg}`;
  el.logBox.appendChild(line);
  el.logBox.scrollTop = el.logBox.scrollHeight;
}

function payload(stepId) {
  return new URLSearchParams({
    action: 'run_step',
    step: String(stepId),
    dest: el.dest.value.trim(),
    ssh_port: el.sshPort.value.trim(),
    mode: el.mode.value,
    sync_users: el.syncUsers.value,
    ssh_password: el.sshPassword.value
  });
}

function bytesToB64(bytes) {
  let bin = '';
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) {
    bin += String.fromCharCode(...bytes.subarray(i, i + chunk));
  }
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
  const baseKey = await crypto.subtle.importKey(
    'raw',
    enc.encode(masterKey),
    'PBKDF2',
    false,
    ['deriveKey']
  );
  return crypto.subtle.deriveKey(
    {
      name: 'PBKDF2',
      salt: saltBytes,
      iterations: 180000,
      hash: 'SHA-256'
    },
    baseKey,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt']
  );
}

async function encryptSecret(plainText, masterKey) {
  const enc = new TextEncoder();
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const key = await deriveAesKey(masterKey, salt);
  const cipherBuf = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, enc.encode(plainText));
  return {
    alg: 'AES-GCM-PBKDF2',
    salt_b64: bytesToB64(salt),
    iv_b64: bytesToB64(iv),
    ct_b64: bytesToB64(new Uint8Array(cipherBuf))
  };
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
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) existing = JSON.parse(raw) || {};
  } catch (_) {}

  const data = {
    dest: el.dest.value.trim(),
    ssh_port: el.sshPort.value.trim(),
    mode: el.mode.value,
    sync_users: el.syncUsers.value
  };

  const pwd = el.sshPassword.value;
  const master = getMasterKey();
  if (pwd && master && window.crypto?.subtle) {
    try {
      data.ssh_password_enc = await encryptSecret(pwd, master);
    } catch (_) {
      log('No se pudo cifrar el password local', 'error');
    }
  } else if (!pwd && existing.ssh_password_enc) {
    data.ssh_password_enc = existing.ssh_password_enc;
  } else if (pwd && !master) {
    log('Define clave maestra para guardar password cifrado', 'error');
    if (existing.ssh_password_enc) data.ssh_password_enc = existing.ssh_password_enc;
  }

  localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
}

function loadFormLocal() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const data = JSON.parse(raw);
    if (typeof data.dest === 'string') el.dest.value = data.dest;
    if (typeof data.ssh_port === 'string') el.sshPort.value = data.ssh_port;
    if (typeof data.mode === 'string') el.mode.value = data.mode;
    if (typeof data.sync_users === 'string') el.syncUsers.value = data.sync_users;
    if (data.ssh_password_enc) {
      el.sshPassword.value = '';
      el.sshPassword.placeholder = 'Password cifrado guardado (usa clave maestra y Desbloquear)';
    }
  } catch (_) {}
}

async function unlockSavedPassword() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const data = JSON.parse(raw);
    if (!data.ssh_password_enc) {
      log('No hay password cifrado guardado');
      return;
    }
    const master = getMasterKey();
    if (!master) {
      log('Escribe la clave maestra local para desbloquear', 'error');
      return;
    }
    if (!window.crypto?.subtle) {
      log('Este navegador no soporta Web Crypto para descifrar', 'error');
      return;
    }
    const plain = await decryptSecret(data.ssh_password_enc, master);
    el.sshPassword.value = plain;
    log('Password SSH descifrado en memoria', 'ok');
  } catch (_) {
    log('Clave maestra incorrecta o datos cifrados inválidos', 'error');
  }
}

async function ensureSshPasswordReady() {
  if (el.sshPassword.value) return true;
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return true;
    const data = JSON.parse(raw);
    if (!data.ssh_password_enc) return true;

    const master = getMasterKey();
    if (!master) {
      alert('Falta la clave maestra local para descifrar el password SSH guardado.');
      log('No se pudo descifrar password: falta clave maestra', 'error');
      return false;
    }
    if (!window.crypto?.subtle) {
      alert('Este navegador no soporta Web Crypto para descifrar el password guardado.');
      log('No se pudo descifrar password: navegador sin Web Crypto', 'error');
      return false;
    }
    const plain = await decryptSecret(data.ssh_password_enc, master);
    el.sshPassword.value = plain;
    return true;
  } catch (_) {
    alert('No se pudo descifrar el password SSH guardado. Revisa la clave maestra.');
    log('No se pudo descifrar password: clave maestra incorrecta o datos corruptos', 'error');
    return false;
  }
}

function clearFormLocal() {
  localStorage.removeItem(STORAGE_KEY);
  sessionStorage.removeItem(MASTER_KEY_SESSION);
  el.dest.value = '';
  el.sshPort.value = '22';
  el.mode.value = 'dry-run';
  el.syncUsers.value = 'yes';
  el.sshPassword.value = '';
  el.masterKey.value = '';
  el.sshPassword.placeholder = 'Opcional si usas llave SSH';
  log('Datos locales eliminados');
}

async function runStep(stepId) {
  setStep(stepId, 'running', 'Ejecutando');
  log(`Paso ${stepId} iniciado...`);

  const res = await fetch(API, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: payload(stepId).toString()
  });
  const d = await res.json();

  if (!d.ok) {
    setStep(stepId, 'error', 'Error');
    log(`Paso ${stepId} falló (code ${d.exit_code ?? 'n/a'})`, 'error');
    if (d.output) log(d.output.replace(/\n/g, '<br>'), 'error');
    throw new Error(d.msg || 'Error en ejecución');
  }

  setStep(stepId, 'done', 'Completado');
  log(`Paso ${stepId} OK`, 'ok');
  if (d.output) log(d.output.replace(/\n/g, '<br>'));
}

async function runAll() {
  if (state.running) return;
  const dest = el.dest.value.trim();
  if (!dest) { alert('Indica destino SSH: usuario@host'); return; }
  const ready = await ensureSshPasswordReady();
  if (!ready) return;
  await saveFormLocal();

  state.running = true;
  state.cancelRequested = false;
  el.statusGlobal.className = 'badge state-running badge-state';
  el.statusGlobal.textContent = 'En ejecución';
  el.btnRunAll.disabled = true;
  el.btnCancelRun.disabled = false;

  try {
    for (const s of steps) {
      await runStep(s.id);
      if (state.cancelRequested) {
        el.statusGlobal.className = 'badge state-error badge-state';
        el.statusGlobal.textContent = 'Cancelado por usuario';
        log('Ejecución cancelada. Se detuvo al finalizar el paso actual.', 'error');
        return;
      }
    }
    el.statusGlobal.className = 'badge state-done badge-state';
    el.statusGlobal.textContent = 'Migración completada';
    log('Migración finalizada correctamente', 'ok');
  } catch (e) {
    el.statusGlobal.className = 'badge state-error badge-state';
    el.statusGlobal.textContent = 'Error';
    log(e.message, 'error');
  } finally {
    state.running = false;
    el.btnRunAll.disabled = false;
    el.btnCancelRun.disabled = true;
  }
}

async function checkEnv() {
  const res = await fetch(API + '?action=check');
  const d = await res.json();
  if (!d.ok) { log('No se pudo verificar entorno', 'error'); return; }
  log(`Script: ${d.script_ready ? 'OK' : 'NO'}`);
  log(`sshpass: ${d.sshpass_available ? 'Disponible' : 'No instalado'}`);
}

function resetAll() {
  el.logBox.innerHTML = '';
  el.statusGlobal.className = 'badge state-pending badge-state';
  el.statusGlobal.textContent = 'En espera';
  state.cancelRequested = false;
  renderSteps();
}

function clearLog() {
  el.logBox.innerHTML = '';
}

function cancelRun() {
  if (!state.running) return;
  state.cancelRequested = true;
  el.btnCancelRun.disabled = true;
  el.statusGlobal.className = 'badge state-error badge-state';
  el.statusGlobal.textContent = 'Cancelando...';
  log('Cancelación solicitada: se detendrá al terminar el paso actual.', 'error');
}

el.btnRunAll.addEventListener('click', runAll);
el.btnCheck.addEventListener('click', checkEnv);
el.btnReset.addEventListener('click', resetAll);
el.btnClearSaved.addEventListener('click', clearFormLocal);
el.btnUnlockPwd.addEventListener('click', unlockSavedPassword);
el.btnClearLog.addEventListener('click', clearLog);
el.btnCancelRun.addEventListener('click', cancelRun);

[el.dest, el.sshPort, el.mode, el.syncUsers, el.sshPassword, el.masterKey].forEach(node => {
  node.addEventListener('input', () => { saveFormLocal().catch(() => {}); });
  node.addEventListener('change', () => { saveFormLocal().catch(() => {}); });
});

renderSteps();
loadFormLocal();
if (sessionStorage.getItem(MASTER_KEY_SESSION)) {
  el.masterKey.value = sessionStorage.getItem(MASTER_KEY_SESSION);
}
</script>
</body>
</html>
