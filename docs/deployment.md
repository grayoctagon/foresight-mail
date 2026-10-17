# foresight installieren und entwickeln

Das Projekt hat eine einzige öffentliche Grenze: `public/`. Domain-DocumentRoot oder lokaler Webroot-Symlink zeigen ausschließlich auf dieses Verzeichnis. `src/`, `templates/`, `config/`, `vendor/`, `.git/` und der Datenbestand sind nicht öffentlich.

## 1. Anwendung und Konfiguration

PHP 8.3 oder neuer wird benötigt. Composer installiert die im Lockfile festgelegten Abhängigkeiten:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
cp config/deployment.example.php config/deployment.php
```

Die zweite Zeile ist für eine Neuinstallation gedacht. Eine vorhandene lokale Konfiguration nicht überschreiben.

Die Vorlage enthält relative Datenpfade:

```php
'paths' => [
    'mailstore_root' => '../foresight-data/mailstore',
    'secret_store_root' => '../foresight-data/secrets',
],
```

Diese Pfade sind immer relativ zum **Projektverzeichnis**, unabhängig vom Arbeitsverzeichnis des PHP-Prozesses und vom Standort der Konfigurationsdatei. Absolute Datenpfade bleiben unterstützt. Das Projekt löst Symlinks und `..` auf und verweigert Mailstore/Secrets im Projekt oder im DocumentRoot.

Alternativ kann `FORESIGHT_DEPLOYMENT_FILE` auf eine JSON-Datei zeigen. Auch dieser Dateipfad darf relativ zum Projekt sein. Die vorhandene Variable `MAILMIRROR_DEPLOYMENT_FILE` bleibt als Fallback kompatibel.

Der Sessionname und `allow_registration` sind konfigurierbar. Ein `base_path` muss nicht gesetzt werden; frühere Werte werden ignoriert.

## 2. Zentrale Verbindung vom öffentlichen zum privaten Teil

In `public/_bootstrap.php` gibt es genau eine anpassbare Pfadzeile:

```php
$projectRoot = __DIR__ . '/..';
```

`public/index.php` und `public/api.php` laden beide diese Datei. In der Repository-Struktur ist die Voreinstellung bereits richtig. Wird ausschließlich `public/` separat platziert, beispielsweise im öffentlichen Ordner eines Hosting-Kontos, wird nur diese Zeile passend relativ geändert.

PHP-Dateipfade werden am Dateistandort verankert. Die öffentliche URL folgt dagegen dem Namen, unter dem die Anwendung vom Webserver erreichbar ist. Ein Symlink auf `public/` beeinflusst deshalb nicht die Auflösung privater Dateien.

## 3. Entwicklung ohne Kopieren

Mit dem PHP-Entwicklungsserver:

```bash
php -S 127.0.0.1:8080 -t public
```

Mit einem lokalen Apache, aus dem Projektverzeichnis, beispielsweise:

```bash
php bin/link-public /var/www/html/foresight
```

Der Befehl erstellt einen Symlink auf das `public/` dieses Projekts. Der Name des Links ist frei wählbar. Ein korrekter bestehender Link wird erkannt; vorhandene Ordner oder andere Links werden nicht überschrieben. Änderungen im Repository sind sofort über den Webserver sichtbar.

Apache benötigt `FollowSymLinks` beziehungsweise eine passende Symlink-Regel sowie Zugriff auf das Ziel und dessen übergeordnete Verzeichnisse. PHP benötigt zusätzlich Lesezugriff auf den privaten Projektteil. Der Beispielpfad `/var/www/html/foresight` ist an die eigene Installation anzupassen.

Durch den Symlink entfällt ein Kopierschritt für öffentliche Dateien.

## 4. Domain-Hauptverzeichnis und Unterordner

Dieselben Dateien funktionieren beispielsweise unter:

```text
https://domain.example/
https://domain.example/mail/
https://domain.example/foresight-mail/
https://domain.example/nested/other/
```

CSS/JS werden über `./assets/...` geladen. Die JavaScript-Module lösen ihre API-Adresse relativ zum eigenen Standort auf. Session-Cookie-Pfade und der interne URL-Präfix werden aus `SCRIPT_NAME` des öffentlichen PHP-Einstiegs ermittelt; `PATH_INFO` wird dabei nicht als Installationsordner behandelt.

Ein Verzeichnisaufruf ohne abschließenden Slash muss auf die Variante mit Slash weiterleiten. Apache erledigt dies mit seiner üblichen Verzeichnisbehandlung. Es ist kein `<base>`-Tag und keine pro Unterordner angepasste Konfiguration nötig.

Die API akzeptiert weiterhin beide Formen:

```text
api.php/webmail/messages
api.php?route=/webmail/messages
```

## 5. Managed Hosting

Das bevorzugte Layout ist:

```text
privater-bereich/
  foresight/                         Projekt inklusive vendor/
    public/                         einziges Domain-DocumentRoot
    src/
    templates/
    config/
    ...
  foresight-data/                    persistent, unabhängig von Code-Updates
    mailstore/
    secrets/
```

Im Hosting-Panel die Domain oder Subdomain auf `foresight/public/` zeigen lassen. Ein Symlink ist auf dem Hosting keine Voraussetzung. Bei Systemen, die das Domain-Ziel auf einen vorgegebenen öffentlichen Ordner begrenzen, kann der Inhalt von `public/` dort liegen und die zentrale Pfadzeile auf den privaten Projektteil zeigen.

Bei easyname unterscheiden sich Pfadwahl und Bedienung zwischen CloudPit und dem bisherigen Controlpanel. Die tatsächlich erlaubten Verzeichnisse und Serverpfade sind im jeweiligen Hosting-Konto zu prüfen. Offizielle Hinweise: [Domain-Verzeichnis](https://www.easyname.at/de/support/webhosting/webspace/wie-kann-ich-den-inhalt-meines-webspace-unter-meiner-domain-anzeigen-lassen), [private/öffentliche Serverpfade](https://www.easyname.at/de/support/webhosting/webspace/wie-lauten-die-serverpfade-zu-meinem-webspace).

Bei `open_basedir` müssen Projekt und Datenverzeichnisse für PHP erlaubt sein. PHP braucht Schreibrechte auf Mailstore/Secrets, nicht auf den Anwendungscode. Keine pauschalen 0777-Rechte verwenden.

Ohne Composer auf dem Server können die Abhängigkeiten lokal unter einer passenden PHP-Version installiert und mit hochgeladen werden. Node.js, Datenbank und permanente Worker sind keine Produktionsvoraussetzung. Die Synchronisation arbeitet weiterhin in kurzen, sequenziellen PHP-Aufrufen.

HTTPS aktivieren. Registrierung nach Einrichtung nur bewusst freigeben. Produktionskonfiguration, Mailstore und Secrets separat sichern; ein Git-Repository ist kein Mail-Backup.

## 6. Git

Versioniert werden Anwendungscode, öffentliche Quellen, Vorlagen, Tests, Dokumentation, `LICENSE.txt`, `composer.json` und `composer.lock`.

Ignoriert werden:

- `config/deployment.php` und `config/deployment.json`
- `vendor/`, lokale Umgebungsdateien und erzeugte Ausgabeordner
- Laufzeit-, Mailstore-, Secret- und Backup-Verzeichnisse

Die regulären Maildaten befinden sich ohnehin außerhalb des Repository. Auch Benutzerdateien mit Login-Hashes und Accountkonfigurationen gehören nicht ins Git.

Beispiel für die optionale Einrichtung eines neuen Repository im Projektverzeichnis; bei einem vorhandenen Clone entfällt `git init`:

```bash
git init -b main
git status --short
git add .
git commit -m "Initialize foresight with portable public layout"
```

Vor dem Commit die vorgemerkten Dateien mit `git diff --cached --stat` und `git diff --cached` prüfen, insbesondere auf lokale Konfiguration, Maildaten und Secrets. Dabei gelten die vom Betreiber konfigurierten Git-Identitätsdaten. Branchname, Commit-Nachricht und Remote-URL legt der Betreiber fest.

## 7. Bestehende Installation aktualisieren und wiederherstellen

Vor einem Update Anwendungscode, lokale Deployment-Konfiguration, Mailstore und Secrets sichern. Laufende Sync-Aufrufketten vorher anhalten. Die privaten Datenverzeichnisse bleiben bei einem Code-Update bestehen und werden nicht durch Dateien aus dem Repository ersetzt.

Nach dem Update die Composer-Abhängigkeiten passend zur Lockdatei installieren und öffentliche Einstiegspunkte, den relativen Projektpfad sowie die lokale Konfiguration prüfen. Bei einem separat platzierten öffentlichen Verzeichnis dessen Dateien ebenfalls aktualisieren und die lokale Pfadzeile in `_bootstrap.php` erhalten.

Beim Zurückwechseln müssen zusammengehöriger Code, Abhängigkeiten, öffentliche Einstiegspunkte und Konfiguration verwendet werden. Falls eine Version das Datenformat verändert, wird auch ein dazu passender Datenstand benötigt. Den persistenten Mailstore niemals unbesehen durch ein Code-Backup ersetzen.
