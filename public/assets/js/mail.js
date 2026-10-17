import {api, url} from './api.js';
import {$, $$, esc, fmtDate, fmtSize, address, loading, toast, confirmAction} from './ui.js';
import {state, savePreferences, folderKey, messageKey, messagePath} from './state.js';

export const columns = [
    ['message','Nachricht',310,'from'], ['date','Empfangen',135,'date'], ['bytes','Größe',90,'bytes'],
    ['from','Von',220,'from'], ['to','An',240,null], ['cc','Cc',240,null], ['hasAttachments','Anhang',82,'hasAttachments'],
    ['priority','Priorität',82,'priority'], ['accountId','Postfach',100,'accountId'], ['spam','SpamLvl',98,null],
    ['urgency','Antwort',100,null], ['answerBy','Beantworten bis',130,null], ['importance','Wichtigkeit',95,null], ['report','Melden',115,null],
];
let listController, messageController, listGeneration = 0, previewGeneration = 0;
let detail = null, body = null, bodyMode = 'html';
let activeFavorite = null, editingFavorite = null;
let renderStart = -1;
let columnDrag = null, blockColumnDrag = false, suppressColumnClickUntil = 0;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

export function clearMail() {
    finishColumnDrag();
    listController?.abort(); messageController?.abort(); ++listGeneration; ++previewGeneration;
    state.messages = []; state.selected = null; state.checked.clear(); detail = body = null;
    activeFavorite = editingFavorite = null; state.collapsed.clear();
    $('#previewContent').innerHTML = ''; $('#previewToolbar').innerHTML = ''; $('#messageBody').innerHTML = '';
}

export async function loadFolders() {
    const session=state.session;
    const result = await api('/accounts');
    if(state.session!==session)return;
    state.accounts = result.accounts.map(a => ({...a, id:a.account_id}));
    const lists = await Promise.all(state.accounts.map(async a => {
        try { const result = await api(`/webmail/accounts/${encodeURIComponent(a.id)}/folders`); a.folderError = null; return result.folders; }
        catch (error) { a.folderError = error.message; return []; }
    }));
    if(state.session!==session)return;
    state.folders = lists.flat();
    state.scopes = state.scopes.filter(key => state.folders.some(f => folderKey(f) === key));
    if (!state.scopes.length) state.scopes = state.folders.filter(f => f.type === 'inbox' && f.selectable).map(folderKey);
    if (!state.scopes.length && state.folders.some(f => f.selectable)) state.scopes = [folderKey(state.folders.find(f => f.selectable))];
    renderSidebar();
}

export function renderSidebar() {
    const pref = state.preferences;
    $('#favorites').innerHTML = `<div class="nav-item ${activeFavorite === 'inboxes' ? 'active':''}" data-inboxes tabindex="0"><span class="star">★</span><span class="label">Alle Eingänge</span><span class="count">${state.folders.filter(f => f.type === 'inbox').reduce((n,f) => n+f.counts.total,0)}</span></div>` +
        pref.favorites.map((f,i) => `<div class="nav-item ${activeFavorite === i ? 'active':''}" data-favorite="${i}" tabindex="0"><span class="star">★</span><span class="label">${esc(f.name)}</span><button class="favorite-remove" data-edit-favorite="${i}" title="Bearbeiten">⋯</button><button class="favorite-remove" data-delete-favorite="${i}" title="Favorit löschen">×</button></div>`).join('');
    $('#mailboxes').innerHTML = state.accounts.map(a => {
        const folders = state.folders.filter(f => f.accountId === a.id);
        const checked = folders.filter(f=>f.selectable).every(f => state.scopes.includes(folderKey(f)));
        return `<section class="mailbox ${state.collapsed.has(a.id) ? 'collapsed':''}"><div class="mailbox-title"><button class="mailbox-collapse" data-collapse="${a.id}" aria-label="${esc(a.label)} ein-/ausklappen">${state.collapsed.has(a.id)?'▶':'▼'}</button><input type="checkbox" data-account-check="${a.id}" ${checked && folders.length?'checked':''} aria-label="Ordner von ${esc(a.label)} auswählen"><span class="mailbox-dot"></span><span class="account-label" title="${esc(a.label)}">${esc(a.label)}</span><button class="btn small" data-account-settings="${a.id}" title="Postfacheinstellungen">⚙</button></div><div class="folders">${folders.map(f => {
            const key = folderKey(f), icon = {inbox:'▣',sent:'↗',drafts:'✎',archive:'▤',junk:'⚠',trash:'⌫'}[f.type] || '◆';
            return `<div class="folder-row ${state.scopes.includes(key)?'active':''}" data-folder="${key}" tabindex="0"><input type="checkbox" data-folder-check="${key}" ${state.scopes.includes(key)?'checked':''} ${!f.selectable?'disabled':''} aria-label="${esc(f.name)} auswählen"><span>${icon}</span><span class="folder-name" title="${esc(f.name)}">${esc(f.name)}${!f.remotePresent?' (lokal)':''}</span><span class="count" title="${f.counts.unread} ungelesen · ${f.counts.pending} noch nicht geladen">${f.counts.total}${f.counts.pending?' ◌':''}</span></div>`;
        }).join('') || `<div class="sidebar-status">${esc(a.folderError || 'Noch keine lokalen Ordner. Bitte synchronisieren.')}</div>`}</div></section>`;
    }).join('') || '<div class="sidebar-status">Noch kein Postfach eingerichtet. Füge unter Einstellungen ein Postfach hinzu.</div>';
    const pending = state.folders.reduce((n,f) => n+f.counts.pending,0);
    $('#sidebarStatus').textContent = pending ? `${pending} Nachrichten warten auf den nächsten Sync.` : 'Lokale Mail-Kopie · IMAP read-only';
}

export async function loadMessages(reset = true) {
    if (!reset && (state.loading || !state.cursor)) return;
    if (reset) {
        listController?.abort(); listController = new AbortController(); ++listGeneration;
        state.messages = []; state.cursor = null; state.total = 0; state.checked.clear(); state.selected = null;
        ++previewGeneration; messageController?.abort(); detail = body = null; renderPreview();
        $('#messageTableWrap').scrollTop = 0; renderStart = -1;
    }
    const generation = listGeneration, signal = listController.signal;
    state.loading = true; state.pageError = null; state.indexProgress = null; renderTable();
    try {
        if (!state.scopes.length) { state.total = 0; return; }
        const params = new URLSearchParams({folders:state.scopes.join(','),limit:String(state.preferences.pageSize),sort:state.sort,direction:state.direction,...state.filters});
        if (!reset && state.cursor) params.set('cursor', state.cursor);
        let result;
        do {
            result = await api(`/webmail/messages?${params}`, {signal});
            if (generation !== listGeneration) return;
            state.indexProgress = result.indexing;
            if (result.indexing.length) {
                renderRows(true); updateCount();
                await pause(120);
                if (signal.aborted) return;
            }
        } while (result.indexing.length);
        if (generation !== listGeneration) return;
        state.messages.push(...result.messages); state.total = result.total; state.cursor = result.nextCursor;
    } catch (error) {
        if (error.name === 'AbortError' || generation !== listGeneration) return;
        if (error.code === 'INDEX_CHANGED') {
            await pause(250);
            if (generation === listGeneration && !signal.aborted) return loadMessages(true);
            return;
        }
        state.pageError = error.message;
    } finally {
        if (generation === listGeneration) { state.loading = false; renderRows(true); updateCount(); }
    }
}

function orderedColumns() {
    const keys = [...new Set([...(state.preferences.columnOrder || []), ...columns.map(c => c[0])])];
    return keys.map(key => columns.find(c => c[0] === key)).filter(Boolean);
}
function visibleColumns() { return orderedColumns().filter(c => state.preferences.columns.includes(c[0])); }
function setColumnOrder(order, focusKey = null, focusStep = null) {
    if (order.join() === orderedColumns().map(c => c[0]).join()) return;
    state.preferences.columnOrder = order;
    savePreferences();
    const menuScroll = $('#columnsMenu').scrollTop;
    renderTable();
    $('#columnsMenu').scrollTop = menuScroll;
    if (focusKey) {
        const row = $('#columnsMenu [data-column-key="'+focusKey+'"]');
        const button = row.querySelector('[data-column-step="'+focusStep+'"]:not(:disabled)') || row.querySelector('button:not(:disabled)');
        button?.focus({preventScroll:true});
        row.scrollIntoView({block:'nearest'});
    }
}
function moveColumn(key, target, after) {
    const order = orderedColumns().map(c => c[0]).filter(c => c !== key);
    const index = order.indexOf(target);
    if (key === target || index < 0) return;
    order.splice(index + (after ? 1 : 0), 0, key);
    setColumnOrder(order);
}
function clearColumnDropIndicators() {
    $$('#tableHead .drop-before, #tableHead .drop-after, #columnsMenu .drop-before, #columnsMenu .drop-after').forEach(el => el.classList.remove('drop-before','drop-after'));
}
function finishColumnDrag() {
    if (columnDrag) suppressColumnClickUntil = performance.now()+250;
    columnDrag = null;
    clearColumnDropIndicators();
    $$('.column-dragging').forEach(el => el.classList.remove('column-dragging'));
}
function setupColumnDrag(container, vertical) {
    container.addEventListener('pointerdown', e => { blockColumnDrag = !!e.target.closest('input,button,[data-resize]'); });
    container.addEventListener('dragstart', e => {
        const item = e.target.closest('[data-column-key]');
        if (!item || blockColumnDrag || !state.preferences) { e.preventDefault(); return; }
        columnDrag = {key:item.dataset.columnKey, session:state.session};
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', columnDrag.key);
        item.classList.add('column-dragging');
    });
    const targetAt = e => {
        const item = e.target.closest('[data-column-key]');
        if (!columnDrag || columnDrag.session !== state.session || !item || !container.contains(item)) return null;
        const rect = item.getBoundingClientRect();
        return {item, after:vertical ? e.clientY > rect.top+rect.height/2 : e.clientX > rect.left+rect.width/2};
    };
    container.addEventListener('dragover', e => {
        const target = targetAt(e);
        if (!target) return;
        e.preventDefault(); e.dataTransfer.dropEffect = 'move';
        clearColumnDropIndicators();
        if (target.item.dataset.columnKey !== columnDrag.key) target.item.classList.add(target.after ? 'drop-after' : 'drop-before');
        const scroller = vertical ? container : $('#messageTableWrap'), rect = scroller.getBoundingClientRect();
        if (vertical) {
            if (e.clientY < rect.top+25) scroller.scrollTop -= 15;
            else if (e.clientY > rect.bottom-25) scroller.scrollTop += 15;
        } else {
            if (e.clientX < rect.left+25) scroller.scrollLeft -= 15;
            else if (e.clientX > rect.right-25) scroller.scrollLeft += 15;
        }
    });
    container.addEventListener('dragleave', e => { if (!container.contains(e.relatedTarget)) clearColumnDropIndicators(); });
    container.addEventListener('drop', e => {
        const target = targetAt(e);
        if (!target) return;
        e.preventDefault();
        const key = columnDrag.key;
        finishColumnDrag();
        moveColumn(key, target.item.dataset.columnKey, target.after);
    });
    container.addEventListener('dragend', finishColumnDrag);
}
function positionColumnsMenu() {
    const menu = $('#columnsMenu');
    if (!menu.classList.contains('show')) return;
    const anchor = $('#columnsBtn').getBoundingClientRect();
    const top = Math.min(anchor.bottom+4, window.innerHeight-100);
    menu.style.top = Math.max(8,top)+'px';
    menu.style.left = Math.max(8,Math.min(anchor.right-menu.offsetWidth,window.innerWidth-menu.offsetWidth-8))+'px';
    menu.style.maxHeight = Math.max(80,window.innerHeight-Math.max(8,top)-8)+'px';
}
export function toggleColumnsMenu(force) {
    $('#columnsMenu').classList.toggle('show', force ?? !$('#columnsMenu').classList.contains('show'));
    positionColumnsMenu();
}
function columnWidth(column) { return state.preferences.columnWidths[column[0]] || column[2]; }
function updateTableWidth() {
    // A definite width makes fixed table layout honor the colgroup instead of mail contents.
    $('#messageTable').style.width = (34 + visibleColumns().reduce((sum,column) => sum + columnWidth(column), 0)) + 'px';
}
function tableState(text, error = false) {
    return `<tr class="table-state"><td colspan="${visibleColumns().length+1}">${error ? '<div class="form-message">'+esc(text)+'</div><button class="btn small" id="retryMessages">Erneut versuchen</button>' : text}</td></tr>`;
}
export function renderTable() {
    const cols = visibleColumns();
    $('#tableCols').innerHTML = '<col style="width:34px">' + cols.map(c => `<col data-col="${c[0]}" style="width:${columnWidth(c)}px">`).join('');
    $('#tableHead').innerHTML = '<th class="check"></th>'+cols.map(c => `<th data-column-key="${c[0]}" draggable="true" data-sort="${c[3] || ''}" title="Ziehen zum Verschieben${c[3] ? ' · Klicken zum Sortieren' : ''}"><span class="column-heading"><span class="column-title" title="${esc(c[1])}">${esc(c[1])}</span>${state.sort===c[3]?'<span class="sort-ind">'+(state.direction==='desc'?'▼':'▲')+'</span>':''}</span><span class="col-resizer" data-resize="${c[0]}" draggable="false"></span></th>`).join('');
    updateTableWidth();
    const allColumns = orderedColumns();
    $('#columnsMenu').innerHTML = '<div class="column-menu-hint">Ziehen oder mit ↑ / ↓ verschieben</div>'+allColumns.map((c,i) => `<div class="column-option" data-column-key="${c[0]}" draggable="true"><span class="column-grip" aria-hidden="true" title="Spalte ziehen">⠿</span><label class="check-line"><input type="checkbox" data-column="${c[0]}" ${state.preferences.columns.includes(c[0])?'checked':''} ${c[0]==='message'?'disabled':''}><span>${c[1]}</span></label><div class="column-move-buttons"><button type="button" data-column-step="-1" ${i===0?'disabled':''} aria-label="${c[1]} nach oben" title="Nach oben">↑</button><button type="button" data-column-step="1" ${i===allColumns.length-1?'disabled':''} aria-label="${c[1]} nach unten" title="Nach unten">↓</button></div></div>`).join('');
    positionColumnsMenu();
    $('#messageTable').classList.toggle('density-compact', state.preferences.density === 'compact');
    $('#filterChips').innerHTML = Object.entries(state.filters).map(([k,v])=>`<span class="chip">${esc(k)}: ${esc(v)} <button data-clear-filter="${esc(k)}" aria-label="Filter entfernen">×</button></span>`).join('');
    $('#filterChips').classList.toggle('has', Object.keys(state.filters).length>0);
    const selectedFolders = state.folders.filter(f=>state.scopes.includes(folderKey(f)));
    $('#listTitle').textContent = typeof activeFavorite === 'number' ? state.preferences.favorites[activeFavorite]?.name || 'Ansicht' : selectedFolders.length === 1 ? selectedFolders[0].name : 'Mehrere Ordner';
    $('#sortStatus').textContent = state.direction === 'desc' ? '▼ Absteigend':'▲ Aufsteigend';
    renderRows(true); updateCount();
}
function cell(m, key) {
    const a = state.accounts.find(a=>a.id===m.accountId);
    switch (key) {
        case 'message': {
            const snippet = esc(m.snippet || (m.contentState==='pending'?'Inhalt noch nicht synchronisiert':''));
            return `<div class="sender-line"><span class="sender">${esc(m.from.name || m.from.email || '–')}</span><span class="mailbox-badge">${esc(a?.label)}</span>${m.flags.flagged?'★':''}</div><div class="subject">${esc(m.subject)}</div><div class="snippet" title="${snippet}">${snippet}</div>`;
        }
        case 'date': return esc(fmtDate(m.date));
        case 'bytes': return `<span title="${esc(m.bytes ?? 0)} Bytes">${esc(fmtSize(m.bytes))}</span>`;
        case 'from':
        case 'to':
        case 'cc': {
            const text = esc((key === 'from' ? address(m.from) : (m[key] || []).map(address).filter(Boolean).join('; ')) || '–');
            return `<div class="address-cell-text" title="${text}">${text}</div>`;
        }
        case 'hasAttachments': return m.hasAttachments ? `📎 ${m.attachmentCount}` : '–';
        case 'priority': return `<span class="pill ${m.priority==='high'?'high':m.priority==='low'?'low':''}">${{high:'Hoch',low:'Niedrig',normal:'Normal'}[m.priority]}</span>`;
        case 'accountId': return esc(a?.label);
        case 'spam': return m.spamScore == null ? '–' : esc(m.spamScore);
        case 'report': return '<span class="ai-unchecked" title="Kein Meldedienst konfiguriert">Nicht verfügbar</span>';
        default: return '<span class="ai-unchecked" title="Keine AI-Bewertung vorhanden">–</span>';
    }
}
function renderRows(force = false) {
    const target = $('#messageBody'), cols = visibleColumns();
    $('#selectionToolbar').classList.toggle('show',state.checked.size>0);
    $('#selectAllMessages').checked=state.messages.length>0&&state.messages.every(m=>state.checked.has(messageKey(m)));
    if (!state.messages.length) {
        if (state.pageError) target.innerHTML = tableState(state.pageError, true);
        else if (state.loading) target.innerHTML = tableState(loading(state.indexProgress?.length ? `Lokaler Suchindex wird vorbereitet … ${state.indexProgress.reduce((n,p)=>n+p.processed,0)} Nachrichten verarbeitet` : 'Nachrichten werden geladen …'));
        else target.innerHTML = tableState(esc(!state.accounts.length ? 'Richte in den Einstellungen dein erstes Postfach ein.' : !state.scopes.length ? 'Wähle einen Ordner oder synchronisiere dein Postfach.' : 'Keine Nachrichten für diese Ansicht.'));
        return;
    }
    const rowHeight = state.preferences.density === 'compact' ? 48 : 76;
    const start = Math.max(0, Math.floor($('#messageTableWrap').scrollTop/rowHeight)-10);
    if (!force && start === renderStart) return;
    renderStart = start;
    const rows = state.messages.slice(start,start+110), end = start+rows.length;
    const spacer = height => `<tr aria-hidden="true"><td colspan="${cols.length+1}" style="height:${height}px;padding:0;border:0"></td></tr>`;
    target.innerHTML = (start ? spacer(start*rowHeight):'')+rows.map(m => {
        const key = messageKey(m);
        return `<tr class="msg-row ${!m.flags.seen?'unread':''} ${state.selected===key?'active':''}" data-message="${esc(key)}" style="height:${rowHeight}px"><td class="cell-check"><input type="checkbox" data-message-check="${esc(key)}" ${state.checked.has(key)?'checked':''} aria-label="Nachricht auswählen"></td>${cols.map(c=>`<td data-column="${c[0]}" class="${c[0]==='message'?'message-cell':'date-cell'}">${cell(m,c[0])}</td>`).join('')}</tr>`;
    }).join('')+(end<state.messages.length?spacer((state.messages.length-end)*rowHeight):'')+(state.pageError?tableState(state.pageError,true):'');
    $('#selectionToolbar').classList.toggle('show',state.checked.size>0);
    $('#selectionLabel').textContent = `${state.checked.size} ausgewählt`;
}
function updateCount() {
    $('#messageCount').textContent = state.loading ? (state.indexProgress?.length ? 'Lokalen Index vorbereiten …':'Lädt …') : `${state.messages.length} von ${state.total ?? '…'} Nachrichten`;
    $('#loadMoreBtn').hidden = !state.cursor;
    $('#loadMoreBtn').disabled = state.loading;
}

export async function openMessage(m) {
    messageController?.abort(); messageController = new AbortController();
    const signal = messageController.signal, generation = ++previewGeneration;
    state.selected = messageKey(m); detail = body = null; renderRows(true);
    $('#workspace').classList.add('show-preview');
    $('#previewToolbar').innerHTML = '<button class="btn small" id="backToList">← Liste</button>';
    $('#previewContent').innerHTML = '<div class="preview-loading">'+loading('Nachricht wird geladen …')+'</div>';
    try {
        const data = await api(messagePath(m),{signal});
        if (generation !== previewGeneration) return;
        detail = data.message; bodyMode = detail.hasHtml?'html':'text'; renderPreview();
        if (detail.contentState === 'available') await loadBody(false,generation,signal);
    } catch (error) {
        if (error.name==='AbortError'||generation!==previewGeneration) return;
        $('#previewContent').innerHTML = `<div class="form-message">${esc(error.message)}</div><button class="btn" id="retryMessage">Erneut versuchen</button>`;
    }
}
async function loadBody(external = false, generation = previewGeneration, signal = messageController?.signal) {
    if (!detail) return;
    try {
        const result = await api(messagePath(detail)+`/body?external=${external?1:0}`,{signal});
        if (generation !== previewGeneration) return;
        body = result; renderPreview();
    } catch (error) {
        if (error.name==='AbortError'||generation!==previewGeneration) return;
        $('#bodyBox').innerHTML = `<div class="form-message">${esc(error.message)}</div><button class="btn" id="retryBody">Inhalt erneut laden</button>`;
    }
}
function renderPreview() {
    if (!detail) {
        $('#previewToolbar').innerHTML = '';
        $('#previewContent').innerHTML = '<div class="empty-state"><div><strong>Dein Postfach</strong>Wähle eine Nachricht aus.</div></div>';
        return;
    }
    const m=detail, account=state.accounts.find(a=>a.id===m.accountId);
    $('#previewToolbar').innerHTML = `<button class="btn small" id="backToList">← Liste</button><button class="btn small" disabled title="SMTP-Versand noch nicht unterstützt">↶ Antworten</button><button class="btn small" disabled title="SMTP-Versand noch nicht unterstützt">Allen antworten</button><button class="btn small" disabled title="SMTP-Versand noch nicht unterstützt">Weiterleiten</button><button class="btn small" id="showHeaders">Header</button>${m.contentState==='available'?`<a class="btn small" href="${esc(url(messagePath(m)+'/raw'))}">EML</a>`:''}<span class="read-only-note">Read-only Mirror</span>`;
    $('#previewContent').innerHTML = `<div class="mail-head"><h1 class="mail-subject">${esc(m.subject)}</h1><div class="mail-meta-line"><div class="avatar">${esc((m.from.name||m.from.email||'?').slice(0,2).toUpperCase())}</div><div class="meta-main"><span class="from-name">${esc(m.from.name||m.from.email||'Unbekannter Absender')}</span> <span class="address">${esc(m.from.name?m.from.email:'')}</span><div class="meta-small">An: ${esc(m.to.map(address).join('; ')||'–')}</div>${m.cc.length?`<div class="meta-small">CC: ${esc(m.cc.map(address).join('; '))}</div>`:''}<div class="meta-small">${esc(fmtDate(m.date))} · ${esc(account?.label)} · ${fmtSize(m.bytes)}</div></div></div><div class="message-tags">${m.flags.flagged?'<span class="pill warn">★ Markiert</span>':''}${m.flags.answered?'<span class="pill ok">Beantwortet</span>':''}${m.flags.deleted?'<span class="pill high">Auf IMAP als gelöscht markiert</span>':''}</div></div>
        <button class="btn small" id="toggleDetails">Details</button><div class="details-box" id="detailsBox"><b>Reply-To:</b> ${esc(m.replyTo.map(address).join('; ')||'–')}<br><b>Message-ID:</b> ${esc(m.messageId||'–')}<br><b>Ordner:</b> ${esc(state.folders.find(f=>f.id===m.folderId&&f.accountId===m.accountId)?.name||'–')}<br><b>UID:</b> ${m.uid}</div>
        <div class="ai-panel"><div class="metric"><div class="metric-label">SpamLvl</div><div class="metric-value">${m.spamScore==null?'–':esc(m.spamScore)}</div><div class="metric-detail">Aus Nachrichtenheader</div></div>${['Antwort-Dringlichkeit','Beantworten bis','AI-Wichtigkeit'].map(label=>`<div class="metric"><div class="metric-label">${label}</div><div class="metric-value">–</div><div class="metric-detail">AI nicht verfügbar</div></div>`).join('')}</div>
        <div class="attachment-bar">${m.attachments.map(a=>`<a class="attachment" href="${esc(url(messagePath(m)+'/attachments/'+encodeURIComponent(a.id)))}"><span>📎</span><div><div class="att-name">${esc(a.name)}</div><div class="att-size">${esc(a.mime)}</div></div><span>↓</span></a>`).join('')}</div>
        ${m.contentState==='pending'?'<div class="load-body">Der Inhalt ist noch nicht lokal gespeichert. Starte oder setze die Synchronisation fort.</div>':`<div class="settings-actions"><button class="btn small" id="bodyHtml" ${!m.hasHtml?'disabled':''}>HTML</button><button class="btn small" id="bodyText">Plaintext</button></div><div id="bodyBox" class="body-box">${body?'':loading('Nachrichteninhalt wird geladen …')}</div>`}`;
    if (!body || !$('#bodyBox')) return;
    $('#bodyBox').innerHTML = (body.truncated?'<p class="settings-note">Die Vorschau ist auf 2 MiB pro Body begrenzt. Die vollständige Nachricht steht als EML bereit.</p>':'') +
        (bodyMode==='html'&&body.html ? `${!body.externalAllowed?'<div class="external-warning">⚠ Externe Inhalte sind blockiert. <button class="btn small" id="loadExternal">Externe Bilder laden</button></div>':''}<iframe class="html-frame" id="htmlFrame" title="Isolierter Nachrichteninhalt" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>` : `<pre>${esc(body.text || 'Kein Plaintext-Teil vorhanden. Verwende die HTML-Ansicht.')}</pre>`);
    if ($('#htmlFrame')) $('#htmlFrame').srcdoc = body.html;
}

function chooseScopes(scopes, favorite = null) {
    state.scopes = scopes; activeFavorite = favorite; state.filters={}; $('#quickSearch').value='';
    renderSidebar(); loadMessages();
}
export function allInboxes() { chooseScopes(state.folders.filter(f=>f.type==='inbox'&&f.selectable).map(folderKey),'inboxes'); }
function openAdvanced() {
    const fields=[['q','Freitext'],['from','Von enthält'],['to','An/CC enthält'],['subject','Betreff enthält'],['dateFrom','Datum von'],['dateTo','Datum bis']];
    $('#advancedDialog').innerHTML=`<form id="advancedForm"><div class="dialog-head">Erweiterte Suche<button type="button" class="btn small" data-close="advancedDialog">×</button></div><div class="dialog-body"><p class="settings-note">Sucht in Absender, Empfänger, Betreff und Vorschautext aller ausgewählten Ordner.</p><div class="form-grid">${fields.map(([key,label])=>`<div class="form-row"><label for="adv-${key}">${label}</label><input id="adv-${key}" name="${key}" type="${key.startsWith('date')?'date':'text'}" maxlength="300" value="${esc(state.filters[key]||'')}"></div>`).join('')}${[['unread','nur ungelesen'],['flagged','nur markiert'],['attachment','mit Anhang'],['unanswered','unbeantwortet']].map(([key,label])=>`<label class="check-line"><input type="checkbox" name="${key}" value="1" ${state.filters[key]?'checked':''}>${label}</label>`).join('')}<div class="form-row"><label for="adv-priority">Priorität</label><select name="priority" id="adv-priority"><option value="">Alle</option>${['high','normal','low'].map(p=>`<option value="${p}" ${state.filters.priority===p?'selected':''}>${{high:'Hoch',normal:'Normal',low:'Niedrig'}[p]}</option>`).join('')}</select></div></div></div><div class="dialog-foot"><button type="reset" class="btn">Zurücksetzen</button><button class="btn accent">Suchen</button></div></form>`;
    $('#advancedForm').onsubmit=e=>{e.preventDefault();state.filters=Object.fromEntries([...new FormData(e.target)].filter(([,v])=>v!==''));$('#quickSearch').value=state.filters.q||'';activeFavorite=null;$('#advancedDialog').close();loadMessages();};
    $('#advancedDialog').showModal();
}
function favoriteDialog(index = null) {
    editingFavorite=index;
    const old = index==null?null:state.preferences.favorites[index];
    $('#favoriteDialog').innerHTML=`<form id="favoriteForm"><div class="dialog-head">Favorit ${old?'bearbeiten':'speichern'}<button type="button" class="btn small" data-close="favoriteDialog">×</button></div><div class="dialog-body"><div class="form-row"><label for="favoriteName">Name</label><input type="text" id="favoriteName" value="${esc(old?.name||'')}" required maxlength="100"></div><p>${old?'Name ändern oder zuerst die gewünschte Ansicht öffnen und neu speichern.':'Speichert die aktuelle Ordnerauswahl und Suchfilter.'}</p></div><div class="dialog-foot"><button class="btn accent">Speichern</button></div></form>`;
    $('#favoriteForm').onsubmit=async e=>{e.preventDefault();const f={name:$('#favoriteName').value.trim(),folders:old?.folders||[...state.scopes],filters:old?.filters||{...state.filters}};if(!f.name)return;if(editingFavorite==null)state.preferences.favorites.push(f);else state.preferences.favorites[editingFavorite]=f;try{await savePreferences();$('#favoriteDialog').close();renderSidebar();}catch{}};
    $('#favoriteDialog').showModal();
}

export function setupMail() {
    $('#mailboxes').addEventListener('click',e=>{
        const collapse=e.target.closest('[data-collapse]');if(collapse){const id=collapse.dataset.collapse;state.collapsed.has(id)?state.collapsed.delete(id):state.collapsed.add(id);renderSidebar();return;}
        if(e.target.closest('input,button'))return;
        const row=e.target.closest('[data-folder]');if(row){const f=state.folders.find(f=>folderKey(f)===row.dataset.folder);if(f?.selectable)chooseScopes([row.dataset.folder]);}
    });
    $('#mailboxes').addEventListener('change',e=>{
        const keys=e.target.dataset.folderCheck?[e.target.dataset.folderCheck]:state.folders.filter(f=>f.accountId===e.target.dataset.accountCheck&&f.selectable).map(folderKey);
        const scopes=new Set(state.scopes);keys.forEach(key=>e.target.checked?scopes.add(key):scopes.delete(key));chooseScopes([...scopes]);
    });
    $('#favorites').addEventListener('click',async e=>{
        const edit=e.target.closest('[data-edit-favorite]');if(edit)return favoriteDialog(Number(edit.dataset.editFavorite));
        const remove=e.target.closest('[data-delete-favorite]');if(remove){const i=Number(remove.dataset.deleteFavorite);if(await confirmAction('Favorit löschen?',state.preferences.favorites[i].name)){state.preferences.favorites.splice(i,1);savePreferences();activeFavorite=null;renderSidebar();}return;}
        if(e.target.closest('[data-inboxes]'))return allInboxes();
        const el=e.target.closest('[data-favorite]');if(!el)return;activeFavorite=Number(el.dataset.favorite);const f=state.preferences.favorites[activeFavorite];state.scopes=f.folders.filter(k=>state.folders.some(folder=>folderKey(folder)===k));state.filters={...f.filters};$('#quickSearch').value=state.filters.q||'';renderSidebar();loadMessages();
    });
    $('#selectAllInboxesBtn').onclick=allInboxes;
    $('#saveFavoriteBtn').onclick=()=>favoriteDialog();
    $('#advancedSearchBtn').onclick=openAdvanced;
    let searchTimer;
    $('#quickSearch').oninput=()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{const v=$('#quickSearch').value.trim();if(v)state.filters.q=v;else delete state.filters.q;activeFavorite=null;loadMessages();},300);};
    $('#clearSearchBtn').onclick=()=>{$('#quickSearch').value='';delete state.filters.q;loadMessages();};
    $('#filterChips').onclick=e=>{const el=e.target.closest('[data-clear-filter]');if(el){delete state.filters[el.dataset.clearFilter];$('#quickSearch').value=state.filters.q||'';loadMessages();}};
    $('#columnsBtn').onclick=()=>toggleColumnsMenu();
    window.addEventListener('resize',positionColumnsMenu);
    setupColumnDrag($('#tableHead'),false);
    setupColumnDrag($('#columnsMenu'),true);
    $('#columnsMenu').onclick=e=>{
        const button=e.target.closest('[data-column-step]');
        if(!button)return;
        e.stopPropagation(); // Rendering replaces the button; don't treat the detached target as an outside click.
        const key=button.closest('[data-column-key]').dataset.columnKey,step=Number(button.dataset.columnStep),order=orderedColumns().map(c=>c[0]),index=order.indexOf(key);
        if(index+step<0||index+step>=order.length)return;
        order.splice(index,1);order.splice(index+step,0,key);setColumnOrder(order,key,step);
    };
    $('#columnsMenu').onchange=e=>{const c=e.target.dataset.column;if(!c)return;state.preferences.columns=e.target.checked?[...state.preferences.columns,c]:state.preferences.columns.filter(x=>x!==c);savePreferences();renderTable();};
    $('#tableHead').onclick=e=>{if(e.target.closest('[data-resize]')||performance.now()<suppressColumnClickUntil)return;const key=e.target.closest('[data-sort]')?.dataset.sort;if(!key)return;state.direction=state.sort===key&&state.direction==='desc'?'asc':'desc';state.sort=key;loadMessages();};
    $('#tableHead').onpointerdown=e=>{
        const handle=e.target.closest('[data-resize]');if(!handle)return;e.preventDefault();const key=handle.dataset.resize,start=e.clientX,width=state.preferences.columnWidths[key]||columns.find(c=>c[0]===key)[2];handle.setPointerCapture(e.pointerId);
        const move=event=>{state.preferences.columnWidths[key]=Math.max(30,Math.min(1200,width+event.clientX-start));$('[data-col="'+key+'"]').style.width=state.preferences.columnWidths[key]+'px';updateTableWidth();};
        const up=()=>{handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',up);savePreferences();};handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',up);
    };
    $('#messageBody').onclick=e=>{
        if(e.target.closest('#retryMessages'))return loadMessages(state.messages.length===0);
        const key=e.target.closest('[data-message]')?.dataset.message;if(!key)return;
        if(e.target.matches('[data-message-check]')){e.target.checked?state.checked.add(key):state.checked.delete(key);renderRows(true);return;}
        const m=state.messages.find(m=>messageKey(m)===key);if(m)openMessage(m);
    };
    $('#selectAllMessages').onchange=e=>{state.messages.forEach(m=>e.target.checked?state.checked.add(messageKey(m)):state.checked.delete(messageKey(m)));renderRows(true);};
    $('#exportSelected').onclick=()=>{
        const selected=state.messages.filter(m=>state.checked.has(messageKey(m))&&m.contentState==='available');
        if(selected.length!==1){toast('Bitte genau eine lokal verfügbare Nachricht zum EML-Export auswählen.','warning');return;}
        const link=document.createElement('a');link.href=url(messagePath(selected[0])+'/raw');link.click();
    };
    $('#messageTableWrap').addEventListener('scroll',()=>{renderRows();const el=$('#messageTableWrap');if(el.scrollHeight-el.scrollTop-el.clientHeight<600)loadMessages(false);},{passive:true});
    $('#loadMoreBtn').onclick=()=>loadMessages(false);
    $('#preview').onclick=e=>{
        if(e.target.closest('#backToList')){$('#workspace').classList.remove('show-preview');return;}
        if(e.target.closest('#retryMessage')){const m=state.messages.find(m=>messageKey(m)===state.selected);if(m)openMessage(m);}
        if(!detail)return;
        if(e.target.closest('#toggleDetails'))$('#detailsBox').classList.toggle('show');
        if(e.target.closest('#showHeaders')){$('#headerRaw').textContent=detail.headers.map(h=>h.name+': '+h.value).join('\n');$('#headersDialog').showModal();}
        if(e.target.closest('#bodyText')){bodyMode='text';renderPreview();}
        if(e.target.closest('#bodyHtml')){bodyMode='html';renderPreview();}
        if(e.target.closest('#loadExternal'))loadBody(true);
        if(e.target.closest('#retryBody'))loadBody(false);
    };
    document.addEventListener('keydown',e=>{
        if(['INPUT','SELECT','TEXTAREA','BUTTON'].includes(document.activeElement?.tagName)||$('dialog[open]')||state.settingsSection)return;
        if(e.key==='/'){e.preventDefault();$('#quickSearch').focus();}
        if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();const current=state.messages.findIndex(m=>messageKey(m)===state.selected);const i=Math.max(0,Math.min(state.messages.length-1,current+(e.key==='ArrowDown'?1:-1)));if(state.messages[i])openMessage(state.messages[i]);}
    });
}
