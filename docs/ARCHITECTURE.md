# COLD CASE – Architektur & Hosting-Kompatibilität

## 1. Hosting-Prüfung (Hetzner Webhosting L)

Quelle: Produktseite hetzner.com/webhosting (abgerufen beim Projektstart). **Vor Produktivstart im KonsoleH-Panel nochmals prüfen**, die Produktseite nennt nicht alle Details.

| Merkmal | Status | Konsequenz für die Architektur |
|---|---|---|
| PHP 8.x | laut Seite „PHP 5, 7, 8“ unterstützt – **konkrete 8.x-Version im Panel prüfen** | Code läuft auf PHP ≥ 8.1 (readonly-Properties, `match` nicht zwingend). Lokal getestet mit 8.3. |
| MariaDB/MySQL | bestätigt (20 DBs) | Schema nutzt nur InnoDB/utf8mb4; getestet mit MariaDB 10.11 |
| SSH | laut Seite inklusive – **Zugang im Panel aktivieren/prüfen** | CLI-Skripte (`bin/migrate.php` …) nutzbar; Fallback siehe Installer-Hinweis |
| Cronjobs | 10 | Aufräumjob optional (`rate_limits`, abgelaufene TV-Kopplungen) |
| 384 MB RAM/Skript, 10 PHP-Prozesse | bestätigt | **Begrenzte Parallelität**: Polling-Intervalle bewusst lang (3 s Spiel, 2 s TV), kurze Requests |
| Node.js | „inklusive“ genannt, **nicht als dauerhafter Prozess zugesichert** | Produktion ohne Node. Frontend ist Vanilla-ES-Module, kein Build nötig |
| Composer | nicht bestätigt | Keine Abhängigkeiten, kein `vendor/` |
| WebSockets / Redis / Docker | nicht bestätigt | Nicht verwendet. Synchronisierung per HTTP-Polling mit Versionsnummern |

**Annahmen (unbestätigt):** `mod_rewrite` aktiv (Standard bei Hetzner), `.htaccess` erlaubt, `mail()` oder SMTP für Mails verfügbar, Document-Root frei wählbar.

## 2. Architekturentscheidung

* **Backend:** PHP 8, eigener schlanker Router, PDO (Prepared Statements, `EMULATE_PREPARES=false`), kein Framework → minimale Deploy-Risiken, keine Composer-Pflicht.
* **Frontend:** Mobile-first SPA aus ES-Modulen (`public/assets/js`), XSS-sicherer DOM-Builder (`dom.js`, nie `innerHTML`), PWA (Manifest + Service Worker), strenge CSP ohne Inline-Skripte.
* **Spiel-Engine statt hartcodierter Fälle:** Ein Fall ist ein validiertes JSON-Dokument (`cases/*.json`, Schema v1). Engine (`src/Game/GameEngine.php`) und Validator (`CaseValidator.php`) sind fallunabhängig. Neue Fälle = neues JSON (Admin-Oberfläche oder `bin/import-case.php`), kein Code.
* **Autorität beim Server:** Der Client sendet Absichten (`inspect`, `ask`, `accuse` …). Freischaltungen, Zeit, Wertung, Rangliste entstehen nur serverseitig. Die API liefert eine *Projektion* des Zustands: nicht freigeschaltete Szenen/Hotspots/Beweise/Antworten/Lösung werden nie serialisiert. Fallinhalt liegt in der DB (`case_versions.content`) bzw. ausserhalb des Webroots.
* **Synchronisierung (Multiplayer/TV):** `game_sessions.version` steigt bei jeder Mutation; Clients pollen `GET /api/sessions/{id}?since=V` (unveränderte Antwort ≈ 60 Byte). Mutationen sind transaktional (`SELECT … FOR UPDATE` auf die Sitzung), idempotent (`client_action_id` UNIQUE) und bei Pinnwand/Notizen optimistisch versioniert (409 `version_conflict` mit aktuellem Stand).
* **Zeitmessung:** pro Sitzung eine Uhr (kein Doppelzählen bei mehreren Geräten/Spielern); je Signal höchstens `idle_cap_seconds` (90 s) gezählt; Pause stoppt die Uhr und sperrt alle Aktionen; Rangliste = aktive Zeit + 10 Min./Fehlanklage + 5 Min./neuem Hinweis. Ranglistenfähig ist nur der **erste** Versuch eines Benutzers je Fall (Wiederholung = Übungsmodus).

## 3. Projektstruktur

```
public/                 Webroot (nur das gehört ins öffentliche Verzeichnis)
  index.php             API-Front-Controller (/api/*)
  app.html, tv.html, admin.html, sw.js, manifest.webmanifest, .htaccess
  assets/{css,js,img,media}   Frontend; media/case001/… = Fallbilder (siehe docs/ART_PROMPTS.md)
src/                    PHP (Namespace ColdCase\…)
  Core/                 Config, Db, Router, Request, Security (Session/CSRF/Header/Audit), RateLimiter, Mailer
  Auth/AuthService.php  Registrierung, Login, Tokens, Export, Löschung, Ränge
  Game/                 GameEngine, Condition, Progress, CaseValidator, CaseRepository, Board, Achievements
  Http/                 Api (Routen), Tv, Admin
migrations/             SQL-Migrationen (001_init.sql …)
cases/                  Fall-JSON (Quelle der Wahrheit für den Import)
bin/                    migrate.php, import-case.php, create-admin.php, validate-case.php
config/                 config.example.php (config.php ist gitignored)
tests/                  run.php (Engine/Auth/Validator), http_smoke.sh (HTTP-Sicherheit)
docs/
```

## 4. Datenmodell (implementiert: `migrations/001_init.sql`)

Abweichungen von der Wunschliste (bewusst, dokumentiert):

* **Fallinhalt als versioniertes JSON** (`case_versions.content`) statt ~12 normalisierter Fall-Tabellen (scenes, hotspots, suspects, dialogue_nodes, evidence, hints, solutions …). Vorteil: atomar veröffentlichbar, versionierbar, im Editor als Ganzes validierbar, Spielstände verweisen per `case_version_id` fest auf eine Version. Nachteil: keine SQL-Abfragen über Fallinhalte. Spielstände sind dagegen normalisiert.
* **Fortschritt** (Beweise, gestellte Fragen, Aktionen, Flags, Hotspots) in einer Tabelle `session_progress (session_id, kind, ref)` statt fünf Tabellen.
* **Rollen:** `users.role` (`player|editor|admin`) statt `roles`/`permissions`-Tabellen. (Editor-Rolle ist angelegt, aber noch nicht verdrahtet; nur `admin` hat Zugriff.)
* Vorhanden: users, user_tokens, rate_limits, cases, case_versions, game_sessions, session_members, session_progress, session_events, session_notes, session_board_items, session_board_connections, session_timeline_events, session_hints, session_accusations, session_pauses, leaderboards, achievements, user_achievements, tv_pairings, media_assets (Tabelle vorhanden, Upload-UI offen), audit_logs.

## 5. Spielmechanik (Fall-Schema v1)

Bedingungen sind JSON-Bäume: `{"evidence":"id"}`, `{"asked":"id"}`, `{"action":"id"}`, `{"flag":"id"}`, `{"hotspot":"id"}`, `{"all":[…]}`, `{"any":[…]}`, `{"not":{…}}`. Sie steuern Sichtbarkeit von Szenen, Hotspots, Dialogoptionen, Aktionen, Zeitachsen-Ereignissen und Hinweisen. Dialoge: datengetrieben, deterministisch; KI-Verhöre sind **nicht** implementiert (siehe offene Punkte).

Anklage: Täter (Pflicht), Motiv 20, Methode 20, Beweiskette 40 (Schlüsselbeweise, Abzug für Fehlgriffe), Rekonstruktion 20 (korrekte relative Reihenfolge der Schlüsselereignisse). Falscher Täter → Fehlanklage; nach 3 Fehlanklagen ist der Fall ungelöst. Die Anklage-Optionen werden erst ab 8 gesicherten Beweisen ausgeliefert (und serverseitig erzwungen), damit die Auswahlmöglichkeiten nichts vorwegnehmen.

Validator vor Veröffentlichung (`CaseValidator`): Pflichtfelder, ID-Eindeutigkeit, Verweise, Bedingungs-Syntax, **Erreichbarkeits-Simulation ohne Hinweise** (Szenen, Hotspots, Beweise, Dialogoptionen, Aktionen, Ereignisse, Pflichtbeweise), Sackgassen-Verdächtige, Lösung gültig/chronologisch, Hinweisstufen 1–4 vorhanden, Täter-Name nicht in Hinweisen 1–3; Warnungen: fehlende Medien, Qualitätsziele (≥5 Personen, ≥15 Beweise, ≥20 Optionen, ≥2 Tatorte).

## 6. Kritische Risiken

| Risiko | Einschätzung / Gegenmassnahme |
|---|---|
| Polling-Last bei 10 PHP-Prozessen | 3-s-Intervall, Antwort bei „unverändert“ minimal, Rate-Limit 90/min/Benutzer. Last-Test auf Zielhosting **offen**. Bei Engpass Intervall im Client erhöhen (`views/game.js`). |
| Google Cast | **Nicht implementiert.** Cast Receiver braucht Registrierung (Google Cast SDK Developer Console, einmalige Gebühr) und eine HTTPS-Receiver-URL; Web-Sender-SDK läuft nur in Chrome/Android, **nicht in iOS-Safari**. Realistischer Ersatz (implementiert): `tv.html` im TV-Browser öffnen oder per Chrome „Tab streamen“. |
| Medien | Fotorealistische Bilder/Audio/Video existieren **nicht**; Platzhalter sind sichtbar gekennzeichnet. Spielbarkeit hängt nicht an Bildern. |
| Inhaltsqualität (Fälle 002/003) | Ein Fall ist vollständig; 002 und 003 stehen aus. Qualitäts-Playtests durch Menschen nötig. |
| Session-Cookies / CSRF | Cookie `SameSite=Lax`, `HttpOnly`, `Secure` bei HTTPS; CSRF-Token-Header für alle Mutationen. |
| Konto-Löschung | Löscht auch vom Benutzer erstellte Teamsitzungen für alle Mitglieder (FK-Kaskade) – für Produktivbetrieb besser Eigentümerwechsel implementieren. |
| E-Mail-Zustellung | `mail()` landet leicht im Spam; für Produktion SMTP (SPF/DKIM) einrichten. SMTP-Treiber ist nicht implementiert. |
