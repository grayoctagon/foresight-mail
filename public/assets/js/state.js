import {api} from './api.js';
import {toast} from './ui.js';
export const state = {
    session:null, accounts:[], folders:[], preferences:null, scopes:[], filters:{}, sort:'date', direction:'desc',
    messages:[], selected:null, checked:new Set(), collapsed:new Set(), total:0, cursor:null,
    loading:false, pageError:null, indexProgress:null, settingsSection:null, jobs:new Map(), syncing:false, stopSync:false,
};
let saving = Promise.resolve();
let previewSaveSession = null;
export async function savePreviewPreferences(mode, rules) {
    const session = state.session, preferences = state.preferences;
    if (!session?.authenticated || !preferences) return;
    if (previewSaveSession === session) throw new Error('Die Vorschau-Einstellungen werden gerade gespeichert.');
    previewSaveSession = session;
    const previous = {previewMode:preferences.previewMode || 'text', previewRules:structuredClone(preferences.previewRules || [])};
    preferences.previewMode = mode;
    preferences.previewRules = structuredClone(rules);
    try {
        const result = await savePreferences();
        if (state.session !== session || state.preferences !== preferences) return;
        if (result) { preferences.previewMode = result.previewMode; preferences.previewRules = result.previewRules; }
        window.dispatchEvent(new Event('preview-preferences-changed'));
    } catch (error) {
        if (state.session === session && state.preferences === preferences) Object.assign(preferences, previous);
        throw error;
    } finally { if (previewSaveSession === session) previewSaveSession = null; }
}
export function savePreferences() {
    const body = structuredClone(state.preferences);
    const session = state.session;
    saving = saving.catch(() => {}).then(() => state.session === session && session?.authenticated ? api('/webmail/settings', {method:'PUT', body}) : undefined);
    saving.catch(error => { if(state.session === session) toast(error.message, 'danger'); });
    return saving;
}
export const folderKey = f => `${f.accountId}~${f.id}`;
export const messageKey = m => `${m.accountId}/${m.id}`;
export const messagePath = m => `/webmail/accounts/${encodeURIComponent(m.accountId)}/messages/${encodeURIComponent(m.id)}`;
