export class HcmsError extends Error {
    status;
    code;
    constructor(message, status, code) {
        super(message);
        this.status = status;
        this.code = code;
        this.name = 'HcmsError';
    }
}
export function createClient(options) {
    const baseUrl = options.baseUrl.replace(/\/$/, '');
    const doFetch = options.fetch ?? globalThis.fetch.bind(globalThis);
    async function request(path, init = {}) {
        const headers = new Headers(init.headers);
        if (!headers.has('Content-Type') && init.body) {
            headers.set('Content-Type', 'application/json');
        }
        if (options.token) {
            headers.set('Authorization', `Bearer ${options.token}`);
        }
        const res = await doFetch(`${baseUrl}${path}`, { ...init, headers });
        if (res.status === 204) {
            return undefined;
        }
        const json = (await res.json());
        if (!res.ok) {
            throw new HcmsError(json.error?.message ?? res.statusText, res.status, json.error?.code);
        }
        if (json.meta && Array.isArray(json.data)) {
            return { data: json.data, meta: json.meta };
        }
        return (json.data ?? json);
    }
    function qs(params) {
        if (!params)
            return '';
        const sp = new URLSearchParams();
        for (const [k, v] of Object.entries(params)) {
            if (v === undefined || v === '')
                continue;
            sp.set(k, String(v));
        }
        const s = sp.toString();
        return s ? `?${s}` : '';
    }
    return {
        list: (slug, params) => request(`/api/${slug}${qs(params)}`),
        get: (slug, id) => request(`/api/${slug}/${id}`),
        create: (slug, body) => request(`/api/${slug}`, { method: 'POST', body: JSON.stringify(body) }),
        update: (slug, id, body) => request(`/api/${slug}/${id}`, { method: 'PATCH', body: JSON.stringify(body) }),
        remove: (slug, id) => request(`/api/${slug}/${id}`, { method: 'DELETE' }),
        preview: (token) => request(`/api/preview/${encodeURIComponent(token)}`),
        openapi: () => request('/api/openapi.json'),
    };
}
