// Verifica migración offline y CSRF sin navegador ni servicios externos.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const names = new Set(['offline_sales', 'cajeros']);
const deleted = [];
const records = new Map();
const local = new Map();
const db = {
    objectStoreNames: { contains: n => names.has(n) },
    deleteObjectStore(n) { deleted.push(n); names.delete(n); },
    createObjectStore(n) { names.add(n); return { createIndex() {} }; },
    close() {},
    transaction() {
        const tx = { objectStore: () => ({
            clear() { records.clear(); },
            put(r) { records.set(r.nombre, r); },
            getAll() { const req = {}; queueMicrotask(() => { req.result = [...records.values()]; req.onsuccess(); }); return req; }
        }) };
        queueMicrotask(() => tx.oncomplete?.());
        return tx;
    }
};
const context = {
    window: { POS_ACTIVE_CONTEXT: { id_empresa: 1, id_sucursal: 2 }, addEventListener() {} },
    document: { addEventListener() {} }, navigator: { onLine: true }, console,
    TextEncoder, Uint8Array, crypto: webcrypto,
    localStorage: { getItem: k => local.get(k) || null, setItem: (k, v) => local.set(k, v) },
    indexedDB: {
        databases: async () => [],
        open() {
            const req = {};
            queueMicrotask(() => { req.result = db; req.onupgradeneeded({ target: { result: db } }); req.onsuccess(); });
            return req;
        }
    }
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(require.resolve('../pos-offline-system.js'), 'utf8'), context);
(async () => {
    const cache = context.window.posCache;
    await cache.init();
    assert.deepEqual(deleted, ['cajeros'], 'La migración no debe eliminar ventas pendientes');
    assert(names.has('offline_sales'));
    await cache.rememberAuthenticatedCashier({ cajero: 'Prueba', rol: 'cajero', id_empresa: 1, id_sucursal: 2, id_almacen: 3 }, '583917');
    const record = [...records.values()][0];
    assert(!('pin' in record));
    assert(!JSON.stringify(record).includes('583917'));
    assert.equal((await cache.verifyCajeroOffline('583917')).nombre, 'Prueba');
    assert.equal(await cache.verifyCajeroOffline('incorrecto'), null);
    record.expiresAt = Date.now() - 1;
    assert.equal(await cache.verifyCajeroOffline('583917'), null);
    const calls = [];
    const csrfContext = {
        URL, Headers, Request,
        location: { href: 'https://www.palweb.net/pos_admin.php', origin: 'https://www.palweb.net' },
        document: { querySelector: () => ({ content: 'token-prueba' }) },
        window: { fetch: async (r, o) => calls.push([r, o]) }
    };
    vm.createContext(csrfContext);
    vm.runInContext(fs.readFileSync(require.resolve('../assets/js/admin-csrf.js'), 'utf8'), csrfContext);
    await csrfContext.window.fetch('pos_admin.php', { method: 'POST' });
    await csrfContext.window.fetch('https://otro.example/api', { method: 'POST' });
    await csrfContext.window.fetch('pos_admin.php');
    assert.equal(calls[0][1].headers.get('X-CSRF-Token'), 'token-prueba');
    assert.equal(calls[1][1].headers, undefined);
    assert.equal(calls[2][1].headers, undefined);
    console.log('10 comprobaciones offline/CSRF correctas.');
})().catch(e => { console.error(e); process.exitCode = 1; });
