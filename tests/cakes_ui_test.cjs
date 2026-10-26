// Prueba de interfaz: las respuestas del pedido se simulan; no crea reservas.
const assert = require('node:assert/strict');
const puppeteer = require('../wa_web_bridge/node_modules/puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: true,
        acceptInsecureCerts: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--host-resolver-rules=MAP www.palweb.net 127.0.0.1'],
    });
    try {
        const page = await browser.newPage();
        await page.setViewport({width: 390, height: 844, deviceScaleFactor: 1});
        await page.setRequestInterception(true);
        const orders = [];
        page.on('request', request => {
            const url = new URL(request.url());
            if (url.pathname.endsWith('/cakes_builder_api.php')) {
                if (request.method() === 'POST') {
                    const body = JSON.parse(request.postData());
                    orders.push(body);
                    return request.respond({status: 200, contentType: 'application/json', body: JSON.stringify({status:'success',id:12345,count:2,tracking_token:body.request_id})});
                }
                return request.respond({status:200,contentType:'application/json',body:'{"status":"success","count":0}'});
            }
            if (url.hostname !== 'www.palweb.net') return request.abort();
            return request.continue();
        });
        await page.goto('https://www.palweb.net/cakes.php', {waitUntil:'domcontentloaded'});
        const miniWidth = await page.$eval('#cakePreview svg rect', el => Number(el.getAttribute('width')));
        await page.click('[data-meringue-color="Azul"]');
        assert.match(await page.$eval('#cakePreview', el => el.innerHTML), /#72b7e8/i);
        await page.click('#addCake');
        await page.click('input[name="model"][value="cake22"] + img');
        const cakeWidth = await page.$eval('#cakePreview svg rect', el => Number(el.getAttribute('width')));
        assert(miniWidth < cakeWidth, 'El minicake debe ser más estrecho que el de 22 cm');
        await page.click('#addCake');
        assert.match(await page.$eval('#cart', el => el.textContent), /Cakes añadidos \(2\)/);
        await page.type('#customerName', 'Prueba de interfaz');
        await page.type('#customerPhone', '000000');
        await page.click('#submitOrder');
        await page.waitForSelector('#successOverlay.is-visible');
        assert.equal(orders.length,1);
        assert.equal(orders[0].items.length,2);
        assert.equal(orders[0].items[0].meringue_color,'Azul');
        assert.equal(orders[0].total,5500);
        assert.match(await page.$eval('#successReservationNumber', el => el.textContent), /12345/);
        assert.match(await page.$eval('#successTrackingLink', el => el.getAttribute('href')), /^cakes_estado\.php\?token=/);
        const popup = await page.$eval('.success-card', el => {const r=el.getBoundingClientRect(); return {left:r.left,right:r.right,top:r.top,bottom:r.bottom};});
        assert(popup.left>=0 && popup.right<=390 && popup.top>=0 && popup.bottom<=844, 'El aviso debe caber en la pantalla');
        await page.click('.restart-btn');
        assert.equal(await page.$eval('#cart', el => el.textContent.trim()),'');
        assert.equal(await page.$eval('#submitOrder', el => el.disabled),false);
        console.log('Interfaz móvil: colores, carrito, confirmación y reinicio OK; sin reservas reales.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode=1; });
