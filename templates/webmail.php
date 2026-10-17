<?php
declare(strict_types=1);
$base = htmlspecialchars($app['deployment']->basePath(), ENT_QUOTES);
$version = (string) filemtime($app['publicRoot'].'/assets/js/webmail.js');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https: http:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?>
<!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>foresight</title>
<link rel="stylesheet" href="./assets/css/webmail.css?v=<?= $version ?>"><link rel="stylesheet" href="./assets/css/integration.css?v=<?= $version ?>">
</head><body data-base-path="<?= $base ?>">
<main id="authView" class="auth-view"><div class="auth-card">foresight wird geladen …</div></main>
<div class="app" id="app" hidden>
 <header class="topbar">
  <div class="brand"><div class="brand-mark">f</div><div><div>foresight</div><div class="brand-sub">Webmail · <span id="sessionUser"></span></div></div></div>
  <div class="top-actions">
   <button class="btn primary" id="newMailBtn" disabled title="foresight unterstützt SMTP-Versand in dieser Version noch nicht.">✉ Neue Nachricht</button>
   <div class="search-wrap"><input id="quickSearch" type="text" placeholder="E-Mails durchsuchen …  ( / )" autocomplete="off" aria-label="E-Mails durchsuchen"><button id="clearSearchBtn" title="Suche leeren">×</button></div>
   <button class="btn ghost" id="advancedSearchBtn">Erweiterte Suche</button><button class="btn ghost" id="refreshBtn" title="Postfächer synchronisieren">↻</button><button class="btn ghost" id="settingsBtn">⚙ Einstellungen</button>
   <div class="menu-wrap"><button class="btn ghost" id="appMenuBtn" aria-label="Menü">⋯</button><div class="menu" id="appMenu">
    <button data-action="columns">Spalten auswählen</button><button data-action="density">Ansicht kompakt/komfortabel</button><button data-action="sync">Synchronisation</button><button data-action="logout">Abmelden</button>
   </div></div>
  </div>
 </header>
 <main class="workspace" id="workspace">
  <aside class="sidebar" id="sidebar">
   <div class="side-head"><span>Favoriten</span><button class="btn small" id="saveFavoriteBtn" title="Ansicht speichern">＋</button></div><div id="favorites"></div>
   <div class="side-head"><span>Postfächer &amp; Ordner</span><button class="btn small" id="selectAllInboxesBtn">Eingänge</button></div><div id="mailboxes"></div><div id="sidebarStatus" class="sidebar-status" role="status"></div>
  </aside>
  <div class="splitter" id="splitSidebar" role="separator" aria-label="Quellenbreite" tabindex="0"><button class="splitter-toggle" id="toggleSidebar" title="Quellen ein-/ausklappen">◀</button></div>
  <section class="list-pane" id="listPane">
   <div class="list-toolbar"><input type="checkbox" id="selectAllMessages" title="Sichtbare Nachrichten auswählen"><div class="list-title" id="listTitle">Alle Eingänge</div><div class="menu-wrap"><button class="btn small" id="columnsBtn">Spalten ▾</button><div class="menu" id="columnsMenu"></div></div></div>
   <div class="selection-toolbar" id="selectionToolbar"><div class="selection-label" id="selectionLabel"></div><button class="btn small" id="exportSelected">EML exportieren</button><button class="btn small" disabled title="Read-only IMAP-Mirror">Verschieben</button><button class="btn small danger" disabled title="Read-only IMAP-Mirror">Löschen</button></div>
   <div class="chips" id="filterChips"></div>
   <div class="message-table-wrap" id="messageTableWrap"><table id="messageTable"><colgroup id="tableCols"></colgroup><thead><tr id="tableHead"></tr></thead><tbody id="messageBody"></tbody></table></div>
   <div class="list-status"><span id="messageCount" role="status"></span><button class="btn small" id="loadMoreBtn" hidden>Weitere laden</button><span id="sortStatus"></span></div>
  </section>
  <div class="splitter" id="splitPreview" role="separator" aria-label="Listenbreite" tabindex="0"><button class="splitter-toggle" id="toggleListPane" title="Nachrichtenliste ein-/ausklappen">◀</button></div>
  <section class="preview" id="preview"><div class="preview-toolbar" id="previewToolbar"></div><div class="preview-scroll" id="previewContent"></div></section>
 </main>
 <section class="settings-page" id="settingsPage"><aside class="settings-nav">
  <button class="btn small" id="backToMailBtn">← Zurück zum Postfach</button><h2>Einstellungen</h2>
  <button class="nav-button" data-settings-section="mailboxes">Postfächer</button><button class="nav-button" data-settings-section="sync">Synchronisation</button><button class="nav-button" data-settings-section="performance">Ansicht &amp; Performance</button><button class="nav-button" data-settings-section="capabilities">Funktionsumfang</button>
  <div class="settings-divider"></div><div class="settings-mailboxes-title">Konfigurierte Postfächer</div><div id="settingsMailboxNav"></div><button class="btn small" id="addMailboxBtn">＋ Postfach hinzufügen</button>
 </aside><main class="settings-main" id="settingsMain"></main></section>
</div>
<dialog id="advancedDialog"></dialog><dialog id="favoriteDialog"></dialog>
<dialog id="headersDialog"><div class="dialog-head"><span>Nachrichtenheader</span><button class="btn small" data-close="headersDialog">×</button></div><div class="dialog-body"><pre class="header-raw" id="headerRaw"></pre></div></dialog>
<dialog id="confirmDialog"><div class="dialog-head" id="confirmTitle"></div><div class="dialog-body" id="confirmText"></div><div class="dialog-foot"><button class="btn" id="confirmCancel">Abbrechen</button><button class="btn danger" id="confirmAccept">Bestätigen</button></div></dialog>
<div id="syncBanner" class="sync-banner" hidden role="status"></div><div class="toast-container" id="toasts" role="status"></div>
<script type="module" src="./assets/js/webmail.js?v=<?= $version ?>"></script></body></html>
