'use strict';

const path = require('path');
const fs = require('fs');
const qrcode = require('qrcode-terminal');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

const API_URL = process.env.POS_BOT_API_URL || 'http://127.0.0.1/marinero/pos_bot_api.php?action=web_incoming';
const API_ORIGIN = (() => {
  try { return new URL(API_URL).origin; } catch (_) { return ''; }
})();
const VERIFY_TOKEN = process.env.POS_BOT_VERIFY_TOKEN || 'palweb_bot_verify';
const SESSION_NAME = process.env.WA_SESSION_NAME || 'palweb-pos-bot';
const AUTH_PATH = process.env.WA_AUTH_PATH || path.join(__dirname, '.wwebjs_auth');
const STATUS_FILE = process.env.WA_STATUS_FILE || path.join(__dirname, 'status.json');
const CHATS_FILE = process.env.WA_CHATS_FILE || '/tmp/palweb_wa_chats.json';
const PROMO_QUEUE_FILE = process.env.WA_PROMO_QUEUE_FILE || '/tmp/palweb_wa_promo_queue.json';

const client = new Client({
  authStrategy: new LocalAuth({ clientId: SESSION_NAME, dataPath: AUTH_PATH }),
  puppeteer: {
    headless: true,
    protocolTimeout: 180000,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
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

function normalizeWaUserId(rawFrom) {
  const base = String(rawFrom || '').split('@')[0] || '';
  const digits = base.replace(/\D+/g, '');
  if (digits) return digits;
  return base.replace(/[^a-zA-Z0-9._-]/g, '').slice(0, 40);
}

function writeStatus(state, extra = {}) {
  const payload = {
    state,
    updated_at: new Date().toISOString(),
    ...extra
  };
  try {
    fs.writeFileSync(STATUS_FILE, JSON.stringify(payload, null, 2));
  } catch (err) {
    console.error('[bridge] No se pudo escribir status:', err.message);
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

async function sendProductCards(targetId, text, products) {
  const intro = String(text || '').trim();
  if (intro) await client.sendMessage(targetId, intro);

  for (const p of (products || [])) {
    const name = String(p?.name || p?.id || 'Producto');
    const price = Number(p?.price || 0);
    const caption = `${name}\nPrecio: $${price.toFixed(2)}`;
    const imageUrl = String(p?.image || '').trim();
    if (imageUrl) {
      try {
        let mediaUrl = imageUrl;
        if (!/^https?:\/\//i.test(mediaUrl) && API_ORIGIN) {
          mediaUrl = mediaUrl.startsWith('/') ? `${API_ORIGIN}${mediaUrl}` : `${API_ORIGIN}/${mediaUrl}`;
        }
        const media = await MessageMedia.fromUrl(mediaUrl, { unsafeMime: true });
        await client.sendMessage(targetId, media, { caption });
        continue;
      } catch (_) {}
    }
    await client.sendMessage(targetId, caption);
  }
}

async function processPromoQueueTick() {
  if (!client.info) return;
  const queue = readJsonFile(PROMO_QUEUE_FILE, { jobs: [] });
  const jobs = Array.isArray(queue.jobs) ? queue.jobs : [];
  if (!jobs.length) return;

  let changed = false;
  const now = Math.floor(Date.now() / 1000);

  for (const job of jobs) {
    if (!job || typeof job !== 'object') continue;
    if (job.status === 'done' || job.status === 'error') continue;
    if ((job.next_run_at || 0) > now) continue;

    const targets = Array.isArray(job.targets) ? job.targets : [];
    const products = Array.isArray(job.products) ? job.products : [];
    const idx = Number(job.current_index || 0);

    if (idx >= targets.length) {
      job.status = 'done';
      job.done_at = new Date().toISOString();
      changed = true;
      continue;
    }

    const target = targets[idx] || {};
    const targetId = String(target.id || '').trim();
    if (!targetId) {
      job.current_index = idx + 1;
      job.next_run_at = now + randomInt(job.min_seconds || 60, job.max_seconds || 120);
      changed = true;
      continue;
    }

    try {
      await sendProductCards(targetId, job.text || '', products);
      job.log = Array.isArray(job.log) ? job.log : [];
      job.log.push({ at: new Date().toISOString(), target_id: targetId, ok: true });
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
      job.log.push({ at: new Date().toISOString(), target_id: targetId, ok: false, error: String(err.message || err) });
      job.status = 'error';
      job.error = String(err.message || err);
      changed = true;
    }
  }

  if (changed) {
    writeJsonFile(PROMO_QUEUE_FILE, { jobs });
  }
}

async function processIncoming(message) {
  let text = String(message.body || '').trim();
  const msgType = String(message.type || 'text');
  if (!text) {
    if (msgType === 'image') text = '[imagen]';
    else if (msgType === 'video') text = '[video]';
    else if (msgType === 'audio' || msgType === 'ptt') text = '[audio]';
    else if (msgType === 'document') text = '[documento]';
    else if (msgType === 'sticker') text = '[sticker]';
    else text = `[${msgType}]`;
  }

  const wa_user_id = normalizeWaUserId(message.from);
  if (!wa_user_id) return;

  const wa_name = message._data?.notifyName || message._data?.pushname || message.author || 'Cliente';
  writeStatus('message_in', { last_from: String(message.from || ''), last_text_preview: text.slice(0, 80) });
  console.log(`[bridge] inbound from=${String(message.from || '')} type=${msgType} text="${text.slice(0, 80)}"`);

  const res = await fetch(API_URL, {
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
    const outText = String(out?.text || '').trim();
    if (!outText) continue;
    await client.sendMessage(message.from, outText);
  }
}

client.on('qr', (qr) => {
  writeStatus('qr_required', { qr: String(qr || '') });
  console.log('\n[bridge] Escanea este QR con tu telefono (WhatsApp > Dispositivos vinculados):\n');
  qrcode.generate(qr, { small: true });
});

client.on('ready', () => {
  writeStatus('ready', { qr: '' });
  console.log('[bridge] WhatsApp Web conectado y listo.');
  if (!bgLoopsStarted) {
    bgLoopsStarted = true;
    refreshChatsCache();
    setInterval(refreshChatsCache, 120000);
    setInterval(() => { processPromoQueueTick().catch(() => {}); }, 5000);
  }
});

client.on('authenticated', () => {
  writeStatus('authenticated', { qr: '' });
  console.log('[bridge] Sesion autenticada.');
});

client.on('auth_failure', (msg) => {
  writeStatus('auth_failure', { message: String(msg || ''), qr: '' });
  console.error('[bridge] Fallo autenticacion:', msg);
});

client.on('disconnected', (reason) => {
  writeStatus('disconnected', { reason: String(reason || ''), qr: '' });
  console.warn('[bridge] Desconectado:', reason);
});

client.on('message', async (message) => {
  try {
    if (message.fromMe) return;
    const from = String(message.from || '');
    if (!(from.endsWith('@c.us') || from.endsWith('@lid') || from.endsWith('@g.us'))) return;
    await processIncoming(message);
  } catch (err) {
    console.error('[bridge] Error procesando mensaje:', err);
  }
});

client.on('message_create', async (message) => {
  try {
    if (message.fromMe) return;
    const from = String(message.from || '');
    if (!(from.endsWith('@c.us') || from.endsWith('@lid') || from.endsWith('@g.us'))) return;
    await processIncoming(message);
  } catch (err) {
    console.error('[bridge] Error en message_create:', err);
  }
});

async function shutdown(signal) {
  writeStatus('stopped', { signal, qr: '' });
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
  writeStatus('disconnected', { reason: 'unhandled_rejection', message: msg, qr: '' });
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
  writeStatus('disconnected', { reason: 'uncaught_exception', message: msg, qr: '' });
  if (!initRetryTimer) {
    initRetryTimer = setTimeout(() => {
      initRetryTimer = null;
      safeInit();
    }, 7000);
  }
});

async function safeInit() {
  writeStatus('starting');
  try {
    await client.initialize();
  } catch (err) {
    const msg = String(err?.message || err || 'init_error');
    console.error('[bridge] initialize error:', msg);
    writeStatus('disconnected', { reason: 'init_error', message: msg, qr: '' });
    if (!initRetryTimer) {
      initRetryTimer = setTimeout(() => {
        initRetryTimer = null;
        safeInit();
      }, 7000);
    }
  }
}

safeInit();
