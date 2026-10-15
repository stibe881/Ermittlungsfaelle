# COLD CASE – Investigation

Interaktive Cold-Case-Ermittlungsplattform (PHP 8 + MySQL/MariaDB, Vanilla-JS-PWA, ohne Build-Schritt, Shared-Hosting-tauglich).
Alle Fälle, Personen und Ereignisse sind frei erfunden. Eigenständige Umsetzung der Grundidee; keine Texte, Grafiken oder Fälle der Referenzseite.

Dokumentation: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) · [`docs/INSTALL_HETZNER.md`](docs/INSTALL_HETZNER.md) · [`docs/ART_PROMPTS.md`](docs/ART_PROMPTS.md)

## Lokal starten
```bash
# MariaDB/MySQL mit Datenbank 'coldcase' bereitstellen, dann:
cp config/config.example.php config/config.php      # DB-Zugang eintragen
php bin/migrate.php
php bin/import-case.php cases/fall-001-hotel.json fall-001-hotel --publish
CC_ADMIN_PASSWORD='mind-12-Zeichen!' php bin/create-admin.php admin@example.com admin
php -S 127.0.0.1:8080 -t public public/router.php   # http://127.0.0.1:8080  ·  TV: /tv.html  ·  Admin: /admin.html
```
Tests: `php tests/run.php` (benötigt DB `coldcase_test`, siehe `tests/config.test.php`) und `tests/http_smoke.sh` (läuft gegen den Dev-Server).

## Status (Arbeitspaket 1)

| Bereich | Stand |
|---|---|
| Benutzerkonten (Registrierung, Login, Reset, Verifikation, Export, Löschung, Ränge, Erfolge) | implementiert; Engine-Tests grün; E-Mail-Versand nur `mail()`/Log (SMTP offen); Passkeys/2FA offen |
| Fall-Engine (Szenen/Hotspots, Beweise, Dialoge mit Bedingungen, Aktionen, Zeitachse, Hinweise 1–4, Anklage, Auflösung) | implementiert und getestet (Komplett-Durchspielen von Fall 001 per Test) |
| Validator (Erreichbarkeit, Sackgassen, Spoiler in Hinweisen …) | implementiert und getestet |
| Speichern/Fortsetzen, Geräte-/Spielerwechsel | implementiert (DB autoritativ) |
| Zeitmessung & Rangliste (Solo/Team getrennt, Erstversuch, Pausen, Strafzeiten) | implementiert, Zeitlogik getestet |
| Multiplayer (Code-Beitritt, Rollen, gemeinsame Pinnwand/Notizen, Versionen, Idempotenz) | implementiert, getestet auf Engine-Ebene; **kein Zwei-Browser-Test** |
| Pinnwand, Zeitachse, Notizen, Beweisvergleich (UI) | implementiert; manuell im Browser (Chromium, 390 px) angeklickt – kein automatisierter UI-Test der Anklage/Resolution |
| TV-Modus (Code-Kopplung, Polling, Projektion) | implementiert, im Browser verifiziert (Beweis auf TV). **Kein QR-Code, keine Google-Cast-Integration** |
| Admin (JSON-Editor, Validieren, Speichern als Version, Veröffentlichen, Testspiel, Archivieren, Statistik, Missbrauchsliste) | implementiert, API/Seite **nicht** automatisiert getestet; **kein grafischer Fall-Editor**, kein Medien-Upload |
| PWA (Manifest, Service Worker) | implementiert, Installierbarkeit nicht auf Geräten geprüft; Icons sind Platzhalter |
| Fall 001 „Die letzte Nacht im Hotel“ | vollständig (5 Szenen, 24 Beweise, 5 Personen, 27 Dialogoptionen, 6 Aktionen, 15 Ereignisse, 11 Hinweise, Lösung); **Bilder fehlen (Platzhalter)** |
| Fall 002, Fall 003 | **offen** |
| Audio/Video, KI-Verhöre, 360°/3D, Web-Installer, Last-/Performance-Messung, Hetzner-Deployment | **offen** |
