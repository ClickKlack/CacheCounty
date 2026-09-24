# CacheCounty – Arbeitsanleitung für Claude

Landkreis-Tracking-App für Geocacher. Nutzer markieren besuchte Landkreise (DE) bzw.
Bezirke (AT) auf einer Leaflet-Karte; pro Nutzer gibt es eine öffentliche Karten- und
Statistikseite plus eine globale Rangliste.

**Verwandte Dokumente:**
- `project.md` – fachliche Spezifikation (Soll-Zustand, teils veraltet – siehe „Doku-Drift")
- `README.md` – Setup-/Installationsanleitung für Betreiber
- Dieses Dokument – Architektur, Konventionen und Stolperfallen für die Arbeit am Code

---

## 1. Tech-Stack & Rahmenbedingungen

| Schicht     | Technologie                                                  |
|-------------|--------------------------------------------------------------|
| Backend     | PHP 8.3+ (kein Framework), PDO/MariaDB 10.4+                  |
| Composer    | nur `phpmailer/phpmailer` (prod) + `phpunit/phpunit` (dev)    |
| Frontend    | Vanilla JS (ES-IIFE-Module, kein Bundler), Leaflet 1.9.4      |
| Libraries   | turf.js 6.5 (Landesumriss), Chart.js 4 (Timeline) – via CDN   |
| Karten      | OpenStreetMap-Tiles, kein API-Key                             |
| Tests       | PHPUnit (API) + Vitest (Frontend)                             |
| Hosting     | klassisches Shared Hosting, Apache + mod_rewrite              |

**Wichtige Konsequenz aus dem Hosting:** kein Build-Step, kein npm im Produktivpfad,
keine Cronjobs, keine SQL-Events. Alles muss als statische Dateien + PHP-Frontcontroller
funktionieren. Abhängigkeiten werden per CDN eingebunden, nicht gebündelt.

---

## 2. Verzeichnisstruktur

```
public/                     Docroot – nur was hier liegt, ist per HTTP erreichbar
  .htaccess                 Produktiv-Rewrites für /api, /map/*, /stats/*
  api/index.php             Front-Controller: CORS, Exception-Handler, Dispatch
  app/                      Frontend (statisch, <base href="/app/">)
    index.html              Kartenansicht      → js/api.js, js/map.js, js/export.js, js/app.js
    stats.html              Statistikseite     → js/api.js, js/stats.js
    admin.html              Adminbereich       → js/api.js, js/admin.js
    js/export.js            GeoJSON-Export für c:geo (Leaflet-frei, testbar)
    css/app.css             ein Stylesheet für alle drei Seiten
  data/*.geojson            Geodaten – gitignored UND vom Deploy ausgeschlossen
api/                        REST-API (PHP), außerhalb des Docroots
  src/routes.php            zentrale Routentabelle
  src/Shared/               Router, Request, Response, Database, Guard
  src/{Auth,Region,Admin,Stats}/*Controller.php
  config/{app,database}.php Templates; *.local.php überschreibt (gitignored)
  tests/                    PHPUnit (RequestTest, RouterTest)
config/countries.json       Länderkonfiguration (Projektwurzel, nicht api/config/!)
tests/api.test.js           Vitest für public/app/js/api.js
tests/export.test.js        Vitest für public/app/js/export.js
scripts/dev-router.php      Dev-Server-Router (bildet public/.htaccess nach)
scripts/dev.sh              startet die lokale Entwicklungsumgebung
scripts/dev.config.example.sh  Vorlage → scripts/dev.config.sh (gitignored)
scripts/.run/               PID, Port und Logs des Dev-Servers (gitignored)
database.sql                Schema + Initial-Admin + Migrationshinweise
.htaccess                   Sicherheitsnetz: leitet alles nach public/ um, falls der
                            Webserver doch die Repo-Wurzel ausliefert
```

**Docroot ist `public/`.** Alles, was der Browser nicht braucht – Quellcode,
Konfiguration, `vendor/`, `database.sql`, Doku –, liegt außerhalb und ist per HTTP nicht
erreichbar. Neue öffentliche Dateien gehören nach `public/`, alles andere nicht. Die
öffentlichen URLs (`/app/…`, `/api/…`, `/map/…`, `/stats/…`, `/data/…`) haben sich mit der
Umstellung nicht geändert.

---

## 3. Entwickeln & Testen

```bash
# Tests
npm test                          # Vitest, 44 Tests
cd api && ./vendor/bin/phpunit    # PHPUnit, 24 Tests

# Abhängigkeiten
cd api && composer install --optimize-autoloader
npm ci
```

Beide Suites laufen aktuell grün. Vor jedem Commit beide ausführen – die CI führt
zusätzlich `composer validate --strict` und `php -l` über `api/src/` aus.

### Lokal starten

```bash
./scripts/dev.sh           # Modus "full": Frontend + API auf Port 8080
./scripts/dev.sh api       # Modus "api":  nur die API auf 8081, für Live Server
./scripts/dev.sh status    # Zustand aller Dienste
./scripts/dev.sh stop      # beendet nur den selbst gestarteten PHP-Server
```

Das Skript prüft PHP-Version, Composer-Abhängigkeiten, lokale Konfiguration und
GeoJSON, startet MariaDB und Mailpit bei Bedarf, testet die Datenbankverbindung
und listet am Ende die registrierten E-Mail-Adressen auf. Bei Verbindungsfehlern
gibt es gezielte Hinweise (etwa zu Fehler 1698, siehe unten). Konfiguriert wird es
über `scripts/dev.config.sh` – beim ersten Start aus `dev.config.example.sh`
erzeugt und gitignored. **Datenbank-Zugangsdaten stehen dort bewusst nicht**: die
liest das Skript aus `api/config/database.local.php`, damit es nur eine Quelle gibt.

Die beiden Modi im Detail:

**A) `full`** – `php -S localhost:8080 -t public scripts/dev-router.php`. Der Router
bildet die Rewrites aus `public/.htaccess` nach und bedient API, `/map/*` und `/stats/*`.
Einfachster Weg. Der PHP-Dev-Server wertet `.htaccess` nicht aus – Änderungen an den
Rewrites deshalb immer in beiden Dateien nachziehen.

**B) `api`** – für VS Code Live Server (Hot Reload fürs Frontend).
`.vscode/settings.json` legt Port 5500 fest und **proxyt `/api` auf
`http://localhost:8081/api`**. Der Proxy ist nur die halbe Miete – der PHP-Server
dahinter muss laufen, genau den startet dieser Modus.

> Fehlt dieser Prozess, liefert Live Server für `/api/*` eine HTML-Fehlerseite. Das
> Frontend meldete früher nur `JSON.parse: unexpected character at line 1 column 1` –
> seit dem `try/catch` um `res.json()` in `api.js` kommt stattdessen
> „Die API hat kein JSON geliefert (HTTP …). Läuft der PHP-Server?".

Variante B kennt `/map/{username}` nicht (Live Server liefert nur statische Dateien),
sondern nur `http://localhost:5500/app/` (Live-Server-Root ist `/public`). Ohne Username in der URL fällt `app.js` auf
den eingeloggten Nutzer zurück – zum Testen fremder Karten Variante A nehmen.

**Lokale Konfiguration:** `api/config/database.local.php` und `api/config/app.local.php`
müssen existieren (Kopien der Templates). Sie sind gitignored und werden vom Deploy
explizit ausgeschlossen.

**Lokale Dienste auf diesem Rechner:**

| Dienst | Port | Hinweis |
|---|---|---|
| MariaDB (Homebrew) | 3306 | DB `CacheCounty`. Zugang **nur über den Unix-Socket** – in `database.local.php` muss `host` deshalb `localhost` sein, nicht `127.0.0.1`. Über TCP scheitert die Anmeldung mit Fehler 1698, und `root` verlangt `unix_socket` als OS-root. |
| Mailpit | 1025 / 8025 | Fängt die Magic-Link-Mails ab. Weboberfläche: http://localhost:8025 |

Magic-Link-Logins landen also nie im echten Postfach, sondern in Mailpit. Wichtig:
`POST /api/auth/magic-link` antwortet **immer** generisch erfolgreich, auch bei
unbekannter Adresse (Schutz vor E-Mail-Enumeration). Kommt keine Mail an, ist die
Adresse meist schlicht nicht in `users` angelegt – das ist kein Fehler.

---

## 4. Architekturprinzipien

**Strikte Trennung Frontend/Backend.** Das Frontend spricht ausschließlich über
`/api/*` mit dem Backend. Es gibt kein serverseitiges Rendering – `/map/{username}`
und `/stats/{username}` liefern dieselben statischen HTML-Dateien aus; der Username
wird clientseitig aus `location.pathname` geparst (`getPageUsername()` in `app.js`,
`parseUsername()` in `stats.js`).

**Neue Länder ohne Codeänderung.** Ein Land besteht aus einem Eintrag in
`config/countries.json` plus einer GeoJSON-Datei in `public/data/`. Die Zuordnung
Landkreis → Bundesland wird zur Laufzeit clientseitig aus den GeoJSON-Properties
abgeleitet (`region_code_property`, `state_code_property` …) – es gibt bewusst
**keine** Regionen-Tabelle in der Datenbank. Wer eine solche Tabelle einführen will,
bricht dieses Prinzip.

**Die Datenbank kennt nur Besuche.** `visits` speichert `country_code` + `region_code`
+ einen denormalisierten `region_name`. Gesamtzahlen („42 von 401") stammen immer aus
dem GeoJSON, nie aus der DB.

**Sessions sind serverseitig, kein JWT.** Token = 64 Hex-Zeichen, liegt als PK in
`sessions`. Übertragung per HttpOnly-Cookie `cc_session` **oder** `Authorization: Bearer`
(`Request::sessionToken()` prüft in dieser Reihenfolge). Das Frontend spiegelt den Token
zusätzlich in `sessionStorage`, damit `api.js` den Bearer-Header setzen kann.

---

## 5. Backend-Konventionen

**Controller-Signatur.** Jede Action ist `public function name(Request $request): void`
und wird über `src/routes.php` als `[Controller::class, 'method']` registriert.
Der Router instanziiert den Controller ohne Konstruktorargumente – **kein DI-Container**.

**Antworten beenden die Ausführung.** `Response::ok()`, `::error()`, `::notFound()`,
`::unauthorized()`, `::forbidden()` sind als `never` deklariert und rufen `exit`.
Nach einem `Response::`-Aufruf folgt nie weiterer Code. Format immer
`{"success":bool,"data":…}` bzw. `{"success":false,"error":"…"}`.

**Auth-Guards am Methodenanfang.** `Guard::requireAuth($request)` bzw.
`Guard::requireAdmin($request)` als erste Zeile; Rückgabe ist
`['user_id','username','is_admin']`. Es gibt keine Middleware-Schicht – wer den Guard
vergisst, macht den Endpunkt öffentlich. **Bei jedem neuen schreibenden Endpunkt prüfen.**

**Routenreihenfolge zählt.** Der Router matcht in Registrierungsreihenfolge und
ersetzt `{param}` durch `([^/]+)`. Deshalb steht `/api/leaderboard` in `routes.php`
bewusst **vor** `/api/stats/{username}`. Neue statische Routen immer vor
gleichpräfixigen Platzhalterrouten einfügen.

**SQL immer per Prepared Statement.** `Database::get()` liefert ein PDO-Singleton mit
`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false` und `time_zone = '+00:00'`.
**Alle Zeitstempel sind UTC.** Dynamische Spaltennamen (z. B. in
`AdminController::updateUser`) nur aus einer festen Allowlist bauen.

**Fehlerausgabe.** `index.php` fängt alle `Throwable` und antwortet generisch mit 500.
Details werden nicht geleakt – aber auch **nicht geloggt** (siehe „Bekannte Schwachstellen").

---

## 6. Frontend-Konventionen

**Kein Modulsystem.** Jede Datei ist eine IIFE, die entweder ein Global exportiert
(`Api`, `CacheMap`, `CacheExport`) oder alles in einem `DOMContentLoaded`-Handler
kapselt (`app.js`, `admin.js`). Ladereihenfolge in den HTML-Dateien ist relevant:
`api.js` zuerst, dann `map.js`, `export.js`, zuletzt `app.js`.

**Testbarkeit erkauft man sich über Browser-Freiheit.** `api.js` und `export.js`
kommen ohne Leaflet und ohne DOM-Bibliotheken aus und werden in den Vitest-Tests
über `new Function(...)` mit gemockten Globals ausgewertet. Wer neue Logik
testbar halten will, legt sie in ein solches Modul statt in `app.js` – dort ist
alles im `DOMContentLoaded`-Closure eingeschlossen und von außen nicht erreichbar.

**Zentraler State pro Seite.** Jede Seite hält ein einzelnes `state`-Objekt und ein
`els`-Objekt mit allen DOM-Referenzen. Neue Zustände dort ergänzen, keine
verstreuten Modulvariablen.

**HTML-Escaping ist manuell.** `app.js` und `admin.js` haben eine lokale
`escHtml()`-Funktion für alles, was in `innerHTML` landet. `stats.js` hat sie **nicht** –
dort werden Usernames und Länderlabels ungeprüft interpoliert. Entschärft wird das nur
durch die Username-Validierung beim Anlegen (`^[a-zA-Z0-9_\-]{2,60}$`). Wer in `stats.js`
neue `innerHTML`-Templates schreibt, sollte `escHtml()` dorthin mitnehmen.

**localStorage für Sichtbarkeit, sessionStorage für Auth.**
- `cc_states_{username}_{countryCode}` → Array der ausgeblendeten Bundesland-Codes
- `cc_token`, `cc_username`, `cc_admin` → Session-Spiegel

**GeoJSON-Pfade unterscheiden sich pro Seite.** `app.js` nimmt nur den Dateinamen und
baut `'../data/' + name` (relativ zu `<base href="/app/">`); `stats.js` nutzt
`'/' + country.geojson`. Beide landen bei `/data/…`, aber der Unterschied ist
leicht zu übersehen.

**Karte.** `CacheMap` (`map.js`) kapselt Leaflet komplett. `loadGeoJSON()` gibt die
`stateMap` (`{stateCode: {name, total, regions[]}}`) zurück, aus der Panel und
Statistiken gerechnet werden. Ausblenden eines Bundeslandes setzt `opacity: 0` **und**
`pointerEvents: none` – die Daten bleiben unverändert, die Gesamtstatistik ebenfalls.
Der Landesumriss wird per `turf.dissolve` berechnet; MultiPolygons müssen vorher in
Polygone aufgelöst werden, sonst schlägt `dissolve` fehl.

**GeoJSON-Export für c:geo.** Das Bundesland-Panel bietet dem eingeloggten Besitzer
je Bundesland zwei Downloads: alle Landkreise farbcodiert (gefunden grün `#4a6741`,
fehlend rot `#c45c2a`) und nur die fehlenden. Erzeugt wird das komplett clientseitig –
`CacheMap.getFeatures()` liefert die Original-Features aus `layer.feature`, den Rest
macht `CacheExport`. Kein API-Endpunkt, weil PHP sonst die 3,8-MB-GeoJSON pro Request
parsen müsste.

Drei Eigenheiten, die beim Ändern zählen:

- Die Einfärbung läuft über **simplestyle-Properties** (`fill`, `fill-opacity`,
  `stroke`, `stroke-opacity`, `stroke-width`) je Feature – genau die wertet c:geos
  `GeoJsonUtils` aus. Andere Property-Namen werden ignoriert.
- c:geo rendert **keine Labels** aus den Properties. `name` ist für andere Tools da,
  sichtbar sind in c:geo nur die Flächen.
- c:geo lehnt den Import ab, wenn `Dateigröße × 10 > freier Speicher`. Deshalb gibt es
  bewusst **keinen** Export für ein ganzes Land. Größter Einzelfall heute: Bayern mit
  96 Kreisen ≈ 0,97 MB.

Die Download-Buttons sitzen im `.state-item`, dessen `<li>` einen eigenen Click-Handler
für das Ein-/Ausblenden trägt. Der Handler hat deshalb eine `closest('.state-item-dl')`-
Guard-Klausel – ohne sie würde jeder Download das Bundesland mit umschalten.

---

## 7. Authentifizierung (Magic Link)

1. `POST /api/auth/magic-link` – erzeugt bei bekannter, aktiver E-Mail ein 64-Zeichen-Token
   (15 min gültig) und versendet es per PHPMailer/SMTP. Antwortet **immer** generisch
   erfolgreich, um E-Mail-Enumeration zu verhindern.
2. Link zeigt auf `{base_url}/app/?token=…`.
3. `GET /api/auth/verify?token=…` – markiert das Token per einzelnem `UPDATE … JOIN`
   atomar als benutzt (`rowCount() === 1` ist die eigentliche Prüfung), legt eine Session
   an und setzt das Cookie. Token ist danach verbrannt.
4. `app.js::checkMagicLinkToken()` entfernt den Token per `history.replaceState` aus der URL.

**Garbage Collection** läuft probabilistisch: bei 2 % aller Magic-Link-Requests werden
abgelaufene Tokens und Sessions gelöscht (`AuthController::maybeRunGc`). Das ersetzt
den Cronjob, den Shared Hosting nicht bietet – nicht durch einen SQL-Event ersetzen.

**User-Anlage nur durch Admins.** Es gibt keine Selbstregistrierung. Der Initial-Admin
kommt aus `database.sql` und muss nach dem ersten Deployment auf eine echte E-Mail
umgestellt werden.

---

## 8. Datenmodell

`users` · `magic_links` · `visits` · `sessions` – alle mit `ON DELETE CASCADE` auf
`users.id`. Ein Nutzer löschen entfernt damit Besuche, Tokens und Sessions.

Schlüssel-Constraint: `UNIQUE (user_id, country_code, region_code)` auf `visits`.
`RegionController::addVisit` prüft zusätzlich vorher und gibt 409 zurück.

Schema-Änderungen gehen in `database.sql`. Es gibt **kein Migrationstool** – bestehende
Instanzen werden über auskommentierte `ALTER TABLE`-Blöcke am Dateiende versorgt
(siehe `last_seen_at`). Diesem Muster folgen.

---

## 9. Deployment

`.github/workflows/deploy.yml`, drei Jobs:

1. **lint** – `composer validate --strict`, `composer install --no-dev`, `php -l` über `api/src`
2. **test** – PHPUnit + Vitest
3. **deploy** – nur bei `workflow_dispatch` (manuell), rsync `--delete` per SSH

Der Deploy-Job erzeugt `public/app/version.json` mit Commit-Hash und Build-Zeit; `app.js` hängt
den Wert an die Leaflet-Attribution an. Lokal existiert die Datei nicht – der Fetch
schlägt bewusst still fehl.

**rsync schließt aus:** `.git`, `.github`, `.vscode`, `docs/`, `scripts/`, `tests/`,
`api/config/*.local.php`, `public/data/*.geojson`, `*.bak.*`. Das Docroot auf dem Server
ist `$DEPLOY_PATH/public`. Konfiguration und Geodaten müssen also **einmalig manuell** auf den Server –
sie kommen weder über Git noch über den Deploy.

---

## 10. Bekannte Schwachstellen und Stolperfallen

Der Reihe nach, grob nach Relevanz:

**CSRF-Schutz fehlt.** `project.md` §9 fordert ihn für schreibende Endpunkte; im Code
existiert er nirgends. Abgesichert wird derzeit nur durch `SameSite=Lax` am Cookie –
das deckt einfache Cross-Site-POSTs ab, ersetzt aber keine Token-Prüfung.

**`Access-Control-Allow-Origin: *`** in `public/api/index.php` ist sehr weit gefasst.
Mit Wildcard blockiert der Browser zwar `credentials: 'include'`, der Bearer-Token-Pfad
aus `sessionStorage` ist aber origin-gebunden und damit nicht betroffen. Beim
Einschränken auf eine konkrete Origin daran denken, dass das Frontend heute
same-origin ausgeliefert wird.

**Keine Fehlerprotokollierung.** Der globale Exception-Handler verwirft die Exception
vollständig. Produktionsfehler sind dadurch praktisch nicht diagnostizierbar – ein
`error_log()` im Handler wäre der kleinste sinnvolle Schritt.

**Session-TTL: Kommentar sagt 30 Tage, `SESSION_TTL_DAYS` steht auf 365.** Der Code
gewinnt; der Kommentar in `AuthController` ist falsch. Vor einer Änderung klären,
welcher Wert gewollt ist.

**`stats.js` liest `cc_is_admin`, geschrieben wird `cc_admin`** (`app.js:135`).
Auf der Statistikseite ist das Admin-Flag deshalb immer `false`. Aktuell folgenlos,
weil die Seite es nicht auswertet – bricht aber, sobald sie es tut.

**`updateVisit` antwortet mit 404, wenn sich nichts geändert hat.** `rowCount()` liefert
bei MySQL geänderte, nicht getroffene Zeilen. Speichert jemand unveränderte Werte
innerhalb derselben Sekunde (`updated_at = NOW()`), meldet die API „Visit not found",
obwohl der Besuch existiert.

**`public/data/de_landkreise.geojson` ist 3,8 MB.** `project.md` Schritt 10 behauptet eine
Vereinfachung auf 1,1 MB – das ist die `.bak`-Datei; die aktive Datei wurde später
gegen eine größere getauscht. Das kostet Ladezeit auf jeder Kartenseite und ist der
naheliegendste Performance-Hebel.

**Keine Tests für Controller oder Datenbank.** Abgedeckt sind `Request`, `Router` und
`api.js` – also Routing- und URL-Mechanik. Auth-Flow, Guards, Visit-CRUD und
Admin-Logik sind ungetestet.

---

## 11. Doku-Drift

Beim Ändern von Code mitpflegen – diese Stellen sind bereits auseinandergelaufen:

- `project.md` Schritt 10 nennt eine GeoJSON-Größe, die nicht mehr stimmt (siehe oben).
- `README.md` führt `GET /api/auth/me` in der Endpunkttabelle, `project.md` nicht.

---

## 12. Sprache

Code-Kommentare sind gemischt Deutsch/Englisch – Backend überwiegend Englisch,
Frontend überwiegend Deutsch. Alle Benutzertexte (UI, Fehlermeldungen im Frontend,
E-Mail-Templates) sind **Deutsch**. API-Fehlermeldungen sind Englisch. Commit-Messages
und Projektdokumentation sind Deutsch. Bei Änderungen jeweils der Konvention der
umgebenden Datei folgen.
