# CacheCounty – Projektspezifikation

> Visuelle Landkreis-Tracking-App für Geocacher

---

## 1. Projektziel

CacheCounty ermöglicht Geocachern, besuchte Landkreise Deutschlands und weiterer konfigurierbarer Länder auf einer interaktiven Karte zu visualisieren. Jeder Nutzer verfügt über eine öffentlich einsehbare, persönliche Kartenansicht sowie eine Statistikseite mit Fortschrittsauswertung und globaler Rangliste. Die Verwaltung besuchter Regionen ist nach Authentifizierung möglich.

---

## 2. Technologie-Stack

| Schicht        | Technologie                                      |
|----------------|--------------------------------------------------|
| Hosting        | Klassisches Shared Hosting                       |
| Backend / API  | PHP 8.3+                                         |
| Datenbank      | MariaDB 10.4+                                    |
| Frontend       | Vanilla JS + Leaflet.js                          |
| Kartenmaterial | OpenStreetMap (Tile-Layer, kein API-Key)         |
| Geodaten       | GeoJSON (Bundesamt für Kartographie / GADM)      |

---

## 3. Architektur

Strikte Trennung von Frontend und Backend über eine REST-API.

```
/api/        → PHP REST-API (JSON-Responses)
/app/        → Frontend (HTML, CSS, Vanilla JS)
/data/       → GeoJSON-Dateien pro Land
/config/     → Länderkonfiguration (countries.json)
```

### Prinzipien

- Das Frontend kommuniziert **ausschließlich** über die API mit dem Backend
- Die API ist **zustandslos** – Authentifizierung erfolgt über Session-Token im Header oder Cookie
- Neue Länder können durch Konfiguration (JSON + GeoJSON-Datei) **ohne Code-Änderungen** ergänzt werden
- Die Zuordnung Landkreis → Bundesland wird ausschließlich aus dem GeoJSON abgeleitet – keine separate Datenbank-Tabelle nötig

---

## 4. User-Management

| Eigenschaft        | Ausprägung                                      |
|--------------------|-------------------------------------------------|
| Anlage             | Ausschließlich durch einen Admin                |
| Self-Service       | Nicht vorgesehen                                |
| URL-Schema         | `https://domain.tld/map/{username}` · `https://domain.tld/stats/{username}` |
| Authentifizierung  | Magic Link per E-Mail (kein Passwort)           |
| Rollen             | `admin`, `user`                                 |

### Ablauf Magic Link

1. User ruft `/login` auf und gibt seine E-Mail-Adresse ein
2. System generiert einen einmaligen Token (64 Zeichen, 15 Minuten gültig)
3. Token wird per E-Mail als Link zugestellt
4. Nach Klick: Token wird validiert, als genutzt markiert (`used_at`) und eine serverseitige Session erstellt
5. Weiterleitung zur eigenen Kartenansicht

---

## 5. Funktionale Anforderungen

### 5.1 Öffentliche Kartenansicht (`/map/{username}`)

- Interaktive Karte mit Landkreis-Umrissen (Leaflet.js + GeoJSON)
- Besuchte Landkreise farblich hervorgehoben (grün), nicht besuchte neutral/grau
- Landesumriss als separater Layer (turf.js dissolve), immer sichtbar unabhängig von Bundesland-Sichtbarkeit
- Länderauswahl über eine Selectbox (nur konfigurierte Länder)
- Statistik-Anzeige gesamt: z. B. „42 von 401 Landkreisen besucht" – immer über alle Bundesländer, unabhängig von der Panel-Auswahl
- Kein Login erforderlich zum Betrachten

---

### 5.2 Bundesland-Panel

Ein kollabiertes Seitenpanel zeigt alle übergeordneten Verwaltungseinheiten (Bundesländer, Kantone – je nach Land) des aktuell gewählten Landes. Die Bezeichnung wird pro Land über den Konfigurationsschlüssel `state_label` gesteuert (z. B. „Bundesland", „Kanton").

#### Aufbau & Darstellung

- Das Panel ist am rechten Bildschirmrand angebracht und über einen Pfeil-Toggle kollabierbar
- Auf Mobile erscheint das Panel als Bottom-Drawer mit Handle zum Aufziehen/Zuziehen
- Am oberen Rand des Panels befinden sich zwei Schnellauswahl-Buttons: **Alle** und **Keine**
- Jeder Eintrag in der Liste enthält:
  - Checkbox (sichtbar / ausgeblendet)
  - Name des Bundeslandes
  - Schmaler Fortschrittsbalken (inline)
  - Zähler: `X / Y` (besuchte Landkreise / Gesamtzahl Landkreise dieses Bundeslandes)

#### Filterverhalten

- Wird ein Bundesland abgewählt, werden seine Landkreis-Polygone auf der Karte ausgeblendet (`opacity: 0`, Klick deaktiviert) – Daten und Statistiken bleiben unverändert
- Abgewählte Bundesländer erscheinen in der Liste ausgegraut
- Die Gesamtstatistik in der Stats-Bar bezieht sich immer auf **alle** Bundesländer

#### Persistenz

- Der Sichtbarkeitszustand wird clientseitig im `localStorage` gespeichert – kein API-Endpunkt nötig
- Schlüssel: `cc_states_{username}_{countryCode}` (z. B. `cc_states_MaxMustermann_DE`)
- Beim Wechsel des Landes oder Users wird der passende Zustand geladen bzw. auf „alle eingeblendet" zurückgesetzt

---

### 5.3 Landkreis-Dialog

Ein Klick auf einen sichtbaren (eingeblendeten) Landkreis öffnet ein Dialogfeld.

**Immer sichtbar:**
- Name des Landkreises
- Zugehöriges Bundesland / Region
- Status: besucht / nicht besucht
- Bei besucht: Datum des Besuchs und Notizen

**Nur für eingeloggten Besitzer:**
- Toggle-Button „Als besucht markieren" / „Besuch entfernen"
- Eingabefeld: Datum des Besuchs
- Eingabefeld: Freitext / Notizen
- Speichern-Button

---

### 5.4 Statistikseite (`/stats/{username}`)

Öffentlich einsehbare Statistikseite je Nutzer, erreichbar über den Header-Link auf der Karte.

| Sektion               | Inhalt                                                                                   |
|-----------------------|------------------------------------------------------------------------------------------|
| Ländervergleich       | Fortschrittsbalken je konfiguriertem Land (besucht / gesamt / Prozent)                  |
| Zeitlicher Verlauf    | Kumuliertes Flächendiagramm (Chart.js) pro Land, Fallback auf `created_at` wenn kein `visited_at` gesetzt |
| Fortschritt je Bundesland | Fortschrittsbalken je Bundesland, sortiert nach relativer Fundrate absteigend       |
| Meilensteine          | Karten für Erster Besuch, 10 %, 25 %, 50 %, 75 %, 100 %, Alle Bundesländer – jeweils mit Erreichungsdatum |
| Rangliste             | Tab „Gesamt" + ein Tab je konfiguriertem Land; Top-50 mit Rangzahl und Tiebreaker-Logik |

Die Bundesland-Zuordnung und Meilensteinberechnung erfolgt clientseitig aus dem GeoJSON (kein separater API-Endpunkt nötig).

---

### 5.5 Admin-Bereich (`/admin`)

- Login per Magic Link (separate Admin-E-Mail)
- User anlegen (Username + E-Mail)
- User deaktivieren / reaktivieren
- User löschen (inkl. aller Visits via CASCADE)
- Übersicht aller User mit Status und Erstelldatum

---

## 6. REST-API

Alle Responses im Format `application/json`.
Authentifizierung über Session-Cookie oder `Authorization`-Header.

### Öffentliche Endpunkte

| Method | Endpunkt                    | Beschreibung                                        |
|--------|-----------------------------|-----------------------------------------------------|
| GET    | `/api/map/{username}`       | Besuchte Regionen des Users                         |
| GET    | `/api/countries`            | Liste konfigurierter Länder                         |
| GET    | `/api/stats/{username}`     | Aggregierte Statistiken (Timeline, Meilensteine …)  |
| GET    | `/api/leaderboard`          | Rangliste, optional `?country=DE`                   |
| POST   | `/api/auth/magic-link`      | Magic Link anfordern                                |
| GET    | `/api/auth/verify`          | Token einlösen (`?token=…`)                         |
| POST   | `/api/auth/logout`          | Session beenden                                     |

### Authentifizierte Endpunkte (User)

| Method | Endpunkt                        | Beschreibung                      |
|--------|---------------------------------|-----------------------------------|
| POST   | `/api/regions/{code}/visit`     | Landkreis als besucht markieren   |
| PUT    | `/api/regions/{code}/visit`     | Datum / Notizen aktualisieren     |
| DELETE | `/api/regions/{code}/visit`     | Besuch entfernen                  |

> `{code}` = kombinierter Schlüssel aus `country_code` + `region_code`, z. B. `DE-09162`

### Admin-Endpunkte

| Method | Endpunkt                        | Beschreibung            |
|--------|---------------------------------|-------------------------|
| GET    | `/api/admin/users`              | Alle User auflisten     |
| POST   | `/api/admin/users`              | User anlegen            |
| PATCH  | `/api/admin/users/{id}`         | User aktivieren/deaktiv.|
| DELETE | `/api/admin/users/{id}`         | User löschen            |
| GET    | `/api/admin/sessions`           | Aktive Sessions         |
| DELETE | `/api/admin/sessions/{token}`   | Session beenden         |

---

## 7. Datenbankschema

### Tabelle: `users`

| Spalte       | Typ               | Beschreibung                      |
|--------------|-------------------|-----------------------------------|
| id           | INT UNSIGNED PK   | Auto-Increment                    |
| username     | VARCHAR(60) UQ    | Teil der öffentlichen URL         |
| email        | VARCHAR(255) UQ   | Für Magic-Link-Versand            |
| is_admin     | TINYINT(1)        | Admin-Flag                        |
| is_active    | TINYINT(1)        | Aktiv/Deaktiviert                 |
| created_at   | DATETIME          | Erstellzeitpunkt                  |
| updated_at   | DATETIME          | Letzter Änderungszeitpunkt        |

### Tabelle: `magic_links`

| Spalte       | Typ               | Beschreibung                      |
|--------------|-------------------|-----------------------------------|
| id           | INT UNSIGNED PK   | Auto-Increment                    |
| user_id      | INT UNSIGNED FK   | → users.id (CASCADE DELETE)       |
| token        | CHAR(64) UQ       | Einmal-Token (Hex)                |
| expires_at   | DATETIME          | Ablaufzeitpunkt (15 min)          |
| used_at      | DATETIME NULL     | Zeitpunkt der Einlösung           |
| ip_address   | VARCHAR(45) NULL  | Optional zur Absicherung          |
| created_at   | DATETIME          | Erstellzeitpunkt                  |

### Tabelle: `visits`

| Spalte       | Typ               | Beschreibung                          |
|--------------|-------------------|---------------------------------------|
| id           | INT UNSIGNED PK   | Auto-Increment                        |
| user_id      | INT UNSIGNED FK   | → users.id (CASCADE DELETE)           |
| country_code | CHAR(2)           | ISO 3166-1 Alpha-2 (z. B. `DE`, `AT`) |
| region_code  | VARCHAR(20)       | AGS (DE), ISO-Code (AT), etc.         |
| region_name  | VARCHAR(255) NULL | Denormalisierter Anzeigename          |
| visited_at   | DATE NULL         | Optionales Besuchsdatum               |
| notes        | TEXT NULL         | Freitext / Notizen                    |
| created_at   | DATETIME          | Erstellzeitpunkt                      |
| updated_at   | DATETIME          | Letzter Änderungszeitpunkt            |

> Unique-Key auf `(user_id, country_code, region_code)` verhindert Duplikate.
> Die Zuordnung Landkreis → Bundesland wird nicht in der DB gespeichert, sondern zur Laufzeit aus dem GeoJSON abgeleitet.

### Tabelle: `sessions`

| Spalte       | Typ               | Beschreibung                      |
|--------------|-------------------|-----------------------------------|
| id           | CHAR(64) PK       | Session-Token (Hex)               |
| user_id      | INT UNSIGNED FK   | → users.id (CASCADE DELETE)       |
| expires_at   | DATETIME          | Ablaufzeitpunkt                   |
| ip_address   | VARCHAR(45) NULL  | Optional                          |
| user_agent   | VARCHAR(512) NULL | Optional                          |
| created_at   | DATETIME          | Erstellzeitpunkt                  |

---

## 8. Länderkonfiguration

Datei: `/config/countries.json`

```json
[
  {
    "code": "DE",
    "label": "Deutschland",
    "state_label": "Bundesland",
    "state_label_plural": "Bundesländer",
    "geojson": "data/de_landkreise.geojson",
    "region_name_property": "GEN",
    "region_code_property": "AGS",
    "state_name_property": "BL",
    "state_code_property": "BL_ID"
  }
]
```

Felder je Land:

| Feld                   | Pflicht | Beschreibung                                                      |
|------------------------|---------|-------------------------------------------------------------------|
| `code`                 | ✅      | ISO 3166-1 Alpha-2                                                |
| `label`                | ✅      | Anzeigename in der Länder-Selectbox                               |
| `state_label`          | ✅      | Singular-Bezeichnung der übergeordneten Einheit (z. B. „Bundesland") |
| `state_label_plural`   | –       | Plural-Form (z. B. „Bundesländer") – wird für Meilenstein-Texte verwendet |
| `geojson`              | ✅      | Pfad zur GeoJSON-Datei relativ zum Projektstamm                   |
| `region_name_property` | ✅      | GeoJSON-Property für den Landkreisnamen                           |
| `region_code_property` | ✅      | GeoJSON-Property für den Landkreis-Code (eindeutig)               |
| `state_name_property`  | ✅      | GeoJSON-Property für den Bundesland-Namen                         |
| `state_code_property`  | ✅      | GeoJSON-Property für den Bundesland-Code (Gruppierungsschlüssel)  |

---

## 9. Nicht-funktionale Anforderungen

| Anforderung        | Ausprägung                                                                                                                                                              |
|--------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Performance        | GeoJSON ggf. vereinfacht (z. B. via Mapshaper) für schnelle Ladezeiten                                                                                                 |
| Responsive Design  | Mobile-freundlich; Bundesland-Panel als Bottom-Drawer auf kleinen Screens                                                                                               |
| Sicherheit         | CSRF-Schutz auf schreibenden Endpunkten                                                                                                                                 |
| Sicherheit         | Magic Links nach Nutzung sofort invalidiert                                                                                                                             |
| Sicherheit         | Sessions serverseitig gespeichert (kein JWT)                                                                                                                            |
| Wartbarkeit        | Abgelaufene Magic Links und Sessions werden in der Applikation bereinigt (kein SQL-Event/Cron): probabilistisch bei jedem `POST /api/auth/magic-link` mit 2 % Wahrscheinlichkeit |
| Persistenz         | Bundesland-Sichtbarkeit wird clientseitig in `localStorage` gespeichert – kein API-Endpunkt nötig                                                                      |
| Kompatibilität     | PHP 8+, MariaDB 10.4+, Shared Hosting                                                                                                                                  |

---

## 10. Implementierungsreihenfolge

1. Datenbankschema (✅ erledigt)
2. PHP-API-Grundgerüst (✅ erledigt)
3. Magic-Link-Authentifizierung (✅ erledigt)
4. API-Endpunkte – Visits CRUD (✅ erledigt)
5. Frontend – Leaflet-Karte + GeoJSON-Darstellung + Landesumriss (✅ erledigt)
6. Frontend – Bundesland-Panel mit Filter & localStorage-Persistenz (✅ erledigt)
7. Frontend – Landkreis-Dialog (Bundesland-Anzeige, Visit-CRUD, Login-Dialog) (✅ erledigt)
8. Admin-Bereich Backend (✅ erledigt) / Frontend (✅ erledigt)
9. GeoJSON-Daten: DE (✅ erledigt) / AT (✅ erledigt)
10. GeoJSON-Vereinfachung (✅ erledigt – DE von 5,2 MB auf 1,1 MB reduziert)
11. Statistikseite `/stats/{username}` mit Rangliste (✅ erledigt)
12. Testing & Deployment (❌ offen)

---

*Projektname: CacheCounty | Stand: März 2026*