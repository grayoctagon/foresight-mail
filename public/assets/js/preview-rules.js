// Display preferences only: From is untrusted and never authorizes external resources.
export const previewWarning = 'Die From-Absenderadresse einer Mail kann gefälscht sein. Verwende Absender- und Domainregeln deshalb mit Vorsicht. Sie sind kein Vertrauensnachweis und geben externe Bilder nicht frei.';

export function senderTargets(email) {
    if (typeof email !== 'string') return null;
    const value = email.trim().toLowerCase(), at = value.lastIndexOf('@');
    if (at < 1 || value.slice(0, at).includes('@') || /[\s<>\x00-\x1f\x7f]/.test(value)) return null;
    let domain;
    try {
        const raw = value.slice(at + 1);
        if (/[\/:?#%\\]/.test(raw)) return null;
        domain = new URL('https://' + raw).hostname.toLowerCase();
    } catch { return null; }
    if (!/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(domain)) return null;
    return {address:value.slice(0, at) + '@' + domain, domain};
}

export function previewMode(preferences, message) {
    const sender = senderTargets(message?.from?.email);
    const rules = preferences?.previewRules || [];
    const address = sender && rules.find(r => r.type === 'address' && r.value === sender.address);
    const domain = sender && rules.find(r => r.type === 'domain' && r.value === sender.domain);
    const mode = address?.mode || domain?.mode || preferences?.previewMode || 'text';
    return mode === 'html' && message?.hasHtml ? 'html' : 'text';
}

export function togglePreviewRule(rules, type, value, mode) {
    const existing = rules.find(r => r.type === type && r.value === value);
    const next = rules.filter(r => r.type !== type || r.value !== value);
    if (existing?.mode !== mode) next.push({type, value, mode});
    return next;
}
