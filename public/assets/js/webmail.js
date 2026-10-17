import {api,setCsrf} from './api.js';
import {$,$$,esc,toast} from './ui.js';
import {state,savePreferences} from './state.js';
import {setupMail,loadFolders,loadMessages,clearMail,renderTable,toggleColumnsMenu} from './mail.js';
import {setupSettings,openSettings,clearSettings} from './settings.js';
import {runSync, startAutoSync, stopAutoSync} from './sync.js';

let register=false;
function loggedOut(message='') {
    stopAutoSync();
    state.syncing=false;$('#refreshBtn').disabled=false;
    clearSettings();
    state.stopSync=true;state.session=null;setCsrf(null);clearMail();state.accounts=[];state.folders=[];state.scopes=[];state.filters={};state.preferences=null;state.settingsSection=null;state.jobs.clear();
    $('#app').hidden=true;$('#authView').hidden=false;$('#settingsMain').innerHTML='';$('#mailboxes').innerHTML='';$('#favorites').innerHTML='';$('#syncBanner').hidden=true;
    $$('dialog[open]').forEach(d=>d.close());renderAuth(message);
}
function renderAuth(message='') {
    $('#authView').innerHTML=`<section class="auth-card"><div class="brand-mark">f</div><h1>foresight</h1><p>${register?'Lege deinen foresight-Benutzer an.':'Melde dich bei foresight an.'}</p><form id="authForm"><div id="authError" class="form-message" ${!message?'hidden':''} role="alert">${esc(message)}</div><div class="form-row"><label for="username">Benutzername</label><input id="username" name="username" type="text" autocomplete="username" required minlength="3" maxlength="64"></div><div class="form-row"><label for="password">Passwort</label><input id="password" name="password" type="password" autocomplete="${register?'new-password':'current-password'}" required minlength="10"></div><button class="btn accent" id="authSubmit">${register?'Benutzer anlegen':'Anmelden'}</button></form>${allowRegistration?`<p class="auth-switch">${register?'Schon registriert?':'Noch kein Benutzer?'} <button class="btn small" id="authSwitch">${register?'Anmelden':'Registrieren'}</button></p>`:''}</section>`;
    $('#authSwitch')?.addEventListener('click',()=>{register=!register;renderAuth();});
    $('#authForm').onsubmit=async e=>{
        e.preventDefault();$('#authSubmit').disabled=true;$('#authError').hidden=true;
        const values=Object.fromEntries(new FormData(e.target));
        try {await api(register?'/register':'/login',{method:'POST',body:values});$('#password').value='';await start();}
        catch(error){$('#authError').textContent=error.message;$('#authError').hidden=false;$('#authSubmit').disabled=false;}
        finally{delete values.password;}
    };
}
let allowRegistration=false;
async function start() {
    try {
        const session=await api('/session');allowRegistration=session.registration_allowed;
        if(!session.authenticated){loggedOut();return;}
        state.session=session;setCsrf(session.csrf_token);
        state.preferences=await api('/webmail/settings');
        $('#authView').hidden=true;$('#app').hidden=false;$('#settingsPage').classList.remove('show');$('#workspace').hidden=false;$('#sessionUser').textContent=session.user.username;
        await loadFolders();applyLayout();await loadMessages();startAutoSync();
    } catch(error) {
        if(state.session?.authenticated){$('#authView').hidden=false;$('#app').hidden=true;$('#authView').innerHTML=`<section class="auth-card"><h1>foresight konnte nicht laden</h1><div class="form-message">${esc(error.message)}</div><button class="btn accent" id="retryStart">Erneut versuchen</button><button class="btn" id="retryLogout">Abmelden</button></section>`;$('#retryStart').onclick=start;$('#retryLogout').onclick=logout;}
        else renderAuth(error.message);
    }
}
async function logout() {
    state.stopSync=true;
    try{await api('/logout',{method:'POST'});loggedOut();}
    catch(error){toast(error.message,'danger');}
}
function applyLayout() {
    if(!state.preferences)return;
    const prefs=state.preferences, available=Math.max(0,$('#workspace').clientWidth-16);
    document.documentElement.style.setProperty('--sidebar-w',(available*prefs.sidebarPercent/100)+'px');
    document.documentElement.style.setProperty('--list-w',(available*prefs.listPercent/100)+'px');
    $('#toggleSidebar').textContent=prefs.sidebarPercent<=0?'▶':'◀';$('#toggleListPane').textContent=prefs.listPercent<=0?'▶':'◀';
}
function setupLayout() {
    const previous={sidebarPercent:20,listPercent:20};
    for(const [id,key,other,toggle] of [['splitSidebar','sidebarPercent','listPercent','toggleSidebar'],['splitPreview','listPercent','sidebarPercent','toggleListPane']]) {
        const el=$('#'+id);
        const resize=value=>{state.preferences[key]=Math.max(0,Math.min(key==='sidebarPercent'?70:80,95-state.preferences[other],value));applyLayout();};
        el.onpointerdown=e=>{
            if(e.target.closest('button')||!state.preferences)return;e.preventDefault();
            const start=e.clientX,value=state.preferences[key],available=Math.max(1,$('#workspace').clientWidth-16);
            el.setPointerCapture(e.pointerId);el.classList.add('dragging');
            const move=event=>resize(value+(event.clientX-start)/available*100);
            const up=()=>{el.classList.remove('dragging');el.removeEventListener('pointermove',move);el.removeEventListener('pointerup',up);savePreferences();};
            el.addEventListener('pointermove',move);el.addEventListener('pointerup',up);
        };
        el.onkeydown=e=>{if(e.target!==el||!['ArrowLeft','ArrowRight'].includes(e.key))return;e.preventDefault();resize(state.preferences[key]+(e.key==='ArrowLeft'?-2:2));savePreferences();};
        $('#'+toggle).onclick=()=>{if(state.preferences[key]>0){previous[key]=state.preferences[key];resize(0);}else resize(previous[key]);savePreferences();};
    }
    window.addEventListener('resize',applyLayout);
}
setupMail();setupSettings();setupLayout();
document.addEventListener('click',e=>{
    const close=e.target.closest('[data-close]');if(close)$('#'+close.dataset.close).close();
    if(!e.target.closest('.menu-wrap')){$$('.menu.show').forEach(el=>el.classList.remove('show'));$('#previewRulesBtn')?.setAttribute('aria-expanded','false');}
});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){const previewOpen=$('#previewRulesMenu')?.classList.contains('show');$$('.menu.show').forEach(el=>el.classList.remove('show'));$('#previewRulesBtn')?.setAttribute('aria-expanded','false');if(previewOpen)$('#previewRulesBtn')?.focus();}});
$('#appMenuBtn').onclick=()=>$('#appMenu').classList.toggle('show');
$('#appMenu').onclick=e=>{
    const action=e.target.closest('[data-action]')?.dataset.action;if(!action)return;$('#appMenu').classList.remove('show');
    if(action==='logout')logout();
    if(action==='sync')openSettings('sync');
    if(action==='columns')toggleColumnsMenu(true);
    if(action==='density'){state.preferences.density=state.preferences.density==='compact'?'comfortable':'compact';savePreferences();renderTable();}
};
$('#refreshBtn').onclick=()=>runSync();
window.addEventListener('session-expired',()=>loggedOut('Deine Sitzung ist abgelaufen. Bitte erneut anmelden.'));
start();
