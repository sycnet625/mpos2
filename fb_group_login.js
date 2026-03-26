'use strict';

const fs = require('fs');
const puppeteer = require('/var/www/wa_web_bridge/node_modules/puppeteer');

const cookiesFile = process.argv[2] || '';
const profileDir = process.argv[3] || '/var/www/fb_bot_browser_profile';
const statusFile = process.argv[4] || '/tmp/palweb_fb_browser_login_status.json';

function writeStatus(status, message, extra = {}) {
  try {
    fs.writeFileSync(statusFile, JSON.stringify({
      status,
      message,
      updated_at: new Date().toISOString(),
      ...extra
    }, null, 2));
  } catch (_) {}
}

function saveCookies(cookies) {
  fs.writeFileSync(cookiesFile, JSON.stringify({
    cookies,
    updated_at: new Date().toISOString()
  }, null, 2));
}

async function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function main() {
  writeStatus('running', 'Abriendo navegador para login de Facebook');
  fs.mkdirSync(profileDir, { recursive: true });

  let browser;
  try {
    browser = await puppeteer.launch({
      headless: false,
      userDataDir: profileDir,
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
  } catch (err) {
    writeStatus('error', 'No se pudo abrir navegador visual', { error: err.message });
    process.exit(1);
  }

  try {
    const page = await browser.newPage();
    await page.goto('https://www.facebook.com/groups/feed/', {
      waitUntil: 'domcontentloaded',
      timeout: 90000
    }).catch(() => {});

    writeStatus('running', 'Inicia sesión en la ventana del navegador de Facebook');

    const deadline = Date.now() + (10 * 60 * 1000);
    while (Date.now() < deadline) {
      let cookies = [];
      try {
        cookies = await page.cookies('https://www.facebook.com');
      } catch (_) {}

      const hasSession = cookies.some((c) => String(c.name) === 'c_user' && String(c.value || '').trim() !== '');
      const currentUrl = page.url();
      if (hasSession && !/login|checkpoint/i.test(currentUrl)) {
        saveCookies(cookies);
        writeStatus('success', 'Sesión de Facebook conectada y cookies guardadas', {
          current_url: currentUrl,
          cookies_count: cookies.length
        });
        await browser.close();
        process.exit(0);
      }

      writeStatus('running', 'Esperando a que completes el login en Facebook', {
        current_url: currentUrl,
        cookies_count: cookies.length
      });
      await sleep(2000);
    }

    writeStatus('error', 'Tiempo agotado esperando login de Facebook');
    await browser.close();
    process.exit(1);
  } catch (err) {
    writeStatus('error', 'Fallo durante el login automatizado de Facebook', { error: err.message });
    try { await browser.close(); } catch (_) {}
    process.exit(1);
  }
}

main();
