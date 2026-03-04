# CacheCounty – Setup

## Voraussetzungen

- PHP 8.0+
- MariaDB 10.4+
- Composer
- Shared Hosting mit mod_rewrite

---

## Installation

### 1. Datenbank anlegen

```sql
CREATE DATABASE cachecounty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Dann `schema.sql` einspielen:

```bash
mysql -u user -p cachecounty < schema.sql
```

### 2. Konfiguration

```bash
cp api/config/database.php api/config/database.local.php
cp api/config/app.php      api/config/app.local.php
```

Beide Dateien mit den eigenen Werten befüllen. Sie werden nicht versioniert.

### 3. Composer Autoloader installieren

```bash
cd api
composer install --optimize-autoloader
```

### 4. Document Root

Den Webserver so konfigurieren, dass `api/public/` als Document Root der API dient.  
Beispiel für eine Subdomain `api.deine-domain.de → /pfad/zu/cachecounty/api/public/`.

Das Frontend (`app/`) liegt separat und kann direkt als statische Seite ausgeliefert werden.

### 5. GeoJSON-Daten

GeoJSON-Dateien für DE und AT in `data/` ablegen:

- `data/de_landkreise.geojson` – Quelle: [Bundesamt für Kartographie](https://gdz.bkg.bund.de/)
- `data/at_bezirke.geojson`   – Quelle: [data.gv.at](https://www.data.gv.at/)

GeoJSON-Dateien sind aus Lizenzgründen nicht im Repository enthalten.

---

## Verzeichnisstruktur

```
cachecounty/
├── api/
│   ├── composer.json
│   ├── config/
│   │   ├── app.php              ← Template (lokal: app.local.php)
│   │   └── database.php         ← Template (lokal: database.local.php)
│   ├── public/
│   │   ├── .htaccess
│   │   └── index.php            ← Entry Point
│   └── src/
│       ├── routes.php
│       ├── Admin/AdminController.php
│       ├── Auth/AuthController.php
│       ├── Region/RegionController.php
│       └── Shared/
│           ├── Database.php
│           ├── Guard.php
│           ├── Request.php
│           ├── Response.php
│           └── Router.php
├── app/                         ← Frontend (Leaflet.js, Vanilla JS)
├── config/
│   └── countries.json           ← Länderkonfiguration
└── data/                        ← GeoJSON-Dateien (nicht versioniert)
```

---

## API-Endpunkte

| Method | Endpunkt                       | Auth    |
|--------|--------------------------------|---------|
| GET    | /api/countries                 | –       |
| GET    | /api/map/{username}            | –       |
| POST   | /api/auth/magic-link           | –       |
| GET    | /api/auth/verify?token=…       | –       |
| POST   | /api/auth/logout               | Session |
| POST   | /api/regions/{code}/visit      | Session |
| PUT    | /api/regions/{code}/visit      | Session |
| DELETE | /api/regions/{code}/visit      | Session |
| GET    | /api/admin/users               | Admin   |
| POST   | /api/admin/users               | Admin   |
| PATCH  | /api/admin/users/{id}          | Admin   |
| DELETE | /api/admin/users/{id}          | Admin   |

Region-Code-Format: `{COUNTRY}-{REGION}`, z. B. `DE-09162` oder `AT-101`.
