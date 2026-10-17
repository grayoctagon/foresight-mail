# foresight

- [x] Git-taugliche Projektstruktur mit öffentlicher `public/`-Grenze, zentraler relativer Pfadzeile, relativen Datenpfaden, automatischem URL-Unterordner und lokalem Public-Symlink einrichten.
- [x] Anwendung, Login, CLI und Dokumentation auf foresight umstellen.

- [x] Authentifizierung, Account-Konfiguration, Sync und lokale Stores analysiert.
- [x] Stabile Webmail-API für Accounts/Ordner, paginierte Suche, MIME-Details, Body, Anhänge und Raw-EML.
- [x] Abgeleiteten, schrittweise aufbaubaren lokalen Such-/Summary-Index für große Ordner ergänzen.
- [x] HTML mit gepflegter Allowlist-Bibliothek, isoliertem Frame und blockierten externen Ressourcen darstellen.
- [x] Drei-Bereich-Webmail-Oberfläche mit echten Sessions, Ordnern, Mails, Suche, Favoriten und Resizern anbinden.
- [x] Bestehende Account-, Identity-, SMTP- und Performance-Einstellungen in die Webmail-Oberfläche integrieren.
- [x] Bestehenden Sync sequenziell steuern und Fortschritt/Fehler anzeigen.
- [x] API-, Sicherheits-, Pagination-, MIME- und Browser-Regressionen testen; bestehende Tests um Deployment-/Pfad- und URL-Mount-Prüfungen ergänzen.
- [x] Öffentliche und private Dateien trennen und README-/TODO-/Security-Prüfung durchführen.

## Geplante Funktionsausbauten

Die bestehende read-only Leseintegration bildet die Grundlage für einen Mailclient mit starker KI-Integration. Die folgenden Erweiterungen sind geplant, aber noch nicht implementiert:

- [ ] Mailversand einschließlich neuer Nachrichten, Antworten und Weiterleiten auf Basis der vorhandenen SMTP-/Identitätskonfiguration ergänzen.
- [ ] KI-Zusammenfassungen für einzelne Nachrichten und längere Mail-Unterhaltungen ergänzen.
- [ ] AI-Suche nach Bedeutung, Themen und natürlichsprachlichen Suchanfragen ergänzen.
- [ ] AI-Chat für Fragen zu Themen aus den eigenen Mails mit nachvollziehbaren Nachrichtenquellen ergänzen.
- [ ] Kontaktübersicht mit Personen, Mailadressen und zugehöriger Korrespondenz ergänzen.
- [ ] Themenübersicht mit zusammengehörigen Nachrichten und Unterhaltungen ergänzen.

Bei der KI-Anbindung Benutzertrennung und Kontrolle über die Verarbeitung vertraulicher Maildaten erhalten. Jede Erweiterung benötigt ein eigenes Fehlermodell und passende Tests.

## Aktuelle Funktionsgrenzen

Die bestehende V1 ist ein read-only IMAP-Mirror. Versand, serverseitige Flagänderungen/Move/Delete, Entwürfe auf IMAP und KI-Funktionen besitzen noch kein Backend. Kontakt- und Themenübersichten fehlen ebenfalls. Nicht unterstützte Aktionen werden in der Oberfläche entsprechend gekennzeichnet; simulierte Ergebnisse entfallen. SMTP- und Send-as-Konfiguration bleiben vollständig bearbeitbar, werden aber noch nicht für den Versand genutzt.

## Integrationsentscheidungen

- Vorhandene Services und API-Routen bleiben erhalten; neue UI nutzt `/api.php/webmail/...` mit `{ok,data,error}`.
- Lokale EMLs/Locations bleiben die Quelle der Wahrheit; zusätzliche Indizes sind rekonstruierbar.
- Suche/Sortierung erfolgt vor Pagination auf dem Server, Bodies/Anhänge erst auf Abruf.
- Keine Datenbank, Node-Runtime oder dauerhaften Worker erforderlich; derzeit keine Anbindung an KI-Dienste. Die externe IMAP-Verbindung dient der Synchronisation.
- UI-Präferenzen/Favoriten werden benutzerbezogen serverseitig gespeichert; keine Mails/Passwörter in localStorage.

## Technische Folgearbeiten außerhalb der abgeschlossenen Leseintegration

- [ ] Öffentlichen Betrieb zusätzlich mit Login-Rate-Limits/Zugriffsschutz absichern; eingebaute Drosselung/MFA bei Bedarf ergänzen.
- [ ] Restriktiveren Schutz bei Freigabe externer Bilder evaluieren; derzeit gibt es keinen eigenen Host-/Netzwerkfilter gegen lokale oder private Bildziele.
- [ ] Bei deutlich größeren Beständen einen partitionierten Suchindex evaluieren; die aktuelle speicherschonende Seitenauswahl scannt den lokalen Summary-Index.
- [ ] Optional vollständige Body-Suche ergänzen; derzeit werden Header und Snippets durchsucht.
- [ ] IMAP-Schreibaktionen und serverseitige Entwürfe als eigene, separat getestete Erweiterungen umsetzen.
