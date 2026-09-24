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
| Libraries   | Chart.js 4.5.1 (Timeline) – selbst gehostet in `public/app/vendor/` |
| Karten      | OpenStreetMap-Tiles, kein API-Key                             |
| Tests       | PHPUnit (API) + Vitest (Frontend)                             |
| Hosting     | klassisches Shared Hosting, Apache + mod_rewrite              |

**Wichtige Konsequenz aus dem Hosting:** kein Build-Step, kein npm im Produktivpfad,
keine Cronjobs, keine SQL-Events. Alles muss als statische Dateien + PHP-Frontcontroller
funktionieren. Fremdbibliotheken und Schriften liegen unverändert unter
`public/app/vendor/` (Version im Pfad, Prüfsummen in `vendor/README.md`), es gibt kein
CDN und kein Google Fonts. Die Seiten fragen nur die eigene Origin und die
OSM-Kachelserver an.

---

## 2. Verzeichnisstruktur

```
public/                     Docroot – nur was hier liegt, ist per HTTP erreichbar
  .htaccess                 Produktiv-Rewrites für /api, /map/*, /stats/*
  api/index.php             Front-Controller: API-Header, Exception-Handler, Dispatch
  favicon.svg, favicon.ico  Favicon (◈ aus dem Logo); dazu apple-touch-icon.png, icon-192/512.png
  site.webmanifest          Web-App-Manifest (Name, Farben, Icons)
  robots.txt                sperrt nur /api/ – Admin-Seite nutzt noindex (muss lesbar bleiben);
                            verweist auf die Sitemap (Produktions-URL fest eingetragen)
  app/                      Frontend (statisch, <base href="/app/">)
    index.html              Kartenansicht      → js/api.js, js/auth-menu.js, js/outline.js, js/map.js, js/export.js, js/app.js
    stats.html              Statistikseite     → js/api.js, js/auth-menu.js, js/stats.js
    admin.html              Adminbereich       → js/api.js, js/auth-menu.js, js/admin.js
    js/auth-menu.js         Header-Menü aller Seiten (Desktop-Leiste / Mobil-Hamburger)
    js/export.js            GeoJSON-Export für c:geo (Leaflet-frei, testbar)
    js/outline.js           Landesumriss aus den Regionsflächen (Leaflet-frei, testbar)
    js/page-meta.js         Titel, Beschreibung, canonical je Karte/Statistik (PageMeta.set)
    vendor/                 Leaflet, Chart.js, Schriften – unverändert, siehe vendor/README.md
    img/gc.png              Symbol des Geocaching-Buttons (Favicon von geocaching.com, lokal)
    css/app.css             ein Stylesheet für alle drei Seiten
  data/*.geojson            Geodaten – gitignored UND vom Deploy ausgeschlossen
api/                        REST-API (PHP), außerhalb des Docroots
  src/routes.php            zentrale Routentabelle
  src/Shared/               Router, Access, OriginCheck, Guard, Request, Response, Database, Config, Token
  src/{Auth,Region,Admin,Stats,Seo}/*Controller.php
  config/{app,database}.php Templates; *.local.php überschreibt (gitignored)
  tests/                    PHPUnit: Unit-Tests (Request, Router, Routen-Vollständigkeit)
  tests/Integration/        PHPUnit gegen Testdatenbank + Dev-Server (Rollen-Matrix u. a.)
config/countries.json       Länderkonfiguration (Projektwurzel, nicht api/config/!)
tests/api.test.js           Vitest für public/app/js/api.js
tests/export.test.js        Vitest für public/app/js/export.js
tests/auth-menu.test.js     Vitest für public/app/js/auth-menu.js (buildHtml)
tests/outline.test.js       Vitest für public/app/js/outline.js
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
npm test                          # Vitest, 69 Tests
cd api && ./vendor/bin/phpunit    # PHPUnit: 57 Unit- + 167 Integrationstests

# Abhängigkeiten
cd api && composer install --optimize-autoloader
npm ci
```

Beide Suites laufen aktuell grün. Vor jedem Commit beide ausführen – die CI führt
zusätzlich `composer validate --strict` und `php -l` über `api/src/` aus. Die CI läuft
auch für Pull Requests.

**Integrationstests** (`api/tests/Integration/`) laufen nur, wenn
`CACHECOUNTY_TEST_DB_NAME` gesetzt ist, sonst werden sie übersprungen. Lokal gibt es
dafür die Datenbank `CacheCounty_test`, Zugang wie die Entwicklungs-DB. Einrichtung
siehe README → „Tests". Mechanik:

- `ApiTestCase` startet einmal pro Lauf `php -S` mit `scripts/dev-router.php` auf einem
  freien Port und reicht die Test-Konfiguration über `CACHECOUNTY_DB_CONFIG` und
  `CACHECOUNTY_APP_CONFIG` durch. Diese Variablen werten `Database` und `Config` vor den
  `*.local.php` aus.
- Das Schema wird aus `database.sql` aufgebaut; vor jedem Test werden alle Tabellen
  geleert und feste Fixtures angelegt (Admin, User A mit Besuch, User B, deaktivierter
  Admin, je eine Session mit bekanntem Token).
- Schutz: Der Datenbankname muss auf `_test` enden, sonst bricht der Lauf ab.
- `RouteMatrix` legt den erwarteten Status je Route und Rolle fest.
  `RoutesCompletenessTest` (Unit, ohne DB) verlangt für jede Route in `routes.php`
  einen Eintrag. **Neue Route = neuer Matrix-Eintrag.**

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
`parseUsername()` in `stats.js`) – beide **dekodieren** den Namen.

**Nutzernamen folgen Geocaching-Namen** (z. B. `Ahnungslos*`, `Max Mustermann`, Umlaute).
Erlaubt sind 2–60 sichtbare Zeichen außer `/` und `\` (`AdminController::createUser`).
Einen `/` weist Apache im Pfad auch kodiert ab. Deshalb gilt überall:
- In URLs immer `encodeURIComponent()` (JS) bzw. `SitemapController::encodeLikeJs()` (PHP),
  damit Links, canonical und Sitemap dieselbe Schreibweise haben.
- Im HTML immer `escHtml()` oder `textContent`.
- Der Router dekodiert Pfadparameter (`rawurldecode`) nach dem Matching;
  `/api/map/Ahnungslos%2A` und `/api/map/Ahnungslos*` treffen denselben Nutzer.

**Neue Länder ohne Codeänderung.** Ein Land besteht aus einem Eintrag in
`config/countries.json` plus einer GeoJSON-Datei in `public/data/`. Die Zuordnung
Landkreis → Bundesland wird zur Laufzeit clientseitig aus den GeoJSON-Properties
abgeleitet (`region_code_property`, `state_code_property` …) – es gibt bewusst
**keine** Regionen-Tabelle in der Datenbank. Wer eine solche Tabelle einführen will,
bricht dieses Prinzip.

**Reihenfolge der Länder** bestimmt `RegionController::sortCountries()`: Länder mit
`"pinned": true` zuerst (heute nur DE), danach alphabetisch nach `label`, per `Collator`
bzw. ohne `intl` mit Umlaut-Rückfall. Die Reihenfolge in `countries.json` spielt keine
Rolle. Das erste Land ist die Voreinstellung der Karte.

**Besuche werden serverseitig validiert, ohne das GeoJSON zu lesen.**
`RegionController::parseCode()` prüft, dass das Land in `countries.json` steht und der
Regionscode zum optionalen `region_code_pattern` des Landes passt (DE `^[0-9]{5}$`,
AT `^[0-9]{3}$`, CH `^[0-9]{3,4}$`, DK `^[0-9]{4}$`). Ob der Code wirklich existiert,
weiß nur das GeoJSON; es pro Request zu parsen (3,8 MB) wäre zu teuer. Das Muster ist der Kompromiss gegen erfundene
Besuche in der Rangliste. Ein neues Land sollte ein Muster mitbringen. Freitexte:
`notes` höchstens 2000 Zeichen, `region_name` höchstens 255, beide nur als String.

**Die Datenbank kennt nur Besuche.** `visits` speichert `country_code` + `region_code`
+ einen denormalisierten `region_name`. Gesamtzahlen („42 von 401") stammen immer aus
dem GeoJSON, nie aus der DB.

**Sessions sind serverseitig, kein JWT.** Token = 64 Hex-Zeichen (`Token::generate()`).
In der DB steht **nur der SHA-256-Hash** (`Token::hash()`), in `sessions.id` ebenso wie
in `magic_links.token`. Der Roh-Token verlässt den Server genau einmal, per Cookie bzw.
Mail. Wer Sessions oder Links in der DB sucht, muss also immer den Hash vergleichen.
Die Admin-Sessionliste gibt den Hash als `id` aus; er taugt zum Beenden der Session,
nicht zum Anmelden.
Übertragung **ausschließlich** per HttpOnly-Cookie `cc_session` (`Request::sessionToken()`).
Ein `Authorization: Bearer`-Header wird bewusst nicht akzeptiert, und keine API-Antwort
enthält den Token. JavaScript sieht ihn also nie, auch nicht bei einer XSS-Lücke.

**Anmeldestatus und Rolle kommen im Frontend immer vom Server.** Jede Seite fragt beim
Laden `GET /api/auth/me` ab (bzw. übernimmt die Antwort von `/verify`). Nichts davon wird
im Browser gespeichert. Ein manipulierter Browser-Speicher kann deshalb keinen
Admin-Link mehr einblenden. Die Admin-Seite zeigt ihren Inhalt erst, wenn `/me`
`is_admin` bestätigt. Bei 401 ruft `api.js` den per `Api.setUnauthorizedHandler()`
registrierten Handler auf; die Seite schaltet dann auf „abgemeldet" (Karte, Statistik)
bzw. leitet zur Karte weiter (Admin). Fehler tragen `err.status`.

---

## 5. Backend-Konventionen

**Controller-Signatur.** Jede Action ist `public function name(Request $request): void`
und wird über `src/routes.php` als `[Controller::class, 'method']` registriert.
Der Router instanziiert den Controller ohne Konstruktorargumente – **kein DI-Container**.

**Antworten beenden die Ausführung.** `Response::ok()`, `::error()`, `::notFound()`,
`::unauthorized()`, `::forbidden()` sind als `never` deklariert und rufen `exit`.
Nach einem `Response::`-Aufruf folgt nie weiterer Code. Format immer
`{"success":bool,"data":…}` bzw. `{"success":false,"error":"…"}`.

**Zugriffsstufe pro Route, geprüft im Router (Default-Deny).** Jede Registrierung in
`routes.php` braucht als drittes Argument `Access::Public`, `Access::User` oder
`Access::Admin`. Ohne diese Angabe scheitert sie sofort, es gibt keinen Standardwert.
`Router::dispatch()` prüft die Stufe über `Guard`, **bevor** der Controller läuft, und
legt den Nutzer in `$request->user()` ab. Die `Guard::`-Aufrufe am Anfang der
Controller-Methoden bleiben als zweite Absicherung; sie lesen den Nutzer aus dem
Request und fragen die DB nicht erneut. Rückgabe ist `['user_id','username','is_admin']`.

**Origin-Prüfung für schreibende Requests (CSRF).** Vor jedem POST/PUT/PATCH/DELETE
prüft `OriginCheck` im Router:
- Der Request muss von der eigenen Origin kommen: `Sec-Fetch-Site: same-origin`, oder
  `Origin` bzw. `Referer` passt zu `base_url` oder `allowed_origins`. Sonst 403.
- Ein Body muss `application/json` sein, sonst 415.

Requests ohne jede Herkunftsangabe (curl, Skripte) werden deshalb abgelehnt. Wer
lokal per curl schreiben will, muss einen passenden `Origin`-Header mitschicken.
`GET`/`HEAD` sind nicht betroffen. `HEAD` wird wie `GET` geroutet.

**Routenreihenfolge zählt.** Der Router matcht in Registrierungsreihenfolge und
ersetzt `{param}` durch `([^/]+)`. Deshalb steht `/api/leaderboard` in `routes.php`
bewusst **vor** `/api/stats/{username}`. Neue statische Routen immer vor
gleichpräfixigen Platzhalterrouten einfügen.

**SQL immer per Prepared Statement.** `Database::get()` liefert ein PDO-Singleton mit
`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false` und `time_zone = '+00:00'`.
**Alle Zeitstempel sind UTC.** Dynamische Spaltennamen (z. B. in
`AdminController::updateUser`) nur aus einer festen Allowlist bauen.

**Fehlerausgabe.** `index.php` fängt alle `Throwable`, schreibt sie per `error_log()`
ins PHP-Fehlerlog des Servers und antwortet dem Client generisch mit 500. Details landen
nie in der Antwort.

**Konfiguration** immer über `Config::app()` lesen (lädt `app.local.php`, sonst `app.php`,
einmal pro Request). In Tests lässt sie sich per `Config::override()` ersetzen.

**Cookie-Flag `Secure`** folgt `base_url` (`Config::isHttps()`), nicht `$_SERVER['HTTPS']` –
hinter dem Reverse-Proxy des Hosters fehlt die Variable oft.

**Client-IP** (`Request::ip()`) nutzt `CF-Connecting-IP` nur bei
`trust_cloudflare = true` in `app.local.php`. Produktion läuft nicht hinter Cloudflare,
dort bleibt der Wert `false` – sonst könnte jeder Client seine IP frei wählen.
Hinter dem lokalen Reverse-Proxy des Hosters (private `REMOTE_ADDR`) gilt die **letzte**
öffentliche IP aus `X-Forwarded-For`: Die hängt der Proxy an, alles davor kommt vom
Client und ist fälschbar. Wichtig fürs Rate Limiting – nicht auf „erste IP" umstellen.

---

## 6. Frontend-Konventionen

**Kein Modulsystem.** Jede Datei ist eine IIFE, die entweder ein Global exportiert
(`Api`, `AuthMenu`, `CountryOutline`, `CacheMap`, `CacheExport`) oder alles in einem `DOMContentLoaded`-Handler
kapselt (`app.js`, `admin.js`). Ladereihenfolge in den HTML-Dateien ist relevant:
`api.js` zuerst, dann `auth-menu.js`, `outline.js`, `map.js`, `export.js`, zuletzt `app.js`.

**Header-Menü.** Alle drei Seiten rendern Anmeldestatus und Aktionen über
`AuthMenu.render(container, { username, items })`. Ein Item ist entweder ein Link
(`href`) oder eine Aktion (`onClick`). Ab 1100 px stehen die Items nebeneinander im
Header, darunter hinter einem ☰-Button. Die Umschaltung läuft rein per CSS
(`.auth-menu` in `app.css`) und hat einen eigenen Breakpoint: Die längste Leiste
(fremde Statistik als Admin, fünf Einträge) braucht gut 1050 px. Neue Header-Aktionen
deshalb als Item ergänzen, nicht als eigenes HTML neben dem Menü, und bei einem
zusätzlichen Eintrag die Breite prüfen, sonst läuft der Header über.

**Testbarkeit erkauft man sich über Browser-Freiheit.** `api.js`, `export.js`, `outline.js` und `AuthMenu.buildHtml()`
kommen ohne Leaflet und ohne DOM-Bibliotheken aus und werden in den Vitest-Tests
über `new Function(...)` mit gemockten Globals ausgewertet. Wer neue Logik
testbar halten will, legt sie in ein solches Modul statt in `app.js` – dort ist
alles im `DOMContentLoaded`-Closure eingeschlossen und von außen nicht erreichbar.

**Zentraler State pro Seite.** Jede Seite hält ein einzelnes `state`-Objekt und ein
`els`-Objekt mit allen DOM-Referenzen. Neue Zustände dort ergänzen, keine
verstreuten Modulvariablen.

**HTML-Escaping ist manuell.** `app.js`, `admin.js`, `stats.js` und `auth-menu.js` haben je
eine lokale `escHtml()` (escapt `& < > " '`). Alles, was per `innerHTML` ins DOM kommt,
läuft da durch, auch Werte aus `countries.json` und GeoJSON. Neue Templates genauso,
oder gleich `textContent` nutzen.

**Suchmaschinen.** `/map/{user}` und `/stats/{user}` sind dieselben statischen Dateien.
Titel, Beschreibung, `canonical` und `og:url` setzt deshalb `PageMeta.set()` je Nutzer
(Google wertet das nach dem Rendern aus). Das statische HTML trägt die allgemeinen
Texte und Open-Graph-Tags für Link-Vorschauen, die kein JavaScript ausführen. Die
Kartenseite hat eine optisch versteckte `h1` und einen `noscript`-Text. Die Admin-Seite
trägt `noindex`, API-Antworten den Header `X-Robots-Tag: noindex`. Die Favicons liegen
im Docroot (`/favicon.ico` fragen Browser ohne `<link>` direkt ab). Quelle ist
`favicon.svg`, die PNG- und ICO-Dateien sind daraus gerendert.
`/sitemap.xml` erzeugt `SitemapController` (Route mit `Access::Public`, per Rewrite in
`public/.htaccess` bzw. `scripts/dev-router.php` auf den Front-Controller). Sie listet die
Startseite sowie Karte und Statistik jedes aktiven Nutzers mit mindestens einem Besuch.
Die URLs sind absolut aus `base_url`, `lastmod` ist die letzte Besuchsänderung.
`Response::raw()` liefert Nicht-JSON-Antworten.

**Content-Security-Policy** steht als `<meta>`-Tag in allen drei HTML-Dateien:
`script-src 'self'` (keine Inline-Skripte, kein `eval`/`new Function`), Bilder nur von
der eigenen Origin, `data:` und den OSM-Kachelservern, `connect-src 'self'`.
`'unsafe-inline'` gilt nur für Styles (`style="…"` in Templates). Eine neue
Fremdressource oder Bibliothek, die Code per `eval` erzeugt, fällt in der
Browser-Konsole sofort als CSP-Verstoß auf. Die CSP dann nicht aufweichen, sondern
die Ursache beheben, wie bei Turf.

**localStorage nur für Sichtbarkeit, nichts für Auth.**
- `cc_states_{username}_{countryCode}` → Array der ausgeblendeten Bundesland-Codes
- Login-Daten gehören **nicht** in den Browser-Speicher (siehe §4). Die Altlasten
  `cc_token`, `cc_username`, `cc_admin`, `cc_is_admin` in `sessionStorage` löscht
  `api.js` beim Laden.

**GeoJSON-Pfade unterscheiden sich pro Seite.** `app.js` nimmt nur den Dateinamen und
baut `'../data/' + name` (relativ zu `<base href="/app/">`); `stats.js` nutzt
`'/' + country.geojson`. Beide landen bei `/data/…`, aber der Unterschied ist
leicht zu übersehen.

**Karte.** `CacheMap` (`map.js`) kapselt Leaflet komplett. `loadGeoJSON()` gibt die
`stateMap` (`{stateCode: {name, total, regions[]}}`) zurück, aus der Panel und
Statistiken gerechnet werden. Ausblenden eines Bundeslandes setzt `opacity: 0` **und**
`pointerEvents: none` – die Daten bleiben unverändert, die Gesamtstatistik ebenfalls.
Den Landesumriss berechnet `CountryOutline.fromFeatures()` (`outline.js`): Kanten, die
in genau einer Region vorkommen, bilden die Außengrenze und werden zu Linien verkettet.
Bis Phase H war das `turf.dissolve`. Turf 6.5 erzeugt aber Code per `new Function` und
verstößt damit gegen die CSP, und Turf 7 braucht für Deutschland rund 2 s statt 0,2 s.
**Voraussetzung:** Nachbarregionen teilen exakt dieselben Eckpunkte, wie in den amtlichen
Datensätzen. Für DE, AT, CH und DK ist das geprüft: identischer Umriss wie mit `dissolve`.
Bei einer neuen GeoJSON-Datei vorher prüfen, dass keine Binnengrenzen im Umriss erscheinen.

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
3. `POST /api/auth/verify` mit `{ "token": … }` – bewusst POST, damit die Anfrage
   denselben Same-Origin-Regeln unterliegt wie andere schreibende Requests. Markiert das Token per einzelnem `UPDATE … JOIN`
   atomar als benutzt (`rowCount() === 1` ist die eigentliche Prüfung), legt eine Session
   an und setzt das Cookie. Token ist danach verbrannt, weitere noch unbenutzte Links
   desselben Nutzers werden gelöscht.
4. `app.js::checkMagicLinkToken()` entfernt den Token per `history.replaceState` aus der URL.

`POST /api/auth/logout` beendet die aktuelle Session, `POST /api/auth/logout-all` alle
Sessions des Nutzers („Überall abmelden" in `app.js`).

**Rate Limiting und Enumerationsschutz** in `requestMagicLink`:
- pro IP höchstens 20 Anfragen je Stunde, gezählt in `auth_attempts`, danach 429
- pro Konto höchstens 3 Links in 15 Minuten; weitere werden **still** nicht verschickt,
  die Antwort bleibt gleich
- SMTP-Fehler werden abgefangen und geloggt, die Antwort bleibt 200; PHPMailer-Timeout 10 s
- Mindestantwortzeit 1,5 s für bekannte und unbekannte Adressen

Die Grenzwerte sind Konstanten im `AuthController`. Die Integrationstests setzen
kleinere Werte über `magic_link_*`-Schlüssel ihrer Test-Konfiguration. Das Frontend
übersetzt 429/400 über `Api.magicLinkErrorText()` ins Deutsche.

**Garbage Collection** läuft probabilistisch: bei 2 % aller Magic-Link-Requests werden
abgelaufene Tokens und Sessions sowie `auth_attempts` älter als ein Tag gelöscht
(`AuthController::maybeRunGc`). Das ersetzt
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
Instanzen werden über auskommentierte `ALTER TABLE`-/`UPDATE`-Blöcke am Dateiende
versorgt (siehe `last_seen_at`, Token-Hashing). Diesem Muster folgen. Die
Integrationstests bauen ihr Schema aus derselben Datei auf – eine Schema-Änderung
ohne Eintrag in `database.sql` fällt dort sofort auf.

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

**Keine CORS-Header.** Die API setzt bewusst kein `Access-Control-Allow-Origin` – das
Frontend wird same-origin ausgeliefert. Ein Frontend auf anderer Origin (etwa eine
API-Subdomain) funktioniert deshalb nicht ohne Anpassung.

**Sessions laufen 365 Tage** (`SESSION_TTL_DAYS`), bewusst so entschieden.

**`updateVisit` antwortet mit 404, wenn sich nichts geändert hat.** `rowCount()` liefert
bei MySQL geänderte, nicht getroffene Zeilen. Speichert jemand unveränderte Werte
innerhalb derselben Sekunde (`updated_at = NOW()`), meldet die API „Visit not found",
obwohl der Besuch existiert.

**`public/data/de_landkreise.geojson` ist 3,8 MB.** `project.md` Schritt 10 behauptet eine
Vereinfachung auf 1,1 MB – das ist die `.bak`-Datei; die aktive Datei wurde später
gegen eine größere getauscht. Das kostet Ladezeit auf jeder Kartenseite und ist der
naheliegendste Performance-Hebel.

**Controller-Tests nur über HTTP.** Die Controller hängen am statischen
`Database::get()` und beenden per `exit` – isolierte Unit-Tests sind deshalb nicht
möglich. Abgedeckt werden sie über die Integrationstests (Autorisierung, Objektebene,
Auth-Flow). Fachlogik wie Statistiken und Rangliste ist darüber hinaus nicht geprüft.

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
