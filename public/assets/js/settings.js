import {api} from './api.js';
import {$, $$, esc, toast, confirmAction} from './ui.js';
import {state, savePreferences} from './state.js';
import {loadFolders, loadMessages, renderTable} from './mail.js';
import {refreshJobs, renderJobs} from './sync.js';

let accountId = null, draft = null;
export function clearSettings() { accountId = draft = null; }
const serverDefault = smtp=>({hostname:'',port:smtp?587:993,connection_security:smtp?'starttls':'implicit_tls',authentication:{username:'',method:'auto',credential_ref:smtp?'smtp-main':'imap-main'},timeout_seconds:30,validate_certificate:true,use_emailid_optimization:true});
const performanceDefaults={max_request_runtime_seconds:20,max_index_entries_per_request:5000,max_content_messages_per_request:100,max_content_bytes_per_request:104857600,imap_metadata_batch_size:250};
const input=(key,label,value='',type='text',extra='')=>`<div class="form-row"><label for="cfg-${key}">${label}</label><input id="cfg-${key}" name="${key}" type="${type}" value="${esc(value)}" ${extra}></div>`;
const check=(key,label,value)=>`<label class="check-line"><input name="${key}" type="checkbox" ${value?'checked':''}>${label}</label>`;
const select=(key,label,value,options)=>`<div class="form-row"><label for="cfg-${key}">${label}</label><select name="${key}" id="cfg-${key}">${options.map(([v,l])=>`<option value="${v}" ${value===v?'selected':''}>${l}</option>`).join('')}</select></div>`;

export function openSettings(section='mailboxes',id=null) {
    state.settingsSection=section;if(id)accountId=id;else accountId=accountId||state.accounts[0]?.id||null;
    $('#workspace').hidden=true;$('#settingsPage').classList.add('show');renderSettings();
}
export function closeSettings() {
    state.settingsSection=null;draft=null;$('#settingsPage').classList.remove('show');$('#settingsMain').innerHTML='';$('#workspace').hidden=false;
    window.dispatchEvent(new Event('resize'));loadMessages();
}
function navigation() {
    $$('.settings-nav [data-settings-section]').forEach(el=>el.classList.toggle('active',el.dataset.settingsSection===state.settingsSection));
    $('#settingsMailboxNav').innerHTML=state.accounts.map(a=>`<button class="nav-button ${accountId===a.id&&state.settingsSection==='mailboxes'?'active':''}" data-settings-account="${a.id}">${esc(a.label)}</button>`).join('');
}
export function renderSettings() {
    navigation();
    if(state.settingsSection==='sync'){renderJobs();refreshJobs();return;}
    if(state.settingsSection==='performance'){renderPreferences();return;}
    if(state.settingsSection==='capabilities') {
        $('#settingsMain').innerHTML='<h1>Funktionsumfang</h1><p class="settings-lead">Diese Anwendung liest die lokale, synchronisierte Kopie deiner IMAP-Postfächer.</p><div class="settings-card"><h2>Verfügbar</h2><p>Ordner, Nachrichten, HTML- und Plaintext-Vorschau, Anhänge, EML-Export, Suche in Headern und Vorschautext, Favoriten und schrittweiser Sync.</p><h2>Noch nicht unterstützt</h2><p>SMTP-Versand, IMAP-Änderungen an Flags, Verschieben und Löschen, Entwürfe sowie AI-Bewertungen und Meldedienste. Die entsprechenden Controls sind deaktiviert. SMTP und Absenderidentitäten lassen sich bereits konfigurieren.</p><h2>Datenschutz</h2><p>Passwörter verbleiben im geschützten Serverstore. Externe Bilder werden erst nach deiner Freigabe pro Nachricht geladen. E-Mail-Inhalte und Passwörter werden nicht im Browser-Storage gespeichert.</p></div>';
        return;
    }
    draft=structuredClone(state.accounts.find(a=>a.id===accountId)||{label:'',enabled:true,imap:serverDefault(false),smtp:null,identities:[],performance:performanceDefaults});
    renderAccount();
}
function serverFields(prefix,s) {
    const configured=draft.credential_status?.[prefix+'_configured'];
    return `<div class="settings-grid">${input(prefix+'.hostname','Hostname',s.hostname,'text','required maxlength="253"')}${input(prefix+'.port','Port',s.port,'number','required min="1" max="65535"')}${select(prefix+'.connection_security','Verbindungssicherheit',s.connection_security,[['implicit_tls','SSL/TLS (implicit)'],['starttls','STARTTLS'],['none','Keine (unsicher)']])}${select(prefix+'.authentication.method','Authentifizierung',s.authentication.method,[['auto','Automatisch'],['login','LOGIN'],['plain','PLAIN']])}${input(prefix+'.authentication.username','Benutzername',s.authentication.username,'text','required autocomplete="off"')}${input(prefix+'_password','Passwort','','password',`autocomplete="new-password" placeholder="${configured?'Leer lassen = unverändert':'Passwort speichern'}" ${prefix==='imap'&&!configured?'required':''}`)}${input(prefix+'.authentication.credential_ref','Credential-Referenz',s.authentication.credential_ref,'text','required pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,127}"')}${input(prefix+'.timeout_seconds','Timeout (Sekunden)',s.timeout_seconds,'number','required min="1" max="600"')}${check(prefix+'.validate_certificate','TLS-Zertifikat prüfen',s.validate_certificate)}${prefix==='imap'?check('imap.use_emailid_optimization','EMAILID-Optimierung',s.use_emailid_optimization!==false):''}</div>`;
}
function identityFields(identity={},i=0) {
    const prefix='identity.'+i+'.';
    return `<div class="identity-card" data-identity-index="${i}"><input type="hidden" name="${prefix}identity_id" value="${esc(identity.identity_id||'')}"><div class="settings-grid">${input(prefix+'label','Label',identity.label)}${input(prefix+'display_name','Anzeigename',identity.display_name)}${input(prefix+'from_address','Mailadresse',identity.from_address,'email','required')}${input(prefix+'envelope_from','Envelope-From',identity.envelope_from,'email')}${input(prefix+'reply_to','Reply-To (mit Komma trennen)',(identity.reply_to||[]).map(a=>a.display_name?`${a.display_name} <${a.address}>`:a.address).join(', '))}${select(prefix+'signature.placement','Signatur-Platzierung',identity.signature?.placement||'before_quote',[['before_quote','Vor Zitat'],['after_quote','Nach Zitat']])}${check(prefix+'enabled','Aktiv',identity.enabled!==false)}${check(prefix+'is_default','Standardidentität',identity.is_default)}${check(prefix+'signature.enabled','Signatur aktiv',identity.signature?.enabled)}<div></div><div class="form-row"><label for="sig-text-${i}">Plaintext-Signatur</label><textarea id="sig-text-${i}" name="${prefix}signature.plain_text" rows="4">${esc(identity.signature?.plain_text)}</textarea></div><div class="form-row"><label for="sig-html-${i}">HTML-Signatur</label><textarea id="sig-html-${i}" name="${prefix}signature.html" rows="4">${esc(identity.signature?.html)}</textarea></div></div><div class="settings-actions"><button type="button" class="btn small danger" data-remove-identity>Identität entfernen</button></div></div>`;
}
function renderAccount() {
    const fresh=!draft.account_id;
    $('#settingsMain').innerHTML=`<h1>${fresh?'Postfach hinzufügen':'Postfach: '+esc(draft.label)}</h1><p class="settings-lead">IMAP-Verbindung, SMTP und Absenderidentitäten. Bestehende Passwörter bleiben bei leeren Passwortfeldern unverändert.</p><form id="accountForm"><div id="accountError" class="form-message" hidden role="alert"></div><div class="settings-card"><div class="settings-grid">${input('label','Postfachname / Label',draft.label,'text','required maxlength="120"')}${check('enabled','Postfach aktiv',draft.enabled)}</div></div>
        <h2>IMAP</h2><div class="settings-card">${serverFields('imap',draft.imap)}<div id="imapSecurityWarning" class="settings-note settings-warning" hidden>Diese Verbindung ist unsicher. TLS und Zertifikatsprüfung werden empfohlen.</div></div>
        <h2>SMTP (Vorbereitung)</h2><div class="settings-card"><label class="check-line"><input id="smtpEnabled" type="checkbox" ${draft.smtp?'checked':''}>SMTP konfigurieren</label><p class="settings-note">Die Einstellungen werden gespeichert; Versand ist in dieser Version noch nicht verfügbar. Envelope-From wird pro Absenderidentität festgelegt.</p><fieldset id="smtpFields" ${!draft.smtp?'hidden disabled':''}>${serverFields('smtp',draft.smtp||serverDefault(true))}</fieldset><div id="smtpSecurityWarning" class="settings-note settings-warning" hidden>Diese Verbindung ist unsicher.</div></div>
        <h2>Send-as / Aliase</h2><div id="identityList">${draft.identities.map(identityFields).join('')}</div><button class="btn small" id="addIdentity" type="button">＋ Identität hinzufügen</button>
        <h2>Sync-Performance</h2><div class="settings-card"><div class="settings-grid">${[['max_request_runtime_seconds','Laufzeitlimit pro Schritt (Sekunden)'],['max_index_entries_per_request','Index-Einträge pro Schritt'],['max_content_messages_per_request','Nachrichten pro Schritt'],['max_content_bytes_per_request','Content-Bytebudget pro Schritt'],['imap_metadata_batch_size','Metadaten-Batch']].map(([key,label])=>input('performance.'+key,label,draft.performance[key],'number','required min="1"')).join('')}</div><p class="settings-note">Die Limits gelten je Arbeitsschritt. Es gibt kein Gesamtlimit für das Postfach.</p></div>
        <div class="save-bar"><button class="btn accent" type="submit" id="saveAccount">Einstellungen speichern</button>${!fresh?'<button class="btn danger" type="button" id="archiveAccount">Postfach archivieren</button>':''}</div></form>`;
    const security=()=>{for(const prefix of ['imap','smtp']){const insecure=$('[name="'+prefix+'.connection_security"]').value==='none'||!$('[name="'+prefix+'.validate_certificate"]').checked;$('#'+prefix+'SecurityWarning').hidden=!insecure||(prefix==='smtp'&&!$('#smtpEnabled').checked);}};
    $('#accountForm').onchange=security;security();
    $('#smtpEnabled').onchange=()=>{$('#smtpFields').hidden=!$('#smtpEnabled').checked;$('#smtpFields').disabled=!$('#smtpEnabled').checked;security();};
    $('#addIdentity').onclick=()=>{const next=Math.max(-1,...$$('[data-identity-index]').map(el=>Number(el.dataset.identityIndex)))+1;$('#identityList').insertAdjacentHTML('beforeend',identityFields({enabled:true,is_default:!$('#identityList').children.length},next));};
    $('#identityList').onclick=e=>{if(e.target.closest('[data-remove-identity]'))e.target.closest('[data-identity-index]').remove();};
    $('#accountForm').onsubmit=saveAccount;
    $('#archiveAccount')?.addEventListener('click',archiveAccount);
}
function collectAccount() {
    const form=$('#accountForm'), result={label:'',enabled:false,imap:serverDefault(false),smtp:$('#smtpEnabled').checked?serverDefault(true):null,identities:[],performance:{}};
    const identities=new Map();
    form.querySelectorAll('[name]').forEach(el=>{
        if(el.disabled||el.closest('fieldset:disabled'))return;
        const parts=el.name.split('.');
        let target=result;
        if(parts[0]==='identity'){parts.shift();const index=parts.shift();if(!identities.has(index))identities.set(index,{});target=identities.get(index);}
        for(const part of parts.slice(0,-1))target=target[part]??(target[part]={});
        target[parts.at(-1)]=el.type==='checkbox'?el.checked:el.type==='number'?Number(el.value):el.value;
    });
    result.identities=[...identities.values()].map(i=>({...i,reply_to:(i.reply_to||'').split(',').map(a=>a.trim()).filter(Boolean).map(a=>{const m=a.match(/^(.*?)\s*<([^>]+)>$/);return {display_name:m?m[1]:'',address:m?m[2]:a};})}));
    return result;
}
async function saveAccount(event) {
    event.preventDefault();const button=$('#saveAccount');button.disabled=true;$('#accountError').hidden=true;
    const body=collectAccount(), session=state.session, form=event.currentTarget;
    try {
        const result=await api(draft.account_id?`/accounts/${draft.account_id}`:'/accounts',{method:draft.account_id?'PUT':'POST',body});
        if(state.session!==session)return;
        accountId=result.account.account_id;await loadFolders();
        if(state.session!==session)return;
        if(form===$('#accountForm'))renderSettings();
        toast('Postfacheinstellungen gespeichert.','success');
    } catch(error) {
        if(state.session!==session)return;
        if(form===$('#accountForm')){$('#accountError').textContent=error.message;$('#accountError').hidden=false;$('#accountError').scrollIntoView({block:'nearest'});}else toast(error.message,'danger');
    } finally { button.disabled=false;delete body.imap_password;delete body.smtp_password; }
}
async function archiveAccount() {
    const session=state.session,id=draft.account_id;
    if(!await confirmAction('Postfach archivieren?',`„${draft.label}“ wird aus der aktiven Liste entfernt. Die lokalen Daten bleiben wiederherstellbar erhalten.`))return;
    if(state.session!==session)return;
    try{
        await api(`/accounts/${id}`,{method:'DELETE'});if(state.session!==session)return;
        accountId=null;await loadFolders();if(state.session!==session)return;
        if(state.settingsSection)renderSettings();toast('Postfach wiederherstellbar archiviert.','success');
    }catch(error){if(state.session===session)toast(error.message,'danger');}
}
function renderPreferences() {
    $('#settingsMain').innerHTML=`<h1>Ansicht &amp; Performance</h1><p class="settings-lead">Die Mail-Liste lädt Zusammenfassungen seitenweise. Inhalte und Anhänge werden erst beim Öffnen abgerufen.</p><form id="preferencesForm"><div class="settings-card"><div class="settings-grid">${input('pageSize','Nachrichten pro API-Seite',state.preferences.pageSize,'number','min="10" max="200" required')}${select('density','Listendichte',state.preferences.density,[['comfortable','Komfortabel'],['compact','Kompakt']])}</div><p class="settings-note">Suche und Sortierung laufen serverseitig über alle ausgewählten lokalen Ordner. Der Suchindex wird bei Bedarf schrittweise aufgebaut. Es werden höchstens 110 Nachrichtenzeilen gleichzeitig dargestellt.</p><button class="btn accent">Speichern</button></div></form>`;
    $('#preferencesForm').onsubmit=async e=>{e.preventDefault();state.preferences.pageSize=Number($('[name="pageSize"]').value);state.preferences.density=$('[name="density"]').value;try{await savePreferences();renderTable();toast('Ansicht gespeichert.','success');}catch{}};
}
export function setupSettings() {
    $('#settingsBtn').onclick=()=>openSettings();$('#backToMailBtn').onclick=closeSettings;
    $('.settings-nav').onclick=e=>{const section=e.target.closest('[data-settings-section]');if(section)openSettings(section.dataset.settingsSection);const account=e.target.closest('[data-settings-account]');if(account)openSettings('mailboxes',account.dataset.settingsAccount);};
    $('#addMailboxBtn').onclick=()=>{accountId='new';openSettings('mailboxes');};
    $('#mailboxes').addEventListener('click',e=>{const account=e.target.closest('[data-account-settings]');if(account)openSettings('mailboxes',account.dataset.accountSettings);});
}
