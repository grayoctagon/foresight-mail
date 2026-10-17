export const base = document.body.dataset.basePath;
const endpoint = new URL('../../api.php', import.meta.url);
let csrf = null;
let authGeneration = 0;
export class ApiError extends Error {
    constructor(message, code, status) { super(message); this.code = code; this.status = status; }
}
export function setCsrf(value) { csrf = value; ++authGeneration; }
export function url(path) { return endpoint.href + path; }
export async function api(path, {method = 'GET', body, signal} = {}) {
    const generation = authGeneration;
    let response;
    try {
        response = await fetch(url(path), {
            method, signal, credentials:'same-origin', cache:'no-store',
            headers:{Accept:'application/json', ...(body !== undefined ? {'Content-Type':'application/json'} : {}), ...(method !== 'GET' && csrf ? {'X-CSRF-Token':csrf} : {})},
            ...(body !== undefined ? {body:JSON.stringify(body)} : {}),
        });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new ApiError('Die API ist nicht erreichbar. Bitte Verbindung prüfen und erneut versuchen.', 'NETWORK_ERROR', 0);
    }
    const payload = await response.json().catch(() => null);
    if (!payload) throw new ApiError('Der Server hat keine gültige JSON-Antwort geliefert.', 'INVALID_RESPONSE', response.status);
    if (!response.ok || payload.ok === false) {
        if (response.status === 401 && generation === authGeneration && !['/login','/register'].includes(path)) window.dispatchEvent(new Event('session-expired'));
        throw new ApiError(payload.error?.message || 'Anfrage fehlgeschlagen.', payload.error?.code, response.status);
    }
    return payload.data ?? payload;
}
