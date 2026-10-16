const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require.resolve('../menu_master.php'), 'utf8');
const scriptMatch = source.match(/<script>\s*(\(function \(\) \{[\s\S]*?\}\)\(\);)\s*<\/script>/);
assert(scriptMatch, 'No se encontró el controlador de progreso de reportes');
const handlers = {};
const timers = [];
const appended = [];
const locationChanges = [];
const document = {
    addEventListener: (name, fn) => { handlers[name] = fn; },
    createElement: () => ({ attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } }),
    body: { appendChild: el => appended.push(el) }
};
const context = {
    document,
    URL,
    HTMLFormElement: class HTMLFormElement {},
    window: {
        location: { href: 'https://www.palweb.net/dashboard.php', origin: 'https://www.palweb.net', pathname: '/dashboard.php', assign: url => locationChanges.push(url) },
        setTimeout: fn => timers.push(fn),
        setInterval: () => 1,
        clearInterval: () => {},
        addEventListener: () => {}
    }
};
vm.createContext(context);
new vm.Script(scriptMatch[1]).runInContext(context);

let prevented = false;
const link = { href: 'https://www.palweb.net/profit.php?view=global', target: '' };
handlers.click({
    defaultPrevented: false, button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false,
    target: { closest: selector => selector === 'a[href]' ? link : null },
    preventDefault: () => { prevented = true; }
});
assert(prevented, 'La navegación a profit.php no se intercepta');
assert.equal(appended.length, 1, 'No se mostró el panel de cálculo');
assert(appended[0].innerHTML.includes('Tiempo transcurrido'));
assert(appended[0].innerHTML.includes('no es un porcentaje exacto'));
timers[0]();
assert.deepEqual(locationChanges, [link.href]);
console.log('Indicador de cálculo mostrado antes de navegar al reporte.');
