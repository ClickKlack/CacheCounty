# CacheCounty – Setup

## Voraussetzungen

- PHP 8.3+
- MariaDB 10.4+
- Composer
- Shared Hosting mit mod_rewrite

---

## Installation

### 1. Datenbank anlegen

```sql
CREATE DATABASE cachecounty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Dann `database.sql` einspielen:

```bash
mysql -u user -p cachecounty < database.sql
```

### 2. Konfiguration

```bash
cp api/config/database.php api/config/database.local.php
cp api/config/app.php      api/config/app.local.php
```

Beide Dateien mit den eigenen Werten befüllen. Sie werden nicht versioniert.

`database.local.php` – Datenbankzugangsdaten (Host, Name, User, Passwort).

`app.local.php` – Basis-URL, E-Mail-Absender und SMTP-Zugangsdaten für den Magic-Link-Versand (PHPMailer).
Beginnt `base_url` mit `https://`, wird das Session-Cookie mit `Secure` gesetzt.
`trust_cloudflare` nur auf `true` setzen, wenn der Server ausschließlich über Cloudflare erreichbar ist.
`allowed_origins` bleibt in Produktion leer. Schreibende Requests werden nur von der eigenen
Origin (`base_url`) angenommen, Requests ohne `Origin`-Header (etwa per curl) werden abgelehnt.

### 3. Composer-Abhängigkeiten installieren

```bash
cd api
composer install --optimize-autoloader
```

### 4. Document Root

Den Webserver so konfigurieren, dass **`public/`** das Document Root ist
(z. B. `deine-domain.de → /pfad/zu/cachecounty/public/`). Dort liegen Frontend,
GeoJSON-Daten und der Einstiegspunkt der API. Quellcode, Konfiguration und `vendor/`
bleiben außerhalb und sind per HTTP nicht erreichbar.

Benötigt werden Apache mit `mod_rewrite` und `AllowOverride` für `.htaccess`.
Zeigt das Document Root versehentlich auf die Projektwurzel, leitet die dortige
`.htaccess` alle Anfragen nach `public/` um.

`public/.htaccess` setzt Security-Header (benötigt `mod_headers`). HSTS startet mit
`max-age=300`. Läuft die Seite danach stabil über HTTPS, den Wert in
`public/.htaccess` auf `31536000` (ein Jahr) erhöhen.

### 5. GeoJSON-Daten

GeoJSON-Dateien für DE und AT in `public/data/` ablegen:

- `public/data/de_landkreise.geojson` – Quelle: [Bundesamt für Kartographie](https://gdz.bkg.bund.de/)
- `public/data/at_bezirke.geojson`   – Quelle: [data.gv.at](https://www.data.gv.at/)

GeoJSON-Dateien sind aus Lizenzgründen nicht im Repository enthalten.

---

## Verzeichnisstruktur

```
cachecounty/
├── public/                      ← Document Root
│   ├── .htaccess                ← Rewrites für /api, /map, /stats
│   ├── api/index.php            ← Entry Point der API
│   ├── app/                     ← Frontend (Leaflet.js, Vanilla JS)
│   │   ├── index.html           ← Kartenansicht (/map/{username})
│   │   ├── stats.html           ← Statistikseite (/stats/{username})
│   │   ├── admin.html           ← Admin-Bereich
│   │   ├── css/app.css
│   │   └── js/
│   │       ├── api.js           ← API-Wrapper
│   │       ├── map.js           ← Leaflet-Karte & GeoJSON-Layer
│   │       ├── export.js        ← GeoJSON-Export für c:geo
│   │       ├── app.js           ← Kartenlogik
│   │       ├── stats.js         ← Statistiklogik
│   │       └── admin.js         ← Admin-Logik
│   └── data/                    ← GeoJSON-Dateien (nicht versioniert)
├── api/                         ← außerhalb des Document Root
│   ├── composer.json
│   ├── config/
│   │   ├── app.php              ← Template (lokal: app.local.php)
│   │   └── database.php         ← Template (lokal: database.local.php)
│   └── src/
│       ├── routes.php
│       ├── Admin/AdminController.php
│       ├── Auth/AuthController.php
│       ├── Region/RegionController.php
│       ├── Stats/StatsController.php
│       └── Shared/
│           ├── Database.php
│           ├── Guard.php
│           ├── Request.php
│           ├── Response.php
│           └── Router.php
├── config/
│   └── countries.json           ← Länderkonfiguration
├── scripts/
│   ├── dev.sh                   ← lokale Entwicklungsumgebung
│   └── dev-router.php           ← Dev-Server-Router (php -S localhost:8080 -t public scripts/dev-router.php)
├── .htaccess                    ← Sicherheitsnetz: leitet nach public/ um
└── database.sql                 ← Schema
```

---

## API-Endpunkte

| Method | Endpunkt                          | Auth    |
|--------|-----------------------------------|---------|
| GET    | /api/countries                    | –       |
| GET    | /api/map/{username}               | –       |
| POST   | /api/auth/magic-link              | –       |
| POST   | /api/auth/verify                  | –       |
| GET    | /api/auth/me                      | Session |
| POST   | /api/auth/logout                  | –       |
| POST   | /api/auth/logout-all              | Session |
| POST   | /api/regions/{code}/visit         | Session |
| PUT    | /api/regions/{code}/visit         | Session |
| DELETE | /api/regions/{code}/visit         | Session |
| GET    | /api/admin/users                  | Admin   |
| POST   | /api/admin/users                  | Admin   |
| PATCH  | /api/admin/users/{id}             | Admin   |
| DELETE | /api/admin/users/{id}             | Admin   |
| GET    | /api/admin/sessions               | Admin   |
| DELETE | /api/admin/sessions/{token}       | Admin   |
| GET    | /api/stats/{username}             | –       |
| GET    | /api/leaderboard                  | –       |

Region-Code-Format: `{COUNTRY}-{REGION}`, z. B. `DE-09162` oder `AT-101`. Das Land muss in
`config/countries.json` konfiguriert sein, der Regionsteil zum `region_code_pattern` des
Landes passen. Sonst antwortet die API mit 400.

**Bestandsdaten prüfen:** Besuche, die vor Einführung der Prüfung mit ungültigen Codes
angelegt wurden, findet diese Abfrage. Bereinigt wird bewusst nicht automatisch:

```sql
SELECT u.username, v.country_code, v.region_code, v.created_at
  FROM visits v JOIN users u ON u.id = v.user_id
 WHERE NOT (   (v.country_code = 'DE' AND v.region_code REGEXP '^[0-9]{5}$')
            OR (v.country_code = 'AT' AND v.region_code REGEXP '^[0-9]{3}$'));
```

---

## Tests

```bash
npm test                                     # Vitest (Frontend)
cd api && ./vendor/bin/phpunit               # PHPUnit: Unit- und Integrationstests
cd api && ./vendor/bin/phpunit --testsuite unit   # nur Unit-Tests, ohne Datenbank
```

### Integrationstests

Die Integrationstests rufen die echte API auf: Sie starten einen PHP-Dev-Server,
bauen das Schema aus `database.sql` in einer **eigenen Testdatenbank** auf und prüfen
unter anderem die Autorisierungs-Matrix (jede Route × jede Rolle). Ohne Testdatenbank
werden sie übersprungen.

Einmalig eine Testdatenbank anlegen. Der Name **muss auf `_test` enden**, sonst
brechen die Tests ab, denn sie leeren vor jedem Test alle Tabellen:

```sql
CREATE DATABASE CacheCounty_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Dann mit Umgebungsvariablen starten:

```bash
export CACHECOUNTY_TEST_DB_NAME=CacheCounty_test
export CACHECOUNTY_TEST_DB_HOST=localhost      # Standard: localhost
export CACHECOUNTY_TEST_DB_USER=…
export CACHECOUNTY_TEST_DB_PASS=…
cd api && ./vendor/bin/phpunit
```

Die CI führt die Integrationstests mit einem MariaDB-Service-Container aus, auch für
Pull Requests. Das Log des Test-Servers liegt unter
`$TMPDIR/cachecounty-integration-server.log`.

**Neue Route?** Jede Route aus `api/src/routes.php` braucht einen Eintrag in
`api/tests/Integration/RouteMatrix.php` mit dem erwarteten Status je Rolle, sonst
schlägt `RoutesCompletenessTest` fehl. Dieser Test läuft auch ohne Datenbank.
