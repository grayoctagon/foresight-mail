import {api} from './api.js';
import {$, esc, fmtSize, duration, toast} from './ui.js';
import {state} from './state.js';
import {loadFolders, loadMessages} from './mail.js';

let pollTimer=null, pollGeneration=0, pollController=null;
let statusTimer=null, statusController=null, statusPolling=false;
const nextChecks=new Map(), automaticResults=new Map();
const inFlightChecks=new Set();
export function stopAutoSync() {
    ++pollGeneration;clearTimeout(pollTimer);pollTimer=null;pollController?.abort();pollController=null;nextChecks.clear();automaticResults.clear();
    clearTimeout(statusTimer);statusTimer=null;statusController?.abort();statusController=null;statusPolling=false;inFlightChecks.clear();
}
export function startAutoSync() {
    stopAutoSync();
    const generation=pollGeneration;
    pollTimer=setTimeout(()=>checkAutomaticSync(generation),1000);
}
function statusAccounts() {
    return state.accounts.filter(a=>{const r=automaticResults.get(a.id);return inFlightChecks.has(a.id) || (['running','busy'].includes(r?.reason) && (!r.job || ['queued','running'].includes(r.job.state)));});
}
function scheduleStatusPoll(generation) {
    if(generation!==pollGeneration || statusTimer!==null || statusPolling || !statusAccounts().length)return;
    statusTimer=setTimeout(()=>pollSyncStatus(generation),5000);
}
function renderAutomaticProgress() {
    if(state.syncing)return;
    const active=state.accounts.find(a=>{const r=automaticResults.get(a.id);return r && (['running','busy','error'].includes(r.reason) || (!['manual','disabled','manual_job'].includes(r.reason) && r.job?.trigger==='automatic' && r.job?.last_error));});
    if(active) {
        const result=automaticResults.get(active.id);
        showProgress(active,result.job || {state:'running',phase_label:'Postfach wird bereits synchronisiert.'});
        $('#syncBanner').insertAdjacentHTML('beforeend',result.retryAfterSeconds===60
            ? '<span> · Status alle 5 Sekunden · nächster Arbeitsschritt frühestens nach 1 Minute.</span>'
            : '<span> · Nächste Prüfung in 5 Minuten.</span>');
        $('#syncBanner').dataset.automatic='true';
    } else if($('#syncBanner').dataset.automatic==='true'){$('#syncBanner').hidden=true;delete $('#syncBanner').dataset.automatic;}
}
async function refreshAutomaticMail(generation,session) {
    if(generation!==pollGeneration || state.session!==session || state.syncing)return;
    await loadFolders();
    // Do not discard a message that the user is currently reading.
    if(generation===pollGeneration && state.session===session && !state.syncing && !state.settingsSection && !state.selected)await loadMessages();
}
async function pollSyncStatus(generation) {
    if(generation!==pollGeneration || !state.session?.authenticated)return;
    statusTimer=null;statusPolling=true;
    const session=state.session;
    statusController=new AbortController();
    let changed=false;
    try {
        if(!state.syncing)for(const account of statusAccounts()) {
            if(generation!==pollGeneration || state.session!==session || state.syncing)return;
            const observed=automaticResults.get(account.id);
            try {
                // Read-only: this endpoint never starts or advances a job and never uses IMAP.
                const {job}=await api(`/accounts/${account.id}/sync/status`,{signal:statusController.signal});
                if(generation!==pollGeneration || state.session!==session)return;
                if(state.syncing || automaticResults.get(account.id)!==observed || !job)continue;
                const previous=state.jobs.get(account.id);
                state.jobs.set(account.id,job);
                const running=['queued','running'].includes(job.state);
                automaticResults.set(account.id,{...observed,job,reason:running?(job.trigger==='automatic'?'running':'manual_job'):job.state,retryAfterSeconds:running?60:300});
                if(job.state==='completed' && (previous?.job_id!==job.job_id || previous?.state!=='completed'))changed=true;
            } catch(error) {
                if(generation!==pollGeneration || state.session!==session)return;
                if(error.name==='AbortError')return;
                // Keep the work deadline independent, but back off failed status reads.
                statusTimer=setTimeout(()=>pollSyncStatus(generation),60000);
                toast('Sync-Status konnte nicht geladen werden. Erneuter Statusabruf in 1 Minute.','warning');
                break;
            }
        }
        renderJobs();renderAutomaticProgress();
        if(changed)await refreshAutomaticMail(generation,session);
    } catch(error) {
        if(generation===pollGeneration && state.session===session)toast(error.message,'danger');
    } finally {
        if(generation===pollGeneration && state.session===session) {
            statusPolling=false;statusController=null;scheduleStatusPoll(generation);
        }
    }
}
async function checkAutomaticSync(generation) {
    if(generation!==pollGeneration || !state.session?.authenticated)return;
    const session=state.session;
    let changed=false;
    pollController=new AbortController();
    try {
        if(!state.syncing)for(const account of [...state.accounts]) {
            if(generation!==pollGeneration || state.session!==session || state.syncing)return;
            if((nextChecks.get(account.id)||0)>Date.now())continue;
            try {
                inFlightChecks.add(account.id);scheduleStatusPoll(generation);
                const result=await api(`/accounts/${account.id}/sync/check`,{method:'POST',signal:pollController.signal});
                if(generation!==pollGeneration || state.session!==session)return;
                const previous=state.jobs.get(account.id);
                state.jobs.set(account.id,result.job);
                automaticResults.set(account.id,result);
                nextChecks.set(account.id,Date.now()+(result.retryAfterSeconds===60?60000:300000));
                if(state.syncing)continue;
                if(result.job?.state==='completed' && (previous?.job_id!==result.job.job_id || previous?.state!=='completed'))changed=true;
            } catch(error) {
                if(generation!==pollGeneration || state.session!==session)return;
                nextChecks.set(account.id,Date.now()+300000);
                if(error.name==='AbortError')return;
                const job={state:'error',last_error:{message:error.message}};
                state.jobs.set(account.id,job);
                automaticResults.set(account.id,{job,reason:'error',retryAfterSeconds:300});
            } finally {
                if(generation===pollGeneration)inFlightChecks.delete(account.id);
            }
        }
        renderJobs();
        renderAutomaticProgress();
        if(changed)await refreshAutomaticMail(generation,session);
    } catch(error) {
        if(generation===pollGeneration && state.session===session)toast(error.message,'danger');
    } finally {
        if(generation===pollGeneration && state.session===session) {
            pollController=null;
            scheduleStatusPoll(generation);
            const next=state.syncing?Date.now()+60000:Math.min(Date.now()+300000,...state.accounts.map(a=>nextChecks.get(a.id)||Date.now()+300000));
            pollTimer=setTimeout(()=>checkAutomaticSync(generation),Math.max(1000,next-Date.now()));
        }
    }
}
window.addEventListener('sync-settings-changed',()=>{if(state.session?.authenticated)startAutoSync();});
window.addEventListener('online',()=>{if(state.session?.authenticated)startAutoSync();});

export async function refreshJobs() {
    const session=state.session;
    for(const a of state.accounts) {
        try { const result=await api(`/accounts/${a.id}/sync/status`);if(state.session!==session)return;state.jobs.set(a.id,result.job); }
        catch(error) { if(state.session!==session)return;state.jobs.set(a.id,{state:'error',last_error:{message:error.message}}); }
    }
    renderJobs();
}
export function renderJobs() {
    if(state.settingsSection!=='sync')return;
    $('#settingsMain').innerHTML=`<h1>Synchronisation</h1><p class="settings-lead">Lädt Nachrichten vom IMAP-Server in die lokale Mail-Kopie. Jeder Schritt wird gespeichert; du kannst später fortsetzen.</p><div class="settings-actions"><button class="btn accent" id="syncAll" ${state.syncing?'disabled':''}>Alle aktiven Postfächer synchronisieren</button><button class="btn" id="syncPause" ${!state.syncing?'disabled':''}>Nach diesem Schritt pausieren</button><button class="btn" id="syncStatus">Status laden</button></div>${state.accounts.map(a=>{
        const j=state.jobs.get(a.id),missing=!a.credential_status?.imap_configured;
        return `<section class="sync-account"><div class="title"><strong>${esc(a.label)}</strong><button class="btn small" data-sync-account="${a.id}" ${state.syncing||!a.enabled||missing?'disabled':''}>${j&&['running','queued'].includes(j.state)?'Fortsetzen':'Synchronisieren'}</button></div><p>${esc(j?.phase_label||'Noch keine Synchronisation')}${j?' · '+esc(j.state):''}</p><progress class="sync-progress" max="100" value="${Number(j?.progress_percent||0)}"></progress><div class="stats"><span>${j?.current||0} / ${j?.total||0}</span><span>Vergangen: ${duration(j?.elapsed_seconds||0)}</span><span>Restzeit: ${duration(j?.eta_seconds)}</span><span>${fmtSize(j?.processed_bytes)}</span><span>${j?.pending_content||0} ausstehend</span></div>${missing?'<div class="form-message">Bitte das IMAP-Passwort in den Postfacheinstellungen speichern.</div>':j?.last_error?`<div class="form-message">${esc(j.last_error.message)}</div>`:''}${!a.enabled?'<div class="settings-note">Dieses Postfach ist deaktiviert.</div>':''}</section>`;
    }).join('')||'<p>Noch kein Postfach eingerichtet.</p>'}`;
    $('#syncAll').onclick=()=>runSync();$('#syncPause').onclick=()=>{state.stopSync=true;toast('Der aktuelle Schritt wird noch abgeschlossen.');};$('#syncStatus').onclick=refreshJobs;
    document.querySelectorAll('[data-sync-account]').forEach(el=>el.onclick=()=>runSync(el.dataset.syncAccount));
}
function showProgress(a,job) {
    const banner=$('#syncBanner');banner.hidden=false;banner.classList.toggle('error',!!job.last_error);
    banner.innerHTML=`<strong>${esc(a.label)}</strong> · ${esc(job.last_error?.message||job.phase_label)} <span>${job.current||0}/${job.total||0}</span><progress class="sync-progress" max="100" value="${Number(job.progress_percent||0)}"></progress>`;
    state.jobs.set(a.id,job);renderJobs();
}
export async function runSync(accountId=null) {
    if(state.syncing)return;
    // The account file lock also protects overlap with a poll already in flight.
    delete $('#syncBanner').dataset.automatic;
    const session=state.session;
    const accounts=state.accounts.filter(a=>a.enabled&&(!accountId||a.id===accountId));
    if(!accounts.length){toast('Kein aktives Postfach eingerichtet.','warning');return;}
    state.syncing=true;state.stopSync=false;renderJobs();$('#refreshBtn').disabled=true;
    let failed=false;
    try {
        for(const a of accounts) {
            if(state.stopSync)break;
            if(!a.credential_status?.imap_configured){failed=true;showProgress(a,{state:'failed',last_error:{message:'IMAP-Passwort fehlt. Bitte unter Einstellungen speichern.'}});continue;}
            let job;
            do {
                if(state.session!==session)return;
                job=(await api(`/accounts/${a.id}/sync/start`,{method:'POST'})).job;
                if(state.session!==session)return;
                if(job.busy)await new Promise(resolve=>setTimeout(resolve,1000));
            } while(job.busy && !state.stopSync);
            if(state.session!==session)return;
            showProgress(a,job);
            while(!['completed','failed'].includes(job.state)&&!state.stopSync) {
                if(state.session!==session)return;
                job=(await api(`/accounts/${a.id}/sync/advance`,{method:'POST'})).job;if(state.session!==session)return;showProgress(a,job);
                if(job.last_error){failed=true;break;}
                if(job.busy)await new Promise(resolve=>setTimeout(resolve,1000));
            }
            if(job.state==='failed')failed=true;
        }
    } catch(error) {
        if(state.session!==session)return;
        failed=true;$('#syncBanner').hidden=false;$('#syncBanner').textContent=error.message;$('#syncBanner').classList.add('error');
    } finally {
        if(state.session===session) {
            state.syncing=false;$('#refreshBtn').disabled=false;renderJobs();
            if(state.session?.authenticated) {
                try {await loadFolders();if(state.session===session && !state.settingsSection)await loadMessages();}catch(error){if(state.session===session)toast(error.message,'danger');}
            }
        }
        if(!failed&&state.session===session){$('#syncBanner').hidden=true;toast(state.stopSync?'Synchronisation pausiert.':'Synchronisation abgeschlossen.','success');}
    }
}
