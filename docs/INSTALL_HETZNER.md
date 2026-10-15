# Installation auf Hetzner Webhosting L

> Getestet wurde lokal (PHP 8.3 + MariaDB 10.11 + `php -S`). **Der Betrieb auf Hetzner selbst ist nicht getestet.** Prüfe jeden Schritt im Panel (KonsoleH).

## 1. Voraussetzungen
* PHP ≥ 8.1 für die Domain einstellen (KonsoleH → Einstellungen → PHP-Version); Erweiterungen `pdo_mysql`, `mbstring`, `sodium`/`argon2` (sonst bcrypt-Fallback), `gd` nur falls später Bildverarbeitung.
* Eine MariaDB/MySQL-Datenbank + Benutzer anlegen (KonsoleH → Datenbanken). Zeichensatz `utf8mb4`.
* HTTPS (Let's Encrypt in KonsoleH aktivieren) – Voraussetzung für PWA, Service Worker und sichere Cookies.
* SSH-Zugang aktivieren (falls verfügbar). Ohne SSH: siehe Abschnitt 10.

## 2. Verzeichnisstruktur auf dem Server
```
~/coldcase/            ← AUSSERHALB des Webroots: src/ migrations/ cases/ bin/ config/ storage/
~/public_html/         ← Inhalt von public/ (index.php, app.html, tv.html, admin.html, sw.js, manifest.webmanifest, .htaccess, assets/)
```
Liegt `public_html` nicht neben `coldcase/`, setze in `public_html/.htaccess` (oder Panel) die Variable:
`SetEnv CC_APP_ROOT /pfad/zu/coldcase` (der Pfad enthält `src/bootstrap.php`).
Alternativ alles in `public_html` ablegen: dann schützen die mitgelieferten `.htaccess` (`Require all denied`) in `src/ config/ migrations/ cases/ bin/ storage/ tests/` diese Ordner – **trotzdem nach dem Upload per Browser prüfen**, dass z. B. `/config/config.example.php` und `/cases/fall-001-hotel.json` nicht abrufbar sind.

## 3. Konfiguration
`config/config.example.php` → `config/config.php` kopieren, DB-Zugangsdaten, `base_url`, `env => 'production'`, Mail-Absender eintragen. `config.php` nie ins Webroot legen und nie committen.
Pfad der Config alternativ per Umgebungsvariable `CC_CONFIG`.

## 4. Datenbank & Inhalte (per SSH)
```bash
cd ~/coldcase
php bin/migrate.php
php bin/import-case.php cases/fall-001-hotel.json fall-001-hotel --publish
CC_ADMIN_PASSWORD='…mind. 12 Zeichen…' php bin/create-admin.php du@example.ch DeinName
```
`import-case.php` validiert vor dem Veröffentlichen; Fehler verhindern die Veröffentlichung (Exit-Code/Meldung).

## 5. Rewrite-Regeln
Mitgeliefert in `public/.htaccess`: HTTPS-Weiterleitung, `/api/*` → `index.php`, übrige Nicht-Dateien → `app.html` (SPA). `mod_rewrite` muss aktiv sein.

## 6. E-Mail
`mail.driver = 'mail'` nutzt PHP `mail()`. Richte SPF/DKIM für die Absenderdomain ein. Für Tests: `'log'` schreibt nach `storage/logs/mail.log`. `require_email_verification => true` erzwingt Bestätigung vor dem Login.

## 7. Berechtigungen
`storage/` muss für PHP beschreibbar sein (755/775). Sonstige Dateien 644, Ordner 755.

## 8. Medien
Bilder nach `public_html/assets/media/case001/…` hochladen (Dateinamen siehe `docs/ART_PROMPTS.md`). Fehlende Dateien zeigen einen gekennzeichneten Platzhalter. Empfohlen: WebP/JPG, max. 1920 px Breite, < 300 KB.

## 9. Backup
* Datenbank täglich: Hetzner-Backup oder Cronjob `mysqldump --single-transaction DB | gzip > ~/backups/cc-$(date +%F).sql.gz`, 14 Tage aufbewahren, Kopie ausserhalb des Hostings.
* Dateien: `config/config.php` und `assets/media/` separat sichern (Fälle liegen in der DB und in `cases/`).

## 10. Ohne SSH
Migration/Import/Admin-Anlage sind CLI-Skripte. Ohne SSH bleibt: lokal ausführen gegen die Remote-Datenbank (falls Hetzner Remote-Zugriff erlaubt) **oder** per phpMyAdmin `migrations/001_init.sql` importieren und Fall/Admin lokal gegen die Remote-DB importieren. **Ein Web-Installer ist nicht implementiert.**

## 11. Updates
Neue Version hochladen (ohne `config/config.php`), `php bin/migrate.php` ausführen. Neue Fallversionen: `import-case.php` erzeugt eine neue Version; laufende Spielstände bleiben an ihrer alten Version.

## 12. Sicherheitsprüfung vor Freigabe
* `https://domain/config/config.example.php`, `/src/Core/Db.php`, `/cases/fall-001-hotel.json`, `/storage/logs/mail.log` → müssen 403/404 liefern.
* `tests/http_smoke.sh https://domain` laufen lassen (legt Testbenutzer an; danach löschen).
* Header prüfen (CSP, nosniff, HSTS), Passwort-Policy, Admin-Konto mit starkem Passwort.
* `env => 'production'` (keine Debug-Ausgaben).

## 13. Funktionstest
Registrieren → Fall starten → Tatort-Hotspot → Beweis → Verhör → Pinnwand → Zeitachse → Aktion → Anklage; zweites Gerät: Teamcode beitreten; TV: `/tv.html` öffnen, Code am Handy (📺) eingeben, „Auf TV“.

## 14. PWA
Manifest + Service Worker sind enthalten (HTTPS nötig). Installieren: iOS Safari „Zum Home-Bildschirm“, Android Chrome „App installieren“. Die Icons (`assets/img/icon-*.png`) sind **einfache Platzhalter**.
