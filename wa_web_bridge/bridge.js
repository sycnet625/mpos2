'use strict';

const path = require('path');
const fs = require('fs');
const qrcode = require('qrcode-terminal');
const { Client, LocalAuth, MessageMedia, Buttons } = require('whatsapp-web.js');

const POS_BOT_HOST = process.env.POS_BOT_HOST || '';
const API_BASE = process.env.POS_BOT_API_BASE || (POS_BOT_HOST ? `https://${POS_BOT_HOST}` : 'http://127.0.0.1');
const LOOPBACK_API_BASE = process.env.POS_BOT_LOOPBACK_API_BASE || 'http://127.0.0.1';
const API_PATH = process.env.POS_BOT_API_PATH || '/pos_bot_api.php?action=web_incoming';
const API_JOBS_PATH = process.env.POS_BOT_JOBS_PATH || '/pos_bot_api.php?action=bridge_scan_jobs';
const API_URL = process.env.POS_BOT_API_URL || `${API_BASE}${API_PATH}`;
const API_JOBS_URL = process.env.POS_BOT_JOBS_URL || `${API_BASE}${API_JOBS_PATH}`;
const API_ORIGIN = (() => {
  const publicOrigin = String(process.env.POS_BOT_PUBLIC_ORIGIN || '').trim();
  if (publicOrigin) return publicOrigin.replace(/\/+$/, '');
  if (POS_BOT_HOST) return `https://${POS_BOT_HOST}`;
  try { return new URL(API_URL).origin; } catch (_) { return ''; }
})();
const VERIFY_TOKEN = process.env.POS_BOT_VERIFY_TOKEN || 'palweb_bot_verify';
const SESSION_NAME = String(process.env.WA_SESSION_NAME || 'palweb-pos-bot')
  .replace(/[^a-zA-Z0-9_-]+/g, '-')
  .replace(/-+/g, '-')
  .replace(/^-|-$/g, '') || 'palweb-pos-bot';
const AUTH_PATH = process.env.WA_AUTH_PATH || path.join(__dirname, '.wwebjs_auth');
const STATUS_FILE = process.env.WA_STATUS_FILE || path.join(__dirname, 'status.json');
const RUNTIME_DIR = process.env.WA_RUNTIME_DIR || path.join(__dirname, 'runtime');
const LOG_FILE = process.env.WA_LOG_FILE || path.join(RUNTIME_DIR, 'bridge.log');
const CHATS_FILE = process.env.WA_CHATS_FILE || path.join(RUNTIME_DIR, 'palweb_wa_chats.json');
const PROMO_QUEUE_FILE = process.env.WA_PROMO_QUEUE_FILE || path.join(RUNTIME_DIR, 'palweb_wa_promo_queue.json');
const OUTBOX_QUEUE_FILE = process.env.WA_OUTBOX_QUEUE_FILE || path.join(RUNTIME_DIR, 'palweb_wa_outbox_queue.json');
const CONTROL_FILE = process.env.WA_CONTROL_FILE || path.join(RUNTIME_DIR, 'palweb_wa_bridge_control.json');
const PROMO_TIMEZONE = process.env.WA_PROMO_TIMEZONE || 'America/Havana';
const LOCAL_PRODUCT_IMAGE_DIRS = [
  path.join(__dirname, '..', 'assets', 'product_images'),
  path.join(__dirname, 'assets', 'product_images')
];

try {
  fs.mkdirSync(RUNTIME_DIR, { recursive: true });
} catch (err) {
  console.error('[bridge] No se pudo crear runtime dir:', err.message);
}

function appendBridgeLog(level, args) {
  try {
    const line = [
      new Date().toISOString(),
      `[${level}]`,
      args.map((v) => {
        if (typeof v === 'string') return v;
        try { return JSON.stringify(v); } catch (_) { return String(v); }
      }).join(' ')
    ].join(' ') + '\n';
    fs.appendFileSync(LOG_FILE, line);
  } catch (_) {}
}

const origConsoleLog = console.log.bind(console);
const origConsoleError = console.error.bind(console);
console.log = (...args) => {
  appendBridgeLog('INFO', args);
  origConsoleLog(...args);
};
console.error = (...args) => {
  appendBridgeLog('ERROR', args);
  origConsoleError(...args);
};

const client = new Client({
  authStrategy: new LocalAuth({ clientId: SESSION_NAME, dataPath: AUTH_PATH }),
  puppeteer: {
    headless: true,
    protocolTimeout: 180000,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-crashpad',
      '--no-first-run',
      '--no-zygote',
      '--disable-gpu'
    ]
  },
  takeoverOnConflict: true,
  takeoverTimeoutMs: 0,
  webVersionCache: {
    type: 'none'
  }
});
let bgLoopsStarted = false;
let initRetryTimer = null;
let currentBridgeState = 'starting';
let currentStateMeta = {};
const processedMessageIds = new Map();
let controlCommandBusy = false;

function normalizeWaUserId(rawFrom) {
  const base = String(rawFrom || '').split('@')[0] || '';
  const digits = base.replace(/\D+/g, '');
  if (digits) return digits;
  return base.replace(/[^a-zA-Z0-9._-]/g, '').slice(0, 40);
}

function normalizeTargetId(raw) {
  const value = String(raw || '').trim();
  if (!value) return '';
  if (value.includes('@')) return value;
  const digits = value.replace(/\D+/g, '');
  if (digits) return `${digits}@c.us`;
  return value;
}

function writeStatus(state, extra = {}) {
  const payload = {
    state,
    promo_timezone: PROMO_TIMEZONE,
    updated_at: new Date().toISOString(),
    ...extra
  };
  try {
    fs.writeFileSync(STATUS_FILE, JSON.stringify(payload, null, 2));
  } catch (err) {
    console.error('[bridge] No se pudo escribir status:', err.message);
  }
}

function setBridgeState(state, extra = {}) {
  currentBridgeState = state;
  currentStateMeta = { ...currentStateMeta, ...extra };
  writeStatus(state, currentStateMeta);
}

function getClientIdentity() {
  try {
    const info = client.info || {};
    const wid = String(info?.wid?._serialized || info?.wid?.user || info?.me?._serialized || info?.me?.user || '').trim();
    const phone = wid.split('@')[0] || '';
    const pushname = String(info?.pushname || info?.wid?.name || info?.me?.name || '').trim();
    const platform = String(info?.platform || '').trim();
    return {
      owner_phone: phone,
      owner_jid: wid,
      owner_name: pushname,
      owner_platform: platform
    };
  } catch (_) {
    return {
      owner_phone: '',
      owner_jid: '',
      owner_name: '',
      owner_platform: ''
    };
  }
}

function readJsonFile(file, fallback) {
  try {
    if (!fs.existsSync(file)) return fallback;
    const raw = fs.readFileSync(file, 'utf8');
    if (!raw.trim()) return fallback;
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : fallback;
  } catch (_) {
    return fallback;
  }
}

function writeJsonFile(file, data) {
  try {
    const tmp = `${file}.tmp`;
    fs.writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf8');
    fs.renameSync(tmp, file);
    return true;
  } catch (err) {
    console.error('[bridge] Error escribiendo JSON:', file, err.message);
    return false;
  }
}

async function apiFetch(url, options = {}) {
  const headers = { ...(options.headers || {}) };
  if (POS_BOT_HOST && !headers.Host) headers.Host = POS_BOT_HOST;
  if (POS_BOT_HOST && !headers['X-Forwarded-Host']) headers['X-Forwarded-Host'] = POS_BOT_HOST;
  if (POS_BOT_HOST && !headers['X-Forwarded-Proto']) headers['X-Forwarded-Proto'] = 'https';
  try {
    return await fetch(url, { ...options, headers });
  } catch (err) {
    try {
      const parsed = new URL(url);
      if (parsed.hostname !== POS_BOT_HOST || !/^https:/i.test(parsed.protocol)) throw err;
      const fallbackUrl = `${LOOPBACK_API_BASE.replace(/\/+$/, '')}${parsed.pathname}${parsed.search}`;
      console.error('[bridge] fetch principal falló, reintentando por loopback:', err.message || err, '=>', fallbackUrl);
      return await fetch(fallbackUrl, { ...options, headers });
    } catch (_) {
      throw err;
    }
  }
}

function removePathIfExists(targetPath) {
  try {
    if (!fs.existsSync(targetPath)) return;
    fs.rmSync(targetPath, { recursive: true, force: true });
  } catch (err) {
    console.error('[bridge] No se pudo borrar:', targetPath, err.message);
  }
}

async function processControlFileTick() {
  if (controlCommandBusy) return;
  if (!fs.existsSync(CONTROL_FILE)) return;
  controlCommandBusy = true;
  try {
    const raw = fs.readFileSync(CONTROL_FILE, 'utf8');
    if (!raw.trim()) {
      fs.unlinkSync(CONTROL_FILE);
      return;
    }
    const command = JSON.parse(raw);
    const action = String(command?.action || '').trim();
    fs.unlinkSync(CONTROL_FILE);
    if (!action) return;
    if (action === 'restart') {
      setBridgeState('starting', { control_action: 'restart', session_ok: false, qr: '' });
      try { await client.destroy(); } catch (err) { console.warn('[bridge] destroy restart:', err.message); }
      setTimeout(() => { safeInit().catch(() => {}); }, 1200);
      return;
    }
    if (action === 'reset_session') {
      setBridgeState('starting', { control_action: 'reset_session', session_ok: false, qr: '' });
      try { await client.logout(); } catch (err) { console.warn('[bridge] logout reset_session:', err.message); }
      try { await client.destroy(); } catch (err) { console.warn('[bridge] destroy reset_session:', err.message); }
      removePathIfExists(path.join(AUTH_PATH, `session-${SESSION_NAME}`));
      removePathIfExists(path.join(__dirname, '.wwebjs_cache'));
      removePathIfExists(STATUS_FILE);
      setTimeout(() => { safeInit().catch(() => {}); }, 1500);
    }
  } catch (err) {
    console.error('[bridge] Error procesando control file:', err.message);
  } finally {
    controlCommandBusy = false;
  }
}

async function refreshChatsCache() {
  try {
    const chats = await client.getChats();
    const rows = chats.map((c) => ({
      id: String(c.id?._serialized || ''),
      name: String(c.name || c.formattedTitle || c.id?.user || c.id?._serialized || ''),
      is_group: !!c.isGroup,
      is_contact: !c.isGroup
    })).filter((x) => x.id);
    writeJsonFile(CHATS_FILE, { updated_at: new Date().toISOString(), rows });
  } catch (err) {
    console.error('[bridge] No se pudo refrescar chats:', err.message);
  }
}

function randomInt(min, max) {
  const a = Math.max(0, Number(min || 0));
  const b = Math.max(a, Number(max || a));
  return Math.floor(Math.random() * (b - a + 1)) + a;
}

function localDateInfo(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: PROMO_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  }).formatToParts(date);

  const map = {};
  for (const p of parts) {
    if (p && p.type && p.type !== 'literal') map[p.type] = p.value;
  }

  const weekdayMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  const weekday = String(map.weekday || 'Sun').slice(0, 3);
  const day = weekdayMap[weekday] ?? 0;
  const y = String(map.year || '');
  const m = String(map.month || '').padStart(2, '0');
  const d = String(map.day || '').padStart(2, '0');
  const hh = String(map.hour || '').padStart(2, '0');
  const mm = String(map.minute || '').padStart(2, '0');

  return { day, hm: `${hh}:${mm}`, key: `${y}-${m}-${d}_${hh}:${mm}` };
}

function normalizeProductImageUrl(rawUrl, productId = '') {
  let mediaUrl = String(rawUrl || '').trim();
  if (mediaUrl === '') return '';
  mediaUrl = mediaUrl.replace('image.php?id=', 'image.php?code=');
  if (/image\.php(?:\?|$)/i.test(mediaUrl) && !/[?&]code=/i.test(mediaUrl) && productId) {
    const sep = mediaUrl.includes('?') ? '&' : '?';
    mediaUrl = `${mediaUrl}${sep}code=${encodeURIComponent(String(productId))}`;
  }
  if (/image\.php(?:\?|$)/i.test(mediaUrl) && !/[?&]fmt=/i.test(mediaUrl)) {
    const sep = mediaUrl.includes('?') ? '&' : '?';
    mediaUrl = `${mediaUrl}${sep}fmt=jpg`;
  }
  if (!/^https?:\/\//i.test(mediaUrl) && API_ORIGIN) {
    mediaUrl = mediaUrl.startsWith('/') ? `${API_ORIGIN}${mediaUrl}` : `${API_ORIGIN}/${mediaUrl}`;
  }
  return mediaUrl;
}

function extractProductCodeFromImageUrl(rawUrl) {
  const mediaUrl = String(rawUrl || '').trim();
  if (!mediaUrl) return '';
  try {
    const parsed = new URL(normalizeProductImageUrl(mediaUrl, ''), API_ORIGIN || 'https://www.palweb.net');
    if (!/image\.php$/i.test(parsed.pathname)) return '';
    return String(parsed.searchParams.get('code') || '').trim();
  } catch (_) {
    return '';
  }
}

function resolveLocalProductImage(productId = '') {
  const sku = String(productId || '').trim();
  if (!sku) return null;
  const exts = [
    ['.jpg', 'image/jpeg'],
    ['.jpeg', 'image/jpeg'],
    ['.webp', 'image/webp'],
    ['.avif', 'image/avif'],
    ['.gif', 'image/gif'],
    ['.png', 'image/png']
  ];
  for (const baseDir of LOCAL_PRODUCT_IMAGE_DIRS) {
    for (const [ext, mime] of exts) {
      const filePath = path.join(baseDir, `${sku}${ext}`);
      if (fs.existsSync(filePath)) {
        return { filePath, mime, ext: ext.slice(1) };
      }
    }
  }
  return null;
}

async function sendMediaUrl(targetId, rawUrl, caption = '', fileBase = 'banner') {
  const mediaUrl = normalizeProductImageUrl(rawUrl, '');
  if (!mediaUrl) {
    if (caption) {
      await client.sendMessage(targetId, caption);
      return 1;
    }
    return 0;
  }
  const productCode = extractProductCodeFromImageUrl(mediaUrl);
  if (productCode) {
    const localImage = resolveLocalProductImage(productCode);
    if (localImage) {
      try {
        const bytes = fs.readFileSync(localImage.filePath);
        const b64 = Buffer.from(bytes).toString('base64');
        if (!b64) throw new Error('imagen local vacía');
        const fileName = `${productCode.replace(/[^a-zA-Z0-9._-]/g, '_')}.${localImage.ext || 'jpg'}`;
        const media = new MessageMedia(localImage.mime, b64, fileName);
        if (caption) {
          await client.sendMessage(targetId, media, { caption });
        } else {
          await client.sendMessage(targetId, media);
        }
        return 1;
      } catch (err) {
        console.error('[bridge] No se pudo enviar imagen local detectada por URL:', productCode, localImage.filePath, err.message || err);
      }
    }
  }
  try {
    const res = await fetch(mediaUrl, {
      method: 'GET',
      redirect: 'follow',
      headers: { 'User-Agent': 'Mozilla/5.0 (PalWebBot/1.0)' }
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const contentType = String(res.headers.get('content-type') || '').split(';')[0].trim();
    if (!contentType.startsWith('image/')) throw new Error(`MIME inválido: ${contentType}`);
    if (contentType === 'image/svg+xml') throw new Error('MIME no compatible para WhatsApp: image/svg+xml');
    const bytes = await res.arrayBuffer();
    const b64 = Buffer.from(bytes).toString('base64');
    if (!b64) throw new Error('imagen vacía');
    const ext = contentType.split('/')[1] || 'jpg';
    const fileName = `${String(fileBase || 'banner').replace(/[^a-zA-Z0-9._-]/g, '_')}.${ext}`;
    const media = new MessageMedia(contentType, b64, fileName);
    if (caption) {
      await client.sendMessage(targetId, media, { caption });
    } else {
      await client.sendMessage(targetId, media);
    }
    return 1;
  } catch (err) {
    const fallback = [caption, mediaUrl].filter(Boolean).join('\n');
    await client.sendMessage(targetId, fallback);
    return 1;
  }
}

async function sendButtonsMessage(targetId, body, buttons, title = '', footer = '') {
  const labelButtons = Array.isArray(buttons) ? buttons.map((b) => String(b || '').trim()).filter(Boolean).slice(0, 3) : [];
  const textBody = String(body || '').trim();
  if (!labelButtons.length) {
    if (textBody) {
      await client.sendMessage(targetId, textBody);
      return 1;
    }
    return 0;
  }
  try {
    const payload = new Buttons(textBody || 'Selecciona una opción', labelButtons.map((txt) => ({ body: txt })), String(title || '').trim(), String(footer || '').trim());
    await client.sendMessage(targetId, payload);
    return 1;
  } catch (err) {
    const fallback = [textBody || 'Opciones rápidas:']
      .concat(labelButtons.map((txt, idx) => `${idx + 1}. ${txt}`))
      .join('\n');
    await client.sendMessage(targetId, fallback);
    return 1;
  }
}

async function sendProductCards(targetId, text, products, outroText, bannerImages) {
  let sentCount = 0;
  const intro = String(text || '').trim();
  const banners = Array.isArray(bannerImages) ? bannerImages.slice(0, 3) : [];
  if (banners.length) {
    for (let i = 0; i < banners.length; i += 1) {
      const b = banners[i] || {};
      const caption = i === 0 ? intro : '';
      sentCount += await sendMediaUrl(targetId, String(b.url || b).trim(), caption, String(b.name || `banner_${i + 1}`));
    }
  } else if (intro) {
    await client.sendMessage(targetId, intro);
    sentCount += 1;
  }

  for (const p of (products || [])) {
    const name = String(p?.name || p?.id || 'Producto');
    const sku = String(p?.id || '').trim();
    const price = Number(p?.price || 0);
    const caption = `${name}\nPrecio: $${price.toFixed(2)}`;
    const imageUrl = String(p?.image || '').trim();
    const localImage = resolveLocalProductImage(sku);
    if (localImage) {
      try {
        const bytes = fs.readFileSync(localImage.filePath);
        const b64 = Buffer.from(bytes).toString('base64');
        if (!b64) throw new Error('imagen local vacía');
        const fileName = `${(sku || 'producto').replace(/[^a-zA-Z0-9._-]/g, '_')}.${localImage.ext || 'jpg'}`;
        const media = new MessageMedia(localImage.mime, b64, fileName);
        await client.sendMessage(targetId, media, { caption });
        sentCount += 1;
        continue;
      } catch (err) {
        console.error('[bridge] No se pudo enviar imagen local de producto:', sku || name, localImage.filePath, err.message || err);
      }
    }
    if (imageUrl) {
      sentCount += await sendMediaUrl(targetId, imageUrl, caption, sku || name || 'producto');
      continue;
    }
    await client.sendMessage(targetId, caption);
    sentCount += 1;
  }
  const outro = String(outroText || '').trim();
  if (outro) {
    await client.sendMessage(targetId, outro);
    sentCount += 1;
  }
  return sentCount;
}

async function processPromoQueueTick() {
  if (!client.info) return;
  const queue = readJsonFile(PROMO_QUEUE_FILE, { jobs: [] });
  const jobs = Array.isArray(queue.jobs) ? queue.jobs : [];
  if (!jobs.length) return;

  let changed = false;
  const now = Math.floor(Date.now() / 1000);
  const nowInfo = localDateInfo();

  for (const job of jobs) {
    if (!job || typeof job !== 'object') continue;
    if (job.status === 'done' || job.status === 'error') continue;
    if ((job.next_run_at || 0) > now) continue;

    const scheduled = Number(job.schedule_enabled || 0) === 1;
    if (scheduled && Number(job.current_index || 0) === 0 && job.status !== 'running' && job.status !== 'queued') {
      const timeOk = String(job.schedule_time || '') === nowInfo.hm;
      const days = Array.isArray(job.schedule_days) ? job.schedule_days.map((x) => Number(x)) : [];
      const dayOk = days.includes(nowInfo.day);
      if (!(timeOk && dayOk)) {
        job.status = 'scheduled';
        job.next_run_at = now + 20;
        changed = true;
        continue;
      }
      if (String(job.last_schedule_key || '') === nowInfo.key) {
        job.status = 'scheduled';
        job.next_run_at = now + 20;
        changed = true;
        continue;
      }
      job.last_schedule_key = nowInfo.key;
      job.status = 'queued';
      job.next_run_at = now;
      changed = true;
    }

    const targets = Array.isArray(job.targets) ? job.targets : [];
    const products = Array.isArray(job.products) ? job.products : [];
    const idx = Number(job.current_index || 0);

    if (idx >= targets.length) {
      if (scheduled) {
        job.status = 'scheduled';
        job.current_index = 0;
        job.next_run_at = now + 20;
      } else {
        job.status = 'done';
        job.done_at = new Date().toISOString();
      }
      changed = true;
      continue;
    }

    const target = targets[idx] || {};
    const targetId = String(target.id || '').trim();
    const targetName = String(target.name || targetId).trim();
    if (!targetId) {
      job.log = Array.isArray(job.log) ? job.log : [];
      job.log.push({ at: new Date().toISOString(), target_id: '', target_name: '', ok: false, messages_sent: 0, error: 'target_id vacío' });
      job.current_index = idx + 1;
      job.next_run_at = now + randomInt(job.min_seconds || 60, job.max_seconds || 120);
      changed = true;
      continue;
    }

    try {
      const sentCount = await sendProductCards(targetId, job.text || '', products, job.outro_text || '', job.banner_images || []);
      job.log = Array.isArray(job.log) ? job.log : [];
      job.log.push({
        at: new Date().toISOString(),
        target_id: targetId,
        target_name: targetName,
        ok: true,
        messages_sent: sentCount,
        error: ''
      });
      job.current_index = idx + 1;
      if (job.current_index >= targets.length) {
        job.status = 'done';
        job.done_at = new Date().toISOString();
      } else {
        job.status = 'running';
        job.next_run_at = now + randomInt(job.min_seconds || 60, job.max_seconds || 120);
      }
      changed = true;
    } catch (err) {
      job.log = Array.isArray(job.log) ? job.log : [];
      job.log.push({
        at: new Date().toISOString(),
        target_id: targetId,
        target_name: targetName,
        ok: false,
        messages_sent: 0,
        error: String(err.message || err)
      });
      job.status = 'error';
      job.error = String(err.message || err);
      changed = true;
    }
  }

  if (changed) {
    writeJsonFile(PROMO_QUEUE_FILE, { jobs });
  }
}

async function processOutboundQueueTick() {
  const queue = readJsonFile(OUTBOX_QUEUE_FILE, { jobs: [] });
  const jobs = Array.isArray(queue.jobs) ? queue.jobs : [];
  let changed = false;
  for (const job of jobs) {
    if (String(job.status || 'queued') !== 'queued') continue;
    const targetId = normalizeTargetId(job.target_id || job.wa_user_id || '');
    if (!targetId) {
      job.status = 'error';
      job.error = 'target_id vacío';
      changed = true;
      continue;
    }
    try {
      const type = String(job.type || 'text').toLowerCase();
      if (type === 'image') {
        await sendMediaUrl(targetId, String(job.url || '').trim(), String(job.caption || job.text || '').trim(), 'bot_reply');
      } else if (type === 'buttons') {
        await sendButtonsMessage(targetId, String(job.text || '').trim(), Array.isArray(job.buttons) ? job.buttons : [], String(job.title || '').trim(), String(job.footer || '').trim());
      } else {
        const text = String(job.text || '').trim();
        if (text) await client.sendMessage(targetId, text);
      }
      job.status = 'sent';
      job.sent_at = new Date().toISOString();
      changed = true;
    } catch (err) {
      job.status = 'error';
      job.error = String(err.message || err);
      changed = true;
    }
  }
  if (changed) {
    const kept = jobs.filter((job) => !['sent'].includes(String(job.status || '')));
    writeJsonFile(OUTBOX_QUEUE_FILE, { jobs: kept });
  }
}

async function scanDueJobs() {
  try {
    await apiFetch(API_JOBS_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ verify_token: VERIFY_TOKEN })
    });
  } catch (err) {
    console.error('[bridge] No se pudo escanear jobs del bot:', err.message);
  }
}

async function processIncoming(message) {
  const messageId = String(message?.id?._serialized || message?.id?.id || '');
  if (messageId) {
    const now = Date.now();
    const prev = processedMessageIds.get(messageId) || 0;
    if (now - prev < 180000) return;
    processedMessageIds.set(messageId, now);
    if (processedMessageIds.size > 4000) {
      const cutoff = now - 180000;
      for (const [k, ts] of processedMessageIds.entries()) {
        if (ts < cutoff) processedMessageIds.delete(k);
      }
    }
  }

  let text = String(message.body || '').trim();
  const msgType = String(message.type || 'text');
  const ignoredTypes = new Set([
    'e2e_notification',
    'notification_template',
    'protocol',
    'ciphertext',
    'gp2',
    'revoked'
  ]);
  if (ignoredTypes.has(msgType)) return;
  if (!text) {
    if (msgType === 'buttons_response') text = String(message.selectedButtonId || '').trim();
    else if (msgType === 'list_response') text = String(message.selectedRowId || '').trim();
    else if (msgType === 'image') text = '[imagen]';
    else if (msgType === 'video') text = '[video]';
    else if (msgType === 'audio' || msgType === 'ptt') text = '[audio]';
    else if (msgType === 'document') text = '[documento]';
    else if (msgType === 'sticker') text = '[sticker]';
    else text = `[${msgType}]`;
  }
  if (/^\[(e2e_notification|notification_template|protocol|ciphertext|gp2|revoked)\]$/i.test(text)) return;

  const wa_user_id = normalizeWaUserId(message.from);
  if (!wa_user_id) return;

  let wa_name = String(message._data?.notifyName || message._data?.pushname || '').trim();
  if (!wa_name || wa_name.toLowerCase() === 'unknown') {
    try {
      const contact = await message.getContact();
      wa_name = String(contact?.pushname || contact?.name || contact?.shortName || '').trim();
    } catch (_) {}
  }
  if (!wa_name) wa_name = 'Cliente';
  setBridgeState('message_in', { last_from: String(message.from || ''), last_text_preview: text.slice(0, 80) });
  console.log(`[bridge] inbound from=${String(message.from || '')} type=${msgType} text="${text.slice(0, 80)}"`);

  const res = await apiFetch(API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      verify_token: VERIFY_TOKEN,
      wa_user_id,
      wa_name,
      text
    })
  });

  let data;
  try {
    data = await res.json();
  } catch (err) {
    console.error('[bridge] respuesta no JSON desde API', err);
    return;
  }

  if (!res.ok || data.status === 'error') {
    console.error('[bridge] API error', res.status, data);
    return;
  }

  const responses = Array.isArray(data.responses) ? data.responses : [];
  for (const out of responses) {
    const type = String(out?.type || 'text').trim().toLowerCase();
    if (type === 'image') {
      const url = String(out?.url || '').trim();
      const caption = String(out?.caption || out?.text || '').trim();
      if (!url) {
        if (caption) await client.sendMessage(message.from, caption);
        continue;
      }
      await sendMediaUrl(message.from, url, caption, 'bot_reply');
      continue;
    }
    if (type === 'buttons') {
      await sendButtonsMessage(message.from, String(out?.text || '').trim(), Array.isArray(out?.buttons) ? out.buttons : [], String(out?.title || '').trim(), String(out?.footer || '').trim());
      continue;
    }
    const outText = String(out?.text || '').trim();
    if (!outText) continue;
    await client.sendMessage(message.from, outText);
  }
}

client.on('qr', (qr) => {
  setBridgeState('qr_required', { qr: String(qr || ''), session_ok: false });
  console.log('\n[bridge] Escanea este QR con tu telefono (WhatsApp > Dispositivos vinculados):\n');
  qrcode.generate(qr, { small: true });
});

client.on('ready', () => {
  setBridgeState('ready', { qr: '', session_ok: true, ...getClientIdentity() });
  console.log('[bridge] WhatsApp Web conectado y listo.');
  if (!bgLoopsStarted) {
    bgLoopsStarted = true;
    refreshChatsCache();
    setInterval(refreshChatsCache, 120000);
    setInterval(() => { processPromoQueueTick().catch(() => {}); }, 5000);
    setInterval(() => { processOutboundQueueTick().catch(() => {}); }, 3000);
    setInterval(() => { scanDueJobs().catch(() => {}); }, 60000);
    setInterval(() => { processControlFileTick().catch(() => {}); }, 1500);
    setInterval(() => {
      const connected = !!client.info;
      if (connected) {
        setBridgeState('ready', { qr: '', session_ok: true, ...getClientIdentity() });
      } else {
        setBridgeState('disconnected', { reason: 'heartbeat_no_client_info', qr: '', session_ok: false });
      }
    }, 15000);
  }
});

client.on('authenticated', () => {
  setBridgeState('authenticated', { qr: '', session_ok: true, ...getClientIdentity() });
  console.log('[bridge] Sesion autenticada.');
});

client.on('auth_failure', (msg) => {
  setBridgeState('auth_failure', { message: String(msg || ''), qr: '', session_ok: false });
  console.error('[bridge] Fallo autenticacion:', msg);
});

client.on('disconnected', (reason) => {
  setBridgeState('disconnected', { reason: String(reason || ''), qr: '', session_ok: false });
  console.warn('[bridge] Desconectado:', reason);
});

client.on('change_state', (waState) => {
  const stateText = String(waState || '');
  const upper = stateText.toUpperCase();
  if (upper.includes('UNPAIRED') || upper.includes('CONFLICT') || upper.includes('UNLAUNCHED')) {
    setBridgeState('disconnected', { reason: `wa_state_${stateText}`, wa_state: stateText, qr: '', session_ok: false });
    return;
  }
  if (upper.includes('CONNECTED') || upper.includes('OPENING') || upper.includes('PAIRING')) {
    setBridgeState('ready', { wa_state: stateText, qr: '', session_ok: !!client.info, ...getClientIdentity() });
    return;
  }
  setBridgeState(currentBridgeState, { wa_state: stateText });
});

client.on('message', async (message) => {
  try {
    if (message.fromMe) return;
    const from = String(message.from || '');
    if (!(from.endsWith('@c.us') || from.endsWith('@lid'))) return;
    await processIncoming(message);
  } catch (err) {
    console.error('[bridge] Error procesando mensaje:', err);
  }
});

async function shutdown(signal) {
  setBridgeState('stopped', { signal, qr: '', session_ok: false });
  console.log(`[bridge] Cerrando por ${signal}...`);
  try {
    await client.destroy();
  } catch (err) {
    console.error('[bridge] Error al cerrar cliente:', err);
  }
  process.exit(0);
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('unhandledRejection', (err) => {
  const msg = String(err?.message || err || 'unknown');
  console.error('[bridge] unhandledRejection:', msg);
  setBridgeState('disconnected', { reason: 'unhandled_rejection', message: msg, qr: '', session_ok: false });
  if (!initRetryTimer) {
    initRetryTimer = setTimeout(() => {
      initRetryTimer = null;
      safeInit();
    }, 7000);
  }
});
process.on('uncaughtException', (err) => {
  const msg = String(err?.message || err || 'unknown');
  console.error('[bridge] uncaughtException:', msg);
  setBridgeState('disconnected', { reason: 'uncaught_exception', message: msg, qr: '', session_ok: false });
  if (!initRetryTimer) {
    initRetryTimer = setTimeout(() => {
      initRetryTimer = null;
      safeInit();
    }, 7000);
  }
});

async function safeInit() {
  setBridgeState('starting', { session_ok: false });
  try {
    await client.initialize();
  } catch (err) {
    const msg = String(err?.message || err || 'init_error');
    console.error('[bridge] initialize error:', msg);
    setBridgeState('disconnected', { reason: 'init_error', message: msg, qr: '', session_ok: false });
    if (!initRetryTimer) {
      initRetryTimer = setTimeout(() => {
        initRetryTimer = null;
        safeInit();
      }, 7000);
    }
  }
}

safeInit();
