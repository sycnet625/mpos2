'use strict';

const fs = require('fs');
const path = require('path');
const puppeteer = require('/var/www/wa_web_bridge/node_modules/puppeteer');

function fail(msg, extra = {}) {
  process.stdout.write(JSON.stringify({ status: 'error', msg, ...extra }, null, 2));
  process.exit(1);
}

async function main() {
  const cookiesFile = process.argv[2] || '';
  if (!cookiesFile || !fs.existsSync(cookiesFile)) {
    fail('No existe archivo de cookies');
  }

  let parsed;
  try {
    parsed = JSON.parse(fs.readFileSync(cookiesFile, 'utf8'));
  } catch (err) {
    fail('No se pudo leer cookies', { error: err.message });
  }
  const cookies = Array.isArray(parsed.cookies) ? parsed.cookies : [];
  if (!cookies.length) fail('No hay cookies guardadas');

  const userDataDir = '/var/www/fb_bot_browser_profile';
  fs.mkdirSync(userDataDir, { recursive: true });

  const browser = await puppeteer.launch({
    headless: 'new',
    userDataDir,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--disable-crashpad',
      '--disable-breakpad',
      '--no-first-run',
      '--disable-extensions'
    ]
  });

  try {
    const page = await browser.newPage();
    await page.setCookie(...cookies);
    await page.goto('https://www.facebook.com/groups/feed/', { waitUntil: 'networkidle2', timeout: 90000 });

    const currentUrl = page.url();
    if (/login|checkpoint/i.test(currentUrl)) {
      fail('Las cookies no abrieron una sesión válida de Facebook');
    }

    for (let i = 0; i < 4; i += 1) {
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await new Promise((resolve) => setTimeout(resolve, 1200));
    }

    const rows = await page.evaluate(() => {
      const normalizeUrl = (href) => {
        try {
          const url = new URL(href, 'https://www.facebook.com');
          return url.toString();
        } catch (_) {
          return '';
        }
      };
      const extractId = (href) => {
        const normalized = normalizeUrl(href);
        const match = normalized.match(/facebook\.com\/groups\/([^/?#]+)/i);
        return match ? String(match[1]).trim() : '';
      };
      const cleanName = (text) => {
        return String(text || '')
          .replace(/\s+/g, ' ')
          .replace(/Activo por última vez hace.*$/i, '')
          .replace(/Active \d.*$/i, '')
          .trim();
      };
      const map = new Map();
      const anchors = Array.from(document.querySelectorAll('a[href*="/groups/"]'));
      for (const a of anchors) {
        const href = a.getAttribute('href') || '';
        const id = extractId(href);
        if (!id) continue;
        const idLower = id.toLowerCase();
        if (['feed', 'discover', 'joins'].includes(idLower)) continue;
        if (!/^\d+$/.test(id) && !/^permalink$/i.test(id)) continue;
        const name = cleanName(a.textContent || '');
        const url = normalizeUrl(href);
        const current = map.get(id) || { id, name: '', url };
        if (!current.name && name) current.name = name;
        if (!current.url && url) current.url = url;
        map.set(id, current);
      }
      return Array.from(map.values()).filter((row) => row.id && row.url && row.name);
    });

    process.stdout.write(JSON.stringify({
      status: 'success',
      rows,
      found: rows.length,
      scanned_at: new Date().toISOString()
    }, null, 2));
  } finally {
    await browser.close();
  }
}

main().catch((err) => fail('Fallo general del scraper', { error: err.message }));
