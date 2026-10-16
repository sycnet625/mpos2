// Adjuntar CSRF únicamente a peticiones del mismo origen que modifican datos.
(() => {
    const token = document.querySelector('meta[name="pos-csrf-token"]')?.content;
    if (!token) return;
    const originalFetch = window.fetch.bind(window);
    window.fetch = (resource, options = {}) => {
        const url = new URL(resource instanceof Request ? resource.url : resource, location.href);
        const method = String(options.method || (resource instanceof Request ? resource.method : 'GET')).toUpperCase();
        if (url.origin === location.origin && !['GET', 'HEAD'].includes(method)) {
            const headers = new Headers(options.headers || (resource instanceof Request ? resource.headers : undefined));
            headers.set('X-CSRF-Token', token);
            options = { ...options, headers };
        }
        return originalFetch(resource, options);
    };
})();
