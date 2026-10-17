export const $ = selector => document.querySelector(selector);
export const $$ = selector => [...document.querySelectorAll(selector)];
export const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
export const fmtDate = value => {
    const date = new Date(value);
    return value && !isNaN(date) ? new Intl.DateTimeFormat('de-AT',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(date) : '–';
};
export const fmtSize = bytes => {
    let value = Number(bytes ?? 0);
    if (!Number.isFinite(value) || value < 0) return '–';
    const units = ['B','KB','MB','GB','TB','PB'];
    let unit = 0;
    while (value >= 1024 && unit < units.length-1) { value /= 1024; ++unit; }
    return `${Number(value.toFixed(2))}${units[unit]}`;
};
export const duration = seconds => seconds == null ? '–' : seconds > 3600 ? `${Math.floor(seconds/3600)} h ${Math.floor(seconds%3600/60)} min` : seconds > 60 ? `${Math.floor(seconds/60)} min ${seconds%60} s` : `${seconds} s`;
export const address = a => a ? (a.name ? `${a.name} <${a.email}>` : a.email) : '';
export function toast(text, type = '') {
    const el = document.createElement('div'); el.className = `toast ${type}`; el.textContent = text;
    $('#toasts').append(el); setTimeout(() => el.remove(), 6500);
}
export const loading = text => `<span class="loading-spinner"></span>${esc(text)}`;
export function confirmAction(title, text) {
    $('#confirmTitle').textContent = title; $('#confirmText').textContent = text; $('#confirmDialog').showModal();
    return new Promise(resolve => {
        const done = value => { $('#confirmDialog').close(); $('#confirmAccept').onclick = null; $('#confirmCancel').onclick = null; resolve(value); };
        $('#confirmAccept').onclick = () => done(true); $('#confirmCancel').onclick = () => done(false);
        $('#confirmDialog').oncancel = () => done(false);
    });
}
