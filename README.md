# foresight

foresight ist ein selbst hostbarer Webmail-Client auf PHP-Basis. Ein serverseitiger IMAP-Client synchronisiert Nachrichten mit externen Mailservern und speichert sie lokal in Dateien. Eine eigene JSON-API stellt diese lokalen Daten für die Web-Oberfläche bereit.

Vom Bediengefühl her liegt die Anwendung zwischen Thunderbird und klassischem Outlook (Outlook Classic), als Web-Anwendung im Browser, mit Konto-/Ordnernavigation, anpassbarer Mailtabelle und Nachrichtenvorschau.

Die Oberfläche verwendet echte Benutzer, Mailaccounts, Ordner und lokal synchronisierte EML-Dateien. Es gibt keinen produktiven Demo-Datenpfad. Der aktuelle Funktionsumfang ist ein read-only Mailclient: Nachrichten synchronisieren, durchsuchen und lesen; Versand und Änderungen auf dem IMAP-Server sind noch nicht implementiert.

Die genannten Mailclients dienen als Orientierung für das Bedienkonzept; foresight ist ein eigenständiges Projekt.

## AI Disclaimer

foresight wird mit Unterstützung generativer KI, insbesondere ChatGPT/Codex, entwickelt. KI wird für Code, Dokumentation und technische Vorschläge eingesetzt; die Ergebnisse werden im Entwicklungsprozess geprüft, angepasst und integriert. Projektidee, Anforderungen und die Entscheidungen über Gestaltung, Auswahl und Zusammenstellung der Beiträge liegen bei grayoctagon.

Dieser Hinweis ist kein Verzicht auf bestehende Urheberrechte. Copyright- und Lizenzangaben beziehen sich auf die eigenen, rechtlich geschützten Beiträge und die schutzfähige Gestaltung bzw. Zusammenstellung des Projekts, soweit entsprechende Rechte bestehen. Ein pauschaler Urheberrechtsschutz für rein KI-generierte Bestandteile wird damit nicht behauptet. Siehe auch die [Einordnung von Creative Commons zu generativer KI und eigenen kreativen Beiträgen](https://creativecommons.org/2023/08/18/understanding-cc-licenses-and-generative-ai/).

Trotz Prüfungen und Tests kann die Anwendung Fehler oder Sicherheitslücken enthalten. Vor dem Einsatz mit echten Zugangsdaten und vertraulichen Nachrichten sollten Konfiguration, Sicherheitsgrenzen und Backups geprüft werden. Es gelten die Gewährleistungs- und Haftungsregelungen der [Lizenz](LICENSE.txt).

## TODO - geplante Funktionen

foresight soll künftig eine starke, direkt in den Mailclient integrierte KI-Unterstützung erhalten. Diese geplanten Produktfunktionen sind von der KI-Unterstützung bei der Entwicklung im obigen Disclaimer zu unterscheiden: In der laufenden Anwendung gibt es derzeit noch keine KI-Integration.

Aktueller Stand ist ein read-only Mailclient: Nachrichten können synchronisiert, mit klassischen Filtern durchsucht und gelesen werden. Folgende Funktionen fehlen noch und sind geplant:

- [ ] **Mails senden:** neue Nachrichten verfassen sowie auf Mails antworten und sie weiterleiten; die vorhandene SMTP- und Identitätskonfiguration dafür nutzen.
- [ ] **Mails zusammenfassen:** KI-generierte Zusammenfassungen einzelner Nachrichten und längerer Mail-Unterhaltungen.
- [ ] **AI-Suche:** Mails anhand von Bedeutung, Themen und natürlichsprachlichen Suchanfragen finden, über die bestehende Header-/Snippet-Suche hinaus.
- [ ] **AI-Chat:** Fragen zu Themen aus den eigenen Mails beantworten und die zugrunde liegenden Nachrichten als Quellen nachvollziehbar machen.
- [ ] **Kontaktübersicht:** Personen und Mailadressen aus der Korrespondenz übersichtlich zusammenführen und zugehörige Nachrichten zugänglich machen.
- [ ] **Themenübersicht:** zusammengehörige Themen und Unterhaltungen über einzelne Nachrichten hinweg sichtbar machen.

Diese Funktionen sind noch nicht implementiert. Bei ihrer Umsetzung sollen die bestehende Benutzertrennung und die Kontrolle über die Verarbeitung vertraulicher Maildaten erhalten bleiben. Technische Aufgaben und weitere offene Punkte werden in [TODO.md](TODO.md) gepflegt.

## Architektur und Datenfluss

`IMAP-Server → PHP-IMAP-Client / schrittweiser Sync → lokale Locations/EMLs → eigene JSON-API → Web-Oberfläche`

`src/Imap` und `src/Sync` bilden den serverseitigen IMAP-Client mit Synchronisation; `src/Storage` übernimmt die lokale Speicherung. Zusammen mit Authentifizierung, Accountverwaltung und API bilden sie das PHP-Backend für den Browser, nicht einen eigenen IMAP-Server. `src/Webmail` ergänzt DTOs, einen rekonstruierbaren Suchindex, MIME-Lesen und sicheres HTML. Beim Öffnen eines Ordners, einer Mail oder eines Anhangs findet kein IMAP-Zugriff statt.

- Ein foresight-Benutzer besitzt beliebig viele eigene Mailaccounts.
- Ein Account enthält Ordner und deren UID-/UIDVALIDITY-basierte Locations.
- SHA-256 benennt bytegetreue EML-Objekte; identische Inhalte sind innerhalb eines Accounts dedupliziert.
- Der Browser verwendet stabile Nachrichten-IDs der API. Diese repräsentieren eine Location und ändern sich bei UIDVALIDITY-Wechsel; er interpretiert keine IMAP- oder Dateipfade.
- Einstellungen und Favoriten liegen benutzerbezogen auf dem Server. Mailinhalte und Passwörter werden nicht in localStorage gespeichert.

## Verzeichnisstruktur

`public/` ist die einzige öffentliche Grenze. Der restliche Projektordner bleibt außerhalb des DocumentRoot.

```text
foresight/                              Projektverzeichnis
  public/
    index.php, api.php                  dünne öffentliche Einstiegspunkte
    _bootstrap.php                     zentrale Verbindung zum privaten Projekt
    .htaccess                          zusätzlicher Apache-Schutz
    assets/css/, assets/js/            Frontend-Quellen, keine Deployment-Kopien
  templates/webmail.php                Oberfläche mit relativen Asset-URLs
  src/Auth/, Config/, Imap/, Sync/, Storage/, Webmail/
  src/Http/api.php                     vorhandener API-Dispatcher
  src/Http/PublicPath.php              automatische Erkennung des URL-Unterordners
  bootstrap.php                       Initialisierung der bestehenden Services
  config/deployment.example.php        versionierte Vorlage
  config/deployment.example.json       alternative JSON-Vorlage
  config/deployment.php                lokale Konfiguration, nicht im Git
  bin/foresight, bin/mailmirror        Sync-CLI und kompatibler bisheriger Einstieg
  bin/link-public                     lokaler Symlink, kein Kopieren der Assets
  docs/deployment.md                   Installation, Git und Hosting
  tests/                              Backend-, API-, Pfad- und Browserprüfungen
  composer.json, composer.lock
  vendor/                             Composer-Abhängigkeiten, nicht im Git
  README.md, TODO.md, LICENSE.txt, .gitignore

foresight-data/                        außerhalb des Repository und Webroots
  mailstore/users/{user}/
    user.json                         Login-Hash
    webmail-settings.json             Layout, Spalten, Favoriten, Seitengröße
    accounts/{account}/
      config.json                     IMAP/SMTP/Identitäten ohne Passwörter
      runtime/, sync/jobs/, logs/
      folders/{folder}/
        state.json, locations.ndjson  autoritativer lokaler Ordnerstand
        webmail.ndjson                rekonstruierbare Summaries
        webmail-index.json            Quellgeneration
        webmail-building.ndjson,
        webmail-build.json            fortsetzbarer Indexaufbau
      objects/{prefix}/{prefix}/
        {sha256}.eml                  unveränderte Nachricht
        {sha256}.json                 Mirror-Metadaten
        {sha256}.webmail.json         rekonstruierbare MIME-Summary
    archive/accounts/                 wiederherstellbar archivierte Accounts
  secrets/{user}/{account}/{ref}.json  geschützte Passwörter
```

In `public/_bootstrap.php` bestimmt genau eine Zeile den relativen Pfad zum privaten Projekt:

```php
$projectRoot = __DIR__ . '/..';
```

In der Standardstruktur ist keine Anpassung nötig. `__DIR__` bezieht sich auf die PHP-Datei, auch beim Aufruf durch einen Symlink. Dateisystempfade aus der Deployment-Konfiguration werden relativ zum Projektverzeichnis aufgelöst; absolute Bestandskonfigurationen bleiben unterstützt.

Die Oberfläche referenziert CSS/JS relativ mit `./assets/...`. Die API-Adresse wird aus dem Standort des JavaScript-Moduls relativ aufgelöst. Cookie-Pfade und der interne URL-Präfix stammen automatisch aus dem öffentlichen PHP-Einstieg (`SCRIPT_NAME`), nicht aus dem privaten Dateipfad. Es gibt keinen einzustellenden `base_path`; ein früherer Wert wird nicht mehr verwendet. `/`, `/mail/`, `/foresight-mail/` und verschachtelte Unterordner verwenden denselben Code. Verzeichnisaufrufe ohne abschließenden Schrägstrich normalisiert der Webserver.

Für die lokale Entwicklung kann ein Symlink im Webroot direkt auf `public/` zeigen. Mailstore und Secrets bleiben in separat konfigurierten privaten Verzeichnissen. Die [Installationsanleitung](docs/deployment.md) beschreibt beide Varianten: ein gemeinsames Projektverzeichnis mit öffentlichem `public/` und ein separat platziertes öffentliches Verzeichnis.

Anwendungscode, Composer-Lockdatei, Vorlagen, Tests und Dokumentation gehören ins Git. Die `.gitignore` schließt `config/deployment.php`, `config/deployment.json`, `vendor/` und Laufzeit-/Secret-Verzeichnisse aus. Hinweise zur Versionierung stehen in der [Hosting- und Entwicklungsanleitung](docs/deployment.md#6-git).

## Oberfläche

Die Oberfläche bietet eine klassische Drei-Bereich-Ansicht in Blau-/Grautönen: Konten und Ordner links, eine anpassbare Mailtabelle in der Mitte und die Nachrichtenvorschau daneben. Die Bereiche lassen sich in der Größe verändern. Einstellungen, Spaltenauswahl und Suchfilter sind direkt in die Webmail-Oberfläche integriert. Ordner lassen sich einzeln oder zusammen auswählen. Suchfilter und Ordnerauswahl können als Favorit gespeichert werden. Favoriten lassen sich umbenennen oder löschen.

Die Tabellenbreite ergibt sich aus den eingestellten Spaltenbreiten. Lange Absender, Betreffzeilen und Snippets vergrößern die Nachrichtenspalte nicht; beim Verkleinern werden sie mit Ellipsis abgeschnitten. Das gilt auch bei ausgeblendeten Nachbarspalten. Beim Überfahren eines Snippets mit der Maus zeigt ein nativer Tooltip den vollständigen Snippet-Text.

Auch Spaltentitel werden bei schmalen Spalten mit Ellipsis gekürzt und sind im Tooltip vollständig lesbar. Der Sortierindikator bleibt innerhalb des Tabellenkopfs; die Größenänderungsgriffe bleiben unabhängig davon bedienbar.

Über „Spalten“ können zusätzlich „Größe“, „Von“, „An“ und „Cc“ eingeblendet werden. Die Größe verwendet die größte passende Einheit in 1024er-Schritten (B bis PB), höchstens zwei Nachkommastellen und keine unnötigen Endnullen, z. B. `1.2MB` oder `1.34KB`. Ihr Tooltip zeigt den exakten Bytewert; die Sortierung erfolgt serverseitig numerisch nach Bytes, auch über mehrere Seiten. Adressspalten zeigen Namen und Mailadressen sowie sämtliche An-/Cc-Empfänger; lange Werte sind per Tooltip vollständig lesbar. Spaltenauswahl und Breiten bleiben benutzerbezogen gespeichert. Bestehende Ansichten werden nicht automatisch um die neuen Spalten erweitert.

Die Spaltenreihenfolge lässt sich direkt durch Ziehen der Tabellenköpfe oder der Einträge im Spaltenmenü ändern. Eine Einfügemarkierung zeigt die Zielposition. Im Menü stehen zusätzlich ↑-/↓-Buttons zur Verfügung, auch per Tastatur bedienbar. Ausgeblendete Spalten behalten ihre Position beim Wiedereinblenden; Breiten und Nachrichtenauswahl bleiben erhalten. Die Auswahl-Checkbox-Spalte bleibt ganz links, „Nachricht“ ist verschiebbar, aber nicht ausblendbar. Ein normaler Klick auf einen sortierbaren Kopf sortiert weiterhin; die Randgriffe ändern ausschließlich die Breite.

Die Einstellung `columnOrder` enthält die Reihenfolge aller Spalten, getrennt von der Sichtbarkeit in `columns`. Sie wird pro Benutzer über `/webmail/settings` gespeichert. Ältere Einstellungen erhalten die Standardreihenfolge; fehlende bzw. künftig neu eingeführte Spalten werden hinten ergänzt. Unbekannte oder doppelte IDs und ungültige Strukturen werden zurückgewiesen.

Die Liste lädt 10-200 Summaries pro Seite (Standard 100), lädt beim Scrollen nach und rendert höchstens 110 Nachrichtenzeilen gleichzeitig. Sortierung und Filterung erfolgen serverseitig vor der Pagination, auch über mehrere Ordner. Die Suche umfasst dekodierte Absender, Empfänger, Betreff und Vorschautext; sie ist **keine vollständige Body-Volltextsuche**. Datums-, Prioritäts-, Ungelesen-, Markiert-, Anhang- und Unbeantwortet-Filter sind verfügbar. Nachrichten können mit Pfeiltasten geöffnet werden; `/` fokussiert die Suche.

Nachrichtendetails, Body, Anhänge und Raw-EML haben getrennte Abrufe. HTML und Plaintext können umgeschaltet werden. Pending-Nachrichten werden als noch nicht lokal verfügbar angezeigt. Kleinere Bildschirme wechseln zwischen Liste und Vorschau.

Postfacheinstellungen integrieren IMAP, vorbereitetes SMTP, Send-as-/Alias-Identitäten, Envelope-From, Reply-To, Signaturen/Signatur-Platzierung und Sync-Limits. Leere Passwortfelder erhalten vorhandene Credentials; fehlende Credentials werden ausdrücklich angezeigt. Ein neuer Account wird erst nach erfolgreichem Credential-Speichern veröffentlicht. Ungültige Einstellungen verändern keine Passwörter.

## API

Die öffentliche API ist relativ zum jeweiligen Anwendungseinstieg `api.php` mit PATH_INFO, z. B. `api.php/webmail/messages`. Ohne PATH_INFO ist `api.php?route=/webmail/messages` möglich.

Antwortformat:

```json
{"ok":true,"data":{"messages":[]},"error":null}
```

Fehler enthalten `ok:false`, `data:null` und `error:{code,message}`. Bestehende Routen behalten zusätzlich ihre alten Top-Level-Felder für bisherige API-Clients. Neue `/webmail`-Routen verwenden ausschließlich die Hülle.

| Methode/Ressource | Inhalt |
| --- | --- |
| GET `/session` | Session, Benutzer, CSRF-Token, Registrierung erlaubt |
| POST `/register`, `/login`, `/logout` | bestehende Authentifizierung |
| GET/POST `/accounts` | Accountliste / Account anlegen |
| GET/PUT/DELETE `/accounts/{account}` | Konfiguration / ändern / wiederherstellbar archivieren |
| GET `/webmail/accounts/{account}/folders` | lokale Ordner mit Gesamt-, Ungelesen- und Pending-Zählern |
| GET `/webmail/messages` | paginierte, gefilterte Summary-Liste |
| GET `/webmail/accounts/{account}/messages/{id}` | Details, Header und Anhangbeschreibungen, noch ohne Body |
| GET `.../messages/{id}/body` | Plaintext und serverseitig bereinigtes HTML |
| GET `.../messages/{id}/body?external=1` | HTML mit ausdrücklich freigegebenen externen Bildern |
| GET `.../messages/{id}/raw` | Original-EML als Download |
| GET `.../messages/{id}/attachments` | Anhangbeschreibungen |
| GET `.../messages/{id}/attachments/{attachment}` | dekodierter Anhang als Download |
| GET/PUT `/webmail/settings` | benutzerbezogene Anzeigeeinstellungen und Favoriten |
| GET `/webmail/capabilities` | tatsächlich unterstützte Funktionen |
| POST `/accounts/{account}/sync/start` | persistenten Job anlegen oder fortsetzen |
| POST `/accounts/{account}/sync/advance` | genau einen Sync-Arbeitsschritt ausführen |
| GET `/accounts/{account}/sync/status` | persistenter Job, Phase, Fortschritt, ETA, Fehler |
| GET `/accounts/{account}/mirror` | bisherige kompatible Ordner-/Location-Liste |
| GET `/accounts/{account}/objects/{hash}` | Original-EML nach Content-Hash als Download |

Listenparameter: `folders=account~folder,account~folder` (max. 128 Ordner), `limit` (1-200 auf API-Ebene), `cursor`, `sort`, `direction`. Sortierfelder: `date`, `subject`, `from`, `bytes`, `priority`, `accountId`, `hasAttachments`. Suchfilter: `q`, `from`, `to`, `subject`, `dateFrom`, `dateTo`, `priority`, `unread`, `flagged`, `attachment`, `unanswered`.

Eine Summary enthält `id`, `accountId`, `folderId`, `subject`, `from:{name,email}`, `to`, `cc`, `date`, `snippet`, `flags`, `hasAttachments`, `attachmentCount`, `bytes` und `contentState`. Antworten liefern `total` und `nextCursor`. Cursor gelten für die jeweilige Filter-/Sortierauswahl und Indexgeneration. Ändert ein Sync die Quellen, liefert ein alter Cursor HTTP 409; die UI lädt die Liste neu.

Beim ersten Abruf kann `indexing` eine Liste laufender lokaler Indexaufbauten enthalten. Die UI wiederholt den GET schrittweise. Es werden keine unvollständig sortierten Teilresultate als fertige Liste ausgegeben.

HTTP-Status: 200/201 Erfolg, 401 fehlende Anmeldung/ungültiges CSRF-Token, 404 nicht gefunden oder fremde Ressource, 405 nicht unterstützte Schreibmethode unter `/webmail`, 409 geänderte Indexgeneration oder laufender lokaler Ordner-Commit, 422 ungültige Eingaben, 500 Server-/Speicherfehler. Ältere Routen können bei einer nicht unterstützten Methode 404 statt 405 liefern. Authentifizierung und CSRF-Prüfung finden gegebenenfalls vor der Methodenauswertung statt.

## Lokaler Suchindex und Performance

Der abgeleitete Ordnerindex wird bei Änderungen an `locations.ndjson` oder `state.json` erneuert. Ein Aufbau verarbeitet höchstens 500 Nachrichten bzw. ungefähr zwei Sekunden pro Aufruf (weiches Zeitbudget; eine einzelne große MIME-Datei kann länger dauern). Ein separates Lock verhindert parallele Indexschreiber. Abgebrochene Teilblöcke werden beim Fortsetzen auf die zuletzt committed Länge zurückgesetzt.

Leser prüfen vor und nach der Verarbeitung die Quellgeneration. Während eines offenen Ordner-Commits liefern sie HTTP 409 statt gemischter UID-/UIDVALIDITY-Daten; die Nachrichtenliste lädt verzögert neu. Unterbrochene lokale Commits werden mit der vorhandenen Recovery-Funktion unter dem Sync-Lock wiederhergestellt, ohne IMAP-Verbindung.

Die Seitenauswahl streamt den Summary-Index und hält höchstens `limit` Treffer im Sortier-Heap. Speicherbedarf für die Seitenauswahl ist damit unabhängig von der Ordnergröße; die Laufzeit einer Suche/Seite ist linear in der Zahl der Summaries. Es werden dabei keine EML-Bodies gelesen. Der erstmalige Aufbau erstellt je unveränderlichem Content-Hash eine MIME-Summary.

Getestet: 50.000 Locations, korrekte Pagination ohne Duplikate, Unicode-Suche und kombinierte Ordner. Auf dem lokalen Testsystem lag eine warme Seite mit 100 Summaries bei ca. 0,5 Sekunden / 57 KB. Das ist ein Messwert, keine Hosting-Garantie.

Body-Vorschauen sind auf 2 MiB pro Text-/HTML-Teil begrenzt. CID-Rasterbilder erhalten ein separates Gesamtbudget von 2 MiB und maximal 512 KiB pro Bild. Größere Inhalte bleiben vollständig über den EML-Download verfügbar. Anhänge und Raw-EML werden als Stream ausgegeben.

## Synchronisation und CLI

Die bestehende read-only Architektur bleibt erhalten: `BODY.PEEK[]`, UID/UIDVALIDITY, Flags, INTERNALDATE, MODSEQ, EMAILID/MAILBOXID, Deduplizierung und Recovery. Es wird kein `ext-imap` verwendet.

Phasen: `discover_mailboxes` → `index_mailboxes` → `fetch_content` → `finalize`. Die UI startet über ↻ oder die Sync-Einstellungen und ruft `advance` strikt sequenziell auf. Pause wirkt nach Abschluss des laufenden Schritts. Schließen des Browsers beendet die Aufrufkette, nicht den gespeicherten Job. Netzwerkfehler werden angezeigt und müssen bewusst fortgesetzt werden.

```bash
php bin/foresight sync-start <account-id>
php bin/foresight sync-step <account-id>
php bin/foresight sync-status <account-id>
php bin/foresight show-account <account-id>
php bin/foresight rebuild-metadata <account-id> <sha256>
```

Der frühere CLI-Einstieg `bin/mailmirror` bleibt als Weiterleitung erhalten. Für neue Konfigurationen gelten `FORESIGHT_DEPLOYMENT_FILE` und `FORESIGHT_MEMORY_LIMIT`; bisherige `MAILMIRROR_*`-Variablen werden weiterhin als Fallback akzeptiert.

Cron ist optional; es gibt keinen eingebauten Scheduler und keinen erforderlichen dauerhaften Worker. Für wiederkehrende Synchronisationsläufe muss die Aufrufkette `sync-start` zum Anlegen bzw. Fortsetzen eines Jobs und anschließend wiederholt `sync-step` verwenden. Jeder Schritt erledigt nur eine Arbeitseinheit; `sync-status` zeigt Fortschritt und Fehler. Nach einem abgeschlossenen oder fehlgeschlagenen Job legt erst ein erneutes `sync-start` einen neuen Job an. Allein wiederholtes `sync-step` startet nach einem abgeschlossenen Job keinen weiteren Lauf. Bei Fehlern muss die Aufrufkette anhalten bzw. nach einer bewussten Retry-Regel fortsetzen; sie darf nicht unbegrenzt eng weiterlaufen.

Account-Locks verhindern parallele Sync-Schreibarbeit. Lock- und Runtime-Dateien müssen für PHP und ggf. den CLI-Benutzer zugänglich sein. Die CLI-Beispiele sind aus dem Projektverzeichnis auszuführen; Cron sollte dieses Arbeitsverzeichnis ausdrücklich setzen oder den CLI-Einstieg über einen absoluten Dateipfad aufrufen.

## Installation und Managed PHP Hosting

Der aktuelle Composer-Lockstand benötigt **PHP 8.3 oder neuer**, Sessions, JSON, OpenSSL/TLS und übliche PHP-Dateifunktionen. Es gibt keine Pflicht zu Node.js, SQL, Redis oder Docker.

1. Repository klonen beziehungsweise das Projekt hochladen und die Abhängigkeiten mit `composer install --no-dev --prefer-dist --optimize-autoloader` installieren. Ohne Composer auf dem Server kann `vendor/` lokal erstellt und mit hochgeladen werden.
2. `config/deployment.example.php` nach `config/deployment.php` kopieren. Sessionname, Registrierung und Datenpfade konfigurieren. Beispielsweise `../foresight-data/mailstore` und `../foresight-data/secrets`; alle relativen Pfade beginnen am Projektverzeichnis. Alternativ eine JSON-Konfiguration über `FORESIGHT_DEPLOYMENT_FILE` angeben, ebenfalls absolut oder relativ zum Projekt. Lokale Konfigurationen werden nicht versioniert.
3. Ausschließlich `public/` als DocumentRoot der Domain/Subdomain einstellen. Die Domain darf direkt auf dieses Verzeichnis zeigen oder lokal durch einen Symlink darauf zugreifen. Der restliche Projektordner und alle Maildaten bleiben privat.
4. PHP benötigt Lesezugriff auf Projekt/Abhängigkeiten und Schreibzugriff auf Mailstore/Secrets. Bei Hosting mit `open_basedir` müssen diese Verzeichnisse zugelassen sein. Keine pauschalen 0777-Rechte setzen.
5. HTTPS aktivieren, ersten foresight-Benutzer anlegen und die Registrierung danach nach Bedarf deaktivieren. Postfach konfigurieren und Sync starten.

Lokaler Start ohne Apache:

```bash
php -S 127.0.0.1:8080 -t public
```

Danach im Browser [http://127.0.0.1:8080/](http://127.0.0.1:8080/) öffnen. Die URL ist ein Beispiel für diesen lokalen Entwicklungsserver, keine feste Installationsadresse.

Lokaler Apache-Symlink, beispielsweise unter einem beliebigen Namen:

```bash
php bin/link-public /var/www/html/mail
```

Der Befehl überschreibt keine vorhandenen Verzeichnisse oder fremden Symlinks. Änderungen in `public/` sind sofort sichtbar; ein Deployment-Kopierschritt entfällt. Apache muss Symlinks und den Zugriff auf das Ziel erlauben. Die enthaltene `.htaccess` ergänzt den Schutz, ersetzt aber nicht die korrekte DocumentRoot-Einstellung.

Bei getrennt platziertem öffentlichen Verzeichnis genügt die Anpassung der Zeile `$projectRoot = __DIR__ . '/..';` in `public/_bootstrap.php` an den passenden relativen Projektpfad. Der Name oder URL-Unterordner der Installation muss dabei nicht konfiguriert werden.

Details, Git-Ablauf und Hosting-Beispiele: [docs/deployment.md](docs/deployment.md).

Backups umfassen lokale Konfiguration, Mailstore und Secrets getrennt vom Git-Repository. Code-Updates sollen den persistenten Datenbestand nicht ersetzen.

## Sicherheitskonzept

- Serverseitige Ownership-Prüfung vor Account-, Ordner-, Message-, Body-, Anhang- und Raw-Zugriff. Fremde Ressourcen liefern 404; clientseitige User-IDs werden nicht akzeptiert.
- Sessions verwenden ID-Regeneration beim Login, Strict Mode, HttpOnly und SameSite=Strict; Secure-Cookies unter HTTPS.
- Authentifizierte Schreibzugriffe einschließlich Logout benötigen `X-CSRF-Token`. Login und Registrierung sind vor der Anmeldung ohne CSRF-Token möglich. Für schreibende Requests werden zusätzlich JSON-Content-Type, die Host-/Port-Übereinstimmung eines vorhandenen Origin-Headers und die Requestgröße geprüft; ein Origin-Header ist nicht zwingend erforderlich.
- Technische IDs werden validiert; Remote-Mailboxnamen oder Attachmentnamen bestimmen keine lokalen Pfade.
- Account-Konfiguration und API-Antworten enthalten keine IMAP-/SMTP-Passwörter. Neue Passwortwerte werden nur zum Speichern übertragen; die API liefert nur Credential-Status.
- Keine Bodies, EMLs oder Credentials in regulären App-Logs. Externe Bibliotheksfehler werden für Sync/API auf sichere Meldungen reduziert.
- Mail-HTML wird mit [HTML Purifier](https://github.com/ezyang/htmlpurifier) und einer engen Allowlist bereinigt; Skripte, Eventhandler, Formulare, SVG, Frames und gefährliche URLs werden entfernt. Die konkrete HTML-/CSS-/URI-Konfiguration einschließlich Ressourcenfilter steht in [src/Webmail/HtmlSanitizer.php](src/Webmail/HtmlSanitizer.php).
- Darstellung ausschließlich im sandboxed iframe ohne Scripts und ohne same-origin-Freigabe, mit zusätzlicher CSP und No-Referrer. Externe Bilder bleiben blockiert, bis der Benutzer sie pro Nachricht erlaubt. Die Freigabe kann Tracking beim Bildhost auslösen.
- CID-Inhalte werden nur als überprüfte PNG/JPEG/GIF/WebP-Bytes eingebettet. Ohne externe Freigabe werden andere Bild-URLs entfernt. Nach der Freigabe sind HTTP(S)-Bildadressen erlaubt, auch solche zu lokalen oder privaten Netzwerkzielen. Ein eigener Host-/Netzwerkfilter oder Bildproxy ist nicht implementiert; der Browser kann solche Adressen im Rahmen seiner eigenen Sicherheitsregeln anfragen.
- Anhänge werden unabhängig vom behaupteten MIME-Typ als `application/octet-stream` mit Attachment-Disposition, bereinigtem Namen, NoSniff und Sandbox ausgeliefert.
- UI-Ausgaben werden escaped. Mails und Credentials gelangen nicht in Browser-Storage; Benutzerwechsel verwirft laufende Mailabrufe und Inhalte.
- Wartende Präferenzänderungen und verspätete Fehlerantworten sind an die ursprüngliche Sitzung gebunden und können die nachfolgende Sitzung nicht verändern.

Der Secret Store enthält entsprechend der bestehenden V1 Klartext-Passwörter mit restriktiven Dateirechten, keine verschlüsselte Vault-Lösung. Backups sind entsprechend zu schützen.

## Tests

```bash
php tests/run.php
php tests/imapengine-integration.php
php tests/deployment.php
php tests/webmail.php
php tests/webmail.php --mount=/mail
php tests/webmail.php --mount=/foresight-mail
php tests/webmail.php --mount=/nested/other
```

Die bestehenden Suites prüfen 43 Mirror-/Storage-Fälle und sieben ImapEngine-Protokollfälle, einschließlich Recovery, Locks, 50.000 Locations und bytegetreuen 100-MB-EMLs. `tests/deployment.php` ergänzt 26 Prüfungen zu relativen Datenpfaden, Konfigurations-Overrides, Schutz gegen Speicherung in öffentlichen Verzeichnissen oder über öffentliche Symlinks sowie URL-Erkennung unabhängig vom privaten Dateipfad. Die Webmail-Suite führt je URL-Mount 97 Prüfungen aus; die oben aufgeführten sieben Aufrufe ergeben zusammen 464 Prüfungen. Der ImapEngine-Protokolltest benötigt die CLI-Erweiterung `pcntl` und wird ohne sie ausdrücklich übersprungen; die HTTP-Suite benötigt `proc_open`, lokale TCP-Sockets und Symlinks.

`tests/webmail.php` startet einen eigenen lokalen PHP-Server, dessen DocumentRoot ausschließlich öffentliche Dateien beziehungsweise Symlinks darauf enthält. Mit `--mount` läuft dieselbe API-Suite unter einem frei gewählten URL-Unterordner. Sie prüft echte Sessions, Cookie-Scoping, relative Assets, Query-Routing, gesperrte private Dateien, Ownership, Cursor-Pagination, MIME/Anhänge/EML, Sync-Status/-Fehler und adversariale HTML-Sanitization. Die Daten sind synthetisch; echte IMAP-Konten werden nicht angesprochen.

Optionale Browser-QA verwendet die [Playwright Library](https://playwright.dev/docs/library), nicht den Playwright-Test-Runner. Node.js und npm werden ausschließlich auf dem Entwicklungsrechner benötigt. Verwende eine von Playwright unterstützte Node-Version; erforderliche Linux-Systembibliotheken und Browserinstallation beschreibt die [Playwright-Browserdokumentation](https://playwright.dev/docs/browsers).

Zuerst in einem Terminal aus dem Projektverzeichnis die isolierte PHP-Testumgebung starten und laufen lassen:

```bash
php tests/webmail.php --mount=/mail --serve
```

Die Ausgabe enthält `BROWSER_QA_URL` mit der vollständigen lokalen Test-URL einschließlich Port und Unterordner. Die Umgebung enthält ausschließlich synthetische Konten und Nachrichten. Die vollständige Browser-Suite darf nicht gegen produktive Mailaccounts laufen.

In einem zweiten Terminal ebenfalls ins Projektverzeichnis wechseln. Das folgende Bash-Beispiel installiert die optionalen Werkzeuge außerhalb des Repository, ohne `package.json`, Lockdateien oder `node_modules/` im Projekt anzulegen:

```bash
foresightQaDir="$(mktemp -d)"
npm install --prefix "$foresightQaDir" --no-save --package-lock=false playwright
export NODE_PATH="$foresightQaDir/node_modules"
"$foresightQaDir/node_modules/.bin/playwright" install chromium

# <port> durch den Port aus BROWSER_QA_URL ersetzen; die Anführungszeichen beibehalten.
node tests/browser.cjs 'http://127.0.0.1:<port>/mail/'
node tests/column-resize.cjs 'http://127.0.0.1:<port>/mail/'
```

`NODE_PATH` macht die separat installierte Bibliothek für die CommonJS-Testskripte auffindbar; die Variable gilt in diesem Terminal. Nach den Tests die PHP-Testumgebung mit Strg+C beenden. Bei einer späteren Testsitzung die Werkzeuge erneut einrichten oder den vorhandenen Werkzeugpfad wieder verwenden.

`WEBMAIL_SCREENSHOTS` legt den Ausgabeordner der Browser-Suite fest. Die Tests prüfen Login, HTML/Text, Header, Downloads, Einstellungen/Identität, Suche/Favoriten, Spaltenwahl und persistierte Reihenfolge, Drag-and-drop, Resizer, mobile Ansicht, Benutzerwechsel und verspätete Antworten. Es dürfen weder JavaScript-Fehler noch unerwünschte externe Abrufe auftreten. Die separate Spaltenprüfung verwendet ausschließlich synthetischen Browserzustand ohne Server-Schreibzugriffe.

## Bekannte Grenzen

- Read-only IMAP-Mirror: kein SMTP-Versand, keine IMAP-Schreibaktionen (Seen/Flagged, Move/Delete), keine Serverentwürfe.
- SMTP-/Identitäts-/Signaturkonfiguration wird gespeichert, aber noch nicht für Versand genutzt.
- KI-Zusammenfassungen, AI-Suche, AI-Chat, AI-Bewertungen, Fristen und Spam-/Phishing-Meldedienste sind nicht implementiert. Soweit zugehörige UI-Elemente vorhanden sind, bleiben ihre Werte leer oder Controls deaktiviert; es werden keine Mock-Ergebnisse erzeugt.
- Eigenständige Kontakt- und Themenübersichten sind geplant, aber noch nicht vorhanden.
- Suche umfasst Header/Snippet, nicht vollständige Bodies. Kein Suchindex mit SQL oder externer Suchmaschine.
- Kein Live-Push/IDLE: neue Mails erscheinen nach einem ausdrücklich gestarteten Sync oder Cron-Lauf.
- Keine automatische Garbage Collection von EML-Objekten; referenzlose historische Inhalte bleiben erhalten. Abgeleitete Webmail-Indizes können bei angehaltenen Zugriffen entfernt und wieder aufgebaut werden.
- Die bestehende ImapEngine-Verarbeitung hält einen vollständigen IMAP-Literal beim Download als PHP-String. Standard-Memory-Limit ist 512 MiB; das 100-MB-Ziel wird getestet. MIME-Lesezugriff arbeitet anschließend mit Datei-Streams.
- Die Sicherheitsgrenzen eines Servers gelten weiterhin: kompromittierter PHP-Prozess/Host kann den privaten Secret Store lesen.
- Die bestehende Authentifizierung bietet noch keine eingebaute Login-Drosselung oder MFA. Für öffentlich erreichbaren Betrieb Registrierung bewusst einschränken und vorgelagerten Zugriffsschutz/Rate-Limits konfigurieren.

Aktuelle Integrationsaufgaben und technische Folgearbeiten: [TODO.md](TODO.md).

## Lizenz

Copyright © 2026 [grayoctagon](https://github.com/grayoctagon/).

Die eigenen Projektbestandteile von foresight, einschließlich Code, Oberfläche und Dokumentation, soweit daran entsprechende Rechte bestehen, sind unter **Creative Commons Attribution-ShareAlike 4.0 International (CC-BY-SA 4.0)** lizenziert.

Du darfst sie auch kommerziell nutzen, teilen und bearbeiten. Dabei sind insbesondere eine angemessene Namensnennung, ein Hinweis auf die Lizenz und die Kennzeichnung von Änderungen erforderlich. Bei Weitergabe bearbeiteter Fassungen gelten die ShareAlike-Bedingungen. Als Quelle sind **foresight** und [grayoctagon](https://github.com/grayoctagon/) anzugeben; vorhandene Quellen- und Copyright-Hinweise bleiben erhalten.

Den vollständigen, maßgeblichen Lizenztext enthält [LICENSE.txt](LICENSE.txt). Eine deutschsprachige Zusammenfassung bietet [Creative Commons](https://creativecommons.org/licenses/by-sa/4.0/deed.de).

Fremdbibliotheken und andere Drittbestandteile, insbesondere die über Composer installierten Abhängigkeiten, behalten ihre jeweiligen Lizenzen und Copyright-Hinweise. Sie werden durch diese Projektlizenz nicht neu lizenziert; ihre Lizenzangaben stehen in den jeweiligen Paketen und in `composer.lock`.
