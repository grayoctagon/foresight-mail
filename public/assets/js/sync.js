import {api} from './api.js';
import {$, esc, fmtSize, duration, toast} from './ui.js';
import {state} from './state.js';
import {loadFolders, loadMessages} from './mail.js';

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
    const session=state.session;
    const accounts=state.accounts.filter(a=>a.enabled&&(!accountId||a.id===accountId));
    if(!accounts.length){toast('Kein aktives Postfach eingerichtet.','warning');return;}
    state.syncing=true;state.stopSync=false;renderJobs();$('#refreshBtn').disabled=true;
    let failed=false;
    try {
        for(const a of accounts) {
            if(state.stopSync)break;
            if(!a.credential_status?.imap_configured){failed=true;showProgress(a,{state:'failed',last_error:{message:'IMAP-Passwort fehlt. Bitte unter Einstellungen speichern.'}});continue;}
            let job=(await api(`/accounts/${a.id}/sync/start`,{method:'POST'})).job;
            if(state.session!==session)return;
            showProgress(a,job);
            while(!['completed','failed'].includes(job.state)&&!state.stopSync) {
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
        state.syncing=false;$('#refreshBtn').disabled=false;renderJobs();
        if(state.session===session && state.session?.authenticated) {
            try {await loadFolders();if(!state.settingsSection)await loadMessages();}catch(error){toast(error.message,'danger');}
        }
        if(!failed&&state.session===session){$('#syncBanner').hidden=true;toast(state.stopSync?'Synchronisation pausiert.':'Synchronisation abgeschlossen.','success');}
    }
}
